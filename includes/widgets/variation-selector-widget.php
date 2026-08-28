<?php

/**
 * Widget: WooCommerce Variation Selector
 *
 * Renders the native WooCommerce variations_form for variable products.
 * Works as the event backbone for the Gallery Grid and Add to Cart widgets —
 * WC's wc-add-to-cart-variation.js fires `found_variation` and `reset_data`
 * events that the other widgets listen to.
 *
 * Swatch plugins (e.g. Variation Swatches for WooCommerce) automatically
 * transform the rendered <select> dropdowns into swatches.
 *
 * Controls
 * ────────
 * • Show quantity input  — renders a WC-style qty spinner inside the form
 * • Show native ATC btn  — toggle the native "Add to cart" button
 *   (disable when using the custom Add to Cart widget on the same page)
 * • Out-of-stock display — show or hide variations that are out of stock
 * • Reset link text      — label for the "Clear" link beside each attribute
 */

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

class Variation_Selector_Widget extends Widget_Base
{

    // ── Identity ───────────────────────────────────────────────────────────────

    public function get_name(): string
    {
        return 'nmj_variation_selector';
    }
    public function get_title(): string
    {
        return esc_html__('Variation Selector', 'nmj');
    }
    public function get_icon(): string
    {
        return 'eicon-product-tabs';
    }
    public function get_categories(): array
    {
        return ['nmj-widgets'];
    }
    public function get_keywords(): array
    {
        return ['variation', 'variable', 'woocommerce', 'select', 'swatch', 'attribute'];
    }

    public function get_script_depends(): array
    {
        return ['wc-add-to-cart-variation'];
    }

    public function get_style_depends(): array
    {
        return ['nmj-variation-selector'];
    }

    /**
     * Never cache this widget's HTML — the embedded data-product_variations
     * payload carries live prices and stock state for every variation.
     */
    protected function is_dynamic_content(): bool
    {
        return true;
    }

    // ── Controls ───────────────────────────────────────────────────────────────

