<?php
/**
 * Starter Dashboard Addon: Front Speed
 *
 * Defers non-critical JavaScript and loads selected stylesheets asynchronously on the
 * front end for visitors who are not logged in. Modes (option starter_front_speed_mode):
 *   off  — nothing happens
 *   test — only on URLs with ?mwtperf=1 (default)
 *   on   — every page view of a logged-out visitor
 *
 * Filters: mwts_front_speed_mode, mwts_front_speed_js_exclude, mwts_front_speed_css_async.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-front-speed.php';

class Starter_Addon_Front_Speed {

    const OPTION = 'starter_front_speed_mode';

    private static $instance = null;

    /** @var bool|null Per-request decision, set once WordPress knows the query. */
    private $enabled = null;

    /** @var array<string, true>|null Script handles that stay synchronous. */
    private $sync = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('script_loader_tag', [$this, 'filter_script_tag'], 20, 2);
        add_filter('style_loader_tag', [$this, 'filter_style_tag'], 20, 2);
        add_action('wp_print_styles', [$this, 'maybe_dequeue_dashicons'], 1);
        add_action('wp_head', [$this, 'print_preconnect'], 1);
        // Footer scripts may get inline `after` code late: recompute the sync set before they print.
        add_action('wp_footer', [$this, 'reset_sync_handles'], 19);
        add_filter('starter_addon_save_settings_front-speed', [$this, 'save_settings'], 10, 2);
    }

    public static function get_mode() {
        $mode = apply_filters('mwts_front_speed_mode', get_option(self::OPTION, Starter_Front_Speed::DEFAULT_MODE));
        return Starter_Front_Speed::normalize_mode($mode);
    }

    private function is_enabled() {
        if ($this->enabled !== null) {
            return $this->enabled;
        }
        if (!did_action('wp')) {
            return false; // query not known yet (is_feed() etc.) — decide later
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag
        $test_flag = isset($_GET[Starter_Front_Speed::TEST_PARAM]) && $_GET[Starter_Front_Speed::TEST_PARAM] === '1';
        $this->enabled = Starter_Front_Speed::is_enabled(self::get_mode(), $test_flag, is_user_logged_in(), $this->is_excluded_request());
        return $this->enabled;
    }

    private function is_excluded_request() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_json_request()
            || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only checks
        if (isset($_GET['elementor-preview']) || isset($_GET['preview']) || isset($_GET['elementor_library'])) {
            return true;
        }
        if (is_feed() || is_preview() || is_customize_preview() || is_robots() || is_trackback() || is_embed()) {
            return true;
        }
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->preview)
            && \Elementor\Plugin::$instance->preview->is_preview_mode()) {
            return true;
        }
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout())) {
            return true;
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) {
                return true;
            }
        }
        return false;
    }

    private function sync_handles() {
        if ($this->sync === null) {
            $wp_scripts = wp_scripts();
            $scripts = [];
            foreach ($wp_scripts->registered as $handle => $dep) {
                $scripts[$handle] = [
                    'deps'  => (array) $dep->deps,
                    'after' => !empty($wp_scripts->get_data($handle, 'after')),
                ];
            }
            $exclude = (array) apply_filters('mwts_front_speed_js_exclude', Starter_Front_Speed::JS_EXCLUDE);
            $this->sync = Starter_Front_Speed::sync_handles($scripts, $exclude);
        }
        return $this->sync;
    }

    public function reset_sync_handles() {
        $this->sync = null;
    }

    public function filter_script_tag($tag, $handle) {
        if (!$this->is_enabled()) {
            return $tag;
        }
        return Starter_Front_Speed::defer_tag($tag, $handle, $this->sync_handles());
    }

    public function filter_style_tag($tag, $handle) {
        if (!$this->is_enabled()) {
            return $tag;
        }
        $async = (array) apply_filters('mwts_front_speed_css_async', Starter_Front_Speed::CSS_ASYNC);
        return Starter_Front_Speed::async_style_tag($tag, $handle, $async);
    }

    public function maybe_dequeue_dashicons() {
        if (!$this->is_enabled()) {
            return;
        }
        $styles = wp_styles();
        if (!in_array('dashicons', $styles->queue, true)) {
            return;
        }
        $deps = [];
        foreach ($styles->registered as $handle => $dep) {
            $deps[$handle] = (array) $dep->deps;
        }
        if (Starter_Front_Speed::can_dequeue_dashicons($styles->queue, $deps)) {
            wp_dequeue_style('dashicons');
        }
    }

    public function print_preconnect() {
        if ($this->is_enabled()) {
            echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>' . "\n";
        }
    }

    /**
     * Settings panel (dashboard modal).
     */
    public static function render_settings() {
        $mode = self::get_mode();
        $test_url = add_query_arg(Starter_Front_Speed::TEST_PARAM, '1', home_url('/'));
        ?>
        <div class="bp-addon-settings" data-addon="front-speed">
            <div class="bp-addon-settings__section">
                <h4><?php _e('Mode', 'starter-dashboard'); ?></h4>
                <div class="bp-addon-settings__field">
                    <label for="front-speed-mode"><?php _e('Optimisation mode', 'starter-dashboard'); ?></label>
                    <select id="front-speed-mode" name="mode">
                        <option value="off" <?php selected($mode, 'off'); ?>><?php _e('Off', 'starter-dashboard'); ?></option>
                        <option value="test" <?php selected($mode, 'test'); ?>><?php _e('Test — only with ?mwtperf=1', 'starter-dashboard'); ?></option>
                        <option value="on" <?php selected($mode, 'on'); ?>><?php _e('On — all logged-out visitors', 'starter-dashboard'); ?></option>
                    </select>
                    <p class="description">
                        <?php _e('Defers JavaScript and loads selected stylesheets asynchronously. Logged-in users never see the change. In test mode, open a page in a private window with ?mwtperf=1, e.g.:', 'starter-dashboard'); ?>
                        <code><?php echo esc_html($test_url); ?></code>
                    </p>
                    <?php if (has_filter('mwts_front_speed_mode')) : ?>
                        <p class="description"><?php _e('Note: the mode is overridden in code by the mwts_front_speed_mode filter.', 'starter-dashboard'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_settings($saved, $settings) {
        $mode = Starter_Front_Speed::normalize_mode(is_array($settings) ? ($settings['mode'] ?? '') : '');
        update_option(self::OPTION, $mode);
        return true;
    }
}

Starter_Addon_Front_Speed::instance();
