<?php
/**
 * Tests for BNOG_Image_Processor.
 *
 * @package BunnyNetOffloadGelform
 */

class BNOG_Test_Image_Processor extends WP_UnitTestCase {

	/**
	 * Image processor instance.
	 *
	 * @var BNOG_Image_Processor
	 */
	private $processor;

	/**
	 * Temp files to clean up.
	 *
	 * @var array
	 */
	private $temp_files = array();

	public function set_up() {
		parent::set_up();
		$this->processor = new BNOG_Image_Processor();
	}

	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * Helper to create a test PNG file.
	 *
	 * @param int  $width  Image width.
	 * @param int  $height Image height.
	 * @param bool $transparent Whether to include transparency.
	 * @param int  $color_type PNG color type (2=RGB, 3=palette, 6=RGBA).
	 * @return string Path to temp file.
	 */
	private function create_test_png( $width = 100, $height = 100, $transparent = false, $color_type = 6 ) {
		$image = imagecreatetruecolor( $width, $height );

		if ( $transparent && $color_type !== 3 ) {
			imagesavealpha( $image, true );
			imagealphablending( $image, false );
			$bg = imagecolorallocatealpha( $image, 0, 0, 0, 64 );
			imagefill( $image, 0, 0, $bg );
		} else {
			$bg = imagecolorallocate( $image, 255, 0, 0 );
			imagefill( $image, 0, 0, $bg );
		}

		$temp = wp_tempnam( 'bnog_test_', sys_get_temp_dir() ) . '.png';
		imagepng( $image, $temp );
		imagedestroy( $image );

		$this->temp_files[] = $temp;
		return $temp;
	}

	/**
	 * Helper to create a test JPEG file.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return string Path to temp file.
	 */
	private function create_test_jpeg( $width = 100, $height = 100 ) {
		$image = imagecreatetruecolor( $width, $height );
		$bg    = imagecolorallocate( $image, 0, 128, 255 );
		imagefill( $image, 0, 0, $bg );

		$temp = wp_tempnam( 'bnog_test_', sys_get_temp_dir() ) . '.jpg';
		imagejpeg( $image, $temp, 85 );
		imagedestroy( $image );

		$this->temp_files[] = $temp;
		return $temp;
	}

	/**
	 * Test that opaque PNGs (RGB, type 2) are detected as non-transparent.
	 */
	public function test_png_transparency_detection_opaque_rgb() {
		$file = $this->create_test_png( 50, 50, false );
		$this->assertFalse( $this->processor->png_has_transparency( $file ) );
	}

	/**
	 * Test that PNGs with alpha pixels are detected as transparent.
	 */
	public function test_png_transparency_detection_transparent_rgba() {
		$file = $this->create_test_png( 50, 50, true, 6 );
		$this->assertTrue( $this->processor->png_has_transparency( $file ) );
	}

	/**
	 * Test that missing files return true (safe default).
	 */
	public function test_png_transparency_detection_missing_file() {
		$this->assertTrue( $this->processor->png_has_transparency( '/nonexistent/file.png' ) );
	}

	/**
	 * Test JPEG is recognized as processable.
	 */
	public function test_is_processable_image_jpeg() {
		$file = $this->create_test_jpeg();
		$this->assertTrue( $this->processor->is_processable_image( $file ) );
	}

	/**
	 * Test PNG is recognized as processable.
	 */
	public function test_is_processable_image_png() {
		$file = $this->create_test_png();
		$this->assertTrue( $this->processor->is_processable_image( $file ) );
	}

	/**
	 * Test that non-existent files are not processable.
	 */
	public function test_is_processable_image_nonexistent() {
		$this->assertFalse( $this->processor->is_processable_image( '/nonexistent/file.jpg' ) );
	}

	/**
	 * Test that non-image files are not processable.
	 */
	public function test_is_processable_image_text_file() {
		$temp = wp_tempnam( 'bnog_test_' ) . '.txt';
		file_put_contents( $temp, 'not an image' );
		$this->temp_files[] = $temp;

		$this->assertFalse( $this->processor->is_processable_image( $temp ) );
	}

	/**
	 * Test MIME type detection for JPEG.
	 */
	public function test_get_mime_type_jpeg() {
		$file = $this->create_test_jpeg();
		$this->assertSame( 'image/jpeg', $this->processor->get_mime_type( $file ) );
	}

	/**
	 * Test MIME type detection for PNG.
	 */
	public function test_get_mime_type_png() {
		$file = $this->create_test_png();
		$this->assertSame( 'image/png', $this->processor->get_mime_type( $file ) );
	}

	/**
	 * Test that processing a non-existent file returns an error.
	 */
	public function test_process_rejects_nonexistent_file() {
		$result = $this->processor->process( '/nonexistent/file.jpg' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	/**
	 * Test compressed size estimation for JPEG.
	 */
	public function test_estimate_compressed_size_jpeg() {
		$file     = $this->create_test_jpeg( 200, 200 );
		$estimate = $this->processor->estimate_compressed_size( $file, 85 );

		$this->assertIsInt( $estimate );
		$this->assertGreaterThan( 0, $estimate );
		$this->assertLessThan( filesize( $file ), $estimate );
	}

	/**
	 * Test compressed size estimation returns false for missing file.
	 */
	public function test_estimate_compressed_size_missing_file() {
		$this->assertFalse( $this->processor->estimate_compressed_size( '/nonexistent/file.jpg' ) );
	}
}
