<?php
/**
 * Tests for the per-user interaction-hint "seen" state (issue #322):
 * Daymark_Interaction_Hints, POST /daymark/v1/interaction-hints/{hint}/seen,
 * and the `interactionHintsSeen` boot config value.
 *
 * @package Daymark
 */

/**
 * Exercises hint storage, validation, and the REST route.
 */
class Test_Interaction_Hints extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );
	}

	private function request( string $hint ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/interaction-hints/' . $hint . '/seen' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	public function test_nothing_seen_by_default() {
		$this->assertSame( array(), Daymark_Interaction_Hints::get_seen( $this->user_id ) );
	}

	public function test_mark_seen_records_the_hint() {
		$seen = Daymark_Interaction_Hints::mark_seen( $this->user_id, 'bookmark' );

		$this->assertSame( array( 'bookmark' ), $seen );
		$this->assertSame( array( 'bookmark' ), Daymark_Interaction_Hints::get_seen( $this->user_id ) );
	}

	public function test_mark_seen_is_idempotent() {
		Daymark_Interaction_Hints::mark_seen( $this->user_id, 'like' );
		Daymark_Interaction_Hints::mark_seen( $this->user_id, 'like' );

		$this->assertCount( 1, get_user_meta( $this->user_id, Daymark_Interaction_Hints::META_KEY, false ) );
	}

	/** Seen keys come back in the fixed KEYS order, whatever order they were seen in. */
	public function test_seen_keys_follow_known_key_order() {
		Daymark_Interaction_Hints::mark_seen( $this->user_id, 'share' );
		Daymark_Interaction_Hints::mark_seen( $this->user_id, 'like' );

		$this->assertSame( array( 'like', 'share' ), Daymark_Interaction_Hints::get_seen( $this->user_id ) );
	}

	public function test_unknown_hint_is_rejected() {
		$result = Daymark_Interaction_Hints::mark_seen( $this->user_id, 'not-a-hint' );

		$this->assertWPError( $result );
		$this->assertSame( array(), get_user_meta( $this->user_id, Daymark_Interaction_Hints::META_KEY, false ) );
	}

	/** A stored row for a key that's no longer known, or a duplicate row, never reaches the app. */
	public function test_unknown_and_duplicate_rows_are_ignored() {
		add_user_meta( $this->user_id, Daymark_Interaction_Hints::META_KEY, 'retired-hint' );
		add_user_meta( $this->user_id, Daymark_Interaction_Hints::META_KEY, 'comment' );
		add_user_meta( $this->user_id, Daymark_Interaction_Hints::META_KEY, 'comment' );

		$this->assertSame( array( 'comment' ), Daymark_Interaction_Hints::get_seen( $this->user_id ) );
	}

	public function test_seen_state_is_per_user() {
		$other = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		Daymark_Interaction_Hints::mark_seen( $this->user_id, 'repost' );

		$this->assertSame( array(), Daymark_Interaction_Hints::get_seen( $other ) );
	}

	public function test_rest_route_records_the_hint() {
		$response = rest_do_request( $this->request( 'external' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'external' ), $response->get_data()['seen'] );
		$this->assertSame( array( 'external' ), Daymark_Interaction_Hints::get_seen( $this->user_id ) );
	}

	public function test_rest_route_rejects_an_unknown_hint() {
		$response = rest_do_request( $this->request( 'nope' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rest_route_requires_a_signed_in_user() {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/daymark/v1/interaction-hints/like/seen' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_rest_route_requires_edit_posts() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = rest_do_request( $this->request( 'like' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_boot_config_carries_seen_hints() {
		Daymark_Interaction_Hints::mark_seen( $this->user_id, 'comment' );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( array( 'comment' ), $config['interactionHintsSeen'] );
	}
}
