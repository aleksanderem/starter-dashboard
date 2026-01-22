<?php
/**
 * Starter Dashboard Addon: Vertical Menu Shortcode
 *
 * Displays WordPress menu vertically with Elementor color integration
 * Usage: [vertical_menu menu="menu-name" show_icons="yes" animation="slide"]
 */

defined('ABSPATH') || exit;

class Starter_Addon_Vertical_Menu {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('vertical_menu', [$this, 'render_shortcode']);

        // Register shortcode in Elementor
        if (class_exists('\Elementor\Plugin')) {
            add_filter('elementor/widgets/wordpress/widget_args', function($args) {
                if (isset($args['shortcode_tags'])) {
                    $args['shortcode_tags'][] = 'vertical_menu';
                }
                return $args;
            });
        }
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'menu' => '',
            'show_icons' => 'yes',
            'animation' => 'slide',
            'container_class' => '',
            'depth' => 0,
            'accordion' => 'yes',
            'submenu_bg' => '#F5F5F5',
        ], $atts);

        // Get menu
        if (empty($atts['menu'])) {
            $locations = get_nav_menu_locations();
            if (isset($locations['primary'])) {
                $menu = wp_get_nav_menu_object($locations['primary']);
            } else {
                $menus = wp_get_nav_menus();
                $menu = !empty($menus) ? $menus[0] : null;
            }
        } else {
            $menu = wp_get_nav_menu_object($atts['menu']);
        }

        if (!$menu) {
            return '<p>' . __('Menu not found. Please specify a valid menu.', 'starter-dashboard') . '</p>';
        }

        $menu_items = wp_get_nav_menu_items($menu->term_id);

        if (empty($menu_items)) {
            return '<p>' . __('Menu is empty.', 'starter-dashboard') . '</p>';
        }

        $menu_tree = $this->build_menu_tree($menu_items);
        $menu_id = 'vertical-menu-' . uniqid();
        $primary_color = $this->get_elementor_primary_color();

        ob_start();
        ?>

        <div id="<?php echo esc_attr($menu_id); ?>" class="vertical-menu-container <?php echo esc_attr($atts['container_class']); ?>" data-animation="<?php echo esc_attr($atts['animation']); ?>" data-accordion="<?php echo esc_attr($atts['accordion']); ?>">
            <?php $this->render_menu_items($menu_tree, 0, $atts); ?>
        </div>

        <style>
            #<?php echo esc_attr($menu_id); ?> {
                --vm-primary-color: <?php echo esc_attr($primary_color); ?>;
                --vm-submenu-bg: <?php echo esc_attr($atts['submenu_bg']); ?>;
            }

            .vertical-menu-container {
                width: 100%;
                font-family: inherit;
                position: relative;
                z-index: 1;
            }

            .vertical-menu-container * {
                box-sizing: border-box;
            }

            .vertical-menu-container ul {
                list-style: none !important;
                margin: 0 !important;
                padding: 0 !important;
                border-bottom: 0 !important;
            }

            .vertical-menu-container ul::after,
            .vertical-menu-container ul::before {
                display: none !important;
                content: none !important;
            }

            .vertical-menu-container li {
                margin: 0 !important;
                padding: 0 !important;
                position: relative;
                list-style: none !important;
                border-bottom: 0 !important;
            }

            .vertical-menu-container li::after,
            .vertical-menu-container li::before {
                display: none !important;
                content: none !important;
            }

            .vertical-menu-container .menu-item {
                border-bottom: 0 !important;
            }

            .vertical-menu-container .menu-item::after,
            .vertical-menu-container .menu-item::before {
                display: none !important;
                content: none !important;
            }

            .vertical-menu-container a {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 22px 20px;
                text-decoration: none;
                color: #2B2B2B;
                transition: all 0.3s ease;
                position: relative;
                border-radius: 12px;
                margin: 6px 0;
                font-size: 18px;
                font-weight: 500;
                letter-spacing: -0.01em;
                box-shadow: none !important;
                border: 0 !important;
                outline: none !important;
            }

            .vertical-menu-container a:hover {
                background-color: var(--vm-primary-color);
                color: white;
                box-shadow: none !important;
                border: 0 !important;
            }

            .vertical-menu-container li.current-menu-item > a,
            .vertical-menu-container li.current-menu-parent > a,
            .vertical-menu-container li.menu-open > a {
                background-color: var(--vm-primary-color);
                color: white;
                box-shadow: none !important;
                border: 0 !important;
            }

            .vertical-menu-container .sub-menu {
                background-color: transparent;
                position: relative;
                width: 100%;
                margin-top: 6px;
                box-shadow: none !important;
                border: 0 !important;
            }

            .vertical-menu-container > ul > li > .sub-menu > li > a {
                padding: 22px 20px 22px 60px;
                font-size: 18px;
                font-weight: 500;
                color: #2B2B2B;
                background-color: var(--vm-submenu-bg);
                margin: 6px 0;
                box-shadow: none !important;
                border: 0 !important;
                outline: none !important;
            }

            .vertical-menu-container .sub-menu a:hover {
                background-color: var(--vm-primary-color);
                color: white;
                box-shadow: none !important;
                border: 0 !important;
            }

            .vertical-menu-container .sub-menu .current-menu-item > a {
                background-color: var(--vm-primary-color);
                color: white;
            }

            .vertical-menu-container .sub-menu a {
                padding: 22px 20px 22px 60px !important;
                font-size: 18px;
                font-weight: 500;
                color: #2B2B2B;
                background-color: var(--vm-submenu-bg);
                margin: 6px 0;
                box-shadow: none !important;
                border: 0 !important;
                outline: none !important;
            }

            .vertical-menu-container .sub-menu .sub-menu a {
                padding-left: 80px !important;
            }

            .vertical-menu-container .sub-menu .sub-menu .sub-menu a {
                padding-left: 100px !important;
            }

            .vertical-menu-container .sub-menu .sub-menu .sub-menu .sub-menu a {
                padding-left: 120px !important;
            }

            .vertical-menu-container .sub-menu {
                display: none;
                max-height: 0;
                overflow: hidden;
                visibility: hidden;
                opacity: 0;
                transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                            opacity 0.3s ease,
                            visibility 0.3s ease,
                            display 0s 0.4s;
            }

            .vertical-menu-container li.menu-open > .sub-menu {
                display: block !important;
                max-height: 5000px;
                visibility: visible;
                opacity: 1;
            }

            .vertical-menu-container[data-animation="slide"] .sub-menu {
                display: block !important;
                transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                            opacity 0.3s ease,
                            visibility 0.3s ease,
                            padding 0.3s ease;
                padding-top: 0;
                padding-bottom: 0;
            }

            .vertical-menu-container[data-animation="slide"] li.menu-open > .sub-menu {
                padding-top: 5px;
                padding-bottom: 5px;
            }

            .vertical-menu-container[data-animation="fade"] .sub-menu {
                transition: opacity 0.3s ease,
                            visibility 0.3s ease;
                max-height: none !important;
                display: block !important;
            }

            .vertical-menu-container[data-animation="fade"] li.menu-open > .sub-menu {
                opacity: 1;
                visibility: visible;
            }

            .vertical-menu-container[data-animation="fade"] .sub-menu:not(.menu-open > .sub-menu) {
                opacity: 0;
                visibility: hidden;
                position: absolute;
                pointer-events: none;
            }

            .vertical-menu-container[data-animation="none"] .sub-menu {
                transition: none;
                display: none;
            }

            .vertical-menu-container[data-animation="none"] li.menu-open > .sub-menu {
                display: block;
                max-height: none;
                visibility: visible;
                opacity: 1;
            }

            .menu-expand-icon {
                margin-left: auto;
                padding-left: 10px;
                transition: transform 0.3s ease;
                display: inline-flex;
                align-items: center;
            }

            .menu-expand-icon svg {
                width: 14px;
                height: 14px;
                fill: currentColor;
                transition: fill 0.3s ease;
            }

            .vertical-menu-container a:hover .menu-expand-icon svg,
            .vertical-menu-container li.menu-open > a .menu-expand-icon svg {
                fill: white;
            }

            li.menu-open > a .menu-expand-icon {
                transform: rotate(180deg);
            }

            .menu-item-text {
                flex: 1;
            }

            @media (max-width: 768px) {
                .vertical-menu-container a {
                    padding: 22px 20px;
                    font-size: 18px;
                    border-radius: 12px;
                }

                .vertical-menu-container .sub-menu {
                    padding-left: 30px;
                }

                .vertical-menu-container .sub-menu a {
                    padding: 22px 20px;
                    font-size: 18px;
                }
            }

            .vertical-menu-container a:focus {
                outline: 2px solid var(--vm-primary-color);
                outline-offset: 2px;
            }

            .vertical-menu-container.loading {
                opacity: 0.6;
                pointer-events: none;
            }
        </style>

        <script>
        (function() {
            function initVerticalMenu() {
                const menuContainer = document.getElementById('<?php echo esc_js($menu_id); ?>');
                if (!menuContainer) return;

                if (menuContainer.dataset.initialized === 'true') return;
                menuContainer.dataset.initialized = 'true';

                menuContainer.addEventListener('click', function(e) {
                    const link = e.target.closest('a');
                    if (!link) return;

                    if (!menuContainer.contains(link)) return;

                    const parentLi = link.parentElement;
                    const submenu = parentLi.querySelector(':scope > .sub-menu');

                    if (submenu) {
                        const href = link.getAttribute('href');
                        if (!href || href === '#' || href === '' || href === 'javascript:void(0)') {
                            e.preventDefault();
                            e.stopPropagation();

                            const isOpen = parentLi.classList.contains('menu-open');

                            if (!isOpen) {
                                parentLi.classList.add('menu-open');

                                const accordionMode = menuContainer.getAttribute('data-accordion') === 'yes';
                                if (accordionMode) {
                                    const siblings = Array.from(parentLi.parentElement.children);
                                    siblings.forEach(function(sibling) {
                                        if (sibling !== parentLi && sibling.classList.contains('menu-open')) {
                                            sibling.classList.remove('menu-open');
                                        }
                                    });
                                }
                            } else {
                                parentLi.classList.remove('menu-open');
                                const nestedOpen = parentLi.querySelectorAll('.menu-open');
                                nestedOpen.forEach(function(nested) {
                                    nested.classList.remove('menu-open');
                                });
                            }

                            return false;
                        }
                    }
                });

                menuContainer.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        const link = e.target.closest('a');
                        if (!link) return;

                        const parentLi = link.parentElement;
                        const submenu = parentLi.querySelector(':scope > .sub-menu');

                        if (submenu) {
                            const href = link.getAttribute('href');
                            if (!href || href === '#' || href === '') {
                                e.preventDefault();
                                parentLi.classList.toggle('menu-open');
                            }
                        }
                    }
                });

                setTimeout(function() {
                    const currentItems = menuContainer.querySelectorAll('.current-menu-item, .current-menu-parent, .current-menu-ancestor');
                    currentItems.forEach(function(item) {
                        let parent = item.parentElement;
                        while (parent && parent !== menuContainer) {
                            if (parent.tagName === 'LI' && parent.querySelector(':scope > .sub-menu')) {
                                parent.classList.add('menu-open');
                            }
                            parent = parent.parentElement;
                        }
                    });
                }, 100);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initVerticalMenu);
            } else {
                initVerticalMenu();
            }

            if (window.jQuery) {
                jQuery(window).on('elementor/frontend/init', function() {
                    setTimeout(initVerticalMenu, 100);
                });

                jQuery(document).on('elementor/popup/show', initVerticalMenu);
                jQuery(document).on('elementor/offcanvas/show', initVerticalMenu);
            }
        })();
        </script>

        <?php
        return ob_get_clean();
    }

    /**
     * Get Elementor primary color
     */
    private function get_elementor_primary_color() {
        $primary_color = '#0073aa';

        if (class_exists('\Elementor\Plugin')) {
            $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
            if ($kit) {
                $kit_settings = $kit->get_settings();
                if (isset($kit_settings['system_colors'])) {
                    foreach ($kit_settings['system_colors'] as $color) {
                        if ($color['_id'] === 'primary') {
                            $primary_color = $color['color'];
                            break;
                        }
                    }
                }
            }
        }

        return $primary_color;
    }

    /**
     * Build hierarchical menu tree
     */
    private function build_menu_tree($menu_items, $parent_id = 0) {
        $tree = [];

        foreach ($menu_items as $item) {
            if ($item->menu_item_parent == $parent_id) {
                $children = $this->build_menu_tree($menu_items, $item->ID);

                $tree[] = [
                    'id' => $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'target' => $item->target,
                    'classes' => $item->classes,
                    'description' => $item->description,
                    'children' => $children,
                    'current' => in_array('current-menu-item', $item->classes),
                    'current_parent' => in_array('current-menu-parent', $item->classes),
                    'current_ancestor' => in_array('current-menu-ancestor', $item->classes),
                ];
            }
        }

        return $tree;
    }

    /**
     * Render menu items recursively
     */
    private function render_menu_items($items, $depth = 0, $atts = []) {
        if (empty($items)) return;

        $class = $depth === 0 ? 'vertical-menu' : 'sub-menu';
        echo '<ul class="' . esc_attr($class) . ' depth-' . esc_attr($depth) . '">';

        foreach ($items as $item) {
            $item_classes = ['menu-item'];

            if (!empty($item['children'])) {
                $item_classes[] = 'has-children';
            }

            if ($item['current']) {
                $item_classes[] = 'current-menu-item';
            }

            if ($item['current_parent']) {
                $item_classes[] = 'current-menu-parent';
            }

            if ($item['current_ancestor']) {
                $item_classes[] = 'current-menu-ancestor';
            }

            if (!empty($item['classes']) && is_array($item['classes'])) {
                $item_classes = array_merge($item_classes, $item['classes']);
            }

            echo '<li class="' . esc_attr(implode(' ', $item_classes)) . '">';

            $link_attrs = [
                'href' => esc_url($item['url'] ?: '#'),
            ];

            if (!empty($item['target'])) {
                $link_attrs['target'] = esc_attr($item['target']);
            }

            if (!empty($item['description'])) {
                $link_attrs['title'] = esc_attr($item['description']);
            }

            echo '<a';
            foreach ($link_attrs as $attr => $value) {
                echo ' ' . $attr . '="' . $value . '"';
            }
            echo '>';

            echo '<span class="menu-item-text">' . esc_html($item['title']) . '</span>';

            if (!empty($item['children']) && $atts['show_icons'] === 'yes') {
                echo '<span class="menu-expand-icon">';
                echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></svg>';
                echo '</span>';
            }

            echo '</a>';

            if (!empty($item['children'])) {
                $this->render_menu_items($item['children'], $depth + 1, $atts);
            }

            echo '</li>';
        }

        echo '</ul>';
    }
}

// Initialize
Starter_Addon_Vertical_Menu::instance();
