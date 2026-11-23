<?php
/**
 * Unit tests for Starter_Front_Speed (pure logic, no WordPress).
 *
 * Run: php tests/test-front-speed.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/front-speed/class-front-speed.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$F = 'Starter_Front_Speed';

// --- modes ---
check('off, no flag', false, $F::is_enabled('off', false, false, false));
check('off, with ?mwtperf=1', false, $F::is_enabled('off', true, false, false));
check('test, no flag', false, $F::is_enabled('test', false, false, false));
check('test, with ?mwtperf=1', true, $F::is_enabled('test', true, false, false));
check('on, no flag', true, $F::is_enabled('on', false, false, false));
check('on, with flag', true, $F::is_enabled('on', true, false, false));
check('logged in (on)', false, $F::is_enabled('on', false, true, false));
check('logged in (test + flag)', false, $F::is_enabled('test', true, true, false));
check('excluded request (on)', false, $F::is_enabled('on', true, false, true));
check('unknown mode -> test', 'test', $F::normalize_mode('fast'));
check('empty option -> test', 'test', $F::normalize_mode(false));
check('mode case-insensitive', 'on', $F::normalize_mode(' ON '));
check('unknown mode behaves like test', false, $F::is_enabled('banana', false, false, false));

// --- sync set (what stays synchronous) ---
$scripts = [
    'jquery-core'          => ['deps' => [], 'after' => false],
    'jquery-migrate'       => ['deps' => [], 'after' => false],
    'jquery'               => ['deps' => ['jquery-core', 'jquery-migrate'], 'after' => false],
    'wp-hooks'             => ['deps' => [], 'after' => false],
    'wp-i18n'              => ['deps' => ['wp-hooks'], 'after' => true],
    'swiper'               => ['deps' => [], 'after' => false],
    'elementor-frontend'   => ['deps' => ['jquery', 'swiper'], 'after' => false],
    'intl-lib'             => ['deps' => [], 'after' => false],
    'intl-tel-input'       => ['deps' => ['jquery', 'intl-lib'], 'after' => true],
    'helper-base'          => ['deps' => [], 'after' => false],
    'ear-licensing-helper' => ['deps' => ['helper-base'], 'after' => true],
    'cmplz-cookiebanner'   => ['deps' => ['cmplz-base'], 'after' => true],
    'cmplz-base'           => ['deps' => [], 'after' => false],
    'wpml-cookie-consent'  => ['deps' => [], 'after' => false],
    'consent-lib'          => ['deps' => [], 'after' => false],
    'wp-consent-api'       => ['deps' => ['consent-lib'], 'after' => false],
    'plugin-slider'        => ['deps' => ['jquery'], 'after' => false],
];
$sync = $F::sync_handles($scripts, $F::JS_EXCLUDE);
$is_sync = function ($h) use ($sync) { return isset($sync[$h]); };
check('jquery-core sync', true, $is_sync('jquery-core'));
check('jquery-migrate sync', true, $is_sync('jquery-migrate'));
check('wp-i18n sync', true, $is_sync('wp-i18n'));
check('wp-hooks sync (excluded + dep of wp-i18n)', true, $is_sync('wp-hooks'));
check('handle with inline after sync', true, $is_sync('ear-licensing-helper'));
check('dependency of inline-after handle sync', true, $is_sync('helper-base'));
check('dependency of excluded handle sync', true, $is_sync('consent-lib'));
check('transitive dependency of sync handle', true, $is_sync('intl-lib'));
check('cmplz-* prefix sync', true, $is_sync('cmplz-cookiebanner') && $is_sync('cmplz-base'));
check('wpml-cookie* prefix sync', true, $is_sync('wpml-cookie-consent'));
check('plain plugin script deferrable', false, $is_sync('plugin-slider'));
check('script depending on jquery deferrable', false, $is_sync('elementor-frontend'));
check('dep of deferrable script deferrable', false, $is_sync('swiper'));
$sync2 = $F::sync_handles($scripts, array_merge($F::JS_EXCLUDE, ['plugin-slider']));
check('exclude filter adds handle', true, isset($sync2['plugin-slider']));

// --- defer_tag ---
$tag = "<script src=\"https://mwt.eu/wp-content/plugins/x/slider.js?ver=1\" id=\"plugin-slider-js\"></script>\n";
check('plain script gets defer', "<script src=\"https://mwt.eu/wp-content/plugins/x/slider.js?ver=1\" id=\"plugin-slider-js\" defer></script>\n", $F::defer_tag($tag, 'plugin-slider', $sync));
$jq = "<script src='/wp-includes/js/jquery/jquery.min.js' id='jquery-core-js'></script>\n";
check('jquery-core untouched', $jq, $F::defer_tag($jq, 'jquery-core', $sync));
$after = "<script src='/a/intl.js' id='intl-tel-input-js'></script>\n<script id='intl-tel-input-js-after'>\nwindow.intl.init();\n</script>\n";
check('inline after handle untouched', $after, $F::defer_tag($after, 'intl-tel-input', $sync));
$dep = "<script src='/a/lib.js' id='intl-lib-js'></script>\n";
check('dependency of sync script untouched', $dep, $F::defer_tag($dep, 'intl-lib', $sync));
$async = '<script async src="/a/x.js" id="x-js"></script>';
check('already async untouched', $async, $F::defer_tag($async, 'plugin-slider', $sync));
$defer = '<script defer src="/a/x.js" id="x-js"></script>';
check('already defer untouched', $defer, $F::defer_tag($defer, 'plugin-slider', $sync));
$deferv = '<script src="/a/x.js" defer="defer"></script>';
check('defer="defer" untouched', $deferv, $F::defer_tag($deferv, 'plugin-slider', $sync));
$module = '<script type="module" src="/a/x.js" id="x-js"></script>';
check('type=module untouched', $module, $F::defer_tag($module, 'plugin-slider', $sync));
$inline_only = "<script id='plugin-slider-js-extra'>var cfg = {\"a\":1};</script>\n";
check('inline-only entry untouched', $inline_only, $F::defer_tag($inline_only, 'plugin-slider', $sync));
$before = "<script id='plugin-slider-js-before'>\nvar cfg = 1; document.write('<script src=\"/b.js\"></script>');\n</script>\n<script src='/a/x.js' id='plugin-slider-js'></script>\n";
check('inline before kept, only src tag deferred',
    "<script id='plugin-slider-js-before'>\nvar cfg = 1; document.write('<script src=\"/b.js\"></script>');\n</script>\n<script src='/a/x.js' id='plugin-slider-js' defer></script>\n",
    $F::defer_tag($before, 'plugin-slider', $sync));
check('src word in attribute value only', '<script data-x="src=1">x()</script>', $F::defer_tag('<script data-x="src=1">x()</script>', 'plugin-slider', $sync));

// --- async_style_tag ---
$css = "<link rel='stylesheet' id='swiper-css' href='https://mwt.eu/wp-content/plugins/e/swiper.min.css?ver=8' media='all' />\n";
check('listed style async',
    "<link rel='stylesheet' id='swiper-css' href='https://mwt.eu/wp-content/plugins/e/swiper.min.css?ver=8' media=\"print\" onload=\"this.media='all';this.onload=null\" /><noscript><link rel='stylesheet' id='swiper-css' href='https://mwt.eu/wp-content/plugins/e/swiper.min.css?ver=8' media='all' /></noscript>\n",
    $F::async_style_tag($css, 'swiper', $F::CSS_ASYNC));
$css2 = '<link rel="stylesheet" id="lenis-css" href="/l.css">';
check('listed style without media attr',
    '<link rel="stylesheet" id="lenis-css" href="/l.css" media="print" onload="this.media=\'all\';this.onload=null"><noscript><link rel="stylesheet" id="lenis-css" href="/l.css"></noscript>',
    $F::async_style_tag($css2, 'lenis', $F::CSS_ASYNC));
$css3 = "<link rel='stylesheet' id='cmplz-general-css' href='/c.css' media='screen' />";
check('original media restored on load', true, strpos($F::async_style_tag($css3, 'cmplz-general', $F::CSS_ASYNC), "this.media='screen'") !== false);
$other = "<link rel='stylesheet' id='ep-styles-css' href='/ep.css' media='all' />\n";
check('style outside list untouched', $other, $F::async_style_tag($other, 'ep-styles', $F::CSS_ASYNC));
check('elementor-frontend untouched', $other, $F::async_style_tag($other, 'elementor-frontend', $F::CSS_ASYNC));
check('protected even if added by filter', $other, $F::async_style_tag($other, 'uicore-theme', array_merge($F::CSS_ASYNC, ['uicore-theme'])));
check('protected *themify*', $other, $F::async_style_tag($other, 'remix-themify-icons', ['remix-themify-icons']));
$print = "<link rel='stylesheet' id='swiper-css' href='/s.css' media='print' />";
check('print stylesheet untouched', $print, $F::async_style_tag($print, 'swiper', $F::CSS_ASYNC));
$done = $F::async_style_tag($css, 'swiper', $F::CSS_ASYNC);
check('second pass does not double-wrap', $done, $F::async_style_tag($done, 'swiper', $F::CSS_ASYNC));
check('filter can add handle', true, strpos($F::async_style_tag($other, 'my-extra', ['my-extra']), 'media="print"') !== false);

// --- dashicons ---
$deps = ['admin-bar' => ['dashicons'], 'my-menu-icons' => ['dashicons'], 'theme' => ['theme-base'], 'theme-base' => [], 'nested' => ['my-menu-icons']];
check('only dashicons + admin-bar queued -> dequeue', true, $F::can_dequeue_dashicons(['dashicons', 'admin-bar', 'theme'], $deps));
check('queued style needs dashicons -> keep', false, $F::can_dequeue_dashicons(['dashicons', 'my-menu-icons'], $deps));
check('transitive need -> keep', false, $F::can_dequeue_dashicons(['dashicons', 'nested'], $deps));

// --- wildcard matching ---
check('matches exact', true, $F::matches('jquery', ['jquery']));
check('prefix wildcard', true, $F::matches('cmplz-cookiebanner', ['cmplz-*']));
check('no partial match without *', false, $F::matches('jquery-ui', ['jquery']));

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
