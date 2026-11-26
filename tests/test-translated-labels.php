<?php
/** Run: php tests/test-translated-labels.php */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/translated-labels/class-labels.php';
$f = 0; $p = 0;
function check($l, $e, $a) { global $f, $p; if ($e === $a) { $p++; return; } $f++; echo "FAIL: $l\n  expected: " . var_export($e, true) . "\n  actual:   " . var_export($a, true) . "\n"; }
$T = 'Starter_Translated_Labels';
$h = '<span class="elementor-heading-title elementor-size-default">Job title</span>';
check('pl', '<span class="elementor-heading-title elementor-size-default">Stanowisko</span>', $T::translate($h, '413546a', 'pl'));
check('en untouched', $h, $T::translate($h, '413546a', 'en'));
check('unknown language untouched', $h, $T::translate($h, '413546a', 'de'));
check('other widget untouched', $h, $T::translate($h, 'zzzzzzz', 'pl'));
check('already translated untouched', '<span>Pozícia</span>', $T::translate('<span>Pozícia</span>', '413546a', 'sk'));
check('longer text untouched', '<span>Job title manager</span>', $T::translate('<span>Job title manager</span>', '413546a', 'pl'));
check('attribute untouched', '<span title="Job title">x</span>', $T::translate('<span title="Job title">x</span>', '413546a', 'pl'));
check('colon label', '<span>Lokalizacja:</span>', $T::translate('<span>Location:</span>', 'ac90ed1', 'pl'));
check('work mode hu', '<span>Munkavégzés módja:</span>', $T::translate('<span>Work mode:</span>', '295619a', 'hu'));
check('empty', '', $T::translate('', '413546a', 'pl'));
check('non-string', null, $T::translate(null, '413546a', 'pl'));
check('custom map', '<b>Hej</b>', $T::translate('<b>Hi</b>', 'a', 'pl', ['a' => ['Hi' => ['pl' => 'Hej']]]));
echo "\n$p passed, $f failed\n"; exit($f ? 1 : 0);
