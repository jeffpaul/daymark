<?php
/**
 * REST tests for the Subscribing/Unsubscribing endpoints (issue #78), plus
 * OPML export/import (issue #80):
 *
 *   - POST   /daymark/v1/subscriptions
 *   - GET    /daymark/v1/subscriptions
 *   - DELETE /daymark/v1/subscriptions/{id}
 *   - POST   /daymark/v1/subscriptions/{id}/refresh
 *   - POST   /daymark/v1/subscriptions/refresh
 *   - GET    /daymark/v1/subscriptions/export
 *   - POST   /daymark/v1/subscriptions/import
 *
 * Feed autodiscovery and favicon lookup both go through
 * Daymark_Subscription_Source_Feed's own wp_safe_remote_get() call, so HTTP
 * is mocked via `pre_http_request` (same approach as
 * tests/test-subscription-source-feed.php) rather than hitting the real
 * network. Daymark_Subscription_OPML's own export()/import() logic (shape,
 * escaping, XXE safety, nested outlines, the entry cap, the xmlUrl/htmlUrl
 * split) is covered directly in tests/test-subscription-opml.php — the
 * export/import tests here only cover the REST wrapping around it (auth,
 * upload validation, response shape).
 *
 * @package Daymark
 */

/**
 * Exercises the subscribe-by-URL, list, refresh, unsubscribe, and
 * OPML export/import REST endpoints.
 */
