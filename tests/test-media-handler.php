<?php
/**
 * Tests for BNOG_Media_Handler.
 *
 * @package BunnyNetOffloadGelform
 */

class BNOG_Test_Media_Handler extends WP_UnitTestCase {

	/**
	 * Media handler instance.
	 *
	 * @var BNOG_Media_Handler
	 */
	private $handler;

	public function set_up() {
		parent::set_up();
		$this->handler = bunny_net_offload_gelform()->media_handler;
	}

	/**
	 * Test adding an attachment to the sync queue.
	 */
	public function test_add_to_queue() {
		delete_option( 'bnog_upload_queue' );

		$this->handler->add_to_queue( 123 );

		$queue = get_option( 'bnog_upload_queue', array() );
		$this->assertContains( 123, $queue );
	}

	/**
	 * Test removing an attachment from the sync queue.
	 */
	public function test_remove_from_queue() {
		update_option( 'bnog_upload_queue', array( 100, 200, 300 ) );

		$this->handler->remove_from_queue( 200 );

		$queue = get_option( 'bnog_upload_queue', array() );
		$this->assertNotContains( 200, $queue );
		$this->assertContains( 100, $queue );
		$this->assertContains( 300, $queue );
	}

	/**
	 * Test removing from an empty queue doesn't cause errors.
	 */
	public function test_remove_from_empty_queue() {
		delete_option( 'bnog_upload_queue' );

		$this->handler->remove_from_queue( 999 );

		$queue = get_option( 'bnog_upload_queue', array() );
		$this->assertEmpty( $queue );
	}

	/**
	 * Test that duplicate IDs are not added to the queue.
	 */
	public function test_queue_no_duplicates() {
		delete_option( 'bnog_upload_queue' );

		$this->handler->add_to_queue( 123 );
		$this->handler->add_to_queue( 123 );

		$queue = get_option( 'bnog_upload_queue', array() );
		$this->assertCount( 1, array_keys( $queue, 123, true ) );
	}

	/**
	 * Test update_content_references updates post_content.
	 */
	public function test_update_content_references_in_post_content() {
		// Create a post with content referencing an image filename.
		$post_id = self::factory()->post->create( array(
			'post_content' => '<img src="https://example.com/uploads/photo.png" />',
		) );

		// Use reflection to call private method.
		$method = new ReflectionMethod( $this->handler, 'update_content_references' );
		$method->setAccessible( true );
		$method->invoke( $this->handler, 'photo.png', 'photo.jpg' );

		$post = get_post( $post_id );
		$this->assertStringContainsString( 'photo.jpg', $post->post_content );
		$this->assertStringNotContainsString( 'photo.png', $post->post_content );
	}

