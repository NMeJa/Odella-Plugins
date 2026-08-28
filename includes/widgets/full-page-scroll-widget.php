<?php
/**
 * Widget: Full Page Scroll & Navigation
 *
 * Features:
 * • Forces calc(100vh - offset) on target sections.
 * • Debounced mouse-wheel & touch snap scrolling.
 * • Optional, customizable side navigation dots with SVG support.
 */

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Icons_Manager;

class Full_Page_Scroll_Widget extends Widget_Base
{
    public function get_name(): string
    {
        return 'nmj_full_page_scroll';
    }
    public function get_title(): string
    {
        return esc_html__('Full Page Scroll', 'nmj');
    }
    public function get_icon(): string
    {
        return 'eicon-scroll';
    }
    public function get_categories(): array
    {
        return ['nmj-widgets'];
    }
    public function get_script_depends(): array { return ['nmj-full-page-scroll']; }
    public function get_style_depends(): array { return ['nmj-full-page-scroll']; }

    protected function register_controls(): void
    {
        // ── SECTION: Sections Setup ────────────────────────────────────────────
        $this->start_controls_section('section_setup', [
            'label' => esc_html__('Sections Setup', 'nmj'),
        ]);

        $this->add_control('header_offset', [
            'label' => esc_html__('Header Height Offset (px)', 'nmj'),
            'description' => esc_html__('Subtracts this from 100vh and offsets the scroll. Enter 100 for your fixed header.', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'default' => 100,
        ]);

        $this->add_control('allow_scroll_past', [
            'label' => esc_html__('Allow Scroll Past Last Section', 'nmj'),
            'description' => esc_html__('When ON, normal scrolling resumes after the last section so the footer is reachable. Snap re-activates when scrolling back up.', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'no',
        ]);

        $repeater = new Repeater();

        $repeater->add_control('section_id', [
            'label' => esc_html__('CSS ID', 'nmj'),
            'description' => esc_html__('Enter the ID of the section WITHOUT the # (e.g., "hero-section").', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'label_block' => true,
        ]);

        $repeater->add_control('tooltip_text', [
            'label' => esc_html__('Dot Tooltip Name', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'label_block' => true,
        ]);

        $this->add_control('sections', [
            'label' => esc_html__('Scroll Sections', 'nmj'),
            'type' => Controls_Manager::REPEATER,
            'fields' => $repeater->get_controls(),
            'title_field' => '{{{ tooltip_text || section_id }}}',
        ]);

        $this->end_controls_section();

        // ── SECTION: Navigation Dots ───────────────────────────────────────────
        $this->start_controls_section('section_dots', [
            'label' => esc_html__('Navigation Dots', 'nmj'),
        ]);

        $this->add_control('show_dots', [
            'label' => esc_html__('Show Navigation Dots', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'nmj'),
            'label_off' => esc_html__('No', 'nmj'),
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->add_control('dot_icon', [
            'label' => esc_html__('Dot Icon', 'nmj'),
            'type' => Controls_Manager::ICONS,
            'default' => [
                'value' => 'fas fa-circle',
                'library' => 'fa-solid',
            ],
            'condition' => [
                'show_dots' => 'yes',
            ],
        ]);

        $this->end_controls_section();

        // ══════════════════════════════════════════════════════════════════════
        // STYLE TAB
        // ══════════════════════════════════════════════════════════════════════
        $this->start_controls_section('section_style_dots', [
            'label' => esc_html__('Dots Style', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
            'condition' => ['show_dots' => 'yes'],
        ]);

        $this->add_control('dot_size', [
            'label' => esc_html__('Icon Size', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 5, 'max' => 50]],
            'default' => ['size' => 12, 'unit' => 'px'],
            'selectors' => [
                '{{WRAPPER}} .nmj-fps-dot i' => 'font-size: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .nmj-fps-dot svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->start_controls_tabs('tabs_dots_style');

        // Normal Tab
        $this->start_controls_tab('tab_dots_normal', ['label' => esc_html__('Normal', 'nmj')]);
        $this->add_control('dot_color_normal', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#cccccc',
            'selectors' => [
                '{{WRAPPER}} .nmj-fps-dot' => 'color: {{VALUE}}; fill: {{VALUE}};',
            ],
        ]);
        $this->end_controls_tab();

        // Active Tab
        $this->start_controls_tab('tab_dots_active', ['label' => esc_html__('Active', 'nmj')]);
        $this->add_control('dot_color_active', [
            'label' => esc_html__('Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#7f54b3',
            'selectors' => [
                '{{WRAPPER}} .nmj-fps-dot.active' => 'color: {{VALUE}}; fill: {{VALUE}}; transform: scale(1.3);',
            ],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $sections = $settings['sections'] ?? [];

        if (empty($sections)) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<p style="color:orange;">⚠ <em>Full Page Scroll: Please add section IDs in the widget settings.</em></p>';
            }
            return;
        }

        // Clean up IDs (ensure no hashes are passed to JS).
        // Repeater rows saved before a control existed simply omit its key, so
        // every read needs a default — in PHP 8 ltrim(null, '#') is also a
        // deprecation on top of the undefined-key warning.
        $clean_sections = array_map(static function ($item) {
            return [
                'id' => ltrim((string) ($item['section_id'] ?? ''), '#'),
                'tooltip' => (string) ($item['tooltip_text'] ?? ''),
            ];
        }, $sections);

        // Rows with no CSS ID have nothing to scroll to.
        $clean_sections = array_values(array_filter(
            $clean_sections,
            static fn($item) => '' !== $item['id']
        ));

        if (empty($clean_sections)) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<p style="color:orange;">⚠ <em>Full Page Scroll: Every section row needs a CSS ID.</em></p>';
            }
            return;
        }

        $offset = absint($settings['header_offset'] ?? 0);
        $show_dots = ($settings['show_dots'] ?? 'yes') === 'yes';
        $allow_past = ($settings['allow_scroll_past'] ?? 'no') === 'yes';

        // Pre-render the chosen SVG/Icon
        ob_start();
        if (!empty($settings['dot_icon']['value'])) {
            Icons_Manager::render_icon($settings['dot_icon'], ['aria-hidden' => 'true']);
        }
        $icon_html = ob_get_clean();

        // Output the JSON data for JS to read
        echo sprintf(
            '<div class="nmj-fps-data" style="display:none;" data-offset="%d" data-sections="%s" data-allow-past="%s"></div>',
            $offset,
            esc_attr(wp_json_encode($clean_sections)),
            $allow_past ? '1' : '0'
        );

        // Output Navigation Dots HTML
        if ($show_dots) {
            echo '<div class="nmj-fps-nav">';
            foreach ($clean_sections as $index => $section) {
                $active_class = $index === 0 ? ' active' : '';
                echo sprintf(
                    '<button class="nmj-fps-dot%s" data-target="%s" aria-label="%s" title="%s">%s</button>',
                    $active_class,
                    esc_attr($section['id']),
                    esc_attr($section['tooltip']),
                    esc_attr($section['tooltip']),
                    $icon_html
                );
            }
            echo '</div>';
        }
    }
}