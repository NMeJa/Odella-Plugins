<?php
/**
 * Encrypted Odella settings.
 *
 * API keys are stored with libsodium secretbox. The option is not autoloaded.
 */

defined('ABSPATH') || exit;

add_action('admin_enqueue_scripts', 'odella_settings_admin_assets');
add_action('wp_ajax_odella_test_api_key', 'odella_ajax_test_api_key');

function odella_secret_key(): string
{
    return hash('sha256', wp_salt('auth') . '|odella-speed-v1', true);
}

function odella_encrypt_secret(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }
    if (!function_exists('sodium_crypto_secretbox')) {
        return odella_encrypt_secret_aes256gcm($plain);
    }
    try {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, odella_secret_key());
    } catch (Throwable $e) {
        return '';
    }
    return base64_encode($nonce . $cipher);
}

function odella_decrypt_secret(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    if (str_starts_with($stored, 'aes256gcm:')) {
        return odella_decrypt_secret_aes256gcm($stored);
    }
    if (!function_exists('sodium_crypto_secretbox_open')) {
        return '';
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    try {
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, odella_secret_key());
    } catch (Throwable $e) {
        return '';
    }
    return is_string($plain) ? $plain : '';
}

function odella_mask_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $tail = strlen($plain) > 4 ? substr($plain, -4) : $plain;
    return '••••••••' . $tail;
}

function odella_encrypt_secret_aes256gcm(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', odella_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if (!is_string($cipher) || !is_string($tag) || strlen($tag) !== 16) {
        return '';
    }
    return 'aes256gcm:' . base64_encode($iv . $tag . $cipher);
}

