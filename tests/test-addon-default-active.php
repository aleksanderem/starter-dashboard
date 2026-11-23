<?php
/**
 * Unit tests for default-active addons in Starter_Addon_Loader (options mocked in memory).
 *
 * Run: php tests/test-addon-default-active.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['test_options'] = [];
function add_action() {}
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$name] : $default;
}
function update_option($name, $value) {
    $GLOBALS['test_options'][$name] = $value;
    return true;
}

require __DIR__ . '/../addons/class-addon-loader.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$addons = [
    'og-image'         => ['name' => 'Social Preview'],
    'reading-time'     => ['name' => 'Reading Time'],
    'image-alt-filter' => ['name' => 'Image Alt Filter', 'default_active' => true],
];

// Loader without running the constructor (it would require every addon file).
function make_loader(array $addons) {
    $ref = new ReflectionClass('Starter_Addon_Loader');
    $loader = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('addons');
    $prop->setAccessible(true);
    $prop->setValue($loader, $addons);
    return $loader;
}

// --- resolve_active() (pure) ---
$L = 'Starter_Addon_Loader';
check('nothing saved -> default addon on', ['image-alt-filter'], $L::resolve_active([], [], $addons));
check('option missing (false) -> default addon on', ['image-alt-filter'], $L::resolve_active(false, false, $addons));
check('stored list kept, default appended', ['og-image', 'image-alt-filter'], $L::resolve_active(['og-image'], [], $addons));
check('explicitly disabled -> off', ['og-image'], $L::resolve_active(['og-image'], ['image-alt-filter'], $addons));
check('no duplicate when also stored', ['image-alt-filter', 'og-image'], $L::resolve_active(['image-alt-filter', 'og-image'], [], $addons));
check('regular addons never auto-on', [], $L::resolve_active([], [], ['og-image' => [], 'reading-time' => ['default_active' => false]]));

// --- fresh site: default addon is active, toggle shows it ---
$GLOBALS['test_options'] = [];
$loader = make_loader($addons);
check('fresh: is_addon_active(default)', true, $loader->is_addon_active('image-alt-filter'));
check('fresh: regular addon off', false, $loader->is_addon_active('og-image'));
check('fresh: nothing written by reading', [], $GLOBALS['test_options']);

// --- existing site with saved addons (option already exists, addon is new) ---
$GLOBALS['test_options'] = ['starter_active_addons' => ['og-image', 'reading-time']];
check('existing site: default addon on', true, $loader->is_addon_active('image-alt-filter'));
check('existing site: list', ['og-image', 'reading-time', 'image-alt-filter'], $loader->get_active_addons());

// --- switch off in the panel ---
$loader->deactivate_addon('image-alt-filter');
check('after off: inactive', false, $loader->is_addon_active('image-alt-filter'));
check('after off: disabled option saved', ['image-alt-filter'], $GLOBALS['test_options']['starter_disabled_addons']);
check('after off: other addons untouched', ['og-image', 'reading-time'], $loader->get_active_addons());

// --- switch on again ---
$loader->activate_addon('image-alt-filter');
check('after on: active', true, $loader->is_addon_active('image-alt-filter'));
check('after on: disabled entry removed', [], $GLOBALS['test_options']['starter_disabled_addons']);
check('after on: stored explicitly', ['og-image', 'reading-time', 'image-alt-filter'], $GLOBALS['test_options']['starter_active_addons']);

// --- off again after an explicit on ---
$loader->deactivate_addon('image-alt-filter');
check('off after on: inactive', false, $loader->is_addon_active('image-alt-filter'));
check('off after on: removed from active option', ['og-image', 'reading-time'], $GLOBALS['test_options']['starter_active_addons']);

// --- regular addons behave exactly as before ---
$GLOBALS['test_options'] = ['starter_active_addons' => ['og-image']];
$loader->deactivate_addon('og-image');
check('regular off: active option emptied', [], $GLOBALS['test_options']['starter_active_addons']);
check('regular off: no disabled option written', false, array_key_exists('starter_disabled_addons', $GLOBALS['test_options']));
$loader->activate_addon('reading-time');
check('regular on', ['reading-time'], $GLOBALS['test_options']['starter_active_addons']);
check('regular on: still no disabled option', false, array_key_exists('starter_disabled_addons', $GLOBALS['test_options']));

// --- real registry: only image-alt-filter is default-active, always_load unchanged ---
$src = file_get_contents(__DIR__ . '/../addons/class-addon-loader.php');
check('default_active only on image-alt-filter and front-speed', 2, substr_count($src, "'default_active' => true,"));
check('always_load list unchanged',
    1,
    substr_count($src, "\$always_load = ['elementor-phone-field', 'elementor-styled-checkboxes', 'elementor-csv-options'];"));

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
