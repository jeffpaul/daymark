<?php
/**
 * Tests for the WordPress.com Reader follows import (issue #435):
 * Daymark_Reader_Import's status, paged fetch, and response parsing, the
 * shared Daymark_Subscription_OPML::import_entries() path it feeds, and the
 * Settings -> Daymark -> Import/Export section that drives it.
 *
 * The real Jetpack classes are never loaded here, so status() is
 * 'unavailable' unless a test filters `daymark_reader_import_status`, and
 * each page of the WordPress.com response is stubbed through
 * `daymark_reader_import_pre_fetch_page`. The stubbed body follows the shape
 * WordPress.com's own client (wp-calypso) reads from `read/following/mine`.
 *
 * @package Daymark
 */

/**
 * Exercises the Reader follows import end to end, short of Jetpack itself.
 */
class Test_Reader_Import extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/**
	 * Stubbed pages, keyed by 1-based page number.
	 *
	 * @var array<int, mixed>
	 */
	private array $pages = array();

	/**
	 * Pages actually requested, in order.
	 *
	 * @var int[]
	 */
	private array $requested_pages = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriptions   = new Daymark_Subscriptions();
		$this->pages           = array();
		$this->requested_pages = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'daymark_reader_import_pre_fetch_page', array( $this, 'stub_page' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'daymark_reader_import_pre_fetch_page', array( $this, 'stub_page' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );
		remove_all_filters( 'daymark_reader_import_status' );
		remove_all_filters( 'daymark_subscription_opml_max_entries' );
		delete_transient( 'daymark_reader_import_follows_' . get_current_user_id() );
		unset( $_GET['tab'], $_GET['daymark_notice'] );

		parent::tear_down();
	}

	/**
	 * @param mixed $pre  Existing short-circuit value.
	 * @param int   $page Requested page.
	 * @return mixed
	 */
	public function stub_page( $pre, $page ) {
		$this->requested_pages[] = (int) $page;

		return $this->pages[ (int) $page ] ?? array(
			'subscriptions' => array(),
			'page'          => (int) $page,
			'number'        => Daymark_Reader_Import::PAGE_SIZE,
		);
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return WP_Error
	 */
	public function block_http_request( $preempt, $parsed_args, $url ) {
		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/** Make status() report the given state. */
	private function set_status( string $status ): void {
		add_filter(
			'daymark_reader_import_status',
			static function () use ( $status ) {
				return $status;
			}
		);
	}

	/**
	 * One raw `read/following/mine` subscription item.
	 *
	 * @param int    $n    Distinguishing number.
	 * @param string $name Site name.
	 * @return array<string, mixed>
	 */
	private function follow( int $n, string $name = '' ): array {
		return array(
			'ID'        => (string) ( 1000 + $n ),
			'URL'       => 'https://site' . $n . '.example/feed/',
			'blog_ID'   => (string) ( 2000 + $n ),
			'feed_ID'   => (string) ( 3000 + $n ),
			'name'      => '' !== $name ? $name : 'Site ' . $n,
			'site_icon' => 'https://site' . $n . '.example/icon.png',
			'meta'      => array(
				'links' => array(
					'site' => 'https://public-api.wordpress.com/rest/v1.1/sites/' . ( 2000 + $n ),
					'feed' => 'https://public-api.wordpress.com/rest/v1.1/read/feed/' . ( 3000 + $n ),
				),
			),
		);
	}

	/**
	 * A page of follows numbered $from..$to.
	 *
	 * @param int $from  First number.
	 * @param int $to    Last number.
	 * @param int $total total_subscriptions to report.
	 * @return array<string, mixed>
	 */
	private function page_of( int $from, int $to, int $total ): array {
		$items = array();

		for ( $n = $from; $n <= $to; $n++ ) {
			$items[] = $this->follow( $n );
		}

		return array(
			'subscriptions'       => $items,
			'total_subscriptions' => $total,
			'number'              => Daymark_Reader_Import::PAGE_SIZE,
		);
	}

	/** Renders the settings page's Import/Export tab. */
	private function render_import_export_tab(): string {
		$_GET['tab'] = 'import-export';

		ob_start();
		( new Daymark_Admin_Subscriptions() )->render_page();

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------
	// status()
	// -----------------------------------------------------------------

	public function test_status_is_unavailable_without_jetpack() {
		$this->assertSame( Daymark_Reader_Import::STATUS_UNAVAILABLE, Daymark_Reader_Import::status() );
	}

	public function test_status_filter_is_honored() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );

		$this->assertSame( Daymark_Reader_Import::STATUS_READY, Daymark_Reader_Import::status() );
	}

	public function test_unknown_status_falls_back_to_unavailable() {
		$this->set_status( 'something-else' );

		$this->assertSame( Daymark_Reader_Import::STATUS_UNAVAILABLE, Daymark_Reader_Import::status() );
	}

	// -----------------------------------------------------------------
	// fetch_follows()
	// -----------------------------------------------------------------

	public function test_fetch_refuses_when_not_connected() {
		$this->set_status( Daymark_Reader_Import::STATUS_NOT_CONNECTED );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_reader_import_unavailable', $result->get_error_code() );
		$this->assertSame( array(), $this->requested_pages, 'Nothing is requested for a user who is not connected.' );
	}

	public function test_fetch_without_jetpack_errors_instead_of_fataling() {
		remove_filter( 'daymark_reader_import_pre_fetch_page', array( $this, 'stub_page' ), 10 );

		$this->assertWPError( Daymark_Reader_Import::fetch_follows() );
	}

	public function test_fetch_single_short_page() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = $this->page_of( 1, 3, 3 );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result['entries'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( array( 1 ), $this->requested_pages, 'A page shorter than the page size is the last one.' );
	}

	public function test_fetch_walks_every_page() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = $this->page_of( 1, 100, 120 );
		$this->pages[2] = $this->page_of( 101, 120, 120 );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertCount( 120, $result['entries'] );
		$this->assertSame( array( 1, 2 ), $this->requested_pages );
		$this->assertSame( 'https://site120.example/feed/', $result['entries'][119]['xml_url'] );
	}

	public function test_fetch_stops_after_an_exactly_full_last_page() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = $this->page_of( 1, 100, 100 );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertCount( 100, $result['entries'] );
		$this->assertSame( array( 1, 2 ), $this->requested_pages, 'An empty second page ends the walk.' );
	}

	public function test_fetch_truncates_at_the_entry_cap() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		add_filter(
			'daymark_subscription_opml_max_entries',
			static function () {
				return 150;
			}
		);
		$this->pages[1] = $this->page_of( 1, 100, 250 );
		$this->pages[2] = $this->page_of( 101, 200, 250 );
		$this->pages[3] = $this->page_of( 201, 250, 250 );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertCount( 150, $result['entries'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 250, $result['total'] );
		$this->assertNotContains( 3, $this->requested_pages, 'Nothing past the cap is requested.' );
	}

	public function test_fetch_drops_duplicate_feed_urls() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = array(
			'subscriptions' => array(
				$this->follow( 1 ),
				array_merge( $this->follow( 1 ), array( 'URL' => 'https://SITE1.example/feed' ) ),
				$this->follow( 2 ),
			),
		);

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertCount( 2, $result['entries'] );
	}

	public function test_fetch_passes_through_a_wordpress_com_error() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = new WP_Error( 'daymark_reader_import_failed', 'WordPress.com is down.' );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertWPError( $result );
		$this->assertSame( 'WordPress.com is down.', $result->get_error_message() );
	}

	public function test_fetch_treats_an_unexpected_stub_value_as_a_failure() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = 'not an array';

		$this->assertWPError( Daymark_Reader_Import::fetch_follows() );
	}

	public function test_fetch_tolerates_a_response_with_no_subscriptions_key() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->pages[1] = array( 'error' => 'unexpected' );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['entries'] );
	}

	// -----------------------------------------------------------------
	// parse_follows()
	// -----------------------------------------------------------------

	public function test_parse_maps_an_item_to_an_import_entry() {
		$entries = Daymark_Reader_Import::parse_follows( array( $this->follow( 7, 'Ada &amp; Co' ) ) );

		$this->assertSame(
			array(
				'label'    => 'Ada & Co',
				'xml_url'  => 'https://site7.example/feed/',
				'html_url' => 'https://site7.example',
				'icon_url' => 'https://site7.example/icon.png',
			),
			$entries[0]
		);
	}

	public function test_parse_falls_back_to_the_host_for_a_nameless_follow() {
		$item         = $this->follow( 3 );
		$item['name'] = '';

		$entries = Daymark_Reader_Import::parse_follows( array( $item ) );

		$this->assertSame( 'site3.example', $entries[0]['label'] );
	}

	public function test_parse_skips_items_without_a_usable_feed_url() {
		$entries = Daymark_Reader_Import::parse_follows(
			array(
				array( 'URL' => '' ),
				array( 'URL' => 'javascript:alert(1)' ),
				array( 'URL' => 'ftp://files.example/feed' ),
				array( 'name' => 'No URL at all' ),
				'not an array',
				$this->follow( 1 ),
			)
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'https://site1.example/feed/', $entries[0]['xml_url'] );
	}

	public function test_parse_drops_a_non_http_icon() {
		$item              = $this->follow( 4 );
		$item['site_icon'] = 'javascript:alert(1)';

		$entries = Daymark_Reader_Import::parse_follows( array( $item ) );

		$this->assertSame( '', $entries[0]['icon_url'] );
	}

	// -----------------------------------------------------------------
	// Import through the shared OPML per-entry path.
	// -----------------------------------------------------------------

	public function test_imported_follows_become_ordinary_subscriptions() {
		wp_clear_scheduled_hook( Daymark_Subscription_Poller::CRON_HOOK . '_now' );

		$entries = Daymark_Reader_Import::parse_follows( array( $this->follow( 1 ), $this->follow( 2 ) ) );
		$results = ( new Daymark_Subscription_OPML() )->import_entries( $entries );

		$this->assertSame( array( 'subscribed', 'subscribed' ), wp_list_pluck( $results, 'status' ) );

		$row = $this->subscriptions->get_by_feed_url( 'https://site1.example/feed/' );
		$this->assertNotNull( $row );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( 'Site 1', $row['site_title'] );
		$this->assertSame( 'https://site1.example', $row['site_url'] );

		$this->assertNotFalse( wp_next_scheduled( Daymark_Subscription_Poller::CRON_HOOK . '_now' ), 'New subscriptions are polled right away.' );
	}

	public function test_an_already_followed_site_is_reported_as_a_duplicate() {
		$entries = Daymark_Reader_Import::parse_follows( array( $this->follow( 1 ) ) );
		$opml    = new Daymark_Subscription_OPML();

		$opml->import_entries( $entries );
		$results = $opml->import_entries( $entries );

		$this->assertSame( 'duplicate', $results[0]['status'] );
	}

	public function test_an_unsafe_feed_url_is_rejected_by_the_url_guard() {
		$item        = $this->follow( 1 );
		$item['URL'] = 'http://127.0.0.1/feed/';

		$results = ( new Daymark_Subscription_OPML() )->import_entries( Daymark_Reader_Import::parse_follows( array( $item ) ) );

		$this->assertSame( 'failed', $results[0]['status'] );
		$this->assertSame( array(), $this->subscriptions->get_all() );
	}

	// -----------------------------------------------------------------
	// Settings -> Daymark -> Import/Export.
	// -----------------------------------------------------------------

	public function test_section_hidden_without_jetpack() {
		$output = $this->render_import_export_tab();

		$this->assertStringNotContainsString( 'Import from WordPress.com Reader', $output );
		$this->assertStringNotContainsString( 'daymark_reader_import_load', $output );
	}

	public function test_connect_link_shown_when_account_not_linked() {
		$this->set_status( Daymark_Reader_Import::STATUS_NOT_CONNECTED );

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'Import from WordPress.com Reader', $output );
		$this->assertStringContainsString( 'Connect your WordPress.com account', $output );
		$this->assertStringContainsString( esc_url( Daymark_Jetpack_Engagement::connect_account_url() ), $output );
		$this->assertStringNotContainsString( 'daymark_reader_import_load', $output );
	}

	public function test_load_button_shown_when_ready() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'daymark_reader_import_load', $output );
		$this->assertStringContainsString( 'Load my Reader follows', $output );
	}

	public function test_checklist_shown_once_follows_are_loaded() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->subscriptions->create(
			array(
				'site_url' => 'https://site2.example',
				'feed_url' => 'https://site2.example/feed/',
			)
		);
		set_transient(
			'daymark_reader_import_follows_' . get_current_user_id(),
			array(
				'entries'   => Daymark_Reader_Import::parse_follows( array( $this->follow( 1, 'First <b>Site</b>' ), $this->follow( 2 ) ) ),
				'total'     => 2,
				'truncated' => false,
			),
			HOUR_IN_SECONDS
		);

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'daymark_reader_import_confirm', $output );
		$this->assertStringContainsString( 'daymark_reader_import_cancel', $output );
		$this->assertStringContainsString( 'You follow 2 sites in the WordPress.com Reader.', $output );
		$this->assertMatchesRegularExpression( '/name="daymark_reader_entry\[\]"\s+value="0"\s+checked="checked"\s+data-daymark-reader-import-entry/', $output );
		$this->assertMatchesRegularExpression( '/value="1"\s+checked="checked"\s+disabled=\'disabled\'/', $output, 'An already-subscribed site is shown but cannot be selected again.' );
		$this->assertStringContainsString( 'already subscribed', $output );
		$this->assertStringNotContainsString( '<b>Site</b>', $output );
		$this->assertStringNotContainsString( 'Only the first', $output );
		$this->assertStringNotContainsString( 'Load my Reader follows', $output );
	}

	public function test_checklist_explains_a_truncated_list() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		set_transient(
			'daymark_reader_import_follows_' . get_current_user_id(),
			array(
				'entries'   => Daymark_Reader_Import::parse_follows( array( $this->follow( 1 ) ) ),
				'total'     => 1500,
				'truncated' => true,
			),
			HOUR_IN_SECONDS
		);

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'Only the first 1 of your 1500 follows are listed.', $output );
	}

	public function test_selected_reader_entries_keeps_only_valid_checked_indices() {
		$entries = Daymark_Reader_Import::parse_follows( array( $this->follow( 1 ), $this->follow( 2 ), $this->follow( 3 ) ) );

		$selected = Daymark_Admin_Subscriptions::selected_reader_entries( $entries, array( '2', 0, 2, 99, 'abc', -1 ) );

		$this->assertSame(
			array( 'https://site1.example/feed/', 'https://site3.example/feed/' ),
			wp_list_pluck( $selected, 'xml_url' )
		);
	}

	public function test_reader_import_results_use_the_opml_results_summary() {
		set_transient(
			'daymark_opml_import_result_' . get_current_user_id(),
			array(
				array(
					'label'   => 'Site 1',
					'status'  => 'subscribed',
					'message' => '',
				),
			),
			MINUTE_IN_SECONDS
		);

		$_GET['daymark_notice'] = 'reader_imported';
		$output                 = $this->render_import_export_tab();

		$this->assertStringContainsString( 'Import complete: 1 subscribed, 0 already subscribed, 0 failed.', $output );
	}
}
