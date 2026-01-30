<?php
/**
 * GitHub Plugin Updater
 *
 * Checks GitHub for plugin updates and integrates with WordPress update system.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.6
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater Class
 *
 * Handles automatic plugin updates from GitHub releases.
 */
class BNOG_GitHub_Updater {

	/**
	 * Plugin configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * GitHub API data cache.
	 *
	 * @var object|null
	 */
	private $github_data = null;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array.
	 */
	public function __construct( $config = array() ) {
		$defaults = array(
			'slug'               => BNOG_PLUGIN_BASENAME,
			'proper_folder_name' => dirname( BNOG_PLUGIN_BASENAME ),
			'github_user'        => 'gelform',
			'github_repo'        => 'bunny-net-offload-gelform',
			'sslverify'          => true,
		);

		$this->config = wp_parse_args( $config, $defaults );

		// Build GitHub URLs.
		$this->config['api_url']    = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->config['github_user'],
			$this->config['github_repo']
		);
		$this->config['github_url'] = sprintf(
			'https://github.com/%s/%s',
			$this->config['github_user'],
			$this->config['github_repo']
		);

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );

		// Enable auto-updates support for this plugin.
		add_filter( 'auto_update_plugin', array( $this, 'auto_update_plugin' ), 10, 2 );
	}

	/**
	 * Allow auto-updates for this plugin.
	 *
	 * By default, WordPress only allows auto-updates for plugins from WordPress.org.
	 * This filter enables auto-updates for our GitHub-hosted plugin when the user
	 * has enabled auto-updates for it.
	 *
	 * @param bool|null $update Whether to update the plugin. Null to use default behavior.
	 * @param object    $item   The plugin update object.
	 * @return bool|null Whether to auto-update.
	 */
	public function auto_update_plugin( $update, $item ) {
		// Only handle our plugin.
		if ( ! isset( $item->plugin ) || $item->plugin !== $this->config['slug'] ) {
			return $update;
		}

		// Check if user has enabled auto-updates for this plugin.
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

		if ( in_array( $this->config['slug'], $auto_updates, true ) ) {
			return true;
		}

		return $update;
	}

	/**
	 * Get GitHub release data.
	 *
	 * @return object|false Release data or false on failure.
	 */
	private function get_github_data() {
		if ( null !== $this->github_data ) {
			return $this->github_data;
		}

		// Check transient first.
		$transient_key = 'bnog_github_release_' . md5( $this->config['api_url'] );
		$cached_data   = get_site_transient( $transient_key );

		if ( false !== $cached_data ) {
			$this->github_data = $cached_data;
			return $this->github_data;
		}

		// Fetch from GitHub API with User-Agent header (required by GitHub).
		$response = wp_remote_get(
			$this->config['api_url'],
			array(
				'timeout'   => 15,
				'sslverify' => $this->config['sslverify'],
				'headers'   => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'Bunny-Net-Offload-Gelform/' . BNOG_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Cache failures for 5 minutes to allow quicker recovery.
			set_site_transient( $transient_key . '_failed', true, 5 * MINUTE_IN_SECONDS );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			// Cache failures for 5 minutes to allow quicker recovery.
			set_site_transient( $transient_key . '_failed', true, 5 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( empty( $data ) || ! isset( $data->tag_name ) ) {
			return false;
		}

		$this->github_data = $data;

		// Cache successful response for 12 hours (twice daily check).
		set_site_transient( $transient_key, $data, 12 * HOUR_IN_SECONDS );

		return $this->github_data;
	}

	/**
	 * Get new version number from GitHub.
	 *
	 * @return string|false Version number or false.
	 */
	private function get_new_version() {
		$data = $this->get_github_data();

		if ( ! $data || empty( $data->tag_name ) ) {
			return false;
		}

		// Remove 'v' prefix if present (e.g., v1.0.6 -> 1.0.6).
		$version = ltrim( $data->tag_name, 'v' );

		// Validate version format (e.g., 1.0 or 1.0.6). Return false if invalid.
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
			return false;
		}

		return $version;
	}

	/**
	 * Get download URL for the latest release.
	 *
	 * Prefers the release asset (properly named zip) over GitHub's auto-generated zipball.
	 *
	 * @return string|false Download URL or false.
	 */
	private function get_zip_url() {
		$data = $this->get_github_data();

		if ( ! $data ) {
			return false;
		}

		// Prefer release asset named 'bunny-net-offload-gelform.zip' (correctly structured).
		if ( ! empty( $data->assets ) && is_array( $data->assets ) ) {
			foreach ( $data->assets as $asset ) {
				if ( isset( $asset->name ) && 'bunny-net-offload-gelform.zip' === $asset->name ) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fallback to zipball_url (extracts to repo-tag folder, handled by post_install).
		if ( ! empty( $data->zipball_url ) ) {
			return $data->zipball_url;
		}

		// Last resort: construct the URL.
		return sprintf(
			'https://github.com/%s/%s/archive/refs/tags/%s.zip',
			$this->config['github_user'],
			$this->config['github_repo'],
			$data->tag_name
		);
	}

	/**
	 * Check for plugin updates.
	 *
	 * @param object $transient Update transient.
	 * @return object Modified transient.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$new_version = $this->get_new_version();

		if ( false === $new_version ) {
			return $transient;
		}

		$current_version = BNOG_VERSION;

		// Compare versions.
		if ( version_compare( $new_version, $current_version, '>' ) ) {
			$plugin = array(
				'slug'         => dirname( $this->config['slug'] ),
				'plugin'       => $this->config['slug'],
				'new_version'  => $new_version,
				'url'          => $this->config['github_url'],
				'package'      => $this->get_zip_url(),
				'icons'        => array(),
				'banners'      => array(),
				'tested'       => '',
				'requires'     => '5.8',
				'requires_php' => '7.4',
			);

			$transient->response[ $this->config['slug'] ] = (object) $plugin;
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the update details popup.
	 *
	 * @param false|object|array $result Result object or false.
	 * @param string             $action API action.
	 * @param object             $args   Arguments.
	 * @return false|object Plugin info or false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || dirname( $this->config['slug'] ) !== $args->slug ) {
			return $result;
		}

		$data = $this->get_github_data();

		if ( ! $data ) {
			return $result;
		}

		$plugin_data = get_plugin_data( BNOG_PLUGIN_FILE );

		$info = array(
			'name'           => $plugin_data['Name'],
			'slug'           => dirname( $this->config['slug'] ),
			'version'        => $this->get_new_version(),
			'author'         => $plugin_data['Author'],
			'author_profile' => $plugin_data['AuthorURI'],
			'homepage'       => $this->config['github_url'],
			'download_link'  => $this->get_zip_url(),
			'requires'       => '5.8',
			'tested'         => '',
			'requires_php'   => '7.4',
			'last_updated'   => isset( $data->published_at ) ? $data->published_at : '',
			'sections'       => array(
				'description' => $plugin_data['Description'],
				'changelog'   => $this->get_changelog( $data ),
			),
		);

		return (object) $info;
	}

	/**
	 * Get changelog from release body.
	 *
	 * @param object $data GitHub release data.
	 * @return string Changelog HTML.
	 */
	private function get_changelog( $data ) {
		if ( empty( $data->body ) ) {
			return '<p>No changelog available.</p>';
		}

		$changelog = $data->body;

		// Convert markdown headers first (before escaping).
		$changelog = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $changelog );
		$changelog = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $changelog );
		$changelog = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $changelog );

		// Convert markdown lists (non-greedy match for consecutive items).
		$changelog = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $changelog );
		$changelog = preg_replace( '/((?:<li>[^<]*<\/li>\s*)+)/', '<ul>$1</ul>', $changelog );

		// Convert newlines to breaks for remaining text.
		$changelog = nl2br( $changelog );

		// Sanitize HTML, allowing only safe tags.
		$allowed_html = array(
			'h2'   => array(),
			'h3'   => array(),
			'h4'   => array(),
			'p'    => array(),
			'br'   => array(),
			'ul'   => array(),
			'li'   => array(),
			'strong' => array(),
			'em'   => array(),
			'code' => array(),
		);

		return wp_kses( $changelog, $allowed_html );
	}

	/**
	 * Handle post-install tasks.
	 *
	 * Rename the extracted folder to match the plugin slug.
	 *
	 * @param bool|WP_Error $response   Installation response.
	 * @param array         $hook_extra Extra arguments.
	 * @param array         $result     Installation result.
	 * @return array Modified result.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Only process our plugin.
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->config['slug'] ) {
			return $result;
		}

		// Check if WP_Filesystem is available.
		if ( ! $wp_filesystem ) {
			return $result;
		}

		// Get the proper destination folder name.
		$proper_destination = WP_PLUGIN_DIR . '/' . $this->config['proper_folder_name'];

		// Move to proper location if needed.
		if ( isset( $result['destination'] ) && $result['destination'] !== $proper_destination ) {
			$move_result = $wp_filesystem->move( $result['destination'], $proper_destination );

			if ( ! $move_result ) {
				// Move failed: keep original destination, but ensure destination_name is accurate.
				if ( ! isset( $result['destination_name'] ) || empty( $result['destination_name'] ) ) {
					$result['destination_name'] = basename( $result['destination'] );
				}

				return $result;
			}

			// Move succeeded: update destination and destination_name to the new location.
			$result['destination']      = $proper_destination;
			$result['destination_name'] = basename( $proper_destination );
		}

		// Reactivate the plugin.
		$activate = activate_plugin( $this->config['slug'] );

		if ( is_wp_error( $activate ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Clear the update cache.
	 *
	 * Useful when forcing an update check.
	 */
	public function clear_cache() {
		$transient_key = 'bnog_github_release_' . md5( $this->config['api_url'] );
		delete_site_transient( $transient_key );
		delete_site_transient( $transient_key . '_failed' );
		$this->github_data = null;
	}
}