    protected function register_controls(): void
    {

        // ── SECTION: Product ──────────────────────────────────────────────────
        $this->start_controls_section('section_product', [
            'label' => esc_html__('Product', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('product_id', [
            'label' => esc_html__('Product ID (optional)', 'nmj'),
            'description' => esc_html__('Leave blank to auto-detect from the current post.', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'min' => 0,
            'default' => '',
        ]);

        $this->end_controls_section();

        // ── SECTION: Form Options ─────────────────────────────────────────────
        $this->start_controls_section('section_options', [
            'label' => esc_html__('Form Options', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('show_quantity', [
            'label' => esc_html__('Show Quantity Input', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'no',
        ]);

        $this->add_control('show_native_atc', [
            'label' => esc_html__('Show Native "Add to Cart" Button', 'nmj'),
            'description' => esc_html__('Disable this when you use the custom Add to Cart widget on the same page.', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'no',
        ]);

        $this->add_control('show_out_of_stock', [
            'label' => esc_html__('Show Out-of-Stock Variations', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->add_control('show_description', [
            'label' => esc_html__('Show Variation Description', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->add_control('show_variation_price', [
            'label' => esc_html__('Show Variation Price', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->end_controls_section();

        // ── SECTION: Clear Link ───────────────────────────────────────────────
        $this->start_controls_section('section_clear_link', [
            'label' => esc_html__('Clear Link', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('clear_position', [
            'label' => esc_html__('Placement', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'default' => 'onbottom',
            'options' => [
                'infront' => ['title' => esc_html__('Left', 'nmj'), 'icon' => 'eicon-h-align-left'],
                'ontop' => ['title' => esc_html__('Top', 'nmj'), 'icon' => 'eicon-v-align-top'],
                'onbottom' => ['title' => esc_html__('Bottom', 'nmj'), 'icon' => 'eicon-v-align-bottom'],
                'after' => ['title' => esc_html__('Right', 'nmj'), 'icon' => 'eicon-h-align-right'],
            ],
            'toggle' => false,
        ]);

        $this->add_control('clear_text', [
            'label' => esc_html__('Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Clear', 'nmj'),
            'dynamic' => ['active' => true],
        ]);

        $this->add_control('clear_tag', [
            'label' => esc_html__('HTML Tag', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'span',
            'options' => [
                'span' => 'span',
                'div' => 'div',
                'p' => 'p',
                'h1' => 'H1',
                'h2' => 'H2',
                'h3' => 'H3',
                'h4' => 'H4',
                'h5' => 'H5',
                'h6' => 'H6',
            ],
        ]);

        $this->add_control('clear_icon', [
            'label' => esc_html__('Icon', 'nmj'),
            'type' => \Elementor\Controls_Manager::ICONS,
        ]);

        $this->add_control('clear_icon_position', [
            'label' => esc_html__('Icon Position', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'left' => ['title' => esc_html__('Before Text', 'nmj'), 'icon' => 'eicon-h-align-left'],
                'right' => ['title' => esc_html__('After Text', 'nmj'), 'icon' => 'eicon-h-align-right'],
            ],
            'default' => 'left',
            'toggle' => false,
            'condition' => [
                'clear_icon[value]!' => '',
            ],
        ]);

        $this->end_controls_section();

        // ══════════════════════════════════════════════════════════════════════
        // STYLE TAB
        // ══════════════════════════════════════════════════════════════════════

        // ── SECTION: Labels ───────────────────────────────────────────────────
        $this->start_controls_section('section_style_labels', [
            'label' => esc_html__('Attribute Labels', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'label_typography',
            'selector' => '{{WRAPPER}} .nmj-variation-label',
        ]);

        $this->add_control('label_color', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .nmj-variation-label' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('label_spacing', [
            'label' => esc_html__('Bottom Spacing', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'default' => ['size' => 6, 'unit' => 'px'],
            'selectors' => [
                '{{WRAPPER}} .nmj-variation-label' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ── SECTION: Select Dropdowns ─────────────────────────────────────────
        $this->start_controls_section('section_style_selects', [
            'label' => esc_html__('Select Dropdowns', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('select_notice', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => '<p style="font-size:12px;color:#6b7280;margin:0;">
                If a swatch plugin is active (e.g. Variation Swatches for WooCommerce),
                it replaces these <code>&lt;select&gt;</code> elements with swatches automatically.
                Style the swatches via the plugin\'s settings or Elementor Custom CSS.
            </p>',
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'select_typography',
            'selector' => '{{WRAPPER}} .nmj-variations-form select',
        ]);

        $this->add_control('select_text_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .nmj-variations-form select' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('select_bg_color', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .nmj-variations-form select' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('select_border_color', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#d1d5db',
            'selectors' => [
                '{{WRAPPER}} .nmj-variations-form select' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('select_border_radius', [
            'label' => esc_html__('Border Radius', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em'],
            'selectors' => [
                '{{WRAPPER}} .nmj-variations-form select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_control('select_padding', [
            'label' => esc_html__('Padding', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em'],
            'default' => ['top' => '8', 'right' => '12', 'bottom' => '8', 'left' => '12', 'unit' => 'px', 'isLinked' => false],
            'selectors' => [
                '{{WRAPPER}} .nmj-variations-form select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ── SECTION: Rows Spacing ─────────────────────────────────────────────
        $this->start_controls_section('section_style_rows', [
            'label' => esc_html__('Row Spacing', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('row_gap', [
            'label' => esc_html__('Gap Between Attribute Rows', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'default' => ['size' => 16, 'unit' => 'px'],
            'selectors' => [
                '{{WRAPPER}} .nmj-variation-row' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ── SECTION: Clear Link Style ─────────────────────────────────────────
        $this->start_controls_section('section_style_clear', [
            'label' => esc_html__('Clear Link', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('clear_link_gap', [
            'label' => esc_html__('Gap from Variations', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'selectors' => [
                '{{WRAPPER}} .nmj-variations-layout' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('clear_alignment', [
            'label' => esc_html__('Alignment', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'left' => ['title' => esc_html__('Left', 'nmj'), 'icon' => 'eicon-text-align-left'],
                'center' => ['title' => esc_html__('Center', 'nmj'), 'icon' => 'eicon-text-align-center'],
                'right' => ['title' => esc_html__('Right', 'nmj'), 'icon' => 'eicon-text-align-right'],
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation-wrapper' => 'text-align: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'clear_typography',
            'selector' => '{{WRAPPER}} .nmj-clear-inner',
        ]);

        $this->start_controls_tabs('tabs_clear_style');

        // NORMAL
        $this->start_controls_tab('tab_clear_normal', [
            'label' => esc_html__('Normal', 'nmj'),
        ]);

        $this->add_control('clear_text_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#9ca3af',
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation .nmj-clear-inner' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('clear_icon_color', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation .nmj-clear-icon i' => 'color: {{VALUE}};',
                '{{WRAPPER}} .nmj-reset-variation .nmj-clear-icon svg' => 'fill: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        // HOVER
        $this->start_controls_tab('tab_clear_hover', [
            'label' => esc_html__('Hover', 'nmj'),
        ]);

        $this->add_control('clear_text_color_hover', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation:hover .nmj-clear-inner' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('clear_icon_color_hover', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation:hover .nmj-clear-icon i' => 'color: {{VALUE}};',
                '{{WRAPPER}} .nmj-reset-variation:hover .nmj-clear-icon svg' => 'fill: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();
        $this->end_controls_tabs();

        $this->add_responsive_control('clear_icon_size', [
            'label' => esc_html__('Icon Size', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'separator' => 'before',
            'size_units' => ['px', 'em'],
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation .nmj-clear-icon i' => 'font-size: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .nmj-reset-variation .nmj-clear-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('clear_icon_spacing', [
            'label' => esc_html__('Icon Spacing', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'selectors' => [
                '{{WRAPPER}} .nmj-reset-variation .nmj-clear-icon' => 'margin-right: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ── SECTION: Variation Description ────────────────────────────────────
        $this->start_controls_section('section_style_desc', [
            'label' => esc_html__('Variation Description', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'desc_typography',
            'selector' => '{{WRAPPER}} .nmj-variation-description',
        ]);

        $this->add_control('desc_color', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#6b7280',
            'selectors' => [
                '{{WRAPPER}} .nmj-variation-description' => 'color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();

        // ── SECTION: Variation Price ──────────────────────────────────────────
        $this->start_controls_section('section_style_price', [
            'label' => esc_html__('Variation Price', 'nmj'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'price_typography',
            'selector' => '{{WRAPPER}} .woocommerce-variation-price, {{WRAPPER}} .woocommerce-variation-price .price, {{WRAPPER}} .woocommerce-variation-price .price .amount',
        ]);

        $this->add_control('price_color', [
            'label'     => esc_html__('Color', 'nmj'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .woocommerce-variation-price' => 'color: {{VALUE}};',
                '{{WRAPPER}} .woocommerce-variation-price .price' => 'color: {{VALUE}};',
                '{{WRAPPER}} .woocommerce-variation-price .price .amount' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('price_spacing', [
            'label'      => esc_html__('Bottom Spacing', 'nmj'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'selectors'  => [
                '{{WRAPPER}} .woocommerce-variation-price' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();

        // ── Resolve product ────────────────────────────────────────────────────
        $product_id = !empty($settings['product_id'])
            ? absint($settings['product_id'])
            : get_the_ID();

        if (!$product_id) {
            $this->editor_notice('⚠ Set a Product ID or place this widget on a product page.');
            return;
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            $this->editor_notice('⚠ Product not found (ID: ' . esc_html($product_id) . ').');
            return;
        }

        if (!$product->is_type('variable')) {
            $this->editor_notice('⚠ Product #' . esc_html($product_id) . ' is not a variable product. This widget only works with variable products.');
            return;
        }

        /** @var \WC_Product_Variable $product */

        $show_quantity = ($settings['show_quantity'] ?? 'no') === 'yes';
        $show_native_atc = ($settings['show_native_atc'] ?? 'no') === 'yes';
        $show_oos = ($settings['show_out_of_stock'] ?? 'yes') === 'yes';
        $show_description = ($settings['show_description'] ?? 'yes') === 'yes';
        $show_var_price = ($settings['show_variation_price'] ?? 'yes') === 'yes';
        $widget_id = $this->get_id();

        $clear_pos = $settings['clear_position'] ?? 'onbottom';
        $clear_text = $settings['clear_text'] ?? 'Clear';
        $clear_tag = $settings['clear_tag'] ?? 'span';
        $icon_pos = $settings['clear_icon_position'] ?? 'left';

        $has_icon = !empty($settings['clear_icon']['value']);
        $has_text = !empty($clear_text);

        $clear_html = '';
        if ($has_icon || $has_text) {
            ob_start();
            ?>
            <div class="nmj-reset-variation-wrapper">
                <a class="reset_variations nmj-reset-variation" href="#">
                    <<?php echo esc_attr($clear_tag); ?> class="nmj-clear-inner<?php echo $icon_pos === 'right' ? ' nmj-clear-inner--icon-right' : ''; ?>">
                        <?php if ($has_icon): ?>
                            <span class="nmj-clear-icon">
                                <?php \Elementor\Icons_Manager::render_icon($settings['clear_icon'], ['aria-hidden' => 'true']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($has_text): ?>
                            <span class="nmj-clear-text"><?php echo esc_html($clear_text); ?></span>
                        <?php endif; ?>
                    </<?php echo esc_attr($clear_tag); ?>>
                </a>
            </div>
            <?php
            $clear_html = ob_get_clean();
        }

        // ── Build variation data ───────────────────────────────────────────────
        // get_available_variation_data() returns the full array WC uses for its
        // JS form, including prices, stock, attributes, and image data.
        $available_variations = $product->get_available_variations();

        // Optionally filter out OOS variations from the JS payload so the form
        // automatically disables them client-side.
        if (!$show_oos) {
            $available_variations = array_filter(
                $available_variations,
                fn($v) => $v['is_in_stock']
            );
            $available_variations = array_values($available_variations);
        }

        $variation_attributes = $product->get_variation_attributes(); // [ 'pa_color' => [ 'red', 'blue' ] ]

        // Presentation toggles ride on modifier classes resolved by
        // assets/css/variation-selector.css, rather than a per-instance <style>
        // block echoed into the body on every render.
        $wrap_classes = 'nmj-variation-selector-wrap';
        if (!$show_description) {
            $wrap_classes .= ' nmj-variation-selector-wrap--no-description';
        }
        if (!$show_var_price) {
            $wrap_classes .= ' nmj-variation-selector-wrap--no-price';
        }
        ?>

        <div class="<?php echo esc_attr($wrap_classes); ?>" id="nmj-vs-<?php echo esc_attr($widget_id); ?>">

            <form class="variations_form cart nmj-variations-form" action="<?php echo esc_url($product->add_to_cart_url()); ?>"
                method="post" enctype="multipart/form-data" data-product_id="<?php echo esc_attr($product_id); ?>"
                data-product_variations="<?php echo esc_attr(wp_json_encode($available_variations)); ?>">

                <?php do_action('woocommerce_before_variations_form'); ?>

                <?php if (empty($available_variations) && false !== $available_variations): ?>
                    <p class="stock out-of-stock">
                        <?php esc_html_e('Sorry, this product is unavailable. Please choose a different combination.', 'woocommerce'); ?>
                    </p>
                <?php else: ?>

                    <?php
                    // 'infront'/'after' sit the clear link beside the attribute
                    // rows; 'ontop'/'onbottom' stack it above or below.
                    $layout_modifier = ($clear_pos === 'infront' || $clear_pos === 'after')
                        ? 'nmj-variations-layout--inline'
                        : 'nmj-variations-layout--stacked';
                    ?>
                    <div class="nmj-variations-layout <?php echo esc_attr($layout_modifier); ?>">

                        <?php if ($clear_pos === 'infront' || $clear_pos === 'ontop')
                            echo $clear_html; ?>

                        <div class="variations nmj-variations-table">
                            <?php foreach ($variation_attributes as $attribute_name => $options): ?>
                                <div class="nmj-variation-row">

                                    <label class="nmj-variation-label" for="<?php echo esc_attr(sanitize_title($attribute_name)); ?>">
                                        <?php echo esc_html(wc_attribute_label($attribute_name)); ?>
                                    </label>

                                    <div class="nmj-variation-select-wrap">
                                        <?php
                                        // wc_dropdown_variation_attribute_options() outputs a <select> element.
                                        // Swatch plugins hook into this output to replace it with swatches.
                                        wc_dropdown_variation_attribute_options([
                                            'options' => $options,
                                            'attribute' => $attribute_name,
                                            'product' => $product,
                                            'show_option_none' => wc_attribute_label($attribute_name),
                                        ]);
                                        ?>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($clear_pos === 'onbottom' || $clear_pos === 'after')
                            echo $clear_html; ?>

                    </div> <!-- /.nmj-variations-layout -->

                    <?php do_action('woocommerce_before_add_to_cart_button'); ?>

                    <div class="single_variation_wrap">
                        <?php do_action('woocommerce_before_single_variation'); ?>

                        <div class="woocommerce_variation single_variation">
                            <?php if ($show_description): ?>
                                <div class="nmj-variation-description woocommerce-variation-description"></div>
                            <?php endif; ?>
                            <?php if ($show_var_price): ?>
                                <div class="woocommerce-variation-price"></div>
                            <?php endif; ?>
                            <div class="woocommerce-variation-availability"></div>
                        </div>

                        <?php do_action('woocommerce_after_single_variation'); ?>

                        <div class="woocommerce-variation-add-to-cart variations_button">

                            <?php if ($show_quantity): ?>
                                <?php
                                woocommerce_quantity_input([
                                    'min_value' => apply_filters('woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product),
                                    'max_value' => apply_filters('woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product),
                                    'input_value' => isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : $product->get_min_purchase_quantity(),
                                ], $product);
                                ?>
                            <?php endif; ?>

                            <?php if ($show_native_atc): ?>
                                <button type="submit"
                                    class="single_add_to_cart_button button alt <?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? wc_wp_theme_get_element_class_name('button') : ''); ?>">
                                    <?php echo esc_html($product->single_add_to_cart_text()); ?>
                                </button>
                            <?php else: ?>
                                <?php
                                // Still need a hidden submit for WC's JS to find, but visually hidden.
                                // wc-add-to-cart-variation.js looks for .single_add_to_cart_button
                                // to enable/disable it on found_variation — we keep it hidden so our
                                // custom ATC widget can take over.
                                ?>
                                <button type="submit" class="single_add_to_cart_button button alt nmj-hidden-atc-btn"
                                    aria-hidden="true" tabindex="-1">
                                    <?php echo esc_html($product->single_add_to_cart_text()); ?>
                                </button>
                            <?php endif; ?>

                            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product_id); ?>" />
                            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                            <input type="hidden" name="variation_id" class="variation_id" value="0" />

                        </div>
                    </div>

                    <?php do_action('woocommerce_after_add_to_cart_button'); ?>

                <?php endif; ?>

            </form>

        </div>
        <?php
    }

    // ── Editor notice helper ───────────────────────────────────────────────────

    private function editor_notice(string $message): void
    {
        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            echo '<p style="background:#fff3cd;color:#856404;padding:10px 14px;font-size:13px;border-radius:4px;margin:0;">'
                . esc_html($message)
                . '</p>';
        }
    }

    // ── Editor preview ─────────────────────────────────────────────────────────

    protected function content_template(): void
    {
        ?>
        <# var attrs=[ { label: 'Color' , options: ['Red', 'Blue' , 'Green' ] }, { label: 'Size' , options: ['S', 'M' , 'L'
            , 'XL' ] }, ]; var pos=settings.clear_position || 'onbottom' ; var tag=settings.clear_tag || 'span' ; var
            txt=settings.clear_text || '' ; var iconPos=settings.clear_icon_position || 'left' ; var iconHtml='' ; if
            (settings.clear_icon && settings.clear_icon.value) { var iconObj=elementor.helpers.renderIcon(view,
            settings.clear_icon, { 'aria-hidden' : 'true' }, 'i' , 'object' ); if (iconObj && iconObj.value) {
            iconHtml='<span class="nmj-clear-icon" style="display:flex;">' + iconObj.value + '</span>' ; } } var clearHtml='' ;
            if (txt || iconHtml) { var innerStyle='display:flex; align-items:center; gap:6px; flex-direction:' +
            (iconPos==='right' ? 'row-reverse' : 'row' ) + ';' ;
            clearHtml='<div class="nmj-reset-variation-wrapper" style="margin:10px 0;">'
            + '<a class="nmj-reset-variation" href="#" style="display:inline-flex; align-items:center; text-decoration:none;">'
            + '<' + tag + ' class="nmj-clear-inner" style="' + innerStyle + '">' + iconHtml + (txt
            ? '<span class="nmj-clear-text">' + txt + '</span>' : '' ) + '</' + tag + '>' + '</a></div>' ; } var layoutStyle=''
            ; var tableStyle='' ; if (pos==='infront' || pos==='after' ) {
            layoutStyle='display:flex; align-items:center; flex-direction:row;' ; } else {
            layoutStyle='display:flex; flex-direction:column;' ; } #>

            <style>
                .nmj-clear-icon svg {
                    width: 1em;
                    height: 1em;
                    fill: currentColor;
                }

                .nmj-clear-icon i {
                    color: currentColor;
                }
            </style>

            <div style="font-family:inherit;">

                <div class="nmj-variations-layout" style="{{{ layoutStyle }}}">

                    <# if (pos==='infront' || pos==='ontop' ) { #> {{{ clearHtml }}} <# } #>

                            <div style="{{{ tableStyle }}}">
                                <# for (var i=0; i < attrs.length; i++) { var a=attrs[i]; #>
                                    <div style="margin-bottom:16px;">
                                        <label style="display:block;font-weight:600;margin-bottom:6px;">{{{ a.label }}}</label>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <select
                                                style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;background:#fff;"
                                                disabled>
                                                <option>Choose {{{ a.label }}}</option>
                                                <# for (var j=0; j < a.options.length; j++) { #>
                                                    <option>{{{ a.options[j] }}}</option>
                                                    <# } #>
                                            </select>
                                        </div>
                                    </div>
                                    <# } #>
                            </div>

                            <# if (pos==='onbottom' || pos==='after' ) { #> {{{ clearHtml }}} <# } #>
                </div>

                <p style="font-size:11px;color:#9ca3af;margin:8px 0 0;font-style:italic;">
                    Preview — real attributes shown on the front end. Swatch plugins replace the dropdowns automatically.
                </p>
            </div>
            <?php
    }
}
