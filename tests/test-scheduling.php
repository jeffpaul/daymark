<?php
/**
 * Publish later: a Mark saved with a time in the future is held by
 * WordPress as a scheduled post and goes live, and to its destinations,
 * at that time.
 *
 * @package Daymark
 */

/**
 * Scheduling a Mark from the composer.
 */
class Test_Scheduling extends WP_UnitTestCase {

	/**
	 * The saving author.
	 *
	 * @var int
	 */
	private int $author;

	public function set_up(): void {
		parent::set_up();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	/**
	 * A time this many seconds from now, as the app sends it and as
	 * WordPress stores it.
	 *
	 * @param int $seconds Seconds from now (negative for the past).
	 * @return array{iso: string, mysql: string}
	 */
	private function at( int $seconds ): array {
		$timestamp = time() + $seconds;

		return array(
			'iso'   => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
			'mysql' => gmdate( 'Y-m-d H:i:s', $timestamp ),
		);
	}

	/**
	 * Publish a note through the publisher.
	 *
	 * @param array<string, mixed> $data Extra publisher input.
	 * @return int Post ID.
	 */
	private function publish( array $data ): int {
		$post_id = Daymark_Plugin::instance()->publisher->publish( array_merge( array( 'caption' => 'For tomorrow morning' ), $data ) );

		$this->assertIsInt( $post_id );

		return $post_id;
	}

	/**
	 * Save an existing Mark again.
	 *
	 * @param int                  $post_id The Mark.
	 * @param array<string, mixed> $data    Extra publisher input.
	 * @return void
	 */
	private function update( int $post_id, array $data ): void {
		$result = Daymark_Plugin::instance()->publisher->update( $post_id, array_merge( array( 'caption' => 'For tomorrow morning' ), $data ) );

		$this->assertSame( $post_id, $result );
	}

	/**
	 * A REST request carrying a valid nonce.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** Publish with a later time holds the Mark until then. */
	public function test_publish_with_a_later_time_schedules_the_mark(): void {
		$when    = $this->at( DAY_IN_SECONDS );
		$post_id = $this->publish(
			array(
				'publish_at'          => $when['iso'],
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $when['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
		// WordPress has it down to publish at that time.
		$this->assertSame( strtotime( $when['mysql'] . ' UTC' ), wp_next_scheduled( 'publish_future_post', array( $post_id ) ) );
		// Nothing is sent anywhere yet.
		$this->assertSame( 'not_attempted', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/** At the set time the Mark goes live and its destinations run, once. */
	public function test_scheduled_mark_goes_live_and_syndicates_at_its_time(): void {
		$post_id = $this->publish(
			array(
				'publish_at'          => $this->at( DAY_IN_SECONDS )['iso'],
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		// What WordPress's own scheduler does when the time comes. Nobody
		// is signed in then.
		wp_set_current_user( 0 );
		wp_publish_post( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertNotSame( 'not_attempted', get_post_meta( $post_id, '_daymark_syndication_status', true ) );
	}

	/** A time that has already passed publishes now. */
	public function test_a_time_already_past_publishes_now(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( -HOUR_IN_SECONDS )['iso'] ) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** A time that can't be read publishes now. */
	public function test_an_unreadable_time_publishes_now(): void {
		$post_id = $this->publish( array( 'publish_at' => 'next blue moon-ish' ) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** Save as draft with a time is still a draft. */
	public function test_a_draft_ignores_the_time(): void {
		$post_id = $this->publish(
			array(
				'status'     => 'draft',
				'publish_at' => $this->at( DAY_IN_SECONDS )['iso'],
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/** Someone who can't publish can't schedule either. */
	public function test_a_contributor_gets_a_draft(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/** When the photo was taken never moves the scheduled time. */
	public function test_capture_time_does_not_move_the_scheduled_time(): void {
		$when     = $this->at( DAY_IN_SECONDS );
		$captured = $this->at( -DAY_IN_SECONDS );
		$post_id  = $this->publish(
			array(
				'publish_at'  => $when['iso'],
				'captured_at' => $captured['iso'],
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $when['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
		$this->assertSame( $captured['mysql'], get_post_meta( $post_id, '_daymark_captured_at', true ) );

		// Nor does a later save of the scheduled Mark.
		$this->update(
			$post_id,
			array(
				'status'      => 'publish',
				'publish_at'  => $when['iso'],
				'captured_at' => $captured['iso'],
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $when['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
	}

	/** After it has gone out, an edit keeps the day it went out on. */
	public function test_an_edit_after_it_went_out_keeps_its_date(): void {
		global $wpdb;

		$captured = $this->at( -DAY_IN_SECONDS );
		$post_id  = $this->publish(
			array(
				'publish_at'  => $this->at( DAY_IN_SECONDS )['iso'],
				'captured_at' => $captured['iso'],
			)
		);

		// Time passes: the set time was an hour ago, and WordPress published it.
		$went_out = $this->at( -HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Moves the clock for the test.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => get_date_from_gmt( $went_out['mysql'] ),
				'post_date_gmt' => $went_out['mysql'],
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
		wp_publish_post( $post_id );

		$this->update(
			$post_id,
			array(
				'caption'     => 'For tomorrow morning, typo fixed',
				'captured_at' => $captured['iso'],
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( $went_out['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
	}

	/** A Mark that is already out can't be pulled back by a later time. */
	public function test_a_published_mark_is_never_scheduled(): void {
		$post_id = $this->publish( array() );

		$this->update(
			$post_id,
			array(
				'status'     => 'publish',
				'publish_at' => $this->at( DAY_IN_SECONDS )['iso'],
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** A Reblog goes out now, whatever time comes with it. */
	public function test_a_reblog_is_never_scheduled(): void {
		$post_id = $this->publish(
			array(
				'repost_of'  => 'https://example.org/2026/a-post/',
				'publish_at' => $this->at( DAY_IN_SECONDS )['iso'],
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** A draft can be scheduled. */
	public function test_a_draft_can_be_scheduled(): void {
		$post_id = $this->publish( array( 'status' => 'draft' ) );
		$when    = $this->at( 2 * DAY_IN_SECONDS );

		$this->update(
			$post_id,
			array(
				'status'     => 'publish',
				'publish_at' => $when['iso'],
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $when['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
	}

	/** A scheduled Mark's time can be changed. */
	public function test_the_time_can_be_changed(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );
		$later   = $this->at( 3 * DAY_IN_SECONDS );

		$this->update(
			$post_id,
			array(
				'status'     => 'publish',
				'publish_at' => $later['iso'],
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $later['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
		$this->assertSame( strtotime( $later['mysql'] . ' UTC' ), wp_next_scheduled( 'publish_future_post', array( $post_id ) ) );
	}

	/** The composer's own background saves leave a scheduled Mark scheduled. */
	public function test_an_autosave_keeps_a_scheduled_mark_scheduled(): void {
		$when    = $this->at( DAY_IN_SECONDS );
		$post_id = $this->publish( array( 'publish_at' => $when['iso'] ) );

		$this->update(
			$post_id,
			array(
				'caption'  => 'For tomorrow morning, reworded',
				'status'   => 'draft',
				'autosave' => true,
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $when['mysql'], get_post_field( 'post_date_gmt', $post_id ) );
		$this->assertSame( 'For tomorrow morning, reworded', get_post_meta( $post_id, '_daymark_caption', true ) );
	}

	/** Publish with no time on a scheduled Mark publishes it now. */
	public function test_publish_now_on_a_scheduled_mark(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		$this->update( $post_id, array( 'status' => 'publish' ) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertLessThanOrEqual( time(), strtotime( get_post_field( 'post_date_gmt', $post_id ) . ' UTC' ) );
		$this->assertFalse( wp_next_scheduled( 'publish_future_post', array( $post_id ) ) );
	}

	/** Save as draft takes a Mark off the schedule, and it can publish normally later. */
	public function test_save_as_draft_takes_a_mark_off_the_schedule(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		$this->update( $post_id, array( 'status' => 'draft' ) );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertFalse( wp_next_scheduled( 'publish_future_post', array( $post_id ) ) );

		// Published later with no time, it goes live then, not on the old schedule.
		$this->update( $post_id, array( 'status' => 'publish' ) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** POST /marks answers with the scheduled status and time. */
	public function test_rest_create_reports_the_schedule(): void {
		$when    = $this->at( DAY_IN_SECONDS );
		$request = $this->request( 'POST', '/daymark/v1/marks' );
		$request->set_body_params(
			array(
				'caption'    => 'Over the REST API',
				'status'     => 'publish',
				'publish_at' => $when['iso'],
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'future', $data['status'] );
		$this->assertSame( strtotime( $when['mysql'] . ' UTC' ), strtotime( $data['date_gmt'] ) );
		// A scheduled Mark is nobody's first published Mark yet.
		$this->assertFalse( $data['first_mark'] );
	}

	/** An autosave sent through the REST API keeps the schedule too. */
	public function test_rest_autosave_keeps_the_schedule(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		$request = $this->request( 'POST', '/daymark/v1/marks/' . $post_id );
		$request->set_body_params(
			array(
				'caption'  => 'Autosaved',
				'status'   => 'draft',
				'autosave' => '1',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'future', get_post_status( $post_id ) );
	}

	/** Scheduled Marks have their own list, and stay out of the others. */
	public function test_scheduled_marks_are_listed_on_request_only(): void {
		$scheduled = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );
		$draft     = $this->publish( array( 'status' => 'draft' ) );

		$ids = function ( string $query ): array {
			$request = $this->request( 'GET', '/daymark/v1/marks' );
			$request->set_query_params( wp_parse_args( $query ) );

			return wp_list_pluck( rest_get_server()->dispatch( $request )->get_data(), 'id' );
		};

		$this->assertSame( array( $scheduled ), $ids( 'status=future' ) );
		$this->assertSame( array( $draft ), $ids( 'status=draft' ) );
		$this->assertNotContains( $scheduled, $ids( '' ) );
	}

	/** Another author's scheduled Mark isn't listed for you. */
	public function test_someone_elses_scheduled_mark_is_not_listed(): void {
		$this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$request->set_query_params( array( 'status' => 'future' ) );

		$this->assertSame( array(), rest_get_server()->dispatch( $request )->get_data() );
	}

	/** POST /marks/{id}/unschedule makes a scheduled Mark a draft, and nothing else. */
	public function test_unschedule_makes_a_scheduled_mark_a_draft(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		$response = rest_get_server()->dispatch( $this->request( 'POST', '/daymark/v1/marks/' . $post_id . '/unschedule' ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertFalse( wp_next_scheduled( 'publish_future_post', array( $post_id ) ) );
		// Its words are untouched.
		$this->assertSame( 'For tomorrow morning', get_post_meta( $post_id, '_daymark_caption', true ) );

		// Published later, it goes live then.
		wp_publish_post( $post_id );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** Unschedule never unpublishes a published Mark. */
	public function test_unschedule_leaves_a_published_mark_alone(): void {
		$post_id = $this->publish( array() );

		$response = rest_get_server()->dispatch( $this->request( 'POST', '/daymark/v1/marks/' . $post_id . '/unschedule' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/** Only someone who can edit the Mark can take it off the schedule. */
	public function test_unschedule_needs_edit_rights(): void {
		$post_id = $this->publish( array( 'publish_at' => $this->at( DAY_IN_SECONDS )['iso'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$response = rest_get_server()->dispatch( $this->request( 'POST', '/daymark/v1/marks/' . $post_id . '/unschedule' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'future', get_post_status( $post_id ) );
	}
}
