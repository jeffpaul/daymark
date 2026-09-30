<?php
/**
 * Daymark_Websub_Subscriber tests (issue #82): sending a WebSub subscribe
 * request, not re-sending one already pending/fresh, marking a subscription
 * failed on a non-2xx hub response, and renewing a verified subscription
 * near lease expiry.
 *
 * HTTP to the hub is mocked via `pre_http_request`, matching the existing
 * pattern in tests/test-subscription-poller.php.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Websub_Subscriber.
 */
class Test_Websub_Subscriber extends WP_UnitTestCase {

	/** @var Daymark_Websub_Subscriber */
	private $subscriber;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/**
	 * Captured requests made to the mocked hub, in order — each a
	 * {url, body} pair — so a test can assert on the exact params sent.
	 *
	 * @var array<int, array{url: string, body: array<string, mixed>}>
	 */
	private array $sent_requests = array();

	/**
	 * HTTP status code intercept_http_request() returns for the next
	 * request(s); a test sets this before calling the method under test.
	 *
	 * @var int
	 */
	private int $mock_status = 202;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriber    = new Daymark_Websub_Subscriber();
		$this->subscriptions = new Daymark_Subscriptions();
		$this->sent_requests = array();
		$this->mock_status   = 202;

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args.
	 * @param string $url         Requested URL.
	 * @return array
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		$this->sent_requests[] = array(
			'url'  => $url,
			'body' => is_array( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : array(),
		);

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $this->mock_status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @return int Subscription ID.
	 */
	private function create_subscription(): int {
		return (int) $this->subscriptions->create(
			array(
				'site_url'    => 'https://example.com/',
				'feed_url'    => 'https://example.com/feed/',
				'source_type' => 'feed',
			)
		);
	}

	public function test_sends_subscribe_request_and_marks_pending() {
		$id = $this->create_subscription();

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertCount( 1, $this->sent_requests );
		$this->assertSame( 'https://hub.example.com/', $this->sent_requests[0]['url'] );
		$this->assertSame( 'subscribe', $this->sent_requests[0]['body']['hub.mode'] );
		$this->assertSame( 'https://example.com/feed/', $this->sent_requests[0]['body']['hub.topic'] );
		// Decoded, since plain permalinks carry the route in an encoded rest_route query argument.
		$this->assertStringContainsString( '/daymark/v1/websub/' . $id, rawurldecode( $this->sent_requests[0]['body']['hub.callback'] ) );
		$this->assertNotEmpty( $this->sent_requests[0]['body']['hub.secret'] );

		// The callback URL carries a token that checks out against the
		// secret Daymark stored (and never contains the secret itself).
		$callback = $this->sent_requests[0]['body']['hub.callback'];
		$secret   = $this->sent_requests[0]['body']['hub.secret'];
		parse_str( (string) wp_parse_url( $callback, PHP_URL_QUERY ), $query );

		$this->assertSame( Daymark_Websub_Subscriber::callback_token( $id, $secret ), $query['daymark_token'] ?? '' );
		$this->assertSame( $secret, $this->subscriptions->get( $id )['websub_secret'] );
		$this->assertStringNotContainsString( $secret, $callback );

		$subscription = $this->subscriptions->get( $id );
		$this->assertSame( 'pending', $subscription['websub_status'] );
		$this->assertSame( 'https://hub.example.com/', $subscription['websub_hub_url'] );
		$this->assertNotSame( '', $subscription['websub_secret'] );
	}

	/**
	 * A hub may verify while it is still answering the subscribe request, and
	 * the endpoint can only check the token against a secret that is already
	 * stored, so the secret and the pending state exist before the hub is
	 * contacted, not only after.
	 */
	public function test_secret_and_pending_state_are_stored_before_the_hub_is_contacted() {
		$id       = $this->create_subscription();
		$observed = array();

		$probe = function ( $preempt, $args, $url ) use ( $id, &$observed ) {
			unset( $args, $url );
			$row      = $this->subscriptions->get( $id );
			$observed = array(
				'status' => $row['websub_status'],
				'secret' => $row['websub_secret'],
			);

			return $preempt;
		};
		add_filter( 'pre_http_request', $probe, 5, 3 );

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		remove_filter( 'pre_http_request', $probe, 5 );

		$this->assertSame( 'pending', $observed['status'] ?? null );
		$this->assertNotEmpty( $observed['secret'] ?? '' );
	}

	/** Each subscribe (or renewal) gets a new secret, so its callback token changes too. */
	public function test_the_callback_token_changes_with_the_secret() {
		$this->assertNotSame(
			Daymark_Websub_Subscriber::callback_token( 7, 'secret-one' ),
			Daymark_Websub_Subscriber::callback_token( 7, 'secret-two' )
		);
		$this->assertNotSame(
			Daymark_Websub_Subscriber::callback_token( 7, 'secret-one' ),
			Daymark_Websub_Subscriber::callback_token( 8, 'secret-one' )
		);
		$this->assertSame( 32, strlen( Daymark_Websub_Subscriber::callback_token( 7, 'secret-one' ) ) );
	}

	public function test_noop_without_a_hub_url() {
		$id = $this->create_subscription();

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', '' );

		$this->assertCount( 0, $this->sent_requests );
		$this->assertSame( 'none', $this->subscriptions->get( $id )['websub_status'] );
	}

	public function test_noop_when_already_pending() {
		$id = $this->create_subscription();
		$this->subscriptions->update( $id, array( 'websub_status' => 'pending' ) );
		// Recently requested: the hub may still verify it.
		set_transient( 'daymark_websub_pending_' . $id, 1, HOUR_IN_SECONDS );

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertCount( 0, $this->sent_requests );
	}

	/**
	 * Put a subscription in the state a real subscribe request leaves it in,
	 * without contacting the hub.
	 *
	 * @param bool $fresh Whether its "recent request" marker is still set.
	 * @return int
	 */
	private function create_pending_subscription( bool $fresh = false ): int {
		$id = $this->create_subscription();
		$this->subscriptions->update(
			$id,
			array(
				'websub_status'  => 'pending',
				'websub_hub_url' => 'https://hub.example.com/',
				'websub_secret'  => 'old-secret',
			)
		);

		if ( $fresh ) {
			set_transient( 'daymark_websub_pending_' . $id, 1, HOUR_IN_SECONDS );
		}

		return $id;
	}

	/** An accepted subscribe request leaves a marker, counts the attempt, and schedules one check just past the window. */
	public function test_accepted_request_schedules_a_verification_check() {
		$id = $this->create_subscription();

		$before = time();
		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertNotFalse( get_transient( 'daymark_websub_pending_' . $id ), 'The recent-request marker is set' );
		$this->assertSame( 1, (int) get_transient( 'daymark_websub_attempts_' . $id ) );

		$next = wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) );

