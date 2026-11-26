<?php
/**
 * Unit tests for the SEO Structure addon (pure logic, no WordPress).
 *
 * Run: php tests/test-seo-structure.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/seo-structure/class-heading-rules.php';
require __DIR__ . '/../addons/seo-structure/class-404-cleanup.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$H = 'Starter_Heading_Rules';
$rules = require __DIR__ . '/../addons/seo-structure/rules.php';

// --- decide() ---
check('hidden everywhere → plain', 'p', $H::decide('zzzzzzz', 5, 'wp-page', true, $rules));
check('listed widget: promote', 'h2', $H::decide('989afe6', 201897, 'archive', false, $rules));
check('listed widget: plain', 'p', $H::decide('2aa6542', 201897, 'archive', false, $rules));
check('listed widget: h1', 'h1', $H::decide('09d8ae7', 108098, 'archive', false, $rules));
check('unknown widget in normal page → untouched (new sections keep their tag)', null, $H::decide('abcdef1', 107801, 'wp-page', false, $rules));
check('menu template → plain', 'p', $H::decide('abcdef1', 1224, 'uicore-tb', false, $rules));
check('footer type → plain', 'p', $H::decide('abcdef1', 999, 'uicore-tb', false, $rules));
check('listing tile type → plain', 'p', $H::decide('abcdef1', 999, 'loop-item', false, $rules));
check('jet listing tile type → plain', 'p', $H::decide('abcdef1', 999, 'jet-listing-items', false, $rules));
check('popup → plain', 'p', $H::decide('abcdef1', 999, 'popup', false, $rules));
check('listed widget wins over doc type', 'h2', $H::decide('989afe6', 1224, 'uicore-tb', false, $rules));
check('empty widget id, normal doc', null, $H::decide('', 5, 'wp-page', false, $rules));
check('no rules → untouched', null, $H::decide('989afe6', 1, 'wp-page', false, []));

// --- hidden_everywhere() ---
check('all three hidden', true, $H::hidden_everywhere(['hide_desktop' => 'hidden-desktop', 'hide_tablet' => 'hidden-tablet', 'hide_mobile' => 'hidden-mobile']));
check('only mobile hidden', false, $H::hidden_everywhere(['hide_mobile' => 'hidden-mobile']));
check('two of three', false, $H::hidden_everywhere(['hide_desktop' => 'hidden-desktop', 'hide_tablet' => 'hidden-tablet']));
check('no settings', false, $H::hidden_everywhere([]));

// --- retag(): demote ---
$in = '<h3 class="elementor-heading-title elementor-size-default">Zobacz</h3>';
check('h3 → p keeps class, adds look classes and marker',
    '<p class="elementor-heading-title elementor-size-default uicore-title h3" data-mwts-was="h3">Zobacz</p>',
    $H::retag($in, 'p'));
check('h1 → h2', '<h2 class="x uicore-title h1" data-mwts-was="h1">T</h2>', $H::retag('<h1 class="x">T</h1>', 'h2'));
check('same tag untouched', $in, $H::retag($in, 'h3'));
check('no class attribute', '<p class="uicore-title h2" data-mwts-was="h2">T</p>', $H::retag('<h2>T</h2>', 'p'));
check('single-quoted class', '<p class="a uicore-title h4" data-mwts-was="h4">T</p>', $H::retag("<h4 class='a'>T</h4>", 'p'));
check('other attributes kept', '<p id="x" class="a uicore-title h3" title="t" data-mwts-was="h3">T</p>', $H::retag('<h3 id="x" class="a" title="t">T</h3>', 'p'));
check('inner markup kept', '<p class="a uicore-title h3" data-mwts-was="h3"><a href="/x"><span>T</span></a></p>',
    $H::retag('<h3 class="a"><a href="/x"><span>T</span></a></h3>', 'p'));
check('several headings in one widget',
    '<p class="a uicore-title h4" data-mwts-was="h4">1</p><p class="a uicore-title h4" data-mwts-was="h4">2</p>',
    $H::retag('<h4 class="a">1</h4><h4 class="a">2</h4>', 'p'));
check('multi-line heading', "<p class=\"a uicore-title h2\" data-mwts-was=\"h2\">Baza\n  wiedzy</p>", $H::retag("<h2 class=\"a\">Baza\n  wiedzy</h2>", 'p'));
check('brand carousel name: plain tag, no look classes', '<p class="bdt-ep-brand-carousel-name" data-mwts-was="h3">Brand Name</p>',
    $H::retag('<h3 class="bdt-ep-brand-carousel-name">Brand Name</h3>', 'p'));
check('uppercase tags', '<p class="a uicore-title h3" data-mwts-was="h3">T</p>', $H::retag('<H3 class="a">T</H3>', 'p'));
check('non-heading html untouched', '<div><p>x</p><hr></div>', $H::retag('<div><p>x</p><hr></div>', 'p'));
check('<header> is not a heading', '<header class="x">a</header>', $H::retag('<header class="x">a</header>', 'p'));
check('<h1> inside widget wrapper', '<div class="w"><h2 class="t uicore-title h1" data-mwts-was="h1">A</h2></div>', $H::retag('<div class="w"><h1 class="t">A</h1></div>', 'h2'));
check('invalid target ignored', $in, $H::retag($in, 'div'));
check('invalid target ignored (script)', $in, $H::retag($in, 'script'));
check('empty html', '', $H::retag('', 'p'));
check('non-string html', null, $H::retag(null, 'p'));

// --- parity_css() ---
$css = $H::parity_css([['h3', 'h2']]);
check('parity targets heading widget', true, strpos($css, '.elementor-widget-heading h2.elementor-heading-title[data-mwts-was="h3"]') !== false);
check('parity uses original level variables', true, strpos($css, 'font-size:var(--uicore-typography--h3-s)') !== false && strpos($css, 'color:var(--uicore-typography--h3-c)') !== false);
check('parity covers highlighted text', true, strpos($css, 'h2.ui-e--highlighted-text[data-mwts-was="h3"]') !== false);
check('parity: bad pair skipped', '', $H::parity_css([['h9', 'h2'], ['h3', '<script>']]));
check('parity: no braces mismatch', substr_count($css, '{'), substr_count($css, '}'));
check('parity: all configured promotions produce css', true, strlen($H::parity_css($rules['promotions'])) > 300);

// --- hidden H1 / has_h1 ---
check('has_h1 true', true, $H::has_h1('<div><h1 class="a">x</h1></div>'));
check('has_h1 false for h2', false, $H::has_h1('<h2>x</h2><header>'));
check('has_h1 false for empty', false, $H::has_h1(''));
$h1 = $H::hidden_h1('O nas <b>& firma</b>');
check('hidden h1 escapes and strips', true, strpos($h1, '>O nas &amp; firma</h1>') !== false && strpos($h1, '<b>') === false);
check('hidden h1 is visually hidden', true, strpos($h1, 'clip:rect(0,0,0,0)') !== false);
check('hidden h1 empty title → nothing', '', $H::hidden_h1('   '));

// --- rules sanity ---
foreach ($rules['widgets'] as $id => $target) {
    if (!preg_match('/^[0-9a-f]{7}$/', (string) $id)) { check("widget id format: {$id}", true, false); }
    if (!preg_match('/^(p|h[1-6])$/', $target)) { check("target format: {$id}", true, false); }
}
check('rules: only one h1 target among widgets per page type is not checked here, but h1 targets exist', true, in_array('h1', $rules['widgets'], true));
check('rules: promotions are all valid pairs', true, count(array_filter($rules['promotions'], function ($p) { return count($p) === 2; })) === count($rules['promotions']));

// --- 404 clean-up ---
$C = 'Starter_404_Cleanup';
$page = '<head><title>Nie ma</title>'
    . '<meta name="robots" content="noindex, max-snippet:-1" />'
    . '<link rel="canonical" href="https://mwtsolutions.eu/strona-nie-zostala-znaleziona/" />'
    . '<link rel="alternate" hreflang="en" href="https://mwtsolutions.eu/en/" />'
    . '<link rel="alternate" hreflang="x-default" href="https://mwtsolutions.eu/" />'
    . '<meta property="og:url" content="https://mwtsolutions.eu/strona-nie-zostala-znaleziona/" />'
    . '<meta property="og:url" content="https://mwtsolutions.eu/x">'
    . '<link rel="alternate" type="application/rss+xml" title="Feed" href="https://mwtsolutions.eu/feed/" />'
    . '<script type="application/ld+json" class="aioseo-schema">{"@graph":[{"@type":"WebPage","url":"https://mwtsolutions.eu/strona-nie-zostala-znaleziona/"}]}</script>'
    . '<script type="application/ld+json">{"other":1}</script></head><body>x</body>';
$clean = $C::clean($page);
check('404: canonical removed', false, stripos($clean, 'rel="canonical"') !== false);
check('404: hreflang removed', false, stripos($clean, 'hreflang') !== false);
check('404: og:url removed (both)', false, stripos($clean, 'og:url') !== false);
check('404: AIOSEO schema removed', false, stripos($clean, 'aioseo-schema') !== false || stripos($clean, 'strona-nie-zostala') !== false);
check('404: robots noindex kept', true, strpos($clean, 'name="robots" content="noindex') !== false);
check('404: RSS link kept (not an alternate language)', true, strpos($clean, 'application/rss+xml') !== false);
check('404: other JSON-LD kept', true, strpos($clean, '{"other":1}') !== false);
check('404: title kept', true, strpos($clean, '<title>Nie ma</title>') !== false);
check('404: body kept', true, strpos($clean, '<body>x</body>') !== false);
check('404: attribute order swapped (hreflang first)', false, stripos($C::clean('<link hreflang="de" rel="alternate" href="/de/">'), 'hreflang') !== false);
check('404: single quotes', false, stripos($C::clean("<link rel='canonical' href='/x'>"), 'canonical') !== false);
check('404: empty input', '', $C::clean(''));
check('404: non-string passthrough', null, $C::clean(null));

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
