<?php
/**
 * Widget: Odella Product Filter
 *
 * Server-rendered category tree, search and sort. The script swaps the
 * archive Loop Grid without a full navigation.
 */

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class Product_Filter_Widget extends Widget_Base
{
    public function get_name(): string
    {
        return 'nmj_product_filter';
    }

    public function get_title(): string
    {
        return esc_html__('Odella Product Filter', 'nmj');
    }

    public function get_icon(): string
    {
        return 'eicon-filter';
    }

    public function get_categories(): array
    {
        return ['nmj-widgets'];
    }

    public function get_keywords(): array
    {
        return ['filter', 'category', 'shop', 'woocommerce'];
    }

    public function get_script_depends(): array
    {
        return ['nmj-product-filter'];
    }

    public function get_style_depends(): array
    {
        return ['nmj-product-filter'];
    }

    protected function is_dynamic_content(): bool
    {
        return true;
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('section_content', [
            'label' => esc_html__('Content', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('show_empty', [
            'label' => esc_html__('Show empty categories', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'default' => '',
        ]);

        $this->add_control('show_counts', [
            'label' => esc_html__('Show counts', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('show_search', [
            'label' => esc_html__('Show search', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('show_sort', [
            'label' => esc_html__('Show sort', 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('show_progress', [
            'label' => esc_html__("Show 'Showing X of Y' under Load More", 'nmj'),
            'type' => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('button_text_ka', [
            'label' => esc_html__('Button text (Georgian)', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => 'ფილტრი',
        ]);

        $this->add_control('button_text_en', [
            'label' => esc_html__('Button text (English)', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => 'Filter',
        ]);

        $this->add_control('expand_level', [
            'label' => esc_html__('Expanded categories', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'current',
            'options' => [
                'current' => esc_html__('Current category branch', 'nmj'),
                '1' => esc_html__('First level', 'nmj'),
                'all' => esc_html__('All', 'nmj'),
            ],
        ]);

        $this->add_control('grid_element_id', [
            'label' => esc_html__('Loop Grid element ID', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => 'c490a4e',
            'description' => esc_html__('The Loop Grid to swap. The store archive grid is c490a4e.', 'nmj'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style', [
            'label' => esc_html__('Filter', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('color_text', [
            'label' => esc_html__('Text', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#212121',
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-text: {{VALUE}};'],
        ]);
        $this->add_control('color_active_text', [
            'label' => esc_html__('Active text', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#00A79D',
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-active-text: {{VALUE}};'],
        ]);
        $this->add_control('color_border', [
            'label' => esc_html__('Border', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#00A79D',
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-border: {{VALUE}};'],
        ]);
        $this->add_control('color_active_bg', [
            'label' => esc_html__('Active background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => 'rgba(0, 167, 157, 0.12)',
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-active-bg: {{VALUE}};'],
        ]);
        $this->add_control('color_panel_bg', [
            'label' => esc_html__('Panel background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#FFFFFF',
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-panel-bg: {{VALUE}};'],
        ]);
        $this->add_control('color_overlay', [
            'label' => esc_html__('Overlay', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => 'rgba(0, 0, 0, 0.8)',
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-overlay: {{VALUE}};'],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'typography_rows',
            'label' => esc_html__('Rows', 'nmj'),
            'selector' => '{{WRAPPER}} .nmj-pf-link, {{WRAPPER}} .nmj-pf-all',
        ]);
        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'typography_counts',
            'label' => esc_html__('Counts', 'nmj'),
            'selector' => '{{WRAPPER}} .nmj-pf-count, {{WRAPPER}} .nmj-pf-term-count',
        ]);
        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name' => 'typography_button',
            'label' => esc_html__('Button', 'nmj'),
            'selector' => '{{WRAPPER}} .nmj-pf-toggle',
        ]);

        $this->add_control('border_width', [
            'label' => esc_html__('Border width', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 0, 'max' => 8]],
            'default' => ['size' => 1, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-border-width: {{SIZE}}{{UNIT}};'],
        ]);
        $this->add_control('border_radius', [
            'label' => esc_html__('Radius', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'default' => ['size' => 10, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-radius: {{SIZE}}{{UNIT}};'],
        ]);
        $this->add_control('hover_scale', [
            'label' => esc_html__('Hover scale', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'default' => 1.03,
            'min' => 1,
            'max' => 1.2,
            'step' => 0.01,
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-hover-scale: {{VALUE}};'],
        ]);
        $this->add_control('panel_width', [
            'label' => esc_html__('Panel width', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 240, 'max' => 640]],
            'default' => ['size' => 360, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-panel-width: {{SIZE}}{{UNIT}};'],
        ]);
        $this->add_control('sheet_height', [
            'label' => esc_html__('Sheet height', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['vh'],
            'range' => ['vh' => ['min' => 40, 'max' => 100]],
            'default' => ['size' => 85, 'unit' => 'vh'],
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-sheet-height: {{SIZE}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('row_height', [
            'label' => esc_html__('Row height', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 40, 'max' => 80]],
            'default' => ['size' => 40, 'unit' => 'px'],
            'mobile_default' => ['size' => 48, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}}' => '--nmj-pf-row-height: {{SIZE}}{{UNIT}};'],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_custom_css', [
            'label' => esc_html__('CSS', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('css_help', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => '<p>' . esc_html__('Each box is the inside of the braces and is added on top of the current style. An empty box changes nothing. In Custom CSS, the word selector means this widget.', 'nmj') . '</p>',
            'content_classes' => 'elementor-descriptor',
        ]);

        foreach ($this->css_targets() as $key => $target) {
            $this->add_control($key, [
                'label' => $target['label'],
                'type' => Controls_Manager::TEXTAREA,
                'rows' => 3,
                'placeholder' => 'color: #00A79D;',
                'description' => $target['hint'],
            ]);
        }

        $this->add_control('css_custom', [
            'label' => esc_html__('Custom CSS', 'nmj'),
            'type' => Controls_Manager::TEXTAREA,
            'rows' => 8,
            'placeholder' => 'selector .nmj-pf-panel .nmj-pf-row { letter-spacing: 0.04em; }',
            'description' => esc_html__('Full CSS, selectors included. Printed after the class boxes, so it wins.', 'nmj'),
            'separator' => 'before',
        ]);

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $widget_id = $this->get_id();
        $panel_id = 'nmj-pf-panel-' . $widget_id;
        $current = $this->current_term();
        $store = function_exists('nmj_store_url') ? nmj_store_url() : home_url('/');
        $archive = $this->archive_url($store);
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : '';
        $total = 0;
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $total = (int) $wp_query->found_posts;
        }
        $label = $this->button_label($settings);
        $this->print_custom_css($settings, $widget_id);
        $grid = preg_replace('/[^A-Za-z0-9]/', '', (string) ($settings['grid_element_id'] ?? 'c490a4e'));
        if ($grid === '') {
            $grid = 'c490a4e';
        }
        ?>
        <div class="nmj-pf" data-total="<?php echo esc_attr((string) $total); ?>" data-per-page="24" data-grid="<?php echo esc_attr($grid); ?>" data-show-progress="<?php echo ($settings['show_progress'] ?? '') === 'yes' ? '1' : '0'; ?>" data-store="<?php echo esc_url($store); ?>">
            <div class="nmj-pf-bar">
                <button class="nmj-pf-toggle" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr($panel_id); ?>">
                    <?php echo esc_html($label); ?>
                    <?php if ($current instanceof \WP_Term) : ?>
                        <span class="nmj-pf-current"> · <?php echo esc_html($current->name); ?></span>
                        <a class="nmj-pf-clear" href="<?php echo esc_url($store); ?>">✕</a>
                    <?php endif; ?>
                </button>
                <span class="nmj-pf-count"><?php echo esc_html(sprintf(nmj_t('products_count'), $total)); ?></span>
            </div>
            <?php $this->render_chips($archive, $search, $orderby); ?>
            <?php if ($search !== '' && $total === 0 && ((function_exists('is_shop') && is_shop()) || (function_exists('is_product_category') && is_product_category()))) : ?>
                <div class="nmj-pf-empty">
                    <p><?php echo esc_html(nmj_t('nothing_found')); ?></p>
                    <a href="<?php echo esc_url(add_query_arg('q', $search, $store)); ?>"><?php echo esc_html(nmj_t('search_all_store')); ?></a>
                </div>
            <?php endif; ?>
            <div class="nmj-pf-overlay" hidden></div>
            <div id="<?php echo esc_attr($panel_id); ?>" class="nmj-pf-panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($label); ?>" hidden>
                <button class="nmj-pf-handle" type="button" aria-label="<?php echo esc_attr($label); ?>"></button>
                <?php if (($settings['show_search'] ?? '') === 'yes') : ?>
                    <form class="nmj-pf-search" method="get" action="<?php echo esc_url($archive); ?>">
                        <input type="search" name="q" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr(nmj_t('search_ph')); ?>">
                        <?php if ($orderby !== '' && $orderby !== 'date') : ?>
                            <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
                <?php if (($settings['show_sort'] ?? '') === 'yes') : ?>
                    <form class="nmj-pf-sort" method="get" action="<?php echo esc_url($archive); ?>">
                        <?php if ($search !== '') : ?>
                            <input type="hidden" name="q" value="<?php echo esc_attr($search); ?>">
                        <?php endif; ?>
                        <select name="orderby">
                            <option value="date" <?php selected($orderby, 'date'); ?> <?php echo $orderby === '' ? 'selected' : ''; ?>><?php echo esc_html(nmj_t('sort_newest')); ?></option>
                            <option value="price" <?php selected($orderby, 'price'); ?>><?php echo esc_html(nmj_t('sort_price_asc')); ?></option>
                            <option value="price-desc" <?php selected($orderby, 'price-desc'); ?>><?php echo esc_html(nmj_t('sort_price_desc')); ?></option>
                        </select>
                    </form>
                <?php endif; ?>
                <?php $this->render_tree($settings, $current, $store); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Visible pieces only. The slide, the open state, and the loading
     * spinner stay in the stylesheet so a color edit cannot stop them.
     *
     * @return array<string, array{label: string, hint: string, selectors: string[]}>
     */
    private function css_targets(): array
    {
        return [
            'css_root' => [
                'label' => esc_html__('Whole filter', 'nmj'),
                'hint' => '.nmj-pf',
                'selectors' => ['.nmj-pf'],
            ],
            'css_bar' => [
                'label' => esc_html__('Bar', 'nmj'),
                'hint' => '.nmj-pf-bar',
                'selectors' => ['.nmj-pf-bar'],
            ],
            'css_toggle' => [
                'label' => esc_html__('Filter pill', 'nmj'),
                'hint' => '.nmj-pf-toggle',
                'selectors' => ['.nmj-pf-toggle'],
            ],
            'css_current' => [
                'label' => esc_html__('Current category', 'nmj'),
                'hint' => '.nmj-pf-current',
                'selectors' => ['.nmj-pf-current'],
            ],
            'css_clear' => [
                'label' => esc_html__('Clear link', 'nmj'),
                'hint' => '.nmj-pf-clear',
                'selectors' => ['.nmj-pf-clear'],
            ],
            'css_count' => [
                'label' => esc_html__('Counts', 'nmj'),
                'hint' => '.nmj-pf-count, .nmj-pf-term-count',
                'selectors' => ['.nmj-pf-count', '.nmj-pf-term-count'],
            ],
            'css_chips' => [
                'label' => esc_html__('Chips', 'nmj'),
                'hint' => '.nmj-pf-chips',
                'selectors' => ['.nmj-pf-chips'],
            ],
            'css_chip' => [
                'label' => esc_html__('Chip', 'nmj'),
                'hint' => '.nmj-pf-chip',
                'selectors' => ['.nmj-pf-chip'],
            ],
            'css_empty' => [
                'label' => esc_html__('Empty state', 'nmj'),
                'hint' => '.nmj-pf-empty',
                'selectors' => ['.nmj-pf-empty'],
            ],
            'css_overlay' => [
                'label' => esc_html__('Overlay', 'nmj'),
                'hint' => '.nmj-pf-overlay',
                'selectors' => ['.nmj-pf-overlay'],
            ],
            'css_panel' => [
                'label' => esc_html__('Panel', 'nmj'),
                'hint' => '.nmj-pf-panel',
                'selectors' => ['.nmj-pf-panel'],
            ],
            'css_search' => [
                'label' => esc_html__('Search field', 'nmj'),
                'hint' => '.nmj-pf-search input',
                'selectors' => ['.nmj-pf-search input'],
            ],
            'css_sort' => [
                'label' => esc_html__('Sort field', 'nmj'),
                'hint' => '.nmj-pf-sort select',
                'selectors' => ['.nmj-pf-sort select'],
            ],
            'css_tree' => [
                'label' => esc_html__('Category list', 'nmj'),
                'hint' => '.nmj-pf-tree',
                'selectors' => ['.nmj-pf-tree'],
            ],
            'css_row' => [
                'label' => esc_html__('Row', 'nmj'),
                'hint' => '.nmj-pf-row',
                'selectors' => ['.nmj-pf-row'],
            ],
            'css_row_hover' => [
                'label' => esc_html__('Row hover', 'nmj'),
                'hint' => '.nmj-pf-row:hover, .nmj-pf-row:focus-within',
                'selectors' => ['.nmj-pf-row:hover', '.nmj-pf-row:focus-within'],
            ],
            'css_link' => [
                'label' => esc_html__('Link', 'nmj'),
                'hint' => '.nmj-pf-link, .nmj-pf-all',
                'selectors' => ['.nmj-pf-link', '.nmj-pf-all'],
            ],
            'css_link_current' => [
                'label' => esc_html__('Current link', 'nmj'),
                'hint' => '.nmj-pf-link[aria-current="page"]',
                'selectors' => ['.nmj-pf-link[aria-current="page"]'],
            ],
            'css_row_current' => [
                'label' => esc_html__('Current row', 'nmj'),
                'hint' => '.nmj-pf-row:has(.nmj-pf-link[aria-current="page"])',
                'selectors' => ['.nmj-pf-row:has(.nmj-pf-link[aria-current="page"])'],
            ],
            'css_chevron' => [
                'label' => esc_html__('Chevron', 'nmj'),
                'hint' => '.nmj-pf-chevron',
                'selectors' => ['.nmj-pf-chevron', '.nmj-pf-chevron:hover', '.nmj-pf-chevron:focus'],
            ],
            'css_chevron_arrow' => [
                'label' => esc_html__('Chevron arrow', 'nmj'),
                'hint' => '.nmj-pf-chevron::before',
                'selectors' => ['.nmj-pf-chevron::before'],
            ],
            'css_sub' => [
                'label' => esc_html__('Nested list', 'nmj'),
                'hint' => '.nmj-pf-sub',
                'selectors' => ['.nmj-pf-sub'],
            ],
            'css_progress' => [
                'label' => esc_html__('Shown-of line', 'nmj'),
                'hint' => '.nmj-pf-progress',
                'selectors' => ['.nmj-pf-progress'],
            ],
        ];
    }

    private function print_custom_css(array $settings, string $widget_id): void
    {
        $wrapper = '.elementor-element-' . preg_replace('/[^A-Za-z0-9]/', '', $widget_id);
        $css = '';
        foreach ($this->css_targets() as $key => $target) {
            $body = $this->css_declarations((string) ($settings[$key] ?? ''));
            if ($body === '') {
                continue;
            }
            $selectors = array_map(static function (string $selector) use ($wrapper): string {
                return $wrapper . ' ' . $selector;
            }, $target['selectors']);
            $css .= implode(',', $selectors) . '{' . $body . '}';
        }
        $css .= $this->css_sheet((string) ($settings['css_custom'] ?? ''), $wrapper);
        if ($css === '') {
            return;
        }
        echo '<style id="nmj-pf-user-' . esc_attr($widget_id) . '">' . $css . '</style>';
    }

    private function css_declarations(string $css): string
    {
        $css = $this->css_plain($css);
        return str_replace(['{', '}'], '', $css);
    }

    private function css_sheet(string $css, string $wrapper): string
    {
        $css = $this->css_plain($css);
        if ($css === '') {
            return '';
        }
        return preg_replace('/\bselector\b/', $wrapper, $css) ?? '';
    }

    private function css_plain(string $css): string
    {
        $css = wp_strip_all_tags($css);
        $css = str_ireplace(['</style', '<script', '<?'], '', $css);
        return trim($css);
    }

    private function button_label(array $settings): string
    {
        $key = function_exists('nmj_current_language') && nmj_current_language() === 'en'
            ? 'button_text_en'
            : 'button_text_ka';
        $value = trim((string) ($settings[$key] ?? ''));
        if ($value === '') {
            return nmj_t('filter');
        }
        return $value;
    }

    private function current_term(): ?\WP_Term
    {
        if (!function_exists('is_product_category') || !is_product_category()) {
            return null;
        }
        $term = get_queried_object();
        return $term instanceof \WP_Term ? $term : null;
    }

    private function archive_url(string $store): string
    {
        $current = $this->current_term();
        if ($current instanceof \WP_Term) {
            $link = get_term_link($current);
            if (!is_wp_error($link)) {
                return $link;
            }
        }
        if (function_exists('is_shop') && is_shop()) {
            return $store;
        }
        $permalink = get_permalink();
        return is_string($permalink) && $permalink !== '' ? $permalink : $store;
    }

    private function render_chips(string $archive, string $search, string $orderby): void
    {
        if ($search === '' && !in_array($orderby, ['price', 'price-desc'], true)) {
            return;
        }
        echo '<div class="nmj-pf-chips">';
        if ($search !== '') {
            $url = remove_query_arg('q', $archive);
            if (in_array($orderby, ['price', 'price-desc'], true)) {
                $url = add_query_arg('orderby', $orderby, $url);
            }
            echo '<a class="nmj-pf-chip" href="' . esc_url($url) . '">' . esc_html($search) . ' ✕</a>';
        }
        if (in_array($orderby, ['price', 'price-desc'], true)) {
            $label = $orderby === 'price' ? nmj_t('sort_price_asc') : nmj_t('sort_price_desc');
            $url = $search !== '' ? add_query_arg('q', $search, $archive) : $archive;
            echo '<a class="nmj-pf-chip" href="' . esc_url($url) . '">' . esc_html($label) . ' ✕</a>';
        }
        echo '</div>';
    }

    private function render_tree(array $settings, ?\WP_Term $current, string $store): void
    {
        $lang = function_exists('nmj_current_language') ? nmj_current_language() : '';
        $args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ];
        if ($lang !== '') {
            $args['lang'] = $lang;
        }
        $terms = get_terms($args);
        if (!is_array($terms)) {
            $terms = [];
        }
        $by_parent = [];
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $by_parent[(int) $term->parent][] = $term;
        }
        $show_empty = ($settings['show_empty'] ?? '') === 'yes';
        $show_counts = ($settings['show_counts'] ?? '') === 'yes';
        $mode = (string) ($settings['expand_level'] ?? 'current');
        $ancestors = $current instanceof \WP_Term
            ? array_map('intval', get_ancestors($current->term_id, 'product_cat'))
            : [];
        if ($current instanceof \WP_Term) {
            $ancestors[] = (int) $current->term_id;
        }
        echo '<ul class="nmj-pf-tree">';
        echo '<li class="nmj-pf-item"><a class="nmj-pf-all nmj-pf-link" href="' . esc_url($store) . '">' . esc_html(nmj_t('all_products')) . '</a></li>';
        $this->render_branch(0, $by_parent, $show_empty, $show_counts, $mode, $ancestors, $current, 0);
        echo '</ul>';
    }

    /**
     * @param array<int, array<int, \WP_Term>> $by_parent
     * @param array<int, int>                  $ancestors
     */
    private function render_branch(int $parent, array $by_parent, bool $show_empty, bool $show_counts, string $mode, array $ancestors, ?\WP_Term $current, int $depth): void
    {
        foreach ($by_parent[$parent] ?? [] as $term) {
            $inclusive = $this->inclusive_count((int) $term->term_id, $by_parent);
            if (!$show_empty && $inclusive === 0) {
                continue;
            }
            $open = $mode === 'all' || ($mode === '1' && $depth < 1) || in_array((int) $term->term_id, $ancestors, true);
            $has_children = !empty($by_parent[(int) $term->term_id]);
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }
            $current_attr = $current instanceof \WP_Term && (int) $current->term_id === (int) $term->term_id
                ? ' aria-current="page"'
                : '';
            echo '<li class="nmj-pf-item">';
            echo '<div class="nmj-pf-row">';
            if ($has_children) {
                echo '<button class="nmj-pf-chevron" type="button" aria-expanded="' . ($open ? 'true' : 'false') . '"></button>';
            }
            echo '<a class="nmj-pf-link" href="' . esc_url($link) . '"' . $current_attr . '>' . esc_html($term->name) . '</a>';
            if ($show_counts) {
                echo '<span class="nmj-pf-term-count">' . esc_html((string) $this->term_count((int) $term->term_id, $term)) . '</span>';
            }
            echo '</div>';
            if ($has_children) {
                echo '<div class="nmj-pf-sub' . ($open ? ' is-open' : '') . '"><div><ul>';
                $this->render_branch((int) $term->term_id, $by_parent, $show_empty, $show_counts, $mode, $ancestors, $current, $depth + 1);
                echo '</ul></div></div>';
            }
            echo '</li>';
        }
    }

    /**
     * @param array<int, array<int, \WP_Term>> $by_parent
     */
    private function inclusive_count(int $term_id, array $by_parent): int
    {
        $term = get_term($term_id, 'product_cat');
        $count = $term instanceof \WP_Term ? $this->term_count($term_id, $term) : 0;
        foreach ($by_parent[$term_id] ?? [] as $child) {
            $count += $this->inclusive_count((int) $child->term_id, $by_parent);
        }
        return $count;
    }

    private function term_count(int $term_id, ?\WP_Term $term = null): int
    {
        $meta = get_term_meta($term_id, 'product_count_product_cat', true);
        if ($meta !== '' && $meta !== false) {
            return (int) $meta;
        }
        return $term instanceof \WP_Term ? (int) $term->count : 0;
    }
}
