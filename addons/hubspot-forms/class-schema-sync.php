<?php
/**
 * HubSpot Forms: schema sync, missing required fields, repair and admin alerts.
 *
 *  - Keeps a versioned copy (hash) of every HubSpot form used by Elementor forms
 *    and by Language Routing targets; refreshed by WP-cron and on demand.
 *  - Detects schema changes and required fields the Elementor mapping does not
 *    cover, per language target.
 *  - Renders missing required fields on the frontend form (auto-appear) and
 *    accepts their values on submit (whitelisted against the stored schema).
 *  - Plans the one-time placeholder retry for HubSpot REQUIRED_FIELD errors.
 *  - Raises persistent admin notices and throttled e-mails (wp_mail).
 *
 * Decision logic: Starter_HubSpot_Schema (class-schema.php).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-schema.php';

class Starter_HubSpot_Schema_Sync {

    const OPT_STORE = 'starter_hubspot_schema_store';
    const OPT_ALERTS = 'starter_hubspot_alerts';
    const OPT_LAST_SYNC = 'starter_hubspot_schema_last_sync';
    const OPT_AUTO_FIELDS = 'starter_hubspot_auto_fields';
    const OPT_SYNC_INTERVAL = 'starter_hubspot_schema_sync_interval';
    const OPT_PLACEHOLDER = 'starter_hubspot_repair_placeholder';
    const OPT_NOTIFY = 'starter_hubspot_notify_enabled';
    const OPT_NOTIFY_EMAIL = 'starter_hubspot_notify_email';

    const DEFAULT_INTERVAL = 'hourly';
    const DEFAULT_PLACEHOLDER = '-';
    const INTERVALS = ['hourly', 'twicedaily', 'daily', 'off'];

    const CRON_HOOK = 'starter_hubspot_schema_sync';
    const MAIL_THROTTLE = DAY_IN_SECONDS;
    const MAX_ALERTS = 30;
    const MAX_EXTRA_LENGTH = 2000;

    const FIELD_EXTRA = 'bp_hs_extra';
    const FIELD_CONSENT = 'bp_hs_consent';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(self::CRON_HOOK, [$this, 'run_cron']);
        add_action(self::CRON_HOOK . '_once', [$this, 'run_cron']);
        add_action('init', [$this, 'ensure_schedule']);
        add_action('wp_ajax_starter_hubspot_sync_schemas', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_starter_hubspot_get_form_targets', [$this, 'ajax_form_targets']);
        add_action('admin_notices', [$this, 'admin_notice']);
        add_action('admin_post_starter_hubspot_dismiss_alert', [$this, 'handle_dismiss_alert']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_script'], 20);
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public static function settings() {
        $interval = (string) get_option(self::OPT_SYNC_INTERVAL, self::DEFAULT_INTERVAL);
        return [
            'auto_fields' => get_option(self::OPT_AUTO_FIELDS, 'yes') === 'yes',
            'interval' => in_array($interval, self::INTERVALS, true) ? $interval : self::DEFAULT_INTERVAL,
            'placeholder' => (string) get_option(self::OPT_PLACEHOLDER, self::DEFAULT_PLACEHOLDER),
            'notify' => get_option(self::OPT_NOTIFY, 'yes') === 'yes',
            'notify_email' => (string) get_option(self::OPT_NOTIFY_EMAIL, ''),
        ];
    }

    public static function save_settings(array $settings) {
        if (!isset($settings['schema_settings_present'])) {
            return;
        }
        $flag = function ($key) use ($settings) {
            return isset($settings[$key]) && Starter_HubSpot_Language_Routing::is_truthy($settings[$key]) ? 'yes' : 'no';
        };

        update_option(self::OPT_AUTO_FIELDS, $flag('auto_fields'));
        update_option(self::OPT_NOTIFY, $flag('notify_enabled'));

        $interval = isset($settings['schema_sync_interval']) ? sanitize_key(wp_unslash($settings['schema_sync_interval'])) : self::DEFAULT_INTERVAL;
        update_option(self::OPT_SYNC_INTERVAL, in_array($interval, self::INTERVALS, true) ? $interval : self::DEFAULT_INTERVAL);

        $placeholder = isset($settings['repair_placeholder']) ? sanitize_text_field(wp_unslash($settings['repair_placeholder'])) : self::DEFAULT_PLACEHOLDER;
        update_option(self::OPT_PLACEHOLDER, mb_substr($placeholder, 0, 50));

        $email = isset($settings['notify_email']) ? sanitize_email(wp_unslash($settings['notify_email'])) : '';
        update_option(self::OPT_NOTIFY_EMAIL, is_email($email) ? $email : '');

        // Interval may have changed
        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::instance()->ensure_schedule();
    }

    public function ensure_schedule() {
        $interval = self::settings()['interval'];
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($interval === 'off') {
            if ($next) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
            return;
        }
        if (!$next) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK);
        }
    }

    /* ------------------------------------------------------------------
     * Schema store and sync
     * ------------------------------------------------------------------ */

    public static function store() {
        $store = get_option(self::OPT_STORE, []);
        return is_array($store) ? $store : [];
    }

    /**
     * Stored (synced) normalized schema of a HubSpot form, or null.
     */
    public static function stored_schema($guid) {
        $guid = strtolower(trim((string) $guid));
        $store = self::store();
        return isset($store[$guid]['schema']) && is_array($store[$guid]['schema']) ? $store[$guid]['schema'] : null;
    }

    /**
     * Schema for display/repair: stored copy, or fetched (5-minute cache) and stored.
     */
    public function schema_for($guid) {
        $schema = self::stored_schema($guid);
        if ($schema !== null) {
            return $schema;
        }
        $fetched = $this->fetch_and_record($guid, false, 'lookup');
        return is_array($fetched) ? $fetched : null;
    }

    /**
     * Fetch a form definition (GET marketing/v3/forms) and record it.
     *
     * @return array|WP_Error normalized schema
     */
    public function fetch_and_record($guid, $force, $trigger) {
        $guid = strtolower(trim((string) $guid));
        if (!preg_match(Starter_HubSpot_Language_Routing::GUID_PATTERN, $guid)) {
            return new WP_Error('invalid_guid', 'Invalid form GUID');
        }
        if ($force) {
            delete_transient('starter_hs_fields_' . md5($guid));
        }
        $form = Starter_Addon_HubSpot_Forms::instance()->get_form_fields($guid);
        if (is_wp_error($form)) {
            return $form;
        }
        $schema = Starter_HubSpot_Schema::normalize($form);
        $this->record_schema($guid, $schema, $trigger);
        return $schema;
    }

    /**
     * Store a schema; when it differs from the stored version, raise a
     * schema_change alert. Returns the diff (empty when unchanged / first sync).
     */
    public function record_schema($guid, array $schema, $trigger = 'sync') {
        $store = self::store();
        $hash = Starter_HubSpot_Schema::hash($schema);
        $previous = $store[$guid] ?? null;
        $diff = [];

        if ($previous && ($previous['hash'] ?? '') !== $hash && is_array($previous['schema'] ?? null)) {
            $diff = Starter_HubSpot_Schema::diff($previous['schema'], $schema);
        }

        $store[$guid] = [
            'name' => self::form_name($guid),
            'hash' => $hash,
            'schema' => $schema,
            'synced_at' => time(),
            'changed_at' => !empty($diff) && Starter_HubSpot_Schema::has_changes($diff) ? time() : ($previous['changed_at'] ?? null),
        ];
        update_option(self::OPT_STORE, $store, false);

        if (!empty($diff) && Starter_HubSpot_Schema::has_changes($diff)) {
            $fields = array_values(array_unique(array_merge(
                $diff['required_added'],
                array_keys($diff['type_changed']),
                $diff['options_changed'],
                $diff['fields_removed']
            )));
            $this->raise_alert('schema_change', $guid, [
                'fields' => $fields,
                'required_added' => $diff['required_added'],
                'consent_changed' => $diff['consent_changed'],
                'pages' => $this->pages_using($guid),
            ]);
        }
        return $diff;
    }

    public function run_cron() {
        $this->sync_all('cron');
    }

    /**
     * Refresh every HubSpot form used by Elementor forms and by Language Routing.
     */
    public function sync_all($trigger) {
        $guids = $this->known_guids();
        $summary = ['time' => time(), 'trigger' => $trigger, 'forms' => 0, 'changed' => 0, 'errors' => []];

        foreach ($guids as $guid) {
            $before = self::store()[$guid]['hash'] ?? null;
            $result = $this->fetch_and_record($guid, true, $trigger);
            if (is_wp_error($result)) {
                $summary['errors'][$guid] = $result->get_error_message();
                continue;
            }
            $summary['forms']++;
            if ($before !== null && $before !== Starter_HubSpot_Schema::hash($result)) {
                $summary['changed']++;
            }
        }
        update_option(self::OPT_LAST_SYNC, $summary, false);
        return $summary;
    }

    /**
     * Source GUIDs from Elementor forms plus all routing sources and targets.
     */
    public function known_guids() {
        $guids = [];
        foreach ($this->scan_forms() as $form) {
            $guids[] = $form['hubspot_form_id'];
        }
        foreach (Starter_HubSpot_Language_Routing::get_routing() as $source => $targets) {
            $guids[] = $source;
            foreach ((array) $targets as $target) {
                $guids[] = $target;
            }
        }
        $guids = array_map('strtolower', array_filter(array_map('strval', $guids)));
        return array_values(array_unique(array_filter($guids, function ($guid) {
            return (bool) preg_match(Starter_HubSpot_Language_Routing::GUID_PATTERN, $guid);
        })));
    }

    /**
     * Elementor form widgets with the HubSpot action, with the settings the
     * coverage check needs.
     */
    public function scan_forms() {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_elementor_data'
            AND pm.meta_value LIKE '%hubspot%'
            AND p.post_status IN ('publish', 'draft', 'private')
        ");
        $forms = [];
        foreach ((array) $rows as $row) {
            $data = json_decode($row->meta_value, true);
            if (!is_array($data)) {
                continue;
            }
            foreach (self::extract_forms($data) as $settings) {
                $forms[] = [
                    'page_id' => (int) $row->ID,
                    'page_title' => (string) $row->post_title,
                    'form_name' => (string) ($settings['form_name'] ?? ''),
                    'hubspot_form_id' => strtolower((string) $settings['hubspot_form_id']),
                    'settings' => $settings,
                ];
            }
        }
        return $forms;
    }

    private static function extract_forms(array $elements, array $found = []) {
        foreach ($elements as $element) {
            if (($element['elType'] ?? '') === 'widget' && ($element['widgetType'] ?? '') === 'form') {
                $settings = (array) ($element['settings'] ?? []);
                if (in_array('hubspot', (array) ($settings['submit_actions'] ?? []), true) && !empty($settings['hubspot_form_id'])) {
                    $found[] = $settings;
                }
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found = self::extract_forms($element['elements'], $found);
            }
        }
        return $found;
    }

    /**
     * Language targets of a source form: [language|'' => target GUID].
     * '' = the source itself (default language and unrouted languages).
     */
    public static function targets_for($source_guid) {
        $source_guid = strtolower((string) $source_guid);
        $targets = ['' => $source_guid];
        $routing = Starter_HubSpot_Language_Routing::get_routing();
        foreach ((array) ($routing[$source_guid] ?? []) as $language => $target) {
            $targets[(string) $language] = strtolower((string) $target);
        }
        return $targets;
    }

    /**
     * Per Elementor form and language target: required HubSpot fields the
     * mapping does not cover. Used by the Mappings tab.
     */
    public function coverage_for_settings(array $settings) {
        $covered = Starter_HubSpot_Schema::covered_fields($settings);
        $consent_mapping = json_decode((string) ($settings['hubspot_consent_mapping_json'] ?? ''), true);
        $consent_mapping = is_array($consent_mapping) ? $consent_mapping : [];

        $report = [];
        foreach (self::targets_for($settings['hubspot_form_id'] ?? '') as $language => $guid) {
            $schema = $this->schema_for($guid);
            $entry = ['language' => $language, 'guid' => $guid, 'name' => self::form_name($guid), 'synced' => $schema !== null, 'missing' => [], 'consent_missing' => []];
            if ($schema !== null) {
                foreach (Starter_HubSpot_Schema::missing_required($schema, $covered) as $field) {
                    $entry['missing'][] = ['name' => $field['name'], 'label' => $field['label'], 'renderable' => Starter_HubSpot_Schema::render_spec($field) !== null];
                }
                $entry['consent_missing'] = array_keys(Starter_HubSpot_Schema::missing_consent($schema, $consent_mapping));
            }
            $report[] = $entry;
        }
        return $report;
    }

    /**
     * Pages whose Elementor forms send to $guid (as source or routing target).
     */
    public function pages_using($guid) {
        $pages = [];
        foreach ($this->scan_forms() as $form) {
            if (in_array($guid, self::targets_for($form['hubspot_form_id']), true)) {
                $pages[$form['page_id']] = $form['page_title'];
            }
        }
        return array_slice(array_values(array_unique($pages)), 0, 10);
    }

    public static function form_name($guid) {
        $forms = get_transient('starter_hubspot_forms_list');
        if (is_array($forms)) {
            foreach ($forms as $form) {
                if (strtolower((string) ($form['id'] ?? '')) === strtolower((string) $guid)) {
                    return (string) $form['name'];
                }
            }
        }
        $store = self::store();
        return (string) ($store[strtolower((string) $guid)]['name'] ?? '');
    }

    /* ------------------------------------------------------------------
     * Frontend: auto-appear of missing required fields
     * ------------------------------------------------------------------ */

    /**
     * Whether a form widget may need extra fields (decides output buffering).
     */
    public function wants_buffer() {
        return self::settings()['auto_fields'];
    }

    /**
     * Insert required fields of the (language-routed) target HubSpot form that
     * the Elementor form does not send, before the submit button.
     */
    public function inject_missing_fields($html, $widget) {
        if (!self::settings()['auto_fields'] || !is_object($widget) || !method_exists($widget, 'get_settings_for_display')) {
            return $html;
        }
        $settings = (array) $widget->get_settings_for_display();
        $source = (string) ($settings['hubspot_form_id'] ?? '');
        if ($source === '') {
            return $html;
        }

        $language = Starter_HubSpot_Language_Routing::detect('', [function_exists('get_queried_object_id') ? get_queried_object_id() : 0]);
        $target = Starter_HubSpot_Language_Routing::resolve($source, $language, Starter_HubSpot_Language_Routing::get_routing());
        $schema = self::stored_schema($target);
        if ($schema === null) {
            // Never call the HubSpot API while rendering a page: sync in the background,
            // at most once per 10 minutes per form (a form that cannot be fetched must not loop)
            $gate = 'starter_hs_sync_req_' . md5($target);
            if (!get_transient($gate) && !wp_next_scheduled(self::CRON_HOOK . '_once')) {
                set_transient($gate, 1, 10 * MINUTE_IN_SECONDS);
                wp_schedule_single_event(time(), self::CRON_HOOK . '_once');
            }
            return $html;
        }

        $markup = $this->missing_fields_markup($schema, $settings);
        if ($markup === '') {
            return $html;
        }
        // Callback, not a replacement string: HubSpot labels may contain "$1" or "\\"
        $insert = function ($match) use ($markup) {
            return $markup . $match[0];
        };
        $injected = preg_replace_callback('#<div[^>]*class="[^"]*elementor-field-type-submit#i', $insert, $html, 1, $count);
        if (!$count) {
            $injected = preg_replace_callback('#</form>#i', $insert, $html, 1, $count);
        }
        return $count ? $injected : $html;
    }

    public function missing_fields_markup(array $schema, array $settings) {
        $covered = Starter_HubSpot_Schema::covered_fields($settings);
        $consent_mapping = json_decode((string) ($settings['hubspot_consent_mapping_json'] ?? ''), true);
        $consent_mapping = is_array($consent_mapping) ? $consent_mapping : [];
        $size = sanitize_html_class((string) ($settings['input_size'] ?? 'sm'));
        $show_labels = ($settings['show_labels'] ?? 'yes') === 'yes';

        $html = '';
        foreach (Starter_HubSpot_Schema::missing_required($schema, $covered) as $field) {
            $spec = Starter_HubSpot_Schema::render_spec($field);
            if ($spec !== null) {
                $html .= self::field_html($spec, $size, $show_labels);
            }
        }
        foreach (Starter_HubSpot_Schema::missing_consent($schema, $consent_mapping) as $key => $label) {
            $html .= self::consent_html($key, $label);
        }
        return $html;
    }

    /**
     * Markup that matches Elementor Pro's own field groups, so the form's
     * styling applies. Values are posted as bp_hs_extra[<HubSpot name>].
     */
    public static function field_html(array $spec, $size, $show_labels) {
        $key = 'bp_hs_' . sanitize_key(str_replace('/', '_', $spec['name']));
        $id = 'form-field-' . $key;
        $name = self::FIELD_EXTRA . '[' . $spec['name'] . ']';
        $label = esc_html($spec['label']);
        $placeholder = $show_labels ? '' : ' placeholder="' . esc_attr($spec['label']) . '"';
        $input_class = 'elementor-field elementor-size-' . $size . ' elementor-field-textual';

        $group = '<div class="elementor-field-type-' . esc_attr($spec['type']) . ' elementor-field-group elementor-column elementor-field-group-' . esc_attr($key)
            . ' elementor-col-100 elementor-field-required elementor-mark-required bp-hubspot-auto-field">';
        $label_html = $show_labels ? '<label for="' . esc_attr($id) . '" class="elementor-field-label">' . $label . '</label>' : '';

        switch ($spec['type']) {
            case 'textarea':
                $control = '<textarea class="' . $input_class . '" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" rows="4" required aria-required="true"' . $placeholder . '></textarea>';
                break;
            case 'select':
                $control = '<div class="elementor-field elementor-select-wrapper"><select class="elementor-field-textual elementor-size-' . $size . '" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" required aria-required="true">'
                    . '<option value="">' . ($show_labels ? '&mdash;' : $label) . '</option>';
                foreach ($spec['options'] as $option) {
                    $control .= '<option value="' . esc_attr($option['value']) . '">' . esc_html($option['label']) . '</option>';
                }
                $control .= '</select></div>';
                break;
            case 'checkbox':
                $control = '<div class="elementor-field-subgroup"><span class="elementor-field-option">'
                    . '<input type="checkbox" value="true" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" required aria-required="true">'
                    . ' <label for="' . esc_attr($id) . '">' . $label . '</label></span></div>';
                $label_html = '';
                break;
            case 'checkboxes':
                $control = '<div class="elementor-field-subgroup">';
                foreach (array_values($spec['options']) as $i => $option) {
                    $option_id = $id . '-' . $i;
                    $control .= '<span class="elementor-field-option"><input type="checkbox" value="' . esc_attr($option['value']) . '" name="' . esc_attr($name) . '[]" id="' . esc_attr($option_id) . '">'
                        . ' <label for="' . esc_attr($option_id) . '">' . esc_html($option['label']) . '</label></span>';
                }
                $control .= '</div>';
                break;
            default:
                $control = '<input size="1" type="' . esc_attr($spec['type']) . '" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="' . $input_class . '" required aria-required="true"' . $placeholder . '>';
        }
        return $group . $label_html . $control . '</div>';
    }

    /**
     * A real, unchecked consent checkbox. Never pre-checked, never auto-filled.
     */
    public static function consent_html($key, $label) {
        $key = sanitize_key($key);
        $id = 'form-field-bp_hs_consent_' . $key;
        $text = $label !== '' ? wp_kses_post($label) : esc_html__('I agree to the processing of my personal data.', 'starter-dashboard');
        return '<div class="elementor-field-type-acceptance elementor-field-group elementor-column elementor-col-100 elementor-field-required bp-hubspot-auto-field">'
            . '<div class="elementor-field-subgroup"><span class="elementor-field-option">'
            . '<input type="checkbox" value="1" name="' . esc_attr(self::FIELD_CONSENT . '[' . $key . ']') . '" id="' . esc_attr($id) . '" class="elementor-field elementor-acceptance-field" required aria-required="true">'
            . ' <label for="' . esc_attr($id) . '">' . $text . '</label></span></div></div>';
    }

    /* ------------------------------------------------------------------
     * Submit: accept auto-appeared values
     * ------------------------------------------------------------------ */

    /**
     * Values of auto-appeared fields: only required, visible fields of the target
     * schema that the mapping did not already fill (compared after the same
     * aliases submit_to_hubspot() applies). Anything else is dropped.
     *
     * @return array<string, string>
     */
    public static function extra_fields($target_guid, array $already, array $post) {
        $raw = $post[self::FIELD_EXTRA] ?? null;
        $schema = self::stored_schema($target_guid);
        if (!is_array($raw) || $schema === null) {
            return [];
        }
        $filled = [];
        foreach ($already as $key => $value) {
            if ($value !== '' && $value !== null) {
                $filled[Starter_HubSpot_Schema::FIELD_ALIASES[$key] ?? $key] = true;
            }
        }
        $extra = [];
        foreach ($raw as $name => $value) {
            $name = (string) $name;
            $field = $schema['fields'][$name] ?? null;
            if ($field === null || empty($field['required']) || !empty($field['hidden']) || isset($filled[$name])) {
                continue;
            }
            if (is_array($value) && $field['type'] !== 'multiple_checkboxes') {
                continue;
            }
            $clean = self::sanitize_extra(wp_unslash($value), $field);
            if ($clean !== '') {
                $extra[$name] = $clean;
            }
        }
        return $extra;
    }

    private static function sanitize_extra($value, array $field) {
        $allowed = array_column($field['options'], 'value');
        switch ($field['type']) {
            case 'email':
                return sanitize_email((string) $value);
            case 'multi_line_text':
                return mb_substr(sanitize_textarea_field((string) $value), 0, self::MAX_EXTRA_LENGTH);
            case 'dropdown':
            case 'radio':
                return in_array((string) $value, $allowed, true) ? (string) $value : '';
            case 'multiple_checkboxes':
                $values = array_values(array_intersect(array_map('strval', (array) $value), $allowed));
                return implode(';', $values);
            case 'single_checkbox':
                return (string) $value === 'true' ? 'true' : '';
            case 'number':
                return is_numeric($value) ? (string) $value : '';
            case 'datepicker':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : '';
            default:
                return mb_substr(sanitize_text_field((string) $value), 0, self::MAX_EXTRA_LENGTH);
        }
    }

    /**
     * Consent ticked on auto-appeared consent checkboxes (only keys the target
     * form requires and the mapping does not cover). Never set without a tick.
     *
     * @return array<string, bool>
     */
    public static function extra_consent($target_guid, array $consent_mapping, array $post) {
        $raw = $post[self::FIELD_CONSENT] ?? null;
        $schema = self::stored_schema($target_guid);
        if (!is_array($raw) || $schema === null) {
            return [];
        }
        $consent = [];
        foreach (array_keys(Starter_HubSpot_Schema::missing_consent($schema, $consent_mapping)) as $key) {
            if (isset($raw[sanitize_key($key)]) && (string) $raw[sanitize_key($key)] === '1') {
                $consent[$key] = true;
            }
        }
        return $consent;
    }

    /**
     * Repair plan for a rejected submission (see Starter_HubSpot_Schema::repair_plan()).
     */
    public function repair_plan($guid, array $errors, array $sent_names) {
        $schema = $this->schema_for($guid) ?? [];
        return Starter_HubSpot_Schema::repair_plan($errors, $schema, $sent_names, self::settings()['placeholder']);
    }

    /* ------------------------------------------------------------------
     * Alerts and notifications
     * ------------------------------------------------------------------ */

    /**
     * Persistent alert (deduplicated per type + form) and a throttled e-mail.
     *
     * @param string $type schema_change | repaired | failed | consent_missing
     */
    public function raise_alert($type, $guid, array $details) {
        $guid = strtolower((string) $guid);
        $alerts = get_option(self::OPT_ALERTS, []);
        $alerts = is_array($alerts) ? $alerts : [];
        $key = $type . '|' . $guid;
        $fields = array_values(array_unique(array_map('strval', $details['fields'] ?? [])));

        $existing = $alerts[$key] ?? null;
        $alerts[$key] = [
            'type' => $type,
            'guid' => $guid,
            'form_name' => self::form_name($guid),
            'fields' => $fields,
            'pages' => array_values(array_map('strval', $details['pages'] ?? [])),
            'error' => mb_substr((string) ($details['error'] ?? ''), 0, 300),
            'first_seen' => $existing['first_seen'] ?? time(),
            'last_seen' => time(),
            'count' => (int) ($existing['count'] ?? 0) + 1,
            'dismissed' => $existing && !empty($existing['dismissed']) && $existing['fields'] === $fields,
        ];
        uasort($alerts, function ($a, $b) { return $b['last_seen'] <=> $a['last_seen']; });
        update_option(self::OPT_ALERTS, array_slice($alerts, 0, self::MAX_ALERTS, true), false);

        $this->maybe_email($alerts[$key]);
    }

    private function maybe_email(array $alert) {
        $settings = self::settings();
        if (!$settings['notify'] || !empty($alert['dismissed'])) {
            return;
        }
        // At most one e-mail per HubSpot form per day
        $throttle = 'starter_hs_mail_' . md5($alert['guid']);
        if (get_transient($throttle)) {
            return;
        }
        $to = $settings['notify_email'] !== '' ? $settings['notify_email'] : get_option('admin_email');
        if (!is_email($to)) {
            return;
        }
        // Set before sending to keep the window for parallel requests small
        set_transient($throttle, 1, self::MAIL_THROTTLE);
        $form = ($alert['form_name'] !== '' ? $alert['form_name'] : __('(unknown name)', 'starter-dashboard')) . ' (' . $alert['guid'] . ')';
        $subject = sprintf(__('[%1$s] HubSpot form needs attention: %2$s', 'starter-dashboard'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $alert['form_name'] !== '' ? $alert['form_name'] : $alert['guid']);

        $lines = [self::alert_text($alert), '', sprintf(__('Form: %s', 'starter-dashboard'), $form)];
        if (!empty($alert['fields'])) {
            $lines[] = sprintf(__('Fields: %s', 'starter-dashboard'), implode(', ', $alert['fields']));
        }
        if (!empty($alert['pages'])) {
            $lines[] = sprintf(__('Pages: %s', 'starter-dashboard'), implode(', ', $alert['pages']));
        }
        $lines[] = '';
        $lines[] = sprintf(__('Check the mapping and the Submission Log: %s', 'starter-dashboard'), admin_url('admin.php?page=starter-dashboard'));

        if (!wp_mail($to, $subject, implode("\n", $lines))) {
            delete_transient($throttle);
        }
    }

    public static function alert_text(array $alert) {
        switch ($alert['type']) {
            case 'schema_change':
                return __('The HubSpot form settings changed. New or changed fields may need to be mapped in Elementor (missing required fields are added to the form automatically when that option is on).', 'starter-dashboard');
            case 'repaired':
                return __('A submission was missing required HubSpot fields. It was sent again with placeholder values, so the lead was saved. Map these fields in the Elementor form.', 'starter-dashboard');
            case 'consent_missing':
                return __('A submission was rejected because a required consent is missing. Consent is never filled in automatically: add a consent checkbox to the Elementor form and map it.', 'starter-dashboard');
            default:
                return __('A submission to HubSpot failed. The lead did not reach HubSpot. Details are in the Submission Log.', 'starter-dashboard');
        }
    }

    public static function active_alerts() {
        $alerts = get_option(self::OPT_ALERTS, []);
        return array_filter(is_array($alerts) ? $alerts : [], function ($alert) {
            return empty($alert['dismissed']);
        });
    }

    public function admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $alerts = array_slice(self::active_alerts(), 0, 5, true);
        foreach ($alerts as $key => $alert) {
            $dismiss = wp_nonce_url(
                admin_url('admin-post.php?action=starter_hubspot_dismiss_alert&alert=' . rawurlencode($key)),
                'starter_hubspot_dismiss_alert'
            );
            $form = $alert['form_name'] !== '' ? $alert['form_name'] : $alert['guid'];
            printf(
                '<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s</p><p>%4$s%5$s%6$s</p><p><a href="%7$s">%8$s</a></p></div>',
                $alert['type'] === 'repaired' || $alert['type'] === 'schema_change' ? 'warning' : 'error',
                esc_html(sprintf(__('HubSpot Forms: %s', 'starter-dashboard'), $form)),
                esc_html(self::alert_text($alert)),
                esc_html(sprintf(__('Form GUID: %s', 'starter-dashboard'), $alert['guid'])),
                !empty($alert['fields']) ? ' &middot; ' . esc_html(sprintf(__('Fields: %s', 'starter-dashboard'), implode(', ', $alert['fields']))) : '',
                !empty($alert['pages']) ? ' &middot; ' . esc_html(sprintf(__('Pages: %s', 'starter-dashboard'), implode(', ', $alert['pages']))) : '',
                esc_url($dismiss),
                esc_html__('Dismiss', 'starter-dashboard')
            );
        }
    }

    public function handle_dismiss_alert() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied', 'starter-dashboard'), 403);
        }
        check_admin_referer('starter_hubspot_dismiss_alert');
        $key = isset($_GET['alert']) ? sanitize_text_field(wp_unslash($_GET['alert'])) : '';
        $alerts = get_option(self::OPT_ALERTS, []);
        if (is_array($alerts) && isset($alerts[$key])) {
            $alerts[$key]['dismissed'] = true;
            update_option(self::OPT_ALERTS, $alerts, false);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /* ------------------------------------------------------------------
     * Admin AJAX
     * ------------------------------------------------------------------ */

    public function ajax_sync_now() {
        check_ajax_referer('starter_settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }
        wp_send_json_success($this->sync_all('manual'));
    }

    /**
     * Editor: required fields of the source form and of every language target.
     */
    public function ajax_form_targets() {
        check_ajax_referer('starter_settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }
        $source = isset($_GET['form_guid']) ? strtolower(sanitize_text_field(wp_unslash($_GET['form_guid']))) : '';
        if (!preg_match(Starter_HubSpot_Language_Routing::GUID_PATTERN, $source)) {
            wp_send_json_error(__('Form GUID required', 'starter-dashboard'));
        }

        $languages = Starter_HubSpot_Language_Routing::get_context()['languages'];
        $result = [];
        foreach (self::targets_for($source) as $language => $guid) {
            // Opening the mapping refreshes the schema (records changes)
            $schema = $this->fetch_and_record($guid, false, 'editor');
            $entry = [
                'language' => $language,
                'language_name' => $language === '' ? '' : ($languages[$language] ?? strtoupper($language)),
                'guid' => $guid,
                'name' => self::form_name($guid),
                'required' => [],
                'consent_required' => [],
                'error' => is_wp_error($schema) ? $schema->get_error_message() : '',
            ];
            if (is_array($schema)) {
                foreach ($schema['fields'] as $field) {
                    if ($field['required'] && !$field['hidden']) {
                        $entry['required'][] = ['name' => $field['name'], 'label' => $field['label'], 'renderable' => Starter_HubSpot_Schema::render_spec($field) !== null];
                    }
                }
                $entry['consent_required'] = array_values(array_map('wp_strip_all_tags', Starter_HubSpot_Schema::missing_consent($schema, [])));
            }
            $result[] = $entry;
        }
        wp_send_json_success(['targets' => $result, 'auto_fields' => self::settings()['auto_fields']]);
    }

    /* ------------------------------------------------------------------
     * Elementor editor: required-field status per language target
     * ------------------------------------------------------------------ */

    public function enqueue_editor_script() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('starter_settings'),
            'aliases' => Starter_HubSpot_Schema::FIELD_ALIASES,
            'i18n' => [
                'title' => __('Required HubSpot fields per language', 'starter-dashboard'),
                'source' => __('Original form (default language)', 'starter-dashboard'),
                'mapped' => __('mapped', 'starter-dashboard'),
                'unmapped' => __('not mapped', 'starter-dashboard'),
                'auto' => __('not mapped: shown on the form automatically', 'starter-dashboard'),
                'manual' => __('not mapped: cannot be shown automatically, map it', 'starter-dashboard'),
                'consent' => __('Required consent not mapped (a consent checkbox is shown automatically):', 'starter-dashboard'),
                'ok' => __('All required fields are mapped.', 'starter-dashboard'),
                'loading' => __('Checking language targets...', 'starter-dashboard'),
                'error' => __('Could not load this HubSpot form', 'starter-dashboard'),
            ],
        ];
        wp_add_inline_script('elementor-editor', 'window.ehfaTargetsConfig=' . wp_json_encode($config) . ';' . self::editor_js());
    }

    private static function editor_js() {
        return <<<'JS'
(function ($) {
    var cfg = window.ehfaTargetsConfig || {};
    var cache = {};
    var t = cfg.i18n || {};

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    // Same rule as the server: mapped rows whose Elementor field exists, else auto-map by id
    function coveredNames(container) {
        var elementorIds = [];
        container.find('.ehfa-mapping-row').first().find('.ehfa-el-select option').each(function () {
            if (this.value) { elementorIds.push(this.value); }
        });
        var covered = [];
        container.find('.ehfa-mapping-row').each(function () {
            var el = $(this).find('.ehfa-el-select').val();
            var hs = $(this).find('.ehfa-hs-select').val();
            if (el && hs && elementorIds.indexOf(el) !== -1) { covered.push(hs); }
        });
        if (!covered.length) { covered = elementorIds.slice(); }
        return covered.map(function (n) { return (cfg.aliases && cfg.aliases[n]) || n; });
    }

    function render(sourceId) {
        var container = $('#ehfa-field-mapping-container');
        if (!container.length || !sourceId || !cache[sourceId]) { return; }
        var data = cache[sourceId];
        var covered = coveredNames(container);
        var html = '<div id="ehfa-target-status" style="margin-top:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:12px;font-size:12px;">';
        html += '<div style="font-size:11px;text-transform:uppercase;color:#ff7a59;letter-spacing:0.5px;margin-bottom:8px;">' + esc(t.title) + '</div>';
        data.targets.forEach(function (target) {
            var label = target.language ? (target.language_name || target.language.toUpperCase()) : t.source;
            html += '<div style="margin-bottom:10px;"><strong>' + esc(label) + '</strong> <span style="opacity:.6">' + esc(target.name || target.guid) + '</span>';
            if (target.error) {
                html += '<div style="color:#d63638;">' + esc(t.error) + '</div></div>';
                return;
            }
            var missing = 0;
            html += '<ul style="margin:4px 0 0 0;list-style:none;padding:0;">';
            target.required.forEach(function (field) {
                var ok = covered.indexOf(field.name) !== -1;
                if (!ok) { missing++; }
                var status = ok ? '<span style="color:#6c6;">✓ ' + esc(t.mapped) + '</span>'
                    : '<span style="color:#ff7a59;">✗ ' + esc(data.auto_fields && field.renderable ? t.auto : (data.auto_fields ? t.manual : t.unmapped)) + '</span>';
                html += '<li>' + esc(field.label) + ' <code>' + esc(field.name) + '</code> — ' + status + '</li>';
            });
            html += '</ul>';
            if (target.consent_required && target.consent_required.length) {
                html += '<div style="color:#ff7a59;margin-top:4px;">' + esc(t.consent) + ' ' + target.consent_required.map(function (l) { return esc(l); }).join('; ') + '</div>';
            }
            if (!missing && target.required.length) { html += '<div style="opacity:.6;">' + esc(t.ok) + '</div>'; }
            html += '</div>';
        });
        html += '</div>';
        container.find('#ehfa-target-status').remove();
        container.append(html);
    }

    window.ehfaRenderTargets = function (sourceId) {
        if (!sourceId) { return; }
        if (cache[sourceId]) { render(sourceId); return; }
        var container = $('#ehfa-field-mapping-container');
        container.find('#ehfa-target-status').remove();
        container.append('<div id="ehfa-target-status" style="margin-top:12px;opacity:.6;font-size:12px;">' + esc(t.loading) + '</div>');
        $.get(cfg.ajaxUrl, { action: 'starter_hubspot_get_form_targets', form_guid: sourceId, nonce: cfg.nonce }, function (response) {
            if (response && response.success) {
                cache[sourceId] = response.data;
                render(sourceId);
            } else {
                container.find('#ehfa-target-status').text(t.error);
            }
        });
    };
})(jQuery);
JS;
    }

    /* ------------------------------------------------------------------
     * Admin settings section
     * ------------------------------------------------------------------ */

    public static function render_settings_section() {
        $s = self::settings();
        $last = get_option(self::OPT_LAST_SYNC, []);
        $intervals = [
            'hourly' => __('Every hour', 'starter-dashboard'),
            'twicedaily' => __('Twice a day', 'starter-dashboard'),
            'daily' => __('Once a day', 'starter-dashboard'),
            'off' => __('Off (manual only)', 'starter-dashboard'),
        ];
        ?>
        <div class="bp-hubspot-section bp-hubspot-schema-settings">
            <div class="bp-hubspot-section__header">
                <easier-icon name="refresh" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                <h4><?php _e('Form Changes & Missing Required Fields', 'starter-dashboard'); ?></h4>
            </div>
            <div class="bp-hubspot-section__content">
                <input type="hidden" name="schema_settings_present" value="1">

                <div class="bp-hubspot-debug-toggle">
                    <label class="bp-hubspot-switch">
                        <input type="checkbox" name="auto_fields" value="yes" <?php checked($s['auto_fields']); ?>>
                        <span class="bp-hubspot-switch-slider"></span>
                    </label>
                    <div class="bp-hubspot-debug-info">
                        <strong><?php _e('Automatically add missing required fields', 'starter-dashboard'); ?></strong>
                        <p class="description"><?php _e('When the HubSpot form for the visitor\'s language requires fields the Elementor form does not send, they are added to the form with HubSpot\'s labels. Missing consent is added as an unticked checkbox. Enabled by default.', 'starter-dashboard'); ?></p>
                    </div>
                </div>

                <div class="bp-hubspot-config-grid" style="margin:15px 0;">
                    <div class="bp-hubspot-field">
                        <label for="hubspot-schema-interval"><?php _e('Check HubSpot forms for changes', 'starter-dashboard'); ?></label>
                        <select id="hubspot-schema-interval" name="schema_sync_interval">
                            <?php foreach ($intervals as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($s['interval'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php if (!empty($last['time'])): ?>
                                <?php echo esc_html(sprintf(
                                    __('Last check: %1$s, %2$d forms, %3$d changed, %4$d errors.', 'starter-dashboard'),
                                    wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['time']),
                                    (int) ($last['forms'] ?? 0),
                                    (int) ($last['changed'] ?? 0),
                                    count((array) ($last['errors'] ?? []))
                                )); ?>
                            <?php else: ?>
                                <?php _e('Not checked yet.', 'starter-dashboard'); ?>
                            <?php endif; ?>
                        </p>
                        <button type="button" class="button button-secondary bp-hubspot-sync-schemas"><?php _e('Check now', 'starter-dashboard'); ?></button>
                        <span class="bp-hubspot-sync-result"></span>
                    </div>
                    <div class="bp-hubspot-field">
                        <label for="hubspot-repair-placeholder"><?php _e('Placeholder for missing text fields', 'starter-dashboard'); ?></label>
                        <input type="text" id="hubspot-repair-placeholder" name="repair_placeholder" class="regular-text" maxlength="50" value="<?php echo esc_attr($s['placeholder']); ?>">
                        <p class="description"><?php _e('If HubSpot still rejects a submission for a missing required text field, it is sent once more with this value so the lead is not lost. Never used for e-mail, choices or consent. Empty = no retry.', 'starter-dashboard'); ?></p>
                    </div>
                </div>

                <div class="bp-hubspot-debug-toggle">
                    <label class="bp-hubspot-switch">
                        <input type="checkbox" name="notify_enabled" value="yes" <?php checked($s['notify']); ?>>
                        <span class="bp-hubspot-switch-slider"></span>
                    </label>
                    <div class="bp-hubspot-debug-info">
                        <strong><?php _e('E-mail the administrator', 'starter-dashboard'); ?></strong>
                        <p class="description"><?php _e('When a HubSpot form changes or a submission is repaired or fails. At most one e-mail per form per day. A notice is always shown in the WordPress dashboard.', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <div class="bp-hubspot-field" style="margin-top:10px;">
                    <label for="hubspot-notify-email"><?php _e('Send to', 'starter-dashboard'); ?></label>
                    <input type="email" id="hubspot-notify-email" name="notify_email" class="regular-text" value="<?php echo esc_attr($s['notify_email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                    <p class="description"><?php _e('Empty = site administrator e-mail.', 'starter-dashboard'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
}