		$this->assertNotFalse( $next, 'A check is scheduled for this subscription' );
		$this->assertGreaterThanOrEqual( $before + 10 * MINUTE_IN_SECONDS, $next, 'It fires after the verification window, not before' );
		$this->assertLessThan( $before + 15 * MINUTE_IN_SECONDS, $next, 'And soon after it, not at the next poll' );
	}

	/** A hub that rejects the request schedules nothing: the row is `failed` and the next poll starts over. */
	public function test_rejected_request_schedules_no_check() {
		$id                = $this->create_subscription();
		$this->mock_status = 500;

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertFalse( wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ) );
		$this->assertFalse( get_transient( 'daymark_websub_pending_' . $id ) );
	}

	/** The verification window is filterable. */
	public function test_verification_window_is_filterable() {
		$id     = $this->create_subscription();
		$filter = static function () {
			return 30 * MINUTE_IN_SECONDS;
		};
		add_filter( 'daymark_websub_verification_timeout', $filter );

		$before = time();
		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		remove_filter( 'daymark_websub_verification_timeout', $filter );

		$this->assertGreaterThanOrEqual( $before + 30 * MINUTE_IN_SECONDS, wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ) );
	}

	/** Verified while the request was still being answered: nothing to wait for, so nothing is scheduled. */
	public function test_no_check_is_scheduled_if_the_hub_already_verified() {
		$id = $this->create_subscription();

		// The hub's verification arrives during the subscribe request.
		$verify = function ( $preempt ) use ( $id ) {
			$this->subscriptions->update( $id, array( 'websub_status' => 'verified' ) );

			return $preempt;
		};
		add_filter( 'pre_http_request', $verify, 5 );

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		remove_filter( 'pre_http_request', $verify, 5 );

		$this->assertSame( 'verified', $this->subscriptions->get( $id )['websub_status'] );
		$this->assertFalse( wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ) );
		$this->assertFalse( get_transient( 'daymark_websub_pending_' . $id ) );
	}

	/** Still pending once the window has passed: the request is sent again, with a fresh secret. */
	public function test_check_retries_a_subscription_that_is_still_pending() {
		$id = $this->create_pending_subscription();
		set_transient( 'daymark_websub_attempts_' . $id, 1, DAY_IN_SECONDS );

		$this->subscriber->check_pending( $id );

		$this->assertCount( 1, $this->sent_requests, 'The subscribe request went out again' );
		$this->assertSame( 'https://hub.example.com/', $this->sent_requests[0]['url'] );
		$this->assertNotSame( 'old-secret', $this->subscriptions->get( $id )['websub_secret'], 'Each retry has a fresh secret' );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'] );
		$this->assertSame( 2, (int) get_transient( 'daymark_websub_attempts_' . $id ) );
		$this->assertNotFalse( wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ), 'And it is checked again' );
	}

	/** The retry chain is bounded: after the limit the row is marked failed and nothing more is sent. */
	public function test_check_gives_up_after_the_attempt_limit() {
		$id = $this->create_pending_subscription();
		set_transient( 'daymark_websub_attempts_' . $id, 3, DAY_IN_SECONDS );

		$this->subscriber->check_pending( $id );

		$this->assertCount( 0, $this->sent_requests );
		$this->assertSame( 'failed', $this->subscriptions->get( $id )['websub_status'] );
		$this->assertFalse( wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ), 'No further check' );
	}

	/** The whole chain, run end to end: three requests, then failed. */
	public function test_a_hub_that_never_verifies_gets_three_requests_then_failed() {
		$id = $this->create_subscription();

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		for ( $window = 0; $window < 5; $window++ ) {
			// Time passes: the "recent" marker has expired by the time the check fires.
			delete_transient( 'daymark_websub_pending_' . $id );
			$this->subscriber->check_pending( $id );
		}

		$this->assertCount( 3, $this->sent_requests, 'One request plus two retries' );
		$this->assertSame( 'failed', $this->subscriptions->get( $id )['websub_status'] );
	}

	/** A request still inside its own window is left alone. */
	public function test_check_leaves_a_recent_request_alone() {
		$id = $this->create_pending_subscription( true );

		$this->subscriber->check_pending( $id );

		$this->assertCount( 0, $this->sent_requests );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'] );
	}

	/** Verified, unsubscribed, or never subscribed: the check does nothing. */
	public function test_check_ignores_anything_that_is_not_pending() {
		$verified = $this->create_subscription();
		$this->subscriptions->update( $verified, array( 'websub_status' => 'verified' ) );

		$this->subscriber->check_pending( $verified );
		$this->subscriber->check_pending( 999999 );

		$this->assertCount( 0, $this->sent_requests );
		$this->assertSame( 'verified', $this->subscriptions->get( $verified )['websub_status'] );
	}

	/** A pending row with no marker is stale (or predates this check): the next poll subscribes it again. */
	public function test_a_stale_pending_row_is_resubscribed_on_the_next_poll() {
		$id = $this->create_pending_subscription( false );

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertCount( 1, $this->sent_requests );
	}

	/** Once verified, the marker, the attempt count, and the scheduled check are all cleared. */
	public function test_clear_pending_state_removes_everything() {
		$id = $this->create_subscription();
		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		Daymark_Websub_Subscriber::clear_pending_state( $id );

		$this->assertFalse( get_transient( 'daymark_websub_pending_' . $id ) );
		$this->assertFalse( get_transient( 'daymark_websub_attempts_' . $id ) );
		$this->assertFalse( wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ) );
	}

	/** The cron hook is actually wired to the check. */
	public function test_the_cron_hook_is_registered() {
		$this->assertNotFalse( has_action( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( Daymark_Plugin::instance()->websub_subscriber, 'check_pending' ) ) );
	}

	public function test_marks_failed_on_non_2xx_hub_response() {
		$id                = $this->create_subscription();
		$this->mock_status = 500;

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertSame( 'failed', $this->subscriptions->get( $id )['websub_status'] );
	}

	public function test_renews_a_verified_subscription_near_lease_expiry() {
		$id = $this->create_subscription();
		$this->subscriptions->update(
			$id,
			array(
				'websub_hub_url'          => 'https://hub.example.com/',
				'websub_status'           => 'verified',
				'websub_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertCount( 1, $this->sent_requests );
	}

	public function test_does_not_renew_a_verified_subscription_far_from_expiry() {
		$id = $this->create_subscription();
		$this->subscriptions->update(
			$id,
			array(
				'websub_hub_url'          => 'https://hub.example.com/',
				'websub_status'           => 'verified',
				'websub_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertCount( 0, $this->sent_requests );
	}
}
