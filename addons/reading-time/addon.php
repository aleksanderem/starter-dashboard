<?php
/**
 * Starter Dashboard Addon: Reading Time
 *
 * Calculates and displays estimated reading time for posts.
 * Usage: [reading_time]
 */

defined('ABSPATH') || exit;

class Starter_Addon_Reading_Time {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('reading_time', [$this, 'render_shortcode']);
    }

    /**
     * Render shortcode output
     *
     * @param array $atts Shortcode attributes
     * @return string Reading time HTML
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'words_per_minute' => 200,
            'before'           => '',
            'after'            => ' min read',
            'show_icon'        => 'true',
            'post_id'          => 0,
            'class'            => 'reading-time',
        ], $atts, 'reading_time');

        // Get post
        $post_id = absint($atts['post_id']);
        $post = $post_id ? get_post($post_id) : get_post();

        if (!$post) {
            return '';
        }

        // Get post content and strip HTML/shortcodes
        $content = $post->post_content;
        $content = strip_shortcodes($content);
        $content = wp_strip_all_tags($content);

        // Count words
        $word_count = str_word_count($content);

        // Calculate reading time
        $words_per_minute = absint($atts['words_per_minute']);
        if ($words_per_minute < 1) {
            $words_per_minute = 200;
        }

        $reading_time = ceil($word_count / $words_per_minute);

        // Minimum 1 minute
        if ($reading_time < 1) {
            $reading_time = 1;
        }

        // Build icon
        $icon = '';
        if ($atts['show_icon'] === 'true') {
            $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
        }

        // Build output
        $output = sprintf(
            '<span class="%s">%s%s%d%s</span>',
            esc_attr($atts['class']),
            $icon,
            esc_html($atts['before']),
            $reading_time,
            esc_html($atts['after'])
        );

        return $output;
    }

    /**
     * Get reading time for a post (helper for external use)
     *
     * @param int $post_id Post ID (0 for current post)
     * @param int $words_per_minute Reading speed
     * @return int Reading time in minutes
     */
    public static function get_reading_time($post_id = 0, $words_per_minute = 200) {
        $post = $post_id ? get_post($post_id) : get_post();

        if (!$post) {
            return 0;
        }

        $content = strip_shortcodes($post->post_content);
        $content = wp_strip_all_tags($content);
        $word_count = str_word_count($content);

        $reading_time = ceil($word_count / max(1, $words_per_minute));

        return max(1, $reading_time);
    }
}

// Initialize
Starter_Addon_Reading_Time::instance();
