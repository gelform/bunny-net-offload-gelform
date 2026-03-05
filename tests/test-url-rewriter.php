<?php
/**
 * Tests for BNOG_URL_Rewriter.
 *
 * @package BunnyNetOffloadGelform
 */

class BNOG_Test_URL_Rewriter extends WP_UnitTestCase {

	/**
	 * URL rewriter instance.
	 *
	 * @var BNOG_URL_Rewriter
	 */
	private $rewriter;

	public function set_up() {
		parent::set_up();
		$this->rewriter = bunny_net_offload_gelform()->url_rewriter;
	}

	/**
	 * Test that Beaver Builder cache paths are excluded from rewriting.
	 */
	public function test_beaver_builder_cache_excluded() {
		$url = 'https://example.com/wp-content/cache/bb-plugin/some-image.jpg';

		// BB cache URLs should not be rewritten.
		$this->assertStringNotContainsString( 'cdn', $this->rewriter->maybe_rewrite_url( $url ) );
	}

	/**
	 * Test that URLs with /cache/ path are excluded.
	 */
	public function test_cache_path_excluded() {
		$url = 'https://example.com/wp-content/cache/image.jpg';

		$result = $this->rewriter->maybe_rewrite_url( $url );
		$this->assertSame( $url, $result );
	}

	/**
	 * Test that non-synced attachments are not rewritten.
	 */
	public function test_non_synced_attachment_not_rewritten() {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$url    = wp_get_attachment_url( $attachment_id );
		$result = $this->rewriter->maybe_rewrite_url( $url );

		// Without CDN URL meta, should return original URL.
		$this->assertSame( $url, $result );
	}

	/**
	 * Test that synced attachments get rewritten to CDN URL.
	 */
	public function test_synced_attachment_rewritten() {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$cdn_url = 'https://cdn.example.com/uploads/canola.jpg';
		update_post_meta( $attachment_id, '_bnog_cdn_url', $cdn_url );
		update_post_meta( $attachment_id, '_bnog_synced', true );

		$url    = wp_get_attachment_url( $attachment_id );
		$result = $this->rewriter->maybe_rewrite_url( $url );

		$this->assertSame( $cdn_url, $result );
	}
}