function odella_decrypt_secret_aes256gcm(string $stored): string
{
    if (!function_exists('openssl_decrypt') || !str_starts_with($stored, 'aes256gcm:')) {
        return '';
    }
    $raw = base64_decode(substr($stored, strlen('aes256gcm:')), true);
    if ($raw === false || strlen($raw) <= 28) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', odella_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

/**
 * @return array<string, string>
 */
function odella_supported_report_languages(): array
{
    return [
        'en' => 'English',
        'ka' => 'Georgian (ქართული)',
        'ru' => 'Russian (Русский)',
        'uk' => 'Ukrainian (Українська)',
        'tr' => 'Turkish (Türkçe)',
        'de' => 'German (Deutsch)',
        'fr' => 'French (Français)',
        'es' => 'Spanish (Español)',
    ];
}

function odella_default_ai_prompt(): string
{
    $prompt = <<<'PROMPT'
You are the performance diagnostics assistant for Odella (https://odella.ge), a WooCommerce + Elementor 4.4 e-commerce store running on Hostinger LiteSpeed Web Server with LiteSpeed Cache, Hostinger Edge CDN, and a custom static JSON product catalog index.

Analyze the deterministic server checks and Google PageSpeed Insights metrics provided below.
1. Identify the primary root cause(s) of any slowness in plain, non-technical language that a store owner can immediately understand, followed by concise technical specifics.
2. Do not blame cold cache if the deterministic check already warmed the cache.
3. Never suggest installing Redis/Memcached (unavailable on this CageFS plan) or enabling LiteSpeed CSS/JS minification (intentionally disabled to prevent CDN 404 races).
4. Give 2 to 4 concrete, prioritized next actions.
PROMPT;
    return rtrim($prompt, "\r\n");
}

function odella_sanitize_gemini_model(string $model): string
{
    $model = trim($model);
    if ($model === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $model) !== 1) {
        return 'gemini-flash-latest';
    }
    return $model;
}

/**
 * @param mixed $input
 * @return array<int, string>
 */
function odella_sanitize_report_languages($input): array
{
    $allowed = array_keys(odella_supported_report_languages());
    $codes = [];
    if (is_array($input)) {
        foreach ($input as $code) {
            if (!is_string($code)) {
                continue;
            }
            if (!in_array($code, $allowed, true) || in_array($code, $codes, true)) {
                continue;
            }
            $codes[] = $code;
        }
    }
    if ($codes === []) {
        return ['en'];
    }
    return array_slice($codes, 0, 3);
}

/**
 * Stored settings. Ciphertext stays ciphertext; nothing is decrypted or logged here.
 *
 * @return array{
 *     psi_key_enc: string,
 *     gemini_key_enc: string,
 *     gemini_model: string,
 *     languages: array<int, string>,
 *     use_custom_prompt: bool,
 *     custom_prompt: string
 * }
 */
function odella_get_speed_settings(): array
{
    $defaults = [
        'psi_key_enc' => '',
        'gemini_key_enc' => '',
        'gemini_model' => 'gemini-flash-latest',
        'languages' => ['en'],
        'use_custom_prompt' => false,
        'custom_prompt' => '',
    ];
    $stored = get_option('odella_speed_settings', []);
    if (!is_array($stored)) {
        $stored = [];
    }
    $settings = array_merge($defaults, $stored);
    $settings['psi_key_enc'] = is_string($settings['psi_key_enc']) ? $settings['psi_key_enc'] : '';
    $settings['gemini_key_enc'] = is_string($settings['gemini_key_enc']) ? $settings['gemini_key_enc'] : '';
    $settings['gemini_model'] = odella_sanitize_gemini_model(is_string($settings['gemini_model']) ? $settings['gemini_model'] : '');
    $settings['languages'] = odella_sanitize_report_languages($settings['languages'] ?? []);
    $flag = $settings['use_custom_prompt'];
    $settings['use_custom_prompt'] = $flag === true || $flag === 1 || $flag === '1';
    $settings['custom_prompt'] = is_string($settings['custom_prompt']) ? $settings['custom_prompt'] : '';
    return [
        'psi_key_enc' => $settings['psi_key_enc'],
        'gemini_key_enc' => $settings['gemini_key_enc'],
        'gemini_model' => $settings['gemini_model'],
        'languages' => $settings['languages'],
        'use_custom_prompt' => $settings['use_custom_prompt'],
        'custom_prompt' => $settings['custom_prompt'],
    ];
}

/**
 * @param array{
 *     psi_key_enc: string,
 *     gemini_key_enc: string,
 *     gemini_model: string,
 *     languages: array<int, string>,
 *     use_custom_prompt: bool,
 *     custom_prompt: string
 * } $settings
 */
function odella_store_speed_settings(array $settings): void
{
    update_option(
        'odella_speed_settings',
        [
            'psi_key_enc' => $settings['psi_key_enc'],
            'gemini_key_enc' => $settings['gemini_key_enc'],
            'gemini_model' => odella_sanitize_gemini_model($settings['gemini_model']),
            'languages' => odella_sanitize_report_languages($settings['languages']),
            'use_custom_prompt' => $settings['use_custom_prompt'],
            'custom_prompt' => $settings['custom_prompt'],
        ],
        false
    );
}

function odella_effective_ai_prompt(): string
{
    $settings = odella_get_speed_settings();
    if ($settings['use_custom_prompt'] && trim($settings['custom_prompt']) !== '') {
        return $settings['custom_prompt'];
    }
    return odella_default_ai_prompt();
}

function odella_secret_badge(string $stored): string
{
    $plain = odella_decrypt_secret($stored);
    if ($plain === '') {
        return 'Not set';
    }
    $badge = 'Configured (' . odella_mask_secret($plain) . ')';
    unset($plain);
    return $badge;
}

/**
 * @return array{ok: bool, message: string}
 */
function odella_save_speed_settings_from_post(): array
{
    $current = odella_get_speed_settings();
    $errors = [];

    $psi_enc = $current['psi_key_enc'];
    if (!empty($_POST['remove_psi_key'])) {
        $psi_enc = '';
    } elseif (isset($_POST['psi_key']) && is_string($_POST['psi_key'])) {
        $typed = trim(wp_unslash($_POST['psi_key']));
        if ($typed !== '') {
            $encrypted = odella_encrypt_secret($typed);
            $typed = '';
            if ($encrypted === '') {
                $errors[] = 'The PageSpeed key could not be saved. The previous key was kept.';
            } else {
                $psi_enc = $encrypted;
            }
        }
    }

    $gemini_enc = $current['gemini_key_enc'];
    if (!empty($_POST['remove_gemini_key'])) {
        $gemini_enc = '';
    } elseif (isset($_POST['gemini_key']) && is_string($_POST['gemini_key'])) {
        $typed = trim(wp_unslash($_POST['gemini_key']));
        if ($typed !== '') {
            $encrypted = odella_encrypt_secret($typed);
            $typed = '';
            if ($encrypted === '') {
                $errors[] = 'The Gemini key could not be saved. The previous key was kept.';
            } else {
                $gemini_enc = $encrypted;
            }
        }
    }

    $use_custom = !empty($_POST['use_custom_prompt']);
    $custom_prompt = $current['custom_prompt'];
    if ($use_custom && isset($_POST['custom_prompt']) && is_string($_POST['custom_prompt'])) {
        $custom_prompt = sanitize_textarea_field(wp_unslash($_POST['custom_prompt']));
    }

    $posted_model = isset($_POST['gemini_model']) && is_string($_POST['gemini_model'])
        ? wp_unslash($_POST['gemini_model'])
        : '';
    $posted_languages = isset($_POST['languages']) && is_array($_POST['languages'])
        ? wp_unslash($_POST['languages'])
        : [];

    odella_store_speed_settings([
        'psi_key_enc' => $psi_enc,
        'gemini_key_enc' => $gemini_enc,
        'gemini_model' => odella_sanitize_gemini_model($posted_model),
        'languages' => odella_sanitize_report_languages($posted_languages),
        'use_custom_prompt' => $use_custom,
        'custom_prompt' => $custom_prompt,
    ]);

    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }
    return ['ok' => true, 'message' => 'Settings saved.'];
}

