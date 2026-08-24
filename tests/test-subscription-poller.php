<?php
/**
 * Daymark_Subscription_Poller tests (issue #78): ingest content-ingest-rule
 * splitting, dedupe, failure/dead-feed handling, pruning's exact retention
 * boundary, click-through fetch (including on a pruned post), and manual
 * refresh's own rate limit + independence from the cron schedule.
 *
 * All HTTP is mocked via `pre_http_request`, matching the existing pattern in
 * tests/test-subscription-source-feed.php — a feed fetch goes through
 * fetch_feed() (SimplePie's WP_SimplePie_File, itself a WP HTTP API wrapper),
 * and a click-through fetch goes through wp_safe_remote_get() directly.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Poller.
 */
class Test_Subscription_Poller extends WP_UnitTestCase {

	/** @var Daymark_Subscription_Poller */
	private $poller;

	/**
	 * URL => canned wp_remote_get()-shaped response, consulted by
	 * intercept_http_request(). An unmapped URL is blocked with a WP_Error,
	 * simulating an unreachable host — this is how "the feed is down" or
	 * "the permalink can't be reached" is exercised without ever touching
	 * the real network.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->poller         = new Daymark_Subscription_Poller();
		$this->http_responses = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
		// fetch_feed() caches parsed feeds (by default, 12 hours) keyed by
		// URL — several tests here poll the same feed URL more than once
		// within a single test to exercise dedupe/failure/success-reset
		// behavior, and a cache hit would silently skip the mocked HTTP
		// layer entirely on the second call. Disabling it keeps every
		// poll_subscription() call in this file hitting the mocked
		// pre_http_request layer, as intended.
		add_action( 'wp_feed_options', array( $this, 'disable_feed_cache' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );
		remove_action( 'wp_feed_options', array( $this, 'disable_feed_cache' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param SimplePie $feed Feed instance, passed by reference by fetch_feed().
	 * @param string    $url  Feed URL (unused).
	 * @return void
	 */
	public function disable_feed_cache( $feed, $url ) {
		unset( $url );
		$feed->enable_cache( false );
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string $url          URL to mock.
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header value. Defaults to an
	 *                             RSS/Atom type: SimplePie (via
	 *                             WP_SimplePie_File) consults this header,
	 *                             not just the body, to detect a feed — an
	 *                             empty/missing one otherwise makes a
	 *                             perfectly valid mocked feed body look like
	 *                             a fetch failure.
	 * @return void
	 */
	private function mock_response( string $url, string $body, string $content_type = 'application/rss+xml; charset=UTF-8' ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => $content_type ),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A minimal valid RSS 2.0 feed with one plain item (no enclosure — a
	 * "standard" post_format) and one item with a single image enclosure (an
	 * "image" post_format, per Daymark_Subscription_Source_Feed::normalize()).
	 *
	 * @return string
	 */
	private function rss_with_standard_and_image_items(): string {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
<title>Example Feed</title>
<link>https://example.com/</link>
<item>
<title>Standard Post</title>
<link>https://example.com/standard-post/</link>
<guid>https://example.com/standard-post/</guid>
<pubDate>Tue, 02 Jan 2024 03:04:05 +0000</pubDate>
<description>This is a standard text post with no media.</description>
</item>
<item>
<title>Photo Post</title>
<link>https://example.com/photo-post/</link>
<guid>https://example.com/photo-post/</guid>
<pubDate>Wed, 03 Jan 2024 03:04:05 +0000</pubDate>
<description>A post with a photo.</description>
<enclosure url="https://example.com/photo.jpg" type="image/jpeg" length="12345" />
</item>
</channel>
</rss>
XML;
	}

	/**
	 * A valid RSS 2.0 feed with zero items — a real, healthy "quiet blog"
	 * state, not a fetch failure.
	 *
	 * @return string
	 */
	private function rss_with_no_items(): string {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
<title>Example Feed</title>
<link>https://example.com/</link>
</channel>
</rss>
XML;
	}

	/**
	 * @param string $feed_url Feed URL for the new row.
	 * @return int Subscription ID.
	 */
	private function create_subscription( string $feed_url ): int {
		$subscriptions = new Daymark_Subscriptions();

		return (int) $subscriptions->create(
			array(
				'site_url'    => 'https://example.com/',
				'feed_url'    => $feed_url,
				'source_type' => 'feed',
			)
		);
	}

	/**
	 * @param int $subscription_id Subscription ID.
	 * @return WP_Post[]
	 */
	private function get_subscription_posts( int $subscription_id ): array {
		return get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test assertion.
				'meta_query'     => array(
					array(
						'key'     => 'subscription_id',
						'value'   => $subscription_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
	}

	/**
	 * @param WP_Post[] $posts Posts to search.
	 * @param string    $title Exact post_title to find.
	 * @return int Post ID, or 0 when not found.
	 */
	private function find_post_by_title( array $posts, string $title ): int {
		foreach ( $posts as $post ) {
			if ( $title === $post->post_title ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}

	/**
	 * Directly creates a fully-populated `daymark_subscription_post`,
	 * bypassing ingest, for tests that need precise control over
	 * `published_at`/`content_state` (the pruning boundary tests).
	 *
	 * @param int    $subscription_id Owning subscription ID.
	 * @param string $published_at    MySQL datetime.
	 * @return int Post ID.
	 */
	private function create_cached_post( int $subscription_id, string $published_at ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Item at ' . $published_at,
				'post_excerpt' => 'An excerpt.',
			)
		);

		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'permalink', 'https://example.com/' . $post_id . '/' );
		update_post_meta( $post_id, 'published_at', $published_at );
		update_post_meta( $post_id, 'featured_image_url', 'https://example.com/img-' . $post_id . '.jpg' );
		update_post_meta( $post_id, 'content_state', 'excerpt_only' );
		update_post_meta( $post_id, 'body_content', 'Cached body content.' );
		update_post_meta( $post_id, 'embed_data', wp_json_encode( array( 'raw_media' => array( 'https://example.com/media.jpg' ) ) ) );

		return $post_id;
	}

	// -----------------------------------------------------------------
	// Ingest.
	// -----------------------------------------------------------------

	/** A successful poll ingests items split by the content-ingest rule. */
	public function test_poll_ingests_items_split_by_content_ingest_rule() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$this->mock_response( 'https://example.com/feed/', $this->rss_with_standard_and_image_items() );

		$this->assertTrue( $this->poller->poll_subscription( $subscription_id ) );

		$posts = $this->get_subscription_posts( $subscription_id );
		$this->assertCount( 2, $posts );

		$standard_id = $this->find_post_by_title( $posts, 'Standard Post' );
		$this->assertGreaterThan( 0, $standard_id );
		$this->assertSame( 'standard', get_post_meta( $standard_id, 'post_format', true ) );
		$this->assertSame( 'excerpt_only', get_post_meta( $standard_id, 'content_state', true ) );
		$this->assertSame( '', get_post_meta( $standard_id, 'embed_data', true ), 'Non-rich-media formats get no embed data' );
		$this->assertSame( '', get_post_meta( $standard_id, 'body_content', true ), 'No full body fetched at ingest' );

		$image_id = $this->find_post_by_title( $posts, 'Photo Post' );
		$this->assertGreaterThan( 0, $image_id );
		$this->assertSame( 'image', get_post_meta( $image_id, 'post_format', true ) );
		$this->assertSame( 'excerpt_only', get_post_meta( $image_id, 'content_state', true ) );
		$this->assertSame( '', get_post_meta( $image_id, 'body_content', true ) );

		$embed = json_decode( (string) get_post_meta( $image_id, 'embed_data', true ), true );
		$this->assertIsArray( $embed );
		$this->assertContains( 'https://example.com/photo.jpg', $embed['raw_media'] );
	}

	/** Polling the same feed twice does not create duplicate posts. */
	public function test_poll_twice_does_not_duplicate_posts() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$this->mock_response( 'https://example.com/feed/', $this->rss_with_standard_and_image_items() );

		$this->assertTrue( $this->poller->poll_subscription( $subscription_id ) );
		$this->assertTrue( $this->poller->poll_subscription( $subscription_id ) );

		$this->assertCount( 2, $this->get_subscription_posts( $subscription_id ) );
	}

