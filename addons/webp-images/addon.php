<?php
/**
 * Starter Dashboard Addon: WebP Images
 *
 * Serves PNG/JPEG files from the uploads directory as WebP on the front end (logged-out
 * visitors). Missing WebP copies are queued and generated in the background by WP-Cron
 * (option mwts_webp_queue, hook mwts_webp_generate) — page views only add to the queue.
 * The settings panel shows the state of the last run and can run one batch by hand.
 *
 * Disable in code:
 *   add_filter('mwts_webp_images_enabled', '__return_false');
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-webp-images.php';

class Starter_Addon_Webp_Images {

    const QUEUE_OPTION = 'mwts_webp_queue';
    const SKIP_OPTION = 'mwts_webp_skip';
    const LAST_OPTION = 'mwts_webp_last';
    const HOOK = 'mwts_webp_generate';
    const ACTION = 'mwts_webp_action';
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
        add_action('admin_post_' . self::ACTION, [$this, 'handle_admin_action']);
        add_action('admin_notices', [$this, 'admin_notice']);
        // The panel has only buttons; "Save Settings" in the modal must not report a failure.
        add_filter('starter_addon_save_settings_webp-images', '__return_true');
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
     * Front end: only add to the queue and make sure the generator is scheduled. No image work here.
     * Scheduling is checked on every such page view, also when the queue did not change, so a lost
     * cron event is planned again.
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
        $queue = get_option(self::QUEUE_OPTION, []);
        $updated = Starter_Webp_Images::queue_add($queue, array_values(array_diff($paths, $skip)), $skip);
        if ($updated !== $queue) {
            update_option(self::QUEUE_OPTION, $updated, false);
        }
        $this->ensure_scheduled(count($updated), self::FIRST_DELAY);
    }

    private function ensure_scheduled($queue_length, $delay) {
        if (Starter_Webp_Images::should_schedule($queue_length, wp_next_scheduled(self::HOOK))) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    /**
     * WP-Cron (or the "Generate now" button): convert up to BATCH_SIZE queued files, then
     * reschedule while the queue is not empty. Every run is recorded in mwts_webp_last.
     * Page caches are not purged here.
     *
     * @param string $trigger cron | manual (WP-Cron passes nothing).
     */
    public function generate($trigger = 'cron') {
        $trigger = $trigger === 'manual' ? 'manual' : 'cron';
        $queue = Starter_Webp_Images::queue_add(get_option(self::QUEUE_OPTION, []), []);
        $supports = (bool) wp_image_editor_supports(['mime_type' => 'image/webp']);
        $editor = function_exists('_wp_image_editor_choose') ? _wp_image_editor_choose(['mime_type' => 'image/webp']) : '';
        $record = function (array $files, $message) use ($trigger, $queue, $supports, $editor) {
            $last = Starter_Webp_Images::last_run_record(time(), $trigger, $files, count($queue), $supports, $editor,
                defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, $message);
            update_option(self::LAST_OPTION, $last, false);
            return $last;
        };

        if (!self::enabled()) {
            return $record([], 'Disabled by the mwts_webp_images_enabled filter');
        }
        if (!$queue) {
            return $record([], 'Queue is empty');
        }
        if (!$supports) {
            // Queue kept: the files are fine, the server cannot write WebP (GD/Imagick without WebP).
            return $record([], 'Server cannot write WebP (wp_image_editor_supports returned false)');
        }
        $uploads = $this->uploads();
        if (!$uploads) {
            $dir = wp_get_upload_dir();
            return $record([], 'Uploads directory unavailable: ' . Starter_Webp_Images::error_message($dir['error'] ?? ''));
        }

        $batch = array_slice($queue, 0, Starter_Webp_Images::BATCH_SIZE);
        $skip = (array) get_option(self::SKIP_OPTION, []);
        $editor_factory = function ($path) {
            return wp_get_image_editor($path);
        };
        $files = [];
        foreach ($batch as $relative) {
            $source = Starter_Webp_Images::resolve_source($uploads['basedir'], $relative);
            [$status, $message] = $source
                ? Starter_Webp_Images::convert_file_detailed($source, $editor_factory)
                : ['error', 'Not a PNG/JPEG file inside the uploads directory (missing or outside uploads)'];
            if (in_array($status, ['small', 'larger', 'error'], true)) {
                $skip[] = $relative; // never retried until the skip list is cleared
            }
            if ($status === 'error') {
                error_log('[mwts-webp] ' . $relative . ': ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            }
            $files[] = ['path' => $relative, 'status' => $status, 'message' => $message];
        }
        update_option(self::SKIP_OPTION, array_values(array_unique($skip)), false);

        // Re-read: page views may have added files while this batch ran.
        wp_cache_delete(self::QUEUE_OPTION, 'options');
        $current = Starter_Webp_Images::queue_add(get_option(self::QUEUE_OPTION, []), []);
        $rest = array_values(array_diff($current, $batch));
        update_option(self::QUEUE_OPTION, $rest, false);
        $this->ensure_scheduled(count($rest), self::NEXT_DELAY);

        return $record($files, '');
    }

    /**
     * Buttons in the settings panel: generate now | clear skip list | clear queue.
     */
    public function handle_admin_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied', 'starter-dashboard'), 403);
        }
        check_admin_referer(self::ACTION);
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

        if ($op === 'generate') {
            if (function_exists('set_time_limit')) {
                @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- may be disallowed by the host
            }
            $this->generate('manual');
        } elseif ($op === 'clear_skip') {
            delete_option(self::SKIP_OPTION);
        } elseif ($op === 'clear_queue') {
            delete_option(self::QUEUE_OPTION);
            wp_clear_scheduled_hook(self::HOOK);
        } else {
            $op = '';
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=starter-addons');
        wp_safe_redirect(add_query_arg('mwts_webp_done', $op, remove_query_arg('mwts_webp_done', $back)));
        exit;
    }

    public function admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        $op = isset($_GET['mwts_webp_done']) ? sanitize_key(wp_unslash($_GET['mwts_webp_done'])) : '';
        if ($op === '' || !current_user_can('manage_options')) {
            return;
        }
        $class = 'notice-success';
        if ($op === 'generate') {
            $last = (array) get_option(self::LAST_OPTION, []);
            $parts = [];
            foreach ((array) ($last['counts'] ?? []) as $status => $count) {
                $parts[] = $status . ': ' . (int) $count;
            }
            $text = sprintf(
                /* translators: 1: number of files, 2: results per status or the reason nothing ran */
                __('WebP Images: processed %1$d file(s). %2$s Details: Addons → WebP Images → Settings.', 'starter-dashboard'),
                (int) ($last['processed'] ?? 0),
                $parts ? implode(', ', $parts) . '.' : (string) ($last['message'] ?? '')
            );
            if (!empty($last['counts']['error']) || (!$parts && !empty($last['message']))) {
                $class = 'notice-warning';
            }
        } elseif ($op === 'clear_skip') {
            $text = __('WebP Images: the skip list was cleared. Those files will be queued again on the next page views.', 'starter-dashboard');
        } elseif ($op === 'clear_queue') {
            $text = __('WebP Images: the queue was cleared and the scheduled run removed.', 'starter-dashboard');
        } else {
            return;
        }
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($text));
    }

    /**
     * Settings panel (dashboard modal): status table and the three buttons.
     */
    public static function render_settings() {
        $queue = Starter_Webp_Images::queue_add(get_option(self::QUEUE_OPTION, []), []);
        $skip = get_option(self::SKIP_OPTION, []);
        $last = get_option(self::LAST_OPTION, []);
        $last = is_array($last) ? $last + [
            'time' => 0, 'trigger' => 'cron', 'processed' => 0, 'queue_before' => 0, 'supports_webp' => false,
            'editor' => '', 'disable_wp_cron' => false, 'message' => '', 'files' => [],
        ] : [];
        $next = wp_next_scheduled(self::HOOK);
        $now = time();
        $uploads = wp_get_upload_dir();
        $supports = (bool) wp_image_editor_supports(['mime_type' => 'image/webp']);
        $editor = function_exists('_wp_image_editor_choose') ? _wp_image_editor_choose(['mime_type' => 'image/webp']) : '';
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $when = function ($timestamp) use ($now) {
            $date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $timestamp);
            $diff = human_time_diff((int) $timestamp, $now);
            /* translators: 1: date, 2: "5 mins" */
            return sprintf((int) $timestamp >= $now ? __('%1$s (in %2$s)', 'starter-dashboard') : __('%1$s (%2$s ago)', 'starter-dashboard'), $date, $diff);
        };
        $yes_no = function ($value) {
            return $value ? __('yes', 'starter-dashboard') : __('no', 'starter-dashboard');
        };

        switch (Starter_Webp_Images::cron_state($next, $now)) {
            case 'none':
                $cron_text = $queue
                    ? __('not scheduled — the next page view with a missing WebP plans it, or use "Generate now"', 'starter-dashboard')
                    : __('not scheduled (queue is empty)', 'starter-dashboard');
                break;
            case 'overdue':
                $cron_text = $when($next) . ' — ' . __('overdue: WP-Cron is not running (DISABLE_WP_CRON without a server cron, or loopback requests blocked)', 'starter-dashboard');
                break;
            default:
                $cron_text = $when($next);
        }
        $rows = [
            __('Queue', 'starter-dashboard')                 => sprintf(_n('%d file', '%d files', count($queue), 'starter-dashboard'), count($queue)),
            __('Skipped (never retried)', 'starter-dashboard') => sprintf(_n('%d file', '%d files', is_array($skip) ? count($skip) : 0, 'starter-dashboard'), is_array($skip) ? count($skip) : 0),
            __('Next run', 'starter-dashboard')              => $cron_text,
            'DISABLE_WP_CRON'                                => $yes_no($cron_disabled),
            __('Server can write WebP', 'starter-dashboard') => $yes_no($supports) . ($editor ? ' (' . $editor . ')' : ''),
            __('Uploads URL', 'starter-dashboard')           => empty($uploads['error']) ? $uploads['baseurl'] : Starter_Webp_Images::error_message($uploads['error']),
        ];
        $form = function ($op, $label, $primary = false) {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0 6px 6px 0;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>">
                <?php wp_nonce_field(self::ACTION, '_wpnonce', false); ?>
                <button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html($label); ?></button>
            </form>
            <?php
        };
        ?>
        <div class="bp-addon-settings" data-addon="webp-images">
            <div class="bp-addon-settings__section">
                <h4><?php _e('Status', 'starter-dashboard'); ?></h4>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <tbody>
                    <?php foreach ($rows as $label => $value) : ?>
                        <tr><th style="width:40%;"><?php echo esc_html($label); ?></th><td><?php echo esc_html($value); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                $form('generate', __('Generate now', 'starter-dashboard'), true);
                $form('clear_skip', __('Clear skip list', 'starter-dashboard'));
                $form('clear_queue', __('Clear queue', 'starter-dashboard'));
                ?>
                <p class="description"><?php _e('"Generate now" converts one batch (up to 10 files) right away and comes back here.', 'starter-dashboard'); ?></p>
            </div>

            <div class="bp-addon-settings__section">
                <h4><?php _e('Last run', 'starter-dashboard'); ?></h4>
                <?php if (empty($last['time'])) : ?>
                    <p><?php _e('No run recorded yet.', 'starter-dashboard'); ?></p>
                <?php else : ?>
                    <p>
                        <?php echo esc_html($when($last['time']) . ' · ' . ($last['trigger'] === 'manual' ? __('manual', 'starter-dashboard') : 'WP-Cron')); ?>
                        · <?php echo esc_html(sprintf(__('%1$d processed, queue before: %2$d', 'starter-dashboard'), (int) $last['processed'], (int) $last['queue_before'])); ?>
                        · <?php echo esc_html(__('WebP support', 'starter-dashboard') . ': ' . $yes_no($last['supports_webp']) . ($last['editor'] ? ' (' . $last['editor'] . ')' : '')); ?>
                        · DISABLE_WP_CRON: <?php echo esc_html($yes_no($last['disable_wp_cron'])); ?>
                    </p>
                    <?php if (!empty($last['message'])) : ?>
                        <p><strong><?php echo esc_html($last['message']); ?></strong></p>
                    <?php endif; ?>
                    <?php if (!empty($last['files'])) : ?>
                        <table class="widefat striped">
                            <thead><tr><th><?php _e('File', 'starter-dashboard'); ?></th><th><?php _e('Result', 'starter-dashboard'); ?></th><th><?php _e('Message', 'starter-dashboard'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($last['files'] as $file) : ?>
                                <tr>
                                    <td style="word-break:break-all;"><?php echo esc_html($file['path']); ?></td>
                                    <td><?php echo $file['status'] === 'error' ? '<strong style="color:#d63638;">' . esc_html($file['status']) . '</strong>' : esc_html($file['status']); ?></td>
                                    <td style="word-break:break-word;"><?php echo esc_html($file['message']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="bp-addon-settings__section">
                <h4><?php _e('Last 10 skipped', 'starter-dashboard'); ?></h4>
                <?php $recent = Starter_Webp_Images::recent_skips($skip); ?>
                <?php if (!$recent) : ?>
                    <p><?php _e('None.', 'starter-dashboard'); ?></p>
                <?php else : ?>
                    <ul style="margin:0;word-break:break-all;">
                        <?php foreach ($recent as $path) : ?>
                            <li><code><?php echo esc_html($path); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

Starter_Addon_Webp_Images::instance();
