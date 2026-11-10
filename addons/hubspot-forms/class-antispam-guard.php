<?php
/**
 * HubSpot Forms: anti-spam guard (WordPress / Elementor glue).
 *
 * Runs before anything is sent to HubSpot:
 *  - Cloudflare Turnstile (widget injected into Elementor forms with the HubSpot action)
 *  - per-IP and per-IP+form rate limits (transients)
 *  - minimum fill time (HMAC-signed timestamp)
 * Rejections are written to the addon's Submission Log without personal data.
 *
 * Decision logic lives in Starter_HubSpot_Antispam (class-antispam.php).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-antispam.php';

class Starter_HubSpot_Antispam_Guard {

    const OPT_TURNSTILE_ENABLED = 'starter_hubspot_turnstile_enabled';
    const OPT_TURNSTILE_SITE_KEY = 'starter_hubspot_turnstile_site_key';
    const OPT_TURNSTILE_SECRET = 'starter_hubspot_turnstile_secret_key';
    const OPT_RATELIMIT_ENABLED = 'starter_hubspot_ratelimit_enabled';
    const OPT_RATELIMIT_IP_MAX = 'starter_hubspot_ratelimit_ip_max';
    const OPT_RATELIMIT_IP_WINDOW = 'starter_hubspot_ratelimit_ip_window';
    const OPT_RATELIMIT_FORM_MAX = 'starter_hubspot_ratelimit_form_max';
    const OPT_RATELIMIT_FORM_WINDOW = 'starter_hubspot_ratelimit_form_window';
    const OPT_TRUSTED_PROXY = 'starter_hubspot_trusted_proxy';
    const OPT_MINTIME_ENABLED = 'starter_hubspot_mintime_enabled';
    const OPT_MINTIME_SECONDS = 'starter_hubspot_mintime_seconds';

    const DEFAULT_RATELIMIT_IP_MAX = 10;
    const DEFAULT_RATELIMIT_IP_WINDOW = 600;
    const DEFAULT_RATELIMIT_FORM_MAX = 3;
    const DEFAULT_RATELIMIT_FORM_WINDOW = 600;
    const DEFAULT_MINTIME_SECONDS = 3;
    const MAX_WINDOW = DAY_IN_SECONDS;
    const MAX_SUBMISSIONS = 1000;
    const MAX_MINTIME_SECONDS = 60;
    const TIMESTAMP_MAX_AGE = 6 * HOUR_IN_SECONDS;
    // Signed timestamps older than this (cached pages) are refreshed by the browser on first focus
    const TIMESTAMP_REFRESH_AFTER = 30 * MINUTE_IN_SECONDS;
    const REJECTION_LOG_THROTTLE = MINUTE_IN_SECONDS;
    // Site-wide cap so a botnet cannot push real submissions out of the 100-entry log
    const REJECTION_LOG_MAX_PER_MINUTE = 10;

    const FIELD_TIMESTAMP = 'bp_hs_ts';
    const FIELD_TURNSTILE = 'cf-turnstile-response';
    // Elementor shows errors keyed by field id next to #form-field-{id}; the guard injects that anchor
    const ERROR_FIELD = 'bp_hubspot_antispam';

    const SCRIPT_HANDLE = 'starter-hubspot-antispam';
    const TURNSTILE_HANDLE = 'starter-hubspot-turnstile';
    const TURNSTILE_API = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=starterHubspotTurnstileReady';

    private static $instance = null;

    /** @var array<string, array{allowed: bool, reason: string}> verdicts per form for this request */
    private $verdicts = [];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Late priority: field validation and the honeypot run first, so failed
        // submissions do not consume rate-limit quota or a Turnstile check
        add_action('elementor_pro/forms/validation', [$this, 'validate'], 100, 2);
        add_action('wp_ajax_starter_hubspot_antispam_ts', [$this, 'ajax_fresh_timestamp']);
        add_action('wp_ajax_nopriv_starter_hubspot_antispam_ts', [$this, 'ajax_fresh_timestamp']);
        add_action('admin_notices', [$this, 'admin_notice']);
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public static function settings() {
        return [
            'turnstile_enabled' => get_option(self::OPT_TURNSTILE_ENABLED, 'no') === 'yes',
            'turnstile_site_key' => (string) get_option(self::OPT_TURNSTILE_SITE_KEY, ''),
            'turnstile_secret' => (string) get_option(self::OPT_TURNSTILE_SECRET, ''),
            'ratelimit_enabled' => get_option(self::OPT_RATELIMIT_ENABLED, 'no') === 'yes',
            'ratelimit_ip_max' => (int) get_option(self::OPT_RATELIMIT_IP_MAX, self::DEFAULT_RATELIMIT_IP_MAX),
            'ratelimit_ip_window' => (int) get_option(self::OPT_RATELIMIT_IP_WINDOW, self::DEFAULT_RATELIMIT_IP_WINDOW),
            'ratelimit_form_max' => (int) get_option(self::OPT_RATELIMIT_FORM_MAX, self::DEFAULT_RATELIMIT_FORM_MAX),
            'ratelimit_form_window' => (int) get_option(self::OPT_RATELIMIT_FORM_WINDOW, self::DEFAULT_RATELIMIT_FORM_WINDOW),
            'trusted_proxy' => get_option(self::OPT_TRUSTED_PROXY, 'no') === 'yes',
            'mintime_enabled' => get_option(self::OPT_MINTIME_ENABLED, 'no') === 'yes',
            'mintime_seconds' => (int) get_option(self::OPT_MINTIME_SECONDS, self::DEFAULT_MINTIME_SECONDS),
        ];
    }

    /**
     * Turnstile protects forms only when switched on AND both keys are saved.
     */
    public static function turnstile_active(?array $settings = null) {
        $settings = $settings ?? self::settings();
        return $settings['turnstile_enabled']
            && $settings['turnstile_site_key'] !== ''
            && $settings['turnstile_secret'] !== '';
    }

    public static function turnstile_misconfigured(?array $settings = null) {
        $settings = $settings ?? self::settings();
        return $settings['turnstile_enabled'] && !self::turnstile_active($settings);
    }

    /**
     * Whether forms need the anti-spam block (error anchor, timestamp, widget).
     */
    public function needs_form_markup() {
        $settings = self::settings();
        return self::needs_scripts($settings) || $settings['ratelimit_enabled'];
    }

    private static function needs_scripts(array $settings) {
        return self::turnstile_active($settings) || $settings['mintime_enabled'];
    }

    /**
     * Save settings sent by the addon settings panel. Nonce and capability are
     * checked by the addon loader before the save filter runs.
     */
    public static function save_settings(array $settings) {
        // Older panels without these fields must not switch protections off
        if (!isset($settings['antispam_settings_present'])) {
            return;
        }

        $flag = function ($key) use ($settings) {
            return isset($settings[$key]) && Starter_HubSpot_Language_Routing::is_truthy($settings[$key]) ? 'yes' : 'no';
        };
        $number = function ($key, $default, $min, $max) use ($settings) {
            $raw = isset($settings[$key]) ? wp_unslash($settings[$key]) : '';
            return Starter_HubSpot_Antispam::sanitize_int($raw, $default, $min, $max);
        };

        update_option(self::OPT_TURNSTILE_ENABLED, $flag('turnstile_enabled'));
        update_option(self::OPT_RATELIMIT_ENABLED, $flag('ratelimit_enabled'));
        update_option(self::OPT_TRUSTED_PROXY, $flag('trusted_proxy'));
        update_option(self::OPT_MINTIME_ENABLED, $flag('mintime_enabled'));

        if (isset($settings['turnstile_site_key'])) {
            update_option(self::OPT_TURNSTILE_SITE_KEY, Starter_HubSpot_Antispam::sanitize_key(wp_unslash($settings['turnstile_site_key'])));
        }

        // Secret field is rendered empty: empty = keep the saved secret
        $new_secret = isset($settings['turnstile_secret_key'])
            ? Starter_HubSpot_Antispam::sanitize_key(wp_unslash($settings['turnstile_secret_key']))
            : '';
        if ($new_secret !== '') {
            update_option(self::OPT_TURNSTILE_SECRET, $new_secret, false);
        } elseif (isset($settings['turnstile_secret_clear']) && Starter_HubSpot_Language_Routing::is_truthy($settings['turnstile_secret_clear'])) {
            delete_option(self::OPT_TURNSTILE_SECRET);
        }

        update_option(self::OPT_RATELIMIT_IP_MAX, $number('ratelimit_ip_max', self::DEFAULT_RATELIMIT_IP_MAX, 0, self::MAX_SUBMISSIONS));
        update_option(self::OPT_RATELIMIT_IP_WINDOW, $number('ratelimit_ip_window', self::DEFAULT_RATELIMIT_IP_WINDOW, 0, self::MAX_WINDOW));
        update_option(self::OPT_RATELIMIT_FORM_MAX, $number('ratelimit_form_max', self::DEFAULT_RATELIMIT_FORM_MAX, 0, self::MAX_SUBMISSIONS));
        update_option(self::OPT_RATELIMIT_FORM_WINDOW, $number('ratelimit_form_window', self::DEFAULT_RATELIMIT_FORM_WINDOW, 0, self::MAX_WINDOW));
        update_option(self::OPT_MINTIME_SECONDS, $number('mintime_seconds', self::DEFAULT_MINTIME_SECONDS, 1, self::MAX_MINTIME_SECONDS));
    }

    /**
     * Rate limit on, trusted proxy off, but this request came through
     * Cloudflare: every visitor would share Cloudflare's IP addresses.
     */
    public static function proxy_misconfigured(?array $settings = null) {
        $settings = $settings ?? self::settings();
        return $settings['ratelimit_enabled'] && !$settings['trusted_proxy'] && !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        $messages = [];
        if (self::turnstile_misconfigured($settings)) {
            $messages[] = __('Cloudflare Turnstile is switched on, but the site key or secret key is missing. Turnstile protection is OFF until both keys are saved.', 'starter-dashboard');
        }
        if (self::proxy_misconfigured($settings)) {
            $messages[] = __('The submission limit per IP is on, but this site is behind Cloudflare and "trusted proxy" is off. All visitors share Cloudflare\'s IP addresses and real people may be blocked. Switch on "Site is behind Cloudflare / a trusted proxy".', 'starter-dashboard');
        }
        foreach ($messages as $message) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('HubSpot Forms:', 'starter-dashboard'),
                esc_html($message)
            );
        }
    }

    /* ------------------------------------------------------------------
     * Frontend markup
     * ------------------------------------------------------------------ */

    /**
     * Add the anti-spam block (error anchor, signed timestamp, Turnstile
     * container) just before </form> of a captured Elementor form widget.
     */
    public function inject_markup($html, $widget) {
        // Called for every buffered HubSpot form; the error anchor is always added
        // so honeypot rejections also show a message next to the form
        if ($this->is_editor_request()) {
            return $html;
        }
        $settings = self::settings();

        $parts = ['<span id="form-field-' . self::ERROR_FIELD . '" class="bp-hubspot-antispam__anchor" hidden></span>'];
        if ($settings['mintime_enabled']) {
            $token = Starter_HubSpot_Antispam::sign_timestamp(time(), self::hmac_key());
            $parts[] = '<input type="hidden" class="bp-hubspot-antispam__ts" name="' . esc_attr(self::FIELD_TIMESTAMP) . '" value="' . esc_attr($token) . '">';
        }
        if (self::turnstile_active($settings)) {
            $widget_id = is_object($widget) && method_exists($widget, 'get_id') ? (string) $widget->get_id() : '';
            $parts[] = '<div class="bp-hubspot-turnstile" data-sitekey="' . esc_attr($settings['turnstile_site_key']) . '"'
                . ' data-form="' . esc_attr($widget_id) . '"></div>';
        }

        $block = '<div class="bp-hubspot-antispam">' . implode('', $parts) . '</div>';
        $injected = preg_replace('#</form>#i', $block . '</form>', $html, 1, $count);
        if ($count === 0) {
            return $html;
        }

        if (self::needs_scripts($settings)) {
            $this->enqueue_assets($settings);
        }
        return $injected;
    }

    private function is_editor_request() {
        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance)) {
            return false;
        }
        $plugin = \Elementor\Plugin::$instance;
        if (isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
            return true;
        }
        return isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode();
    }

    /**
     * Load the helper script (and Turnstile API) only on pages that rendered a
     * protected form. Popups printed after footer scripts get their tags printed directly.
     */
    private function enqueue_assets(array $settings) {
        if (!wp_script_is(self::SCRIPT_HANDLE, 'registered')) {
            wp_register_script(self::SCRIPT_HANDLE, false, [], null, true);
            wp_add_inline_script(self::SCRIPT_HANDLE, $this->frontend_js($settings));
        }
        $handles = [self::SCRIPT_HANDLE];

        if (self::turnstile_active($settings)) {
            if (!wp_script_is(self::TURNSTILE_HANDLE, 'registered')) {
                // No ?ver= : Cloudflare asks not to cache or alter api.js
                wp_register_script(self::TURNSTILE_HANDLE, self::TURNSTILE_API, [self::SCRIPT_HANDLE], null, true);
            }
            $handles[] = self::TURNSTILE_HANDLE;
        }

        if (did_action('wp_print_footer_scripts')) {
            wp_print_scripts($handles);
            return;
        }
        foreach ($handles as $handle) {
            wp_enqueue_script($handle);
        }
    }

    private function frontend_js(array $settings) {
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'tsField' => self::FIELD_TIMESTAMP,
            'tsRefreshAfter' => self::TIMESTAMP_REFRESH_AFTER,
            'turnstile' => [
                'appearance' => 'interaction-only',
                'responseField' => self::FIELD_TURNSTILE,
            ],
        ];
        $config = apply_filters('starter_hubspot_antispam_js_config', $config);

        return 'window.starterHubspotAntispam=' . wp_json_encode($config) . ';' . <<<'JS'
