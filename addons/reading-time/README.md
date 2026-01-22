# Reading Time

Displays estimated reading time for posts and pages.

## Shortcode

```
[reading_time]
```

## Attributes

| Attribute | Default | Description |
|-----------|---------|-------------|
| `words_per_minute` | 200 | Reading speed (words per minute) |
| `before` | (empty) | Text before the number |
| `after` | " min read" | Text after the number |
| `show_icon` | "true" | Show clock icon ("true" or "false") |
| `post_id` | 0 | Specific post ID (0 = current post) |
| `class` | "reading-time" | CSS class for the wrapper |

## Examples

### Basic usage
```
[reading_time]
```
Output: 🕐 5 min read

### Custom text
```
[reading_time before="Reading time: " after=" minutes"]
```
Output: 🕐 Reading time: 5 minutes

### Without icon
```
[reading_time show_icon="false"]
```
Output: 5 min read

### Faster reading speed
```
[reading_time words_per_minute="250"]
```

### For specific post
```
[reading_time post_id="123"]
```

## PHP Function

You can also get reading time programmatically:

```php
$minutes = Starter_Addon_Reading_Time::get_reading_time($post_id, $words_per_minute);
```

### Parameters
- `$post_id` (int) - Post ID, 0 for current post
- `$words_per_minute` (int) - Reading speed, default 200

### Returns
Integer - estimated reading time in minutes (minimum 1)

## Styling

The shortcode outputs:
```html
<span class="reading-time">
    <svg>...</svg> 5 min read
</span>
```

You can style it with CSS:
```css
.reading-time {
    font-size: 14px;
    color: #666;
}

.reading-time svg {
    width: 16px;
    height: 16px;
}
```
