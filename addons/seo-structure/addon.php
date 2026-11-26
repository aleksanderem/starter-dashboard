<?php
/**
 * Starter Dashboard Addon: SEO Structure
 *
 * 1. Heading structure: one H1 per page, H2/H3 only where the SEO guidelines say so, everything
 *    else (menu, footer, tiles, hidden duplicates, ...) becomes ordinary text — with the same look.
 *    Works on the rendered widgets, so it covers every language and tiles loaded on scroll.
 * 2. 404 pages: no canonical, no language alternates, no structured data about a page that does not exist.
 *
 * Disable in code:
 *   add_filter('mwts_seo_structure_headings', '__return_false');
 *   add_filter('mwts_seo_structure_404', '__return_false');
 * Rules live in rules.php (filter `mwts_seo_structure_rules`).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-heading-rules.php';
require_once __DIR__ . '/class-404-cleanup.php';

class Starter_Addon_Seo_Structure {

    private static $instance = null;

    /** @var array */
    private $rules = [];

    /** @var array<int,int> id => original-language id */
    private $original_ids = [];

    /** @var bool the hidden H1 was already added in this request */
    private $h1_added = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $rules = require __DIR__ . '/rules.php';
        $this->rules = apply_filters('mwts_seo_structure_rules', is_array($rules) ? $rules : []);

        if (apply_filters('mwts_seo_structure_headings', true)) {
            add_filter('elementor/widget/render_content', [$this, 'filter_widget'], 99, 2);
            add_filter('elementor/frontend/the_content', [$this, 'maybe_add_h1'], 99);
            add_action('wp_head', [$this, 'print_css'], 99);
        }
        if (apply_filters('mwts_seo_structure_404', true)) {
            add_action('template_redirect', [$this, 'maybe_clean_404'], 0);
        }
    }

    /** Elementor widget output → headings re-tagged according to the rules. */
    public function filter_widget($content, $widget) {
        try {
            if (!is_string($content) || stripos($content, '<h') === false || is_admin()) {
                return $content;
            }
            if ($this->in_elementor_editor()) {
                return $content; // editors see the tags they chose
            }
            $id = is_object($widget) && method_exists($widget, 'get_id') ? (string) $widget->get_id() : '';
            list($doc_id, $doc_type) = $this->current_document();
            $decision = Starter_Heading_Rules::decide($id, $doc_id, $doc_type, $this->is_hidden_everywhere($widget), $this->rules);
            return $decision === null ? $content : Starter_Heading_Rules::retag($content, $decision);
        } catch (\Throwable $e) {
            error_log('[mwts-seo-structure] ' . $e->getMessage());
            return $content;
        }
    }

    /** Pages without a visible title get a visually hidden H1 (page title), unless one exists. */
    public function maybe_add_h1($content) {
        try {
            if ($this->h1_added || !is_string($content) || !is_singular() || !in_the_loop() || !is_main_query()) {
                return $content;
            }
            $ids = isset($this->rules['h1_pages']) ? $this->rules['h1_pages'] : [];
            $page_id = (int) get_queried_object_id();
            if (!$page_id || !in_array($this->original_id($page_id), $ids, true)) {
                return $content;
            }
            if (Starter_Heading_Rules::has_h1($content)) {
                return $content;
            }
            $h1 = Starter_Heading_Rules::hidden_h1(get_the_title($page_id));
            if ($h1 === '') {
                return $content;
            }
            $this->h1_added = true;
            return $h1 . $content;
        } catch (\Throwable $e) {
            error_log('[mwts-seo-structure] ' . $e->getMessage());
            return $content;
        }
    }

    /** Style parity for promoted headings (h3 → h2 ...). */
    public function print_css() {
        $pairs = isset($this->rules['promotions']) ? $this->rules['promotions'] : [];
        $css = Starter_Heading_Rules::parity_css($pairs);
        if ($css !== '') {
            echo '<style id="mwts-heading-parity">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from constants
        }
    }

    public function maybe_clean_404() {
        if (is_404() && !is_admin() && !wp_doing_ajax()) {
            ob_start(['Starter_404_Cleanup', 'clean']);
        }
    }

    // ---- helpers ----------------------------------------------------------------------------

    private function in_elementor_editor() {
        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance)) {
            return false;
        }
        $p = \Elementor\Plugin::$instance;
        $edit = isset($p->editor) && method_exists($p->editor, 'is_edit_mode') && $p->editor->is_edit_mode();
        $preview = isset($p->preview) && method_exists($p->preview, 'is_preview_mode') && $p->preview->is_preview_mode();
        return $edit || $preview;
    }

    /** @return array{0:int,1:string} original-language document id and Elementor document type */
    private function current_document() {
        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->documents)) {
            return [0, ''];
        }
        $doc = \Elementor\Plugin::$instance->documents->get_current();
        if (!$doc || !method_exists($doc, 'get_main_id')) {
            return [0, ''];
        }
        $type = method_exists($doc, 'get_name') ? (string) $doc->get_name() : '';
        return [$this->original_id((int) $doc->get_main_id()), $type];
    }

    /** Post id in the site's default language (widget ids are shared, post ids are not). */
    private function original_id($id) {
        if (!$id) {
            return 0;
        }
        if (!isset($this->original_ids[$id])) {
            $orig = apply_filters('wpml_object_id', $id, get_post_type($id), true, apply_filters('wpml_default_language', null));
            $this->original_ids[$id] = $orig ? (int) $orig : (int) $id;
        }
        return $this->original_ids[$id];
    }

    /** Widget or any parent hidden on desktop, tablet and mobile = never visible. */
    private function is_hidden_everywhere($widget) {
        $el = $widget;
        for ($i = 0; $el && $i < 15; $i++) {
            if (method_exists($el, 'get_settings') && Starter_Heading_Rules::hidden_everywhere((array) $el->get_settings())) {
                return true;
            }
            $el = method_exists($el, 'get_parent') ? $el->get_parent() : null;
        }
        return false;
    }
}

Starter_Addon_Seo_Structure::instance();
