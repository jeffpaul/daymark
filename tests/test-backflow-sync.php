<?php
/**
 * Automatic backflow sync tests.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Backflow_Sync scheduling and sync behavior.
 */
class Test_Backflow_Sync extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		delete_transient( 'daymark_backflow_freshened' );
	}

	/**
	 * Create a published Mark with an external post reference.
	 *
	 * @param bool $backflow_supported Whether the reference is a real connector's.
	 * @return int Post ID.
	 */
	private function create_syndicated_daymark( bool $backflow_supported ): int {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );
		update_post_meta( $post_id, '_daymark_comment_backflow_enabled', '1' );
		update_post_meta(
			$post_id,
			'_daymark_external_posts',
			wp_json_encode(
				array(
					'bluesky' => array(
						'external_id'        => $backflow_supported ? 'at://did:plc:x/app.bsky.feed.post/' . $post_id : 'mock-bsky-' . $post_id,
						'external_url'       => 'https://bsky.app/profile/demo/post/' . $post_id,
						'label'              => 'Bluesky',
						'status'             => $backflow_supported ? 'published' : 'mocked',
						'backflow_supported' => $backflow_supported,
					),
				)
			)
		);

		return $post_id;
	}

	/** The recurring schedule is created and cleared. */
	public function test_schedule_and_unschedule() {
		Daymark_Backflow_Sync::unschedule();
		$this->assertFalse( wp_next_scheduled( Daymark_Backflow_Sync::CRON_HOOK ) );

		Daymark_Backflow_Sync::schedule();
		$this->assertNotFalse( wp_next_scheduled( Daymark_Backflow_Sync::CRON_HOOK ) );

		Daymark_Backflow_Sync::unschedule();
		$this->assertFalse( wp_next_scheduled( Daymark_Backflow_Sync::CRON_HOOK ) );
	}

	/** Real syndicated Marks get synced; mock-only Marks are skipped. */
	public function test_sync_targets_real_references_only() {
		$real_id = $this->create_syndicated_daymark( true );
		$mock_id = $this->create_syndicated_daymark( false );

		$synced = array();
		add_filter(
			'daymark_import_network_responses',
			function ( $handled, $post_id, $network ) use ( &$synced ) {
				$synced[] = array( (int) $post_id, $network );

				return array(); // Handled: no comments imported.
			},
			10,
			3
		);

		$sync = new Daymark_Backflow_Sync();
		$sync->sync_recent_marks();

		$this->assertContains( array( $real_id, 'bluesky' ), $synced );

		foreach ( $synced as $call ) {
			$this->assertNotSame( $mock_id, $call[0], 'Mock-only Marks must not auto-sync.' );
		}
	}

	/** The per-post cooldown prevents immediate re-polling. */
	public function test_per_post_cooldown() {
		$post_id = $this->create_syndicated_daymark( true );

		$calls = 0;
		add_filter(
			'daymark_import_network_responses',
			function ( $handled ) use ( &$calls ) {
				unset( $handled );
				++$calls;

				return array();
			}
		);

		$sync = new Daymark_Backflow_Sync();
		$sync->sync_recent_marks();
		$sync->sync_recent_marks();

		$this->assertSame( 1, $calls, 'Second sync within the cooldown must skip the post.' );
	}

	/** The cooldown lock is acquired atomically: only one caller wins. */
	public function test_mark_cooldown_is_atomic() {
		$post_id = $this->create_syndicated_daymark( true );
		$sync    = new Daymark_Backflow_Sync();

		$this->assertTrue( $sync->mark_cooldown( $post_id ), 'First acquire wins the lock' );
		$this->assertFalse( $sync->mark_cooldown( $post_id ), 'Second acquire must fail' );
		$this->assertTrue( $sync->on_cooldown( $post_id ) );
	}

	/** Manual sync of a real reference honors the shared cooldown (429). */
	public function test_rest_sync_real_reference_honors_cooldown() {
		$post_id = $this->create_syndicated_daymark( true );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => get_current_user_id(),
			)
		);

		( new Daymark_Backflow_Sync() )->mark_cooldown( $post_id );

		$request = new WP_REST_Request( 'POST', "/daymark/v1/marks/{$post_id}/sync-responses" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'networks', array( 'bluesky' ) );

		$this->assertSame( 429, rest_do_request( $request )->get_status() );
	}

	/** Mock demo syncs stay instant: the cooldown is never enforced for them. */
	public function test_rest_sync_mock_reference_ignores_cooldown() {
		$post_id = $this->create_syndicated_daymark( false );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => get_current_user_id(),
			)
		);

		( new Daymark_Backflow_Sync() )->mark_cooldown( $post_id );

		$request = new WP_REST_Request( 'POST', "/daymark/v1/marks/{$post_id}/sync-responses" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'networks', array( 'bluesky' ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	/** Viewing notifications schedules one async freshen per window. */
	public function test_maybe_freshen_schedules_once() {
		$sync = new Daymark_Backflow_Sync();

		$this->assertFalse( wp_next_scheduled( Daymark_Backflow_Sync::CRON_HOOK . '_now' ) );

		$sync->maybe_freshen();
		$first = wp_next_scheduled( Daymark_Backflow_Sync::CRON_HOOK . '_now' );
		$this->assertNotFalse( $first );

		// Within the freshness window a second view is a no-op.
		wp_unschedule_event( $first, Daymark_Backflow_Sync::CRON_HOOK . '_now' );
		$sync->maybe_freshen();
		$this->assertFalse( wp_next_scheduled( Daymark_Backflow_Sync::CRON_HOOK . '_now' ) );
	}

	/** The notifications endpoint triggers the freshen path. */
	public function test_notifications_endpoint_freshens() {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/notifications' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		rest_do_request( $request );

		$this->assertNotFalse( get_transient( 'daymark_backflow_freshened' ) );
	}

	/**
	 * Report reaction counts for every synced network.
	 *
	 * @param array<string, int> $counts Counts to report.
	 * @return callable The filter callback, so the test can remove it.
	 */
	private function report_reactions( array $counts ): callable {
		$callback = static function () use ( $counts ) {
			return $counts;
		};

		add_filter( 'daymark_import_network_reactions', $callback );

		return $callback;
	}

	/** A connector's reported counts are added to the Timeline counts. */
	public function test_reported_reactions_add_to_timeline_counts() {
		$post_id = $this->create_syndicated_daymark( true );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => get_current_user_id(),
			)
		);

		// One like already delivered as a comment by a federation plugin.
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'like',
				'comment_approved' => 1,
			)
		);

		add_filter( 'daymark_import_network_responses', '__return_empty_array' );
		$this->report_reactions(
			array(
				'likes'   => 4,
				'reposts' => 2,
			)
		);

		( new Daymark_Backflow_Sync() )->sync_recent_marks();

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 5, $data['like_count'] );
		$this->assertSame( 2, $data['repost_count'] );
	}

	/** A later sync replaces a network's counts instead of adding to them. */
	public function test_reported_reactions_replace_previous_counts() {
		$post_id = $this->create_syndicated_daymark( true );

		Daymark_Backflow_Sync::store_reactions(
			$post_id,
			'bluesky',
			array(
				'likes'   => 4,
				'reposts' => 2,
			)
		);
		Daymark_Backflow_Sync::store_reactions( $post_id, 'bluesky', array( 'likes' => 3 ) );

		$this->assertSame(
			array(
				'likes'   => 3,
				'reposts' => 2,
			),
			Daymark_Backflow_Sync::reaction_totals( $post_id ),
			'An unreported count keeps its previous value.'
		);
	}

	/** Counts from several networks are summed. */
	public function test_reaction_totals_sum_networks() {
		$post_id = $this->create_syndicated_daymark( true );

		Daymark_Backflow_Sync::store_reactions( $post_id, 'bluesky', array( 'likes' => 4 ) );
		Daymark_Backflow_Sync::store_reactions(
			$post_id,
			'mastodon',
			array(
				'likes'   => 1,
				'reposts' => 6,
			)
		);

		$this->assertSame(
			array(
				'likes'   => 5,
				'reposts' => 6,
			),
			Daymark_Backflow_Sync::reaction_totals( $post_id )
		);
	}

	/** Bad values are clamped or ignored, never stored as-is. */
	public function test_store_reactions_rejects_bad_values() {
		$post_id = $this->create_syndicated_daymark( true );

		$this->assertFalse( Daymark_Backflow_Sync::store_reactions( $post_id, 'bluesky', array( 'likes' => 'lots' ) ) );
		$this->assertFalse( Daymark_Backflow_Sync::store_reactions( $post_id, '', array( 'likes' => 1 ) ) );
		$this->assertTrue( Daymark_Backflow_Sync::store_reactions( $post_id, 'bluesky', array( 'likes' => -3 ) ) );

		$this->assertSame(
			array(
				'likes'   => 0,
				'reposts' => 0,
			),
			Daymark_Backflow_Sync::reaction_totals( $post_id )
		);
	}

	/** A Mark that was never synced has no reaction counts. */
	public function test_reaction_totals_default_to_zero() {
		$post_id = $this->create_syndicated_daymark( true );

		$this->assertSame(
			array(
				'likes'   => 0,
				'reposts' => 0,
			),
			Daymark_Backflow_Sync::reaction_totals( $post_id )
		);
	}

	/** A connector that reports nothing stores nothing. */
	public function test_unreported_reactions_store_nothing() {
		$post_id = $this->create_syndicated_daymark( true );
		add_filter( 'daymark_import_network_responses', '__return_empty_array' );

		( new Daymark_Backflow_Sync() )->sync_recent_marks();

		$this->assertSame( '', get_post_meta( $post_id, Daymark_Backflow_Sync::REACTIONS_META, true ) );
	}
}
