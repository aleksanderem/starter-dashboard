<?php
/**
 * Tests for schema sync, auto-appear fields, extra field intake, the
 * REQUIRED_FIELD repair retry in submit_to_hubspot() and admin alerts.
 * WordPress is stubbed; HubSpot responses and wp_mail are mocked.
 * Nothing leaves this process.
 *
 * Run: php tests/test-schema-sync.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['posts'] = [];        // queued HubSpot submit responses
$GLOBALS['post_calls'] = [];   // captured submit payloads
$GLOBALS['get_responses'] = []; // url => response for marketing/v3/forms
$GLOBALS['mails'] = [];
$GLOBALS['language'] = 'cs';

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function add_action() {}
function add_filter() {}
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return $text; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($t) { return (string) $t; }
function wp_kses_post($t) { return strip_tags((string) $t, '<a><strong><em><p><br>'); }
function sanitize_html_class($c) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $c); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_email($v) { return filter_var(trim((string) $v), FILTER_SANITIZE_EMAIL); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function is_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false; }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int) $v); }
function wp_json_encode($v) { return json_encode($v); }
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'wpml_active_languages') {
        return ['pl' => ['translated_name' => 'Polski'], 'cs' => ['translated_name' => 'Čeština']];
    }
    if ($hook === 'wpml_default_language') { return 'pl'; }
    if ($hook === 'wpml_post_language_details') { return ['language_code' => $GLOBALS['language']]; }
    return $value;
}
function get_option($n, $d = false) { return array_key_exists($n, $GLOBALS['options']) ? $GLOBALS['options'][$n] : $d; }
function update_option($n, $v, $a = null) { $GLOBALS['options'][$n] = $v; return true; }
function delete_option($n) { unset($GLOBALS['options'][$n]); return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_remote_get($url, $args = []) { return $GLOBALS['get_responses'][$url] ?? new WP_Error('x', 'no mock for ' . $url); }
function wp_remote_post($url, $args = []) {
    $GLOBALS['post_calls'][] = ['url' => $url, 'payload' => json_decode($args['body'], true)];
    return array_shift($GLOBALS['posts']) ?? new WP_Error('x', 'no mock');
}
function wp_remote_retrieve_response_code($r) { return is_array($r) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body($r) { return is_array($r) ? $r['body'] : ''; }
function wp_mail($to, $subject, $body) { $GLOBALS['mails'][] = compact('to', 'subject', 'body'); return true; }
function get_bloginfo($k) { return 'Test Site'; }
function wp_specialchars_decode($s) { return $s; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://example.test' . $p; }
function wp_next_scheduled($h) { return false; }
function wp_clear_scheduled_hook($h) { return 0; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['scheduled'][] = $h; return true; }
function wp_schedule_single_event($t, $h) { $GLOBALS['scheduled'][] = $h; return true; }
function get_queried_object_id() { return 7; }
function wp_salt($s) { return 'salt'; }

class Fake_WPDB {
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public function get_results($sql) { return []; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require __DIR__ . '/../addons/hubspot-forms/addon.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}
function response($code, $body) { return ['response' => ['code' => $code], 'body' => json_encode($body)]; }

$pl = '9e102cff-cb2e-4f3d-a0df-82bf8812984d';
$cz = '36a68b67-908a-494f-abe0-4d795858c240';

// Mocked GET https://api.hubapi.com/marketing/v3/forms/{guid}
function hubspot_form_definition(array $required_extra) {
    $fields = [
        ['name' => 'email', 'label' => 'E-mail', 'fieldType' => 'email', 'required' => true],
        ['name' => 'message', 'label' => 'Zpráva', 'fieldType' => 'multi_line_text', 'required' => false],
    ];
    foreach ($required_extra as $name => $label) {
        $fields[] = ['name' => $name, 'label' => $label, 'fieldType' => 'single_line_text', 'required' => true];
    }
    return response(200, [
        'fieldGroups' => [['fields' => $fields]],
        'legalConsentOptions' => ['type' => 'explicit_consent_to_process', 'consentToProcessCheckboxLabel' => '<p>Souhlasím se zpracováním</p>', 'communicationConsentCheckboxes' => []],
    ]);
}
function reset_world() {
    global $pl, $cz;
    $GLOBALS['options'] = [
        'starter_hubspot_portal_id' => '123',
        'starter_hubspot_access_token' => 'TEST_TOKEN_PLACEHOLDER',
        'starter_hubspot_lang_routing' => [$pl => ['cs' => $cz]],
        'admin_email' => 'admin@example.test',
    ];
    $GLOBALS['transients'] = ['starter_hubspot_forms_list' => [['id' => $cz, 'name' => 'CZ_ask about price'], ['id' => $pl, 'name' => 'PL_formularz zapytaj o cenę']]];
    $GLOBALS['posts'] = [];
    $GLOBALS['post_calls'] = [];
    $GLOBALS['mails'] = [];
    $GLOBALS['get_responses'] = [];
}

$sync = Starter_HubSpot_Schema_Sync::instance();
$cz_url = 'https://api.hubapi.com/marketing/v3/forms/' . $cz;

// --- a) schema sync: first record, then a change ---
reset_world();
$GLOBALS['get_responses'][$cz_url] = hubspot_form_definition([]);
$sync->fetch_and_record($cz, true, 'test');
check('first sync stored', true, is_array(Starter_HubSpot_Schema_Sync::stored_schema($cz)));
check('first sync: no alert', [], get_option('starter_hubspot_alerts', []));
check('first sync: no mail', 0, count($GLOBALS['mails']));

$GLOBALS['get_responses'][$cz_url] = hubspot_form_definition(['company' => 'Společnost', 'firstname' => 'Jméno', 'lastname' => 'Příjmení']);
$sync->fetch_and_record($cz, true, 'test');
$alerts = get_option('starter_hubspot_alerts');
check('change detected -> schema_change alert', true, isset($alerts['schema_change|' . $cz]));
check('alert lists new required fields', ['company', 'firstname', 'lastname'], (function ($a) { sort($a); return $a; })($alerts['schema_change|' . $cz]['fields']));
check('alert has form name', 'CZ_ask about price', $alerts['schema_change|' . $cz]['form_name']);
check('one admin mail', 1, count($GLOBALS['mails']));
check('mail to admin_email by default', 'admin@example.test', $GLOBALS['mails'][0]['to']);
check('mail names form + GUID', true, strpos($GLOBALS['mails'][0]['body'], 'CZ_ask about price (' . $cz . ')') !== false);
check('mail lists fields', true, strpos($GLOBALS['mails'][0]['body'], 'company') !== false);

$GLOBALS['get_responses'][$cz_url] = hubspot_form_definition(['company' => 'Společnost']);
$sync->fetch_and_record($cz, true, 'test');
check('second change same day: mail throttled', 1, count($GLOBALS['mails']));

$GLOBALS['options']['starter_hubspot_notify_enabled'] = 'no';
$GLOBALS['transients'] = ['starter_hubspot_forms_list' => $GLOBALS['transients']['starter_hubspot_forms_list']];
$GLOBALS['get_responses'][$cz_url] = hubspot_form_definition(['company' => 'Společnost', 'firstname' => 'Jméno', 'lastname' => 'Příjmení']);
$sync->fetch_and_record($cz, true, 'test');
check('notify off: no mail', 1, count($GLOBALS['mails']));

$GLOBALS['options']['starter_hubspot_notify_enabled'] = 'yes';
$GLOBALS['options']['admin_email'] = 'not-an-address';
$GLOBALS['transients'] = ['starter_hubspot_forms_list' => $GLOBALS['transients']['starter_hubspot_forms_list']];
$sync->raise_alert('failed', $cz, ['fields' => ['x'], 'error' => 'e']);
check('bad recipient: no mail and no 24 h throttle', [1, false], [count($GLOBALS['mails']), get_transient('starter_hs_mail_' . md5($cz))]);
$GLOBALS['options']['admin_email'] = 'admin@example.test';
check('notify off: notice alert still stored', true, isset(get_option('starter_hubspot_alerts')['schema_change|' . $cz]));

// --- c) auto-appear on the CS page of a form mapped for the PL source ---
class Fake_Widget {
    public $settings;
    public function __construct($settings) { $this->settings = $settings; }
    public function get_settings_for_display() { return $this->settings; }
    public function get_name() { return 'form'; }
    public function get_id() { return 'w1'; }
}
$settings = [
    'submit_actions' => ['hubspot'],
    'hubspot_form_id' => $pl,
    'form_fields' => [['custom_id' => 'email'], ['custom_id' => 'message']],
    'hubspot_field_mapping_json' => json_encode([['elementor' => 'email', 'hubspot' => 'email'], ['elementor' => 'message', 'hubspot' => 'message']]),
    'input_size' => 'md',
    'show_labels' => 'yes',
];
$form_html = '<form class="elementor-form"><div class="elementor-form-fields-wrapper">'
    . '<div class="elementor-field-type-email elementor-field-group"><input name="form_fields[email]"></div>'
    . '<div class="elementor-field-group elementor-column elementor-field-type-submit elementor-col-100"><button type="submit">Send</button></div>'
    . '</div></form>';

$GLOBALS['language'] = 'cs';
$out = $sync->inject_missing_fields($form_html, new Fake_Widget($settings));
check('auto-appear: company field', true, strpos($out, 'name="bp_hs_extra[company]"') !== false);
check('auto-appear: firstname + lastname', true, strpos($out, 'bp_hs_extra[firstname]') !== false && strpos($out, 'bp_hs_extra[lastname]') !== false);
check('auto-appear: CZ label from target form', true, strpos($out, 'Společnost') !== false);
check('auto-appear: required attribute', true, substr_count($out, 'required aria-required="true"') >= 3);
check('auto-appear: before submit button', true, strpos($out, 'bp_hs_extra[lastname]') < strpos($out, 'elementor-field-type-submit'));
check('auto-appear: mapped email not duplicated', false, strpos($out, 'bp_hs_extra[email]') !== false);
check('auto-appear: elementor size class', true, strpos($out, 'elementor-size-md') !== false);
check('auto-appear: consent is a real unchecked checkbox', true, (bool) preg_match('/<input type="checkbox" value="1" name="bp_hs_consent\[_consenttoprocess\]"[^>]*required/', $out));
check('auto-appear: consent never pre-checked', false, strpos($out, 'checked') !== false);

$GLOBALS['language'] = 'pl';
$GLOBALS['scheduled'] = [];
$out_pl = $sync->inject_missing_fields($form_html, new Fake_Widget($settings));
check('PL page: source schema unknown -> unchanged, sync scheduled', [$form_html, 1], [$out_pl, count($GLOBALS['scheduled'])]);
$sync->inject_missing_fields($form_html, new Fake_Widget($settings));
$sync->inject_missing_fields($form_html, new Fake_Widget($settings));
check('PL page: background sync requested once per 10 min, not per view', 1, count($GLOBALS['scheduled']));

$GLOBALS['options']['starter_hubspot_auto_fields'] = 'no';
$GLOBALS['language'] = 'cs';
check('auto-appear off: unchanged', $form_html, $sync->inject_missing_fields($form_html, new Fake_Widget($settings)));
unset($GLOBALS['options']['starter_hubspot_auto_fields']);

$dollar = $settings;
$GLOBALS['options']['starter_hubspot_schema_store'][$cz]['schema']['fields']['company']['label'] = 'Price $1 \\1';
$out = $sync->inject_missing_fields($form_html, new Fake_Widget($dollar));
check('label with $1 / backslash inserted literally', true, strpos($out, 'Price $1 \\1') !== false);

// Coverage for the Mappings tab
$coverage = $sync->coverage_for_settings($settings);
$cs_entry = array_values(array_filter($coverage, function ($c) { return $c['language'] === 'cs'; }))[0];
check('coverage: CS target reports missing required', ['company', 'firstname', 'lastname'], array_column($cs_entry['missing'], 'name'));

// --- submit intake: extra fields are whitelisted against the target schema ---
$post = [
    'bp_hs_extra' => ['company' => ' ACME <b>s.r.o.</b> ', 'firstname' => 'Jan', 'lifecyclestage' => 'customer', 'email' => 'other@example.test'],
    'bp_hs_consent' => ['_consenttoprocess' => '1'],
];
$extra = Starter_HubSpot_Schema_Sync::extra_fields($cz, ['email' => 'jan@example.test'], $post);
check('extra: known fields sanitized', ['company' => 'ACME s.r.o.', 'firstname' => 'Jan'], $extra);
check('extra: consent only when ticked', ['_consentToProcess' => true], Starter_HubSpot_Schema_Sync::extra_consent($cz, [], $post));
check('extra: consent not ticked', [], Starter_HubSpot_Schema_Sync::extra_consent($cz, [], ['bp_hs_consent' => ['_consenttoprocess' => '0']]));
check('extra: consent already mapped is ignored', [], Starter_HubSpot_Schema_Sync::extra_consent($cz, ['_consentToProcess' => 'gdpr'], $post));
$GLOBALS['options']['starter_hubspot_schema_store'][$cz]['schema']['fields']['utm_source'] = ['name' => 'utm_source', 'label' => 'UTM', 'type' => 'single_line_text', 'required' => true, 'hidden' => true, 'options' => []];
$sneaky = ['bp_hs_extra' => ['message' => 'optional field', 'utm_source' => 'bot', 'firstname' => 'Dup', 'company' => ['x'], 'lastname' => 'Novák']];
check('extra: only required visible, alias-aware, no arrays', ['lastname' => 'Novák'], Starter_HubSpot_Schema_Sync::extra_fields($cz, ['first_name' => 'Jan'], $sneaky));
check('extra: unknown target schema -> nothing', [], Starter_HubSpot_Schema_Sync::extra_fields('11111111-1111-4111-8111-111111111111', [], $post));

// --- d) 400 REQUIRED_FIELD -> one retry with placeholders -> repaired ---
reset_world();
$GLOBALS['get_responses'][$cz_url] = hubspot_form_definition(['company' => 'Společnost', 'firstname' => 'Jméno', 'lastname' => 'Příjmení']);
$GLOBALS['posts'] = [
    response(400, ['status' => 'error', 'message' => 'The request is not valid', 'errors' => [
        ['message' => "Error in 'fields.company'. Required field 'company' is missing", 'errorType' => 'REQUIRED_FIELD'],
        ['message' => "Error in 'fields.firstname'. Required field 'firstname' is missing", 'errorType' => 'REQUIRED_FIELD'],
        ['message' => "Error in 'fields.lastname'. Required field 'lastname' is missing", 'errorType' => 'REQUIRED_FIELD'],
    ]]),
    response(200, ['inlineMessage' => 'Thanks']),
];
$result = Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'lead@example.test', 'message' => 'Cena?'], ['page_title' => 'Kontakt CZ', 'language' => 'cs', 'source_form_id' => $pl]);
check('repaired submission returns success', true, $result);
check('exactly two submit calls (one retry)', 2, count($GLOBALS['post_calls']));
check('submit goes to mocked HubSpot endpoint', 'https://api.hsforms.com/submissions/v3/integration/submit/123/' . $cz, $GLOBALS['post_calls'][0]['url']);
$retry_fields = array_column($GLOBALS['post_calls'][1]['payload']['fields'], 'value', 'name');
check('retry keeps real values', 'lead@example.test', $retry_fields['email']);
check('retry adds placeholder only for missing fields', ['company' => '-', 'firstname' => '-', 'lastname' => '-'], array_intersect_key($retry_fields, array_flip(['company', 'firstname', 'lastname'])));
$log = get_option('starter_hubspot_submission_log');
$entry = end($log);
check('log: status repaired', 'repaired', $entry['status']);
check('log: success true', true, $entry['success']);
check('log: repaired fields', ['company', 'firstname', 'lastname'], $entry['repaired_fields']);
check('log: first errors keep errorType and field apart', ['REQUIRED_FIELD', 'company'], [$entry['first_errors'][0]['type'], $entry['first_errors'][0]['field']]);
check('repaired alert raised', true, isset(get_option('starter_hubspot_alerts')['repaired|' . $cz]));
check('repaired alert mail', 1, count($GLOBALS['mails']));
check('repaired mail mentions page', true, strpos($GLOBALS['mails'][0]['body'], 'Kontakt CZ') !== false);

// Retry also rejected -> failed, readable errors, no third call
reset_world();
$GLOBALS['get_responses'][$cz_url] = hubspot_form_definition(['company' => 'Společnost']);
$req = response(400, ['message' => 'The request is not valid', 'errors' => [['name' => 'company', 'message' => 'Required field is missing', 'errorType' => 'REQUIRED_FIELD']]]);
$GLOBALS['posts'] = [$req, $req, response(200, [])];
$result = Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'lead@example.test'], []);
check('retry failure returns error', true, is_wp_error($result));
check('only one retry', 2, count($GLOBALS['post_calls']));
$log = get_option('starter_hubspot_submission_log');
$entry = end($log);
check('readable error string', 'The request is not valid; REQUIRED_FIELD (company): Required field is missing', $entry['error']);
check('log keeps attempted repair', ['company'], $entry['repair_attempted']);
check('failed alert', true, isset(get_option('starter_hubspot_alerts')['failed|' . $cz]));

reset_world();
$GLOBALS['posts'] = [response(400, ['message' => 'Contact lead@example.test is a duplicate', 'errors' => [['message' => 'Contact lead@example.test is a duplicate', 'errorType' => 'SOMETHING_ELSE']]])];
Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'lead@example.test'], []);
check('failed alert keeps e-mail addresses out', false, strpos(get_option('starter_hubspot_alerts')['failed|' . $cz]['error'], 'lead@example.test'));

// Placeholder disabled -> no retry
reset_world();
$GLOBALS['options']['starter_hubspot_repair_placeholder'] = '';
$GLOBALS['posts'] = [$req, response(200, [])];
Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'lead@example.test'], []);
check('empty placeholder: no retry', 1, count($GLOBALS['post_calls']));

// Consent missing -> never filled, consent alert, no retry
reset_world();
$GLOBALS['posts'] = [response(400, ['errors' => [['message' => "Required field 'gdpr_consent' is missing", 'errorType' => 'REQUIRED_FIELD']]]), response(200, [])];
Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'lead@example.test'], []);
check('consent: no retry', 1, count($GLOBALS['post_calls']));
check('consent: dedicated alert', true, isset(get_option('starter_hubspot_alerts')['consent_missing|' . $cz]));

// Visitor typo -> logged, but no admin alert
reset_world();
$GLOBALS['posts'] = [response(400, ['errors' => [['message' => 'Email address is invalid', 'errorType' => 'INVALID_EMAIL', 'name' => 'email']]])];
Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'not-an-email'], []);
check('invalid e-mail: no alert', [], get_option('starter_hubspot_alerts', []));
check('invalid e-mail: logged as error', false, end($GLOBALS['options']['starter_hubspot_submission_log'])['success']);

// Normal success path unchanged
reset_world();
$GLOBALS['posts'] = [response(200, [])];
check('plain success', true, Starter_Addon_HubSpot_Forms::submit_to_hubspot($cz, ['email' => 'lead@example.test'], []));
check('plain success: single call', 1, count($GLOBALS['post_calls']));
check('plain success: no status key', false, isset(end($GLOBALS['options']['starter_hubspot_submission_log'])['status']));

// --- settings save ---
reset_world();
Starter_HubSpot_Schema_Sync::save_settings(['debug_mode' => 1]);
check('save without schema fields keeps defaults', false, isset($GLOBALS['options']['starter_hubspot_auto_fields']));
Starter_HubSpot_Schema_Sync::save_settings(['schema_settings_present' => '1', 'auto_fields' => 0, 'schema_sync_interval' => 'weekly', 'repair_placeholder' => ' Nie podano ', 'notify_enabled' => 1, 'notify_email' => 'bad']);
check('save: auto off (0)', 'no', get_option('starter_hubspot_auto_fields'));
check('save: unknown interval -> hourly', 'hourly', get_option('starter_hubspot_schema_sync_interval'));
check('save: placeholder trimmed', 'Nie podano', get_option('starter_hubspot_repair_placeholder'));
check('save: invalid e-mail -> empty (admin e-mail)', '', get_option('starter_hubspot_notify_email'));


echo "\n{$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
