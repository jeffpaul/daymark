<?php
/**
 * Tests for the per-user "last opened Explore" time: Daymark_Explore_Seen
 * and POST /daymark/v1/explore/seen. Explore's People row rings the sites
 * with a post received since the visit before the current one.
 *
 * @package Daymark
 */

/**
 * Exercises the marker's storage and its REST route.
 */
class Test_Explore_Seen extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/explore/seen' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** A first visit has nothing before it, and is stored. */
	public function test_first_visit_has_no_previous() {
		$visit = Daymark_Explore_Seen::touch( $this->user_id );

		$this->assertSame( '', $visit['previous'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $visit['seen'] );
		$this->assertSame( $visit['seen'], get_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, true ) );
	}

	/** A later visit is told when the one before it was, and replaces it. */
	public function test_next_visit_returns_the_one_before() {
		update_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, '2026-03-04 05:06:07' );

		$visit = Daymark_Explore_Seen::touch( $this->user_id );

		$this->assertSame( '2026-03-04 05:06:07', $visit['previous'] );
		$this->assertSame( $visit['seen'], get_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, true ) );
		$this->assertGreaterThan( '2026-03-04 05:06:07', $visit['seen'] );
	}

	/** A stored value that isn't a time counts as no earlier visit. */
	public function test_a_malformed_stored_value_is_ignored() {
		update_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, 'yesterday' );

		$this->assertSame( '', Daymark_Explore_Seen::touch( $this->user_id )['previous'] );
	}

	/** Each user has their own marker. */
	public function test_markers_are_per_user() {
		update_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, '2026-03-04 05:06:07' );
		$other = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertSame( '', Daymark_Explore_Seen::touch( $other )['previous'] );
		$this->assertSame( '2026-03-04 05:06:07', get_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, true ) );
	}

	/** The route answers with both times in ISO 8601, UTC. */
	public function test_route_returns_previous_and_seen() {
		update_user_meta( $this->user_id, Daymark_Explore_Seen::META_KEY, '2026-03-04 05:06:07' );

		$response = rest_do_request( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '2026-03-04T05:06:07+00:00', $data['previous'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['seen'] );
	}

	/** The first visit's `previous` is an empty string, not a date. */
	public function test_route_first_visit_previous_is_empty() {
		$data = rest_do_request( $this->request() )->get_data();

		$this->assertSame( '', $data['previous'] );
	}

	/** Like the rest of the app's routes, it needs someone who can write posts. */
	public function test_route_refuses_a_subscriber_and_a_logged_out_visitor() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $this->request() )->get_status() );

		wp_set_current_user( 0 );
		$this->assertContains( rest_do_request( new WP_REST_Request( 'POST', '/daymark/v1/explore/seen' ) )->get_status(), array( 401, 403 ) );
	}
}
