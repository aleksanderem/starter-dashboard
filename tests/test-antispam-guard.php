<?php
/**
 * Tests for Starter_HubSpot_Antispam_Guard with minimal WordPress stubs
 * (in-memory options/transients, fake HTTP). No WordPress boot, no network.
 *
 * Run: php tests/test-antispam-guard.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['http_calls'] = [];
$GLOBALS['http_response'] = null;

function add_action() {}
function __($text, $domain = '') { return $text; }
function apply_filters($hook, $value) { return $value; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['options'][$name]); return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function wp_salt($scheme) { return 'unit-test-salt-' . $scheme; }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_post($url, $args) {
    $GLOBALS['http_calls'][] = [$url, $args['body']];
    return $GLOBALS['http_response'];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function home_url($path = '') { return 'https://example.com' . $path; }
class WP_Error {}

class Starter_Addon_HubSpot_Forms {
    public static $log = [];
    public static function log_submission($data) { self::$log[] = $data; }
}

require __DIR__ . '/../addons/hubspot-forms/class-language-routing.php';
require __DIR__ . '/../addons/hubspot-forms/class-antispam-guard.php';

class Fake_Record {
    private $settings;
    public function __construct($settings) { $this->settings = $settings; }
    public function get($key) {
        if ($key === 'form_settings') { return $this->settings; }
        if ($key === 'meta') { return ['page_url' => ['value' => 'https://example.com/kontakt/']]; }
        return [];
    }
}
class Fake_Handler {
    public $errors = [];
    public $messages = ['error' => []];
    public function add_error($field, $message = '') { $this->errors[$field] = $message; return $this; }
    public function add_error_message($message) { $this->messages['error'][] = $message; return $this; }
}

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

/**
 * Fresh guard per scenario: verdicts are cached per request inside the singleton.
 */
