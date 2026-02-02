<?php
/**
 * HubSpot Form Action for Elementor Pro
 */

use Elementor\Controls_Manager;
use Elementor\Repeater;
use ElementorPro\Modules\Forms\Classes\Action_Base;

if (!defined('ABSPATH')) {
    exit;
}

class EHFA_HubSpot_Action extends Action_Base {

    public function get_name() {
        return 'hubspot';
    }

    public function get_label() {
        return __('HubSpot', 'starter-dashboard');
    }

    public function register_settings_section($widget) {
        $widget->start_controls_section(
            'section_hubspot',
            [
                'label' => __('HubSpot', 'starter-dashboard'),
                'condition' => [
                    'submit_actions' => $this->get_name(),
                ],
            ]
        );

        // Check if configured
        $portal_id = get_option('starter_hubspot_portal_id', '');
        $access_token = get_option('starter_hubspot_access_token', '');

        if (empty($portal_id) || empty($access_token)) {
            $widget->add_control(
                'hubspot_not_configured',
                [
                    'type' => Controls_Manager::RAW_HTML,
                    'raw' => sprintf(
                        '<div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 10px;">%s <a href="%s" target="_blank">%s</a></div>',
                        __('HubSpot is not configured.', 'starter-dashboard'),
                        admin_url('admin.php?page=starter-dashboard'),
                        __('Configure in Hub Dashboard → HubSpot Forms addon', 'starter-dashboard')
                    ),
                ]
            );
        }

        // Form selector
        $widget->add_control(
            'hubspot_form_id',
            [
                'label' => __('HubSpot Form', 'starter-dashboard'),
                'type' => Controls_Manager::SELECT2,
                'options' => $this->get_forms_options(),
                'default' => '',
                'label_block' => true,
                'description' => __('Select the HubSpot form to submit to', 'starter-dashboard'),
            ]
        );

        // Field mapping container (populated by JS)
        $widget->add_control(
            'hubspot_field_mapping_container',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => '<div id="ehfa-field-mapping-container"></div>',
                'separator' => 'before',
            ]
        );

        // Hidden field to store JSON mapping
        $widget->add_control(
            'hubspot_field_mapping_json',
            [
                'type' => Controls_Manager::HIDDEN,
                'default' => '[]',
            ]
        );

        // Legacy repeater for backward compatibility
        $repeater = new Repeater();

        $repeater->add_control(
            'local_id',
            [
                'label' => __('Elementor Field ID', 'starter-dashboard'),
                'type' => Controls_Manager::TEXT,
                'placeholder' => 'email',
                'label_block' => true,
            ]
        );

        $repeater->add_control(
            'remote_id',
            [
                'label' => __('HubSpot Field', 'starter-dashboard'),
                'type' => Controls_Manager::SELECT,
                'options' => $this->get_common_hubspot_fields(),
                'label_block' => true,
            ]
        );

        $widget->add_control(
            'hubspot_fields_map',
            [
                'label' => __('Legacy Field Mapping (use mapping above instead)', 'starter-dashboard'),
                'type' => Controls_Manager::REPEATER,
                'fields' => $repeater->get_controls(),
                'default' => [],
                'title_field' => '{{{ local_id }}} → {{{ remote_id }}}',
                'prevent_empty' => false,
            ]
        );

        // Send page context
        $widget->add_control(
            'hubspot_send_context',
            [
                'label' => __('Send Page Context', 'starter-dashboard'),
                'type' => Controls_Manager::SWITCHER,
                'default' => 'yes',
                'separator' => 'before',
                'description' => __('Include page URL and title in submission', 'starter-dashboard'),
            ]
        );

