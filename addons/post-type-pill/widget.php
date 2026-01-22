<?php
/**
 * Elementor Post Type Pill Widget
 *
 * @author Alex Miesak
 */

defined('ABSPATH') || exit;

class Starter_Post_Type_Pill_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'starter-post-type-pill';
    }

    public function get_title() {
        return __('Post Type Pill', 'starter-dashboard');
    }

    public function get_icon() {
        return 'eicon-tags';
    }

    public function get_categories() {
        return ['starter-utils', 'starter-main', 'theme-elements'];
    }

    public function get_keywords() {
        return ['post type', 'pill', 'badge', 'label', 'category', 'search'];
    }

    protected function register_controls() {

        // Content Section
        $this->start_controls_section(
            'content_section',
            [
                'label' => __('Content', 'starter-dashboard'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'use_custom_labels',
            [
                'label' => __('Use Custom Labels', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'label_on' => __('Yes', 'starter-dashboard'),
                'label_off' => __('No', 'starter-dashboard'),
                'return_value' => 'yes',
                'default' => 'yes',
                'description' => __('Override default post type labels with custom ones', 'starter-dashboard'),
            ]
        );

        // Get all public post types for the repeater
        $post_types = Starter_Addon_Post_Type_Pill::get_post_types_options();
        $default_labels = Starter_Addon_Post_Type_Pill::get_default_labels();

        $repeater = new \Elementor\Repeater();

        $repeater->add_control(
            'post_type',
            [
                'label' => __('Post Type', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => $post_types,
                'default' => 'post',
            ]
        );

        $repeater->add_control(
            'custom_label',
            [
                'label' => __('Custom Label', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'placeholder' => __('e.g., Article, News, etc.', 'starter-dashboard'),
            ]
        );

        $repeater->add_control(
            'pill_color',
            [
                'label' => __('Pill Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#0073aa',
            ]
        );

        $repeater->add_control(
            'text_color',
            [
                'label' => __('Text Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#ffffff',
            ]
        );

        // Build default items
        $default_items = [];
        foreach (['post', 'page'] as $pt) {
            if (isset($post_types[$pt])) {
                $default_items[] = [
                    'post_type' => $pt,
                    'custom_label' => isset($default_labels[$pt]) ? $default_labels[$pt] : $post_types[$pt],
                    'pill_color' => $pt === 'post' ? '#0073aa' : '#23282d',
                    'text_color' => '#ffffff',
                ];
            }
        }

        $this->add_control(
            'post_type_labels',
            [
                'label' => __('Post Type Labels', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::REPEATER,
                'fields' => $repeater->get_controls(),
                'default' => $default_items,
                'title_field' => '{{{ post_type }}} → {{{ custom_label }}}',
                'condition' => [
                    'use_custom_labels' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'fallback_label',
            [
                'label' => __('Fallback Label', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => '',
                'placeholder' => __('Leave empty to use WP default', 'starter-dashboard'),
                'description' => __('Label for post types not in the list above', 'starter-dashboard'),
            ]
        );

        $this->end_controls_section();

        // Style Section - Pill
        $this->start_controls_section(
            'style_section',
            [
                'label' => __('Pill Style', 'starter-dashboard'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'default_bg_color',
            [
                'label' => __('Default Background', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#0073aa',
                'selectors' => [
                    '{{WRAPPER}} .bp-post-type-pill' => 'background-color: {{VALUE}};',
                ],
                'condition' => [
                    'use_custom_labels!' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'default_text_color',
            [
                'label' => __('Default Text Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .bp-post-type-pill' => 'color: {{VALUE}};',
                ],
                'condition' => [
                    'use_custom_labels!' => 'yes',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'pill_typography',
                'selector' => '{{WRAPPER}} .bp-post-type-pill',
            ]
        );

        $this->add_responsive_control(
            'pill_padding',
            [
                'label' => __('Padding', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'default' => [
                    'top' => '4',
                    'right' => '12',
                    'bottom' => '4',
                    'left' => '12',
                    'unit' => 'px',
                    'isLinked' => false,
                ],
                'selectors' => [
                    '{{WRAPPER}} .bp-post-type-pill' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'pill_border_radius',
            [
                'label' => __('Border Radius', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%'],
                'default' => [
                    'top' => '20',
                    'right' => '20',
                    'bottom' => '20',
                    'left' => '20',
                    'unit' => 'px',
                    'isLinked' => true,
                ],
                'selectors' => [
                    '{{WRAPPER}} .bp-post-type-pill' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'pill_box_shadow',
                'selector' => '{{WRAPPER}} .bp-post-type-pill',
            ]
        );

        $this->add_responsive_control(
            'pill_alignment',
            [
                'label' => __('Alignment', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::CHOOSE,
                'options' => [
                    'left' => [
                        'title' => __('Left', 'starter-dashboard'),
                        'icon' => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => __('Center', 'starter-dashboard'),
                        'icon' => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => __('Right', 'starter-dashboard'),
                        'icon' => 'eicon-text-align-right',
                    ],
                ],
                'default' => 'left',
                'selectors' => [
                    '{{WRAPPER}} .bp-post-type-pill-wrapper' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $post_id = get_the_ID();
        if (!$post_id) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<div class="bp-post-type-pill-wrapper"><span class="bp-post-type-pill" style="background-color: #0073aa; color: #fff;">Article</span></div>';
            }
            return;
        }

        $post_type = get_post_type($post_id);
        $post_type_obj = get_post_type_object($post_type);

        if (!$post_type_obj) {
            return;
        }

        // Determine label and colors
        $label = $post_type_obj->labels->singular_name;
        $bg_color = '';
        $text_color = '';

        if ($settings['use_custom_labels'] === 'yes' && !empty($settings['post_type_labels'])) {
            foreach ($settings['post_type_labels'] as $item) {
                if ($item['post_type'] === $post_type) {
                    $label = !empty($item['custom_label']) ? $item['custom_label'] : $label;
                    $bg_color = !empty($item['pill_color']) ? $item['pill_color'] : '';
                    $text_color = !empty($item['text_color']) ? $item['text_color'] : '';
                    break;
                }
            }

            // Use fallback if post type not found in list
            if (!empty($settings['fallback_label'])) {
                $found = false;
                foreach ($settings['post_type_labels'] as $item) {
                    if ($item['post_type'] === $post_type) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $label = $settings['fallback_label'];
                }
            }
        }

        // Build inline styles
        $inline_style = '';
        if ($bg_color) {
            $inline_style .= 'background-color: ' . esc_attr($bg_color) . ';';
        }
        if ($text_color) {
            $inline_style .= 'color: ' . esc_attr($text_color) . ';';
        }

        $style_attr = $inline_style ? ' style="' . $inline_style . '"' : '';

        printf(
            '<div class="bp-post-type-pill-wrapper"><span class="bp-post-type-pill" data-post-type="%s"%s>%s</span></div>',
            esc_attr($post_type),
            $style_attr,
            esc_html($label)
        );
    }

    protected function content_template() {
        ?>
        <#
        var label = 'Article';
        var bgColor = '#0073aa';
        var textColor = '#ffffff';
        #>
        <div class="bp-post-type-pill-wrapper">
            <span class="bp-post-type-pill" style="background-color: {{ bgColor }}; color: {{ textColor }}; display: inline-block;">
                {{ label }}
            </span>
        </div>
        <?php
    }
}
