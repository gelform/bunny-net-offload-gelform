<?php
/**
 * Bunny.net Authorization Handler
 *
 * Handles the authorization flow with Bunny.net using their WordPress plugin auth endpoint.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bunny.net Authorization class.
 *
 * @since 1.0.0
 */
class BNOG_Bunny_Auth {

    /**
     * Option name for storing encrypted API key.
     *
     * @var string
     */
    const API_KEY_OPTION = 'bnog_api_key';

    /**
     * Bunny.net WordPress plugin authorization endpoint.
     *
     * @var string
     */
    const AUTH_ENDPOINT = 'https://dash.bunny.net/auth/login';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'handle_auth_callback' ) );
        add_action( 'wp_ajax_bnog_disconnect', array( $this, 'ajax_disconnect' ) );
    }

    /**
     * Check if we have a stored API key.
     *
     * @return bool
     */
    public function has_api_key() {
        $encrypted = get_option( self::API_KEY_OPTION );
        return ! empty( $encrypted );
    }

    /**
     * Get the decrypted API key.
     *
     * @return string|false The API key or false if not set.
     */
    public function get_api_key() {
        $encrypted = get_option( self::API_KEY_OPTION );

        if ( empty( $encrypted ) ) {
            return false;
        }

        return $this->decrypt( $encrypted );
    }

    /**
     * Store the API key (encrypted).
     *
     * @param string $api_key The API key to store.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function set_api_key( $api_key ) {
        $encrypted = $this->encrypt( $api_key );
        
        if ( is_wp_error( $encrypted ) ) {
            return $encrypted;
        }
        
        return update_option( self::API_KEY_OPTION, $encrypted );
    }

    /**
     * Delete the stored API key.
     *
     * @return bool
     */
    public function delete_api_key() {
        return delete_option( self::API_KEY_OPTION );
    }

    /**
     * Get the authorization URL for Bunny.net.
     *
     * Uses the same flow as the official Bunny.net WordPress plugin.
     *
     * @return string
     */
    public function get_auth_url() {
        $site_url     = site_url();
        $callback_url = admin_url( 'options-general.php?page=bunny-net-offload-gelform' );

        // Generate state parameter for CSRF protection.
        $state = wp_create_nonce( 'bnog_auth_' . get_current_user_id() );
        set_transient( 'bnog_auth_state_' . $state, time(), 300 ); // 5 minutes.

        $params = array(
            'source'      => 'wp-plugin',
            'domain'      => $site_url,
            'callbackUrl' => $callback_url,
            'state'       => $state,
        );

        return add_query_arg( $params, self::AUTH_ENDPOINT );
    }

    /**
     * Handle the authorization callback from Bunny.net.
     */
    public function handle_auth_callback() {
        // Check if this is our page.
        if ( ! isset( $_GET['page'] ) || 'bunny-net-offload-gelform' !== $_GET['page'] ) {
            return;
        }

        // Verify user capabilities early.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Debug: Log all GET parameters to help identify what Bunny sends back.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && count( $_GET ) > 1 ) {
            $params = array();
            foreach ( $_GET as $key => $value ) {
                if ( 'page' !== $key ) {
                    $params[ $key ] = sanitize_text_field( wp_unslash( $value ) );
                }
            }
            if ( ! empty( $params ) ) {
                // Store for display on admin page.
                set_transient( 'bnog_debug_callback_params', $params, 300 );
                error_log( '[Bunny.net Offload] Callback params: ' . wp_json_encode( $params ) );
            }
        }

        // Verify state parameter for CSRF protection.
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        if ( ! empty( $state ) ) {
            $stored_state = get_transient( 'bnog_auth_state_' . $state );
            if ( ! $stored_state ) {
                set_transient( 'bnog_auth_error', __( 'Invalid or expired authorization request. Please try again.', 'bunny-net-offload-gelform' ), 60 );
                wp_safe_redirect( admin_url( 'admin.php?page=bunny-net-offload-gelform&auth=failed' ) );
                exit;
            }
            // Delete the used state.
            delete_transient( 'bnog_auth_state_' . $state );
        }

        // Check for API key in callback - try various possible parameter names.
        $api_key = '';

        // Known parameter names Bunny might use
        $possible_keys = array( 'apiKey', 'AccessKey', 'api_key', 'token', 'key', 'accessKey', 'access_key' );

        foreach ( $possible_keys as $param ) {
            if ( isset( $_GET[ $param ] ) && ! empty( $_GET[ $param ] ) ) {
                $api_key = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
                break;
            }
        }

        // No API key in request, not a callback.
        if ( empty( $api_key ) ) {
            return;
        }

        // Validate the API key by making a test request.
        if ( ! $this->validate_api_key( $api_key ) ) {
            set_transient( 'bnog_auth_error', __( 'Invalid API key received. Please try again.', 'bunny-net-offload-gelform' ), 60 );
            wp_safe_redirect( admin_url( 'options-general.php?page=bunny-net-offload-gelform&auth=failed' ) );
            exit;
        }

        // Store the API key.
        $this->set_api_key( $api_key );

        // Redirect to success (remove API key from URL).
        wp_safe_redirect( admin_url( 'options-general.php?page=bunny-net-offload-gelform&auth=success' ) );
        exit;
    }

    /**
     * Validate an API key by making a test request.
     *
     * @param string $api_key The API key to validate.
     * @return bool
     */
    public function validate_api_key( $api_key ) {
        $response = wp_remote_get(
            'https://api.bunny.net/storagezone',
            array(
                'headers' => array(
                    'AccessKey' => $api_key,
                    'Accept'    => 'application/json',
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            bunny_net_offload_gelform()->log( 'API key validation failed: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        return 200 === $code || 201 === $code;
    }

    /**
     * AJAX handler for disconnecting from Bunny.net.
     */
    public function ajax_disconnect() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        // Verify capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        // Delete API key.
        $this->delete_api_key();

        // Clear configuration but keep image settings.
        $config = bunny_net_offload_gelform()->get_config();
        $keep   = array(
            'max_width'        => isset( $config['max_width'] ) ? $config['max_width'] : 2048,
            'max_height'       => isset( $config['max_height'] ) ? $config['max_height'] : 2048,
            'jpeg_quality'     => isset( $config['jpeg_quality'] ) ? $config['jpeg_quality'] : 85,
            'png_compression'  => isset( $config['png_compression'] ) ? $config['png_compression'] : 6,
            'keep_local_files' => isset( $config['keep_local_files'] ) ? $config['keep_local_files'] : true,
        );

        update_option( 'bnog_config', $keep );

        wp_send_json_success( array( 'message' => __( 'Disconnected successfully.', 'bunny-net-offload-gelform' ) ) );
    }

    /**
     * Encrypt a value using WordPress salts.
     *
     * @param string $value The value to encrypt.
     * @return string|WP_Error Encrypted value or WP_Error on failure.
     */
    private function encrypt( $value ) {
        if ( ! extension_loaded( 'openssl' ) ) {
            return new WP_Error(
                'no_openssl',
                __( 'OpenSSL extension is required for secure API key storage. Please contact your hosting provider.', 'bunny-net-offload-gelform' )
            );
        }

        $key    = $this->get_encryption_key();
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $cipher ) {
            return new WP_Error(
                'encryption_failed',
                __( 'Failed to encrypt API key. Please try again.', 'bunny-net-offload-gelform' )
            );
        }

        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a value.
     *
     * @param string $encrypted The encrypted value.
     * @return string|false
     */
    private function decrypt( $encrypted ) {
        if ( ! extension_loaded( 'openssl' ) ) {
            return false;
        }

        $data = base64_decode( $encrypted );

        if ( false === $data || strlen( $data ) < 17 ) {
            return false;
        }

        $key    = $this->get_encryption_key();
        $iv     = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );

        $decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $decrypted ) {
            return false;
        }

        return $decrypted;
    }

    /**
     * Get encryption key derived from WordPress salts.
     *
     * @return string
     */
    private function get_encryption_key() {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bunny-net-offload-gelform-default-key';
        $salt .= defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';

        return hash( 'sha256', $salt, true );
    }

    /**
     * Encrypt sensitive data (public method for other classes).
     *
     * @param string $value Value to encrypt.
     * @return string
     */
    public function encrypt_value( $value ) {
        return $this->encrypt( $value );
    }

    /**
     * Decrypt sensitive data (public method for other classes).
     *
     * @param string $encrypted Encrypted value.
     * @return string|false
     */
    public function decrypt_value( $encrypted ) {
        return $this->decrypt( $encrypted );
    }
}
