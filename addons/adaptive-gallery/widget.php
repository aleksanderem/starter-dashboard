<?php
/**
 * Adaptive Gallery Elementor Widget
 *
 * Gallery carousel that honors original image dimensions and adjusts
 * visible slides based on image orientation (landscape/portrait).
 */

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Group_Control_Image_Size;

class Starter_Adaptive_Gallery_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return starter_hub_prefix() . '_adaptive_gallery';
    }

    public function get_title() {
        return __('Adaptive Gallery', 'starter-dashboard');
    }

    public function get_icon() {
        return 'eicon-gallery-justified';
    }

    public function get_categories() {
        return ['starter-addons'];
    }

    public function get_keywords() {
        return ['gallery', 'carousel', 'slider', 'photos', 'adaptive', 'responsive'];
    }

    protected function register_controls() {
        // Content Section - Gallery
        $this->start_controls_section(
            'gallery_section',
            [
                'label' => __('Gallery Images', 'starter-dashboard'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'gallery',
            [
                'label' => __('Add Images', 'starter-dashboard'),
                'type' => Controls_Manager::GALLERY,
                'default' => [],
                'show_label' => false,
                'dynamic' => [
                    'active' => true,
                ],
            ]
        );

        $this->end_controls_section();

        // Content Section - Carousel Settings
        $this->start_controls_section(
            'carousel_section',
            [
                'label' => __('Carousel Settings', 'starter-dashboard'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'slides_landscape',
            [
                'label' => __('Slides for Landscape Images', 'starter-dashboard'),
                'type' => Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 5,
                'default' => 2,
                'description' => __('Number of slides visible when current image is landscape', 'starter-dashboard'),
            ]
        );

        $this->add_control(
            'slides_portrait',
            [
                'label' => __('Slides for Portrait Images', 'starter-dashboard'),
                'type' => Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 5,
                'default' => 3,
                'description' => __('Number of slides visible when current image is portrait', 'starter-dashboard'),
            ]
        );

        $this->add_control(
            'slides_square',
            [
                'label' => __('Slides for Square Images', 'starter-dashboard'),
                'type' => Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 5,
                'default' => 2,
                'description' => __('Number of slides visible when current image is square', 'starter-dashboard'),
            ]
        );

        $this->add_control(
            'gallery_height',
            [
                'label' => __('Gallery Height', 'starter-dashboard'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px', 'vh'],
                'range' => [
                    'px' => [
                        'min' => 200,
                        'max' => 800,
                    ],
                    'vh' => [
                        'min' => 20,
                        'max' => 100,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 450,
                ],
                'selectors' => [
                    '{{WRAPPER}} .adaptive-gallery-container' => 'height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'autoplay',
            [
                'label' => __('Autoplay', 'starter-dashboard'),
                'type' => Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'autoplay_speed',
            [
                'label' => __('Autoplay Speed (ms)', 'starter-dashboard'),
                'type' => Controls_Manager::NUMBER,
                'min' => 1000,
                'max' => 10000,
                'step' => 500,
                'default' => 4000,
                'condition' => [
                    'autoplay' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'infinite',
            [
                'label' => __('Infinite Loop', 'starter-dashboard'),
                'type' => Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'show_arrows',
            [
                'label' => __('Show Arrows', 'starter-dashboard'),
                'type' => Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'show_dots',
            [
                'label' => __('Show Dots', 'starter-dashboard'),
                'type' => Controls_Manager::SWITCHER,
                'default' => '',
            ]
        );

        $this->end_controls_section();

        // Style Section - Images
        $this->start_controls_section(
            'style_images_section',
            [
                'label' => __('Images', 'starter-dashboard'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'image_border_radius',
            [
                'label' => __('Border Radius', 'starter-dashboard'),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%'],
                'default' => [
                    'top' => 12,
                    'right' => 12,
                    'bottom' => 12,
                    'left' => 12,
                    'unit' => 'px',
                ],
                'selectors' => [
                    '{{WRAPPER}} .adaptive-gallery-slide img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'image_spacing',
            [
                'label' => __('Spacing Between Images', 'starter-dashboard'),
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 50,
                    ],
                ],
                'default' => [
                    'size' => 15,
                ],
            ]
        );

        $this->end_controls_section();

        // Style Section - Navigation
        $this->start_controls_section(
            'style_nav_section',
            [
                'label' => __('Navigation', 'starter-dashboard'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'arrow_color',
            [
                'label' => __('Arrow Color', 'starter-dashboard'),
                'type' => Controls_Manager::COLOR,
                'default' => '#1A3580',
                'selectors' => [
                    '{{WRAPPER}} .adaptive-gallery-arrow' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'arrow_bg_color',
            [
                'label' => __('Arrow Background', 'starter-dashboard'),
                'type' => Controls_Manager::COLOR,
                'default' => 'rgba(255, 255, 255, 0.9)',
                'selectors' => [
                    '{{WRAPPER}} .adaptive-gallery-arrow' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'arrow_size',
            [
                'label' => __('Arrow Size', 'starter-dashboard'),
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 30,
                        'max' => 60,
                    ],
                ],
                'default' => [
                    'size' => 44,
                ],
                'selectors' => [
                    '{{WRAPPER}} .adaptive-gallery-arrow' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $gallery = $settings['gallery'];

        if (empty($gallery)) {
            return;
        }

        $widget_id = $this->get_id();
        $spacing = isset($settings['image_spacing']['size']) ? $settings['image_spacing']['size'] : 15;

        // Prepare images data with orientation
        $images_data = [];
        foreach ($gallery as $image) {
            $image_data = wp_get_attachment_image_src($image['id'], 'full');
            if ($image_data) {
                $width = $image_data[1];
                $height = $image_data[2];
                $ratio = $width / $height;

                // Determine orientation
                if ($ratio > 1.2) {
                    $orientation = 'landscape';
                } elseif ($ratio < 0.8) {
                    $orientation = 'portrait';
                } else {
                    $orientation = 'square';
                }

                $images_data[] = [
                    'id' => $image['id'],
                    'url' => $image_data[0],
                    'width' => $width,
                    'height' => $height,
                    'orientation' => $orientation,
                    'alt' => get_post_meta($image['id'], '_wp_attachment_image_alt', true),
                ];
            }
        }

        if (empty($images_data)) {
            return;
        }

        // Encode settings for JS
        $slider_settings = [
            'slidesLandscape' => intval($settings['slides_landscape']),
            'slidesPortrait' => intval($settings['slides_portrait']),
            'slidesSquare' => intval($settings['slides_square']),
            'autoplay' => $settings['autoplay'] === 'yes',
            'autoplaySpeed' => intval($settings['autoplay_speed']),
            'infinite' => $settings['infinite'] === 'yes',
            'showArrows' => $settings['show_arrows'] === 'yes',
            'showDots' => $settings['show_dots'] === 'yes',
            'spacing' => $spacing,
            'images' => $images_data,
        ];
        ?>

        <div class="adaptive-gallery-wrapper" id="adaptive-gallery-<?php echo esc_attr($widget_id); ?>">
            <div class="adaptive-gallery-container">
                <div class="adaptive-gallery-track">
                    <?php foreach ($images_data as $index => $img) : ?>
                        <div class="adaptive-gallery-slide"
                             data-orientation="<?php echo esc_attr($img['orientation']); ?>"
                             data-index="<?php echo esc_attr($index); ?>">
                            <img src="<?php echo esc_url($img['url']); ?>"
                                 alt="<?php echo esc_attr($img['alt']); ?>"
                                 loading="lazy">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($settings['show_arrows'] === 'yes') : ?>
                <button class="adaptive-gallery-arrow adaptive-gallery-prev" aria-label="Previous">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
                <button class="adaptive-gallery-arrow adaptive-gallery-next" aria-label="Next">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            <?php endif; ?>

            <?php if ($settings['show_dots'] === 'yes') : ?>
                <div class="adaptive-gallery-dots"></div>
            <?php endif; ?>
        </div>

        <script>
            (function() {
                var settings = <?php echo json_encode($slider_settings); ?>;
                if (typeof window.initAdaptiveGallery === 'function') {
                    window.initAdaptiveGallery('adaptive-gallery-<?php echo esc_attr($widget_id); ?>', settings);
                } else {
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof window.initAdaptiveGallery === 'function') {
                            window.initAdaptiveGallery('adaptive-gallery-<?php echo esc_attr($widget_id); ?>', settings);
                        }
                    });
                }
            })();
        </script>

        <?php
    }

    public function get_script_depends() {
        return [starter_hub_prefix() . '-adaptive-gallery'];
    }

    public function get_style_depends() {
        return [starter_hub_prefix() . '-adaptive-gallery'];
    }
}
