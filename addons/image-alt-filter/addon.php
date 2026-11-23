<?php
/**
 * Starter Dashboard Addon: Image Alt Filter
 *
 * Fills empty or missing alt attributes of <img> tags on the front end while the
 * page is rendered. Nothing is written to the database.
 *
 * Override the generated text with:
 *   add_filter('mwts_image_alt_text', function ($alt, $img_tag, $attachment_id) { return $alt; }, 10, 3);
 *
 * Also swaps 1x1 GIF lazy-load placeholders (WP-Optimize etc.) for a sized empty SVG to
 * prevent layout shift. Disable with:
 *   add_filter('mwts_image_fix_lazy_placeholder', '__return_false');
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-alt-text.php';

class Starter_Addon_Image_Alt_Filter {

    /** Pages larger than this are passed through untouched. */
    const MAX_HTML_BYTES = 3145728; // 3 MB

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('template_redirect', [$this, 'start_buffer'], 0);
        add_filter('wp_get_attachment_image_attributes', [$this, 'filter_attachment_attributes'], 20, 2);
    }

    /**
     * Front-end page views only.
     */
    private function is_front_request() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || wp_is_json_request()) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only checks
        if (isset($_GET['elementor-preview']) || isset($_GET['preview']) || isset($_GET['elementor_library'])) {
            return false;
        }
        if (did_action('wp')) {
            if (is_feed() || is_preview() || is_customize_preview() || is_robots() || is_trackback()) {
                return false;
            }
        }
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->preview)
            && \Elementor\Plugin::$instance->preview->is_preview_mode()) {
            return false;
        }
        return true;
    }

    public function start_buffer() {
        if ($this->is_front_request()) {
            ob_start([$this, 'filter_buffer']);
        }
    }

    /**
     * Output buffer callback: one scan over the final HTML.
     */
    public function filter_buffer($html) {
        if (!is_string($html) || $html === '' || strlen($html) > self::MAX_HTML_BYTES
            || stripos($html, '<img') === false || !$this->is_html_response()) {
            return $html;
        }

        return Starter_Image_Alt_Text::filter_html(
            $html,
            [$this, 'lookup_attachments'],
            get_bloginfo('name'),
            function ($alt, $img_tag, $attachment_id) {
                return (string) apply_filters('mwts_image_alt_text', $alt, $img_tag, $attachment_id);
            },
            // Return false to keep lazy-load 1x1 GIF placeholders untouched.
            (bool) apply_filters('mwts_image_fix_lazy_placeholder', true)
        );
    }

    private function is_html_response() {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }
        return true; // PHP default is text/html
    }

    /**
     * Load all attachments referenced on the page with one primed query.
     *
     * @param int[] $ids
     * @return array<int, array{alt:string, title:string}>
     */
    public function lookup_attachments(array $ids) {
        _prime_post_caches($ids, false, true);

        $result = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                continue;
            }
            $result[$id] = [
                'alt'   => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                'title' => (string) $post->post_title,
            ];
        }
        return $result;
    }

    /**
     * Images produced by wp_get_attachment_image(): WordPress already copied the
     * alt meta into $attr['alt']; fill it only when it is still empty.
     */
    public function filter_attachment_attributes($attr, $attachment) {
        if (!is_array($attr) || (isset($attr['alt']) && trim((string) $attr['alt']) !== '') || !$this->is_front_request()) {
            return $attr;
        }

        $attrs = [];
        foreach ($attr as $name => $value) {
            $attrs[strtolower($name)] = ['value' => (string) $value, 'raw' => true, 'offset' => 0, 'length' => 0];
        }

        $id = $attachment instanceof WP_Post ? (int) $attachment->ID : 0;
        if (Starter_Image_Alt_Text::is_decorative($attrs)) {
            $alt = '';
        } else {
            $data = $attachment instanceof WP_Post ? ['alt' => '', 'title' => (string) $attachment->post_title] : null;
            $alt = Starter_Image_Alt_Text::resolve_alt($data, (string) ($attr['src'] ?? ''), get_bloginfo('name'));
        }

        $tag = '<img';
        foreach ($attr as $name => $value) {
            $tag .= ' ' . $name . '="' . esc_attr($value) . '"';
        }
        $tag .= '>';

        $attr['alt'] = (string) apply_filters('mwts_image_alt_text', $alt, $tag, $id);
        return $attr;
    }
}

Starter_Addon_Image_Alt_Filter::instance();
