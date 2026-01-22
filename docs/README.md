# Starter Dashboard - Documentation

Version: 4.1.x

## Overview

Starter Dashboard is a comprehensive WordPress admin plugin that replaces the default WordPress dashboard with a customizable, branded hub. It provides a Command Menu (Cmd+K), post type management tiles, user role editing, menu visibility control, custom post type visibility settings, whitelabel options, and a modular addon system with 10+ built-in addons including 301 Redirects management.

---

## Table of Contents

1. [Installation](#installation)
2. [Architecture](#architecture)
3. [Command Menu](#command-menu)
4. [Main Dashboard](#main-dashboard)
5. [Settings Page](#settings-page)
6. [Role Editor](#role-editor)
7. [Menu Visibility Control](#menu-visibility-control)
8. [Admin Menu Order](#admin-menu-order)
9. [CPT Visibility](#cpt-visibility)
10. [Visual Settings](#visual-settings)
11. [Whitelabel](#whitelabel)
12. [Content Versioning](#content-versioning)
13. [Import/Export](#importexport)
14. [Addon System](#addon-system)
15. [301 Redirects Addon](#301-redirects-addon)
16. [Icons (ezicons)](#icons-ezicons)
17. [AJAX Endpoints](#ajax-endpoints)
18. [Hooks and Filters](#hooks-and-filters)
19. [Database Options](#database-options)
20. [GitHub Updates](#github-updates)

---

## Installation

The plugin should be placed in `wp-content/plugins/starter-dashboard/` with the following structure:

```
starter-dashboard/
├── starter-dashboard.php    # Main plugin file
├── dashboard.css            # Dashboard page styles
├── dashboard.js             # Dashboard page scripts
├── settings.css             # Settings page styles
├── settings.js              # Settings page scripts
├── sortable.min.js          # SortableJS library
├── addons/                  # Addon system
│   ├── class-addon-loader.php
│   ├── hubspot-forms/
│   ├── og-image/
│   ├── post-type-pill/
│   ├── vertical-menu/
│   └── ...
└── docs/                    # Documentation
```

After placing files, activate through WordPress admin Plugins page.

---

## Architecture

### Main Class: `Starter_Dashboard`

The plugin uses a singleton pattern with `Starter_Dashboard::instance()`. All functionality is initialized in the constructor through WordPress hooks.

```php
class Starter_Dashboard {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // All hooks registered here
    }
}
```

### Key Properties

| Property | Type | Description |
|----------|------|-------------|
| `$dashboard_hook` | string | Hook suffix for dashboard page |
| `$settings_hook` | string | Hook suffix for settings page |
| `$addons_hook` | string | Hook suffix for addons page |
| `$content_types` | array | Post types shown in Content tab (`post`, `page`) |
| `$builder_types` | array | Post types shown in Builder tab (`elementor_library`, `e-landing-page`) |
| `$type_colors` | array | Color mapping for post type tiles |

---

## Command Menu

The Command Menu provides Spotlight/Raycast-style quick navigation across the WordPress admin.

### Activation

Press `Cmd+K` (Mac) or `Ctrl+K` (Windows/Linux) anywhere in the WordPress admin to open.

### Features

- **Quick Navigation** - Jump to any admin page instantly
- **Nested Categories** - Create, Settings, Tools, Site, Hub, WooCommerce
- **Recent Posts** - Quick access to recently edited content
- **Keyboard-driven** - Full keyboard navigation with arrow keys and Enter
- **Smart Search** - Fuzzy search across all menu items and actions

### Technology

The Command Menu is built as a separate React/TypeScript application:

```
command-menu/
├── src/
│   ├── wp-command-menu.tsx    # Main component
│   ├── components/            # UI components
│   └── globals.css            # Tailwind styles
├── dist/                      # Built assets
└── package.json               # Dependencies
```

Built with React 19, Tailwind CSS 4, React Aria Components, and @untitledui/icons.

### Building

```bash
cd command-menu
npm install
npm run build
```

### Configuration

Menu items are automatically collected from WordPress admin menu and passed to the React app via `wp_localize_script()`:

```php
wp_localize_script('wp-command-menu-js', 'wpCommandMenuConfig', [
    'menuItems' => $menu_items,
    'recentPosts' => $recent_posts,
    'quickActions' => $quick_actions,
    'settingsActions' => $settings_actions,
    'hubActions' => $hub_actions,
    // ...
]);
```

---

## Main Dashboard

The dashboard (`admin.php?page=starter-dashboard`) displays:

### Header Section
- Custom logo (configurable in Visual Settings)
- Hub name (configurable)
- User greeting
- Settings button (admin only)

### Statistics Bar
- Total items count across all post types
- Per-type counts with color coding

### Tabbed Content Areas

1. **Content Tab** - Posts and Pages
2. **Builder & Templates Tab** - Elementor templates
3. **Custom Post Types Tab** - All other registered CPTs
4. **Additional Elements Tab** - Custom menu items for selected users
5. **Addon Tabs** - Dynamically added for active addons with settings

### Post Type Tiles

Each tile displays:
- Post type icon
- Post type label
- Item count
- Quick action links (View All, Add New)
- Color coding based on `$type_colors`

Tiles are draggable (SortableJS) and order is saved per-user in user meta.

### Tile Order Storage

```php
// Saved to user meta
update_user_meta($user_id, 'starter_dashboard_tile_order', $order_array);
```

---

## Settings Page

The settings page (`admin.php?page=starter-dashboard-settings`) has multiple tabs:

### Tab: Menu Visibility

Control which admin menu items are visible for each user role. Supports both top-level menus and submenus.

**Data format:**
```php
$hidden_menus = [
    'editor' => ['edit.php', 'upload.php'],
    'author' => ['tools.php', 'edit.php?post_type=page'],
];
```

Submenu items use format: `parent_slug||submenu_slug`

### Tab: Role Editor

Full capability management for all WordPress roles:
- View and edit capabilities per role
- Create new roles (optionally cloning from existing)
- Delete custom roles (built-in roles protected)
- Capabilities grouped by category (Posts, Pages, Media, Users, etc.)
- Add custom capabilities

### Tab: CPT Visibility

Control which Custom Post Types appear in the dashboard for each user role.

### Tab: Admin Menu Order

Drag-and-drop reordering of the WordPress admin sidebar menu. Order saved globally and applied to all users.

### Tab: Additional Elements

Configure which menu items appear in the "Additional Elements" tab on the dashboard. Select specific users who can see this tab.

### Tab: Visual Settings

Customize the dashboard appearance:
- Hub Name (replaces "Dashboard" in menu)
- Logo URL
- Primary Color
- Secondary Color
- Accent Color

---

## Role Editor

### Creating Roles

```php
// AJAX endpoint: starter_create_role
$role_slug = sanitize_key($_POST['role_slug']);
$role_name = sanitize_text_field($_POST['role_name']);
$clone_from = sanitize_key($_POST['clone_from']);

add_role($role_slug, $role_name, $capabilities);
```

### Editing Capabilities

```php
// AJAX endpoint: starter_save_role_caps
$role = get_role($role_slug);
$role->add_cap('capability_name');
$role->remove_cap('capability_name');
```

### Capability Groups

Capabilities are automatically grouped:
- **Posts** - Contains `post` in name
- **Pages** - Contains `page` in name
- **Media** - Contains `upload`, `media`, `file`
- **Users** - Contains `user`
- **Themes & Plugins** - Contains `theme`, `plugin`, `update`, `install`
- **Settings** - Contains `option`, `setting`, `manage`
- **Custom Post Types** - Starts with `edit_`, `delete_`, `publish_`, `read_`
- **Other** - Everything else

---

## Menu Visibility Control

### How It Works

1. Original menu stored at priority 9998
2. Restrictions applied at priority 9999
3. Menu items removed using `remove_menu_page()` and `remove_submenu_page()`

### Applying Restrictions

```php
public function apply_menu_restrictions() {
    $user = wp_get_current_user();
    $hidden_menus = get_option('starter_hidden_menus', []);

    foreach ($user->roles as $role) {
        if (isset($hidden_menus[$role])) {
            foreach ($hidden_menus[$role] as $menu_slug) {
                if (strpos($menu_slug, '||') !== false) {
                    // Submenu: parent_slug||submenu_slug
                    list($parent, $submenu) = explode('||', $menu_slug);
                    remove_submenu_page($parent, $submenu);
                } else {
                    remove_menu_page($menu_slug);
                }
            }
        }
    }
}
```

---

## Admin Menu Order

### Storage

```php
// Option name: starter_admin_menu_order
$order = [
    'starter-dashboard',
    'separator1',
    'edit.php',
    'edit.php?post_type=page',
    // ...
];
```

### Application

Menu order is applied at priority 10000 (after all other menu modifications):

```php
public function apply_menu_order() {
    global $menu;
    $saved_order = get_option('starter_admin_menu_order', []);

    // Reorder $menu based on $saved_order
}
```

---

## CPT Visibility

Control which post types appear in dashboard tiles per role.

### Storage

```php
// Option: starter_hidden_cpts
$hidden_cpts = [
    'editor' => ['elementor_library'],
    'author' => ['elementor_library', 'e-landing-page'],
];
```

### Filtering

```php
public function get_post_types_for_tiles() {
    $user = wp_get_current_user();
    $hidden = get_option('starter_hidden_cpts', []);
    $all_types = get_post_types(['public' => true], 'objects');

    foreach ($user->roles as $role) {
        if (isset($hidden[$role])) {
            // Remove hidden types from $all_types
        }
    }

    return $all_types;
}
```

---

## Visual Settings

### Options

| Setting | Default | Description |
|---------|---------|-------------|
| `hub_name` | "Content Hub" | Dashboard page title and menu label |
| `logo_url` | Plugin default | Logo shown in dashboard header |
| `primary_color` | `#1C3C8B` | Main brand color |
| `secondary_color` | `#2ABADE` | Secondary color |
| `accent_color` | `#0097CD` | Accent color |

### Storage

```php
// Option: starter_visual_settings
$settings = [
    'hub_name' => 'Content Hub',
    'logo_url' => 'https://...',
    'primary_color' => '#1C3C8B',
    'secondary_color' => '#2ABADE',
    'accent_color' => '#0097CD',
];
```

### CSS Variables

Visual settings are output as CSS custom properties:

```css
.starter-dashboard {
    --header-primary: #1C3C8B;
    --header-secondary: #2ABADE;
    --header-accent: #0097CD;
}
```

---

## Whitelabel

The Whitelabel feature allows complete rebranding of the plugin for agency/client use.

### Accessing Whitelabel Settings

Whitelabel settings are hidden by default. Access via:
```
/wp-admin/admin.php?page=starter-whitelabel
```

Or add `?whitelabel=1` to any dashboard URL to reveal the menu item.

### Options

| Setting | Description |
|---------|-------------|
| Plugin Name | Replace "Starter Dashboard" with custom name |
| Plugin Description | Custom description shown in Plugins list |
| Plugin Author | Custom author name |
| Plugin Author URI | Custom author website |
| Hide from Plugins List | Completely hide plugin from Plugins page |

### Storage

```php
// Option: starter_whitelabel_settings
$settings = [
    'plugin_name' => 'My Custom Dashboard',
    'plugin_description' => 'Custom admin dashboard',
    'plugin_author' => 'My Agency',
    'plugin_author_uri' => 'https://myagency.com',
    'hide_from_plugins' => false,
];
```

### Implementation

Whitelabel settings are applied via `all_plugins` filter:

```php
add_filter('all_plugins', [$this, 'filter_plugin_metadata']);
```

---

## Content Versioning

Posts can be tagged with a "Content Version" meta field for filtering.

### Meta Key

```php
$meta_key = '_content_version';
// Values: '', '1', '2'
```

### Filter Dropdown

Added to post list tables via `restrict_manage_posts` hook. Allows filtering by:
- All versions
- Version 1 (legacy)
- Version 2 (new)

### Default for New Posts

New posts automatically get Version 2:

```php
add_action('wp_insert_post', function($post_id, $post, $update) {
    if (!$update) {
        update_post_meta($post_id, '_content_version', '2');
    }
}, 10, 3);
```

---

## Import/Export

### Export Format

```json
{
    "meta": {
        "plugin_version": "3.9.2",
        "export_date": "2024-01-21T12:00:00+00:00",
        "site_url": "https://example.com",
        "sections": ["all"]
    },
    "settings": {
        "hidden_menus": {},
        "hidden_cpts": {},
        "admin_menu_order": [],
        "visual_settings": {},
        "additional_elements": {}
    }
}
```

### Sections

- `menu_visibility` - Hidden menus per role
- `cpt_visibility` - Hidden CPTs per role
- `admin_menu_order` - Menu ordering
- `visual_settings` - Branding
- `additional_elements` - Additional Elements config
- `all` - Everything

---

## Addon System

See [ADDONS.md](./ADDONS.md) for complete addon development guide.

### Quick Overview

Addons are modular extensions managed by `Starter_Addon_Loader`. They can:
- Add Elementor widgets
- Add WordPress shortcodes
- Integrate with external services
- Add settings panels to the dashboard

### Loader Location

```
addons/class-addon-loader.php
```

### Registering Addons

Addons are registered in `register_addons()` method:

```php
$this->addons['addon-id'] = [
    'name' => 'Addon Name',
    'description' => 'What it does',
    'icon' => 'ezicon-name',
    'category' => 'elementor|integration|seo|ui',
    'file' => $addons_dir . '/addon-folder/addon.php',
    'has_settings' => true|false,
    'settings_callback' => 'Class::render_settings',
    'version' => '1.0.0',
];
```

---

## 301 Redirects Addon

The 301 Redirects addon provides comprehensive URL redirect management directly in the dashboard.

### Features

- **Multiple Redirect Types** - 301 (permanent), 302 (temporary), 307 (temporary preserve method)
- **Match Types** - Exact match, Wildcard (*), Regex patterns
- **Hit Tracking** - Counts how many times each redirect is used
- **Testing** - Built-in redirect testing with status verification
- **External Redirect Scanner** - Detects redirects from Yoast, Redirection, Rank Math, .htaccess
- **Import/Export** - CSV import/export for bulk management
- **Post Integration** - Shows redirect status in post list tables

### Accessing

When the addon is active, access via:
- Dashboard tab: "301 Redirects"
- Command Menu: Type "redirects" or "301"

### Redirect Format

```php
// Storage: option 'starter_redirects_301'
$redirects = [
    [
        'id' => 'abc123',
        'from' => '/old-page',
        'to' => '/new-page',
        'status_code' => '301',
        'match_type' => 'exact',  // exact|wildcard|regex
        'enabled' => true,
        'hits' => 42,
        'created' => '2024-01-01 12:00:00',
        'last_hit' => '2024-01-15 08:30:00',
        'note' => 'Migrated from old site',
    ],
];
```

### Match Types

| Type | Pattern Example | Matches |
|------|-----------------|---------|
| Exact | `/old-page` | Only `/old-page` |
| Wildcard | `/blog/*` | `/blog/post-1`, `/blog/post-2`, etc. |
| Regex | `/products/([0-9]+)` | `/products/123`, with capture groups |

### Regex Capture Groups

For regex redirects, capture groups can be used in the destination:

```
From: /products/([0-9]+)/(.*)
To: /shop/$1/item/$2
```

### Testing Redirects

Each redirect can be tested individually:
- **Exact match** - Click ↻ to test immediately
- **Wildcard/Regex** - Opens modal to enter test URL

Test results show:
- ✓ Working (green) - Redirect works as expected
- ≠ Mismatch (orange) - Redirects but to wrong destination
- ✗ Error (red) - No redirect or server error

### AJAX Endpoints

| Action | Description |
|--------|-------------|
| `starter_redirects_get` | Get all redirects |
| `starter_redirects_save` | Save/update redirect |
| `starter_redirects_delete` | Delete redirect |
| `starter_redirects_test_url` | Test a URL for redirects |
| `starter_redirects_import` | Import from CSV |
| `starter_redirects_export` | Export to CSV |
| `starter_redirects_scan_external` | Scan for external redirects |

### Import CSV Format

```csv
/old-url,/new-url,Optional note
/another-old,https://external.com/page,External redirect
```

---

## Icons (ezicons)

The plugin uses ezicons for all iconography.

### SDK Integration

```php
public function add_ezicons_sdk() {
    ?>
    <script
        src="https://ezicons.com/sdk.js"
        data-key="YOUR_KEY"
        data-global-inline="true">
    </script>
    <?php
}
```

### Usage

```html
<easier-icon
    name="settings-05"
    variant="twotone"
    size="20"
    stroke-color="#1C3C8B"
    color="#1C3C8B">
</easier-icon>
```

### Helper Function

```php
public static function render_icon($dashicon, $size = 18, $color = 'currentColor') {
    $ezicon = self::dashicon_to_ezicon($dashicon);

    if ($color === null) {
        // Let CSS control color via variables
        return sprintf(
            '<easier-icon name="%s" variant="twotone" size="%d"></easier-icon>',
            esc_attr($ezicon),
            (int) $size
        );
    }

    return sprintf(
        '<easier-icon name="%s" variant="twotone" size="%d" stroke-color="%s" color="%s"></easier-icon>',
        esc_attr($ezicon),
        (int) $size,
        esc_attr($color),
        esc_attr($color)
    );
}
```

### CSS Variables for Icons

```css
easier-icon {
    --ei-stroke-color: var(--card-color, #1C3C8B);
    --ei-color: var(--card-color, #1C3C8B);
}
```

---

## AJAX Endpoints

All endpoints require appropriate nonces and capabilities.

| Action | Nonce | Capability | Description |
|--------|-------|------------|-------------|
| `starter_save_tile_order` | `starter_dashboard_nonce` | `read` | Save dashboard tile order |
| `starter_save_menu_settings` | `starter_settings_nonce` | `manage_options` | Save menu visibility |
| `starter_save_role_caps` | `starter_settings_nonce` | `manage_options` | Save role capabilities |
| `starter_create_role` | `starter_settings_nonce` | `manage_options` | Create new role |
| `starter_delete_role` | `starter_settings_nonce` | `manage_options` | Delete custom role |
| `starter_save_cpt_visibility` | `starter_settings_nonce` | `manage_options` | Save CPT visibility |
| `starter_save_admin_menu_order` | `starter_settings_nonce` | `manage_options` | Save menu order |
| `starter_reset_admin_menu_order` | `starter_settings_nonce` | `manage_options` | Reset menu order |
| `starter_save_visual_settings` | `starter_settings_nonce` | `manage_options` | Save visual settings |
| `starter_export_settings` | `starter_settings_nonce` | `manage_options` | Export settings JSON |
| `starter_import_settings` | `starter_settings_nonce` | `manage_options` | Import settings JSON |
| `starter_toggle_addon` | `starter_settings_nonce` | `manage_options` | Activate/deactivate addon |
| `starter_get_addon_settings` | `starter_settings_nonce` | `manage_options` | Get addon settings HTML |
| `starter_save_addon_settings` | `starter_settings_nonce` | `manage_options` | Save addon settings |

---

## Hooks and Filters

### Actions

```php
// Before dashboard renders
do_action('starter_dashboard_before_render');

// After dashboard renders
do_action('starter_dashboard_after_render');
```

### Filters

```php
// Modify post types shown in dashboard
$types = apply_filters('starter_dashboard_post_types', $types);

// Modify quick actions
$actions = apply_filters('starter_dashboard_quick_actions', $actions);

// Addon settings save (per addon)
$saved = apply_filters('starter_addon_save_settings_{addon_id}', false, $settings);
```

---

## Database Options

| Option Name | Type | Description |
|-------------|------|-------------|
| `starter_hidden_menus` | array | Hidden menu items per role |
| `starter_hidden_cpts` | array | Hidden CPTs per role |
| `starter_admin_menu_order` | array | Admin menu ordering |
| `starter_visual_settings` | array | Branding settings |
| `starter_additional_elements_users` | array | User IDs with Additional Elements access |
| `starter_additional_elements_items` | array | Items in Additional Elements |
| `starter_active_addons` | array | List of active addon IDs |

### User Meta

| Meta Key | Type | Description |
|----------|------|-------------|
| `starter_dashboard_tile_order` | array | User's tile ordering preference |
| `starter_additional_elements_order` | array | User's Additional Elements ordering |

---

## File Structure Reference

```
starter-dashboard/
├── starter-dashboard.php      # Main plugin (5000+ lines)
├── dashboard.css              # Dashboard styles
├── dashboard.js               # Dashboard JS (tabs, sorting, AJAX)
├── settings.css               # Settings page styles
├── settings.js                # Settings JS (tabs, forms, AJAX)
├── sortable.min.js            # SortableJS library
├── addons/
│   ├── class-addon-loader.php # Addon management system
│   ├── hubspot-forms/
│   │   ├── addon.php          # HubSpot integration
│   │   └── class-hubspot-action.php
│   ├── og-image/
│   │   └── addon.php          # Open Graph meta
│   ├── post-type-pill/
│   │   ├── addon.php          # Main addon file
│   │   └── widget.php         # Elementor widget
│   ├── vertical-menu/
│   │   └── addon.php          # Menu shortcode
│   ├── elementor-csv-options/
│   │   └── addon.php          # Form CSV options
│   ├── elementor-phone-field/
│   │   ├── addon.php          # Phone field
│   │   └── field-phone-intl.php
│   ├── elementor-styled-checkboxes/
│   │   └── addon.php          # Styled checkboxes
│   ├── redirects-301/
│   │   └── addon.php          # 301 Redirects manager
│   ├── reading-time/
│   │   └── addon.php          # Reading time shortcode
│   └── adaptive-gallery/
│       └── addon.php          # Adaptive gallery widget
├── command-menu/              # React Command Menu app
│   ├── src/
│   ├── dist/
│   └── package.json
├── lib/
│   ├── Parsedown.php          # Markdown parser
│   └── plugin-update-checker/ # GitHub updates
└── docs/
    ├── README.md              # This file
    └── ADDONS.md              # Addon development guide
```

---

## GitHub Updates

The plugin supports automatic updates from GitHub releases using Plugin Update Checker.

### Configuration

In `starter-dashboard.php`:

```php
$starter_dashboard_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/YOUR_USERNAME/starter-dashboard/',
    __FILE__,
    'starter-dashboard'
);

$starter_dashboard_update_checker->setBranch('main');
$starter_dashboard_update_checker->getVcsApi()->enableReleaseAssets();
```

### Creating Releases

1. Update version in `starter-dashboard.php` and `readme.txt`
2. Build distribution: `./build-dist.sh`
3. Create git tag: `git tag v4.1.2`
4. Push with tags: `git push origin main --tags`
5. Create GitHub Release with ZIP attached

### Automated Release Script

Use `./release.sh` for automated releases:

```bash
./release.sh 4.1.2
```

The script:
- Updates version numbers
- Builds Command Menu React app
- Creates distribution ZIP
- Commits and tags
- Pushes to GitHub
- Creates GitHub Release with ZIP attached
