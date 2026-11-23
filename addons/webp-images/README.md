# WebP Images

Serves PNG and JPEG files from the uploads directory as WebP on the front end, without external services.

## How it works

1. While a page is rendered for a logged-out visitor, the addon looks at `<img>` and `<source>` tags, in the attributes `src`, `srcset`, `data-src`, `data-srcset` and `data-lazy-src`.
2. For each upload URL ending in `.png`, `.jpg` or `.jpeg` it checks whether a WebP copy exists next to the file: `2025/07/foo.png` → `2025/07/foo.png.webp`. The `.webp` is appended to the full name, so it never clashes with existing files.
3. Copy exists → the URL in the page points to it. In `srcset` each candidate is handled separately and its descriptor (`300w`, `2x`) stays.
4. Copy missing → the page keeps the original, and the file goes into a queue (option `mwts_webp_queue`, max 200 entries). WP-Cron (`mwts_webp_generate`) starts 30 seconds later.

Page views do nothing with images except add them to the queue.

## Background generation

Each run converts up to 10 files. If the queue is not empty afterwards, the next run is scheduled 60 seconds later.

- Only files inside the uploads directory, only PNG/JPEG.
- Files under 30 KB are skipped (the gain is too small).
- Wider than 1920 px → scaled down to 1920 px wide, proportions kept.
- Quality 82.
- If the WebP is not smaller than the original, it is deleted.

Skipped files, files that would get bigger and editor errors go to the option `mwts_webp_skip` and are never tried again. To retry them, delete that option.

If the server's image library cannot write WebP (GD/Imagick without WebP), nothing is generated and the queue stays.

## Not handled

- URLs with `?ver=…`, `#…` or HTML entities, files outside uploads, other domains (CDN).
- Already WebP, SVG and GIF.
- CSS backgrounds (`background-image`), `<a href>` links to images.
- Content of `<script>`, `<style>`, `<noscript>` and HTML comments.
- Admin, AJAX, REST, cron, feeds, previews, the Elementor editor, logged-in users, non-HTML responses and pages over 3 MB.

## Page cache

Generating a file does not clear the page cache. Pages cached before the WebP existed keep the PNG/JPEG until the cache is cleared by hand.

## On by default / switching off

The addon is on after install or update and can be switched off in the Addons panel. In code:

```php
add_filter('mwts_webp_images_enabled', '__return_false');
```

The filter also stops background generation. Generated `.webp` files stay on disk; removing the addon does not delete them.

## Check

Private window → DevTools → Network → Img: after a page view, wait about a minute, clear the page cache and reload. Images from uploads should be `….png.webp` with type `webp`.
