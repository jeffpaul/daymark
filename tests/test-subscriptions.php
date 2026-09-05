<?php
/**
 * Daymark_Subscriptions table tests (issue #78 — the daymark_subscription
 * custom DB table only).
 *
 * @package Daymark
 */

/**
 * CRUD round-trip and duplicate-feed-url rejection for the
 * `daymark_subscriptions` table.
 */
class Test_Subscriptions extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/**
	 * URL => canned wp_remote_get()-shaped response, consulted by
	 * intercept_http_request(). Only subscribe_to_site() tests populate
	 * this; every other test in this file makes no HTTP request at all.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		// The table is normally created on plugin activation; tests run
		// against a live WP install where activation hooks don't fire, so
		// install() is called directly (it is idempotent either way).
		Daymark_Subscriptions::install();

		$this->subscriptions  = new Daymark_Subscriptions();
		$this->http_responses = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );

		// Daymark_Subscription_Html_Cache is a static, request-scoped cache
		// shared by every subscription source's discovery-time homepage
		// fetch (issue #137) — but PHPUnit runs every test in this file in
		// one continuous PHP process, so without resetting it, an earlier
		// test's HTML fixture would leak into a later test for a reused URL
		// (e.g. several subscribe_to_site() tests here all fetch
		// 'https://example.com/'). Clear it before every test so each one
		// starts from a clean fetch cache.
		Daymark_Subscription_Html_Cache::reset();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * Short-circuits every HTTP request made in this file (same approach as
	 * tests/test-rest-subscriptions.php): a mapped URL returns its canned
	 * response, anything unmapped is blocked with a WP_Error rather than
	 * hitting the real network.
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
	 * @param string $content_type Content-Type header value.
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

	/**
	 * HTML whose feed `<link>` carries WordPress's default "{Site Name} »
	 * Feed" title convention — the case site_title must NOT inherit
	 * verbatim (that belongs in feed_title instead).
	 */
	private function html_with_default_feed_title(): string {
		return '<html><head><title>Example</title>'
			. '<link rel="alternate" type="application/rss+xml" title="Example » Feed" href="/feed/" />'
			. '</head><body></body></html>';
	}

	/**
	 * Scenario: install() creates a table with the expected columns.
	 *
	 * Verified via DESCRIBE rather than SHOW TABLES: the WP core test suite
	 * transparently rewrites CREATE TABLE to CREATE TEMPORARY TABLE for
	 * test isolation (see WP_UnitTestCase_Base::_create_temporary_tables()),
	 * and MySQL's SHOW TABLES does not list temporary tables — DESCRIBE
	 * (and ordinary DML) works against them exactly as it would a real one.
	 */
	public function test_install_creates_table() {
		global $wpdb;

		$table   = Daymark_Subscriptions::table_name();
		$columns = $wpdb->get_col( "DESCRIBE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Test assertion against a custom table's schema; table name only, not user input.

		$this->assertSame(
			array(
				'id',
				'site_url',
				'feed_url',
				'source_type',
				'site_title',
				'feed_title',
				'site_icon_url',
				'status',
				'consecutive_failure_count',
				'last_checked_at',
				'last_manual_refresh_at',
				'last_error',
				'websub_hub_url',
				'websub_status',
				'websub_lease_expires_at',
				'websub_secret',
				'created_at',
			),
			$columns
		);
	}

	/**
	 * Scenario: a site already running Daymark when subscriptions shipped
	 * never re-fires register_activation_hook() on update, so the table
	 * would never exist without a self-heal. Daymark_Plugin::on_init() calls
	 * install() on every request for exactly this reason (matching the same
	 * self-heal pattern Daymark_Backflow_Sync::schedule() and
	 * Daymark_Subscription_Poller::schedule() already use). Simulate that
	 * install by dropping the table and clearing the recorded schema
	 * version, then confirm a subscription can be created once on_init()
	 * runs again.
	 */
	public function test_missing_table_self_heals_on_init() {
		global $wpdb;

		$table = Daymark_Subscriptions::table_name();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input; simulating a pre-activation install (test-only DDL).
		delete_option( 'daymark_subscriptions_db_version' );

		Daymark_Plugin::instance()->on_init();

		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/** Scenario: create -> get -> update -> delete round trip. */
	public function test_crud_round_trip() {
		$id = $this->subscriptions->create(
			array(
				'site_url'      => 'https://example.com/',
				'feed_url'      => 'https://example.com/feed/',
				'source_type'   => 'feed',
				'site_title'    => 'Example Site',
				'feed_title'    => 'Example Site » Feed',
				'site_icon_url' => 'https://example.com/favicon.ico',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $this->subscriptions->get( $id );
		$this->assertIsArray( $row );
		$this->assertSame( 'https://example.com/', $row['site_url'] );
		$this->assertSame( 'https://example.com/feed/', $row['feed_url'] );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( 'Example Site', $row['site_title'] );
		$this->assertSame( 'Example Site » Feed', $row['feed_title'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
		$this->assertNotEmpty( $row['created_at'] );

		$by_feed_url = $this->subscriptions->get_by_feed_url( 'https://example.com/feed/' );
		$this->assertIsArray( $by_feed_url );
		$this->assertSame( $id, (int) $by_feed_url['id'] );

		$active = $this->subscriptions->get_active();
		$this->assertCount( 1, $active );
		$this->assertSame( $id, (int) $active[0]['id'] );

		$updated = $this->subscriptions->update(
			$id,
			array(
				'status'                    => 'error',
				'consecutive_failure_count' => 3,
				'last_checked_at'           => '2026-01-01 00:00:00',
			)
		);
		$this->assertTrue( $updated );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'error', $row['status'] );
		$this->assertSame( '3', (string) $row['consecutive_failure_count'] );
		$this->assertSame( '2026-01-01 00:00:00', $row['last_checked_at'] );

		// An error-status subscription no longer appears in the active list.
		$this->assertCount( 0, $this->subscriptions->get_active() );

		$this->assertTrue( $this->subscriptions->delete( $id ) );
		$this->assertNull( $this->subscriptions->get( $id ) );
	}

	/** Scenario: update() writes feed_title, same as it already does site_title. */
	public function test_update_writes_feed_title() {
		$id = $this->subscriptions->create( array( 'feed_url' => 'https://example.net/feed/' ) );
		$this->assertIsInt( $id );

		$this->assertTrue( $this->subscriptions->update( $id, array( 'feed_title' => 'Example » Category Feed' ) ) );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'Example » Category Feed', $row['feed_title'] );
	}

	/** Scenario: consecutive_failure_count increments and resets. */
	public function test_failure_count_increment_and_reset() {
		$id = $this->subscriptions->create( array( 'feed_url' => 'https://example.org/feed/' ) );
		$this->assertIsInt( $id );

		$this->assertSame( 1, $this->subscriptions->increment_failure_count( $id ) );
		$this->assertSame( 2, $this->subscriptions->increment_failure_count( $id ) );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( '2', (string) $row['consecutive_failure_count'] );

		$this->assertTrue( $this->subscriptions->reset_failure_count( $id ) );
		$row = $this->subscriptions->get( $id );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
	}

	/**
	 * Scenario: inserting a second row with a feed_url already in use fails
	 * cleanly with a WP_Error — not a fatal, and no second row is created —
	 * whether caught by the application-level pre-check or (in a race) by
	 * the table's own UNIQUE index.
	 */
	public function test_duplicate_feed_url_rejected_cleanly() {
		$feed_url = 'https://duplicate-example.com/feed/';

		$first = $this->subscriptions->create( array( 'feed_url' => $feed_url ) );
		$this->assertIsInt( $first );

		$second = $this->subscriptions->create( array( 'feed_url' => $feed_url ) );
		$this->assertWPError( $second );
		$this->assertSame( 'daymark_subscription_duplicate', $second->get_error_code() );

		global $wpdb;
		$table = Daymark_Subscriptions::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion counting rows in a custom table.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE feed_url = %s", $feed_url ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
		);
		$this->assertSame( 1, $count );
	}

	/** Scenario: an empty/missing feed_url is rejected rather than inserted. */
	public function test_missing_feed_url_rejected() {
		$result = $this->subscriptions->create( array( 'site_url' => 'https://example.com/' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_invalid_feed_url', $result->get_error_code() );
	}

	/** Scenario: an unrecognized status falls back to 'active' rather than storing garbage. */
	public function test_invalid_status_falls_back_to_active() {
		$id = $this->subscriptions->create(
			array(
				'feed_url' => 'https://example.net/feed/',
				'status'   => 'not-a-real-status',
			)
		);
		$this->assertIsInt( $id );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'active', $row['status'] );
	}

	/**
	 * Scenario: get_flagged() returns only `status = 'error'` rows, and
	 * leaves active rows out — the mirror image of get_active(). This is
	 * the query Daymark_Notifications::get_notifications() (issue #78,
	 * "Dead feed detection") builds `dead_feed` items from.
	 */
	public function test_get_flagged_returns_only_error_status() {
		$active_id = $this->subscriptions->create( array( 'feed_url' => 'https://active-example.com/feed/' ) );
		$this->assertIsInt( $active_id );

		$flagged_id = $this->subscriptions->create( array( 'feed_url' => 'https://flagged-example.com/feed/' ) );
		$this->assertIsInt( $flagged_id );
		$this->assertTrue(
			$this->subscriptions->update(
				$flagged_id,
				array(
					'status'                    => 'error',
					'consecutive_failure_count' => 7,
					'last_checked_at'           => '2026-01-01 00:00:00',
				)
			)
		);

		$flagged = $this->subscriptions->get_flagged();

		$this->assertCount( 1, $flagged );
		$this->assertSame( $flagged_id, (int) $flagged[0]['id'] );
		$this->assertSame( 'error', $flagged[0]['status'] );

		$flagged_ids = array_column( $flagged, 'id' );
		$this->assertNotContains( (string) $active_id, array_map( 'strval', $flagged_ids ) );
	}

	/**
	 * Scenario (issue #182): get_with_issues() returns any subscription with
	 * a positive consecutive_failure_count, whether or not it's already
	 * reached the 7-failure dead threshold — unlike get_flagged(), which
	 * only ever returns the fully-dead ('status' = 'error') case.
	 */
	public function test_get_with_issues_includes_both_failing_and_dead_subscriptions() {
		$healthy_id = $this->subscriptions->create( array( 'feed_url' => 'https://healthy-example.com/feed/' ) );
		$this->assertIsInt( $healthy_id );

		$failing_id = $this->subscriptions->create( array( 'feed_url' => 'https://failing-example.com/feed/' ) );
		$this->assertIsInt( $failing_id );
		$this->assertTrue(
			$this->subscriptions->update(
				$failing_id,
				array(
					'consecutive_failure_count' => 2,
					'last_error'                => 'Connection timed out.',
				)
			)
		);

		$dead_id = $this->subscriptions->create( array( 'feed_url' => 'https://dead-example.com/feed/' ) );
		$this->assertIsInt( $dead_id );
		$this->assertTrue(
			$this->subscriptions->update(
				$dead_id,
				array(
					'status'                    => 'error',
					'consecutive_failure_count' => 7,
					'last_error'                => 'This subscription\'s feed could not be reached or parsed.',
				)
			)
		);

		$issues    = $this->subscriptions->get_with_issues();
		$issue_ids = array_map( 'strval', array_column( $issues, 'id' ) );

		$this->assertCount( 2, $issues );
		$this->assertContains( (string) $failing_id, $issue_ids );
		$this->assertContains( (string) $dead_id, $issue_ids );
		$this->assertNotContains( (string) $healthy_id, $issue_ids );
	}

	/** Scenario: an unrecognized source_type is still accepted (future values reserved, not enum-locked). */
	public function test_future_source_type_is_accepted() {
		$id = $this->subscriptions->create(
			array(
				'feed_url'    => 'https://example.dev/feed/',
				'source_type' => 'activitypub',
			)
		);
		$this->assertIsInt( $id );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'activitypub', $row['source_type'] );
	}

	/**
	 * Scenario: get_all() returns every subscription regardless of status,
	 * unlike get_active()/get_flagged() which each show only one status.
	 */
	public function test_get_all_returns_every_status() {
		$active_id = $this->subscriptions->create( array( 'feed_url' => 'https://all-active.example/feed/' ) );
		$this->assertIsInt( $active_id );

		$error_id = $this->subscriptions->create( array( 'feed_url' => 'https://all-error.example/feed/' ) );
		$this->assertIsInt( $error_id );
		$this->assertTrue( $this->subscriptions->update( $error_id, array( 'status' => 'error' ) ) );

		$all = $this->subscriptions->get_all();
		$ids = array_map( 'intval', array_column( $all, 'id' ) );

		$this->assertCount( 2, $all );
		$this->assertContains( $active_id, $ids );
		$this->assertContains( $error_id, $ids );
	}

	/**
	 * Scenario: subscribe_to_site() happy path — discovers the feed, creates
	 * the row, and best-effort resolves the favicon. This is the method the
	 * REST subscribe endpoint and the wp-admin Settings screen's
	 * subscribe-by-URL form both share (issue #78).
	 */
	public function test_subscribe_to_site_creates_row_and_resolves_favicon() {
		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );

		$result = $this->subscriptions->subscribe_to_site( 'https://example.com/' );

		$this->assertIsInt( $result );

		$row = $this->subscriptions->get( $result );
		$this->assertIsArray( $row );
		$this->assertSame( 'https://example.com/', $row['site_url'] );
		$this->assertSame( 'https://example.com/feed/', $row['feed_url'] );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( 'https://example.com/icon.png', $row['site_icon_url'] );
		// The fixture's <title> is the plain site name — the feed <link>
		// carries no title attribute at all here, so this also confirms
		// site_title comes from the page title, not an empty feed title.
		$this->assertSame( 'Example', $row['site_title'] );
	}

	/**
	 * Scenario: site_title captures just the plain site name from the
	 * page's own <title> tag — not the feed <link>'s default "{Site Name}
	 * » Feed" title convention, which is kept separately as feed_title so
	 * a future multi-feed-per-site subscription has something to tell
	 * otherwise-identical rows apart by.
	 */
	public function test_subscribe_to_site_separates_site_title_from_feed_title() {
		$this->mock_response( 'https://example.com/', $this->html_with_default_feed_title() );

		$result = $this->subscriptions->subscribe_to_site( 'https://example.com/' );

		$this->assertIsInt( $result );

		$row = $this->subscriptions->get( $result );
		$this->assertSame( 'Example', $row['site_title'] );
		$this->assertSame( 'Example » Feed', $row['feed_title'] );
	}

	/** Scenario: a non-http(s) site_url is rejected before any discovery is attempted. */
	public function test_subscribe_to_site_rejects_invalid_url() {
		$result = $this->subscriptions->subscribe_to_site( 'ftp://example.com/' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_invalid_url', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Scenario: a bare domain typed with no scheme (the common case for
	 * someone typing e.g. "example.com" into the subscribe form, which
	 * previously failed with "Please enter a valid site URL.") is assumed
	 * to be `https://` rather than rejected.
	 */
	public function test_subscribe_to_site_assumes_https_for_a_bare_domain() {
		// normalize_site_url() only prepends the scheme — it does not add a
		// trailing slash, so the outbound fetch (and this mock) is for the
		// exact normalized string, not a "clean" https://example.com/.
		$this->mock_response( 'https://example.com', $this->html_with_feed_and_icon() );

		$result = $this->subscriptions->subscribe_to_site( 'example.com' );

		$this->assertIsInt( $result );

		$row = $this->subscriptions->get( $result );
		$this->assertSame( 'https://example.com', $row['site_url'] );
	}

	/** Scenario: garbage input that still has no real host after normalization is rejected cleanly. */
	public function test_subscribe_to_site_rejects_garbage_input() {
		$result = $this->subscriptions->subscribe_to_site( 'not a url at all' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_invalid_url', $result->get_error_code() );
	}

	/**
	 * Scenario (issue #81, SSRF hardening): a site URL that resolves to a
	 * private/internal address is rejected the exact same way any other
	 * invalid site URL already is — same error code, no fetch attempted (no
	 * response mocked for this host, so a real attempt would be caught by
	 * intercept_http_request()'s "unmocked request blocked" WP_Error, not
	 * the assertion below).
	 */
	public function test_subscribe_to_site_rejects_unsafe_url() {
		$result = $this->subscriptions->subscribe_to_site( 'http://127.0.0.1/' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_invalid_url', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->subscriptions->get_active(), 'Nothing was created' );
	}

	/** Scenario: a URL with no discoverable feed fails clearly, not with a fatal. */
	public function test_subscribe_to_site_no_feed_found() {
		$this->mock_response( 'https://no-feed.example/', $this->html_without_feed() );

		$result = $this->subscriptions->subscribe_to_site( 'https://no-feed.example/' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_no_feed_found', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->subscriptions->get_active(), 'Nothing was created' );
	}

	/** Scenario: subscribing to an already-subscribed feed propagates create()'s duplicate error as-is. */
	public function test_subscribe_to_site_duplicate_feed() {
		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );

		$first = $this->subscriptions->subscribe_to_site( 'https://example.com/' );
		$this->assertIsInt( $first );

		$second = $this->subscriptions->subscribe_to_site( 'https://example.com/' );

		$this->assertWPError( $second );
		$this->assertSame( 'daymark_subscription_duplicate', $second->get_error_code() );
	}

	// -----------------------------------------------------------------
	// Subscribing to a second, differently-scoped feed on the same site
	// (issue #183).
	// -----------------------------------------------------------------

	/**
	 * Scenario: pasting a URL that is itself a feed (not a page to run
	 * autodiscovery against) subscribes to exactly that feed directly —
	 * no page-based discovery, no HTML fetch at all. Lets a caller who
	 * already knows a specific feed's own URL (e.g. a Notes archive's
	 * `/notes/feed/`, distinct from the same site's main `/feed/`)
	 * subscribe to exactly that feed.
	 */
	public function test_subscribe_to_site_accepts_a_direct_feed_url() {
		$feed = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0">
<channel>
<title>Jeremy's Notes</title>
<link>https://jeremyfelt.example/notes/</link>
</channel>
</rss>
XML;
		$this->mock_response( 'https://jeremyfelt.example/notes/feed/', $feed, 'application/rss+xml; charset=UTF-8' );

		$result = $this->subscriptions->subscribe_to_site( 'https://jeremyfelt.example/notes/feed/' );

		$this->assertIsInt( $result );

		$row = $this->subscriptions->get( $result );
		$this->assertSame( 'https://jeremyfelt.example/notes/feed/', $row['feed_url'] );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( "Jeremy's Notes", $row['site_title'] );
	}

	/**
	 * Scenario: two different sites/domains each publishing their own feed
	 * (e.g. a friend's main site and a separate notes-only site) subscribe
	 * independently with no special handling needed — each resolves to its
	 * own distinct feed_url.
	 */
	public function test_subscribe_to_site_allows_two_independent_domains() {
		$this->mock_response( 'https://peterwilson.example/', $this->html_with_feed_and_icon() );
		$this->mock_response( 'https://peterwilson-notes.example/', $this->html_with_feed_and_icon() );

		$posts = $this->subscriptions->subscribe_to_site( 'https://peterwilson.example/' );
		$notes = $this->subscriptions->subscribe_to_site( 'https://peterwilson-notes.example/' );

		$this->assertIsInt( $posts );
		$this->assertIsInt( $notes );
		$this->assertNotSame(
			$this->subscriptions->get( $posts )['feed_url'],
			$this->subscriptions->get( $notes )['feed_url']
		);
	}

	/**
	 * Scenario: a WordPress site's own REST API source always resolves to
	 * the same site-wide `wp/v2/posts` collection regardless of which page
	 * discovery started from — so subscribing to a second page on that same
	 * site (e.g. a Notes archive, after already subscribing to the main
	 * Blog) would otherwise "discover" the exact feed already subscribed
	 * and fail as a duplicate. Retrying with that source excluded lets the
	 * next-preferred source (the RSS/Atom feed source, reading this exact
	 * page's own `<link rel="alternate">`) find the page-specific feed the
	 * user actually meant instead.
	 */
	public function test_subscribe_to_site_finds_a_page_specific_feed_after_a_site_wide_duplicate() {
		$blog_html = '<html><head><title>Jeremy</title>'
			. '<link rel="https://api.w.org/" href="https://jeremyfelt.example/wp-json/">'
			. '</head><body></body></html>';

		// The Notes page shares the exact same site-wide REST API discovery
		// link (same WordPress install) but also advertises its own,
		// page-specific RSS feed — realistic for a WordPress archive page
		// that has both.
		$notes_html = '<html><head><title>Notes</title>'
			. '<link rel="https://api.w.org/" href="https://jeremyfelt.example/wp-json/">'
			. '<link rel="alternate" type="application/rss+xml" href="https://jeremyfelt.example/notes/feed/">'
			. '</head><body></body></html>';

		$this->mock_response( 'https://jeremyfelt.example/blog/', $blog_html );
		$this->mock_response( 'https://jeremyfelt.example/notes/', $notes_html );
		$this->mock_response( 'https://jeremyfelt.example/wp-json/wp/v2/posts?per_page=1', '[]' );

		$blog = $this->subscriptions->subscribe_to_site( 'https://jeremyfelt.example/blog/' );
		$this->assertIsInt( $blog );

		$blog_row = $this->subscriptions->get( $blog );
		$this->assertSame( 'https://jeremyfelt.example/wp-json/wp/v2/posts', $blog_row['feed_url'] );
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the lowercase machine ID (source_type value), not prose.
		$this->assertSame( 'wordpress', $blog_row['source_type'] );

		$notes = $this->subscriptions->subscribe_to_site( 'https://jeremyfelt.example/notes/' );
		$this->assertIsInt( $notes, 'A second, page-specific feed was found instead of failing as a duplicate' );

		$notes_row = $this->subscriptions->get( $notes );
		$this->assertSame( 'https://jeremyfelt.example/notes/feed/', $notes_row['feed_url'] );
		$this->assertSame( 'feed', $notes_row['source_type'] );
		$this->assertNotSame( $blog_row['feed_url'], $notes_row['feed_url'] );
	}

	/**
	 * Scenario: unsubscribe() trashes every cached daymark_subscription_post
	 * ingested from this subscription and deletes the subscription row —
	 * the shared method both DELETE /daymark/v1/subscriptions/{id} and the
	 * wp-admin Settings -> Daymark screen's Unsubscribe action call, so
	 * neither surface can leave an orphaned cached post behind.
	 */
	public function test_unsubscribe_trashes_cached_posts_and_deletes_row() {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);
		$this->assertIsInt( $id );

		$post_id = self::factory()->post->create(
			array( 'post_type' => Daymark_Subscription_Post_Type::POST_TYPE )
		);
		update_post_meta( $post_id, 'subscription_id', $id );

		$other_subscription_post_id = self::factory()->post->create(
			array( 'post_type' => Daymark_Subscription_Post_Type::POST_TYPE )
		);
		update_post_meta( $other_subscription_post_id, 'subscription_id', $id + 1 );

		$result = $this->subscriptions->unsubscribe( $id );

		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 1, $result['trashed_posts'] );
		$this->assertNull( $this->subscriptions->get( $id ), 'The subscription row is gone' );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'This subscription\'s cached post is trashed, not orphaned' );
		$this->assertSame(
			'publish',
			get_post_status( $other_subscription_post_id ),
			'A different subscription\'s post is untouched'
		);
	}

	/**
	 * Scenario (issue #94): refresh_icon() re-runs favicon discovery for an
	 * existing subscription and updates its stored site_icon_url — the same
	 * discovery subscribe_to_site() only ever runs once, at subscribe time.
	 */
	public function test_refresh_icon_updates_site_icon_url() {
		$id = $this->subscriptions->create(
			array(
				'site_url'      => 'https://example.com/',
				'feed_url'      => 'https://example.com/feed/',
				'site_icon_url' => 'https://example.com/old-icon.png',
			)
		);
		$this->assertIsInt( $id );

		$this->mock_response( 'https://example.com/', $this->html_with_feed_and_icon() );

		$result = $this->subscriptions->refresh_icon( $id );

		$this->assertTrue( $result );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'https://example.com/icon.png', $row['site_icon_url'] );
	}

	/** Scenario: refresh_icon() on a nonexistent subscription is a clean 404-style WP_Error. */
	public function test_refresh_icon_missing_subscription_returns_not_found() {
		$result = $this->subscriptions->refresh_icon( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Scenario: a site with no discoverable icon (no host at all to derive
	 * even the /favicon.ico fallback from) is not a fatal — a real, expected
	 * outcome reported as a distinguishable WP_Error rather than 500ing.
	 */
	public function test_refresh_icon_no_icon_found_returns_error() {
		// A non-empty, scheme-less string like 'not-a-real-url' is not a
		// usable fixture here: esc_url_raw() (via create()'s own
		// sanitization) auto-prepends 'http://' to a bare string with no
		// scheme, which gives fallback_favicon_url() a host to guess
		// {scheme}://{host}/favicon.ico from after all — that fallback is
		// never itself verified to resolve, so this needs a site_url with
		// no host at all (an empty string) to genuinely exercise "no icon
		// could be found".
		$id = $this->subscriptions->create(
			array(
				'site_url' => '',
				'feed_url' => 'https://example.net/feed/',
			)
		);
		$this->assertIsInt( $id );

		$result = $this->subscriptions->refresh_icon( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_icon_not_found', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/** Scenario: a subscription whose source_type has no icon-capable source fails cleanly rather than fatally. */
	public function test_refresh_icon_unsupported_source_type_returns_error() {
		$id = $this->subscriptions->create(
			array(
				'site_url'    => 'https://example.com/',
				'feed_url'    => 'https://example.com/feed/',
				'source_type' => 'activitypub',
			)
		);
		$this->assertIsInt( $id );

		$result = $this->subscriptions->refresh_icon( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_icon_not_found', $result->get_error_code() );
	}
}
