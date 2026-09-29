<?php
/**
 * Tests for Daymark_ActivityPub_Engagement (issue #439): real ActivityPub
 * Like/Announce/Undo through the ActivityPub plugin's outbox, the Webmention
 * suppression that keeps an origin from receiving two Likes, the actor gate,
 * and the route counting toward Like availability.
 *
 * Runs against tests/activitypub-stub/ (see its load.php): the real plugin
 * isn't a test dependency. The stub is inert until a test enables a user.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_ActivityPub_Engagement directly and through the REST routes.
 */
class Test_ActivityPub_Engagement extends WP_UnitTestCase {

	/** @var int */
	private $author;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var string */
	private $permalink = 'https://mastodon.example/@ada/123';

	/** @var string */
	private $object_id = 'https://mastodon.example/users/ada/statuses/123';

	/** @var string */
	private $actor_id = 'https://mastodon.example/users/ada';

	public function set_up(): void {
		parent::set_up();

		if ( ! Daymark_Test_ActivityPub_Stub::is_loaded() ) {
			$this->markTestSkipped( 'A real ActivityPub plugin is loaded; these tests need the stub.' );
		}

		Daymark_Test_ActivityPub_Stub::reset();
		Daymark_Subscriptions::install();
		add_filter( 'daymark_subscription_url_guard_skip_dns_resolution', '__return_true' );

		$this->author        = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriptions = new Daymark_Subscriptions();
	}

	public function tear_down(): void {
		remove_filter( 'daymark_subscription_url_guard_skip_dns_resolution', '__return_true' );
		Daymark_Test_ActivityPub_Stub::reset();

		parent::tear_down();
	}

	/**
	 * Enable the author as an ActivityPub user and make the permalink resolve.
	 *
	 * @return void
	 */
	private function enable_route(): void {
		Daymark_Test_ActivityPub_Stub::$enabled_users[] = $this->author;

		Daymark_Test_ActivityPub_Stub::$remote_objects[ $this->permalink ] = array(
			'id'           => $this->object_id,
			'type'         => 'Note',
			'attributedTo' => $this->actor_id,
		);
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * @return int A `daymark_subscription_post` at $this->permalink.
	 */
	private function create_subscription_post(): int {
		$subscription_id = $this->subscriptions->create(
			array(
				'site_url' => 'https://mastodon.example/',
				'feed_url' => 'https://mastodon.example/@ada.rss' . wp_generate_password( 6, false ),
			)
		);

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'A toot',
			)
		);
		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', $this->permalink );