class Test_Rest_Subscriptions extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var int */
	private $admin_user;

	/**
	 * URL => canned wp_remote_get()-shaped response, consulted by
	 * intercept_http_request().
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author_a       = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->admin_user     = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->http_responses = array();

		// Daymark_Subscription_Html_Cache is a static, request-scoped cache
		// shared by every subscription source's discovery-time homepage
		// fetch (issue #137) — but PHPUnit runs every test in this file (and
		// every other test file) in one continuous PHP process, so without
		// resetting it, an earlier test's fixture for a reused URL (several
		// tests here, and in other files, reuse 'https://example.com/')
		// could leak into a test here.
		Daymark_Subscription_Html_Cache::reset();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * Short-circuits every HTTP request made in this file: a mapped URL
	 * returns its canned response, anything unmapped is blocked with a
	 * WP_Error rather than hitting the real network.
	 *
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
	 * Register a canned 200 response for a URL.
	 *
	 * @param string $url          URL to mock.
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header value. Only matters
	 *                             for a feed-content fetch (SimplePie
	 *                             consults this header to detect a feed);
	 *                             defaults to a value that is harmless for
	 *                             this file's site-HTML fetches.
	 * @return void
	 */
	private function mock_response( string $url, string $body, string $content_type = 'text/html; charset=UTF-8' ): void {
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

	/** HTML with a discoverable main feed and an explicit favicon link. */
	private function html_with_feed_and_icon(): string {
		return '<html><head><title>Example</title>'
			. '<link rel="alternate" type="application/rss+xml" href="/feed/" />'
			. '<link rel="icon" href="/icon.png" />'
			. '</head><body></body></html>';
	}

	/** HTML with no discoverable feed at all. */
	private function html_without_feed(): string {
		return '<html><head><title>Example</title></head><body></body></html>';
	}

	/** A valid RSS 2.0 feed with a single item, for the immediate-ingest test. */
	private function rss_with_one_item(): string {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
<title>Example Feed</title>
<link>https://example.com/</link>
<item>
<title>A Post</title>
<link>https://example.com/a-post/</link>
<guid>https://example.com/a-post/</guid>
<pubDate>Tue, 02 Jan 2024 03:04:05 +0000</pubDate>
<description>Some content.</description>
</item>
</channel>
</rss>
XML;
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

	/** Happy path: subscribing creates a row and returns it. */
	public function test_subscribe_creates_row_and_returns_it() {
		wp_set_current_user( $this->admin_user );

		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );

		$request = $this->request( 'POST', '/daymark/v1/subscriptions' );
		$request->set_param( 'site_url', 'https://example.com/' );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'https://example.com/feed/', $data['feed_url'] );
		$this->assertSame( 'https://example.com/', $data['site_url'] );
		$this->assertSame( 'feed', $data['source_type'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 'https://example.com/icon.png', $data['site_icon_url'] );
		$this->assertGreaterThan( 0, $data['id'] );

		$subscriptions = new Daymark_Subscriptions();
		$row           = $subscriptions->get( (int) $data['id'] );
		$this->assertNotNull( $row, 'The row was actually persisted' );
		$this->assertSame( 'https://example.com/feed/', $row['feed_url'] );
	}

	/**
	 * Subscribing triggers an immediate best-effort fetch of the feed, so a
	 * subscribed site's posts appear in the Timeline right away rather than
	 * waiting for the next scheduled poll (by default once a day) —
	 * subscribe_to_site() only creates the row; this response's own status
	 * codes/shape are unaffected either way.
	 */
	public function test_subscribe_ingests_posts_immediately() {
		wp_set_current_user( $this->admin_user );

		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );
		$this->mock_response(
			'https://example.com/feed/',
			$this->rss_with_one_item(),
			'application/rss+xml; charset=UTF-8'
		);

		$request = $this->request( 'POST', '/daymark/v1/subscriptions' );
		$request->set_param( 'site_url', 'https://example.com/' );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );

		$posts = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$this->assertCount( 1, $posts, 'The feed\'s one item was ingested immediately, not left for the next scheduled poll' );
		$this->assertSame( 'A Post', $posts[0]->post_title );
	}

	/** Subscribing to an already-subscribed feed propagates the duplicate error as-is. */
	public function test_subscribe_duplicate_feed_returns_existing_error() {
		wp_set_current_user( $this->admin_user );

		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );

		$first = rest_do_request( $this->build_subscribe_request( 'https://example.com/' ) );
		$this->assertSame( 201, $first->get_status() );

		$second = rest_do_request( $this->build_subscribe_request( 'https://example.com/' ) );

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'daymark_subscription_duplicate', $second->get_data()['code'] );
	}

	/** A URL with no discoverable feed fails clearly, not with a fatal. */
	public function test_subscribe_no_feed_found_returns_clear_error() {
		wp_set_current_user( $this->admin_user );

		$this->mock_response( 'https://no-feed.example/', $this->html_without_feed() );

		$response = rest_do_request( $this->build_subscribe_request( 'https://no-feed.example/' ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertLessThan( 500, $response->get_status(), 'A clean client error, not a fatal' );
		$this->assertSame( 'daymark_subscription_no_feed_found', $response->get_data()['code'] );

		$subscriptions = new Daymark_Subscriptions();
		$this->assertSame( array(), $subscriptions->get_active(), 'Nothing was created' );
	}

	/** A non-http(s) site_url is rejected before any discovery is attempted. */
	public function test_subscribe_rejects_invalid_url() {
		wp_set_current_user( $this->admin_user );

		$response = rest_do_request( $this->build_subscribe_request( 'ftp://example.com/' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_subscription_invalid_url', $response->get_data()['code'] );
	}

	/** GET /subscriptions returns active subscriptions. */
	public function test_list_returns_active_subscriptions() {
		wp_set_current_user( $this->author_a );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://example.org/',
				'feed_url' => 'https://example.org/feed/',
			)
		);

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/subscriptions' ) );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data(), 'id' );
		$this->assertContains( $subscription_id, $ids );
	}

	/** Unsubscribing deletes the row and trashes its cached subscription posts. */
	public function test_unsubscribe_deletes_row_and_trashes_subscription_posts() {
		wp_set_current_user( $this->admin_user );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://example.net/',
				'feed_url' => 'https://example.net/feed/',
			)
		);

		$cached_post_id = self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $cached_post_id, 'subscription_id', $subscription_id );

		// A cached post from a *different* subscription must be left alone.
		$other_subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://elsewhere.example/',
				'feed_url' => 'https://elsewhere.example/feed/',
			)
		);
		$other_post_id         = self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other_post_id, 'subscription_id', $other_subscription_id );

		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/subscriptions/{$subscription_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 1, $data['trashed_posts'] );

		$this->assertSame( 'trash', get_post_status( $cached_post_id ) );
		$this->assertSame( 'publish', get_post_status( $other_post_id ), "Another subscription's posts are untouched" );

		$this->assertNull( $subscriptions->get( $subscription_id ), 'The subscription row is gone' );
	}

	/** Unsubscribing from a nonexistent subscription is a 404. */
	public function test_unsubscribe_missing_id_is_404() {
		wp_set_current_user( $this->admin_user );

		$response = rest_do_request( $this->request( 'DELETE', '/daymark/v1/subscriptions/999999' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'daymark_subscription_not_found', $response->get_data()['code'] );
	}

	/**
	 * The routes that change site-wide subscription settings (subscribe,
	 * unsubscribe, OPML export and import) share Settings -> Daymark's
	 * capability, so an Author with a valid nonce gets a 403 from each.
	 */
	public function test_settings_like_routes_require_manage_options() {
		wp_set_current_user( $this->author_a );

		$requests = array(
			$this->build_subscribe_request( 'https://example.org/' ),
			$this->request( 'DELETE', '/daymark/v1/subscriptions/1' ),
			$this->request( 'GET', '/daymark/v1/subscriptions/export' ),
			$this->request( 'POST', '/daymark/v1/subscriptions/import' ),
		);

		foreach ( $requests as $request ) {
			$response = rest_do_request( $request );

			$this->assertSame( 403, $response->get_status(), $request->get_method() . ' ' . $request->get_route() );
			$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		}
	}

	/** The two routes the app shell itself relies on stay available to anyone who can edit posts. */
	public function test_list_and_refresh_remain_available_to_authors() {
		wp_set_current_user( $this->author_a );

		$this->assertSame( 200, rest_do_request( $this->request( 'GET', '/daymark/v1/subscriptions' ) )->get_status() );

		$refresh = rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/999999/refresh' ) );
		$this->assertNotSame( 403, $refresh->get_status(), 'Refresh is permitted; a missing ID is a 404, not a permission error' );
	}

	/** All three routes reject an unauthenticated (logged-out) request with 401. */
	public function test_all_routes_reject_unauthenticated_requests() {
		wp_set_current_user( 0 );

		$post = new WP_REST_Request( 'POST', '/daymark/v1/subscriptions' );
		$post->set_param( 'site_url', 'https://example.com/' );
		$this->assertSame( 401, rest_do_request( $post )->get_status() );

		$get = new WP_REST_Request( 'GET', '/daymark/v1/subscriptions' );
		$this->assertSame( 401, rest_do_request( $get )->get_status() );

		$delete = new WP_REST_Request( 'DELETE', '/daymark/v1/subscriptions/1' );
		$this->assertSame( 401, rest_do_request( $delete )->get_status() );

		$refresh = new WP_REST_Request( 'POST', '/daymark/v1/subscriptions/1/refresh' );
		$this->assertSame( 401, rest_do_request( $refresh )->get_status() );

		$export = new WP_REST_Request( 'GET', '/daymark/v1/subscriptions/export' );
		$this->assertSame( 401, rest_do_request( $export )->get_status() );

		$import = new WP_REST_Request( 'POST', '/daymark/v1/subscriptions/import' );
		$this->assertSame( 401, rest_do_request( $import )->get_status() );
	}

	/** POST /subscriptions/{id}/refresh polls immediately outside the manual-refresh window. */
	public function test_refresh_polls_and_returns_the_subscription() {
		wp_set_current_user( $this->author_a );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);

		// A feed with one item: Daymark_Subscription_Source_Feed::fetch()
		// returns an empty array both for an unreachable feed and for one
		// that is reachable but has zero items — poll_subscription()
		// deliberately cannot distinguish the two at this interface (see
		// Daymark_Subscription_Poller::poll_subscription()'s docblock), so a
		// zero-item feed here would be treated as a fetch failure rather
		// than a successful empty poll.
		$this->mock_response(
			'https://example.com/feed/',
			'<?xml version="1.0"?><rss version="2.0"><channel><title>Example</title><link>https://example.com/</link>'
			. '<item><title>A Post</title><link>https://example.com/a-post/</link><guid>https://example.com/a-post/</guid>'
			. '<pubDate>Tue, 02 Jan 2024 03:04:05 +0000</pubDate><description>Body.</description></item>'
			. '</channel></rss>',
			'application/rss+xml; charset=UTF-8'
		);

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/subscriptions/{$subscription_id}/refresh" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $subscription_id, $data['id'] );
		$this->assertNotSame( '', $data['last_manual_refresh_at'] );
	}

	/** POST /subscriptions/{id}/refresh within the 15-minute window returns 429. */
	public function test_refresh_too_recent_returns_429() {
		wp_set_current_user( $this->author_a );

		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);
		$subscriptions->update( $subscription_id, array( 'last_manual_refresh_at' => current_time( 'mysql', true ) ) );

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/subscriptions/{$subscription_id}/refresh" ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'daymark_subscription_refresh_too_recent', $response->get_data()['code'] );
	}

	/** POST /subscriptions/{id}/refresh for a nonexistent subscription is a 404. */
	public function test_refresh_missing_id_is_404() {
		wp_set_current_user( $this->author_a );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/999999/refresh' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'daymark_subscription_not_found', $response->get_data()['code'] );
	}

	/**
	 * Build a POST /subscriptions request carrying a valid nonce and the
	 * given site_url.
	 *
	 * @param string $site_url Site URL to subscribe to.
	 * @return WP_REST_Request
	 */
	private function build_subscribe_request( string $site_url ): WP_REST_Request {
		$request = $this->request( 'POST', '/daymark/v1/subscriptions' );
		$request->set_param( 'site_url', $site_url );

		return $request;
	}

	/**
	 * GET /subscriptions/export returns the same OPML export()
	 * Daymark_Subscription_OPML::export() produces, with file-download
	 * headers set — rest_do_request() bypasses WP_REST_Server::serve_request()
	 * entirely (see maybe_serve_opml_export()'s own docblock), so this
	 * asserts directly on the WP_REST_Response object rather than on real
	 * HTTP output.
	 */
	public function test_export_returns_opml_with_download_headers() {
		wp_set_current_user( $this->admin_user );

		$subscriptions = new Daymark_Subscriptions();
		$subscriptions->create(
			array(
				'site_url'   => 'https://export-me.example/',
				'feed_url'   => 'https://export-me.example/feed/',
				'site_title' => 'Export Me',
			)
		);

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/subscriptions/export' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'xml', $data );
		$this->assertStringContainsString( '<opml', $data['xml'] );
		$this->assertStringContainsString( 'Export Me', $data['xml'] );
		$this->assertStringContainsString( 'https://export-me.example/feed/', $data['xml'] );

		$headers = $response->get_headers();
		$this->assertSame( 'text/x-opml+xml; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="daymark-subscriptions.opml"', $headers['Content-Disposition'] );
	}

	/** POST /subscriptions/import creates rows and reports per-entry results, matching Daymark_Subscription_OPML::import()'s own contract. */
	public function test_import_creates_subscriptions_and_reports_results() {
		wp_set_current_user( $this->admin_user );

		$opml = '<?xml version="1.0"?><opml version="2.0"><body>'
			. '<outline text="Imported Feed" xmlUrl="https://imported.example/feed" htmlUrl="https://imported.example/" />'
			. '</body></opml>';

		$request = $this->request( 'POST', '/daymark/v1/subscriptions/import' );
		$request->set_file_params(
			array(
				'opml' => array(
					'name'     => 'subscriptions.opml',
					'type'     => 'text/x-opml+xml',
					'tmp_name' => $this->write_opml_fixture( $opml ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $opml ),
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'subscribed', $data[0]['status'] );
		$this->assertSame( 'Imported Feed', $data[0]['label'] );

		$subscriptions = new Daymark_Subscriptions();
		$this->assertNotNull( $subscriptions->get_by_feed_url( 'https://imported.example/feed' ) );
	}

	/** A missing file is a clean 400, not a fatal. */
	public function test_import_requires_a_file() {
		wp_set_current_user( $this->admin_user );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/import' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_subscription_opml_missing_file', $response->get_data()['code'] );
	}

	/** A file with a disallowed extension is rejected before it is ever parsed. */
	public function test_import_rejects_disallowed_extension() {
		wp_set_current_user( $this->admin_user );

		$request = $this->request( 'POST', '/daymark/v1/subscriptions/import' );
		$request->set_file_params(
			array(
				'opml' => array(
					'name'     => 'subscriptions.txt',
					'type'     => 'text/plain',
					'tmp_name' => $this->write_opml_fixture( 'not opml' ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => 8,
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_subscription_opml_invalid_extension', $response->get_data()['code'] );
	}

	/** A file over the configured upload-size cap is rejected before it is ever read/parsed. */
	public function test_import_rejects_oversized_upload() {
		wp_set_current_user( $this->admin_user );

		add_filter(
			'daymark_subscription_opml_max_upload_bytes',
			static function () {
				return 10;
			}
		);

		$opml    = '<?xml version="1.0"?><opml version="2.0"><body>'
			. '<outline text="Too Big" xmlUrl="https://too-big.example/feed" />'
			. '</body></opml>';
		$request = $this->request( 'POST', '/daymark/v1/subscriptions/import' );
		$request->set_file_params(
			array(
				'opml' => array(
					'name'     => 'subscriptions.opml',
					'type'     => 'text/x-opml+xml',
					'tmp_name' => $this->write_opml_fixture( $opml ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $opml ),
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_subscription_opml_too_large', $response->get_data()['code'] );

		$subscriptions = new Daymark_Subscriptions();
		$this->assertSame( array(), $subscriptions->get_all() );
	}

	/** A malformed (non-OPML) file's parse failure surfaces as a clean 400, not a fatal. */
	public function test_import_rejects_malformed_file() {
		wp_set_current_user( $this->admin_user );

		$request = $this->request( 'POST', '/daymark/v1/subscriptions/import' );
		$request->set_file_params(
			array(
				'opml' => array(
					'name'     => 'subscriptions.opml',
					'type'     => 'text/x-opml+xml',
					'tmp_name' => $this->write_opml_fixture( 'not xml at all <<<' ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => 19,
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_subscription_opml_invalid', $response->get_data()['code'] );
	}

	/**
	 * Write an OPML fixture body to a temp file and return its path, for
	 * set_file_params()'s `tmp_name`.
	 *
	 * @param string $body File contents.
	 * @return string Temp file path.
	 */
	private function write_opml_fixture( string $body ): string {
		$path = wp_tempnam( 'daymark-opml-' ) . '.opml';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write; WP_Filesystem is unnecessary here.
		file_put_contents( $path, $body );

		return $path;
	}

	/** A one-item RSS feed body, for refresh tests. */
	private function one_item_feed( string $site ): string {
		return '<?xml version="1.0"?><rss version="2.0"><channel><title>Example</title><link>' . $site . '</link>'
			. '<item><title>A Post</title><link>' . $site . 'a-post/</link><guid>' . $site . 'a-post/</guid>'
			. '<pubDate>Tue, 02 Jan 2024 03:04:05 +0000</pubDate><description>Body.</description></item>'
			. '</channel></rss>';
	}

	/** Creates two active subscriptions with reachable feeds; returns their IDs. */
	private function two_reachable_subscriptions(): array {
		$subscriptions = new Daymark_Subscriptions();
		$ids           = array();

		foreach ( array( 'https://one.example/', 'https://two.example/' ) as $site ) {
			$ids[] = $subscriptions->create(
				array(
					'site_url' => $site,
					'feed_url' => $site . 'feed/',
				)
			);
			$this->mock_response( $site . 'feed/', $this->one_item_feed( $site ), 'application/rss+xml; charset=UTF-8' );
		}

		return $ids;
	}

	/** POST /subscriptions/refresh checks every site and reports counts. */
	public function test_refresh_all_checks_every_site() {
		wp_set_current_user( $this->author_a );
		$this->two_reachable_subscriptions();

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/refresh' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'total'     => 2,
				'refreshed' => 2,
				'failed'    => 0,
				'recent'    => 0,
				'queued'    => 0,
			),
			$response->get_data()
		);
	}

	/** A site inside its manual-refresh cooldown is skipped and counted as recent. */
	public function test_refresh_all_counts_recently_checked_sites() {
		wp_set_current_user( $this->author_a );
		$ids = $this->two_reachable_subscriptions();
		( new Daymark_Subscriptions() )->update( $ids[0], array( 'last_manual_refresh_at' => current_time( 'mysql', true ) ) );

		$data = rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/refresh' ) )->get_data();

		$this->assertSame( 1, $data['refreshed'] );
		$this->assertSame( 1, $data['recent'] );
	}

	/** Sites left once the time budget runs out go to an immediate background poll. */
	public function test_refresh_all_queues_sites_past_the_time_budget() {
		wp_set_current_user( $this->author_a );
		$this->two_reachable_subscriptions();
		wp_clear_scheduled_hook( Daymark_Subscription_Poller::CRON_HOOK . '_now' );
		add_filter( 'daymark_subscription_refresh_all_time_budget', '__return_zero' );

		$data = rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/refresh' ) )->get_data();

		remove_filter( 'daymark_subscription_refresh_all_time_budget', '__return_zero' );
		$this->assertSame( 0, $data['refreshed'] );
		$this->assertSame( 2, $data['queued'] );
		$this->assertNotFalse( wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK . '_now' ) );
	}

	/** One refresh of every site spends one rate-limit charge, not one per site. */
	public function test_refresh_all_spends_one_rate_limit_charge() {
		wp_set_current_user( $this->author_a );
		$this->two_reachable_subscriptions();

		// ACTION_SUBSCRIPTION_REFRESH allows 10 requests per window.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 200, rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/refresh' ) )->get_status() );
		}

		$this->assertSame( 429, rest_do_request( $this->request( 'POST', '/daymark/v1/subscriptions/refresh' ) )->get_status() );
	}

	/** The refresh-all route needs a logged-in user. */
	public function test_refresh_all_rejects_unauthenticated_requests() {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/daymark/v1/subscriptions/refresh' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	// -----------------------------------------------------------------
	// Following a site from the app: POST /subscriptions/discover, then
	// POST /subscriptions/follow.
	// -----------------------------------------------------------------

	/**
	 * Run discovery for example.com as the current user.
	 *
	 * @return WP_REST_Response
	 */
	private function discover_example(): WP_REST_Response {
		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );

		$request = $this->request( 'POST', '/daymark/v1/subscriptions/discover' );
		$request->set_param( 'site_url', 'https://example.com/' );

		return rest_do_request( $request );
	}

	/**
	 * Follow a discovered feed by index.
	 *
	 * @param int    $index Candidate index.
	 * @param string $title Site name to set, or '' for none.
	 * @return WP_REST_Response
	 */
	private function follow( int $index, string $title = '' ): WP_REST_Response {
		$request = $this->request( 'POST', '/daymark/v1/subscriptions/follow' );
		$request->set_param( 'index', $index );
		if ( '' !== $title ) {
			$request->set_param( 'site_title', $title );
		}

		return rest_do_request( $request );
	}

	/** Discovery lists the site's feeds, its name, and which one to pick by default. */
	public function test_discover_lists_feeds(): void {
		wp_set_current_user( $this->admin_user );

		$response = $this->discover_example();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Example', $data['site_title'] );
		$urls = wp_list_pluck( $data['candidates'], 'url' );
		$this->assertContains( 'https://example.com/feed/', $urls );
		$this->assertArrayHasKey( $data['default_index'], $data['candidates'] );
		$this->assertFalse( $data['candidates'][0]['subscribed'] );
	}

	/** Following a discovered feed creates the subscription, with an edited name. */
	public function test_follow_creates_subscription_with_name(): void {
		wp_set_current_user( $this->admin_user );
		$data  = $this->discover_example()->get_data();
		$index = array_search( 'https://example.com/feed/', wp_list_pluck( $data['candidates'], 'url' ), true );

		$response = $this->follow( (int) $index, 'My Friend' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'https://example.com/feed/', $response->get_data()['feed_url'] );
		$this->assertSame( 'My Friend', $response->get_data()['site_title'] );
	}

	/** A discovery can be used once: following again needs a fresh lookup. */
	public function test_follow_uses_discovery_once(): void {
		wp_set_current_user( $this->admin_user );
		$this->discover_example();

		$this->assertSame( 201, $this->follow( 0 )->get_status() );
		$this->assertSame( 410, $this->follow( 0 )->get_status() );
	}

	/** Following with no discovery first, or an index it didn't find, is refused. */
	public function test_follow_requires_a_discovered_feed(): void {
		wp_set_current_user( $this->admin_user );

		$this->assertSame( 410, $this->follow( 0 )->get_status() );

		$this->discover_example();
		$response = $this->follow( 99 );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_follow_invalid_feed', $response->get_data()['code'] );
	}

	/** An Author can't follow sites, the same as on Settings -> Daymark. */
	public function test_follow_routes_need_manage_options(): void {
		wp_set_current_user( $this->author_a );

		$this->assertSame( 403, $this->discover_example()->get_status() );
		$this->assertSame( 403, $this->follow( 0 )->get_status() );
	}

	/** One user's discovery can't be followed by another user. */
	public function test_discovery_is_per_user(): void {
		wp_set_current_user( $this->admin_user );
		$this->discover_example();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 410, $this->follow( 0 )->get_status() );
	}
}
