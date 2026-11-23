<?php
/**
 * Unit tests for Starter_Image_Alt_Text (pure logic, no WordPress).
 *
 * Run: php tests/test-image-alt-filter.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../addons/image-alt-filter/class-alt-text.php';

$failures = 0;
$passes = 0;
function check($label, $expected, $actual) {
    global $failures, $passes;
    if ($expected === $actual) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$A = 'Starter_Image_Alt_Text';
$no_lookup = function () { throw new Exception('lookup must not be called'); };
$site = 'MWT Solutions';

// --- clean(): examples from the spec ---
check('prefix + counter', 'Webinar EC Max', $A::clean('PL26_webinar_EC_Max-01'));
check('plain title untouched', 'MWT logo', $A::clean('MWT logo'));
check('dashes to spaces, en dash kept', 'Backup – jak go zrobic w Recovery Manager Plus', $A::clean('Backup-–-jak-go-zrobic-w-Recovery-Manager-Plus'));
check('junk word + counters = nothing', '', $A::clean('Group-8-1'));
check('EN_ prefix', 'Product sheet', $A::clean('EN_product_sheet'));
check('CS26_ prefix', 'Konference praha', $A::clean('CS26_konference_praha'));
check('entity &#8211;', 'Backup – jak go zrobic', $A::clean('Backup-&#8211;-jak-go-zrobic'));
check('double-encoded entity', 'AD – Entra', $A::clean('AD &amp;#8211; Entra'));
check('slashes kept', 'Hybrid AD/Entra ID – konfiguracja', $A::clean('Hybrid AD/Entra ID &#8211; konfiguracja'));
check('dimensions removed', 'Hero banner', $A::clean('hero-banner-1200x1200'));
check('IMG_1234 only = nothing', '', $A::clean('IMG_1234'));
check('IMG_1234 with text', 'Biuro Warszawa', $A::clean('IMG_1234-biuro-Warszawa'));
check('hash removed', 'Recovery manager', $A::clean('recovery-manager-5f3a9c2e8b1d4a7f'));
check('untitled only', '', $A::clean('untitled-design-2'));
check('junk word next to real content kept', 'Shape of security', $A::clean('shape-of-security'));
check('real version number kept', 'Windows 11', $A::clean('Windows 11'));
check('first letter upper (multibyte)', 'Łódź spotkanie', $A::clean('łódź_spotkanie'));
check('invalid UTF-8 does not crash', true, is_string($A::clean("caf\xE9-menu")));
$long = $A::clean(str_repeat('Active Directory audit ', 10));
check('max 125 chars', true, mb_strlen($long) <= 125);
$src_long = str_repeat('Active Directory audit ', 10);
check('cut at word boundary', ' ', substr($src_long, strlen($long), 1));
check('cut is prefix of source', 0, strpos($src_long, $long));

// --- filename() ---
check('filename with size suffix', 'PL26_webinar_EC_Max-01', $A::filename('https://mwt.eu/wp-content/uploads/2026/03/PL26_webinar_EC_Max-01-300x200.jpg'));
check('filename scaled + query', 'office-team', $A::filename('/wp-content/uploads/office-team-scaled.webp?ver=2'));
check('filename url-encoded', 'zespół biuro', $A::filename('/u/zesp%C3%B3%C5%82%20biuro.png'));
check('data: src ignored', '', $A::filename('data:image/gif;base64,R0lGOD'));

// --- resolve_alt() ---
check('meta alt wins', 'Zespół MWT', $A::resolve_alt(['alt' => ' Zespół MWT ', 'title' => 'IMG_1'], 'x.jpg', $site));
check('title when no meta alt', 'Webinar EC Max', $A::resolve_alt(['alt' => '', 'title' => 'PL26_webinar_EC_Max-01'], 'x.jpg', $site));
check('junk title falls to filename', 'Recovery Manager Plus', $A::resolve_alt(['alt' => '', 'title' => 'Group-8-1'], '/u/Recovery-Manager-Plus.png', $site));
check('nothing -> site name', 'MWT Solutions', $A::resolve_alt(null, '/u/Group-8-1.png', 'MWT Solutions'));
check('site name decoded', 'A & B', $A::resolve_alt(null, '/u/IMG_0001.jpg', 'A &amp; B'));

// --- filter_html(): tags ---
$f = function ($html, $lookup = null) use ($A, $no_lookup, $site) { return $A::filter_html($html, $lookup ?: $no_lookup, $site); };

$keep = '<p>x</p><img src="/u/foo-bar.jpg" alt="Istniejący opis" class="a">';
check('non-empty alt untouched', $keep, $f($keep));
check("non-empty alt in single quotes untouched", "<img alt='Opis' src='/u/a.jpg'>", $f("<img alt='Opis' src='/u/a.jpg'>"));
check('empty alt filled', '<img src="/u/PL26_webinar_EC_Max-01-300x200.jpg" alt="Webinar EC Max">', $f('<img src="/u/PL26_webinar_EC_Max-01-300x200.jpg" alt="">'));
check('missing alt added', '<img alt="Office team" src="/u/office-team.jpg" />', $f('<img src="/u/office-team.jpg" />'));
check('bare alt filled', '<img alt="Office team" src="/u/office-team.jpg">', $f('<img alt src="/u/office-team.jpg">'));
check('whitespace alt filled', '<img alt="Office team" src="/u/office-team.jpg">', $f('<img alt="  " src="/u/office-team.jpg">'));
check('upper-case tag', '<IMG alt="Office team" SRC="/u/office-team.jpg">', $f('<IMG SRC="/u/office-team.jpg">'));
check('junk filename -> site name', '<img alt="MWT Solutions" src="/u/Group-8-1.png">', $f('<img src="/u/Group-8-1.png">'));
check('lazy-load data-src used', '<img alt="Office team" src="data:image/gif;base64,AA" data-src="/u/office-team.jpg">', $f('<img src="data:image/gif;base64,AA" data-src="/u/office-team.jpg">'));
check('alt escaped', '<img alt="Q&amp;A &quot;live&quot;" src="/u/x.jpg">', $A::filter_html('<img src="/u/x.jpg">', $no_lookup, '', function () { return 'Q&A "live"'; }));
check('alt inside other attribute not mistaken', '<img alt="Office team" title="alt=&quot;x&quot;" src="/u/office-team.jpg">', $f('<img title="alt=&quot;x&quot;" src="/u/office-team.jpg">'));
check('data-alt is not alt', '<img alt="Office team" data-alt="x" src="/u/office-team.jpg">', $f('<img data-alt="x" src="/u/office-team.jpg">'));
check('> inside attribute value', '<img alt="Office team" data-x="a>b" src="/u/office-team.jpg">', $f('<img data-x="a>b" src="/u/office-team.jpg">'));

// --- decorative ---
check('aria-hidden keeps alt=""', '<img aria-hidden="true" alt="" src="/u/office-team.jpg">', $f('<img aria-hidden="true" alt="" src="/u/office-team.jpg">'));
check('aria-hidden adds alt=""', '<img alt="" aria-hidden="true" src="/u/office-team.jpg">', $f('<img aria-hidden="true" src="/u/office-team.jpg">'));
check('role=presentation', '<img alt="" role="presentation" src="/u/x.jpg">', $f('<img role="presentation" src="/u/x.jpg">'));
check('data-mwts-decorative', '<img alt="" data-mwts-decorative src="/u/office-team.jpg">', $f('<img data-mwts-decorative src="/u/office-team.jpg">'));
check('svg in icon container', '<div class="elementor-icon-box-icon"><span><img alt="" src="/u/check.svg"></span></div>', $f('<div class="elementor-icon-box-icon"><span><img src="/u/check.svg"></span></div>'));
check('svg outside icon container gets alt', '<div class="hero"><img alt="Company map" src="/u/company-map.svg"></div>', $f('<div class="hero"><img src="/u/company-map.svg"></div>'));
check('jpg in icon container is not decorative', '<div class="icon-box"><img alt="Office team" src="/u/office-team.jpg"></div>', $f('<div class="icon-box"><img src="/u/office-team.jpg"></div>'));

// --- protected blocks ---
$script = '<script>var t = "<img src=\"/u/a.jpg\">"; if (a < b) {}</script>';
check('img in <script> untouched', $script, $f($script));
$json = '<script type="application/ld+json">{"html":"<img src=/u/a.jpg>"}</script>';
check('img in JSON script untouched', $json, $f($json));
$mixed = '<style>.a{background:url(x)}</style><!-- <img src="/u/c.jpg"> --><noscript><img src="/u/d.jpg"></noscript><img src="/u/office-team.jpg">';
check('style/comment/noscript untouched, real img filled',
    '<style>.a{background:url(x)}</style><!-- <img src="/u/c.jpg"> --><noscript><img src="/u/d.jpg"></noscript><img alt="Office team" src="/u/office-team.jpg">',
    $f($mixed));
check('</script> inside string variant', '<script>a="</scr"+"ipt><img src=x>"</script><img alt="B" src="/u/b.jpg">', $f('<script>a="</scr"+"ipt><img src=x>"</script><img src="/u/b.jpg">'));
check('unclosed comment protects rest', '<!-- <img src="/u/a.jpg">', $f('<!-- <img src="/u/a.jpg">'));
check('no <img> -> same string', '<p>hello</p>', $f('<p>hello</p>'));

// --- attachments: one lookup for all IDs, meta alt > title ---
$calls = [];
$lookup = function (array $ids) use (&$calls) {
    $calls[] = $ids;
    return [
        12 => ['alt' => 'Zespół MWT na konferencji', 'title' => 'IMG_1'],
        34 => ['alt' => '', 'title' => 'PL26_webinar_EC_Max-01'],
        56 => ['alt' => '', 'title' => 'Group-8-1'],
    ];
};
$page = '<img class="wp-image-12" src="/u/a.jpg" alt=""><img class="size-full wp-image-34" src="/u/b.jpg">'
      . '<img class="wp-image-56" src="/u/Recovery-Manager-Plus-1024x768.png"><img class="wp-image-12" src="/u/a.jpg">'
      . '<img class="wp-image-99" src="/u/office-team.jpg"><img class="wp-image-77" alt="Ręczny" src="/u/z.jpg">';
$expected = '<img class="wp-image-12" src="/u/a.jpg" alt="Zespół MWT na konferencji"><img alt="Webinar EC Max" class="size-full wp-image-34" src="/u/b.jpg">'
      . '<img alt="Recovery Manager Plus" class="wp-image-56" src="/u/Recovery-Manager-Plus-1024x768.png"><img alt="Zespół MWT na konferencji" class="wp-image-12" src="/u/a.jpg">'
      . '<img alt="Office team" class="wp-image-99" src="/u/office-team.jpg"><img class="wp-image-77" alt="Ręczny" src="/u/z.jpg">';
check('attachment page', $expected, $A::filter_html($page, $lookup, $site));
check('lookup called once', 1, count($calls));
check('lookup gets unique IDs, skips images with alt', [12, 34, 56, 99], $calls[0]);

// --- filter hook arguments ---
$seen = [];
$out = $A::filter_html('<img class="wp-image-34" src="/u/b.jpg">', $lookup, $site, function ($alt, $tag, $id) use (&$seen) {
    $seen = [$alt, $tag, $id];
    return 'Własny opis';
});
check('filter args', ['Webinar EC Max', '<img class="wp-image-34" src="/u/b.jpg">', 34], $seen);
check('filter result used', '<img alt="Własny opis" class="wp-image-34" src="/u/b.jpg">', $out);
$called = false;
$A::filter_html('<img alt="Jest" src="/u/b.jpg">', $no_lookup, $site, function () use (&$called) { $called = true; return 'x'; });
check('filter not called for non-empty alt', false, $called);

// --- large page (~1 MB) ---
$big = '<html><head><script>' . str_repeat('var x = "<img src=a.jpg>"; if (a<b) {} ', 12000) . '</script></head><body>';
for ($i = 0; $i < 5000; $i++) {
    $big .= '<div class="c"><p>Lorem ipsum dolor sit amet ' . $i . '</p><img class="wp-image-' . ($i % 50 + 1) . '" src="/u/photo-' . $i . '-300x200.jpg"></div>';
}
$big .= '</body></html>';
$calls = [];
$t = microtime(true);
$big_out = $A::filter_html($big, function ($ids) use (&$calls) { $calls[] = $ids; return []; }, $site);
$elapsed = microtime(true) - $t;
check('big page >= 800 KB', true, strlen($big) > 800000);
check('big page processed (no PCRE failure)', 5000, substr_count($big_out, ' alt="'));
check('big page script untouched', true, strpos($big_out, str_repeat('var x = "<img src=a.jpg>"; if (a<b) {} ', 12000)) !== false);
check('big page one lookup', 1, count($calls));
check('big page < 1s', true, $elapsed < 1.0);
check('big page: only alt added', $big, str_replace(['<img alt="MWT Solutions" '], ['<img '], $big_out));

// --- lazy-load placeholder fix (1x1 GIF -> sized SVG) ---
$gif = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
$svg707 = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='707' height='175' viewBox='0 0 707 175'%3E%3C/svg%3E";
$lazy = '<img width="707" height="175" src="' . $gif . '" data-src="/u/hero.jpg" class="lazyload" alt="Baner">';
check('WP-Optimize placeholder -> SVG 707x175', str_replace($gif, $svg707, $lazy), $f($lazy));
check('is_1x1_gif: WP-Optimize GIF', true, $A::is_1x1_gif($gif));
$gif2x2 = 'data:image/gif;base64,' . base64_encode("GIF89a\x02\x00\x02\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x02\x00\x02\x00\x00\x02\x02D\x01\x00;");
check('is_1x1_gif: 2x2 GIF is not', false, $A::is_1x1_gif($gif2x2));
$gif_other = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';
check('other 1x1 GIF also fixed', '<img src="' . str_replace('707', '40', str_replace('175', '30', $svg707)) . '" data-lazy-src="/u/a.jpg" width="40" height="30" alt="A">',
    $f('<img src="' . $gif_other . '" data-lazy-src="/u/a.jpg" width="40" height="30" alt="A">'));
$no_dims = '<img src="' . $gif . '" data-src="/u/hero.jpg" class="lazyload" alt="Baner">';
check('no width/height untouched', $no_dims, $f($no_dims));
$only_w = '<img width="707" src="' . $gif . '" data-src="/u/hero.jpg" alt="Baner">';
check('only width untouched', $only_w, $f($only_w));
$zero = '<img width="0" height="0" src="' . $gif . '" data-src="/u/hero.jpg" alt="Baner">';
check('zero dimensions untouched', $zero, $f($zero));
$pct = '<img width="100%" height="175" src="' . $gif . '" data-src="/u/hero.jpg" alt="Baner">';
check('percent width untouched', $pct, $f($pct));
$already = '<img width="707" height="175" src="' . $svg707 . '" data-src="/u/hero.jpg" alt="Baner">';
check('already SVG untouched', $already, $f($already));
$no_data_src = '<img width="707" height="175" src="' . $gif . '" class="lazyload" alt="Baner">';
check('no data-src untouched', $no_data_src, $f($no_data_src));
$real = '<img width="707" height="175" src="/u/hero.jpg" data-src="/u/hero.jpg" alt="Baner">';
check('real src untouched', $real, $f($real));
$single = "<img width='707' height='175' src='" . $gif . "' data-src='/u/hero.jpg' alt='Baner'>";
check('single-quoted tag: only src replaced', "<img width='707' height='175' src=\"" . $svg707 . "\" data-src='/u/hero.jpg' alt='Baner'>", $f($single));
check('lazy fix + alt in one tag',
    '<img alt="Hero" width="707" height="175" src="' . $svg707 . '" data-src="/u/hero.jpg" class="lazyload">',
    $f('<img width="707" height="175" src="' . $gif . '" data-src="/u/hero.jpg" class="lazyload">'));
$prot = '<noscript><img width="707" height="175" src="' . $gif . '" data-src="/u/x.jpg" alt="X"></noscript><script>var h=\'<img width="707" height="175" src="' . $gif . '" data-src="/u/x.jpg">\';</script><!-- <img width="707" height="175" src="' . $gif . '" data-src="/u/x.jpg"> -->';
check('lazy fix skips noscript/script/comments', $prot, $f($prot));
check('lazy fix can be switched off', $lazy, $A::filter_html($lazy, $no_lookup, $site, null, false));
check('switched off still fills alt',
    '<img alt="Hero" width="707" height="175" src="' . $gif . '" data-src="/u/hero.jpg">',
    $A::filter_html('<img width="707" height="175" src="' . $gif . '" data-src="/u/hero.jpg">', $no_lookup, $site, null, false));

echo "\n{$passes} passed, {$failures} failed (big page: " . round($elapsed * 1000) . " ms)\n";
exit($failures ? 1 : 0);
