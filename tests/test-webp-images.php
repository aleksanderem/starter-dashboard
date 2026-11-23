<?php
/**
 * Unit tests for Starter_Webp_Images (pure logic, no WordPress).
 *
 * Run: php tests/test-webp-images.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/webp-images/class-webp-images.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$W = 'Starter_Webp_Images';
$base = 'https://mwt.eu/wp-content/uploads';
$all = function () { return true; };
$none = function () { return false; };
$rw = function ($html, $exists = null) use ($W, $base, $all) {
    return $W::rewrite_html($html, $base, $exists ?: $all);
};

// --- src ---
$r = $rw('<p><img src="https://mwt.eu/wp-content/uploads/2025/07/foo.png" alt="x"></p>');
check('png src rewritten', '<p><img src="https://mwt.eu/wp-content/uploads/2025/07/foo.png.webp" alt="x"></p>', $r['html']);
check('nothing missing when webp exists', [], $r['missing']);
$r = $rw("<img src='https://mwt.eu/wp-content/uploads/a.JPG'>");
check('upper-case extension, single quotes', "<img src='https://mwt.eu/wp-content/uploads/a.JPG.webp'>", $r['html']);
$r = $rw('<img src=https://mwt.eu/wp-content/uploads/a.jpeg width=10>');
check('unquoted value', '<img src=https://mwt.eu/wp-content/uploads/a.jpeg.webp width=10>', $r['html']);
$r = $rw('<IMG SRC="https://mwt.eu/wp-content/uploads/a.Jpeg">');
check('upper-case tag and attribute', '<IMG SRC="https://mwt.eu/wp-content/uploads/a.Jpeg.webp">', $r['html']);

// --- srcset with descriptors ---
$srcset = '<img srcset="https://mwt.eu/wp-content/uploads/a-300x200.png 300w, https://mwt.eu/wp-content/uploads/a.png 1024w,https://cdn.other.com/b.png 2048w">';
$r = $rw($srcset);
check('srcset candidates keep descriptors',
    '<img srcset="https://mwt.eu/wp-content/uploads/a-300x200.png.webp 300w, https://mwt.eu/wp-content/uploads/a.png.webp 1024w,https://cdn.other.com/b.png 2048w">',
    $r['html']);
$r = $rw('<img srcset="https://mwt.eu/wp-content/uploads/a.png 1x,  https://mwt.eu/wp-content/uploads/a@2x.png 2x">');
check('density descriptors and extra spaces',
    '<img srcset="https://mwt.eu/wp-content/uploads/a.png.webp 1x,  https://mwt.eu/wp-content/uploads/a@2x.png.webp 2x">', $r['html']);
$r = $rw('<img srcset="https://mwt.eu/wp-content/uploads/a.png">');
check('srcset with single candidate, no descriptor', '<img srcset="https://mwt.eu/wp-content/uploads/a.png.webp">', $r['html']);
$only_big = function ($url) { return strpos($url, '-300x') === false; };
$r = $rw($srcset, $only_big);
check('srcset: only candidates with an existing webp',
    '<img srcset="https://mwt.eu/wp-content/uploads/a-300x200.png 300w, https://mwt.eu/wp-content/uploads/a.png.webp 1024w,https://cdn.other.com/b.png 2048w">',
    $r['html']);
check('srcset: missing candidate reported', ['https://mwt.eu/wp-content/uploads/a-300x200.png'], $r['missing']);

// --- lazy-load attributes and <source> ---
$lazy = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="https://mwt.eu/wp-content/uploads/a.png" data-srcset="https://mwt.eu/wp-content/uploads/a.png 1x" data-lazy-src="https://mwt.eu/wp-content/uploads/b.jpg">';
check('data-src, data-srcset, data-lazy-src rewritten, data: src kept',
    '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="https://mwt.eu/wp-content/uploads/a.png.webp" data-srcset="https://mwt.eu/wp-content/uploads/a.png.webp 1x" data-lazy-src="https://mwt.eu/wp-content/uploads/b.jpg.webp">',
    $rw($lazy)['html']);
check('<source srcset> rewritten',
    '<picture><source srcset="https://mwt.eu/wp-content/uploads/a.png.webp 800w" media="(min-width:800px)"><img src="https://mwt.eu/wp-content/uploads/a.png.webp"></picture>',
    $rw('<picture><source srcset="https://mwt.eu/wp-content/uploads/a.png 800w" media="(min-width:800px)"><img src="https://mwt.eu/wp-content/uploads/a.png"></picture>')['html']);
$other_attr = '<img src="https://mwt.eu/wp-content/uploads/a.png.webp" data-full="https://mwt.eu/wp-content/uploads/a.png" href="https://mwt.eu/wp-content/uploads/a.png">';
check('other attributes untouched', $other_attr, $rw($other_attr)['html']);
$link = '<a href="https://mwt.eu/wp-content/uploads/a.png">x</a><div style="background:url(https://mwt.eu/wp-content/uploads/a.png)"></div>';
check('non-img tags untouched', $link, $rw($link)['html']);
$imagetag = '<image src="https://mwt.eu/wp-content/uploads/a.png"><sourcex srcset="https://mwt.eu/wp-content/uploads/a.png">';
check('<image>/<sourcex> are not img/source', $imagetag, $rw($imagetag)['html']);

// --- URLs that do not qualify ---
$skip = [
    'query string'         => '<img src="https://mwt.eu/wp-content/uploads/a.png?ver=2">',
    'fragment'             => '<img src="https://mwt.eu/wp-content/uploads/a.png#x">',
    'outside uploads'      => '<img src="https://mwt.eu/wp-content/themes/t/a.png">',
    'other domain'         => '<img src="https://cdn.other.com/wp-content/uploads/a.png">',
    'similar prefix'       => '<img src="https://mwt.eu/wp-content/uploads-old/a.png">',
    'already webp'         => '<img src="https://mwt.eu/wp-content/uploads/a.png.webp">',
    'plain webp'           => '<img src="https://mwt.eu/wp-content/uploads/a.webp">',
    'svg'                  => '<img src="https://mwt.eu/wp-content/uploads/a.svg">',
    'gif'                  => '<img src="https://mwt.eu/wp-content/uploads/a.gif">',
    'png not at the end'   => '<img src="https://mwt.eu/wp-content/uploads/a.png/b">',
    'parent directory'     => '<img src="https://mwt.eu/wp-content/uploads/../wp-config.png">',
    'entity in url'        => '<img src="https://mwt.eu/wp-content/uploads/a&amp;b.png">',
];
foreach ($skip as $label => $html) {
    $r = $rw($html);
    check("skip: {$label}", $html, $r['html']);
    check("skip: {$label} not queued", [], $r['missing']);
}

// --- protected blocks ---
$protected = '<!-- <img src="https://mwt.eu/wp-content/uploads/a.png"> -->'
    . '<script>var s = \'<img src="https://mwt.eu/wp-content/uploads/a.png">\';</script>'
    . '<style>/* <img src="https://mwt.eu/wp-content/uploads/a.png"> */</style>'
    . '<noscript><img src="https://mwt.eu/wp-content/uploads/a.png"></noscript>'
    . '<SCRIPT type="text/template"><img src="https://mwt.eu/wp-content/uploads/a.png"></SCRIPT>';
