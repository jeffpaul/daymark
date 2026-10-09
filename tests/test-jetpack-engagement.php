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

	// -----------------------------------------------------------------
	// Likes on this site's own Marks (pulled in from WordPress.com).
	// -----------------------------------------------------------------

	public function test_site_connected_is_false_when_unavailable() {
		$this->assertFalse( Daymark_Jetpack_Engagement::site_connected() );
	}

	public function test_sync_own_likes_no_ops_when_unavailable() {
		$post_id = (int) self::factory()->post->create();

		$this->assertFalse( Daymark_Jetpack_Engagement::sync_own_likes( $post_id ) );
		$this->assertSame( 0, Daymark_Jetpack_Engagement::own_likes( $post_id )['count'] );
	}

	public function test_store_own_likes_records_count_and_likers() {
		$post_id = (int) self::factory()->post->create();

		Daymark_Jetpack_Engagement::store_own_likes(
			$post_id,
			array(
				'found' => 3,
				'likes' => array(
					array(
						'ID'         => 11,
						'name'       => 'Ada',
						'URL'        => 'https://ada.example/',
						'avatar_URL' => 'https://gravatar.example/ada.png',
						'date_liked' => '2026-09-01T10:00:00+00:00',
					),
					array(
						'ID'   => 12,
						'name' => 'Grace',
					),
				),
			)
		);

		$likes = Daymark_Jetpack_Engagement::own_likes( $post_id );

		$this->assertSame( 3, $likes['count'] );
		$this->assertCount( 2, $likes['likers'] );
		$this->assertSame( 'Ada', $likes['likers']['11']['name'] );
		$this->assertSame( strtotime( '2026-09-01T10:00:00+00:00' ), $likes['likers']['11']['liked_at'] );
		$this->assertGreaterThan( 0, $likes['likers']['12']['liked_at'] );
	}

	public function test_store_own_likes_keeps_first_seen_date_and_drops_unlikes() {
		$post_id = (int) self::factory()->post->create();

		Daymark_Jetpack_Engagement::store_own_likes(
			$post_id,
			array(
				'likes' => array(
					array(
						'ID'   => 21,
						'name' => 'First',
					),
					array(
						'ID'   => 22,
						'name' => 'Leaver',
					),
				),
			)
		);
		$first_seen = Daymark_Jetpack_Engagement::own_likes( $post_id )['likers']['21']['liked_at'];

		Daymark_Jetpack_Engagement::store_own_likes(
			$post_id,
			array(
				'likes' => array(
					array(
						'ID'         => 21,
						'name'       => 'First',
						'date_liked' => '2020-01-01T00:00:00+00:00',
					),
				),
			)
		);
		$likes = Daymark_Jetpack_Engagement::own_likes( $post_id );

		$this->assertSame( 1, $likes['count'] );
		$this->assertSame( $first_seen, $likes['likers']['21']['liked_at'] );
		$this->assertArrayNotHasKey( '22', $likes['likers'] );
	}

	public function test_own_likes_add_to_the_timeline_like_count_and_notifications() {
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $editor,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		Daymark_Jetpack_Engagement::store_own_likes(
			$post_id,
			array(
				'found' => 1,
				'likes' => array(
					array(
						'ID'   => 31,
						'name' => 'Linus',
					),
				),
			)
		);

		$items = Daymark_Plugin::instance()->notifications->get_notifications();
		$likes = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'jetpack_like' === ( $item['type'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $likes );
		$this->assertSame( 'Linus', $likes[0]['author'] );
		$this->assertSame( $post_id, $likes[0]['post_id'] );
		$this->assertSame( 'wpcom', $likes[0]['source'] );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['like_count'] );
	}

	/** The older-likes pass walks every published post, newest first, then starts over. */
	public function test_older_likes_batches_walk_all_posts_then_restart() {
		delete_option( Daymark_Jetpack_Engagement::OLDER_LIKES_CURSOR );
		$batch = static function () {
			return 2;
		};
		add_filter( 'daymark_jetpack_older_likes_batch', $batch );

		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		rsort( $ids );

		$this->assertSame( array( $ids[0], $ids[1] ), Daymark_Jetpack_Engagement::next_older_likes_batch() );
		$this->assertSame( array( $ids[2] ), Daymark_Jetpack_Engagement::next_older_likes_batch(), 'Continues below the last post, skipping drafts and pages.' );
		$this->assertSame( array( $ids[0], $ids[1] ), Daymark_Jetpack_Engagement::next_older_likes_batch(), 'Starts over from the newest after the oldest.' );

		remove_filter( 'daymark_jetpack_older_likes_batch', $batch );
	}

	/** Without a Jetpack connection the older-likes pass does nothing. */
	public function test_sync_older_likes_no_ops_when_unavailable() {
		delete_option( Daymark_Jetpack_Engagement::OLDER_LIKES_CURSOR );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( 0, Daymark_Jetpack_Engagement::sync_older_likes() );
		$this->assertFalse( get_option( Daymark_Jetpack_Engagement::OLDER_LIKES_CURSOR ), 'No position stored when nothing ran.' );
	}

	/** A like with no date found on an old post is dated with the post, not now. */
	public function test_store_own_likes_uses_the_fallback_date() {
		$post_id   = (int) self::factory()->post->create();
		$post_date = strtotime( '2020-01-01 00:00:00' );

		Daymark_Jetpack_Engagement::store_own_likes(
			$post_id,
			array(
				'found' => 1,
				'likes' => array(
					array(
						'ID'   => 7,
						'name' => 'Old Friend',
					),
				),
			),
			$post_date
		);

		$this->assertSame( $post_date, Daymark_Jetpack_Engagement::own_likes( $post_id )['likers']['7']['liked_at'] );
	}
}
