<?php
/**
 * Starter Dashboard Addon: HubSpot Forms
 *
 * Adds HubSpot form submission action to Elementor Pro Forms
 * with comprehensive dashboard, form browser, and submission logging
 */

defined('ABSPATH') || exit;

class Starter_Addon_HubSpot_Forms {

    private static $instance = null;
    private $option_portal = 'starter_hubspot_portal_id';
    private $option_token = 'starter_hubspot_access_token';
    private $option_debug = 'starter_hubspot_debug_mode';
    private $log_option = 'starter_hubspot_submission_log';
    private $max_log_entries = 100;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX handlers
        add_action('wp_ajax_starter_hubspot_get_forms', [$this, 'ajax_get_forms']);
        add_action('wp_ajax_starter_hubspot_get_form_fields', [$this, 'ajax_get_form_fields']);
        add_action('wp_ajax_starter_hubspot_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_starter_hubspot_get_elementor_forms', [$this, 'ajax_get_elementor_forms']);
        add_action('wp_ajax_starter_hubspot_get_submission_log', [$this, 'ajax_get_submission_log']);
        add_action('wp_ajax_starter_hubspot_clear_log', [$this, 'ajax_clear_log']);

        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_scripts']);

        // Register HubSpot action for Elementor Pro
        add_action('elementor_pro/forms/actions/register', [$this, 'register_form_action'], 20);

