<?php
/**
 * Per-user REST rate limiting tests (security hardening).
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Rate_Limiter behavior and its REST wiring.
 */
class Test_Rate_Limiter extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<string, Closure> */
	private array $installed_filters = array();

	public function set_up(): void {
		parent::set_up();
		$this->user_id           = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->installed_filters = array();
	}

	public function tear_down(): void {
		foreach ( $this->installed_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		parent::tear_down();
	}

	/**
	 * Override one action's limits for the test.
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

	/** Attempts count down the budget; the next attempt returns 429. */
	public function test_attempt_counts_against_limit() {
		$this->set_limits( 'publish', 2, 5 * MINUTE_IN_SECONDS );
		$limiter = new Daymark_Rate_Limiter();

		$this->assertTrue( $limiter->attempt( 'publish', $this->user_id ) );
		$this->assertTrue( $limiter->attempt( 'publish', $this->user_id ) );
		$this->assertSame( 0, $limiter->remaining( 'publish', $this->user_id ) );

		$result = $limiter->attempt( 'publish', $this->user_id );
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_rate_limit_exceeded', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/** An expired window resets the bucket and allows new attempts. */
	public function test_window_expiry_resets_the_bucket() {
		$this->set_limits( 'publish', 1, 60 );
		$limiter = new Daymark_Rate_Limiter();

		$this->assertTrue( $limiter->attempt( 'publish', $this->user_id ) );
		$this->assertWPError( $limiter->attempt( 'publish', $this->user_id ) );

		// Simulate a window that elapsed since the last recorded attempt.
		set_transient(
			'daymark_rl_publish_' . $this->user_id,
			array(
				'count' => 999,
				'start' => time() - 120,
			),
			60
		);

		$this->assertTrue( $limiter->attempt( 'publish', $this->user_id ), 'An expired window must allow a new attempt' );
		$this->assertSame( 0, $limiter->remaining( 'publish', $this->user_id ) );
	}

	/** Remaining reports the budget left and resets after the window. */
	public function test_remaining_reflects_window() {
		$this->set_limits( 'sync', 3, 60 );
		$limiter = new Daymark_Rate_Limiter();

		$this->assertSame( 3, $limiter->remaining( 'sync', $this->user_id ) );
		$limiter->attempt( 'sync', $this->user_id );
		$this->assertSame( 2, $limiter->remaining( 'sync', $this->user_id ) );

		// A stale bucket is reported as full budget (window expired).
		set_transient(
			'daymark_rl_sync_' . $this->user_id,
			array(
				'count' => 1,
				'start' => time() - 120,
			),
			60
		);
		$this->assertSame( 3, $limiter->remaining( 'sync', $this->user_id ) );
	}

	/** The limiter is scoped per user, not shared across accounts. */
	public function test_bucket_is_per_user() {
		$this->set_limits( 'ai', 1, 5 * MINUTE_IN_SECONDS );
		$other_user = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$limiter    = new Daymark_Rate_Limiter();

		$limiter->attempt( 'ai', $this->user_id );
		$this->assertWPError( $limiter->attempt( 'ai', $this->user_id ) );
		$this->assertTrue( $limiter->attempt( 'ai', $other_user ), 'Another user has their own budget' );
	}

	/** A logged-out caller cannot be rate limited (no identity to key on). */
	public function test_unauthenticated_attempt_is_rejected() {
		wp_set_current_user( 0 );
		$limiter = new Daymark_Rate_Limiter();

		$result = $limiter->attempt( 'ai' );
		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/** POST /ai/suggestions returns 429 once the per-user budget is spent. */
	public function test_rest_ai_suggestions_returns_429_when_exhausted() {
		$this->set_limits( 'ai', 3, 5 * MINUTE_IN_SECONDS );
		wp_set_current_user( $this->user_id );

		for ( $i = 0; $i < 3; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/suggestions' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$this->assertSame( 200, rest_do_request( $request )->get_status(), 'Requests within the budget succeed' );
		}

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/suggestions' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 429, $response->get_status(), 'The next request hits the limit' );
	}
}
