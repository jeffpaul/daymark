<?php
/**
 * Daymark_Subscription_Source_Microformats tests (issue #84): h-entry/h-card
 * discovery and parsing, normalize()'s source-agnostic shape, nested compound
 * object (h-card/h-cite) exclusion, and the feed-vs-microformats precedence
 * rule via Daymark_Subscriptions::subscribe_to_site().
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Source_Microformats.
 */
class Test_Subscription_Source_Microformats extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response (or WP_Error), consulted
	 * by intercept_http_request().
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/** @var Daymark_Subscription_Source_Microformats */
	private $source;

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->source         = new Daymark_Subscription_Source_Microformats();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
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
		unset( $preempt, $parsed_args );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * Register a canned 200 response for a URL.
	 *
	 * @param string $url  URL to mock.
	 * @param string $body Response body.
	 * @return void
	 */
	private function mock_response( string $url, string $body ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private const H_FEED_PAGE = <<<'HTML'
<html><head><title>Jane's Site</title></head><body>
<div class="h-feed">
  <article class="h-entry">
    <h1 class="p-name">Hello World</h1>
    <time class="dt-published" datetime="2024-03-05T10:00:00+00:00">March 5</time>
    <a class="u-url" href="/2024/hello-world/">permalink</a>
    <div class="p-author h-card">
      <img class="u-photo" src="/me.jpg" />
      <span class="p-name">Jane Doe</span>
    </div>
    <div class="e-content">
      <p>This is the body.</p>
      <img class="u-photo" src="/photo1.jpg" />
    </div>
  </article>
  <article class="h-entry">
    <a class="u-like-of" href="https://other.example/post/1"></a>
    <a class="u-url" href="/2024/a-like/">permalink</a>
  </article>
</div>
</body></html>
HTML;

	/** discover() finds h-feed/h-entry markup and returns a subscribable candidate. */
	public function test_discover_finds_h_feed_markup() {
		$this->mock_response( 'https://jane.example/', self::H_FEED_PAGE );

		$result = $this->source->discover( 'https://jane.example/' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://jane.example/', $result[0]['url'] );
		$this->assertSame( "Jane's Site", $result[0]['title'] );
	}

	/** discover() finds nothing on a page with no mf2 markup at all. */
	public function test_discover_finds_nothing_without_markup() {
		$this->mock_response( 'https://plain.example/', '<html><head><title>Plain</title></head><body><p>No microformats here.</p></body></html>' );

		$this->assertSame( array(), $this->source->discover( 'https://plain.example/' ) );
	}

	/** discover() finds nothing when the site is unreachable. */
	public function test_discover_finds_nothing_when_unreachable() {
		// No mock registered for this URL: intercept_http_request() blocks
		// it with a WP_Error, exactly like a real unreachable site would.
		$this->assertSame( array(), $this->source->discover( 'https://unreachable.example/' ) );
	}

	/** fetch() parses each h-entry into a raw item, and normalize() maps the first entry's photo/author/date correctly. */
	public function test_fetch_and_normalize_map_photo_entry() {
		$this->mock_response( 'https://jane.example/', self::H_FEED_PAGE );

		$raw_items = $this->source->fetch( 'https://jane.example/' );

		$this->assertIsArray( $raw_items );
		$this->assertCount( 2, $raw_items );

		$normalized = $this->source->normalize( $raw_items[0] );

		$this->assertSame( 'Hello World', $normalized['title'] );
		$this->assertSame( 'Jane Doe', $normalized['author'] );
		$this->assertSame( 'https://jane.example/2024/hello-world/', $normalized['permalink'] );
		$this->assertSame( '2024-03-05 10:00:00', $normalized['published_at'] );
		$this->assertSame( 'image', $normalized['post_format'] );
		// The author h-card's own u-photo (the avatar) must never leak into
		// the entry's own photo — only the e-content photo counts.
		$this->assertSame( 'https://jane.example/photo1.jpg', $normalized['featured_image_url'] );
		$this->assertSame( array( 'https://jane.example/photo1.jpg' ), $normalized['raw_media'] );
	}

	/** normalize() gives a like-of entry with no p-name a sensible fallback title instead of an empty one. */
	public function test_normalize_gives_fallback_title_for_untitled_like() {
		$this->mock_response( 'https://jane.example/', self::H_FEED_PAGE );

		$raw_items  = $this->source->fetch( 'https://jane.example/' );
		$normalized = $this->source->normalize( $raw_items[1] );

		$this->assertSame( 'Like', $normalized['title'] );
		$this->assertSame( 'https://jane.example/2024/a-like/', $normalized['permalink'] );
		$this->assertSame( 'standard', $normalized['post_format'] );
	}

	/** fetch() returns an empty (not error) array for a page that fetches fine but currently has no h-entry markup — a healthy, quiet state, not a failure. */
	public function test_fetch_returns_empty_array_when_no_entries() {
		$this->mock_response( 'https://quiet.example/', '<html><body><p>Nothing published yet.</p></body></html>' );

		$this->assertSame( array(), $this->source->fetch( 'https://quiet.example/' ) );
	}

	/** fetch() returns a WP_Error when the page cannot be reached. */
	public function test_fetch_returns_error_when_unreachable() {
		$result = $this->source->fetch( 'https://unreachable.example/' );

		$this->assertWPError( $result );
	}

	/** A nested h-entry (e.g. a quoted repost) is not counted as a separate top-level item, and the outer entry is classified by its own post-type class. */
	public function test_nested_h_entry_is_not_double_counted() {
		$html = '<div class="h-feed"><article class="h-entry"><p class="p-name">Quoting this</p>'
			. '<div class="u-repost-of h-cite"><div class="h-entry"><p class="p-name">Nested (excluded)</p></div></div>'
			. '<div class="e-content"><p>quoted</p></div></article></div>';

		$this->mock_response( 'https://reposter.example/', $html );

		$raw_items = $this->source->fetch( 'https://reposter.example/' );

		$this->assertCount( 1, $raw_items );
		$this->assertSame( 'Quoting this', $raw_items[0]['name'] );
		$this->assertSame( 'repost', $raw_items[0]['post_type'] );
	}

	/** Daymark_Subscriptions::subscribe_to_site() records source_type 'feed' when a site exposes both a feed and h-feed markup — the documented precedence rule (issue #84). */
	public function test_subscribe_prefers_feed_over_microformats_when_both_exist() {
		$site_html = '<html><head><title>Both</title>'
			. '<link rel="alternate" type="application/rss+xml" title="Both &raquo; Feed" href="https://both.example/feed/">'
			. '</head><body><div class="h-feed"><article class="h-entry"><p class="p-name">Hi</p></article></div></body></html>';

		$this->mock_response( 'https://both.example/', $site_html );
		// subscribe_to_site() only ever calls discover() on the feed source
		// (a `<link rel="alternate">` autodiscovery scan of the site's own
		// HTML, mocked above) plus its favicon/title lookups against that
		// same HTML — never fetch_feed() itself — so the feed URL the
		// `<link>` tag points at needs no mock of its own for this test.

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$result        = $subscriptions->subscribe_to_site( 'https://both.example/' );

		$this->assertIsInt( $result );

		$row = $subscriptions->get( $result );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( 'https://both.example/feed/', $row['feed_url'] );
	}

	/** Daymark_Subscriptions::subscribe_to_site() falls back to source_type 'microformats' when a site has h-feed markup but no discoverable feed at all (issue #84). */
	public function test_subscribe_falls_back_to_microformats_without_a_feed() {
		$site_html = '<html><head><title>MF2 Only</title></head><body>'
			. '<div class="h-feed"><article class="h-entry"><p class="p-name">Hi</p></article></div>'
			. '</body></html>';

		$this->mock_response( 'https://mf2only.example/', $site_html );

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$result        = $subscriptions->subscribe_to_site( 'https://mf2only.example/' );

		$this->assertIsInt( $result );

		$row = $subscriptions->get( $result );
		$this->assertSame( 'microformats', $row['source_type'] );
		$this->assertSame( 'https://mf2only.example/', $row['feed_url'] );
	}
}
