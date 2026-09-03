<?php
/**
 * REST tests for GET /daymark/v1/timeline (issue #78): the merged,
 * date-sorted feed of the user's own Marks and cached
 * `daymark_subscription_post` entries.
 *
 * @package Daymark
 */

/**
 * Exercises the Timeline endpoint's merge/sort/pagination and item shape.
 */
class Test_Rest_Timeline extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author_a      = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriptions = new Daymark_Subscriptions();
	}

	/**
	 * Create a published Mark post with a given GMT post date.
	 *
	 * @param string $date_gmt     MySQL datetime (GMT), e.g. '2024-01-05 00:00:00'.
	 * @param string $title        Post title.
	 * @param string $primary_type _daymark_primary_type value.
	 * @return int
	 */
	private function create_mark( string $date_gmt, string $title = 'A Mark', string $primary_type = 'note' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->author_a,
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_content'  => 'Body copy for ' . $title,
				'post_date_gmt' => $date_gmt,
				'post_date'     => get_date_from_gmt( $date_gmt ),
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', $primary_type );

		return $post_id;
	}

	/**
	 * Create a subscription row.
	 *
	 * @param string $feed_url      Unique feed URL.
	 * @param string $site_icon_url Cached favicon URL.
	 * @return int
	 */
	private function create_subscription( string $feed_url, string $site_icon_url = '' ): int {
		$id = $this->subscriptions->create(
			array(
				'site_url'      => 'https://example.com/',
				'feed_url'      => $feed_url,
				'site_icon_url' => $site_icon_url,
			)
		);

		$this->assertIsInt( $id, 'Subscription row created' );

		return $id;
	}

	/**
	 * Create a cached `daymark_subscription_post`.
	 *
	 * @param int    $subscription_id Owning subscription ID.
	 * @param string $published_at    MySQL datetime (GMT) — the field the
	 *                                Timeline sorts subscription posts by.
	 * @param string $title           Post title.
	 * @param string $content_state   'full'|'excerpt_only'|'pruned'.
	 * @param string $post_format     'standard'|'image'|'video'|'audio'.
	 * @return int
	 */
	private function create_subscription_post( int $subscription_id, string $published_at, string $title = 'A Subscription Post', string $content_state = 'excerpt_only', string $post_format = 'standard' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => 'Excerpt for ' . $title,
			)
		);

		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', $published_at );
		update_post_meta( $post_id, 'permalink', 'https://example.com/' . sanitize_title( $title ) . '/' );
		update_post_meta( $post_id, 'author', 'Someone Else' );
		update_post_meta( $post_id, 'post_format', $post_format );
		update_post_meta( $post_id, 'content_state', $content_state );

		return $post_id;
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

	/**
	 * Marks and subscription posts are merged and sorted purely by date,
	 * even when a subscription post's true (published_at) date is newer
	 * than a Mark that was inserted into the DB more recently — proving the
	 * merge sorts on the right field rather than accidentally reflecting
	 * per-source query order.
	 */
	public function test_marks_and_subscription_posts_are_interleaved_and_sorted_by_date() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );

		// Deliberately out-of-insertion-order dates across both sources.
		$oldest_mark = $this->create_mark( '2024-01-01 00:00:00', 'Oldest Mark' );
		$newest_sub  = $this->create_subscription_post( $subscription_id, '2024-01-04 00:00:00', 'Newest Subscription Post' );
		$middle_mark = $this->create_mark( '2024-01-03 00:00:00', 'Middle Mark' );
		$oldest_sub  = $this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00', 'Older Subscription Post' );

		$request  = $this->request( 'GET', '/daymark/v1/timeline' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$items = $response->get_data();
		$ids   = array_column( $items, 'id' );

		$this->assertSame(
			array( $newest_sub, $middle_mark, $oldest_sub, $oldest_mark ),
			$ids,
			'Items are sorted purely by date across both sources, not grouped by source'
		);

		$this->assertSame( 'subscription_post', $items[0]['item_type'] );
		$this->assertSame( 'mark', $items[1]['item_type'] );
	}

	/** Every item carries an explicit item_type discriminator. */
	public function test_items_carry_item_type() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$mark_id         = $this->create_mark( '2024-01-01 00:00:00' );
		$sub_post_id     = $this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00' );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		$this->assertSame( 'mark', $by_id[ $mark_id ]['item_type'] );
		$this->assertSame( 'subscription_post', $by_id[ $sub_post_id ]['item_type'] );
	}

	/**
	 * A Mark's card has no other way to show its own caption text — its
	 * title is often just a timestamp fallback (see
	 * Daymark_Publisher::publish()) — so the Timeline endpoint has to
	 * expose the same 24-word-trimmed post_excerpt the publisher already
	 * stores, not just the title.
	 */
	public function test_mark_items_carry_their_excerpt() {
		wp_set_current_user( $this->author_a );

		$mark_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->author_a,
				'post_status'   => 'publish',
				'post_title'    => 'A Note Mark',
				'post_excerpt'  => 'A short caption for this Note Mark.',
				'post_date_gmt' => '2024-01-01 00:00:00',
				'post_date'     => get_date_from_gmt( '2024-01-01 00:00:00' ),
			)
		);
		update_post_meta( $mark_id, '_daymark_is_mark', '1' );
		update_post_meta( $mark_id, '_daymark_primary_type', 'note' );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$this->assertSame( $mark_id, $items[0]['id'] );
		$this->assertSame( 'A short caption for this Note Mark.', $items[0]['excerpt'] );
	}

	/**
	 * The Timeline's Marks query is deliberately NOT gated on
	 * _daymark_is_mark (see get_timeline()'s own docblock,
	 * class-rest-controller.php) — a site owner publishing some content
	 * through Daymark's composer and some directly in the block editor
	 * shouldn't mean half of it is invisible on their own Timeline.
	 */
	public function test_ordinary_block_editor_post_appears_on_timeline() {
		wp_set_current_user( $this->author_a );

		$ordinary_post_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->author_a,
				'post_status'   => 'publish',
				'post_title'    => 'An Ordinary Blog Post',
				'post_excerpt'  => 'Written straight in the block editor, never touching Daymark.',
				'post_date_gmt' => '2024-01-01 00:00:00',
				'post_date'     => get_date_from_gmt( '2024-01-01 00:00:00' ),
			)
		);
		// Deliberately no _daymark_is_mark / _daymark_primary_type meta —
		// this is the whole point of the test.

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$this->assertSame( $ordinary_post_id, $items[0]['id'] );
		$this->assertSame( 'mark', $items[0]['item_type'] );
		// No _daymark_primary_type meta means an empty type — the app
		// shell infers a reasonable card kind client-side from what it
		// actually has (thumbnail/excerpt) rather than the server
		// guessing one.
		$this->assertSame( '', $items[0]['type'] );
		$this->assertSame( 'Written straight in the block editor, never touching Daymark.', $items[0]['excerpt'] );
	}

	/**
	 * An ordinary post's own real WordPress post format is reported
	 * (defaulting to 'standard' when never set) so the app shell can tell
	 * a Standard post that merely carries a featured image apart from a
	 * real Image/Gallery/Video format — see resolveCardKind(), app.js.
	 * Without this, a Standard post with a featured image previously
	 * rendered with the same full-bleed banner treatment a real Image
	 * Mark gets, rather than a smaller thumbnail beside its title/excerpt.
	 */
	public function test_ordinary_post_reports_its_own_post_format() {
		wp_set_current_user( $this->author_a );

		$standard_post_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->author_a,
				'post_status'   => 'publish',
				'post_title'    => 'A Standard Post With A Featured Image',
				'post_date_gmt' => '2024-01-01 00:00:00',
				'post_date'     => get_date_from_gmt( '2024-01-01 00:00:00' ),
			)
		);

		$gallery_post_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->author_a,
				'post_status'   => 'publish',
				'post_title'    => 'A Real Gallery-Format Post',
				'post_date_gmt' => '2024-01-02 00:00:00',
				'post_date'     => get_date_from_gmt( '2024-01-02 00:00:00' ),
			)
		);
		set_post_format( $gallery_post_id, 'gallery' );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$this->assertSame( $gallery_post_id, $items[0]['id'] );
		$this->assertSame( 'gallery', $items[0]['post_format'] );
		$this->assertSame( $standard_post_id, $items[1]['id'] );
		$this->assertSame( 'standard', $items[1]['post_format'] );
	}

	/**
	 * The one exception to the above: narrowing to one specific
	 * _daymark_primary_type value only ever matches true Marks, since an
	 * ordinary block-editor post has no such meta to match against.
	 * Explore's "browse by type" and Search's type chips stay scoped to
	 * Daymark's own type vocabulary rather than guessing at arbitrary
	 * post content.
	 */
	public function test_type_filter_excludes_ordinary_block_editor_posts() {
		wp_set_current_user( $this->author_a );

		$image_mark_id = $this->create_mark( '2024-01-01 00:00:00', 'A Real Image Mark', 'image' );
		self::factory()->post->create(
			array(
				'post_author'   => $this->author_a,
				'post_status'   => 'publish',
				'post_title'    => 'An Ordinary Post With A Featured Image',
				'post_date_gmt' => '2024-01-02 00:00:00',
				'post_date'     => get_date_from_gmt( '2024-01-02 00:00:00' ),
			)
		);

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'type', 'image' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $image_mark_id ), $ids );
	}

	/**
	 * Pagination is correct across a page boundary that spans both sources:
	 * page 1 and page 2 together contain every item exactly once, in the
	 * right overall order, even though the boundary falls mid-merge.
	 */
	public function test_pagination_is_correct_across_a_boundary_spanning_both_sources() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );

		// 3 Marks and 3 subscription posts, interleaved by date, newest
		// first: sub(6), mark(5), sub(4), mark(3), sub(2), mark(1).
		$expected_order = array();

		for ( $day = 1; $day <= 6; $day++ ) {
			$date = sprintf( '2024-01-%02d 00:00:00', $day );

			if ( 0 === $day % 2 ) {
				$expected_order[] = $this->create_mark( $date, "Mark day {$day}" );
			} else {
				$expected_order[] = $this->create_subscription_post( $subscription_id, $date, "Sub post day {$day}" );
			}
		}

		$expected_order = array_reverse( $expected_order ); // Newest first.

		$page_1_request = $this->request( 'GET', '/daymark/v1/timeline' );
		$page_1_request->set_param( 'per_page', 4 );
		$page_1_request->set_param( 'page', 1 );
		$page_1_ids = array_column( rest_do_request( $page_1_request )->get_data(), 'id' );

		$page_2_request = $this->request( 'GET', '/daymark/v1/timeline' );
		$page_2_request->set_param( 'per_page', 4 );
		$page_2_request->set_param( 'page', 2 );
		$page_2_ids = array_column( rest_do_request( $page_2_request )->get_data(), 'id' );

		$this->assertSame( array_slice( $expected_order, 0, 4 ), $page_1_ids );
		$this->assertSame( array_slice( $expected_order, 4, 4 ), $page_2_ids );
	}

	/**
	 * An arbitrarily deep `page` value must not size either source query
	 * unbounded (`page * per_page` is capped at MAX_TIMELINE_QUERY_ITEMS) —
	 * it responds cleanly with an empty result past that depth rather than
	 * attempting a huge query.
	 */
	public function test_deep_pagination_is_capped_not_unbounded() {
		wp_set_current_user( $this->author_a );

		$this->create_mark( '2024-01-01 00:00:00' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'per_page', 50 );
		$request->set_param( 'page', 100000 );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data(), 'Beyond the cap, this is an empty result, not an error or a huge query' );
	}

	/** A pruned subscription post's item includes its subscription's site_icon_url. */
	public function test_pruned_subscription_post_includes_subscription_site_icon_url() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/', 'https://example.com/favicon.ico' );
		$post_id         = $this->create_subscription_post( $subscription_id, '2024-01-01 00:00:00', 'Pruned Post', 'pruned' );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$item = current( array_filter( $items, static fn( $i ) => $i['id'] === $post_id ) );

		$this->assertNotFalse( $item, 'The pruned post is present in the Timeline' );
		$this->assertSame( 'pruned', $item['content_state'] );
		$this->assertSame( 'https://example.com/favicon.ico', $item['site_icon_url'] );
	}

	/** Unauthenticated requests are rejected. */
	public function test_unauthenticated_request_is_rejected() {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/daymark/v1/timeline' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/** `s` matches a Mark's title. */
	public function test_search_matches_a_mark_title() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$matching_mark   = $this->create_mark( '2024-01-01 00:00:00', 'Unique Zebra Title' );
		$this->create_mark( '2024-01-02 00:00:00', 'Something Else Entirely' );
		$this->create_subscription_post( $subscription_id, '2024-01-03 00:00:00', 'Not A Match' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 's', 'Zebra' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $matching_mark ), $ids );
	}

	/** `s` matches a subscription post's title. */
	public function test_search_matches_a_subscription_post_title() {
		wp_set_current_user( $this->author_a );

		$subscription_id   = $this->create_subscription( 'https://example.com/feed/' );
		$matching_sub_post = $this->create_subscription_post( $subscription_id, '2024-01-01 00:00:00', 'Unique Narwhal Title' );
		$this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00', 'Something Else Entirely' );
		$this->create_mark( '2024-01-03 00:00:00', 'Not A Match' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 's', 'Narwhal' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $matching_sub_post ), $ids );
	}

	/** `type` scopes Marks to `_daymark_primary_type`. */
	public function test_type_scopes_marks_to_primary_type() {
		wp_set_current_user( $this->author_a );

		$image_mark = $this->create_mark( '2024-01-01 00:00:00', 'Image Mark', 'image' );
		$this->create_mark( '2024-01-02 00:00:00', 'Note Mark', 'note' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'type', 'image' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $image_mark ), $ids );
	}

	/** `type` scopes subscription posts to `post_format`. */
	public function test_type_scopes_subscription_posts_to_post_format() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$image_sub_post  = $this->create_subscription_post( $subscription_id, '2024-01-01 00:00:00', 'Image Sub Post', 'excerpt_only', 'image' );
		$this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00', 'Standard Sub Post', 'excerpt_only', 'standard' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'type', 'image' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $image_sub_post ), $ids );
	}

	/** `mine=1` excludes all subscription posts, even ones that wouldn't match a search term either way. */
	public function test_mine_excludes_all_subscription_posts() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$mark_id         = $this->create_mark( '2024-01-01 00:00:00', 'My Mark' );
		$this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00', 'Someone Else Entirely' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'mine', '1' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $mark_id ), $ids );
	}

	/** `subscription_id` excludes Marks and excludes posts from a different subscription. */
	public function test_subscription_id_scopes_to_one_subscription_and_excludes_marks() {
		wp_set_current_user( $this->author_a );

		$subscription_a = $this->create_subscription( 'https://example.com/feed-a/' );
		$subscription_b = $this->create_subscription( 'https://example.com/feed-b/' );

		$this->create_mark( '2024-01-01 00:00:00', 'A Mark' );
		$post_from_a = $this->create_subscription_post( $subscription_a, '2024-01-02 00:00:00', 'From Subscription A' );
		$this->create_subscription_post( $subscription_b, '2024-01-03 00:00:00', 'From Subscription B' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'subscription_id', $subscription_a );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $post_from_a ), $ids );
	}

	/** `mine=1` together with `subscription_id` set still behaves as `mine` (Marks only), per documented precedence. */
	public function test_mine_wins_over_subscription_id_when_both_are_set() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$mark_id         = $this->create_mark( '2024-01-01 00:00:00', 'A Mark' );
		$this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00', 'A Subscription Post' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$request->set_param( 'mine', '1' );
		$request->set_param( 'subscription_id', $subscription_id );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $mark_id ), $ids );
	}

	/** The existing "no filters" behavior still passes unchanged with the new params present but unset. */
	public function test_no_filters_still_returns_everything() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$mark_id         = $this->create_mark( '2024-01-01 00:00:00' );
		$sub_post_id     = $this->create_subscription_post( $subscription_id, '2024-01-02 00:00:00' );

		$request = $this->request( 'GET', '/daymark/v1/timeline' );
		$ids     = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertEqualsCanonicalizing( array( $mark_id, $sub_post_id ), $ids );
	}
}
