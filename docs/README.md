# Starter Dashboard - Documentation

Version: 3.9.2

## Overview

Starter Dashboard is a comprehensive WordPress admin plugin that replaces the default WordPress dashboard with a customizable, branded hub. It provides post type management tiles, user role editing, menu visibility control, custom post type visibility settings, and a modular addon system.

---

## Table of Contents

1. [Installation](#installation)
2. [Architecture](#architecture)
3. [Main Dashboard](#main-dashboard)
4. [Settings Page](#settings-page)
5. [Role Editor](#role-editor)
6. [Menu Visibility Control](#menu-visibility-control)
7. [Admin Menu Order](#admin-menu-order)
8. [CPT Visibility](#cpt-visibility)
9. [Visual Settings](#visual-settings)
10. [Content Versioning](#content-versioning)
11. [Import/Export](#importexport)
12. [Addon System](#addon-system)
13. [Icons (ezicons)](#icons-ezicons)
14. [AJAX Endpoints](#ajax-endpoints)
15. [Hooks and Filters](#hooks-and-filters)
16. [Database Options](#database-options)

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
│   └── elementor-styled-checkboxes/
│       └── addon.php          # Styled checkboxes
└── docs/
    ├── README.md              # This file
    └── ADDONS.md              # Addon development guide
```
