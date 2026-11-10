<?php
/**
 * HubSpot Forms: form schema logic.
 *
 * Pure functions (no WordPress calls) for comparing HubSpot form definitions,
 * working out which required HubSpot fields an Elementor form does not cover,
 * reading HubSpot submission errors and planning a one-time repair retry.
 * WordPress glue: class-schema-sync.php. Tests: tests/test-schema.php.
 */

defined('ABSPATH') || exit;

class Starter_HubSpot_Schema {

    /** HubSpot field types that may receive a placeholder on retry (never email, choices, consent). */
    const PLACEHOLDER_TYPES = ['single_line_text', 'multi_line_text', 'phone', 'mobile_phone'];

    /** HubSpot fieldType => how the missing field is rendered on the Elementor form. */
    const RENDER_TYPES = [
        'single_line_text' => 'text',
        'multi_line_text' => 'textarea',
        'email' => 'email',
        'phone' => 'tel',
        'mobile_phone' => 'tel',
        'number' => 'number',
        'dropdown' => 'select',
        'radio' => 'select',
        'single_checkbox' => 'checkbox',
        'multiple_checkboxes' => 'checkboxes',
        'datepicker' => 'date',
    ];

    /** Same aliases submit_to_hubspot() applies to Elementor field ids. */
    const FIELD_ALIASES = [
        'first_name' => 'firstname',
        'last_name' => 'lastname',
        'phone_number' => 'phone',
        'mobile_phone' => 'mobilephone',
        'mobile' => 'mobilephone',
        'job_title' => 'jobtitle',
        'street_address' => 'address',
        'postal_code' => 'zip',
        'zip_code' => 'zip',
    ];

    const CONSENT_NAME_PATTERN = '/(consent|gdpr|rodo|legal|privacy|terms|agree|zgod|subscription)/i';

