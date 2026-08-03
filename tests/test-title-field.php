<?php
/**
 * Optional Title field: per-type policy (filterable), the AI title
 * suggestion mock fallback, and the POST /ai/title REST route.
 *
 * @package Daymark
 */

/**
 * Title-field policy + AI title suggestion + REST endpoint.
 */
class Test_Title_Field extends WP_UnitTestCase {

	/**
	 * Act as an author for every test.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	/** Audio and video are optional by default; every other type is hidden. */
	public function test_policy_defaults() {
		$policy = Daymark_Publisher::title_field_policy();

		$this->assertSame( 'optional', $policy['audio'] );
		$this->assertSame( 'optional', $policy['video'] );

		foreach ( array( 'note', 'image', 'gallery', 'mixed' ) as $type ) {
			$this->assertSame( 'hidden', $policy[ $type ], "{$type} should hide the title field by default" );
		}
	}

	/** The daymark_title_field_policy filter can flip a type. */
	public function test_policy_filter_overrides_a_type() {
		$override = static function ( $policy ) {
			$policy['note'] = 'optional';
			return $policy;
		};

		add_filter( 'daymark_title_field_policy', $override );
		$policy = Daymark_Publisher::title_field_policy();
		remove_filter( 'daymark_title_field_policy', $override );

		$this->assertSame( 'optional', $policy['note'] );
		// Untouched defaults still hold.
		$this->assertSame( 'optional', $policy['audio'] );
		$this->assertSame( 'hidden', $policy['image'] );
	}

	/** Without a provider, suggest_title() returns a non-empty mock title. */
	public function test_suggest_title_mock_without_provider() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$ai    = new Daymark_AI_Assist();
		$title = $ai->suggest_title(
			array(
				'text' => 'Late-night studio session',
				'type' => 'audio',
			)
		);

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertNotSame( '', $title );
		$this->assertLessThanOrEqual( 80, strlen( $title ), 'Mock title stays short' );
	}

	/** A textless draft still yields a non-empty per-type mock title. */
	public function test_suggest_title_mock_without_text() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$ai    = new Daymark_AI_Assist();
		$title = $ai->suggest_title( array( 'type' => 'video' ) );

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertNotSame( '', $title );
	}

	/** Mock titles are deterministic: same input, same output. */
	public function test_suggest_title_mock_is_deterministic() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$context = array(
			'text' => 'Morning walk in the park',
			'type' => 'audio',
		);
		$first   = ( new Daymark_AI_Assist() )->suggest_title( $context );
		$second  = ( new Daymark_AI_Assist() )->suggest_title( $context );

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertSame( $first, $second );
	}

	/** POST /ai/title returns a title (mock without a provider). */
	public function test_rest_title_returns_title_when_authorized() {
		add_filter( 'wp_supports_ai', '__return_false' );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/title' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'text', 'A quiet field recording' );
		$request->set_param( 'primary_type', 'audio' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'title', $data );
		$this->assertArrayHasKey( 'is_mocked', $data );
		$this->assertArrayHasKey( 'provider_label', $data );
		$this->assertNotSame( '', $data['title'] );
		$this->assertTrue( $data['is_mocked'] );
	}

	/** POST /ai/title rejects a request with no nonce (logged-in → 403). */
	public function test_rest_title_rejects_missing_nonce() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/title' );
		$request->set_param( 'text', 'No nonce here' );
		$request->set_param( 'primary_type', 'audio' );

		// The author from set_up is authenticated, so a missing nonce is a 403.
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/** POST /ai/title rejects a logged-out request (no nonce → 401). */
	public function test_rest_title_rejects_unauthenticated() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/title' );
		$request->set_param( 'text', 'Logged out' );
		$request->set_param( 'primary_type', 'audio' );

		$this->assertSame( 401, rest_do_request( $request )->get_status() );
	}
}
