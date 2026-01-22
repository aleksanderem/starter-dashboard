<?php
/**
 * Elementor Phone International Field Type
 */

defined('ABSPATH') || exit;

class Starter_Phone_Intl_Field extends \ElementorPro\Modules\Forms\Fields\Field_Base {

    /**
     * Get field type
     */
    public function get_type() {
        return 'phone_intl';
    }

    /**
     * Get field name
     */
    public function get_name() {
        return __('Phone (International)', 'starter-dashboard');
    }

    /**
     * Update form widget controls
     */
    public function update_controls($widget) {
        $elementor = \ElementorPro\Plugin::elementor();

        $control_data = $elementor->controls_manager->get_control_from_stack(
            $widget->get_unique_name(),
            'form_fields'
        );

        if (is_wp_error($control_data)) {
            return;
        }

        // Add phone-specific controls
        $field_controls = [
            'phone_initial_country' => [
                'name' => 'phone_initial_country',
                'label' => __('Initial Country', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => $this->get_country_options(),
                'default' => 'auto',
                'condition' => [
                    'field_type' => $this->get_type(),
                ],
                'tab' => 'content',
                'inner_tab' => 'form_fields_content_tab',
                'tabs_wrapper' => 'form_fields_tabs',
            ],
            'phone_preferred_countries' => [
                'name' => 'phone_preferred_countries',
                'label' => __('Preferred Countries', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'us,gb,ca,au,de,fr,pl',
                'placeholder' => 'us,gb,ca,au',
                'description' => __('Comma-separated country codes (shown first in list)', 'starter-dashboard'),
                'condition' => [
                    'field_type' => $this->get_type(),
                ],
                'tab' => 'content',
                'inner_tab' => 'form_fields_content_tab',
                'tabs_wrapper' => 'form_fields_tabs',
            ],
            'phone_only_countries' => [
                'name' => 'phone_only_countries',
                'label' => __('Only Countries', 'starter-dashboard'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => '',
                'placeholder' => 'us,ca,gb',
                'description' => __('Leave empty for all countries, or specify allowed countries', 'starter-dashboard'),
                'condition' => [
                    'field_type' => $this->get_type(),
                ],
                'tab' => 'content',
                'inner_tab' => 'form_fields_content_tab',
                'tabs_wrapper' => 'form_fields_tabs',
            ],
        ];

        $control_data['fields'] = $this->inject_field_controls($control_data['fields'], $field_controls);

        $widget->update_control('form_fields', $control_data);
    }

    /**
     * Get country options for select
     */
    private function get_country_options() {
        return [
            'auto' => __('Auto-detect', 'starter-dashboard'),
            'us' => '🇺🇸 United States',
            'gb' => '🇬🇧 United Kingdom',
            'ca' => '🇨🇦 Canada',
            'au' => '🇦🇺 Australia',
            'de' => '🇩🇪 Germany',
            'fr' => '🇫🇷 France',
            'pl' => '🇵🇱 Poland',
            'es' => '🇪🇸 Spain',
            'it' => '🇮🇹 Italy',
            'nl' => '🇳🇱 Netherlands',
            'be' => '🇧🇪 Belgium',
            'at' => '🇦🇹 Austria',
            'ch' => '🇨🇭 Switzerland',
            'ie' => '🇮🇪 Ireland',
            'nz' => '🇳🇿 New Zealand',
            'se' => '🇸🇪 Sweden',
            'no' => '🇳🇴 Norway',
            'dk' => '🇩🇰 Denmark',
            'fi' => '🇫🇮 Finland',
            'pt' => '🇵🇹 Portugal',
            'cz' => '🇨🇿 Czech Republic',
            'sk' => '🇸🇰 Slovakia',
            'hu' => '🇭🇺 Hungary',
            'ro' => '🇷🇴 Romania',
            'bg' => '🇧🇬 Bulgaria',
            'hr' => '🇭🇷 Croatia',
            'si' => '🇸🇮 Slovenia',
            'ua' => '🇺🇦 Ukraine',
            'ru' => '🇷🇺 Russia',
            'br' => '🇧🇷 Brazil',
            'mx' => '🇲🇽 Mexico',
            'ar' => '🇦🇷 Argentina',
            'cl' => '🇨🇱 Chile',
            'co' => '🇨🇴 Colombia',
            'jp' => '🇯🇵 Japan',
            'cn' => '🇨🇳 China',
            'kr' => '🇰🇷 South Korea',
            'in' => '🇮🇳 India',
            'sg' => '🇸🇬 Singapore',
            'hk' => '🇭🇰 Hong Kong',
            'tw' => '🇹🇼 Taiwan',
            'th' => '🇹🇭 Thailand',
            'my' => '🇲🇾 Malaysia',
            'ph' => '🇵🇭 Philippines',
            'id' => '🇮🇩 Indonesia',
            'vn' => '🇻🇳 Vietnam',
            'ae' => '🇦🇪 UAE',
            'sa' => '🇸🇦 Saudi Arabia',
            'il' => '🇮🇱 Israel',
            'tr' => '🇹🇷 Turkey',
            'za' => '🇿🇦 South Africa',
            'eg' => '🇪🇬 Egypt',
            'ng' => '🇳🇬 Nigeria',
            'ke' => '🇰🇪 Kenya',
        ];
    }

    /**
     * Render field output
     */
    public function render($item, $item_index, $form) {
        $form_id = $form->get_id();
        $field_id = $item['custom_id'] ?: 'field_' . $item_index;

        $form->add_render_attribute(
            'input' . $item_index,
            [
                'type' => 'tel',
                'name' => 'form_fields[' . $field_id . ']',
                'id' => 'form-field-' . $field_id,
                'class' => [
                    'elementor-field',
                    'elementor-field-textual',
                    'elementor-phone-intl-input',
                    'elementor-size-' . $item['input_size'],
                ],
                'data-initial-country' => !empty($item['phone_initial_country']) ? $item['phone_initial_country'] : 'auto',
                'data-preferred' => !empty($item['phone_preferred_countries']) ? $item['phone_preferred_countries'] : 'us,gb,ca,au,de,fr,pl',
            ]
        );

        if (!empty($item['phone_only_countries'])) {
            $form->add_render_attribute('input' . $item_index, 'data-only-countries', $item['phone_only_countries']);
        }

        if (!empty($item['placeholder'])) {
            $form->add_render_attribute('input' . $item_index, 'placeholder', $item['placeholder']);
        }

        if (!empty($item['required'])) {
            $form->add_render_attribute('input' . $item_index, 'required', 'required');
            $form->add_render_attribute('input' . $item_index, 'aria-required', 'true');
        }

        // Enqueue scripts
        wp_enqueue_style('intl-tel-input');
        wp_enqueue_script('intl-tel-input');

        echo '<input ' . $form->get_render_attribute_string('input' . $item_index) . '>';
        echo '<input type="hidden" class="phone-full-number" name="form_fields[' . esc_attr($field_id) . '_full]" value="">';
    }

    /**
     * Validation
     */
    public function validation($field, $record, $ajax_handler) {
        if (empty($field['value'])) {
            if (!empty($field['required'])) {
                $ajax_handler->add_error(
                    $field['id'],
                    __('Phone number is required', 'starter-dashboard')
                );
            }
            return;
        }

        // Basic validation - must have at least 7 digits
        $digits = preg_replace('/[^0-9]/', '', $field['value']);
        if (strlen($digits) < 7) {
            $ajax_handler->add_error(
                $field['id'],
                __('Please enter a valid phone number', 'starter-dashboard')
            );
        }
    }

    /**
     * Process field value before storing
     */
    public function process_value($value, $field) {
        // Clean up the number - keep only digits and +
        return preg_replace('/[^0-9+]/', '', $value);
    }
}
