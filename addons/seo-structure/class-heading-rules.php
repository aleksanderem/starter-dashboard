<?php
/**
 * Pure logic for the SEO Structure addon (no WordPress calls — testable with plain `php`).
 *
 * Changes the HTML tag of headings (h1–h6) in rendered Elementor widgets without changing how
 * they look: the original level is kept as `uicore-title hN` classes (UiCore typography helpers)
 * plus a small stylesheet for promoted headings (see parity_css()).
 */

defined('ABSPATH') || exit;

class Starter_Heading_Rules {

    /** Decision value meaning "not a heading any more" (rendered as <p>). */
    const PLAIN = 'p';

    /**
     * Decide the target for one widget.
     *
     * @param string $widget_id  Elementor widget id (stable across WPML translations).
     * @param int    $doc_id     Elementor document id of the template being rendered (original language).
     * @param string $doc_type   Elementor document type (e.g. 'uicore-tb', 'loop-item', 'wp-page').
     * @param bool   $hidden     Widget or one of its parents is hidden on desktop, tablet and mobile.
     * @param array  $rules      See rules.php.
     * @return string|null  'p', 'h1'..'h6', or null = leave the widget alone.
     */
    public static function decide($widget_id, $doc_id, $doc_type, $hidden, array $rules) {
        if ($hidden) {
            return self::PLAIN;
        }
        $widgets = isset($rules['widgets']) ? $rules['widgets'] : [];
        if ($widget_id !== '' && isset($widgets[$widget_id])) {
            return $widgets[$widget_id];
        }
        $plain_docs = isset($rules['plain_docs']) ? $rules['plain_docs'] : [];
        if ($doc_id && in_array((int) $doc_id, $plain_docs, true)) {
            return self::PLAIN;
        }
        $plain_types = isset($rules['plain_doc_types']) ? $rules['plain_doc_types'] : [];
        if ($doc_type !== '' && in_array((string) $doc_type, $plain_types, true)) {
            return self::PLAIN;
        }
        return null;
    }

    /** Hidden on every device = never visible. */
    public static function hidden_everywhere(array $settings) {
        return !empty($settings['hide_desktop']) && !empty($settings['hide_tablet']) && !empty($settings['hide_mobile']);
    }

    /**
     * Rewrite every heading in $html to $to ('p' or 'h1'..'h6').
     * Headings that already have that tag are left untouched.
     */
    public static function retag($html, $to) {
        if (!is_string($html) || $html === '' || !preg_match('/^(p|h[1-6])$/', (string) $to)) {
            return $html;
        }
        return preg_replace_callback(
            '~<h([1-6])(\s[^>]*)?>(.*?)</h\1\s*>~is',
            function ($m) use ($to) {
                $from = 'h' . $m[1];
                if ($from === $to) {
                    return $m[0];
                }
                $attrs = isset($m[2]) ? $m[2] : '';
                $attrs = self::add_attrs($attrs, $from);
                return '<' . $to . $attrs . '>' . $m[3] . '</' . $to . '>';
            },
            $html
        );
    }

    /** Keep the original look (classes) and remember the original level (data attribute). */
    private static function add_attrs($attrs, $from) {
        // Brand carousel names have their own tag-based styling; a plain tag already matches it.
        $keep_look = strpos($attrs, 'bdt-ep-brand-carousel-name') === false;
        $extra = $keep_look ? ' uicore-title ' . $from : '';

        if (preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/is', $attrs, $c)) {
            $classes = trim($c[2] . $extra);
            $attrs = str_replace($c[0], ' class="' . $classes . '"', $attrs);
        } elseif ($extra !== '') {
            $attrs .= ' class="' . trim($extra) . '"';
        }
        return $attrs . ' data-mwts-was="' . $from . '"';
    }

    /**
     * CSS that keeps promoted headings (e.g. h3 → h2) looking like their original level.
     * Specificity sits above the theme's tag rules and below Elementor's per-widget settings.
     *
     * @param array $pairs  e.g. [['h3','h2'], ['h4','h3'], ['h2','h1']] (from, to)
     */
    public static function parity_css(array $pairs) {
        $targets = [
            '.elementor-widget-heading %s.elementor-heading-title',
            '.elementor-widget-bdt-advanced-icon-box %s.bdt-ep-advanced-icon-box-title',
            '%s.ui-e--highlighted-text',
            '%s.ui-e-title',
        ];
        $css = '';
        foreach ($pairs as $pair) {
            list($from, $to) = $pair;
            if (!preg_match('/^h[1-6]$/', $from) || !preg_match('/^h[1-6]$/', $to)) {
                continue;
            }
            $sel = [];
            foreach ($targets as $t) {
                $sel[] = sprintf($t, $to) . '[data-mwts-was="' . $from . '"]';
            }
            $v = '--uicore-typography--' . $from . '-';
            $css .= implode(',', $sel) . '{'
                . 'color:var(' . $v . 'c);font-family:var(' . $v . 'f);font-size:var(' . $v . 's);'
                . 'font-style:var(' . $v . 'st);font-weight:var(' . $v . 'w);letter-spacing:var(' . $v . 'ls);'
                . 'line-height:var(' . $v . 'h);text-transform:var(' . $v . 't)}';
        }
        return $css;
    }

    /** Visually hidden <h1> with the page title (for pages whose design has no visible title). */
    public static function hidden_h1($title) {
        $title = trim(wp_strip_all_tags_safe($title));
        if ($title === '') {
            return '';
        }
        return '<h1 class="mwts-sr-h1" style="position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    }

    /** True when the HTML already contains an <h1>. */
    public static function has_h1($html) {
        return is_string($html) && preg_match('~<h1[\s>]~i', $html) === 1;
    }
}

if (!function_exists('wp_strip_all_tags_safe')) {
    /** Falls back to strip_tags() when WordPress is not loaded (tests). */
    function wp_strip_all_tags_safe($s) {
        return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($s) : strip_tags((string) $s);
    }
}
