<?php
/**
 * Daymark_Websub_Endpoint tests (issue #82): the hub verification challenge
 * (GET) and signed content delivery (POST) — both necessarily unauthenticated,
 * so what actually gates them (subscription/topic/status match for GET, an
 * HMAC signature for POST) is what these tests exercise.
 *
 * Tests dispatch through rest_do_request(), which returns the WP_REST_Response
 * directly without going through WP_REST_Server::serve_request()'s raw-output
 * stage — so, like Daymark_REST_Controller::maybe_serve_opml_export()'s own
 * tests, these assert on handle_verification()'s returned response (status,
 * data, marker header) rather than on serve_raw_challenge()'s echoed bytes.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Websub_Endpoint.
 */
class Test_Websub_Endpoint extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriptions = new Daymark_Subscriptions();
	}

	/**
	 * @param array<string, mixed> $overrides Fields to set beyond the defaults.
	 * @return int Subscription ID.
	 */
	private function create_subscription( array $overrides = array() ): int {
		$id = (int) $this->subscriptions->create(
			array(
				'site_url'    => 'https://example.com/',
				'feed_url'    => 'https://example.com/feed/',
				'source_type' => 'feed',
			)
		);

		if ( ! empty( $overrides ) ) {
			$this->subscriptions->update( $id, $overrides );
		}

		return $id;
	}

	/** A valid RSS 2.0 feed with a single item. */
	private function rss_with_one_item(): string {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
<title>Example Feed</title>
<link>https://example.com/</link>
<item>
<title>A Post</title>
<link>https://example.com/a-post/</link>
<guid>https://example.com/a-post/</guid>
<pubDate>Tue, 02 Jan 2024 03:04:05 +0000</pubDate>
<description>Some content.</description>
</item>
</channel>
</rss>
XML;
	}

	/**
	 * Build the verification GET a hub sends, carrying (by default) the
	 * token Daymark put in the callback URL for this secret.
	 *
	 * @param int         $id     Subscription ID.
	 * @param string|null $token  Token to send; null derives the correct one from $secret.
	 * @param string      $secret Secret the token is derived from.
	 * @param string      $topic  hub.topic value.
	 * @return WP_REST_Request
	 */
	private function verification_request( int $id, ?string $token = null, string $secret = 'a-stored-secret', string $topic = 'https://example.com/feed/' ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/websub/' . $id );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_topic', $topic );
		$request->set_param( 'hub_challenge', 'a-random-challenge' );
		$request->set_param( 'hub_lease_seconds', '86400' );

		$send = null === $token ? Daymark_Websub_Subscriber::callback_token( $id, $secret ) : $token;

		if ( '' !== $send ) {
			$request->set_param( 'daymark_token', $send );
		}

		return $request;
	}

	/**
	 * Create a pending subscription that has a stored secret, as a real
	 * subscribe request leaves it.
	 *
	 * @return int
	 */
	private function create_pending_subscription(): int {
		return $this->create_subscription(
			array(
				'websub_status' => 'pending',
				'websub_secret' => 'a-stored-secret',
			)
		);
	}

	public function test_get_challenge_echoed_for_a_genuinely_pending_subscription() {
		$id = $this->create_pending_subscription();

		$response = rest_do_request( $this->verification_request( $id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'a-random-challenge', $response->get_data() );

		$subscription = $this->subscriptions->get( $id );
		$this->assertSame( 'verified', $subscription['websub_status'] );
		$this->assertNotNull( $subscription['websub_lease_expires_at'] );
	}

	/** Verification ends the retry chain: no marker, no attempt count, and no scheduled check. */
	public function test_verification_clears_the_pending_retry_state() {
		$id = $this->create_pending_subscription();

		set_transient( 'daymark_websub_pending_' . $id, 1, HOUR_IN_SECONDS );
		set_transient( 'daymark_websub_attempts_' . $id, 2, DAY_IN_SECONDS );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) );

		$response = rest_do_request( $this->verification_request( $id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_transient( 'daymark_websub_pending_' . $id ) );
		$this->assertFalse( get_transient( 'daymark_websub_attempts_' . $id ) );
		$this->assertFalse( wp_next_scheduled( Daymark_Websub_Subscriber::VERIFY_TIMEOUT_HOOK, array( $id ) ) );
	}

	public function test_get_challenge_rejected_for_a_topic_mismatch() {
		$id = $this->create_pending_subscription();

		$response = rest_do_request( $this->verification_request( $id, null, 'a-stored-secret', 'https://not-the-right-feed.example.com/' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'] );
	}

	/**
	 * Subscription IDs are sequential and the feed URL is public, so a
	 * verification request without the callback token must not verify.
	 */
	public function test_get_challenge_rejected_without_the_callback_token() {
		$id = $this->create_pending_subscription();

		$response = rest_do_request( $this->verification_request( $id, '' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'], 'Status is unchanged' );
		$this->assertNull( $this->subscriptions->get( $id )['websub_lease_expires_at'] ?? null, 'No lease was granted' );
	}

	public function test_get_challenge_rejected_with_a_wrong_token() {
		$id = $this->create_pending_subscription();

		$response = rest_do_request( $this->verification_request( $id, str_repeat( '0', 32 ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'] );
	}

	/** A token issued for another subscription (or another secret) is worthless here. */
	public function test_get_challenge_rejected_with_a_token_for_another_subscription() {
		$id    = $this->create_pending_subscription();
		$other = Daymark_Websub_Subscriber::callback_token( $id + 1, 'a-stored-secret' );

		$this->assertSame( 404, rest_do_request( $this->verification_request( $id, $other ) )->get_status() );

		$stale = Daymark_Websub_Subscriber::callback_token( $id, 'an-earlier-secret' );

		$this->assertSame( 404, rest_do_request( $this->verification_request( $id, $stale ) )->get_status() );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'] );
	}

	/** A pending row with no stored secret has nothing to check a token against. */
	public function test_get_challenge_rejected_when_no_secret_is_stored() {
		$id = $this->create_subscription( array( 'websub_status' => 'pending' ) );

		$this->assertSame( 404, rest_do_request( $this->verification_request( $id, null, '' ) )->get_status() );
		$this->assertSame( 'pending', $this->subscriptions->get( $id )['websub_status'] );
	}

	public function test_get_challenge_rejected_when_not_pending() {
		$id = $this->create_subscription( array( 'websub_status' => 'none' ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/websub/' . $id );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_topic', 'https://example.com/feed/' );
		$request->set_param( 'hub_challenge', 'a-random-challenge' );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_post_with_valid_signature_ingests_and_returns_202() {
		$secret = 'a-shared-secret';
		$id     = $this->create_subscription(
			array(
				'websub_status'  => 'verified',
				'websub_secret'  => $secret,
				'websub_hub_url' => 'https://hub.example.com/',
			)
		);

		$body      = $this->rss_with_one_item();
		$signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/websub/' . $id );
		$request->set_header( 'X-Hub-Signature-256', $signature );
		$request->set_body( $body );

		$response = rest_do_request( $request );

		$this->assertSame( 202, $response->get_status() );

		$posts = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$this->assertCount( 1, $posts );
		$this->assertSame( 'A Post', $posts[0]->post_title );
	}

	public function test_post_with_invalid_signature_is_rejected_and_nothing_ingested() {
		$id = $this->create_subscription(
			array(
				'websub_status' => 'verified',
				'websub_secret' => 'a-shared-secret',
			)
		);

		$body = $this->rss_with_one_item();

		$request = new WP_REST_Request( 'POST', '/daymark/v1/websub/' . $id );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=' . hash_hmac( 'sha256', $body, 'the-wrong-secret' ) );
		$request->set_body( $body );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );

		$posts = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$this->assertCount( 0, $posts );
	}

	public function test_post_rejected_when_subscription_not_verified() {
		$id = $this->create_subscription(
			array(
				'websub_status' => 'pending',
				'websub_secret' => 'a-shared-secret',
			)
		);

		$body = $this->rss_with_one_item();

		$request = new WP_REST_Request( 'POST', '/daymark/v1/websub/' . $id );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=' . hash_hmac( 'sha256', $body, 'a-shared-secret' ) );
		$request->set_body( $body );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}
}
