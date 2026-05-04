# CLAUDE.md - Bunny.net Offload by Gelform

## Overview
WordPress plugin for image optimization and CDN offloading using Bunny.net with OAuth-style authorization.

## Architecture

### Key Files
- `bunny-net-offload-gelform.php` - Main plugin file, version constants
- `includes/class-admin.php` - Admin UI, settings page, AJAX handlers
- `includes/class-media-handler.php` - Sync queue, attachment processing
- `includes/class-image-processor.php` - Resize/compress, PNG-to-JPEG conversion, transparency detection
- `includes/class-bunny-storage.php` - CDN upload/download operations
- `includes/class-github-updater.php` - GitHub-based plugin updates
- `includes/class-url-rewriter.php` - Rewrites media URLs to CDN
- `assets/admin.js` - Admin JavaScript for settings UI
- `tests/` - PHPUnit tests and UIlicious E2E tests

### Settings Storage
- Plugin config stored in `bnog_config` option
- Sync queue stored in `bnog_sync_queue` option
- Sync status stored in `bnog_sync_status` option

### Key Settings
- `sync_all_files` - When enabled, syncs all file types; when disabled, only images (`image/*`)
- `keep_local_files` - Whether to keep files locally after CDN upload
- `auto_updates` - Custom auto-update toggle (WordPress native doesn't work for GitHub plugins)

## Testing

- **PHPUnit tests**: `tests/test-*.php` — run with `phpunit` from plugin root
- **UIlicious E2E tests**: `tests/uilicious/*.test.js` — run via UIlicious dashboard
- **Testing guide**: See `TESTING.md` for setup, manual checklists, and CI info

## Recent Changes (v1.0.12 - v1.0.21)

### v1.0.21 - Refresh button preserves active tab
- The `<a class="bnog-refresh-btn">` link is server-rendered and its href is fixed at page-load time, so it didn't reflect tab changes made client-side via `history.replaceState`.
- Added a JS click handler that calls `e.preventDefault()` then `window.location.reload()`, which reloads whatever URL is currently in the address bar (preserving the active tab).
- Bound via delegation on `document` so it covers all `.bnog-refresh-btn` instances (Status panel and the Bulk Processing panel).

### v1.0.20 - Auto-throttled batch loop in process_queue
- `process_queue` now runs batches back-to-back within a single cron tick until either the queue empties, the sync is stopped, or PHP's max execution time is approaching.
- Added private `get_time_budget()` helper: uses 75% of `ini_get('max_execution_time')` (clamped 5-50s); falls back to 50s when no limit is set.
- Throughput on fast hosts is now bound by upload speed instead of the 60s cron interval; slow hosts still finish each batch safely without timeout.
- Logs include batches-run and elapsed time on completion / budget exit for easier diagnosis.

### v1.0.19 - Fix path validation rejecting all uploads when realpath() fails
- Path validation in `class-bunny-storage.php` no longer requires `realpath()` to succeed
- Compares both raw and resolved file paths against both raw and resolved bases (uploads + content)
- Fixes hosts where `open_basedir` or symlink layouts cause `realpath()` to return false for valid files
- Adds debug log line when a path is rejected so future failures are easier to diagnose

### v1.0.18 - PNG-to-JPEG conversion, Copilot review fixes
- PNG-to-JPEG conversion for non-transparent PNGs (uses chunk-stream tRNS detection)
- Safe postmeta updates — PHP-based `maybe_unserialize()`/`maybe_serialize()` instead of SQL `REPLACE()`
- Attachment metadata `file` field updated during PNG-to-JPEG conversion (fixes srcset)
- Informational `error_log()` calls gated behind `WP_DEBUG`
- Stop button preserves Dashicon icon (`.html()` instead of `.text()`)
- Added PHPUnit test infrastructure and UIlicious E2E tests

### v1.0.17 - Simplify file type validation
- Removed redundant file type validation from `upload()` function
- Deleted `is_allowed_file_type()` function entirely
- Trust WordPress media library validation (files validated at upload time)
- File type filtering handled by sync queue builder based on `sync_all_files` setting

### v1.0.16 - Fix SVG sync (superseded by v1.0.17)
- Added `mime_content_type()` fallback for MIME detection
- Allowed all `image/*` types including SVGs

### v1.0.15 - Fix perpetual "1 waiting to sync"
- When attachment file is missing from disk, mark as synced with `_bnog_sync_skipped` meta
- Prevents files that can't be synced from showing forever in queue

### v1.0.14 - Persist tab selection in URL
- Tab selection now updates URL with `?tab=` parameter
- Loading page with `?tab=settings` opens directly to Settings tab
- Added `initTabFromUrl()` function in admin.js

### v1.0.13 - Fix auto_updates setting not saved
- Added `auto_updates` to AJAX data in `handleSaveSettings()`

### v1.0.12 - Add auto-updates toggle in plugin settings
- WordPress native auto-update toggle doesn't work for GitHub-hosted plugins
- Added "Updates" section in Settings tab with custom checkbox
- `auto_update_plugin` filter checks `$config['auto_updates']` setting
- Removed `no_update` transient population (not needed with custom toggle)

## Sync Queue Flow
1. User clicks "Start Sync" or new media uploaded
2. `class-media-handler.php` queries unsynced attachments:
   - If `sync_all_files`: all attachments without `_bnog_synced` meta
   - Otherwise: only `post_mime_type LIKE 'image/%'`
3. Attachments added to `bnog_sync_queue` option
4. Cron job `bnog_process_queue` processes in batches of 10
5. Each attachment: upload to Bunny CDN, set `_bnog_synced` meta

## Release Process

1. Bump version in `bunny-net-offload-gelform.php` — both `Version:` header (line 6) and `BNOG_VERSION` constant (line 26)
2. Commit and push to `main`
3. Tag with `v` prefix: `git tag v1.0.XX && git push origin v1.0.XX`
4. GitHub Actions (`.github/workflows/release.yml`) automatically:
   - Creates a zip excluding dev files (tests, CLAUDE.md, phpunit.xml, TESTING.md, etc.)
   - Publishes as a GitHub Release with auto-generated release notes
5. WordPress sites with the plugin installed pick up the update via `BNOG_GitHub_Updater`:
   - Checks GitHub API every 12 hours for new releases
   - Compares `tag_name` against `BNOG_VERSION`
   - If `auto_updates` is enabled in plugin settings, updates automatically
   - Otherwise shows in WP Dashboard → Updates
