<?php
/**
 * Unit tests for Starter_HubSpot_Language_Routing (pure logic, no WordPress).
 *
 * Run: php tests/test-language-routing.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/hubspot-forms/class-language-routing.php';

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

$pl = '9e102cff-cb2e-4f3d-a0df-82bf8812984d';
$cz = '36a68b67-1111-4222-8333-944444444444';
$sk = '93c6db57-1111-4222-8333-944444444444';
$nl = '098c0a91-a88a-43c6-b489-acf5e78b55a2';

$routing = [
    $pl => ['cs' => $cz, 'sk' => $sk],
];

// resolve()
check('routes CZ to CZ form', $cz, Starter_HubSpot_Language_Routing::resolve($pl, 'cs', $routing));
check('routes SK to SK form', $sk, Starter_HubSpot_Language_Routing::resolve($pl, 'sk', $routing));
check('unmapped language keeps source', $pl, Starter_HubSpot_Language_Routing::resolve($pl, 'hu', $routing));
check('null language keeps source', $pl, Starter_HubSpot_Language_Routing::resolve($pl, null, $routing));
check('unmapped form keeps source', $nl, Starter_HubSpot_Language_Routing::resolve($nl, 'cs', $routing));
check('empty routing keeps source', $pl, Starter_HubSpot_Language_Routing::resolve($pl, 'cs', []));
check('uppercase source guid still routes', $cz, Starter_HubSpot_Language_Routing::resolve(strtoupper($pl), 'cs', $routing));
check('uppercase language still routes', $sk, Starter_HubSpot_Language_Routing::resolve($pl, 'SK', $routing));

// sanitize()
$raw = [
    $pl => ['cs' => $cz, 'sk' => '', 'xx' => $sk, 'hu' => 'not-a-guid', 'en' => $pl],
    'bad key' => ['cs' => $cz],
    $nl => 'not-an-array',
    strtoupper($nl) => ['cs' => strtoupper($cz)],
];
$clean = Starter_HubSpot_Language_Routing::sanitize($raw, ['cs', 'sk', 'hu', 'en']);
check('sanitize keeps valid pair', $cz, $clean[$pl]['cs'] ?? null);
check('sanitize drops empty target', false, isset($clean[$pl]['sk']));
check('sanitize drops unknown language', false, isset($clean[$pl]['xx']));
check('sanitize drops invalid guid target', false, isset($clean[$pl]['hu']));
check('sanitize drops identity mapping', false, isset($clean[$pl]['en']));
check('sanitize drops invalid source key', false, isset($clean['bad key']));
check('sanitize lowercases guids', $cz, $clean[$nl]['cs'] ?? null);
check('sanitize result keys', [$pl, $nl], array_keys($clean));
check('sanitize non-array input', [], Starter_HubSpot_Language_Routing::sanitize('nope', ['cs']));

// language_from_url()
$codes = ['pl', 'en', 'cs', 'sk', 'hu'];
check('url /cs/ page', 'cs', Starter_HubSpot_Language_Routing::language_from_url('https://mwtsolutions.eu/cs/kontakt/', $codes, 'pl'));
check('url /sk root', 'sk', Starter_HubSpot_Language_Routing::language_from_url('https://mwtsolutions.eu/sk', $codes, 'pl'));
check('url default language', 'pl', Starter_HubSpot_Language_Routing::language_from_url('https://mwtsolutions.eu/kontakt/', $codes, 'pl'));
check('url with query', 'hu', Starter_HubSpot_Language_Routing::language_from_url('https://mwtsolutions.eu/hu/kapcsolat/?utm=x', $codes, 'pl'));
check('prefix must be whole segment', 'pl', Starter_HubSpot_Language_Routing::language_from_url('https://mwtsolutions.eu/csr-policy/', $codes, 'pl'));
check('lang param', 'sk', Starter_HubSpot_Language_Routing::language_from_url('https://example.com/?page_id=5&lang=sk', $codes, 'pl'));
check('subdirectory install', 'cs', Starter_HubSpot_Language_Routing::language_from_url('https://example.com/site/cs/x/', $codes, 'pl', '/site'));
check('empty url', null, Starter_HubSpot_Language_Routing::language_from_url('', $codes, 'pl'));

// is_truthy() - dashboard sends checkboxes as 1/0
check('truthy "1"', true, Starter_HubSpot_Language_Routing::is_truthy('1'));
check('truthy 1', true, Starter_HubSpot_Language_Routing::is_truthy(1));
check('truthy yes', true, Starter_HubSpot_Language_Routing::is_truthy('yes'));
check('falsy "0"', false, Starter_HubSpot_Language_Routing::is_truthy('0'));
check('falsy no', false, Starter_HubSpot_Language_Routing::is_truthy('no'));
check('falsy null', false, Starter_HubSpot_Language_Routing::is_truthy(null));

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
