=== Starter Dashboard ===
Contributors: developer
Tags: dashboard, admin, command menu, custom post types, role editor
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 4.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom admin dashboard with command menu, post type tiles, menu visibility control, role editor, CPT management, and addon system.

== Description ==

Starter Dashboard is a comprehensive WordPress admin enhancement plugin that provides:

= Command Menu (Cmd+K / Ctrl+K) =
* Quick access to all admin pages
* Create new content instantly
* Navigate to settings, tools, and more
* Keyboard-driven workflow

= Dashboard Tiles =
* Visual post type overview
* Quick links to content management
* Customizable tile layout

= Menu Control =
* Hide/show admin menu items per role
* Organize admin navigation
* Role-based menu visibility

= Role Editor =
* Create custom user roles
* Manage capabilities
* Clone existing roles

= Custom Post Types =
* Register custom post types via UI
* Configure labels, supports, and visibility
* No coding required

= Addon System =
* Modular addon architecture
* Easy to extend functionality
* Includes several built-in addons

== Installation ==

1. Upload the `starter-dashboard` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Access the dashboard from 'Dashboard' menu in admin

== Frequently Asked Questions ==

= How do I open the command menu? =

Press Cmd+K (Mac) or Ctrl+K (Windows/Linux) anywhere in the WordPress admin.

= Can I customize which commands appear? =

The command menu automatically includes all registered admin pages, recent posts, and quick actions based on your installed plugins.

== Changelog ==

= 4.3.1 =
* FIXED: Menu Visibility - hidden menu items now appear in settings so they can be unhidden again
* FIXED: Modal fallback - if modal fails to open or AJAX errors, falls back to direct page navigation
* FIXED: Iframe modal fallback for Elementor edit links and quick actions

= 4.1.6 =
* FIXED: HubSpot Forms - select fields now send option labels (e.g. "Connecticut - CT") instead of just values (e.g. "CT")
* IMPROVED: Better data quality in HubSpot submissions for dropdown/select fields

= 4.1.5 =
* HOTFIX: HubSpot Forms - fixed "server error" on form submissions caused by missing context object in API payload
* FIXED: HubSpot API now always receives context object as required by API specification

= 4.1.4 =
* FIXED: HubSpot Forms - submissions log now always shows page URL and title, regardless of "Send Page Context" setting
* IMPROVED: "Send Page Context" setting now only controls whether context is sent to HubSpot API, not whether it's logged

= 4.1.3 =
* FIXED: Elementor Phone Field - submission timing issue resolved using event capture phase
* FIXED: HubSpot Forms - improved error logging with field-level validation details
* NEW: HubSpot Forms - independent debug mode toggle in addon settings
* IMPROVED: HubSpot Forms - enhanced submissions log with expandable debug details (fields, request payload, API response)
* IMPROVED: Error messages now show specific field validation errors from HubSpot API
* IMPROVED: Debug mode works independently of WP_DEBUG setting

= 4.1.2 =
* IMPROVED: 301 Redirects - added retest button for all redirect types (not just regex/wildcard)
* IMPROVED: 301 Redirects - home badge with ⌂ character for cleaner visual
* IMPROVED: Exact match redirects now test directly without opening modal
* UPDATED: Documentation with Command Menu, Whitelabel, and 301 Redirects guides

= 4.1.1 =
* IMPROVED: 301 Redirects - cleaner UI with home icon instead of full site URL in redirect paths
* ADDED: Automated release script for easier version management

= 4.1.0 =
* NEW: Hub section in Command Menu with quick access to dashboard, settings, and addons
* NEW: Active addon shortcuts in command palette (301 Redirects, Social Preview, HubSpot)
* IMPROVED: 301 Redirects - test results saved with timestamps and tooltips
* IMPROVED: 301 Redirects - regex/wildcard test modal with live preview
* FIXED: HubSpot Forms - removed redundant form name badges from Elementor Mappings tab

= 4.0.0 =
* NEW: Command Menu (Cmd+K) for quick navigation
* NEW: Nested command categories (Create, Settings, Tools, Site, WooCommerce)
* NEW: Recent posts quick access
* NEW: Keyboard shortcut hint with animation
* Improved admin experience

= 3.9.2 =
* Bug fixes and improvements

== Upgrade Notice ==

= 4.0.0 =
Major update with new Command Menu feature. Press Cmd+K to try it!
