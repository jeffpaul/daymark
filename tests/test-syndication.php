<?php
/**
 * Syndication tests — E2E scenarios 4, 5 (type-based defaults, canonical site).
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Syndication_Registry routing and mocked publishing.
 */
class Test_Syndication_Registry extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var Daymark_Syndication_Registry
	 */
	private Daymark_Syndication_Registry $registry;

	public function set_up(): void {
		parent::set_up();
		$this->registry = Daymark_Syndication_Registry::instance();
	}

	public function test_note_defaults_to_bluesky() {
		$this->assertContains( 'bluesky', $this->registry->get_defaults_for_type( 'note' ) );
	}

	public function test_image_defaults_to_instagram() {
		$this->assertContains( 'instagram', $this->registry->get_defaults_for_type( 'image' ) );
	}

	public function test_gallery_defaults_to_instagram() {
		$this->assertContains( 'instagram', $this->registry->get_defaults_for_type( 'gallery' ) );
	}

	public function test_video_defaults_to_youtube() {
		$this->assertContains( 'youtube', $this->registry->get_defaults_for_type( 'video' ) );
	}

	public function test_audio_and_mixed_have_no_defaults() {
		$this->assertSame( array(), $this->registry->get_defaults_for_type( 'audio' ) );
		$this->assertSame( array(), $this->registry->get_defaults_for_type( 'mixed' ) );
	}

	public function test_seven_built_in_connectors_registered() {
		$connectors = $this->registry->get_connectors();
		$this->assertCount( 7, $connectors );
		foreach ( array( 'bluesky', 'mastodon', 'instagram', 'youtube', 'tiktok', 'threads', 'x' ) as $id ) {
			$this->assertArrayHasKey( $id, $connectors );
		}
	}

	/** Mock publish round-trip records external posts and 'mocked' status. */
	public function test_publish_to_targets_stores_external_posts() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Syndication round trip',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$this->assertIsInt( $post_id );
		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertArrayHasKey( 'bluesky', $external );
		$this->assertEquals( 'mocked', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/**
	 * A target that can't represent this Mark type (e.g. YouTube for a
	 * note) is rejected before ever reaching the connector's own publish()
	 * — but issue #255's routing-transparency view needs that rejection
	 * recorded, not silently discarded, so it's distinguishable from a
	 * target that was simply never attempted.
	 */
	public function test_unsupported_target_is_recorded_not_discarded() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Unsupported target test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'youtube' ),
			)
		);

		$this->assertIsInt( $post_id );

		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertArrayHasKey( 'youtube', $external );
		$this->assertSame( 'unsupported', $external['youtube']['status'] );
		$this->assertNull( $external['youtube']['external_id'] );

		$this->assertSame( 'failed', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/**
	 * A successful target's own result isn't lost when another target in
	 * the same publish fails — both get recorded, and the overall status
	 * still reflects the success (a real/mocked publish outranks a failure).
	 */
	public function test_failure_alongside_a_success_records_both() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Mixed outcome test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky', 'youtube' ),
			)
		);

		$this->assertIsInt( $post_id );

		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertArrayHasKey( 'bluesky', $external );
		$this->assertArrayHasKey( 'youtube', $external );
		$this->assertSame( 'mocked', $external['bluesky']['status'] );
		$this->assertSame( 'unsupported', $external['youtube']['status'] );

		$this->assertSame( 'mocked', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/**
	 * A target ID with no registered connector at all (its plugin was
	 * deactivated/uninstalled after the Mark's destinations were selected —
	 * issue #263) is recorded as 'unavailable' rather than silently
	 * vanishing, mirroring how an unsupported-type target is already
	 * recorded above instead of discarded.
	 */
	public function test_unavailable_connector_is_recorded_not_discarded() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Unavailable connector test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'no-longer-installed' ),
			)
		);

		$this->assertIsInt( $post_id );

		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertArrayHasKey( 'no-longer-installed', $external );
		$this->assertSame( 'unavailable', $external['no-longer-installed']['status'] );
		$this->assertNull( $external['no-longer-installed']['external_id'] );
		$this->assertNotEmpty( $external['no-longer-installed']['message'] );

		$this->assertSame( 'failed', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/**
	 * A success and an unavailable-connector failure in the same publish
	 * both get recorded, and the overall status still reflects the success —
	 * same "success outranks failure" rule already covered above for an
	 * unsupported-type failure.
	 */
	public function test_unavailable_connector_alongside_a_success_records_both() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Mixed unavailable outcome test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky', 'no-longer-installed' ),
			)
		);

		$this->assertIsInt( $post_id );

		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertSame( 'mocked', $external['bluesky']['status'] );
		$this->assertSame( 'unavailable', $external['no-longer-installed']['status'] );

		$this->assertSame( 'mocked', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/** Your Site is always canonical — syndication never replaces the WP post. */
	public function test_your_site_always_canonical() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Site canonical test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky', 'instagram' ),
			)
		);

		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertEquals( 'publish', $post->post_status );
	}
}
