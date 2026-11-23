<?php
/**
 * Pure logic for the Front Speed addon (no WordPress calls — testable with plain `php`).
 */

defined('ABSPATH') || exit;

class Starter_Front_Speed {

    const MODES = ['off', 'test', 'on'];
    const DEFAULT_MODE = 'test';
    const TEST_PARAM = 'mwtperf';

    /** Script handles that stay synchronous (`*` = wildcard). */
    const JS_EXCLUDE = [
        'jquery-core', 'jquery', 'jquery-migrate', 'wp-i18n', 'wp-hooks',
        'wp-optimize-send-command', 'wp-optimize-lazy-load', 'google_gtagjs', 'wp-consent-api',
        'cmplz-*', 'wpml-cookie*',
    ];

    /** Style handles (without the -css suffix) loaded asynchronously by default. */
    const CSS_ASYNC = [
        'intl-tel-input', 'jet-engine-frontend', 'init-plugin-suite-live-search-style', 'magic-card-style',
        'mwt-wyj-filters', 'swiper', 'e-swiper', 'ui-e-carousel', 'ui-e-post-meta', 'ui-e-legacy-grid',
        'e-animation-fadeInUp', 'lenis', 'dce-animations', 'cmplz-general',
    ];

    /** Layout-critical styles that are never loaded asynchronously, even if added via filter. */
    const CSS_PROTECTED = ['ep-styles', 'bdt-uikit', 'uicore-*', 'elementor-*', 'widget-*', '*remix*', '*themify*'];

    const SCRIPT_BLOCK_PATTERN = '~<script\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>.*?(?:</script\s*>|\z)~is';
    const LINK_PATTERN = '~<link\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>~i';
    const ATTR_PATTERN = '~([^\s=/>"\']++)(?:\s*=\s*(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>"\']++)))?~';

    public static function normalize_mode($mode) {
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /**
     * Should optimisations run for this request?
     *
     * @param string $mode       off | test | on.
     * @param bool   $test_flag  ?mwtperf=1 present.
     * @param bool   $logged_in  Visitor is logged in.
     * @param bool   $excluded   Admin/AJAX/REST/cron/feed/preview/editor/non-HTML/cart/checkout.
     */
    public static function is_enabled($mode, $test_flag, $logged_in, $excluded) {
        $mode = self::normalize_mode($mode);
        if ($mode === 'off' || $logged_in || $excluded) {
            return false;
        }
        return $mode === 'on' || $test_flag;
    }

    /** Handle matches a list entry; `*` is a wildcard. */
    public static function matches($handle, array $patterns) {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $handle) {
                return true;
            }
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                if (preg_match($regex, $handle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Handles that must stay synchronous: excluded ones, ones with inline `after` code,
     * and every handle those depend on (transitively).
     *
     * @param array $scripts handle => ['deps' => string[], 'after' => bool]
     * @param array $exclude Patterns (see JS_EXCLUDE).
     * @return array<string, true>
     */
    public static function sync_handles(array $scripts, array $exclude) {
        $sync = [];
        $stack = [];
        foreach ($scripts as $handle => $script) {
            $handle = (string) $handle;
            if (!empty($script['after']) || self::matches($handle, $exclude)) {
                $stack[] = $handle;
            }
        }
        while ($stack) {
            $handle = array_pop($stack);
            if (isset($sync[$handle])) {
                continue;
            }
            $sync[$handle] = true;
            foreach ((array) ($scripts[$handle]['deps'] ?? []) as $dep) {
                if (!isset($sync[$dep])) {
                    $stack[] = (string) $dep;
                }
            }
        }
        return $sync;
    }

    /**
     * Add `defer` to external <script src> tags of a handle. Inline scripts in the same
     * string (wp_add_inline_script before/after, translations) are left untouched.
     */
    public static function defer_tag($tag, $handle, array $sync) {
        if (isset($sync[$handle]) || stripos($tag, '<script') === false) {
            return $tag;
        }
        // Whole <script>…</script> blocks, so markup inside inline JS strings is never touched.
        return preg_replace_callback(self::SCRIPT_BLOCK_PATTERN, function ($m) {
            $attrs = self::parse_attributes($m[1]);
            if (!isset($attrs['src']) || isset($attrs['async']) || isset($attrs['defer'])
                || strtolower(trim($attrs['type'] ?? '')) === 'module') {
                return $m[0];
            }
            return '<script' . rtrim($m[1]) . ' defer>' . substr($m[0], strlen('<script' . $m[1] . '>'));
        }, $tag);
    }

    /**
     * Load a stylesheet without blocking rendering:
     * <link ... media="print" onload="this.media='all';this.onload=null"><noscript>original</noscript>
     */
    public static function async_style_tag($tag, $handle, array $async_list) {
        if (!self::matches($handle, $async_list) || self::matches($handle, self::CSS_PROTECTED)
            || stripos($tag, '<link') === false || stripos($tag, '<noscript') !== false) {
            return $tag;
        }
        return preg_replace_callback(self::LINK_PATTERN, function ($m) {
            $attrs = self::parse_attributes($m[1]);
            $media = trim($attrs['media'] ?? 'all');
            if ($media === '') {
                $media = 'all';
            }
            if (stripos($attrs['rel'] ?? '', 'stylesheet') === false || empty($attrs['href']) || isset($attrs['onload'])
                || strtolower($media) === 'print' || !preg_match('/^[a-z0-9 (),:.\-]+$/i', $media)) {
                return $m[0];
            }
            $inner = rtrim($m[1]);
            $self_closing = substr($inner, -1) === '/';
            if ($self_closing) {
                $inner = rtrim(substr($inner, 0, -1));
            }
            $inner = preg_replace('~\smedia\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']+)~i', '', $inner, 1);
            $inner .= ' media="print" onload="this.media=\'' . $media . '\';this.onload=null"';
            return '<link' . $inner . ($self_closing ? ' />' : '>') . '<noscript>' . $m[0] . '</noscript>';
        }, $tag);
    }

    /**
     * Dashicons can be dropped when no queued style (except the admin bar) needs it, directly or transitively.
     *
     * @param string[] $queue Queued style handles.
     * @param array    $deps  handle => string[] dependencies (registered styles).
     */
    public static function can_dequeue_dashicons(array $queue, array $deps) {
        $seen = [];
        $stack = array_values(array_diff($queue, ['dashicons', 'admin-bar']));
        while ($stack) {
            $handle = array_pop($stack);
            if (isset($seen[$handle])) {
                continue;
            }
            $seen[$handle] = true;
            foreach ((array) ($deps[$handle] ?? []) as $dep) {
                if ($dep === 'dashicons') {
                    return false;
                }
                $stack[] = $dep;
            }
        }
        return true;
    }

    /** Attribute name (lower-case) => decoded value; valueless attributes map to ''. */
    private static function parse_attributes($inner) {
        $attrs = [];
        if (!preg_match_all(self::ATTR_PATTERN, $inner, $m, PREG_SET_ORDER)) {
            return $attrs;
        }
        foreach ($m as $match) {
            $name = strtolower($match[1]);
            if ($name === '' || isset($attrs[$name])) {
                continue;
            }
            $value = '';
            foreach ([2, 3, 4] as $g) {
                if (isset($match[$g]) && $match[$g] !== '') {
                    $value = $match[$g];
                    break;
                }
            }
            $attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attrs;
    }
}