        $widget->end_controls_section();
    }

    /**
     * Get forms options for select
     */
    private function get_forms_options() {
        $options = ['' => __('-- Select Form --', 'starter-dashboard')];

        $forms = get_transient('starter_hubspot_forms_list');

        if ($forms === false) {
            if (class_exists('Starter_Addon_HubSpot_Forms')) {
                $instance = Starter_Addon_HubSpot_Forms::instance();
                $forms = $instance->get_hubspot_forms();

                if (!is_wp_error($forms)) {
                    set_transient('starter_hubspot_forms_list', $forms, 5 * MINUTE_IN_SECONDS);
                } else {
                    $forms = [];
                }
            } else {
                $forms = [];
            }
        }

        foreach ($forms as $form) {
            $options[$form['id']] = $form['name'];
        }

        return $options;
    }

    /**
     * Get common HubSpot fields for legacy repeater
     */
    private function get_common_hubspot_fields() {
        return [
            '' => __('-- Select Field --', 'starter-dashboard'),
            'email' => 'Email',
            'firstname' => 'First Name',
            'lastname' => 'Last Name',
            'phone' => 'Phone',
            'mobilephone' => 'Mobile Phone',
            'company' => 'Company',
            'jobtitle' => 'Job Title',
            'website' => 'Website',
            'address' => 'Street Address',
            'city' => 'City',
            'state' => 'State/Region',
            'zip' => 'Postal Code',
            'country' => 'Country',
            'message' => 'Message',
        ];
    }

    public function on_export($element) {
        unset(
            $element['settings']['hubspot_form_id'],
            $element['settings']['hubspot_fields_map'],
            $element['settings']['hubspot_field_mapping_json']
        );
        return $element;
    }

    public function run($record, $ajax_handler) {
        $settings = $record->get('form_settings');

        if (empty($settings['hubspot_form_id'])) {
            $ajax_handler->add_admin_error_message(__('HubSpot form not selected', 'starter-dashboard'));
            return;
        }

        // Get submitted fields
        $raw_fields = $record->get('fields');
        $submitted_data = [];

        foreach ($raw_fields as $id => $field) {
            $submitted_data[$id] = $field['value'];
        }

        // Build HubSpot fields array from mappings
        $hubspot_fields = [];

        // First check JSON mapping (new format)
        if (!empty($settings['hubspot_field_mapping_json'])) {
            $json_mappings = json_decode($settings['hubspot_field_mapping_json'], true);
            if (is_array($json_mappings)) {
                foreach ($json_mappings as $mapping) {
                    $elementor_id = trim($mapping['elementor'] ?? '');
                    $hubspot_id = trim($mapping['hubspot'] ?? '');

                    if (!empty($elementor_id) && !empty($hubspot_id) && isset($submitted_data[$elementor_id])) {
                        $hubspot_fields[$hubspot_id] = $submitted_data[$elementor_id];
                    }
                }
            }
        }

        // Then check legacy repeater mapping
        if (!empty($settings['hubspot_fields_map'])) {
            foreach ($settings['hubspot_fields_map'] as $mapping) {
                $local_id = trim($mapping['local_id'] ?? '');
                $remote_id = trim($mapping['remote_id'] ?? '');

                if (!empty($local_id) && !empty($remote_id) && isset($submitted_data[$local_id])) {
                    // Don't override if already set from JSON mapping
                    if (!isset($hubspot_fields[$remote_id])) {
                        $hubspot_fields[$remote_id] = $submitted_data[$local_id];
                    }
                }
            }
        }

        // If no fields mapped, try to auto-map by field ID
        if (empty($hubspot_fields)) {
            foreach ($submitted_data as $id => $value) {
                $hubspot_fields[$id] = $value;
            }
        }

        // Prepare context - always collect for logging
        $page_url = $record->get('meta')['page_url']['value'] ?? '';
        $page_title = $record->get('meta')['page_title']['value'] ?? '';
        $client_ip = $this->get_client_ip();

        // Only send context to HubSpot if setting is enabled
        $context = [
            'page_url' => $page_url,
            'page_title' => $page_title,
        ];

        if (!empty($settings['hubspot_send_context']) && $settings['hubspot_send_context'] === 'yes') {
            $context['ip'] = $client_ip;
            $context['send_to_hubspot'] = true;
        } else {
            $context['send_to_hubspot'] = false;
        }

        // Submit to HubSpot
        $result = Starter_Addon_HubSpot_Forms::submit_to_hubspot(
            $settings['hubspot_form_id'],
            $hubspot_fields,
            $context
        );

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $debug_mode = get_option('starter_hubspot_debug_mode', 'no') === 'yes';

            // Build detailed error message
            $detailed_error = sprintf(__('HubSpot Error: %s', 'starter-dashboard'), $error_message);

            // Add field information in debug mode
            if ($debug_mode) {
                $detailed_error .= "\n\n" . __('Submitted fields:', 'starter-dashboard');
                foreach ($hubspot_fields as $field_name => $field_value) {
                    $detailed_error .= sprintf("\n- %s: %s", $field_name, $field_value);
                }
                $detailed_error .= "\n\n" . sprintf(
                    __('Check debug log at: %s', 'starter-dashboard'),
                    dirname(__FILE__) . '/hubspot-debug.log'
                );
            }

            $ajax_handler->add_admin_error_message($detailed_error);

            // Log to error_log if debug mode or WP_DEBUG
            if ($debug_mode || (defined('WP_DEBUG') && WP_DEBUG)) {
                error_log('[Starter HubSpot] Submission failed: ' . $error_message);
                error_log('[Starter HubSpot] Form ID: ' . $settings['hubspot_form_id']);
                error_log('[Starter HubSpot] Fields: ' . json_encode($hubspot_fields));
            }
        }
    }

    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }
}
