<?php
/**
 * Notifications read and archived state: tabs' categories, per-item read,
 * mark all as read, archive and unarchive, and the REST routes.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Notification_State and the /notifications/{read,unread,
 * archive,unarchive} routes.
 */
class Test_Notification_State extends WP_UnitTestCase {

	/**
	 * Mark owner.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * A Mark the owner can edit.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );

		$this->post_id = self::factory()->post->create( array( 'post_author' => $this->user_id ) );
		update_post_meta( $this->post_id, '_daymark_is_mark', '1' );
		update_post_meta( $this->post_id, '_daymark_primary_type', 'note' );
	}

	/**
	 * Create an approved comment on the Mark.
	 *
	 * @param string $type    Comment type.
	 * @param string $date    GMT date.
	 * @return int
	 */
	private function comment( string $type = 'comment', string $date = '' ): int {
		$date = '' !== $date ? $date : gmdate( 'Y-m-d H:i:s', time() - 60 );

		return (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_type'     => $type,
				'comment_approved' => 1,
				'comment_date_gmt' => $date,
				'comment_date'     => $date,
			)
		);
	}

	/**
	 * Current notification items keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function items(): array {
		return array_column( Daymark_Plugin::instance()->notifications->get_notifications(), null, 'id' );
	}

	/**
	 * POST to a notification-state route.
	 *
	 * @param string               $action read, unread, archive, unarchive.
	 * @param array<string, mixed> $body   Request body.
	 * @return WP_REST_Response
	 */
	private function post( string $action, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/notifications/' . $action );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/** Every item has a stable ID, a tab category, an ISO date, and a read flag. */
	public function test_items_carry_id_category_date_and_read_flag() {
		$reply   = $this->comment();
		$mention = $this->comment( 'pingback' );

		$items = $this->items();

		$this->assertArrayHasKey( 'comment-' . $reply, $items );
		$this->assertSame( 'comments', $items[ 'comment-' . $reply ]['category'] );
		$this->assertSame( 'mentions', $items[ 'comment-' . $mention ]['category'] );
		$this->assertSame( 'mention', $items[ 'comment-' . $mention ]['comment_kind'] );
		$this->assertFalse( $items[ 'comment-' . $reply ]['read'] );
		$this->assertNotSame( '', $items[ 'comment-' . $reply ]['date_gmt'] );
	}

	/** Items are newest first. */
	public function test_items_are_newest_first() {
		$older = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * 3 ) );
		$newer = $this->comment( 'like', gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$ids = array_keys( $this->items() );

		$this->assertSame( array( 'comment-' . $newer, 'comment-' . $older ), $ids );
	}

	/** Anything before the last visit to Notifications starts out read. */
	public function test_items_seen_before_this_feature_start_read() {
		$old = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$new = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 10 ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() - 60 );

		$items = $this->items();

		$this->assertTrue( $items[ 'comment-' . $old ]['read'] );
		$this->assertFalse( $items[ 'comment-' . $new ]['read'] );
	}

	/** Marking one item read, then unread again, round-trips through the routes. */
	public function test_mark_read_and_unread() {
		$id = 'comment-' . $this->comment();

		$this->assertSame( 200, $this->post( 'read', array( 'ids' => array( $id ) ) )->get_status() );
		$this->assertTrue( $this->items()[ $id ]['read'] );

		$this->assertSame( 200, $this->post( 'unread', array( 'ids' => array( $id ) ) )->get_status() );
		$this->assertFalse( $this->items()[ $id ]['read'] );
	}

	/** An item covered by "Mark all as read" can still be marked unread. */
	public function test_mark_unread_after_mark_all_read() {
		$id = 'comment-' . $this->comment();

		$this->post( 'read', array( 'all' => true ) );
		$this->assertTrue( $this->items()[ $id ]['read'] );

		$this->post( 'unread', array( 'ids' => array( $id ) ) );
		$this->assertFalse( $this->items()[ $id ]['read'] );
	}

	/** Mark all as read covers every current item. */
	public function test_mark_all_read() {
		$this->comment();
		$this->comment( 'like' );

		$response = $this->post( 'read', array( 'all' => true ) );

		$this->assertSame( 200, $response->get_status() );
		foreach ( $this->items() as $item ) {
			$this->assertTrue( $item['read'] );
		}
	}

	/** Archiving hides a notification without deleting the comment; unarchive brings it back. */
	public function test_archive_and_unarchive() {
		$comment_id = $this->comment();
		$id         = 'comment-' . $comment_id;

		$this->post( 'archive', array( 'ids' => array( $id ) ) );

		$this->assertArrayNotHasKey( $id, $this->items() );
		$this->assertInstanceOf( WP_Comment::class, get_comment( $comment_id ), 'The comment itself stays' );

		$this->post( 'unarchive', array( 'ids' => array( $id ) ) );

		$items = $this->items();
		$this->assertArrayHasKey( $id, $items );
		$this->assertTrue( $items[ $id ]['read'], 'An archived item comes back read' );
	}

	/** Archived items don't shorten the list below the limit. */
	public function test_archived_items_do_not_shorten_the_list() {
		$ids = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = 'comment-' . $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 60 * ( $i + 1 ) ) );
		}

		$this->post( 'archive', array( 'ids' => array( $ids[0] ) ) );

		$items = Daymark_Plugin::instance()->notifications->get_notifications( 3 );

		$this->assertCount( 3, $items );
		$this->assertSame( array_slice( $ids, 1 ), array_column( $items, 'id' ) );
	}

	/** State is per user. */
	public function test_state_is_per_user() {
		$id = 'comment-' . $this->comment();
		$this->post( 'archive', array( 'ids' => array( $id ) ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->assertArrayHasKey( $id, $this->items() );
	}

	/** Malformed IDs are dropped; a request with none left is refused. */
	public function test_rejects_requests_with_no_valid_ids() {
		$response = $this->post( 'read', array( 'ids' => array( 'not an id', '<script>', 'post-1' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Notification_State::READ_META, false ) );
	}

	/** The routes need a logged-in user. */
	public function test_routes_reject_unauthenticated_requests() {
		wp_set_current_user( 0 );

		foreach ( array( 'read', 'unread', 'archive', 'unarchive' ) as $action ) {
			$this->assertSame( 401, $this->post( $action, array( 'ids' => array( 'comment-1' ) ) )->get_status() );
		}
	}

	/** Replying from Notifications marks that notification read. */
	public function test_reply_marks_read() {
		$comment_id = $this->comment();

		$request = new WP_REST_Request( 'POST', '/daymark/v1/notifications/' . $comment_id . '/reply' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'content', 'Thanks!' );
		$this->assertSame( 201, rest_do_request( $request )->get_status() );

		$this->assertTrue( $this->items()[ 'comment-' . $comment_id ]['read'] );
	}

	/** Deleting a comment forgets its state for every user. */
	public function test_deleted_comment_state_is_forgotten() {
		$comment_id = $this->comment();
		$this->post( 'archive', array( 'ids' => array( 'comment-' . $comment_id ) ) );

		wp_delete_comment( $comment_id, true );

		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Notification_State::ARCHIVED_META, false ) );
	}

	/** A recovered subscription's archived issue is dropped, so a new failure shows again. */
	public function test_feed_issue_state_is_pruned_once_the_issue_is_gone() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://failing.example/',
				'feed_url' => 'https://failing.example/feed/',
			)
		);
		$subscriptions->update(
			$subscription_id,
			array(
				'consecutive_failure_count' => 1,
				'last_checked_at'           => current_time( 'mysql', true ),
				'last_error'                => 'Unreachable.',
			)
		);

		$id = 'feed_issue-' . $subscription_id;
		$this->assertArrayHasKey( $id, $this->items() );
		$this->post( 'archive', array( 'ids' => array( $id ) ) );
		$this->assertArrayNotHasKey( $id, $this->items() );

		$subscriptions->update( $subscription_id, array( 'consecutive_failure_count' => 0 ) );
		$this->items();
		$this->assertSame( array(), get_user_meta( $admin, Daymark_Notification_State::ARCHIVED_META, false ) );

		$subscriptions->update( $subscription_id, array( 'consecutive_failure_count' => 1 ) );
		$this->assertArrayHasKey( $id, $this->items() );
	}

	/** ID validation. */
	public function test_is_valid_id() {
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'comment-12' ) );
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'jetpack_like-4-99887' ) );
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'plugin_overlap-post-kinds' ) );
		$this->assertFalse( Daymark_Notification_State::is_valid_id( 'comment-' ) );
		$this->assertFalse( Daymark_Notification_State::is_valid_id( 'post-12' ) );
		$this->assertFalse( Daymark_Notification_State::is_valid_id( 'comment-1 OR 1=1' ) );
	}
}
