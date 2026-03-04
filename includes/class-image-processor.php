<?php
/**
 * Image Processor
 *
 * Handles image resizing and compression using WordPress's WP_Image_Editor.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Image Processor class.
 *
 * @since 1.0.0
 */
class BNOG_Image_Processor {

    /**
     * Supported image MIME types.
     *
     * @var array
     */
    const SUPPORTED_TYPES = array(
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    );

    /**
     * Check if a file is an image that we can process.
     *
     * @param string $file_path Path to the file.
     * @return bool
     */
    public function is_processable_image( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $mime_type = $this->get_mime_type( $file_path );

        return in_array( $mime_type, self::SUPPORTED_TYPES, true );
    }

    /**
     * Get the MIME type of a file.
     *
     * @param string $file_path Path to the file.
     * @return string|false MIME type or false on failure.
     */
    public function get_mime_type( $file_path ) {
        $file_type = wp_check_filetype( $file_path );

        if ( ! empty( $file_type['type'] ) ) {
            return $file_type['type'];
        }

        // Fallback to getimagesize.
        $image_info = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( false !== $image_info && ! empty( $image_info['mime'] ) ) {
            return $image_info['mime'];
        }

        return false;
    }

    /**
     * Process an image: resize and compress.
     *
     * @param string $file_path Path to the image file.
     * @param array  $options   Optional. Processing options.
     * @return string|WP_Error Path to processed file or WP_Error on failure.
     */
    public function process( $file_path, $options = array() ) {
        if ( ! $this->is_processable_image( $file_path ) ) {
            return new WP_Error( 'invalid_image', __( 'File is not a supported image type.', 'bunny-net-offload-gelform' ) );
        }

        // Check file size (50MB limit to prevent memory exhaustion).
        $file_size = filesize( $file_path );
        $max_size  = 50 * MB_IN_BYTES;
        
        if ( false === $file_size || $file_size > $max_size ) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    /* translators: %s: Maximum file size in MB */
                    __( 'Image file is too large to process (maximum %s MB).', 'bunny-net-offload-gelform' ),
                    number_format( $max_size / MB_IN_BYTES )
                )
            );
        }

        $config = bunny_net_offload_gelform()->get_config();

        // Merge options with config defaults.
        $defaults = array(
            'max_width'       => isset( $config['max_width'] ) ? $config['max_width'] : 2048,
            'max_height'      => isset( $config['max_height'] ) ? $config['max_height'] : 2048,
            'jpeg_quality'    => isset( $config['jpeg_quality'] ) ? $config['jpeg_quality'] : 85,
            'png_compression' => isset( $config['png_compression'] ) ? $config['png_compression'] : 6,
            'webp_quality'    => isset( $config['webp_quality'] ) ? $config['webp_quality'] : 82,
        );

        $options = wp_parse_args( $options, $defaults );

        // Load the image editor.
        $editor = wp_get_image_editor( $file_path );

        if ( is_wp_error( $editor ) ) {
            bunny_net_offload_gelform()->log( 'Failed to load image editor: ' . $editor->get_error_message(), 'error' );
            return $editor;
        }

        // Get original dimensions.
        $original_size = $editor->get_size();

        // Check if resizing is needed.
        $needs_resize = (
            $original_size['width'] > $options['max_width'] ||
            $original_size['height'] > $options['max_height']
        );

        if ( $needs_resize ) {
            $result = $editor->resize( $options['max_width'], $options['max_height'], false );

            if ( is_wp_error( $result ) ) {
                bunny_net_offload_gelform()->log( 'Failed to resize image: ' . $result->get_error_message(), 'error' );
                return $result;
            }

            $new_size = $editor->get_size();
            bunny_net_offload_gelform()->log(
                sprintf(
                    'Image resized: %dx%d -> %dx%d',
                    $original_size['width'],
                    $original_size['height'],
                    $new_size['width'],
                    $new_size['height']
                ),
                'info'
            );
        }

        // Get MIME type for setting appropriate quality.
        $mime_type = $this->get_mime_type( $file_path );

        // Check if we should convert PNG to JPEG.
        $convert_png = false;
        if ( 'image/png' === $mime_type && ! empty( $config['convert_png_to_jpg'] ) ) {
            if ( ! $this->png_has_transparency( $file_path ) ) {
                $convert_png = true;
                $mime_type   = 'image/jpeg';
            }
        }

        // Set quality settings based on output type.
        if ( 'image/webp' === $mime_type ) {
            $editor->set_quality( $options['webp_quality'] );
        } else {
            $editor->set_quality( $options['jpeg_quality'] );
        }

        // Save if resized or converting format.
        if ( $needs_resize || $convert_png ) {
            if ( $convert_png ) {
                // Save as JPEG with unique filename (avoid collisions).
                $dir      = dirname( $file_path );
                $basename = pathinfo( $file_path, PATHINFO_FILENAME ) . '.jpg';
                $unique   = wp_unique_filename( $dir, $basename );
                $new_file_path = $dir . '/' . $unique;
                $saved         = $editor->save( $new_file_path, 'image/jpeg' );
            } else {
                // Save over the original file.
                $saved = $editor->save( $file_path, $mime_type );
            }

            if ( is_wp_error( $saved ) ) {
                bunny_net_offload_gelform()->log( 'Failed to save processed image: ' . $saved->get_error_message(), 'error' );
                return $saved;
            }

            $output_file = $saved['path'];

            // If we converted PNG to JPEG, delete the original PNG.
            if ( $convert_png && file_exists( $file_path ) && $output_file !== $file_path ) {
                wp_delete_file( $file_path );
                bunny_net_offload_gelform()->log( sprintf( 'Converted PNG to JPEG: %s -> %s', $file_path, $output_file ), 'info' );
            } else {
                bunny_net_offload_gelform()->log( sprintf( 'Image processed and saved: %s', $output_file ), 'info' );
            }

            return $output_file;
        }

        return $file_path;
    }

    /**
     * Check if a PNG image has transparency (alpha channel with non-opaque pixels).
     *
     * @param string $file_path Path to the PNG file.
     * @return bool True if the image has transparent pixels.
     */
    public function png_has_transparency( $file_path ) {
        // Quick check: if the PNG doesn't have an alpha channel in its header, it's not transparent.
        // PNG color type is at byte offset 25. Type 4 = greyscale+alpha, type 6 = RGBA.
        $header = file_get_contents( $file_path, false, null, 0, 26 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( false === $header || strlen( $header ) < 26 ) {
            return true; // Assume transparency if we can't read header (safe default).
        }

        $color_type = ord( $header[25] );

        // Color types without alpha: 0 (greyscale), 2 (RGB), 3 (palette).
        if ( 0 === $color_type || 2 === $color_type ) {
            return false;
        }

        // For palette-based PNGs (type 3), check for tRNS chunk.
        if ( 3 === $color_type ) {
            $contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            return false !== $contents && false !== strpos( $contents, 'tRNS' );
        }

        // Types 4 and 6 have alpha — sample pixels to check if any are actually non-opaque.
        $image = @imagecreatefrompng( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( ! $image ) {
            return true; // Assume transparency if we can't load (safe default).
        }

        $width  = imagesx( $image );
        $height = imagesy( $image );

        // Sample pixels rather than checking every one (performance).
        $step = max( 1, intval( sqrt( $width * $height / 1000 ) ) );

        for ( $x = 0; $x < $width; $x += $step ) {
            for ( $y = 0; $y < $height; $y += $step ) {
                $rgba  = imagecolorat( $image, $x, $y );
                $alpha = ( $rgba >> 24 ) & 0x7F;

                if ( $alpha > 0 ) {
                    imagedestroy( $image );
                    return true;
                }
            }
        }

        imagedestroy( $image );
        return false;
    }

    /**
     * Process an image without modifying the original.
     *
     * @param string $file_path Path to the image file.
     * @param array  $options   Optional. Processing options.
     * @return string|WP_Error Path to processed file or WP_Error on failure.
     */
    public function process_copy( $file_path, $options = array() ) {
        if ( ! $this->is_processable_image( $file_path ) ) {
            return new WP_Error( 'invalid_image', __( 'File is not a supported image type.', 'bunny-net-offload-gelform' ) );
        }

        // Validate file path is within allowed directories (defense in depth).
        $file_path   = wp_normalize_path( realpath( $file_path ) );
        $upload_dir  = wp_upload_dir();
        $uploads_path = wp_normalize_path( $upload_dir['basedir'] );
        $content_path = wp_normalize_path( WP_CONTENT_DIR );

        if ( false === $file_path || ( 0 !== strpos( $file_path, $uploads_path ) && 0 !== strpos( $file_path, $content_path ) ) ) {
            return new WP_Error( 'invalid_path', __( 'File path is not within allowed directories.', 'bunny-net-offload-gelform' ) );
        }

        $config = bunny_net_offload_gelform()->get_config();

        // Merge options with config defaults.
        $defaults = array(
            'max_width'       => isset( $config['max_width'] ) ? $config['max_width'] : 2048,
            'max_height'      => isset( $config['max_height'] ) ? $config['max_height'] : 2048,
            'jpeg_quality'    => isset( $config['jpeg_quality'] ) ? $config['jpeg_quality'] : 85,
            'png_compression' => isset( $config['png_compression'] ) ? $config['png_compression'] : 6,
            'webp_quality'    => isset( $config['webp_quality'] ) ? $config['webp_quality'] : 82,
        );

        $options = wp_parse_args( $options, $defaults );

        // Load the image editor.
        $editor = wp_get_image_editor( $file_path );

        if ( is_wp_error( $editor ) ) {
            return $editor;
        }

        // Get original dimensions.
        $original_size = $editor->get_size();

        // Check if resizing is needed.
        $needs_resize = (
            $original_size['width'] > $options['max_width'] ||
            $original_size['height'] > $options['max_height']
        );

        if ( $needs_resize ) {
            $result = $editor->resize( $options['max_width'], $options['max_height'], false );

            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        // Get MIME type.
        $mime_type = $this->get_mime_type( $file_path );

        // Set quality settings based on image type.
        if ( 'image/webp' === $mime_type ) {
            $editor->set_quality( $options['webp_quality'] );
        } else {
            $editor->set_quality( $options['jpeg_quality'] );
        }

        // Generate temp output filename.
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/bnog-temp';

        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );

            // Add .htaccess for security.
            $htaccess = $temp_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
        }

        $pathinfo    = pathinfo( $file_path );
        $output_file = $temp_dir . '/' . uniqid( 'bnog_' ) . '.' . $pathinfo['extension'];

        // Save processed image.
        $saved = $editor->save( $output_file, $mime_type );

        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        return $saved['path'];
    }

    /**
     * Get image dimensions.
     *
     * @param string $file_path Path to the image file.
     * @return array|false Array with 'width' and 'height' or false on failure.
     */
    public function get_dimensions( $file_path ) {
        $editor = wp_get_image_editor( $file_path );

        if ( is_wp_error( $editor ) ) {
            return false;
        }

        return $editor->get_size();
    }

    /**
     * Check if an image needs processing (resize/compress).
     *
     * @param string $file_path Path to the image file.
     * @return bool
     */
    public function needs_processing( $file_path ) {
        if ( ! $this->is_processable_image( $file_path ) ) {
            return false;
        }

        $dimensions = $this->get_dimensions( $file_path );

        if ( false === $dimensions ) {
            return false;
        }

        $config     = bunny_net_offload_gelform()->get_config();
        $max_width  = isset( $config['max_width'] ) ? $config['max_width'] : 2048;
        $max_height = isset( $config['max_height'] ) ? $config['max_height'] : 2048;

        return (
            $dimensions['width'] > $max_width ||
            $dimensions['height'] > $max_height
        );
    }

    /**
     * Clean up temporary processed files.
     *
     * @param int $max_age Maximum age in seconds (default: 1 hour).
     */
    public function cleanup_temp_files( $max_age = 3600 ) {
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/bnog-temp';

        if ( ! is_dir( $temp_dir ) ) {
            return;
        }

        $files = glob( $temp_dir . '/bnog_*' );

        if ( empty( $files ) ) {
            return;
        }

        $now = time();

        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                $file_age = $now - filemtime( $file );

                if ( $file_age > $max_age ) {
                    wp_delete_file( $file );
                }
            }
        }
    }

    /**
     * Get estimated file size after compression.
     *
     * @param string $file_path Path to the image file.
     * @param int    $quality   JPEG quality (1-100).
     * @return int|false Estimated size in bytes or false on failure.
     */
    public function estimate_compressed_size( $file_path, $quality = 85 ) {
        $original_size = filesize( $file_path );

        if ( false === $original_size ) {
            return false;
        }

        $mime_type = $this->get_mime_type( $file_path );

        // Rough estimation based on format and quality.
        switch ( $mime_type ) {
            case 'image/jpeg':
            case 'image/jpg':
                // JPEG compression is roughly linear with quality.
                $ratio = $quality / 100;
                return intval( $original_size * $ratio * 0.7 );

            case 'image/png':
                // PNG is lossless, size depends on image complexity.
                return intval( $original_size * 0.85 );

            case 'image/webp':
                // WebP is generally more efficient.
                return intval( $original_size * 0.6 );

            default:
                return $original_size;
        }
    }
}
