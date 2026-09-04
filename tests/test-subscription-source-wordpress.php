<?php
/**
 * Daymark_Subscription_Source_WordPress tests (issue #137): REST API
 * discovery + reachability probing, normalize()'s source-agnostic shape and
 * real post_format mapping, and the WordPress-over-feed precedence rule via
 * Daymark_Subscriptions::subscribe_to_site().
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Source_WordPress.
 */
class Test_Subscription_Source_WordPress extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response (or WP_Error), consulted
	 * by intercept_http_request(). Keyed by the URL with its query string
	 * stripped, since fetch() appends per_page/orderby/_embed args.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/** @var Daymark_Subscription_Source_WordPress */
	private $source;

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->source         = new Daymark_Subscription_Source_WordPress();

		// Daymark_Subscription_Html_Cache is a static, request-scoped cache
		// shared by every subscription source's discovery-time homepage
		// fetch (issue #137) — but PHPUnit runs every test in this file (and
		// every other test file) in one continuous PHP process, so without
		// resetting it, an earlier test's fixture for a reused URL could
		// leak into a test here (several tests here reuse
		// 'https://jane.example/').
		Daymark_Subscription_Html_Cache::reset();

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

		$base = strtok( $url, '?' );

		if ( array_key_exists( $base, $this->http_responses ) ) {
			return $this->http_responses[ $base ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * Register a canned 200 response for a URL (query string ignored).
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

	private const WP_SITE_HTML = '<html><head><title>Jane</title><link rel="https://api.w.org/" href="https://jane.example/wp-json/"></head><body></body></html>';

	/** discover() finds a working REST API and returns the wp/v2/posts collection URL. */
	public function test_discover_finds_working_rest_api() {
		$this->mock_response( 'https://jane.example/', self::WP_SITE_HTML );
		$this->mock_response( 'https://jane.example/wp-json/wp/v2/posts', '[]' );

		$result = $this->source->discover( 'https://jane.example/' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://jane.example/wp-json/wp/v2/posts', $result[0]['url'] );
		$this->assertSame( 'Jane', $result[0]['title'] );
	}

	/** discover() finds nothing when the site advertises no REST API link at all. */
	public function test_discover_finds_nothing_without_rest_link() {
		$this->mock_response( 'https://plain.example/', '<html><head><title>Plain</title></head><body></body></html>' );

		$this->assertSame( array(), $this->source->discover( 'https://plain.example/' ) );
	}

	/**
	 * discover() finds nothing when the REST API link is present but
	 * wp/v2/posts itself doesn't respond — a site that disables the REST
	 * API (or that one route specifically) while leaving the discovery
	 * `<link>` tag in place.
	 */
	public function test_discover_finds_nothing_when_posts_endpoint_unreachable() {
		$this->mock_response( 'https://blocked.example/', str_replace( 'jane.example', 'blocked.example', self::WP_SITE_HTML ) );
		// No mock registered for the posts endpoint: intercept_http_request()
		// blocks it with a WP_Error, exactly like a disabled REST API would.

		$this->assertSame( array(), $this->source->discover( 'https://blocked.example/' ) );
	}

	/** discover() finds nothing when the site itself is unreachable. */
	public function test_discover_finds_nothing_when_site_unreachable() {
		$this->assertSame( array(), $this->source->discover( 'https://unreachable.example/' ) );
	}

	/** fetch() parses each post, and normalize() maps a real post_format, embedded author, and embedded featured image directly — no guessing. */
	public function test_fetch_and_normalize_map_real_post_format_and_embeds() {
		$posts = array(
			array(
				'title'          => array( 'rendered' => 'Hello &amp; World' ),
				'excerpt'        => array( 'rendered' => '<p>An excerpt&hellip;</p>' ),
				'content'        => array( 'rendered' => '<p>Full body</p>' ),
				'date_gmt'       => '2024-03-05T10:00:00',
				'link'           => 'https://jane.example/2024/hello-world/',
				'format'         => 'image',
				'featured_media' => 5,
				'_embedded'      => array(
					'author'           => array( array( 'name' => 'Jane Doe' ) ),
					'wp:featuredmedia' => array( array( 'source_url' => 'https://jane.example/wp-content/uploads/photo.jpg' ) ),
				),
			),
		);

		$this->mock_response( 'https://jane.example/wp-json/wp/v2/posts', wp_json_encode( $posts ) );

		$raw_items = $this->source->fetch( 'https://jane.example/wp-json/wp/v2/posts' );

		$this->assertIsArray( $raw_items );
		$this->assertCount( 1, $raw_items );

		$normalized = $this->source->normalize( $raw_items[0] );

		$this->assertSame( 'Hello & World', $normalized['title'] );
		$this->assertSame( 'An excerpt…', $normalized['excerpt'] );
		$this->assertSame( 'Jane Doe', $normalized['author'] );
		$this->assertSame( '2024-03-05 10:00:00', $normalized['published_at'] );
		$this->assertSame( 'https://jane.example/2024/hello-world/', $normalized['permalink'] );
		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://jane.example/wp-content/uploads/photo.jpg', $normalized['featured_image_url'] );
		$this->assertSame( array( 'https://jane.example/wp-content/uploads/photo.jpg' ), $normalized['raw_media'] );
	}

	/** normalize() maps WordPress post_format values with no dedicated Daymark bucket (aside/link/quote) down to 'standard'. */
	public function test_normalize_maps_unmapped_formats_to_standard() {
		foreach ( array( 'aside', 'link', 'quote', 'standard', 'unknown-future-format' ) as $wp_format ) {
			$normalized = $this->source->normalize(
				array(
					'title'  => array( 'rendered' => 'Item' ),
					'format' => $wp_format,
				)
			);

			$this->assertSame( 'standard', $normalized['post_format'], "format '$wp_format' should map to 'standard'" );
		}
	}

	/** normalize() maps WordPress's status/chat post_format values to Daymark's own 'note' bucket, not 'standard'. */
	public function test_normalize_maps_status_and_chat_formats_to_note() {
		foreach ( array( 'status', 'chat' ) as $wp_format ) {
			$normalized = $this->source->normalize(
				array(
					'title'  => array( 'rendered' => 'Item' ),
					'format' => $wp_format,
				)
			);

			$this->assertSame( 'note', $normalized['post_format'], "format '$wp_format' should map to 'note'" );
		}
	}

	/** normalize() passes through every real Daymark-mapped format unchanged. */
	public function test_normalize_passes_through_media_formats() {
		foreach ( array( 'image', 'video', 'audio', 'gallery' ) as $wp_format ) {
			$normalized = $this->source->normalize(
				array(
					'title'  => array( 'rendered' => 'Item' ),
					'format' => $wp_format,
				)
			);

			$this->assertSame( $wp_format, $normalized['post_format'] );
		}
	}

	/** The content-sniff fallback never runs for (nor overrides) a 'note' result from status/chat — 'note' is a real, confirmed signal, not an ambiguous 'standard'. */
	public function test_normalize_never_sniffs_a_note_format() {
		$normalized = $this->source->normalize(
			array(
				'title'   => array( 'rendered' => 'Update' ),
				'format'  => 'status',
				'content' => array( 'rendered' => '<img src="https://jane.example/inline.jpg">' ),
			)
		);

		$this->assertSame( 'note', $normalized['post_format'] );
		$this->assertSame( '', $normalized['featured_image_url'] );
	}

	/**
	 * normalize() falls back to sniffing the post's own rendered content for
	 * inline media when the real `format` field is 'standard' — most
	 * WordPress sites never assign a post format at all, so a bare `standard`
	 * shouldn't be trusted as "definitely no media" the way an explicitly
	 * assigned `image`/`video`/`audio`/`gallery` is.
	 */
	public function test_normalize_sniffs_inline_media_when_format_is_standard() {
		$normalized = $this->source->normalize(
			array(
				'title'   => array( 'rendered' => 'A quiet morning' ),
				'format'  => 'standard',
				'content' => array( 'rendered' => '<img src="https://jane.example/inline.jpg"><p>A quiet morning.</p>' ),
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://jane.example/inline.jpg', $normalized['featured_image_url'] );
	}

	/** The content-sniff fallback never overrides a real, explicitly assigned format. */
	public function test_normalize_never_sniffs_when_format_is_already_a_real_media_format() {
		$normalized = $this->source->normalize(
			array(
				'title'   => array( 'rendered' => 'Clip' ),
				'format'  => 'image',
				'content' => array( 'rendered' => '<video src="https://jane.example/clip.mp4"></video>' ),
			)
		);

		// A real 'image' format wins even though the content itself sniffs as video.
		$this->assertSame( 'image', $normalized['post_format'] );
	}

	/** The content-sniff fallback never overrides a real embedded featured image with a weaker sniffed one. */
	public function test_normalize_prefers_embedded_featured_image_over_sniffed_one() {
		$normalized = $this->source->normalize(
			array(
				'title'          => array( 'rendered' => 'Item' ),
				'format'         => 'standard',
				'content'        => array( 'rendered' => '<img src="https://jane.example/inline.jpg">' ),
				'featured_media' => 5,
				'_embedded'      => array(
					'wp:featuredmedia' => array( array( 'source_url' => 'https://jane.example/wp-content/uploads/real.jpg' ) ),
				),
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://jane.example/wp-content/uploads/real.jpg', $normalized['featured_image_url'] );
	}

	/** A 'standard'-format post with substantial text and only a header image stays 'standard' — an illustrated article, not a photo post. */
	public function test_normalize_ignores_header_image_in_long_standard_article() {
		$long_text  = str_repeat( 'word ', 100 );
		$normalized = $this->source->normalize(
			array(
				'title'   => array( 'rendered' => 'Article' ),
				'format'  => 'standard',
				'content' => array( 'rendered' => '<img src="https://jane.example/header.jpg"><p>' . $long_text . '</p>' ),
			)
		);

		$this->assertSame( 'standard', $normalized['post_format'] );
	}

	/** fetch() returns an empty (not error) array for a site that responds fine but currently has no posts — a healthy, quiet state, not a failure. */
	public function test_fetch_returns_empty_array_when_no_posts() {
		$this->mock_response( 'https://quiet.example/wp-json/wp/v2/posts', '[]' );

		$this->assertSame( array(), $this->source->fetch( 'https://quiet.example/wp-json/wp/v2/posts' ) );
	}

	/** fetch() returns a WP_Error when the endpoint cannot be reached. */
	public function test_fetch_returns_error_when_unreachable() {
		$this->assertWPError( $this->source->fetch( 'https://unreachable.example/wp-json/wp/v2/posts' ) );
	}

	/** fetch() returns a WP_Error when the response isn't a valid JSON post list. */
	public function test_fetch_returns_error_on_invalid_json() {
		$this->mock_response( 'https://broken.example/wp-json/wp/v2/posts', 'not json' );

		$this->assertWPError( $this->source->fetch( 'https://broken.example/wp-json/wp/v2/posts' ) );
	}

	/** Daymark_Subscriptions::subscribe_to_site() prefers the WordPress REST API over the site's own RSS/Atom feed when both are discoverable (issue #137). */
	public function test_subscribe_prefers_wordpress_rest_over_feed() {
		$site_html = '<html><head><title>Both</title>'
			. '<link rel="alternate" type="application/rss+xml" title="Both &raquo; Feed" href="https://both.example/feed/">'
			. '<link rel="https://api.w.org/" href="https://both.example/wp-json/">'
			. '</head><body></body></html>';

		$this->mock_response( 'https://both.example/', $site_html );
		$this->mock_response( 'https://both.example/wp-json/wp/v2/posts', '[]' );

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$result        = $subscriptions->subscribe_to_site( 'https://both.example/' );

		$this->assertIsInt( $result );

		$row = $subscriptions->get( $result );
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the lowercase machine ID (source_type value), not prose.
		$this->assertSame( 'wordpress', $row['source_type'] );
		$this->assertSame( 'https://both.example/wp-json/wp/v2/posts', $row['feed_url'] );
	}

	/** Daymark_Subscriptions::subscribe_to_site() falls back to the feed source when the REST API link is present but unreachable (issue #137). */
	public function test_subscribe_falls_back_to_feed_when_rest_api_unreachable() {
		$site_html = '<html><head><title>Broken REST</title>'
			. '<link rel="alternate" type="application/rss+xml" title="Broken REST &raquo; Feed" href="https://brokenrest.example/feed/">'
			. '<link rel="https://api.w.org/" href="https://brokenrest.example/wp-json/">'
			. '</head><body></body></html>';

		$this->mock_response( 'https://brokenrest.example/', $site_html );
		// No mock for the wp/v2/posts probe: it fails, so discovery falls
		// through to the feed source's own <link rel="alternate"> match.

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$result        = $subscriptions->subscribe_to_site( 'https://brokenrest.example/' );

		$this->assertIsInt( $result );

		$row = $subscriptions->get( $result );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( 'https://brokenrest.example/feed/', $row['feed_url'] );
	}
}
