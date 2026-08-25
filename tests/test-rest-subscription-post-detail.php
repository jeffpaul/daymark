<?php
/**
 * REST tests for GET /daymark/v1/subscription-posts/{id} (issue #78): the
 * per-item click-through detail fetch that returns `body_content` for a
 * `daymark_subscription_post`, which GET /timeline's list response
 * deliberately omits.
 *
 * The permalink fetch goes through
 * Daymark_Subscription_Poller::fetch_full_content()'s own
 * wp_safe_remote_get() call, so HTTP is mocked via `pre_http_request` (same
 * approach as tests/test-subscription-poller.php and
 * tests/test-rest-subscriptions.php) rather than hitting the real network.
 *
 * @package Daymark
 */

/**
 * Exercises the subscription post detail REST endpoint.
 */
class Test_Rest_Subscription_Post_Detail extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/**
	 * URL => canned wp_remote_get()-shaped response, consulted by
	 * intercept_http_request().
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/**
	 * URL => number of times intercept_http_request() served a mapped
	 * response for it, so a test can assert a permalink was (or wasn't)
	 * actually fetched.
	 *
	 * @var array<string, int>
	 */
	private array $http_call_counts = array();

	/** @var array<string, Closure> */
	private array $installed_filters = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author_a          = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriptions     = new Daymark_Subscriptions();
		$this->http_responses    = array();
		$this->http_call_counts  = array();
		$this->installed_filters = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		foreach ( $this->installed_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}

		parent::tear_down();
	}

	/**
	 * Short-circuits every HTTP request made in this file: a mapped URL
	 * returns its canned response (and increments its call count), anything
	 * unmapped is blocked with a WP_Error rather than hitting the real
	 * network.
	 *
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		unset( $parsed_args );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			$this->http_call_counts[ $url ] = ( $this->http_call_counts[ $url ] ?? 0 ) + 1;

			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * Register a canned 200 response for a URL.
	 *
	 * @param string $url  URL to mock.
	 * @param string $body Response body.
	 * @return void
	 */
	private function mock_response( string $url, string $body ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Override one rate-limit action's limits for the test, mirroring
	 * tests/test-rate-limiter.php's set_limits().
	 *
	 * @param string $action Action key.
	 * @param int    $limit  Attempt limit.
	 * @param int    $window Window in seconds.
	 */
	private function set_limits( string $action, int $limit, int $window ): void {
		$callback = static function ( $all, $hook_action ) use ( $action, $limit, $window ) {
			unset( $hook_action );
			$all            = is_array( $all ) ? $all : array();
			$all[ $action ] = array(
				'limit'  => $limit,
				'window' => $window,
			);

			return $all;
		};

		add_filter( 'daymark_rate_limits', $callback, 10, 2 );
		$this->installed_filters['daymark_rate_limits'] = $callback;
	}

	/**
	 * Create a subscription row.
	 *
	 * @param string $feed_url Unique feed URL.
	 * @return int
	 */
	private function create_subscription( string $feed_url ): int {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => $feed_url,
			)
		);

		$this->assertIsInt( $id, 'Subscription row created' );

		return $id;
	}

	/**
	 * Create a cached `daymark_subscription_post`.
	 *
	 * @param int    $subscription_id Owning subscription ID.
	 * @param string $permalink       Source permalink.
	 * @param string $content_state   'full'|'excerpt_only'|'pruned'.
	 * @param string $body_content    Pre-cached body (only meaningful for 'full').
	 * @return int
	 */
	private function create_subscription_post( int $subscription_id, string $permalink, string $content_state = 'excerpt_only', string $body_content = '' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'A Subscription Post',
				'post_excerpt' => 'Excerpt for A Subscription Post',
			)
		);

		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', $permalink );
		update_post_meta( $post_id, 'author', 'Someone Else' );
		update_post_meta( $post_id, 'post_format', 'standard' );
		update_post_meta( $post_id, 'content_state', $content_state );
		update_post_meta( $post_id, 'body_content', $body_content );

		return $post_id;
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param int $post_id `daymark_subscription_post` ID.
	 * @return WP_REST_Request
	 */
	private function request_for( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', "/daymark/v1/subscription-posts/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** An excerpt-only post triggers a live fetch and returns the fetched body. */
	public function test_excerpt_only_post_triggers_fetch_and_returns_full_content() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_subscription_post( $subscription_id, 'https://example.com/full-post/', 'excerpt_only' );

		$this->mock_response( 'https://example.com/full-post/', '<html><body><p>Full <script>alert(1)</script>article content.</p></body></html>' );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'full', $data['content_state'] );
		$this->assertStringContainsString( 'Full', $data['body_content'] );
		$this->assertStringContainsString( 'article content', $data['body_content'] );
		$this->assertStringNotContainsString( '<script>', $data['body_content'], 'Body content stays sanitized (wp_kses_post()), not re-escaped' );
		$this->assertSame( 'subscription_post', $data['item_type'] );
		$this->assertSame( $post_id, $data['id'] );

		$this->assertSame( 'full', get_post_meta( $post_id, 'content_state', true ), 'Cached in post meta too' );
		$this->assertSame( 1, $this->http_call_counts['https://example.com/full-post/'] ?? 0 );
	}

	/** A post already content_state=full is served from cache; no HTTP request is made. */
	public function test_already_full_post_does_not_trigger_a_second_fetch() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_subscription_post(
			$subscription_id,
			'https://example.com/already-fetched/',
			'full',
			'<p>Cached body.</p>'
		);

		// Deliberately not mocked: if a request were attempted, it would be
		// blocked by intercept_http_request() and surface as a non-200
		// response, so a 200 here also proves no request was made.
		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'full', $data['content_state'] );
		$this->assertSame( '<p>Cached body.</p>', $data['body_content'] );

		$this->assertSame( 0, $this->http_call_counts['https://example.com/already-fetched/'] ?? 0, 'No HTTP request was made for an already-full post' );
	}

	/** A fetch failure propagates the poller's WP_Error status, not a 200. */
	public function test_fetch_failure_propagates_error_status() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_subscription_post( $subscription_id, 'https://unreachable.example/post/', 'excerpt_only' );

		// Deliberately not mocked: intercept_http_request() blocks it,
		// simulating an unreachable source site.
		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'daymark_subscription_fetch_failed', $response->get_data()['code'] );
	}

	/** An unauthenticated request is rejected with 401. */
	public function test_unauthenticated_request_returns_401() {
		wp_set_current_user( 0 );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_subscription_post( $subscription_id, 'https://example.com/full-post/', 'full', '<p>Cached.</p>' );

		$request  = new WP_REST_Request( 'GET', "/daymark/v1/subscription-posts/{$post_id}" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/** A nonexistent post ID is a clean 404, not a fatal. */
	public function test_nonexistent_post_id_returns_404() {
		wp_set_current_user( $this->author_a );

		$response = rest_do_request( $this->request_for( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'daymark_subscription_post_not_found', $response->get_data()['code'] );
	}

	/** Past the configured per-user budget, the endpoint returns 429 + Retry-After. */
	public function test_rate_limiting_returns_429_past_the_threshold() {
		$this->set_limits( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH, 2, 5 * MINUTE_IN_SECONDS );
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );

		// content_state=full + a pre-cached body: every request in this test
		// is served from cache, so only the rate limiter (not the HTTP mock)
		// determines the response.
		for ( $i = 0; $i < 2; $i++ ) {
			$post_id  = $this->create_subscription_post( $subscription_id, 'https://example.com/cached-' . $i . '/', 'full', '<p>Cached.</p>' );
			$response = rest_do_request( $this->request_for( $post_id ) );
			$this->assertSame( 200, $response->get_status(), 'Requests within the budget succeed' );
		}

		$post_id  = $this->create_subscription_post( $subscription_id, 'https://example.com/cached-over/', 'full', '<p>Cached.</p>' );
		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 429, $response->get_status(), 'The next request hits the limit' );
		$this->assertSame( 'daymark_rate_limit_exceeded', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'retry_after', $response->get_data()['data'] );
	}
}
