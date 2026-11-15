<?php
/**
 * My Account menu.
 *
 * Downloads and payment methods are not used: there are no digital products,
 * and payment gateways stay off.
 */

defined('ABSPATH') || exit;

add_filter('woocommerce_account_menu_items', 'nmj_account_menu_items');
add_filter('gettext', 'nmj_translate_account', 10, 3);
add_filter('woocommerce_get_privacy_policy_text', 'nmj_account_privacy_text', 10, 2);

/**
 * @param array<string, string> $items
 * @return array<string, string>
 */
function nmj_account_menu_items(array $items): array
{
    unset($items['downloads'], $items['payment-methods']);
    return $items;
}

/**
 * WooCommerce's KA file leaves a few account-form strings in English.
 */
function nmj_translate_account(string $translation, string $text, string $domain): string
{
    if (nmj_current_language() !== 'ka' || ($domain !== 'woocommerce' && $domain !== 'default')) {
        return $translation;
    }
    $keys = [
        'A link to set a new password will be sent to your email address.' => 'account_set_password',
        'Show password' => 'account_show_password',
        'Hide password' => 'account_hide_password',
    ];
    if (!isset($keys[$text])) {
        return $translation;
    }
    return nmj_t($keys[$text]);
}

/**
 * The saved registration privacy sentence is English. Keep the option as it
 * is and swap it on the Georgian site, leaving the [privacy_policy] placeholder
 * for WooCommerce to turn into the policy link.
 *
 * @param mixed $text
 * @param mixed $type
 */
function nmj_account_privacy_text($text, $type): string
{
    $text = is_string($text) ? $text : '';
    if ($type !== 'registration' || nmj_current_language() !== 'ka') {
        return $text;
    }
    if (strpos($text, 'Your personal data will be used') === false) {
        return $text;
    }
    return nmj_t('account_privacy');
}

/**
 * Times the account registration form and releases the 302 before mail.
 *
 * Request-local only. The stored profile has durations and a timestamp:
 * no email address, no name, and no user id.
 */
final class NMJ_Registration_Profile
{
    private static bool $active = false;

    private static bool $customer_created = false;

    private static bool $mail_open = false;

    private static float $started_at = 0.0;

    private static float $user_started_at = 0.0;

    private static float $created_started_at = 0.0;

    private static float $mail_started_at = 0.0;

    private static int $insert_user_ms = 0;

    private static int $created_customer_ms = 0;

    private static int $mail_ms = 0;

    public static function boot(): void
    {
        add_filter('woocommerce_defer_transactional_emails', '__return_true');
        add_action('woocommerce_register_post', [self::class, 'on_register_post'], 1);
        add_action('user_register', [self::class, 'on_user_register_start'], 1);
        add_action('user_register', [self::class, 'on_user_register_end'], 999);
        add_action('woocommerce_created_customer', [self::class, 'on_created_customer_start'], 1);
        add_action('woocommerce_created_customer', [self::class, 'on_created_customer_end'], 999);
    }

    public static function on_register_post(): void
    {
        if (!self::is_account_registration_post()) {
            return;
        }
        self::$active = true;
        self::$started_at = microtime(true);
        add_filter('pre_wp_mail', [self::class, 'on_mail_start'], 1, 1);
        add_action('phpmailer_init', [self::class, 'on_phpmailer_init'], 1);
        add_action('wp_mail_succeeded', [self::class, 'on_mail_end'], 10, 0);
        add_action('wp_mail_failed', [self::class, 'on_mail_end'], 10, 0);
    }

    public static function on_user_register_start(): void
    {
        if (!self::$active) {
            return;
        }
        self::$user_started_at = microtime(true);
    }

    public static function on_user_register_end(): void
    {
        if (!self::$active) {
            return;
        }
        self::$insert_user_ms = self::elapsed_ms(self::$user_started_at);
    }

