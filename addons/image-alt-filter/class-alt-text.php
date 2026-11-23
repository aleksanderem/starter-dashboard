<?php
/**
 * Pure alt-text logic for the Image Alt Filter addon.
 *
 * No WordPress calls in this file, so it can be tested with plain `php`.
 * WordPress-specific data (attachment titles, site name, filters) is passed in.
 */

defined('ABSPATH') || exit;

class Starter_Image_Alt_Text {

    const MAX_LENGTH = 125;

    /** Words that carry no meaning when they are the only content left. */
    const JUNK_WORDS = [
        'group', 'vector', 'shape', 'frame', 'icon', 'icons', 'untitled', 'image', 'images', 'img',
        'photo', 'picture', 'pic', 'graphic', 'rectangle', 'ellipse', 'layer', 'artboard', 'asset',
        'mask', 'path', 'copy', 'final', 'new', 'placeholder', 'default', 'file', 'screenshot',
        'design', 'bg', 'background', 'element', 'object', 'clip', 'dsc', 'dscn', 'pxl', 'scaled',
    ];

    /**
     * Protected blocks (left byte-identical) or an <img> tag.
     * Loops are written "unrolled" so 1 MB pages do not hit pcre.backtrack_limit.
     */
    const SCAN_PATTERN = '~
        <!--[^-]*+(?:-(?!->)[^-]*+)*+(?:-->|\z)
      | <(script|style|noscript)\b[^>]*+>[^<]*+(?:<(?!/\1\s*>)[^<]*+)*+(?:</\1\s*>|\z)
      | <img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>
    ~ix';

    const ATTR_PATTERN = '~([^\s=/>"\']++)(?:\s*=\s*(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>"\']++)))?~';

