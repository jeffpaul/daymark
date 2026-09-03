<?php
/**
 * Notifications + backflow tests — E2E scenarios 7, 8, 9.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Notifications backflow import, exclusion scope, portability.
 */
class Test_Notifications extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// The daymark_subscription table is normally created on plugin
		// activation; tests run against a live WP install where activation
		// hooks don't fire, so install() is called directly here (it is
		// idempotent either way) — mirrors Test_Subscriptions::set_up().
		Daymark_Subscriptions::install();
	}

	/**
	 * Issue #78, "Dead feed detection": a subscription flagged
	 * `status = 'error'` surfaces as a `dead_feed` notification item with
	 * the expected fields, and every field is the type get_notifications()
	 * promises (no raw/unsanitized passthrough).
	 */
	public function test_flagged_subscription_appears_as_dead_feed_notification() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url'   => 'https://example.com/',
				'feed_url'   => 'https://example.com/feed/',
				'site_title' => 'Example Site',
			)
		);
		$this->assertIsInt( $subscription_id );

		$this->assertTrue(
			$subscriptions->update(
				$subscription_id,
				array(
					'status'                    => 'error',
					'consecutive_failure_count' => 7,
					'last_checked_at'           => '2026-01-01 00:00:00',
				)
			)
		);

		$notifications = new Daymark_Notifications();
		$items         = $notifications->get_notifications();

		$dead_feed_items = array_values(
			array_filter(
				$items,
				static function ( array $item ) {
					return 'dead_feed' === ( $item['type'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $dead_feed_items );

		$item = $dead_feed_items[0];
		$this->assertSame( $subscription_id, $item['subscription_id'] );
		$this->assertSame( 'Example Site', $item['site_title'] );
		$this->assertSame( 'https://example.com/', $item['site_url'] );
		$this->assertSame( 'https://example.com/feed/', $item['feed_url'] );
		$this->assertSame( 'error', $item['status'] );
		$this->assertSame( 7, $item['consecutive_failure_count'] );
		$this->assertSame( '2026-01-01 00:00:00', $item['last_checked_at'] );
		$this->assertNotEmpty( $item['last_checked_at_relative'] );
	}

	/**
	 * Scenario (issue #81): a `dead_feed` item's `last_error` field carries
	 * the human-readable reason for the most recent failed check.
	 */
	public function test_dead_feed_item_includes_last_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://error-example.com/',
				'feed_url' => 'https://error-example.com/feed/',
			)
		);
		$this->assertIsInt( $subscription_id );

		$this->assertTrue(
			$subscriptions->update(
				$subscription_id,
				array(
					'status'          => 'error',
					'last_checked_at' => '2026-01-01 00:00:00',
					'last_error'      => 'This subscription\'s feed could not be reached or parsed.',
				)
			)
		);

		$notifications = new Daymark_Notifications();
		$items         = $notifications->get_notifications();

		$dead_feed_items = array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $subscription_id ) {
					return 'dead_feed' === ( $item['type'] ?? '' ) && $subscription_id === $item['subscription_id'];
				}
			)
		);

		$this->assertCount( 1, $dead_feed_items );
		$this->assertSame( 'This subscription\'s feed could not be reached or parsed.', $dead_feed_items[0]['last_error'] );
	}

	/** An active (non-flagged) subscription never appears as a `dead_feed` item. */
	public function test_active_subscription_does_not_appear_as_dead_feed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create( array( 'feed_url' => 'https://still-active.example.com/feed/' ) );
		$this->assertIsInt( $subscription_id );

		$notifications = new Daymark_Notifications();
		$items         = $notifications->get_notifications();

		$dead_feed_subscription_ids = array_column(
			array_filter(
				$items,
				static function ( array $item ) {
					return 'dead_feed' === ( $item['type'] ?? '' );
				}
			),
			'subscription_id'
		);

		$this->assertNotContains( $subscription_id, $dead_feed_subscription_ids );
	}

	/**
	 * A `dead_feed` item's text fields are sanitized on the way out — a
	 * malicious site_title/site_url stored on the row never reaches the
	 * response unescaped.
	 */
	public function test_dead_feed_item_fields_are_sanitized() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url'   => 'https://sanitize-example.com/',
				'feed_url'   => 'https://sanitize-example.com/feed/',
				'site_title' => '<script>alert(1)</script>Evil Site',
			)
		);
		$this->assertIsInt( $subscription_id );

		$this->assertTrue(
			$subscriptions->update(
				$subscription_id,
				array(
					'status'          => 'error',
					'last_checked_at' => '2026-01-01 00:00:00',
				)
			)
		);

		$notifications = new Daymark_Notifications();
		$items         = $notifications->get_notifications();

		$dead_feed_items = array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $subscription_id ) {
					return 'dead_feed' === ( $item['type'] ?? '' ) && $subscription_id === $item['subscription_id'];
				}
			)
		);

		$this->assertCount( 1, $dead_feed_items );
		$this->assertStringNotContainsString( '<script>', $dead_feed_items[0]['site_title'] );
	}

	/**
	 * Unread lifecycle: a newly flagged dead subscription counts as
	 * unread, the same as a new comment (has_unread()'s documented
	 * judgment call), and viewing notifications clears it exactly the
	 * same way.
	 */
	public function test_unread_reflects_newly_flagged_subscription() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$notifications = new Daymark_Notifications();
		$this->assertFalse( $notifications->has_unread(), 'No Marks, no flagged subscriptions: no unread' );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create( array( 'feed_url' => 'https://unread-example.com/feed/' ) );
		$this->assertIsInt( $subscription_id );

		$this->assertFalse( $notifications->has_unread(), 'A merely active subscription is not unread' );

		$subscriptions->update(
			$subscription_id,
			array(
				'status'          => 'error',
				'last_checked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->assertTrue( $notifications->has_unread(), 'A newly flagged dead subscription counts as unread' );

		$notifications->mark_seen();
		$this->assertFalse( $notifications->has_unread(), 'Viewing notifications marks the dead-feed alert seen too' );
	}

	/** Scenario 8: comments on non-Mark posts are excluded. */
	public function test_excludes_normal_post_comments() {
		// Notifications are scoped to posts the user can edit; an editor
		// sees all of them, so the Daymark-only exclusion is what's tested.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$normal_post = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$comment_id  = self::factory()->comment->create( array( 'comment_post_ID' => $normal_post ) );

		$daymark_post = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $daymark_post, '_daymark_is_mark', '1' );
		update_post_meta( $daymark_post, '_daymark_primary_type', 'note' );
		$daymark_comment = self::factory()->comment->create( array( 'comment_post_ID' => $daymark_post ) );

		$notifications = new Daymark_Notifications();
		$results       = $notifications->get_notifications();

		$returned_ids = array_column( $results, 'comment_ID' );
		$this->assertContains( (int) $daymark_comment, $returned_ids, 'Mark comment should appear' );
		$this->assertNotContains( (int) $comment_id, $returned_ids, 'Normal post comment must not appear' );
	}

	/** Scenario 7: mocked sync imports labeled comments and dedupes on repeat. */
	public function test_import_responses_labels_and_dedupes() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Backflow test note',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$notifications = new Daymark_Notifications();
		$result        = $notifications->import_responses( $post_id, array( 'bluesky' ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, $result['imported_count'] );
		$labels = array_column( $result['comments'], 'source_label' );
		$this->assertContains( 'Reply from Bluesky', $labels );

		// Repeat sync must not duplicate.
		$second = $notifications->import_responses( $post_id, array( 'bluesky' ) );
		$this->assertSame( 0, $second['imported_count'] );
	}

	/** The import-approval filter can route imported replies to moderation. */
	public function test_import_approved_filter_can_hold_replies() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Held import test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$approve_filter = function () {
			return 0;
		};
		add_filter( 'daymark_comment_import_approved', $approve_filter );

		$notifications = new Daymark_Notifications();
		$result        = $notifications->import_responses( $post_id, array( 'bluesky' ) );
		$this->assertGreaterThanOrEqual( 1, $result['imported_count'] );

		remove_filter( 'daymark_comment_import_approved', $approve_filter );

		$held = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'hold',
			)
		);
		$this->assertNotEmpty( $held, 'A filter returning 0 must hold imported replies in moderation' );
	}

	/** Unread flag lifecycle: set by new replies, cleared by viewing. */
	public function test_unread_state_lifecycle() {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner_id );

		$notifications = new Daymark_Notifications();
		$this->assertFalse( $notifications->has_unread(), 'No Marks, no unread' );

		$post_id = self::factory()->post->create( array( 'post_author' => $owner_id ) );
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$this->assertTrue( $notifications->has_unread(), 'A new reply means unread' );

		// Viewing the notifications endpoint clears the flag server-side.
		$request = new WP_REST_Request( 'GET', '/daymark/v1/notifications' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		rest_do_request( $request );

		$this->assertFalse( $notifications->has_unread(), 'Viewing marks everything seen' );

		// A newer reply flips it back.
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 ),
			)
		);
		$this->assertTrue( $notifications->has_unread(), 'A newer reply is unread again' );

		// Scoping: another author has no unread from this post.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( $notifications->has_unread(), "Other authors don't inherit unread state" );
	}

	/** Sync against a non-Mark post is a 404. */
	public function test_sync_non_daymark_post_is_404() {
		$normal_post   = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$notifications = new Daymark_Notifications();
		$result        = $notifications->import_responses( $normal_post, array( 'bluesky' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 404, $result->get_error_data()['status'] );
	}

	/** Scenario 9: deactivation preserves Mark posts. */
	public function test_post_survives_deactivation() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_title' => 'Portability test',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		// Simulate deactivation (flushes rewrites only; never deletes content).
		Daymark_Plugin::deactivate();

		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertEquals( 'Portability test', $post->post_title );
		$this->assertEquals( '1', get_post_meta( $post_id, '_daymark_is_mark', true ) );
	}
}
