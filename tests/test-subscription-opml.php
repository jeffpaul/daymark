<?php
/**
 * Daymark_Subscription_OPML tests (issue #80): export() shape/escaping, and
 * import()'s XXE-safe parsing, per-entry result reporting, nested outlines,
 * the entry-count cap, and the xmlUrl-fast-path/htmlUrl-live-discovery split.
 *
 * HTTP is mocked the same way tests/test-subscriptions.php and
 * tests/test-rest-subscriptions.php already do: `pre_http_request` maps a
 * known URL to a canned response and blocks anything unmapped with a
 * WP_Error, rather than hitting the real network.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Subscription_OPML::export() and ::import().
 */
class Test_Subscription_OPML extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var Daymark_Subscription_OPML */
	private $opml;

	/**
	 * URL => canned wp_remote_get()-shaped response, consulted by
	 * intercept_http_request().
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/**
	 * Count of requests actually made per URL, so a test can assert an
	 * xmlUrl-fast-path entry made zero requests while an htmlUrl-only entry
	 * made the expected discovery request(s).
	 *
	 * @var array<string, int>
	 */
	private array $request_counts = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriptions  = new Daymark_Subscriptions();
		$this->opml           = new Daymark_Subscription_OPML();
		$this->http_responses = array();
		$this->request_counts = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );

		// Same reasoning as test-subscriptions.php's set_up(): the feed
		// source singleton's own per-instance HTML fetch cache must not leak
		// a fixture from an earlier test into this one for a reused URL.
		$feed_source = Daymark_Plugin::instance()->subscription_source_registry->get_source( 'feed' );

		if ( $feed_source instanceof Daymark_Subscription_Source_Feed ) {
			$html_cache = new ReflectionProperty( Daymark_Subscription_Source_Feed::class, 'html_cache' );
			$html_cache->setAccessible( true );
			$html_cache->setValue( $feed_source, array() );
		}
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		$this->request_counts[ $url ] = ( $this->request_counts[ $url ] ?? 0 ) + 1;

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
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

	// -----------------------------------------------------------------
	// export()
	// -----------------------------------------------------------------

	/** export() produces a well-formed OPML document with one <outline> per subscription. */
	public function test_export_produces_valid_opml_shape() {
		$this->subscriptions->create(
			array(
				'site_url'      => 'https://a.example/',
				'feed_url'      => 'https://a.example/feed/',
				'site_title'    => 'Site A',
				'feed_title'    => 'Site A » Feed',
				'site_icon_url' => 'https://a.example/icon.png',
			)
		);

		$xml = $this->opml->export();

		$dom = new DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ), 'export() produces parseable XML' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own native property, not ours to rename.
		$this->assertSame( 'opml', $dom->documentElement->nodeName );

		$xpath    = new DOMXPath( $dom );
		$outlines = $xpath->query( '//outline' );
		$this->assertSame( 1, $outlines->length );

		$outline = $outlines->item( 0 );
		$this->assertSame( 'rss', $outline->getAttribute( 'type' ) );
		$this->assertSame( 'Site A', $outline->getAttribute( 'text' ) );
		$this->assertSame( 'Site A', $outline->getAttribute( 'title' ) );
		$this->assertSame( 'https://a.example/feed/', $outline->getAttribute( 'xmlUrl' ) );
		$this->assertSame( 'https://a.example/', $outline->getAttribute( 'htmlUrl' ) );

		$titles = $xpath->query( '//head/title' );
		$this->assertSame( 'Daymark Subscriptions', $titles->item( 0 )->nodeValue );
	}

	/** export() includes a dead-flagged (status = 'error') subscription — a still-worth-backing-up follow (locked-in scoping decision #2). */
	public function test_export_includes_error_status_subscriptions() {
		$id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://dead.example/',
				'feed_url'   => 'https://dead.example/feed/',
				'site_title' => 'Dead Feed',
			)
		);
		$this->subscriptions->update( $id, array( 'status' => 'error' ) );

		$xml = $this->opml->export();

		$this->assertStringContainsString( 'Dead Feed', $xml );
		$this->assertStringContainsString( 'https://dead.example/feed/', $xml );
	}

	/** export() escapes special characters in a subscription's title. */
	public function test_export_escapes_special_characters() {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://escaped.example/',
				'feed_url'   => 'https://escaped.example/feed/',
				'site_title' => 'Ben & Jerry\'s "Blog" <Test>',
			)
		);

		$xml = $this->opml->export();

		// Raw special characters never appear unescaped in the serialized document.
		$this->assertStringNotContainsString( 'Ben & Jerry', $xml );
		$this->assertStringContainsString( 'Ben &amp; Jerry', $xml );

		// But the parsed value round-trips back to the original string.
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );
		$this->assertSame(
			'Ben & Jerry\'s "Blog" <Test>',
			$xpath->query( '//outline' )->item( 0 )->getAttribute( 'title' )
		);
	}

	/** export() only writes a daymark:iconUrl attribute when site_icon_url is set. */
	public function test_export_icon_url_only_when_set() {
		$this->subscriptions->create(
			array(
				'site_url'      => 'https://has-icon.example/',
				'feed_url'      => 'https://has-icon.example/feed/',
				'site_icon_url' => 'https://has-icon.example/icon.png',
			)
		);
		$this->subscriptions->create(
			array(
				'site_url' => 'https://no-icon.example/',
				'feed_url' => 'https://no-icon.example/feed/',
			)
		);

		$xml = $this->opml->export();

		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$with_icon = $xpath->query( '//outline[@xmlUrl="https://has-icon.example/feed/"]' )->item( 0 );
		$this->assertSame(
			'https://has-icon.example/icon.png',
			$with_icon->getAttributeNS( 'https://github.com/jeffpaul/daymark', 'iconUrl' )
		);

		$without_icon = $xpath->query( '//outline[@xmlUrl="https://no-icon.example/feed/"]' )->item( 0 );
		$this->assertFalse( $without_icon->hasAttributeNS( 'https://github.com/jeffpaul/daymark', 'iconUrl' ) );
	}

	// -----------------------------------------------------------------
	// import()
	// -----------------------------------------------------------------

	/** import() of a well-formed multi-entry file creates the right rows and reports 'subscribed' for each. */
	public function test_import_creates_rows_for_each_entry() {
		$xml = <<<'XML'
<?xml version="1.0"?>
<opml version="2.0">
<body>
<outline text="Feed One" xmlUrl="https://one.example/feed" htmlUrl="https://one.example/" />
<outline text="Feed Two" xmlUrl="https://two.example/feed" htmlUrl="https://two.example/" />
</body>
</opml>
XML;

		$results = $this->opml->import( $xml );

		$this->assertIsArray( $results );
		$this->assertCount( 2, $results );
		$this->assertSame( 'subscribed', $results[0]['status'] );
		$this->assertSame( 'subscribed', $results[1]['status'] );
		$this->assertSame( 'Feed One', $results[0]['label'] );

		$this->assertNotNull( $this->subscriptions->get_by_feed_url( 'https://one.example/feed' ) );
		$this->assertNotNull( $this->subscriptions->get_by_feed_url( 'https://two.example/feed' ) );
	}

	/** import() imports a daymark:iconUrl attribute as the new row's site_icon_url. */
	public function test_import_carries_over_daymark_icon_url() {
		$xml = <<<'XML'
<?xml version="1.0"?>
<opml version="2.0" xmlns:daymark="https://github.com/jeffpaul/daymark">
<body>
<outline text="Iconic Feed" xmlUrl="https://iconic.example/feed" htmlUrl="https://iconic.example/" daymark:iconUrl="https://iconic.example/icon.png" />
</body>
</opml>
XML;

		$results = $this->opml->import( $xml );

		$this->assertSame( 'subscribed', $results[0]['status'] );

		$row = $this->subscriptions->get_by_feed_url( 'https://iconic.example/feed' );
		$this->assertSame( 'https://iconic.example/icon.png', $row['site_icon_url'] );
	}

	/** import() reports a duplicate xmlUrl as 'duplicate' without erroring the rest of the batch. */
	public function test_import_reports_duplicate_without_failing_batch() {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://existing.example/',
				'feed_url' => 'https://existing.example/feed',
			)
		);

		$xml = <<<'XML'
