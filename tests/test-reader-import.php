<?php
/**
 * Tests for the WordPress.com Reader follows import (issue #435):
 * Daymark_Reader_Import's status, fetch, and response parsing, the
 * shared Daymark_Subscription_OPML::import_entries() path it feeds, and the
 * Settings -> Daymark -> Import/Export section that drives it.
 *
 * The real Jetpack classes are never loaded here, so status() is
 * 'unavailable' unless a test filters `daymark_reader_import_status`, and
 * the WordPress.com response is stubbed through
 * `daymark_reader_import_pre_fetch`. The stubbed body follows the shape
 * Jetpack's own Blogroll block reads from wpcom/v2 `/me/following`.
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
	 * The stubbed `/me/following` response.
	 *
	 * @var mixed
	 */
	private $response = array();

	/**
	 * How many times the follow list was requested.
	 *
	 * @var int
	 */
	private int $fetches = 0;

	/**
	 * Outbound URLs requested (and blocked), in order.
	 *
	 * @var string[]
	 */
	private array $http_urls = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriptions = new Daymark_Subscriptions();
		$this->response      = array();
		$this->fetches       = 0;
		$this->http_urls     = array();

		Daymark_Subscription_Html_Cache::reset();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'daymark_reader_import_pre_fetch', array( $this, 'stub_fetch' ) );
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'daymark_reader_import_pre_fetch', array( $this, 'stub_fetch' ) );
		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );
		remove_all_filters( 'daymark_reader_import_status' );
		remove_all_filters( 'daymark_subscription_opml_max_entries' );
		delete_transient( 'daymark_reader_import_follows_' . get_current_user_id() );
		unset( $_GET['tab'], $_GET['daymark_notice'] );

		parent::tear_down();
	}

	/**
	 * @return mixed The stubbed response.
	 */
	public function stub_fetch() {
		++$this->fetches;

		return $this->response;
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return WP_Error
	 */
	public function block_http_request( $preempt, $parsed_args, $url ) {
		$this->http_urls[] = (string) $url;

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
	 * One raw `/me/following` item.
	 *
	 * @param int    $n    Distinguishing number.
	 * @param string $name Site name.
	 * @return array<string, mixed>
	 */
	private function follow( int $n, string $name = '' ): array {
		return array(
			'blog_id'           => (string) ( 2000 + $n ),
			'URL'               => 'https://site' . $n . '.example',
			'name'              => '' !== $name ? $name : 'Site ' . $n,
			'site_icon'         => 'https://site' . $n . '.example/icon.png',
			'description'       => 'About site ' . $n,
			'is_non_wpcom_site' => false,
		);
	}

	/**
	 * Follows numbered $from..$to.
	 *
	 * @param int $from First number.
	 * @param int $to   Last number.
	 * @return array<int, array<string, mixed>>
	 */
	private function follows( int $from, int $to ): array {
		$items = array();

		for ( $n = $from; $n <= $to; $n++ ) {
			$items[] = $this->follow( $n );
		}

		return $items;
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
		$this->assertSame( 0, $this->fetches, 'Nothing is requested for a user who is not connected.' );
	}

	public function test_fetch_without_jetpack_errors_instead_of_fataling() {
		remove_filter( 'daymark_reader_import_pre_fetch', array( $this, 'stub_fetch' ) );
		$this->set_status( Daymark_Reader_Import::STATUS_READY );

		$this->assertWPError( Daymark_Reader_Import::fetch_follows() );
	}

	public function test_fetch_reads_the_whole_list_in_one_request() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->response = $this->follows( 1, 120 );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertIsArray( $result );
		$this->assertCount( 120, $result['entries'] );
		$this->assertSame( 120, $result['total'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 1, $this->fetches );
		$this->assertSame( 'https://site120.example', $result['entries'][119]['html_url'] );
	}

	public function test_fetch_truncates_at_the_entry_cap() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		add_filter(
			'daymark_subscription_opml_max_entries',
			static function () {
				return 150;
			}
		);
		$this->response = $this->follows( 1, 250 );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertCount( 150, $result['entries'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 250, $result['total'] );
	}

	public function test_fetch_drops_duplicate_site_urls() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->response = array(
			$this->follow( 1 ),
			array_merge( $this->follow( 1 ), array( 'URL' => 'https://SITE1.example/' ) ),
			$this->follow( 2 ),
		);

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertCount( 2, $result['entries'] );
		$this->assertSame( 2, $result['total'] );
	}

	public function test_fetch_passes_through_a_wordpress_com_error() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->response = new WP_Error( 'daymark_reader_import_failed', 'WordPress.com is down.' );

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertWPError( $result );
		$this->assertSame( 'WordPress.com is down.', $result->get_error_message() );
	}

	public function test_fetch_treats_an_unexpected_stub_value_as_a_failure() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->response = 'not an array';

		$this->assertWPError( Daymark_Reader_Import::fetch_follows() );
	}

	public function test_fetch_treats_an_error_object_as_a_failure() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->response = array(
			'code'    => 'rest_no_route',
			'message' => 'No route was found matching the URL and request method.',
		);

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_reader_import_failed', $result->get_error_code() );
	}

	public function test_fetch_accepts_an_empty_list() {
		$this->set_status( Daymark_Reader_Import::STATUS_READY );
		$this->response = array();

		$result = Daymark_Reader_Import::fetch_follows();

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['entries'] );
		$this->assertSame( 0, $result['total'] );
	}

	// -----------------------------------------------------------------
	// parse_follows()
	// -----------------------------------------------------------------

	public function test_parse_maps_an_item_to_an_import_entry() {
		$entries = Daymark_Reader_Import::parse_follows( array( $this->follow( 7, 'Ada &amp; Co' ) ) );

		$this->assertSame(
			array(
				'label'    => 'Ada & Co',
				'xml_url'  => '',
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

	public function test_parse_keeps_a_site_url_path() {
		$item        = $this->follow( 5 );
		$item['URL'] = 'https://site5.example/blog/';

		$entries = Daymark_Reader_Import::parse_follows( array( $item ) );

		$this->assertSame( 'https://site5.example/blog/', $entries[0]['html_url'] );
	}

	public function test_parse_skips_items_without_a_usable_site_url() {
		$entries = Daymark_Reader_Import::parse_follows(
			array(
				array( 'URL' => '' ),
				array( 'URL' => 'javascript:alert(1)' ),
				array( 'URL' => 'ftp://files.example/' ),
				array( 'name' => 'No URL at all' ),
				'not an array',
				$this->follow( 1 ),
			)
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'https://site1.example', $entries[0]['html_url'] );
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

	public function test_an_imported_follow_finds_its_feed_from_the_site_url() {
		$entries = Daymark_Reader_Import::parse_follows( array( $this->follow( 1 ) ) );
		$results = ( new Daymark_Subscription_OPML() )->import_entries( $entries );

		$this->assertContains( 'https://site1.example', $this->http_urls, 'The site itself is fetched to find its feed, as when adding a site by URL.' );
		$this->assertSame( 'failed', $results[0]['status'], 'With every request blocked, no feed is found and nothing is created.' );
		$this->assertSame( array(), $this->subscriptions->get_all() );
	}

	public function test_an_unsafe_site_url_is_rejected_without_a_request() {
		$item        = $this->follow( 1 );
		$item['URL'] = 'http://127.0.0.1/';

		$results = ( new Daymark_Subscription_OPML() )->import_entries( Daymark_Reader_Import::parse_follows( array( $item ) ) );

		$this->assertSame( 'failed', $results[0]['status'] );
		$this->assertSame( array(), $this->http_urls );
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
				'site_url' => 'https://site2.example/',
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
		$this->assertStringContainsString( '<code class="daymark-candidate-url" style="margin-left:24px;">https://site1.example</code>', $output, 'The site address is shown, since there is no feed URL.' );
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
			array( 'https://site1.example', 'https://site3.example' ),
			wp_list_pluck( $selected, 'html_url' )
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
