<?php
/**
 * Plugin Name:       Odella Elementor Widgets
 * Description:       A growing library of custom Elementor widgets with WooCommerce support.
 * Version:           1.0.40
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            NMJ
 * License:           GPL-2.0-or-later
 *
 * Elementor tested up to: 3.25
 * WC requires at least:   8.0
 * WC tested up to:        9.x
 */

defined('ABSPATH') || exit;

// ── Constants ──────────────────────────────────────────────────────────────────
define('NMJ_VERSION', '1.0.40');
define('NMJ_FILE', __FILE__);
define('NMJ_PATH', plugin_dir_path(__FILE__));
define('NMJ_URL', plugin_dir_url(__FILE__));
define('NMJ_WIDGETS_DIR', NMJ_PATH . 'includes/widgets/');

// ── Bootstrap ──────────────────────────────────────────────────────────────────
/**
 * Fire up the plugin after both Elementor and WooCommerce are loaded.
 * Using `plugins_loaded` with a late priority keeps us safely after
 * all dependencies are registered.
 */
add_action('plugins_loaded', static function () {

    // 1. Guard: Elementor must be active.
    if (!did_action('elementor/loaded')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>'
                . '<strong>Custom Elementor Widgets</strong> requires the Elementor plugin to be active.'
                . '</p></div>';
        });
        return;
    }

    // 2. Guard: WooCommerce must be active.
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>'
                . '<strong>Custom Elementor Widgets</strong> requires WooCommerce to be active.'
                . '</p></div>';
        });
        return;
    }

    // 3. Declare HPOS compatibility (WooCommerce 7+).
    add_action('before_woocommerce_init', static function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                NMJ_FILE,
                true
            );
        }
    });

    // 4. Load the plugin core.
    require_once NMJ_PATH . 'includes/plugin.php';
    \NMJ\Plugin::instance();

}, 20);
