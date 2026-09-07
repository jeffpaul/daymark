<?php
/**
 * REST tests for GET /daymark/v1/marks/{id}'s `external_posts` field —
 * per-Mark routing transparency (issue #255): "where did this go."
 *
 * @package Daymark
 */

/**
 * Exercises the per-connector routing detail GET /marks/{id} exposes.
 */
class Test_Rest_Mark_Routing extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Request
	 */
	private function request_for( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** A successfully mocked target's own stored detail comes through as-is. */
	public function test_external_posts_reflects_successful_target() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'             => 'Routing success test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$data = rest_do_request( $this->request_for( $post_id ) )->get_data();

		$this->assertArrayHasKey( 'bluesky', $data['external_posts'] );
		$this->assertSame( 'mocked', $data['external_posts']['bluesky']['status'] );
		$this->assertNotEmpty( $data['external_posts']['bluesky']['label'] );
		$this->assertNotEmpty( $data['external_posts']['bluesky']['external_url'] );
	}

	/**
	 * A target that can't represent this Mark's type (YouTube for a note)
	 * still shows up in external_posts — the whole point of issue #255's
	 * data-model fix (Daymark_Syndication_Registry::store_results()) — not
	 * silently absent the way it used to be.
	 */
	public function test_external_posts_reflects_unsupported_target() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'             => 'Routing failure test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'youtube' ),
			)
		);

		$data = rest_do_request( $this->request_for( $post_id ) )->get_data();

		$this->assertArrayHasKey( 'youtube', $data['external_posts'] );
		$this->assertSame( 'unsupported', $data['external_posts']['youtube']['status'] );
		$this->assertSame( '', $data['external_posts']['youtube']['external_url'] );
	}

	/**
	 * A target selected but with no stored external_posts entry at all — a
	 * Mark published before issue #255's data-model fix shipped — still
	 * gets a row, live-resolved, rather than silently vanishing from the
	 * response.
	 */
	public function test_external_posts_falls_back_for_legacy_target_with_no_stored_entry() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'             => 'Legacy target test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		// Simulate a Mark published before store_results() recorded every
		// attempt: its own _daymark_external_posts never got an entry.
		update_post_meta( $post_id, '_daymark_external_posts', wp_json_encode( (object) array() ) );

		$data = rest_do_request( $this->request_for( $post_id ) )->get_data();

		$this->assertArrayHasKey( 'bluesky', $data['external_posts'] );
		$this->assertSame( 'unknown', $data['external_posts']['bluesky']['status'] );
		$this->assertSame( 'Bluesky', $data['external_posts']['bluesky']['label'], 'Falls back to the connector\'s own live label, not the raw ID' );
	}

	/**
	 * A `backflow_supported` target's sync recency (issue #258) appears
	 * once a check has actually run for it.
	 */
	public function test_external_posts_includes_sync_recency_for_backflow_supported_target() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'             => 'Sync recency REST test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		// Simulate a real connector's own reference, matching
		// Test_Backflow_Sync::create_syndicated_daymark( true ).
		update_post_meta(
			$post_id,
			'_daymark_external_posts',
			wp_json_encode(
				array(
					'bluesky' => array(
						'external_id'        => 'at://did:plc:x/app.bsky.feed.post/' . $post_id,
						'external_url'       => 'https://bsky.app/profile/demo/post/' . $post_id,
						'label'              => 'Bluesky',
						'status'             => 'published',
						'backflow_supported' => true,
					),
				)
			)
		);

		$before_sync = rest_do_request( $this->request_for( $post_id ) )->get_data();
		$this->assertSame( '', $before_sync['external_posts']['bluesky']['backflow_last_synced_at'], 'Not checked yet' );

		$handled_filter = static function () {
			return array(); // A real connector handled it: no comments imported.
		};
		add_filter( 'daymark_import_network_responses', $handled_filter );
		( new Daymark_Notifications() )->import_responses( $post_id, array( 'bluesky' ) );
		remove_filter( 'daymark_import_network_responses', $handled_filter );

		$after_sync = rest_do_request( $this->request_for( $post_id ) )->get_data();
		$this->assertNotSame( '', $after_sync['external_posts']['bluesky']['backflow_last_synced_at'] );
	}

	/**
	 * A mocked (non-`backflow_supported`) target never reports a sync
	 * recency — its replies aren't checked from a live source, so surfacing
	 * one would be misleading, even after a mock sync has run.
	 */
	public function test_external_posts_omits_sync_recency_for_mocked_target() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'             => 'Mock target no recency test',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		( new Daymark_Notifications() )->import_responses( $post_id, array( 'bluesky' ) );

		$data = rest_do_request( $this->request_for( $post_id ) )->get_data();
		$this->assertFalse( $data['external_posts']['bluesky']['backflow_supported'] );
		$this->assertSame( '', $data['external_posts']['bluesky']['backflow_last_synced_at'] );
	}

	/** A Mark with no syndication targets reports none — nothing to route. */
	public function test_external_posts_empty_when_no_targets() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'No targets test',
				'primary_type' => 'note',
			)
		);

		$data = rest_do_request( $this->request_for( $post_id ) )->get_data();

		$this->assertSame( array(), $data['targets'] );
		$this->assertSame( array(), $data['external_posts'] );
	}
}
