<?php

namespace NMJ\Widgets\Traits;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Border;

trait ATC_Style_Controls
{
    protected function register_style_controls(): void
    {
        $W = '{{WRAPPER}}';
        $B = "$W .nmj-atc-btn";
        $T = "$W .nmj-atc-text";
        $I = "$W .nmj-atc-icon";
        $Q = "$W .nmj-atc-qty";

        // ── Button ────────────────────────────────────────────────────────
        $this->start_controls_section('sec_s_btn', [
            'label' => esc_html__('Button', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('btn_padding', [
            'label' => esc_html__('Padding', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', '%'],
            'default' => ['top' => '12', 'right' => '24', 'bottom' => '12', 'left' => '24', 'unit' => 'px', 'isLinked' => false],
            'selectors' => [$B => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);

        $this->add_responsive_control('btn_border_radius', [
            'label' => esc_html__('Border Radius', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em'],
            'default' => ['top' => '4', 'right' => '4', 'bottom' => '4', 'left' => '4', 'unit' => 'px', 'isLinked' => true],
            'selectors' => [$B => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);

        $this->add_group_control(Group_Control_Border::get_type(), [
            'name' => 'btn_border',
            'selector' => $B,
        ]);

        $this->add_group_control(Group_Control_Box_Shadow::get_type(), [
            'name' => 'btn_box_shadow',
            'selector' => $B,
        ]);

        $this->add_responsive_control('btn_width', [
            'label' => esc_html__('Width', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', '%', 'em'],
            'range' => ['px' => ['min' => 50, 'max' => 600]],
            'selectors' => [$B => 'width:{{SIZE}}{{UNIT}};'],
        ]);

        // Tabs: Normal / Hover
        $this->start_controls_tabs('btn_tabs');

        $this->start_controls_tab('btn_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('btn_bg', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#7f54b3',
            'selectors' => [$B => 'background-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('btn_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('btn_bg_h', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#6540a0',
            'selectors' => ["$B:hover" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('btn_border_color_h', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B:hover" => 'border-color:{{VALUE}};'],
        ]);
        $this->add_control('btn_transition', [
            'label' => esc_html__('Transition (ms)', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 250],
            'range' => ['px' => ['min' => 0, 'max' => 1000]],
            'selectors' => [$B => 'transition:all {{SIZE}}ms ease;'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        // ── Button Text ───────────────────────────────────────────────────
        $this->start_controls_section('sec_s_text', [
            'label' => esc_html__('Button Text', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'text_typo',
            'selector' => $T,
        ]);

        $this->start_controls_tabs('text_tabs');

        $this->start_controls_tab('text_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('text_color', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#ffffff',
            'selectors' => [$T => 'color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('text_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('text_color_h', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#ffffff',
            'selectors' => ["$B:hover $T" => 'color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        // ── Button Icon ───────────────────────────────────────────────────
        $this->start_controls_section('sec_s_icon', [
            'label' => esc_html__('Button Icon', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('icon_size', [
            'label' => esc_html__('Size', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 16, 'unit' => 'px'],
            'range' => ['px' => ['min' => 8, 'max' => 80]],
            'selectors' => [
                $I => 'font-size:{{SIZE}}{{UNIT}};',
                "$I svg" => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('icon_gap', [
            'label' => esc_html__('Gap from Text', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 8, 'unit' => 'px'],
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'selectors' => [$B => 'gap:{{SIZE}}{{UNIT}};'],
        ]);

        $this->end_controls_section();


        // ── State: In Stock ───────────────────────────────────────────────
        $this->start_controls_section('sec_s_instock', [
            'label' => esc_html__('State: In Stock', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->start_controls_tabs('instock_tabs');

        $this->start_controls_tab('instock_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('instock_text_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('instock_icon_color', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('instock_bg', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('instock_border_color', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('instock_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('instock_text_color_h', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock:hover .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('instock_icon_color_h', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock:hover .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('instock_bg_h', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock:hover" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('instock_border_color_h', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--in-stock:hover" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        // ── State: Added ──────────────────────────────────────────────────
        $this->start_controls_section('sec_s_added', [
            'label' => esc_html__('State: Added', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->start_controls_tabs('added_tabs');

        $this->start_controls_tab('added_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('added_text_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#fff',
            'selectors' => ["$B.nmj--added .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('added_icon_color', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#fff',
            'selectors' => ["$B.nmj--added .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('added_bg', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#28a745',
            'selectors' => ["$B.nmj--added" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('added_border_color', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--added" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('added_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('added_text_color_h', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--added:hover .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('added_icon_color_h', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--added:hover .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('added_bg_h', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--added:hover" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('added_border_color_h', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--added:hover" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        // ── State: Out of Stock ───────────────────────────────────────────
        $this->start_controls_section('sec_s_oos', [
            'label' => esc_html__('State: Out of Stock', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('oos_opacity', [
            'label' => esc_html__('Opacity', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 0.6],
            'range' => ['px' => ['min' => 0.1, 'max' => 1, 'step' => 0.05]],
            'selectors' => ["$B.nmj--oos" => 'opacity:{{SIZE}};'],
        ]);

        $this->start_controls_tabs('oos_tabs');

        $this->start_controls_tab('oos_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('oos_text_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#999',
            'selectors' => ["$B.nmj--oos .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('oos_icon_color', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#999',
            'selectors' => ["$B.nmj--oos .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('oos_bg', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#e5e5e5',
            'selectors' => ["$B.nmj--oos" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('oos_border_color', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--oos" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('oos_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('oos_text_color_h', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--oos:hover .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('oos_icon_color_h', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--oos:hover .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('oos_bg_h', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--oos:hover" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('oos_border_color_h', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--oos:hover" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        // ── State: Awaiting / Quick View ──────────────────────────────────
        $this->start_controls_section('sec_s_await', [
            'label' => esc_html__('State: Awaiting / Quick View', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->start_controls_tabs('await_tabs');

        $this->start_controls_tab('await_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('aw_text_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#6b7280',
            'selectors' => ["$B.nmj--awaiting .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('aw_icon_color', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#6b7280',
            'selectors' => ["$B.nmj--awaiting .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('aw_bg', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#e5e7eb',
            'selectors' => ["$B.nmj--awaiting" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('aw_border_color', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--awaiting" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('await_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('aw_text_color_h', [
            'label' => esc_html__('Text Color', 'nmj'),
            'description' => esc_html__('Applies when Quick View mode is active (button is clickable).', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--awaiting:hover .nmj-atc-text" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('aw_icon_color_h', [
            'label' => esc_html__('Icon Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--awaiting:hover .nmj-atc-icon" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('aw_bg_h', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--awaiting:hover" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('aw_border_color_h', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$B.nmj--awaiting:hover" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        // ── Quantity Field Style ──────────────────────────────────────────
        $this->start_controls_section('sec_s_qty', [
            'label' => esc_html__('Quantity Field', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
            'condition' => ['show_quantity_input' => 'yes'],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'qty_typo',
            'selector' => $Q,
        ]);

        $this->add_responsive_control('qty_width', [
            'label' => esc_html__('Width', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'default' => ['size' => 60, 'unit' => 'px'],
            'range' => ['px' => ['min' => 30, 'max' => 200]],
            'selectors' => [$Q => 'width:{{SIZE}}{{UNIT}};'],
        ]);

        $this->add_responsive_control('qty_gap', [
            'label' => esc_html__('Gap from Button', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'default' => ['size' => 10, 'unit' => 'px'],
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'selectors' => ["$W .nmj-atc-wrap" => 'gap:{{SIZE}}{{UNIT}};'],
        ]);

        $this->add_control('qty_padding', [
            'label' => esc_html__('Padding', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em'],
            'default' => ['top' => '6', 'right' => '6', 'bottom' => '6', 'left' => '6', 'unit' => 'px', 'isLinked' => true],
            'selectors' => [$Q => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);

        $this->add_control('qty_border_radius', [
            'label' => esc_html__('Border Radius', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'default' => ['top' => '4', 'right' => '4', 'bottom' => '4', 'left' => '4', 'unit' => 'px', 'isLinked' => true],
            'selectors' => [$Q => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);

        // Tabs: Normal / Hover / Focus
        $this->start_controls_tabs('qty_tabs');

        $this->start_controls_tab('qty_tab_n', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('qty_color', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => [$Q => 'color:{{VALUE}};'],
        ]);
        $this->add_control('qty_bg', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#ffffff',
            'selectors' => [$Q => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('qty_border_color', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#cccccc',
            'selectors' => [$Q => 'border:1px solid {{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('qty_tab_h', ['label' => esc_html__('Hover', 'nmj')]);
        $this->add_control('qty_color_h', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$Q:hover" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('qty_bg_h', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$Q:hover" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('qty_border_color_h', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$Q:hover" => 'border-color:{{VALUE}};'],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('qty_tab_f', ['label' => esc_html__('Focus', 'nmj')]);
        $this->add_control('qty_color_f', [
            'label' => esc_html__('Text Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$Q:focus" => 'color:{{VALUE}};'],
        ]);
        $this->add_control('qty_bg_f', [
            'label' => esc_html__('Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$Q:focus" => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('qty_border_color_f', [
            'label' => esc_html__('Border Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'selectors' => ["$Q:focus" => 'border-color:{{VALUE}};outline:none;'],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();
    }
}
