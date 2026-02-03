<?php
/**
 * Starter Dashboard Addon: Elementor Styled Checkboxes
 *
 * Adds styling options for checkboxes/radio buttons in Elementor Pro Forms
 */

defined('ABSPATH') || exit;

class Starter_Addon_Elementor_Styled_Checkboxes {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Add controls to Form widget
        add_action('elementor/element/form/section_form_style/after_section_end', [$this, 'add_checkbox_style_section'], 10, 2);

        // Add required field control to checkbox fields
        add_action('elementor/element/form/section_form_fields/before_section_end', [$this, 'add_required_field_control'], 10, 2);

        // Add validation hooks
        add_action('elementor_pro/forms/validation', [$this, 'validate_required_checkboxes'], 10, 2);

        // Frontend styles
        add_action('wp_head', [$this, 'print_base_styles']);
        add_action('elementor/frontend/after_enqueue_styles', [$this, 'print_base_styles']);

        // Enqueue validation script
        add_action('elementor/frontend/after_enqueue_scripts', [$this, 'enqueue_validation_script']);
    }

    /**
     * Add required field control to checkbox fields
     */
    public function add_required_field_control($element, $args) {
        $elementor = \Elementor\Plugin::$instance;

        $control_data = $element->get_controls('form_fields');

        if (!isset($control_data['fields'])) {
            return;
        }

        // Add "Required" control for checkbox fields
        $control_data['fields']['field_required_checkbox'] = [
            'name' => 'field_required_checkbox',
            'label' => __('Required', 'starter-dashboard'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'condition' => [
                'field_type' => 'checkbox',
            ],
            'tab' => 'content',
            'inner_tab' => 'form_fields_content_tab',
            'tabs_wrapper' => 'form_fields_tabs',
        ];

        $element->update_control('form_fields', $control_data);
    }

    /**
     * Add checkbox styling section to Form widget
     */
    public function add_checkbox_style_section($element, $args) {
        $element->start_controls_section(
            'section_checkbox_style',
            [
                'label' => __('Checkbox & Radio Style', 'starter-dashboard'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        // Checkbox Size
        $element->add_responsive_control(
            'checkbox_size',
            [
                'label' => __('Checkbox Size', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 12,
                        'max' => 40,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 22,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"],
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"],
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; min-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        // Icon Size
        $element->add_responsive_control(
            'checkbox_icon_size',
            [
                'label' => __('Checkmark Size', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 4,
                        'max' => 16,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 6,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:checked::after,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:checked::after' => 'width: {{SIZE}}{{UNIT}}; height: calc({{SIZE}}{{UNIT}} * 2);',
                ],
            ]
        );

        // Checkmark Thickness
        $element->add_responsive_control(
            'checkbox_icon_thickness',
            [
                'label' => __('Checkmark Thickness', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 1,
                        'max' => 5,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 2,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]::after,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]::after' => 'border-width: 0 {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} 0;',
                ],
            ]
        );

        // Border Radius
        $element->add_responsive_control(
            'checkbox_border_radius',
            [
                'label' => __('Border Radius', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%'],
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 20,
                        'step' => 1,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 50,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 6,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"],
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]' => 'border-radius: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        // Border Width
        $element->add_responsive_control(
            'checkbox_border_width',
            [
                'label' => __('Border Width', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 1,
                        'max' => 5,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 2,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"],
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"],
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]' => 'border-width: {{SIZE}}{{UNIT}}; border-style: solid;',
                ],
            ]
        );

        // Spacing between options
        $element->add_responsive_control(
            'checkbox_spacing',
            [
                'label' => __('Options Spacing', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 30,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 10,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox .elementor-field-subgroup,
                     {{WRAPPER}} .elementor-field-type-radio .elementor-field-subgroup' => 'gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        // Gap between checkbox and label
        $element->add_responsive_control(
            'checkbox_label_gap',
            [
                'label' => __('Label Gap', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 30,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 12,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox .elementor-field-option label,
                     {{WRAPPER}} .elementor-field-type-radio .elementor-field-option label' => 'gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        // Margin
        $element->add_responsive_control(
            'checkbox_margin',
            [
                'label' => __('Margin', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox,
                     {{WRAPPER}} .elementor-field-type-radio,
                     {{WRAPPER}} .elementor-field-type-acceptance' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Padding
        $element->add_responsive_control(
            'checkbox_padding',
            [
                'label' => __('Padding', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox,
                     {{WRAPPER}} .elementor-field-type-radio,
                     {{WRAPPER}} .elementor-field-type-acceptance' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Option Item Margin
        $element->add_responsive_control(
            'checkbox_option_margin',
            [
                'label' => __('Option Item Margin', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em'],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox .elementor-field-option,
                     {{WRAPPER}} .elementor-field-type-radio .elementor-field-option' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Option Item Padding
        $element->add_responsive_control(
            'checkbox_option_padding',
            [
                'label' => __('Option Item Padding', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em'],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox .elementor-field-option label,
                     {{WRAPPER}} .elementor-field-type-radio .elementor-field-option label' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Checkbox Input Margin
        $element->add_responsive_control(
            'checkbox_input_margin',
            [
                'label' => __('Input Margin', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em'],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"],
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"],
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Typography heading
        $element->add_control(
            'checkbox_typography_heading',
            [
                'label' => __('Typography', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        // Label Typography
        $element->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'checkbox_label_typography',
                'label' => __('Label Typography', 'starter-dashboard'),
                'selector' => '{{WRAPPER}} .elementor-field-type-checkbox .elementor-field-option label,
                               {{WRAPPER}} .elementor-field-type-radio .elementor-field-option label,
                               {{WRAPPER}} .elementor-field-type-acceptance label',
            ]
        );

        // Label Color
        $element->add_control(
            'checkbox_label_color',
            [
                'label' => __('Label Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox .elementor-field-option label,
                     {{WRAPPER}} .elementor-field-type-radio .elementor-field-option label,
                     {{WRAPPER}} .elementor-field-type-acceptance label' => 'color: {{VALUE}};',
                ],
            ]
        );

        // Colors heading
        $element->add_control(
            'checkbox_colors_heading',
            [
                'label' => __('Colors', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        // Start tabs
        $element->start_controls_tabs('checkbox_color_tabs');

        // Normal tab
        $element->start_controls_tab(
            'checkbox_tab_normal',
            [
                'label' => __('Normal', 'starter-dashboard'),
            ]
        );

        $element->add_control(
            'checkbox_bg_color',
            [
                'label' => __('Background Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:not(:checked),
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"]:not(:checked),
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:not(:checked)' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $element->add_control(
            'checkbox_border_color',
            [
                'label' => __('Border Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#d1d5db',
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:not(:checked),
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"]:not(:checked),
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:not(:checked)' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $element->end_controls_tab();

        // Hover tab
        $element->start_controls_tab(
            'checkbox_tab_hover',
            [
                'label' => __('Hover', 'starter-dashboard'),
            ]
        );

        $element->add_control(
            'checkbox_bg_color_hover',
            [
                'label' => __('Background Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#f9fafb',
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:not(:checked):hover,
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"]:not(:checked):hover,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:not(:checked):hover' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $element->add_control(
            'checkbox_border_color_hover',
            [
                'label' => __('Border Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#9ca3af',
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:not(:checked):hover,
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"]:not(:checked):hover,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:not(:checked):hover' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $element->add_control(
            'checkbox_checkmark_color_hover',
            [
                'label' => __('Checkmark Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:checked:hover::after,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:checked:hover::after' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $element->end_controls_tab();

        // Active/Checked tab
        $element->start_controls_tab(
            'checkbox_tab_active',
            [
                'label' => __('Active', 'starter-dashboard'),
            ]
        );

        $element->add_control(
            'checkbox_bg_color_checked',
            [
                'label' => __('Background Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'global' => [
                    'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:checked,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:checked' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $element->add_control(
            'checkbox_border_color_checked',
            [
                'label' => __('Border Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'global' => [
                    'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
                ],
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:checked,
                     {{WRAPPER}} .elementor-field-type-radio input[type="radio"]:checked,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:checked' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $element->add_control(
            'checkbox_checkmark_color',
            [
                'label' => __('Checkmark Color', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .elementor-field-type-checkbox input[type="checkbox"]:checked::after,
                     {{WRAPPER}} .elementor-field-type-acceptance input[type="checkbox"]:checked::after' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();

        $element->end_controls_section();
    }

    /**
     * Validate required checkbox fields
     */
    public function validate_required_checkboxes($record, $ajax_handler) {
        $fields = $record->get('fields');
        $form_settings = $record->get('form_settings');

        // Get all form fields from settings
        if (!isset($form_settings['form_fields']) || !is_array($form_settings['form_fields'])) {
            return;
        }

        foreach ($form_settings['form_fields'] as $index => $field) {
            // Check if this is a checkbox field with required enabled
            if (isset($field['field_type']) && $field['field_type'] === 'checkbox'
                && isset($field['field_required_checkbox']) && $field['field_required_checkbox'] === 'yes') {

                $field_id = isset($field['custom_id']) && !empty($field['custom_id'])
                    ? $field['custom_id']
                    : $field['_id'];

                // Check if the field was submitted and has at least one value
                $has_value = false;

                if (isset($fields[$field_id])) {
                    $field_value = $fields[$field_id]['value'];

                    if (is_array($field_value)) {
                        $values = array_filter($field_value, function($v) {
                            return !empty($v) && $v !== '';
                        });
                        $has_value = !empty($values);
                    } else {
                        $has_value = !empty($field_value) && $field_value !== '';
                    }
                }

                if (!$has_value) {
                    // Get field label for more specific error message
                    $field_label = isset($field['field_label']) && !empty($field['field_label'])
                        ? $field['field_label']
                        : __('This field', 'starter-dashboard');

                    $error_message = sprintf(
                        __('%s: Please select at least one option', 'starter-dashboard'),
                        $field_label
                    );

                    $error_message = apply_filters(
                        'starter_checkbox_required_error_message',
                        $error_message,
                        $field_id,
                        $field
                    );

                    // Add error to the specific field
                    $ajax_handler->add_error($field_id, $error_message);

                    // Also add a form-level error to prevent the generic error message
                    $ajax_handler->add_error_message($error_message);
                }
            }
        }
    }

    /**
     * Enqueue validation script
     */
    public function enqueue_validation_script() {
        wp_enqueue_script(
            'starter-checkbox-validation',
            plugin_dir_url(__FILE__) . 'validation.js',
            ['jquery', 'elementor-frontend'],
            '1.0.1', // Incremented version to force cache refresh
            true
        );

        wp_localize_script('starter-checkbox-validation', 'starterCheckboxValidation', [
            'errorMessage' => __('Please select at least one option', 'starter-dashboard'),
        ]);
    }

    /**
     * Print base styles
     */
    public function print_base_styles() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <style id="styled-checkboxes-base-css">
        /* Base checkbox/radio styles */
        .elementor-field-type-checkbox .elementor-field-subgroup,
        .elementor-field-type-radio .elementor-field-subgroup {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .elementor-field-type-checkbox .elementor-field-option,
        .elementor-field-type-radio .elementor-field-option {
            display: flex !important;
            align-items: center;
            margin: 0 !important;
        }

        .elementor-field-type-checkbox .elementor-field-option label,
        .elementor-field-type-radio .elementor-field-option label {
            display: inline-flex !important;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            margin: 0;
            flex: 1;
        }

        .elementor-field-type-checkbox input[type="checkbox"],
        .elementor-field-type-radio input[type="radio"],
        .elementor-field-type-acceptance input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            min-width: 22px;
            border: 2px solid #d1d5db;
            background: #fff;
            cursor: pointer;
            transition: all 0.2s ease;
            margin: 0;
            flex-shrink: 0;
        }

        .elementor-field-type-checkbox input[type="checkbox"],
        .elementor-field-type-acceptance input[type="checkbox"] {
            border-radius: 6px;
        }

        .elementor-field-type-radio input[type="radio"] {
            border-radius: 50%;
        }

        .elementor-field-type-checkbox input[type="checkbox"]:checked,
        .elementor-field-type-acceptance input[type="checkbox"]:checked {
            background-color: var(--e-global-color-primary, #22c55e);
            border-color: var(--e-global-color-primary, #22c55e);
            position: relative;
        }

        .elementor-field-type-checkbox input[type="checkbox"]::after,
        .elementor-field-type-acceptance input[type="checkbox"]::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 6px;
            height: 12px;
            border: solid #ffffff;
            border-width: 0 2px 2px 0;
            transform: translate(-50%, -60%) rotate(45deg);
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        .elementor-field-type-checkbox input[type="checkbox"]:checked::after,
        .elementor-field-type-acceptance input[type="checkbox"]:checked::after {
            opacity: 1;
        }

        .elementor-field-type-radio input[type="radio"]:checked {
            background: #fff;
            border-color: var(--e-global-color-primary, #22c55e);
            border-width: 6px;
        }

        .elementor-field-type-acceptance .elementor-field-subgroup {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        /* Error state styles for required checkbox validation */
        .elementor-field-type-checkbox.elementor-error .elementor-field-subgroup,
        .elementor-field-type-acceptance.elementor-error .elementor-field-subgroup {
            border: 1px solid #d32f2f;
            border-radius: 4px;
            padding: 12px;
            background-color: #ffebee;
        }

        /* Make checkboxes red when there's an error - all states */
        .elementor-field-type-checkbox.elementor-error input[type="checkbox"],
        .elementor-field-type-checkbox.elementor-error input[type="checkbox"]:not(:checked),
        .elementor-field-type-acceptance.elementor-error input[type="checkbox"],
        .elementor-field-type-acceptance.elementor-error input[type="checkbox"]:not(:checked) {
            border-color: #d32f2f !important;
            background-color: #ffebee !important;
        }

        /* Error state for checked checkboxes */
        .elementor-field-type-checkbox.elementor-error input[type="checkbox"]:checked,
        .elementor-field-type-acceptance.elementor-error input[type="checkbox"]:checked {
            border-color: #d32f2f !important;
            background-color: #d32f2f !important;
        }

        /* Error state for hover - override hover styles */
        .elementor-field-type-checkbox.elementor-error input[type="checkbox"]:hover,
        .elementor-field-type-checkbox.elementor-error input[type="checkbox"]:not(:checked):hover,
        .elementor-field-type-acceptance.elementor-error input[type="checkbox"]:hover,
        .elementor-field-type-acceptance.elementor-error input[type="checkbox"]:not(:checked):hover {
            border-color: #b71c1c !important;
            background-color: #ffcdd2 !important;
        }

        /* Error state for checked hover */
        .elementor-field-type-checkbox.elementor-error input[type="checkbox"]:checked:hover,
        .elementor-field-type-acceptance.elementor-error input[type="checkbox"]:checked:hover {
            border-color: #b71c1c !important;
            background-color: #b71c1c !important;
        }

        /* Error message styling */
        .elementor-field-type-checkbox .elementor-message-danger,
        .elementor-field-type-acceptance .elementor-message-danger {
            color: #d32f2f;
            font-size: 0.875em;
            margin-top: 8px;
            display: block;
        }

        .elementor-field-type-checkbox.elementor-error,
        .elementor-field-type-acceptance.elementor-error {
            margin-bottom: 16px;
        }
        </style>
        <?php
    }

}

Starter_Addon_Elementor_Styled_Checkboxes::instance();
