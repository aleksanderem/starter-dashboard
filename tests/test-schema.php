<?php
/**
 * Unit tests for Starter_HubSpot_Schema (pure logic, mocked HubSpot payloads).
 *
 * Run: php tests/test-schema.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/hubspot-forms/class-schema.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$S = 'Starter_HubSpot_Schema';

// Mocked get_form_fields() output for the CZ target form (36a68b67-...)
$cz_form = [
    'fields' => [
        ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
        ['name' => 'firstname', 'label' => 'Jméno', 'type' => 'single_line_text', 'required' => true],
        ['name' => 'lastname', 'label' => 'Příjmení', 'type' => 'single_line_text', 'required' => true],
        ['name' => 'company', 'label' => 'Společnost', 'type' => 'single_line_text', 'required' => true],
        ['name' => 'message', 'label' => 'Zpráva', 'type' => 'multi_line_text', 'required' => false],
        ['name' => 'country', 'label' => 'Země', 'type' => 'dropdown', 'required' => false, 'options' => [['label' => 'Česko', 'value' => 'CZ'], ['label' => 'Slovensko', 'value' => 'SK']]],
        ['name' => 'utm', 'label' => 'UTM', 'type' => 'single_line_text', 'required' => true, 'hidden' => true],
    ],
    'consent' => [
        'type' => 'explicit_consent_to_process',
        'consentToProcess' => ['label' => 'Souhlasím se zpracováním', 'required' => true],
        'communications' => [['subscriptionTypeId' => 123, 'label' => 'Newsletter', 'required' => false]],
    ],
];
$cz = $S::normalize($cz_form);

// --- normalize ---
check('fields keyed + sorted by name', ['company', 'country', 'email', 'firstname', 'lastname', 'message', 'utm'], array_keys($cz['fields']));
check('required flag', true, $cz['fields']['company']['required']);
check('hidden flag', true, $cz['fields']['utm']['hidden']);
check('options kept', ['CZ', 'SK'], array_column($cz['fields']['country']['options'], 'value'));
check('consent process required', true, $cz['consent']['process_required']);
check('consent communications', [['id' => 123, 'label' => 'Newsletter', 'required' => false]], $cz['consent']['communications']);
check('normalize tolerates garbage', ['fields' => [], 'consent' => ['type' => 'none', 'process_label' => '', 'process_required' => false, 'communications' => []]], $S::normalize(['fields' => ['x', ['label' => 'no name']]]));

// --- hash / diff ---
$cz_relabeled = $cz_form;
$cz_relabeled['fields'][1]['label'] = 'Křestní jméno';
check('label change keeps hash', $S::hash($cz), $S::hash($S::normalize($cz_relabeled)));

$old_form = $cz_form;
$old_form['fields'][3]['required'] = false;   // company optional before
unset($old_form['fields'][2]);                // lastname did not exist
$old_form['fields'][5]['options'][] = ['label' => 'Polska', 'value' => 'PL'];
$old_form['fields'][4]['type'] = 'single_line_text';
$old = $S::normalize($old_form);
check('hash differs after change', false, $S::hash($old) === $S::hash($cz));

$diff = $S::diff($old, $cz);
check('diff required added', ['company', 'lastname'], (function ($a) { sort($a); return $a; })($diff['required_added']));
check('diff fields added', ['lastname'], $diff['fields_added']);
check('diff type changed', ['message' => 'single_line_text -> multi_line_text'], $diff['type_changed']);
check('diff options changed', ['country'], $diff['options_changed']);
check('diff consent unchanged', false, $diff['consent_changed']);
check('has changes', true, $S::has_changes($diff));
check('no changes against itself', false, $S::has_changes($S::diff($cz, $cz)));

$diff_back = $S::diff($cz, $old);
check('diff required removed (field deleted / made optional)', ['company', 'lastname'], (function ($a) { sort($a); return $a; })($diff_back['required_removed']));
check('diff fields removed', ['lastname'], $diff_back['fields_removed']);

$consent_changed = $cz_form;
$consent_changed['consent']['communications'][0]['required'] = true;
check('consent change detected', true, $S::diff($cz, $S::normalize($consent_changed))['consent_changed']);

// --- coverage: Elementor form mapped for the PL source (email + message only) ---
$elementor_settings = [
    'form_fields' => [
        ['custom_id' => 'email', 'field_type' => 'email'],
        ['custom_id' => 'message', 'field_type' => 'textarea'],
        ['custom_id' => 'first_name', 'field_type' => 'text'],
    ],
    'hubspot_field_mapping_json' => json_encode([
        ['elementor' => 'email', 'hubspot' => 'email'],
        ['elementor' => 'message', 'hubspot' => 'message'],
        ['elementor' => 'ghost', 'hubspot' => 'company'], // Elementor field no longer exists
    ]),
];
$covered = $S::covered_fields($elementor_settings);
check('covered from JSON mapping, ignores deleted Elementor field', ['email', 'message'], $covered);

$missing = $S::missing_required($cz, $covered);
check('CZ target misses company, firstname, lastname (hidden utm skipped)', ['company', 'firstname', 'lastname'], array_column($missing, 'name'));

$auto = $S::covered_fields(['form_fields' => [['custom_id' => 'first_name'], ['custom_id' => 'email'], ['_id' => 'abc']]]);
check('no mapping: auto-map by id with aliases', ['firstname', 'email', 'abc'], $auto);

$legacy = $S::covered_fields(['form_fields' => [['custom_id' => 'co']], 'hubspot_fields_map' => [['local_id' => 'co', 'remote_id' => 'company']]]);
check('legacy repeater mapping', ['company'], $legacy);

check('missing consent: process unmapped', ['_consentToProcess' => 'Souhlasím se zpracováním'], $S::missing_consent($cz, []));
check('missing consent: process mapped', [], $S::missing_consent($cz, ['_consentToProcess' => 'gdpr']));

// --- render spec ---
check('text field spec', ['name' => 'company', 'label' => 'Společnost', 'type' => 'text', 'options' => [], 'required' => true], $S::render_spec($cz['fields']['company']));
check('dropdown -> select', 'select', $S::render_spec($cz['fields']['country'])['type']);
check('textarea', 'textarea', $S::render_spec($cz['fields']['message'])['type']);
check('file upload not rendered', null, $S::render_spec(['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'options' => []]));
check('select without options not rendered', null, $S::render_spec(['name' => 'x', 'label' => 'X', 'type' => 'dropdown', 'options' => []]));

// --- HubSpot 400 REQUIRED_FIELD (mocked response body) ---
$body_400 = [
    'status' => 'error',
    'message' => 'The request is not valid',
    'errors' => [
        ['message' => "Error in 'fields.company'. Required field 'company' is missing", 'errorType' => 'REQUIRED_FIELD'],
        ['message' => "Error in 'fields.firstname'. Required field 'firstname' is missing", 'errorType' => 'REQUIRED_FIELD'],
        ['name' => 'lastname', 'message' => 'Required field is missing', 'errorType' => 'REQUIRED_FIELD'],
    ],
];
$errors = $S::parse_submit_errors($body_400);
check('errorType kept separate from field name', ['REQUIRED_FIELD', 'REQUIRED_FIELD', 'REQUIRED_FIELD'], array_column($errors, 'type'));
check('field names from message or name key', ['company', 'firstname', 'lastname'], array_column($errors, 'field'));
check('parse garbage body', [], $S::parse_submit_errors('nope'));
check('string error kept', [['type' => '', 'field' => '', 'message' => 'oops']], $S::parse_submit_errors(['errors' => ['oops']]));

$plan = $S::repair_plan($errors, $cz, ['email', 'message'], '-');
check('repair: retry allowed', true, $plan['retry']);
check('repair: placeholders only for missing fields', ['company' => '-', 'firstname' => '-', 'lastname' => '-'], $plan['fields']);

$with_consent = array_merge($errors, [['type' => 'REQUIRED_FIELD', 'field' => 'gdpr_consent', 'message' => '']]);
$plan = $S::repair_plan($with_consent, $cz, [], '-');
check('repair: consent never filled -> no retry', false, $plan['retry']);
check('repair: consent reported', 'consent', $plan['unrepairable']['gdpr_consent']);

$plan = $S::repair_plan([['type' => 'REQUIRED_FIELD', 'field' => 'email', 'message' => '']], $cz, [], '-');
check('repair: email never filled', ['retry' => false, 'fields' => [], 'unrepairable' => ['email' => 'type-email']], $plan);

$plan = $S::repair_plan([['type' => 'REQUIRED_FIELD', 'field' => 'country', 'message' => '']], $cz, [], '-');
check('repair: dropdown not filled', 'type-dropdown', $plan['unrepairable']['country']);

$plan = $S::repair_plan([['type' => 'INVALID_EMAIL', 'field' => 'email', 'message' => '']], $cz, [], '-');
check('repair: other error types -> no retry', false, $plan['retry']);

$plan = $S::repair_plan([['type' => 'REQUIRED_FIELD', 'field' => 'company', 'message' => '']], [], [], 'Nie podano');
check('repair: unknown schema text-ish field allowed', ['company' => 'Nie podano'], $plan['fields']);
check('repair: field not in a known schema gets no placeholder', 'unknown-field', $S::repair_plan([['type' => 'REQUIRED_FIELD', 'field' => 'mystery', 'message' => '']], $cz, [], '-')['unrepairable']['mystery']);
check('repair: empty placeholder disables', false, $S::repair_plan($errors, $cz, [], '  ')['retry']);
check('repair: field already sent is not overwritten', 'already-sent', $S::repair_plan([['type' => 'REQUIRED_FIELD', 'field' => 'company', 'message' => '']], $cz, ['company'], '-')['unrepairable']['company']);

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