		return $post_id;
	}

	/**
	 * @param string $type Activity type.
	 * @return array<int, array<string, mixed>> Queued calls of that type.
	 */
	private function queued( string $type ): array {
		return array_values(
			array_filter(
				Daymark_Test_ActivityPub_Stub::$queued,
				static function ( $call ) use ( $type ) {
					return $type === $call['activity']['type'];
				}
			)
		);
	}

	// -----------------------------------------------------------------
	// Gate.
	// -----------------------------------------------------------------

	public function test_plugin_available_with_a_supported_version() {
		$this->assertTrue( Daymark_ActivityPub_Engagement::plugin_available() );
	}

	public function test_route_unavailable_for_a_user_who_is_not_an_activitypub_author() {
		wp_set_current_user( $this->author );

		$this->assertFalse( Daymark_ActivityPub_Engagement::available_for_user() );
		$this->assertSame( 0, Daymark_ActivityPub_Engagement::like( $this->author, $this->permalink ) );
		$this->assertEmpty( Daymark_Test_ActivityPub_Stub::$queued, 'Never falls back to the blog actor.' );
	}

	public function test_route_unavailable_for_no_current_user() {
		wp_set_current_user( 0 );

		$this->assertFalse( Daymark_ActivityPub_Engagement::available_for_user() );
	}

	// -----------------------------------------------------------------
	// Resolution.
	// -----------------------------------------------------------------

	public function test_resolve_target_reads_id_and_author() {
		$this->enable_route();

		$this->assertSame(
			array(
				'id'            => $this->object_id,
				'attributed_to' => $this->actor_id,
			),
			Daymark_ActivityPub_Engagement::resolve_target( $this->permalink )
		);
	}

	public function test_resolve_target_is_cached_including_negative_results() {
		$this->enable_route();

		Daymark_ActivityPub_Engagement::resolve_target( $this->permalink );
		Daymark_ActivityPub_Engagement::resolve_target( $this->permalink );
		$this->assertCount( 1, Daymark_Test_ActivityPub_Stub::$fetches );

		$plain = 'https://blog.example/plain-post/';
		$this->assertNull( Daymark_ActivityPub_Engagement::resolve_target( $plain ) );
		$this->assertFalse( Daymark_ActivityPub_Engagement::cached_target( $plain ) );
		Daymark_ActivityPub_Engagement::resolve_target( $plain );
		$this->assertCount( 2, Daymark_Test_ActivityPub_Stub::$fetches, 'A cached miss is not fetched again.' );
	}

	public function test_resolve_target_reads_attributed_to_from_a_list() {
		Daymark_Test_ActivityPub_Stub::$remote_objects[ $this->permalink ] = array(
			'id'           => $this->object_id,
			'type'         => 'Article',
			'attributedTo' => array(
				array(
					'type' => 'Link',
					'href' => 'https://elsewhere.example/',
				),
				array(
					'type' => 'Person',
					'id'   => $this->actor_id,
				),
			),
		);

		$this->assertSame( $this->actor_id, Daymark_ActivityPub_Engagement::resolve_target( $this->permalink )['attributed_to'] );
	}

	public function test_resolve_target_rejects_an_actor_or_an_object_without_an_author() {
		Daymark_Test_ActivityPub_Stub::$remote_objects['https://mastodon.example/@ada']   = array(
			'id'   => $this->actor_id,
			'type' => 'Person',
		);
		Daymark_Test_ActivityPub_Stub::$remote_objects['https://mastodon.example/@ada/9'] = array(
			'id'   => 'https://mastodon.example/users/ada/statuses/9',
			'type' => 'Note',
		);

		$this->assertNull( Daymark_ActivityPub_Engagement::resolve_target( 'https://mastodon.example/@ada' ) );
		$this->assertNull( Daymark_ActivityPub_Engagement::resolve_target( 'https://mastodon.example/@ada/9' ) );
	}

	public function test_resolve_target_runs_the_url_guard_before_fetching() {
		$this->assertNull( Daymark_ActivityPub_Engagement::resolve_target( 'http://127.0.0.1/post' ) );
		$this->assertEmpty( Daymark_Test_ActivityPub_Stub::$fetches );
	}

	// -----------------------------------------------------------------
	// Like / Undo.
	// -----------------------------------------------------------------

	public function test_like_queues_a_private_like_addressed_to_the_author() {
		$this->enable_route();

		$outbox_id = Daymark_ActivityPub_Engagement::like( $this->author, $this->permalink );
		$likes     = $this->queued( 'Like' );

		$this->assertGreaterThan( 0, $outbox_id );
		$this->assertCount( 1, $likes );
		$this->assertSame( $this->object_id, $likes[0]['activity']['object'] );
		$this->assertSame( array( $this->actor_id ), $likes[0]['activity']['to'] );
		$this->assertArrayNotHasKey( 'cc', $likes[0]['activity'] );
		$this->assertSame( 'private', $likes[0]['visibility'] );
		$this->assertSame( $this->author, $likes[0]['user_id'] );
	}

	public function test_like_route_queues_activitypub_like_and_suppresses_webmention() {
		wp_set_current_user( $this->author );
		$this->enable_route();
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'activitypub', $data['method'] );
		$this->assertSame( 'pending', $data['delivery'] );
		$this->assertCount( 1, $this->queued( 'Like' ) );

		$mark_id = (int) $data['mark_id'];
		$this->assertSame( $this->permalink, get_post_meta( $mark_id, '_daymark_like_of', true ) );
		$this->assertSame( Daymark_Test_ActivityPub_Stub::$queued[0]['outbox_id'], absint( get_post_meta( $mark_id, Daymark_ActivityPub_Engagement::OUTBOX_META, true ) ) );
		$this->assertSame( '', (string) get_post_meta( $mark_id, '_mentionme', true ), 'The Webmention plugin is told not to send.' );

		$targets = ( new Daymark_Microformats() )->add_webmention_targets( array( $this->permalink ), $mark_id );
		$this->assertNotContains( $this->permalink, $targets );
	}

	public function test_like_route_without_any_other_route_is_refused_when_activitypub_queue_fails() {
		wp_set_current_user( $this->author );
		$this->enable_route();
		Daymark_Test_ActivityPub_Stub::$fail_queue = true;
		$post_id                                   = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'daymark_like_undeliverable', $response->as_error()->get_error_code() );
	}

	public function test_like_route_is_refused_for_a_user_who_is_not_an_activitypub_author() {
		wp_set_current_user( $this->author );
		Daymark_Test_ActivityPub_Stub::$remote_objects[ $this->permalink ] = array(
			'id'           => $this->object_id,
			'type'         => 'Note',
			'attributedTo' => $this->actor_id,
		);
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertEmpty( Daymark_Test_ActivityPub_Stub::$queued );
	}

	public function test_unlike_queues_an_undo_of_the_like() {
		wp_set_current_user( $this->author );
		$this->enable_route();
		$post_id = $this->create_subscription_post();

		rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		$like_outbox = Daymark_Test_ActivityPub_Stub::$queued[0]['outbox_id'];

		$response = rest_do_request( $this->request( 'DELETE', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertFalse( $response->get_data()['liked'] );
		$this->assertCount( 1, Daymark_Test_ActivityPub_Stub::$undone );
		$this->assertSame( $like_outbox, Daymark_Test_ActivityPub_Stub::$undone[0]['outbox_id'] );
	}

	public function test_undo_runs_once_across_trash_then_delete() {
		wp_set_current_user( $this->author );
		$this->enable_route();
		$mark_id = (int) self::factory()->post->create( array( 'post_author' => $this->author ) );
		Daymark_ActivityPub_Engagement::attach_to_mark( $mark_id, Daymark_ActivityPub_Engagement::like( $this->author, $this->permalink ), 'Like' );

		wp_trash_post( $mark_id );
		wp_delete_post( $mark_id, true );

		$this->assertCount( 1, Daymark_Test_ActivityPub_Stub::$undone );
	}

	// -----------------------------------------------------------------
	// Reblog → Announce.
	// -----------------------------------------------------------------

	public function test_reblog_queues_an_unlisted_announce_and_suppresses_only_the_repost_webmention() {
		wp_set_current_user( $this->author );
		$this->enable_route();

		$mark_id = Daymark_Plugin::instance()->publisher->publish(
			array(
				'caption'        => 'Worth reading.',
				'primary_type'   => 'note',
				'status'         => 'publish',
				'ai_assist_used' => false,
				'repost_of'      => $this->permalink,
				'quote_title'    => 'A toot',
			)
		);

		$this->assertIsInt( $mark_id );

		$announces = $this->queued( 'Announce' );
		$this->assertCount( 1, $announces, 'Queued exactly once despite both publish hooks firing.' );
		$this->assertSame( $this->object_id, $announces[0]['activity']['object'] );
		$this->assertSame( array( $this->actor_id ), $announces[0]['activity']['to'] );
		$this->assertSame( array( Daymark_ActivityPub_Engagement::PUBLIC_COLLECTION ), $announces[0]['activity']['cc'] );
		$this->assertSame( 'private', $announces[0]['visibility'] );

		$other   = 'https://elsewhere.example/linked/';
		$targets = ( new Daymark_Microformats() )->add_webmention_targets( array( $this->permalink . '/', $other ), $mark_id );
		$this->assertSame( array( $other ), $targets );

		wp_trash_post( $mark_id );
		$this->assertCount( 1, Daymark_Test_ActivityPub_Stub::$undone );
		$this->assertSame( $announces[0]['outbox_id'], Daymark_Test_ActivityPub_Stub::$undone[0]['outbox_id'] );
	}

	public function test_reblog_without_the_route_queues_nothing_and_keeps_its_webmention() {
		wp_set_current_user( $this->author );

		$mark_id = Daymark_Plugin::instance()->publisher->publish(
			array(
				'caption'        => 'Worth reading.',
				'primary_type'   => 'note',
				'status'         => 'publish',
				'ai_assist_used' => false,
				'repost_of'      => $this->permalink,
			)
		);

		$this->assertEmpty( Daymark_Test_ActivityPub_Stub::$queued );
		$this->assertContains( $this->permalink, ( new Daymark_Microformats() )->add_webmention_targets( array(), $mark_id ) );
	}

	// -----------------------------------------------------------------
	// Availability and delivery state.
	// -----------------------------------------------------------------

	public function test_availability_counts_the_activitypub_route() {
		wp_set_current_user( $this->author );
		$this->enable_route();
		$post_id = $this->create_subscription_post();

		$this->assertTrue( Daymark_Like_Delivery::mechanisms_exist() );
		$this->assertNull( Daymark_Like_Delivery::cached_availability( $this->permalink ), 'Not looked up yet.' );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/subscription-posts/' . $post_id . '/like-availability' ) );
		$this->assertTrue( $response->get_data()['available'] );
		$this->assertSame( 'activitypub', $response->get_data()['method'] );

		$this->assertTrue( Daymark_Like_Delivery::cached_availability( $this->permalink ) );
	}

	public function test_availability_is_false_for_a_cached_non_fediverse_origin() {
		wp_set_current_user( $this->author );
		Daymark_Test_ActivityPub_Stub::$enabled_users[] = $this->author;
		$post_id                                        = $this->create_subscription_post();

		$this->assertFalse( Daymark_Like_Delivery::resolve( $post_id )['available'] );
		$this->assertFalse( Daymark_Like_Delivery::cached_availability( $this->permalink ) );
	}

	public function test_delivery_state_follows_the_inbox_result() {
		$this->enable_route();
		$mark_id   = (int) self::factory()->post->create();
		$outbox_id = Daymark_ActivityPub_Engagement::like( $this->author, $this->permalink );
		Daymark_ActivityPub_Engagement::attach_to_mark( $mark_id, $outbox_id, 'Like' );

		$this->assertSame( 'pending', Daymark_Like_Delivery::like_state( false, $mark_id, $this->permalink ) );

		Daymark_ActivityPub_Engagement::record_inbox_result( new WP_Error( 'http_request_failed', 'nope' ), 'https://mastodon.example/inbox', '{}', $this->author, $outbox_id );
		$this->assertSame( 'failed', Daymark_Like_Delivery::like_state( false, $mark_id, $this->permalink ) );

		do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://mastodon.example/inbox', '{}', $this->author, $outbox_id );
		$this->assertSame( 'sent', Daymark_Like_Delivery::like_state( false, $mark_id, $this->permalink ) );

		Daymark_ActivityPub_Engagement::record_inbox_result( new WP_Error( 'http_request_failed', 'nope' ), 'https://mastodon.example/inbox', '{}', $this->author, $outbox_id );
		$this->assertSame( 'sent', Daymark_Like_Delivery::like_state( false, $mark_id, $this->permalink ), 'A later failure never undoes a delivered Like.' );
	}

	public function test_mark_without_activitypub_keeps_webmention_state() {
		$mark_id = (int) self::factory()->post->create();
		update_post_meta( $mark_id, '_mentionme', '1' );

		$this->assertSame( '', Daymark_ActivityPub_Engagement::delivery_state( $mark_id ) );
		$this->assertSame( 'pending', Daymark_Like_Delivery::like_state( false, $mark_id, $this->permalink ) );
	}
}
