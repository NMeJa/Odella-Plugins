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

    protected function register_controls(): void
    {
        $this->start_controls_section('section_images', [
            'label' => esc_html__('Images', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
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

        $this->end_controls_section();

        $this->start_controls_section('section_pattern', [
            'label' => esc_html__('Row Pattern', 'nmj'),
            'tab' => Controls_Manager::TAB_CONTENT,
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

        $repeater->add_control('row_shape', [
            'label' => esc_html__('Image Shape', 'nmj'),
            'type' => Controls_Manager::SELECT,
            'default' => 'square',
            'options' => [
                'square' => esc_html__('Square (1:1)', 'nmj'),
                'full' => esc_html__('Full (natural ratio)', 'nmj'),
                'custom' => esc_html__('Custom height', 'nmj'),
            ],
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
            'selectors' => ['{{WRAPPER}} .nmj-grid-item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;'],
        ]);

        $this->add_control('background_color', [
            'label' => esc_html__('Cell Background', 'nmj'),
            'type' => Controls_Manager::COLOR,
            'default' => '#f3f4f6',
            'selectors' => ['{{WRAPPER}} .nmj-grid-item' => 'background-color: {{VALUE}};'],
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

        $row_css = '';
        foreach ($pattern as $i => $row_def) {
            $shape = $row_def['row_shape'] ?? 'square';
            $fit = $row_def['row_object_fit'] ?? 'cover';
            $pos = $row_def['row_object_position'] ?? 'center center';
            $custom_h = $row_def['row_custom_height'] ?? [];
            $cols = max(1, absint($row_def['row_columns'] ?? 1));

            $aspect_ratio_css = '';
            $height_css = '';

            if ($shape === 'square') {
                if ($cols === 1 && $multiplier !== 1.0) {
                    $ar_h = round(1 / $multiplier, 4);
                    $aspect_ratio_css = "aspect-ratio: 1 / {$ar_h};";
                } else {
                    $aspect_ratio_css = 'aspect-ratio: 1 / 1;';
                }
            } elseif ($shape === 'full') {
                $aspect_ratio_css = 'aspect-ratio: auto;';
                $height_css = 'height: auto;';
            } elseif ($shape === 'custom' && !empty($custom_h['size'])) {
                $unit = $custom_h['unit'] ?? 'px';
                $size = floatval($custom_h['size']);
                $height_css = "height: {$size}{$unit};";
                if ($cols === 1 && $multiplier !== 1.0) {
                    $scaled = round($size * $multiplier, 2);
                    $height_css = "height: {$scaled}{$unit};";
                }
            }

            $row_css .= "
                #nmj-gg-{$widget_id} .nmj-grid-row[data-row-index='{$i}'] .nmj-grid-item { {$aspect_ratio_css} {$height_css} }
                #nmj-gg-{$widget_id} .nmj-grid-row[data-row-index='{$i}'] .nmj-grid-img { object-fit: {$fit}; object-position: {$pos}; }
            ";
        }

?>
        <style id="nmj-gg-<?php echo esc_attr($widget_id); ?>-css">
            #nmj-gg-<?php echo esc_attr($widget_id); ?> .nmj-gallery-grid {
                display: flex;
                flex-direction: column;
            }

            #nmj-gg-<?php echo esc_attr($widget_id); ?> .nmj-grid-row {
                display: grid;
                grid-template-columns: repeat(var(--nmj-cols, 1), 1fr);
            }

            #nmj-gg-<?php echo esc_attr($widget_id); ?> .nmj-grid-item {
                overflow: hidden;
                display: block;
                width: 100%;
                position: relative;
            }

            #nmj-gg-<?php echo esc_attr($widget_id); ?> .nmj-grid-img {
                display: block;
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            <?php echo $row_css; ?>
        </style>

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
                ?>
                    <div class="nmj-grid-row" data-row-index="<?php echo $pattern_idx; ?>"
                        style="--nmj-cols: <?php echo $cols; ?>;">
                        <?php foreach ($row_images as $image):
                            $img_id = isset($image['id']) ? absint($image['id']) : 0;
                            $img_url = $image['url'] ?? '';

                            if ($img_id) {
                                $img_html = wp_get_attachment_image($img_id, $image_size, false, [
                                    'class' => 'nmj-grid-img',
                                    'loading' => $img_index === 0 ? 'eager' : 'lazy',
                                    'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: '',
                                ]);
                            } elseif ($img_url) {
                                $img_html = '<img src="' . esc_url($img_url) . '" class="nmj-grid-img" loading="lazy" alt="">';
                            } else {
                                continue;
                            }
                        ?>
                            <div class="nmj-grid-item"><?php echo $img_html; ?></div>
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
            var hasImages=settings.gallery && settings.gallery.length> 0;
        #>
        <# if (!hasImages) { #>
            <p style="background:#fff3cd;color:#856404;padding:10px 14px;font-size:13px;border-radius:4px;">⚠ <em>Add images in the Images panel, or connect a dynamic tag.</em></p>
        <# } #>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <# for (var r=0; r < pattern.length; r++) { 
                var cols=parseInt(pattern[r].row_columns) || 1; 
                var shape=pattern[r].row_shape || 'square' ; 
                var ar=shape==='square' ? '1/1' : shape==='full' ? '3/2' : 'unset' ; 
                var h=shape==='custom' ? '80px' : 'auto' ; 
            #>
                <div style="display:grid;grid-template-columns:repeat({{ cols }}, 1fr);gap:6px;">
                    <# for (var c=0; c < cols; c++) { #>
                        <div style="background:#e5e7eb;aspect-ratio:{{ ar }};height:{{ h }};border-radius:4px;display:flex;align-items:center;justify-content:center;min-height:40px;">
                            <span style="font-size:18px;opacity:0.5;">🖼</span>
                        </div>
                    <# } #>
                </div>
            <# } #>
        </div>
        <?php
    }
}