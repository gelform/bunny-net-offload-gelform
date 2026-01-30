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
     * Paths to exclude from CDN URL rewriting.
     *
     * These are plugin cache/generated paths that won't exist on CDN.
     *
     * @var array
     */
    private const EXCLUDED_PATHS = array(
        '/bb-plugin/',        // Beaver Builder cache/cropped images.
        '/bb-theme/',         // Beaver Builder theme cache.
        '/fl-builder/',       // Beaver Builder alternate path.
        '/cache/',            // Generic cache folders.
        '/bwg_',              // Photo Gallery plugin.
        '/wpcf7_',            // Contact Form 7.
    );

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

        // Filter content URLs - use high priority like WP Offload Media.
        add_filter( 'the_content', array( $this, 'filter_content' ), 100 );
        add_filter( 'the_excerpt', array( $this, 'filter_content' ), 100 );

        // Filter attachment image attributes.
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_image_attributes' ), 99, 3 );

        // Filter wp_prepare_attachment_for_js - used by Beaver Builder's FLBuilderPhoto::get_attachment_data().
        add_filter( 'wp_prepare_attachment_for_js', array( $this, 'filter_attachment_for_js' ), 99, 3 );

        // Filter attachment metadata for responsive images.
        add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 99, 2 );

        // Beaver Builder specific hooks.
        add_filter( 'fl_builder_photo_data', array( $this, 'filter_bb_photo_data' ), 99, 3 );
        add_filter( 'fl_builder_photo_module_url', array( $this, 'filter_bb_photo_url' ), 99, 2 );
        add_filter( 'fl_builder_photo_module_cropped_url', array( $this, 'filter_bb_photo_cropped_url' ), 99, 3 );

        // Widget filters.
        add_filter( 'widget_display_callback', array( $this, 'filter_widget' ), 99 );
        add_filter( 'widget_block_content', array( $this, 'filter_content' ), 99 );

        // Custom CSS filter.
        add_filter( 'wp_get_custom_css', array( $this, 'filter_content' ), 99 );

        // Theme customizer filters.
        add_filter( 'theme_mod_background_image', array( $this, 'filter_single_url' ), 99 );
        add_filter( 'theme_mod_header_image', array( $this, 'filter_single_url' ), 99 );
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
        if ( empty( $content ) || ! is_string( $content ) || ! $this->is_cdn_available() ) {
            return $content;
        }

        $cdn_base_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_base_url ) ) {
            return $content;
        }

        // First, process img tags with wp-image-X class (like WP Offload Media).
        // This allows us to get the attachment ID directly.
        $content = $this->filter_img_tags( $content );

        // Get upload directory info.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // Create pattern that matches the base URL with or without scheme.
        $base_url_no_scheme = preg_replace( '/^https?:/', '', $base_url );
        $base_url_patterns  = array(
            preg_quote( $base_url, '/' ),
            preg_quote( $base_url_no_scheme, '/' ),
        );

        // Build file extension pattern.
        $config   = bunny_net_offload_gelform()->get_config();
        $sync_all = ! empty( $config['sync_all_files'] );

        // Image extensions are always included.
        $extensions = 'jpg|jpeg|png|gif|webp|svg';

        // Add other file types if sync_all_files is enabled.
        if ( $sync_all ) {
            $extensions .= '|pdf|doc|docx|xls|xlsx|ppt|pptx|zip|rar|mp3|mp4|mov|avi|wmv|flv|ogg|wav|txt|csv';
        }

        // Match media URLs in content using all base URL variations.
        foreach ( $base_url_patterns as $base_pattern ) {
            $pattern = '/(' . $base_pattern . '\/[^\s"\'<>\)]+\.(?:' . $extensions . '))(?=["\'\s<>\)]|$)/i';

            $content = preg_replace_callback(
                $pattern,
                function ( $matches ) {
                    return $this->local_url_to_cdn( $matches[1] );
                },
                $content
            );
        }

        return $content;
    }

    /**
     * Filter img tags to rewrite URLs using wp-image-X class to get attachment ID.
     *
     * @param string $content Content with img tags.
     * @return string Modified content.
     */
    private function filter_img_tags( $content ) {
        // Find all img tags.
        if ( ! preg_match_all( '/<img [^>]+>/', $content, $matches ) || empty( $matches[0] ) ) {
            return $content;
        }

        $replacements = array();

        foreach ( array_unique( $matches[0] ) as $img_tag ) {
            // Try to get attachment ID from wp-image-X class.
            if ( ! preg_match( '/wp-image-([0-9]+)/i', $img_tag, $class_match ) ) {
                continue;
            }

            $attachment_id = (int) $class_match[1];

            // Check if this attachment is synced.
            $is_synced = get_post_meta( $attachment_id, '_bnog_synced', true );
            if ( ! $is_synced ) {
                continue;
            }

            // Get the src attribute.
            if ( ! preg_match( '/src=["\']([^"\']+)["\']/', $img_tag, $src_match ) ) {
                continue;
            }

            $original_src = $src_match[1];

            // Skip if already a CDN URL.
            $cdn_base = $this->get_effective_cdn_url();
            if ( strpos( $original_src, $cdn_base ) !== false ) {
                continue;
            }

            // Convert to CDN URL.
            $cdn_src = $this->local_url_to_cdn( $original_src );

            if ( $cdn_src !== $original_src ) {
                $new_img_tag = str_replace( $original_src, $cdn_src, $img_tag );

                // Also update srcset if present.
                if ( preg_match( '/srcset=["\']([^"\']+)["\']/', $new_img_tag, $srcset_match ) ) {
                    $new_srcset  = $this->filter_srcset_string( $srcset_match[1] );
                    $new_img_tag = str_replace( $srcset_match[1], $new_srcset, $new_img_tag );
                }

                $replacements[ $img_tag ] = $new_img_tag;
            }
        }

        // Apply all replacements.
        if ( ! empty( $replacements ) ) {
            $content = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
        }

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
        if ( empty( $local_url ) || ! is_string( $local_url ) ) {
            return $local_url;
        }

        $cdn_base_url = $this->get_effective_cdn_url();

        if ( empty( $cdn_base_url ) ) {
            return $local_url;
        }

        // Exclude plugin cache/generated paths that won't exist on CDN.
        foreach ( self::EXCLUDED_PATHS as $excluded ) {
            if ( strpos( $local_url, $excluded ) !== false ) {
                return $local_url;
            }
        }

        // Already a CDN URL - check both with and without scheme.
        $cdn_base_no_scheme = preg_replace( '/^https?:/', '', $cdn_base_url );
        if ( strpos( $local_url, $cdn_base_url ) !== false || strpos( $local_url, $cdn_base_no_scheme ) !== false ) {
            return $local_url;
        }

        // Also check if it's already the Bunny.net CDN URL (original hostname).
        $config = bunny_net_offload_gelform()->get_config();
        if ( ! empty( $config['cdn_url'] ) && strpos( $local_url, $config['cdn_url'] ) !== false ) {
            return $local_url;
        }

        // Get upload directory info.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // Create variations of base URL (with http, https, and scheme-less).
        $base_url_https     = preg_replace( '/^http:/', 'https:', $base_url );
        $base_url_http      = preg_replace( '/^https:/', 'http:', $base_url );
        $base_url_no_scheme = preg_replace( '/^https?:/', '', $base_url );

        // Check against all variations of upload URL.
        $relative = null;

        if ( strpos( $local_url, $base_url_https ) !== false ) {
            $relative = str_replace( $base_url_https, '', $local_url );
        } elseif ( strpos( $local_url, $base_url_http ) !== false ) {
            $relative = str_replace( $base_url_http, '', $local_url );
        } elseif ( strpos( $local_url, $base_url_no_scheme ) !== false ) {
            $relative = str_replace( $base_url_no_scheme, '', $local_url );
        } elseif ( strpos( $local_url, '/wp-content/uploads/' ) !== false ) {
            // Fallback: check for relative uploads path.
            $pos = strpos( $local_url, '/wp-content/uploads/' );
            $relative = substr( $local_url, $pos + strlen( '/wp-content/uploads' ) );
        }

        if ( null !== $relative ) {
            return trailingslashit( $cdn_base_url ) . 'wp-content/uploads' . $relative;
        }

        // Try site URL replacement.
        $site_url            = site_url();
        $site_url_no_scheme  = preg_replace( '/^https?:/', '', $site_url );

        if ( strpos( $local_url, $site_url ) !== false ) {
            $relative = str_replace( $site_url, '', $local_url );
            return trailingslashit( $cdn_base_url ) . ltrim( $relative, '/' );
        } elseif ( strpos( $local_url, $site_url_no_scheme ) !== false ) {
            $relative = str_replace( $site_url_no_scheme, '', $local_url );
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

    /**
     * Filter wp_prepare_attachment_for_js data.
     *
     * This is critical for Beaver Builder which uses FLBuilderPhoto::get_attachment_data()
     * that internally calls wp_prepare_attachment_for_js().
     *
     * @param array       $response   Array of prepared attachment data.
     * @param WP_Post     $attachment Attachment object.
     * @param array|false $meta       Array of attachment meta data, or false if none.
     * @return array Modified response.
     */
    public function filter_attachment_for_js( $response, $attachment, $meta ) {
        if ( ! $this->is_cdn_available() ) {
            return $response;
        }

        $attachment_id = $attachment->ID;

        // Filter the main URL.
        $cdn_url = get_post_meta( $attachment_id, '_bnog_cdn_url', true );
        if ( ! empty( $cdn_url ) ) {
            $cdn_url = $this->rewrite_cdn_url_with_effective_base( $cdn_url );
            $response['url'] = $cdn_url;

            // Also update the link if it points to the attachment file.
            if ( ! empty( $response['link'] ) && strpos( $response['link'], 'wp-content/uploads' ) !== false ) {
                $response['link'] = $cdn_url;
            }
        }

        // Filter all size URLs.
        if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
            foreach ( $response['sizes'] as $size_name => &$size_data ) {
                // Try to get size-specific CDN URL.
                $size_cdn_url = get_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_name, true );

                if ( ! empty( $size_cdn_url ) ) {
                    $size_data['url'] = $this->rewrite_cdn_url_with_effective_base( $size_cdn_url );
                } elseif ( ! empty( $cdn_url ) ) {
                    // Construct size URL from main CDN URL.
                    $size_data['url'] = $this->local_url_to_cdn( $size_data['url'] );
                }
            }
        }

        return $response;
    }

    /**
     * Filter attachment metadata.
     *
     * @param array $data          Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array Modified metadata.
     */
    public function filter_attachment_metadata( $data, $attachment_id ) {
        if ( ! is_array( $data ) || ! $this->is_cdn_available() ) {
            return $data;
        }

        // Check if being used in a content filter context where we need encoded filenames.
        global $wp_current_filter;
        if ( is_array( $wp_current_filter ) && in_array( 'the_content', $wp_current_filter, true ) ) {
            // Encode filenames for URL matching (like WP Offload Media does).
            // Preserve directory path while encoding only the filename portion.
            if ( ! empty( $data['file'] ) ) {
                $dir      = dirname( $data['file'] );
                $filename = basename( $data['file'] );
                // Only encode if there are special characters that need encoding.
                if ( $dir && '.' !== $dir ) {
                    $data['file'] = $dir . '/' . rawurlencode( $filename );
                } else {
                    $data['file'] = rawurlencode( $filename );
                }
            }

            if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
                foreach ( $data['sizes'] as $size_name => &$size_data ) {
                    if ( ! empty( $size_data['file'] ) ) {
                        $size_data['file'] = rawurlencode( $size_data['file'] );
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Filter Beaver Builder photo data.
     *
     * @param object $data     Photo data object.
     * @param object $settings Module settings.
     * @param string $node     Module node ID.
     * @return object Modified photo data.
     */
    public function filter_bb_photo_data( $data, $settings, $node ) {
        if ( ! is_object( $data ) || ! $this->is_cdn_available() ) {
            return $data;
        }

        // Get attachment ID from data.
        $attachment_id = isset( $data->id ) ? $data->id : 0;

        if ( ! $attachment_id ) {
            return $data;
        }

        // Filter main URL.
        $cdn_url = get_post_meta( $attachment_id, '_bnog_cdn_url', true );
        if ( ! empty( $cdn_url ) ) {
            $cdn_url = $this->rewrite_cdn_url_with_effective_base( $cdn_url );
            $data->url = $cdn_url;

            if ( isset( $data->link ) && strpos( $data->link, 'wp-content/uploads' ) !== false ) {
                $data->link = $cdn_url;
            }
        }

        // Filter sizes.
        if ( isset( $data->sizes ) && is_object( $data->sizes ) ) {
            foreach ( $data->sizes as $size_name => $size_obj ) {
                if ( ! is_object( $size_obj ) || ! isset( $size_obj->url ) ) {
                    continue;
                }

                // Try size-specific CDN URL.
                $size_cdn_url = get_post_meta( $attachment_id, '_bnog_cdn_url_' . $size_name, true );

                if ( ! empty( $size_cdn_url ) ) {
                    $size_obj->url = $this->rewrite_cdn_url_with_effective_base( $size_cdn_url );
                } elseif ( ! empty( $cdn_url ) ) {
                    $size_obj->url = $this->local_url_to_cdn( $size_obj->url );
                }
            }
        }

        return $data;
    }

    /**
     * Filter Beaver Builder photo module URL.
     *
     * This is the final URL filter for the photo module src.
     *
     * @param string $url    Photo URL.
     * @param object $module Photo module instance.
     * @return string Filtered URL.
     */
    public function filter_bb_photo_url( $url, $module ) {
        if ( empty( $url ) || ! $this->is_cdn_available() ) {
            return $url;
        }

        // Try to get the attachment ID from module settings.
        $attachment_id = 0;
        if ( isset( $module->settings->photo ) && is_numeric( $module->settings->photo ) ) {
            $attachment_id = (int) $module->settings->photo;
        } elseif ( isset( $module->data->id ) ) {
            $attachment_id = (int) $module->data->id;
        }

        if ( $attachment_id ) {
            // Check if this attachment is synced.
            $is_synced = get_post_meta( $attachment_id, '_bnog_synced', true );

            if ( $is_synced ) {
                // Convert the URL to CDN.
                return $this->local_url_to_cdn( $url );
            }
        }

        // Fallback: try to convert using URL pattern matching.
        return $this->local_url_to_cdn( $url );
    }

    /**
     * Filter Beaver Builder cropped photo URL.
     *
     * @param string $url          Cropped photo URL.
     * @param array  $cropped_path Cropped path info.
     * @param object $module       Photo module instance.
     * @return string Filtered URL.
     */
    public function filter_bb_photo_cropped_url( $url, $cropped_path, $module ) {
        // Cropped images are stored locally in BB's cache folder.
        // We don't rewrite these as they're not on the CDN.
        return $url;
    }

    /**
     * Filter widget instance data.
     *
     * @param array $instance Widget instance.
     * @return array Modified instance.
     */
    public function filter_widget( $instance ) {
        if ( empty( $instance ) || ! is_array( $instance ) || ! $this->is_cdn_available() ) {
            return $instance;
        }

        foreach ( $instance as $key => $value ) {
            if ( is_string( $value ) && ! empty( $value ) ) {
                // Check if it's a URL or contains content with URLs.
                if ( $this->is_local_media_url( $value ) ) {
                    $instance[ $key ] = $this->local_url_to_cdn( $value );
                } elseif ( strpos( $value, 'wp-content/uploads' ) !== false ) {
                    // Might be content with embedded URLs.
                    $instance[ $key ] = $this->filter_content( $value );
                }
            }
        }

        return $instance;
    }

    /**
     * Filter a single URL value.
     *
     * @param string $url URL to filter.
     * @return string Filtered URL.
     */
    public function filter_single_url( $url ) {
        if ( empty( $url ) || ! $this->is_cdn_available() ) {
            return $url;
        }

        return $this->local_url_to_cdn( $url );
    }

    /**
     * Check if a URL is a local media URL.
     *
     * @param string $url URL to check.
     * @return bool True if local media URL.
     */
    private function is_local_media_url( $url ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return false;
        }

        // Check if it looks like a URL.
        if ( strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) {
            return false;
        }

        // Check if it's a local upload URL.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        return strpos( $url, $base_url ) !== false || strpos( $url, 'wp-content/uploads' ) !== false;
    }
}
