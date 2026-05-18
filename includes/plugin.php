<?php
// plugin.php

/**
 * Plugin core: widget registration and shared setup.
 */

namespace NMJ;

defined('ABSPATH') || exit;

final class Plugin
{

    // ── Singleton ──────────────────────────────────────────────────────────────

    private static ?Plugin $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->register_hooks();
    }

    // ── Hooks ──────────────────────────────────────────────────────────────────

    private function register_hooks(): void
    {
        add_action('elementor/elements/categories_registered', [$this, 'register_widget_categories']);
        add_action('elementor/widgets/register', [$this, 'register_widgets']);
        add_action('elementor/frontend/after_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_assets']);

        add_action('wp_ajax_nmj_load_quick_view', [$this, 'load_quick_view_template']);
        add_action('wp_ajax_nopriv_nmj_load_quick_view', [$this, 'load_quick_view_template']);
    }

    // ── Quick View AJAX Handler ────────────────────────────────────────────────

    public function load_quick_view_template(): void
    {
        if (! isset($_POST['product_id']) || ! isset($_POST['template_id'])) {
            wp_send_json_error('Missing data');
        }

        $product_id = absint($_POST['product_id']);
        $template_id = absint($_POST['template_id']);

        $template_post = get_post($template_id);
        if (!$template_post || $template_post->post_status !== 'publish' || $template_post->post_type !== 'elementor_library') {
            wp_send_json_error('Invalid template ID');
        }

        global $post;
        $post = get_post($product_id);
        setup_postdata($post);

        ob_start();
        echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($template_id);
        $html = ob_get_clean();

        wp_reset_postdata();
        wp_send_json_success(['html' => $html]);
    }

    // ── Widget category ────────────────────────────────────────────────────────

    public function register_widget_categories(\Elementor\Elements_Manager $manager): void
    {
        $manager->add_category(
            'nmj-widgets',
            [
                'title' => esc_html__('Odella Widgets', 'nmj'),
                'icon' => 'eicon-woocommerce',
            ]
        );
    }

    // ── Widget registration ────────────────────────────────────────────────────

    private array $widgets = [
        'add-to-cart-widget'      => \NMJ\Widgets\Add_To_Cart_Widget::class,
        'product-price-widget'    => \NMJ\Widgets\Product_Price_Widget::class,
        'full-page-scroll-widget' => \NMJ\Widgets\Full_Page_Scroll_Widget::class,
        'gallery-grid-widget'     => \NMJ\Widgets\Gallery_Grid_Widget::class,
        'variation-selector-widget' => \NMJ\Widgets\Variation_Selector_Widget::class,
    ];

    public function register_widgets(\Elementor\Widgets_Manager $manager): void
    {
        foreach ($this->widgets as $file => $class) {
            $path = NMJ_WIDGETS_DIR . $file . '.php';

            if (!file_exists($path)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("NMJ: Widget file not found — {$path}");
                }
                continue;
            }

            require_once $path;

            if (class_exists($class)) {
                $manager->register(new $class());
            }
        }
    }

    // ── Assets ─────────────────────────────────────────────────────────────────

    private function get_asset_version(string $path): string
    {
        return (defined('WP_DEBUG') && WP_DEBUG && file_exists($path)) ? (string) filemtime($path) : NMJ_VERSION;
    }

    public function enqueue_frontend_assets(): void
    {
        if (function_exists('WC')) {
            wp_enqueue_script('wc-add-to-cart');
        }

        $css_path    = NMJ_PATH . 'assets/css/odella-widgets.css';
        $js_path     = NMJ_PATH . 'assets/js/add-to-cart.js';
        $fps_js_path = NMJ_PATH . 'assets/js/full-page-scroll.js';

        wp_register_style('nmj-widgets', NMJ_URL . 'assets/css/odella-widgets.css', [], $this->get_asset_version($css_path));
        wp_enqueue_style('nmj-widgets');

        wp_register_script('nmj-add-to-cart', NMJ_URL . 'assets/js/add-to-cart.js', ['jquery', 'wc-add-to-cart'], $this->get_asset_version($js_path), true);
        wp_enqueue_script('nmj-add-to-cart');

        wp_register_script('nmj-full-page-scroll', NMJ_URL . 'assets/js/full-page-scroll.js', [], $this->get_asset_version($fps_js_path), true);
        wp_enqueue_script('nmj-full-page-scroll');
    }

    public function enqueue_editor_assets(): void
    {
        // Load the widget CSS inside the Elementor editor iframe so that
        // selector-based controls (and prefix_class) take effect live.
        $css_path = NMJ_PATH . 'assets/css/odella-widgets.css';
        wp_enqueue_style('nmj-widgets', NMJ_URL . 'assets/css/odella-widgets.css', [], $this->get_asset_version($css_path));
    }
}
