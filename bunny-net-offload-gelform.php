<?php
/**
 * Plugin Name: Bunny.net Offload by Gelform
 * Plugin URI: https://github.com/gelform/bunny-net-offload-gelform
 * Description: Dead-simple image optimization and CDN offloading using Bunny.net with OAuth-style authorization.
 * Version: 1.0.6
 * Author: Gelform
 * Author URI: https://gelform.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bunny-net-offload-gelform
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package BunnyNetOffloadGelform
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'BNOG_VERSION', '1.0.6' );
define( 'BNOG_PLUGIN_FILE', __FILE__ );
define( 'BNOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BNOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BNOG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
final class Bunny_Net_Offload_Gelform {

    /**
     * Plugin instance.
     *
     * @var Bunny_Net_Offload_Gelform
     */
    private static $instance = null;

    /**
     * Bunny Auth handler.
     *
     * @var BNOG_Bunny_Auth
     */
    public $auth;

    /**
     * Bunny API handler.
     *
     * @var BNOG_Bunny_API
     */
    public $api;

    /**
     * Bunny Storage handler.
     *
     * @var BNOG_Bunny_Storage
     */
    public $storage;

    /**
     * Image Processor.
     *
     * @var BNOG_Image_Processor
     */
    public $image_processor;

    /**
     * Media Handler.
     *
     * @var BNOG_Media_Handler
     */
    public $media_handler;

    /**
     * URL Rewriter.
     *
     * @var BNOG_URL_Rewriter
     */
    public $url_rewriter;

    /**
     * Admin handler.
     *
     * @var BNOG_Admin
     */
    public $admin;

    /**
     * GitHub Updater.
     *
     * @var BNOG_GitHub_Updater
     */
    public $updater;

    /**
     * Get plugin instance.
     *
     * @return Bunny_Net_Offload_Gelform
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Load required files.
     */
    private function load_dependencies() {
        require_once BNOG_PLUGIN_DIR . 'includes/class-bunny-auth.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-bunny-api.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-bunny-storage.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-image-processor.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-media-handler.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-url-rewriter.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-admin.php';
        require_once BNOG_PLUGIN_DIR . 'includes/class-github-updater.php';
    }

    /**
     * Initialize components.
     */
    private function init_components() {
        $this->auth            = new BNOG_Bunny_Auth();
        $this->api             = new BNOG_Bunny_API();
        $this->storage         = new BNOG_Bunny_Storage();
        $this->image_processor = new BNOG_Image_Processor();
        $this->media_handler   = new BNOG_Media_Handler();
        $this->url_rewriter    = new BNOG_URL_Rewriter();
        $this->admin           = new BNOG_Admin();
        $this->updater         = new BNOG_GitHub_Updater();
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks() {
        register_activation_hook( BNOG_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( BNOG_PLUGIN_FILE, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( BNOG_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
    }

    /**
     * Add settings link to plugin listing.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified links.
     */
    public function add_settings_link( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'options-general.php?page=bunny-net-offload-gelform' ),
            __( 'Settings', 'bunny-net-offload-gelform' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Set default options if not exists.
        if ( false === get_option( 'bnog_config' ) ) {
            $defaults = array(
                'max_width'        => 2048,
                'max_height'       => 2048,
                'jpeg_quality'     => 85,
                'png_compression'  => 6,
                'webp_quality'     => 82,
                'keep_local_files' => true,
            );
            update_option( 'bnog_config', $defaults );
        }

        // Schedule cron for background processing.
        if ( ! wp_next_scheduled( 'bnog_process_queue' ) ) {
            wp_schedule_event( time(), 'every_minute', 'bnog_process_queue' );
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Clear scheduled events.
        wp_clear_scheduled_hook( 'bnog_process_queue' );
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bunny-net-offload-gelform',
            false,
            dirname( BNOG_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Get plugin configuration.
     *
     * @param string $key     Optional. Specific config key to retrieve.
     * @param mixed  $default Optional. Default value if key doesn't exist.
     * @return mixed
     */
    public function get_config( $key = null, $default = null ) {
        $config = get_option( 'bnog_config', array() );

        if ( null === $key ) {
            return $config;
        }

        return isset( $config[ $key ] ) ? $config[ $key ] : $default;
    }

    /**
     * Update plugin configuration.
     *
     * @param string|array $key   Config key or array of key-value pairs.
     * @param mixed        $value Optional. Value if $key is string.
     * @return bool
     */
    public function update_config( $key, $value = null ) {
        $config = get_option( 'bnog_config', array() );

        if ( is_array( $key ) ) {
            $config = array_merge( $config, $key );
        } else {
            $config[ $key ] = $value;
        }

        return update_option( 'bnog_config', $config );
    }

    /**
     * Check if the plugin is fully configured.
     *
     * @return bool
     */
    public function is_configured() {
        $config = $this->get_config();
        return ! empty( $config['cdn_url'] ) && ! empty( $config['storage_zone_name'] );
    }

    /**
     * Check if connected to Bunny.net.
     *
     * @return bool
     */
    public function is_connected() {
        return $this->auth->has_api_key();
    }

    /**
     * Log message when debugging is enabled.
     *
     * @param string $message Message to log.
     * @param string $level   Log level (info, warning, error).
     */
    public function log( $message, $level = 'info' ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $prefix = sprintf( '[Bunny.net Offload][%s] ', strtoupper( $level ) );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $prefix . $message );
        }
    }
}

// Add custom cron interval.
add_filter(
    'cron_schedules',
    function ( $schedules ) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute', 'bunny-net-offload-gelform' ),
        );
        return $schedules;
    }
);

/**
 * Get the main plugin instance.
 *
 * @return Bunny_Net_Offload_Gelform
 */
function bunny_net_offload_gelform() {
    return Bunny_Net_Offload_Gelform::get_instance();
}

// Initialize the plugin.
bunny_net_offload_gelform();