    public static function on_created_customer_start(): void
    {
        if (!self::$active) {
            return;
        }
        self::$customer_created = true;
        self::$created_started_at = microtime(true);
    }

    public static function on_created_customer_end(): void
    {
        if (!self::$active) {
            return;
        }
        self::$customer_created = true;
        self::$created_customer_ms = self::elapsed_ms(self::$created_started_at);
        if (self::is_account_registration_post()) {
            add_filter('wp_redirect', [self::class, 'flush_registration_redirect'], 999, 2);
        }
        self::save();
    }

    /**
     * @param mixed $short_circuit
     * @return mixed
     */
    public static function on_mail_start(mixed $short_circuit = null): mixed
    {
        self::mark_mail_start();
        return $short_circuit;
    }

    public static function on_phpmailer_init(): void
    {
        self::mark_mail_start();
    }

    public static function on_mail_end(): void
    {
        if (!self::$active || !self::$mail_open) {
            return;
        }
        self::$mail_ms += self::elapsed_ms(self::$mail_started_at);
        self::$mail_open = false;
        self::$mail_started_at = 0.0;
        self::save();
    }

    /**
     * Send the new-customer 302, finish the HTTP request, then exit.
     * exit runs shutdown, so queued mail is sent after the browser has the redirect.
     */
    public static function flush_registration_redirect(string $location, int $status = 302): string
    {
        if (!self::$active || !self::$customer_created || !self::is_account_registration_post()) {
            return $location;
        }
        $safe = wp_sanitize_redirect($location);
        if ($safe === '' || headers_sent()) {
            return $location;
        }

        self::save();
        http_response_code(302);
        header('Status: 302 Moved Temporarily', true, 302);
        header('Location: ' . $safe, true, 302);
        header('Cache-Control: no-store, private');
        header('Connection: close');
        header('Content-Length: 0');

        // This host wraps PHP in an output buffer. finish_request does not
        // release the client until that buffer is closed, so queued mail on
        // shutdown would still hold the 302.
        ignore_user_abort(true);
        if (function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request')) {
            self::discard_buffers();
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } else {
                fastcgi_finish_request();
            }
        } else {
            self::flush_buffers();
        }

        exit;
    }

    private static function discard_buffers(): void
    {
        $level = ob_get_level();
        while ($level > 0) {
            ob_end_clean();
            $next = ob_get_level();
            if ($next >= $level) {
                break;
            }
            $level = $next;
        }
    }

    private static function flush_buffers(): void
    {
        $level = ob_get_level();
        while ($level > 0) {
            ob_end_flush();
            $next = ob_get_level();
            if ($next >= $level) {
                break;
            }
            $level = $next;
        }
        flush();
    }

    private static function mark_mail_start(): void
    {
        if (!self::$active || self::$mail_open) {
            return;
        }
        self::$mail_open = true;
        self::$mail_started_at = microtime(true);
    }

    private static function is_account_registration_post(): bool
    {
        if (PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }
        if (is_admin()) {
            return false;
        }
        if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
        if ($method !== 'POST') {
            return false;
        }
        return isset($_POST['register'], $_POST['woocommerce-register-nonce'])
            && !isset($_POST['woocommerce-process-checkout-nonce']);
    }

    private static function elapsed_ms(float $started_at): int
    {
        if ($started_at <= 0) {
            return 0;
        }
        return (int) round((microtime(true) - $started_at) * 1000);
    }

    private static function save(): void
    {
        if (!self::$active || !self::$customer_created) {
            return;
        }
        update_option('odella_last_registration_profile', [
            'total_ms' => self::elapsed_ms(self::$started_at),
            'insert_user_ms' => self::$insert_user_ms,
            'created_customer_ms' => self::$created_customer_ms,
            'mail_ms' => self::$mail_ms,
            'deferred' => (bool) apply_filters('woocommerce_defer_transactional_emails', false),
            'timestamp' => time(),
        ], false);
    }
}

NMJ_Registration_Profile::boot();
