<?php
/**
 * Syndication tests — E2E scenarios 4, 5 (type-based defaults, canonical site).
 *
 * @package Moment
 */

/**
 * Tests Moment_Syndication_Registry routing and mocked publishing.
 */
class Test_Syndication_Registry extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var Moment_Syndication_Registry
	 */
	private Moment_Syndication_Registry $registry;

	public function set_up(): void {
		parent::set_up();
		$this->registry = Moment_Syndication_Registry::instance();
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

		$publisher = new Moment_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Syndication round trip',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$this->assertIsInt( $post_id );
		$external = json_decode( (string) get_post_meta( $post_id, '_moment_external_posts', true ), true );
		$this->assertArrayHasKey( 'bluesky', $external );
		$this->assertEquals( 'mocked', get_post_meta( $post_id, '_moment_syndication_status', true ) );
	}

	/** Your Site is always canonical — syndication never replaces the WP post. */
	public function test_your_site_always_canonical() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Moment_Publisher();
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
