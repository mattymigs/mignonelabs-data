# Mignone Labs Site 1.0.0

Status: packaged and unit-tested; NOT installed or enabled on the production website as of this commit.

This small WordPress plugin replaces the deployment plan for the unpublished `twentytwentyfour-wpvibe-draft` website helpers. Do not publish that old theme draft. This plugin leaves the active theme, stored page content, and shared header/footer unchanged.

## Features

- Converts the existing `[carryaware_current_release]` output into escaped paragraphs and semantic bullet lists.
- Populates the existing `<div id="ml-release-history-slot"></div>` on `/carryaware-release-notes/` with older entries from `apps/carryawarenj.json`.
- Never promotes an archive entry over Apple's current public release; filters current/future entries and sorts history by numeric version.
- Reads page excerpts and featured images to emit server-rendered descriptions and Open Graph/Twitter tags; skips known SEO plugins to avoid duplicate ownership.
- Does not invent prices, ratings, release dates, Google Play links, or FlockAware availability. Only CarryAware release archives are configured in 1.0.0; metadata can serve other published pages with an excerpt.

## Dependencies and data handling

WordPress 6.5+, PHP 8.0+. Keep the existing **Code Snippets → Mignone Labs Core** entry active. It continues to provide Apple's current-release data with its request-driven six-hour transient. This plugin is an extension, not a replacement for that provider.

The GitHub archive is fetched on the server from a fixed HTTPS address with a six-second timeout, no redirects, and a 512 KiB response cap. Valid history is cached for 15 minutes; failures for five minutes. Last-known-good public release information is retained in non-autoloaded WordPress options and is explicitly labeled when used after a failure. No remote data is executed as code. No account credentials, tracking scripts, analytics, or database backups are included.

Release pages and authorized preview requests request no page caching from LiteSpeed. The previously configured release-page cache exclusion remains in place. The plugin clears LiteSpeed's cache when its public mode changes or it is deactivated; it does not clear the cache on every page view.

## Install and preview

1. Create a ZIP with `mignone-labs-site/` at its root containing this README, `mignone-labs-site.php`, and `includes/website.php`.
2. In WordPress, use Plugins → Add New Plugin → Upload Plugin, select the ZIP, Install Now, then Activate Plugin.
3. Activation defaults to **Preview only**. Public visitor output remains unchanged. Go to Tools → Mignone Labs Site and open **Preview the release page** while logged in as an administrator.
4. Verify the current public version, semantic bullets, earlier release entries, and unchanged site layout. Inspect descriptions and sharing tags in page source. The authorized preview uses a user-bound WordPress nonce and is marked noindex/no-cache.
5. Only after successful preview and explicit owner approval, check **Enable the enhancements for public visitors** and save the mode. Then verify the ordinary public pages.

An authenticated read-only REST verification route is available at `GET /wp-json/mignone-labs-site/v1/preview` for administrators. It renders release/history/metadata and reports the mode, provider status, and active theme. Rendering may warm public-data caches but does not enable live mode or rewrite pages. Anonymous requests are denied.

## Rollback

Clear the public-mode checkbox and save, or deactivate this plugin. Deactivation also clears the enable setting so a later activation starts in preview-only mode. The original release shortcode and pages continue to exist. Cached public release data is retained for recovery. Do not turn on the old draft-theme helper at the same time.

## Testing

From the repository root:

```sh
php -l website/plugins/mignone-labs-site/mignone-labs-site.php
php -l website/plugins/mignone-labs-site/includes/website.php
php website/tests/site-plugin-test.php
```

All 38 deterministic unit tests passed during preparation on September 9, 2026. They cover escaping, formatting, archive validation, caching and outages, metadata selection, administrator/nonce preview restrictions, default preview-only mode, and deactivation. These tests use WordPress API stubs. They are not a WordPress integration test, a physical-iPhone test, a production deployment verification, or a backup-restoration test.

## Backup and source-control boundary

The owner reported that the private downloaded cPanel archive passed `gzip -t` and `tar -tzf`. The archive was not uploaded to this public repository and has not been restored in a test environment. This repository versions the plugin source, tests, release data, and documentation; it is still not a complete WordPress database/files backup or a full page/template export.

Top app follow-up remains CarryAwareNJ issue #10: audit hard-coded/static in-app content immediately after the website work.
