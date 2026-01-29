<?php
/**
 * Uninstall Script
 *
 * Cleanup when the plugin is deleted.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access and verify uninstall is triggered properly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data on uninstall.
 *
 * Note: By default, this only removes WordPress options and post meta.
 * Remote CDN content (storage zone, pull zone) is preserved to prevent
 * accidental data loss. Users should manually delete these through the
 * Bunny.net dashboard if needed.
 */

// Delete plugin options.
delete_option( 'bnog_api_key' );
delete_option( 'bnog_config' );
delete_option( 'bnog_sync_status' );
delete_option( 'bnog_upload_queue' );

// Delete transients.
delete_transient( 'bnog_cdn_available' );
delete_transient( 'bnog_auth_error' );
delete_transient( 'bnog_debug_callback_params' );

// Delete all attachment meta related to this plugin.
global $wpdb;

// Delete CDN URL meta.
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_bnog_%'"
);

// Clean up scheduled events.
wp_clear_scheduled_hook( 'bnog_process_queue' );

// Remove temp directory if exists.
$upload_dir = wp_upload_dir();
$temp_dir   = $upload_dir['basedir'] . '/bnog-temp';

if ( is_dir( $temp_dir ) ) {
    // Remove all files in temp directory.
    $files = glob( $temp_dir . '/*' );
    foreach ( $files as $file ) {
        if ( is_file( $file ) ) {
            wp_delete_file( $file );
        }
    }

    // Remove .htaccess.
    $htaccess = $temp_dir . '/.htaccess';
    if ( file_exists( $htaccess ) ) {
        wp_delete_file( $htaccess );
    }

    // Remove directory.
    @rmdir( $temp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * Note: We intentionally do NOT delete the CDN storage zone and pull zone.
 *
 * Reasons:
 * 1. User may want to keep their CDN content for backup or migration.
 * 2. Accidental plugin deletion shouldn't result in data loss.
 * 3. User can easily delete these through the Bunny.net dashboard.
 *
 * To fully remove CDN resources:
 * 1. Log into Bunny.net dashboard (https://dash.bunny.net)
 * 2. Go to Storage Zones and delete the zone created by this plugin.
 * 3. Go to Pull Zones and delete the zone created by this plugin.
 *
 * The zone name format is: {your-domain}-{random-chars}
 * Example: mysite-com-x7k2m9
 */
