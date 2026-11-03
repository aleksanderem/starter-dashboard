<?php
// Standalone regression test using the demo form's checkbox settings. No network or WordPress boot.
define('ABSPATH', __DIR__ . '/');
function add_action() {}
function __($text, $domain = '') { return $text; }
function apply_filters($hook, $value) { return $value; }
function wp_strip_all_tags($text) { return strip_tags($text); }
require __DIR__ . '/../addons/elementor-styled-checkboxes/addon.php';

class Checkbox_Record {
    private $fields;
    private $settings;
    public function __construct($fields, $settings) { $this->fields = $fields; $this->settings = $settings; }
    public function get($key) { return $key === 'fields' ? $this->fields : ['form_fields' => $this->settings]; }
}
class Checkbox_Handler {
    public $errors = [];
    public $messages = [];
    public function add_error($id, $message) { $this->errors[$id] = $message; }
    public function add_error_message($message) { $this->messages[] = $message; }
}
$passes = 0;
$failures = 0;
function check($label, $expected, $actual) {
    global $passes, $failures;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: $label\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n";
}
$addon = Starter_Addon_Elementor_Styled_Checkboxes::instance();
$settings = json_decode(file_get_contents(__DIR__ . '/fixtures/demo-checkboxes.json'), true);
$marketing = 'consent_other_communication';
$processing = 'constent_store_personal_data';
$fields = [$marketing => ['value' => 'yes'], $processing => ['value' => 'yes']];

$handler = new Checkbox_Handler();
$addon->validate_required_checkboxes(new Checkbox_Record($fields, $settings), $handler);
check('both consents checked pass', [], $handler->errors);
foreach ([$marketing, $processing] as $missing) {
    $submitted = $fields;
    unset($submitted[$missing]);
    $handler = new Checkbox_Handler();
    $addon->validate_required_checkboxes(new Checkbox_Record($submitted, $settings), $handler);
    $option = $missing === $marketing ? $settings[0]['field_options'] : $settings[1]['field_options'];
    check('missing agreement identified by visible text', explode('|', $option)[0] . ': This field is required', $handler->errors[$missing] ?? null);
    check('only missing agreement is rejected', [$missing], array_keys($handler->errors));
    check('form-level message also identifies agreement', [$handler->errors[$missing]], $handler->messages);
}
$handler = new Checkbox_Handler();
$arrayFields = [$marketing => ['value' => ['yes']], $processing => ['value' => ['yes']]];
$addon->validate_required_checkboxes(new Checkbox_Record($arrayFields, $settings), $handler);
check('array checkbox values pass', [], $handler->errors);
$settings[0]['field_required_checkbox'] = '';
$handler = new Checkbox_Handler();
$addon->validate_required_checkboxes(new Checkbox_Record([$processing => ['value' => 'yes']], $settings), $handler);
check('optional checkbox is not made required', [], $handler->errors);
$settings[0]['field_required_checkbox'] = 'yes';
$settings[0]['field_label'] = 'Marketing consent';
$handler = new Checkbox_Handler();
$addon->validate_required_checkboxes(new Checkbox_Record([], $settings), $handler);
check('explicit label preserved', 'Marketing consent: This field is required', $handler->errors[$marketing]);

$scan = new ReflectionMethod($addon, 'scan_elements_for_required_checkboxes');
$scan->setAccessible(true);
$required = [];
$elements = [['id' => 'demo', 'widgetType' => 'form', 'settings' => ['form_fields' => $settings]]];
$scan->invokeArgs($addon, [$elements, &$required]);
check('frontend metadata also identifies unlabeled agreement', explode('|', $settings[1]['field_options'])[0], $required['form-demo'][1]['label']);
echo "$passes passed, $failures failed\n";
exit($failures ? 1 : 0);
