# Social Preview (OG Image)

Adds Open Graph and Twitter Card meta tags to your WordPress site for better social media sharing previews. Includes both global defaults and per-post customization.

## Features

- Open Graph meta tags (Facebook, LinkedIn, etc.)
- Twitter Card meta tags
- Global default settings
- Per-post/page customization
- Live preview in settings
- Automatic fallbacks (featured image, excerpt)

## Global Settings

Access via Dashboard → Hub → Addons → Social Preview settings:

### Default Settings

- **Site Name**: Override the default site name for OG tags
- **Default Description**: Fallback description when post has no excerpt
- **Default OG Image**: Used when no featured image exists (recommended: 1200x630px)

### Twitter Card Settings

- **Card Type**:
  - Summary Large Image (recommended for most content)
  - Summary (smaller card format)
- **Twitter @username**: Your site's Twitter handle

## Per-Post Settings

Each post and page has a "Social Preview Settings" meta box with:

- **OG Title**: Custom title (defaults to post title)
- **OG Description**: Custom description (defaults to excerpt or content)
- **OG Image**: Custom image with media library selector

## Meta Tags Generated

### Open Graph

```html
<meta property="og:type" content="article">
<meta property="og:title" content="Page Title">
<meta property="og:description" content="Page description...">
<meta property="og:url" content="https://example.com/page/">
<meta property="og:site_name" content="Site Name">
<meta property="og:image" content="https://example.com/image.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
```

### Twitter Card

```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Page Title">
<meta name="twitter:description" content="Page description...">
<meta name="twitter:site" content="@yoursite">
<meta name="twitter:image" content="https://example.com/image.jpg">
```

## Fallback Logic

### Title
1. Custom OG title (if set)
2. Post/page title
3. Site name (for homepage)
4. Archive title (for archives)

### Description
1. Custom OG description (if set)
2. Post excerpt
3. First 30 words of content
4. Default description from settings
5. Site tagline

### Image
1. Custom OG image (if set)
2. Featured image
3. Default OG image from settings

## Image Recommendations

For optimal display across platforms:

- **Dimensions**: 1200 x 630 pixels
- **Aspect Ratio**: 1.91:1
- **Format**: JPG or PNG
- **File Size**: Under 1MB
- **Safe Area**: Keep important content centered (some platforms crop edges)

## Testing Your Tags

Use these tools to verify your meta tags:

- [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/)
- [Twitter Card Validator](https://cards-dev.twitter.com/validator)
- [LinkedIn Post Inspector](https://www.linkedin.com/post-inspector/)
- [OpenGraph.xyz](https://www.opengraph.xyz/)

## Notes

- Tags are only output on the frontend, not in admin
- Custom values override all automatic fallbacks
- Empty custom fields use the fallback chain
- Works with custom post types (posts and pages by default)