function odella_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html('You do not have permission to manage Odella settings.'));
    }

    $notice = '';
    $notice_class = 'notice-success';
    if (
        isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['odella_settings_submit'])
    ) {
        check_admin_referer('odella_settings_save');
        $saved = odella_save_speed_settings_from_post();
        $notice = $saved['message'];
        $notice_class = $saved['ok'] ? 'notice-success' : 'notice-error';
    }

    $settings = odella_get_speed_settings();
    $psi_badge = odella_secret_badge($settings['psi_key_enc']);
    $gemini_badge = odella_secret_badge($settings['gemini_key_enc']);
    $use_custom = $settings['use_custom_prompt'];
    $default_prompt = odella_default_ai_prompt();
    $prompt_value = $default_prompt;
    if ($use_custom && trim($settings['custom_prompt']) !== '') {
        $prompt_value = $settings['custom_prompt'];
    }
    $custom_json = wp_json_encode(
        $settings['custom_prompt'],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($custom_json)) {
        $custom_json = '""';
    }
    $selected = array_fill_keys($settings['languages'], true);
    ?>
    <div class="wrap">
        <h1>Settings</h1>
        <?php if ($notice !== '') : ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?php wp_nonce_field('odella_settings_save'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">PageSpeed Insights API Key</th>
                    <td>
                        <p><strong><?php echo esc_html($psi_badge); ?></strong></p>
                        <p>
                            <input type="password" class="regular-text" id="odella-psi-key" name="psi_key" value="" autocomplete="new-password" placeholder="Leave empty to keep current key">
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="remove_psi_key" value="1">
                                Remove stored PageSpeed key
                            </label>
                        </p>
                        <p>
                            <button type="button" class="button" id="odella-test-psi-key">Test PageSpeed Key</button>
                            <span id="odella-psi-key-status" role="status"></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Google Gemini API Key</th>
                    <td>
                        <p><strong><?php echo esc_html($gemini_badge); ?></strong></p>
                        <p>
                            <input type="password" class="regular-text" id="odella-gemini-key" name="gemini_key" value="" autocomplete="new-password" placeholder="Leave empty to keep current key">
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="remove_gemini_key" value="1">
                                Remove stored Gemini key
                            </label>
                        </p>
                        <p>
                            <button type="button" class="button" id="odella-test-gemini-key">Test Gemini Key</button>
                            <span id="odella-gemini-key-status" role="status"></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="odella-gemini-model">Gemini Model</label></th>
                    <td>
                        <input type="text" class="regular-text" id="odella-gemini-model" name="gemini_model" value="<?php echo esc_attr($settings['gemini_model']); ?>" maxlength="128" spellcheck="false">
                        <p class="description">Default is gemini-flash-latest.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">AI Report Languages (choose 1 to 3)</th>
                    <td>
                        <?php foreach (odella_supported_report_languages() as $code => $label) : ?>
                            <label style="display:block;margin:0 0 4px;">
                                <input type="checkbox" class="odella-report-lang" name="languages[]" value="<?php echo esc_attr($code); ?>" <?php checked(isset($selected[$code])); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">AI System Prompt</th>
                    <td>
                        <p>
                            <label>
                                <input type="checkbox" name="use_custom_prompt" id="odella-use-custom-prompt" value="1" <?php checked($use_custom); ?>>
                                Custom
                            </label>
                        </p>
                        <textarea id="odella-ai-prompt" rows="9" class="large-text code" data-mode="<?php echo $use_custom ? 'custom' : 'default'; ?>" <?php echo $use_custom ? 'name="custom_prompt"' : 'readonly'; ?>><?php echo esc_textarea($prompt_value); ?></textarea>
                        <script type="application/json" id="odella-custom-prompt-json"><?php echo $custom_json; ?></script>
                        <p>
                            <button type="button" class="button" id="odella-reset-prompt" <?php echo $use_custom ? '' : 'hidden'; ?>>Reset to default prompt</button>
                        </p>
                        <p class="description">The multi-language JSON output format is appended automatically so language tabs always work.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save settings', 'primary', 'odella_settings_submit'); ?>
        </form>
    </div>
    <?php
}