        // Settings save handler
        add_filter('starter_addon_save_settings_hubspot-forms', [$this, 'save_settings'], 10, 2);
    }

    /**
     * Register form action
     */
    public function register_form_action($actions_registrar) {
        require_once __DIR__ . '/class-hubspot-action.php';
        $actions_registrar->register(new EHFA_HubSpot_Action());
    }

    /**
     * Render settings panel (called via AJAX in dashboard)
     */
    public static function render_settings() {
        $instance = self::instance();
        $portal_id = get_option($instance->option_portal, '');
        $token = get_option($instance->option_token, '');
        $debug_mode = get_option($instance->option_debug, 'no');
        $is_configured = !empty($portal_id) && !empty($token);
        ?>
        <div class="bp-addon-settings bp-hubspot-dashboard" data-addon="hubspot-forms">

            <!-- API Configuration Section -->
            <div class="bp-hubspot-section bp-hubspot-config">
                <div class="bp-hubspot-section__header">
                    <easier-icon name="globe" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                    <h4><?php _e('API Configuration', 'starter-dashboard'); ?></h4>
                    <?php if ($is_configured): ?>
                        <span class="bp-hubspot-status bp-hubspot-status--connected">
                            <easier-icon name="tick-02" variant="twotone" size="16" color="currentColor"></easier-icon> <?php _e('Connected', 'starter-dashboard'); ?>
                        </span>
                    <?php else: ?>
                        <span class="bp-hubspot-status bp-hubspot-status--disconnected">
                            <easier-icon name="alert-02" variant="twotone" size="16" color="currentColor"></easier-icon> <?php _e('Not Connected', 'starter-dashboard'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="bp-hubspot-section__content">
                    <div class="bp-hubspot-config-grid">
                        <div class="bp-hubspot-field">
                            <label for="hubspot-portal-id"><?php _e('Portal ID (Hub ID)', 'starter-dashboard'); ?></label>
                            <input type="text"
                                   id="hubspot-portal-id"
                                   name="portal_id"
                                   value="<?php echo esc_attr($portal_id); ?>"
                                   placeholder="12345678"
                                   class="regular-text">
                            <p class="description"><?php _e('Find it in HubSpot Settings → Account Setup', 'starter-dashboard'); ?></p>
                        </div>

                        <div class="bp-hubspot-field">
                            <label for="hubspot-access-token"><?php _e('Private App Access Token', 'starter-dashboard'); ?></label>
                            <div class="bp-hubspot-token-wrapper">
                                <input type="password"
                                       id="hubspot-access-token"
                                       name="access_token"
                                       value="<?php echo esc_attr($token); ?>"
                                       placeholder="pat-..."
                                       class="regular-text">
                                <button type="button" class="button bp-hubspot-toggle-token">
                                    <easier-icon name="eye" variant="twotone" size="16" color="currentColor"></easier-icon>
                                </button>
                            </div>
                            <p class="description"><?php _e('Create in HubSpot Settings → Integrations → Private Apps (requires "forms" scope)', 'starter-dashboard'); ?></p>
                        </div>
                    </div>

                    <div class="bp-hubspot-config-actions">
                        <button type="button" class="button button-secondary bp-hubspot-test-connection" <?php echo !$is_configured ? 'disabled' : ''; ?>>
                            <easier-icon name="cloud" variant="twotone" size="16" color="currentColor"></easier-icon>
                            <?php _e('Test Connection', 'starter-dashboard'); ?>
                        </button>
                        <span class="bp-hubspot-test-result"></span>
                    </div>
                </div>
            </div>

            <!-- Debug Mode Section -->
            <div class="bp-hubspot-section bp-hubspot-debug">
                <div class="bp-hubspot-section__header">
                    <easier-icon name="bug" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                    <h4><?php _e('Debug Mode', 'starter-dashboard'); ?></h4>
                </div>
                <div class="bp-hubspot-section__content">
                    <div class="bp-hubspot-debug-toggle">
                        <label class="bp-hubspot-switch">
                            <input type="checkbox"
                                   name="debug_mode"
                                   value="yes"
                                   <?php checked($debug_mode, 'yes'); ?>>
                            <span class="bp-hubspot-switch-slider"></span>
                        </label>
                        <div class="bp-hubspot-debug-info">
                            <strong><?php _e('Enable detailed error logging', 'starter-dashboard'); ?></strong>
                            <p class="description">
                                <?php _e('When enabled, submissions log will show all submitted field values, API responses, and detailed error information. Independent of WP_DEBUG.', 'starter-dashboard'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($is_configured): ?>

            <!-- Tabs Navigation -->
            <div class="bp-hubspot-tabs">
                <button type="button" class="bp-hubspot-tab bp-hubspot-tab--active" data-tab="forms">
                    <easier-icon name="message-02" variant="twotone" size="16" color="currentColor"></easier-icon>
                    <?php _e('HubSpot Forms', 'starter-dashboard'); ?>
                </button>
                <button type="button" class="bp-hubspot-tab" data-tab="mappings">
                    <easier-icon name="shuffle" variant="twotone" size="16" color="currentColor"></easier-icon>
                    <?php _e('Elementor Mappings', 'starter-dashboard'); ?>
                </button>
                <button type="button" class="bp-hubspot-tab" data-tab="log">
                    <easier-icon name="list-view" variant="twotone" size="16" color="currentColor"></easier-icon>
                    <?php _e('Submission Log', 'starter-dashboard'); ?>
                </button>
            </div>

            <!-- Tab: HubSpot Forms Browser -->
            <div class="bp-hubspot-tab-content bp-hubspot-tab-content--active" data-tab="forms">
                <div class="bp-hubspot-section">
                    <div class="bp-hubspot-section__header">
                        <easier-icon name="message-02" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                        <h4><?php _e('HubSpot Forms', 'starter-dashboard'); ?></h4>
                        <button type="button" class="button button-small bp-hubspot-refresh-forms">
                            <easier-icon name="refresh" variant="twotone" size="14" color="currentColor"></easier-icon>
                            <?php _e('Refresh', 'starter-dashboard'); ?>
                        </button>
                    </div>
                    <div class="bp-hubspot-section__content">
                        <div class="bp-hubspot-forms-container">
                            <div class="bp-hubspot-forms-list" id="bp-hubspot-forms-list">
                                <div class="bp-hubspot-loading">
                                    <span class="spinner is-active"></span>
                                    <?php _e('Loading HubSpot forms...', 'starter-dashboard'); ?>
                                </div>
                            </div>
                            <div class="bp-hubspot-form-details" id="bp-hubspot-form-details">
                                <div class="bp-hubspot-form-details__placeholder">
                                    <easier-icon name="arrow-left-01" variant="twotone" size="24" color="#999"></easier-icon>
                                    <p><?php _e('Select a form to view its fields', 'starter-dashboard'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Elementor Mappings -->
            <div class="bp-hubspot-tab-content" data-tab="mappings">
                <div class="bp-hubspot-section">
                    <div class="bp-hubspot-section__header">
                        <easier-icon name="shuffle" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                        <h4><?php _e('Elementor Forms with HubSpot Action', 'starter-dashboard'); ?></h4>
                        <button type="button" class="button button-small bp-hubspot-refresh-mappings">
                            <easier-icon name="refresh" variant="twotone" size="14" color="currentColor"></easier-icon>
                            <?php _e('Refresh', 'starter-dashboard'); ?>
                        </button>
                    </div>
                    <div class="bp-hubspot-section__content">
                        <p class="description" style="margin-bottom: 15px;">
                            <?php _e('Forms using HubSpot integration and their field mappings. Click a form to see mapping details.', 'starter-dashboard'); ?>
                        </p>
                        <div class="bp-hubspot-elementor-forms" id="bp-hubspot-elementor-forms">
                            <div class="bp-hubspot-loading">
                                <span class="spinner is-active"></span>
                                <?php _e('Scanning Elementor forms...', 'starter-dashboard'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Submission Log -->
            <div class="bp-hubspot-tab-content" data-tab="log">
                <div class="bp-hubspot-section">
                    <div class="bp-hubspot-section__header">
                        <easier-icon name="list-view" variant="twotone" size="20" color="#ff7a59"></easier-icon>
                        <h4><?php _e('Submission Log', 'starter-dashboard'); ?></h4>
                        <div class="bp-hubspot-log-actions">
                            <button type="button" class="button button-small bp-hubspot-refresh-log">
                                <easier-icon name="refresh" variant="twotone" size="14" color="currentColor"></easier-icon>
                                <?php _e('Refresh', 'starter-dashboard'); ?>
                            </button>
                            <button type="button" class="button button-small bp-hubspot-clear-log">
                                <easier-icon name="delete-04" variant="twotone" size="14" color="currentColor"></easier-icon>
                                <?php _e('Clear Log', 'starter-dashboard'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="bp-hubspot-section__content">
                        <p class="description" style="margin-bottom: 15px;">
                            <?php _e('Recent form submissions to HubSpot. Shows success/failure status and details.', 'starter-dashboard'); ?>
                        </p>
                        <div class="bp-hubspot-log-filters">
                            <select id="bp-hubspot-log-filter">
                                <option value="all"><?php _e('All Submissions', 'starter-dashboard'); ?></option>
                                <option value="success"><?php _e('Successful Only', 'starter-dashboard'); ?></option>
                                <option value="error"><?php _e('Errors Only', 'starter-dashboard'); ?></option>
                            </select>
                        </div>
                        <div class="bp-hubspot-log-container" id="bp-hubspot-log-container">
                            <div class="bp-hubspot-loading">
                                <span class="spinner is-active"></span>
                                <?php _e('Loading submission log...', 'starter-dashboard'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>

            <!-- Not Configured Message -->
            <div class="bp-hubspot-section bp-hubspot-not-configured">
                <div class="bp-hubspot-not-configured__content">
                    <easier-icon name="information-circle" variant="twotone" size="48" color="#ff7a59"></easier-icon>
                    <h4><?php _e('Configure HubSpot API to Get Started', 'starter-dashboard'); ?></h4>
                    <p><?php _e('Enter your Portal ID and Private App Access Token above to connect to HubSpot and access forms browser, mappings overview, and submission logging.', 'starter-dashboard'); ?></p>
                    <ol>
                        <li><?php _e('Log in to your HubSpot account', 'starter-dashboard'); ?></li>
                        <li><?php _e('Go to Settings → Account Setup to find your Portal ID', 'starter-dashboard'); ?></li>
                        <li><?php _e('Go to Settings → Integrations → Private Apps', 'starter-dashboard'); ?></li>
                        <li><?php _e('Create a new Private App with "forms" scope', 'starter-dashboard'); ?></li>
                        <li><?php _e('Copy the Access Token and paste it above', 'starter-dashboard'); ?></li>
                    </ol>
                </div>
            </div>

            <?php endif; ?>
        </div>

        <style>
        /* HubSpot Dashboard Styles */
        .bp-hubspot-dashboard {
            max-width: 100%;
        }

        .bp-hubspot-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .bp-hubspot-section__header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
        }

        .bp-hubspot-section__header h4 {
            margin: 0;
            flex: 1;
            font-size: 14px;
            font-weight: 600;
        }

        .bp-hubspot-section__header easier-icon {
            --ei-stroke-color: #ff7a59;
            --ei-color: #ff7a59;
        }

        .bp-hubspot-section__content {
            padding: 20px;
        }

        .bp-hubspot-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .bp-hubspot-status--connected {
            background: #d4edda;
            color: #155724;
        }

        .bp-hubspot-status--disconnected {
            background: #fff3cd;
            color: #856404;
        }

        .bp-hubspot-config-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .bp-hubspot-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1e1e1e;
        }

        .bp-hubspot-field input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .bp-hubspot-field .description {
            margin-top: 6px;
            color: #666;
            font-size: 12px;
        }

        .bp-hubspot-token-wrapper {
            display: flex;
            gap: 5px;
        }

        .bp-hubspot-token-wrapper input {
            flex: 1;
        }

        .bp-hubspot-config-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .bp-hubspot-test-result {
            font-size: 13px;
        }

        .bp-hubspot-test-result.success {
            color: #155724;
        }

        .bp-hubspot-test-result.error {
            color: #721c24;
        }

        /* Tabs */
        .bp-hubspot-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 0;
            border-bottom: 1px solid #ddd;
            background: #f8f9fa;
            padding: 0 10px;
            border-radius: 8px 8px 0 0;
        }

        .bp-hubspot-tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -1px;
            cursor: pointer;
            font-size: 13px;
            color: #666;
            transition: all 0.2s;
        }

        .bp-hubspot-tab:hover {
            color: #ff7a59;
        }

        .bp-hubspot-tab--active {
            color: #ff7a59;
            border-bottom-color: #ff7a59;
            background: #fff;
        }

        .bp-hubspot-tab easier-icon {
            vertical-align: middle;
        }

        .bp-hubspot-tab-content {
            display: none;
        }

        .bp-hubspot-tab-content--active {
            display: block;
        }

        .bp-hubspot-tab-content > .bp-hubspot-section {
            border-radius: 0 0 8px 8px;
            margin-top: 0;
            border-top: none;
        }

        /* Forms Browser */
        .bp-hubspot-forms-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            min-height: 400px;
        }

        .bp-hubspot-forms-list {
            border: 1px solid #ddd;
            border-radius: 6px;
            max-height: 500px;
            overflow-y: auto;
        }

        .bp-hubspot-form-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }

        .bp-hubspot-form-item:last-child {
            border-bottom: none;
        }

        .bp-hubspot-form-item:hover {
            background: #f8f9fa;
        }

        .bp-hubspot-form-item--active {
            background: #fff5f2;
            border-left: 3px solid #ff7a59;
        }

        .bp-hubspot-form-item__name {
            font-weight: 600;
            color: #1e1e1e;
            margin-bottom: 4px;
        }

        .bp-hubspot-form-item__meta {
            font-size: 11px;
            color: #888;
            display: flex;
            gap: 10px;
        }

        .bp-hubspot-form-details {
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #fafafa;
        }

        .bp-hubspot-form-details__placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            min-height: 300px;
            color: #888;
            text-align: center;
        }

        .bp-hubspot-form-details__placeholder easier-icon {
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .bp-hubspot-form-details__content {
            padding: 20px;
        }

        .bp-hubspot-form-details__header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ddd;
        }

        .bp-hubspot-form-details__title {
            font-size: 16px;
            font-weight: 600;
            color: #1e1e1e;
            margin: 0 0 8px 0;
        }

        .bp-hubspot-form-details__id {
            font-size: 12px;
            color: #888;
            font-family: monospace;
        }

        .bp-hubspot-fields-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .bp-hubspot-fields-table th,
        .bp-hubspot-fields-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .bp-hubspot-fields-table th {
            background: #f0f0f0;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
        }

        .bp-hubspot-fields-table tr:hover td {
            background: #f8f9fa;
        }

        .bp-hubspot-field-name {
            font-family: monospace;
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .bp-hubspot-field-required {
            color: #dc3545;
            font-weight: bold;
        }

        .bp-hubspot-field-type {
            color: #666;
            font-size: 12px;
        }

        /* Elementor Mappings */
        .bp-hubspot-elementor-forms {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .bp-hubspot-elementor-form {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }

        .bp-hubspot-elementor-form__header {
            display: grid;
            grid-template-columns: 1fr 1fr 30px;
            align-items: center;
            gap: 20px;
            padding: 15px 20px;
            background: #f8f9fa;
            cursor: pointer;
            transition: background 0.2s;
        }

        .bp-hubspot-elementor-form__header:hover {
            background: #f0f0f0;
        }

        .bp-hubspot-elementor-form__info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .bp-hubspot-elementor-form__title {
            font-weight: 600;
            color: #1e1e1e;
        }

        .bp-hubspot-elementor-form__hubspot {
            font-size: 13px;
            color: #ff7a59;
            text-align: left;
        }

        .bp-hubspot-elementor-form__toggle {
            color: #666;
            transition: transform 0.2s;
            text-align: right;
        }

        .bp-hubspot-elementor-form--expanded .bp-hubspot-elementor-form__toggle {
            transform: rotate(180deg);
        }

        .bp-hubspot-elementor-form__details {
            display: none;
            padding: 20px;
            border-top: 1px solid #ddd;
            background: #fff;
        }

        .bp-hubspot-elementor-form--expanded .bp-hubspot-elementor-form__details {
            display: block;
        }

        .bp-hubspot-mapping-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .bp-hubspot-mapping-table th,
        .bp-hubspot-mapping-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .bp-hubspot-mapping-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
        }

        .bp-hubspot-mapping-arrow {
            color: #ff7a59;
            font-size: 16px;
        }

        /* Submission Log */
        .bp-hubspot-log-filters {
            margin-bottom: 15px;
        }

        .bp-hubspot-log-filters select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .bp-hubspot-log-container {
            border: 1px solid #ddd;
            border-radius: 6px;
            max-height: 500px;
            overflow-y: auto;
        }

        .bp-hubspot-log-entry {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }

        .bp-hubspot-log-entry:last-child {
            border-bottom: none;
        }

        .bp-hubspot-log-entry__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .bp-hubspot-log-entry__status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .bp-hubspot-log-entry__status--success {
            background: #d4edda;
            color: #155724;
        }

        .bp-hubspot-log-entry__status--error {
            background: #f8d7da;
            color: #721c24;
        }

        .bp-hubspot-log-entry__time {
            font-size: 12px;
            color: #888;
        }

        .bp-hubspot-log-entry__info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            font-size: 13px;
        }

        .bp-hubspot-log-entry__label {
            font-size: 11px;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .bp-hubspot-log-entry__value {
            color: #1e1e1e;
        }

        .bp-hubspot-log-entry__error {
            margin-top: 10px;
            padding: 10px;
            background: #fff5f5;
            border-left: 3px solid #dc3545;
            font-size: 13px;
            color: #721c24;
        }

        .bp-hubspot-log-entry__fields {
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 12px;
        }

        .bp-hubspot-log-entry__fields-toggle {
            cursor: pointer;
            color: #0073aa;
            font-size: 12px;
        }

        .bp-hubspot-log-entry__fields-content {
            display: none;
            margin-top: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .bp-hubspot-log-entry__fields-content.visible {
            display: block;
        }

        /* Debug Mode Styles */
        .bp-hubspot-debug-toggle {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .bp-hubspot-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
            flex-shrink: 0;
        }

        .bp-hubspot-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .bp-hubspot-switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 26px;
        }

        .bp-hubspot-switch-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        .bp-hubspot-switch input:checked + .bp-hubspot-switch-slider {
            background-color: #ff7a59;
        }

        .bp-hubspot-switch input:checked + .bp-hubspot-switch-slider:before {
            transform: translateX(24px);
        }

        .bp-hubspot-debug-info {
            flex: 1;
        }

        .bp-hubspot-debug-info strong {
            display: block;
            margin-bottom: 5px;
            color: #1e1e1e;
        }

        .bp-hubspot-log-entry__debug {
            margin-top: 15px;
            padding: 12px;
            background: #f0f7ff;
            border: 1px solid #d0e8ff;
            border-radius: 6px;
        }

        .bp-hubspot-log-entry__debug-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: #2196F3;
            color: white;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .bp-hubspot-log-entry__debug-section {
            margin-bottom: 10px;
        }

        .bp-hubspot-log-entry__debug-section:last-child {
            margin-bottom: 0;
        }

        .bp-hubspot-log-entry__debug-toggle {
            display: block;
            cursor: pointer;
            color: #2196F3;
            font-weight: 600;
            font-size: 13px;
            padding: 8px 10px;
            background: white;
            border: 1px solid #d0e8ff;
            border-radius: 4px;
            user-select: none;
        }

        .bp-hubspot-log-entry__debug-toggle:hover {
            background: #f8fbff;
        }

        .bp-hubspot-log-entry__debug-content {
            display: none;
            margin-top: 8px;
            padding: 12px;
            background: #1e1e1e;
            color: #f8f8f2;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        .bp-hubspot-log-entry__debug-content.visible {
            display: block;
        }

        .bp-hubspot-log-empty {
            padding: 40px;
            text-align: center;
            color: #888;
        }

        .bp-hubspot-log-empty easier-icon {
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Not Configured */
        .bp-hubspot-not-configured__content {
            text-align: center;
            padding: 40px;
        }

        .bp-hubspot-not-configured__content easier-icon {
            --ei-stroke-color: #ff7a59;
            --ei-color: #ff7a59;
            margin-bottom: 20px;
        }

        .bp-hubspot-not-configured__content h4 {
            font-size: 18px;
            margin: 0 0 15px 0;
        }

        .bp-hubspot-not-configured__content ol {
            text-align: left;
            max-width: 400px;
            margin: 20px auto 0;
        }

        .bp-hubspot-not-configured__content li {
            margin-bottom: 8px;
            color: #666;
        }

        /* Loading State */
        .bp-hubspot-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 40px;
            color: #666;
        }

        .bp-hubspot-loading .spinner {
            float: none;
            margin: 0;
        }

        /* Log Actions */
        .bp-hubspot-log-actions {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 782px) {
            .bp-hubspot-config-grid {
                grid-template-columns: 1fr;
            }

            .bp-hubspot-forms-container {
                grid-template-columns: 1fr;
            }

            .bp-hubspot-log-entry__info {
                grid-template-columns: 1fr;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var hubspotNonce = '<?php echo wp_create_nonce('starter_settings'); ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var formsCache = {};
            var fieldsCache = {};

            // Initialize
            function init() {
                initTabs();
                initConfigHandlers();

                <?php if ($is_configured): ?>
                loadHubSpotForms();
                loadElementorForms();
                loadSubmissionLog();
                <?php endif; ?>
            }

            // Tabs
            function initTabs() {
                $('.bp-hubspot-tab').on('click', function() {
                    var tab = $(this).data('tab');
                    $('.bp-hubspot-tab').removeClass('bp-hubspot-tab--active');
                    $(this).addClass('bp-hubspot-tab--active');
                    $('.bp-hubspot-tab-content').removeClass('bp-hubspot-tab-content--active');
                    $('.bp-hubspot-tab-content[data-tab="' + tab + '"]').addClass('bp-hubspot-tab-content--active');
                });
            }

            // Config handlers
            function initConfigHandlers() {
                // Toggle token visibility
                $('.bp-hubspot-toggle-token').on('click', function() {
                    var input = $('#hubspot-access-token');
                    var icon = $(this).find('easier-icon');
                    if (input.attr('type') === 'password') {
                        input.attr('type', 'text');
                        icon.attr('name', 'view');
                    } else {
                        input.attr('type', 'password');
                        icon.attr('name', 'eye');
                    }
                });

                // Test connection
                $('.bp-hubspot-test-connection').on('click', function() {
                    var btn = $(this);
                    var result = $('.bp-hubspot-test-result');

                    btn.prop('disabled', true);
                    result.removeClass('success error').text('Testing...');

                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'starter_hubspot_test_connection',
                            nonce: hubspotNonce
                        },
                        success: function(response) {
                            btn.prop('disabled', false);
                            if (response.success) {
                                result.addClass('success').html('<easier-icon name="tick-02" variant="twotone" size="16" color="currentColor"></easier-icon> ' + response.data.message);
                            } else {
                                result.addClass('error').html('<easier-icon name="cancel-01" variant="twotone" size="16" color="currentColor"></easier-icon> ' + response.data);
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false);
                            result.addClass('error').text('Connection failed');
                        }
                    });
                });

                // Refresh buttons
                $('.bp-hubspot-refresh-forms').on('click', function() {
                    formsCache = {};
                    loadHubSpotForms(true); // Force refresh from API
                });

                $('.bp-hubspot-refresh-mappings').on('click', function() {
                    loadElementorForms();
                });

                $('.bp-hubspot-refresh-log').on('click', function() {
                    loadSubmissionLog();
                });

                // Clear log
                $('.bp-hubspot-clear-log').on('click', function() {
                    if (confirm('<?php _e('Are you sure you want to clear the submission log?', 'starter-dashboard'); ?>')) {
                        clearSubmissionLog();
                    }
                });

                // Log filter
                $('#bp-hubspot-log-filter').on('change', function() {
                    filterLog($(this).val());
                });
            }

            // Load HubSpot Forms
            function loadHubSpotForms(forceRefresh) {
                var container = $('#bp-hubspot-forms-list');
                container.html('<div class="bp-hubspot-loading"><span class="spinner is-active"></span> <?php _e('Loading forms...', 'starter-dashboard'); ?></div>');

                var requestData = {
                    action: 'starter_hubspot_get_forms',
                    nonce: hubspotNonce
                };
                if (forceRefresh) {
                    requestData.force_refresh = '1';
                }

                $.ajax({
                    url: ajaxUrl,
                    type: 'GET',
                    data: requestData,
                    success: function(response) {
                        if (response.success && response.data) {
                            formsCache = response.data;
                            renderFormsList(response.data);
                        } else {
                            container.html('<div class="bp-hubspot-log-empty"><easier-icon name="alert-02" variant="twotone" size="24" color="#ff7a59"></easier-icon><p>' + (response.data || 'Error loading forms') + '</p></div>');
                        }
                    },
                    error: function() {
                        container.html('<div class="bp-hubspot-log-empty"><easier-icon name="alert-02" variant="twotone" size="24" color="#ff7a59"></easier-icon><p><?php _e('Error loading forms', 'starter-dashboard'); ?></p></div>');
                    }
                });
            }

            // Render forms list
            function renderFormsList(forms) {
                var container = $('#bp-hubspot-forms-list');

                if (!forms || forms.length === 0) {
                    container.html('<div class="bp-hubspot-log-empty"><easier-icon name="message-02" variant="twotone" size="24" color="#999"></easier-icon><p><?php _e('No forms found', 'starter-dashboard'); ?></p></div>');
                    return;
                }

                var html = '';
                forms.forEach(function(form) {
                    var date = form.createdAt ? new Date(form.createdAt).toLocaleDateString() : '';
                    html += '<div class="bp-hubspot-form-item" data-form-id="' + form.id + '">';
                    html += '<div class="bp-hubspot-form-item__name">' + escapeHtml(form.name) + '</div>';
                    html += '<div class="bp-hubspot-form-item__meta">';
                    html += '<span><?php _e('ID:', 'starter-dashboard'); ?> ' + form.id.substring(0, 8) + '...</span>';
                    if (date) {
                        html += '<span><?php _e('Created:', 'starter-dashboard'); ?> ' + date + '</span>';
                    }
                    html += '</div></div>';
                });

                container.html(html);

                // Click handler
                container.on('click', '.bp-hubspot-form-item', function() {
                    var formId = $(this).data('form-id');
                    $('.bp-hubspot-form-item').removeClass('bp-hubspot-form-item--active');
                    $(this).addClass('bp-hubspot-form-item--active');
                    loadFormFields(formId);
                });
            }

            // Load form fields
            function loadFormFields(formId) {
                var container = $('#bp-hubspot-form-details');

                // Check cache
                if (fieldsCache[formId]) {
                    renderFormFields(formId, fieldsCache[formId]);
                    return;
                }

                container.html('<div class="bp-hubspot-loading"><span class="spinner is-active"></span> <?php _e('Loading fields...', 'starter-dashboard'); ?></div>');

                $.ajax({
                    url: ajaxUrl,
                    type: 'GET',
                    data: {
                        action: 'starter_hubspot_get_form_fields',
                        form_guid: formId,
                        nonce: hubspotNonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            fieldsCache[formId] = response.data;
                            renderFormFields(formId, response.data);
                        } else {
                            container.html('<div class="bp-hubspot-log-empty"><p>' + (response.data || 'Error loading fields') + '</p></div>');
                        }
                    },
                    error: function() {
                        container.html('<div class="bp-hubspot-log-empty"><p><?php _e('Error loading fields', 'starter-dashboard'); ?></p></div>');
                    }
                });
            }

            // Render form fields
            function renderFormFields(formId, fields) {
                var container = $('#bp-hubspot-form-details');
                var form = formsCache.find(function(f) { return f.id === formId; });
                var formName = form ? form.name : formId;

                var html = '<div class="bp-hubspot-form-details__content">';
                html += '<div class="bp-hubspot-form-details__header">';
                html += '<h4 class="bp-hubspot-form-details__title">' + escapeHtml(formName) + '</h4>';
                html += '<div class="bp-hubspot-form-details__id"><?php _e('Form ID:', 'starter-dashboard'); ?> ' + formId + '</div>';
                html += '</div>';

                if (fields && fields.length > 0) {
                    html += '<table class="bp-hubspot-fields-table">';
                    html += '<thead><tr>';
                    html += '<th><?php _e('Field Name', 'starter-dashboard'); ?></th>';
                    html += '<th><?php _e('Label', 'starter-dashboard'); ?></th>';
                    html += '<th><?php _e('Type', 'starter-dashboard'); ?></th>';
                    html += '<th><?php _e('Required', 'starter-dashboard'); ?></th>';
                    html += '</tr></thead><tbody>';

                    fields.forEach(function(field) {
                        html += '<tr>';
                        html += '<td><span class="bp-hubspot-field-name">' + escapeHtml(field.name) + '</span></td>';
                        html += '<td>' + escapeHtml(field.label || '-') + '</td>';
                        html += '<td><span class="bp-hubspot-field-type">' + escapeHtml(field.type || 'text') + '</span></td>';
                        html += '<td>' + (field.required ? '<span class="bp-hubspot-field-required">*</span>' : '-') + '</td>';
                        html += '</tr>';
                    });

                    html += '</tbody></table>';
                } else {
                    html += '<p><?php _e('No fields found in this form', 'starter-dashboard'); ?></p>';
                }

                html += '</div>';
                container.html(html);
            }

            // Load Elementor Forms with HubSpot
            function loadElementorForms() {
                var container = $('#bp-hubspot-elementor-forms');
                container.html('<div class="bp-hubspot-loading"><span class="spinner is-active"></span> <?php _e('Scanning forms...', 'starter-dashboard'); ?></div>');

                $.ajax({
                    url: ajaxUrl,
                    type: 'GET',
                    data: {
                        action: 'starter_hubspot_get_elementor_forms',
                        nonce: hubspotNonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            renderElementorForms(response.data);
                        } else {
                            container.html('<div class="bp-hubspot-log-empty"><easier-icon name="information-circle" variant="twotone" size="24" color="#999"></easier-icon><p>' + (response.data || '<?php _e('No forms with HubSpot integration found', 'starter-dashboard'); ?>') + '</p></div>');
                        }
                    },
                    error: function() {
                        container.html('<div class="bp-hubspot-log-empty"><p><?php _e('Error scanning forms', 'starter-dashboard'); ?></p></div>');
                    }
                });
            }

            // Render Elementor Forms
            function renderElementorForms(forms) {
                var container = $('#bp-hubspot-elementor-forms');

                if (!forms || forms.length === 0) {
                    container.html('<div class="bp-hubspot-log-empty"><easier-icon name="information-circle" variant="twotone" size="24" color="#999"></easier-icon><p><?php _e('No Elementor forms with HubSpot integration found. Add "HubSpot" action to your Elementor forms.', 'starter-dashboard'); ?></p></div>');
                    return;
                }

                var html = '';
                forms.forEach(function(form, index) {
                    html += '<div class="bp-hubspot-elementor-form" data-index="' + index + '">';
                    html += '<div class="bp-hubspot-elementor-form__header">';
                    html += '<div class="bp-hubspot-elementor-form__info">';
                    html += '<span class="bp-hubspot-elementor-form__title">' + escapeHtml(form.page_title) + '</span>';
                    html += '</div>';
                    html += '<div class="bp-hubspot-elementor-form__hubspot">';
                    html += escapeHtml(form.hubspot_form_name || form.hubspot_form_id);
                    html += '</div>';
                    html += '<span class="bp-hubspot-elementor-form__toggle"><easier-icon name="arrow-down-03" variant="twotone" size="16" color="#999"></easier-icon></span>';
                    html += '</div>';

                    html += '<div class="bp-hubspot-elementor-form__details">';

                    if (form.mappings && form.mappings.length > 0) {
                        html += '<table class="bp-hubspot-mapping-table">';
                        html += '<thead><tr>';
                        html += '<th><?php _e('Elementor Field ID', 'starter-dashboard'); ?></th>';
                        html += '<th></th>';
                        html += '<th><?php _e('HubSpot Field', 'starter-dashboard'); ?></th>';
                        html += '</tr></thead><tbody>';

                        form.mappings.forEach(function(mapping) {
                            html += '<tr>';
                            html += '<td><span class="bp-hubspot-field-name">' + escapeHtml(mapping.elementor || mapping.local_id || '-') + '</span></td>';
                            html += '<td class="bp-hubspot-mapping-arrow">→</td>';
                            html += '<td><span class="bp-hubspot-field-name">' + escapeHtml(mapping.hubspot || mapping.remote_id || '-') + '</span></td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                    } else {
                        html += '<p style="color: #888; font-style: italic;"><?php _e('No field mappings configured. Fields will be auto-mapped by ID.', 'starter-dashboard'); ?></p>';
                    }

                    html += '<div style="margin-top: 15px; font-size: 12px; color: #888;">';
                    html += '<strong><?php _e('Page:', 'starter-dashboard'); ?></strong> <a href="' + form.edit_url + '" target="_blank">' + escapeHtml(form.page_title) + ' <easier-icon name="share-08" variant="twotone" size="12" color="currentColor" style="vertical-align:middle;"></easier-icon></a>';
                    html += '</div>';

                    html += '</div></div>';
                });

                container.html(html);

                // Toggle expand
                container.on('click', '.bp-hubspot-elementor-form__header', function() {
                    $(this).closest('.bp-hubspot-elementor-form').toggleClass('bp-hubspot-elementor-form--expanded');
                });
            }

            // Load Submission Log
            function loadSubmissionLog() {
                var container = $('#bp-hubspot-log-container');
                container.html('<div class="bp-hubspot-loading"><span class="spinner is-active"></span> <?php _e('Loading log...', 'starter-dashboard'); ?></div>');

                $.ajax({
                    url: ajaxUrl,
                    type: 'GET',
                    data: {
                        action: 'starter_hubspot_get_submission_log',
                        nonce: hubspotNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            renderSubmissionLog(response.data);
                        } else {
                            container.html('<div class="bp-hubspot-log-empty"><p>' + (response.data || 'Error loading log') + '</p></div>');
                        }
                    },
                    error: function() {
                        container.html('<div class="bp-hubspot-log-empty"><p><?php _e('Error loading log', 'starter-dashboard'); ?></p></div>');
                    }
                });
            }

            // Render Submission Log
            function renderSubmissionLog(entries) {
                var container = $('#bp-hubspot-log-container');

                if (!entries || entries.length === 0) {
                    container.html('<div class="bp-hubspot-log-empty"><easier-icon name="clipboard" variant="twotone" size="24" color="#999"></easier-icon><p><?php _e('No submissions logged yet. Submissions will appear here after forms are submitted.', 'starter-dashboard'); ?></p></div>');
                    return;
                }

                var html = '';
                entries.forEach(function(entry, index) {
                    var statusClass = entry.success ? 'success' : 'error';
                    var statusText = entry.success ? '<?php _e('Success', 'starter-dashboard'); ?>' : '<?php _e('Error', 'starter-dashboard'); ?>';
                    var statusIcon = entry.success ? 'yes-alt' : 'dismiss';
                    var time = new Date(entry.timestamp * 1000).toLocaleString();

                    html += '<div class="bp-hubspot-log-entry" data-status="' + statusClass + '">';
                    html += '<div class="bp-hubspot-log-entry__header">';
                    html += '<span class="bp-hubspot-log-entry__status bp-hubspot-log-entry__status--' + statusClass + '">';
                    html += '<easier-icon name="' + (entry.success ? 'tick-02' : 'cancel-01') + '" variant="twotone" size="16" color="currentColor"></easier-icon> ' + statusText;
                    html += '</span>';
                    html += '<span class="bp-hubspot-log-entry__time">' + time + '</span>';
                    html += '</div>';

                    html += '<div class="bp-hubspot-log-entry__info">';
                    html += '<div><div class="bp-hubspot-log-entry__label"><?php _e('HubSpot Form', 'starter-dashboard'); ?></div>';
                    html += '<div class="bp-hubspot-log-entry__value">' + escapeHtml(entry.form_name || entry.form_id || '-') + '</div></div>';
                    html += '<div><div class="bp-hubspot-log-entry__label"><?php _e('Page', 'starter-dashboard'); ?></div>';
                    html += '<div class="bp-hubspot-log-entry__value">' + escapeHtml(entry.page_title || entry.page_url || '-') + '</div></div>';
                    html += '<div><div class="bp-hubspot-log-entry__label"><?php _e('Fields Sent', 'starter-dashboard'); ?></div>';
                    html += '<div class="bp-hubspot-log-entry__value">' + (entry.fields_count || 0) + '</div></div>';
                    html += '</div>';

                    if (!entry.success && entry.error) {
                        html += '<div class="bp-hubspot-log-entry__error">';
                        html += '<strong><?php _e('Error:', 'starter-dashboard'); ?></strong> ' + escapeHtml(entry.error);
                        if (entry.response_code) {
                            html += '<br><strong><?php _e('Response Code:', 'starter-dashboard'); ?></strong> ' + entry.response_code;
                        }
                        html += '</div>';
                    }

                    // Show debug details if available (debug mode was enabled for this submission)
                    if (entry.debug_mode) {
                        html += '<div class="bp-hubspot-log-entry__debug">';
                        html += '<div class="bp-hubspot-log-entry__debug-badge">';
                        html += '<easier-icon name="bug" variant="twotone" size="14" color="currentColor"></easier-icon> <?php _e('Debug Mode', 'starter-dashboard'); ?>';
                        html += '</div>';

                        // Submitted fields
                        if (entry.fields) {
                            html += '<div class="bp-hubspot-log-entry__debug-section">';
                            html += '<span class="bp-hubspot-log-entry__debug-toggle" data-target="fields-' + index + '">';
                            html += '<?php _e('Submitted Fields', 'starter-dashboard'); ?> ▼</span>';
                            html += '<pre class="bp-hubspot-log-entry__debug-content" data-target="fields-' + index + '">' + escapeHtml(JSON.stringify(entry.fields, null, 2)) + '</pre>';
                            html += '</div>';
                        }

                        // Request payload
                        if (entry.request_payload) {
                            html += '<div class="bp-hubspot-log-entry__debug-section">';
                            html += '<span class="bp-hubspot-log-entry__debug-toggle" data-target="request-' + index + '">';
                            html += '<?php _e('API Request Payload', 'starter-dashboard'); ?> ▼</span>';
                            html += '<pre class="bp-hubspot-log-entry__debug-content" data-target="request-' + index + '">' + escapeHtml(JSON.stringify(entry.request_payload, null, 2)) + '</pre>';
                            html += '</div>';
                        }

                        // Response body
                        if (entry.response_body) {
                            html += '<div class="bp-hubspot-log-entry__debug-section">';
                            html += '<span class="bp-hubspot-log-entry__debug-toggle" data-target="response-' + index + '">';
                            html += '<?php _e('API Response', 'starter-dashboard'); ?> ▼</span>';
                            html += '<pre class="bp-hubspot-log-entry__debug-content" data-target="response-' + index + '">' + escapeHtml(JSON.stringify(entry.response_body, null, 2)) + '</pre>';
                            html += '</div>';
                        }

                        html += '</div>';
                    }

                    html += '</div>';
                });

                container.html(html);

                // Toggle debug sections visibility
                container.on('click', '.bp-hubspot-log-entry__debug-toggle', function() {
                    var target = $(this).data('target');
                    var content = $('.bp-hubspot-log-entry__debug-content[data-target="' + target + '"]');
                    var isVisible = content.hasClass('visible');

                    content.toggleClass('visible');

                    // Update arrow
                    var text = $(this).text();
                    $(this).text(isVisible ? text.replace('▲', '▼') : text.replace('▼', '▲'));
                });
            }

            // Filter log
            function filterLog(filter) {
                var entries = $('.bp-hubspot-log-entry');
                entries.each(function() {
                    var status = $(this).data('status');
                    if (filter === 'all' || filter === status) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            }

            // Clear log
            function clearSubmissionLog() {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_hubspot_clear_log',
                        nonce: hubspotNonce
                    },
                    success: function(response) {
                        loadSubmissionLog();
                    }
                });
            }

            // Helper: Escape HTML
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(text));
                return div.innerHTML;
            }

            // Initialize
            init();
        });
        </script>
        <?php
    }

    /**
     * Save settings
     */
    public function save_settings($saved, $settings) {
        if (isset($settings['portal_id'])) {
            update_option($this->option_portal, sanitize_text_field($settings['portal_id']));
        }
        if (isset($settings['access_token'])) {
            update_option($this->option_token, sanitize_text_field($settings['access_token']));
        }
        if (isset($settings['debug_mode'])) {
            update_option($this->option_debug, $settings['debug_mode'] === 'yes' ? 'yes' : 'no');
        } else {
            update_option($this->option_debug, 'no');
        }
        // Clear caches when settings change
        delete_transient('starter_hubspot_forms_list');
        delete_transient('starter_hubspot_all_fields');
        return true;
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('starter_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        $forms = $this->get_hubspot_forms();

        if (is_wp_error($forms)) {
            wp_send_json_error($forms->get_error_message());
        }

        wp_send_json_success([
            'message' => sprintf(__('Connected! Found %d forms.', 'starter-dashboard'), count($forms))
        ]);
    }

    /**
     * Enqueue editor scripts
     */
    public function enqueue_editor_scripts() {
        wp_add_inline_script('elementor-editor', $this->get_editor_js());
    }

    /**
     * Get editor JavaScript
     */
    private function get_editor_js() {
        $nonce = wp_create_nonce('starter_settings');
        $ajax_url = admin_url('admin-ajax.php');

        return <<<JS
(function($) {
    'use strict';

    var ehfaFieldsCache = {};
    var ehfaCurrentModel = null;
    var ehfaCurrentFormId = null;
    var ehfaInitialized = false;
    var ehfaElementorFields = [];

    console.log('[EHFA] Script loaded');

    // Get Elementor form fields from current model
    function getElementorFormFields(model) {
        var fields = [];
        if (!model) return fields;

        try {
            var settings = model.get('settings');
            var formFields = settings.get('form_fields');

            if (formFields && formFields.models) {
                formFields.models.forEach(function(fieldModel) {
                    var attrs = fieldModel.attributes;
                    fields.push({
                        id: attrs.custom_id || attrs._id || '',
                        label: attrs.field_label || attrs.custom_id || 'Unnamed',
                        type: attrs.field_type || 'text'
                    });
                });
            }
        } catch(e) {
            console.log('[EHFA] Error getting form fields:', e);
        }

        return fields;
    }

    function loadHubSpotFormFields(formId, model, forceReset) {
        console.log('[EHFA] loadHubSpotFormFields called', {formId: formId, forceReset: forceReset, prevFormId: ehfaCurrentFormId});

        // Detect if form changed
        var formChanged = (ehfaCurrentFormId && ehfaCurrentFormId !== formId);
        if (forceReset === undefined) {
            forceReset = formChanged;
        }

        console.log('[EHFA] forceReset resolved to:', forceReset);

        ehfaCurrentModel = model;
        ehfaCurrentFormId = formId;

        var container = $('#ehfa-field-mapping-container');
        console.log('[EHFA] Container found:', container.length);
        if (!container.length) return;

        if (!formId) {
            container.html('');
            return;
        }

        if (ehfaFieldsCache[formId]) {
            buildMappingUI(ehfaFieldsCache[formId], model, forceReset);
            return;
        }

        container.html('<p style="color:#666; padding:10px;"><i class="eicon-loading eicon-animation-spin"></i> Loading HubSpot form fields...</p>');

        $.ajax({
            url: '{$ajax_url}',
            type: 'GET',
            data: {
                action: 'starter_hubspot_get_form_fields',
                form_guid: formId,
                nonce: '{$nonce}'
            },
            success: function(response) {
                console.log('[EHFA] AJAX response:', response);
                if (response.success && response.data) {
                    console.log('[EHFA] Fields received:', response.data);
                    console.log('[EHFA] Required fields:', response.data.filter(function(f) { return f.required; }));
                    ehfaFieldsCache[formId] = response.data;
                    buildMappingUI(response.data, model, forceReset);
                } else {
                    container.html('<p style="color:#d63638; padding:10px;">Failed to load HubSpot fields</p>');
                }
            },
            error: function() {
                container.html('<p style="color:#d63638; padding:10px;">Error loading fields</p>');
            }
        });
    }

    function buildMappingUI(hubspotFields, model, forceReset) {
        console.log('[EHFA] buildMappingUI called', {fieldsCount: hubspotFields.length, forceReset: forceReset});

        var container = $('#ehfa-field-mapping-container');
        if (!container.length || !hubspotFields.length) return;

        // Get Elementor form fields
        ehfaElementorFields = getElementorFormFields(model);
        console.log('[EHFA] Elementor fields:', ehfaElementorFields);

        var currentMappings = [];

        // If forceReset is true (form changed), auto-populate with required fields
        if (forceReset) {
            var requiredFields = hubspotFields.filter(function(f) { return f.required; });
            requiredFields.forEach(function(field) {
                // Try to guess Elementor field ID from HubSpot field name
                var guessedElementorId = field.name;
                // Convert hubspot names to common elementor patterns
                if (field.name === 'firstname') guessedElementorId = 'first_name';
                if (field.name === 'lastname') guessedElementorId = 'last_name';
                if (field.name === 'mobilephone') guessedElementorId = 'mobile';
                if (field.name === 'jobtitle') guessedElementorId = 'job_title';

                currentMappings.push({
                    elementor: guessedElementorId,
                    hubspot: field.name,
                    required: true
                });
            });
            // If no required fields, add one empty row
            if (currentMappings.length === 0) {
                currentMappings = [{elementor: '', hubspot: ''}];
            }
        } else {
            // Load existing mappings
            try {
                currentMappings = JSON.parse(model.get('settings').get('hubspot_field_mapping_json') || '[]');
            } catch(e) {}

            // Get list of required HubSpot field names
            var requiredHubspotFields = hubspotFields.filter(function(f) { return f.required; }).map(function(f) { return f.name; });

            // Mark mappings as required if they map to required HubSpot fields
            currentMappings = currentMappings.map(function(mapping) {
                if (mapping.hubspot && requiredHubspotFields.indexOf(mapping.hubspot) !== -1) {
                    mapping.required = true;
                }
                return mapping;
            });

            // Check if all required fields are mapped, if not - add missing ones
            var mappedHubspotFields = currentMappings.map(function(m) { return m.hubspot; });
            requiredHubspotFields.forEach(function(reqField) {
                if (mappedHubspotFields.indexOf(reqField) === -1) {
                    // This required field is not mapped - add it
                    var guessedElementorId = reqField;
                    if (reqField === 'firstname') guessedElementorId = 'first_name';
                    if (reqField === 'lastname') guessedElementorId = 'last_name';
                    if (reqField === 'mobilephone') guessedElementorId = 'mobile';
                    if (reqField === 'jobtitle') guessedElementorId = 'job_title';

                    currentMappings.unshift({
                        elementor: guessedElementorId,
                        hubspot: reqField,
                        required: true
                    });
                }
            });

            // If still no mappings at all, add one empty row
            if (currentMappings.length === 0) {
                currentMappings = [{elementor: '', hubspot: ''}];
            }
        }

        // Build required fields indicator
        var requiredList = hubspotFields.filter(function(f) { return f.required; }).map(function(f) { return f.label || f.name; });

        var html = '<style>';
        html += '.ehfa-mapping-wrapper { margin: 0; }';
        html += '.ehfa-required-notice { background: rgba(255,122,89,0.08); border-left: 3px solid #ff7a59; padding: 8px 12px; margin-bottom: 12px; font-size: 12px; color: #aaa; }';
        html += '.ehfa-required-notice strong { color: #ff7a59; }';
        html += '.ehfa-mappings-list { display: flex; flex-direction: column; gap: 8px; }';
        html += '.ehfa-mapping-row { background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.06); border-radius: 4px; padding: 12px; }';
        html += '.ehfa-mapping-row:hover { border-color: rgba(255,255,255,0.12); }';
        html += '.ehfa-field-group { margin-bottom: 8px; }';
        html += '.ehfa-field-group:last-of-type { margin-bottom: 0; }';
        html += '.ehfa-field-label { font-size: 10px; text-transform: uppercase; color: rgba(255,255,255,0.4); margin-bottom: 5px; letter-spacing: 0.5px; display: block; }';
        html += '.ehfa-el-select, .ehfa-hs-select { width: 100% !important; }';
        html += '.ehfa-mapping-row .select2-container--default .select2-selection--single { background: rgba(0,0,0,0.25) !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 3px !important; height: 34px !important; }';
        html += '.ehfa-mapping-row .select2-container--default .select2-selection--single .select2-selection__rendered { color: #fff !important; line-height: 32px !important; padding-left: 10px !important; font-size: 12px !important; }';
        html += '.ehfa-mapping-row .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px !important; }';
        html += '.ehfa-mapping-row .select2-container--default .select2-selection--single .select2-selection__placeholder { color: rgba(255,255,255,0.25) !important; }';
        html += '.ehfa-row-actions { display: flex; justify-content: flex-end; margin-top: 10px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.05); }';
        html += '.ehfa-remove-btn { background: none; border: none; color: rgba(255,255,255,0.35); cursor: pointer; padding: 4px 8px; font-size: 11px; display: flex; align-items: center; gap: 4px; border-radius: 3px; }';
        html += '.ehfa-remove-btn:hover { background: rgba(255,80,80,0.15); color: #ff6b6b; }';
        html += '.ehfa-remove-btn svg { width: 10px; height: 10px; fill: currentColor; }';
        html += '.ehfa-add-mapping { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 10px; padding: 10px; background: transparent; border: 1px dashed rgba(255,255,255,0.12); border-radius: 4px; color: rgba(255,255,255,0.45); font-size: 12px; cursor: pointer; transition: all 0.15s; }';
        html += '.ehfa-add-mapping:hover { border-color: #ff7a59; color: #ff7a59; }';
        html += '.ehfa-add-mapping svg { width: 12px; height: 12px; fill: currentColor; }';
        html += '.ehfa-mapping-row.ehfa-required { border-left: 2px solid #ff7a59; }';
        html += '.ehfa-required-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 9px; text-transform: uppercase; color: #ff7a59; letter-spacing: 0.5px; margin-left: 8px; }';
        html += '.ehfa-required-badge svg { width: 10px; height: 10px; fill: currentColor; }';
        html += '</style>';

        html += '<div class="ehfa-mapping-wrapper">';

        if (requiredList.length > 0) {
            html += '<div class="ehfa-required-notice">';
            html += '<strong>Required:</strong> ' + requiredList.join(', ');
            html += '</div>';
        }

        html += '<div class="ehfa-mappings-list">';

        // Build Elementor field options
        var elOptions = '<option value="">Select Elementor field...</option>';
        ehfaElementorFields.forEach(function(field) {
            elOptions += '<option value="' + field.id + '">' + field.label + ' (' + field.id + ')</option>';
        });

        // Build HubSpot field options
        var hsOptions = '<option value="">Select HubSpot field...</option>';
        hubspotFields.forEach(function(field) {
            var req = field.required ? ' ★' : '';
            hsOptions += '<option value="' + field.name + '" data-required="' + (field.required ? '1' : '0') + '">' + (field.label || field.name) + req + '</option>';
        });

        currentMappings.forEach(function(mapping, idx) {
            html += buildMappingRow(idx, mapping, elOptions, hsOptions);
        });

        html += '</div>';
        html += '<button type="button" class="ehfa-add-mapping">';
        html += '<svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>';
        html += 'Add Field Mapping</button>';
        html += '</div>';

        container.html(html);

        // Initialize Select2 for Elementor fields
        container.find('.ehfa-el-select').each(function() {
            $(this).select2({
                placeholder: 'Select Elementor field...',
                allowClear: true,
                width: '100%',
                dropdownParent: container
            });
        });

        // Initialize Select2 for HubSpot fields
        container.find('.ehfa-hs-select').each(function() {
            $(this).select2({
                placeholder: 'Search HubSpot field...',
                allowClear: true,
                width: '100%',
                dropdownParent: container
            });
        });

        // Set values for pre-populated selects
        container.find('.ehfa-mapping-row').each(function(idx) {
            if (currentMappings[idx]) {
                if (currentMappings[idx].elementor) {
                    $(this).find('.ehfa-el-select').val(currentMappings[idx].elementor).trigger('change.select2');
                }
                if (currentMappings[idx].hubspot) {
                    $(this).find('.ehfa-hs-select').val(currentMappings[idx].hubspot).trigger('change.select2');
                }
            }
        });

        bindMappingEvents(container, elOptions, hsOptions);

        // Update disabled options after initial setup
        updateDisabledOptions(container);

        // Auto-save if we pre-populated
        if (forceReset || currentMappings.length > 0) {
            saveMappings();
        }
    }

    // Update disabled state for options that are already used in other rows
    function updateDisabledOptions(container) {
        // Collect all selected Elementor fields
        var usedElFields = [];
        container.find('.ehfa-el-select').each(function() {
            var val = $(this).val();
            if (val) usedElFields.push(val);
        });

        // Collect all selected HubSpot fields
        var usedHsFields = [];
        container.find('.ehfa-hs-select').each(function() {
            var val = $(this).val();
            if (val) usedHsFields.push(val);
        });

        // Update each Elementor select
        container.find('.ehfa-el-select').each(function() {
            var currentVal = $(this).val();
            $(this).find('option').each(function() {
                var optVal = $(this).val();
                if (optVal && optVal !== currentVal && usedElFields.indexOf(optVal) !== -1) {
                    $(this).prop('disabled', true);
                } else {
                    $(this).prop('disabled', false);
                }
            });
        });

        // Update each HubSpot select
        container.find('.ehfa-hs-select').each(function() {
            var currentVal = $(this).val();
            $(this).find('option').each(function() {
                var optVal = $(this).val();
                if (optVal && optVal !== currentVal && usedHsFields.indexOf(optVal) !== -1) {
                    $(this).prop('disabled', true);
                } else {
                    $(this).prop('disabled', false);
                }
            });
        });
    }

    function buildMappingRow(idx, mapping, elOptions, hsOptions) {
        var isRequired = mapping.required === true;
        var html = '<div class="ehfa-mapping-row' + (isRequired ? ' ehfa-required' : '') + '" data-index="' + idx + '" data-required="' + (isRequired ? '1' : '0') + '">';
        html += '<div class="ehfa-field-group">';
        html += '<label class="ehfa-field-label">Elementor Field';
        if (isRequired) {
            html += '<span class="ehfa-required-badge"><svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>Required</span>';
        }
        html += '</label>';
        html += '<select class="ehfa-el-select">' + elOptions + '</select>';
        html += '</div>';
        html += '<div class="ehfa-field-group">';
        html += '<label class="ehfa-field-label">HubSpot Field</label>';
        html += '<select class="ehfa-hs-select">' + hsOptions + '</select>';
        html += '</div>';
        if (!isRequired) {
            html += '<div class="ehfa-row-actions">';
            html += '<button type="button" class="ehfa-remove-mapping ehfa-remove-btn" title="Remove">';
            html += '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
            html += 'Remove';
            html += '</button>';
            html += '</div>';
        }
        html += '</div>';
        return html;
    }

    function bindMappingEvents(container, elOptions, hsOptions) {
        container.off('click', '.ehfa-add-mapping').on('click', '.ehfa-add-mapping', function() {
            var list = container.find('.ehfa-mappings-list');
            var idx = list.find('.ehfa-mapping-row').length;
            var newRow = $(buildMappingRow(idx, {elementor: '', hubspot: ''}, elOptions, hsOptions));
            list.append(newRow);

            newRow.find('.ehfa-el-select').select2({
                placeholder: 'Select Elementor field...',
                allowClear: true,
                width: '100%',
                dropdownParent: container
            });

            newRow.find('.ehfa-hs-select').select2({
                placeholder: 'Search HubSpot field...',
                allowClear: true,
                width: '100%',
                dropdownParent: container
            });

            // Update disabled options after adding new row
            updateDisabledOptions(container);
        });

        container.off('click', '.ehfa-remove-mapping').on('click', '.ehfa-remove-mapping', function() {
            var row = $(this).closest('.ehfa-mapping-row');
            // Don't allow removing required fields
            if (row.data('required') === 1 || row.data('required') === '1') {
                return;
            }
            var rows = container.find('.ehfa-mapping-row');
            if (rows.length > 1) {
                row.remove();
                saveMappings();
                // Update disabled options after removing row
                updateDisabledOptions(container);
            }
        });

        container.off('change', '.ehfa-el-select, .ehfa-hs-select').on('change', '.ehfa-el-select, .ehfa-hs-select', function() {
            saveMappings();
            // Update disabled options after selection change
            updateDisabledOptions(container);
        });
    }

    function saveMappings() {
        if (!ehfaCurrentModel) {
            console.log('[EHFA] saveMappings: No model available');
            return;
        }

        var mappings = [];
        $('#ehfa-field-mapping-container .ehfa-mapping-row').each(function() {
            var elementorField = $(this).find('.ehfa-el-select').val();
            var hubspotField = $(this).find('.ehfa-hs-select').val();

            if (elementorField || hubspotField) {
                mappings.push({
                    elementor: elementorField || '',
                    hubspot: hubspotField || ''
                });
            }
        });

        var jsonValue = JSON.stringify(mappings);
        console.log('[EHFA] Saving mappings:', jsonValue);

        try {
            ehfaCurrentModel.setSetting('hubspot_field_mapping_json', jsonValue);
            console.log('[EHFA] Mappings saved successfully');
        } catch(e) {
            console.log('[EHFA] Error saving mappings:', e);
        }
    }

    // Watch for HubSpot form select changes via MutationObserver
    function initMutationObserver() {
        if (ehfaInitialized) return;
        ehfaInitialized = true;

        console.log('[EHFA] Starting MutationObserver');

        var observer = new MutationObserver(function(mutations) {
            // Look for the hubspot_form_id select
            var hubspotSelect = document.querySelector('[data-setting="hubspot_form_id"]');

            // Also update the model reference when panel changes
            try {
                if (typeof elementor !== 'undefined' && elementor.getPanelView) {
                    var panel = elementor.getPanelView();
                    if (panel && panel.getCurrentPageView) {
                        var pageView = panel.getCurrentPageView();
                        if (pageView && pageView.model) {
                            ehfaCurrentModel = pageView.model;
                        }
                    }
                }
            } catch(e) {}

            if (hubspotSelect && !hubspotSelect.dataset.ehfaInitialized) {
                hubspotSelect.dataset.ehfaInitialized = 'true';
                console.log('[EHFA] Found hubspot_form_id select');

                // Track the previously selected form to detect actual changes
                var previousFormId = $(hubspotSelect).val();

                // Bind change event - only forceReset when user actually changes the form
                $(hubspotSelect).on('change', function(e, isInitialLoad) {
                    var formId = $(this).val();
                    console.log('[EHFA] Select changed to:', formId, 'previous:', previousFormId, 'isInitialLoad:', isInitialLoad);

                    if (formId) {
                        // Get model from Elementor panel
                        var model = null;
                        try {
                            if (typeof elementor !== 'undefined' && elementor.getPanelView) {
                                var panel = elementor.getPanelView();
                                if (panel && panel.getCurrentPageView) {
                                    var pageView = panel.getCurrentPageView();
                                    if (pageView && pageView.model) {
                                        model = pageView.model;
                                    }
                                }
                            }
                        } catch(e) {
                            console.log('[EHFA] Error getting model:', e);
                        }

                        // Only forceReset if user actually changed the form (not on initial load)
                        var shouldReset = !isInitialLoad && previousFormId && previousFormId !== formId;
                        console.log('[EHFA] shouldReset:', shouldReset);

                        loadHubSpotFormFields(formId, model, shouldReset);
                        previousFormId = formId;
                    } else {
                        // Clear mappings if no form selected
                        $('#ehfa-field-mapping-container').html('');
                        previousFormId = null;
                    }
                });

                // Trigger initial load if form already selected (pass isInitialLoad = true)
                var currentValue = $(hubspotSelect).val();
                console.log('[EHFA] Current value:', currentValue);
                if (currentValue) {
                    setTimeout(function() {
                        $(hubspotSelect).trigger('change', [true]);
                    }, 200);
                }
            }

            // Also look for the mapping container to initialize it
            var container = document.getElementById('ehfa-field-mapping-container');
            if (container && !container.dataset.ehfaWatched) {
                container.dataset.ehfaWatched = 'true';
                console.log('[EHFA] Found mapping container');

                // Check if there's already a form selected
                var select = document.querySelector('[data-setting="hubspot_form_id"]');
                if (select && select.value) {
                    console.log('[EHFA] Triggering load for existing form:', select.value);
                    setTimeout(function() {
                        $(select).trigger('change', [true]); // Pass isInitialLoad = true
                    }, 300);
                }
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        console.log('[EHFA] MutationObserver started');
    }

    // Start observer when DOM is ready
    $(document).ready(function() {
        console.log('[EHFA] DOM ready, initializing...');
        setTimeout(initMutationObserver, 500);
    });

    // Also try on window load
    $(window).on('load', function() {
        console.log('[EHFA] Window load');
        setTimeout(initMutationObserver, 100);
    });

})(jQuery);
JS;
    }

    /**
     * AJAX: Get HubSpot forms list
     */
    public function ajax_get_forms() {
        check_ajax_referer('starter_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        // Force refresh if requested (clears transient cache)
        $force_refresh = isset($_GET['force_refresh']) && $_GET['force_refresh'] === '1';
        if ($force_refresh) {
            delete_transient('starter_hubspot_forms_list');
        }

        $forms = $this->get_hubspot_forms();

        if (is_wp_error($forms)) {
            wp_send_json_error($forms->get_error_message());
        }

        wp_send_json_success($forms);
    }

    /**
     * AJAX: Get form fields
     */
    public function ajax_get_form_fields() {
        check_ajax_referer('starter_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        $form_guid = isset($_GET['form_guid']) ? sanitize_text_field($_GET['form_guid']) : '';

        if (empty($form_guid)) {
            wp_send_json_error(__('Form GUID required', 'starter-dashboard'));
        }

        $fields = $this->get_form_fields($form_guid);

        if (is_wp_error($fields)) {
            wp_send_json_error($fields->get_error_message());
        }

        wp_send_json_success($fields);
    }

    /**
     * AJAX: Get Elementor forms with HubSpot action
     */
    public function ajax_get_elementor_forms() {
        check_ajax_referer('starter_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        $forms = $this->find_elementor_hubspot_forms();
        wp_send_json_success($forms);
    }

    /**
     * AJAX: Get submission log
     */
    public function ajax_get_submission_log() {
        check_ajax_referer('starter_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        $log = get_option($this->log_option, []);

        // Reverse to show newest first
        $log = array_reverse($log);

        wp_send_json_success($log);
    }

    /**
     * AJAX: Clear submission log
     */
    public function ajax_clear_log() {
        check_ajax_referer('starter_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        delete_option($this->log_option);
        wp_send_json_success();
    }

    /**
     * Find Elementor forms with HubSpot action
     */
    private function find_elementor_hubspot_forms() {
        global $wpdb;

        $forms = [];

        // Get HubSpot forms for name lookup
        $hubspot_forms = $this->get_hubspot_forms();
        $hubspot_forms_map = [];
        if (!is_wp_error($hubspot_forms)) {
            foreach ($hubspot_forms as $form) {
                $hubspot_forms_map[$form['id']] = $form['name'];
            }
        }

        // Search in postmeta for Elementor data
        $results = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_elementor_data'
            AND pm.meta_value LIKE '%hubspot%'
            AND p.post_status IN ('publish', 'draft', 'private')
        ");

        foreach ($results as $row) {
            $elementor_data = json_decode($row->meta_value, true);
            if (!is_array($elementor_data)) continue;

            $found_forms = $this->extract_hubspot_forms_from_elementor($elementor_data);

            foreach ($found_forms as $form_data) {
                $hubspot_form_name = isset($hubspot_forms_map[$form_data['hubspot_form_id']])
                    ? $hubspot_forms_map[$form_data['hubspot_form_id']]
                    : null;

                $forms[] = [
                    'page_id' => $row->ID,
                    'page_title' => $row->post_title,
                    'edit_url' => admin_url('post.php?post=' . $row->ID . '&action=elementor'),
                    'form_name' => $form_data['form_name'],
                    'hubspot_form_id' => $form_data['hubspot_form_id'],
                    'hubspot_form_name' => $hubspot_form_name,
                    'mappings' => $form_data['mappings'],
                ];
            }
        }

        return $forms;
    }

    /**
     * Extract HubSpot form configs from Elementor data
     */
    private function extract_hubspot_forms_from_elementor($elements, $results = []) {
        foreach ($elements as $element) {
            if (isset($element['elType']) && $element['elType'] === 'widget' && isset($element['widgetType']) && $element['widgetType'] === 'form') {
                $settings = $element['settings'] ?? [];

                // Check if HubSpot action is enabled
                $submit_actions = $settings['submit_actions'] ?? [];
                if (in_array('hubspot', $submit_actions) && !empty($settings['hubspot_form_id'])) {
                    $mappings = [];

                    // Get mappings from repeater
                    if (!empty($settings['hubspot_fields_map'])) {
                        foreach ($settings['hubspot_fields_map'] as $mapping) {
                            if (!empty($mapping['local_id']) || !empty($mapping['remote_id'])) {
                                $mappings[] = [
                                    'elementor' => $mapping['local_id'] ?? '',
                                    'hubspot' => $mapping['remote_id'] ?? '',
                                ];
                            }
                        }
                    }

                    // Also check JSON mapping
                    if (!empty($settings['hubspot_field_mapping_json'])) {
                        $json_mappings = json_decode($settings['hubspot_field_mapping_json'], true);
                        if (is_array($json_mappings)) {
                            $mappings = array_merge($mappings, $json_mappings);
                        }
                    }

                    $results[] = [
                        'form_name' => $settings['form_name'] ?? 'Unnamed Form',
                        'hubspot_form_id' => $settings['hubspot_form_id'],
                        'mappings' => $mappings,
                    ];
                }
            }

            // Recurse into child elements
            if (!empty($element['elements'])) {
                $results = $this->extract_hubspot_forms_from_elementor($element['elements'], $results);
            }
        }

        return $results;
    }

    /**
     * Get HubSpot forms via API (with pagination support)
     */
    public function get_hubspot_forms() {
        $access_token = get_option($this->option_token, '');

        if (empty($access_token)) {
            return new WP_Error('no_token', __('HubSpot access token not configured', 'starter-dashboard'));
        }

        // Check cache
        $cached = get_transient('starter_hubspot_forms_list');
        if ($cached !== false) {
            return $cached;
        }

        $forms = [];
        $after = null;
        $max_pages = 10; // Safety limit to prevent infinite loops
        $page = 0;

        // Paginate through all forms
        do {
            $page++;
            $url = 'https://api.hubapi.com/marketing/v3/forms/?limit=100';
            if ($after) {
                $url .= '&after=' . urlencode($after);
            }

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $error = $body['message'] ?? __('Unknown error', 'starter-dashboard');
                return new WP_Error('api_error', $error);
            }

            foreach ($body['results'] ?? [] as $form) {
                $forms[] = [
                    'id' => $form['id'],
                    'name' => $form['name'],
                    'createdAt' => $form['createdAt'] ?? '',
                ];
            }

            // Check for next page cursor
            $after = $body['paging']['next']['after'] ?? null;

        } while ($after && $page < $max_pages);

        // Sort forms alphabetically by name for easier selection
        usort($forms, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Cache for 5 minutes
        set_transient('starter_hubspot_forms_list', $forms, 5 * MINUTE_IN_SECONDS);

        return $forms;
    }

    /**
     * Get form fields
     */
    public function get_form_fields($form_guid) {
        $access_token = get_option($this->option_token, '');

        if (empty($access_token)) {
            return new WP_Error('no_token', __('HubSpot access token not configured', 'starter-dashboard'));
        }

        $response = wp_remote_get('https://api.hubapi.com/marketing/v3/forms/' . $form_guid, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error = $body['message'] ?? __('Unknown error', 'starter-dashboard');
            return new WP_Error('api_error', $error);
        }

        $fields = [];
        foreach ($body['fieldGroups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $field_data = [
                    'name' => $field['name'],
                    'label' => $field['label'] ?? $field['name'],
                    'type' => $field['fieldType'] ?? 'text',
                    'required' => $field['required'] ?? false,
                ];

                // Include options for dropdown/select/radio/checkbox fields
                if (!empty($field['options'])) {
                    $field_data['options'] = array_map(function($opt) {
                        return [
                            'label' => $opt['label'] ?? $opt['value'] ?? '',
                            'value' => $opt['value'] ?? '',
                        ];
                    }, $field['options']);
                }

                $fields[] = $field_data;
            }
        }

        return $fields;
    }

    /**
     * Log a submission
     */
    public static function log_submission($data) {
        $instance = self::instance();
        $log = get_option($instance->log_option, []);

        $log[] = array_merge([
            'timestamp' => time(),
        ], $data);

        // Keep only last N entries
        if (count($log) > $instance->max_log_entries) {
            $log = array_slice($log, -$instance->max_log_entries);
        }

        update_option($instance->log_option, $log);
    }

    /**
     * Submit form to HubSpot
     */
    public static function submit_to_hubspot($form_guid, $fields, $context = []) {
        $instance = self::instance();
        $portal_id = get_option($instance->option_portal, '');
        $access_token = get_option($instance->option_token, '');
        $debug_mode = get_option($instance->option_debug, 'no') === 'yes';

        // Get form name for logging
        $forms = $instance->get_hubspot_forms();
        $form_name = $form_guid;
        if (!is_wp_error($forms)) {
            foreach ($forms as $form) {
                if ($form['id'] === $form_guid) {
                    $form_name = $form['name'];
                    break;
                }
            }
        }

        $log_data = [
            'form_id' => $form_guid,
            'form_name' => $form_name,
            'page_url' => $context['page_url'] ?? '',
            'page_title' => $context['page_title'] ?? '',
            'fields_count' => count($fields),
            'debug_mode' => $debug_mode,
        ];

        // Include full field data in debug mode
        if ($debug_mode) {
            $log_data['fields'] = $fields;
        }

        if (empty($portal_id) || empty($access_token)) {
            $log_data['success'] = false;
            $log_data['error'] = __('HubSpot not configured', 'starter-dashboard');
            self::log_submission($log_data);
            return new WP_Error('not_configured', __('HubSpot not configured', 'starter-dashboard'));
        }

        $payload = [
            'fields' => [],
            'context' => [
                'pageUri' => $context['page_url'] ?? '',
                'pageName' => $context['page_title'] ?? '',
                'ipAddress' => $context['ip'] ?? '',
            ],
        ];

        if (!empty($_COOKIE['hubspotutk'])) {
            $payload['context']['hutk'] = sanitize_text_field($_COOKIE['hubspotutk']);
        }

        // Common field name mappings (Elementor → HubSpot)
        $field_aliases = [
            'first_name' => 'firstname',
            'last_name' => 'lastname',
            'phone_number' => 'phone',
            'mobile_phone' => 'mobilephone',
            'mobile' => 'mobilephone',
            'job_title' => 'jobtitle',
            'street_address' => 'address',
            'postal_code' => 'zip',
            'zip_code' => 'zip',
        ];

        foreach ($fields as $name => $value) {
            if ($value !== '' && $value !== null) {
                // Apply field name alias if exists
                $hubspot_name = $field_aliases[$name] ?? $name;

                $payload['fields'][] = [
                    'name' => $hubspot_name,
                    'value' => is_array($value) ? implode(';', $value) : (string) $value,
                ];
            }
        }

        // Use public endpoint (no auth required)
        $url = sprintf(
            'https://api.hsforms.com/submissions/v3/integration/submit/%s/%s',
            $portal_id,
            $form_guid
        );

        $json_body = json_encode($payload);

        // Store request details in debug mode
        if ($debug_mode) {
            $log_data['request_url'] = $url;
            $log_data['request_payload'] = $payload;
        }

        // Debug logging to file - only if debug mode enabled
        if ($debug_mode) {
            $debug_file = __DIR__ . '/hubspot-debug.log';
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " URL: " . $url . "\n", FILE_APPEND);
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " Payload: " . $json_body . "\n", FILE_APPEND);
        }

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => $json_body,
            'timeout' => 30,
        ]);

        // Debug response - write directly to file if debug mode
        if ($debug_mode) {
            $debug_file = __DIR__ . '/hubspot-debug.log';
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " Response Code: " . wp_remote_retrieve_response_code($response) . "\n", FILE_APPEND);
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " Response Body: " . wp_remote_retrieve_body($response) . "\n\n", FILE_APPEND);
        }

        if (is_wp_error($response)) {
            $log_data['success'] = false;
            $log_data['error'] = $response->get_error_message();
            self::log_submission($log_data);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            $log_data['success'] = true;
            if ($debug_mode && !empty($body)) {
                $log_data['response_body'] = $body;
            }
            self::log_submission($log_data);
            return true;
        }

        // Extract detailed error information
        $error_parts = [];

        // Main error message
        if (!empty($body['message'])) {
            $error_parts[] = $body['message'];
        }

        // Field-level errors
        if (!empty($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $err) {
                if (is_array($err)) {
                    $field_name = $err['errorType'] ?? $err['name'] ?? 'unknown';
                    $field_message = $err['message'] ?? 'validation error';
                    $error_parts[] = sprintf('%s: %s', $field_name, $field_message);
                } elseif (is_string($err)) {
                    $error_parts[] = $err;
                }
            }
        }

        // If no specific errors, use generic message
        if (empty($error_parts)) {
            $error_parts[] = __('Submission failed', 'starter-dashboard');
        }

        $error = implode('; ', $error_parts);

        $log_data['success'] = false;
        $log_data['error'] = $error;
        $log_data['response_code'] = $code;
        $log_data['response_body'] = $body;
        self::log_submission($log_data);

        return new WP_Error('submission_failed', $error);
    }
}

// Initialize only if Elementor Pro is active
add_action('plugins_loaded', function() {
    if (class_exists('ElementorPro\Plugin')) {
        Starter_Addon_HubSpot_Forms::instance();
    }
}, 20);
