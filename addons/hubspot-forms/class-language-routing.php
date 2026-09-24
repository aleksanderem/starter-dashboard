<?php
/**
 * HubSpot Forms: per-language form routing.
 *
 * WPML/Polylang copy Elementor widget settings from the original page, so every
 * translated form keeps the HubSpot form chosen on the original. The routing map
 * (source HubSpot form GUID => [language code => target GUID]) swaps the form at
 * submit time, based on the language the visitor was browsing.
 */

defined('ABSPATH') || exit;

class Starter_HubSpot_Language_Routing {

    const OPTION = 'starter_hubspot_lang_routing';
    const GUID_PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/';
    const LANG_PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

    /**
     * Return the HubSpot form GUID to use for the given language.
     */
    public static function resolve($form_id, $language, array $routing) {
        // sanitize() stores lowercase keys
        $source = strtolower(trim((string) $form_id));
        $language = strtolower(trim((string) $language));
        if ($language === '' || empty($routing[$source][$language])) {
            return $form_id;
        }
        return $routing[$source][$language];
    }

    /**
     * Keep only valid source GUID => [allowed language => target GUID] pairs.
     */
    public static function sanitize($raw, array $allowed_languages) {
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $source => $targets) {
            $source = strtolower(trim((string) $source));
            if (!preg_match(self::GUID_PATTERN, $source) || !is_array($targets)) {
                continue;
            }
            foreach ($targets as $language => $target) {
                $language = strtolower(trim((string) $language));
                $target = strtolower(trim((string) $target));
                if (!in_array($language, $allowed_languages, true)) {
                    continue;
                }
                if (!preg_match(self::GUID_PATTERN, $target) || $target === $source) {
                    continue;
                }
                $clean[$source][$language] = $target;
            }
        }
        return $clean;
    }

    /**
     * Read the language from a URL: ?lang= parameter or a leading /{code}/ path segment.
     */
    public static function language_from_url($url, array $language_codes, $default_language, $home_path = '') {
        if (empty($url)) {
            return null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);
            if (!empty($params['lang']) && in_array($params['lang'], $language_codes, true)) {
                return $params['lang'];
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $home_path = rtrim((string) $home_path, '/');
        if ($home_path !== '' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }

        $first_segment = explode('/', trim($path, '/'))[0];
        if ($first_segment !== '' && in_array($first_segment, $language_codes, true)) {
            return $first_segment;
        }
        return $default_language;
    }

    /**
     * Checkbox values arrive as 1/0 from the dashboard and as 'yes'/'no' from older code.
     */
    public static function is_truthy($value) {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * Saved routing map.
     */
    public static function get_routing() {
        $routing = get_option(self::OPTION, []);
        return is_array($routing) ? $routing : [];
    }

    /**
     * Active multilingual plugin and its languages.
     *
     * @return array{plugin: ?string, default: ?string, languages: array<string,string>}
     */
    public static function get_context() {
        $context = ['plugin' => null, 'default' => null, 'languages' => []];

        $wpml_languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (is_array($wpml_languages) && !empty($wpml_languages)) {
            $context['plugin'] = 'wpml';
            $context['default'] = apply_filters('wpml_default_language', null);
            foreach ($wpml_languages as $code => $language) {
                $context['languages'][$code] = $language['translated_name'] ?? $language['native_name'] ?? $code;
            }
            return $context;
        }

        if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
            $context['plugin'] = 'polylang';
            $context['default'] = pll_default_language('slug');
            $slugs = pll_languages_list(['fields' => 'slug']);
            $names = pll_languages_list(['fields' => 'name']);
            foreach ($slugs as $index => $slug) {
                $context['languages'][$slug] = $names[$index] ?? $slug;
            }
        }

        return $context;
    }

    /**
     * Language of a submission: page URL first (what the visitor saw), then the
     * language of the posts that hold the form, then the current request language.
     */
    public static function detect($page_url, array $post_ids) {
        $context = self::get_context();
        if ($context['plugin'] === null) {
            return null;
        }
        $codes = array_keys($context['languages']);

        global $sitepress;
        if ($context['plugin'] === 'wpml' && !empty($page_url) && is_object($sitepress) && method_exists($sitepress, 'get_language_from_url')) {
            $language = $sitepress->get_language_from_url($page_url);
            if (in_array($language, $codes, true)) {
                return $language;
            }
        }

        if (!empty($page_url)) {
            $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);
            $language = self::language_from_url($page_url, $codes, $context['default'], $home_path);
            if ($language !== null) {
                return $language;
            }
        }

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) {
                continue;
            }
            if ($context['plugin'] === 'wpml') {
                $details = apply_filters('wpml_post_language_details', null, $post_id);
                if (is_array($details) && !empty($details['language_code'])) {
                    return $details['language_code'];
                }
            } elseif (function_exists('pll_get_post_language')) {
                $language = pll_get_post_language($post_id, 'slug');
                if ($language) {
                    return $language;
                }
            }
        }

        $current = $context['plugin'] === 'wpml'
            ? apply_filters('wpml_current_language', null)
            : (function_exists('pll_current_language') ? pll_current_language('slug') : null);
        return $current ?: $context['default'];
    }
}
