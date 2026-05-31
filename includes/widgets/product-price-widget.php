<?php
/**
 * Widget: WooCommerce Product Price / Out of Stock
 *
 * Features
 * ─────────
 * • Displays standard WooCommerce price HTML if in stock.
 * • Displays a custom "Out of Stock" message if inventory is zero.
 * • Separate style controls for regular price, sale price (strikethrough), and OOS text.
 * • Loop compatible via get_the_ID().
 */

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

class Product_Price_Widget extends Widget_Base
{

    // ── Identity ───────────────────────────────────────────────────────────────

    public function get_name(): string
    {
        return 'nmj_product_price';
    }
    public function get_title(): string
    {
        return esc_html__('Product Price', 'nmj');
    }
    public function get_icon(): string
    {
        return 'eicon-product-price';
    }
    public function get_categories(): array
    {
        return ['nmj-widgets'];
    }
    public function get_keywords(): array
    {
        return ['woocommerce', 'price', 'cost', 'out of stock'];
    }

    public function get_script_depends(): array
    {
        return ['nmj-add-to-cart'];
    }

    // ── Controls ───────────────────────────────────────────────────────────────

    protected function register_controls(): void
    {

        // ── SECTION: Content ──────────────────────────────────────────────────
        $this->start_controls_section('section_content', [
            'label' => esc_html__('Content', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('product_id', [
            'label' => esc_html__('Product ID (optional)', 'nmj'),
            'description' => esc_html__('Leave blank to auto-detect inside loops/single pages.', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'min' => 0,
            'default' => '',
        ]);

        $this->add_control('out_of_stock_text', [
            'label' => esc_html__('Out of Stock Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Out of Stock', 'nmj'),
            'dynamic' => ['active' => true],
        ]);

        $this->add_responsive_control('alignment', [
            'label' => esc_html__('Alignment', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'left' => ['title' => esc_html__('Left', 'nmj'), 'icon' => 'eicon-text-align-left'],
                'center' => ['title' => esc_html__('Center', 'nmj'), 'icon' => 'eicon-text-align-center'],
                'right' => ['title' => esc_html__('Right', 'nmj'), 'icon' => 'eicon-text-align-right'],
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-product-price-wrapper' => 'text-align: {{VALUE}};',
            ],
        ]);

        $this->add_control('price_tag', [
            'label' => esc_html__('HTML Tag', 'cew'),
            'type' => Controls_Manager::SELECT,
            'default' => 'div',
            'options' => [
                'div' => 'div',
                'h1' => 'h1',
                'h2' => 'h2',
                'h3' => 'h3',
                'h4' => 'h4',
                'h5' => 'h5',
                'h6' => 'h6',
                'p' => 'p',
                'span' => 'span',
            ],
        ]);

        $this->end_controls_section();

        // ══════════════════════════════════════════════════════════════════════
        // STYLE TAB
        // ══════════════════════════════════════════════════════════════════════

        // ── SECTION: Current / Regular Price ──────────────────────────────────
        // This targets the main displayed price — both simple products and
        // the sale price on discounted products (the <ins> element).
        $this->start_controls_section('section_style_price', [
            'label' => esc_html__('Price (In Stock)', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('heading_current_price', [
            'label' => esc_html__('Current Price', 'nmj'),
            'type' => Controls_Manager::HEADING,
        ]);

        $this->add_control('price_color', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                // Simple product price
                '{{WRAPPER}} .nmj-price-in-stock .price > .amount' => 'color: {{VALUE}};',
                // Sale product — the active (green) price lives in <ins>
                '{{WRAPPER}} .nmj-price-in-stock .price ins .amount' => 'color: {{VALUE}};',
                '{{WRAPPER}} .nmj-price-in-stock .price ins' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'price_typography',
            'selector' => '{{WRAPPER}} .nmj-price-in-stock .price > .amount, {{WRAPPER}} .nmj-price-in-stock .price ins .amount',
        ]);

        // ── Original / Strikethrough Price ────────────────────────────────
        // Only visible when a sale price is set — lives in the <del> element.
        $this->add_control('heading_original_price', [
            'label' => esc_html__('Original Price (strikethrough)', 'nmj'),
            'type' => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        $this->add_control('sale_original_color', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#999999',
            'selectors' => [
                '{{WRAPPER}} .nmj-price-in-stock .price del' => 'color: {{VALUE}};',
                '{{WRAPPER}} .nmj-price-in-stock .price del .amount' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'sale_original_typography',
            'selector' => '{{WRAPPER}} .nmj-price-in-stock .price del .amount',
        ]);

        $this->add_control('sale_original_decoration', [
            'label' => esc_html__('Strikethrough Style', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'line-through',
            'options' => [
                'line-through' => esc_html__('Line Through', 'nmj'),
                'none' => esc_html__('None', 'nmj'),
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-price-in-stock .price del .amount' => 'text-decoration: {{VALUE}};',
                '{{WRAPPER}} .nmj-price-in-stock .price del' => 'text-decoration: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();

        // ── SECTION: Out of Stock Style ────────────────────────────────────────
        $this->start_controls_section('section_style_oos', [
            'label' => esc_html__('Out of Stock Text', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('oos_color', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#d9534f',
            'selectors' => [
                '{{WRAPPER}} .nmj-price-oos' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'oos_typography',
            'selector' => '{{WRAPPER}} .nmj-price-oos',
        ]);

        $this->end_controls_section();
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();

        $product_id = !empty($settings['product_id'])
            ? absint($settings['product_id'])
            : get_the_ID();

        if (!$product_id) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<div class="nmj-product-price-wrapper"><p style="color:orange;font-size:13px;">⚠ <em>Set a Product ID or use inside a loop.</em></p></div>';
            }
            return;
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            return;
        }

        $allowed_tags = ['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span'];
        $tag = in_array($settings['price_tag'], $allowed_tags, true) ? $settings['price_tag'] : 'div';
        $in_stock = $product->is_in_stock();

        // Print once per page: override theme loading animations on .price
        // elements inside this widget. Many themes fade-in or skeleton-load
        // .price nodes; when JS swaps the HTML the new nodes restart in the
        // "loading" state and get stuck there.
        static $css_printed = false;
        if (!$css_printed) {
            echo '<style>.nmj-price-in-stock .price,.nmj-price-in-stock .price *{opacity:1!important;visibility:visible!important;animation:none!important;transition:none!important}</style>';
            $css_printed = true;
        }
        ?>
        <div class="nmj-product-price-wrapper nmj-price-product-<?php echo esc_attr($product_id); ?>" data-product_id="<?php echo esc_attr($product_id); ?>">
            <?php if ($in_stock): ?>
                <?php $price_html = $product->get_price_html(); ?>
                <<?php echo $tag; ?> class="nmj-price-in-stock" data-original-price="<?php echo esc_attr($price_html); ?>">
                    <?php echo wp_kses_post($price_html); ?>
                </<?php echo $tag; ?>>
            <?php else: ?>
                <<?php echo $tag; ?> class="nmj-price-oos">
                    <?php echo esc_html($settings['out_of_stock_text']); ?>
                </<?php echo $tag; ?>>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function content_template(): void
    {
        ?>
        <#
        var oosText=settings.out_of_stock_text || 'Out of Stock' ;
        var allowedTags=['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span' ];
        var tag=allowedTags.indexOf(settings.price_tag)> -1 ? settings.price_tag : 'div';
        #>
            <div class="nmj-product-price-wrapper">
                <{{{ tag }}} class="nmj-price-in-stock">
                    <span class="price">
                        <del><span class="amount"><span class="woocommerce-Price-currencySymbol">$</span>29.99</span></del>
                        <ins><span class="amount"><span class="woocommerce-Price-currencySymbol">$</span>19.99</span></ins>
                    </span>
                </{{{ tag }}}>
            </div>
            <?php
    }
}