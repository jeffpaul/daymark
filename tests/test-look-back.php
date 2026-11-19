<?php
/**
 * Explore's "Look back" strip: a few of the site's own posts from about
 * this time in earlier years, six months ago, and one month ago.
 *
 * @package Daymark
 */

/**
 * Daymark_Look_Back and its REST route.
 */
class Test_Look_Back extends WP_UnitTestCase {

	/**
	 * The day every test treats as today, on the site's clock.
	 */
	private const TODAY = '2026-11-18';

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
	 * An image attachment set as a post's featured image.
	 *
	 * @param int $post_id The post.
	 */
	private function give_featured_image( int $post_id ): void {
		$attachment_id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		set_post_thumbnail( $post_id, $attachment_id );
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
	 * Each tile as "kind:amount:post_id", in the order returned.
	 *
	 * @param string $today 'Y-m-d'.
	 * @return string[]
	 */
	private function tiles( string $today = self::TODAY ): array {
		return array_map(
			static function ( array $tile ): string {
				return $tile['kind'] . ':' . $tile['amount'] . ':' . $tile['post_id'];
			},
			Daymark_Look_Back::tiles( $today )
		);
	}

	/** Posts from this date in earlier years come first, the latest year first. */
	public function test_this_day_in_earlier_years_newest_year_first(): void {
		$three_years = $this->post_on( '2023-11-18 08:00:00' );
		$one_year    = $this->post_on( '2025-11-18 21:15:00' );

		$this->assertSame(
			array( "years:1:{$one_year}", "years:3:{$three_years}" ),
			$this->tiles()
		);
	}

	/** A tile says which year its post is from. */
	public function test_tile_carries_the_posts_year(): void {
		$this->post_on( '2023-11-18 08:00:00' );

		$this->assertSame( 2023, Daymark_Look_Back::tiles( self::TODAY )[0]['year'] );
	}

	/** Today's own posts, and anything dated later, are not a look back. */
	public function test_today_and_later_are_left_out(): void {
		$this->post_on( '2026-11-18 07:00:00' );
		$this->post_on( '2026-11-17 07:00:00' );
		$this->post_on( '2026-11-25 07:00:00' );

		$this->assertSame( array(), $this->tiles() );
	}

	/** With nothing on the exact date, a year offers one post from within three days of it. */
	public function test_year_without_the_exact_day_offers_that_week(): void {
		$near = $this->post_on( '2024-11-16 10:00:00' );

		$this->assertSame( array( "week:2:{$near}" ), $this->tiles() );
	}

	/** Three days either side is the limit of "this week". */
	public function test_week_reaches_three_days_and_no_further(): void {
		$this->post_on( '2024-11-14 23:59:59' );
		$edge = $this->post_on( '2024-11-21 23:59:59' );
		$this->post_on( '2024-11-22 00:00:00' );

		$this->assertSame( array( "week:2:{$edge}" ), $this->tiles() );
	}

	/** A year that has the exact date does not also add a nearby post. */
	public function test_year_with_the_exact_day_skips_its_week(): void {
		$exact = $this->post_on( '2025-11-18 09:00:00' );
		$this->post_on( '2025-11-17 09:00:00' );
		$this->post_on( '2025-11-19 09:00:00' );

		$this->assertSame( array( "years:1:{$exact}" ), $this->tiles() );
	}

	/** A busy day shows its three latest posts, newest first, and no more. */
	public function test_exact_day_shows_at_most_three_posts(): void {
		$this->post_on( '2025-11-18 08:00:00' );
		$second = $this->post_on( '2025-11-18 09:00:00' );
		$third  = $this->post_on( '2025-11-18 10:00:00' );
		$fourth = $this->post_on( '2025-11-18 11:00:00' );

		$this->assertSame(
			array( "years:1:{$fourth}", "years:1:{$third}", "years:1:{$second}" ),
			$this->tiles()
		);
	}

	/** "This week" picks a post with a picture over a nearer one without. */
	public function test_week_prefers_a_post_with_a_picture(): void {
		$this->post_on( '2024-11-17 10:00:00' );
		$pictured = $this->post_on( '2024-11-20 10:00:00' );
		$this->give_featured_image( $pictured );

		$this->assertSame( array( "week:2:{$pictured}" ), $this->tiles() );
	}

	/** With no picture to choose by, "this week" picks the nearest day. */
	public function test_week_otherwise_prefers_the_nearest_day(): void {
		$this->post_on( '2024-11-15 10:00:00' );
		$nearest = $this->post_on( '2024-11-19 10:00:00' );

		$this->assertSame( array( "week:2:{$nearest}" ), $this->tiles() );
	}

	/** Six months ago and one month ago each add one post, after the years. */
	public function test_six_months_and_one_month_ago_follow_the_years(): void {
		$one_month  = $this->post_on( '2026-10-18 12:00:00' );
		$six_months = $this->post_on( '2026-05-18 12:00:00' );
		$one_year   = $this->post_on( '2025-11-18 12:00:00' );

		$this->assertSame(
			array( "years:1:{$one_year}", "months:6:{$six_months}", "months:1:{$one_month}" ),
			$this->tiles()
		);
	}

	/** A month tile takes the exact date when there is one, else the nearest within three days. */
	public function test_months_prefer_the_exact_date_then_the_nearest(): void {
		$this->post_on( '2026-10-16 12:00:00' );
		$exact = $this->post_on( '2026-10-18 12:00:00' );
		$this->post_on( '2026-05-21 12:00:00' );
		$near = $this->post_on( '2026-05-20 12:00:00' );

		$this->assertSame( array( "months:6:{$near}", "months:1:{$exact}" ), $this->tiles() );
	}

	/** On the 31st, "one month ago" is the last day of a shorter month. */
	public function test_month_ago_from_the_31st_is_the_months_last_day(): void {
		$last_day = $this->post_on( '2026-02-28 12:00:00' );

		$this->assertSame( array( "months:1:{$last_day}" ), $this->tiles( '2026-03-31' ) );
	}

	/** On Feb 29, an ordinary year's Feb 28 is "this week", and a leap year's Feb 29 is the day itself. */
	public function test_leap_day(): void {
		$feb_28   = $this->post_on( '2023-02-28 12:00:00' );
		$leap_day = $this->post_on( '2020-02-29 12:00:00' );

		$this->assertSame(
			array( "week:1:{$feb_28}", "years:4:{$leap_day}" ),
			$this->tiles( '2024-02-29' )
		);
	}

	/** Drafts, private posts, pages, and Like Marks are never a look back. */
	public function test_leaves_out_what_the_timeline_leaves_out(): void {
		$shown = $this->post_on( '2025-11-18 09:00:00' );
		$this->post_on( '2024-11-18 09:00:00', array( 'post_status' => 'draft' ) );
		$this->post_on( '2023-11-18 09:00:00', array( 'post_status' => 'private' ) );
		$this->post_on( '2022-11-18 09:00:00', array( 'post_type' => 'page' ) );
		$like = $this->post_on( '2021-11-18 09:00:00' );
		update_post_meta( $like, '_daymark_like_of', 'https://example.com/some-post/' );

		$this->assertSame( array( "years:1:{$shown}" ), $this->tiles() );
	}

	/** The day is the site's own calendar day, not the UTC one. */
	public function test_days_follow_the_sites_timezone(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		// 11:30 PM on Nov 18 in Chicago is already Nov 19 in UTC.
		$late = $this->post_on( '2025-11-18 23:30:00' );

		$this->assertSame( array( "years:1:{$late}" ), $this->tiles() );
	}

	/** A long history is cut to the strip's limit, keeping the latest years. */
	public function test_strip_is_capped(): void {
		for ( $year = 2025; $year >= 2005; $year-- ) {
			$this->post_on( $year . '-11-18 09:00:00' );
		}

		$tiles = Daymark_Look_Back::tiles( self::TODAY );

		$this->assertCount( Daymark_Look_Back::MAX_TILES, $tiles );
		$this->assertSame( 1, $tiles[0]['amount'] );
	}

	/** With no day given, today is the site's own date. */
	public function test_defaults_to_the_sites_today(): void {
		$today    = new DateTimeImmutable( wp_date( 'Y-m-d' ) . ' 12:00:00' );
		$year_ago = $this->post_on( $today->modify( '-1 year' )->format( 'Y-m-d H:i:s' ) );

		$this->assertContains( $year_ago, wp_list_pluck( Daymark_Look_Back::tiles(), 'post_id' ) );
	}

	/** GET /look-back answers with Timeline-shaped posts, each saying why it is there. */
	public function test_rest_returns_posts_with_their_reason(): void {
		$today    = new DateTimeImmutable( wp_date( 'Y-m-d' ) . ' 12:00:00' );
		$year_ago = $this->post_on(
			$today->modify( '-1 year' )->format( 'Y-m-d H:i:s' ),
			array( 'post_title' => 'A year back' )
		);

		$response = rest_do_request( $this->rest_get( '/daymark/v1/look-back' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'mark', $data[0]['item_type'] );
		$this->assertSame( $year_ago, $data[0]['id'] );
		$this->assertSame( array( 'kind', 'amount', 'year' ), array_keys( $data[0]['look_back'] ) );
		// "week" only when the suite runs on a Feb 29, whose year ago is a Feb 28.
		$this->assertContains( $data[0]['look_back']['kind'], array( 'years', 'week' ) );
		$this->assertSame( 1, $data[0]['look_back']['amount'] );
		$this->assertSame( (int) $today->modify( '-1 year' )->format( 'Y' ), $data[0]['look_back']['year'] );
	}

	/** GET /look-back is for signed-in people who can post. */
	public function test_rest_refuses_a_visitor(): void {
		$this->post_on( '2025-11-18 09:00:00' );
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/daymark/v1/look-back' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
