<?php
/**
 * Unit tests for Starter_Cmplz_Fast_Banner (pure logic, no WordPress).
 *
 * Run: php tests/test-cmplz-fast-banner.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/cmplz-fast-banner/class-fast-banner.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$F = 'Starter_Cmplz_Fast_Banner';
$js = $F::inline_js();

// --- shape of the generated script ---
check('handle', 'cmplz-cookiebanner', $F::HANDLE);
check('is a string', true, is_string($js) && $js !== '');
foreach (['consenttype', 'region', 'version', 'forceEnableStats', 'banner_version'] as $key) {
    check("contains key {$key}", true, strpos($js, $key . ':') !== false);
}
check('guards missing complianz object', true, strpos($js, "typeof complianz==='undefined'") !== false);
check('only fills empty storage', true, strpos($js, "sessionStorage.getItem('cmplz_user_data')") !== false);
check('writes cmplz_user_data', true, strpos($js, "sessionStorage.setItem('cmplz_user_data'") !== false);
check('uses JSON.stringify (valid JSON)', true, strpos($js, 'JSON.stringify(') !== false);
check('wrapped in try/catch', true, strpos($js, 'try{') !== false && strpos($js, 'catch(e){}') !== false);
check('forceEnableStats false', true, strpos($js, 'forceEnableStats:false') !== false);
check('no script tag (wp_add_inline_script adds it)', false, stripos($js, '<script') !== false);
check('nothing external: no URL', 0, preg_match('~https?:|//[a-z]~i', $js));
check('nothing external: no PHP input', true, $js === $F::inline_js());
check('single line', false, strpos($js, "\n") !== false);

// --- behaviour, executed in Node when available ---
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "SKIP: node not found, runtime checks skipped\n";
} else {
    $harness = <<<'JS'
const store = {};
global.sessionStorage = {
  getItem: k => (k in store ? store[k] : null),
  setItem: (k, v) => { store[k] = String(v); },
};
const scenario = process.argv[2];
if (scenario !== 'no-complianz') {
  global.complianz = {consenttype: 'optin', region: 'eu', version: '7.4.2', banner_version: '41', other: 'x'};
}
if (scenario === 'prefilled') { store.cmplz_user_data = '{"region":"us"}'; }
if (scenario === 'throws') { global.sessionStorage = { getItem() { throw new Error('denied'); } }; }
eval(process.argv[1]);
process.stdout.write(JSON.stringify({stored: store.cmplz_user_data === undefined ? null : store.cmplz_user_data}));
JS;
    $run = function ($scenario) use ($node, $harness, $js) {
        $cmd = escapeshellarg($node) . ' -e ' . escapeshellarg($harness) . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($scenario) . ' 2>&1';
        return json_decode((string) shell_exec($cmd), true);
    };

    $fresh = $run('fresh');
    $data = json_decode((string) ($fresh['stored'] ?? ''), true);
    check('node: stored value is valid JSON', true, is_array($data));
    check('node: stored object', [
        'consenttype' => 'optin', 'region' => 'eu', 'version' => '7.4.2',
        'forceEnableStats' => false, 'banner_version' => '41',
    ], $data);

    $pre = $run('prefilled');
    check('node: existing storage untouched', '{"region":"us"}', $pre['stored'] ?? 'missing');

    $none = $run('no-complianz');
    check('node: no complianz object -> nothing stored', true, is_array($none) && $none['stored'] === null);

    $throws = $run('throws');
    check('node: sessionStorage error swallowed', true, is_array($throws) && $throws['stored'] === null);
}

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