(function () {
    var cfg = window.starterHubspotAntispam || {};
    var apiReady = false;
    var refreshed = typeof WeakSet === 'function' ? new WeakSet() : null;

    function formOf(el) { return el && el.closest ? el.closest('form') : null; }
    function isVisible(el) { return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }

    function render(container, force) {
        if (!apiReady || !window.turnstile || container.hasAttribute('data-bp-rendered')) { return; }
        if (!force && !isVisible(formOf(container))) { return; }
        container.setAttribute('data-bp-rendered', '1');
        try {
            var id = window.turnstile.render(container, {
                sitekey: container.getAttribute('data-sitekey'),
                appearance: cfg.turnstile.appearance,
                'response-field-name': cfg.turnstile.responseField,
                'refresh-expired': 'auto'
            });
            container.setAttribute('data-bp-widget', id);
        } catch (e) {
            container.removeAttribute('data-bp-rendered');
        }
    }

    function renderAll(force) {
        var list = document.querySelectorAll('.bp-hubspot-turnstile:not([data-bp-rendered])');
        for (var i = 0; i < list.length; i++) { render(list[i], force); }
    }

    function resetForm(form) {
        var c = form && form.querySelector('.bp-hubspot-turnstile[data-bp-widget]');
        if (c && window.turnstile) {
            try { window.turnstile.reset(c.getAttribute('data-bp-widget')); } catch (e) {}
        }
    }

    // Cached pages carry an old timestamp: fetch a fresh one when the visitor starts filling in
    function refreshTimestamp(form) {
        var input = form.querySelector('input[name="' + cfg.tsField + '"]');
        if (!input || (refreshed && refreshed.has(form))) { return; }
        if (refreshed) { refreshed.add(form); }
        var issued = parseInt((input.value.split('.')[1] || '0'), 10);
        if (Date.now() / 1000 - issued < cfg.tsRefreshAfter) { return; }
        var body = new FormData();
        body.append('action', 'starter_hubspot_antispam_ts');
        fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r && r.success && r.data && r.data.token) { input.value = r.data.token; } })
            .catch(function () {});
    }

    window.starterHubspotTurnstileReady = function () { apiReady = true; renderAll(false); };
    // api.js already present (loaded by another plugin): onload will not call us
    if (window.turnstile && typeof window.turnstile.render === 'function') { window.starterHubspotTurnstileReady(); }

    document.addEventListener('focusin', function (e) {
        var form = formOf(e.target);
        if (!form || !form.querySelector('.bp-hubspot-antispam')) { return; }
        refreshTimestamp(form);
        var c = form.querySelector('.bp-hubspot-turnstile:not([data-bp-rendered])');
        if (c) { render(c, true); }
    });

    if (window.jQuery) {
        window.jQuery(document).on('elementor/popup/show', function () { renderAll(false); });
        window.jQuery(document).ajaxComplete(function (event, xhr, settings) {
            // Turnstile tokens are single-use: reset the widget whose token was just sent
            var data = settings && settings.data;
            if (typeof FormData === 'undefined' || !(data instanceof FormData)) { return; }
            if (data.get('action') !== 'elementor_pro_forms_send_form') { return; }
            var sent = data.get(cfg.turnstile.responseField);
            if (!sent) { return; }
            var inputs = document.querySelectorAll('.bp-hubspot-turnstile input[name="' + cfg.turnstile.responseField + '"]');
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].value === sent) { resetForm(formOf(inputs[i])); }
            }
        });
    }

    // Forms injected later (AJAX-loaded popups, tabs)
    if ('MutationObserver' in window) {
        var timer = null;
        new MutationObserver(function () {
            if (!apiReady || timer) { return; }
            timer = setTimeout(function () { timer = null; renderAll(false); }, 250);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
JS;
    }

    /**
     * Fresh signed timestamp for pages served from a cache.
     */
    public function ajax_fresh_timestamp() {
        nocache_headers();
        if (!self::settings()['mintime_enabled']) {
            wp_send_json_error(null, 404);
        }
        wp_send_json_success(['token' => Starter_HubSpot_Antispam::sign_timestamp(time(), self::hmac_key())]);
    }

    /* ------------------------------------------------------------------
     * Server-side checks
     * ------------------------------------------------------------------ */

    /**
     * Elementor validation hook. Only forms that use the HubSpot action.
     */
    public function validate($record, $ajax_handler) {
        if (!self::record_uses_hubspot($record)) {
            return;
        }
        // Another validator (fields, honeypot) already failed: nothing will be sent
        if (!empty($ajax_handler->errors)) {
            return;
        }
        $verdict = $this->verdict($record);
        if (!$verdict['allowed']) {
            self::add_rejection_error($ajax_handler, $verdict['reason']);
        }
    }

    /**
     * Safety net for EHFA_HubSpot_Action::run(): true only if this submission
     * passed (or now passes) every enabled check.
     */
    public function allow_submission($record, $ajax_handler) {
        $verdict = $this->verdict($record);
        if (!$verdict['allowed']) {
            self::add_rejection_error($ajax_handler, $verdict['reason']);
        }
        return $verdict['allowed'];
    }

    /**
     * Run the checks once per form per request (Turnstile tokens are single-use,
     * rate-limit hits must be counted once).
     */
    private function verdict($record) {
        $form = self::form_identity($record);
        if (!isset($this->verdicts[$form['key']])) {
            $reason = $this->run_checks($record, $form);
            $this->verdicts[$form['key']] = ['allowed' => $reason === null, 'reason' => (string) $reason];
        }
        return $this->verdicts[$form['key']];
    }

    /**
     * @return ?string null when allowed, otherwise the rejection reason.
     */
    private function run_checks($record, array $form) {
        $settings = self::settings();
        $ip = self::client_ip($settings);

        if (!apply_filters('starter_hubspot_antispam_applies', true, $record, $form)) {
            return null;
        }

        if ($settings['mintime_enabled']) {
            $token = isset($_POST[self::FIELD_TIMESTAMP]) ? sanitize_text_field(wp_unslash($_POST[self::FIELD_TIMESTAMP])) : '';
            $status = Starter_HubSpot_Antispam::verify_timestamp(
                $token,
                self::hmac_key(),
                time(),
                max(1, $settings['mintime_seconds']),
                self::TIMESTAMP_MAX_AGE
            );
            if ($status !== 'ok') {
                self::log_rejection('too-fast', $record, $ip, $status);
                return $status === 'too-fast' ? 'too-fast' : 'expired';
            }
        }

        if (self::turnstile_active($settings)) {
            $token = isset($_POST[self::FIELD_TURNSTILE]) ? sanitize_text_field(wp_unslash($_POST[self::FIELD_TURNSTILE])) : '';
            $result = Starter_HubSpot_Antispam::turnstile_verify($token, $settings['turnstile_secret'], $ip, [__CLASS__, 'http_post']);
            if (!$result['ok']) {
                self::log_rejection('turnstile', $record, $ip, $result['error']);
                return 'turnstile';
            }
        }

        if ($settings['ratelimit_enabled'] && !self::rate_limit_allows($settings, $ip, $form['rate_key'])) {
            self::log_rejection('rate-limit', $record, $ip, '');
            return 'rate-limit';
        }

        return null;
    }

    /**
     * wp_remote_post adapter for Starter_HubSpot_Antispam::turnstile_verify().
     */
    public static function http_post($url, array $body) {
        $response = wp_remote_post($url, [
            'timeout' => 8,
            'body' => $body,
            'redirection' => 0,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }

    /**
     * Two buckets: all forms from the IP, and this form from the IP. A hit is
     * recorded only when both allow it.
     */
    private static function rate_limit_allows(array $settings, $ip, $form_key) {
        $subject = Starter_HubSpot_Antispam::rate_limit_subject($ip);
        if ($subject === '') {
            // No usable IP: nothing to bucket by (should not happen with REMOTE_ADDR)
            return true;
        }
        $now = time();
        $buckets = [
            [
                'key' => 'starter_hs_rl_ip_' . md5($subject),
                'max' => $settings['ratelimit_ip_max'],
                'window' => $settings['ratelimit_ip_window'],
            ],
            [
                'key' => 'starter_hs_rl_form_' . md5($subject . '|' . $form_key),
                'max' => $settings['ratelimit_form_max'],
                'window' => $settings['ratelimit_form_window'],
            ],
        ];

        $decisions = [];
        foreach ($buckets as $bucket) {
            $decision = Starter_HubSpot_Antispam::rate_limit_decide(get_transient($bucket['key']), $now, $bucket['max'], $bucket['window']);
            if (!$decision['allowed']) {
                return false;
            }
            $decisions[] = [$bucket, $decision];
        }
        foreach ($decisions as list($bucket, $decision)) {
            if ($bucket['max'] > 0 && $bucket['window'] > 0) {
                set_transient($bucket['key'], $decision['hits'], $bucket['window']);
            }
        }
        return true;
    }

    /**
     * Elementor Pro only stops a submission when $ajax_handler->errors is not
     * empty (add_error_message() alone lets the actions run). The message is
     * shown next to the anchor injected by inject_markup().
     */
    public static function add_rejection_error($ajax_handler, $reason) {
        $ajax_handler->add_error(self::ERROR_FIELD, self::rejection_message($reason));
    }

    public static function rejection_message($reason) {
        switch ($reason) {
            case 'turnstile':
                return __('Please complete the security check and try again.', 'starter-dashboard');
            case 'rate-limit':
                return __('Too many submissions. Please wait a few minutes and try again.', 'starter-dashboard');
            case 'too-fast':
                return __('The form was sent too quickly. Please wait a moment and try again.', 'starter-dashboard');
            case 'expired':
                return __('This form has expired. Please reload the page and try again.', 'starter-dashboard');
            default:
                return __('Submission rejected.', 'starter-dashboard');
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public static function client_ip(?array $settings = null) {
        $settings = $settings ?? self::settings();
        return Starter_HubSpot_Antispam::client_ip($_SERVER, $settings['trusted_proxy']);
    }

    /**
     * HMAC key derived from WordPress salts; nothing extra is stored.
     */
    private static function hmac_key() {
        return hash_hmac('sha256', 'starter_hubspot_antispam', wp_salt('nonce'));
    }

    public static function record_uses_hubspot($record) {
        $settings = $record->get('form_settings');
        $actions = (array) ($settings['submit_actions'] ?? []);
        return in_array('hubspot', $actions, true);
    }

    /**
     * Form identity. Rate limits use the SOURCE HubSpot form GUID (before
     * language routing), so translated copies of one form share a bucket;
     * falls back to the Elementor widget id.
     *
     * @return array{key: string, rate_key: string, hubspot_form_id: string, elementor_form_id: string, form_name: string}
     */
    private static function form_identity($record) {
        $settings = (array) $record->get('form_settings');
        $hubspot = strtolower(sanitize_text_field((string) ($settings['hubspot_form_id'] ?? '')));
        $elementor = sanitize_key((string) ($settings['id'] ?? ($_POST['form_id'] ?? '')));
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        return [
            'key' => $post_id . '|' . $elementor . '|' . $hubspot,
            'rate_key' => $hubspot !== '' ? 'hs:' . $hubspot : 'el:' . $elementor,
            'hubspot_form_id' => $hubspot,
            'elementor_form_id' => $elementor,
            'form_name' => mb_substr(sanitize_text_field((string) ($settings['form_name'] ?? '')), 0, 100),
        ];
    }

    /**
     * Log a rejected submission. No field values, e-mail, phone, message or
     * raw IP; only reason, form identifiers, language and a keyed IP hash.
     * Throttled per reason+IP+form so floods do not flush the log or hammer the DB.
     */
    public static function log_rejection($reason, $record, $ip = null, $detail = '') {
        $ip = $ip ?? self::client_ip();
        $form = self::form_identity($record);
        $ip_hash = Starter_HubSpot_Antispam::ip_hash($ip, self::hmac_key());

        $throttle_key = 'starter_hs_rj_' . md5($reason . '|' . $ip_hash . '|' . $form['rate_key']);
        if (get_transient($throttle_key)) {
            return;
        }
        set_transient($throttle_key, 1, self::REJECTION_LOG_THROTTLE);

        $minute_key = 'starter_hs_rj_minute_' . (int) floor(time() / MINUTE_IN_SECONDS);
        $written = (int) get_transient($minute_key);
        if ($written >= self::REJECTION_LOG_MAX_PER_MINUTE) {
            return;
        }
        set_transient($minute_key, $written + 1, 2 * MINUTE_IN_SECONDS);

        $meta = (array) $record->get('meta');
        $page_url = (string) ($meta['page_url']['value'] ?? '');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $language = Starter_HubSpot_Language_Routing::detect($page_url, [$post_id, $_POST['queried_id'] ?? 0]);

        Starter_Addon_HubSpot_Forms::log_submission([
            'success' => false,
            'status' => 'rejected',
            'reason' => sanitize_key($reason),
            'detail' => substr(preg_replace('/[^a-z0-9,\-]/', '', strtolower((string) $detail)), 0, 100),
            'form_id' => $form['hubspot_form_id'],
            'form_name' => $form['form_name'],
            'elementor_form_id' => $form['elementor_form_id'],
            'post_id' => $post_id,
            'language' => $language,
            'ip_hash' => $ip_hash,
        ]);

        if (get_option('starter_hubspot_debug_mode', 'no') === 'yes') {
            error_log(sprintf('[Starter HubSpot] Rejected submission: reason=%s detail=%s form=%s ip_hash=%s', $reason, $detail, $form['rate_key'], $ip_hash));
        }
    }

    /* ------------------------------------------------------------------
     * Admin settings section
     * ------------------------------------------------------------------ */

    public static function render_settings_section() {
        $s = self::settings();
        $secret_mask = Starter_HubSpot_Antispam::mask_secret($s['turnstile_secret']);
        ?>
        <div class="bp-hubspot-section bp-hubspot-antispam-settings">
            <div class="bp-hubspot-section__header">
                <easier-icon name="shield-01" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                <h4><?php _e('Spam Protection (Turnstile, Rate Limit, Fill Time)', 'starter-dashboard'); ?></h4>
            </div>
            <div class="bp-hubspot-section__content">
                <input type="hidden" name="antispam_settings_present" value="1">
                <p class="description" style="margin-bottom:15px;">
                    <?php _e('Checked on the server before anything is sent to HubSpot. Applies to every Elementor form that uses the HubSpot action. Do not enable CAPTCHA in HubSpot itself: it would block the server-side submissions.', 'starter-dashboard'); ?>
                </p>

                <?php if (self::turnstile_misconfigured($s)): ?>
                <div class="notice notice-warning inline" style="margin:0 0 15px;">
                    <p><?php _e('Turnstile is switched on, but a key is missing. Protection stays OFF until both keys are saved.', 'starter-dashboard'); ?></p>
                </div>
                <?php endif; ?>
                <?php if (self::proxy_misconfigured($s)): ?>
                <div class="notice notice-warning inline" style="margin:0 0 15px;">
                    <p><?php _e('This site is behind Cloudflare, but "trusted proxy" is off: the IP limit counts Cloudflare\'s addresses, not visitors. Switch on the trusted proxy setting below.', 'starter-dashboard'); ?></p>
                </div>
                <?php endif; ?>

                <!-- Turnstile -->
                <div class="bp-hubspot-debug-toggle">
                    <label class="bp-hubspot-switch">
                        <input type="checkbox" name="turnstile_enabled" value="yes" <?php checked($s['turnstile_enabled']); ?>>
                        <span class="bp-hubspot-switch-slider"></span>
                    </label>
                    <div class="bp-hubspot-debug-info">
                        <strong><?php _e('Cloudflare Turnstile', 'starter-dashboard'); ?></strong>
                        <p class="description"><?php _e('Adds a Turnstile check to HubSpot forms. The widget stays invisible unless Cloudflare needs the visitor to click. Submissions without a valid token are rejected.', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <div class="bp-hubspot-config-grid" style="margin:10px 0 20px;">
                    <div class="bp-hubspot-field">
                        <label for="hubspot-turnstile-site-key"><?php _e('Turnstile Site Key', 'starter-dashboard'); ?></label>
                        <input type="text" id="hubspot-turnstile-site-key" name="turnstile_site_key" class="regular-text"
                               value="<?php echo esc_attr($s['turnstile_site_key']); ?>" placeholder="0x4AAAAAAA..." autocomplete="off">
                    </div>
                    <div class="bp-hubspot-field">
                        <label for="hubspot-turnstile-secret"><?php _e('Turnstile Secret Key', 'starter-dashboard'); ?></label>
                        <input type="password" id="hubspot-turnstile-secret" name="turnstile_secret_key" class="regular-text"
                               value="" autocomplete="new-password"
                               placeholder="<?php echo esc_attr($secret_mask !== '' ? $secret_mask : '0x4AAAAAAA...'); ?>">
                        <p class="description">
                            <?php if ($secret_mask !== ''): ?>
                                <?php _e('A secret is saved. Leave empty to keep it, or type a new one to replace it.', 'starter-dashboard'); ?>
                                <label style="display:block;margin-top:4px;">
                                    <input type="checkbox" name="turnstile_secret_clear" value="yes">
                                    <?php _e('Remove saved secret', 'starter-dashboard'); ?>
                                </label>
                            <?php else: ?>
                                <?php _e('Cloudflare dashboard → Turnstile → your widget → Settings.', 'starter-dashboard'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Rate limit -->
                <div class="bp-hubspot-debug-toggle">
                    <label class="bp-hubspot-switch">
                        <input type="checkbox" name="ratelimit_enabled" value="yes" <?php checked($s['ratelimit_enabled']); ?>>
                        <span class="bp-hubspot-switch-slider"></span>
                    </label>
                    <div class="bp-hubspot-debug-info">
                        <strong><?php _e('Limit submissions per IP address', 'starter-dashboard'); ?></strong>
                        <p class="description"><?php _e('Blocks an IP address that sends more than the allowed number of submissions in the time window. Set a limit to 0 to switch that limit off.', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <div class="bp-hubspot-config-grid" style="margin:10px 0 20px;">
                    <div class="bp-hubspot-field">
                        <label><?php _e('All forms: max submissions per IP', 'starter-dashboard'); ?></label>
                        <input type="number" min="0" max="<?php echo (int) self::MAX_SUBMISSIONS; ?>" name="ratelimit_ip_max" value="<?php echo (int) $s['ratelimit_ip_max']; ?>" class="small-text">
                        <?php _e('in', 'starter-dashboard'); ?>
                        <input type="number" min="0" max="<?php echo (int) self::MAX_WINDOW; ?>" name="ratelimit_ip_window" value="<?php echo (int) $s['ratelimit_ip_window']; ?>" class="small-text">
                        <?php _e('seconds', 'starter-dashboard'); ?>
                    </div>
                    <div class="bp-hubspot-field">
                        <label><?php _e('One form: max submissions per IP', 'starter-dashboard'); ?></label>
                        <input type="number" min="0" max="<?php echo (int) self::MAX_SUBMISSIONS; ?>" name="ratelimit_form_max" value="<?php echo (int) $s['ratelimit_form_max']; ?>" class="small-text">
                        <?php _e('in', 'starter-dashboard'); ?>
                        <input type="number" min="0" max="<?php echo (int) self::MAX_WINDOW; ?>" name="ratelimit_form_window" value="<?php echo (int) $s['ratelimit_form_window']; ?>" class="small-text">
                        <?php _e('seconds', 'starter-dashboard'); ?>
                        <p class="description"><?php _e('"One form" means one HubSpot form, counted together across languages.', 'starter-dashboard'); ?></p>
                    </div>
                </div>

                <!-- Trusted proxy -->
                <div class="bp-hubspot-debug-toggle" style="margin-bottom:20px;">
                    <label class="bp-hubspot-switch">
                        <input type="checkbox" name="trusted_proxy" value="yes" <?php checked($s['trusted_proxy']); ?>>
                        <span class="bp-hubspot-switch-slider"></span>
                    </label>
                    <div class="bp-hubspot-debug-info">
                        <strong><?php _e('Site is behind Cloudflare / a trusted proxy', 'starter-dashboard'); ?></strong>
                        <p class="description"><?php _e('Reads the visitor IP from CF-Connecting-IP or X-Forwarded-For. Turn on ONLY if all traffic reaches this server through that proxy; otherwise visitors can fake their IP. When off, the connection IP is used.', 'starter-dashboard'); ?></p>
                    </div>
                </div>

                <!-- Minimum fill time -->
                <div class="bp-hubspot-debug-toggle">
                    <label class="bp-hubspot-switch">
                        <input type="checkbox" name="mintime_enabled" value="yes" <?php checked($s['mintime_enabled']); ?>>
                        <span class="bp-hubspot-switch-slider"></span>
                    </label>
                    <div class="bp-hubspot-debug-info">
                        <strong><?php _e('Minimum time to fill in the form', 'starter-dashboard'); ?></strong>
                        <p class="description"><?php _e('Rejects forms sent faster than a person can type, and forms without a valid time stamp. Clear the page cache after switching this on.', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <div class="bp-hubspot-field" style="margin-top:10px;">
                    <label><?php _e('Minimum seconds', 'starter-dashboard'); ?></label>
                    <input type="number" min="1" max="<?php echo (int) self::MAX_MINTIME_SECONDS; ?>" name="mintime_seconds" value="<?php echo (int) $s['mintime_seconds']; ?>" class="small-text">
                </div>
            </div>
        </div>
        <?php
    }
}
