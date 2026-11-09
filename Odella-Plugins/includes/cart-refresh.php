<?php
/**
 * Fresh Modern Cart markup on cached pages, and Georgian drawer texts.
 *
 * LiteSpeed stores the drawer HTML and moderncart_ajax_object.ajax_nonce
 * with the page. cart-refresh.js asks this route for a nonce that belongs
 * to the current visitor, then reloads the drawer.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', 'nmj_register_cart_nonce_route');
add_action('wp_enqueue_scripts', 'nmj_enqueue_cart_refresh', 20);
add_filter('gettext', 'nmj_translate_modern_cart', 10, 3);
add_filter('option_moderncart_cart', 'nmj_translate_modern_cart_option');

function nmj_register_cart_nonce_route(): void
{
    register_rest_route('odella/v1', '/cart-nonce', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'nmj_rest_cart_nonce',
    ]);

    register_rest_route('odella/v1', '/cart-items', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'nmj_rest_cart_items',
    ]);
}

/**
 * Product and variation IDs in this visitor's cart. Add-to-cart buttons
 * use it to correct the checked icon on cached pages.
 */
function nmj_rest_cart_items(): WP_REST_Response
{
    if (function_exists('wc_load_cart')) {
        wc_load_cart();
    }

    $simple = [];
    $variations = [];
    $wc = function_exists('WC') ? WC() : null;
    if ($wc && $wc->cart) {
        foreach ($wc->cart->get_cart() as $item) {
            $variation_id = (int) ($item['variation_id'] ?? 0);
            $product_id = (int) ($item['product_id'] ?? 0);
            if ($variation_id > 0) {
                $variations[] = $variation_id;
            } elseif ($product_id > 0) {
                $simple[] = $product_id;
            }
        }
    }

    $response = new WP_REST_Response([
        'simple' => array_values(array_unique($simple)),
        'variations' => array_values(array_unique($variations)),
    ]);
    $response->header('Cache-Control', 'no-store, private');
    do_action('litespeed_control_set_nocache', 'odella cart items');

    return $response;
}

function nmj_rest_cart_nonce(): WP_REST_Response
{
    if (function_exists('WC') && WC() && WC()->session === null && method_exists(WC(), 'initialize_session')) {
        WC()->initialize_session();
    }

    $response = new WP_REST_Response([
        'nonce' => wp_create_nonce('moderncart_ajax_nonce'),
    ]);
    $response->header('Cache-Control', 'no-store, private');
    do_action('litespeed_control_set_nocache', 'odella cart nonce');

    return $response;
}

function nmj_enqueue_cart_refresh(): void
{
    if (nmj_skip_cart_refresh() || !wp_script_is('moderncart-cart-js', 'enqueued')) {
        return;
    }

    $rel = 'assets/js/cart-refresh.js';
    $abs = NMJ_PATH . $rel;
    wp_enqueue_script(
        'nmj-cart-refresh',
        NMJ_URL . $rel,
        ['moderncart-cart-js'],
        file_exists($abs) ? (string) filemtime($abs) : NMJ_VERSION,
        true
    );
    wp_localize_script('nmj-cart-refresh', 'nmjCart', [
        'nonceUrl' => rest_url('odella/v1/cart-nonce'),
    ]);
}

function nmj_skip_cart_refresh(): bool
{
    if (is_admin() || isset($_GET['elementor-preview'])) {
        return true;
    }
    if (class_exists('\Elementor\Plugin')) {
        $elementor = \Elementor\Plugin::$instance;
        if ($elementor && isset($elementor->preview) && $elementor->preview->is_preview_mode()) {
            return true;
        }
    }
    return false;
}

/**
 * Language for the drawer. admin-ajax does not always have a Polylang
 * language, so the storefront referer decides English pages.
 */
function nmj_cart_language(): string
{
    if (function_exists('pll_current_language')) {
        $lang = pll_current_language();
        if (is_string($lang) && ($lang === 'ka' || $lang === 'en')) {
            return $lang;
        }
    }
    $ref = wp_get_referer();
    if (is_string($ref) && preg_match('#/en(/|$)#', $ref)) {
        return 'en';
    }
    return 'ka';
}

/**
 * English source → Georgian. The live drawer title and checkout button are
 * saved in Modern Cart's settings ("Review Your Cart", "Proceed to Checkout"),
 * so those exact values are included with the gettext originals.
 *
 * @return array<string, string>
 */
function nmj_modern_cart_strings(): array
{
    return [
        'Your Cart Is Empty' => 'თქვენი კალათა ცარიელია',
        "Check out our shop to see what's available" => 'ნახეთ ჩვენი მაღაზია.',
        "Check out our shop to see what's available." => 'ნახეთ ჩვენი მაღაზია.',
        'Your cart is empty. Shop now' => 'კალათა ცარიელია. დაიწყეთ შოპინგი',
        'Total' => 'ჯამი',
        'Remove' => 'წაშლა',
        'Edit Cart' => 'კალათის რედაქტირება',
        'Your Cart' => 'თქვენი კალათა',
        'Review Your Cart' => 'თქვენი კალათა',
        'Checkout' => 'გაფორმება',
        'Proceed to checkout' => 'გაფორმება',
        'Proceed to Checkout' => 'გაფორმება',
        'Checkout now' => 'გაფორმება',
        'Somethings wrong! try again later' => 'რაღაც შეცდომაა, სცადეთ თავიდან',
    ];
}

function nmj_translate_modern_cart(string $translation, string $text, string $domain): string
{
    if ($domain !== 'modern-cart' || nmj_cart_language() !== 'ka') {
        return $translation;
    }
    if (is_admin() && !wp_doing_ajax()) {
        return $translation;
    }
    $map = nmj_modern_cart_strings();
    return $map[$text] ?? $translation;
}

/**
 * @param mixed $value
 * @return mixed
 */
function nmj_translate_modern_cart_option($value)
{
    if (!is_array($value) || nmj_cart_language() !== 'ka') {
        return $value;
    }
    if (is_admin() && !wp_doing_ajax()) {
        return $value;
    }
    $map = nmj_modern_cart_strings();
    foreach (['main_title', 'checkout_button_label', 'empty_cart_button_text'] as $key) {
        if (isset($value[$key]) && is_string($value[$key]) && isset($map[$value[$key]])) {
            $value[$key] = $map[$value[$key]];
        }
    }
    return $value;
}
