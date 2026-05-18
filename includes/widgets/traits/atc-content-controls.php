<?php

namespace NMJ\Widgets\Traits;

use Elementor\Controls_Manager;
use Elementor\Icons_Manager;

trait ATC_Content_Controls
{
    protected function register_content_controls(): void
    {
        // ── General ───────────────────────────────────────────────────────
        $this->start_controls_section('section_general', [
            'label' => esc_html__('General', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('product_id', [
            'label' => esc_html__('Product ID (optional)', 'nmj'),
            'description' => esc_html__('Leave blank to auto-detect.', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'min' => 0,
            'default' => '',
        ]);

        $this->add_control('lock_after_add', [
            'label' => esc_html__('Lock After Adding', 'nmj'),
            'description' => esc_html__('When ON the button switches to "Added" state and stays locked. When OFF the user can add multiple times.', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->add_control('editor_preview_divider', [
            'type' => Controls_Manager::DIVIDER,
        ]);

        $this->add_control('editor_preview_state', [
            'label'       => esc_html__('🖥 Preview State', 'nmj'),
            'description' => esc_html__('Editor only — pick which button state to preview. Has no effect on the live site.', 'nmj'),
            'type'        => Controls_Manager::SELECT,
            'default'     => 'in-stock',
            'options'     => [
                'in-stock' => esc_html__('✅ In Stock',        'nmj'),
                'added'    => esc_html__('🛒 Added to Cart',   'nmj'),
                'oos'      => esc_html__('❌ Out of Stock',    'nmj'),
                'awaiting' => esc_html__('🔀 Awaiting / Quick View', 'nmj'),
            ],
        ]);

        $this->end_controls_section();

        // ── Button Layout ─────────────────────────────────────────────────
        $this->start_controls_section('section_layout', [
            'label' => esc_html__('Button Layout', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('icon_position', [
            'label' => esc_html__('Icon Position', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'left' => ['title' => esc_html__('Left', 'nmj'), 'icon' => 'eicon-h-align-left'],
                'right' => ['title' => esc_html__('Right', 'nmj'), 'icon' => 'eicon-h-align-right'],
            ],
            'default' => 'left',
            'toggle' => false,
            'selectors' => [
                '{{WRAPPER}} .nmj-atc-btn' => 'flex-direction: {{VALUE}};',
            ],
            'selectors_dictionary' => [
                'left' => 'row',
                'right' => 'row-reverse',
            ],
        ]);

        $this->add_responsive_control('text_valign', [
            'label' => esc_html__('Text Vertical Alignment', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'flex-start' => ['title' => esc_html__('Top', 'nmj'), 'icon' => 'eicon-v-align-top'],
                'center' => ['title' => esc_html__('Middle', 'nmj'), 'icon' => 'eicon-v-align-middle'],
                'flex-end' => ['title' => esc_html__('Bottom', 'nmj'), 'icon' => 'eicon-v-align-bottom'],
            ],
            'default' => 'center',
            'toggle' => false,
            'selectors' => ['{{WRAPPER}} .nmj-atc-text' => 'align-self: {{VALUE}};'],
        ]);

        $this->add_responsive_control('text_offset', [
            'label' => esc_html__('Text Vertical Offset', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'range' => ['px' => ['min' => -20, 'max' => 20]],
            'selectors' => ['{{WRAPPER}} .nmj-atc-text' => 'transform: translateY({{SIZE}}{{UNIT}});'],
        ]);

        $this->end_controls_section();

        // ── Quantity Field ────────────────────────────────────────────────
        $this->start_controls_section('section_quantity', [
            'label' => esc_html__('Quantity Field', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('show_quantity_input', [
            'label' => esc_html__('Show Quantity Input', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'no',
        ]);

        $this->add_control('qty_position', [
            'label' => esc_html__('Position', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'left' => ['title' => esc_html__('Left', 'nmj'), 'icon' => 'eicon-h-align-left'],
                'right' => ['title' => esc_html__('Right', 'nmj'), 'icon' => 'eicon-h-align-right'],
            ],
            'default' => 'left',
            'toggle' => false,
            'condition' => ['show_quantity_input' => 'yes'],
        ]);

        $this->add_control('qty_default', [
            'label' => esc_html__('Default Quantity', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'default' => 1,
        ]);

        $this->end_controls_section();

        // ── ✅ In Stock ───────────────────────────────────────────────────
        $this->start_controls_section('section_in_stock', [
            'label' => esc_html__('✅ In Stock / Add to Cart', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('in_stock_text', [
            'label' => esc_html__('Button Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Add to Cart', 'nmj'),
            'dynamic' => ['active' => true],
        ]);

        $this->add_control('in_stock_icon', [
            'label' => esc_html__('Icon', 'nmj'),
            'type' => Controls_Manager::ICONS,
            'default' => ['value' => 'fas fa-cart-plus', 'library' => 'fa-solid'],
        ]);

        $this->end_controls_section();

        // ── 🛒 Added to Cart ──────────────────────────────────────────────
        $this->start_controls_section('section_added', [
            'label' => esc_html__('🛒 Added to Cart', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('added_text', [
            'label' => esc_html__('Button Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Added', 'nmj'),
            'dynamic' => ['active' => true],
        ]);

        $this->add_control('added_icon', [
            'label' => esc_html__('Icon', 'nmj'),
            'type' => Controls_Manager::ICONS,
            'default' => ['value' => 'fas fa-check-circle', 'library' => 'fa-solid'],
        ]);

        $this->end_controls_section();

        // ── ❌ Out of Stock ───────────────────────────────────────────────
        $this->start_controls_section('section_oos', [
            'label' => esc_html__('❌ Out of Stock', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('out_of_stock_text', [
            'label' => esc_html__('Button Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Out of Stock', 'nmj'),
            'dynamic' => ['active' => true],
        ]);

        $this->add_control('out_of_stock_icon', [
            'label' => esc_html__('Icon', 'nmj'),
            'type' => Controls_Manager::ICONS,
            'default' => ['value' => 'fas fa-ban', 'library' => 'fa-solid'],
        ]);

        $this->end_controls_section();

        // ── 🔀 Variable Product ───────────────────────────────────────────
        $this->start_controls_section('section_variable', [
            'label' => esc_html__('🔀 Variable Product', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('variable_behavior', [
            'label' => esc_html__('Behavior', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'inline',
            'options' => [
                'inline' => esc_html__('Inline — wait for Variation Selector', 'nmj'),
                'quick_view' => esc_html__('Quick View — open Elementor Popup', 'nmj'),
                'redirect' => esc_html__('Redirect — link to product page', 'nmj'),
            ],
        ]);

        $popup_options = ['0' => esc_html__('— Select Popup —', 'nmj')];
        $popups = get_posts([
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'elementor_library_type',
                    'field' => 'slug',
                    'terms' => 'popup',
                ]
            ],
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        foreach ($popups as $popup) {
            $popup_options[$popup->ID] = sprintf('%s (#%d)', $popup->post_title, $popup->ID);
        }

        $this->add_control('quick_view_popup_id', [
            'label' => esc_html__('Quick View Popup', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => '0',
            'options' => $popup_options,
            'condition' => ['variable_behavior' => 'quick_view'],
        ]);

        $this->add_control('awaiting_text', [
            'label' => esc_html__('Awaiting Selection Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Select options', 'nmj'),
            'dynamic' => ['active' => true],
            'condition' => ['variable_behavior' => 'inline'],
        ]);

        $this->add_control('quick_view_text', [
            'label' => esc_html__('Quick View Button Text', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => esc_html__('Quick View', 'nmj'),
            'dynamic' => ['active' => true],
            'condition' => ['variable_behavior' => 'quick_view'],
        ]);

        $this->add_control('awaiting_icon', [
            'label' => esc_html__('Awaiting / Quick View Icon', 'nmj'),
            'type' => Controls_Manager::ICONS,
            'default' => ['value' => 'fas fa-sliders-h', 'library' => 'fa-solid'],
            'condition' => ['variable_behavior' => ['inline', 'quick_view']],
        ]);

        $this->end_controls_section();
    }
}