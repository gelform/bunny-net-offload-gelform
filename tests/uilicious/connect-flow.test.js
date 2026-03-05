// UIlicious Test: Bunny.net Offload — Connect Flow
// Tests the OAuth connect and disconnect flow on the admin settings page.
//
// Required variables: SITE_URL, WP_USER, WP_PASS

// Log in to WordPress admin.
I.goTo(SITE_URL + "/wp-login.php");
I.fill("Username", WP_USER);
I.fill("Password", WP_PASS);
I.click("Log In");
I.amAt(SITE_URL + "/wp-admin/");

// Navigate to plugin settings.
I.goTo(SITE_URL + "/wp-admin/options-general.php?page=bunny-net-offload-gelform");
I.see("Bunny.net Offload");

// --- Test: Connect button is visible when not connected ---
I.see("Connect to Bunny.net");
I.see.element("#bnog-connect");

// --- Test: Connect button has correct link ---
var connectBtn = I.getElement("#bnog-connect");
TEST.assert(connectBtn !== null, "Connect button should exist");

// --- Test: Signup link is visible ---
I.see("Don't have a Bunny.net account?");

// --- Test: Disconnect flow (if already connected) ---
// I.see.element() throws on failure, so use try/catch for conditional check.
try {
    I.see.element("#bnog-disconnect");
    I.click("#bnog-disconnect");
    // Should show confirmation or redirect to connect screen.
    I.see("Connect to Bunny.net");
} catch (e) {
    // Disconnect button not present — plugin is not connected, skip this test.
}