$checked = [];
$r = $W::rewrite_html($protected, $base, function ($url) use (&$checked) { $checked[] = $url; return true; });
check('comments/script/style/noscript byte-identical', $protected, $r['html']);
check('protected blocks never checked on disk', [], $checked);
$mixed = '<!-- x --><img src="https://mwt.eu/wp-content/uploads/a.png"><script>1</script>';
check('img after protected blocks rewritten', '<!-- x --><img src="https://mwt.eu/wp-content/uploads/a.png.webp"><script>1</script>', $rw($mixed)['html']);
$unclosed = '<img src="https://mwt.eu/wp-content/uploads/a.png"><!-- <img src="https://mwt.eu/wp-content/uploads/b.png">';
check('unclosed comment protects the rest', '<img src="https://mwt.eu/wp-content/uploads/a.png.webp"><!-- <img src="https://mwt.eu/wp-content/uploads/b.png">', $rw($unclosed)['html']);

// --- unchanged HTML is the same string ---
$plain = "<!doctype html>\n<html><body><p>Brak obrazków \xC5\xBC</p><img src=\"/logo.svg\"></body></html>";
check('no candidates -> identical string', $plain, $rw($plain)['html']);
$nowebp = '<img src="https://mwt.eu/wp-content/uploads/a.png" srcset="https://mwt.eu/wp-content/uploads/a.png 1x, https://mwt.eu/wp-content/uploads/b.jpg 2x">';
$r = $rw($nowebp, $none);
check('no webp on disk -> identical string', $nowebp, $r['html']);
check('missing list unique, in page order', ['https://mwt.eu/wp-content/uploads/a.png', 'https://mwt.eu/wp-content/uploads/b.jpg'], $r['missing']);
$calls = 0;
$W::rewrite_html($nowebp, $base, function () use (&$calls) { $calls++; return false; });
check('each URL checked once per page', 2, $calls);
check('trailing slash in base url', '<img src="https://mwt.eu/wp-content/uploads/a.png.webp">',
    $W::rewrite_html('<img src="https://mwt.eu/wp-content/uploads/a.png">', $base . '/', $all)['html']);
