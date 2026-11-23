<?php
/**
 * Starter Dashboard Addon: Complianz Fast Banner
 *
 * Complianz with geoIP asks /wp-json/complianz/v1/banner on the first visit and shows the
 * cookie banner only after the answer (2–6 s). This addon pre-fills the browser's
 * sessionStorage with the site's default consent type, so the banner shows immediately.
 * Trade-off: geoIP is skipped — every visitor gets the default (EU opt-in) banner.
 *
 * Disable in code:
 *   add_filter('mwts_cmplz_fast_banner', '__return_false');
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-fast-banner.php';

class Starter_Addon_Cmplz_Fast_Banner {

    private static $instance = null;

    /** @var bool Inline script already attached in this request. */
    private $added = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Complianz enqueues on wp_enqueue_scripts; the footer pass catches late enqueues.
        add_action('wp_enqueue_scripts', [$this, 'maybe_add_inline'], PHP_INT_MAX);
        add_action('wp_footer', [$this, 'maybe_add_inline'], 1);
    }

    public function maybe_add_inline() {
        if ($this->added || is_admin() || is_user_logged_in()) {
            return;
        }
        $handle = Starter_Cmplz_Fast_Banner::HANDLE;
        if (!wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'done')) {
            return;
        }
        if (!apply_filters('mwts_cmplz_fast_banner', true)) {
            return;
        }
        $this->added = wp_add_inline_script($handle, Starter_Cmplz_Fast_Banner::inline_js(), 'before');
    }
}

Starter_Addon_Cmplz_Fast_Banner::instance();
