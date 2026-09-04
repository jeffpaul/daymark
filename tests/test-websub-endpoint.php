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

	public function test_get_challenge_echoed_for_a_genuinely_pending_subscription() {
		$id = $this->create_subscription( array( 'websub_status' => 'pending' ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/websub/' . $id );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_topic', 'https://example.com/feed/' );
		$request->set_param( 'hub_challenge', 'a-random-challenge' );
		$request->set_param( 'hub_lease_seconds', '86400' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'a-random-challenge', $response->get_data() );

		$subscription = $this->subscriptions->get( $id );
		$this->assertSame( 'verified', $subscription['websub_status'] );
		$this->assertNotNull( $subscription['websub_lease_expires_at'] );
	}

	public function test_get_challenge_rejected_for_a_topic_mismatch() {
		$id = $this->create_subscription( array( 'websub_status' => 'pending' ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/websub/' . $id );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_topic', 'https://not-the-right-feed.example.com/' );
		$request->set_param( 'hub_challenge', 'a-random-challenge' );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
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
