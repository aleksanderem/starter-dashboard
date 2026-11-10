<?php
/**
 * Unit tests for Starter_HubSpot_Antispam (pure logic, no WordPress, no network).
 *
 * Run: php tests/test-antispam.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/hubspot-forms/class-antispam.php';

$failures = 0;
$passes = 0;

function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) {
        $passes++;
        return;
    }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$A = 'Starter_HubSpot_Antispam';

// --- client_ip(): REMOTE_ADDR unless a trusted proxy is configured ---
$spoofed = [
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
    'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 198.51.100.8',
];
check('untrusted: ignores CF-Connecting-IP and XFF', '203.0.113.10', $A::client_ip($spoofed, false));
check('trusted: prefers CF-Connecting-IP', '198.51.100.7', $A::client_ip($spoofed, true));

$xff_only = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.8'];
check('trusted: XFF uses rightmost (proxy-appended) entry', '198.51.100.8', $A::client_ip($xff_only, true));
check('untrusted: XFF ignored', '10.0.0.2', $A::client_ip($xff_only, false));

$garbage = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, <script>'];
check('trusted: garbage headers fall back to REMOTE_ADDR', '10.0.0.2', $A::client_ip($garbage, true));
check('trusted: IP with port is rejected', '10.0.0.2', $A::client_ip(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4:5555'], true));
check('missing REMOTE_ADDR', '', $A::client_ip([], false));
check('invalid REMOTE_ADDR', '', $A::client_ip(['REMOTE_ADDR' => '999.1.1.1'], false));
check('IPv6 normalized', '2001:db8::1', $A::client_ip(['REMOTE_ADDR' => '2001:DB8:0:0:0:0:0:1'], false));
check('overlong value rejected', '', $A::valid_ip(str_repeat('1', 46)));

// --- rate_limit_subject(): IPv6 grouped by /64 ---
check('IPv4 subject is the address', '203.0.113.10', $A::rate_limit_subject('203.0.113.10'));
check('IPv6 subject is the /64', '2001:db8:1:2::/64', $A::rate_limit_subject('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
check('same /64 -> same subject', $A::rate_limit_subject('2001:db8:1:2::1'), $A::rate_limit_subject('2001:db8:1:2::ffff'));
check('invalid subject', '', $A::rate_limit_subject('nope'));

// --- rate_limit_decide(): sliding window ---
$now = 1700000000;
$r = $A::rate_limit_decide([], $now, 3, 600);
check('first hit allowed', true, $r['allowed']);
check('first hit recorded', [$now], $r['hits']);

$r = $A::rate_limit_decide([$now - 10, $now - 5], $now, 3, 600);
check('third hit allowed', true, $r['allowed']);
check('third hit recorded', 3, count($r['hits']));

$r = $A::rate_limit_decide([$now - 30, $now - 20, $now - 10], $now, 3, 600);
check('fourth hit in window blocked', false, $r['allowed']);
check('blocked hit not recorded', 3, count($r['hits']));

$r = $A::rate_limit_decide([$now - 700, $now - 650, $now - 10], $now, 3, 600);
check('old hits expire from window', true, $r['allowed']);
check('expired hits pruned', [$now - 10, $now], $r['hits']);

check('limit 0 disables', true, $A::rate_limit_decide([$now, $now, $now], $now, 0, 600)['allowed']);
check('window 0 disables', true, $A::rate_limit_decide([$now, $now, $now], $now, 1, 0)['allowed']);
check('corrupt stored value treated as empty', true, $A::rate_limit_decide('garbage', $now, 1, 600)['allowed']);
check('non-int entries ignored', true, $A::rate_limit_decide(['x', null, '1700000000'], $now, 1, 600)['allowed']);

// --- HMAC timestamp ---
$key = 'test-key-from-wp-salt';
$token = $A::sign_timestamp($now - 10, $key);
check('valid token after 10s', 'ok', $A::verify_timestamp($token, $key, $now, 3, 21600));
check('too fast (1s)', 'too-fast', $A::verify_timestamp($A::sign_timestamp($now - 1, $key), $key, $now, 3, 21600));
check('exactly at threshold is ok', 'ok', $A::verify_timestamp($A::sign_timestamp($now - 3, $key), $key, $now, 3, 21600));
check('expired token', 'expired', $A::verify_timestamp($A::sign_timestamp($now - 21601, $key), $key, $now, 3, 21600));
check('missing token', 'missing', $A::verify_timestamp('', $key, $now, 3, 21600));
check('wrong key = forged', 'invalid', $A::verify_timestamp($token, 'other-key', $now, 3, 21600));
$tampered = preg_replace('/^v1\.\d+\./', 'v1.' . ($now - 3600) . '.', $token);
check('tampered timestamp = forged', 'invalid', $A::verify_timestamp($tampered, $key, $now, 3, 21600));
check('garbage token', 'invalid', $A::verify_timestamp('hello', $key, $now, 3, 21600));
check('future timestamp rejected', 'invalid', $A::verify_timestamp($A::sign_timestamp($now + 600, $key), $key, $now, 3, 21600));
check('min 0 accepts immediate submit', 'ok', $A::verify_timestamp($A::sign_timestamp($now, $key), $key, $now, 0, 21600));

// --- Turnstile: fail closed ---
$calls = 0;
$never_called = function ($url, $body) use (&$calls) { $calls++; return ['code' => 200, 'body' => '{"success":true}']; };
check('empty token fails closed', ['ok' => false, 'error' => 'missing-token'], $A::turnstile_verify('', 'secret', '1.2.3.4', $never_called));
check('whitespace token fails closed', ['ok' => false, 'error' => 'missing-token'], $A::turnstile_verify("  \n", 'secret', '1.2.3.4', $never_called));
check('overlong token fails closed', ['ok' => false, 'error' => 'invalid-token'], $A::turnstile_verify(str_repeat('a', 2049), 'secret', '1.2.3.4', $never_called));
check('missing secret fails closed', ['ok' => false, 'error' => 'not-configured'], $A::turnstile_verify('tok', '', '1.2.3.4', $never_called));
check('no HTTP call for empty token/overlong/no secret', 0, $calls);

$captured = null;
$ok_http = function ($url, $body) use (&$captured) { $captured = [$url, $body]; return ['code' => 200, 'body' => '{"success":true,"hostname":"example.com"}']; };
check('success response passes', ['ok' => true, 'error' => ''], $A::turnstile_verify('tok', 'sec', '1.2.3.4', $ok_http));
check('siteverify URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $captured[0]);
check('siteverify body', ['secret' => 'sec', 'response' => 'tok', 'remoteip' => '1.2.3.4'], $captured[1]);
$A::turnstile_verify('tok', 'sec', '', $ok_http);
check('remoteip omitted when unknown', false, array_key_exists('remoteip', $captured[1]));

check('network error fails closed', false, $A::turnstile_verify('tok', 'sec', '', function () { return null; })['ok']);
check('network error code', 'network-error', $A::turnstile_evaluate(null)['error']);
check('HTTP 500 fails closed', ['ok' => false, 'error' => 'http-500'], $A::turnstile_evaluate(['code' => 500, 'body' => '{"success":true}']));
check('non-JSON fails closed', ['ok' => false, 'error' => 'bad-response'], $A::turnstile_evaluate(['code' => 200, 'body' => '<html>']));
check('missing success key fails closed', false, $A::turnstile_evaluate(['code' => 200, 'body' => '{}'])['ok']);
check('success "true" string fails closed', false, $A::turnstile_evaluate(['code' => 200, 'body' => '{"success":"true"}'])['ok']);
check('success false with codes', ['ok' => false, 'error' => 'invalid-input-response,timeout-or-duplicate'],
    $A::turnstile_evaluate(['code' => 200, 'body' => '{"success":false,"error-codes":["invalid-input-response","timeout-or-duplicate"]}']));
check('success false without codes', ['ok' => false, 'error' => 'rejected'], $A::turnstile_evaluate(['code' => 200, 'body' => '{"success":false}']));

// --- sanitizers / helpers ---
check('key sanitize strips junk', '0x4AAAAAAA_abc-DEF', $A::sanitize_key(" 0x4AAAAAAA_abc-DEF<>\"' ;/ "));
check('key sanitize caps length', 256, strlen($A::sanitize_key(str_repeat('a', 300))));
check('int in range', 5, $A::sanitize_int('5', 3, 0, 100));
check('int clamped high', 100, $A::sanitize_int('5000', 3, 0, 100));
check('int clamped low', 0, $A::sanitize_int('-5', 3, 0, 100));
check('int garbage -> default', 3, $A::sanitize_int('abc', 3, 0, 100));
check('int empty -> default', 3, $A::sanitize_int('', 3, 0, 100));
check('ip hash is 12 hex chars', 1, preg_match('/^[a-f0-9]{12}$/', $A::ip_hash('1.2.3.4', 'k')));
check('ip hash does not contain IP', false, strpos($A::ip_hash('1.2.3.4', 'k'), '1.2.3.4') !== false);
check('ip hash stable', $A::ip_hash('1.2.3.4', 'k'), $A::ip_hash('1.2.3.4', 'k'));
check('ip hash keyed', false, $A::ip_hash('1.2.3.4', 'k') === $A::ip_hash('1.2.3.4', 'other'));
check('ip hash empty ip', '', $A::ip_hash('', 'k'));
check('mask shows last 4 only', '••••••••WXYZ', $A::mask_secret('0x4AAAAAAAsecretWXYZ'));
check('mask short secret shows nothing', '••••••••', $A::mask_secret('short'));
check('mask empty', '', $A::mask_secret(''));

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