check('empty base url -> no change', '<img src="/a.png">', $W::rewrite_html('<img src="/a.png">', '', $all)['html']);

// --- large page (pcre.backtrack_limit) ---
$big = '<!-- ' . str_repeat('- comment -', 30000) . ' -->'
    . '<script>' . str_repeat('if (a < b) { x = "<img src=1>"; }', 10000) . '</script>'
    . str_repeat('<div class="x"><img src="https://mwt.eu/wp-content/uploads/a.png" alt="y"></div>', 3000)
    . '<p>' . str_repeat('lorem ipsum ', 20000) . '</p>';
check('test page is over 1 MB', true, strlen($big) > 1048576);
$r = $rw($big);
check('large page: no PCRE error', PREG_NO_ERROR, preg_last_error());
check('large page: every img rewritten', 3000, substr_count($r['html'], 'a.png.webp"'));
check('large page: rest identical', $big, str_replace('a.png.webp"', 'a.png"', $r['html']));

// --- url_to_relative ---
check('relative path', '2025/07/foo.png', $W::url_to_relative('https://mwt.eu/wp-content/uploads/2025/07/foo.png', $base));
check('relative path decoded', '2025/07/zdjęcie 1.png', $W::url_to_relative('https://mwt.eu/wp-content/uploads/2025/07/zdj%C4%99cie%201.png', $base));
check('relative: outside uploads', null, $W::url_to_relative('https://mwt.eu/wp-content/themes/a.png', $base));
check('relative: encoded parent dir rejected', null, $W::url_to_relative('https://mwt.eu/wp-content/uploads/%2e%2e/a.png', $base));
check('relative: encoded NUL rejected', null, $W::url_to_relative('https://mwt.eu/wp-content/uploads/a%00.png', $base));
check('relative: backslash rejected', null, $W::url_to_relative('https://mwt.eu/wp-content/uploads/a%5C..%5Cb.png', $base));

// --- queue ---
check('queue: append new', ['a.png', 'b.png'], $W::queue_add(['a.png'], ['b.png']));
check('queue: no duplicates', ['a.png', 'b.png'], $W::queue_add(['a.png'], ['a.png', 'b.png', 'b.png']));
check('queue: skipped files not queued', ['a.png'], $W::queue_add(['a.png'], ['c.png'], ['c.png']));
check('queue: max size', 200, count($W::queue_add([], array_map(function ($i) { return "f{$i}.png"; }, range(1, 250)))));
$full = array_map(function ($i) { return "f{$i}.png"; }, range(1, 200));
check('queue: full queue unchanged', $full, $W::queue_add($full, ['new.png']));
check('queue: custom max', ['a.png', 'b.png'], $W::queue_add([], ['a.png', 'b.png', 'c.png'], [], 2));
check('queue: invalid stored value', ['a.png'], $W::queue_add('garbage', ['a.png']));
check('queue: original array not modified', ['a.png'], (function () use ($W) { $q = ['a.png']; $W::queue_add($q, ['b.png']); return $q; })());
check('batch size 10', 10, $W::BATCH_SIZE);

