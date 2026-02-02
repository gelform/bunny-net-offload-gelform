<?php
/**
 * Media Handler
 *
 * Hooks into WordPress media upload process to process and upload images to CDN.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Media Handler class.
 *
 * @since 1.0.0
 */
class BNOG_Media_Handler {

    /**
     * Queue option name for background processing.
     *
     * @var string
     */
    const QUEUE_OPTION = 'bnog_upload_queue';

    /**
     * Constructor.
     */
    public function __construct() {
        // Hook into upload process.
        add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), 10, 2 );

        // Hook into attachment creation.
        add_action( 'add_attachment', array( $this, 'process_attachment' ), 10, 1 );

        // Process intermediate sizes.
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_thumbnails' ), 10, 2 );

        // Handle attachment deletion.
        add_action( 'delete_attachment', array( $this, 'handle_delete' ), 10, 1 );

        // Background processing cron.
        add_action( 'bnog_process_queue', array( $this, 'process_queue' ) );
        add_action( 'bnog_process_delete_local_queue', array( $this, 'process_delete_local_queue' ) );

        // AJAX handlers for sync.
        add_action( 'wp_ajax_bnog_sync_media', array( $this, 'ajax_sync_media' ) );
        add_action( 'wp_ajax_bnog_get_sync_status', array( $this, 'ajax_get_sync_status' ) );
        add_action( 'wp_ajax_bnog_cancel_sync', array( $this, 'ajax_cancel_sync' ) );
    }

    /**
     * Handle file upload - process image before WordPress saves it.
     *
     * @param array  $upload Upload data array.
     * @param string $context Upload context.
     * @return array Modified upload data.
     */
    public function handle_upload( $upload, $context = 'upload' ) {
        // Skip if not configured.
        if ( ! bunny_net_offload_gelform()->is_configured() ) {
            return $upload;
        }

        // Skip if error in upload.
        if ( isset( $upload['error'] ) && $upload['error'] ) {
            return $upload;
        }

        // Skip non-images.
        if ( ! bunny_net_offload_gelform()->image_processor->is_processable_image( $upload['file'] ) ) {
            return $upload;
        }

        // Process the image (resize and compress).
        $processed = bunny_net_offload_gelform()->image_processor->process( $upload['file'] );

        if ( is_wp_error( $processed ) ) {
            bunny_net_offload_gelform()->log( 'Image processing failed: ' . $processed->get_error_message(), 'error' );
            // Continue with original file.
            return $upload;
        }

        // Update file path if it changed.
        if ( $processed !== $upload['file'] ) {
            $upload['file'] = $processed;
        }

        return $upload;
    }

    /**
     * Process attachment after it's added to database.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function process_attachment( $attachment_id ) {
        // Skip if not configured.
        if ( ! bunny_net_offload_gelform()->is_configured() ) {
            return;
        }

        // Get attachment file path.
        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return;
        }

        // Check if this is an image or if sync_all_files is enabled.
        $is_image   = bunny_net_offload_gelform()->image_processor->is_processable_image( $file_path );
        $config     = bunny_net_offload_gelform()->get_config();
        $sync_all   = ! empty( $config['sync_all_files'] );

        // Skip non-images if sync_all_files is not enabled.
        if ( ! $is_image && ! $sync_all ) {
            return;
        }

        // Add to upload queue for background processing.
        $this->add_to_queue( $attachment_id );
    }

    /**
     * Process thumbnails after they're generated.
     *
     * @param array $metadata     Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array Modified metadata.
     */
    public function process_thumbnails( $metadata, $attachment_id ) {
        // Skip if not configured.
        if ( ! bunny_net_offload_gelform()->is_configured() ) {
            return $metadata;
        }

        // Get the main file path.
        $main_file = get_attached_file( $attachment_id );

        if ( ! $main_file || ! file_exists( $main_file ) ) {
            return $metadata;
        }

        // Check if this is an image or if sync_all_files is enabled.
        $is_image = bunny_net_offload_gelform()->image_processor->is_processable_image( $main_file );
        $config   = bunny_net_offload_gelform()->get_config();
        $sync_all = ! empty( $config['sync_all_files'] );

        // Skip non-images if sync_all_files is not enabled.
        if ( ! $is_image && ! $sync_all ) {
            return $metadata;
        }

        // Upload main file.
        $remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $main_file );
        $cdn_url     = bunny_net_offload_gelform()->storage->upload( $main_file, $remote_path );

        if ( ! is_wp_error( $cdn_url ) ) {
            update_post_meta( $attachment_id, '_bnog_cdn_url', $cdn_url );
            update_post_meta( $attachment_id, '_bnog_synced', true );

            // Optionally delete local file.
            if ( empty( $config['keep_local_files'] ) ) {
                wp_delete_file( $main_file );
            }
        } else {
            bunny_net_offload_gelform()->log( 'Failed to upload main file: ' . $cdn_url->get_error_message(), 'error' );
        }

        // Upload thumbnails (only for images).
        if ( $is_image && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $main_file );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $thumb_file = $upload_dir . '/' . $size_data['file'];

                if ( ! file_exists( $thumb_file ) ) {
                    continue;
                }

                $thumb_remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $thumb_file );
                $thumb_cdn_url     = bunny_net_offload_gelform()->storage->upload( $thumb_file, $thumb_remote_path );

                if ( ! is_wp_error( $thumb_cdn_url ) ) {
                    update_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_name, $thumb_cdn_url );

                    // Optionally delete local thumbnail.
                    if ( empty( $config['keep_local_files'] ) ) {
                        wp_delete_file( $thumb_file );
                    }
                }
            }
        }

        return $metadata;
    }

    /**
     * Handle attachment deletion.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function handle_delete( $attachment_id ) {
        // Skip if not configured.
        if ( ! bunny_net_offload_gelform()->is_configured() ) {
            return;
        }

        // Get attachment metadata.
        $metadata  = wp_get_attachment_metadata( $attachment_id );
        $main_file = get_attached_file( $attachment_id );

        if ( ! $main_file ) {
            return;
        }

        // Delete main file from CDN.
        $remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $main_file );
        bunny_net_offload_gelform()->storage->delete( $remote_path );

        // Delete thumbnails from CDN.
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $main_file );

            foreach ( $metadata['sizes'] as $size_data ) {
                $thumb_file        = $upload_dir . '/' . $size_data['file'];
                $thumb_remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $thumb_file );
                bunny_net_offload_gelform()->storage->delete( $thumb_remote_path );
            }
        }

        // Clean up post meta.
        delete_post_meta( $attachment_id, '_bnog_cdn_url' );
        delete_post_meta( $attachment_id, '_bnog_synced' );

        // Delete all size-specific CDN URLs (only plugin-specific meta).
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                $attachment_id,
                $wpdb->esc_like( '_bnog_cdn_url_' ) . '%'
            )
        );
    }

    /**
     * Add attachment to upload queue.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function add_to_queue( $attachment_id ) {
        $queue   = get_option( self::QUEUE_OPTION, array() );
        $queue[] = $attachment_id;
        $queue   = array_unique( $queue );
        update_option( self::QUEUE_OPTION, $queue );
    }

    /**
     * Remove attachment from upload queue.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function remove_from_queue( $attachment_id ) {
        $queue = get_option( self::QUEUE_OPTION, array() );
        $queue = array_diff( $queue, array( $attachment_id ) );
        update_option( self::QUEUE_OPTION, array_values( $queue ) );
    }

    /**
     * Process upload queue (cron job).
     */
    public function process_queue() {
        $queue = get_option( self::QUEUE_OPTION, array() );

        if ( empty( $queue ) ) {
            // Clear running status when queue is empty.
            $status = get_option( 'bnog_sync_status', array() );
            if ( ! empty( $status['running'] ) ) {
                $status['running'] = false;
                update_option( 'bnog_sync_status', $status );
            }
            return;
        }

        // Get sync status to check resize option.
        $status             = get_option( 'bnog_sync_status', array() );
        $resize_before_sync = ! empty( $status['resize_before_sync'] );

        // Process in batches of 10.
        $batch = array_slice( $queue, 0, 10 );

        foreach ( $batch as $attachment_id ) {
            $result = $this->sync_attachment( $attachment_id, $resize_before_sync );

            // Only remove from queue if sync succeeded.
            if ( ! is_wp_error( $result ) ) {
                $this->remove_from_queue( $attachment_id );
            } else {
                // Log the error and track it in status.
                bunny_net_offload_gelform()->log(
                    sprintf( 'Failed to sync attachment %d: %s', $attachment_id, $result->get_error_message() ),
                    'error'
                );

                // Move failed item to end of queue to retry later.
                $this->remove_from_queue( $attachment_id );
                $current_queue = get_option( self::QUEUE_OPTION, array() );
                $current_queue[] = $attachment_id;
                update_option( self::QUEUE_OPTION, array_unique( $current_queue ) );

                // Track error count.
                $status = get_option( 'bnog_sync_status', array() );
                $status['errors'] = isset( $status['errors'] ) ? $status['errors'] + 1 : 1;

                // If we've had too many consecutive errors, pause sync.
                if ( $status['errors'] >= 50 ) {
                    $status['running'] = false;
                    $status['paused_reason'] = __( 'Sync paused due to too many errors. Check your file types and CDN configuration.', 'bunny-net-offload-gelform' );
                    update_option( 'bnog_sync_status', $status );
                    return;
                }
                update_option( 'bnog_sync_status', $status );
            }
        }

        // Check if queue is now empty.
        $remaining_queue = get_option( self::QUEUE_OPTION, array() );
        if ( empty( $remaining_queue ) ) {
            $status = get_option( 'bnog_sync_status', array() );
            $status['running'] = false;
            update_option( 'bnog_sync_status', $status );
        }
    }

    /**
     * Process delete local files queue (cron job).
     *
     * Deletes local files that are verified to exist on CDN.
     */
    public function process_delete_local_queue() {
        $queue = get_option( 'bnog_delete_local_queue', array() );

        if ( empty( $queue ) ) {
            // Clear running status when queue is empty.
            $status = get_option( 'bnog_delete_local_status', array() );
            if ( ! empty( $status['running'] ) ) {
                $status['running'] = false;
                update_option( 'bnog_delete_local_status', $status );
            }
            return;
        }

        // Process in batches of 10.
        $batch = array_slice( $queue, 0, 10 );

        foreach ( $batch as $attachment_id ) {
            $result = $this->delete_local_attachment_files( $attachment_id );

            // Remove from queue regardless of result.
            $this->remove_from_delete_local_queue( $attachment_id );

            // Update status.
            $status = get_option( 'bnog_delete_local_status', array() );
            $status['processed'] = isset( $status['processed'] ) ? $status['processed'] + 1 : 1;

            if ( true === $result ) {
                $status['deleted'] = isset( $status['deleted'] ) ? $status['deleted'] + 1 : 1;
            } elseif ( 'skipped' === $result ) {
                $status['skipped'] = isset( $status['skipped'] ) ? $status['skipped'] + 1 : 1;
            } else {
                $status['errors'] = isset( $status['errors'] ) ? $status['errors'] + 1 : 1;
            }

            update_option( 'bnog_delete_local_status', $status );
        }

        // Check if queue is now empty.
        $remaining_queue = get_option( 'bnog_delete_local_queue', array() );
        if ( empty( $remaining_queue ) ) {
            $status = get_option( 'bnog_delete_local_status', array() );
            $status['running'] = false;
            update_option( 'bnog_delete_local_status', $status );
        } else {
            // Schedule next batch (only if not already scheduled).
            if ( ! wp_next_scheduled( 'bnog_process_delete_local_queue' ) ) {
                wp_schedule_single_event( time() + 1, 'bnog_process_delete_local_queue' );
            }
        }
    }

    /**
     * Remove attachment from delete local queue.
     *
     * @param int $attachment_id Attachment ID.
     */
    private function remove_from_delete_local_queue( $attachment_id ) {
        $queue = get_option( 'bnog_delete_local_queue', array() );
        $queue = array_diff( $queue, array( $attachment_id ) );
        update_option( 'bnog_delete_local_queue', array_values( $queue ) );
    }

    /**
     * Delete local files for an attachment after verifying they exist on CDN.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|string True on success, 'skipped' if not on CDN, false on error.
     */
    private function delete_local_attachment_files( $attachment_id ) {
        $main_file = get_attached_file( $attachment_id );

        if ( ! $main_file ) {
            return 'skipped';
        }

        // Check if main file exists locally - if not, nothing to delete.
        if ( ! file_exists( $main_file ) ) {
            return 'skipped';
        }

        // Verify file exists on CDN before deleting locally.
        $remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $main_file );
        if ( ! bunny_net_offload_gelform()->storage->exists( $remote_path ) ) {
            bunny_net_offload_gelform()->log(
                sprintf( 'Skipping local delete for attachment %d: not found on CDN', $attachment_id ),
                'warning'
            );
            return 'skipped';
        }

        // Get metadata for thumbnails.
        $metadata     = wp_get_attachment_metadata( $attachment_id );
        $thumb_files  = array();
        $all_verified = true;

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $main_file );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $thumb_file = $upload_dir . '/' . $size_data['file'];

                if ( ! file_exists( $thumb_file ) ) {
                    continue;
                }

                // Verify thumbnail exists on CDN.
                $thumb_remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $thumb_file );
                if ( ! bunny_net_offload_gelform()->storage->exists( $thumb_remote_path ) ) {
                    $all_verified = false;
                    bunny_net_offload_gelform()->log(
                        sprintf( 'Thumbnail %s for attachment %d not found on CDN', $size_name, $attachment_id ),
                        'warning'
                    );
                } else {
                    $thumb_files[] = $thumb_file;
                }
            }
        }

        // Only delete if all files are verified on CDN.
        if ( ! $all_verified ) {
            return 'skipped';
        }

        // Delete main file.
        wp_delete_file( $main_file );

        // Delete original file if WordPress created a scaled version.
        if ( ! empty( $metadata['original_image'] ) ) {
            $upload_dir    = dirname( $main_file );
            $original_file = $upload_dir . '/' . $metadata['original_image'];
            if ( file_exists( $original_file ) ) {
                // Verify original exists on CDN.
                $original_remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $original_file );
                if ( bunny_net_offload_gelform()->storage->exists( $original_remote_path ) ) {
                    wp_delete_file( $original_file );
                }
            }
        }

        // Delete thumbnail files.
        foreach ( $thumb_files as $thumb_file ) {
            if ( file_exists( $thumb_file ) ) {
                wp_delete_file( $thumb_file );
            }
        }

        bunny_net_offload_gelform()->log(
            sprintf( 'Deleted local files for attachment %d after CDN verification.', $attachment_id ),
            'info'
        );

        return true;
    }

    /**
     * Sync a single attachment to CDN.
     *
     * @param int  $attachment_id     Attachment ID.
     * @param bool $resize_before_sync Whether to resize/compress before syncing.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function sync_attachment( $attachment_id, $resize_before_sync = false ) {
        $main_file = get_attached_file( $attachment_id );

        if ( ! $main_file || ! file_exists( $main_file ) ) {
            // Mark as synced anyway so it doesn't keep showing as "waiting to sync".
            // The file doesn't exist locally, so there's nothing to sync.
            update_post_meta( $attachment_id, '_bnog_synced', true );
            update_post_meta( $attachment_id, '_bnog_sync_skipped', 'file_not_found' );
            return true;
        }

        // Process image if resize option is enabled.
        if ( $resize_before_sync && bunny_net_offload_gelform()->image_processor->is_processable_image( $main_file ) ) {
            $processed = bunny_net_offload_gelform()->image_processor->process( $main_file );

            if ( is_wp_error( $processed ) ) {
                bunny_net_offload_gelform()->log( 'Image processing failed during sync: ' . $processed->get_error_message(), 'warning' );
                // Continue with original file.
            } else {
                // Update attachment metadata with new dimensions.
                $this->update_attachment_dimensions( $attachment_id, $main_file );
            }
        }

        // Upload main file.
        $remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $main_file );
        $cdn_url     = bunny_net_offload_gelform()->storage->upload( $main_file, $remote_path );

        if ( is_wp_error( $cdn_url ) ) {
            return $cdn_url;
        }

        update_post_meta( $attachment_id, '_bnog_cdn_url', $cdn_url );
        update_post_meta( $attachment_id, '_bnog_synced', true );

        // Get config for keep_local_files option.
        $config           = bunny_net_offload_gelform()->get_config();
        $keep_local_files = ! empty( $config['keep_local_files'] );

        // Sync thumbnails.
        $metadata         = wp_get_attachment_metadata( $attachment_id );
        $metadata_updated = false;
        $thumb_files      = array();

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $main_file );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $thumb_file = $upload_dir . '/' . $size_data['file'];

                if ( ! file_exists( $thumb_file ) ) {
                    continue;
                }

                // Track thumbnail files for potential deletion.
                $thumb_files[] = $thumb_file;

                // Process thumbnail if resize option is enabled.
                if ( $resize_before_sync && bunny_net_offload_gelform()->image_processor->is_processable_image( $thumb_file ) ) {
                    $processed = bunny_net_offload_gelform()->image_processor->process( $thumb_file );

                    // Update thumbnail dimensions in metadata if processed.
                    if ( ! is_wp_error( $processed ) ) {
                        $thumb_size = wp_getimagesize( $thumb_file );
                        if ( $thumb_size ) {
                            $metadata['sizes'][ $size_name ]['width']  = $thumb_size[0];
                            $metadata['sizes'][ $size_name ]['height'] = $thumb_size[1];
                            $metadata_updated = true;
                        }
                    }
                }

                $thumb_remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $thumb_file );
                $thumb_cdn_url     = bunny_net_offload_gelform()->storage->upload( $thumb_file, $thumb_remote_path );

                if ( ! is_wp_error( $thumb_cdn_url ) ) {
                    update_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_name, $thumb_cdn_url );
                }
            }
        }

        // Save updated metadata if thumbnails were resized.
        if ( $metadata_updated ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        // Delete local files if keep_local_files is disabled.
        if ( ! $keep_local_files ) {
            // Delete main file (may be -scaled version).
            if ( file_exists( $main_file ) ) {
                wp_delete_file( $main_file );
            }

            // Delete original file if WordPress created a scaled version.
            // WordPress stores original filename in metadata when it creates a -scaled version.
            if ( ! empty( $metadata['original_image'] ) ) {
                $upload_dir    = dirname( $main_file );
                $original_file = $upload_dir . '/' . $metadata['original_image'];
                if ( file_exists( $original_file ) ) {
                    wp_delete_file( $original_file );
                }
            }

            // Delete thumbnail files.
            foreach ( $thumb_files as $thumb_file ) {
                if ( file_exists( $thumb_file ) ) {
                    wp_delete_file( $thumb_file );
                }
            }

            bunny_net_offload_gelform()->log(
                sprintf( 'Deleted local files for attachment %d after CDN sync.', $attachment_id ),
                'info'
            );
        }

        return true;
    }

    /**
     * Update attachment metadata with new dimensions after resize.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file_path     Path to the resized file.
     */
    private function update_attachment_dimensions( $attachment_id, $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return;
        }

        // Get new dimensions from file.
        $image_size = wp_getimagesize( $file_path );

        if ( ! $image_size ) {
            return;
        }

        $new_width  = $image_size[0];
        $new_height = $image_size[1];

        // Get current metadata.
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) ) {
            $metadata = array();
        }

        // Update dimensions.
        $metadata['width']  = $new_width;
        $metadata['height'] = $new_height;

        // Update the metadata in database.
        wp_update_attachment_metadata( $attachment_id, $metadata );

        bunny_net_offload_gelform()->log(
            sprintf(
                'Updated attachment %d metadata: %dx%d',
                $attachment_id,
                $new_width,
                $new_height
            ),
            'info'
        );
    }

    /**
     * AJAX handler for syncing existing media.
     */
    public function ajax_sync_media() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        // Get resize option.
        $resize_before_sync = ! empty( $_POST['resize_before_sync'] );

        // Check if we should sync all files or just images.
        $config   = bunny_net_offload_gelform()->get_config();
        $sync_all = ! empty( $config['sync_all_files'] );

        // Get unsynced attachment IDs using direct SQL for better memory efficiency.
        global $wpdb;

        if ( $sync_all ) {
            // Get all unsynced attachments.
            $attachments = $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bnog_synced'
                WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND pm.meta_id IS NULL"
            );
        } else {
            // Get only unsynced image attachments.
            $attachments = $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bnog_synced'
                WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND p.post_mime_type LIKE 'image/%'
                AND pm.meta_id IS NULL"
            );
        }

        // Convert to integers.
        $attachments = array_map( 'intval', $attachments );

        if ( empty( $attachments ) ) {
            $already_synced_message = $sync_all
                ? __( 'All files are already synced.', 'bunny-net-offload-gelform' )
                : __( 'All images are already synced.', 'bunny-net-offload-gelform' );

            wp_send_json_success(
                array(
                    'message' => $already_synced_message,
                    'total'   => 0,
                )
            );
        }

        // Store sync status including resize option.
        update_option(
            'bnog_sync_status',
            array(
                'total'              => count( $attachments ),
                'processed'          => 0,
                'errors'             => 0,
                'running'            => true,
                'started'            => time(),
                'resize_before_sync' => $resize_before_sync,
            )
        );

        // Add all to queue.
        $queue = $attachments;
        update_option( self::QUEUE_OPTION, $queue );

        // Trigger immediate processing.
        wp_schedule_single_event( time(), 'bnog_process_queue' );

        if ( $sync_all ) {
            $message = $resize_before_sync
                ? __( 'Syncing %d files with image resizing. This will happen in the background.', 'bunny-net-offload-gelform' )
                : __( 'Syncing %d files. This will happen in the background.', 'bunny-net-offload-gelform' );
        } else {
            $message = $resize_before_sync
                ? __( 'Syncing %d images with resizing. This will happen in the background.', 'bunny-net-offload-gelform' )
                : __( 'Syncing %d images. This will happen in the background.', 'bunny-net-offload-gelform' );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: number of files */
                    $message,
                    count( $attachments )
                ),
                'total'   => count( $attachments ),
            )
        );
    }

    /**
     * AJAX handler to get sync status.
     */
    public function ajax_get_sync_status() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        $status = get_option( 'bnog_sync_status', array() );
        $queue  = get_option( self::QUEUE_OPTION, array() );

        // Calculate processed count.
        $total     = isset( $status['total'] ) ? $status['total'] : 0;
        $remaining = count( $queue );
        $processed = $total - $remaining;

        // Count total synced.
        $synced_count = $this->get_synced_count();

        wp_send_json_success(
            array(
                'total'        => $total,
                'processed'    => $processed,
                'remaining'    => $remaining,
                'synced_count' => $synced_count,
                'running'      => ! empty( $status['running'] ) && $remaining > 0,
            )
        );
    }

    /**
     * AJAX handler to cancel sync.
     */
    public function ajax_cancel_sync() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        // Clear queue.
        delete_option( self::QUEUE_OPTION );

        // Update status.
        $status            = get_option( 'bnog_sync_status', array() );
        $status['running'] = false;
        update_option( 'bnog_sync_status', $status );

        wp_send_json_success( array( 'message' => __( 'Sync cancelled.', 'bunny-net-offload-gelform' ) ) );
    }

    /**
     * Get count of synced files.
     *
     * @return int
     */
    public function get_synced_count() {
        global $wpdb;

        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_bnog_synced'"
        );

        return intval( $count );
    }

    /**
     * Get count of unsynced files.
     *
     * Uses direct SQL query for better performance on large media libraries.
     *
     * @return int
     */
    public function get_unsynced_count() {
        global $wpdb;

        // Check if we should count all files or just images.
        $config   = bunny_net_offload_gelform()->get_config();
        $sync_all = ! empty( $config['sync_all_files'] );

        // Build query to count attachments without _bnog_synced meta.
        if ( $sync_all ) {
            // Count all attachments not synced.
            $count = $wpdb->get_var(
                "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bnog_synced'
                WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND pm.meta_id IS NULL"
            );
        } else {
            // Count only image attachments not synced.
            $count = $wpdb->get_var(
                "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bnog_synced'
                WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND p.post_mime_type LIKE 'image/%'
                AND pm.meta_id IS NULL"
            );
        }

        return intval( $count );
    }

    /**
     * Get total image count.
     *
     * @return int
     */
    public function get_total_image_count() {
        $count = wp_count_posts( 'attachment' );
        $total = 0;

        // Get image mime types.
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );

        $attachments = get_posts( $args );

        return count( $attachments );
    }
}
