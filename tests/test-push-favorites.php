<?php
/**
 * Tests for favorite sites: starring a subscription
 * (Daymark_Subscriptions::set_favorite(), POST/DELETE
 * /subscriptions/{id}/favorite), the hourly favorites poll, and the push
 * a favorite's new post sends (Daymark_Push::queue_favorite_post()).
 *
 * @package Daymark
 */

/**
 * Exercises favorites end to end.
 */
class Test_Push_Favorites extends WP_UnitTestCase {

	/** @var int */
	private $admin_id;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		$this->subscriptions = Daymark_Plugin::instance()->subscriptions;
		$this->admin_id      = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		_set_cron_array( array() );
		Daymark_Push::send_favorite_pushes(); // Start each test with an empty queue.
		_set_cron_array( array() );

		// Every outbound request fails, so a poll records a failed check
		// instead of reaching the network.
		add_filter( 'pre_http_request', array( $this, 'fail_requests' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fail_requests' ), 10 );
		remove_all_filters( 'daymark_subscription_poll_interval' );
		parent::tear_down();
	}

	public function fail_requests() {
		return new WP_Error( 'http_request_failed', 'offline' );
	}

	private function subscription( string $host = 'ana.example' ): int {
		return (int) $this->subscriptions->create(
			array(
				'site_url'   => 'https://' . $host,
				'feed_url'   => 'https://' . $host . '/feed/',
				'site_title' => 'Ana’s blog',
			)
		);
	}

	private function give_admin_a_device(): void {
		update_user_meta( $this->admin_id, Daymark_Push::SUBSCRIPTION_META, wp_json_encode( array( 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc' ), JSON_UNESCAPED_SLASHES ) );
	}

	private function ingest( int $subscription_id, string $published_at, string $title = 'Hello world' ): int {
		return Daymark_Plugin::instance()->subscription_poller->maybe_ingest_item(
			$subscription_id,
			array(
				'permalink'    => 'https://ana.example/' . wp_generate_password( 8, false ),
				'title'        => $title,
				'published_at' => $published_at,
			)
		);
	}

	private function scheduled_pushes(): array {
		$events = array();

		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Daymark_Push::CRON_HOOK ] ?? array() ) as $event ) {
				$events[] = $event['args'][1];
			}
		}

		return $events;
	}

	private function rest( string $method, int $id ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/daymark/v1/subscriptions/' . $id . '/favorite' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	public function test_star_and_unstar() {
		$id = $this->subscription();

		$this->assertTrue( $this->subscriptions->set_favorite( $id, true ) );
		$starred = $this->subscriptions->get( $id )['favorited_at'];
		$this->assertNotEmpty( $starred );
		$this->assertSame( array( $id ), array_map( 'intval', wp_list_pluck( $this->subscriptions->get_favorites(), 'id' ) ) );

		// Starring again keeps the original time.
		$this->subscriptions->set_favorite( $id, true );
		$this->assertSame( $starred, $this->subscriptions->get( $id )['favorited_at'] );

		$this->assertTrue( $this->subscriptions->set_favorite( $id, false ) );
		$this->assertNull( $this->subscriptions->get( $id )['favorited_at'] );
		$this->assertSame( array(), $this->subscriptions->get_favorites() );
		$this->assertFalse( $this->subscriptions->set_favorite( 999999, true ) );
	}

	public function test_rest_stars_a_site_for_managers_only() {
		$id = $this->subscription();

		$on = $this->rest( 'POST', $id );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( $on->get_data()['favorite'] );

		$off = $this->rest( 'DELETE', $id );
		$this->assertFalse( $off->get_data()['favorite'] );

		$this->assertSame( 404, $this->rest( 'POST', 999999 )->get_status() );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', $id )->get_status() );
	}

