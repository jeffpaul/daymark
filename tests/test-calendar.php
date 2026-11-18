<?php
/**
 * The Me screen's "Your days" calendar: which days of a month the site
 * published on, and one day's posts.
 *
 * @package Daymark
 */

/**
 * Daymark_Calendar and its two REST routes.
 */
class Test_Calendar extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	/**
	 * A published post at a given time on the site's clock.
	 *
	 * @param string               $date  'Y-m-d H:i:s', site time.
	 * @param array<string, mixed> $extra Extra post fields.
	 * @return int Post ID.
	 */
	private function post_on( string $date, array $extra = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_status'   => 'publish',
					'post_date'     => $date,
					'post_date_gmt' => get_gmt_from_date( $date ),
				),
				$extra
			)
		);
	}

	/**
	 * A REST GET carrying a valid nonce.
	 *
	 * @param string $route Route.
	 * @return WP_REST_Request
	 */
	private function rest_get( string $route ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * An image attachment set as a post's featured image.
	 *
	 * @param int $post_id The post.
	 * @return int Attachment ID.
	 */
	private function give_featured_image( int $post_id ): int {
		$attachment_id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}

	/** A month lists only its own days, each with a count. */
	public function test_month_counts_posts_per_day(): void {
		$this->post_on( '2025-03-04 09:00:00' );
		$this->post_on( '2025-03-04 18:30:00' );
		$this->post_on( '2025-03-20 12:00:00' );
		$this->post_on( '2025-02-28 23:59:59' );
		$this->post_on( '2025-04-01 00:00:00' );

		$month = Daymark_Calendar::month( '2025-03' );

		$this->assertSame( '2025-03', $month['month'] );
		$this->assertSame( 3, $month['total'] );
		$this->assertSame(
			array(
				'2025-03-04' => 2,
				'2025-03-20' => 1,
			),
			wp_list_pluck( $month['days'], 'count', 'date' )
		);
	}

	/** The all-time count and the first month cover every month. */
	public function test_month_reports_all_time_count_and_first_month(): void {
		$this->post_on( '2023-11-09 08:00:00' );
		$this->post_on( '2025-03-04 09:00:00' );
		$this->post_on( '2025-03-05 09:00:00' );

		$month = Daymark_Calendar::month( '2025-03' );

		$this->assertSame( 3, $month['all_time'] );
		$this->assertSame( '2023-11', $month['first_month'] );
	}

	/** A site with nothing published has no days and no first month. */
	public function test_month_with_no_posts_is_empty(): void {
		$month = Daymark_Calendar::month( '2025-03' );

		$this->assertSame( array(), $month['days'] );
		$this->assertSame( 0, $month['total'] );
		$this->assertSame( 0, $month['all_time'] );
		$this->assertSame( '', $month['first_month'] );
	}

	/** Drafts, scheduled Marks, pages, and Like Marks are not a day's content. */
	public function test_month_leaves_out_what_the_timeline_leaves_out(): void {
		$this->post_on( '2025-03-04 09:00:00' );
		$this->post_on( '2025-03-05 09:00:00', array( 'post_status' => 'draft' ) );
		$this->post_on( '2025-03-06 09:00:00', array( 'post_status' => 'private' ) );
		$this->post_on( '2025-03-07 09:00:00', array( 'post_type' => 'page' ) );
		$like = $this->post_on( '2025-03-08 09:00:00' );
		update_post_meta( $like, '_daymark_like_of', 'https://example.com/some-post/' );

		$month = Daymark_Calendar::month( '2025-03' );

		$this->assertSame( array( '2025-03-04' ), wp_list_pluck( $month['days'], 'date' ) );
		$this->assertSame( 1, $month['all_time'] );
	}

	/** A day is the site's own calendar day, not the UTC one. */
	public function test_days_follow_the_sites_timezone(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		// 11:30 PM on March 4 in Chicago is already March 5 in UTC.
		$late = $this->post_on( '2025-03-04 23:30:00' );

		$this->assertSame( '2025-03-05', substr( (string) get_post_field( 'post_date_gmt', $late ), 0, 10 ) );
		$this->assertSame( array( '2025-03-04' ), wp_list_pluck( Daymark_Calendar::month( '2025-03' )['days'], 'date' ) );
		$this->assertSame( array( $late ), Daymark_Calendar::day_post_ids( '2025-03-04' ) );
		$this->assertSame( array(), Daymark_Calendar::day_post_ids( '2025-03-05' ) );
	}

	/** A day shows the picture of a post that has one. */
	public function test_day_carries_a_thumbnail_when_a_post_has_an_image(): void {
		$this->post_on( '2025-03-04 09:00:00' );
		$with_image = $this->post_on( '2025-03-04 10:00:00' );
		$attachment = $this->give_featured_image( $with_image );
		$this->post_on( '2025-03-05 09:00:00' );

		$days = wp_list_pluck( Daymark_Calendar::month( '2025-03' )['days'], 'thumbnail', 'date' );

		$this->assertSame( wp_get_attachment_image_url( $attachment, 'thumbnail' ), $days['2025-03-04'] );
		$this->assertSame( '', $days['2025-03-05'] );
	}

	/** A Mark's first photo stands in when it has no featured image. */
	public function test_day_thumbnail_falls_back_to_the_marks_first_photo(): void {
		$post_id    = $this->post_on( '2025-03-04 09:00:00' );
		$attachment = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		update_post_meta( $post_id, '_daymark_media_ids', wp_json_encode( array( $attachment ) ) );

		$days = Daymark_Calendar::month( '2025-03' )['days'];

		$this->assertSame( wp_get_attachment_image_url( $attachment, 'thumbnail' ), $days[0]['thumbnail'] );
	}

	/** A password-protected post counts, but its picture stays hidden. */
	public function test_password_protected_post_gives_no_thumbnail(): void {
		$post_id = $this->post_on( '2025-03-04 09:00:00', array( 'post_password' => 'secret' ) );
		$this->give_featured_image( $post_id );

		$days = Daymark_Calendar::month( '2025-03' )['days'];

		$this->assertSame( 1, $days[0]['count'] );
		$this->assertSame( '', $days[0]['thumbnail'] );
	}

	/** A value that isn't a month means the current month. */
	public function test_month_defaults_to_the_current_month(): void {
		$this->assertSame( wp_date( 'Y-m' ), Daymark_Calendar::month()['month'] );
		$this->assertSame( wp_date( 'Y-m' ), Daymark_Calendar::month( '2025-13' )['month'] );
		$this->assertSame( wp_date( 'Y-m-d' ), Daymark_Calendar::month()['today'] );
	}

	/** The week starts on the day the site's settings say. */
	public function test_month_reports_the_sites_first_day_of_the_week(): void {
		update_option( 'start_of_week', 1 );

		$this->assertSame( 1, Daymark_Calendar::month( '2025-03' )['week_starts_on'] );
	}

	/** One day's posts come back newest first. */
	public function test_day_post_ids_are_newest_first(): void {
		$morning = $this->post_on( '2025-03-04 09:00:00' );
		$evening = $this->post_on( '2025-03-04 18:30:00' );
		$this->post_on( '2025-03-05 09:00:00' );

		$this->assertSame( array( $evening, $morning ), Daymark_Calendar::day_post_ids( '2025-03-04' ) );
		$this->assertSame( array(), Daymark_Calendar::day_post_ids( '2025-02-30' ) );
		$this->assertSame( array(), Daymark_Calendar::day_post_ids( 'yesterday' ) );
	}

	/** GET /calendar answers with the month asked for. */
	public function test_rest_month_route(): void {
		$this->post_on( '2025-03-04 09:00:00' );

		$request = $this->rest_get( '/daymark/v1/calendar' );
		$request->set_param( 'month', '2025-03' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '2025-03', $data['month'] );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( '2025-03-04', $data['days'][0]['date'] );
	}

	/** GET /calendar refuses a month that isn't one. */
	public function test_rest_month_route_rejects_a_bad_month(): void {
		$request = $this->rest_get( '/daymark/v1/calendar' );
		$request->set_param( 'month', '2025-3' );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/** GET /calendar/{day} lists that day's posts as Timeline items. */
	public function test_rest_day_route_lists_the_days_posts(): void {
		$morning = $this->post_on( '2025-03-04 09:00:00', array( 'post_title' => 'Morning walk' ) );
		$evening = $this->post_on( '2025-03-04 18:30:00', array( 'post_title' => 'Evening light' ) );
		$this->post_on( '2025-03-05 09:00:00' );

		$response = rest_get_server()->dispatch( $this->rest_get( '/daymark/v1/calendar/2025-03-04' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( $evening, $morning ), wp_list_pluck( $data, 'id' ) );
		$this->assertSame( 'mark', $data[0]['item_type'] );
		$this->assertSame( 'publish', $data[0]['status'] );
	}

	/** GET /calendar/{day} refuses a day that doesn't exist. */
	public function test_rest_day_route_rejects_an_impossible_day(): void {
		$this->assertSame( 400, rest_get_server()->dispatch( $this->rest_get( '/daymark/v1/calendar/2025-02-30' ) )->get_status() );
	}

	/** Both routes need a signed-in person who can write posts, and a nonce. */
	public function test_rest_routes_require_a_nonce_and_edit_posts(): void {
		$no_nonce = new WP_REST_Request( 'GET', '/daymark/v1/calendar' );
		$this->assertSame( 403, rest_get_server()->dispatch( $no_nonce )->get_status() );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, rest_get_server()->dispatch( $this->rest_get( '/daymark/v1/calendar' ) )->get_status() );
		$this->assertSame( 403, rest_get_server()->dispatch( $this->rest_get( '/daymark/v1/calendar/2025-03-04' ) )->get_status() );
	}
}
