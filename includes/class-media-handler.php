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

        // Skip non-images.
        if ( ! bunny_net_offload_gelform()->image_processor->is_processable_image( $file_path ) ) {
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

        // Skip non-images.
        if ( ! bunny_net_offload_gelform()->image_processor->is_processable_image( $main_file ) ) {
            return $metadata;
        }

        // Upload main file.
        $remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $main_file );
        $cdn_url     = bunny_net_offload_gelform()->storage->upload( $main_file, $remote_path );

        if ( ! is_wp_error( $cdn_url ) ) {
            update_post_meta( $attachment_id, '_bnog_cdn_url', $cdn_url );
            update_post_meta( $attachment_id, '_bnog_synced', true );

            // Optionally delete local file.
            $config = bunny_net_offload_gelform()->get_config();
            if ( empty( $config['keep_local_files'] ) ) {
                wp_delete_file( $main_file );
            }
        } else {
            bunny_net_offload_gelform()->log( 'Failed to upload main file: ' . $cdn_url->get_error_message(), 'error' );
        }

        // Upload thumbnails.
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
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
            return;
        }

        // Get sync status to check resize option.
        $status             = get_option( 'bnog_sync_status', array() );
        $resize_before_sync = ! empty( $status['resize_before_sync'] );

        // Process in batches of 10.
        $batch = array_slice( $queue, 0, 10 );

        foreach ( $batch as $attachment_id ) {
            $this->sync_attachment( $attachment_id, $resize_before_sync );
            $this->remove_from_queue( $attachment_id );
        }
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
            return new WP_Error( 'file_not_found', __( 'Attachment file not found.', 'bunny-net-offload-gelform' ) );
        }

        // Process image if resize option is enabled.
        if ( $resize_before_sync && bunny_net_offload_gelform()->image_processor->is_processable_image( $main_file ) ) {
            $processed = bunny_net_offload_gelform()->image_processor->process( $main_file );

            if ( is_wp_error( $processed ) ) {
                bunny_net_offload_gelform()->log( 'Image processing failed during sync: ' . $processed->get_error_message(), 'warning' );
                // Continue with original file.
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

        // Sync thumbnails.
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $main_file );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $thumb_file = $upload_dir . '/' . $size_data['file'];

                if ( ! file_exists( $thumb_file ) ) {
                    continue;
                }

                // Process thumbnail if resize option is enabled.
                if ( $resize_before_sync && bunny_net_offload_gelform()->image_processor->is_processable_image( $thumb_file ) ) {
                    bunny_net_offload_gelform()->image_processor->process( $thumb_file );
                }

                $thumb_remote_path = bunny_net_offload_gelform()->storage->path_to_remote_path( $thumb_file );
                $thumb_cdn_url     = bunny_net_offload_gelform()->storage->upload( $thumb_file, $thumb_remote_path );

                if ( ! is_wp_error( $thumb_cdn_url ) ) {
                    update_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_name, $thumb_cdn_url );
                }
            }
        }

        return true;
    }

    /**
     * AJAX handler for syncing existing media.
     */
    public function ajax_sync_media() {
        check_ajax_referer( 'bnog_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        // Get resize option.
        $resize_before_sync = ! empty( $_POST['resize_before_sync'] );

        // Get all image attachments not yet synced.
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_bnog_synced',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );

        $attachments = get_posts( $args );

        if ( empty( $attachments ) ) {
            wp_send_json_success(
                array(
                    'message' => __( 'All images are already synced.', 'bunny-net-offload-gelform' ),
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

        $message = $resize_before_sync
            ? __( 'Syncing %d images with resizing. This will happen in the background.', 'bunny-net-offload-gelform' )
            : __( 'Syncing %d images. This will happen in the background.', 'bunny-net-offload-gelform' );

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: number of images */
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
        check_ajax_referer( 'bnog_admin_nonce', 'nonce' );

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
        check_ajax_referer( 'bnog_admin_nonce', 'nonce' );

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
     * Get count of synced images.
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
     * Get count of unsynced images.
     *
     * @return int
     */
    public function get_unsynced_count() {
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_bnog_synced',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );

        $attachments = get_posts( $args );

        return count( $attachments );
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
