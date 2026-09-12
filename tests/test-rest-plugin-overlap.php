<?php
/**
 * REST tests for plugin-overlap dismissal (issue #346): the POST
 * /daymark/v1/notifications/plugin-overlaps/{plugin}/dismiss route.
 *
 * @package Daymark
 */

/**
 * Exercises the dismiss_plugin_overlap REST route.
 */
class Test_Rest_Plugin_Overlap extends WP_UnitTestCase {

	/** @var int */
	private $author;

	public function set_up(): void {
		parent::set_up();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	public function test_dismisses_a_known_plugin() {
		wp_set_current_user( $this->author );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/notifications/plugin-overlaps/post-kinds/dismiss' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['dismissed'] );
		$this->assertSame( 'post-kinds', $response->get_data()['plugin'] );
		$this->assertTrue( Daymark_Plugin::instance()->plugin_overlap->is_dismissed( $this->author, 'post-kinds' ) );
	}

	public function test_unknown_plugin_returns_404() {
		wp_set_current_user( $this->author );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/notifications/plugin-overlaps/not-a-real-plugin/dismiss' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_unauthenticated_request_returns_401() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/daymark/v1/notifications/plugin-overlaps/post-kinds/dismiss' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/** Dismissing one plugin never marks a different one dismissed for the same user. */
	public function test_dismissal_is_scoped_per_plugin() {
		wp_set_current_user( $this->author );

		rest_do_request( $this->request( 'POST', '/daymark/v1/notifications/plugin-overlaps/post-kinds/dismiss' ) );

		$this->assertFalse( Daymark_Plugin::instance()->plugin_overlap->is_dismissed( $this->author, 'microformats2' ) );
	}
}