function odella_settings_admin_assets(string $hook_suffix): void
{
    unset($hook_suffix);
    if (!isset($_GET['page']) || !is_string($_GET['page']) || $_GET['page'] !== 'odella-settings') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    wp_enqueue_script('jquery');
    wp_localize_script('jquery', 'odellaSettings', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('odella_settings_ajax'),
    ]);
    wp_add_inline_script('jquery', odella_settings_admin_script());
}

function odella_settings_admin_script(): string
{
    $default = wp_json_encode(
        odella_default_ai_prompt(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($default)) {
        $default = '""';
    }
    $script = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.odellaSettings || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce = cfg.nonce || '';
    var defaultPrompt = window.odellaDefaultPrompt || '';
    var toggle = document.getElementById('odella-use-custom-prompt');
    var area = document.getElementById('odella-ai-prompt');
    var reset = document.getElementById('odella-reset-prompt');
    var stored = document.getElementById('odella-custom-prompt-json');
    var customPrompt = '';
    if (stored) {
        try {
            customPrompt = JSON.parse(stored.textContent || '""');
            if (typeof customPrompt !== 'string') {
                customPrompt = '';
            }
        } catch (ignore) {
            customPrompt = '';
        }
    }

    function showPromptMode() {
        if (!toggle || !area) {
            return;
        }
        if (toggle.checked) {
            if (area.getAttribute('data-mode') !== 'custom') {
                area.value = customPrompt.trim() !== '' ? customPrompt : defaultPrompt;
            }
            area.readOnly = false;
            area.setAttribute('name', 'custom_prompt');
            area.setAttribute('data-mode', 'custom');
            if (reset) {
                reset.hidden = false;
            }
            return;
        }
        if (area.getAttribute('data-mode') === 'custom') {
            customPrompt = area.value;
        }
        area.value = defaultPrompt;
        area.readOnly = true;
        area.removeAttribute('name');
        area.setAttribute('data-mode', 'default');
        if (reset) {
            reset.hidden = true;
        }
    }

    if (toggle) {
        toggle.addEventListener('change', showPromptMode);
    }
    if (reset && area) {
        reset.addEventListener('click', function () {
            area.value = defaultPrompt;
            customPrompt = defaultPrompt;
        });
    }

    var boxes = Array.prototype.slice.call(document.querySelectorAll('input.odella-report-lang'));
    function syncLanguages() {
        var checked = boxes.filter(function (box) { return box.checked; });
        boxes.forEach(function (box) {
            box.disabled = !box.checked && checked.length >= 3;
        });
    }
    boxes.forEach(function (box) {
        box.addEventListener('change', function () {
            var checked = boxes.filter(function (other) { return other.checked; });
            if (checked.length === 0) {
                box.checked = true;
            }
            syncLanguages();
        });
    });
    syncLanguages();

    function redact(message, secret) {
        if (!secret || String(secret).length < 8) {
            return message;
        }
        return String(message).split(String(secret)).join('[redacted]');
    }

    function testKey(which, inputId, statusId) {
        var status = document.getElementById(statusId);
        var input = document.getElementById(inputId);
        if (!status) {
            return;
        }
        var candidate = input ? input.value : '';
        status.textContent = 'Testing…';
        var body = new URLSearchParams();
        body.set('action', 'odella_test_api_key');
        body.set('nonce', nonce);
        body.set('which', which);
        body.set('candidate_key', candidate);
        if (which === 'gemini') {
            var model = document.getElementById('odella-gemini-model');
            body.set('model', model ? model.value : '');
        }
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            var message = data && data.data && data.data.message ? data.data.message : 'Request failed';
            status.textContent = redact(message, candidate);
        }).catch(function () {
            status.textContent = 'Request failed';
        });
    }

    var psiButton = document.getElementById('odella-test-psi-key');
    if (psiButton) {
        psiButton.addEventListener('click', function () {
            testKey('psi', 'odella-psi-key', 'odella-psi-key-status');
        });
    }
    var geminiButton = document.getElementById('odella-test-gemini-key');
    if (geminiButton) {
        geminiButton.addEventListener('click', function () {
            testKey('gemini', 'odella-gemini-key', 'odella-gemini-key-status');
        });
    }
});
JS;
    return 'window.odellaDefaultPrompt = ' . $default . ";\n" . $script;
}