function fresh_guard() {
    $prop = new ReflectionProperty('Starter_HubSpot_Antispam_Guard', 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
    return Starter_HubSpot_Antispam_Guard::instance();
}
function reset_state(array $options = []) {
    $GLOBALS['options'] = $options;
    $GLOBALS['transients'] = [];
    $GLOBALS['http_calls'] = [];
    $GLOBALS['http_response'] = null;
    Starter_Addon_HubSpot_Forms::$log = [];
    $_POST = ['post_id' => '42', 'form_id' => 'abc123', 'form_fields' => ['email' => 'victim@example.org']];
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.5'];
}

$hs_guid = '9e102cff-cb2e-4f3d-a0df-82bf8812984d';
$hubspot_form = new Fake_Record(['submit_actions' => ['hubspot'], 'hubspot_form_id' => $hs_guid, 'id' => 'abc123', 'form_name' => 'Newsletter']);
$other_form = new Fake_Record(['submit_actions' => ['email'], 'id' => 'zzz']);

// --- 1. Everything off: legitimate submissions pass untouched ---
reset_state();
$guard = fresh_guard();
$h = new Fake_Handler();
$guard->validate($hubspot_form, $h);
check('all off: no errors', [], $h->errors);
check('all off: run() allowed', true, fresh_guard()->allow_submission($hubspot_form, new Fake_Handler()));
check('all off: no markup needed', false, fresh_guard()->needs_form_markup());

// --- 2. Turnstile switched on without keys = feature off + misconfigured notice ---
reset_state(['starter_hubspot_turnstile_enabled' => 'yes']);
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('turnstile without keys does not block', [], $h->errors);
check('turnstile without keys flagged misconfigured', true, Starter_HubSpot_Antispam_Guard::turnstile_misconfigured());
check('turnstile without keys: no HTTP call', 0, count($GLOBALS['http_calls']));

// --- 3. Turnstile on with keys: missing token fails closed, no HTTP call ---
$turnstile_on = [
    'starter_hubspot_turnstile_enabled' => 'yes',
    'starter_hubspot_turnstile_site_key' => '1x00000000000000000000AA',
    'starter_hubspot_turnstile_secret_key' => 'PLACEHOLDER_SECRET_1234',
];
reset_state($turnstile_on);
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('missing token: blocked via add_error', true, isset($h->errors['bp_hubspot_antispam']));
check('missing token: user message', 'Please complete the security check and try again.', $h->errors['bp_hubspot_antispam'] ?? null);
check('missing token: no HTTP call', 0, count($GLOBALS['http_calls']));
check('missing token: logged', 'turnstile', Starter_Addon_HubSpot_Forms::$log[0]['reason'] ?? null);
check('missing token: detail', 'missing-token', Starter_Addon_HubSpot_Forms::$log[0]['detail'] ?? null);

$entry = Starter_Addon_HubSpot_Forms::$log[0];
$serialized = json_encode($entry);
check('log: status rejected', 'rejected', $entry['status']);
check('log: success false', false, $entry['success']);
check('log: no e-mail', false, strpos($serialized, 'victim@example.org') !== false);
check('log: no raw IP', false, strpos($serialized, '203.0.113.5') !== false);
check('log: no fields key', false, array_key_exists('fields', $entry));
check('log: ip hash present', 1, preg_match('/^[a-f0-9]{12}$/', $entry['ip_hash']));
check('log: hubspot form id', $hs_guid, $entry['form_id']);
check('log: elementor form id', 'abc123', $entry['elementor_form_id']);
check('log: allowed keys only', ['detail', 'elementor_form_id', 'form_id', 'form_name', 'ip_hash', 'language', 'post_id', 'reason', 'status', 'success'],
    (function ($e) { $k = array_keys($e); sort($k); return $k; })($entry));

// run() safety net reuses the verdict without re-verifying
$g = fresh_guard();
$g->validate($hubspot_form, new Fake_Handler());
$h2 = new Fake_Handler();
check('run(): rejected verdict reused', false, $g->allow_submission($hubspot_form, $h2));
check('run(): adds error too', true, isset($h2->errors['bp_hubspot_antispam']));

// --- 4. Turnstile token verified server-side with remoteip ---
reset_state($turnstile_on);
$_POST['cf-turnstile-response'] = 'tok-123';
$GLOBALS['http_response'] = ['response' => ['code' => 200], 'body' => '{"success":true}'];
$g = fresh_guard();
$h = new Fake_Handler();
$g->validate($hubspot_form, $h);
check('valid token passes', [], $h->errors);
check('siteverify called once', 1, count($GLOBALS['http_calls']));
check('siteverify body', ['secret' => 'PLACEHOLDER_SECRET_1234', 'response' => 'tok-123', 'remoteip' => '203.0.113.5'], $GLOBALS['http_calls'][0][1]);
check('run(): passes without second siteverify (token single-use)', true, $g->allow_submission($hubspot_form, new Fake_Handler()));
check('still one siteverify call', 1, count($GLOBALS['http_calls']));

// Network error / bad answer fail closed
reset_state($turnstile_on);
$_POST['cf-turnstile-response'] = 'tok-123';
$GLOBALS['http_response'] = new WP_Error();
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('network error fails closed', true, isset($h->errors['bp_hubspot_antispam']));

reset_state($turnstile_on);
$_POST['cf-turnstile-response'] = 'tok-123';
$GLOBALS['http_response'] = ['response' => ['code' => 200], 'body' => '{"success":false,"error-codes":["invalid-input-response"]}'];
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('invalid token fails closed', true, isset($h->errors['bp_hubspot_antispam']));
check('invalid token detail logged', 'invalid-input-response', Starter_Addon_HubSpot_Forms::$log[0]['detail'] ?? null);

// --- 5. Non-HubSpot forms and already-failed validation are untouched ---
reset_state($turnstile_on);
$h = new Fake_Handler();
fresh_guard()->validate($other_form, $h);
check('non-HubSpot form ignored', [], $h->errors);
$h = new Fake_Handler();
$h->errors['email'] = 'Required';
fresh_guard()->validate($hubspot_form, $h);
check('already invalid: guard does not add', ['email' => 'Required'], $h->errors);
check('already invalid: nothing logged', 0, count(Starter_Addon_HubSpot_Forms::$log));

// --- 6. Rate limit: per IP+form and per IP ---
$rl = [
    'starter_hubspot_ratelimit_enabled' => 'yes',
    'starter_hubspot_ratelimit_ip_max' => 5,
    'starter_hubspot_ratelimit_ip_window' => 600,
    'starter_hubspot_ratelimit_form_max' => 2,
    'starter_hubspot_ratelimit_form_window' => 600,
];
reset_state($rl);
$results = [];
for ($i = 0; $i < 3; $i++) {
    $h = new Fake_Handler();
    fresh_guard()->validate($hubspot_form, $h);
    $results[] = empty($h->errors);
}
check('form bucket: 2 allowed then blocked', [true, true, false], $results);
check('rate-limit message', 'Too many submissions. Please wait a few minutes and try again.', $h->errors['bp_hubspot_antispam'] ?? null);
check('rate-limit logged', 'rate-limit', end(Starter_Addon_HubSpot_Forms::$log)['reason']);

// Another IP is unaffected
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('other IP not limited', [], $h->errors);

// Spoofed headers ignored without trusted proxy
$_SERVER = ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8'];
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('spoofed XFF does not reset the limit', true, isset($h->errors['bp_hubspot_antispam']));

// Per-IP bucket across forms (form bucket 0 = off)
reset_state(array_merge($rl, ['starter_hubspot_ratelimit_ip_max' => 2, 'starter_hubspot_ratelimit_form_max' => 0]));
$results = [];
foreach (['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'] as $guid) {
    $h = new Fake_Handler();
    fresh_guard()->validate(new Fake_Record(['submit_actions' => ['hubspot'], 'hubspot_form_id' => $guid, 'id' => 'f']), $h);
    $results[] = empty($h->errors);
}
check('IP bucket across forms: 2 allowed then blocked', [true, true, false], $results);

// Rate limit toggle off
reset_state(array_merge($rl, ['starter_hubspot_ratelimit_enabled' => 'no']));
$results = [];
for ($i = 0; $i < 5; $i++) {
    $h = new Fake_Handler();
    fresh_guard()->validate($hubspot_form, $h);
    $results[] = empty($h->errors);
}
check('rate limit off: never blocks', [true, true, true, true, true], $results);

// Rejection logging is throttled per reason+IP+form
reset_state($rl);
for ($i = 0; $i < 6; $i++) {
    fresh_guard()->validate($hubspot_form, new Fake_Handler());
}
check('flood logs one rejection per minute', 1, count(Starter_Addon_HubSpot_Forms::$log));

// Site-wide cap: many IPs flooding cannot flush the shared log
reset_state($turnstile_on);
for ($i = 1; $i <= 30; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
    fresh_guard()->validate($hubspot_form, new Fake_Handler());
}
check('botnet flood: max 10 rejection log entries per minute', 10, count(Starter_Addon_HubSpot_Forms::$log));

// Cloudflare detected while rate limit counts REMOTE_ADDR
reset_state($rl);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
check('proxy misconfigured warning', true, Starter_HubSpot_Antispam_Guard::proxy_misconfigured());
$GLOBALS['options']['starter_hubspot_trusted_proxy'] = 'yes';
check('no warning with trusted proxy on', false, Starter_HubSpot_Antispam_Guard::proxy_misconfigured());
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
$GLOBALS['options']['starter_hubspot_trusted_proxy'] = 'no';
check('no warning without Cloudflare header', false, Starter_HubSpot_Antispam_Guard::proxy_misconfigured());

// --- 7. Minimum fill time ---
$mt = ['starter_hubspot_mintime_enabled' => 'yes', 'starter_hubspot_mintime_seconds' => 3];
$key = hash_hmac('sha256', 'starter_hubspot_antispam', wp_salt('nonce'));

reset_state($mt);
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('min-time: missing stamp rejected', 'This form has expired. Please reload the page and try again.', $h->errors['bp_hubspot_antispam'] ?? null);
check('min-time: logged as too-fast/missing', ['too-fast', 'missing'], [Starter_Addon_HubSpot_Forms::$log[0]['reason'], Starter_Addon_HubSpot_Forms::$log[0]['detail']]);

reset_state($mt);
$_POST['bp_hs_ts'] = Starter_HubSpot_Antispam::sign_timestamp(time(), $key);
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('min-time: instant submit rejected', 'The form was sent too quickly. Please wait a moment and try again.', $h->errors['bp_hubspot_antispam'] ?? null);

reset_state($mt);
$_POST['bp_hs_ts'] = Starter_HubSpot_Antispam::sign_timestamp(time() - 20, $key);
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('min-time: 20s later passes', [], $h->errors);

reset_state($mt);
$_POST['bp_hs_ts'] = Starter_HubSpot_Antispam::sign_timestamp(time() - 20, 'attacker-key');
$h = new Fake_Handler();
fresh_guard()->validate($hubspot_form, $h);
check('min-time: forged stamp rejected', true, isset($h->errors['bp_hubspot_antispam']));

// --- 8. Settings save ---
reset_state(['starter_hubspot_turnstile_secret_key' => 'OLD_SECRET_VALUE_1234', 'starter_hubspot_ratelimit_enabled' => 'yes']);
Starter_HubSpot_Antispam_Guard::save_settings(['debug_mode' => 1]);
check('save without antispam fields keeps options', 'yes', get_option('starter_hubspot_ratelimit_enabled'));

Starter_HubSpot_Antispam_Guard::save_settings([
    'antispam_settings_present' => '1',
    'turnstile_enabled' => 1,
    'turnstile_site_key' => ' 0x4AAAA<b>bad</b> ',
    'turnstile_secret_key' => '',
    'ratelimit_enabled' => 0,
    'ratelimit_ip_max' => '99999',
    'ratelimit_ip_window' => 'abc',
    'ratelimit_form_max' => '0',
    'ratelimit_form_window' => '300',
    'trusted_proxy' => '1',
    'mintime_enabled' => 'yes',
    'mintime_seconds' => '0',
]);
check('save: dashboard 1 -> yes', 'yes', get_option('starter_hubspot_turnstile_enabled'));
check('save: dashboard 0 -> no', 'no', get_option('starter_hubspot_ratelimit_enabled'));
check('save: trusted proxy', 'yes', get_option('starter_hubspot_trusted_proxy'));
check('save: mintime yes', 'yes', get_option('starter_hubspot_mintime_enabled'));
check('save: site key sanitized', '0x4AAAAbbadb', get_option('starter_hubspot_turnstile_site_key'));
check('save: empty secret keeps old', 'OLD_SECRET_VALUE_1234', get_option('starter_hubspot_turnstile_secret_key'));
check('save: max clamped', 1000, get_option('starter_hubspot_ratelimit_ip_max'));
check('save: garbage window -> default', 600, get_option('starter_hubspot_ratelimit_ip_window'));
check('save: 0 allowed for limit', 0, get_option('starter_hubspot_ratelimit_form_max'));
check('save: window kept', 300, get_option('starter_hubspot_ratelimit_form_window'));
check('save: min seconds clamped to >= 1', 1, get_option('starter_hubspot_mintime_seconds'));

Starter_HubSpot_Antispam_Guard::save_settings(['antispam_settings_present' => '1', 'turnstile_secret_key' => 'NEW_SECRET_abc-123']);
check('save: new secret replaces', 'NEW_SECRET_abc-123', get_option('starter_hubspot_turnstile_secret_key'));
check('save: missing checkbox -> no', 'no', get_option('starter_hubspot_turnstile_enabled'));
Starter_HubSpot_Antispam_Guard::save_settings(['antispam_settings_present' => '1', 'turnstile_secret_clear' => 1]);
check('save: clear secret', false, get_option('starter_hubspot_turnstile_secret_key'));

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
