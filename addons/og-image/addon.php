<?php
/**
 * Starter Dashboard Addon: OG Image & Social Preview
 *
 * Adds Open Graph, Twitter Card, and social preview meta tags management
 */

defined('ABSPATH') || exit;

class Starter_Addon_OG_Image {

    private static $instance = null;
    private $option_name = 'starter_og_settings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_head', [$this, 'output_meta_tags'], 1);
        add_action('add_meta_boxes', [$this, 'add_post_meta_box']);
        add_action('save_post', [$this, 'save_post_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Settings save handler
        add_filter('starter_addon_save_settings_og-image', [$this, 'save_settings'], 10, 2);
    }

    /**
     * Render settings panel (called via AJAX in dashboard)
     */
    public static function render_settings() {
        $instance = self::instance();
        $options = get_option($instance->option_name, []);
        ?>
        <div class="bp-addon-settings bp-addon-settings--two-column" data-addon="og-image">
            <div class="bp-addon-settings__main">
                <div class="bp-addon-settings__section">
                    <h4><?php _e('Default Settings', 'starter-dashboard'); ?></h4>
                    <p class="description"><?php _e('Configure default Open Graph settings for your site.', 'starter-dashboard'); ?></p>

                    <div class="bp-addon-settings__field">
                        <label for="og-site-name"><?php _e('Site Name', 'starter-dashboard'); ?></label>
                        <input type="text"
                               id="og-site-name"
                               name="site_name"
                               value="<?php echo esc_attr($options['site_name'] ?? ''); ?>"
                               placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"
                               class="regular-text">
                    </div>

                    <div class="bp-addon-settings__field">
                        <label for="og-default-description"><?php _e('Default Description', 'starter-dashboard'); ?></label>
                        <textarea id="og-default-description"
                                  name="default_description"
                                  rows="3"
                                  placeholder="<?php echo esc_attr(get_bloginfo('description')); ?>"
                                  class="large-text"><?php echo esc_textarea($options['default_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="bp-addon-settings__field">
                        <label for="og-default-image"><?php _e('Default OG Image', 'starter-dashboard'); ?></label>
                        <div class="bp-image-upload-field">
                            <input type="url"
                                   id="og-default-image"
                                   name="default_og_image"
                                   value="<?php echo esc_url($options['default_og_image'] ?? ''); ?>"
                                   class="regular-text">
                            <button type="button" class="button bp-select-image"><?php _e('Select Image', 'starter-dashboard'); ?></button>
                        </div>
                        <p class="description"><?php _e('Recommended size: 1200x630 pixels', 'starter-dashboard'); ?></p>
                    </div>
                </div>

                <div class="bp-addon-settings__section">
                    <h4><?php _e('Twitter Card Settings', 'starter-dashboard'); ?></h4>

                    <div class="bp-addon-settings__field">
                        <label for="og-twitter-card-type"><?php _e('Card Type', 'starter-dashboard'); ?></label>
                        <select id="og-twitter-card-type" name="twitter_card_type">
                            <option value="summary_large_image" <?php selected($options['twitter_card_type'] ?? '', 'summary_large_image'); ?>><?php _e('Summary Large Image', 'starter-dashboard'); ?></option>
                            <option value="summary" <?php selected($options['twitter_card_type'] ?? '', 'summary'); ?>><?php _e('Summary', 'starter-dashboard'); ?></option>
                        </select>
                    </div>

                    <div class="bp-addon-settings__field">
                        <label for="og-twitter-site"><?php _e('Twitter @username', 'starter-dashboard'); ?></label>
                        <input type="text"
                               id="og-twitter-site"
                               name="twitter_site"
                               value="<?php echo esc_attr($options['twitter_site'] ?? ''); ?>"
                               placeholder="@yoursite"
                               class="regular-text">
                    </div>
                </div>
            </div>

            <div class="bp-addon-settings__sidebar">
                <div class="bp-addon-settings__preview-card">
                    <h4><?php _e('Live Preview', 'starter-dashboard'); ?></h4>
                    <p class="description"><?php _e('How your content will appear when shared on social media.', 'starter-dashboard'); ?></p>
                    <?php $instance->render_preview($options); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render preview card
     */
    private function render_preview($options = null) {
        if ($options === null) {
            $options = get_option($this->option_name, []);
        }
        $image = $options['default_og_image'] ?? '';
        $title = $options['site_name'] ?? get_bloginfo('name');
        $description = $options['default_description'] ?? get_bloginfo('description');
        $url = home_url();
        ?>
        <div style="max-width:400px; border:1px solid #dadde1; border-radius:8px; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <?php if ($image): ?>
                <div style="width:100%; height:200px; background:url('<?php echo esc_url($image); ?>') center/cover no-repeat; background-color:#f0f2f5;"></div>
            <?php else: ?>
                <div style="width:100%; height:200px; background:#f0f2f5; display:flex; align-items:center; justify-content:center; color:#65676b;">
                    <?php _e('No image set', 'starter-dashboard'); ?>
                </div>
            <?php endif; ?>
            <div style="padding:12px; background:#f0f2f5;">
                <div style="font-size:11px; color:#65676b; text-transform:uppercase;"><?php echo esc_html(parse_url($url, PHP_URL_HOST)); ?></div>
                <div style="font-size:14px; font-weight:600; color:#1c1e21; margin:4px 0;"><?php echo esc_html($title); ?></div>
                <div style="font-size:13px; color:#65676b; line-height:1.4;"><?php echo esc_html(wp_trim_words($description, 15)); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Save settings
     */
    public function save_settings($saved, $settings) {
        $sanitized = [];

        if (isset($settings['default_og_image'])) {
            $sanitized['default_og_image'] = esc_url_raw($settings['default_og_image']);
        }
        if (isset($settings['site_name'])) {
            $sanitized['site_name'] = sanitize_text_field($settings['site_name']);
        }
        if (isset($settings['default_description'])) {
            $sanitized['default_description'] = sanitize_textarea_field($settings['default_description']);
        }
        if (isset($settings['twitter_card_type'])) {
            $sanitized['twitter_card_type'] = sanitize_text_field($settings['twitter_card_type']);
        }
        if (isset($settings['twitter_site'])) {
            $sanitized['twitter_site'] = sanitize_text_field($settings['twitter_site']);
        }

        update_option($this->option_name, $sanitized);
        return true;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_media();
        }
    }

    /**
     * Add meta box to posts/pages
     */
    public function add_post_meta_box() {
        $post_types = ['post', 'page'];
        foreach ($post_types as $post_type) {
            add_meta_box(
                'starter_og_meta',
                __('Social Preview Settings', 'starter-dashboard'),
                [$this, 'render_post_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public function render_post_meta_box($post) {
        wp_nonce_field('starter_og_meta', 'starter_og_meta_nonce');

        $og_title = get_post_meta($post->ID, '_og_title', true);
        $og_description = get_post_meta($post->ID, '_og_description', true);
        $og_image = get_post_meta($post->ID, '_og_image', true);
        ?>
        <div class="og-meta-box" style="padding:10px 0;">
            <p>
                <label for="og-title"><strong><?php _e('OG Title', 'starter-dashboard'); ?></strong></label><br>
                <input type="text" id="og-title" name="og_title" value="<?php echo esc_attr($og_title); ?>" class="large-text" placeholder="<?php echo esc_attr($post->post_title); ?>">
                <span class="description"><?php _e('Leave empty to use post title', 'starter-dashboard'); ?></span>
            </p>

            <p>
                <label for="og-description"><strong><?php _e('OG Description', 'starter-dashboard'); ?></strong></label><br>
                <textarea id="og-description" name="og_description" rows="3" class="large-text" placeholder="<?php echo esc_attr(wp_trim_words(wp_strip_all_tags($post->post_content), 30)); ?>"><?php echo esc_textarea($og_description); ?></textarea>
                <span class="description"><?php _e('Leave empty to use excerpt or content', 'starter-dashboard'); ?></span>
            </p>

            <p>
                <label><strong><?php _e('OG Image', 'starter-dashboard'); ?></strong></label><br>
                <div class="og-image-field">
                    <input type="url" id="og-image-url" name="og_image" value="<?php echo esc_url($og_image); ?>" class="regular-text" placeholder="<?php _e('Use featured image if empty', 'starter-dashboard'); ?>">
                    <button type="button" class="button og-image-upload"><?php _e('Select Image', 'starter-dashboard'); ?></button>
                    <button type="button" class="button og-image-remove" <?php echo empty($og_image) ? 'style="display:none;"' : ''; ?>><?php _e('Remove', 'starter-dashboard'); ?></button>

                    <div class="og-image-preview" style="margin-top:10px;">
                        <?php if ($og_image): ?>
                            <img src="<?php echo esc_url($og_image); ?>" style="max-width:300px;height:auto;border:1px solid #ddd;">
                        <?php endif; ?>
                    </div>
                </div>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $(document).on('click', '.og-image-upload', function(e) {
                e.preventDefault();
                var button = $(this);
                var field = button.siblings('input[type="url"]');
                var preview = button.siblings('.og-image-preview');
                var removeBtn = button.siblings('.og-image-remove');

                var frame = wp.media({
                    title: 'Select OG Image',
                    button: { text: 'Use this image' },
                    multiple: false
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    field.val(attachment.url);
                    preview.html('<img src="' + attachment.url + '" style="max-width:300px;height:auto;border:1px solid #ddd;">');
                    removeBtn.show();
                });

                frame.open();
            });

            $(document).on('click', '.og-image-remove', function(e) {
                e.preventDefault();
                var button = $(this);
                button.siblings('input[type="url"]').val('');
                button.siblings('.og-image-preview').html('');
                button.hide();
            });
        });
        </script>
        <?php
    }

    /**
     * Save post meta
     */
    public function save_post_meta($post_id) {
        if (!isset($_POST['starter_og_meta_nonce']) ||
            !wp_verify_nonce($_POST['starter_og_meta_nonce'], 'starter_og_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = ['og_title', 'og_description', 'og_image'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = $field === 'og_image'
                    ? esc_url_raw($_POST[$field])
                    : sanitize_text_field($_POST[$field]);

                if (!empty($value)) {
                    update_post_meta($post_id, '_' . $field, $value);
                } else {
                    delete_post_meta($post_id, '_' . $field);
                }
            }
        }
    }

    /**
     * Output meta tags in head
     */
    public function output_meta_tags() {
        $options = get_option($this->option_name, []);

        $title = $this->get_og_title($options);
        $description = $this->get_og_description($options);
        $image = $this->get_og_image($options);
        $url = $this->get_current_url();
        $site_name = !empty($options['site_name']) ? $options['site_name'] : get_bloginfo('name');
        $type = is_single() ? 'article' : 'website';

        echo "\n<!-- Starter Dashboard Social Preview -->\n";
        echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";

        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            echo '<meta property="og:image:width" content="1200">' . "\n";
            echo '<meta property="og:image:height" content="630">' . "\n";
        }

        $card_type = !empty($options['twitter_card_type']) ? $options['twitter_card_type'] : 'summary_large_image';
        echo '<meta name="twitter:card" content="' . esc_attr($card_type) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";

        if (!empty($options['twitter_site'])) {
            echo '<meta name="twitter:site" content="' . esc_attr($options['twitter_site']) . '">' . "\n";
        }

        if ($image) {
            echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
        }

        echo "<!-- /Starter Dashboard Social Preview -->\n\n";
    }

    private function get_og_title($options) {
        if (is_singular()) {
            $post_id = get_the_ID();
            $custom = get_post_meta($post_id, '_og_title', true);
            if ($custom) {
                return $custom;
            }
            return get_the_title();
        }

        if (is_home() || is_front_page()) {
            return !empty($options['site_name']) ? $options['site_name'] : get_bloginfo('name');
        }

        if (is_archive()) {
            return get_the_archive_title();
        }

        return get_bloginfo('name');
    }

    private function get_og_description($options) {
        if (is_singular()) {
            $post_id = get_the_ID();
            $custom = get_post_meta($post_id, '_og_description', true);
            if ($custom) {
                return $custom;
            }

            $post = get_post($post_id);
            if ($post->post_excerpt) {
                return wp_trim_words($post->post_excerpt, 30);
            }

            return wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        }

        if (is_archive()) {
            return get_the_archive_description() ?: '';
        }

        return !empty($options['default_description']) ? $options['default_description'] : get_bloginfo('description');
    }

    private function get_og_image($options) {
        if (is_singular()) {
            $post_id = get_the_ID();

            $custom = get_post_meta($post_id, '_og_image', true);
            if ($custom) {
                return $custom;
            }

            if (has_post_thumbnail($post_id)) {
                $thumb = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'large');
                if ($thumb) {
                    return $thumb[0];
                }
            }
        }

        return !empty($options['default_og_image']) ? $options['default_og_image'] : '';
    }

    private function get_current_url() {
        global $wp;
        return home_url(add_query_arg([], $wp->request));
    }
}

// Initialize
Starter_Addon_OG_Image::instance();
