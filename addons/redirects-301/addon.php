<?php
/**
 * Starter Dashboard Addon: 301 Redirects
 *
 * Manage 301 redirects with hit tracking and post list integration
 */

defined('ABSPATH') || exit;

class Starter_Addon_Redirects_301 {

    private static $instance = null;
    private $option_name = 'starter_301_redirects';
    private $cache_key = 'starter_301_redirects_map';
    private $scan_cache_key = 'starter_301_scan_results';
    private $cache_expiration = DAY_IN_SECONDS; // 24 hours

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Perform redirects early
        add_action('template_redirect', [$this, 'handle_redirect'], 1);

        // AJAX handlers
        add_action('wp_ajax_starter_redirects_get_all', [$this, 'ajax_get_all']);
        add_action('wp_ajax_starter_redirects_save', [$this, 'ajax_save']);
        add_action('wp_ajax_starter_redirects_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_starter_redirects_import', [$this, 'ajax_import']);
        add_action('wp_ajax_starter_redirects_search_posts', [$this, 'ajax_search_posts']);
        add_action('wp_ajax_starter_redirects_scan_url', [$this, 'ajax_scan_url']);
        add_action('wp_ajax_starter_redirects_scan_all', [$this, 'ajax_scan_all']);
        add_action('wp_ajax_starter_redirects_get_scan_cache', [$this, 'ajax_get_scan_cache']);
        add_action('wp_ajax_starter_redirects_clear_scan_cache', [$this, 'ajax_clear_scan_cache']);
        add_action('wp_ajax_starter_redirects_save_scan_cache', [$this, 'ajax_save_scan_cache']);
        add_action('wp_ajax_starter_redirects_scan_external_sources', [$this, 'ajax_scan_external_sources']);
        add_action('wp_ajax_starter_redirects_test_url', [$this, 'ajax_test_url']);
        add_action('wp_ajax_starter_redirects_save_test_result', [$this, 'ajax_save_test_result']);

        // Add columns to post list tables
        add_action('admin_init', [$this, 'register_columns']);

        // Add admin CSS for columns
        add_action('admin_head', [$this, 'print_admin_column_css']);

