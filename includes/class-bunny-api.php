<?php
/**
 * Bunny.net API Wrapper
 *
 * Handles all API interactions with Bunny.net for managing storage zones and pull zones.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bunny.net API class.
 *
 * @since 1.0.0
 */
class BNOG_Bunny_API {

    /**
     * Bunny.net API base URL.
     *
     * @var string
     */
    const API_BASE = 'https://api.bunny.net';

    /**
     * Storage regions with their hostnames.
     *
     * @var array
     */
    const STORAGE_REGIONS = array(
        'DE'  => array(
            'name'     => 'Frankfurt, Germany',
            'hostname' => 'storage.bunnycdn.com',
        ),
        'NY'  => array(
            'name'     => 'New York, USA',
            'hostname' => 'ny.storage.bunnycdn.com',
        ),
        'LA'  => array(
            'name'     => 'Los Angeles, USA',
            'hostname' => 'la.storage.bunnycdn.com',
        ),
        'SG'  => array(
            'name'     => 'Singapore',
            'hostname' => 'sg.storage.bunnycdn.com',
        ),
        'SYD' => array(
            'name'     => 'Sydney, Australia',
            'hostname' => 'syd.storage.bunnycdn.com',
        ),
    );

    /**
     * Recommended replication regions for each primary region.
     * Provides geographic redundancy based on proximity.
     *
     * @var array
     */
    const REPLICATION_REGIONS = array(
        'DE'  => array( 'NY' ),       // Europe -> US East.
        'NY'  => array( 'LA', 'DE' ), // US East -> US West + Europe.
        'LA'  => array( 'NY', 'SG' ), // US West -> US East + Asia.
        'SG'  => array( 'SYD', 'DE' ), // Singapore -> Australia + Europe.
        'SYD' => array( 'SG', 'LA' ), // Australia -> Singapore + US West.
    );

    /**
     * Make an API request.
     *
     * @param string $endpoint API endpoint (relative to base URL).
     * @param string $method   HTTP method.
     * @param array  $body     Request body data.
     * @param int    $retry    Current retry count.
     * @return array|WP_Error
     */
    public function request( $endpoint, $method = 'GET', $body = array(), $retry = 0 ) {
        $api_key = bunny_net_offload_gelform()->auth->get_api_key();

        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', __( 'Not connected to Bunny.net.', 'bunny-net-offload-gelform' ) );
        }

        $url = self::API_BASE . $endpoint;

