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

		// Increase timeout for GitHub API requests.
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 10, 2 );
	}

	/**
	 * Increase HTTP request timeout for GitHub.
	 *
	 * @param int    $timeout Current timeout.
	 * @param string $url     Request URL.
	 * @return int Modified timeout.
	 */
	public function http_request_timeout( $timeout, $url = '' ) {
		if ( strpos( $url, 'api.github.com' ) !== false || strpos( $url, 'github.com' ) !== false ) {
			return 15;
		}
		return $timeout;
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

		// Fetch from GitHub API.
		$response = wp_remote_get(
			$this->config['api_url'],
			array(
				'sslverify' => $this->config['sslverify'],
				'headers'   => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( empty( $data ) || ! isset( $data->tag_name ) ) {
			return false;
		}

		$this->github_data = $data;

		// Cache for 12 hours (twice daily check).
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

		return $version;
	}

	/**
	 * Get download URL for the latest release.
	 *
	 * @return string|false Download URL or false.
	 */
	private function get_zip_url() {
		$data = $this->get_github_data();

		if ( ! $data ) {
			return false;
		}

		// Prefer the zipball_url from the release.
		if ( ! empty( $data->zipball_url ) ) {
			return $data->zipball_url;
		}

		// Fallback to constructing the URL.
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
				'slug'        => dirname( $this->config['slug'] ),
				'plugin'      => $this->config['slug'],
				'new_version' => $new_version,
				'url'         => $this->config['github_url'],
				'package'     => $this->get_zip_url(),
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires'    => '5.8',
				'requires_php'=> '7.4',
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
			'name'              => $plugin_data['Name'],
			'slug'              => dirname( $this->config['slug'] ),
			'version'           => $this->get_new_version(),
			'author'            => $plugin_data['Author'],
			'author_profile'    => $plugin_data['AuthorURI'],
			'homepage'          => $this->config['github_url'],
			'download_link'     => $this->get_zip_url(),
			'requires'          => '5.8',
			'tested'            => '',
			'requires_php'      => '7.4',
			'last_updated'      => isset( $data->published_at ) ? $data->published_at : '',
			'sections'          => array(
				'description'  => $plugin_data['Description'],
				'changelog'    => $this->get_changelog( $data ),
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

		// Convert markdown to basic HTML.
		$changelog = esc_html( $data->body );
		$changelog = nl2br( $changelog );

		// Convert markdown headers.
		$changelog = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $changelog );
		$changelog = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $changelog );
		$changelog = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $changelog );

		// Convert markdown lists.
		$changelog = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $changelog );
		$changelog = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $changelog );

		return $changelog;
	}

	/**
	 * Handle post-install tasks.
	 *
	 * Rename the extracted folder to match the plugin slug.
	 *
	 * @param bool  $response   Installation response.
	 * @param array $hook_extra Extra arguments.
	 * @param array $result     Installation result.
	 * @return array Modified result.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Only process our plugin.
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->config['slug'] ) {
			return $result;
		}

		// Get the proper destination folder name.
		$proper_destination = WP_PLUGIN_DIR . '/' . $this->config['proper_folder_name'];

		// Move to proper location if needed.
		if ( $result['destination'] !== $proper_destination ) {
			$wp_filesystem->move( $result['destination'], $proper_destination );
			$result['destination'] = $proper_destination;
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
		$this->github_data = null;
	}
}
