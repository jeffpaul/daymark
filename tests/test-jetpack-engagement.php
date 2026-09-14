<?php
/**
 * Tests for Daymark_Jetpack_Engagement (issue #391): the Jetpack-native
 * Like/Comment fast path's detection, per-user state, and cleanup.
 *
 * The real `Automattic\Jetpack\Connection\Client`/`Manager` classes are never
 * loaded in this test environment (Jetpack isn't a test dependency), so
 * `is_available()` is false throughout — exercising exactly the "not
 * available, degrade gracefully" contract every caller (REST routes,
 * Daymark_Comment_Delivery) relies on to leave the classic path completely
 * unaffected. The per-user meta state methods (mark_liked()/is_liked()/etc.)
 * are independent of that availability check and are tested directly.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Jetpack_Engagement directly.
 */
class Test_Jetpack_Engagement extends WP_UnitTestCase {

	/** @var int */
	private $user_a;

	/** @var int */
	private $user_b;

	public function set_up(): void {
		parent::set_up();

		$this->user_a = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->user_b = (int) self::factory()->user->create( array( 'role' => 'author' ) );
	}

	// -----------------------------------------------------------------
	// Availability / connection detection — graceful absence.
	// -----------------------------------------------------------------

	public function test_is_available_is_false_without_the_real_jetpack_classes() {
		$this->assertFalse( Daymark_Jetpack_Engagement::is_available() );
	}

	public function test_current_user_connected_is_false_when_unavailable() {
		wp_set_current_user( $this->user_a );

		$this->assertFalse( Daymark_Jetpack_Engagement::current_user_connected() );
	}

	public function test_current_user_connected_is_false_for_no_current_user() {
		wp_set_current_user( 0 );

		$this->assertFalse( Daymark_Jetpack_Engagement::current_user_connected() );
	}

	public function test_resolve_origin_is_null_when_unavailable() {
		$this->assertNull( Daymark_Jetpack_Engagement::resolve_origin( 'https://example.com/hello-world/' ) );
	}

	public function test_like_is_a_wp_error_when_unavailable() {
		$result = Daymark_Jetpack_Engagement::like( 123, 456 );

		$this->assertWPError( $result );
	}

	public function test_unlike_is_a_wp_error_when_unavailable() {
		$result = Daymark_Jetpack_Engagement::unlike( 123, 456 );

		$this->assertWPError( $result );
	}

	public function test_comment_is_a_wp_error_when_unavailable() {
		$result = Daymark_Jetpack_Engagement::comment( 123, 456, 'Nice post!' );

		$this->assertWPError( $result );
	}

	public function test_connect_account_url_points_at_the_jetpack_dashboard() {
		$this->assertStringContainsString( 'page=jetpack', Daymark_Jetpack_Engagement::connect_account_url() );
	}

	// -----------------------------------------------------------------
	// Per-user Like state — independent of Jetpack availability.
	// -----------------------------------------------------------------

	public function test_not_liked_by_default() {
		$this->assertFalse( Daymark_Jetpack_Engagement::is_liked( $this->user_a, 123 ) );
	}

	public function test_mark_liked_then_is_liked() {
		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, 123 );

		$this->assertTrue( Daymark_Jetpack_Engagement::is_liked( $this->user_a, 123 ) );
	}

	public function test_mark_liked_is_idempotent() {
		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, 123 );
		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, 123 );

		$raw = get_user_meta( $this->user_a, Daymark_Jetpack_Engagement::META_LIKE, false );
		$this->assertCount( 1, $raw );
	}

	public function test_unmark_liked() {
		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, 123 );
		Daymark_Jetpack_Engagement::unmark_liked( $this->user_a, 123 );

		$this->assertFalse( Daymark_Jetpack_Engagement::is_liked( $this->user_a, 123 ) );
	}

	public function test_like_state_is_scoped_per_user() {
		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, 123 );

		$this->assertFalse( Daymark_Jetpack_Engagement::is_liked( $this->user_b, 123 ) );
	}

	// -----------------------------------------------------------------
	// Per-user Comment state — write-once, no undo.
	// -----------------------------------------------------------------

	public function test_not_commented_by_default() {
		$this->assertFalse( Daymark_Jetpack_Engagement::is_commented( $this->user_a, 123 ) );
	}

	public function test_mark_commented_then_is_commented() {
		Daymark_Jetpack_Engagement::mark_commented( $this->user_a, 123 );

		$this->assertTrue( Daymark_Jetpack_Engagement::is_commented( $this->user_a, 123 ) );
	}

	public function test_mark_commented_is_idempotent() {
		Daymark_Jetpack_Engagement::mark_commented( $this->user_a, 123 );
		Daymark_Jetpack_Engagement::mark_commented( $this->user_a, 123 );

		$raw = get_user_meta( $this->user_a, Daymark_Jetpack_Engagement::META_COMMENT, false );
		$this->assertCount( 1, $raw );
	}

	public function test_comment_state_is_scoped_per_user() {
		Daymark_Jetpack_Engagement::mark_commented( $this->user_a, 123 );

		$this->assertFalse( Daymark_Jetpack_Engagement::is_commented( $this->user_b, 123 ) );
	}

	// -----------------------------------------------------------------
	// deleted_post cleanup — mirrors Daymark_Bookmarks' own convention.
	// -----------------------------------------------------------------

	public function test_deleted_post_clears_like_and_comment_state_for_every_user() {
		$post_id = (int) self::factory()->post->create();

		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, $post_id );
		Daymark_Jetpack_Engagement::mark_commented( $this->user_b, $post_id );

		wp_delete_post( $post_id, true );

		$this->assertFalse( Daymark_Jetpack_Engagement::is_liked( $this->user_a, $post_id ) );
		$this->assertFalse( Daymark_Jetpack_Engagement::is_commented( $this->user_b, $post_id ) );
	}

	public function test_trashing_a_post_does_not_clear_state() {
		$post_id = (int) self::factory()->post->create();

		Daymark_Jetpack_Engagement::mark_liked( $this->user_a, $post_id );

		wp_trash_post( $post_id );

		$this->assertTrue( Daymark_Jetpack_Engagement::is_liked( $this->user_a, $post_id ) );
	}
}
