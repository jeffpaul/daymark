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

		Daymark_Comment_Replies::flush_local_keys();
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

	/**
	 * A reply Daymark found to the owner's comment on a followed post
	 * (issue #559).
	 *
	 * @param int $mark_id    The Mark that carries the owner's comment; 0 for none.
	 * @param int $found_time When Daymark found the reply; 0 for a minute ago.
	 * @return string The reply's item ID.
	 */
	private function found_reply( int $mark_id = 0, int $found_time = 0 ): string {
		$sub_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'An origin post',
			)
		);
		update_post_meta( $sub_id, 'permalink', 'https://origin.example/post/' );

		Daymark_Comment_Replies::start_watch(
			$this->user_id,
			$sub_id,
			array(
				'root'    => 'https://origin.example/wp-json/',
				'remote'  => 55,
				'comment' => 9,
				'mark'    => $mark_id,
			)
		);

		$found_time       = $found_time > 0 ? $found_time : time() - 60;
		$watch            = Daymark_Comment_Replies::get_watches( $this->user_id )[0];
		$found            = $watch;
		$found['seen']    = array( '12' );
		$found['replies'] = array(
			array(
				'id'         => '12',
				'author'     => 'Ana',
				'author_url' => 'https://ana.example/',
				'avatar'     => '',
				'text'       => 'Thanks!',
				'url'        => 'https://origin.example/post/#comment-12',
				'date'       => $found_time,
				'found'      => $found_time,
				'local'      => false,
			),
		);
		update_user_meta( $this->user_id, Daymark_Comment_Replies::META, wp_slash( $found ), $watch );

		return 'comment_reply-' . $watch['id'] . '-12';
	}

	/**
	 * The same reply reaching the owner's Mark as a Webmention comment.
	 *
	 * @param int    $approved 1 approved, 0 held for moderation.
	 * @param string $date     GMT date; '' for now.
	 * @param string $type     Comment type.
	 * @return int Comment ID.
	 */
	private function webmention_copy( int $approved, string $date = '', string $type = 'comment' ): int {
		$date = '' !== $date ? $date : gmdate( 'Y-m-d H:i:s' );

		return (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_type'     => $type,
				'comment_approved' => $approved,
				'comment_author'   => 'Ana',
				'comment_content'  => 'Thanks!',
				'comment_date_gmt' => $date,
				'comment_date'     => $date,
				'comment_meta'     => array( 'webmention_source_url' => 'https://origin.example/post/#comment-12' ),
			)
		);
	}

	/**
	 * Approve a Webmention copy, or hold it for moderation again.
	 *
	 * @param int    $copy   Comment ID.
	 * @param string $status approve or hold.
	 * @return void
	 */
	private function set_copy_status( int $copy, string $status ): void {
		wp_set_comment_status( $copy, $status );
	}

	/**
	 * A found reply you archived stays archived when its Webmention copy on
	 * the Mark is approved and takes its place; unarchiving either brings
	 * the reply back.
	 */
	public function test_an_archived_reply_stays_archived_when_its_copy_is_approved() {
		update_post_meta( $this->post_id, '_daymark_in_reply_to', 'https://origin.example/post/' );
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 0 );

		$this->assertArrayHasKey( $id, $this->items(), 'A held copy leaves the found reply listed' );
		$this->post( 'archive', array( 'ids' => array( $id ) ) );

		$this->set_copy_status( $copy, 'approve' );

		$items = $this->items();
		$this->assertArrayNotHasKey( $id, $items );
		$this->assertArrayNotHasKey( 'comment-' . $copy, $items, 'The approved copy is the same reply, still archived' );

		$this->assertSame( 200, $this->post( 'unarchive', array( 'ids' => array( 'comment-' . $copy ) ) )->get_status() );

		$items = $this->items();
		$this->assertArrayHasKey( 'comment-' . $copy, $items );
		$this->assertArrayNotHasKey( $id, $items, 'Shown once, as the copy' );
		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Notification_State::ARCHIVED_META, false ) );
	}

	/** A reply archived while its copy was approved stays archived when the copy is held again. */
	public function test_an_archived_reply_stays_archived_when_its_copy_is_held_again() {
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 0 );

		$this->post( 'archive', array( 'ids' => array( $id ) ) );
		$this->set_copy_status( $copy, 'approve' );
		$this->items();

		$this->set_copy_status( $copy, 'hold' );

		$this->assertArrayNotHasKey( $id, $this->items() );
	}

	/** A copy you archived before Daymark found the reply keeps the reply archived too. */
	public function test_an_archived_copy_keeps_the_found_reply_archived() {
		$copy = $this->webmention_copy( 1, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->post( 'archive', array( 'ids' => array( 'comment-' . $copy ) ) );

		$id = $this->found_reply( $this->post_id );
		$this->assertArrayNotHasKey( 'comment-' . $copy, $this->items() );

		$this->set_copy_status( $copy, 'hold' );

		$this->assertArrayNotHasKey( $id, $this->items() );
	}

	/** A found reply you read keeps that once its copy takes its place, and marking the copy unread reaches the reply. */
	public function test_a_reply_keeps_its_read_state_when_its_copy_is_approved() {
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 0 );

		$this->post( 'read', array( 'ids' => array( $id ) ) );
		$this->set_copy_status( $copy, 'approve' );

		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );

		$this->post( 'unread', array( 'ids' => array( 'comment-' . $copy ) ) );
		$this->assertFalse( $this->items()[ 'comment-' . $copy ]['read'] );

		$this->set_copy_status( $copy, 'hold' );
		$this->assertFalse( $this->items()[ $id ]['read'] );
	}

	/** A reply marked unread after "Mark all as read" stays unread once its copy takes its place. */
	public function test_a_reply_marked_unread_stays_unread_when_its_copy_is_approved() {
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$this->post( 'read', array( 'all' => true ) );
		$this->post( 'unread', array( 'ids' => array( $id ) ) );
		$this->assertFalse( $this->items()[ $id ]['read'] );

		$this->set_copy_status( $copy, 'approve' );

		$this->assertFalse( $this->items()[ 'comment-' . $copy ]['read'] );
	}

	/** Replying to the copy marks it read, though the found reply was marked unread. */
	public function test_replying_to_the_copy_marks_it_read() {
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 0 );

		$this->post( 'unread', array( 'ids' => array( $id ) ) );
		$this->set_copy_status( $copy, 'approve' );
		$this->assertFalse( $this->items()[ 'comment-' . $copy ]['read'] );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/notifications/' . $copy . '/reply' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'content', 'You are welcome.' );
		$this->assertSame( 201, rest_do_request( $request )->get_status() );

		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * A copy that arrives after you saw the found reply keeps the reply's
	 * date: it isn't new, it's read, and it doesn't light the bell.
	 */
	public function test_a_copy_of_a_reply_you_saw_is_not_new() {
		$notifications = Daymark_Plugin::instance()->notifications;
		$found_time    = time() - 2 * HOUR_IN_SECONDS;
		$seen          = time() - HOUR_IN_SECONDS;

		$this->found_reply( $this->post_id, $found_time );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$copy = $this->webmention_copy( 1 );

		$this->assertFalse( $notifications->has_unread() );

		$items = array_column( $notifications->get_notifications( Daymark_Notifications::DEFAULT_LIMIT, $seen ), null, 'id' );
		$this->assertSame( $found_time, $items[ 'comment-' . $copy ]['timestamp'], 'It keeps the found reply\'s place' );
		$this->assertSame( gmdate( 'c', $found_time ), $items[ 'comment-' . $copy ]['date_gmt'] );
		$this->assertFalse( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertTrue( $items[ 'comment-' . $copy ]['read'] );
	}

	/** A copy of a reply found since you last looked is still new. */
	public function test_a_copy_of_a_reply_you_have_not_seen_is_new() {
		$notifications = Daymark_Plugin::instance()->notifications;
		$found_time    = time() - HOUR_IN_SECONDS;
		$seen          = time() - 2 * HOUR_IN_SECONDS;

		$this->found_reply( $this->post_id, $found_time );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$copy = $this->webmention_copy( 1 );

		$this->assertTrue( $notifications->has_unread() );

		$items = array_column( $notifications->get_notifications( Daymark_Notifications::DEFAULT_LIMIT, $seen ), null, 'id' );
		$this->assertSame( $found_time, $items[ 'comment-' . $copy ]['timestamp'] );
		$this->assertTrue( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertFalse( $items[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * A copy held for moderation until after Daymark found the reply was
	 * never listed before the reply was. Once approved it takes the reply's
	 * date: it stays new and unread, and the bell stays lit.
	 */
	public function test_a_copy_held_until_after_the_reply_was_found_stays_new() {
		$notifications = Daymark_Plugin::instance()->notifications;
		$copy_time     = time() - 3 * HOUR_IN_SECONDS;
		$seen          = time() - 2 * HOUR_IN_SECONDS;
		$found_time    = time() - HOUR_IN_SECONDS;

		$copy = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', $copy_time ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$id = $this->found_reply( $this->post_id, $found_time );

		$this->assertTrue( $notifications->has_unread(), 'The found reply is news' );
		$this->assertFalse( $this->items()[ $id ]['read'] );

		$this->set_copy_status( $copy, 'approve' );

		$this->assertTrue( $notifications->has_unread(), 'Approving its copy does not clear the bell' );

		$items = array_column( $notifications->get_notifications( Daymark_Notifications::DEFAULT_LIMIT, $seen ), null, 'id' );
		$this->assertArrayNotHasKey( $id, $items, 'Shown once, as the copy' );
		$this->assertSame( $found_time, $items[ 'comment-' . $copy ]['timestamp'], 'Dated when it was first listed' );
		$this->assertTrue( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertFalse( $items[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * The bell sees such a copy behind an older comment you already saw,
	 * though the copy itself was written before both.
	 */
	public function test_the_bell_sees_a_copy_dated_by_a_newly_found_reply() {
		$copy = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) );
		$this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 90 * MINUTE_IN_SECONDS ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() - HOUR_IN_SECONDS );
		$this->found_reply( $this->post_id, time() - 30 * MINUTE_IN_SECONDS );
		$this->set_copy_status( $copy, 'approve' );

		$this->assertTrue( Daymark_Plugin::instance()->notifications->has_unread() );
	}

	/**
	 * A copy that takes a newer reply's date is listed in the reply's
	 * place, though newer comments push the comment itself past the list.
	 */
	public function test_a_copy_moved_up_the_list_is_listed() {
		$copy   = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$newest = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
		$this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) );
		$this->found_reply( $this->post_id, time() - MINUTE_IN_SECONDS );
		$this->set_copy_status( $copy, 'approve' );

		$items = Daymark_Plugin::instance()->notifications->get_notifications( 2 );

		$this->assertSame( array( 'comment-' . $copy, 'comment-' . $newest ), array_column( $items, 'id' ) );
	}

	/**
	 * After "Mark all as read", such a copy stays unread once Daymark
	 * forgets the reply and the copy goes back to its own, older date.
	 */
	public function test_a_copy_unread_by_the_reply_date_stays_unread_after_the_reply_is_forgotten() {
		$copy = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) );
		update_user_meta( $this->user_id, Daymark_Notification_State::READ_BEFORE_META, time() - 2 * HOUR_IN_SECONDS );
		$this->found_reply( $this->post_id, time() - HOUR_IN_SECONDS );
		$this->set_copy_status( $copy, 'approve' );

		$this->assertFalse( $this->items()[ 'comment-' . $copy ]['read'] );

		delete_user_meta( $this->user_id, Daymark_Comment_Replies::META );

		$this->assertFalse( $this->items()[ 'comment-' . $copy ]['read'] );

		$this->post( 'read', array( 'all' => true ) );
		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * Without "Mark all as read", such a copy is read by the same rule as
	 * a comment from when the reply was found, and nothing is recorded.
	 */
	public function test_a_copy_held_until_after_the_reply_was_found_reads_like_any_other_item() {
		$found_time = time() - HOUR_IN_SECONDS;
		$copy       = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) );
		$control    = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', $found_time ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() - 2 * HOUR_IN_SECONDS );
		$this->found_reply( $this->post_id, $found_time );
		$this->set_copy_status( $copy, 'approve' );

		$items = $this->items();
		$this->assertFalse( $items[ 'comment-' . $copy ]['read'] );
		$this->assertFalse( $items[ 'comment-' . $control ]['read'] );

		// Another visit.
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() );

		$items = $this->items();
		$this->assertSame( $items[ 'comment-' . $control ]['read'], $items[ 'comment-' . $copy ]['read'] );
		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Notification_State::UNREAD_META, false ) );
	}

	/** A copy read because it took the found reply's date stays read after Daymark forgets the reply. */
	public function test_a_copy_read_by_the_reply_date_stays_read_after_the_reply_is_forgotten() {
		$this->found_reply( $this->post_id, time() - 2 * HOUR_IN_SECONDS );
		update_user_meta( $this->user_id, Daymark_Notification_State::READ_BEFORE_META, time() - HOUR_IN_SECONDS );
		$copy = $this->webmention_copy( 1 );

		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );

		delete_user_meta( $this->user_id, Daymark_Comment_Replies::META );

		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );
	}

	/** The bell still sees a new comment listed after a copy of an old reply. */
	public function test_the_bell_sees_a_new_comment_behind_a_copy() {
		$notifications = Daymark_Plugin::instance()->notifications;

		$this->found_reply( $this->post_id, time() - 2 * HOUR_IN_SECONDS );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() - HOUR_IN_SECONDS );
		$this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) );
		$this->webmention_copy( 1 );

		$this->assertTrue( $notifications->has_unread() );
	}

	/**
	 * A copy that takes an older reply's date can't hide a newer comment
	 * past the list's limit.
	 */
	public function test_a_copy_moved_down_the_list_does_not_hide_a_comment() {
		$this->found_reply( $this->post_id, time() - DAY_IN_SECONDS );
		$older = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
		$newer = $this->comment( 'comment', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$copy  = $this->webmention_copy( 1 );

		$items = Daymark_Plugin::instance()->notifications->get_notifications( 2 );

		$this->assertSame( array( 'comment-' . $newer, 'comment-' . $older ), array_column( $items, 'id' ) );
		$this->assertNotContains( 'comment-' . $copy, array_column( $items, 'id' ) );
	}

	/**
	 * A Webmention comment of a type Notifications doesn't list leaves the
	 * found reply showing, and the reply shows once its copy is changed to
	 * a type Notifications does list.
	 */
	public function test_a_copy_notifications_does_not_list_leaves_the_reply_shown() {
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 1, '', 'webmention' );

		$items = $this->items();
		$this->assertArrayHasKey( $id, $items );
		$this->assertArrayNotHasKey( 'comment-' . $copy, $items );

		wp_update_comment(
			array(
				'comment_ID'   => $copy,
				'comment_type' => 'comment',
			)
		);

		$items = $this->items();
		$this->assertArrayNotHasKey( $id, $items );
		$this->assertArrayHasKey( 'comment-' . $copy, $items );
	}

	/**
	 * A Webmention comment on a Mark Notifications doesn't list (here a
	 * private one) leaves the found reply showing, and takes its place
	 * again once the Mark is listed.
	 */
	public function test_a_copy_on_a_mark_notifications_does_not_list_leaves_the_reply_shown() {
		$id   = $this->found_reply( $this->post_id );
		$copy = $this->webmention_copy( 1 );

		$items = $this->items();
		$this->assertArrayNotHasKey( $id, $items );
		$this->assertArrayHasKey( 'comment-' . $copy, $items );

		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'private',
			)
		);

		$items = $this->items();
		$this->assertArrayHasKey( $id, $items );
		$this->assertArrayNotHasKey( 'comment-' . $copy, $items );
		$this->assertArrayNotHasKey( 'comment-' . $copy, Daymark_Comment_Replies::superseded( $this->user_id ) );

		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'publish',
			)
		);

		$items = $this->items();
		$this->assertArrayNotHasKey( $id, $items );
		$this->assertArrayHasKey( 'comment-' . $copy, $items );
	}

	/** A followed site's reply can be read and archived; its state goes when Daymark forgets it. */
	public function test_followed_site_reply_state() {
		$id = $this->found_reply();

		$items = $this->items();
		$this->assertArrayHasKey( $id, $items );
		$this->assertSame( 'comments', $items[ $id ]['category'] );
		$this->assertFalse( $items[ $id ]['read'] );

		$this->assertSame( 200, $this->post( 'read', array( 'ids' => array( $id ) ) )->get_status() );
		$this->assertTrue( $this->items()[ $id ]['read'] );

		$this->assertSame( 200, $this->post( 'archive', array( 'ids' => array( $id ) ) )->get_status() );
		$this->assertArrayNotHasKey( $id, $this->items() );

		delete_user_meta( $this->user_id, Daymark_Comment_Replies::META );
		$this->items();
		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Notification_State::READ_META, false ) );
		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Notification_State::ARCHIVED_META, false ) );
	}

	/** ID validation. */
	public function test_is_valid_id() {
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'comment-12' ) );
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'jetpack_like-4-99887' ) );
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'plugin_overlap-post-kinds' ) );
		$this->assertTrue( Daymark_Notification_State::is_valid_id( 'comment_reply-0a1b2c3d4e5f-12' ) );
		$this->assertFalse( Daymark_Notification_State::is_valid_id( 'comment-' ) );
		$this->assertFalse( Daymark_Notification_State::is_valid_id( 'post-12' ) );
		$this->assertFalse( Daymark_Notification_State::is_valid_id( 'comment-1 OR 1=1' ) );
	}

	/**
	 * Notification items keyed by ID, with is_new against a last visit.
	 *
	 * @param int $seen The last visit to Notifications.
	 * @return array<string, array<string, mixed>>
	 */
	private function items_seen( int $seen ): array {
		return array_column( Daymark_Plugin::instance()->notifications->get_notifications( Daymark_Notifications::DEFAULT_LIMIT, $seen ), null, 'id' );
	}

	/**
	 * Age every watch past the time it's kept, then run a check, which
	 * forgets them and their replies.
	 *
	 * @return void
	 */
	private function expire_watches(): void {
		foreach ( Daymark_Comment_Replies::get_watches( $this->user_id ) as $watch ) {
			$expired            = $watch;
			$expired['created'] = time() - 46 * DAY_IN_SECONDS;
			update_user_meta( $this->user_id, Daymark_Comment_Replies::META, wp_slash( $expired ), $watch );
		}

		delete_transient( Daymark_Comment_Replies::LOCK );
		Daymark_Comment_Replies::check_due();

		$this->assertSame( array(), Daymark_Comment_Replies::get_watches( $this->user_id ) );
	}

	/**
	 * After Daymark forgets a found reply, its copy still sits where the
	 * reply was found: not new, read, and not lighting the bell.
	 *
	 * @param int    $copy       The copy's comment ID.
	 * @param string $id         The forgotten reply's item ID.
	 * @param int    $found_time When Daymark found the reply.
	 * @param int    $seen       The last visit to Notifications.
	 * @return void
	 */
	private function assert_copy_keeps_the_reply_date( int $copy, string $id, int $found_time, int $seen ): void {
		$this->assertSame(
			array(
				$this->user_id => array(
					'from'  => $id,
					'found' => $found_time,
				),
			),
			get_comment_meta( $copy, Daymark_Comment_Replies::FOUND_META, true )
		);
		$this->assertFalse( Daymark_Plugin::instance()->notifications->has_unread() );

		$items = $this->items_seen( $seen );
		$this->assertArrayNotHasKey( $id, $items );
		$this->assertSame( $found_time, $items[ 'comment-' . $copy ]['timestamp'], 'It keeps the found reply\'s place' );
		$this->assertFalse( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertTrue( $items[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * A copy of a reply you saw keeps the reply's date after Daymark stops
	 * keeping the reply, so it doesn't turn new again.
	 */
	public function test_a_copy_keeps_the_reply_date_after_the_reply_expires() {
		$found_time = time() - 2 * HOUR_IN_SECONDS;
		$seen       = time() - HOUR_IN_SECONDS;

		$id = $this->found_reply( $this->post_id, $found_time );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$copy = $this->webmention_copy( 1 );

		$this->expire_watches();

		$this->assert_copy_keeps_the_reply_date( $copy, $id, $found_time, $seen );
	}

	/**
	 * A copy held for moderation when Daymark stopped keeping the reply
	 * still takes the reply's date once it's approved.
	 */
	public function test_a_copy_approved_after_the_reply_expires_keeps_the_reply_date() {
		$found_time = time() - 2 * HOUR_IN_SECONDS;
		$seen       = time() - HOUR_IN_SECONDS;

		$id = $this->found_reply( $this->post_id, $found_time );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$copy = $this->webmention_copy( 0 );

		$this->expire_watches();
		$this->set_copy_status( $copy, 'approve' );

		$this->assert_copy_keeps_the_reply_date( $copy, $id, $found_time, $seen );
	}

	/**
	 * A copy written before your last visit, of a reply found after it,
	 * stays new and unread after Daymark stops keeping the reply.
	 */
	public function test_a_copy_of_a_newly_found_reply_stays_new_after_the_reply_expires() {
		$notifications = Daymark_Plugin::instance()->notifications;
		$seen          = time() - 2 * HOUR_IN_SECONDS;
		$found_time    = time() - HOUR_IN_SECONDS;

		$copy = $this->webmention_copy( 0, gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$this->found_reply( $this->post_id, $found_time );
		$this->set_copy_status( $copy, 'approve' );

		$this->expire_watches();

		$this->assertTrue( $notifications->has_unread() );

		$items = $this->items_seen( $seen );
		$this->assertSame( $found_time, $items[ 'comment-' . $copy ]['timestamp'] );
		$this->assertTrue( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertFalse( $items[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * A copy keeps the reply's date when its watch is dropped to make
	 * room for newer ones.
	 */
	public function test_a_copy_keeps_the_reply_date_after_its_watch_is_trimmed() {
		$found_time = time() - 2 * HOUR_IN_SECONDS;
		$seen       = time() - HOUR_IN_SECONDS;

		$id    = $this->found_reply( $this->post_id, $found_time );
		$watch = Daymark_Comment_Replies::get_watches( $this->user_id )[0];
		$older = $watch;

		$older['created'] = time() - 4 * HOUR_IN_SECONDS;
		update_user_meta( $this->user_id, Daymark_Comment_Replies::META, wp_slash( $older ), $watch );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$copy = $this->webmention_copy( 1 );

		$other = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Another origin post',
			)
		);
		update_post_meta( $other, 'permalink', 'https://origin.example/other/' );

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertTrue(
				Daymark_Comment_Replies::start_watch(
					$this->user_id,
					$other,
					array(
						'root'    => 'https://origin.example/wp-json/',
						'remote'  => 56,
						'comment' => 100 + $i,
					)
				)
			);
		}

		$watches = Daymark_Comment_Replies::get_watches( $this->user_id );
		$this->assertCount( 25, $watches );
		$this->assertNotContains( $watch['id'], array_column( $watches, 'id' ), 'The oldest watch made room' );

		$this->assert_copy_keeps_the_reply_date( $copy, $id, $found_time, $seen );
	}

	/**
	 * A reply you read, whose copy arrives after "Mark all as read", keeps
	 * that read state after Daymark stops keeping the reply.
	 */
	public function test_a_copy_keeps_the_replys_read_state_after_the_reply_expires() {
		update_user_meta( $this->user_id, Daymark_Notification_State::READ_BEFORE_META, time() - 5 * HOUR_IN_SECONDS );
		$id = $this->found_reply( $this->post_id, time() - HOUR_IN_SECONDS );
		$this->post( 'read', array( 'ids' => array( $id ) ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() - 2 * HOUR_IN_SECONDS );
		$copy = $this->webmention_copy( 0 );
		$this->set_copy_status( $copy, 'approve' );

		$this->expire_watches();

		$this->assertSame( '', get_comment_meta( $copy, Daymark_Comment_Replies::FOUND_META, true ), 'Its own date reads the same way' );
		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );
	}

	/** The same, for a copy approved after Daymark stopped keeping the reply. */
	public function test_a_copy_approved_after_the_reply_expires_keeps_its_read_state() {
		update_user_meta( $this->user_id, Daymark_Notification_State::READ_BEFORE_META, time() - 5 * HOUR_IN_SECONDS );
		$id = $this->found_reply( $this->post_id, time() - HOUR_IN_SECONDS );
		$this->post( 'read', array( 'ids' => array( $id ) ) );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, time() - 2 * HOUR_IN_SECONDS );
		$copy = $this->webmention_copy( 0 );

		$this->expire_watches();
		$this->set_copy_status( $copy, 'approve' );

		$this->assertTrue( $this->items()[ 'comment-' . $copy ]['read'] );
	}

	/** A reply you archived stays archived as its copy after Daymark stops keeping the reply. */
	public function test_a_copy_stays_archived_after_the_reply_expires() {
		$id = $this->found_reply( $this->post_id );
		$this->post( 'archive', array( 'ids' => array( $id ) ) );
		$copy = $this->webmention_copy( 1 );

		$this->expire_watches();

		$items = $this->items();
		$this->assertArrayNotHasKey( $id, $items );
		$this->assertArrayNotHasKey( 'comment-' . $copy, $items );
	}

	/**
	 * Once both dates read the same way, the next check drops the link
	 * and the copy is listed by its own date.
	 */
	public function test_a_link_is_dropped_once_it_no_longer_changes_anything() {
		$found_time = time() - 2 * HOUR_IN_SECONDS;
		$seen       = time() - HOUR_IN_SECONDS;

		$id = $this->found_reply( $this->post_id, $found_time );
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		$copy = $this->webmention_copy( 1 );

		$this->expire_watches();
		$this->assert_copy_keeps_the_reply_date( $copy, $id, $found_time, $seen );

		// Another visit.
		$seen = time();
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );
		delete_transient( Daymark_Comment_Replies::LOCK );
		Daymark_Comment_Replies::check_due();

		$this->assertSame( '', get_comment_meta( $copy, Daymark_Comment_Replies::FOUND_META, true ) );
		$this->assertFalse( Daymark_Plugin::instance()->notifications->has_unread() );

		$items = $this->items_seen( $seen );
		$this->assertSame( (int) strtotime( get_comment( $copy )->comment_date_gmt . ' UTC' ), $items[ 'comment-' . $copy ]['timestamp'] );
		$this->assertFalse( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertTrue( $items[ 'comment-' . $copy ]['read'] );
	}

	/**
	 * A copy that reads the same either way isn't linked when Daymark
	 * stops keeping the reply, but it's still marked as pushed, so
	 * approving it again later doesn't push the reply a second time.
	 */
	public function test_a_copy_that_reads_the_same_either_way_is_marked_pushed_but_not_linked() {
		$this->found_reply( $this->post_id, time() - 2 * HOUR_IN_SECONDS );
		$copy = $this->webmention_copy( 1 );
		$seen = time();
		update_user_meta( $this->user_id, Daymark_Notifications::SEEN_META, $seen );

		$this->assertSame( '', get_comment_meta( $copy, Daymark_Push::COMMENT_SENT_META, true ) );

		$this->expire_watches();

		$this->assertSame( '', get_comment_meta( $copy, Daymark_Comment_Replies::FOUND_META, true ) );
		$this->assertSame( '1', get_comment_meta( $copy, Daymark_Push::COMMENT_SENT_META, true ) );
		$this->assertFalse( Daymark_Plugin::instance()->notifications->has_unread() );

		$items = $this->items_seen( $seen );
		$this->assertFalse( $items[ 'comment-' . $copy ]['is_new'] );
		$this->assertTrue( $items[ 'comment-' . $copy ]['read'] );
	}
}
