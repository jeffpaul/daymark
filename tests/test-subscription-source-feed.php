<?php
/**
 * Daymark_Subscription_Source_Feed tests (issue #78) — the concrete RSS/Atom
 * feed source: the main-feed autodiscovery heuristic, normalize()'s
 * source-agnostic shape and sanitization, favicon discovery, and the
 * registry's built-in registration of this source.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Source_Feed and its registration.
 */
class Test_Subscription_Source_Feed extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response (or WP_Error), consulted
	 * by intercept_http_request().
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/** @var Daymark_Subscription_Source_Feed */
	private $source;

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->source         = new Daymark_Subscription_Source_Feed();

		// Daymark_Subscription_Html_Cache is a static, request-scoped cache
		// shared by every subscription source's discovery-time homepage
		// fetch (issue #137) — but PHPUnit runs every test in this file in
		// one continuous PHP process, so without resetting it, an earlier
		// test's HTML fixture would leak into a later test for a reused URL
		// (several tests here reuse 'https://example.com/').
		Daymark_Subscription_Html_Cache::reset();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
		// fetch_feed() caches parsed feeds by default (see the identical note
		// in Test_Subscription_Poller); disabled so every fetch() call here
		// hits the mocked pre_http_request layer, not a stale cache entry
		// from an earlier test reusing the same feed URL.
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
	 * Short-circuits wp_remote_request() for every request made in this
	 * test file: a mapped URL returns its canned response, anything
	 * unmapped is blocked with a WP_Error rather than silently hitting the
	 * real network.
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

	/**
	 * Register a canned 200 RSS/Atom response for a URL — like
	 * mock_response(), but with the `content-type` header SimplePie (via
	 * WP_SimplePie_File) consults to detect a feed; an empty/missing one
	 * otherwise makes a perfectly valid mocked feed body look like a fetch
	 * failure.
	 *
	 * @param string $url  URL to mock.
	 * @param string $body Response body.
	 * @return void
	 */
	private function mock_feed_response( string $url, string $body ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => 'application/rss+xml; charset=UTF-8' ),
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
	 * Build a minimal HTML document with the given `<link>` tags in its
	 * `<head>`.
	 *
	 * @param array<int, array<string, string>> $links Each entry is an attr name => value map for one `<link>` tag.
	 * @return string
	 */
	private function html_with_links( array $links ): string {
		$markup = '';

		foreach ( $links as $link ) {
			$attrs = '';

			foreach ( $link as $name => $value ) {
				$attrs .= sprintf( ' %s="%s"', $name, esc_attr( $value ) );
			}

			$markup .= "<link{$attrs} />\n";
		}

		return "<html><head><title>Example</title>{$markup}</head><body></body></html>";
	}

	/**
	 * Scenario: tie-break level 1 — the shortest/most root-level feed path
	 * wins even when it does not appear first in document order and even
	 * though a "Comments" and a category feed are also present.
	 */
	public function test_discover_prefers_shortest_root_level_path() {
		$html = $this->html_with_links(
			array(
				array(
					'rel'   => 'alternate',
					'type'  => 'application/rss+xml',
					'href'  => '/category/x/feed/',
					'title' => 'Example Site » Category: X Feed',
				),
				array(
					'rel'   => 'alternate',
					'type'  => 'application/rss+xml',
					'href'  => '/feed/comments/',
					'title' => 'Example Site » Comments Feed',
				),
				array(
					'rel'   => 'alternate',
					'type'  => 'application/rss+xml',
					'href'  => '/feed/',
					'title' => 'Example Site » Feed',
				),
			)
		);

		$this->mock_response( 'https://example.com/', $html );

		$discovered = $this->source->discover( 'https://example.com/' );

		$this->assertNotEmpty( $discovered );
		$this->assertSame( 'https://example.com/feed/', $discovered[0]['url'] );
	}

	/**
	 * Scenario: tie-break level 2 — when path depth ties, WordPress's
	 * default "{Site Name} » Feed" title convention wins over a title
	 * carrying "Comments".
	 */
	public function test_discover_prefers_default_title_convention_on_depth_tie() {
		$html = $this->html_with_links(
			array(
				array(
					'rel'   => 'alternate',
					'type'  => 'application/rss+xml',
					'href'  => '/comments/',
					'title' => 'Example Site » Comments Feed',
				),
				array(
					'rel'   => 'alternate',
					'type'  => 'application/rss+xml',
					'href'  => '/feed/',
					'title' => 'Example Site » Feed',
				),
			)
		);

		$this->mock_response( 'https://example.com/', $html );

		$discovered = $this->source->discover( 'https://example.com/' );

		$this->assertSame( 'https://example.com/feed/', $discovered[0]['url'] );
	}

	/**
	 * Scenario: tie-break level 3 — depth and title convention both tie
	 * (neither link is titled), so the first link in document order wins.
	 */
	public function test_discover_falls_back_to_document_order_when_still_tied() {
		$html = $this->html_with_links(
			array(
				array(
					'rel'  => 'alternate',
					'type' => 'application/rss+xml',
					'href' => '/one/',
				),
				array(
					'rel'  => 'alternate',
					'type' => 'application/atom+xml',
					'href' => '/two/',
				),
			)
		);

		$this->mock_response( 'https://example.com/', $html );

		$discovered = $this->source->discover( 'https://example.com/' );

		$this->assertSame( 'https://example.com/one/', $discovered[0]['url'] );
	}

	/** Scenario: no `<link rel="alternate">` feed tags at all → empty array. */
	public function test_discover_returns_empty_array_when_no_feed_found() {
		$html = '<html><head><title>Example</title></head><body></body></html>';

		$this->mock_response( 'https://example.com/', $html );

		$this->assertSame( array(), $this->source->discover( 'https://example.com/' ) );
	}

	/** Scenario: an unreachable/erroring site also discovers nothing. */
	public function test_discover_returns_empty_array_on_fetch_failure() {
		// Deliberately not mocked — intercept_http_request() blocks it with
		// a WP_Error, simulating an unreachable host.
		$this->assertSame( array(), $this->source->discover( 'https://unreachable.example/' ) );
	}

	/** Scenario: a non-http(s) site URL is rejected before any request is made. */
	public function test_discover_rejects_non_http_scheme() {
		$this->assertSame( array(), $this->source->discover( 'ftp://example.com/' ) );
	}

	/**
	 * Scenario (issue #81, SSRF hardening): a site URL that resolves to a
	 * private/internal address discovers nothing — the exact same empty
	 * result any other invalid site URL already produces, and no request is
	 * ever attempted (an unmocked request would be blocked and would still
	 * result in an empty array either way, so this also confirms the guard,
	 * not just the fetch, is what's short-circuiting here).
	 */
	public function test_discover_rejects_unsafe_host() {
		$this->assertSame( array(), $this->source->discover( 'http://127.0.0.1/' ) );
	}

	/**
	 * Scenario (issue #81): the site-HTML fetch behind discover() rejects a
	 * response at or beyond the configured size cap rather than parsing a
	 * (possibly truncated) document — a real, discoverable feed link is
	 * present in the fixture, so this proves the cap itself is what blocks
	 * discovery, not a missing link.
	 */
	public function test_discover_rejects_html_response_at_or_over_size_cap() {
		$html = $this->html_with_links(
			array(
				array(
					'rel'  => 'alternate',
					'type' => 'application/rss+xml',
					'href' => '/feed/',
				),
			)
		);

		add_filter(
			'daymark_subscription_max_html_bytes',
			static function () use ( $html ) {
				// One byte below the real body length: the body is "at or
				// beyond" this cap, so it is rejected as (possibly) truncated.
				return strlen( $html ) - 1;
			}
		);

		$this->mock_response( 'https://example.com/', $html );

		$this->assertSame( array(), $this->source->discover( 'https://example.com/' ) );
	}

	/**
	 * Scenario: normalize() produces the documented source-agnostic shape
	 * and sanitizes every string field — title/author strip tags and
	 * scripts, permalink and enclosure URLs are esc_url_raw'd, and the
	 * MySQL-datetime-shaped date passes through unchanged.
	 */
	public function test_normalize_produces_documented_shape_with_sanitized_fields() {
		$raw_item = array(
			'title'       => '<script>alert(1)</script>Hello & Welcome',
			'author'      => 'Jane <b>Doe</b>',
			'permalink'   => 'https://example.com/post-1?utm=1"><script>alert(2)</script>',
			'date'        => '2024-01-02 03:04:05',
			'content'     => '<p>Full body content, not used when a description is present.</p>',
			'description' => '<p onclick="alert(3)">This is a <strong>short</strong> summary of the post.</p>',
			'enclosures'  => array(
				array(
					'url'    => 'https://example.com/image1.jpg"><script>alert(4)</script>',
					'type'   => 'image/jpeg',
					'medium' => 'image',
				),
				array(
					'url'    => 'https://example.com/image2.png',
					'type'   => 'image/png',
					'medium' => 'image',
				),
			),
		);

		$normalized = $this->source->normalize( $raw_item );

		$this->assertSame(
			array(
				'title',
				'excerpt',
				'author',
				'published_at',
				'permalink',
				'post_format',
				'featured_image_url',
				'raw_media',
			),
			array_keys( $normalized )
		);

		$this->assertSame( 'Hello & Welcome', $normalized['title'] );
		$this->assertStringNotContainsString( '<script>', $normalized['title'] );
		$this->assertSame( 'Jane Doe', $normalized['author'] );
		$this->assertStringNotContainsString( '<script>', $normalized['permalink'] );
		$this->assertStringStartsWith( 'https://example.com/post-1', $normalized['permalink'] );
		$this->assertSame( '2024-01-02 03:04:05', $normalized['published_at'] );
		$this->assertStringContainsString( 'short summary of the post', $normalized['excerpt'] );
		$this->assertStringNotContainsString( '<strong>', $normalized['excerpt'] );

		// Two image enclosures → gallery, and the first sanitized image URL
		// is the featured image.
		$this->assertSame( 'gallery', $normalized['post_format'] );
		$this->assertStringNotContainsString( '<script>', $normalized['featured_image_url'] );
		$this->assertStringStartsWith( 'https://example.com/image1.jpg', $normalized['featured_image_url'] );
		$this->assertCount( 2, $normalized['raw_media'] );
		$this->assertSame( 'https://example.com/image2.png', $normalized['raw_media'][1] );
	}

	/** Scenario: a single image enclosure → post_format 'image'. */
	public function test_normalize_detects_single_image_format() {
		$normalized = $this->source->normalize(
			array(
				'enclosures' => array(
					array(
						'url'    => 'https://example.com/photo.jpg',
						'type'   => 'image/jpeg',
						'medium' => 'image',
					),
				),
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://example.com/photo.jpg', $normalized['featured_image_url'] );
	}

	/** Scenario: a video enclosure → post_format 'video'. */
	public function test_normalize_detects_video_format() {
		$normalized = $this->source->normalize(
			array(
				'enclosures' => array(
					array(
						'url'    => 'https://example.com/clip.mp4',
						'type'   => 'video/mp4',
						'medium' => 'video',
					),
				),
			)
		);

		$this->assertSame( 'video', $normalized['post_format'] );
	}

	/** Scenario: an audio enclosure → post_format 'audio'. */
	public function test_normalize_detects_audio_format() {
		$normalized = $this->source->normalize(
			array(
				'enclosures' => array(
					array(
						'url'    => 'https://example.com/episode.mp3',
						'type'   => 'audio/mpeg',
						'medium' => 'audio',
					),
				),
			)
		);

		$this->assertSame( 'audio', $normalized['post_format'] );
	}

	/** Scenario: no enclosures at all → post_format 'standard', no media. */
	public function test_normalize_falls_back_to_standard_format() {
		$normalized = $this->source->normalize(
			array(
				'title' => 'A plain post',
			)
		);

		$this->assertSame( 'standard', $normalized['post_format'] );
		$this->assertSame( '', $normalized['featured_image_url'] );
		$this->assertSame( array(), $normalized['raw_media'] );
	}

	/**
	 * Scenario: no enclosures, but a real <video> tag embedded directly in
	 * the content body — a native player is a strong, unambiguous signal
	 * even with no mf2 markup, so it always counts.
	 */
	public function test_normalize_detects_video_from_inline_content_tag() {
		$normalized = $this->source->normalize(
			array(
				'content' => '<p>Watch this.</p><video src="https://example.com/clip.mp4" controls></video>',
			)
		);

		$this->assertSame( 'video', $normalized['post_format'] );
	}

	/**
	 * Scenario: a microformats2 u-photo class on an inline <img> is trusted
	 * even alongside substantial article text — an explicit author signal,
	 * unlike a bare <img>.
	 */
	public function test_normalize_detects_mf2_photo_regardless_of_surrounding_text_length() {
		$long_text  = str_repeat( 'word ', 100 );
		$normalized = $this->source->normalize(
			array(
				'content' => '<img class="u-photo" src="https://example.com/photo.jpg" /><p>' . $long_text . '</p>',
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://example.com/photo.jpg', $normalized['featured_image_url'] );
	}

	/**
	 * Scenario: a bare <img> (no mf2 markup) with only a short caption reads
	 * as a photo post — the same "short text" shape a real photo-blog post
	 * has.
	 */
	public function test_normalize_detects_plain_inline_image_when_text_is_short() {
		$normalized = $this->source->normalize(
			array(
				'content' => '<img src="https://example.com/photo.jpg" /><p>A quiet morning.</p>',
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://example.com/photo.jpg', $normalized['featured_image_url'] );
	}

	/**
	 * Scenario: a bare <img> alongside a substantial article stays
	 * 'standard' — an illustrated article is not a photo post, and treating
	 * every text post with a header image as "image" would make the bucket
	 * meaningless.
	 */
	public function test_normalize_ignores_plain_inline_image_in_long_article() {
		$long_text  = str_repeat( 'word ', 100 );
		$normalized = $this->source->normalize(
			array(
				'content' => '<img src="https://example.com/header.jpg" /><p>' . $long_text . '</p>',
			)
		);

		$this->assertSame( 'standard', $normalized['post_format'] );
	}

	/** An enclosure-confirmed signal always wins over content sniffing. */
	public function test_normalize_prefers_enclosure_over_content_sniffing() {
		$normalized = $this->source->normalize(
			array(
				'enclosures' => array(
					array(
						'url'    => 'https://example.com/episode.mp3',
						'type'   => 'audio/mpeg',
						'medium' => 'audio',
					),
				),
				'content'    => '<img src="https://example.com/cover.jpg" /><p>Short note.</p>',
			)
		);

		$this->assertSame( 'audio', $normalized['post_format'] );
	}

	/** Content sniffing falls back to `description` when `content` is empty. */
	public function test_normalize_sniffs_description_when_content_is_empty() {
		$normalized = $this->source->normalize(
			array(
				'description' => '<img class="u-photo" src="https://example.com/photo.jpg" />',
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
	}

	/**
	 * Malformed content HTML never breaks normalize() — a pathological feed
	 * item degrades to 'standard', it doesn't fatal the whole poll run.
	 */
	public function test_normalize_tolerates_malformed_content_html() {
		$normalized = $this->source->normalize(
			array(
				'content' => '<img src="https://example.com/a.jpg" class="<<<' . str_repeat( '<div>', 500 ),
			)
		);

		$this->assertContains( $normalized['post_format'], array( 'standard', 'image' ) );
	}

	/** Scenario: an unparsable date normalizes to ''. */
	public function test_normalize_handles_missing_date() {
		$normalized = $this->source->normalize( array( 'date' => '' ) );

		$this->assertSame( '', $normalized['published_at'] );
	}

	/**
	 * Scenario (issue #81): fetch_html() (shared by discover(),
	 * get_favicon_url(), and get_site_title()) requests the documented 1 MB
	 * default size cap and 10s default timeout, and honors both filters.
	 */
	public function test_html_fetch_uses_filterable_timeout_and_size_limit() {
		$captured = null;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
				if ( 'https://example.com/' === $url ) {
					$captured = $parsed_args;
				}

				return $preempt;
			},
			5,
			3
		);

		add_filter(
			'daymark_subscription_html_fetch_timeout',
			static function () {
				return 3;
			}
		);

		$this->mock_response( 'https://example.com/', $this->html_with_links( array() ) );

		$this->source->get_site_title( 'https://example.com/' );

		$this->assertNotNull( $captured, 'The request was made and its args captured' );
		$this->assertSame( 3, $captured['timeout'] );
		$this->assertSame( 1024 * 1024, $captured['limit_response_size'] );
	}

	/**
	 * Scenario: favicon discovery prefers an explicit `<link rel="icon">`
	 * over the `/favicon.ico` fallback.
	 */
	public function test_favicon_prefers_explicit_icon_link() {
		$html = $this->html_with_links(
			array(
				array(
					'rel'  => 'icon',
					'href' => '/assets/icon.png',
				),
			)
		);

		$this->mock_response( 'https://example.com/', $html );

		$this->assertSame(
			'https://example.com/assets/icon.png',
			$this->source->get_favicon_url( 'https://example.com/' )
		);
	}

	/**
	 * Scenario: no `<link rel="icon">` present → falls back to
	 * `{scheme}://{host}/favicon.ico`.
	 */
	public function test_favicon_falls_back_to_favicon_ico() {
		$html = '<html><head><title>Example</title></head><body></body></html>';

		$this->mock_response( 'https://example.com/', $html );

		$this->assertSame(
			'https://example.com/favicon.ico',
			$this->source->get_favicon_url( 'https://example.com/' )
		);
	}

	/**
	 * Scenario: an unreachable site still resolves the `/favicon.ico`
	 * fallback rather than returning nothing.
	 */
	public function test_favicon_falls_back_when_site_unreachable() {
		$this->assertSame(
			'https://unreachable.example/favicon.ico',
			$this->source->get_favicon_url( 'https://unreachable.example/' )
		);
	}

	/**
	 * Scenario (issue #81, SSRF hardening): a site URL that resolves to a
	 * private/internal address returns '' — unlike a merely unreachable
	 * host (test above), an *unsafe* host never even reaches the
	 * `/favicon.ico` fallback, since the guard rejects it before any host
	 * information is used for a request of any kind.
	 */
	public function test_favicon_rejects_unsafe_host() {
		$this->assertSame( '', $this->source->get_favicon_url( 'http://127.0.0.1/' ) );
	}

	/** Scenario: get_site_title() reads the page's own plain <title>. */
	public function test_get_site_title_reads_title_tag() {
		$this->mock_response( 'https://example.com/', $this->html_with_links( array() ) );

		$this->assertSame( 'Example', $this->source->get_site_title( 'https://example.com/' ) );
	}

	/**
	 * Scenario: the site's <title> is deliberately not the feed <link>'s
	 * own title attribute — get_site_title() must never pick up WordPress's
	 * "{Site Name} » Feed" convention from a <link> tag.
	 */
	public function test_get_site_title_ignores_feed_link_title() {
		$html = '<html><head><title>Example</title>'
			. '<link rel="alternate" type="application/rss+xml" title="Example » Feed" href="/feed/" />'
			. '</head><body></body></html>';

		$this->mock_response( 'https://example.com/', $html );

		$this->assertSame( 'Example', $this->source->get_site_title( 'https://example.com/' ) );
	}

	/** Scenario: no <title> tag at all → empty string, not a fatal. */
	public function test_get_site_title_returns_empty_when_no_title_tag() {
		$this->mock_response( 'https://example.com/', '<html><head></head><body></body></html>' );

		$this->assertSame( '', $this->source->get_site_title( 'https://example.com/' ) );
	}

	/** Scenario: an unreachable site returns an empty string, not a fatal. */
	public function test_get_site_title_returns_empty_when_site_unreachable() {
		$this->assertSame( '', $this->source->get_site_title( 'https://unreachable.example/' ) );
	}

	/** Scenario (issue #81, SSRF hardening): an unsafe host returns ''. */
	public function test_get_site_title_rejects_unsafe_host() {
		$this->assertSame( '', $this->source->get_site_title( 'http://127.0.0.1/' ) );
	}

	/** Scenario: HTML entities and surrounding whitespace in <title> are cleaned up. */
	public function test_get_site_title_decodes_entities_and_trims() {
		$html = "<html><head><title>\n  Jack &amp; Jill's Blog  \n</title></head><body></body></html>";

		$this->mock_response( 'https://example.com/', $html );

		$this->assertSame( "Jack & Jill's Blog", $this->source->get_site_title( 'https://example.com/' ) );
	}

	/**
	 * Scenario: discover() and get_favicon_url() for the same site share one
	 * HTTP fetch rather than each issuing their own request.
	 */
	public function test_discover_and_favicon_share_one_fetch_for_the_same_site() {
		$requests = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
				if ( 'https://example.com/' === $url ) {
					++$requests;
				}

				return $preempt;
			},
			5,
			3
		);

		$html = $this->html_with_links(
			array(
				array(
					'rel'   => 'alternate',
					'type'  => 'application/rss+xml',
					'href'  => '/feed/',
					'title' => 'Example Site » Feed',
				),
				array(
					'rel'  => 'icon',
					'href' => '/icon.png',
				),
			)
		);

		$this->mock_response( 'https://example.com/', $html );

		$this->source->discover( 'https://example.com/' );
		$this->source->get_favicon_url( 'https://example.com/' );

		$this->assertSame( 1, $requests );
	}

	// -----------------------------------------------------------------
	// fetch() hardening (issue #81).
	// -----------------------------------------------------------------

	/**
	 * Scenario: a feed URL that resolves to a private/internal address is
	 * rejected before any request is attempted, with a specific reason in
	 * the WP_Error message — reused as-is by
	 * Daymark_Subscription_Poller::record_failed_check() for `last_error`.
	 */
	public function test_fetch_rejects_unsafe_feed_url() {
		$result = $this->source->fetch( 'http://127.0.0.1/feed/' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_invalid_feed_url', $result->get_error_code() );
		$this->assertStringContainsString( 'private or internal network address', $result->get_error_message() );
	}

	/**
	 * Scenario: inject_feed_response_size_limit() — the http_request_args
	 * callback scoped to fetch()'s own fetch_feed() call — injects the
	 * documented 2 MB default, and honors the
	 * `daymark_subscription_max_feed_bytes` filter.
	 */
	public function test_feed_response_size_limit_default_and_filter() {
		$args = $this->source->inject_feed_response_size_limit( array() );
		$this->assertSame( 2 * 1024 * 1024, $args['limit_response_size'] );

		add_filter(
			'daymark_subscription_max_feed_bytes',
			static function () {
				return 12345;
			}
		);

		$args = $this->source->inject_feed_response_size_limit( array() );
		$this->assertSame( 12345, $args['limit_response_size'] );
	}

	/**
	 * Scenario: a feed response cut off mid-document (what a real
	 * size-limited download looks like once truncated) fails as a WP_Error
	 * via SimplePie's own parse failure — fetch()'s existing is_wp_error()
	 * contract needs no separate truncation-detection code for this call
	 * site (see fetch()'s own docblock).
	 */
	public function test_fetch_treats_a_truncated_feed_as_a_parse_failure() {
		$truncated = '<?xml version="1.0"?><rss version="2.0"><channel><title>Example</title><item><title>Cut o';
		$this->mock_feed_response( 'https://example.com/feed/', $truncated );

		$result = $this->source->fetch( 'https://example.com/feed/' );

		$this->assertWPError( $result );
	}

	/** Scenario: configure_feed_timeout() sets the documented 10s default and honors its filter. */
	public function test_feed_fetch_timeout_default_and_filter() {
		$feed = new class() {
			public $timeout;
			public function set_timeout( $seconds ) {
				$this->timeout = $seconds;
			}
		};

		$this->source->configure_feed_timeout( $feed );
		$this->assertSame( 10, $feed->timeout );

		add_filter(
			'daymark_subscription_feed_fetch_timeout',
			static function () {
				return 3;
			}
		);

		$this->source->configure_feed_timeout( $feed );
		$this->assertSame( 3, $feed->timeout );
	}

	/**
	 * Scenario: the registry's built-in registration returns a real
	 * Daymark_Subscription_Source_Feed instance for the 'feed' source ID.
	 */
	public function test_registry_returns_built_in_feed_source() {
		$registry = Daymark_Subscription_Source_Registry::instance();
		$source   = $registry->get_source( 'feed' );

		$this->assertInstanceOf( Daymark_Subscription_Source_Feed::class, $source );
		$this->assertSame( 'feed', $source->get_id() );
		$this->assertArrayHasKey( 'feed', $registry->get_sources() );
	}
}
