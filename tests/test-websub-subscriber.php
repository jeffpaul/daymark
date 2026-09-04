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
		$this->assertStringContainsString( '/daymark/v1/websub/' . $id, $this->sent_requests[0]['body']['hub.callback'] );
		$this->assertNotEmpty( $this->sent_requests[0]['body']['hub.secret'] );

		$subscription = $this->subscriptions->get( $id );
		$this->assertSame( 'pending', $subscription['websub_status'] );
		$this->assertSame( 'https://hub.example.com/', $subscription['websub_hub_url'] );
		$this->assertNotSame( '', $subscription['websub_secret'] );
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

		$this->subscriber->maybe_subscribe( $id, 'https://example.com/feed/', 'https://hub.example.com/' );

		$this->assertCount( 0, $this->sent_requests );
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
