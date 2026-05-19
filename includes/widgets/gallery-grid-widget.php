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

        foreach ( $default_image_sizes as $size ) {
            $width = 0;
            $height = 0;
            if ( in_array( $size, [ 'thumbnail', 'medium', 'medium_large', 'large' ] ) ) {
                $width  = (int) get_option( "{$size}_size_w" );
                $height = (int) get_option( "{$size}_size_h" );
            } elseif ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
                $width  = (int) $_wp_additional_image_sizes[ $size ]['width'];
                $height = (int) $_wp_additional_image_sizes[ $size ]['height'];
            }

            if ( $width > 0 && $height > 0 ) {
                $ar = round( $width / $height, 4 );
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
        $open_lightbox = $settings['open_lightbox'] ?? 'default';
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
                            $is_lightbox = 'yes' === $open_lightbox || ('default' === $open_lightbox && 'yes' === \Elementor\Plugin::$instance->kit->get_settings('global_image_lightbox'));
                            
                            if ( $is_lightbox && ! empty( $full_img_url ) ) {
                                $item_html = sprintf(
                                    '<a href="%s" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="%s" class="nmj-gallery-lightbox-link">%s</a>',
                                    esc_url( $full_img_url ),
                                    esc_attr( $widget_id ),
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
            var pattern = settings.row_pattern || []; 
            if (!pattern.length) { 
                pattern = [{row_columns: 1, row_shape: 'square'}, {row_columns: 2, row_shape: 'square'}]; 
            } 
            var gallery = settings.gallery || [];
            var hasImages = gallery.length > 0;
            var multiplier = settings.big_row_multiplier ? parseFloat(settings.big_row_multiplier.size) || 1 : 1;
            var open_lightbox = settings.open_lightbox || 'default';
        #>
        <# if (!hasImages) { #>
            <p style="background:#fff3cd;color:#856404;padding:10px 14px;font-size:13px;border-radius:4px;">⚠ <em>Add images in the Images panel, or connect a dynamic tag.</em></p>
        <# return; } #>
        
        <div class="nmj-gallery-grid">
            <# 
            var img_index = 0;
            var row_number = 0;
            var pattern_length = pattern.length;
            
            while (img_index < gallery.length) {
                var row_def = pattern[row_number % pattern_length];
                var cols = Math.max(1, parseInt(row_def.row_columns) || 1);
                var row_images = gallery.slice(img_index, img_index + cols);
                
                if (!row_images.length) break;
                
                var shape = row_def.row_shape || 'square';
                var fit = row_def.row_object_fit || 'cover';
                var pos = row_def.row_object_position || 'center center';
                
                var ar_css = 'auto';
                var h_css = 'auto';
                
                if (shape === 'square') {
                    if (cols === 1 && multiplier !== 1.0) {
                        var ar_h = (1 / multiplier).toFixed(4);
                        ar_css = '1 / ' + ar_h;
                    } else {
                        ar_css = '1 / 1';
                    }
                } else if (shape.indexOf('ar_') === 0) {
                    var ar_val = parseFloat(shape.substring(3));
                    if (cols === 1 && multiplier !== 1.0) {
                        ar_val = (ar_val / multiplier).toFixed(4);
                    }
                    ar_css = ar_val;
                } else if (shape === 'custom') {
                    var size = row_def.row_custom_height ? parseFloat(row_def.row_custom_height.size) : 0;
                    var unit = row_def.row_custom_height ? row_def.row_custom_height.unit : 'px';
                    if (size) {
                        h_css = size + unit;
                        if (cols === 1 && multiplier !== 1.0) {
                            var scaled = (size * multiplier).toFixed(2);
                            h_css = scaled + unit;
                        }
                    }
                }
            #>
                <div class="nmj-grid-row" style="--nmj-cols: {{ cols }}; --nmj-ar: {{ ar_css }}; --nmj-h: {{ h_css }}; --nmj-fit: {{ fit }}; --nmj-pos: {{ pos }};">
                    <# _.each(row_images, function(image) { 
                        var img_html = '<img src="' + _.escape(image.url) + '" class="nmj-grid-img" alt="">';
                        var item_html = img_html;
                        
                        var is_lightbox = open_lightbox === 'yes' || (open_lightbox === 'default' && typeof elementor !== 'undefined' && elementor.getKitSettings && elementor.getKitSettings('global_image_lightbox') === 'yes');
                        
                        if (is_lightbox && image.url) {
                            item_html = '<a href="' + _.escape(image.url) + '" data-elementor-open-lightbox="yes" class="nmj-gallery-lightbox-link">' + img_html + '</a>';
                        }
                    #>
                        <div class="nmj-grid-item">{{{ item_html }}}</div>
                    <# }); #>
                </div>
            <# 
                img_index += row_images.length;
                row_number++;
            } 
            #>
        </div>
        <?php
    }
}