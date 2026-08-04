<?php
/**
 * Save-as-draft and deferred-syndication tests.
 *
 * @package Daymark
 */

/**
 * Drafts never syndicate; stored targets run when the Mark goes live,
 * whether published from the app or wp-admin.
 */
class Test_Drafts extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	private function save_draft_with_target(): int {
		$publisher = new Daymark_Publisher();

		return (int) $publisher->publish(
			array(
				'caption'             => 'Draft to finish later',
				'status'              => 'draft',
				'syndication_targets' => array( 'bluesky' ),
			)
		);
	}

	/** An explicit draft request wins even for users who can publish. */
	public function test_explicit_draft_is_saved_as_draft() {
		$post_id = $this->save_draft_with_target();

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/** Drafts store their targets but never attempt syndication. */
	public function test_draft_does_not_syndicate() {
		$post_id = $this->save_draft_with_target();

		$this->assertSame(
			array( 'bluesky' ),
			json_decode( (string) get_post_meta( $post_id, '_daymark_syndication_targets', true ), true )
		);
		$this->assertSame( 'not_attempted', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
		$this->assertSame(
			array(),
			(array) json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true )
		);
	}

	/** Publishing the draft (from anywhere) runs the stored targets once. */
	public function test_publishing_draft_triggers_deferred_syndication() {
		$post_id = $this->save_draft_with_target();

		// Simulates a wp-admin publish: no Daymark code path involved.
		wp_publish_post( $post_id );

		$this->assertNotSame( 'not_attempted', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertArrayHasKey( 'bluesky', (array) $external );

		// A later re-publish cycle must not syndicate again.
		$first = $external['bluesky']['external_id'];
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		wp_publish_post( $post_id );
		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertSame( $first, $external['bluesky']['external_id'], 'Re-publishing must not duplicate syndication' );
	}

	/** The REST endpoint accepts status=draft. */
	public function test_rest_create_accepts_draft_status() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/marks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'REST draft' );
		$request->set_param( 'status', 'draft' );

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()['status'] );
	}

	/** GET /marks?status= filters the list (drafts stay reachable). */
	public function test_marks_list_status_filter() {
		$draft_id = $this->save_draft_with_target();

		$publisher    = new Daymark_Publisher();
		$published_id = (int) $publisher->publish( array( 'caption' => 'Live one' ) );

		$fetch = function ( string $status ) {
			$request = new WP_REST_Request( 'GET', '/daymark/v1/marks' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'status', $status );

			return array_column( rest_do_request( $request )->get_data(), 'id' );
		};

		$this->assertSame( array( $draft_id ), array_values( array_intersect( $fetch( 'draft' ), array( $draft_id, $published_id ) ) ) );
		$this->assertSame( array( $published_id ), array_values( array_intersect( $fetch( 'publish' ), array( $draft_id, $published_id ) ) ) );
		$this->assertCount( 2, array_intersect( $fetch( 'any' ), array( $draft_id, $published_id ) ) );
	}

	/** GET /marks/{id} returns the editable payload, raw caption included. */
	public function test_get_daymark_returns_edit_payload() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'             => "Line one.\n\nLine two's detail.",
				'status'              => 'draft',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$request = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( "Line one.\n\nLine two's detail.", $data['caption'], 'Caption must round-trip losslessly' );
		$this->assertSame( array( 'bluesky' ), $data['targets'] );
		$this->assertSame( array(), $data['media'] );
	}

	/** Caption recovery for Marks predating the caption meta. */
	public function test_get_daymark_recovers_caption_from_content() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption' => 'Recoverable text',
				'status'  => 'draft',
			)
		);
		delete_post_meta( $post_id, '_daymark_caption' );

		$request = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( 'Recoverable text', rest_do_request( $request )->get_data()['caption'] );
	}

	/** Updating a draft rewrites caption/content and can keep it a draft. */
	public function test_update_draft_in_place() {
		$post_id = $this->save_draft_with_target();

		$request = new WP_REST_Request( 'POST', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'Finished thought.' );
		$request->set_param( 'status', 'draft' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'Finished thought.', get_post_meta( $post_id, '_daymark_caption', true ) );
		$this->assertStringContainsString( 'Finished thought.', get_post( $post_id )->post_content );
		$this->assertSame( 'not_attempted', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/** Publishing via the update endpoint runs the deferred syndication. */
	public function test_update_to_publish_syndicates() {
		$post_id = $this->save_draft_with_target();

		$request = new WP_REST_Request( 'POST', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'Ready now.' );
		$request->set_param( 'targets', array( 'bluesky' ) );
		$request->set_param( 'status', 'publish' );
		rest_do_request( $request );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$external = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$this->assertArrayHasKey( 'bluesky', (array) $external, 'Publishing an edited draft must run its targets' );
	}

	/** Another author cannot read or update someone else's draft. */
	public function test_edit_endpoints_respect_per_post_capability() {
		$post_id = $this->save_draft_with_target();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		foreach ( array( 'GET', 'POST' ) as $method ) {
			$request = new WP_REST_Request( $method, "/daymark/v1/marks/{$post_id}" );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$this->assertSame( 403, rest_do_request( $request )->get_status(), "{$method} must be forbidden cross-author" );
		}
	}

	/** Requesting publish without the capability still degrades to draft. */
	public function test_publish_request_without_capability_stays_draft() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption' => 'Contributor daymark',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}
}