        // Settings save handler
        add_filter('starter_addon_save_settings_redirects-301', [$this, 'save_settings'], 10, 2);
    }

    /**
     * Print CSS for redirect columns in admin
     */
    public function print_admin_column_css() {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        ?>
        <style>
            .column-starter_redirect { width: 180px; }
            .starter-redirect-info { line-height: 1.6; }
            .starter-redirect-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace;
                margin: 2px 0;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            /* Hub redirects - blue */
            .starter-redirect-badge--incoming {
                background: #e7f3ff;
                color: #0073aa;
                border: 1px solid #b8daff;
            }
            .starter-redirect-badge--outgoing {
                background: #e7f3ff;
                color: #0073aa;
                border: 1px solid #b8daff;
            }
            /* External redirects - gray */
            .starter-redirect-badge--external {
                background: #f0f0f1;
                color: #646970;
                border: 1px solid #dcdcde;
            }
            .starter-redirect-badge--external .dashicons {
                opacity: 0.7;
            }
            /* Tooltip for external redirects */
            .starter-redirect-tooltip {
                position: relative;
                cursor: help;
            }
            .starter-redirect-tooltip::after {
                content: attr(data-tooltip);
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: #1d2327;
                color: #fff;
                padding: 6px 10px;
                border-radius: 4px;
                font-size: 11px;
                white-space: nowrap;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.2s, visibility 0.2s;
                z-index: 100;
                margin-bottom: 6px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .starter-redirect-tooltip::before {
                content: '';
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 5px solid transparent;
                border-top-color: #1d2327;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.2s, visibility 0.2s;
                z-index: 100;
                margin-bottom: -4px;
            }
            .starter-redirect-tooltip:hover::after,
            .starter-redirect-tooltip:hover::before {
                opacity: 1;
                visibility: visible;
            }
            .starter-redirect-badge .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                line-height: 14px;
            }
            .starter-redirect-badge small {
                opacity: 0.7;
                font-size: 10px;
            }
            /* Add redirect button */
            .starter-redirect-add-btn {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: #f6f7f7;
                color: #50575e;
                font-size: 12px;
                cursor: pointer;
                transition: all 0.15s;
            }
            .starter-redirect-add-btn:hover {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }
            .starter-redirect-add-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                line-height: 14px;
            }
            /* Redirect row with actions */
            .starter-redirect-row {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-bottom: 4px;
            }
            .starter-redirect-row:last-child { margin-bottom: 0; }
            .starter-redirect-actions {
                display: none;
                gap: 2px;
            }
            .starter-redirect-row:hover .starter-redirect-actions {
                display: flex;
            }
            .starter-redirect-edit-inline,
            .starter-redirect-delete-inline {
                background: none;
                border: none;
                padding: 2px;
                cursor: pointer;
                color: #787c82;
                border-radius: 3px;
                line-height: 1;
            }
            .starter-redirect-edit-inline:hover {
                background: #2271b1;
                color: #fff;
            }
            .starter-redirect-delete-inline:hover {
                background: #d63638;
                color: #fff;
            }
            .starter-redirect-edit-inline .dashicons,
            .starter-redirect-delete-inline .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }
            /* Modal styles */
            .starter-redirect-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.6);
                z-index: 100050;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .starter-redirect-modal {
                background: #fff;
                border-radius: 8px;
                width: 90%;
                max-width: 500px;
                max-height: 80vh;
                display: flex;
                flex-direction: column;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            }
            .starter-redirect-modal__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid #ddd;
            }
            .starter-redirect-modal__header h3 {
                margin: 0;
                font-size: 16px;
            }
            .starter-redirect-modal__close {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #666;
                line-height: 1;
                padding: 0;
            }
            .starter-redirect-modal__close:hover { color: #d63638; }
            .starter-redirect-modal__body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }
            .starter-redirect-modal__search {
                margin-bottom: 16px;
            }
            .starter-redirect-modal__search input {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            .starter-redirect-modal__search input:focus {
                border-color: #2271b1;
                outline: none;
                box-shadow: 0 0 0 1px #2271b1;
            }
            .starter-redirect-modal__info {
                background: #f0f6fc;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 12px;
                margin-bottom: 16px;
                font-size: 13px;
            }
            .starter-redirect-modal__info strong {
                color: #1d2327;
            }
            .starter-redirect-modal__list {
                max-height: 300px;
                overflow-y: auto;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .starter-redirect-modal__item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 14px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
                transition: background 0.15s;
            }
            .starter-redirect-modal__item:last-child { border-bottom: none; }
            .starter-redirect-modal__item:hover {
                background: #f0f6fc;
            }
            .starter-redirect-modal__item-info {
                flex: 1;
                min-width: 0;
            }
            .starter-redirect-modal__item-title {
                font-weight: 600;
                color: #1d2327;
                margin-bottom: 4px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-size: 14px;
            }
            .starter-redirect-modal__item-slug {
                font-size: 13px;
                color: #0073aa;
                font-family: monospace;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                background: #f0f6fc;
                padding: 2px 6px;
                border-radius: 3px;
                display: inline-block;
            }
            .starter-redirect-modal__item-redirect-info {
                font-size: 11px;
                color: #996800;
                margin-top: 4px;
            }
            .starter-redirect-modal__item-type {
                font-size: 11px;
                color: #666;
                background: #f0f0f1;
                padding: 2px 8px;
                border-radius: 3px;
                margin-left: 10px;
                flex-shrink: 0;
            }
            .starter-redirect-modal__loading,
            .starter-redirect-modal__empty {
                padding: 30px;
                text-align: center;
                color: #666;
            }
            .starter-redirect-modal__loading .spinner {
                float: none;
                margin: 0 8px 0 0;
            }
            /* Warning styles */
            .starter-redirect-modal__warning {
                background: #fcf0f1;
                border: 1px solid #d63638;
                border-radius: 4px;
                padding: 12px 14px;
                margin-bottom: 16px;
                font-size: 13px;
                color: #8a2424;
                display: flex;
                align-items: flex-start;
                gap: 8px;
            }
            .starter-redirect-modal__warning .dashicons {
                color: #d63638;
                flex-shrink: 0;
            }
            .starter-redirect-modal__warning strong {
                color: #8a2424;
                font-family: monospace;
            }
            /* Item with existing redirect */
            .starter-redirect-modal__item--has-redirect {
                background: #fff8e5;
                border-left: 3px solid #dba617;
            }
            .starter-redirect-modal__item--has-redirect:hover {
                background: #fff3cd;
            }
            .starter-redirect-modal__item-warning {
                color: #dba617;
                margin-left: 6px;
            }
            .starter-redirect-modal__item-warning .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                vertical-align: middle;
            }
            .starter-redirect-modal__item-redirect-info {
                color: #996800;
                font-size: 11px;
                margin-left: 8px;
            }
        </style>

        <!-- Modal HTML -->
        <div id="starter-redirect-modal" class="starter-redirect-modal-overlay" style="display:none;">
            <div class="starter-redirect-modal">
                <div class="starter-redirect-modal__header">
                    <h3><?php _e('Select Redirect Target', 'starter-dashboard'); ?></h3>
                    <button type="button" class="starter-redirect-modal__close">&times;</button>
                </div>
                <div class="starter-redirect-modal__body">
                    <div class="starter-redirect-modal__info">
                        <?php _e('Redirecting from:', 'starter-dashboard'); ?>
                        <strong id="starter-redirect-from-display"></strong>
                    </div>
                    <div class="starter-redirect-modal__warning" id="starter-redirect-warning" style="display:none;"></div>
                    <div class="starter-redirect-modal__search">
                        <input type="text" id="starter-redirect-search" placeholder="<?php esc_attr_e('Search by title or slug...', 'starter-dashboard'); ?>">
                    </div>
                    <div class="starter-redirect-modal__list" id="starter-redirect-list">
                        <div class="starter-redirect-modal__loading">
                            <span class="spinner is-active"></span>
                            <?php _e('Loading...', 'starter-dashboard'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal HTML -->
        <div id="starter-redirect-edit-modal" class="starter-redirect-modal-overlay" style="display:none;">
            <div class="starter-redirect-modal" style="max-width:400px;">
                <div class="starter-redirect-modal__header">
                    <h3><?php _e('Edit Redirect', 'starter-dashboard'); ?></h3>
                    <button type="button" class="starter-redirect-modal__close">&times;</button>
                </div>
                <div class="starter-redirect-modal__body">
                    <input type="hidden" id="starter-redirect-edit-id">
                    <div class="starter-redirect-edit-field">
                        <label for="starter-redirect-edit-from"><?php _e('From URL', 'starter-dashboard'); ?></label>
                        <input type="text" id="starter-redirect-edit-from" class="large-text">
                    </div>
                    <div class="starter-redirect-edit-field" style="margin-top:12px;">
                        <label for="starter-redirect-edit-to"><?php _e('To URL', 'starter-dashboard'); ?></label>
                        <input type="text" id="starter-redirect-edit-to" class="large-text">
                    </div>
                    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">
                        <button type="button" class="button" id="starter-redirect-edit-cancel"><?php _e('Cancel', 'starter-dashboard'); ?></button>
                        <button type="button" class="button button-primary" id="starter-redirect-edit-save"><?php _e('Save Changes', 'starter-dashboard'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .starter-redirect-edit-field label {
                display: block;
                font-weight: 500;
                margin-bottom: 4px;
            }
            .starter-redirect-edit-field input {
                width: 100%;
            }
        </style>

        <script>
        jQuery(function($) {
            var currentFromPath = '';
            var currentPostId = 0;
            var searchTimeout = null;
            var nonce = '<?php echo wp_create_nonce('starter_redirects_nonce'); ?>';
            var dashboardNonce = '<?php echo wp_create_nonce('starter_dashboard_nonce'); ?>';

            // Open modal
            $(document).on('click', '.starter-redirect-add-btn', function() {
                currentPostId = $(this).data('post-id');
                currentFromPath = $(this).data('post-path');
                var postTitle = $(this).data('post-title');

                $('#starter-redirect-from-display').text(currentFromPath + ' (' + postTitle + ')');
                $('#starter-redirect-warning').hide();
                $('#starter-redirect-search').val('');
                $('#starter-redirect-modal').show();

                loadPosts('');
                $('#starter-redirect-search').focus();
            });

            // Close modal
            $(document).on('click', '.starter-redirect-modal__close, .starter-redirect-modal-overlay', function(e) {
                if (e.target === this) {
                    $('#starter-redirect-modal').hide();
                }
            });

            // Escape key closes modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#starter-redirect-modal').is(':visible')) {
                    $('#starter-redirect-modal').hide();
                }
            });

            // Search
            $(document).on('input', '#starter-redirect-search', function() {
                clearTimeout(searchTimeout);
                var search = $(this).val();
                searchTimeout = setTimeout(function() {
                    loadPosts(search);
                }, 300);
            });

            // Load posts
            function loadPosts(search) {
                var $list = $('#starter-redirect-list');
                $list.html('<div class="starter-redirect-modal__loading"><span class="spinner is-active"></span> <?php _e('Loading...', 'starter-dashboard'); ?></div>');

                $.post(ajaxurl, {
                    action: 'starter_redirects_search_posts',
                    nonce: nonce,
                    search: search,
                    exclude: currentPostId,
                    from_path: currentFromPath
                }, function(response) {
                    if (!response.success) {
                        $list.html('<div class="starter-redirect-modal__empty"><?php _e('Error loading posts', 'starter-dashboard'); ?></div>');
                        return;
                    }

                    var data = response.data;

                    // Show warning if source is already redirected
                    if (data.source_already_redirected) {
                        $('#starter-redirect-warning')
                            .html('<span class="dashicons dashicons-warning"></span> <?php _e('Warning: This URL is already redirected to', 'starter-dashboard'); ?> <strong>' + escapeHtml(data.source_redirected_to) + '</strong>. <?php _e('Creating a new redirect will replace the existing one.', 'starter-dashboard'); ?>')
                            .show();
                    } else {
                        $('#starter-redirect-warning').hide();
                    }

                    if (data.posts && data.posts.length > 0) {
                        var html = '';
                        data.posts.forEach(function(post) {
                            var itemClass = 'starter-redirect-modal__item';
                            if (post.is_redirected) {
                                itemClass += ' starter-redirect-modal__item--has-redirect';
                            }

                            html += '<div class="' + itemClass + '" data-url="' + escapeAttr(post.url) + '" data-is-redirected="' + (post.is_redirected ? '1' : '0') + '" data-redirected-to="' + escapeAttr(post.redirected_to || '') + '">';
                            html += '<div class="starter-redirect-modal__item-info">';
                            html += '<div class="starter-redirect-modal__item-title">' + escapeHtml(post.title);
                            if (post.is_redirected) {
                                html += ' <span class="starter-redirect-modal__item-warning" title="<?php esc_attr_e('This page is already being redirected to another URL', 'starter-dashboard'); ?>"><span class="dashicons dashicons-warning"></span></span>';
                            }
                            html += '</div>';
                            html += '<div class="starter-redirect-modal__item-slug">/' + escapeHtml(post.slug || '') + '/</div>';
                            if (post.is_redirected) {
                                html += '<div class="starter-redirect-modal__item-redirect-info"><?php _e('Currently redirects to:', 'starter-dashboard'); ?> ' + escapeHtml(post.redirected_to) + '</div>';
                            }
                            html += '</div>';
                            html += '<span class="starter-redirect-modal__item-type">' + escapeHtml(post.type) + '</span>';
                            html += '</div>';
                        });
                        $list.html(html);
                    } else {
                        $list.html('<div class="starter-redirect-modal__empty"><?php _e('No pages found', 'starter-dashboard'); ?></div>');
                    }
                });
            }

            // Select target
            $(document).on('click', '.starter-redirect-modal__item', function() {
                var toUrl = $(this).data('url');
                var isRedirected = $(this).data('is-redirected') === 1;
                var redirectedTo = $(this).data('redirected-to');

                // Warn if target is already redirected
                if (isRedirected) {
                    if (!confirm('<?php _e('Warning: The selected page is already being redirected to', 'starter-dashboard'); ?> "' + redirectedTo + '". <?php _e('This might create a redirect chain. Continue anyway?', 'starter-dashboard'); ?>')) {
                        return;
                    }
                }

                // Save redirect
                $.post(ajaxurl, {
                    action: 'starter_redirects_save',
                    nonce: '<?php echo wp_create_nonce('starter_dashboard_nonce'); ?>',
                    from: currentFromPath,
                    to: toUrl,
                    enabled: true,
                    note: '<?php _e('Created from post list', 'starter-dashboard'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#starter-redirect-modal').hide();
                        // Refresh the page to show updated column
                        location.reload();
                    } else {
                        alert(response.data || '<?php _e('Error saving redirect', 'starter-dashboard'); ?>');
                    }
                });
            });

            // Inline Edit button
            $(document).on('click', '.starter-redirect-edit-inline', function(e) {
                e.stopPropagation();
                var id = $(this).data('id');
                var from = $(this).data('from');
                var to = $(this).data('to');

                $('#starter-redirect-edit-id').val(id);
                $('#starter-redirect-edit-from').val(from);
                $('#starter-redirect-edit-to').val(to);
                $('#starter-redirect-edit-modal').show();
            });

            // Edit modal close
            $('#starter-redirect-edit-cancel, #starter-redirect-edit-modal .starter-redirect-modal__close').on('click', function() {
                $('#starter-redirect-edit-modal').hide();
            });
            $(document).on('click', '#starter-redirect-edit-modal.starter-redirect-modal-overlay', function(e) {
                if (e.target === this) {
                    $('#starter-redirect-edit-modal').hide();
                }
            });

            // Edit modal save
            $('#starter-redirect-edit-save').on('click', function() {
                var id = $('#starter-redirect-edit-id').val();
                var from = $('#starter-redirect-edit-from').val();
                var to = $('#starter-redirect-edit-to').val();

                if (!from || !to) {
                    alert('<?php _e('Both From and To URLs are required', 'starter-dashboard'); ?>');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'starter_redirects_save',
                    nonce: dashboardNonce,
                    id: id,
                    from: from,
                    to: to,
                    enabled: true
                }, function(response) {
                    if (response.success) {
                        $('#starter-redirect-edit-modal').hide();
                        location.reload();
                    } else {
                        alert(response.data || '<?php _e('Error saving redirect', 'starter-dashboard'); ?>');
                    }
                });
            });

            // Inline Delete button
            $(document).on('click', '.starter-redirect-delete-inline', function(e) {
                e.stopPropagation();
                if (!confirm('<?php _e('Delete this redirect?', 'starter-dashboard'); ?>')) {
                    return;
                }

                var id = $(this).data('id');
                var $row = $(this).closest('.starter-redirect-row');

                $.post(ajaxurl, {
                    action: 'starter_redirects_delete',
                    nonce: dashboardNonce,
                    id: id
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || '<?php _e('Error deleting redirect', 'starter-dashboard'); ?>');
                    }
                });
            });

            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function escapeAttr(text) {
                return escapeHtml(text).replace(/"/g, '&quot;');
            }
        });
        </script>
        <?php
    }

    /**
     * Get all redirects
     */
    public function get_redirects() {
        return get_option($this->option_name, []);
    }

    /**
     * Save redirects
     */
    public function save_redirects($redirects) {
        $result = update_option($this->option_name, $redirects);
        // Rebuild cache when redirects change
        $this->rebuild_cache();
        return $result;
    }

    /**
     * Get cached redirect map (from_path => redirect_data)
     */
    public function get_redirect_map() {
        $map = get_transient($this->cache_key);

        if ($map === false) {
            $map = $this->rebuild_cache();
        }

        return $map;
    }

    /**
     * Rebuild the redirect cache
     */
    public function rebuild_cache() {
        $redirects = $this->get_redirects();
        $map = [
            'from' => [],  // from_path => redirect data
            'to' => [],    // to_path => array of from_paths
        ];

        foreach ($redirects as $id => $redirect) {
            if (empty($redirect['enabled'])) {
                continue;
            }

            $from_path = $this->normalize_path($redirect['from']);
            $to_path = $this->normalize_path($redirect['to']);

            // Map from paths
            $map['from'][$from_path] = [
                'id' => $id,
                'to' => $redirect['to'],
                'hits' => isset($redirect['hits']) ? $redirect['hits'] : 0,
            ];

            // Map to paths (for incoming redirects)
            if (!isset($map['to'][$to_path])) {
                $map['to'][$to_path] = [];
            }
            $map['to'][$to_path][] = [
                'id' => $id,
                'from' => $redirect['from'],
                'hits' => isset($redirect['hits']) ? $redirect['hits'] : 0,
            ];
        }

        set_transient($this->cache_key, $map, $this->cache_expiration);

        return $map;
    }

    /**
     * Clear the redirect cache
     */
    public function clear_cache() {
        delete_transient($this->cache_key);
    }

    /**
     * Check if a URL path is already being redirected FROM
     */
    public function is_path_redirected($path) {
        $map = $this->get_redirect_map();
        $normalized = $this->normalize_path($path);
        return isset($map['from'][$normalized]) ? $map['from'][$normalized] : false;
    }

    /**
     * Get incoming redirects for a path
     */
    public function get_incoming_redirects($path) {
        $map = $this->get_redirect_map();
        $normalized = $this->normalize_path($path);
        return isset($map['to'][$normalized]) ? $map['to'][$normalized] : [];
    }

    /**
     * Normalize URL path for comparison (internal use)
     * Strips trailing slashes for consistent matching
     */
    private function normalize_path($path) {
        // Remove query string for comparison
        $path = strtok($path, '?');
        // Normalize: lowercase, ensure leading slash, remove trailing slash
        $path = strtolower(trim($path, '/'));
        $path = '/' . $path;
        return $path;
    }

    /**
     * Format URL path according to WordPress permalink settings
     * Use this for display and storage
     */
    private function format_permalink_path($path) {
        // Remove query string
        $query = '';
        if (strpos($path, '?') !== false) {
            list($path, $query) = explode('?', $path, 2);
            $query = '?' . $query;
        }

        // Ensure leading slash
        $path = '/' . ltrim($path, '/');

        // Apply WordPress trailing slash setting
        // user_trailingslashit respects the permalink structure
        if (function_exists('user_trailingslashit') && $path !== '/') {
            $path = user_trailingslashit($path);
        }

        return $path . $query;
    }

    /**
     * Check if WordPress uses trailing slashes in permalinks
     */
    private function uses_trailing_slash() {
        $permalink_structure = get_option('permalink_structure');
        return $permalink_structure && substr($permalink_structure, -1) === '/';
    }

    /**
     * Handle 301 redirect
     */
    public function handle_redirect() {
        if (is_admin()) {
            return;
        }

        $redirects = $this->get_redirects();
        if (empty($redirects)) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'];
        $current_path = $this->normalize_path($request_uri);

        // Check if URL has query string - if so, don't match against root redirect
        // This prevents /?elementor_library=... from matching a "/" redirect
        $has_query_string = strpos($request_uri, '?') !== false;
        $query_part = $has_query_string ? substr($request_uri, strpos($request_uri, '?')) : '';

        foreach ($redirects as $id => $redirect) {
            if (empty($redirect['from']) || empty($redirect['to']) || empty($redirect['enabled'])) {
                continue;
            }

            $from_path = $this->normalize_path($redirect['from']);
            $from_has_query = strpos($redirect['from'], '?') !== false;
            $match_type = isset($redirect['match_type']) ? $redirect['match_type'] : 'exact';

            $matches = false;
            $captured_groups = [];

            // Match based on match_type
            switch ($match_type) {
                case 'wildcard':
                    // Convert wildcard to regex: * matches anything
                    $pattern = str_replace(['*'], ['(.*)'], preg_quote($from_path, '#'));
                    if (preg_match('#^' . $pattern . '$#i', $current_path, $captured_groups)) {
                        $matches = true;
                    }
                    break;

                case 'regex':
                    // Use from path as regex pattern directly
                    $pattern = $redirect['from'];
                    // Add delimiters if not present
                    if (substr($pattern, 0, 1) !== '#' && substr($pattern, 0, 1) !== '/') {
                        $pattern = '#' . $pattern . '#i';
                    }
                    if (@preg_match($pattern, $current_path, $captured_groups)) {
                        $matches = true;
                    }
                    break;

                case 'exact':
                default:
                    // Match logic:
                    // 1. If redirect "from" has query string, match exactly
                    // 2. If redirect "from" is just path, only match if request has NO query string
                    //    OR if request path matches exactly (not just normalized)
                    // This prevents /?something from matching a "/" redirect
                    if ($from_has_query) {
                        // From has query string - match full URL
                        $matches = ($request_uri === $redirect['from']);
                    } else {
                        // From is just a path - only match if:
                        // - Paths match AND request has no query string, OR
                        // - It's a specific path (not just "/") and paths match
                        if ($current_path === $from_path) {
                            if ($from_path === '/') {
                                // Root path - only match if no query string
                                $matches = !$has_query_string;
                            } else {
                                // Specific path - match regardless of query string
                                $matches = true;
                            }
                        }
                    }
                    break;
            }

            if ($matches) {
                // Increment hit counter
                $redirects[$id]['hits'] = isset($redirect['hits']) ? $redirect['hits'] + 1 : 1;
                $redirects[$id]['last_hit'] = current_time('mysql');
                $this->save_redirects($redirects);

                // Build target URL
                $to = $redirect['to'];

                // Replace captured groups in destination URL ($1, $2, etc.)
                if (!empty($captured_groups)) {
                    for ($i = 1; $i < count($captured_groups); $i++) {
                        $to = str_replace('$' . $i, $captured_groups[$i], $to);
                    }
                }

                if (strpos($to, 'http') !== 0) {
                    $to = home_url($to);
                }

                // Preserve query string if redirecting to a path (not full URL)
                if ($has_query_string && strpos($to, '?') === false && strpos($redirect['to'], 'http') !== 0) {
                    $to .= $query_part;
                }

                // Get status code (301, 302, 307)
                $status_code = isset($redirect['status_code']) ? (int) $redirect['status_code'] : 301;
                if (!in_array($status_code, [301, 302, 307])) {
                    $status_code = 301;
                }

                // Perform redirect
                wp_redirect($to, $status_code);
                exit;
            }
        }
    }

    /**
     * Register columns for post types
     */
    public function register_columns() {
        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", [$this, 'add_redirect_column']);
            add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_redirect_column'], 10, 2);
        }
    }

    /**
     * Add redirect column to post list
     */
    public function add_redirect_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['starter_redirect'] = __('Redirects', 'starter-dashboard');
            }
        }
        return $new_columns;
    }

    /**
     * Render redirect column content
     */
    public function render_redirect_column($column, $post_id) {
        if ($column !== 'starter_redirect') {
            return;
        }

        $post = get_post($post_id);
        $raw_url = wp_make_link_relative(get_permalink($post_id));
        // Formatted path for display and storage (respects WP permalink settings)
        $display_path = $this->format_permalink_path($raw_url);
        // Normalized path for cache lookups
        $post_path = $this->normalize_path($raw_url);

        // Use cached data for performance
        $outgoing = $this->is_path_redirected($post_path);
        $incoming = $this->get_incoming_redirects($post_path);

        // Check for external redirects from scan cache
        $external_redirect = $this->get_external_redirect_from_cache($post_id);

        // Track if any redirect exists (to hide add button)
        $has_any_redirect = !empty($incoming) || !empty($outgoing) || !empty($external_redirect);

        // Render incoming redirects (URLs pointing to this post) - from Hub
        if (!empty($incoming)) {
            echo '<div class="starter-redirect-info starter-redirect-incoming">';
            foreach ($incoming as $r) {
                $hits = isset($r['hits']) ? $r['hits'] : 0;
                $redirect_id = isset($r['id']) ? $r['id'] : '';
                echo '<div class="starter-redirect-row">';
                echo '<span class="starter-redirect-badge starter-redirect-badge--incoming starter-redirect-tooltip" data-tooltip="' . esc_attr(sprintf(__('Hub redirect: %d hits', 'starter-dashboard'), $hits)) . '">';
                echo '<span class="dashicons dashicons-arrow-right-alt"></span> ';
                echo esc_html($r['from']);
                if ($hits > 0) {
                    echo ' <small>(' . number_format($hits) . ')</small>';
                }
                echo '</span>';
                if ($redirect_id) {
                    echo '<span class="starter-redirect-actions">';
                    echo '<button type="button" class="starter-redirect-edit-inline starter-redirect-tooltip" data-id="' . esc_attr($redirect_id) . '" data-from="' . esc_attr($r['from']) . '" data-to="' . esc_attr($display_path) . '" data-tooltip="' . esc_attr__('Edit redirect', 'starter-dashboard') . '"><span class="dashicons dashicons-edit"></span></button>';
                    echo '<button type="button" class="starter-redirect-delete-inline starter-redirect-tooltip" data-id="' . esc_attr($redirect_id) . '" data-tooltip="' . esc_attr__('Delete redirect', 'starter-dashboard') . '"><span class="dashicons dashicons-trash"></span></button>';
                    echo '</span>';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        // Render outgoing redirect (this post redirects elsewhere) - from Hub
        if ($outgoing) {
            $hits = isset($outgoing['hits']) ? $outgoing['hits'] : 0;
            $redirect_id = isset($outgoing['id']) ? $outgoing['id'] : '';
            echo '<div class="starter-redirect-info starter-redirect-outgoing">';
            echo '<div class="starter-redirect-row">';
            echo '<span class="starter-redirect-badge starter-redirect-badge--outgoing starter-redirect-tooltip" data-tooltip="' . esc_attr(sprintf(__('Hub redirect to %s (%d hits)', 'starter-dashboard'), $outgoing['to'], $hits)) . '">';
            echo '<span class="dashicons dashicons-migrate"></span> ';
            echo esc_html($outgoing['to']);
            if ($hits > 0) {
                echo ' <small>(' . number_format($hits) . ')</small>';
            }
            echo '</span>';
            if ($redirect_id) {
                echo '<span class="starter-redirect-actions">';
                echo '<button type="button" class="starter-redirect-edit-inline starter-redirect-tooltip" data-id="' . esc_attr($redirect_id) . '" data-from="' . esc_attr($display_path) . '" data-to="' . esc_attr($outgoing['to']) . '" data-tooltip="' . esc_attr__('Edit redirect', 'starter-dashboard') . '"><span class="dashicons dashicons-edit"></span></button>';
                echo '<button type="button" class="starter-redirect-delete-inline starter-redirect-tooltip" data-id="' . esc_attr($redirect_id) . '" data-tooltip="' . esc_attr__('Delete redirect', 'starter-dashboard') . '"><span class="dashicons dashicons-trash"></span></button>';
                echo '</span>';
            }
            echo '</div>';
            echo '</div>';
        }

        // Render external redirect (from other sources like .htaccess, other plugins)
        if ($external_redirect && !$outgoing) {
            echo '<div class="starter-redirect-info starter-redirect-external">';
            echo '<div class="starter-redirect-row">';
            echo '<span class="starter-redirect-badge starter-redirect-badge--external starter-redirect-tooltip" data-tooltip="' . esc_attr__('External redirect (not from Hub) - check .htaccess or other plugins', 'starter-dashboard') . '">';
            echo '<span class="dashicons dashicons-migrate"></span> ';
            echo esc_html($external_redirect['redirects_to']);
            echo ' <small>(' . esc_html($external_redirect['status']) . ')</small>';
            echo '</span>';
            echo '</div>';
            echo '</div>';
        }

        // Only show "Add redirect" button if no redirect exists
        if (!$has_any_redirect) {
            echo '<button type="button" class="starter-redirect-add-btn starter-redirect-tooltip" data-post-id="' . esc_attr($post_id) . '" data-post-path="' . esc_attr($display_path) . '" data-post-title="' . esc_attr(get_the_title($post_id)) . '" data-tooltip="' . esc_attr__('Add redirect from this page', 'starter-dashboard') . '">';
            echo '<span class="dashicons dashicons-plus-alt2"></span>';
            echo '</button>';
        }
    }

    /**
     * Get external redirect from scan cache for a post
     */
    private function get_external_redirect_from_cache($post_id) {
        $scan_cache = get_transient($this->scan_cache_key);
        if (!$scan_cache || !is_array($scan_cache)) {
            return null;
        }

        foreach ($scan_cache as $item) {
            if (isset($item['post_id']) && $item['post_id'] == $post_id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * AJAX: Get all redirects
     */
    public function ajax_get_all() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $redirects = $this->get_redirects();
        $site_url = home_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);

        // Add URL info for display
        foreach ($redirects as $id => &$redirect) {
            $redirect['id'] = $id;

            // Determine if redirect target is internal (Hub) or external
            $to_url = $redirect['to'];
            $is_internal = false;

            // Check if it's a relative URL (starts with /)
            if (strpos($to_url, '/') === 0 && strpos($to_url, '//') !== 0) {
                $is_internal = true;
            }
            // Check if it's an absolute URL pointing to the same host
            elseif (strpos($to_url, 'http') === 0) {
                $to_host = parse_url($to_url, PHP_URL_HOST);
                $is_internal = ($to_host === $site_host);
            }

            $redirect['is_internal'] = $is_internal;

            // Try to resolve page title for internal URLs
            if ($is_internal && empty($redirect['to_title'])) {
                $resolved = $this->resolve_url_to_post($to_url);
                if ($resolved) {
                    $redirect['to_post_id'] = $resolved['id'];
                    $redirect['to_title'] = $resolved['title'];
                }
            }
        }

        wp_send_json_success(array_values($redirects));
    }

    /**
     * Try to resolve a URL path to a WordPress post/page
     *
     * @param string $url URL or path to resolve
     * @return array|false Array with 'id' and 'title' or false if not found
     */
    private function resolve_url_to_post($url) {
        // Extract path from URL
        if (strpos($url, 'http') === 0) {
            $path = parse_url($url, PHP_URL_PATH);
        } else {
            $path = $url;
        }

        if (empty($path) || $path === '/') {
            return false;
        }

        // Clean up the path
        $path = trim($path, '/');

        // Try to find post by URL using url_to_postid
        $full_url = home_url('/' . $path . '/');
        $post_id = url_to_postid($full_url);

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                return [
                    'id' => $post_id,
                    'title' => $post->post_title,
                ];
            }
        }

        // Try without trailing slash
        $full_url = home_url('/' . $path);
        $post_id = url_to_postid($full_url);

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                return [
                    'id' => $post_id,
                    'title' => $post->post_title,
                ];
            }
        }

        return false;
    }

    /**
     * AJAX: Save redirect
     */
    public function ajax_save() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $from = isset($_POST['from']) ? sanitize_text_field($_POST['from']) : '';
        $to = isset($_POST['to']) ? sanitize_text_field($_POST['to']) : '';
        $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : true;
        $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';
        $to_post_id = isset($_POST['to_post_id']) ? absint($_POST['to_post_id']) : 0;
        $to_title = isset($_POST['to_title']) ? sanitize_text_field($_POST['to_title']) : '';
        $status_code = isset($_POST['status_code']) ? sanitize_text_field($_POST['status_code']) : '301';
        $match_type = isset($_POST['match_type']) ? sanitize_text_field($_POST['match_type']) : 'exact';
        $to_type = isset($_POST['to_type']) ? sanitize_text_field($_POST['to_type']) : 'custom';

        if (empty($from) || empty($to)) {
            wp_send_json_error(__('From and To URLs are required', 'starter-dashboard'));
        }

        // Format URLs according to WordPress permalink settings
        // Only format relative paths, leave full URLs intact
        if (strpos($from, 'http') !== 0) {
            $from = $this->format_permalink_path($from);
        }
        if (strpos($to, 'http') !== 0) {
            $to = $this->format_permalink_path($to);
        }

        $redirects = $this->get_redirects();

        // Generate ID if new
        if (empty($id)) {
            $id = 'r_' . uniqid();
        }

        // Preserve existing hit count and dates
        $existing = isset($redirects[$id]) ? $redirects[$id] : [];

        $redirects[$id] = [
            'from' => $from,
            'to' => $to,
            'enabled' => $enabled,
            'status_code' => $status_code,
            'match_type' => $match_type,
            'to_type' => $to_type,
            'note' => $note,
            'to_post_id' => $to_post_id ?: null,
            'to_title' => $to_title ?: null,
            'hits' => isset($existing['hits']) ? $existing['hits'] : 0,
            'last_hit' => isset($existing['last_hit']) ? $existing['last_hit'] : null,
            'created' => isset($existing['created']) ? $existing['created'] : current_time('mysql'),
            'modified' => current_time('mysql'),
        ];

        $this->save_redirects($redirects);

        wp_send_json_success([
            'id' => $id,
            'redirect' => $redirects[$id],
            'message' => __('Redirect saved', 'starter-dashboard'),
        ]);
    }

    /**
     * AJAX: Delete redirect
     */
    public function ajax_delete() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if (empty($id)) {
            wp_send_json_error(__('Redirect ID required', 'starter-dashboard'));
        }

        $redirects = $this->get_redirects();

        if (!isset($redirects[$id])) {
            wp_send_json_error(__('Redirect not found', 'starter-dashboard'));
        }

        unset($redirects[$id]);
        $this->save_redirects($redirects);

        wp_send_json_success(__('Redirect deleted', 'starter-dashboard'));
    }

    /**
     * AJAX: Import redirects from CSV
     */
    public function ajax_import() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $csv_data = isset($_POST['csv']) ? sanitize_textarea_field($_POST['csv']) : '';
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'merge';

        if (empty($csv_data)) {
            wp_send_json_error(__('No data provided', 'starter-dashboard'));
        }

        $lines = explode("\n", $csv_data);
        $imported = 0;
        $redirects = ($mode === 'replace') ? [] : $this->get_redirects();

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Support both comma and tab delimiters
            $parts = strpos($line, "\t") !== false ? explode("\t", $line) : explode(',', $line);

            if (count($parts) >= 2) {
                $from = trim($parts[0], '" ');
                $to = trim($parts[1], '" ');
                $note = isset($parts[2]) ? trim($parts[2], '" ') : '';

                if (!empty($from) && !empty($to)) {
                    // Format URLs according to WordPress permalink settings
                    if (strpos($from, 'http') !== 0) {
                        $from = $this->format_permalink_path($from);
                    }
                    if (strpos($to, 'http') !== 0) {
                        $to = $this->format_permalink_path($to);
                    }

                    $id = 'r_' . uniqid();
                    $redirects[$id] = [
                        'from' => $from,
                        'to' => $to,
                        'enabled' => true,
                        'note' => $note,
                        'hits' => 0,
                        'last_hit' => null,
                        'created' => current_time('mysql'),
                        'modified' => current_time('mysql'),
                    ];
                    $imported++;
                }
            }
        }

        $this->save_redirects($redirects);

        wp_send_json_success([
            'imported' => $imported,
            'total' => count($redirects),
            'message' => sprintf(__('%d redirects imported', 'starter-dashboard'), $imported),
        ]);
    }

    /**
     * AJAX: Search posts for redirect target
     */
    public function ajax_search_posts() {
        check_ajax_referer('starter_redirects_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $exclude = isset($_POST['exclude']) ? absint($_POST['exclude']) : 0;
        $from_path = isset($_POST['from_path']) ? sanitize_text_field($_POST['from_path']) : '';

        // Check if the source path is already being redirected
        $source_redirect = $from_path ? $this->is_path_redirected($from_path) : false;

        // Excluded post types (Elementor, WP internal, etc.)
        $excluded_types = [
            'elementor_library',
            'elementor-thhf',
            'elementor_font',
            'elementor_icons',
            'e-landing-page',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
            'acf-field-group',
            'acf-field',
        ];

        // Priority order: page, post, then other CPTs
        $priority_types = ['page', 'post'];
        $all_public_types = get_post_types(['public' => true], 'names');
        $allowed_types = array_diff($all_public_types, $excluded_types);

        // Build ordered type list
        $ordered_types = [];
        foreach ($priority_types as $type) {
            if (in_array($type, $allowed_types)) {
                $ordered_types[] = $type;
            }
        }
        foreach ($allowed_types as $type) {
            if (!in_array($type, $ordered_types)) {
                $ordered_types[] = $type;
            }
        }

        $results = [];

        // Search by title and slug using custom query for better matching
        global $wpdb;

        foreach ($ordered_types as $post_type) {
            if (count($results) >= 20) break;

            $limit = 20 - count($results);

            // Build query - if search is empty, just get recent posts
            if (!empty($search)) {
                $search_term = '%' . $wpdb->esc_like($search) . '%';

                $query = $wpdb->prepare(
                    "SELECT ID, post_title, post_name, post_type
                    FROM {$wpdb->posts}
                    WHERE post_type = %s
                    AND post_status = 'publish'
                    AND (post_title LIKE %s OR post_name LIKE %s)
                    " . ($exclude > 0 ? "AND ID != %d" : "") . "
                    ORDER BY
                        CASE
                            WHEN post_name LIKE %s THEN 1
                            WHEN post_title LIKE %s THEN 2
                            ELSE 3
                        END,
                        post_title ASC
                    LIMIT %d",
                    array_merge(
                        [$post_type, $search_term, $search_term],
                        $exclude > 0 ? [$exclude] : [],
                        [$search_term, $search_term, $limit]
                    )
                );
            } else {
                // No search term - get recent posts ordered by title
                $query = $wpdb->prepare(
                    "SELECT ID, post_title, post_name, post_type
                    FROM {$wpdb->posts}
                    WHERE post_type = %s
                    AND post_status = 'publish'
                    " . ($exclude > 0 ? "AND ID != %d" : "") . "
                    ORDER BY post_title ASC
                    LIMIT %d",
                    array_merge(
                        [$post_type],
                        $exclude > 0 ? [$exclude] : [],
                        [$limit]
                    )
                );
            }

            $posts = $wpdb->get_results($query);

            foreach ($posts as $post) {
                $post_type_obj = get_post_type_object($post->post_type);
                $post_url = wp_make_link_relative(get_permalink($post->ID));
                $post_url = $this->format_permalink_path($post_url);
                $post_path = $this->normalize_path($post_url);

                $is_redirected = $this->is_path_redirected($post_path);

                $results[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'slug' => $post->post_name,
                    'type' => $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type,
                    'url' => $post_url,
                    'edit_url' => get_edit_post_link($post->ID, 'raw'),
                    'is_redirected' => $is_redirected ? true : false,
                    'redirected_to' => $is_redirected ? $is_redirected['to'] : null,
                ];
            }
        }

        wp_send_json_success([
            'posts' => $results,
            'source_already_redirected' => $source_redirect ? true : false,
            'source_redirected_to' => $source_redirect ? $source_redirect['to'] : null,
        ]);
    }

    /**
     * AJAX: Scan a single URL for redirects
     */
    public function ajax_scan_url() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url)) {
            wp_send_json_error(__('URL is required', 'starter-dashboard'));
        }

        // Make sure it's a full URL
        if (strpos($url, 'http') !== 0) {
            $url = home_url($url);
        }

        $result = $this->check_url_redirect($url);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Scan all published posts for redirects
     */
    public function ajax_scan_all() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit = 10; // Process 10 posts at a time

        // Get public post types but exclude Elementor templates and other internal types
        $post_types = get_post_types(['public' => true], 'names');
        $excluded_types = ['elementor_library', 'elementor-thhf', 'elementor_font', 'elementor_icons', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'];
        $post_types = array_diff($post_types, $excluded_types);

        $args = [
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ];

        $post_ids = get_posts($args);
        $total = wp_count_posts('page')->publish + wp_count_posts('post')->publish;
        $results = [];

        foreach ($post_ids as $post_id) {
            $url = get_permalink($post_id);
            $check = $this->check_url_redirect($url);

            if ($check['redirected']) {
                // Determine source from chain or general source
                $source = $check['source'] ?? 'Unknown';
                if (!empty($check['chain']) && isset($check['chain'][0]['source'])) {
                    $source = $check['chain'][0]['source'];
                }

                // Skip redirects managed by our plugin (Hub) - only show external ones
                if ($source === 'plugin' || $source === 'Starter Dashboard') {
                    continue;
                }

                $results[] = [
                    'post_id' => $post_id,
                    'title' => get_the_title($post_id),
                    'url' => wp_make_link_relative($url),
                    'status' => $check['status_code'],
                    'redirects_to' => $check['final_url'],
                    'source' => $source,
                    'chain' => $check['chain'],
                ];
            }
        }

        // If this is the final batch and we have results from this batch,
        // the frontend will accumulate and save via ajax_save_scan_cache
        $is_final = count($post_ids) < $limit;

        wp_send_json_success([
            'results' => $results,
            'processed' => $offset + count($post_ids),
            'total' => $total,
            'has_more' => !$is_final,
            'is_final' => $is_final,
        ]);
    }

    /**
     * AJAX: Get cached scan results
     */
    public function ajax_get_scan_cache() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $cache = get_transient($this->scan_cache_key);
        $cache_time = get_option($this->scan_cache_key . '_time', null);

        wp_send_json_success([
            'results' => $cache ? $cache : [],
            'cached_at' => $cache_time,
            'has_cache' => !empty($cache),
        ]);
    }

    /**
     * AJAX: Clear scan cache
     */
    public function ajax_clear_scan_cache() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        delete_transient($this->scan_cache_key);
        delete_option($this->scan_cache_key . '_time');

        wp_send_json_success(['message' => __('Scan cache cleared', 'starter-dashboard')]);
    }

    /**
     * AJAX: Save scan results to cache
     */
    public function ajax_save_scan_cache() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $results = isset($_POST['results']) ? $_POST['results'] : [];

        // Sanitize results
        $sanitized = [];
        if (is_array($results)) {
            foreach ($results as $item) {
                $sanitized[] = [
                    'post_id' => isset($item['post_id']) ? absint($item['post_id']) : 0,
                    'title' => isset($item['title']) ? sanitize_text_field($item['title']) : '',
                    'url' => isset($item['url']) ? esc_url_raw($item['url']) : '',
                    'status' => isset($item['status']) ? absint($item['status']) : 0,
                    'redirects_to' => isset($item['redirects_to']) ? esc_url_raw($item['redirects_to']) : '',
                ];
            }
        }

        set_transient($this->scan_cache_key, $sanitized, WEEK_IN_SECONDS);
        update_option($this->scan_cache_key . '_time', current_time('mysql'));

        wp_send_json_success([
            'message' => __('Scan results cached', 'starter-dashboard'),
            'count' => count($sanitized),
        ]);
    }

    /**
     * AJAX: Test URL for redirect
     */
    public function ajax_test_url() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';
        if (empty($url)) {
            wp_send_json_error(__('URL is required', 'starter-dashboard'));
        }

        // Build full URL
        $full_url = home_url($url);

        // Use wp_remote_get with redirect following disabled
        $response = wp_remote_get($full_url, [
            'timeout' => 10,
            'redirection' => 0, // Don't follow redirects
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $location = isset($headers['location']) ? $headers['location'] : '';

        $redirected = in_array($status_code, [301, 302, 303, 307, 308]) && !empty($location);

        wp_send_json_success([
            'url' => $full_url,
            'status_code' => $status_code,
            'redirected' => $redirected,
            'redirect_to' => $location,
        ]);
    }

    /**
     * AJAX: Save test result for a redirect
     */
    public function ajax_save_test_result() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $redirect_id = isset($_POST['redirect_id']) ? sanitize_text_field($_POST['redirect_id']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if (empty($redirect_id) || empty($status)) {
            wp_send_json_error(__('Missing required fields', 'starter-dashboard'));
        }

        $redirects = $this->get_redirects();

        // Redirects are stored with ID as array key
        if (isset($redirects[$redirect_id])) {
            $redirects[$redirect_id]['last_test'] = [
                'status' => $status,
                'message' => $message,
                'timestamp' => current_time('timestamp'),
                'date' => current_time('Y-m-d H:i:s'),
            ];
            $this->save_redirects($redirects);
            wp_send_json_success(['message' => __('Test result saved', 'starter-dashboard')]);
        } else {
            wp_send_json_error(__('Redirect not found: ' . $redirect_id, 'starter-dashboard'));
        }
    }

    /**
     * AJAX: Scan external redirect sources (Yoast, Redirection plugin, .htaccess, etc.)
     */
    public function ajax_scan_external_sources() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $results = [];
        $sources_checked = [];

        // 1. Scan Yoast SEO Premium redirects
        $yoast_redirects = $this->scan_yoast_redirects();
        $results = array_merge($results, $yoast_redirects);
        $sources_checked['Yoast SEO Premium'] = [
            'found' => count($yoast_redirects),
            'available' => $this->is_yoast_redirects_available(),
        ];

        // 2. Scan Redirection plugin
        $redirection_redirects = $this->scan_redirection_plugin();
        $results = array_merge($results, $redirection_redirects);
        $sources_checked['Redirection'] = [
            'found' => count($redirection_redirects),
            'available' => $this->is_redirection_available(),
        ];

        // 3. Scan Rank Math redirects
        $rankmath_redirects = $this->scan_rankmath_redirects();
        $results = array_merge($results, $rankmath_redirects);
        $sources_checked['Rank Math'] = [
            'found' => count($rankmath_redirects),
            'available' => $this->is_rankmath_available(),
        ];

        // 4. Scan Simple 301 Redirects plugin
        $simple301_redirects = $this->scan_simple_301_redirects();
        $results = array_merge($results, $simple301_redirects);
        $sources_checked['Simple 301 Redirects'] = [
            'found' => count($simple301_redirects),
            'available' => !empty(get_option('301_redirects', [])),
        ];

        // 5. Scan .htaccess
        $htaccess_redirects = $this->scan_htaccess();
        $results = array_merge($results, $htaccess_redirects);
        $sources_checked['.htaccess'] = [
            'found' => count($htaccess_redirects),
            'available' => file_exists(ABSPATH . '.htaccess'),
        ];

        // 6. Scan Safe Redirect Manager plugin
        $srm_redirects = $this->scan_safe_redirect_manager();
        $results = array_merge($results, $srm_redirects);
        $sources_checked['Safe Redirect Manager'] = [
            'found' => count($srm_redirects),
            'available' => post_type_exists('redirect_rule'),
        ];

        // 7. Scan 301 Redirects plugin (EPS) - uses wp_redirects table
        $eps_redirects = $this->scan_eps_301_redirects();
        $results = array_merge($results, $eps_redirects);
        $sources_checked['301 Redirects (EPS)'] = [
            'found' => count($eps_redirects),
            'available' => $this->is_eps_redirects_available(),
        ];

        // Save to cache
        set_transient($this->scan_cache_key, $results, WEEK_IN_SECONDS);
        update_option($this->scan_cache_key . '_time', current_time('mysql'));

        wp_send_json_success([
            'results' => $results,
            'count' => count($results),
            'cached_at' => current_time('mysql'),
            'sources_checked' => $sources_checked,
        ]);
    }

    /**
     * Check if Yoast redirects table exists
     */
    private function is_yoast_redirects_available() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yoast_seo_redirects';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Check if Redirection plugin table exists
     */
    private function is_redirection_available() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirection_items';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Check if Rank Math redirects table exists
     */
    private function is_rankmath_available() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rank_math_redirections';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Scan Safe Redirect Manager plugin
     */
    private function scan_safe_redirect_manager() {
        $results = [];

        if (!post_type_exists('redirect_rule')) {
            return $results;
        }

        $redirects = get_posts([
            'post_type' => 'redirect_rule',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);

        foreach ($redirects as $redirect) {
            $from = get_post_meta($redirect->ID, '_redirect_rule_from', true);
            $to = get_post_meta($redirect->ID, '_redirect_rule_to', true);
            $status = get_post_meta($redirect->ID, '_redirect_rule_status_code', true);

            if (empty($from) || empty($to)) {
                continue;
            }

            $results[] = [
                'from' => $from,
                'to' => $to,
                'status' => (int) $status ?: 301,
                'source' => 'Safe Redirect Manager',
                'source_icon' => 'plugin',
            ];
        }

        return $results;
    }

    /**
     * Check if EPS 301 Redirects table exists
     */
    private function is_eps_redirects_available() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirects';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Scan 301 Redirects plugin (EPS) - uses wp_redirects table
     */
    private function scan_eps_301_redirects() {
        $results = [];

        global $wpdb;
        $table_name = $wpdb->prefix . 'redirects';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return $results;
        }

        // Status is a string: '301', '302', '307', or 'inactive'
        $redirects = $wpdb->get_results("SELECT * FROM $table_name WHERE status != 'inactive'", ARRAY_A);

        if ($redirects) {
            foreach ($redirects as $redirect) {
                $from = isset($redirect['url_from']) ? $redirect['url_from'] : '';
                $to = isset($redirect['url_to']) ? $redirect['url_to'] : '';

                if (empty($from)) {
                    continue;
                }

                // Handle redirect to post ID - EPS stores post ID in url_to when type='post'
                $to_display = $to;
                $type = isset($redirect['type']) ? $redirect['type'] : 'url';
                if ($type === 'post' && is_numeric($to)) {
                    $post = get_post((int) $to);
                    if ($post) {
                        $to_display = get_permalink($post->ID);
                    }
                }

                $results[] = [
                    'from' => '/' . ltrim($from, '/'),
                    'to' => $to_display ?: $to,
                    'status' => isset($redirect['status']) ? (int) $redirect['status'] : 301,
                    'source' => '301 Redirects (EPS)',
                    'source_icon' => 'plugin',
                    'hits' => isset($redirect['count']) ? (int) $redirect['count'] : 0,
                ];
            }
        }

        return $results;
    }

    /**
     * Scan Yoast SEO Premium redirects
     */
    private function scan_yoast_redirects() {
        $results = [];

        // Yoast Premium stores redirects in wp_yoast_seo_redirects table
        global $wpdb;
        $table_name = $wpdb->prefix . 'yoast_seo_redirects';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return $results;
        }

        $redirects = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

        if ($redirects) {
            foreach ($redirects as $redirect) {
                $results[] = [
                    'from' => '/' . ltrim($redirect['origin'], '/'),
                    'to' => $redirect['url'],
                    'status' => (int) $redirect['type'],
                    'source' => 'Yoast SEO Premium',
                    'source_icon' => 'yoast',
                ];
            }
        }

        return $results;
    }

    /**
     * Scan Redirection plugin
     */
    private function scan_redirection_plugin() {
        $results = [];

        // Redirection plugin stores redirects in wp_redirection_items table
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirection_items';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return $results;
        }

        $redirects = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status = 'enabled'",
            ARRAY_A
        );

        if ($redirects) {
            foreach ($redirects as $redirect) {
                // Redirection plugin uses action_data for the target URL
                $to = $redirect['action_data'];
                if (empty($to)) {
                    continue;
                }

                $results[] = [
                    'from' => $redirect['url'],
                    'to' => $to,
                    'status' => $redirect['action_code'] ?: 301,
                    'source' => 'Redirection',
                    'source_icon' => 'redirection',
                    'hits' => (int) $redirect['last_count'],
                ];
            }
        }

        return $results;
    }

    /**
     * Scan Rank Math redirects
     */
    private function scan_rankmath_redirects() {
        $results = [];

        // Rank Math stores redirects in wp_rank_math_redirections table
        global $wpdb;
        $table_name = $wpdb->prefix . 'rank_math_redirections';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return $results;
        }

        $redirects = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status = 'active'",
            ARRAY_A
        );

        if ($redirects) {
            foreach ($redirects as $redirect) {
                $sources = maybe_unserialize($redirect['sources']);
                $from = is_array($sources) && isset($sources[0]['pattern']) ? $sources[0]['pattern'] : '';

                if (empty($from)) {
                    continue;
                }

                $results[] = [
                    'from' => $from,
                    'to' => $redirect['url_to'],
                    'status' => (int) $redirect['header_code'],
                    'source' => 'Rank Math',
                    'source_icon' => 'rankmath',
                    'hits' => (int) $redirect['hits'],
                ];
            }
        }

        return $results;
    }

    /**
     * Scan Simple 301 Redirects plugin
     */
    private function scan_simple_301_redirects() {
        $results = [];

        // Simple 301 Redirects stores in options
        $redirects = get_option('301_redirects', []);

        if (is_array($redirects) && !empty($redirects)) {
            foreach ($redirects as $from => $to) {
                if (empty($from) || empty($to)) {
                    continue;
                }

                $results[] = [
                    'from' => $from,
                    'to' => $to,
                    'status' => 301,
                    'source' => 'Simple 301 Redirects',
                    'source_icon' => 'plugin',
                ];
            }
        }

        return $results;
    }

    /**
     * Scan .htaccess for redirect rules
     */
    private function scan_htaccess() {
        $results = [];

        $htaccess_path = ABSPATH . '.htaccess';

        if (!file_exists($htaccess_path) || !is_readable($htaccess_path)) {
            return $results;
        }

        $content = file_get_contents($htaccess_path);

        if (empty($content)) {
            return $results;
        }

        // Match Redirect directives: Redirect 301 /old /new
        preg_match_all(
            '/^\s*Redirect\s+(30[1237]|permanent|temp)\s+(\S+)\s+(\S+)/mi',
            $content,
            $redirect_matches,
            PREG_SET_ORDER
        );

        foreach ($redirect_matches as $match) {
            $status = $match[1];
            if ($status === 'permanent') $status = 301;
            if ($status === 'temp') $status = 302;

            $results[] = [
                'from' => $match[2],
                'to' => $match[3],
                'status' => (int) $status,
                'source' => '.htaccess (Redirect)',
                'source_icon' => 'htaccess',
            ];
        }

        // Match RedirectMatch directives: RedirectMatch 301 ^/old(.*)$ /new$1
        preg_match_all(
            '/^\s*RedirectMatch\s+(30[1237]|permanent|temp)\s+(\S+)\s+(\S+)/mi',
            $content,
            $redirectmatch_matches,
            PREG_SET_ORDER
        );

        foreach ($redirectmatch_matches as $match) {
            $status = $match[1];
            if ($status === 'permanent') $status = 301;
            if ($status === 'temp') $status = 302;

            $results[] = [
                'from' => $match[2] . ' (regex)',
                'to' => $match[3],
                'status' => (int) $status,
                'source' => '.htaccess (RedirectMatch)',
                'source_icon' => 'htaccess',
            ];
        }

        // Match RewriteRule redirects: RewriteRule ^old$ /new [R=301,L]
        preg_match_all(
            '/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[.*R(?:=(\d{3}))?.*\]/mi',
            $content,
            $rewrite_matches,
            PREG_SET_ORDER
        );

        foreach ($rewrite_matches as $match) {
            $status = isset($match[3]) ? (int) $match[3] : 302;

            $results[] = [
                'from' => $match[1] . ' (regex)',
                'to' => $match[2],
                'status' => $status,
                'source' => '.htaccess (RewriteRule)',
                'source_icon' => 'htaccess',
            ];
        }

        return $results;
    }

    /**
     * Check if a URL redirects
     */
    private function check_url_redirect($url) {
        $result = [
            'url' => $url,
            'redirected' => false,
            'status_code' => 200,
            'final_url' => $url,
            'chain' => [],
            'source' => null, // 'plugin', 'htaccess', 'wordpress', etc.
        ];

        // First check our own redirects
        $path = wp_make_link_relative($url);
        $our_redirect = $this->is_path_redirected($this->normalize_path($path));

        if ($our_redirect) {
            $result['redirected'] = true;
            $result['status_code'] = 301;
            $result['final_url'] = $our_redirect['to'];
            $result['source'] = 'plugin';
            $result['chain'][] = [
                'from' => $path,
                'to' => $our_redirect['to'],
                'status' => 301,
                'source' => 'Starter Dashboard',
            ];
            return $result;
        }

        // Check actual HTTP response (follow redirects)
        $response = wp_remote_head($url, [
            'timeout' => 10,
            'redirection' => 0, // Don't follow redirects automatically
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $result['status_code'] = $status_code;

        // Check for redirect status codes
        if (in_array($status_code, [301, 302, 303, 307, 308])) {
            $result['redirected'] = true;
            $location = wp_remote_retrieve_header($response, 'location');

            if ($location) {
                // If relative URL, make it absolute
                if (strpos($location, 'http') !== 0) {
                    $location = home_url($location);
                }

                $result['final_url'] = $location;
                $result['chain'][] = [
                    'from' => $url,
                    'to' => $location,
                    'status' => $status_code,
                    'source' => 'Server',
                ];

                // Follow the chain (max 5 redirects)
                $current_url = $location;
                for ($i = 0; $i < 5; $i++) {
                    $chain_response = wp_remote_head($current_url, [
                        'timeout' => 5,
                        'redirection' => 0,
                        'sslverify' => false,
                    ]);

                    if (is_wp_error($chain_response)) {
                        break;
                    }

                    $chain_status = wp_remote_retrieve_response_code($chain_response);
                    if (!in_array($chain_status, [301, 302, 303, 307, 308])) {
                        $result['final_url'] = $current_url;
                        break;
                    }

                    $chain_location = wp_remote_retrieve_header($chain_response, 'location');
                    if (!$chain_location) {
                        break;
                    }

                    if (strpos($chain_location, 'http') !== 0) {
                        $chain_location = home_url($chain_location);
                    }

                    $result['chain'][] = [
                        'from' => $current_url,
                        'to' => $chain_location,
                        'status' => $chain_status,
                        'source' => 'Server',
                    ];

                    $current_url = $chain_location;
                    $result['final_url'] = $chain_location;
                }
            }
        }

        return $result;
    }

    /**
     * Save settings handler
     */
    public function save_settings($response, $data) {
        // Settings are saved via individual AJAX handlers
        return $response;
    }

    /**
     * Find redirects for a specific post
     */
    public static function get_redirects_for_post($post_id) {
        $instance = self::instance();
        $post_path = $instance->normalize_path(wp_make_link_relative(get_permalink($post_id)));
        $redirects = $instance->get_redirects();

        $result = [
            'incoming' => [],
            'outgoing' => null,
        ];

        foreach ($redirects as $id => $redirect) {
            if (empty($redirect['enabled'])) {
                continue;
            }

            $from_path = $instance->normalize_path($redirect['from']);
            $to_path = $instance->normalize_path($redirect['to']);

            if ($to_path === $post_path) {
                $redirect['id'] = $id;
                $result['incoming'][] = $redirect;
            }

            if ($from_path === $post_path) {
                $redirect['id'] = $id;
                $result['outgoing'] = $redirect;
            }
        }

        return $result;
    }

    /**
     * Render settings panel
     */
    public static function render_settings() {
        $instance = self::instance();
        $redirects = $instance->get_redirects();
        $total_hits = 0;
        $active_count = 0;
        $site_url = trailingslashit(home_url());

        foreach ($redirects as $r) {
            if (!empty($r['enabled'])) {
                $active_count++;
            }
            $total_hits += isset($r['hits']) ? $r['hits'] : 0;
        }
        ?>
        <div class="bp-addon-settings bp-redirects-dashboard" data-addon="redirects-301">

            <!-- Stats Bar -->
            <div class="bp-redirects-stats-bar">
                <div class="bp-redirects-stats-bar__left">
                    <span class="bp-redirects-stats-bar__stat">
                        <strong><?php echo count($redirects); ?></strong> <?php _e('redirects', 'starter-dashboard'); ?>
                    </span>
                    <span class="bp-redirects-stats-bar__divider">•</span>
                    <span class="bp-redirects-stats-bar__stat">
                        <strong><?php echo $active_count; ?></strong> <?php _e('active', 'starter-dashboard'); ?>
                    </span>
                    <span class="bp-redirects-stats-bar__divider">•</span>
                    <span class="bp-redirects-stats-bar__stat">
                        <strong><?php echo number_format($total_hits); ?></strong> <?php _e('total hits', 'starter-dashboard'); ?>
                    </span>
                </div>
                <div class="bp-redirects-stats-bar__right">
                    <button type="button" class="bp-btn bp-btn--ghost bp-btn--sm bp-redirects-test-all-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                        <?php _e('Test All', 'starter-dashboard'); ?>
                    </button>
                    <button type="button" class="bp-btn bp-btn--ghost bp-btn--sm bp-redirects-import-btn">
                        <?php _e('Import', 'starter-dashboard'); ?>
                    </button>
                    <button type="button" class="bp-btn bp-btn--ghost bp-btn--sm bp-redirects-export-btn">
                        <?php _e('Export', 'starter-dashboard'); ?>
                    </button>
                </div>
            </div>

            <!-- Redirects Table -->
            <div class="bp-redirects-table-wrapper">
                <table class="bp-redirects-table">
                    <thead>
                        <tr>
                            <th class="col-id"><?php _e('ID', 'starter-dashboard'); ?></th>
                            <th class="col-from"><?php _e('Redirect From', 'starter-dashboard'); ?></th>
                            <th class="col-to"><?php _e('Redirect To', 'starter-dashboard'); ?></th>
                            <th class="col-hits"><?php _e('Hits', 'starter-dashboard'); ?></th>
                            <th class="col-test"><?php _e('Test', 'starter-dashboard'); ?></th>
                            <th class="col-actions"><?php _e('Actions', 'starter-dashboard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Add New Row -->
                        <tr class="bp-redirects-add-row">
                            <td class="col-id">
                                <select id="redirect-type" class="bp-select bp-select--sm">
                                    <option value="301">301</option>
                                    <option value="302">302</option>
                                    <option value="307">307</option>
                                    <option value="off"><?php _e('Off', 'starter-dashboard'); ?></option>
                                </select>
                            </td>
                            <td class="col-from">
                                <div class="bp-redirects-from-field">
                                    <select id="redirect-match-type" class="bp-select bp-select--sm" title="<?php esc_attr_e('Match type', 'starter-dashboard'); ?>">
                                        <option value="exact"><?php _e('Exact', 'starter-dashboard'); ?></option>
                                        <option value="wildcard"><?php _e('Wildcard', 'starter-dashboard'); ?></option>
                                        <option value="regex"><?php _e('Regex', 'starter-dashboard'); ?></option>
                                    </select>
                                    <div class="bp-input-with-prefix">
                                        <span class="bp-input-prefix"><?php echo esc_html($site_url); ?></span>
                                        <input type="text" id="redirect-from" placeholder="old-page" class="bp-input">
                                    </div>
                                </div>
                            </td>
                            <td class="col-to">
                                <div class="bp-redirects-to-field">
                                    <select id="redirect-to-type" class="bp-select bp-select--sm">
                                        <option value="custom"><?php _e('Custom URL', 'starter-dashboard'); ?></option>
                                        <option value="regex"><?php _e('Regex', 'starter-dashboard'); ?></option>
                                        <option value="wordpress"><?php _e('WordPress', 'starter-dashboard'); ?></option>
                                    </select>
                                    <div class="bp-redirects-to-custom" id="redirect-to-custom-wrapper">
                                        <input type="text" id="redirect-to" placeholder="<?php echo esc_attr($site_url); ?>new-page" class="bp-input">
                                    </div>
                                    <div class="bp-redirects-to-wordpress" id="redirect-to-wordpress-wrapper" style="display:none;">
                                        <button type="button" class="bp-btn bp-btn--ghost bp-btn--sm" id="redirect-to-select-wp">
                                            <?php _e('Select Page/Post...', 'starter-dashboard'); ?>
                                        </button>
                                        <span class="bp-redirects-to-selected" id="redirect-to-selected" style="display:none;">
                                            <span class="bp-redirects-to-selected__title"></span>
                                            <button type="button" class="bp-redirects-to-selected__clear" title="<?php esc_attr_e('Clear', 'starter-dashboard'); ?>">&times;</button>
                                        </span>
                                    </div>
                                    <input type="hidden" id="redirect-to-post-id" value="">
                                    <input type="hidden" id="redirect-to-post-title" value="">
                                </div>
                            </td>
                            <td class="col-hits"></td>
                            <td class="col-test"></td>
                            <td class="col-actions">
                                <button type="button" class="bp-btn bp-btn--primary bp-btn--sm bp-redirects-add-btn">
                                    <?php _e('Save', 'starter-dashboard'); ?>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tbody id="bp-redirects-list">
                        <tr>
                            <td colspan="6" class="bp-redirects-loading">
                                <span class="spinner is-active"></span>
                                <?php _e('Loading redirects...', 'starter-dashboard'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Search -->
            <div class="bp-redirects-search-bar">
                <input type="text" id="redirects-search" placeholder="<?php esc_attr_e('Search redirects...', 'starter-dashboard'); ?>" class="bp-input">
            </div>

            <!-- External Redirects Scanner -->
            <div class="bp-redirects-scanner">
                <div class="bp-redirects-scanner__header">
                    <div class="bp-redirects-scanner__title">
                        <h4><?php _e('External Redirects', 'starter-dashboard'); ?></h4>
                        <span class="bp-redirects-scanner__subtitle"><?php _e('Redirects from Yoast, Redirection, Rank Math, .htaccess', 'starter-dashboard'); ?></span>
                    </div>
                    <div class="bp-redirects-scanner__actions">
                        <span class="bp-redirects-scanner__cached-at" id="scanner-cached-at"></span>
                        <button type="button" class="bp-btn bp-btn--ghost bp-btn--sm bp-redirects-scan-btn" id="scanner-start-btn">
                            <?php _e('Scan', 'starter-dashboard'); ?>
                        </button>
                    </div>
                </div>
                <div class="bp-redirects-scanner__results" id="scanner-results">
                    <div class="bp-redirects-scanner__empty">
                        <?php _e('Click "Scan" to find redirects from other plugins and .htaccess.', 'starter-dashboard'); ?>
                    </div>
                </div>
            </div>

            <!-- Import Modal -->
            <div class="bp-redirects-modal" id="bp-redirects-import-modal" style="display:none;">
                <div class="bp-redirects-modal__overlay"></div>
                <div class="bp-redirects-modal__content">
                    <div class="bp-redirects-modal__header">
                        <h4><?php _e('Import Redirects', 'starter-dashboard'); ?></h4>
                        <button type="button" class="bp-redirects-modal__close">&times;</button>
                    </div>
                    <div class="bp-redirects-modal__body">
                        <p><?php _e('Paste CSV data with format: from,to,note (one per line)', 'starter-dashboard'); ?></p>
                        <textarea id="redirects-import-data" rows="10" class="bp-textarea" placeholder="/old-url,/new-url,Optional note
/another-old,/another-new"></textarea>
                        <div class="bp-redirects-import-mode">
                            <label>
                                <input type="radio" name="import-mode" value="merge" checked>
                                <?php _e('Merge with existing', 'starter-dashboard'); ?>
                            </label>
                            <label>
                                <input type="radio" name="import-mode" value="replace">
                                <?php _e('Replace all', 'starter-dashboard'); ?>
                            </label>
                        </div>
                    </div>
                    <div class="bp-redirects-modal__footer">
                        <button type="button" class="bp-btn bp-btn--ghost bp-redirects-modal__cancel"><?php _e('Cancel', 'starter-dashboard'); ?></button>
                        <button type="button" class="bp-btn bp-btn--primary bp-redirects-import-confirm">
                            <?php _e('Import', 'starter-dashboard'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- WordPress Content Selection Modal -->
            <div class="bp-redirects-modal" id="bp-redirects-wp-modal" style="display:none;">
                <div class="bp-redirects-modal__overlay"></div>
                <div class="bp-redirects-modal__content bp-redirects-modal__content--lg">
                    <div class="bp-redirects-modal__header">
                        <h4><?php _e('Select Target Page', 'starter-dashboard'); ?></h4>
                        <button type="button" class="bp-redirects-modal__close">&times;</button>
                    </div>
                    <div class="bp-redirects-modal__body">
                        <div class="bp-redirects-wp-search">
                            <input type="text" id="bp-redirects-wp-search" placeholder="<?php esc_attr_e('Search pages, posts...', 'starter-dashboard'); ?>" class="bp-input">
                        </div>
                        <div class="bp-redirects-wp-list" id="bp-redirects-wp-list">
                            <div class="bp-redirects-wp-loading">
                                <span class="spinner is-active"></span>
                                <?php _e('Loading...', 'starter-dashboard'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test URL Modal -->
            <div class="bp-redirects-test-modal" id="bp-redirects-test-modal">
                <div class="bp-redirects-test-modal__content">
                    <div class="bp-redirects-test-modal__header">
                        <h3 class="bp-redirects-test-modal__title"><?php _e('Test Redirect', 'starter-dashboard'); ?></h3>
                        <button type="button" class="bp-redirects-test-modal__close">&times;</button>
                    </div>
                    <div class="bp-redirects-test-modal__pattern">
                        <div class="bp-redirects-test-modal__pattern-label"><?php _e('Pattern', 'starter-dashboard'); ?></div>
                        <div id="test-modal-pattern"></div>
                    </div>
                    <div class="bp-redirects-test-modal__input-group">
                        <label for="test-modal-url"><?php _e('Test URL (path only)', 'starter-dashboard'); ?></label>
                        <div class="bp-input-with-prefix">
                            <span class="bp-input-prefix"><?php echo esc_html(trailingslashit(home_url())); ?></span>
                            <input type="text" id="test-modal-url" placeholder="example/path" class="bp-input">
                        </div>
                    </div>
                    <div id="test-modal-result" style="display: none;"></div>
                    <div class="bp-redirects-test-modal__actions">
                        <button type="button" class="bp-btn bp-btn--ghost bp-btn--sm bp-redirects-test-modal__cancel"><?php _e('Cancel', 'starter-dashboard'); ?></button>
                        <button type="button" class="bp-btn bp-btn--primary bp-btn--sm" id="test-modal-submit"><?php _e('Test', 'starter-dashboard'); ?></button>
                    </div>
                </div>
            </div>

        </div>

        <style>
            /* UntitledUI-inspired Design System */
            :root {
                --bp-gray-25: #FCFCFD;
                --bp-gray-50: #F9FAFB;
                --bp-gray-100: #F2F4F7;
                --bp-gray-200: #EAECF0;
                --bp-gray-300: #D0D5DD;
                --bp-gray-400: #98A2B3;
                --bp-gray-500: #667085;
                --bp-gray-600: #475467;
                --bp-gray-700: #344054;
                --bp-gray-800: #1D2939;
                --bp-gray-900: #101828;
                --bp-primary-50: #F9F5FF;
                --bp-primary-100: #F4EBFF;
                --bp-primary-500: #7F56D9;
                --bp-primary-600: #6941C6;
                --bp-primary-700: #53389E;
                --bp-success-50: #ECFDF3;
                --bp-success-500: #12B76A;
                --bp-success-700: #027A48;
                --bp-error-50: #FEF3F2;
                --bp-error-500: #F04438;
                --bp-error-700: #B42318;
                --bp-warning-50: #FFFAEB;
                --bp-warning-500: #F79009;
                --bp-warning-700: #B54708;
                --bp-blue-50: #EFF8FF;
                --bp-blue-500: #2E90FA;
                --bp-blue-700: #175CD3;
                --bp-purple-50: #F4F3FF;
                --bp-purple-700: #5925DC;
                --bp-orange-50: #FFF6ED;
                --bp-orange-700: #C4320A;
            }

            .bp-redirects-dashboard {
                max-width: 100%;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            }

            /* Override parent panel */
            .bp-addon-settings-panel:has(.bp-redirects-dashboard) {
                max-width: none;
                overflow: visible;
            }

            /* Buttons */
            .bp-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 10px 16px;
                font-size: 14px;
                font-weight: 500;
                line-height: 1;
                border-radius: 8px;
                border: 1px solid transparent;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .bp-btn--sm {
                padding: 8px 14px;
                font-size: 13px;
                height: 38px;
                box-sizing: border-box;
            }
            .bp-btn--primary {
                background: var(--bp-primary-600);
                color: #fff;
                border-color: var(--bp-primary-600);
            }
            .bp-btn--primary:hover {
                background: var(--bp-primary-700);
                border-color: var(--bp-primary-700);
            }
            .bp-btn--ghost {
                background: transparent;
                color: var(--bp-gray-600);
                border-color: var(--bp-gray-300);
            }
            .bp-btn--ghost:hover {
                background: var(--bp-gray-50);
                color: var(--bp-gray-700);
            }
            .bp-btn--danger {
                background: transparent;
                color: var(--bp-error-700);
                border-color: var(--bp-gray-300);
            }
            .bp-btn--danger:hover {
                background: var(--bp-error-50);
                border-color: var(--bp-error-500);
            }

            /* Inputs */
            .bp-input {
                padding: 8px 14px;
                font-size: 14px;
                line-height: 1.4;
                border: 1px solid var(--bp-gray-300);
                border-radius: 8px;
                background: #fff;
                color: var(--bp-gray-900);
                transition: border-color 0.15s, box-shadow 0.15s;
                width: 100%;
                box-sizing: border-box;
                height: 38px;
            }
            .bp-input:focus {
                outline: none;
                border-color: var(--bp-primary-500);
                box-shadow: 0 0 0 4px var(--bp-primary-100);
            }
            .bp-input--error {
                border-color: var(--bp-error-500) !important;
                background-color: var(--bp-error-50) !important;
            }
            .bp-input--error:focus {
                box-shadow: 0 0 0 4px rgba(240, 68, 56, 0.1) !important;
            }
            .bp-input--warning {
                border-color: var(--bp-warning-500) !important;
                background-color: var(--bp-warning-50) !important;
            }
            .bp-input--warning:focus {
                box-shadow: 0 0 0 4px rgba(247, 144, 9, 0.1) !important;
            }
            .bp-input-hint {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                margin-top: 4px;
                padding: 8px 12px;
                font-size: 12px;
                border-radius: 6px;
                z-index: 10;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            .bp-input-hint__close {
                position: absolute;
                top: 4px;
                right: 6px;
                background: none;
                border: none;
                font-size: 16px;
                cursor: pointer;
                opacity: 0.5;
                line-height: 1;
                padding: 2px 6px;
            }
            .bp-input-hint__close:hover {
                opacity: 1;
            }
            .bp-input-hint--error {
                background: var(--bp-error-50);
                border: 1px solid var(--bp-error-500);
                color: var(--bp-error-700);
            }
            .bp-input-hint--warning {
                background: var(--bp-warning-50);
                border: 1px solid var(--bp-warning-500);
                color: var(--bp-warning-700);
            }
            .bp-input-hint--success {
                background: var(--bp-success-50);
                border: 1px solid var(--bp-success-500);
                color: var(--bp-success-700);
            }
            .bp-input-hint code {
                background: rgba(0,0,0,0.1);
                padding: 1px 4px;
                border-radius: 3px;
                font-family: monospace;
            }
            .bp-input-hint--success code {
                background: rgba(0,0,0,0.08);
            }
            .bp-regex-tester {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-top: 6px;
            }
            .bp-regex-tester__label {
                font-size: 11px;
                color: inherit;
                opacity: 0.8;
                white-space: nowrap;
            }
            .bp-regex-tester__input {
                flex: 1;
                padding: 4px 8px;
                font-size: 11px;
                border: 1px solid rgba(0,0,0,0.15);
                border-radius: 4px;
                background: rgba(255,255,255,0.9);
                font-family: monospace;
                min-width: 0;
            }
            .bp-regex-tester__input:focus {
                outline: none;
                border-color: rgba(0,0,0,0.3);
            }
            .bp-input-hint--success .bp-regex-output {
                margin-top: 6px;
                padding-top: 6px;
                border-top: 1px dashed rgba(0,0,0,0.15);
                font-size: 11px;
            }
            .bp-regex-output code {
                background: rgba(0,0,0,0.08);
                padding: 2px 6px;
                border-radius: 3px;
            }
            .bp-regex-output--match {
                color: var(--bp-success-700);
            }
            .bp-regex-output--no-match {
                color: var(--bp-gray-500);
                font-style: italic;
            }
            .bp-regex-test-live {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                margin-left: 8px;
                padding: 2px 8px;
                font-size: 10px;
                background: rgba(0,0,0,0.1);
                border: none;
                border-radius: 3px;
                cursor: pointer;
                color: inherit;
            }
            .bp-regex-test-live:hover {
                background: rgba(0,0,0,0.15);
            }
            .bp-regex-test-live:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .bp-regex-live-result {
                margin-top: 6px;
                padding: 6px 8px;
                border-radius: 4px;
                font-size: 11px;
            }
            .bp-regex-live-result--success {
                background: var(--bp-success-50);
                border: 1px solid var(--bp-success-500);
                color: var(--bp-success-700);
            }
            .bp-regex-live-result--error {
                background: var(--bp-error-50);
                border: 1px solid var(--bp-error-500);
                color: var(--bp-error-700);
            }
            .bp-regex-live-result code {
                background: rgba(0,0,0,0.1);
                padding: 1px 4px;
                border-radius: 2px;
            }
            .bp-redirects-from-field,
            .bp-redirects-to-custom {
                position: relative;
            }
            .bp-input::placeholder {
                color: var(--bp-gray-400);
            }
            .bp-select {
                padding: 0 20px 0 10px !important;
                font-size: 14px;
                border: 1px solid var(--bp-gray-300);
                border-radius: 8px;
                background: #fff;
                color: var(--bp-gray-900);
                cursor: pointer;
                height: 38px !important;
                box-sizing: border-box;
            }
            .bp-select--sm {
                padding: 0 20px 0 10px !important;
                font-size: 13px;
                height: 38px !important;
            }
            .bp-textarea {
                padding: 12px 14px;
                font-size: 14px;
                border: 1px solid var(--bp-gray-300);
                border-radius: 8px;
                background: #fff;
                resize: vertical;
                width: 100%;
                box-sizing: border-box;
            }

            /* Stats Bar */
            .bp-redirects-stats-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 16px;
                background: var(--bp-gray-50);
                border: 1px solid var(--bp-gray-200);
                border-radius: 8px;
                margin-bottom: 16px;
            }
            .bp-redirects-stats-bar__left {
                display: flex;
                align-items: center;
                gap: 12px;
                font-size: 14px;
                color: var(--bp-gray-600);
            }
            .bp-redirects-stats-bar__left strong {
                color: var(--bp-gray-900);
                font-weight: 600;
            }
            .bp-redirects-stats-bar__divider {
                color: var(--bp-gray-300);
            }
            .bp-redirects-stats-bar__right {
                display: flex;
                gap: 8px;
            }

            /* Table */
            .bp-redirects-table-wrapper {
                border: 1px solid var(--bp-gray-200);
                border-radius: 12px;
                overflow: hidden;
                background: #fff;
            }
            .bp-redirects-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
            }
            .bp-redirects-table thead {
                background: var(--bp-gray-50);
            }
            .bp-redirects-table th {
                padding: 12px 16px;
                text-align: left;
                font-weight: 500;
                font-size: 12px;
                color: var(--bp-gray-600);
                text-transform: uppercase;
                letter-spacing: 0.05em;
                border-bottom: 1px solid var(--bp-gray-200);
            }
            .bp-redirects-table td {
                padding: 12px 16px;
                border-bottom: 1px solid var(--bp-gray-200);
                vertical-align: middle;
            }
            .bp-redirects-table tbody tr:last-child td {
                border-bottom: none;
            }
            .bp-redirects-table tbody tr:hover {
                background: var(--bp-gray-25);
            }
            .bp-redirects-table .col-id { width: 70px; }
            .bp-redirects-table .col-from { width: 32%; }
            .bp-redirects-table .col-to { width: 32%; }
            .bp-redirects-table .col-hits { width: 80px; text-align: right; }
            .bp-redirects-table .col-test { width: 60px; text-align: center; }
            .bp-redirects-table .col-actions { width: 150px; text-align: right; }

            /* Test Badge */
            .bp-redirects-test-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                font-size: 14px;
                font-weight: 600;
                cursor: default;
                position: relative;
            }
            /* Custom tooltip */
            .bp-redirects-test-badge[data-tooltip]:hover::after {
                content: attr(data-tooltip);
                position: absolute;
                bottom: calc(100% + 8px);
                left: 50%;
                transform: translateX(-50%);
                background: var(--bp-gray-900);
                color: #fff;
                padding: 6px 10px;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 400;
                white-space: nowrap;
                z-index: 1000;
                pointer-events: none;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            }
            .bp-redirects-test-badge[data-tooltip]:hover::before {
                content: '';
                position: absolute;
                bottom: calc(100% + 2px);
                left: 50%;
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: var(--bp-gray-900);
                z-index: 1000;
                pointer-events: none;
            }
            .bp-redirects-test-badge--pending {
                background: var(--bp-gray-100);
                color: var(--bp-gray-400);
            }
            .bp-redirects-test-badge--testing {
                background: var(--bp-gray-100);
            }
            .bp-redirects-test-badge--testing .spinner {
                margin: 0;
                float: none;
            }
            .bp-redirects-test-badge--ok {
                background: var(--bp-success-50);
                color: var(--bp-success-700);
            }
            .bp-redirects-test-badge--mismatch {
                background: var(--bp-warning-50);
                color: var(--bp-warning-700);
            }
            .bp-redirects-test-badge--error {
                background: var(--bp-error-50);
                color: var(--bp-error-700);
            }
            .bp-redirects-test-badge--disabled {
                background: var(--bp-gray-100);
                color: var(--bp-gray-400);
            }

            /* Test All Button */
            .bp-redirects-test-all-btn {
                display: inline-flex;
                align-items: center;
            }
            .bp-redirects-test-all-btn.testing {
                opacity: 0.7;
                pointer-events: none;
            }

            /* Single Test Button */
            .bp-redirects-test-single {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 2px 8px;
                font-size: 11px;
                font-weight: 500;
                color: var(--bp-primary-700);
                background: var(--bp-primary-50);
                border: 1px solid var(--bp-primary-200);
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.15s ease;
                margin-left: 4px;
            }
            .bp-redirects-test-single:hover {
                background: var(--bp-primary-100);
                border-color: var(--bp-primary-300);
            }
            .bp-redirects-test-badge + .bp-redirects-test-single {
                padding: 2px 4px;
                font-size: 12px;
            }

            /* Test URL Modal */
            .bp-redirects-test-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 100000;
                align-items: center;
                justify-content: center;
            }
            .bp-redirects-test-modal.active {
                display: flex;
            }
            .bp-redirects-test-modal__content {
                background: #fff;
                border-radius: 12px;
                padding: 24px;
                width: 100%;
                max-width: 500px;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            }
            .bp-redirects-test-modal__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 16px;
            }
            .bp-redirects-test-modal__title {
                font-size: 16px;
                font-weight: 600;
                color: var(--bp-gray-900);
                margin: 0;
            }
            .bp-redirects-test-modal__close {
                background: none;
                border: none;
                font-size: 24px;
                color: var(--bp-gray-400);
                cursor: pointer;
                padding: 0;
                line-height: 1;
            }
            .bp-redirects-test-modal__close:hover {
                color: var(--bp-gray-600);
            }
            .bp-redirects-test-modal__pattern {
                background: var(--bp-gray-50);
                border: 1px solid var(--bp-gray-200);
                border-radius: 8px;
                padding: 12px 16px;
                margin-bottom: 20px;
            }
            .bp-redirects-test-modal__pattern-label {
                font-size: 11px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--bp-gray-500);
                margin-bottom: 6px;
            }
            .bp-redirects-test-modal__pattern > div:last-child {
                font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
                font-size: 13px;
                color: var(--bp-gray-900);
                word-break: break-all;
                line-height: 1.5;
            }
            .bp-redirects-test-modal__input-group {
                margin-bottom: 20px;
            }
            .bp-redirects-test-modal__input-group label {
                display: block;
                font-size: 13px;
                font-weight: 500;
                color: var(--bp-gray-700);
                margin-bottom: 8px;
            }
            .bp-redirects-test-modal__result {
                margin-top: 20px;
                padding: 16px;
                border-radius: 8px;
                font-size: 13px;
                line-height: 1.5;
            }
            .bp-redirects-test-modal__result code {
                display: block;
                background: rgba(0,0,0,0.05);
                padding: 8px 10px;
                border-radius: 4px;
                margin-top: 4px;
                font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
                font-size: 12px;
            }
            .bp-redirects-test-modal__result--success {
                background: var(--bp-success-50);
                border: 1px solid var(--bp-success-500);
                color: var(--bp-success-700);
            }
            .bp-redirects-test-modal__result--error {
                background: var(--bp-error-50);
                border: 1px solid var(--bp-error-500);
                color: var(--bp-error-700);
            }
            .bp-redirects-test-modal__result--mismatch {
                background: var(--bp-warning-50);
                border: 1px solid var(--bp-warning-500);
                color: var(--bp-warning-700);
            }
            .bp-redirects-test-modal__actions {
                display: flex;
                gap: 12px;
                justify-content: flex-end;
                margin-top: 20px;
                padding-top: 16px;
                border-top: 1px solid var(--bp-gray-200);
            }

            /* Add Row */
            .bp-redirects-add-row {
                background: var(--bp-primary-50) !important;
            }
            .bp-redirects-add-row td {
                border-bottom: 2px solid var(--bp-primary-100) !important;
            }

            /* Input with Prefix */
            .bp-input-with-prefix {
                display: flex;
                align-items: stretch;
                border: 1px solid var(--bp-gray-300);
                border-radius: 8px;
                overflow: hidden;
                background: #fff;
                height: 38px;
            }
            .bp-input-with-prefix:focus-within {
                border-color: var(--bp-primary-500);
                box-shadow: 0 0 0 4px var(--bp-primary-100);
            }
            .bp-input-prefix {
                display: flex;
                align-items: center;
                padding: 0 12px;
                background: var(--bp-gray-50);
                color: var(--bp-gray-500);
                font-size: 13px;
                border-right: 1px solid var(--bp-gray-300);
                white-space: nowrap;
            }
            .bp-input-with-prefix .bp-input {
                border: none !important;
                border-radius: 0 8px 8px 0 !important;
                margin: 0 !important;
                flex: 1;
                height: auto;
            }
            .bp-input-with-prefix .bp-input:focus {
                box-shadow: none !important;
            }

            /* From Field */
            .bp-redirects-from-field {
                display: flex;
                gap: 8px;
                align-items: center;
                position: relative;
            }
            .bp-redirects-from-field .bp-select {
                flex-shrink: 0;
                width: 90px;
            }
            .bp-redirects-from-field .bp-input-with-prefix {
                flex: 1;
            }

            /* To Field */
            .bp-redirects-to-field {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .bp-redirects-to-field .bp-select {
                flex-shrink: 0;
                width: 145px;
            }
            .bp-redirects-to-field .bp-input {
                flex: 1;
            }

            /* Row Data */
            .bp-redirects-row__id {
                font-weight: 500;
                color: var(--bp-gray-500);
            }
            .bp-redirects-row__status {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 4px 10px;
                font-size: 12px;
                font-weight: 600;
                border-radius: 6px;
                min-width: 40px;
            }
            .bp-redirects-row__status--301 {
                background: var(--bp-success-50);
                color: var(--bp-success-700);
            }
            .bp-redirects-row__status--302 {
                background: var(--bp-warning-50);
                color: var(--bp-warning-700);
            }
            .bp-redirects-row__status--307 {
                background: var(--bp-blue-50);
                color: var(--bp-blue-700);
            }
            .bp-redirects-row__status--off {
                background: var(--bp-gray-100);
                color: var(--bp-gray-500);
            }
            .bp-redirects-row__match-type {
                display: inline-block;
                padding: 2px 6px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                border-radius: 4px;
                margin-right: 6px;
                vertical-align: middle;
            }
            .bp-redirects-row__match-type--wildcard {
                background: var(--bp-purple-50);
                color: var(--bp-purple-700);
            }
            .bp-redirects-row__match-type--regex {
                background: var(--bp-orange-50);
                color: var(--bp-orange-700);
            }
            .bp-redirects-row__from {
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
                font-size: 13px;
                color: var(--bp-gray-700);
            }
            .bp-redirects-row__from-base {
                color: var(--bp-gray-400);
            }
            .bp-redirects-row__from-path {
                color: var(--bp-blue-700);
                font-weight: 500;
            }
            .bp-redirects-row__to {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .bp-redirects-row__to-type {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 2px 8px;
                font-size: 12px;
                font-weight: 500;
                border-radius: 6px;
                flex-shrink: 0;
            }
            .bp-redirects-row__to-type--hub {
                background: var(--bp-success-50);
                color: var(--bp-success-700);
            }
            .bp-redirects-row__to-type--external {
                background: var(--bp-warning-50);
                color: var(--bp-warning-500);
            }
            .bp-redirects-row__to-info {
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-width: 0;
            }
            .bp-redirects-row__to-id {
                font-size: 12px;
                color: var(--bp-gray-500);
            }
            .bp-redirects-row__to-title {
                color: var(--bp-blue-700);
                font-weight: 500;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .bp-redirects-row__to-url {
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
                font-size: 13px;
                color: var(--bp-success-700);
            }
            .bp-redirects-row__hits {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 6px;
                font-weight: 500;
                color: var(--bp-gray-700);
            }
            .bp-redirects-row__hits-icon {
                color: var(--bp-gray-400);
            }
            .bp-redirects-row__actions {
                display: flex;
                gap: 8px;
                justify-content: flex-end;
            }

            /* To Field - WordPress Selector */
            .bp-redirects-to-field {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .bp-redirects-to-field .bp-select {
                flex-shrink: 0;
                width: auto;
            }
            .bp-redirects-to-custom {
                flex: 1;
                position: relative;
            }
            .bp-redirects-to-wordpress {
                flex: 1;
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .bp-redirects-to-selected {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 12px;
                background: var(--bp-success-50);
                border: 1px solid var(--bp-success-500);
                border-radius: 6px;
                font-size: 13px;
            }
            .bp-redirects-to-selected__title {
                color: var(--bp-success-700);
                font-weight: 500;
            }
            .bp-redirects-to-selected__clear {
                background: none;
                border: none;
                font-size: 16px;
                color: var(--bp-gray-500);
                cursor: pointer;
                padding: 0;
                line-height: 1;
            }
            .bp-redirects-to-selected__clear:hover {
                color: var(--bp-error-500);
            }

            /* WordPress Selection Modal */
            .bp-redirects-modal__content--lg {
                max-width: 600px;
            }
            .bp-redirects-wp-search {
                margin-bottom: 16px;
            }
            .bp-redirects-wp-list {
                max-height: 400px;
                overflow-y: auto;
                border: 1px solid var(--bp-gray-200);
                border-radius: 8px;
            }
            .bp-redirects-wp-loading {
                padding: 32px;
                text-align: center;
                color: var(--bp-gray-500);
            }
            .bp-redirects-wp-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 16px;
                border-bottom: 1px solid var(--bp-gray-100);
                cursor: pointer;
                transition: background 0.15s;
            }
            .bp-redirects-wp-item:last-child {
                border-bottom: none;
            }
            .bp-redirects-wp-item:hover {
                background: var(--bp-gray-50);
            }
            .bp-redirects-wp-item__info {
                flex: 1;
                min-width: 0;
            }
            .bp-redirects-wp-item__title {
                font-weight: 500;
                color: var(--bp-gray-900);
                margin-bottom: 4px;
            }
            .bp-redirects-wp-item__url {
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
                font-size: 12px;
                color: var(--bp-gray-500);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .bp-redirects-wp-item__type {
                padding: 2px 8px;
                background: var(--bp-gray-100);
                color: var(--bp-gray-600);
                font-size: 11px;
                font-weight: 500;
                border-radius: 4px;
                text-transform: uppercase;
                flex-shrink: 0;
                margin-left: 12px;
            }
            .bp-redirects-wp-empty {
                padding: 32px;
                text-align: center;
                color: var(--bp-gray-500);
            }

            /* Search Bar */
            .bp-redirects-search-bar {
                margin-top: 16px;
                max-width: 300px;
            }

            /* External Links Scanner */
            .bp-redirects-scanner {
                margin-top: 32px;
                border: 1px solid var(--bp-gray-200);
                border-radius: 12px;
                background: #fff;
                overflow: hidden;
            }
            .bp-redirects-scanner__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                background: var(--bp-gray-50);
                border-bottom: 1px solid var(--bp-gray-200);
            }
            .bp-redirects-scanner__title h4 {
                margin: 0 0 4px 0;
                font-size: 14px;
                font-weight: 600;
                color: var(--bp-gray-900);
            }
            .bp-redirects-scanner__subtitle {
                font-size: 13px;
                color: var(--bp-gray-500);
            }
            .bp-redirects-scanner__actions {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .bp-redirects-scanner__cached-at {
                font-size: 12px;
                color: var(--bp-gray-400);
            }
            .bp-redirects-scanner__progress {
                padding: 12px 20px;
                background: var(--bp-primary-50);
                border-bottom: 1px solid var(--bp-primary-100);
            }
            .bp-redirects-scanner__progress-bar {
                height: 6px;
                background: var(--bp-gray-200);
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 8px;
            }
            .bp-redirects-scanner__progress-fill {
                height: 100%;
                background: var(--bp-primary-500);
                border-radius: 3px;
                transition: width 0.3s ease;
                width: 0%;
            }
            .bp-redirects-scanner__progress-text {
                font-size: 12px;
                color: var(--bp-gray-600);
            }
            .bp-redirects-scanner__results {
                max-height: 400px;
                overflow-y: auto;
            }
            .bp-redirects-scanner__empty {
                padding: 32px;
                text-align: center;
                color: var(--bp-gray-500);
                font-size: 14px;
            }
            .bp-redirects-scanner__item {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 20px;
                border-bottom: 1px solid var(--bp-gray-100);
            }
            .bp-redirects-scanner__item:last-child {
                border-bottom: none;
            }
            .bp-redirects-scanner__item-badge {
                padding: 2px 8px;
                font-size: 11px;
                font-weight: 500;
                border-radius: 4px;
                background: var(--bp-warning-50);
                color: var(--bp-warning-500);
                flex-shrink: 0;
                text-transform: uppercase;
            }
            .bp-redirects-scanner__item-info {
                flex: 1;
                min-width: 0;
            }
            .bp-redirects-scanner__item-url {
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
                font-size: 13px;
                color: var(--bp-gray-700);
                word-break: break-all;
            }
            .bp-redirects-scanner__item-source {
                font-size: 12px;
                color: var(--bp-gray-500);
                margin-top: 4px;
            }
            .bp-redirects-scanner__item-label {
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                color: var(--bp-gray-400);
                margin-right: 4px;
            }
            .bp-redirects-scanner__item-from {
                margin-bottom: 6px;
            }
            .bp-redirects-scanner__item-from strong {
                color: var(--bp-gray-800);
            }
            .bp-redirects-scanner__item-path {
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
                font-size: 12px;
                color: var(--bp-gray-500);
            }
            .bp-redirects-scanner__item-to {
                display: flex;
                align-items: center;
            }
            .bp-redirects-scanner__item-dest {
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
                font-size: 13px;
                color: var(--bp-warning-500);
                text-decoration: none;
                word-break: break-all;
            }
            .bp-redirects-scanner__item-dest:hover {
                text-decoration: underline;
                color: var(--bp-warning-700);
            }
            .bp-redirects-scanner__item-source-info {
                margin-top: 6px;
                padding-top: 6px;
                border-top: 1px dashed var(--bp-gray-200);
            }
            .bp-redirects-scanner__item-source-name {
                font-weight: 500;
                color: var(--bp-gray-600);
                background: var(--bp-gray-100);
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 12px;
            }
            .bp-redirects-scanner__item-status {
                font-size: 11px;
                color: var(--bp-gray-400);
            }
            .bp-redirects-scanner__item-hits {
                margin-top: 4px;
                font-size: 12px;
                color: var(--bp-gray-500);
            }

            /* Grouped display */
            .bp-redirects-scanner__group {
                border-bottom: 1px solid var(--bp-gray-200);
            }
            .bp-redirects-scanner__group:last-child {
                border-bottom: none;
            }
            .bp-redirects-scanner__group-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 20px;
                background: var(--bp-gray-50);
                border-bottom: 1px solid var(--bp-gray-100);
            }
            .bp-redirects-scanner__group-name {
                font-weight: 600;
                color: var(--bp-gray-700);
                font-size: 13px;
            }
            .bp-redirects-scanner__group-count {
                font-size: 12px;
                color: var(--bp-gray-500);
            }

            /* Status badges */
            .bp-redirects-scanner__item-badge--301 {
                background: var(--bp-success-50);
                color: var(--bp-success-700);
            }
            .bp-redirects-scanner__item-badge--302,
            .bp-redirects-scanner__item-badge--307 {
                background: var(--bp-warning-50);
                color: var(--bp-warning-500);
            }

            /* Error state */
            .bp-redirects-scanner__empty--error {
                color: var(--bp-error-500);
            }

            /* Sources checked list */
            .bp-redirects-scanner__sources-checked {
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid var(--bp-gray-200);
                text-align: left;
                font-size: 13px;
            }
            .bp-redirects-scanner__source-item {
                display: inline-block;
                margin: 4px 8px 4px 0;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 12px;
            }
            .bp-redirects-scanner__source-item--available {
                background: var(--bp-success-50);
                color: var(--bp-success-700);
            }
            .bp-redirects-scanner__source-item--not-available {
                background: var(--bp-gray-100);
                color: var(--bp-gray-400);
            }

            .bp-redirects-scanner__count {
                padding: 12px 20px;
                background: var(--bp-gray-50);
                border-top: 1px solid var(--bp-gray-200);
                font-size: 13px;
                color: var(--bp-gray-600);
            }

            /* Empty State */
            .bp-redirects-empty {
                padding: 48px 24px;
                text-align: center;
                color: var(--bp-gray-500);
            }

            /* Loading */
            .bp-redirects-loading {
                padding: 24px;
                text-align: center;
                color: var(--bp-gray-500);
            }

            /* Disabled row */
            .bp-redirects-row--disabled {
                opacity: 0.5;
            }
            .bp-redirects-row--disabled .bp-redirects-row__from-path {
                text-decoration: line-through;
            }

            /* Modal */
            .bp-redirects-modal {
                position: fixed;
                inset: 0;
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .bp-redirects-modal__overlay {
                position: absolute;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
            }
            .bp-redirects-modal__content {
                position: relative;
                background: #fff;
                border-radius: 12px;
                width: 100%;
                max-width: 500px;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            }
            .bp-redirects-modal__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid var(--bp-gray-200);
            }
            .bp-redirects-modal__header h4 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
            }
            .bp-redirects-modal__close {
                background: none;
                border: none;
                font-size: 24px;
                color: var(--bp-gray-400);
                cursor: pointer;
                line-height: 1;
            }
            .bp-redirects-modal__body {
                padding: 20px;
            }
            .bp-redirects-modal__footer {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
                padding: 16px 20px;
                border-top: 1px solid var(--bp-gray-200);
            }
            .bp-redirects-import-mode {
                display: flex;
                gap: 20px;
                margin-top: 12px;
            }
            .bp-redirects-import-mode label {
                display: flex;
                align-items: center;
                gap: 6px;
                cursor: pointer;
            }
        </style>

        <script>
        jQuery(function($) {
            var $container = $('.bp-redirects-dashboard');
            var redirects = [];
            var redirectTestResults = {};
            var siteUrl = '<?php echo esc_js(trailingslashit(home_url())); ?>';

            // Save test result to database
            function saveTestResult(redirectId, status, message) {
                $.post(ajaxurl, {
                    action: 'starter_redirects_save_test_result',
                    nonce: starterDashboard.nonce,
                    redirect_id: redirectId,
                    status: status,
                    message: message || ''
                });
                // Update in-memory redirect data
                var redirect = redirects.find(function(r) { return r.id === redirectId; });
                if (redirect) {
                    redirect.last_test = {
                        status: status,
                        message: message || '',
                        date: new Date().toLocaleString('pl-PL')
                    };
                }
            }

            // Load redirects
            function loadRedirects() {
                $.post(ajaxurl, {
                    action: 'starter_redirects_get_all',
                    nonce: starterDashboard.nonce
                }, function(response) {
                    if (response.success) {
                        redirects = response.data;
                        renderRedirects();
                    }
                });
            }

            // Build tooltip with timestamp if available
            function buildTooltip(baseText, testData) {
                var parts = [baseText];
                if (testData && testData.date) {
                    parts.push('<?php esc_attr_e('Tested:', 'starter-dashboard'); ?> ' + testData.date);
                }
                if (testData && testData.message) {
                    parts.push(testData.message);
                }
                var result = parts.join(' | ');
                // Simple attribute escaping
                return result.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            // Render redirects list
            function renderRedirects(filter) {
                var $list = $('#bp-redirects-list');
                var filtered = redirects;

                if (filter) {
                    filter = filter.toLowerCase();
                    filtered = redirects.filter(function(r) {
                        return r.from.toLowerCase().indexOf(filter) !== -1 ||
                               r.to.toLowerCase().indexOf(filter) !== -1 ||
                               (r.note && r.note.toLowerCase().indexOf(filter) !== -1);
                    });
                }

                if (filtered.length === 0) {
                    $list.html('<tr><td colspan="6" class="bp-redirects-empty">' +
                        (filter ? '<?php _e('No redirects match your search', 'starter-dashboard'); ?>' :
                                  '<?php _e('No redirects yet. Add your first one above!', 'starter-dashboard'); ?>') +
                        '</td></tr>');
                    return;
                }

                var html = '';
                filtered.forEach(function(r, index) {
                    var hits = r.hits || 0;
                    var disabledClass = !r.enabled ? ' bp-redirects-row--disabled' : '';
                    var isInternal = r.is_internal;
                    var statusCode = r.status_code || '301';

                    html += '<tr class="bp-redirects-row' + disabledClass + '" data-id="' + r.id + '">';

                    // ID column with status code badge
                    html += '<td class="col-id">';
                    if (r.enabled) {
                        html += '<span class="bp-redirects-row__status bp-redirects-row__status--' + statusCode + '">' + statusCode + '</span>';
                    } else {
                        html += '<span class="bp-redirects-row__status bp-redirects-row__status--off"><?php _e('Off', 'starter-dashboard'); ?></span>';
                    }
                    html += '</td>';

                    // From column
                    html += '<td class="col-from">';
                    var matchType = r.match_type || 'exact';
                    if (matchType !== 'exact') {
                        html += '<span class="bp-redirects-row__match-type bp-redirects-row__match-type--' + matchType + '">' + matchType + '</span>';
                    }
                    html += '<span class="bp-redirects-row__from">';
                    html += '<span class="bp-redirects-row__from-base">' + siteUrl + '</span>';
                    html += '<span class="bp-redirects-row__from-path">' + escapeHtml(r.from.replace(/^\//, '')) + '</span>';
                    html += '</span>';
                    html += '</td>';

                    // To column
                    html += '<td class="col-to">';
                    html += '<div class="bp-redirects-row__to">';

                    // Hub or External badge
                    if (isInternal) {
                        html += '<span class="bp-redirects-row__to-type bp-redirects-row__to-type--hub">HUB</span>';
                    } else {
                        html += '<span class="bp-redirects-row__to-type bp-redirects-row__to-type--external">EXTERNAL</span>';
                    }

                    // Show page info if we have title/post_id, otherwise just URL
                    if (r.to_title || r.to_post_id) {
                        html += '<div class="bp-redirects-row__to-info">';
                        if (r.to_title) {
                            html += '<span class="bp-redirects-row__to-title">' + escapeHtml(r.to_title) + '</span>';
                        }
                        html += '<span class="bp-redirects-row__to-url">' + escapeHtml(r.to) + '</span>';
                        html += '</div>';
                    } else {
                        html += '<span class="bp-redirects-row__to-url">' + escapeHtml(r.to) + '</span>';
                    }

                    html += '</div>';
                    html += '</td>';

                    // Hits column
                    html += '<td class="col-hits">';
                    html += '<span class="bp-redirects-row__hits">';
                    html += '<span>' + hits.toLocaleString() + '</span>';
                    html += '<svg class="bp-redirects-row__hits-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>';
                    html += '</span>';
                    html += '</td>';

                    // Test status column
                    html += '<td class="col-test">';
                    // Use in-memory result or saved last_test from redirect data
                    var testStatus = redirectTestResults[r.id] || (r.last_test ? r.last_test : null);
                    var needsManualTest = (matchType === 'regex' || matchType === 'wildcard') && r.enabled;

                    if (!r.enabled) {
                        html += '<span class="bp-redirects-test-badge bp-redirects-test-badge--disabled" data-tooltip="<?php esc_attr_e('Disabled', 'starter-dashboard'); ?>">–</span>';
                    } else if (testStatus) {
                        if (testStatus.status === 'testing') {
                            html += '<span class="bp-redirects-test-badge bp-redirects-test-badge--testing"><span class="spinner is-active"></span></span>';
                        } else if (testStatus.status === 'ok') {
                            html += '<span class="bp-redirects-test-badge bp-redirects-test-badge--ok" data-tooltip="' + buildTooltip('<?php esc_attr_e('Working', 'starter-dashboard'); ?>', testStatus) + '">✓</span>';
                        } else if (testStatus.status === 'mismatch') {
                            html += '<span class="bp-redirects-test-badge bp-redirects-test-badge--mismatch" data-tooltip="' + buildTooltip('<?php esc_attr_e('Mismatch', 'starter-dashboard'); ?>', testStatus) + '">≠</span>';
                        } else if (testStatus.status === 'error') {
                            html += '<span class="bp-redirects-test-badge bp-redirects-test-badge--error" data-tooltip="' + buildTooltip('<?php esc_attr_e('Error', 'starter-dashboard'); ?>', testStatus) + '">✗</span>';
                        }
                        // Add re-test button for regex/wildcard after showing result
                        if (needsManualTest) {
                            html += '<button type="button" class="bp-redirects-test-single" data-id="' + r.id + '" title="<?php esc_attr_e('Test again', 'starter-dashboard'); ?>">↻</button>';
                        }
                    } else if (needsManualTest) {
                        // Show test button for regex/wildcard that haven't been tested
                        html += '<button type="button" class="bp-redirects-test-single" data-id="' + r.id + '" title="<?php esc_attr_e('Test with URL', 'starter-dashboard'); ?>"><?php _e('Test', 'starter-dashboard'); ?></button>';
                    } else {
                        html += '<span class="bp-redirects-test-badge bp-redirects-test-badge--pending" data-tooltip="<?php esc_attr_e('Not tested', 'starter-dashboard'); ?>">?</span>';
                    }
                    html += '</td>';

                    // Actions column
                    html += '<td class="col-actions">';
                    html += '<div class="bp-redirects-row__actions">';
                    html += '<button type="button" class="bp-btn bp-btn--ghost bp-btn--sm edit"><?php _e('Edit', 'starter-dashboard'); ?></button>';
                    html += '<button type="button" class="bp-btn bp-btn--danger bp-btn--sm delete"><?php _e('Delete', 'starter-dashboard'); ?></button>';
                    html += '</div>';
                    html += '</td>';

                    html += '</tr>';
                });

                $list.html(html);
            }

            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function escapeAttr(text) {
                if (!text) return '';
                return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            // === To Type Selector ===
            $('#redirect-to-type').on('change', function() {
                var type = $(this).val();
                if (type === 'wordpress') {
                    $('#redirect-to-custom-wrapper').hide();
                    $('#redirect-to-wordpress-wrapper').show();
                } else {
                    $('#redirect-to-custom-wrapper').show();
                    $('#redirect-to-wordpress-wrapper').hide();
                    // Update placeholder based on type
                    if (type === 'regex') {
                        $('#redirect-to').attr('placeholder', '/new-path/$1/');
                    } else {
                        $('#redirect-to').attr('placeholder', '<?php echo esc_attr($site_url); ?>new-page');
                    }
                }
            });

            // === WordPress Selection Modal ===
            var wpSearchTimeout = null;

            $('#redirect-to-select-wp').on('click', function() {
                $('#bp-redirects-wp-modal').show();
                loadWpPosts('');
                $('#bp-redirects-wp-search').val('').focus();
            });

            // Close modal
            $('#bp-redirects-wp-modal').on('click', '.bp-redirects-modal__overlay, .bp-redirects-modal__close', function() {
                $('#bp-redirects-wp-modal').hide();
            });

            // Search in modal
            $('#bp-redirects-wp-search').on('input', function() {
                clearTimeout(wpSearchTimeout);
                var search = $(this).val();
                wpSearchTimeout = setTimeout(function() {
                    loadWpPosts(search);
                }, 300);
            });

            // Load WordPress posts
            function loadWpPosts(search) {
                var $list = $('#bp-redirects-wp-list');
                $list.html('<div class="bp-redirects-wp-loading"><span class="spinner is-active"></span> <?php _e('Loading...', 'starter-dashboard'); ?></div>');

                $.post(ajaxurl, {
                    action: 'starter_redirects_search_posts',
                    nonce: '<?php echo wp_create_nonce('starter_redirects_nonce'); ?>',
                    search: search,
                    from_path: ''
                }, function(response) {
                    if (!response.success || !response.data.posts || response.data.posts.length === 0) {
                        $list.html('<div class="bp-redirects-wp-empty"><?php _e('No content found', 'starter-dashboard'); ?></div>');
                        return;
                    }

                    var html = '';
                    response.data.posts.forEach(function(post) {
                        html += '<div class="bp-redirects-wp-item" data-url="' + escapeHtml(post.url) + '" data-id="' + post.id + '" data-title="' + escapeHtml(post.title) + '">';
                        html += '<div class="bp-redirects-wp-item__info">';
                        html += '<div class="bp-redirects-wp-item__title">' + escapeHtml(post.title) + '</div>';
                        html += '<div class="bp-redirects-wp-item__url">/' + escapeHtml(post.slug) + '/</div>';
                        html += '</div>';
                        html += '<span class="bp-redirects-wp-item__type">' + escapeHtml(post.type) + '</span>';
                        html += '</div>';
                    });
                    $list.html(html);
                });
            }

            // Select post from modal
            $(document).on('click', '.bp-redirects-wp-item', function() {
                var url = $(this).data('url');
                var postId = $(this).data('id');
                var title = $(this).data('title');

                // Set values
                $('#redirect-to').val(url);
                $('#redirect-to-post-id').val(postId);
                $('#redirect-to-post-title').val(title);

                // Show selected
                $('#redirect-to-select-wp').hide();
                $('#redirect-to-selected').show().find('.bp-redirects-to-selected__title').text(title);

                // Close modal
                $('#bp-redirects-wp-modal').hide();
            });

            // Clear selected WordPress post
            $('.bp-redirects-to-selected__clear').on('click', function() {
                $('#redirect-to').val('');
                $('#redirect-to-post-id').val('');
                $('#redirect-to-post-title').val('');
                $('#redirect-to-selected').hide();
                $('#redirect-to-select-wp').show();
            });

            // Validate regex pattern
            function isValidRegex(pattern) {
                try {
                    new RegExp(pattern);
                    return { valid: true };
                } catch (e) {
                    return { valid: false, error: e.message };
                }
            }

            // Check if pattern contains regex metacharacters
            function hasRegexChars(pattern) {
                // Common regex metacharacters: ^ $ . * + ? ( ) [ ] { } | \
                return /[\^\$\.\*\+\?\(\)\[\]\{\}\|\\]/.test(pattern);
            }

            // Real-time regex validation
            function validateRegexField($input, isToField) {
                var pattern = $input.val().trim();
                var matchType = $('#redirect-match-type').val();
                var toType = $('#redirect-to-type').val();
                var $container = isToField ? $input.closest('.bp-redirects-to-custom') : $input.closest('.bp-redirects-from-field');

                // Remove existing hints
                $container.find('.bp-input-hint').remove();

                // Only validate if regex mode is selected
                if (isToField && toType !== 'regex') {
                    $input.removeClass('bp-input--error bp-input--warning');
                    return;
                }
                if (!isToField && matchType !== 'regex') {
                    $input.removeClass('bp-input--error bp-input--warning');
                    return;
                }

                if (!pattern) {
                    $input.removeClass('bp-input--error bp-input--warning');
                    return;
                }

                // Check for regex syntax errors
                var validation = isValidRegex(isToField ? pattern.replace(/\$\d+/g, 'x') : pattern);
                if (!validation.valid) {
                    $input.addClass('bp-input--error').removeClass('bp-input--warning');
                    $container.append('<div class="bp-input-hint bp-input-hint--error"><strong><?php _e('Invalid regex syntax', 'starter-dashboard'); ?></strong><br>' + validation.error + '</div>');
                    return;
                }

                // For "from" field - warn if no regex chars, show compact tester
                if (!isToField) {
                    if (!hasRegexChars(pattern)) {
                        $input.addClass('bp-input--warning').removeClass('bp-input--error');
                        var html = '<div class="bp-input-hint bp-input-hint--warning">';
                        html += '<button type="button" class="bp-input-hint__close">&times;</button>';
                        html += '<strong><?php _e('Use regex pattern', 'starter-dashboard'); ?></strong><br><?php _e('Examples:', 'starter-dashboard'); ?> <code>^/blog/(.*)$</code> <code>/old-(.*)/page</code></div>';
                        $container.append(html);
                    } else {
                        $input.removeClass('bp-input--error bp-input--warning');
                        // Compact tester - preserve test URL
                        var savedTestUrl = window.regexTestUrl || '';
                        var testerHtml = '<div class="bp-input-hint bp-input-hint--success">';
                        testerHtml += '<button type="button" class="bp-input-hint__close">&times;</button>';
                        testerHtml += '<div class="bp-regex-tester">';
                        testerHtml += '<span class="bp-regex-tester__label"><?php _e('Test:', 'starter-dashboard'); ?></span>';
                        testerHtml += '<input type="text" class="bp-regex-tester__input" id="regex-test-url" placeholder="/test-url/" value="' + escapeHtml(savedTestUrl) + '">';
                        testerHtml += '</div>';
                        testerHtml += '<div class="bp-regex-output" id="regex-from-output"></div>';
                        testerHtml += '</div>';
                        $container.append(testerHtml);
                        // Trigger test if there's already a value
                        updateRegexTest();
                    }
                    return;
                }

                // For "to" field - require $1, $2 etc, show output preview
                if (isToField) {
                    if (!/\$\d+/.test(pattern)) {
                        $input.addClass('bp-input--error').removeClass('bp-input--warning');
                        var html = '<div class="bp-input-hint bp-input-hint--error">';
                        html += '<button type="button" class="bp-input-hint__close">&times;</button>';
                        html += '<strong><?php _e('Add capture group reference', 'starter-dashboard'); ?></strong><br><?php _e('Example:', 'starter-dashboard'); ?> <code>/new-path/$1/</code></div>';
                        $container.append(html);
                    } else {
                        $input.removeClass('bp-input--error bp-input--warning');
                        // Show output preview
                        var testerHtml = '<div class="bp-input-hint bp-input-hint--success">';
                        testerHtml += '<button type="button" class="bp-input-hint__close">&times;</button>';
                        testerHtml += '<div class="bp-regex-output" id="regex-to-output"><?php _e('Enter test URL in "From" field', 'starter-dashboard'); ?></div>';
                        testerHtml += '</div>';
                        $container.append(testerHtml);
                        // Update with current test
                        updateRegexTest();
                    }
                }
            }

            // Update regex test results in both fields
            function updateRegexTest() {
                var testUrl = $('#regex-test-url').val();
                var $fromOutput = $('#regex-from-output');
                var $toOutput = $('#regex-to-output');

                if (!testUrl) {
                    $fromOutput.html('');
                    $toOutput.html('<span class="bp-regex-output--no-match"><?php _e('Enter test URL above', 'starter-dashboard'); ?></span>');
                    return;
                }

                var fromPattern = $('#redirect-from').val().trim();
                var toPattern = $('#redirect-to').val().trim();

                try {
                    var regex = new RegExp(fromPattern);
                    var match = testUrl.match(regex);

                    if (match) {
                        // Show match in From field with Test Live button
                        var groups = match.slice(1).map(function(g, i) { return '$' + (i+1) + '=' + (g || ''); }).join(', ');
                        var fromHtml = '<span class="bp-regex-output--match">✓ <?php _e('Match', 'starter-dashboard'); ?>' + (groups ? ' <code>' + groups + '</code>' : '') + '</span>';
                        fromHtml += '<button type="button" class="bp-regex-test-live" data-url="' + escapeHtml(testUrl) + '"><?php _e('Test Live', 'starter-dashboard'); ?> ↗</button>';
                        $fromOutput.html(fromHtml);

                        // Show result in To field
                        if (toPattern && /\$\d+/.test(toPattern)) {
                            var resultUrl = toPattern;
                            for (var i = 1; i < match.length; i++) {
                                resultUrl = resultUrl.replace(new RegExp('\\$' + i, 'g'), match[i] || '');
                            }
                            $toOutput.html('<span class="bp-regex-output--match">→ <code>' + escapeHtml(resultUrl) + '</code></span>');
                        }
                    } else {
                        $fromOutput.html('<span class="bp-regex-output--no-match">✗ <?php _e('No match', 'starter-dashboard'); ?></span>');
                        $toOutput.html('<span class="bp-regex-output--no-match">—</span>');
                    }
                } catch (e) {
                    $fromOutput.html('<span class="bp-regex-output--no-match"><?php _e('Error', 'starter-dashboard'); ?></span>');
                }
            }

            // Test live redirect
            $(document).on('click', '.bp-regex-test-live', function() {
                var $btn = $(this);
                var testUrl = $btn.data('url');
                var $container = $btn.closest('.bp-input-hint');

                $btn.prop('disabled', true).text('<?php _e('Testing...', 'starter-dashboard'); ?>');
                $container.find('.bp-regex-live-result').remove();

                $.post(ajaxurl, {
                    action: 'starter_redirects_test_url',
                    nonce: starterDashboard.nonce,
                    url: testUrl
                }, function(response) {
                    $btn.prop('disabled', false).html('<?php _e('Test Live', 'starter-dashboard'); ?> ↗');

                    var resultHtml = '';
                    if (response.success) {
                        var data = response.data;
                        if (data.redirected) {
                            resultHtml = '<div class="bp-regex-live-result bp-regex-live-result--success">';
                            resultHtml += '✓ <strong>' + data.status_code + '</strong> → <code>' + escapeHtml(data.redirect_to) + '</code>';
                            resultHtml += '</div>';
                        } else {
                            resultHtml = '<div class="bp-regex-live-result bp-regex-live-result--error">';
                            resultHtml += '✗ <?php _e('No redirect', 'starter-dashboard'); ?> (HTTP ' + data.status_code + ')';
                            resultHtml += '</div>';
                        }
                    } else {
                        resultHtml = '<div class="bp-regex-live-result bp-regex-live-result--error">';
                        resultHtml += '✗ <?php _e('Error:', 'starter-dashboard'); ?> ' + response.data;
                        resultHtml += '</div>';
                    }
                    $container.append(resultHtml);
                });
            });

            // Test regex in real-time and save URL
            $(document).on('input', '#regex-test-url', function() {
                window.regexTestUrl = $(this).val();
                updateRegexTest();
            });

            // Also update test when To field changes
            $('#redirect-to').on('input', function() {
                if ($('#redirect-to-type').val() === 'regex') {
                    updateRegexTest();
                }
            });

            // Close hint button
            $(document).on('click', '.bp-input-hint__close', function() {
                $(this).closest('.bp-input-hint').remove();
            });

            // Trigger validation on input and mode change
            $('#redirect-from').on('input', function() {
                validateRegexField($(this), false);
            });
            $('#redirect-to').on('input', function() {
                validateRegexField($(this), true);
            });
            $('#redirect-match-type').on('change', function() {
                validateRegexField($('#redirect-from'), false);
            });
            $('#redirect-to-type').on('change', function() {
                validateRegexField($('#redirect-to'), true);
            });

            // Add/Update redirect
            $('.bp-redirects-add-btn').on('click', function() {
                var from = $('#redirect-from').val().trim();
                var toType = $('#redirect-to-type').val();
                var to = $('#redirect-to').val().trim();
                var toPostId = $('#redirect-to-post-id').val();
                var toPostTitle = $('#redirect-to-post-title').val();
                var statusCode = $('#redirect-type').val();
                var matchType = $('#redirect-match-type').val();
                var $btn = $(this);
                var editId = $btn.data('edit-id');

                if (!from || !to) {
                    alert('<?php _e('Please enter both From and To URLs', 'starter-dashboard'); ?>');
                    return;
                }

                // Block save if there are validation errors
                if ($('#redirect-from').hasClass('bp-input--error') || $('#redirect-to').hasClass('bp-input--error')) {
                    alert('<?php _e('Please fix the validation errors before saving.', 'starter-dashboard'); ?>');
                    return;
                }

                // Validate regex patterns
                if (matchType === 'regex') {
                    var fromValidation = isValidRegex(from);
                    if (!fromValidation.valid) {
                        alert('<?php _e('Invalid regex pattern in "From" field:', 'starter-dashboard'); ?>\n' + fromValidation.error);
                        $('#redirect-from').focus();
                        return;
                    }
                    // Block if pattern doesn't look like regex (no regex chars = not a regex)
                    if (!hasRegexChars(from)) {
                        alert('<?php _e('The "From" pattern must contain regex characters (like ^, $, .*, +, (), [], etc.).\n\nFor simple URL matching, use "Exact" or "Wildcard" mode instead.', 'starter-dashboard'); ?>');
                        $('#redirect-from').focus();
                        return;
                    }
                }

                if (toType === 'regex') {
                    // Check for valid replacement pattern (must contain $1, $2 etc.)
                    if (!/\$\d+/.test(to)) {
                        alert('<?php _e('Regex destination must contain capture group references like $1, $2, etc.\n\nExample: /new-path/$1/', 'starter-dashboard'); ?>');
                        $('#redirect-to').focus();
                        return;
                    }
                    var toValidation = isValidRegex(to.replace(/\$\d+/g, 'x'));
                    if (!toValidation.valid) {
                        alert('<?php _e('Invalid regex pattern in "To" field:', 'starter-dashboard'); ?>\n' + toValidation.error);
                        $('#redirect-to').focus();
                        return;
                    }
                }

                $btn.prop('disabled', true);

                // Handle "off" status
                var enabled = statusCode !== 'off';

                var postData = {
                    action: 'starter_redirects_save',
                    nonce: starterDashboard.nonce,
                    from: from,
                    to: to,
                    enabled: enabled,
                    status_code: statusCode === 'off' ? '301' : statusCode,
                    match_type: matchType,
                    to_type: toType
                };

                // Include post info if WordPress selection
                if (toType === 'wordpress' && toPostId) {
                    postData.to_post_id = toPostId;
                    postData.to_title = toPostTitle;
                }

                // Include ID if editing
                if (editId) {
                    postData.id = editId;
                }

                $.post(ajaxurl, postData, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        // Clear form and reset button
                        $('#redirect-from, #redirect-to').val('');
                        $('#redirect-to-post-id, #redirect-to-post-title').val('');
                        $('#redirect-to-type').val('custom').trigger('change');
                        $('#redirect-type').val('301');
                        $('#redirect-match-type').val('exact');
                        $('#redirect-to-selected').hide();
                        $('#redirect-to-select-wp').show();
                        $btn.removeData('edit-id').text('<?php _e('Save', 'starter-dashboard'); ?>');
                        loadRedirects();
                        updateStats();
                    } else {
                        alert(response.data);
                    }
                });
            });

            // Toggle redirect
            $(document).on('change', '.redirect-toggle', function() {
                var $item = $(this).closest('.bp-redirects-row');
                var id = $item.data('id');
                var r = redirects.find(function(r) { return r.id === id; });

                if (r) {
                    $.post(ajaxurl, {
                        action: 'starter_redirects_save',
                        nonce: starterDashboard.nonce,
                        id: id,
                        from: r.from,
                        to: r.to,
                        note: r.note,
                        enabled: this.checked
                    }, function(response) {
                        if (response.success) {
                            loadRedirects();
                            updateStats();
                        }
                    });
                }
            });

            // Edit redirect
            $(document).on('click', '.bp-redirects-row__actions .edit', function() {
                var $row = $(this).closest('.bp-redirects-row');
                var id = $row.data('id');
                var r = redirects.find(function(r) { return r.id === id; });

                if (r) {
                    $('#redirect-from').val(r.from);
                    $('#redirect-to').val(r.to);

                    // Set status code dropdown (or "off" if disabled)
                    if (!r.enabled) {
                        $('#redirect-type').val('off');
                    } else {
                        $('#redirect-type').val(r.status_code || '301');
                    }

                    // Set match type dropdown
                    $('#redirect-match-type').val(r.match_type || 'exact');

                    // Set to type (custom, regex, wordpress) based on saved value or post_id
                    var toType = r.to_type || (r.to_post_id ? 'wordpress' : 'custom');
                    $('#redirect-to-type').val(toType).trigger('change');

                    if (toType === 'wordpress' && r.to_post_id) {
                        $('#redirect-to-post-id').val(r.to_post_id);
                        $('#redirect-to-post-title').val(r.to_title || '');
                        $('#redirect-to-select-wp').hide();
                        $('#redirect-to-selected').show().find('.bp-redirects-to-selected__title').text(r.to_title || r.to);
                    } else {
                        $('#redirect-to-post-id').val('');
                        $('#redirect-to-post-title').val('');
                        $('#redirect-to-selected').hide();
                        $('#redirect-to-select-wp').show();
                    }

                    // Update add button to update mode
                    var $addBtn = $('.bp-redirects-add-btn');
                    $addBtn.data('edit-id', id).text('<?php _e('Update', 'starter-dashboard'); ?>');

                    // Scroll to form
                    $('html, body').animate({
                        scrollTop: $('.bp-redirects-add-row').offset().top - 50
                    }, 300);
                }
            });

            // Delete redirect
            $(document).on('click', '.bp-redirects-row__actions .delete', function() {
                if (!confirm('<?php _e('Delete this redirect?', 'starter-dashboard'); ?>')) {
                    return;
                }

                var $row = $(this).closest('.bp-redirects-row');
                var id = $row.data('id');

                $.post(ajaxurl, {
                    action: 'starter_redirects_delete',
                    nonce: starterDashboard.nonce,
                    id: id
                }, function(response) {
                    if (response.success) {
                        loadRedirects();
                        updateStats();
                    }
                });
            });

            // Search
            var searchTimeout;
            $('#redirects-search').on('input', function() {
                clearTimeout(searchTimeout);
                var val = $(this).val();
                searchTimeout = setTimeout(function() {
                    renderRedirects(val);
                }, 200);
            });

            // Import modal
            $('.bp-redirects-import-btn').on('click', function() {
                $('#bp-redirects-import-modal').show();
            });

            $('.bp-redirects-modal__close, .bp-redirects-modal__cancel').on('click', function() {
                $('#bp-redirects-import-modal').hide();
            });

            $('.bp-redirects-import-confirm').on('click', function() {
                var csv = $('#redirects-import-data').val();
                var mode = $('input[name="import-mode"]:checked').val();

                if (!csv.trim()) {
                    alert('<?php _e('Please paste some data to import', 'starter-dashboard'); ?>');
                    return;
                }

                var $btn = $(this).prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'starter_redirects_import',
                    nonce: starterDashboard.nonce,
                    csv: csv,
                    mode: mode
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        alert(response.data.message);
                        $('#bp-redirects-import-modal').hide();
                        $('#redirects-import-data').val('');
                        loadRedirects();
                        updateStats();
                    } else {
                        alert(response.data);
                    }
                });
            });

            // Export
            $('.bp-redirects-export-btn').on('click', function() {
                var csv = 'from,to,note\n';
                redirects.forEach(function(r) {
                    csv += '"' + r.from + '","' + r.to + '","' + (r.note || '') + '"\n';
                });

                var blob = new Blob([csv], { type: 'text/csv' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'redirects-' + new Date().toISOString().split('T')[0] + '.csv';
                a.click();
            });

            // Test All Redirects
            var testingInProgress = false;
            $('.bp-redirects-test-all-btn').on('click', function() {
                if (testingInProgress) return;

                var $btn = $(this);
                $btn.addClass('testing').html('<span class="spinner is-active" style="margin: 0 4px 0 0; float: none;"></span> <?php _e('Testing...', 'starter-dashboard'); ?>');
                testingInProgress = true;
                redirectTestResults = {};

                // Filter enabled redirects (exact match only for testing)
                var toTest = redirects.filter(function(r) {
                    return r.enabled && r.match_type !== 'regex' && r.match_type !== 'wildcard';
                });

                // Mark disabled and regex/wildcard as skipped
                redirects.forEach(function(r) {
                    if (!r.enabled) {
                        redirectTestResults[r.id] = { status: 'disabled' };
                    } else if (r.match_type === 'regex' || r.match_type === 'wildcard') {
                        redirectTestResults[r.id] = { status: 'disabled', message: '<?php esc_attr_e('Cannot test regex/wildcard patterns', 'starter-dashboard'); ?>' };
                    }
                });
                renderRedirects($('#redirects-search').val());

                if (toTest.length === 0) {
                    $btn.removeClass('testing').html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> <?php _e('Test All', 'starter-dashboard'); ?>');
                    testingInProgress = false;
                    return;
                }

                var index = 0;
                function testNext() {
                    if (index >= toTest.length) {
                        $btn.removeClass('testing').html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> <?php _e('Test All', 'starter-dashboard'); ?>');
                        testingInProgress = false;
                        return;
                    }

                    var r = toTest[index];
                    redirectTestResults[r.id] = { status: 'testing' };
                    renderRedirects($('#redirects-search').val());

                    $.post(ajaxurl, {
                        action: 'starter_redirects_test_url',
                        nonce: starterDashboard.nonce,
                        url: r.from
                    }, function(response) {
                        var testResult = { status: 'error', message: '' };

                        if (response.success) {
                            var data = response.data;
                            var expectedCode = parseInt(r.status_code) || 301;

                            if (data.redirected && data.status_code === expectedCode) {
                                // Check if redirect target matches
                                var expectedTo = r.to;
                                var actualTo = data.redirect_to;

                                // Normalize URLs for comparison
                                if (expectedTo.indexOf('http') !== 0) {
                                    expectedTo = siteUrl.replace(/\/$/, '') + (expectedTo.indexOf('/') === 0 ? expectedTo : '/' + expectedTo);
                                }

                                // Simple comparison (remove trailing slashes)
                                var normExpected = expectedTo.replace(/\/$/, '').toLowerCase();
                                var normActual = actualTo.replace(/\/$/, '').toLowerCase();

                                if (normExpected === normActual) {
                                    testResult = { status: 'ok', message: '' };
                                } else {
                                    testResult = {
                                        status: 'mismatch',
                                        message: '<?php esc_attr_e('Redirects to: ', 'starter-dashboard'); ?>' + actualTo
                                    };
                                }
                            } else if (data.redirected) {
                                testResult = {
                                    status: 'mismatch',
                                    message: '<?php esc_attr_e('Status: ', 'starter-dashboard'); ?>' + data.status_code + ' -> ' + data.redirect_to
                                };
                            } else {
                                testResult = {
                                    status: 'error',
                                    message: '<?php esc_attr_e('No redirect (HTTP ', 'starter-dashboard'); ?>' + data.status_code + ')'
                                };
                            }
                        } else {
                            testResult = {
                                status: 'error',
                                message: response.data || '<?php esc_attr_e('Request failed', 'starter-dashboard'); ?>'
                            };
                        }

                        redirectTestResults[r.id] = testResult;
                        saveTestResult(r.id, testResult.status, testResult.message);

                        renderRedirects($('#redirects-search').val());
                        index++;
                        // Small delay between requests to avoid overwhelming the server
                        setTimeout(testNext, 200);
                    }).fail(function() {
                        var failResult = {
                            status: 'error',
                            message: '<?php esc_attr_e('Request failed', 'starter-dashboard'); ?>'
                        };
                        redirectTestResults[r.id] = failResult;
                        saveTestResult(r.id, failResult.status, failResult.message);
                        renderRedirects($('#redirects-search').val());
                        index++;
                        setTimeout(testNext, 200);
                    });
                }

                testNext();
            });

            // Single Test Modal for regex/wildcard
            var currentTestRedirectId = null;
            var $testModal = $('#bp-redirects-test-modal');

            $(document).on('click', '.bp-redirects-test-single', function() {
                var id = $(this).data('id');
                var redirect = redirects.find(function(r) { return r.id === id; });
                if (!redirect) return;

                currentTestRedirectId = id;
                $('#test-modal-pattern').text(redirect.from);
                $('#test-modal-url').val('');
                $('#test-modal-result').hide().empty();
                $testModal.addClass('active');
                $('#test-modal-url').focus();
            });

            // Close modal
            $testModal.on('click', '.bp-redirects-test-modal__close, .bp-redirects-test-modal__cancel', function() {
                $testModal.removeClass('active');
                currentTestRedirectId = null;
            });

            // Close on backdrop click
            $testModal.on('click', function(e) {
                if (e.target === this) {
                    $testModal.removeClass('active');
                    currentTestRedirectId = null;
                }
            });

            // Submit test
            $('#test-modal-submit').on('click', function() {
                var testUrl = $('#test-modal-url').val().trim();
                if (!testUrl) {
                    $('#test-modal-result')
                        .removeClass('bp-redirects-test-modal__result--success bp-redirects-test-modal__result--mismatch')
                        .addClass('bp-redirects-test-modal__result--error')
                        .html('<?php _e('Please enter a test URL', 'starter-dashboard'); ?>')
                        .show();
                    return;
                }

                var redirect = redirects.find(function(r) { return r.id === currentTestRedirectId; });
                if (!redirect) return;

                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="spinner is-active" style="margin: 0; float: none;"></span>');

                // Mark as testing
                redirectTestResults[currentTestRedirectId] = { status: 'testing' };
                renderRedirects($('#redirects-search').val());

                // Add leading slash if missing
                if (testUrl.indexOf('/') !== 0) {
                    testUrl = '/' + testUrl;
                }

                $.post(ajaxurl, {
                    action: 'starter_redirects_test_url',
                    nonce: starterDashboard.nonce,
                    url: testUrl
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php _e('Test', 'starter-dashboard'); ?>');

                    if (response.success) {
                        var data = response.data;
                        var expectedCode = parseInt(redirect.status_code) || 301;
                        var $result = $('#test-modal-result');

                        if (data.redirected && data.status_code === expectedCode) {
                            // Build expected destination URL by applying regex/wildcard substitution
                            var expectedTo = redirect.to;
                            var matchType = redirect.match_type || 'exact';
                            var matched = false;

                            // For regex, apply capture groups - try multiple variations
                            if (matchType === 'regex') {
                                try {
                                    var pattern = redirect.from;
                                    var testVariations = [
                                        testUrl,
                                        testUrl + '/',
                                        testUrl.replace(/\/$/, '')
                                    ];

                                    var regex = new RegExp(pattern);
                                    for (var v = 0; v < testVariations.length && !matched; v++) {
                                        var match = testVariations[v].match(regex);
                                        if (match) {
                                            matched = true;
                                            expectedTo = redirect.to;
                                            for (var i = 1; i < match.length; i++) {
                                                expectedTo = expectedTo.replace(new RegExp('\\$' + i, 'g'), match[i] || '');
                                            }
                                        }
                                    }
                                } catch(e) { console.log('Regex error:', e); }
                            }
                            // For wildcard, replace * captures
                            else if (matchType === 'wildcard') {
                                var wildcardPattern = redirect.from
                                    .replace(/[.+^${}()|[\]\\]/g, '\\$&')
                                    .replace(/\*/g, '(.*)');
                                try {
                                    var testVariations = [
                                        testUrl,
                                        testUrl + '/',
                                        testUrl.replace(/\/$/, '')
                                    ];

                                    for (var v = 0; v < testVariations.length && !matched; v++) {
                                        var regex = new RegExp('^' + wildcardPattern + '$');
                                        var match = testVariations[v].match(regex);
                                        if (match) {
                                            matched = true;
                                            expectedTo = redirect.to;
                                            for (var i = 1; i < match.length; i++) {
                                                expectedTo = expectedTo.replace(new RegExp('\\$' + i, 'g'), match[i] || '');
                                            }
                                        }
                                    }
                                } catch(e) { console.log('Wildcard error:', e); }
                            } else {
                                matched = true; // exact match doesn't need substitution
                            }

                            // Normalize URLs for comparison
                            if (expectedTo.indexOf('http') !== 0) {
                                expectedTo = siteUrl.replace(/\/$/, '') + (expectedTo.indexOf('/') === 0 ? expectedTo : '/' + expectedTo);
                            }

                            var normExpected = expectedTo.replace(/\/$/, '').toLowerCase();
                            var normActual = data.redirect_to.replace(/\/$/, '').toLowerCase();

                            // If regex/wildcard matched and we calculated expected, compare
                            // If not matched, just show the result as "working" since server handled it
                            if (!matched || normExpected === normActual) {
                                redirectTestResults[currentTestRedirectId] = { status: 'ok' };
                                saveTestResult(currentTestRedirectId, 'ok', '');
                                $result
                                    .removeClass('bp-redirects-test-modal__result--error bp-redirects-test-modal__result--mismatch')
                                    .addClass('bp-redirects-test-modal__result--success')
                                    .html('<strong>✓ <?php _e('Working!', 'starter-dashboard'); ?></strong><div style="margin-top: 8px;"><?php _e('Redirects to:', 'starter-dashboard'); ?><br><code style="word-break: break-all;">' + escapeHtml(data.redirect_to) + '</code></div>')
                                    .show();
                            } else {
                                var mismatchMsg = '<?php esc_attr_e('Got: ', 'starter-dashboard'); ?>' + data.redirect_to;
                                redirectTestResults[currentTestRedirectId] = {
                                    status: 'mismatch',
                                    message: mismatchMsg
                                };
                                saveTestResult(currentTestRedirectId, 'mismatch', mismatchMsg);
                                $result
                                    .removeClass('bp-redirects-test-modal__result--error bp-redirects-test-modal__result--success')
                                    .addClass('bp-redirects-test-modal__result--mismatch')
                                    .html('<strong>≠ <?php _e('Destination mismatch', 'starter-dashboard'); ?></strong>' +
                                        '<div style="margin-top: 8px;"><strong><?php _e('Expected:', 'starter-dashboard'); ?></strong><br><code style="word-break: break-all;">' + escapeHtml(expectedTo) + '</code></div>' +
                                        '<div style="margin-top: 8px;"><strong><?php _e('Got:', 'starter-dashboard'); ?></strong><br><code style="word-break: break-all;">' + escapeHtml(data.redirect_to) + '</code></div>')
                                    .show();
                            }
                        } else if (data.redirected) {
                            var wrongCodeMsg = '<?php esc_attr_e('Status: ', 'starter-dashboard'); ?>' + data.status_code;
                            redirectTestResults[currentTestRedirectId] = {
                                status: 'mismatch',
                                message: wrongCodeMsg
                            };
                            saveTestResult(currentTestRedirectId, 'mismatch', wrongCodeMsg);
                            $result
                                .removeClass('bp-redirects-test-modal__result--error bp-redirects-test-modal__result--success')
                                .addClass('bp-redirects-test-modal__result--mismatch')
                                .html('<strong>≠ <?php _e('Wrong status code', 'starter-dashboard'); ?></strong><br><?php _e('Expected:', 'starter-dashboard'); ?> ' + (redirect.status_code || 301) + '<br><?php _e('Got:', 'starter-dashboard'); ?> ' + data.status_code + ' -> ' + escapeHtml(data.redirect_to))
                                .show();
                        } else {
                            var noRedirectMsg = '<?php esc_attr_e('No redirect (HTTP ', 'starter-dashboard'); ?>' + data.status_code + ')';
                            redirectTestResults[currentTestRedirectId] = {
                                status: 'error',
                                message: noRedirectMsg
                            };
                            saveTestResult(currentTestRedirectId, 'error', noRedirectMsg);
                            $result
                                .removeClass('bp-redirects-test-modal__result--success bp-redirects-test-modal__result--mismatch')
                                .addClass('bp-redirects-test-modal__result--error')
                                .html('<strong>✗ <?php _e('No redirect', 'starter-dashboard'); ?></strong><br><?php _e('HTTP Status:', 'starter-dashboard'); ?> ' + data.status_code)
                                .show();
                        }
                    } else {
                        var errorMsg = response.data || '<?php esc_attr_e('Request failed', 'starter-dashboard'); ?>';
                        redirectTestResults[currentTestRedirectId] = {
                            status: 'error',
                            message: errorMsg
                        };
                        saveTestResult(currentTestRedirectId, 'error', errorMsg);
                        $('#test-modal-result')
                            .removeClass('bp-redirects-test-modal__result--success bp-redirects-test-modal__result--mismatch')
                            .addClass('bp-redirects-test-modal__result--error')
                            .html('<strong>✗ <?php _e('Error', 'starter-dashboard'); ?></strong><br>' + escapeHtml(errorMsg))
                            .show();
                    }

                    renderRedirects($('#redirects-search').val());
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php _e('Test', 'starter-dashboard'); ?>');
                    var failMsg = '<?php esc_attr_e('Request failed', 'starter-dashboard'); ?>';
                    redirectTestResults[currentTestRedirectId] = {
                        status: 'error',
                        message: failMsg
                    };
                    saveTestResult(currentTestRedirectId, 'error', failMsg);
                    $('#test-modal-result')
                        .removeClass('bp-redirects-test-modal__result--success bp-redirects-test-modal__result--mismatch')
                        .addClass('bp-redirects-test-modal__result--error')
                        .html('<strong>✗ <?php _e('Request failed', 'starter-dashboard'); ?></strong>')
                        .show();
                    renderRedirects($('#redirects-search').val());
                });
            });

            // Submit on Enter in test URL field
            $('#test-modal-url').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#test-modal-submit').click();
                }
            });

            // Update stats
            function updateStats() {
                $.post(ajaxurl, {
                    action: 'starter_redirects_get_all',
                    nonce: starterDashboard.nonce
                }, function(response) {
                    if (response.success) {
                        var total = response.data.length;
                        var active = response.data.filter(function(r) { return r.enabled; }).length;
                        var hits = response.data.reduce(function(sum, r) { return sum + (r.hits || 0); }, 0);

                        $('.bp-redirects-stat__value').eq(0).text(total);
                        $('.bp-redirects-stat__value').eq(1).text(active);
                        $('.bp-redirects-stat__value').eq(2).text(hits.toLocaleString());
                    }
                });
            }

            // === External Redirects Scanner ===

            // Load cached scan results on page load
            loadExternalRedirectsCache();

            function loadExternalRedirectsCache() {
                $.post(ajaxurl, {
                    action: 'starter_redirects_get_scan_cache',
                    nonce: starterDashboard.nonce
                }, function(response) {
                    if (response.success && response.data.results) {
                        renderExternalRedirects(response.data.results, response.data.cached_at);
                    }
                });
            }

            function renderExternalRedirects(results, cachedAt, sourcesChecked) {
                var $results = $('#scanner-results');
                var $cachedAt = $('#scanner-cached-at');

                // Update cached at timestamp
                if (cachedAt) {
                    $cachedAt.text('<?php _e('Last scan:', 'starter-dashboard'); ?> ' + cachedAt);
                } else {
                    $cachedAt.text('');
                }

                if (!results || results.length === 0) {
                    var html = '<div class="bp-redirects-scanner__empty">';
                    html += '<?php _e('No external redirects found.', 'starter-dashboard'); ?>';

                    // Show which sources were checked
                    if (sourcesChecked) {
                        html += '<div class="bp-redirects-scanner__sources-checked">';
                        html += '<strong><?php _e('Sources checked:', 'starter-dashboard'); ?></strong><br>';
                        Object.keys(sourcesChecked).forEach(function(source) {
                            var info = sourcesChecked[source];
                            var status = info.available ? '✓' : '✗';
                            var statusClass = info.available ? 'available' : 'not-available';
                            html += '<span class="bp-redirects-scanner__source-item bp-redirects-scanner__source-item--' + statusClass + '">';
                            html += status + ' ' + source;
                            if (info.available && info.found > 0) {
                                html += ' (' + info.found + ')';
                            }
                            html += '</span>';
                        });
                        html += '</div>';
                    }

                    html += '</div>';
                    $results.html(html);
                    return;
                }

                // Group results by source
                var grouped = {};
                results.forEach(function(item) {
                    var source = item.source || 'Unknown';
                    if (!grouped[source]) {
                        grouped[source] = [];
                    }
                    grouped[source].push(item);
                });

                var html = '';
                Object.keys(grouped).forEach(function(source) {
                    var items = grouped[source];

                    // Source header
                    html += '<div class="bp-redirects-scanner__group">';
                    html += '<div class="bp-redirects-scanner__group-header">';
                    html += '<span class="bp-redirects-scanner__group-name">' + escapeHtml(source) + '</span>';
                    html += '<span class="bp-redirects-scanner__group-count">' + items.length + ' <?php _e('redirect(s)', 'starter-dashboard'); ?></span>';
                    html += '</div>';

                    // Items in this group
                    items.forEach(function(item) {
                        html += '<div class="bp-redirects-scanner__item">';
                        html += '<span class="bp-redirects-scanner__item-badge bp-redirects-scanner__item-badge--' + (item.status || 301) + '">' + (item.status || 301) + '</span>';
                        html += '<div class="bp-redirects-scanner__item-info">';

                        // From URL
                        html += '<div class="bp-redirects-scanner__item-from">';
                        html += '<span class="bp-redirects-scanner__item-label"><?php _e('From:', 'starter-dashboard'); ?></span> ';
                        html += '<span class="bp-redirects-scanner__item-path">' + escapeHtml(item.from) + '</span>';
                        html += '</div>';

                        // To URL
                        html += '<div class="bp-redirects-scanner__item-to">';
                        html += '<span class="bp-redirects-scanner__item-label"><?php _e('To:', 'starter-dashboard'); ?></span> ';
                        var toUrl = item.to || '';
                        if (toUrl.indexOf('http') === 0) {
                            html += '<a href="' + escapeHtml(toUrl) + '" target="_blank" class="bp-redirects-scanner__item-dest">' + escapeHtml(toUrl) + '</a>';
                        } else {
                            html += '<span class="bp-redirects-scanner__item-dest">' + escapeHtml(toUrl) + '</span>';
                        }
                        html += '</div>';

                        // Hits if available
                        if (item.hits) {
                            html += '<div class="bp-redirects-scanner__item-hits">';
                            html += '<span class="bp-redirects-scanner__item-label"><?php _e('Hits:', 'starter-dashboard'); ?></span> ';
                            html += '<span>' + item.hits.toLocaleString() + '</span>';
                            html += '</div>';
                        }

                        html += '</div>';
                        html += '</div>';
                    });

                    html += '</div>';
                });

                html += '<div class="bp-redirects-scanner__count">' +
                    '<strong>' + results.length + '</strong> <?php _e('external redirect(s) found', 'starter-dashboard'); ?>' +
                    '</div>';

                $results.html(html);
            }

            // Start scan button
            $('#scanner-start-btn').on('click', function() {
                scanExternalSources();
            });

            function scanExternalSources() {
                var $btn = $('#scanner-start-btn');
                $btn.prop('disabled', true).text('<?php _e('Scanning...', 'starter-dashboard'); ?>');
                $('#scanner-results').html('<div class="bp-redirects-scanner__empty"><?php _e('Scanning Yoast, Redirection, Rank Math, .htaccess...', 'starter-dashboard'); ?></div>');

                $.post(ajaxurl, {
                    action: 'starter_redirects_scan_external_sources',
                    nonce: starterDashboard.nonce
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php _e('Scan', 'starter-dashboard'); ?>');

                    if (response.success) {
                        renderExternalRedirects(response.data.results, response.data.cached_at, response.data.sources_checked);
                    } else {
                        $('#scanner-results').html('<div class="bp-redirects-scanner__empty bp-redirects-scanner__empty--error">' +
                            '<?php _e('Error scanning sources', 'starter-dashboard'); ?>' +
                            '</div>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php _e('Scan', 'starter-dashboard'); ?>');
                    $('#scanner-results').html('<div class="bp-redirects-scanner__empty bp-redirects-scanner__empty--error">' +
                        '<?php _e('Error scanning sources', 'starter-dashboard'); ?>' +
                        '</div>');
                });
            }

            // Init
            loadRedirects();
            loadExternalRedirectsCache();
        });
        </script>
        <?php
    }
}

// Initialize
Starter_Addon_Redirects_301::instance();
