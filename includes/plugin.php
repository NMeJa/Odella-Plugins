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
        if (!isset($_POST['product_id']) || !isset($_POST['template_id'])) {
            wp_send_json_error('Missing data');
        }

        // No nonce check by design.
        //
        // This endpoint is public, read-only and idempotent: it renders an
        // already-published Elementor template against an already-published
        // product and changes no state, so there is no CSRF surface for a nonce
        // to protect. It previously verified one, but the nonce was localized
        // into the page HTML — behind a full-page cache serving markup older
        // than the 12–24h nonce lifetime, every logged-out visitor got
        // "Invalid nonce" and quick view simply stopped working.
        //
        // Authorization is enforced below instead, on the two things that
        // actually matter: the product and the template must both exist, be of
        // the expected post type, and be publicly viewable.
        $product_id = absint($_POST['product_id']);
        $template_id = absint($_POST['template_id']);

        // Guard: ensure the product exists and is actually a product post type.
        // Without this, get_post() returns null and setup_postdata(null)
        // generates PHP warnings and Elementor renders with no post context.
        $product_post = get_post($product_id);
        if (!$product_post || $product_post->post_type !== 'product') {
            wp_send_json_error('Product not found');
        }
        // Prevent unauthenticated access to draft, private, or password-protected products.
        if ($product_post->post_status !== 'publish' || post_password_required($product_post)) {
            wp_send_json_error('Product not available');
        }

        $template_post = get_post($template_id);
        if (!$template_post || $template_post->post_status !== 'publish' || $template_post->post_type !== 'elementor_library') {
            wp_send_json_error('Invalid template ID');
        }

        global $post;
        $post = $product_post;
        setup_postdata($post);

        // Wrap in try/catch/finally:
        //   - \Throwable catches both \Exception and PHP Error (e.g. Elementor type errors).
        //   - catch sends a structured JSON error so the client never gets a silent 500.
        //   - wp_reset_postdata() called in catch (before wp_die) AND in finally (success path),
        //     because wp_die() in the catch block prevents finally from running.
        $html = '';
        try {
            $html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($template_id);
        } catch (\Throwable $e) {
            wp_reset_postdata();
            wp_send_json_error('Render failed'); // calls wp_die(); finally does NOT run after this
        } finally {
            wp_reset_postdata(); // runs on the success path
        }

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
        'add-to-cart-widget' => \NMJ\Widgets\Add_To_Cart_Widget::class,
        'product-price-widget' => \NMJ\Widgets\Product_Price_Widget::class,
        'full-page-scroll-widget' => \NMJ\Widgets\Full_Page_Scroll_Widget::class,
        'gallery-grid-widget' => \NMJ\Widgets\Gallery_Grid_Widget::class,
        'variation-selector-widget' => \NMJ\Widgets\Variation_Selector_Widget::class,
        'responsive-video-widget' => \NMJ\Widgets\Responsive_Video_Widget::class,
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
        $js_path = NMJ_PATH . 'assets/js/add-to-cart.js';
        $fps_js_path = NMJ_PATH . 'assets/js/full-page-scroll.js';
        $gg_js_path = NMJ_PATH . 'assets/js/gallery-grid.js';

        // Per-widget CSS — register only; each widget declares its handle in
        // get_style_depends() so Elementor enqueues it lazily on pages that
        // actually contain the widget.
        foreach ([
            'nmj-add-to-cart'          => 'assets/css/add-to-cart.css',
            'nmj-full-page-scroll'     => 'assets/css/full-page-scroll.css',
            'nmj-gallery-grid'         => 'assets/css/gallery-grid.css',
            'nmj-responsive-video'     => 'assets/css/responsive-video.css',
            'nmj-product-price'        => 'assets/css/product-price.css',
            'nmj-variation-selector'   => 'assets/css/variation-selector.css',
        ] as $handle => $rel_path) {
            $abs = NMJ_PATH . $rel_path;
            wp_register_style($handle, NMJ_URL . $rel_path, [], $this->get_asset_version($abs));
        }

        // JS — register only; lazy-enqueued via get_script_depends().
        wp_register_script('nmj-add-to-cart', NMJ_URL . 'assets/js/add-to-cart.js', ['jquery', 'wc-add-to-cart'], $this->get_asset_version($js_path), true);

        // No nonce here — see load_quick_view_template() for why. A nonce baked
        // into cached page HTML expires and breaks quick view for every
        // logged-out visitor served from the cache.
        wp_localize_script('nmj-add-to-cart', 'nmj_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);

        // jquery + elementor-frontend: the script hooks frontend/element_ready
        // so instances arriving via AJAX (popups, filtered loops) initialise.
        wp_register_script('nmj-full-page-scroll', NMJ_URL . 'assets/js/full-page-scroll.js', ['jquery', 'elementor-frontend'], $this->get_asset_version($fps_js_path), true);

        wp_register_script('nmj-gallery-grid', NMJ_URL . 'assets/js/gallery-grid.js', ['jquery', 'elementor-frontend'], $this->get_asset_version($gg_js_path), true);

        $rv_js_path = NMJ_PATH . 'assets/js/responsive-video.js';
        wp_register_script('nmj-responsive-video', NMJ_URL . 'assets/js/responsive-video.js', [], $this->get_asset_version($rv_js_path), true);
    }

    public function enqueue_editor_assets(): void
    {
        // Load every widget CSS inside the Elementor editor iframe so that
        // selector-based controls and prefix_class take effect in live preview.
        foreach ([
            'nmj-add-to-cart'          => 'assets/css/add-to-cart.css',
            'nmj-full-page-scroll'     => 'assets/css/full-page-scroll.css',
            'nmj-gallery-grid'         => 'assets/css/gallery-grid.css',
            'nmj-responsive-video'     => 'assets/css/responsive-video.css',
            'nmj-product-price'        => 'assets/css/product-price.css',
            'nmj-variation-selector'   => 'assets/css/variation-selector.css',
        ] as $handle => $rel_path) {
            $abs = NMJ_PATH . $rel_path;
            wp_enqueue_style($handle, NMJ_URL . $rel_path, [], $this->get_asset_version($abs));
        }
    }
}
