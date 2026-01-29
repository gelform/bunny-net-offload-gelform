=== Bunny.net Offload by Gelform ===
Contributors: gelform
Tags: cdn, bunny, image optimization, performance, offload
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dead-simple image optimization and CDN offloading using Bunny.net with one-click OAuth authorization.

== Description ==

Bunny.net Offload by Gelform provides effortless image optimization and CDN delivery using Bunny.net. No API keys to copy-paste, no complex configuration - just connect and go.

= Features =

* **One-Click Connect**: OAuth-style authorization - just click "Connect to Bunny.net" and authorize
* **Automatic Setup**: Creates storage zone and pull zone automatically with geographic replication
* **Image Optimization**: Resizes and compresses images on upload
* **Global CDN**: Serve images from Bunny.net's global edge network
* **URL Rewriting**: Automatically rewrites image URLs to use CDN
* **Background Sync**: Sync existing media library in the background
* **Responsive Images**: Full srcset support for responsive images

= How It Works =

1. Click "Connect to Bunny.net" and authorize the plugin
2. Choose your storage region and image settings
3. Click "Set Up CDN" - that's it!

All new images are automatically optimized and uploaded to your CDN. Existing images can be synced with one click.

= Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* A Bunny.net account (free tier available)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/bunny-net-offload-gelform/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Bunny.net Offload
4. Click "Connect to Bunny.net" and follow the authorization flow
5. Configure your settings and click "Set Up CDN"

== Frequently Asked Questions ==

= Do I need a Bunny.net account? =

Yes, you need a Bunny.net account. They offer a free trial and very affordable pricing for storage and bandwidth.

= Will this work with my existing images? =

Yes! After setup, you can click "Start Sync of Existing Files" to upload all your existing images to the CDN. This happens in the background.

= What happens to my images if I deactivate the plugin? =

Your images remain on the CDN and your local server (if you chose to keep local files). URLs will fall back to local files when the plugin is deactivated.

= Does this work with page builders? =

Yes, the plugin uses WordPress's standard image filters, so it works with any theme or page builder that uses standard WordPress image functions.

= Can I use a custom domain for the CDN? =

The plugin uses Bunny.net's default b-cdn.net subdomain. Custom domains can be configured through the Bunny.net dashboard.

== Screenshots ==

1. Connect screen - one click to authorize
2. Setup screen - choose region and image settings
3. Dashboard - manage your CDN settings

== Changelog ==

= 1.0.0 =
* Initial release
* OAuth-style authorization with Bunny.net
* Automatic storage zone and pull zone creation with geographic replication
* Image optimization on upload
* URL rewriting for CDN delivery
* Background sync for existing media
* Admin dashboard for settings management

== Upgrade Notice ==

= 1.0.0 =
Initial release of Bunny.net Offload by Gelform.
