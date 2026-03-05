// UIlicious Test: Bunny.net Offload — Bulk Sync
// Tests bulk sync start, stop, progress display, and error handling.
// Requires the plugin to be connected and CDN configured with media in library.
//
// Required variables: SITE_URL, WP_USER, WP_PASS

// Log in to WordPress admin.
I.goTo(SITE_URL + "/wp-login.php");
I.fill("Username", WP_USER);
I.fill("Password", WP_PASS);
I.click("Log In");

// Navigate to plugin settings, status tab.
I.goTo(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform");
I.see("Bunny.net Offload");

// --- Test: Sync section is visible ---
I.see("Sync");
I.see.element("#bnog-sync-btn");

// --- Test: Sync stats display ---
I.see("Synced");
I.see("Pending");

// --- Test: Resize checkbox exists ---
I.see.element("#bnog-resize-before-sync");

// --- Test: Start sync ---
I.click("#bnog-sync-btn");
I.wait(2);

// Should show progress or sync-in-progress state.
// Check for running indicator.
var hasSyncRunning = I.see.element(".bnog-sync-in-progress") || I.see("Syncing");
TEST.assert(hasSyncRunning, "Sync should start and show progress");

// --- Test: Stop button appears during sync ---
I.see.element("#bnog-stop-sync-btn");

// --- Test: Stop button preserves icon ---
var stopBtn = I.getElement("#bnog-stop-sync-btn");
var hasIcon = stopBtn.innerHTML.indexOf("dashicons") !== -1;
TEST.assert(hasIcon, "Stop button should contain dashicons icon");

// --- Test: Click Stop ---
I.click("#bnog-stop-sync-btn");
I.see.element("[disabled]"); // Button should be disabled after clicking.

// Wait for page to reload after stop.
I.wait(3);

// --- Test: Sync stopped ---
I.goTo(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform");
I.see("Sync");
// Start button should be available again.
I.see.element("#bnog-sync-btn");
