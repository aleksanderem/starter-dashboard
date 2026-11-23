# Front Speed

Improves Speed Index, FCP and LCP for visitors who are not logged in by deferring JavaScript and loading non-critical stylesheets without blocking rendering. Nothing is stored in the database except the selected mode.

## Modes

Set in the addon settings (Addons → Front Speed → Settings), option `starter_front_speed_mode`:

- `off`: nothing happens.
- `test` (default): only on URLs with `?mwtperf=1`, e.g. `https://example.com/?mwtperf=1`. Open it in a private window, since logged-in users are always skipped.
- `on`: every page view of a logged-out visitor.

Override in code: `add_filter('mwts_front_speed_mode', fn() => 'off');`

Always skipped: logged-in users, admin, AJAX, REST, cron, feeds, previews, the Elementor editor/preview (`elementor-preview`, `preview`, `elementor_library`), the Customizer, non-HTML responses, WooCommerce cart and checkout.

## JavaScript

External scripts get `defer`. They stay synchronous when the handle is on the exclude list (jQuery, wp-i18n, wp-hooks, WP-Optimize, Google tag, consent API, `cmplz-*`, `wpml-cookie*`; extend with `mwts_front_speed_js_exclude`), has inline code added after it (`wp_add_inline_script(..., 'after')`), or is a dependency (direct or indirect) of a script that stays synchronous. Scripts that are already `async`, `defer` or `type="module"`, and inline scripts, are left alone. The set is computed in memory from the registered scripts, once for the head and once more before footer scripts print.

## CSS

Listed stylesheets load as `media="print"` and switch to their original media when loaded, with a `<noscript>` fallback. Default list (handles without `-css`): intl-tel-input, jet-engine-frontend, init-plugin-suite-live-search-style, magic-card-style, mwt-wyj-filters, swiper, e-swiper, ui-e-carousel, ui-e-post-meta, ui-e-legacy-grid, e-animation-fadeInUp, lenis, dce-animations, cmplz-general. Change it with `mwts_front_speed_css_async`. Layout-critical styles (`ep-styles`, `bdt-uikit`, `uicore-*`, `elementor-*`, `widget-*`, anything with `remix` or `themify`) are never made async, even if added by the filter.

Also: `dashicons` is dropped when no queued stylesheet (other than the admin bar) depends on it, and `<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>` is added to the head.

## Rolling out

1. Keep `test`, open pages with `?mwtperf=1` in a private window, check the browser console for errors and the layout of menus, sliders, forms and the cookie banner.
2. Compare PageSpeed Insights for the URL with and without `?mwtperf=1`.
3. Switch to `on`, then purge the page cache.
