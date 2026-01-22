# Vertical Menu

Shortcode that displays WordPress navigation menus in a vertical accordion format. Automatically integrates with Elementor's primary color for consistent styling.

## Features

- Vertical accordion menu layout
- Automatic submenu expand/collapse
- Elementor color integration
- Multiple animation options
- Accordion or independent mode
- Current page highlighting
- Keyboard navigation support
- Mobile responsive

## Shortcode Usage

```
[vertical_menu]
[vertical_menu menu="main-menu"]
[vertical_menu menu="footer-nav" animation="fade" accordion="no"]
```

## Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `menu` | Primary menu | Menu name, slug, or ID |
| `show_icons` | `yes` | Show expand/collapse arrows |
| `animation` | `slide` | Animation type: `slide`, `fade`, or `none` |
| `container_class` | (empty) | Additional CSS class for wrapper |
| `depth` | `0` | Menu depth (0 = unlimited) |
| `accordion` | `yes` | Close other items when opening new |
| `submenu_bg` | `#F5F5F5` | Submenu background color |

## Menu Selection

The shortcode finds menus in this order:

1. Specified `menu` parameter (name, slug, or ID)
2. Menu assigned to "primary" location
3. First available menu

## Styling

### CSS Variables

The menu uses CSS custom properties that can be overridden:

```css
.vertical-menu-container {
    --vm-primary-color: #0073aa;  /* From Elementor */
    --vm-submenu-bg: #F5F5F5;     /* From shortcode parameter */
}
```

### Color Integration

The menu automatically reads Elementor's primary color from the active kit. This ensures the active/hover states match your site's design system.

### Custom Styling Example

```css
/* Change menu item padding */
.vertical-menu-container a {
    padding: 15px 20px;
}

/* Custom submenu indent */
.vertical-menu-container .sub-menu a {
    padding-left: 40px !important;
}

/* Different hover color */
.vertical-menu-container a:hover {
    background-color: #your-color;
}
```

## Behavior

### Accordion Mode (default)

When `accordion="yes"`, opening a submenu closes any other open submenus at the same level. This keeps the menu compact.

### Independent Mode

When `accordion="no"`, multiple submenus can be open simultaneously.

### Current Item

The menu automatically:
- Highlights the current page
- Opens parent menus of the current page
- Applies `current-menu-item`, `current-menu-parent`, and `current-menu-ancestor` classes

## Animation Options

### Slide (default)
Submenus slide down smoothly with height animation.

### Fade
Submenus fade in/out without height change.

### None
Instant show/hide with no animation.

## Keyboard Navigation

- **Enter/Space**: Toggle submenu (on items with children)
- **Tab**: Navigate between menu items
- Standard focus indicators included

## Elementor Integration

The shortcode works within Elementor:
- Use in Shortcode widget
- Works in popups and offcanvas
- Reinitializes when needed in editor preview

## Menu Structure Support

Works with WordPress's standard menu structure:
- Top-level items
- Nested submenus (multiple levels)
- Custom CSS classes on items
- Menu item descriptions (as title attribute)
- Custom link targets

## Example: Sidebar Navigation

```
[vertical_menu menu="docs-menu" animation="slide" submenu_bg="#f0f0f0"]
```

## Example: FAQ Accordion

```
[vertical_menu menu="faq-sections" accordion="yes" show_icons="yes"]
```
