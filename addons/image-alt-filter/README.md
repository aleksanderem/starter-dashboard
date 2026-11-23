# Image Alt Filter

Fills empty or missing `alt` attributes of `<img>` tags on the front end while the page is rendered. Nothing is written to the database; the Media Library stays unchanged.

## Where the text comes from

1. Images with a `wp-image-ID` class: the attachment's Alt Text field, otherwise its title (cleaned). All attachments on a page are loaded with one query.
2. Other images: the file name from `src` (or `data-src` for lazy-loaded images), without extension, `-300x200` size suffixes, hashes and counters.
3. Nothing meaningful left (e.g. `Group-8-1.png`, `IMG_1234.jpg`): the site name.

Cleaning removes prefixes like `PL26_`, `EN_`, `CS26_`, turns `_` and `-` into spaces, drops `1200x1200`, `IMG_1234`, trailing counters (`-01`, `-1`), and filler words (group, vector, shape, frame, icon, untitled, image…) when they are the only content. First letter upper-case, max 125 characters.

A non-empty `alt` is never changed.

## Decorative images

Images with `aria-hidden="true"`, `role="presentation"`, the `data-mwts-decorative` attribute, or SVG files inside an element whose class contains `icon` (e.g. Elementor icon boxes) get `alt=""`.

## Lazy-load placeholders (layout shift fix)

Lazy-load plugins such as WP-Optimize put a 1x1 GIF into `src` and the real file into `data-src`. The browser then reserves a square box and the page jumps when the real image loads. When an `<img>` has a 1x1 GIF `src`, a `data-src` or `data-lazy-src`, and numeric `width` and `height`, the `src` is swapped for an empty SVG with the same size, so the right space is reserved from the start. Nothing else in the tag changes. Disable with:

```php
add_filter('mwts_image_fix_lazy_placeholder', '__return_false');
```

## On by default

The addon is on after install or update. Switching it off in the Addons panel is remembered (option `starter_disabled_addons`); switching it on again removes that entry.

## Where it does not run

Admin, AJAX, REST, cron, feeds, previews, the Elementor editor/preview, non-HTML responses and pages over 3 MB. Content of `<script>`, `<style>`, `<noscript>` and HTML comments is never modified.

## Override

```php
add_filter('mwts_image_alt_text', function ($alt, $img_tag, $attachment_id) {
    return $alt;
}, 10, 3);
```

`$attachment_id` is `0` when the image has no `wp-image-ID` class.