// --- resolve_source (real files in a temp dir) ---
$tmp = sys_get_temp_dir() . '/mwts-webp-test-' . getmypid();
@mkdir($tmp . '/uploads/2025/07', 0777, true);
@mkdir($tmp . '/private', 0777, true);
file_put_contents($tmp . '/uploads/2025/07/foo.png', 'x');
file_put_contents($tmp . '/uploads/2025/07/foo.gif', 'x');
file_put_contents($tmp . '/private/secret.png', 'x');
@symlink($tmp . '/private', $tmp . '/uploads/link');
$up = $tmp . '/uploads';
check('resolve: file in uploads', realpath($up . '/2025/07/foo.png'), $W::resolve_source($up, '2025/07/foo.png'));
check('resolve: missing file', null, $W::resolve_source($up, '2025/07/nope.png'));
check('resolve: wrong extension', null, $W::resolve_source($up, '2025/07/foo.gif'));
check('resolve: parent directory', null, $W::resolve_source($up, '../private/secret.png'));
check('resolve: symlink out of uploads', null, $W::resolve_source($up, 'link/secret.png'));
check('resolve: directory', null, $W::resolve_source($up, '2025'));
check('resolve: empty', null, $W::resolve_source($up, ''));

// --- convert_file with a fake image editor ---
class Fake_Editor {
    public $calls = [];
    public $width;
    public $out_bytes;
    public $fail_save = false;
    public function __construct($width, $out_bytes) { $this->width = $width; $this->out_bytes = $out_bytes; }
    public function get_size() { return ['width' => $this->width, 'height' => 1000]; }
    public function resize($w, $h, $crop = false) { $this->calls[] = ['resize', $w, $h, $crop]; return true; }
    public function set_quality($q) { $this->calls[] = ['quality', $q]; return true; }
    public function save($path, $mime) {
        $this->calls[] = ['save', basename($path), $mime];
        if ($this->fail_save) { return new stdClass(); } // WP_Error-like
        file_put_contents($path, str_repeat('w', $this->out_bytes));
        return ['path' => $path, 'mime-type' => $mime];
    }
}
$src = $up . '/2025/07/big.png';
file_put_contents($src, str_repeat('p', 50000));

$ed = new Fake_Editor(2560, 20000);
check('convert: converted', 'converted', $W::convert_file($src, function () use ($ed) { return $ed; }));
check('convert: webp next to original', 20000, @filesize($src . '.webp'));
check('convert: resize to 1920, keep proportions, quality 82, webp mime', [
    ['resize', 1920, null, false], ['quality', 82], ['save', 'big.png.webp', 'image/webp'],
], $ed->calls);
check('convert: existing webp -> exists, editor not used', 'exists', $W::convert_file($src, function () { throw new Exception('must not run'); }));
unlink($src . '.webp');

$ed = new Fake_Editor(800, 20000);
$W::convert_file($src, function () use ($ed) { return $ed; });
check('convert: narrow image not resized', 'quality', $ed->calls[0][0]);
unlink($src . '.webp');

$ed = new Fake_Editor(800, 60000);
check('convert: webp not smaller -> larger', 'larger', $W::convert_file($src, function () use ($ed) { return $ed; }));
check('convert: larger webp deleted', false, file_exists($src . '.webp'));

$small = $up . '/2025/07/small.jpg';
file_put_contents($small, str_repeat('s', 30 * 1024 - 1));
check('convert: under 30 KB -> small', 'small', $W::convert_file($small, function () { throw new Exception('must not run'); }));
file_put_contents($small, str_repeat('s', 30 * 1024));
clearstatcache(); // PHP caches filesize() for a rewritten file
$ed = new Fake_Editor(800, 100);
check('convert: exactly 30 KB is converted', 'converted', $W::convert_file($small, function () use ($ed) { return $ed; }));

check('convert: editor error object', 'error', $W::convert_file($src, function () { return new stdClass(); }));
check('convert: editor exception', 'error', $W::convert_file($src, function () { throw new RuntimeException('boom'); }));
$ed = new Fake_Editor(800, 100);
$ed->fail_save = true;
check('convert: save error', 'error', $W::convert_file($src, function () use ($ed) { return $ed; }));
check('convert: no webp left after error', false, file_exists($src . '.webp'));

// cleanup
foreach (['2025/07/foo.png', '2025/07/foo.gif', '2025/07/big.png', '2025/07/small.jpg', '2025/07/small.jpg.webp'] as $f) { @unlink($up . '/' . $f); }
@unlink($up . '/link');
@unlink($tmp . '/private/secret.png');
@rmdir($up . '/2025/07'); @rmdir($up . '/2025'); @rmdir($up); @rmdir($tmp . '/private'); @rmdir($tmp);

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
