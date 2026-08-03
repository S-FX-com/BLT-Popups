=== BLT Popups ===
Contributors: sfxcom
Tags: popup, lightbox, modal, promotion, scheduling
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, single-purpose image popups: scheduled, targeted, and cache-safe. One active popup site-wide, unlimited saved.

== Description ==

BLT Popups does one thing well — scheduled, targeted image popups — and nothing else. No page-builder dependency, no lightbox library, no jQuery, and no build step. It is built for a managed portfolio of client sites that sit behind Cloudflare and page caches.

**Highlights**

* Single custom post type (`blt_popup`); each popup is a reusable, saveable post.
* Only one popup is live site-wide at a time; activating one deactivates any other.
* Modal lightbox with auto overlay dimming (configurable color + opacity).
* Close via the "X", click-outside, or the Esc key.
* Scheduling by date range and daily time window (site timezone).
* Targeting: all pages, homepage only, specific pages, specific post types, or URL pattern (contains / starts-with).
* Frequency capping: every load, once per session, once per day, once every N days, or once ever (per visitor).
* WordPress Media Library image picker; optional call-to-action button in addition to the clickable image.
* Cache-safe by design: eligibility is resolved through an uncached REST endpoint, so a popup with an elapsed end date never lingers on cached HTML.
* In-admin live preview plus a nonce-gated front-end preview URL — see the popup in real page context before activating.
* Lightweight impression/click counters shown on the popup list.

**Caching model**

The plugin never bakes volatile eligibility into cached HTML. Page targeting (stable per URL) gates whether the tiny front-end script loads at all; the date/time window is re-checked fresh through `GET /wp-json/blt-popups/v1/active`, and per-visitor frequency lives in a cookie. This keeps behaviour correct behind Cloudflare "cache everything" rules.

== Installation ==

1. Upload the `blt-popups` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **BLT Popups → Add New**, configure the image, destination, schedule, targeting, and frequency, then click **Activate**.

Updates are delivered automatically from the plugin's GitHub releases via the bundled update checker.

== Frequently Asked Questions ==

= How many popups can be live at once? =

One. Activating a popup automatically deactivates any other active popup. You can save unlimited drafts/inactive popups for reuse.

= Will it work behind Cloudflare or a page cache? =

Yes. Eligibility is resolved through an uncached REST endpoint plus a per-visitor cookie, so cached HTML never shows a stale popup.

= Does it require jQuery or a page builder? =

No. The front end is dependency-free vanilla JavaScript and loads only when a popup could apply.

== Changelog ==

= 1.1.0 =
* Destination can now be Internal (search-and-select a page, with predictive suggestions as you type) or External (a URL that always opens in a new tab/window).
* Choice of entrance animation: None, Fade, Slide In, or Zoom In.
* Duplicate any popup into a new draft from the list table or its editor.
* Modernized the popup editor: a sticky header, numbered/collapsible settings sections, toggle switches, and sidebar panels for an always-on Live Preview (Desktop/Tablet/Mobile) and a Popup Summary recap.

= 1.0.0 =
* Initial release: scheduled/targeted image popups, single-active enforcement, cache-safe REST delivery, in-admin and front-end preview, frequency capping, and impression/click counters.