function odella_redact_secret(string $message, string $secret): string
{
    if ($secret === '') {
        return $message;
    }
    $message = str_replace($secret, '[redacted]', $message);
    $encoded = rawurlencode($secret);
    if ($encoded !== '' && $encoded !== $secret) {
        $message = str_replace($encoded, '[redacted]', $message);
    }
    return $message;
}

/**
 * @param mixed $body
 */
function odella_api_error_message($body, string $fallback, string $secret): string
{
    $message = $fallback;
    if (is_array($body) && isset($body['error']['message']) && is_string($body['error']['message']) && $body['error']['message'] !== '') {
        $message = $body['error']['message'];
    }
    return odella_redact_secret($message, $secret);
}

function odella_ajax_test_api_key(): void
{
    check_ajax_referer('odella_settings_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to test API keys.']);
    }

    $which = isset($_POST['which']) && is_string($_POST['which']) ? sanitize_key(wp_unslash($_POST['which'])) : '';
    $candidate = isset($_POST['candidate_key']) && is_string($_POST['candidate_key'])
        ? trim(wp_unslash($_POST['candidate_key']))
        : '';
    $settings = odella_get_speed_settings();

    if ($which === 'psi') {
        $key = $candidate !== '' ? $candidate : odella_decrypt_secret($settings['psi_key_enc']);
        if ($key === '') {
            wp_send_json_error(['message' => 'No PageSpeed key is set.']);
        }
        odella_test_pagespeed_key($key);
    }

    if ($which === 'gemini') {
        $key = $candidate !== '' ? $candidate : odella_decrypt_secret($settings['gemini_key_enc']);
        if ($key === '') {
            wp_send_json_error(['message' => 'No Gemini key is set.']);
        }
        $posted_model = isset($_POST['model']) && is_string($_POST['model']) ? wp_unslash($_POST['model']) : '';
        $model = odella_sanitize_gemini_model($posted_model !== '' ? $posted_model : $settings['gemini_model']);
        odella_test_gemini_key($key, $model);
    }

    wp_send_json_error(['message' => 'Choose a PageSpeed or Gemini key to test.']);
}

function odella_test_pagespeed_key(string $key): void
{
    $url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=https%3A%2F%2Fodella.ge%2Fka%2F&strategy=mobile&category=performance&key=' . rawurlencode($key);
    $response = wp_remote_get($url, ['timeout' => 60]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => odella_redact_secret($response->get_error_message(), $key)]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code === 200 && is_array($body) && !isset($body['error'])) {
        $score = $body['lighthouseResult']['categories']['performance']['score'] ?? null;
        $shown = is_numeric($score) ? (string) (int) round((float) $score * 100) : 'n/a';
        wp_send_json_success(['message' => 'PageSpeed key valid (HTTP 200, score ' . $shown . ')']);
    }

    $fallback = $code > 0 ? 'PageSpeed request failed (HTTP ' . $code . ').' : 'PageSpeed request failed.';
    wp_send_json_error(['message' => odella_api_error_message($body, $fallback, $key)]);
}

function odella_test_gemini_key(string $key, string $model): void
{
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $payload = wp_json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => 'Reply with the single word OK.'],
                ],
            ],
        ],
    ]);
    if (!is_string($payload)) {
        wp_send_json_error(['message' => 'Could not build the Gemini request.']);
    }

    $response = wp_remote_post($endpoint, [
        'timeout' => 20,
        'headers' => [
            'x-goog-api-key' => $key,
            'Content-Type' => 'application/json',
        ],
        'body' => $payload,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => odella_redact_secret($response->get_error_message(), $key)]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $reply = '';
    if (
        is_array($body)
        && isset($body['candidates'][0]['content']['parts'][0]['text'])
        && is_string($body['candidates'][0]['content']['parts'][0]['text'])
    ) {
        $reply = trim($body['candidates'][0]['content']['parts'][0]['text']);
    }
    $collapsed = preg_replace('/\s+/u', ' ', $reply);
    $reply = is_string($collapsed) ? trim($collapsed) : '';
    $reply = odella_redact_secret($reply, $key);

    if ($code === 200 && $reply !== '' && (!is_array($body) || !isset($body['error']))) {
        wp_send_json_success(['message' => 'Gemini key valid (' . $model . ' replied: ' . $reply . ')']);
    }

    $fallback = $code > 0 ? 'Gemini request failed (HTTP ' . $code . ').' : 'Gemini request failed.';
    if ($code === 200 && $reply === '') {
        $fallback = 'Gemini returned no reply.';
    }
    wp_send_json_error(['message' => odella_api_error_message($body, $fallback, $key)]);
}
