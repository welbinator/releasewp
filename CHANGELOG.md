# Changelog

All notable changes to ReleaseWP are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.2.0] - 2026-06-07

### Added
- GitHub Releases auto-updater: WordPress admin will now notify you of new ReleaseWP versions and allow one-click updates, with SHA-256 integrity verification before installation.
- Automated release workflow: tag-push triggered GitHub Actions workflow with PHPCS + PHPStan quality gates, SHA-256 checksum generation, and automated GitHub Release creation.
- Admin Setup Guide: tabbed settings page with step-by-step instructions for connecting a GitHub repository to WordPress.
- Generate Secret button: one-click server-side webhook secret generation and automatic storage.
- `CHANGELOG.md`.

### Security
- **S-001** — Added HMAC-SHA256 webhook signature verification. The REST endpoint now requires a valid `X-Hub-Signature-256` header signed with the configured webhook secret. Requests without a valid signature are rejected with 403.
- **A-001** — Fixed Contributor privilege escalation. Added explicit `publish_posts` capability check in the REST handler so WordPress Contributors cannot publish posts via the endpoint.
- **S-002 / DEP-01** — Parsedown safe mode enabled. `setSafeMode(true)` and `setMarkupEscaped(true)` now enforced, preventing raw HTML and `javascript:` URIs in webhook payloads from bypassing the parser (CVE-2021-39424 mitigation).
- **S-003** — REST route parameter validation added. `title` and `content` now have `type`, `required`, `validate_callback`, and `sanitize_callback` defined on the route.
- **S-004** — Post type validated at runtime. The `releasewp_post_type` option is now checked against registered public post types before use; invalid values fall back to `post`.
- **C-001** — Fixed dead error check. `wp_insert_post()` now called with `$wp_error = true` so `WP_Error` is returned and handled on failure rather than silently returning 0.
- **C-002** — Settings sanitization hardened. All `register_setting()` calls now declare `type`, `capability`, and `sanitize_callback`.
- **C-003** — Server-side error logging added. `wp_insert_post()` failures are now logged via `error_log()` for operational visibility.

## [1.1.0]

### Added
- Title template setting: use the `%version%` placeholder to customise the post title generated from each GitHub release tag.

## [1.0.0]

### Added
- REST API endpoint (`POST /wp-json/releasewp/v1/post-update`) for receiving GitHub release webhook payloads.
- WordPress post creation from release title and notes (markdown rendered via Parsedown).
- Admin settings page for configuring the target post type.
