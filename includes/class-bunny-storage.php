<?php
/**
 * Bunny.net Storage Operations
 *
 * Handles file upload and delete operations to Bunny.net Storage Zones.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bunny.net Storage class.
 *
 * @since 1.0.0
 */
class BNOG_Bunny_Storage {

    /**
     * Maximum retry attempts for uploads.
     *
     * @var int
     */
    const MAX_RETRIES = 3;

    /**
     * Upload a file to Bunny Storage.
     *
     * @param string $local_path  Full path to local file.
     * @param string $remote_path Remote path (relative to storage zone root).
     * @param int    $retry       Current retry count.
     * @return string|WP_Error CDN URL on success, WP_Error on failure.
     */
    public function upload( $local_path, $remote_path, $retry = 0 ) {
        $config = bunny_net_offload_gelform()->get_config();

        if ( empty( $config['storage_zone_name'] ) || empty( $config['storage_zone_password'] ) ) {
            return new WP_Error( 'not_configured', __( 'CDN is not configured.', 'bunny-net-offload-gelform' ) );
        }

        if ( ! file_exists( $local_path ) || ! is_readable( $local_path ) ) {
            return new WP_Error( 'file_not_found', __( 'Local file not found or not readable.', 'bunny-net-offload-gelform' ) );
        }

        // Get storage password (it's stored encrypted).
        $storage_password = $config['storage_zone_password'];

        // Always try to decrypt - the password is encrypted when stored.
        $decrypted = bunny_net_offload_gelform()->auth->decrypt_value( $storage_password );
        if ( $decrypted && $decrypted !== $storage_password ) {
            $storage_password = $decrypted;
        }

        // Build storage URL.
        $hostname     = isset( $config['storage_hostname'] ) ? $config['storage_hostname'] : 'storage.bunnycdn.com';
        $zone_name    = $config['storage_zone_name'];
        $remote_path  = ltrim( $remote_path, '/' );
        $storage_url  = sprintf( 'https://%s/%s/%s', $hostname, $zone_name, $remote_path );

        // Read file content.
        $file_content = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( false === $file_content ) {
            return new WP_Error( 'read_error', __( 'Failed to read local file.', 'bunny-net-offload-gelform' ) );
        }

        // Make upload request.
        $response = wp_remote_request(
            $storage_url,
            array(
                'method'  => 'PUT',
                'headers' => array(
                    'AccessKey'    => $storage_password,
                    'Content-Type' => 'application/octet-stream',
                ),
                'body'    => $file_content,
                'timeout' => 60,
            )
        );

        if ( is_wp_error( $response ) ) {
            // Retry with exponential backoff.
            if ( $retry < self::MAX_RETRIES ) {
                $delay = pow( 2, $retry ); // 1s, 2s, 4s.
                sleep( $delay );
                return $this->upload( $local_path, $remote_path, $retry + 1 );
            }

            bunny_net_offload_gelform()->log( 'Upload failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 ) {
            // Retry on server errors.
            if ( $code >= 500 && $retry < self::MAX_RETRIES ) {
                $delay = pow( 2, $retry );
                sleep( $delay );
                return $this->upload( $local_path, $remote_path, $retry + 1 );
            }

            $body    = wp_remote_retrieve_body( $response );
            $message = ! empty( $body ) ? $body : __( 'Upload failed.', 'bunny-net-offload-gelform' );
            bunny_net_offload_gelform()->log( sprintf( 'Upload error (%d): %s', $code, $message ), 'error' );
            return new WP_Error( 'upload_failed', $message, array( 'status' => $code ) );
        }

        // Return CDN URL.
        $cdn_url = trailingslashit( $config['cdn_url'] ) . $remote_path;

        bunny_net_offload_gelform()->log( sprintf( 'File uploaded: %s -> %s', $local_path, $cdn_url ), 'info' );

        return $cdn_url;
    }

    /**
     * Delete a file from Bunny Storage.
     *
     * @param string $remote_path Remote path (relative to storage zone root).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete( $remote_path ) {
        $config = bunny_net_offload_gelform()->get_config();

        if ( empty( $config['storage_zone_name'] ) || empty( $config['storage_zone_password'] ) ) {
            return new WP_Error( 'not_configured', __( 'CDN is not configured.', 'bunny-net-offload-gelform' ) );
        }

        // Get storage password (it's stored encrypted).
        $storage_password = $config['storage_zone_password'];
        $decrypted = bunny_net_offload_gelform()->auth->decrypt_value( $storage_password );
        if ( $decrypted && $decrypted !== $storage_password ) {
            $storage_password = $decrypted;
        }

        // Build storage URL.
        $hostname     = isset( $config['storage_hostname'] ) ? $config['storage_hostname'] : 'storage.bunnycdn.com';
        $zone_name    = $config['storage_zone_name'];
        $remote_path  = ltrim( $remote_path, '/' );
        $storage_url  = sprintf( 'https://%s/%s/%s', $hostname, $zone_name, $remote_path );

        // Make delete request.
        $response = wp_remote_request(
            $storage_url,
            array(
                'method'  => 'DELETE',
                'headers' => array(
                    'AccessKey' => $storage_password,
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            bunny_net_offload_gelform()->log( 'Delete failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // 200 = deleted, 404 = already gone (both OK).
        if ( 200 === $code || 404 === $code ) {
            bunny_net_offload_gelform()->log( sprintf( 'File deleted: %s', $remote_path ), 'info' );
            return true;
        }

        $body    = wp_remote_retrieve_body( $response );
        $message = ! empty( $body ) ? $body : __( 'Delete failed.', 'bunny-net-offload-gelform' );
        bunny_net_offload_gelform()->log( sprintf( 'Delete error (%d): %s', $code, $message ), 'error' );

        return new WP_Error( 'delete_failed', $message, array( 'status' => $code ) );
    }

    /**
     * Check if a file exists in Bunny Storage.
     *
     * @param string $remote_path Remote path to check.
     * @return bool
     */
    public function exists( $remote_path ) {
        $config = bunny_net_offload_gelform()->get_config();

        if ( empty( $config['cdn_url'] ) ) {
            return false;
        }

        $cdn_url  = trailingslashit( $config['cdn_url'] ) . ltrim( $remote_path, '/' );
        $response = wp_remote_head( $cdn_url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        return 200 === $code;
    }

    /**
     * Get the CDN URL for a remote path.
     *
     * @param string $remote_path Remote path.
     * @return string|false CDN URL or false if not configured.
     */
    public function get_cdn_url( $remote_path ) {
        $config = bunny_net_offload_gelform()->get_config();

        if ( empty( $config['cdn_url'] ) ) {
            return false;
        }

        return trailingslashit( $config['cdn_url'] ) . ltrim( $remote_path, '/' );
    }

    /**
     * Convert a local WordPress URL to a remote storage path.
     *
     * @param string $local_url Local WordPress URL.
     * @return string Remote storage path.
     */
    public function url_to_remote_path( $local_url ) {
        // Remove site URL to get relative path.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // Check if URL is from uploads directory.
        if ( 0 === strpos( $local_url, $base_url ) ) {
            $relative = str_replace( $base_url, '', $local_url );
            return 'wp-content/uploads' . $relative;
        }

        // Try to extract path from full URL.
        $site_url = site_url();
        if ( 0 === strpos( $local_url, $site_url ) ) {
            return ltrim( str_replace( $site_url, '', $local_url ), '/' );
        }

        // Return as-is, just clean it up.
        return ltrim( $local_url, '/' );
    }

    /**
     * Convert a local file path to a remote storage path.
     *
     * @param string $local_path Local file path.
     * @return string Remote storage path.
     */
    public function path_to_remote_path( $local_path ) {
        // Get uploads directory.
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        // Check if file is in uploads directory.
        if ( 0 === strpos( $local_path, $base_dir ) ) {
            $relative = str_replace( $base_dir, '', $local_path );
            return 'wp-content/uploads' . $relative;
        }

        // Check if file is in wp-content.
        $content_dir = WP_CONTENT_DIR;
        if ( 0 === strpos( $local_path, $content_dir ) ) {
            $relative = str_replace( $content_dir, '', $local_path );
            return 'wp-content' . $relative;
        }

        // Try ABSPATH.
        if ( 0 === strpos( $local_path, ABSPATH ) ) {
            return ltrim( str_replace( ABSPATH, '', $local_path ), '/' );
        }

        // Return filename only as fallback.
        return basename( $local_path );
    }

    /**
     * Upload a file from URL to storage.
     *
     * @param string $url         URL to download from.
     * @param string $remote_path Remote path to upload to.
     * @return string|WP_Error CDN URL on success, WP_Error on failure.
     */
    public function upload_from_url( $url, $remote_path ) {
        // Download the file.
        $temp_file = download_url( $url, 60 );

        if ( is_wp_error( $temp_file ) ) {
            return $temp_file;
        }

        // Upload to storage.
        $result = $this->upload( $temp_file, $remote_path );

        // Clean up temp file.
        wp_delete_file( $temp_file );

        return $result;
    }

    /**
     * Batch upload multiple files.
     *
     * @param array $files Array of ['local_path' => '', 'remote_path' => ''].
     * @return array Results array with success/failure for each file.
     */
    public function batch_upload( $files ) {
        $results = array();

        foreach ( $files as $file ) {
            $local_path  = isset( $file['local_path'] ) ? $file['local_path'] : '';
            $remote_path = isset( $file['remote_path'] ) ? $file['remote_path'] : '';

            if ( empty( $local_path ) || empty( $remote_path ) ) {
                $results[] = array(
                    'local_path'  => $local_path,
                    'remote_path' => $remote_path,
                    'success'     => false,
                    'error'       => __( 'Missing file path.', 'bunny-net-offload-gelform' ),
                );
                continue;
            }

            $result = $this->upload( $local_path, $remote_path );

            $results[] = array(
                'local_path'  => $local_path,
                'remote_path' => $remote_path,
                'success'     => ! is_wp_error( $result ),
                'cdn_url'     => is_wp_error( $result ) ? null : $result,
                'error'       => is_wp_error( $result ) ? $result->get_error_message() : null,
            );
        }

        return $results;
    }

    /**
     * Batch delete multiple files.
     *
     * @param array $remote_paths Array of remote paths.
     * @return array Results array with success/failure for each file.
     */
    public function batch_delete( $remote_paths ) {
        $results = array();

        foreach ( $remote_paths as $remote_path ) {
            $result = $this->delete( $remote_path );

            $results[] = array(
                'remote_path' => $remote_path,
                'success'     => ! is_wp_error( $result ),
                'error'       => is_wp_error( $result ) ? $result->get_error_message() : null,
            );
        }

        return $results;
    }
}