	/**
	 * Test update_content_references updates scalar postmeta.
	 */
	public function test_update_content_references_in_scalar_postmeta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'custom_image_url', 'https://example.com/uploads/photo.png' );

		$method = new ReflectionMethod( $this->handler, 'update_content_references' );
		$method->setAccessible( true );
		$method->invoke( $this->handler, 'photo.png', 'photo.jpg' );

		$meta = get_post_meta( $post_id, 'custom_image_url', true );
		$this->assertStringContainsString( 'photo.jpg', $meta );
	}

	/**
	 * Test update_content_references does NOT corrupt serialized postmeta.
	 */
	public function test_update_content_references_preserves_serialized_meta() {
		$post_id = self::factory()->post->create();

		// Store a serialized array containing the filename.
		$data = array(
			'url'    => 'https://example.com/uploads/photo.png',
			'width'  => 800,
			'height' => 600,
		);
		update_post_meta( $post_id, 'builder_settings', $data );

		$method = new ReflectionMethod( $this->handler, 'update_content_references' );
		$method->setAccessible( true );
		$method->invoke( $this->handler, 'photo.png', 'photo.jpg' );

		// The serialized meta should be untouched (skipped by the safe update).
		$meta = get_post_meta( $post_id, 'builder_settings', true );
		$this->assertIsArray( $meta );
		$this->assertSame( 800, $meta['width'] );
		// The URL inside the array should NOT be updated (serialized data is skipped).
		$this->assertStringContainsString( 'photo.png', $meta['url'] );
	}

	/**
	 * Test update_content_references skips bnog_ prefixed meta keys.
	 */
	public function test_update_content_references_skips_bnog_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_bnog_cdn_url', 'https://cdn.example.com/photo.png' );

		$method = new ReflectionMethod( $this->handler, 'update_content_references' );
		$method->setAccessible( true );
		$method->invoke( $this->handler, 'photo.png', 'photo.jpg' );

		// Plugin's own meta should not be modified.
		$meta = get_post_meta( $post_id, '_bnog_cdn_url', true );
		$this->assertStringContainsString( 'photo.png', $meta );
	}

	/**
	 * Test update_content_references with identical filenames does nothing.
	 */
	public function test_update_content_references_same_name_noop() {
		$post_id = self::factory()->post->create( array(
			'post_content' => '<img src="photo.png" />',
		) );

		$method = new ReflectionMethod( $this->handler, 'update_content_references' );
		$method->setAccessible( true );
		$method->invoke( $this->handler, 'photo.png', 'photo.png' );

		$post = get_post( $post_id );
		$this->assertStringContainsString( 'photo.png', $post->post_content );
	}

	/**
	 * Test process_queue clears running status when queue is empty.
	 */
	public function test_process_queue_clears_status_on_empty_queue() {
		delete_option( 'bnog_upload_queue' );
		update_option( 'bnog_sync_status', array( 'running' => true ) );

		$this->handler->process_queue();

		$status = get_option( 'bnog_sync_status', array() );
		$this->assertEmpty( $status['running'] );
	}

	/**
	 * Invoke the private get_time_budget() helper with a temporary
	 * max_execution_time and return the resulting budget.
	 *
	 * @param int $max Value to set for max_execution_time during the call.
	 * @return float
	 */
	private function call_get_time_budget( $max ) {
		$original = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', (string) $max ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$method = new ReflectionMethod( $this->handler, 'get_time_budget' );
			$method->setAccessible( true );
			return $method->invoke( $this->handler );
		} finally {
			ini_set( 'max_execution_time', (string) $original ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	/**
	 * Test that get_time_budget never exceeds max_execution_time, including
	 * very small limits where a static floor would have overrun the wall.
	 */
	public function test_get_time_budget_never_exceeds_max() {
		foreach ( array( 1, 2, 3, 5, 10, 30, 60, 120 ) as $max ) {
			$budget = $this->call_get_time_budget( $max );
			$this->assertLessThanOrEqual(
				$max,
				$budget,
				sprintf( 'Budget %.2f exceeded max_execution_time=%d', $budget, $max )
			);
		}
	}

	/**
	 * Test that get_time_budget caps at 50s for large limits.
	 */
	public function test_get_time_budget_caps_at_50() {
		$budget = $this->call_get_time_budget( 300 );
		$this->assertEquals( 50.0, $budget );
	}

	/**
	 * Test that get_time_budget falls back to 50s when no limit is set
	 * (max_execution_time=0, typical for CLI).
	 */
	public function test_get_time_budget_no_limit_returns_50() {
		$budget = $this->call_get_time_budget( 0 );
		$this->assertEquals( 50.0, $budget );
	}

	/**
	 * Test that get_time_budget scales down for small limits — for the
	 * regression Copilot flagged on PR #17.
	 */
	public function test_get_time_budget_scales_down_for_small_limits() {
		$this->assertEqualsWithDelta( 0.75, $this->call_get_time_budget( 1 ), 0.001 );
		$this->assertEqualsWithDelta( 2.25, $this->call_get_time_budget( 3 ), 0.001 );
		$this->assertEqualsWithDelta( 7.5, $this->call_get_time_budget( 10 ), 0.001 );
	}
}
