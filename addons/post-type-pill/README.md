# Post Type Pill

Elementor widget and shortcode that displays a styled pill/badge showing the current post type name. Useful for indicating content type in post listings, archives, or single post templates.

## Features

- Elementor widget with full styling controls
- Shortcode for use anywhere in WordPress
- Customizable label mappings per post type
- Automatic post type detection
- Horizontal/vertical alignment options
- Typography and color controls

## Elementor Widget

Add the "Post Type Pill" widget from the Starter Utils category. Available settings:

### Content Tab

- **Custom Labels**: Override default post type labels (e.g., show "Article" instead of "Post")
- **Text Alignment**: Left, center, or right

### Style Tab

- **Typography**: Font family, size, weight, transform, line height
- **Text Color**: Normal and hover states
- **Background Color**: Normal and hover states
- **Border**: Type, width, color, radius
- **Padding**: Inner spacing
- **Box Shadow**: Optional shadow effect

## Shortcode Usage

```
[post_type_pill]
```

### Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `post_id` | Current post | Specific post ID to get type from |
| `class` | `post-type-pill` | CSS class for styling |

### Examples

```
[post_type_pill]
[post_type_pill post_id="123"]
[post_type_pill class="my-custom-pill"]
```

## Default Label Mappings

The addon includes sensible defaults that can be overridden:

- `post` → "Article"
- `page` → "Page"
- `subscribers` → "Job Alert"
- `elementor_library` → "Template"

Other post types use their registered singular name.

## CSS Classes

- `.bp-post-type-pill` - Widget wrapper class
- `.post-type-pill` - Shortcode output class
- `[data-post-type="post"]` - Data attribute for type-specific styling

## Example: Type-Specific Colors

```css
.post-type-pill[data-post-type="post"] {
    background-color: #3b82f6;
}
.post-type-pill[data-post-type="page"] {
    background-color: #10b981;
}
.post-type-pill[data-post-type="product"] {
    background-color: #f59e0b;
}
```
