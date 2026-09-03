<?php
/**
 * POST /daymark/v1/ai/tags: the quiet-tag-suggestion REST route (quiet mark
 * metadata capture). Mirrors tests/test-title-field.php's coverage of
 * /ai/title.
 *
 * @package Daymark
 */

/**
 * Tests the /ai/tags REST endpoint.
 */
class Test_Rest_Ai_Tags extends WP_UnitTestCase {

	/**
	 * Act as an author for every test.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	/** POST /ai/tags returns a tag list (mock without a provider). */
	public function test_rest_tags_returns_tags_when_authorized() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'text', 'A quiet afternoon at the park' );
		$request->set_param( 'primary_type', 'note' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'tags', $data );
		$this->assertArrayHasKey( 'is_mocked', $data );
		$this->assertArrayHasKey( 'provider_label', $data );
		$this->assertIsArray( $data['tags'] );
		$this->assertNotEmpty( $data['tags'] );
		$this->assertTrue( $data['is_mocked'] );
	}

	/** The legacy `caption`/`type` field names still work, matching /ai/title. */
	public function test_rest_tags_accepts_legacy_field_names() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'Legacy field name test' );
		$request->set_param( 'type', 'note' );

		$response = rest_do_request( $request );

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertSame( 200, $response->get_status() );
	}

	/** POST /ai/tags rejects a request with no nonce (logged-in → 403). */
	public function test_rest_tags_rejects_missing_nonce() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
		$request->set_param( 'text', 'No nonce here' );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/** POST /ai/tags rejects a logged-out request (no nonce → 401). */
	public function test_rest_tags_rejects_unauthenticated() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
		$request->set_param( 'text', 'Logged out' );

		$this->assertSame( 401, rest_do_request( $request )->get_status() );
	}

	/** POST /ai/tags is rate limited via the shared ACTION_AI bucket. */
	public function test_rest_tags_returns_429_when_ai_budget_exhausted() {
		$limits_filter = static function ( $all, $action ) {
			unset( $action );
			$all       = is_array( $all ) ? $all : array();
			$all['ai'] = array(
				'limit'  => 2,
				'window' => 5 * MINUTE_IN_SECONDS,
			);
			return $all;
		};
		add_filter( 'daymark_rate_limits', $limits_filter, 10, 2 );

		for ( $i = 0; $i < 2; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$this->assertSame( 200, rest_do_request( $request )->get_status(), 'Requests within the budget succeed' );
		}

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		remove_filter( 'daymark_rate_limits', $limits_filter, 10 );

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * The ACTION_AI budget is shared with the other AI routes — exhausting
	 * it via /ai/title also blocks /ai/tags, proving they're the same
	 * bucket rather than accidentally independent ones.
	 */
	public function test_rest_tags_shares_ai_budget_with_other_ai_routes() {
		$limits_filter = static function ( $all, $action ) {
			unset( $action );
			$all       = is_array( $all ) ? $all : array();
			$all['ai'] = array(
				'limit'  => 1,
				'window' => 5 * MINUTE_IN_SECONDS,
			);
			return $all;
		};
		add_filter( 'daymark_rate_limits', $limits_filter, 10, 2 );

		$title_request = new WP_REST_Request( 'POST', '/daymark/v1/ai/title' );
		$title_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, rest_do_request( $title_request )->get_status() );

		$tags_request = new WP_REST_Request( 'POST', '/daymark/v1/ai/tags' );
		$tags_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $tags_request );

		remove_filter( 'daymark_rate_limits', $limits_filter, 10 );

		$this->assertSame( 429, $response->get_status() );
	}
}