	public function test_new_post_from_a_favorite_pushes_with_a_link_to_it() {
		$this->give_admin_a_device();
		$id = $this->subscription();
		$this->subscriptions->set_favorite( $id, true );

		$post_id = $this->ingest( $id, gmdate( 'Y-m-d H:i:s', time() + 60 ), 'Spring at the lake' );
		Daymark_Push::send_favorite_pushes();

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 1, $pushes );
		$this->assertSame( 'Ana’s blog', $pushes[0]['title'] );
		$this->assertSame( 'Spring at the lake', $pushes[0]['body'] );
		$this->assertSame( add_query_arg( 'daymark_post', $post_id, Daymark_Routes::app_url() ), $pushes[0]['url'] );
		$this->assertSame( 'favorite-' . $id, $pushes[0]['tag'] );
	}

	public function test_several_new_posts_become_one_push() {
		$this->give_admin_a_device();
		$id = $this->subscription();
		$this->subscriptions->set_favorite( $id, true );

		$now = gmdate( 'Y-m-d H:i:s', time() + 60 );
		$this->ingest( $id, $now );
		$this->ingest( $id, $now );
		$this->ingest( $id, $now );
		Daymark_Push::send_favorite_pushes();

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 1, $pushes );
		$this->assertSame( '3 new posts', $pushes[0]['body'] );
		$this->assertSame( Daymark_Routes::app_url(), $pushes[0]['url'] );
	}

	public function test_old_posts_and_other_sites_dont_push() {
		$this->give_admin_a_device();
		$favorite = $this->subscription();
		$other    = $this->subscription( 'bo.example' );
		$this->subscriptions->set_favorite( $favorite, true );

		// Published before the site was starred.
		$this->ingest( $favorite, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		// Not a favorite.
		$this->ingest( $other, gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		Daymark_Push::send_favorite_pushes();

		$this->assertCount( 0, $this->scheduled_pushes() );
	}

	public function test_a_post_older_than_the_limit_doesnt_push() {
		$this->give_admin_a_device();
		$id = $this->subscription();
		$this->subscriptions->set_favorite( $id, true );

		// Pretend the site was starred long ago, so only the age limit applies.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup on the custom table.
		$wpdb->update( Daymark_Subscriptions::table_name(), array( 'favorited_at' => '2020-01-01 00:00:00' ), array( 'id' => $id ) );

		$this->assertFalse( Daymark_Push::queue_favorite_post( $this->ingest( $id, gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ), $id ) );
		Daymark_Push::send_favorite_pushes();

		$this->assertCount( 0, $this->scheduled_pushes() );
	}

	public function test_no_push_when_favorites_are_turned_off() {
		$this->give_admin_a_device();
		Daymark_Push::update_prefs( $this->admin_id, array( 'favorites' => false ) );
		$id = $this->subscription();
		$this->subscriptions->set_favorite( $id, true );

		$this->ingest( $id, gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		Daymark_Push::send_favorite_pushes();

		$this->assertCount( 0, $this->scheduled_pushes() );
	}

	public function test_hourly_poll_checks_favorites_that_are_due() {
		$due    = $this->subscription();
		$recent = $this->subscription( 'bo.example' );
		$plain  = $this->subscription( 'cy.example' );
		$this->subscriptions->set_favorite( $due, true );
		$this->subscriptions->set_favorite( $recent, true );
		$this->subscriptions->update( $recent, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );

		Daymark_Plugin::instance()->subscription_poller->run_favorites_poll();

		// A poll ran (and failed, offline) only for the favorite that was due.
		$this->assertSame( 1, (int) $this->subscriptions->get( $due )['consecutive_failure_count'] );
		$this->assertSame( 0, (int) $this->subscriptions->get( $recent )['consecutive_failure_count'] );
		$this->assertSame( 0, (int) $this->subscriptions->get( $plain )['consecutive_failure_count'] );
	}

	public function test_hourly_poll_does_nothing_when_every_site_is_hourly() {
		$id = $this->subscription();
		$this->subscriptions->set_favorite( $id, true );
		add_filter(
			'daymark_subscription_poll_interval',
			static function () {
				return HOUR_IN_SECONDS;
			}
		);

		Daymark_Plugin::instance()->subscription_poller->run_favorites_poll();

		$this->assertSame( 0, (int) $this->subscriptions->get( $id )['consecutive_failure_count'] );
	}

	public function test_app_config_carries_the_post_to_open() {
		$this->assertSame( 42, Daymark_Routes::build_app_config( 'home', '', 0, 42 )['pendingPostId'] );
		$this->assertSame( 0, Daymark_Routes::build_app_config()['pendingPostId'] );
		$this->assertArrayHasKey( 'favorites', Daymark_Routes::build_app_config()['push']['prefs'] );
	}

	public function test_hourly_poll_is_scheduled() {
		Daymark_Subscription_Poller::schedule();

		$this->assertNotFalse( wp_next_scheduled( Daymark_Subscription_Poller::FAVORITES_CRON_HOOK ) );
	}
}