        $args = array(
            'method'  => $method,
            'headers' => array(
                'AccessKey'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'timeout' => 30,
        );

        if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            // Retry logic with exponential backoff.
            if ( $retry < 3 ) {
                $delay = pow( 2, $retry ); // 1s, 2s, 4s.
                sleep( $delay );
                return $this->request( $endpoint, $method, $body, $retry + 1 );
            }

            bunny_net_offload_gelform()->log( 'API request failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_response = wp_remote_retrieve_body( $response );
        $data = json_decode( $body_response, true );

        // Handle rate limiting.
        if ( 429 === $code ) {
            if ( $retry < 3 ) {
                $delay = pow( 2, $retry + 1 );
                sleep( $delay );
                return $this->request( $endpoint, $method, $body, $retry + 1 );
            }

            return new WP_Error( 'rate_limited', __( 'API rate limit exceeded. Please try again later.', 'bunny-net-offload-gelform' ) );
        }

        // Handle errors.
        if ( $code >= 400 ) {
            $message = isset( $data['Message'] ) ? $data['Message'] : __( 'API request failed.', 'bunny-net-offload-gelform' );
            bunny_net_offload_gelform()->log( sprintf( 'API error (%d): %s', $code, $message ), 'error' );
            return new WP_Error( 'api_error', $message, array( 'status' => $code ) );
        }

        return array(
            'code' => $code,
            'data' => $data,
        );
    }

    /**
     * Generate a unique storage zone name using domain prefix and random characters.
     *
     * Format: wp-[first 4 chars of domain]-[random string]
     * Example: wp-bass-a1b2c3d4e5f6 for basstourist.com
     *
     * @return string
     */
    public function generate_zone_name() {
        // Get the site domain and extract first 4 characters.
        $site_url      = wp_parse_url( site_url(), PHP_URL_HOST );
        $domain_prefix = '';

        if ( ! empty( $site_url ) ) {
            // Remove www. prefix if present.
            $domain = preg_replace( '/^www\./', '', $site_url );
            // Get first 4 alphanumeric characters of the domain.
            $domain_prefix = preg_replace( '/[^a-z0-9]/', '', strtolower( $domain ) );
            $domain_prefix = substr( $domain_prefix, 0, 4 );
        }

        // Fallback if domain prefix is empty or too short - pad with random chars.
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        while ( strlen( $domain_prefix ) < 4 ) {
            $domain_prefix .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        }

        // Generate a random alphanumeric string (12 chars for uniqueness).
        $chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $random = '';

        for ( $i = 0; $i < 12; $i++ ) {
            $random .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        }

        return 'wp-' . $domain_prefix . '-' . $random;
    }

    /**
     * Get list of storage zones.
     *
     * @return array|WP_Error
     */
    public function get_storage_zones() {
        return $this->request( '/storagezone' );
    }

    /**
     * Create a new storage zone.
     *
     * @param string $name                Storage zone name.
     * @param string $region              Region code (DE, NY, LA, SG, SYD).
     * @param bool   $enable_replication  Whether to enable replication regions.
     * @return array|WP_Error
     */
    public function create_storage_zone( $name, $region = 'DE', $enable_replication = true ) {
        $body = array(
            'Name'     => $name,
            'Region'   => $region,
            'ZoneTier' => 0, // Standard tier.
        );

        // Add replication regions for redundancy.
        if ( $enable_replication && isset( self::REPLICATION_REGIONS[ $region ] ) ) {
            $body['ReplicationRegions'] = self::REPLICATION_REGIONS[ $region ];
        }

        $response = $this->request( '/storagezone', 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $replication_info = '';
        if ( $enable_replication && isset( self::REPLICATION_REGIONS[ $region ] ) ) {
            $replication_info = ' with replication to ' . implode( ', ', self::REPLICATION_REGIONS[ $region ] );
        }

        bunny_net_offload_gelform()->log( sprintf( 'Storage zone created: %s in %s%s', $name, $region, $replication_info ), 'info' );

        return $response;
    }

    /**
     * Delete a storage zone.
     *
     * @param int $zone_id Storage zone ID.
     * @return array|WP_Error
     */
    public function delete_storage_zone( $zone_id ) {
        return $this->request( '/storagezone/' . intval( $zone_id ), 'DELETE' );
    }

    /**
     * Get list of pull zones.
     *
     * @return array|WP_Error
     */
    public function get_pull_zones() {
        return $this->request( '/pullzone' );
    }

    /**
     * Create a new pull zone linked to a storage zone.
     *
     * @param string $name            Pull zone name.
     * @param int    $storage_zone_id Storage zone ID to link.
     * @return array|WP_Error
     */
    public function create_pull_zone( $name, $storage_zone_id ) {
        $body = array(
            'Name'          => $name,
            'StorageZoneId' => $storage_zone_id,
            'Type'          => 2, // Storage zone pull type.
        );

        $response = $this->request( '/pullzone', 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        bunny_net_offload_gelform()->log( sprintf( 'Pull zone created: %s linked to storage zone %d', $name, $storage_zone_id ), 'info' );

        return $response;
    }

    /**
     * Delete a pull zone.
     *
     * @param int $zone_id Pull zone ID.
     * @return array|WP_Error
     */
    public function delete_pull_zone( $zone_id ) {
        return $this->request( '/pullzone/' . intval( $zone_id ), 'DELETE' );
    }

    /**
     * Purge the CDN cache for a pull zone.
     *
     * @param int $zone_id Pull zone ID.
     * @return array|WP_Error
     */
    public function purge_cache( $zone_id ) {
        return $this->request( '/pullzone/' . intval( $zone_id ) . '/purgeCache', 'POST' );
    }

    /**
     * Purge a specific URL from cache.
     *
     * @param string $url The URL to purge.
     * @return array|WP_Error
     */
    public function purge_url( $url ) {
        $body = array(
            'Url' => $url,
        );

        return $this->request( '/purge', 'POST', $body );
    }

    /**
     * Get storage region info.
     *
     * @param string $region Region code.
     * @return array|null
     */
    public function get_region_info( $region ) {
        return isset( self::STORAGE_REGIONS[ $region ] ) ? self::STORAGE_REGIONS[ $region ] : null;
    }

    /**
     * Get all available storage regions.
     *
     * @return array
     */
    public function get_available_regions() {
        return self::STORAGE_REGIONS;
    }

    /**
     * Get replication regions for a primary region.
     *
     * @param string $region Primary region code.
     * @return array Array of replication region codes.
     */
    public function get_replication_regions( $region ) {
        return isset( self::REPLICATION_REGIONS[ $region ] ) ? self::REPLICATION_REGIONS[ $region ] : array();
    }

    /**
     * Get storage hostname for a region.
     *
     * @param string $region Region code.
     * @return string
     */
    public function get_storage_hostname( $region ) {
        $info = $this->get_region_info( $region );
        return $info ? $info['hostname'] : 'storage.bunnycdn.com';
    }

    /**
     * Setup CDN: Create storage zone and pull zone.
     *
     * @param string      $region            Region code.
     * @param string|null $custom_zone_name  Optional custom zone name. If null, auto-generates.
     * @return array|WP_Error Result with zone details or error.
     */
    public function setup_cdn( $region = 'DE', $custom_zone_name = null ) {
        // Use custom zone name if provided, otherwise generate one.
        $zone_name = ! empty( $custom_zone_name ) ? $custom_zone_name : $this->generate_zone_name();

        // Create storage zone.
        $storage_response = $this->create_storage_zone( $zone_name, $region );

        if ( is_wp_error( $storage_response ) ) {
            return $storage_response;
        }

        $storage_data = $storage_response['data'];
        $storage_id   = $storage_data['Id'];
        $storage_pass = $storage_data['Password'];

        // Create pull zone linked to storage zone.
        $pull_response = $this->create_pull_zone( $zone_name, $storage_id );

        if ( is_wp_error( $pull_response ) ) {
            // Cleanup: delete the storage zone we just created.
            $this->delete_storage_zone( $storage_id );
            return $pull_response;
        }

        $pull_data = $pull_response['data'];
        $pull_id   = $pull_data['Id'];

        // Extract the CDN hostname.
        $cdn_url = '';
        if ( ! empty( $pull_data['Hostnames'] ) ) {
            foreach ( $pull_data['Hostnames'] as $hostname ) {
                if ( ! empty( $hostname['IsSystemHostname'] ) ) {
                    $cdn_url = 'https://' . $hostname['Value'];
                    break;
                }
            }
        }

        // Fallback to constructed URL.
        if ( empty( $cdn_url ) ) {
            $cdn_url = 'https://' . $zone_name . '.b-cdn.net';
        }

        return array(
            'storage_zone_id'       => $storage_id,
            'storage_zone_name'     => $zone_name,
            'storage_zone_password' => $storage_pass,
            'storage_region'        => $region,
            'storage_hostname'      => $this->get_storage_hostname( $region ),
            'pull_zone_id'          => $pull_id,
            'cdn_url'               => $cdn_url,
        );
    }

    /**
     * Tear down CDN: Delete pull zone and storage zone.
     *
     * @return bool|WP_Error
     */
    public function teardown_cdn() {
        $config = bunny_net_offload_gelform()->get_config();

        // Delete pull zone first.
        if ( ! empty( $config['pull_zone_id'] ) ) {
            $result = $this->delete_pull_zone( $config['pull_zone_id'] );
            if ( is_wp_error( $result ) ) {
                bunny_net_offload_gelform()->log( 'Failed to delete pull zone: ' . $result->get_error_message(), 'error' );
            }
        }

        // Delete storage zone.
        if ( ! empty( $config['storage_zone_id'] ) ) {
            $result = $this->delete_storage_zone( $config['storage_zone_id'] );
            if ( is_wp_error( $result ) ) {
                bunny_net_offload_gelform()->log( 'Failed to delete storage zone: ' . $result->get_error_message(), 'error' );
                return $result;
            }
        }

        return true;
    }
}