	/** A failed fetch increments the failure count and touches nothing cached. */
	public function test_poll_failure_increments_failure_count_and_leaves_posts_untouched() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$this->mock_response( 'https://example.com/feed/', $this->rss_with_standard_and_image_items() );

		$this->assertTrue( $this->poller->poll_subscription( $subscription_id ) );
		$this->assertCount( 2, $this->get_subscription_posts( $subscription_id ) );

		// Unmap the feed URL: the next fetch is now "unreachable".
		$this->http_responses = array();

		$result = $this->poller->poll_subscription( $subscription_id );
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_fetch_failed', $result->get_error_code() );

		$subscriptions = new Daymark_Subscriptions();
		$row           = $subscriptions->get( $subscription_id );
		$this->assertSame( '1', (string) $row['consecutive_failure_count'] );

		$this->assertCount( 2, $this->get_subscription_posts( $subscription_id ), 'Existing cached posts are untouched' );
	}

	/**
	 * A feed that fetches and parses fine but currently has zero items is a
	 * real, healthy state (a quiet blog between posts) — it must NOT count
	 * as a failure, or a perfectly fine subscription would get wrongly
	 * flagged dead after enough silent poll cycles.
	 */
	public function test_poll_empty_but_valid_feed_does_not_count_as_failure() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$this->mock_response( 'https://example.com/feed/', $this->rss_with_no_items() );

		$result = $this->poller->poll_subscription( $subscription_id );

		$this->assertTrue( $result );

		$subscriptions = new Daymark_Subscriptions();
		$row           = $subscriptions->get( $subscription_id );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
		$this->assertSame( 'active', $row['status'] );
	}

	/** A successful fetch resets a nonzero failure count back to 0. */
	public function test_poll_success_resets_failure_count() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );

		$subscriptions = new Daymark_Subscriptions();
		$subscriptions->update( $subscription_id, array( 'consecutive_failure_count' => 4 ) );

		$this->mock_response( 'https://example.com/feed/', $this->rss_with_standard_and_image_items() );
		$this->assertTrue( $this->poller->poll_subscription( $subscription_id ) );

		$row = $subscriptions->get( $subscription_id );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
	}

	/** Seven consecutive failed checks flags the subscription dead. */
	public function test_dead_feed_flagged_after_seven_consecutive_failures() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$subscriptions   = new Daymark_Subscriptions();

		// Deliberately never mocked: every check fails.
		for ( $i = 0; $i < 6; $i++ ) {
			$this->poller->poll_subscription( $subscription_id );
		}

		$row = $subscriptions->get( $subscription_id );
		$this->assertSame( 'active', $row['status'], 'Still active before the 7th consecutive failure' );

		$this->poller->poll_subscription( $subscription_id );

		$row = $subscriptions->get( $subscription_id );
		$this->assertSame( '7', (string) $row['consecutive_failure_count'] );
		$this->assertSame( 'error', $row['status'] );
		$this->assertSame( array(), $subscriptions->get_active(), 'A dead-flagged subscription is no longer polled automatically' );
	}

	// -----------------------------------------------------------------
	// Pruning.
	// -----------------------------------------------------------------

	/** Fewer than 10 total posts: nothing is pruned, regardless of age. */
	public function test_prune_boundary_fewer_than_ten_posts_prunes_nothing() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed-a/' );

		$post_ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			// Some deliberately old (3 years ago), some recent — age must
			// not matter while the subscription's total is under 10.
			$days_ago   = ( 0 === $i % 2 ) ? ( 3 * 365 ) : 1;
			$post_ids[] = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ) );
		}

		$this->assertSame( 0, $this->poller->prune_subscription( $subscription_id ) );

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( 'excerpt_only', get_post_meta( $post_id, 'content_state', true ) );
			$this->assertNotSame( '', get_post_meta( $post_id, 'body_content', true ) );
		}
	}

	/** More than 10 posts, but all published within the last year: nothing is pruned. */
	public function test_prune_boundary_all_within_last_year_prunes_nothing() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed-b/' );

		// 15 posts (> the 10-post floor), each within the last year — the
		// PRD's "100 posts in the last year" scenario at a unit-test scale;
		// the retention rule is the same regardless of exact count.
		$post_ids = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$post_ids[] = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s', time() - ( $i * DAY_IN_SECONDS ) ) );
		}

		$this->assertSame( 0, $this->poller->prune_subscription( $subscription_id ) );

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( 'excerpt_only', get_post_meta( $post_id, 'content_state', true ) );
		}
	}

	/**
	 * A mix: 10 recent posts (retained as the top 10), one post outside the
	 * top 10 but still within the last year (retained by the last-year
	 * clause), and one post outside both (pruned).
	 */
	public function test_prune_mix_prunes_only_posts_outside_both_windows() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed-c/' );

		$recent_ids = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$recent_ids[] = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s', time() - ( $i * DAY_IN_SECONDS ) ) );
		}

		// Outside the top 10 by recency, but within the last year: retained.
		$within_last_year_id = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ) );

		// Outside the top 10 and outside the last year: pruned.
		$stale_id = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s', time() - ( 2 * YEAR_IN_SECONDS ) ) );

		$pruned_count = $this->poller->prune_subscription( $subscription_id );

		$this->assertSame( 1, $pruned_count );

		foreach ( $recent_ids as $post_id ) {
			$this->assertSame( 'excerpt_only', get_post_meta( $post_id, 'content_state', true ), 'A top-10 post is retained' );
		}

		$this->assertSame( 'excerpt_only', get_post_meta( $within_last_year_id, 'content_state', true ), 'Outside top 10 but within the last year is retained' );

		// The pruned post's exact resulting fields: only body_content and
		// embed_data are cleared, content_state flips to 'pruned', and
		// title/excerpt/published_at/featured_image_url are untouched.
		$this->assertSame( 'pruned', get_post_meta( $stale_id, 'content_state', true ) );
		$this->assertSame( '', get_post_meta( $stale_id, 'body_content', true ) );
		$this->assertSame( '', get_post_meta( $stale_id, 'embed_data', true ) );

		$post = get_post( $stale_id );
		$this->assertSame( 'Item at ' . get_post_meta( $stale_id, 'published_at', true ), $post->post_title );
		$this->assertSame( 'An excerpt.', $post->post_excerpt );
		$this->assertNotSame( '', get_post_meta( $stale_id, 'published_at', true ) );
		$this->assertNotSame( '', get_post_meta( $stale_id, 'featured_image_url', true ) );
	}

	// -----------------------------------------------------------------
	// Click-through fetch.
	// -----------------------------------------------------------------

	/** Click-through fetch on an empty-body post populates body_content and flips content_state to 'full'. */
	public function test_fetch_full_content_populates_body_and_flips_to_full() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', 'https://example.com/full-post/' );
		update_post_meta( $post_id, 'body_content', '' );
		update_post_meta( $post_id, 'content_state', 'excerpt_only' );

		$this->mock_response(
			'https://example.com/full-post/',
			'<html><body><p>Full <script>alert(1)</script>article content here.</p></body></html>',
			'text/html; charset=UTF-8'
		);

		$this->assertTrue( $this->poller->fetch_full_content( $post_id ) );

		$this->assertSame( 'full', get_post_meta( $post_id, 'content_state', true ) );

		$body = (string) get_post_meta( $post_id, 'body_content', true );
		$this->assertStringContainsString( 'Full', $body );
		$this->assertStringContainsString( 'article content here', $body );
		$this->assertStringNotContainsString( '<script>', $body );

		$this->assertNotSame( '', get_post_meta( $post_id, 'fetched_full_at', true ) );
	}

	/** Click-through fetch on a *pruned* post re-triggers the same flow. */
	public function test_fetch_full_content_on_pruned_post_works_the_same_way() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', 'https://example.com/pruned-post/' );
		update_post_meta( $post_id, 'body_content', '' );
		update_post_meta( $post_id, 'embed_data', '' );
		update_post_meta( $post_id, 'content_state', 'pruned' );

		$this->mock_response( 'https://example.com/pruned-post/', '<html><body><p>Recovered content.</p></body></html>', 'text/html; charset=UTF-8' );

		$this->assertTrue( $this->poller->fetch_full_content( $post_id ) );

		$this->assertSame( 'full', get_post_meta( $post_id, 'content_state', true ) );
		$this->assertStringContainsString( 'Recovered content', (string) get_post_meta( $post_id, 'body_content', true ) );
	}

	/** A failed click-through fetch returns a clean WP_Error. */
	public function test_fetch_full_content_failure_returns_clean_wp_error() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$post_id         = $this->create_cached_post( $subscription_id, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', 'https://unreachable.example/post/' );
		update_post_meta( $post_id, 'body_content', '' );

		// Deliberately not mocked: intercept_http_request() blocks it.
		$result = $this->poller->fetch_full_content( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_fetch_failed', $result->get_error_code() );
	}

	/** Fetching for a nonexistent subscription post is a clean WP_Error, not a fatal. */
	public function test_fetch_full_content_missing_post_returns_clean_wp_error() {
		$result = $this->poller->fetch_full_content( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_post_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------
	// Manual refresh.
	// -----------------------------------------------------------------

	/** A manual refresh within the 15-minute window is rejected and makes no request. */
	public function test_manual_refresh_within_window_is_rejected_and_does_not_poll() {
		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$subscriptions   = new Daymark_Subscriptions();
		$subscriptions->update( $subscription_id, array( 'last_manual_refresh_at' => current_time( 'mysql', true ) ) );

		// Deliberately not mocked: if a request were actually made, it would
		// be blocked and recorded as a failed check — the assertion below on
		// consecutive_failure_count would then fail, proving no request happened.
		$result = $this->poller->manual_refresh( $subscription_id );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_refresh_too_recent', $result->get_error_code() );

		$row = $subscriptions->get( $subscription_id );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
	}

	/** A manual refresh outside the window polls and updates last_manual_refresh_at. */
	public function test_manual_refresh_outside_window_polls_and_updates_timestamp() {
		$subscription_id  = $this->create_subscription( 'https://example.com/feed/' );
		$subscriptions    = new Daymark_Subscriptions();
		$stale_refresh_at = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$subscriptions->update( $subscription_id, array( 'last_manual_refresh_at' => $stale_refresh_at ) );

		$this->mock_response( 'https://example.com/feed/', $this->rss_with_standard_and_image_items() );

		$this->assertTrue( $this->poller->manual_refresh( $subscription_id ) );

		$row = $subscriptions->get( $subscription_id );
		$this->assertGreaterThan(
			strtotime( $stale_refresh_at . ' +00:00' ),
			strtotime( $row['last_manual_refresh_at'] . ' +00:00' )
		);

		$this->assertCount( 2, $this->get_subscription_posts( $subscription_id ) );
	}

	/** A manual refresh never resets or otherwise interacts with the cron schedule. */
	public function test_manual_refresh_never_touches_cron_schedule() {
		Daymark_Subscription_Poller::unschedule();
		Daymark_Subscription_Poller::schedule();
		$scheduled_at = wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK );
		$this->assertNotFalse( $scheduled_at );

		$subscription_id = $this->create_subscription( 'https://example.com/feed/' );
		$this->mock_response( 'https://example.com/feed/', $this->rss_with_standard_and_image_items() );

		$this->poller->manual_refresh( $subscription_id );

		$this->assertSame( $scheduled_at, wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK ) );
	}

	/** A manual refresh for a nonexistent subscription is a clean WP_Error. */
	public function test_manual_refresh_missing_subscription_returns_clean_wp_error() {
		$result = $this->poller->manual_refresh( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------
	// Cron scheduling.
	// -----------------------------------------------------------------

	/** The recurring schedule is created and cleared. */
	public function test_schedule_and_unschedule() {
		Daymark_Subscription_Poller::unschedule();
		$this->assertFalse( wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK ) );

		Daymark_Subscription_Poller::schedule();
		$this->assertNotFalse( wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK ) );

		Daymark_Subscription_Poller::unschedule();
		$this->assertFalse( wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK ) );
	}

	/** The custom cron schedule's interval is sourced from the documented filter. */
	public function test_cron_schedule_interval_is_filterable() {
		add_filter(
			'daymark_subscription_poll_interval',
			static function () {
				return 6 * HOUR_IN_SECONDS;
			}
		);

		$schedules = Daymark_Subscription_Poller::register_cron_schedule( array() );

		$this->assertArrayHasKey( 'daymark_subscription_poll_interval', $schedules );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $schedules['daymark_subscription_poll_interval']['interval'] );
	}
}