    /**
     * Canonical schema from get_form_fields() output ({fields, consent}).
     */
    public static function normalize($form) {
        $fields = [];
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }
            $options = [];
            foreach ((array) ($field['options'] ?? []) as $option) {
                $options[] = [
                    'label' => (string) ($option['label'] ?? ($option['value'] ?? '')),
                    'value' => (string) ($option['value'] ?? ''),
                ];
            }
            $name = (string) $field['name'];
            $fields[$name] = [
                'name' => $name,
                'label' => (string) ($field['label'] ?? $name),
                'type' => (string) ($field['type'] ?? 'single_line_text'),
                'required' => !empty($field['required']),
                'hidden' => !empty($field['hidden']),
                'options' => $options,
            ];
        }
        ksort($fields);

        $consent = (array) ($form['consent'] ?? []);
        $communications = [];
        foreach ((array) ($consent['communications'] ?? []) as $item) {
            $communications[] = [
                'id' => (int) ($item['subscriptionTypeId'] ?? 0),
                'label' => (string) ($item['label'] ?? ''),
                'required' => !empty($item['required']),
            ];
        }

        return [
            'fields' => $fields,
            'consent' => [
                'type' => (string) ($consent['type'] ?? 'none'),
                'process_label' => (string) ($consent['consentToProcess']['label'] ?? ''),
                'process_required' => !empty($consent['consentToProcess']['required']),
                'communications' => $communications,
            ],
        ];
    }

    /**
     * Version hash over what matters for submissions (labels excluded, so a
     * translated label does not count as a schema change).
     */
    public static function hash(array $schema) {
        $signature = [];
        foreach ($schema['fields'] ?? [] as $name => $field) {
            $signature[$name] = [
                $field['type'],
                $field['required'],
                $field['hidden'],
                array_column($field['options'], 'value'),
            ];
        }
        $consent = $schema['consent'] ?? [];
        $signature['__consent'] = [
            $consent['type'] ?? 'none',
            $consent['process_required'] ?? false,
            array_map(function ($c) { return [$c['id'], $c['required']]; }, $consent['communications'] ?? []),
        ];
        return hash('sha256', json_encode($signature));
    }

    /**
     * What changed between two normalized schemas.
     */
    public static function diff(array $old, array $new) {
        $old_fields = $old['fields'] ?? [];
        $new_fields = $new['fields'] ?? [];
        $diff = [
            'required_added' => [],
            'required_removed' => [],
            'fields_added' => array_values(array_diff(array_keys($new_fields), array_keys($old_fields))),
            'fields_removed' => array_values(array_diff(array_keys($old_fields), array_keys($new_fields))),
            'type_changed' => [],
            'options_changed' => [],
            'consent_changed' => false,
        ];

        foreach ($new_fields as $name => $field) {
            $before = $old_fields[$name] ?? null;
            if ($field['required'] && (!$before || !$before['required'])) {
                $diff['required_added'][] = $name;
            }
            if (!$before) {
                continue;
            }
            if (!$field['required'] && $before['required']) {
                $diff['required_removed'][] = $name;
            }
            if ($field['type'] !== $before['type']) {
                $diff['type_changed'][$name] = $before['type'] . ' -> ' . $field['type'];
            }
            if (array_column($field['options'], 'value') !== array_column($before['options'], 'value')) {
                $diff['options_changed'][] = $name;
            }
        }
        foreach ($old_fields as $name => $field) {
            if ($field['required'] && !isset($new_fields[$name])) {
                $diff['required_removed'][] = $name;
            }
        }

        $old_consent = $old;
        $new_consent = $new;
        $old_consent['fields'] = $new_consent['fields'] = [];
        $diff['consent_changed'] = self::hash($old_consent) !== self::hash($new_consent);

        return $diff;
    }

    public static function has_changes(array $diff) {
        foreach ($diff as $value) {
            if (!empty($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * HubSpot field names an Elementor form sends, mirroring
     * EHFA_HubSpot_Action::run() + submit_to_hubspot() (JSON mapping, legacy
     * repeater, otherwise auto-map by field id; aliases applied).
     *
     * @param array $settings Elementor form widget settings.
     * @return string[]
     */
    public static function covered_fields(array $settings) {
        $element_ids = [];
        foreach ((array) ($settings['form_fields'] ?? []) as $field) {
            $id = (string) ($field['custom_id'] ?? ($field['_id'] ?? ''));
            if ($id !== '') {
                $element_ids[] = $id;
            }
        }

        $covered = [];
        $json = json_decode((string) ($settings['hubspot_field_mapping_json'] ?? ''), true);
        foreach (is_array($json) ? $json : [] as $mapping) {
            $elementor = trim((string) ($mapping['elementor'] ?? ''));
            $hubspot = trim((string) ($mapping['hubspot'] ?? ''));
            if ($elementor !== '' && $hubspot !== '' && in_array($elementor, $element_ids, true)) {
                $covered[] = $hubspot;
            }
        }
        foreach ((array) ($settings['hubspot_fields_map'] ?? []) as $mapping) {
            $local = trim((string) ($mapping['local_id'] ?? ''));
            $remote = trim((string) ($mapping['remote_id'] ?? ''));
            if ($local !== '' && $remote !== '' && in_array($local, $element_ids, true)) {
                $covered[] = $remote;
            }
        }
        if (empty($covered)) {
            $covered = $element_ids;
        }

        $aliased = array_map(function ($name) {
            return self::FIELD_ALIASES[$name] ?? $name;
        }, $covered);
        return array_values(array_unique($aliased));
    }

    /**
     * Required, visible fields of the target schema the form does not send.
     *
     * @return array[] field definitions
     */
    public static function missing_required(array $schema, array $covered) {
        $missing = [];
        foreach ($schema['fields'] ?? [] as $name => $field) {
            if ($field['required'] && !$field['hidden'] && !in_array($name, $covered, true)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Required consent checkboxes of the target form that the form's consent
     * mapping does not cover. Keys match hubspot_consent_mapping_json.
     *
     * @return array<string, string> consent key => label
     */
    public static function missing_consent(array $schema, array $consent_mapping) {
        $consent = $schema['consent'] ?? [];
        $missing = [];
        if (!empty($consent['process_required']) && empty($consent_mapping['_consentToProcess'])) {
            $missing['_consentToProcess'] = (string) $consent['process_label'];
        }
        foreach ($consent['communications'] ?? [] as $item) {
            $key = 'sub_' . (int) $item['id'];
            if (!empty($item['required']) && empty($consent_mapping[$key])) {
                $missing[$key] = (string) $item['label'];
            }
        }
        return $missing;
    }

    /**
     * How to render a missing field on the form, or null when it cannot be
     * rendered (file upload, payment, unknown type).
     */
    public static function render_spec(array $field) {
        $type = self::RENDER_TYPES[$field['type'] ?? ''] ?? null;
        if ($type === null) {
            return null;
        }
        if (in_array($type, ['select', 'checkboxes'], true) && empty($field['options'])) {
            return null;
        }
        return [
            'name' => $field['name'],
            'label' => $field['label'] !== '' ? $field['label'] : $field['name'],
            'type' => $type,
            'options' => $field['options'],
            'required' => true,
        ];
    }

    /**
     * Readable errors from a HubSpot submission response. HubSpot puts the
     * error kind in errorType (e.g. REQUIRED_FIELD) and the field either in
     * `name` or only inside the message ("Required field 'company' is missing").
     *
     * @return array<int, array{type: string, field: string, message: string}>
     */
    public static function parse_submit_errors($body) {
        $errors = [];
        foreach ((array) (is_array($body) ? ($body['errors'] ?? []) : []) as $error) {
            if (is_string($error)) {
                $errors[] = ['type' => '', 'field' => '', 'message' => $error];
                continue;
            }
            if (!is_array($error)) {
                continue;
            }
            $message = (string) ($error['message'] ?? '');
            $field = is_string($error['name'] ?? null) ? trim($error['name']) : '';
            if ($field === '' && preg_match("/Required field '([^']+)'/", $message, $m)) {
                $field = $m[1];
            }
            if ($field === '' && preg_match('/fields\.([A-Za-z0-9_\-\/]+)/', $message, $m)) {
                $field = $m[1];
            }
            $errors[] = [
                'type' => strtoupper((string) ($error['errorType'] ?? '')),
                'field' => $field,
                'message' => $message,
            ];
        }
        return $errors;
    }

    /**
     * One-time repair plan for a rejected submission. Retries only when every
     * error is a missing required field that may safely get a placeholder:
     * plain text / phone fields, never e-mail, choices, checkboxes or consent.
     *
     * @param array    $errors     parse_submit_errors() output
     * @param array    $schema     normalized target schema (may be empty when unknown)
     * @param string[] $sent_names field names already sent with a value
     * @return array{retry: bool, fields: array<string, string>, unrepairable: array<string, string>}
     */
    public static function repair_plan(array $errors, array $schema, array $sent_names, $placeholder) {
        $plan = ['retry' => false, 'fields' => [], 'unrepairable' => []];
        if (empty($errors) || trim((string) $placeholder) === '') {
            return $plan;
        }

        foreach ($errors as $error) {
            $name = $error['field'];
            if ($error['type'] !== 'REQUIRED_FIELD' || $name === '') {
                $plan['unrepairable'][$name !== '' ? $name : '#' . count($plan['unrepairable'])] = $error['type'] !== '' ? strtolower($error['type']) : 'unknown-error';
                continue;
            }
            $reason = self::placeholder_blocker($name, $schema['fields'][$name] ?? null, $sent_names, !empty($schema['fields']));
            if ($reason !== null) {
                $plan['unrepairable'][$name] = $reason;
                continue;
            }
            $plan['fields'][$name] = (string) $placeholder;
        }

        $plan['retry'] = !empty($plan['fields']) && empty($plan['unrepairable']);
        return $plan;
    }

    /**
     * @return ?string reason the field must not get a placeholder, null when it may
     */
    private static function placeholder_blocker($name, $field, array $sent_names, $schema_known = false) {
        if (in_array($name, $sent_names, true)) {
            return 'already-sent';
        }
        if (self::is_consent_like($name)) {
            return 'consent';
        }
        if ($field === null) {
            if ($schema_known) {
                return 'unknown-field';
            }
            return stripos($name, 'email') !== false ? 'email' : null;
        }
        if (!in_array($field['type'], self::PLACEHOLDER_TYPES, true)) {
            return 'type-' . $field['type'];
        }
        return null;
    }

    public static function is_consent_like($name) {
        return (bool) preg_match(self::CONSENT_NAME_PATTERN, (string) $name);
    }
}
