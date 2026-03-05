// UIlicious Test: Bunny.net Offload — Settings Page
// Tests settings save/load, tab navigation, and form validation.
// Requires the plugin to be connected and CDN configured.
//
// Required variables: SITE_URL, WP_USER, WP_PASS

// Log in to WordPress admin.
I.goTo(SITE_URL + "/wp-login.php");
I.fill("Username", WP_USER);
I.fill("Password", WP_PASS);
I.click("Log In");

// Navigate to plugin settings.
I.goTo(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform");
I.see("Bunny.net Offload");

// --- Test: Tab navigation ---
// Status tab should be active by default.
I.see("CDN Status");

// Click Advanced tab.
I.click("Advanced");
I.see.element("#bnog-tab-advanced");

// Click back to Status tab.
I.click("Status");
I.see.element("#bnog-tab-status");

// --- Test: Settings form elements exist ---
I.goTo(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform&tab=advanced");

// Check for image quality settings.
I.see("Max Width");
I.see("JPEG Quality");

// --- Test: Settings save and persist ---
// Change max width value.
I.fill("Max Width", "1920");
I.fill("JPEG Quality", "90");
I.click("Save Settings");

// Verify settings persisted after reload.
I.goTo(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform&tab=advanced");
var maxWidthField = I.getElement("[name='max_width']");
TEST.assert(maxWidthField.value === "1920", "Max width should persist as 1920");

// --- Test: Tab persists in URL ---
I.amAt(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform&tab=advanced");
I.see.element("#bnog-tab-advanced");
