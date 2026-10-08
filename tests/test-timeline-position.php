<?php
/**
 * Tests for the Timeline's per-user "last seen" marker and reading
 * position: Daymark_Timeline_Position, POST /daymark/v1/timeline/last-seen,
 * POST /daymark/v1/timeline/position, and the `timelineLastSeen` and
 * `timelinePosition` boot config values.
 *
 * @package Daymark
 */

/**
 * Exercises the marker's forward-only rule, validation, and REST route.
 */
class Test_Timeline_Position extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * A published post dated `$gmt` (MySQL datetime, UTC).
	 *
	 * @param string $gmt    Post date, GMT.
	 * @param string $status Post status.
	 * @return int
	 */
	private function create_post( string $gmt, string $status = 'publish' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_status'   => $status,
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
			)
		);
	}

	/**
	 * A cached subscription post published at `$gmt`.
	 *
	 * @param string $gmt published_at value.
	 * @return int
	 */
	private function create_subscription_post( string $gmt ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, 'published_at', $gmt );

		return $post_id;
	}

	private function request( int $id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/timeline/last-seen' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'id', $id );

		return $request;
	}

	public function test_no_marker_by_default() {
		$this->assertNull( Daymark_Timeline_Position::get( $this->user_id ) );
	}

	public function test_first_seen_item_becomes_the_marker() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );

		$marker = Daymark_Timeline_Position::mark_seen( $this->user_id, $post_id );

		$this->assertSame(
			array(
				'id'        => $post_id,
				'item_type' => 'mark',
			),
			$marker
		);
	}

	public function test_newer_item_moves_the_marker_forward() {
		$older = $this->create_post( '2026-09-01 10:00:00' );
		$newer = $this->create_subscription_post( '2026-09-02 10:00:00' );

		Daymark_Timeline_Position::mark_seen( $this->user_id, $older );
		$marker = Daymark_Timeline_Position::mark_seen( $this->user_id, $newer );

		$this->assertSame( $newer, $marker['id'] );
		$this->assertSame( 'subscription_post', $marker['item_type'] );
	}

	public function test_older_item_never_moves_the_marker_back() {
		$older = $this->create_subscription_post( '2026-09-01 10:00:00' );
		$newer = $this->create_post( '2026-09-02 10:00:00' );

		Daymark_Timeline_Position::mark_seen( $this->user_id, $newer );
		$marker = Daymark_Timeline_Position::mark_seen( $this->user_id, $older );

		$this->assertSame( $newer, $marker['id'] );
	}

	public function test_marker_is_dropped_once_its_item_leaves_the_timeline() {
		$post_id = $this->create_post( '2026-09-02 10:00:00' );
		Daymark_Timeline_Position::mark_seen( $this->user_id, $post_id );

		wp_trash_post( $post_id );

		$this->assertNull( Daymark_Timeline_Position::get( $this->user_id ) );

		// And an older item can take its place.
		$older  = $this->create_post( '2026-09-01 10:00:00' );
		$marker = Daymark_Timeline_Position::mark_seen( $this->user_id, $older );
		$this->assertSame( $older, $marker['id'] );
	}

	public function test_non_timeline_items_are_rejected() {
		$draft = $this->create_post( '2026-09-01 10:00:00', 'draft' );
		$page  = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertWPError( Daymark_Timeline_Position::mark_seen( $this->user_id, $draft ) );
		$this->assertWPError( Daymark_Timeline_Position::mark_seen( $this->user_id, $page ) );
		$this->assertNull( Daymark_Timeline_Position::get( $this->user_id ) );
	}

	public function test_markers_are_per_user() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );
		$other   = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		Daymark_Timeline_Position::mark_seen( $this->user_id, $post_id );

		$this->assertNull( Daymark_Timeline_Position::get( $other ) );
	}

	public function test_rest_route_records_and_returns_the_marker() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );

		$response = rest_do_request( $this->request( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post_id, $response->get_data()['last_seen']['id'] );
		$this->assertSame( $post_id, Daymark_Timeline_Position::get( $this->user_id )['id'] );
	}

	public function test_rest_route_404s_for_an_unknown_item() {
		$response = rest_do_request( $this->request( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rest_route_requires_a_logged_in_user() {
		wp_set_current_user( 0 );
		$post_id = $this->create_post( '2026-09-01 10:00:00' );

		$response = rest_do_request( $this->request( $post_id ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_boot_config_carries_the_marker() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );
		Daymark_Timeline_Position::mark_seen( $this->user_id, $post_id );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( $post_id, $config['timelineLastSeen']['id'] );
	}

	/**
	 * A POST /timeline/position request for `$post_id`.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Request
	 */
	private function position_request( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/timeline/position' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'id', $post_id );

		return $request;
	}

	public function test_position_is_null_until_saved() {
		$this->assertNull( Daymark_Timeline_Position::get_position( $this->user_id ) );
	}

	public function test_position_moves_backward_as_well_as_forward() {
		$older = $this->create_post( '2026-09-01 10:00:00' );
		$newer = $this->create_post( '2026-09-02 10:00:00' );

		Daymark_Timeline_Position::set_position( $this->user_id, $newer );
		$position = Daymark_Timeline_Position::set_position( $this->user_id, $older );

		$this->assertSame( $older, $position['id'] );
		$this->assertSame( $older, Daymark_Timeline_Position::get_position( $this->user_id )['id'] );
	}

	public function test_position_and_last_seen_are_independent() {
		$older = $this->create_post( '2026-09-01 10:00:00' );
		$newer = $this->create_post( '2026-09-02 10:00:00' );

		Daymark_Timeline_Position::mark_seen( $this->user_id, $newer );
		Daymark_Timeline_Position::set_position( $this->user_id, $older );

		$this->assertSame( $newer, Daymark_Timeline_Position::get( $this->user_id )['id'] );
		$this->assertSame( $older, Daymark_Timeline_Position::get_position( $this->user_id )['id'] );
	}

	public function test_position_accepts_a_subscription_post() {
		$post_id = $this->create_subscription_post( '2026-09-01 10:00:00' );

		$position = Daymark_Timeline_Position::set_position( $this->user_id, $post_id );

		$this->assertSame( 'subscription_post', $position['item_type'] );
	}

	public function test_position_rejects_items_not_on_the_timeline() {
		$draft = $this->create_post( '2026-09-01 10:00:00', 'draft' );
		$page  = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertWPError( Daymark_Timeline_Position::set_position( $this->user_id, $draft ) );
		$this->assertWPError( Daymark_Timeline_Position::set_position( $this->user_id, $page ) );
		$this->assertNull( Daymark_Timeline_Position::get_position( $this->user_id ) );
	}

	public function test_position_is_null_once_its_item_leaves_the_timeline() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );
		Daymark_Timeline_Position::set_position( $this->user_id, $post_id );

		wp_trash_post( $post_id );

		$this->assertNull( Daymark_Timeline_Position::get_position( $this->user_id ) );
	}

	public function test_position_rest_route_saves_and_returns_the_position() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );

		$response = rest_do_request( $this->position_request( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post_id, $response->get_data()['position']['id'] );
	}

	public function test_position_rest_route_404s_for_an_unknown_item() {
		$response = rest_do_request( $this->position_request( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_position_rest_route_requires_a_logged_in_user() {
		wp_set_current_user( 0 );
		$post_id = $this->create_post( '2026-09-01 10:00:00' );

		$response = rest_do_request( $this->position_request( $post_id ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_boot_config_carries_the_position() {
		$post_id = $this->create_post( '2026-09-01 10:00:00' );
		Daymark_Timeline_Position::set_position( $this->user_id, $post_id );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( $post_id, $config['timelinePosition']['id'] );
	}
}
