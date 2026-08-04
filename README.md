# BLT Popups

Lightweight, single-purpose image popups for WordPress — scheduled, targeted, and cache-safe. One popup is live site-wide at a time; unlimited popups can be saved for reuse.

Part of the **BLT** family of plugins by S-FX.com Small Business Solutions.

## Features

- Single custom post type (`blt_popup`); each popup is a reusable post.
- Only one popup live at a time — a quick toggle switch on the popup list (backed by WordPress's native Publish/Draft status) activates one and deactivates any other.
- Modal lightbox with configurable overlay dimming (color + opacity).
- Close via the "×", click-outside, or the Esc key; focus-trapped for keyboard users.
- Scheduling by date range and daily time window (site timezone).
- Targeting: all pages, homepage only, specific pages, specific post types, or URL pattern (contains / starts-with).
- Frequency capping: every load, once per session, once per day, once every N days, or once ever.
- WordPress Media Library image picker; optional call-to-action button.
- **Cache-safe by design:** eligibility is resolved through an uncached REST endpoint, so a popup with an elapsed end date never lingers on cached HTML (Cloudflare "cache everything" friendly).
- In-admin live preview plus a nonce-gated front-end preview URL.
- Lightweight impression/click counters (admins excluded).
- No jQuery, no page-builder dependency, no build step.

## Installation

1. Install the zip from **Plugins → Add New → Upload Plugin**, or copy the `blt-popups` folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Go to **BLT Popups → Add New**, configure the image, destination, schedule, targeting, and frequency, then click **Publish** to make it live — or flip the toggle on any saved popup from the **All Popups** list.

## Automatic updates

Updates are delivered from this repository's GitHub Releases via the bundled [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). While the repository is public no credentials are needed; for a private repository, define `BLT_POPUPS_GITHUB_TOKEN` in `wp-config.php`.

## Releases

Every push to `main` runs `.github/workflows/release.yml`, which publishes a GitHub Release with an installable `blt-popups-<version>.zip`. The current version in `blt-popups.php` is released as-is if it has no release yet; otherwise the patch version is bumped automatically (use `#minor` or `#major` in a commit message to bump higher).

## License

GPL-2.0-or-later.
