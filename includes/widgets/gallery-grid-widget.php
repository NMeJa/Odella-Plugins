<?php

/**
 * Widget: Gallery Grid
 */

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Group_Control_Image_Size;

class Gallery_Grid_Widget extends Widget_Base
{
    public function get_name(): string
    {
        return 'nmj_gallery_grid';
    }
    public function get_title(): string
    {
        return esc_html__('Gallery Grid', 'nmj');
    }
    public function get_icon(): string
    {
        return 'eicon-gallery-grid';
    }
    public function get_categories(): array
    {
        return ['nmj-widgets'];
    }
    public function get_keywords(): array
    {
        return ['gallery', 'grid', 'images', 'metro', 'pattern', 'woocommerce', 'photos'];
    }

    public function get_script_depends(): array
    {
        return ['nmj-gallery-grid'];
    }

    public function get_style_depends(): array
    {
        return ['nmj-gallery-grid'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('section_images', [
            'label' => esc_html__('Images', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('layout', [
            'label' => esc_html__('Layout', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'grid',
            'options' => [
                'grid' => esc_html__('Grid Pattern', 'nmj'),
                'product_gallery' => esc_html__('Product Gallery', 'nmj'),
            ],
        ]);

        $this->add_control('gallery', [
            'label' => esc_html__('Images', 'nmj'),
            'type' => Controls_Manager::GALLERY,
            'show_label' => false,
            'dynamic' => ['active' => true],
        ]);

        $this->add_group_control(Group_Control_Image_Size::get_type(), [
            'name' => 'image',
            'default' => 'large',
            'separator' => 'before',
        ]);

        $this->add_group_control(Group_Control_Image_Size::get_type(), [
            'name' => 'thumb_image',
            'label' => esc_html__('Thumbnail Resolution', 'nmj'),
            'default' => 'thumbnail',
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_control('open_lightbox', [
            'label' => esc_html__('Lightbox', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'default',
            'options' => [
                'default' => esc_html__('Default', 'nmj'),
                'yes' => esc_html__('Yes', 'nmj'),
                'no' => esc_html__('No', 'nmj'),
            ],
            'separator' => 'before',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_pattern', [
            'label' => esc_html__('Row Pattern', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
            'condition' => ['layout' => 'grid'],
        ]);

        $this->add_control('pattern_info', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => '<p style="font-size:12px;color:#6b7280;margin:0 0 4px;">
                    Define each row. The pattern repeats once all rows are filled.<br><br>
                    <strong>Square mode tip:</strong> a 1-column row is naturally 2× taller
                    than a 2-column row since both are 1:1 squares. Use the "Big row multiplier" in Style tab to adjust.
                </p>',
        ]);

        $repeater = new Repeater();

        $repeater->add_control('row_label', [
            'label' => esc_html__('Label (reference only)', 'nmj'),
            'type' => Controls_Manager::TEXT,
            'default' => 'Row',
        ]);

        $repeater->add_control('row_columns', [
            'label' => esc_html__('Columns', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 8,
            'default' => 1,
        ]);

        $shape_options = [
            'square' => esc_html__('Square (1:1)', 'nmj'),
            'full' => esc_html__('Full (natural ratio)', 'nmj'),
            'custom' => esc_html__('Custom height', 'nmj'),
        ];

        global $_wp_additional_image_sizes;
        $default_image_sizes = get_intermediate_image_sizes();

        foreach ($default_image_sizes as $size) {
            $width = 0;
            $height = 0;
            if (in_array($size, ['thumbnail', 'medium', 'medium_large', 'large'])) {
                $width  = (int) get_option("{$size}_size_w");
                $height = (int) get_option("{$size}_size_h");
            } elseif (isset($_wp_additional_image_sizes[$size])) {
                $width  = (int) $_wp_additional_image_sizes[$size]['width'];
                $height = (int) $_wp_additional_image_sizes[$size]['height'];
            }

            if ($width > 0 && $height > 0) {
                $ar = round($width / $height, 4);
                $key = 'ar_' . $ar;
                $label = ucwords(str_replace(['_', '-'], ' ', $size)) . " ({$width}x{$height})";

                if (!isset($shape_options[$key])) {
                    $shape_options[$key] = $label;
                } else {
                    $shape_options[$key] .= ' / ' . ucwords(str_replace(['_', '-'], ' ', $size));
                }
            }
        }

        $repeater->add_control('row_shape', [
            'label' => esc_html__('Image Shape', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'square',
            'options' => $shape_options,
        ]);

        $repeater->add_responsive_control('row_custom_height', [
            'label' => esc_html__('Height', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'vh', 'vw', 'em', '%'],
            'default' => ['size' => 300, 'unit' => 'px'],
            'range' => [
                'px' => ['min' => 50, 'max' => 1200],
                'vh' => ['min' => 5, 'max' => 100],
            ],
            'condition' => ['row_shape' => 'custom'],
        ]);

        $repeater->add_control('row_object_fit', [
            'label' => esc_html__('Image Fit', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'cover',
            'options' => [
                'cover' => esc_html__('Cover', 'nmj'),
                'contain' => esc_html__('Contain', 'nmj'),
                'fill' => esc_html__('Fill', 'nmj'),
                'scale-down' => esc_html__('Scale Down', 'nmj'),
                'none' => esc_html__('None', 'nmj'),
            ],
            'separator' => 'before',
        ]);

        $repeater->add_control('row_object_position', [
            'label' => esc_html__('Focus Point', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'center center',
            'options' => [
                'top left' => esc_html__('Top Left', 'nmj'),
                'top center' => esc_html__('Top Center', 'nmj'),
                'top right' => esc_html__('Top Right', 'nmj'),
                'center left' => esc_html__('Middle Left', 'nmj'),
                'center center' => esc_html__('Center', 'nmj'),
                'center right' => esc_html__('Middle Right', 'nmj'),
                'bottom left' => esc_html__('Bottom Left', 'nmj'),
                'bottom center' => esc_html__('Bottom Center', 'nmj'),
                'bottom right' => esc_html__('Bottom Right', 'nmj'),
            ],
            'condition' => ['row_object_fit' => ['cover', 'none']],
        ]);

        $this->add_control('row_pattern', [
            'label' => esc_html__('Rows', 'nmj'),
            'type' => Controls_Manager::REPEATER,
            'fields' => $repeater->get_controls(),
            'default' => [
                ['row_label' => 'Big row', 'row_columns' => 1, 'row_shape' => 'square', 'row_object_fit' => 'cover'],
                ['row_label' => 'Small rows', 'row_columns' => 2, 'row_shape' => 'square', 'row_object_fit' => 'cover'],
            ],
            'title_field' => '{{{ row_label }}} — {{{ row_columns }}} col',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_layout', [
            'label' => esc_html__('Layout', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('column_gap', [
            'label' => esc_html__('Gap Between Images', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', '%'],
            'default' => ['size' => 10, 'unit' => 'px'],
            'range' => ['px' => ['min' => 0, 'max' => 100]],
            'selectors' => ['{{WRAPPER}} .nmj-grid-row' => 'column-gap: {{SIZE}}{{UNIT}};'],
        ]);

        $this->add_responsive_control('row_gap', [
            'label' => esc_html__('Gap Between Rows', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', '%'],
            'default' => ['size' => 10, 'unit' => 'px'],
            'range' => ['px' => ['min' => 0, 'max' => 100]],
            'selectors' => ['{{WRAPPER}} .nmj-gallery-grid' => 'row-gap: {{SIZE}}{{UNIT}};'],
            'condition' => ['layout' => 'grid'],
        ]);

        $this->add_responsive_control('pg_thumbnails_position', [
            'label' => esc_html__('Thumbnails Position', 'nmj'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'left' => [
                    'title' => esc_html__('Left', 'nmj'),
                    'icon' => 'eicon-h-align-left',
                ],
                'top' => [
                    'title' => esc_html__('Top', 'nmj'),
                    'icon' => 'eicon-v-align-top',
                ],
                'right' => [
                    'title' => esc_html__('Right', 'nmj'),
                    'icon' => 'eicon-h-align-right',
                ],
                'bottom' => [
                    'title' => esc_html__('Bottom', 'nmj'),
                    'icon' => 'eicon-v-align-bottom',
                ],
            ],
            'default' => 'bottom',
            'selectors_dictionary' => [
                'left' => 'flex-direction: row-reverse; --pg-grid-cols: none; --pg-grid-rows: repeat(var(--pg-grid-count, 4), 1fr); --pg-auto-flow: column; --pg-auto-cols: 1fr; --pg-auto-rows: auto;',
                'right' => 'flex-direction: row; --pg-grid-cols: none; --pg-grid-rows: repeat(var(--pg-grid-count, 4), 1fr); --pg-auto-flow: column; --pg-auto-cols: 1fr; --pg-auto-rows: auto;',
                'top' => 'flex-direction: column-reverse; --pg-grid-cols: repeat(var(--pg-grid-count, 4), 1fr); --pg-grid-rows: none; --pg-auto-flow: row; --pg-auto-cols: auto; --pg-auto-rows: 1fr;',
                'bottom' => 'flex-direction: column; --pg-grid-cols: repeat(var(--pg-grid-count, 4), 1fr); --pg-grid-rows: none; --pg-auto-flow: row; --pg-auto-cols: auto; --pg-auto-rows: 1fr;',
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-product-gallery' => 'display: flex; {{VALUE}}',
                '{{WRAPPER}} .nmj-pg-main-wrap' => 'flex-grow: 1; min-width: 0;',
                '{{WRAPPER}} .nmj-pg-thumbnails-wrap' => 'display: grid; grid-template-columns: var(--pg-grid-cols, repeat(var(--pg-grid-count, 4), 1fr)); grid-template-rows: var(--pg-grid-rows, none); grid-auto-flow: var(--pg-auto-flow, row); grid-auto-columns: var(--pg-auto-cols, auto); grid-auto-rows: var(--pg-auto-rows, 1fr);',
            ],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_responsive_control('pg_thumbnails_width', [
            'label' => esc_html__('Thumbnails Area Width', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', '%'],
            'default' => ['size' => 100, 'unit' => 'px'],
            'range' => [
                'px' => ['min' => 50, 'max' => 500],
                '%' => ['min' => 10, 'max' => 50],
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-pg-thumbnails-wrap' => 'width: {{SIZE}}{{UNIT}}; flex-shrink: 0;',
                '{{WRAPPER}} .nmj-pg-main-wrap' => 'width: auto;',
            ],
            'condition' => [
                'layout' => 'product_gallery',
                'pg_thumbnails_position' => ['left', 'right'],
            ],
        ]);

        $this->add_responsive_control('pg_thumb_columns', [
            'label' => esc_html__('Thumbnail Columns / Rows', 'nmj'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 10,
            'default' => 4,
            'selectors' => ['{{WRAPPER}}' => '--pg-grid-count: {{VALUE}};'],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_responsive_control('pg_thumb_height', [
            'label' => esc_html__('Thumbnail Height', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'vh', 'vw', '%'],
            'range' => [
                'px' => ['min' => 30, 'max' => 500],
                'vh' => ['min' => 5, 'max' => 50],
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-pg-thumb-img' => 'height: {{SIZE}}{{UNIT}}; width: 100%; display: block;',
            ],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_control('pg_thumb_fit', [
            'label' => esc_html__('Thumbnail Image Fit', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'cover',
            'options' => [
                'cover' => esc_html__('Cover (Crop)', 'nmj'),
                'contain' => esc_html__('Contain (Fit)', 'nmj'),
                'fill' => esc_html__('Fill', 'nmj'),
            ],
            'selectors' => ['{{WRAPPER}} .nmj-pg-thumb-img' => 'object-fit: {{VALUE}};'],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_responsive_control('pg_gap', [
            'label' => esc_html__('Gap', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', '%'],
            'default' => ['size' => 10, 'unit' => 'px'],
            'range' => ['px' => ['min' => 0, 'max' => 100]],
            'selectors' => [
                '{{WRAPPER}} .nmj-product-gallery' => 'gap: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .nmj-pg-thumbnails-wrap' => 'gap: {{SIZE}}{{UNIT}};',
            ],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_responsive_control('pg_main_height', [
            'label' => esc_html__('Main Image Height', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'vh', 'vw', '%'],
            'range' => [
                'px' => ['min' => 100, 'max' => 1200],
                'vh' => ['min' => 10, 'max' => 100],
            ],
            'selectors' => [
                '{{WRAPPER}} .nmj-pg-main-img' => 'height: {{SIZE}}{{UNIT}}; width: 100%; display: block;',
            ],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_control('pg_main_fit', [
            'label' => esc_html__('Main Image Fit', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'cover',
            'options' => [
                'cover' => esc_html__('Cover (Crop)', 'nmj'),
                'contain' => esc_html__('Contain (Fit)', 'nmj'),
                'fill' => esc_html__('Fill', 'nmj'),
            ],
            'selectors' => ['{{WRAPPER}} .nmj-pg-main-img' => 'object-fit: {{VALUE}};'],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->add_control('big_row_multiplier', [
            'label' => esc_html__('Big Row Multiplier', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 1],
            'range' => ['px' => ['min' => 0.5, 'max' => 4, 'step' => 0.1]],
            'separator' => 'before',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_cell', [
            'label' => esc_html__('Image Cell', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('border_radius', [
            'label' => esc_html__('Border Radius', 'nmj'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em'],
            'selectors' => [
                '{{WRAPPER}} .nmj-grid-item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
                '{{WRAPPER}} .nmj-pg-main-wrap' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
                '{{WRAPPER}} .nmj-pg-thumb' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
            ],
        ]);

        $this->add_control('background_color', [
            'label' => esc_html__('Cell Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#f3f4f6',
            'selectors' => [
                '{{WRAPPER}} .nmj-grid-item' => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .nmj-pg-main-wrap' => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .nmj-pg-thumb' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('pg_thumb_active_color', [
            'label' => esc_html__('Active Thumb Color', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#000000',
            'selectors' => ['{{WRAPPER}} .nmj-pg-thumb.nmj-pg-thumb-active' => 'border-color: {{VALUE}};'],
            'condition' => ['layout' => 'product_gallery'],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_hover', [
            'label' => esc_html__('Hover', 'nmj'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('hover_opacity', [
            'label' => esc_html__('Opacity', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 0.85],
            'range' => ['px' => ['min' => 0.1, 'max' => 1, 'step' => 0.05]],
            'selectors' => ['{{WRAPPER}} .nmj-grid-item:hover .nmj-grid-img' => 'opacity: {{SIZE}};'],
        ]);

        $this->add_control('hover_scale', [
            'label' => esc_html__('Scale', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 1.03],
            'range' => ['px' => ['min' => 1, 'max' => 1.5, 'step' => 0.01]],
            'selectors' => ['{{WRAPPER}} .nmj-grid-item:hover .nmj-grid-img' => 'transform: scale({{SIZE}});'],
        ]);

        $this->add_control('hover_transition', [
            'label' => esc_html__('Transition (ms)', 'nmj'),
            'type' => Controls_Manager::SLIDER,
            'default' => ['size' => 350],
            'range' => ['px' => ['min' => 0, 'max' => 1500]],
            'selectors' => ['{{WRAPPER}} .nmj-grid-img' => 'transition: opacity {{SIZE}}ms ease, transform {{SIZE}}ms ease;'],
        ]);

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $gallery = $settings['gallery'] ?? [];
        $pattern = $settings['row_pattern'] ?? [];
        $image_size = $settings['image_size'] ?? 'large';
        $thumb_image_size = $settings['thumb_image_size'] ?? 'thumbnail';
        $open_lightbox = $settings['open_lightbox'] ?? 'default';
        $layout = $settings['layout'] ?? 'grid';
        $multiplier = (float) ($settings['big_row_multiplier']['size'] ?? 1);
        $widget_id = $this->get_id();

        if (empty($gallery)) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<p style="background:#fff3cd;color:#856404;padding:10px 14px;font-size:13px;border-radius:4px;">⚠ <em>Add images in the Images panel, or connect a dynamic tag.</em></p>';
            }
            return;
        }

        if (empty($pattern)) {
            $pattern = [
                ['row_columns' => 1, 'row_shape' => 'square', 'row_object_fit' => 'cover', 'row_object_position' => 'center center'],
                ['row_columns' => 2, 'row_shape' => 'square', 'row_object_fit' => 'cover', 'row_object_position' => 'center center'],
            ];
        }

        $pattern_length = count($pattern);
        $total = count($gallery);
        $img_index = 0;
        $row_number = 0;

        if ($layout === 'product_gallery') {
            // this changed: Added an isset check for \Elementor\Plugin::$instance->kit to ensure it is not null before calling get_settings() to prevent fatal errors during AJAX rendering.
            $kit = \Elementor\Plugin::$instance->kit;
            $is_lightbox = 'yes' === $open_lightbox || ('default' === $open_lightbox && $kit && 'yes' === $kit->get_settings('global_image_lightbox'));
?>
            <div id="nmj-gg-<?php echo esc_attr($widget_id); ?>" class="nmj-product-gallery">
                <div class="nmj-pg-main-wrap">
                    <?php foreach ($gallery as $index => $image):
                        $img_id = isset($image['id']) ? absint($image['id']) : 0;
                        $img_url = $image['url'] ?? '';
                        $full_url = '';

                        if ($img_id) {
                            $img_html = wp_get_attachment_image($img_id, $image_size, false, [
                                'class' => 'nmj-pg-main-img',
                                'loading' => $index === 0 ? 'eager' : 'lazy',
                                'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: '',
                            ]);
                            $full_url = wp_get_attachment_image_url($img_id, 'full');
                        } elseif ($img_url) {
                            $img_html = '<img src="' . esc_url($img_url) . '" class="nmj-pg-main-img" loading="' . ($index === 0 ? 'eager' : 'lazy') . '" alt="">';
                            $full_url = $img_url;
                        } else {
                            continue;
                        }

                        $is_active = $index === 0 ? ' nmj-main-active' : '';
                        $style = $index === 0 ? '' : ' style="display: none;"';

                        if ($is_lightbox && !empty($full_url)): ?>
                            <a href="<?php echo esc_url($full_url); ?>" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="<?php echo esc_attr($widget_id); ?>" class="nmj-pg-main-link<?php echo $is_active; ?>" <?php echo $style; ?>>
                                <?php echo $img_html; ?>
                            </a>
                        <?php else: ?>
                            <div class="nmj-pg-main-link<?php echo $is_active; ?>" <?php echo $style; ?>>
                                <?php echo $img_html; ?>
                            </div>
                    <?php endif;
                    endforeach; ?>
                </div>

                <?php if ($total > 1): ?>
                    <div class="nmj-pg-thumbnails-wrap">
                        <?php foreach ($gallery as $index => $image):
                            $img_id = isset($image['id']) ? absint($image['id']) : 0;
                            $img_url = $image['url'] ?? '';
                            $thumb_html = '';

                            if ($img_id) {
                                $thumb_html = wp_get_attachment_image($img_id, $thumb_image_size, false, [
                                    'class' => 'nmj-pg-thumb-img',
                                    'loading' => 'lazy',
                                    'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: '',
                                ]);
                                if (!$thumb_html) {
                                    $thumb_html = wp_get_attachment_image($img_id, 'thumbnail', false, ['class' => 'nmj-pg-thumb-img', 'loading' => 'lazy']);
                                }
                            } elseif ($img_url) {
                                $thumb_html = '<img src="' . esc_url($img_url) . '" class="nmj-pg-thumb-img" loading="lazy" alt="">';
                            } else {
                                continue;
                            }
                        ?>
                            <div class="nmj-pg-thumb <?php echo $index === 0 ? 'nmj-pg-thumb-active' : ''; ?>" tabindex="0">
                                <?php echo $thumb_html; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php
            return;
        }
        ?>

        <div id="nmj-gg-<?php echo esc_attr($widget_id); ?>">
            <div class="nmj-gallery-grid">
                <?php
                while ($img_index < $total):
                    $row_def = $pattern[$row_number % $pattern_length];
                    $cols = max(1, absint($row_def['row_columns'] ?? 1));
                    $pattern_idx = $row_number % $pattern_length;
                    $row_images = array_slice($gallery, $img_index, $cols);

                    if (empty($row_images))
                        break;

                    $shape = $row_def['row_shape'] ?? 'square';
                    $fit = $row_def['row_object_fit'] ?? 'cover';
                    $pos = $row_def['row_object_position'] ?? 'center center';
                    $custom_h = $row_def['row_custom_height'] ?? [];

                    $ar_css = 'auto';
                    $h_css = 'auto';

                    if ($shape === 'square') {
                        if ($cols === 1 && $multiplier !== 1.0) {
                            $ar_h = round(1 / $multiplier, 4);
                            $ar_css = "1 / {$ar_h}";
                        } else {
                            $ar_css = '1 / 1';
                        }
                    } elseif (strpos($shape, 'ar_') === 0) {
                        $ar_val = (float) substr($shape, 3);
                        if ($cols === 1 && $multiplier !== 1.0) {
                            $ar_val = round($ar_val / $multiplier, 4);
                        }
                        $ar_css = "{$ar_val}";
                    } elseif ($shape === 'custom' && !empty($custom_h['size'])) {
                        $unit = $custom_h['unit'] ?? 'px';
                        $size = floatval($custom_h['size']);
                        $h_css = "{$size}{$unit}";
                        if ($cols === 1 && $multiplier !== 1.0) {
                            $scaled = round($size * $multiplier, 2);
                            $h_css = "{$scaled}{$unit}";
                        }
                    }
                ?>
                    <div class="nmj-grid-row" data-row-index="<?php echo esc_attr($pattern_idx); ?>"
                        style="--nmj-cols: <?php echo esc_attr($cols); ?>; --nmj-ar: <?php echo esc_attr($ar_css); ?>; --nmj-h: <?php echo esc_attr($h_css); ?>; --nmj-fit: <?php echo esc_attr($fit); ?>; --nmj-pos: <?php echo esc_attr($pos); ?>;">
                        <?php foreach ($row_images as $image):
                            $img_id = isset($image['id']) ? absint($image['id']) : 0;
                            $img_url = $image['url'] ?? '';

                            $full_img_url = '';
                            if ($img_id) {
                                $img_html = wp_get_attachment_image($img_id, $image_size, false, [
                                    'class' => 'nmj-grid-img',
                                    'loading' => $img_index === 0 ? 'eager' : 'lazy',
                                    'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: '',
                                ]);
                                $full_img_url = wp_get_attachment_image_url($img_id, 'full');
                            } elseif ($img_url) {
                                $img_html = '<img src="' . esc_url($img_url) . '" class="nmj-grid-img" loading="lazy" alt="">';
                                $full_img_url = $img_url;
                            } else {
                                continue;
                            }

                            $item_html = $img_html;

                            // this changed: Added an isset check for \Elementor\Plugin::$instance->kit to ensure it is not null before calling get_settings() to prevent fatal errors during AJAX rendering.
                            $kit = \Elementor\Plugin::$instance->kit;
                            $is_lightbox = 'yes' === $open_lightbox || ('default' === $open_lightbox && $kit && 'yes' === $kit->get_settings('global_image_lightbox'));

                            if ($is_lightbox && ! empty($full_img_url)) {
                                $item_html = sprintf(
                                    '<a href="%s" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="%s" class="nmj-gallery-lightbox-link">%s</a>',
                                    esc_url($full_img_url),
                                    esc_attr($widget_id),
                                    $img_html
                                );
                            }
                        ?>
                            <div class="nmj-grid-item"><?php echo $item_html; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php
                    $img_index += count($row_images);
                    $row_number++;
                endwhile;
                ?>
            </div>
        </div>
    <?php
    }
    protected function content_template(): void
    {
    ?>
        <#
            var pattern=settings.row_pattern || [];
            if (!pattern.length) {
            pattern=[{row_columns: 1, row_shape: 'square' }, {row_columns: 2, row_shape: 'square' }];
            }
            var gallery=settings.gallery || [];
            var hasImages=gallery.length> 0;
            var multiplier = settings.big_row_multiplier ? parseFloat(settings.big_row_multiplier.size) || 1 : 1;
            var open_lightbox = settings.open_lightbox || 'default';
            var layout = settings.layout || 'grid';
            #>
            <# if (!hasImages) { #>
                <p style="background:#fff3cd;color:#856404;padding:10px 14px;font-size:13px;border-radius:4px;">⚠ <em>Add images in the Images panel, or connect a dynamic tag.</em></p>
                <# return; } #>

                    <# if (layout==='product_gallery' ) {
                        var is_lightbox=open_lightbox==='yes' || (open_lightbox==='default' && typeof elementor !=='undefined' && elementor.getKitSettings && elementor.getKitSettings('global_image_lightbox')==='yes' );
                        #>
                        <div class="nmj-product-gallery">
                            <div class="nmj-pg-main-wrap">
                                <# _.each(gallery, function(image, index) {
                                    var img_html='<img src="' + _.escape(image.url) + '" class="nmj-pg-main-img" alt="">' ;
                                    var is_active=index===0 ? ' nmj-main-active' : '' ;
                                    var style=index===0 ? '' : ' style="display: none;"' ;

                                    if (is_lightbox && image.url) { #>
                                    <a href="{{ image.url }}" data-elementor-open-lightbox="yes" class="nmj-pg-main-link{{ is_active }}" {{{ style }}}>
                                        {{{ img_html }}}
                                    </a>
                                    <# } else { #>
                                        <div class="nmj-pg-main-link{{ is_active }}" {{{ style }}}>
                                            {{{ img_html }}}
                                        </div>
                                        <# }
                                            }); #>
                            </div>

                            <# if (gallery.length> 1) { #>
                                <div class="nmj-pg-thumbnails-wrap">
                                    <# _.each(gallery, function(image, index) {
                                        var thumb_html='<img src="' + _.escape(image.url) + '" class="nmj-pg-thumb-img" alt="">' ;
                                        #>
                                        <div class="nmj-pg-thumb {{ index === 0 ? 'nmj-pg-thumb-active' : '' }}">
                                            {{{ thumb_html }}}
                                        </div>
                                        <# }); #>
                                </div>
                                <# } #>
                        </div>
                        <# return; } #>

                            <div class="nmj-gallery-grid">
                                <#
                                    var img_index=0;
                                    var row_number=0;
                                    var pattern_length=pattern.length;

                                    while (img_index < gallery.length) {
                                    var row_def=pattern[row_number % pattern_length];
                                    var cols=Math.max(1, parseInt(row_def.row_columns) || 1);
                                    var row_images=gallery.slice(img_index, img_index + cols);

                                    if (!row_images.length) break;

                                    var shape=row_def.row_shape || 'square' ;
                                    var fit=row_def.row_object_fit || 'cover' ;
                                    var pos=row_def.row_object_position || 'center center' ;

                                    var ar_css='auto' ;
                                    var h_css='auto' ;

                                    if (shape==='square' ) {
                                    if (cols===1 && multiplier !==1.0) {
                                    var ar_h=(1 / multiplier).toFixed(4);
                                    ar_css='1 / ' + ar_h;
                                    } else {
                                    ar_css='1 / 1' ;
                                    }
                                    } else if (shape.indexOf('ar_')===0) {
                                    var ar_val=parseFloat(shape.substring(3));
                                    if (cols===1 && multiplier !==1.0) {
                                    ar_val=(ar_val / multiplier).toFixed(4);
                                    }
                                    ar_css=ar_val;
                                    } else if (shape==='custom' ) {
                                    var size=row_def.row_custom_height ? parseFloat(row_def.row_custom_height.size) : 0;
                                    var unit=row_def.row_custom_height ? row_def.row_custom_height.unit : 'px' ;
                                    if (size) {
                                    h_css=size + unit;
                                    if (cols===1 && multiplier !==1.0) {
                                    var scaled=(size * multiplier).toFixed(2);
                                    h_css=scaled + unit;
                                    }
                                    }
                                    }
                                    #>
                                    <div class="nmj-grid-row" style="--nmj-cols: {{ cols }}; --nmj-ar: {{ ar_css }}; --nmj-h: {{ h_css }}; --nmj-fit: {{ fit }}; --nmj-pos: {{ pos }};">
                                        <# _.each(row_images, function(image) {
                                            var img_html='<img src="' + _.escape(image.url) + '" class="nmj-grid-img" alt="">' ;
                                            var item_html=img_html;

                                            var is_lightbox=open_lightbox==='yes' || (open_lightbox==='default' && typeof elementor !=='undefined' && elementor.getKitSettings && elementor.getKitSettings('global_image_lightbox')==='yes' );

                                            if (is_lightbox && image.url) {
                                            item_html='<a href="' + _.escape(image.url) + '" data-elementor-open-lightbox="yes" class="nmj-gallery-lightbox-link">' + img_html + '</a>' ;
                                            }
                                            #>
                                            <div class="nmj-grid-item">{{{ item_html }}}</div>
                                            <# }); #>
                                    </div>
                                    <#
                                        img_index +=row_images.length;
                                        row_number++;
                                        }
                                        #>
                            </div>
                    <?php
                }
            }
