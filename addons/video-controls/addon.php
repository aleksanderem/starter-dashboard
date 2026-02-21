<?php
/**
 * Starter Dashboard Addon: Video Controls
 *
 * Shortcode for controlling Elementor background videos with mute/unmute and play/pause buttons.
 * Usage: [video_controls target="#section-id" position="bottom-right" debug="false"]
 *
 * Requires: Elementor
 */

defined('ABSPATH') || exit;

class Starter_Addon_Video_Controls {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_shortcode('video_controls', [$this, 'shortcode']);
    }

    public function register_assets() {
        $addon_url = plugin_dir_url(__FILE__);
        $prefix = starter_hub_prefix();
        $handle = $prefix . '-video-controls';

        wp_register_style(
            $handle,
            $addon_url . 'style.css',
            [],
            '1.0.3'
        );

        wp_register_script(
            $handle,
            $addon_url . 'script.js',
            ['jquery'],
            '1.0.3',
            true
        );
    }

    /**
     * Video controls shortcode
     *
     * Parameters:
     * - target: CSS selector of the Elementor container with background video (default: auto-detect)
     * - position: inline, top-left, top-right, bottom-left, bottom-right, custom (default: inline)
     * - mute_text / unmute_text / play_text / pause_text: Button labels
     * - show_icons: true/false (default: true)
     * - debug: true/false (default: false)
     */
    public function shortcode($atts) {
        $atts = shortcode_atts([
            'target' => '',
            'position' => 'inline',
            'mute_text' => 'Mute',
            'unmute_text' => 'Unmute',
            'play_text' => 'Play',
            'pause_text' => 'Pause',
            'show_icons' => 'true',
            'debug' => 'false',
        ], $atts, 'video_controls');

        $prefix = starter_hub_prefix();
        wp_enqueue_style($prefix . '-video-controls');
        wp_enqueue_script($prefix . '-video-controls');

        $unique_id = 'video-controls-' . uniqid();
        $show_icons = filter_var($atts['show_icons'], FILTER_VALIDATE_BOOLEAN);
        $debug = filter_var($atts['debug'], FILTER_VALIDATE_BOOLEAN);

        $position_class = 'evc-position-' . sanitize_html_class($atts['position']);
        $classes = ['elementor-video-controls', $position_class];
        if ($atts['position'] !== 'inline') {
            $classes[] = 'evc-absolute';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="<?php echo esc_attr($unique_id); ?>"
             data-target="<?php echo esc_attr($atts['target']); ?>"
             data-mute-text="<?php echo esc_attr($atts['mute_text']); ?>"
             data-unmute-text="<?php echo esc_attr($atts['unmute_text']); ?>"
             data-play-text="<?php echo esc_attr($atts['play_text']); ?>"
             data-pause-text="<?php echo esc_attr($atts['pause_text']); ?>"
             data-show-icons="<?php echo $show_icons ? 'true' : 'false'; ?>"
             data-debug="<?php echo $debug ? 'true' : 'false'; ?>"
             style="<?php echo $debug ? 'background: rgba(255,0,0,0.2); border: 2px solid red;' : ''; ?>">

            <button class="evc-btn evc-mute-btn" aria-label="<?php echo esc_attr($atts['mute_text']); ?>" style="display: inline-flex !important;">
                <?php if ($show_icons): ?>
                    <svg class="evc-icon evc-icon-unmuted" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 5L6 9H2V15H6L11 19V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M15.54 8.46C16.4774 9.39764 17.0039 10.6692 17.0039 11.995C17.0039 13.3208 16.4774 14.5924 15.54 15.53" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M19.07 4.93C20.9447 6.80528 21.9979 9.34836 21.9979 12C21.9979 14.6516 20.9447 17.1947 19.07 19.07" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <svg class="evc-icon evc-icon-muted" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:none;">
                        <path d="M11 5L6 9H2V15H6L11 19V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <line x1="23" y1="9" x2="17" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="17" y1="9" x2="23" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                <?php else: ?>
                    <span class="evc-text-unmuted"><?php echo esc_html($atts['mute_text']); ?></span>
                    <span class="evc-text-muted" style="display:none;"><?php echo esc_html($atts['unmute_text']); ?></span>
                <?php endif; ?>
            </button>

            <button class="evc-btn evc-play-btn" aria-label="<?php echo esc_attr($atts['pause_text']); ?>" style="display: inline-flex !important;">
                <?php if ($show_icons): ?>
                    <svg class="evc-icon evc-icon-playing" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="6" y="4" width="4" height="16" fill="currentColor"/>
                        <rect x="14" y="4" width="4" height="16" fill="currentColor"/>
                    </svg>
                    <svg class="evc-icon evc-icon-paused" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:none;">
                        <polygon points="5 3 19 12 5 21 5 3" fill="currentColor"/>
                    </svg>
                <?php else: ?>
                    <span class="evc-text-playing"><?php echo esc_html($atts['pause_text']); ?></span>
                    <span class="evc-text-paused" style="display:none;"><?php echo esc_html($atts['play_text']); ?></span>
                <?php endif; ?>
            </button>

            <div class="evc-progress">
                <div class="evc-progress-bar"></div>
            </div>

            <?php if ($debug): ?>
                <div class="evc-debug" style="background: #000; color: #0f0; padding: 5px; font-size: 10px; font-family: monospace; margin-top: 5px;">
                    DEBUG MODE<br>
                    ID: <?php echo esc_html($unique_id); ?><br>
                    Target: <?php echo esc_html($atts['target'] ?: 'auto-detect'); ?><br>
                    Position: <?php echo esc_html($atts['position']); ?><br>
                    Icons: <?php echo $show_icons ? 'yes' : 'no'; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

Starter_Addon_Video_Controls::instance();