    /**
     * Fill missing/empty alt attributes (and fix lazy-load placeholders) in a full HTML document.
     *
     * @param string        $html     Page HTML.
     * @param callable      $lookup   fn(int[] $ids): array<int, array{alt:string, title:string}> — called once.
     * @param string        $fallback Used when nothing meaningful can be derived (site name).
     * @param callable|null $filter   fn(string $alt, string $img_tag, int $attachment_id): string.
     * @param bool          $fix_lazy Replace 1x1 GIF lazy-load placeholders with a sized SVG (see lazy_placeholder_edit()).
     */
    public static function filter_html($html, callable $lookup, $fallback = '', $filter = null, $fix_lazy = true) {
        if (stripos($html, '<img') === false) {
            return $html;
        }
        if (!preg_match_all(self::SCAN_PATTERN, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html; // no match or PCRE error: never break the page
        }

        $jobs = [];
        $ids = [];
        foreach ($matches[0] as [$tag, $offset]) {
            if (strncasecmp($tag, '<img', 4) !== 0) {
                continue;
            }
            $attrs = self::parse_attributes($tag);
            $lazy_edit = $fix_lazy ? self::lazy_placeholder_edit($tag, $attrs) : null;
            $needs_alt = !isset($attrs['alt']) || trim($attrs['alt']['value']) === ''; // never touch a non-empty alt
            if (!$needs_alt && !$lazy_edit) {
                continue;
            }
            $id = 0;
            $decorative = false;
            if ($needs_alt) {
                $before = substr($html, max(0, $offset - 300), min(300, $offset));
                $id = self::attachment_id($attrs);
                $decorative = self::is_decorative($attrs, $before);
                if ($id) {
                    $ids[$id] = $id;
                }
            }
            $jobs[] = [$tag, $offset, $attrs, $needs_alt, $id, $decorative, $lazy_edit];
        }
        if (!$jobs) {
            return $html;
        }

        $attachments = $ids ? (array) $lookup(array_values($ids)) : [];

        $out = '';
        $cursor = 0;
        foreach ($jobs as [$tag, $offset, $attrs, $needs_alt, $id, $decorative, $lazy_edit]) {
            $edits = $lazy_edit ? [$lazy_edit] : [];
            // Decorative image that already has alt="" — keep its alt as is.
            if ($needs_alt && !($decorative && isset($attrs['alt']))) {
                $alt = $decorative ? '' : self::resolve_alt($attachments[$id] ?? null, self::source($attrs), $fallback);
                if ($filter) {
                    $alt = (string) $filter($alt, $tag, $id);
                }
                $edits[] = self::alt_edit($attrs, $alt);
            }
            if (!$edits) {
                continue;
            }
            $out .= substr($html, $cursor, $offset - $cursor) . self::apply_edits($tag, $edits);
            $cursor = $offset + strlen($tag);
        }

        return $out . substr($html, $cursor);
    }

    /**
     * Lazy-load placeholder fix (CLS): a 1x1 GIF in src makes the browser reserve a square box
     * until the real file loads. When the tag has data-src/data-lazy-src and numeric width/height,
     * swap src for an empty SVG with the same proportions. Everything else in the tag stays.
     *
     * @return array|null Edit [offset, length, replacement] for apply_edits(), or null.
     */
    public static function lazy_placeholder_edit($tag, array $attrs) {
        if (!isset($attrs['src']) || !self::is_1x1_gif($attrs['src']['value'])) {
            return null;
        }
        if (trim($attrs['data-src']['value'] ?? '') === '' && trim($attrs['data-lazy-src']['value'] ?? '') === '') {
            return null;
        }
        $w = trim($attrs['width']['value'] ?? '');
        $h = trim($attrs['height']['value'] ?? '');
        if (!ctype_digit($w) || !ctype_digit($h) || (int) $w === 0 || (int) $h === 0) {
            return null;
        }
        $w = (int) $w;
        $h = (int) $h;
        $svg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'%3E%3C/svg%3E";
        $src = $attrs['src'];
        $name = substr($tag, $src['offset'], strcspn($tag, " \t\n\r\f=", $src['offset']));
        // Only integers are interpolated and the value has no `"`, `<` or `&`, so it is attribute-safe as is.
        return [$src['offset'], $src['length'], $name . '="' . $svg . '"'];
    }

    /** data:image/gif whose header says 1x1 pixel. */
    public static function is_1x1_gif($src) {
        if (!preg_match('~^\s*data:image/gif;base64,([A-Za-z0-9+/=\s]{16,})~i', $src, $m)) {
            return false;
        }
        $bytes = base64_decode(substr(preg_replace('/\s+/', '', $m[1]), 0, 16), true);
        if ($bytes === false || strncmp($bytes, 'GIF', 3) !== 0 || strlen($bytes) < 10) {
            return false;
        }
        $size = unpack('vwidth/vheight', substr($bytes, 6, 4));
        return $size['width'] === 1 && $size['height'] === 1;
    }

    /**
     * Alt for one image: attachment alt meta, then attachment title, then file name, then fallback.
     *
     * @param array|null $attachment ['alt' => string, 'title' => string] or null.
     */
    public static function resolve_alt($attachment, $src, $fallback = '') {
        if ($attachment) {
            $meta = self::limit(trim(preg_replace('/\s+/u', ' ', self::decode($attachment['alt'] ?? ''))));
            if ($meta !== '') {
                return $meta;
            }
            $title = self::clean($attachment['title'] ?? '');
            if ($title !== '') {
                return $title;
            }
        }
        $name = self::clean(self::filename($src));
        if ($name !== '') {
            return $name;
        }
        return trim(self::decode($fallback));
    }

    /**
     * Turn a title or file name into readable alt text. Returns '' when nothing meaningful is left.
     */
    public static function clean($text) {
        $text = mb_scrub(self::decode((string) $text), 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/^(?:[A-Z]{2}\d{0,4}_)+/', '', $text);           // PL26_, EN_, CS26_
        $text = preg_replace('/[_\-]+/', ' ', $text);
        $text = preg_replace('/\b(?:IMG|DSC|DSCN|PXL|DCIM)\s*\d+\b/i', ' ', $text); // camera names
        $text = preg_replace('/\b\d+\s*x\s*\d+\b/i', ' ', $text);              // 1200x1200
        $text = preg_replace('/\b(?=[a-f0-9]*\d)(?=[a-f0-9]*[a-f])[a-f0-9]{8,}\b/i', ' ', $text); // hex hashes
        $text = preg_replace('/\b[A-Za-z0-9]{20,}\b|\b\d{6,}\b/', ' ', $text);  // long random strings, timestamps
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $text = preg_replace('/(?:\s+(?:0\d+|\d))+$/', '', $text);              // counters: -01, -1, -8-1
        $text = self::trim_punctuation($text);

        $words = preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $meaningful = array_filter($words, function ($w) {
            return !ctype_digit($w) && !in_array($w, self::JUNK_WORDS, true);
        });
        if (!$meaningful) {
            return '';
        }

        $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        return self::limit($text);
    }

    /**
     * File name (without extension, WP size/edit suffixes) from an image URL.
     */
    public static function filename($src) {
        $path = parse_url((string) $src, PHP_URL_PATH);
        if (!$path || strncasecmp((string) $src, 'data:', 5) === 0) {
            return '';
        }
        $name = rawurldecode(basename($path));
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name);
        $name = preg_replace('/(?:-\d+x\d+|-scaled|-e\d{10,}|@\dx)+$/i', '', $name);
        return $name;
    }

    /**
     * Decorative image: explicit markers, or an SVG inside an icon container.
     *
     * @param array  $attrs  Result of parse_attributes().
     * @param string $before Up to 300 bytes of HTML preceding the tag.
     */
    public static function is_decorative(array $attrs, $before = '') {
        $value = function ($name) use ($attrs) {
            return isset($attrs[$name]) ? strtolower(trim($attrs[$name]['value'])) : null;
        };
        if ($value('aria-hidden') === 'true' || in_array($value('role'), ['presentation', 'none'], true)
            || isset($attrs['data-mwts-decorative'])) {
            return true;
        }
        $src = self::source($attrs);
        $is_svg = preg_match('/\.svg(?:[?#]|$)/i', $src) || stripos($src, 'data:image/svg') === 0;
        if (!$is_svg) {
            return false;
        }
        $icon_class = '/class\s*=\s*["\'][^"\']*(?<![a-z])icons?(?![a-z])/i';
        return preg_match($icon_class, 'class="' . ($attrs['class']['value'] ?? '') . '"')
            || preg_match($icon_class, $before);
    }

    /**
     * Parse attributes of a single tag. Keys are lower-case names; values are entity-decoded.
     *
     * @return array<string, array{value:string, raw:bool, offset:int, length:int}>
     *         raw=false when the attribute has no value (e.g. bare `alt`).
     */
    public static function parse_attributes($tag) {
        $attrs = [];
        $start = 4; // after "<img"
        $inner = substr($tag, $start);
        if (!preg_match_all(self::ATTR_PATTERN, $inner, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $attrs;
        }
        foreach ($m as $match) {
            $name = strtolower($match[1][0]);
            if (isset($attrs[$name])) {
                continue; // HTML keeps the first occurrence
            }
            $raw = '';
            $has_value = false;
            foreach ([2, 3, 4] as $g) {
                if (isset($match[$g]) && $match[$g][1] !== -1) {
                    $raw = $match[$g][0];
                    $has_value = true;
                    break;
                }
            }
            $attrs[$name] = [
                'value'  => self::decode($raw),
                'raw'    => $has_value,
                'offset' => $start + $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }
        return $attrs;
    }

    private static function attachment_id(array $attrs) {
        if (isset($attrs['class']) && preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $attrs['class']['value'], $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** Real image URL, preferring lazy-load attributes over data: placeholders. */
    private static function source(array $attrs) {
        foreach (['src', 'data-src', 'data-lazy-src', 'data-original'] as $name) {
            if (!empty($attrs[$name]['value']) && strncasecmp($attrs[$name]['value'], 'data:', 5) !== 0) {
                return $attrs[$name]['value'];
            }
        }
        return $attrs['src']['value'] ?? '';
    }

    /** Edit that sets alt: replaces an existing alt attribute or inserts one right after "<img". */
    private static function alt_edit(array $attrs, $alt) {
        $attr = 'alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        if (isset($attrs['alt'])) {
            return [$attrs['alt']['offset'], $attrs['alt']['length'], $attr];
        }
        return [4, 0, ' ' . $attr];
    }

    /** Apply [offset, length, replacement] edits, last offset first so earlier offsets stay valid. */
    private static function apply_edits($tag, array $edits) {
        usort($edits, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        foreach ($edits as [$offset, $length, $replacement]) {
            $tag = substr_replace($tag, $replacement, $offset, $length);
        }
        return $tag;
    }

    private static function decode($text) {
        // Twice: titles sometimes arrive double-encoded (&amp;#8211;).
        return html_entity_decode(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function limit($text) {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_LENGTH);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > self::MAX_LENGTH / 2) {
            $cut = mb_substr($cut, 0, $space);
        }
        return self::trim_punctuation($cut);
    }

    private static function trim_punctuation($text) {
        return preg_replace('/^[\s.,;:|–—-]+|[\s.,;:|–—-]+$/u', '', $text);
    }
}
