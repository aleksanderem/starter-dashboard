<?php
/**
 * Starter Dashboard Addon: Translated Labels
 *
 * Swaps English literals left in Elementor templates (e.g. the careers tiles: "Job title",
 * "Location:", "Work mode:") for the visitor's language. Add more in class-labels.php.
 *
 * Disable in code:  add_filter('mwts_translated_labels', '__return_false');
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-labels.php';

add_filter('elementor/widget/render_content', function ($content, $widget) {
    try {
        if (!is_string($content) || is_admin() || !apply_filters('mwts_translated_labels', true)) {
            return $content;
        }
        if (!is_object($widget) || !method_exists($widget, 'get_id')) {
            return $content;
        }
        $lang = apply_filters('wpml_current_language', null);
        $lang = is_string($lang) && $lang !== '' ? $lang : substr((string) get_locale(), 0, 2);
        return Starter_Translated_Labels::translate($content, (string) $widget->get_id(), $lang);
    } catch (\Throwable $e) {
        error_log('[mwts-translated-labels] ' . $e->getMessage());
        return $content;
    }
}, 98, 2);
