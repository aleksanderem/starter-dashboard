# Complianz Fast Banner

Shows the Complianz cookie banner right away on the first visit.

## Problem

With geoIP switched on, Complianz first asks the server which region the visitor is in (`GET /wp-json/complianz/v1/banner?...`). That request starts the whole of WordPress and takes 2–6 seconds, and the banner only appears after the answer.

`complianz.js` skips this request when the browser's `sessionStorage` already has `cmplz_user_data` with the same `version` and `banner_version` as the page.

## What the addon does

Right before `complianz.js` (inline script on the `cmplz-cookiebanner` handle, after `var complianz = {...}`) it checks whether `sessionStorage.cmplz_user_data` is empty. If it is, it stores:

```json
{"consenttype": "<complianz.consenttype>", "region": "<complianz.region>", "version": "<complianz.version>", "forceEnableStats": false, "banner_version": "<complianz.banner_version>"}
```

All values come from the configuration Complianz already prints on the page. Nothing from the request or the database is added. If the browser blocks `sessionStorage`, nothing happens and Complianz works as before.

## Trade-off

geoIP is effectively off. Every visitor gets the site's default consent type, here the strict EU opt-in banner, also visitors from outside the EU (USA, UK and so on). If a region-specific banner is needed for them, switch this addon off.

## Where it runs

The front end only, only when Complianz has queued `cmplz-cookiebanner`. Not in the admin and not for logged-in users. Data already stored in the session (e.g. after a real geoIP answer) is never overwritten.

## On by default / switching off

The addon is on after install or update and can be switched off in the Addons panel. In code:

```php
add_filter('mwts_cmplz_fast_banner', '__return_false');
```

## After a Complianz update

If a future Complianz version changes the format of `cmplz_user_data` or stops skipping the request, the banner simply loads the old (slow) way. Check: private window, DevTools → Network, filter `complianz/v1/banner` — the request should not appear.
