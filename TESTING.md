# Testing Guide — Bunny.net Offload by Gelform

## Test Infrastructure

### PHPUnit Tests

Located in `tests/` directory. Requires WordPress test suite.

#### Setup

```bash
# Install WordPress test suite (run once)
# Download the installer script if not present:
mkdir -p tests/bin
curl -o tests/bin/install-wp-tests.sh https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/install.sh
chmod +x tests/bin/install-wp-tests.sh

bash tests/bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run all tests
cd wp-content/plugins/bunny-net-offload-gelform
phpunit

# Run specific test class
phpunit --filter BNOG_Test_Image_Processor

# Run specific test method
phpunit --filter test_png_transparency_detection_opaque
```

#### Test Classes

| File | Class | What It Tests |
|---|---|---|
| `tests/test-image-processor.php` | `BNOG_Test_Image_Processor` | PNG transparency detection, image processing errors, MIME type detection |
| `tests/test-media-handler.php` | `BNOG_Test_Media_Handler` | Queue management, content reference updates, metadata updates |
| `tests/test-url-rewriter.php` | `BNOG_Test_URL_Rewriter` | CDN URL rewriting, Beaver Builder exclusions, srcset support |

### UIlicious Tests

End-to-end UI tests located in `tests/uilicious/`. These test the admin settings page in a real browser.

#### Test Files

| File | What It Tests |
|---|---|
| `tests/uilicious/connect-flow.test.js` | OAuth connect button, disconnect flow |
| `tests/uilicious/settings-page.test.js` | Settings save/load, tab navigation, form validation |
| `tests/uilicious/bulk-sync.test.js` | Bulk sync start/stop, progress display, error handling |

#### Running UIlicious Tests

1. Upload test files to UIlicious dashboard
2. Configure test variables: `SITE_URL`, `WP_USER`, `WP_PASS`
3. Run from UIlicious dashboard or CLI

## Manual Testing Checklist

### v1.0.19 Changes

- [ ] **Path Validation Tolerates `realpath()` Failure**
  - Sync existing media on a host with `open_basedir` configured (e.g. wpwiki.org) → verify queue drains without `invalid_path` errors
  - `wp option get bnog_sync_status` should show `errors:0` and `running:false` after queue completes
  - Sync on a normal host (no `open_basedir`) → verify still works (regression check)
  - With `WP_DEBUG` enabled (the `Path rejected:` log line goes through `bunny_net_offload_gelform()->log()`, which is gated on `WP_DEBUG`), feed a path outside `wp-content/` → verify `[Bunny.net Offload][ERROR] Path rejected: ...` lands in `debug.log`

### v1.0.18 Changes

- [ ] **PNG-to-JPEG Conversion**
  - Upload a PNG without transparency → verify it converts to JPEG
  - Upload a PNG with transparency → verify it stays as PNG
  - Verify srcset URLs reference the new .jpg filename
  - Verify post content references are updated from .png to .jpg

- [ ] **Settings Postback**
  - Change settings and save → verify values persist after page reload
  - Switch tabs → verify correct tab is selected after page reload

- [ ] **Bulk Processing**
  - Start bulk sync → verify progress displays
  - Click Stop → verify icon is preserved in button
  - Verify error logs only appear when WP_DEBUG is enabled

- [ ] **Serialized Meta Safety**
  - Convert a PNG referenced by a page builder (e.g., Beaver Builder)
  - Verify page builder data is not corrupted
  - Verify scalar meta values are updated correctly

### General Regression

- [ ] Connect to Bunny.net via OAuth
- [ ] Set up CDN (creates storage + pull zone)
- [ ] Upload new image → verify CDN URL assigned
- [ ] Verify responsive images (srcset) use CDN URLs
- [ ] Purge CDN cache
- [ ] Disconnect from Bunny.net
- [ ] Verify local files fallback works after disconnect
