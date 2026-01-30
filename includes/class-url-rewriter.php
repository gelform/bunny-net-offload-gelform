<?php
/**
 * URL Rewriter
 *
 * Filters WordPress image URLs to serve from CDN.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * URL Rewriter class.
 *
 * @since 1.0.0
 */
class BNOG_URL_Rewriter {

    /**
     * Cache for CDN URL availability checks.
     *
     * @var array
     */
    private $availability_cache = array();

    /**
     * Cached effective CDN URL.
     *
     * @var string|null
     */
    private $effective_cdn_url = null;

    /**
     * Constructor.
     */
    public function __construct() {
        // Only hook if configured.
        add_action( 'init', array( $this, 'setup_filters' ) );
    }

    /**
     * Setup URL rewriting filters if configured.
     */
    public function setup_filters() {
        if ( ! bunny_net_offload_gelform()->is_configured() ) {
            return;
        }

        // Filter attachment URL.
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 99, 2 );

        // Filter attachment image src.
        add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 99, 4 );

        // Filter srcset for responsive images.
        add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 99, 5 );

        // Filter content URLs.
        add_filter( 'the_content', array( $this, 'filter_content' ), 99 );

        // Filter attachment image attributes.
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_image_attributes' ), 99, 3 );
    }

    /**
     * Filter attachment URL to use CDN.
     *
     * @param string $url           Attachment URL.
     * @param int    $attachment_id Attachment ID.
     * @return string Modified URL.
     */
    public function filter_attachment_url( $url, $attachment_id ) {
        // Check for stored CDN URL.
        $cdn_url = get_post_meta( $attachment_id, '_bnog_cdn_url', true );

        if ( ! empty( $cdn_url ) ) {
            // Verify CDN is available (with caching).
            if ( $this->is_cdn_available() ) {
                // Rewrite with custom domain if configured.
                return $this->rewrite_cdn_url_with_effective_base( $cdn_url );
            }
        }

        return $url;
    }

    /**
     * Filter image src array.
     *
     * @param array|false  $image         Image src array or false.
     * @param int          $attachment_id Attachment ID.
     * @param string|int[] $size          Image size.
     * @param bool         $icon          Whether the image should be treated as icon.
     * @return array|false Modified image src array.
     */
    public function filter_image_src( $image, $attachment_id, $size, $icon ) {
        if ( false === $image || ! is_array( $image ) ) {
            return $image;
        }

        // Check for size-specific CDN URL.
        $size_name = is_array( $size ) ? implode( 'x', $size ) : $size;
        $cdn_url   = get_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_name, true );

        if ( empty( $cdn_url ) ) {
            // Try main CDN URL.
            $cdn_url = get_post_meta( $attachment_id, '_bnog_cdn_url', true );
        }

        if ( ! empty( $cdn_url ) && $this->is_cdn_available() ) {
            // Rewrite with custom domain if configured.
            $cdn_url = $this->rewrite_cdn_url_with_effective_base( $cdn_url );

            // If we have a size-specific URL, use it.
            if ( strpos( $cdn_url, '-' . $image[1] . 'x' . $image[2] . '.' ) !== false ) {
                $image[0] = $cdn_url;
            } else {
                // Construct size-specific URL from main URL.
                $image[0] = $this->construct_size_url( $cdn_url, $attachment_id, $size );
            }
        }

        return $image;
    }

    /**
     * Filter srcset for responsive images.
     *
     * @param array  $sources       Array of image sources.
     * @param array  $size_array    Width and height of the image.
     * @param string $image_src     Image src.
     * @param array  $image_meta    Image meta.
     * @param int    $attachment_id Attachment ID.
     * @return array Modified sources array.
     */
    public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( empty( $sources ) || ! $this->is_cdn_available() ) {
            return $sources;
        }

        $cdn_base_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_base_url ) ) {
            return $sources;
        }

        // Get upload directory info.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        foreach ( $sources as $width => $source ) {
            // Check if this is already a CDN URL.
            if ( strpos( $source['url'], $cdn_base_url ) !== false ) {
                continue;
            }

            // Try to get size-specific CDN URL.
            $size_key      = $this->find_size_key_by_dimensions( $image_meta, $source['url'] );
            $size_cdn_url  = $size_key ? get_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_key, true ) : '';

            if ( ! empty( $size_cdn_url ) ) {
                // Rewrite with custom domain if configured.
                $sources[ $width ]['url'] = $this->rewrite_cdn_url_with_effective_base( $size_cdn_url );
            } else {
                // Convert local URL to CDN URL.
                $sources[ $width ]['url'] = $this->local_url_to_cdn( $source['url'] );
            }
        }

        return $sources;
    }

    /**
     * Filter post content to rewrite media URLs.
     *
     * @param string $content Post content.
     * @return string Modified content.
     */
    public function filter_content( $content ) {
        if ( empty( $content ) || ! $this->is_cdn_available() ) {
            return $content;
        }

        $cdn_base_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_base_url ) ) {
            return $content;
        }

        // Get upload directory info.
        $upload_dir = wp_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        // Build file extension pattern.
        $config        = bunny_net_offload_gelform()->get_config();
        $sync_all      = ! empty( $config['sync_all_files'] );

        // Image extensions are always included.
        $extensions = 'jpg|jpeg|png|gif|webp';

        // Add other file types if sync_all_files is enabled.
        if ( $sync_all ) {
            $extensions .= '|pdf|doc|docx|xls|xlsx|ppt|pptx|zip|rar|mp3|mp4|mov|avi|wmv|flv|ogg|wav|txt|csv';
        }

        // Match media URLs in content.
        $pattern = '/(' . $base_url . '\/[^\s"\'<>]+\.(?:' . $extensions . '))/i';

        $content = preg_replace_callback(
            $pattern,
            function ( $matches ) {
                return $this->local_url_to_cdn( $matches[1] );
            },
            $content
        );

        return $content;
    }

    /**
     * Filter attachment image attributes.
     *
     * @param array        $attr       Image attributes.
     * @param WP_Post      $attachment Attachment post object.
     * @param string|int[] $size       Requested image size.
     * @return array Modified attributes.
     */
    public function filter_image_attributes( $attr, $attachment, $size ) {
        if ( ! $this->is_cdn_available() ) {
            return $attr;
        }

        // Filter src attribute.
        if ( ! empty( $attr['src'] ) ) {
            $attr['src'] = $this->local_url_to_cdn( $attr['src'] );
        }

        // Filter srcset attribute.
        if ( ! empty( $attr['srcset'] ) ) {
            $attr['srcset'] = $this->filter_srcset_string( $attr['srcset'] );
        }

        return $attr;
    }

    /**
     * Convert a local URL to CDN URL.
     *
     * @param string $local_url Local URL.
     * @return string CDN URL or original if not convertible.
     */
    public function local_url_to_cdn( $local_url ) {
        $cdn_base_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_base_url ) ) {
            return $local_url;
        }

        // Already a CDN URL.
        if ( strpos( $local_url, $cdn_base_url ) !== false ) {
            return $local_url;
        }

        // Get upload directory info.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // Check if this is an upload URL.
        if ( strpos( $local_url, $base_url ) !== false ) {
            // Get the relative path.
            $relative = str_replace( $base_url, '', $local_url );
            return trailingslashit( $cdn_base_url ) . 'wp-content/uploads' . $relative;
        }

        // Try site URL replacement.
        $site_url = site_url();
        if ( strpos( $local_url, $site_url ) !== false ) {
            $relative = str_replace( $site_url, '', $local_url );
            return trailingslashit( $cdn_base_url ) . ltrim( $relative, '/' );
        }

        return $local_url;
    }

    /**
     * Convert a CDN URL back to local URL.
     *
     * @param string $cdn_url CDN URL.
     * @return string Local URL or original if not convertible.
     */
    public function cdn_url_to_local( $cdn_url ) {
        $cdn_base_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_base_url ) ) {
            return $cdn_url;
        }

        // Check if this is a CDN URL (check both custom and default).
        // Use strict prefix check (0 === strpos) to avoid matching URLs where
        // the base appears later in the string (e.g., query/fragment).
        $relative          = null;
        $cdn_base_prefixed = trailingslashit( $cdn_base_url );

        if ( 0 === strpos( $cdn_url, $cdn_base_prefixed ) ) {
            $relative = substr( $cdn_url, strlen( $cdn_base_prefixed ) );
        } else {
            // Also check against the original CDN URL if custom domain is set.
            $config = bunny_net_offload_gelform()->get_config();
            if ( ! empty( $config['cdn_url'] ) ) {
                $original_cdn_prefixed = trailingslashit( $config['cdn_url'] );
                if ( 0 === strpos( $cdn_url, $original_cdn_prefixed ) ) {
                    $relative = substr( $cdn_url, strlen( $original_cdn_prefixed ) );
                }
            }
        }

        if ( null === $relative ) {
            return $cdn_url;
        }

        // Check if it's an upload path.
        if ( strpos( $relative, 'wp-content/uploads' ) === 0 ) {
            $upload_dir    = wp_upload_dir();
            $upload_path   = str_replace( 'wp-content/uploads', '', $relative );
            return $upload_dir['baseurl'] . $upload_path;
        }

        // Return site URL version.
        return site_url( '/' . $relative );
    }

    /**
     * Filter srcset string attribute.
     *
     * @param string $srcset Srcset string.
     * @return string Modified srcset string.
     */
    private function filter_srcset_string( $srcset ) {
        if ( empty( $srcset ) ) {
            return $srcset;
        }

        $sources = explode( ', ', $srcset );

        foreach ( $sources as $index => $source ) {
            $parts = preg_split( '/\s+/', trim( $source ) );

            if ( ! empty( $parts[0] ) ) {
                $parts[0]          = $this->local_url_to_cdn( $parts[0] );
                $sources[ $index ] = implode( ' ', $parts );
            }
        }

        return implode( ', ', $sources );
    }

    /**
     * Find size key by matching dimensions in URL.
     *
     * @param array  $image_meta Image metadata.
     * @param string $url        Image URL.
     * @return string|false Size key or false if not found.
     */
    private function find_size_key_by_dimensions( $image_meta, $url ) {
        if ( empty( $image_meta['sizes'] ) ) {
            return false;
        }

        foreach ( $image_meta['sizes'] as $size_key => $size_data ) {
            if ( strpos( $url, $size_data['file'] ) !== false ) {
                return $size_key;
            }
        }

        return false;
    }

    /**
     * Construct size-specific URL from main URL.
     *
     * @param string       $cdn_url       Main CDN URL.
     * @param int          $attachment_id Attachment ID.
     * @param string|array $size          Requested size.
     * @return string Size-specific URL.
     */
    private function construct_size_url( $cdn_url, $attachment_id, $size ) {
        // Get metadata.
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( empty( $metadata['sizes'] ) ) {
            return $cdn_url;
        }

        // Get size name.
        $size_name = is_array( $size ) ? '' : $size;

        if ( empty( $size_name ) && is_array( $size ) ) {
            // Find size that matches dimensions.
            foreach ( $metadata['sizes'] as $key => $data ) {
                if ( $data['width'] == $size[0] && $data['height'] == $size[1] ) {
                    $size_name = $key;
                    break;
                }
            }
        }

        if ( empty( $size_name ) || empty( $metadata['sizes'][ $size_name ] ) ) {
            return $cdn_url;
        }

        // Get the size file.
        $size_file = $metadata['sizes'][ $size_name ]['file'];

        // Replace filename in CDN URL.
        $main_file = basename( $cdn_url );
        return str_replace( $main_file, $size_file, $cdn_url );
    }

    /**
     * Check if CDN is available (with caching).
     *
     * @return bool
     */
    private function is_cdn_available() {
        // Cache check for 5 minutes.
        $cache_key = 'bnog_cdn_available';

        if ( isset( $this->availability_cache[ $cache_key ] ) ) {
            return $this->availability_cache[ $cache_key ];
        }

        $cached = get_transient( $cache_key );

        if ( false !== $cached ) {
            $this->availability_cache[ $cache_key ] = (bool) $cached;
            return (bool) $cached;
        }

        // Do a quick HEAD request to check CDN availability.
        $cdn_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_url ) ) {
            $this->availability_cache[ $cache_key ] = false;
            return false;
        }

        $response = wp_remote_head(
            $cdn_url,
            array(
                'timeout'   => 5,
                'sslverify' => true,
            )
        );

        $available = ! is_wp_error( $response );

        // Cache for 5 minutes.
        set_transient( $cache_key, $available ? 1 : 0, 5 * MINUTE_IN_SECONDS );
        $this->availability_cache[ $cache_key ] = $available;

        return $available;
    }

    /**
     * Clear CDN availability cache.
     */
    public function clear_availability_cache() {
        delete_transient( 'bnog_cdn_available' );
        $this->availability_cache = array();
        $this->effective_cdn_url  = null;
    }

    /**
     * Get the effective CDN URL (custom domain or default).
     *
     * If a custom CDN domain is configured, it will be used instead of
     * the default Bunny.net CDN URL.
     *
     * @return string The CDN base URL (with https://).
     */
    public function get_effective_cdn_url() {
        if ( null !== $this->effective_cdn_url ) {
            return $this->effective_cdn_url;
        }

        $config = bunny_net_offload_gelform()->get_config();

        // Check for custom domain first.
        if ( ! empty( $config['custom_cdn_domain'] ) ) {
            $this->effective_cdn_url = 'https://' . $config['custom_cdn_domain'];
            return $this->effective_cdn_url;
        }

        // Fall back to default CDN URL.
        $this->effective_cdn_url = ! empty( $config['cdn_url'] ) ? $config['cdn_url'] : '';
        return $this->effective_cdn_url;
    }

    /**
     * Rewrite a stored CDN URL to use the effective CDN base.
     *
     * When a custom domain is configured, stored meta URLs (which use the
     * default Bunny hostname) are rewritten to use the custom domain.
     *
     * @param string $stored_cdn_url The stored CDN URL (may use default Bunny hostname).
     * @return string The URL rewritten with the effective CDN base.
     */
    private function rewrite_cdn_url_with_effective_base( $stored_cdn_url ) {
        $config = bunny_net_offload_gelform()->get_config();

        // If no custom domain is set, return the URL as-is.
        if ( empty( $config['custom_cdn_domain'] ) ) {
            return $stored_cdn_url;
        }

        // Get the original CDN URL (default Bunny hostname).
        $original_cdn_url = ! empty( $config['cdn_url'] ) ? $config['cdn_url'] : '';

        if ( empty( $original_cdn_url ) ) {
            return $stored_cdn_url;
        }

        // Check if the stored URL uses the original CDN base.
        $original_cdn_prefixed = trailingslashit( $original_cdn_url );

        if ( 0 === strpos( $stored_cdn_url, $original_cdn_prefixed ) ) {
            // Extract the relative path and rewrite with custom domain.
            $relative_path     = substr( $stored_cdn_url, strlen( $original_cdn_prefixed ) );
            $effective_cdn_url = $this->get_effective_cdn_url();
            return trailingslashit( $effective_cdn_url ) . $relative_path;
        }

        // URL doesn't match original CDN base, return as-is.
        return $stored_cdn_url;
    }
}
