<?php
/**
 * Starter Dashboard Addon: WebP Images
 *
 * Serves PNG/JPEG files from the uploads directory as WebP on the front end (logged-out
 * visitors). Missing WebP copies are queued and generated in the background by WP-Cron
 * (option mwts_webp_queue, hook mwts_webp_generate) — page views only add to the queue.
 *
 * Disable in code:
 *   add_filter('mwts_webp_images_enabled', '__return_false');
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-webp-images.php';

class Starter_Addon_Webp_Images {

    const QUEUE_OPTION = 'mwts_webp_queue';
    const SKIP_OPTION = 'mwts_webp_skip';
    const HOOK = 'mwts_webp_generate';
    const FIRST_DELAY = 30;
    const NEXT_DELAY = 60;

    /** Pages larger than this are passed through untouched. */
    const MAX_HTML_BYTES = 3145728; // 3 MB

    private static $instance = null;

    /** @var array<string, bool> WebP path => exists, for this request. */
    private $exists = [];

    /** @var array|null wp_get_upload_dir() result. */
    private $uploads = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('template_redirect', [$this, 'start_buffer'], 1);
        add_action(self::HOOK, [$this, 'generate']);
    }

    private static function enabled() {
        return (bool) apply_filters('mwts_webp_images_enabled', true);
    }

    /**
     * Front-end page views of logged-out visitors only.
     */
    private function is_front_request() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || wp_is_json_request() || is_user_logged_in()) {
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
        if ($this->is_front_request() && self::enabled()) {
            ob_start([$this, 'filter_buffer']);
        }
    }

    /**
     * Output buffer callback: one scan over the final HTML.
     */
    public function filter_buffer($html) {
        if (!is_string($html) || $html === '' || strlen($html) > self::MAX_HTML_BYTES
            || (stripos($html, '<img') === false && stripos($html, '<source') === false) || !$this->is_html_response()) {
            return $html;
        }
        $uploads = $this->uploads();
        if (!$uploads) {
            return $html;
        }

        $result = Starter_Webp_Images::rewrite_html($html, $uploads['baseurl'], [$this, 'webp_exists']);
        if ($result['missing']) {
            $this->enqueue($result['missing'], $uploads['baseurl']);
        }
        return $result['html'];
    }

    private function is_html_response() {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }
        return true; // PHP default is text/html
    }

    private function uploads() {
        if ($this->uploads === null) {
            $dir = wp_get_upload_dir();
            $this->uploads = (empty($dir['error']) && !empty($dir['baseurl']) && !empty($dir['basedir'])) ? $dir : [];
        }
        return $this->uploads;
    }

    /** Callback for rewrite_html(): does "<file>.webp" exist on disk? Cached for the request. */
    public function webp_exists($url) {
        $uploads = $this->uploads();
        $relative = Starter_Webp_Images::url_to_relative($url, $uploads['baseurl']);
        if ($relative === null) {
            return false;
        }
        $path = $uploads['basedir'] . '/' . $relative . Starter_Webp_Images::SUFFIX;
        if (!isset($this->exists[$path])) {
            $this->exists[$path] = file_exists($path);
        }
        return $this->exists[$path];
    }

    /**
     * Front end: only add to the queue and schedule the generator. No image work here.
     */
    private function enqueue(array $urls, $baseurl) {
        $paths = [];
        foreach ($urls as $url) {
            $relative = Starter_Webp_Images::url_to_relative($url, $baseurl);
            if ($relative !== null) {
                $paths[] = $relative;
            }
        }
        $skip = (array) get_option(self::SKIP_OPTION, []);
        $paths = array_values(array_diff($paths, $skip));
        if (!$paths) {
            return; // only files that were already rejected: no further reads or writes
        }
        $queue = get_option(self::QUEUE_OPTION, []);
        $updated = Starter_Webp_Images::queue_add($queue, $paths, $skip);
        if ($updated !== $queue) {
            update_option(self::QUEUE_OPTION, $updated, false);
        }
        if ($updated && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::FIRST_DELAY, self::HOOK);
        }
    }

    /**
     * WP-Cron: convert up to BATCH_SIZE queued files, then reschedule while the queue is not empty.
     * Page caches are not purged here.
     */
    public function generate() {
        if (!self::enabled()) {
            return;
        }
        $queue = Starter_Webp_Images::queue_add(get_option(self::QUEUE_OPTION, []), []);
        if (!$queue || !wp_image_editor_supports(['mime_type' => 'image/webp'])) {
            return; // nothing to do, or the server cannot write WebP (queue kept as is)
        }
        $uploads = $this->uploads();
        if (!$uploads) {
            return;
        }

        $batch = array_slice($queue, 0, Starter_Webp_Images::BATCH_SIZE);
        $skip = (array) get_option(self::SKIP_OPTION, []);
        $editor_factory = function ($path) {
            return wp_get_image_editor($path);
        };
        foreach ($batch as $relative) {
            $source = Starter_Webp_Images::resolve_source($uploads['basedir'], $relative);
            $status = $source ? Starter_Webp_Images::convert_file($source, $editor_factory) : 'error';
            if (in_array($status, ['small', 'larger', 'error'], true)) {
                $skip[] = $relative; // never retried
            }
        }
        update_option(self::SKIP_OPTION, array_values(array_unique($skip)), false);

        // Re-read: page views may have added files while this batch ran.
        wp_cache_delete(self::QUEUE_OPTION, 'options');
        $current = Starter_Webp_Images::queue_add(get_option(self::QUEUE_OPTION, []), []);
        $rest = array_values(array_diff($current, $batch));
        update_option(self::QUEUE_OPTION, $rest, false);
        if ($rest && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::NEXT_DELAY, self::HOOK);
        }
    }
}

Starter_Addon_Webp_Images::instance();