<?xml version="1.0"?>
<opml version="2.0">
<body>
<outline text="Already Subscribed" xmlUrl="https://existing.example/feed" htmlUrl="https://existing.example/" />
<outline text="New Feed" xmlUrl="https://brand-new.example/feed" htmlUrl="https://brand-new.example/" />
</body>
</opml>
XML;

		$results = $this->opml->import( $xml );

		$this->assertCount( 2, $results );
		$this->assertSame( 'duplicate', $results[0]['status'] );
		$this->assertSame( 'subscribed', $results[1]['status'] );

		$this->assertNotNull( $this->subscriptions->get_by_feed_url( 'https://brand-new.example/feed' ), 'The rest of the batch still imported' );
	}

	/**
	 * An XXE payload (a DOCTYPE declaring an external entity referencing a
	 * local file) must not leak that file's contents into any imported
	 * field, and must not fatal/crash the parse — see import()'s own
	 * docblock for exactly why libxml's default (post-2.9) behavior already
	 * prevents this without any extra flag.
	 */
	public function test_import_xxe_payload_does_not_leak_file_contents() {
		$xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE opml [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
<opml version="2.0">
<body>
<outline text="&xxe;" xmlUrl="https://xxe.example/feed" htmlUrl="https://xxe.example/" />
</body>
</opml>
XML;

		$result = $this->opml->import( $xml );

		// libxml refuses to parse an undefined entity reference by default
		// (DTD processing/external entity loading is off unless explicitly
		// enabled) — this fails the parse cleanly rather than crashing or,
		// worse, resolving the entity.
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_opml_invalid', $result->get_error_code() );

		$this->assertSame( array(), $this->subscriptions->get_all(), 'Nothing was imported from the malicious file' );
	}

	/** A malformed / non-OPML file returns the request-level WP_Error, not a fatal. */
	public function test_import_malformed_file_returns_invalid_error() {
		$result = $this->opml->import( 'this is not xml at all <<<' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_opml_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/** A well-formed XML document whose root isn't <opml> is still rejected as invalid OPML. */
	public function test_import_non_opml_root_returns_invalid_error() {
		$result = $this->opml->import( '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_opml_invalid', $result->get_error_code() );
	}

	/** An empty string is rejected the same way, not treated as zero valid entries. */
	public function test_import_empty_string_returns_invalid_error() {
		$result = $this->opml->import( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_opml_invalid', $result->get_error_code() );
	}

	/** The entry-count cap rejects an oversized file outright, with nothing imported. */
	public function test_import_entry_cap_rejects_oversized_file() {
		add_filter(
			'daymark_subscription_opml_max_entries',
			static function () {
				return 2;
			}
		);

		$xml = <<<'XML'
<?xml version="1.0"?>
<opml version="2.0">
<body>
<outline text="Feed One" xmlUrl="https://cap-one.example/feed" />
<outline text="Feed Two" xmlUrl="https://cap-two.example/feed" />
<outline text="Feed Three" xmlUrl="https://cap-three.example/feed" />
</body>
</opml>
XML;

		$result = $this->opml->import( $xml );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_opml_too_many_entries', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$this->assertSame( array(), $this->subscriptions->get_all(), 'Nothing was imported once the cap was exceeded' );
	}

	/** A pure folder/grouping outline (no xmlUrl or htmlUrl) is skipped and does not count against the entry cap. */
	public function test_import_skips_folder_outlines_and_walks_nested_ones() {
		$xml = <<<'XML'
<?xml version="1.0"?>
<opml version="2.0">
<body>
<outline text="Folder A">
  <outline text="Nested Feed" xmlUrl="https://nested.example/feed" htmlUrl="https://nested.example/" />
  <outline text="Sub Folder">
    <outline text="Deeply Nested Feed" xmlUrl="https://deep.example/feed" htmlUrl="https://deep.example/" />
  </outline>
</outline>
<outline text="Top Level Feed" xmlUrl="https://top.example/feed" htmlUrl="https://top.example/" />
</body>
</opml>
XML;

		$results = $this->opml->import( $xml );

		// Two pure folder outlines ("Folder A", "Sub Folder") are skipped
		// entirely — only the three real feed entries appear in the results.
		$this->assertCount( 3, $results );

		$labels = array_column( $results, 'label' );
		$this->assertContains( 'Nested Feed', $labels );
		$this->assertContains( 'Deeply Nested Feed', $labels );
		$this->assertContains( 'Top Level Feed', $labels );

		foreach ( $results as $result ) {
			$this->assertSame( 'subscribed', $result['status'] );
		}
	}

	/**
	 * An xmlUrl entry uses the fast path (create() only) and makes zero
	 * outbound requests; an htmlUrl-only entry falls back to full live
	 * discovery (subscribe_to_site()), which does make a request. Asserted
	 * via the request-count map intercept_http_request() maintains.
	 */
	public function test_xml_url_entry_skips_live_fetch_while_html_url_entry_does_not() {
		$this->mock_response( 'https://discover-me.example/', $this->html_with_feed_and_icon() );

		$xml = <<<'XML'
<?xml version="1.0"?>
<opml version="2.0">
<body>
<outline text="Fast Path Feed" xmlUrl="https://fast-path.example/feed" htmlUrl="https://fast-path.example/" />
<outline text="Needs Discovery" htmlUrl="https://discover-me.example/" />
</body>
</opml>
XML;

		$results = $this->opml->import( $xml );

		$this->assertCount( 2, $results );
		$this->assertSame( 'subscribed', $results[0]['status'] );
		$this->assertSame( 'subscribed', $results[1]['status'] );

		$this->assertArrayNotHasKey(
			'https://fast-path.example/',
			$this->request_counts,
			'The xmlUrl fast path made no live request at all for its own site'
		);
		$this->assertSame(
			1,
			$this->request_counts['https://discover-me.example/'] ?? 0,
			'The htmlUrl-only entry went through live discovery exactly once'
		);

		$fast_path_row = $this->subscriptions->get_by_feed_url( 'https://fast-path.example/feed' );
		$this->assertNotNull( $fast_path_row );
		$this->assertSame( 'https://fast-path.example/', $fast_path_row['site_url'] );

		$discovered_row = $this->subscriptions->get_by_feed_url( 'https://discover-me.example/feed/' );
		$this->assertNotNull( $discovered_row, 'Live discovery found the feed autodiscovery advertised' );
	}

	/** An xmlUrl entry that resolves to an unsafe (SSRF-guarded) target fails cleanly, not fatally, and is not stored. */
	public function test_xml_url_entry_rejects_unsafe_url() {
		$xml = '<?xml version="1.0"?><opml version="2.0"><body>'
			. '<outline text="Unsafe" xmlUrl="http://127.0.0.1/feed" htmlUrl="http://127.0.0.1/" />'
			. '</body></opml>';

		$results = $this->opml->import( $xml );

		$this->assertCount( 1, $results );
		$this->assertSame( 'failed', $results[0]['status'] );
		$this->assertSame( array(), $this->subscriptions->get_all() );
	}

	/** An outline with neither xmlUrl nor htmlUrl at the top level is skipped just like a nested folder. */
	public function test_import_skips_top_level_outline_with_no_urls() {
		$xml = '<?xml version="1.0"?><opml version="2.0"><body>'
			. '<outline text="Just a label, no URLs" />'
			. '</body></opml>';

		$results = $this->opml->import( $xml );

		$this->assertSame( array(), $results );
	}
}
