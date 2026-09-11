<?php
/**
 * Daymark_Subscription_Opengraph tests (issue #349): resolve() end to end.
 *
 * This class fetches a page directly with wp_safe_remote_get(), not
 * wp_oembed_get(), so WP core's own `pre_oembed_result` filter (used by
 * tests/test-subscription-oembed.php) doesn't apply here — this mirrors the
 * `pre_http_request` mocking pattern tests/test-comment-delivery.php
 * already established for a raw HTTP fetch instead.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Opengraph::resolve().
 */
class Test_Subscription_Opengraph extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();

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
		unset( $parsed_args );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string $url  URL to mock.
	 * @param string $body Response body.
	 * @param int    $code HTTP status code.
	 * @return void
	 */
	private function mock_response( string $url, string $body, int $code = 200 ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** An empty URL never even reaches wp_safe_remote_get(). */
	public function test_resolve_returns_empty_for_blank_url(): void {
		$this->assertSame( array(), Daymark_Subscription_Opengraph::resolve( '' ) );
	}

	/** A non-http(s) scheme is rejected before any lookup is attempted. */
	public function test_resolve_rejects_non_http_scheme(): void {
		$this->assertSame( array(), Daymark_Subscription_Opengraph::resolve( 'javascript:alert(1)' ) );
	}

	/**
	 * A URL the SSRF guard rejects (here, one that resolves to a private
	 * address via the test-only DNS-resolution filter) never reaches
	 * wp_safe_remote_get() at all.
	 */
	public function test_resolve_rejects_unsafe_url(): void {
		$filter = function () {
			return array( '10.0.0.5' );
		};

		add_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$this->mock_response(
			'https://internal.example/post',
			'<html><head><meta property="og:title" content="Hi"></head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://internal.example/post' );

		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$this->assertSame( array(), $result );
	}

	/** The common case: og:title/og:description/og:image all present. */
	public function test_resolve_extracts_og_title_description_image(): void {
		$this->mock_response(
			'https://blog.example/post',
			'<html><head>
				<meta property="og:title" content="An Example Post">
				<meta property="og:description" content="A short excerpt about the post.">
				<meta property="og:image" content="https://blog.example/cover.jpg">
			</head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/post' );

		$this->assertSame( 'link', $result['type'] );
		$this->assertSame( 'An Example Post', $result['title'] );
		$this->assertSame( 'A short excerpt about the post.', $result['description'] );
		$this->assertSame( 'https://blog.example/cover.jpg', $result['image'] );
	}

	/** og:image:secure_url outranks a plain og:image when both are present. */
	public function test_resolve_prefers_secure_image_url(): void {
		$this->mock_response(
			'https://blog.example/secure-image',
			'<html><head>
				<meta property="og:title" content="Secure image test">
				<meta property="og:image" content="http://blog.example/insecure.jpg">
				<meta property="og:image:secure_url" content="https://blog.example/secure.jpg">
			</head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/secure-image' );

		$this->assertSame( 'https://blog.example/secure.jpg', $result['image'] );
	}

	/** Twitter Card meta tags back each field individually when Open Graph has none. */
	public function test_resolve_falls_back_to_twitter_card(): void {
		$this->mock_response(
			'https://blog.example/twitter-only',
			'<html><head>
				<meta name="twitter:title" content="Twitter Card Title">
				<meta name="twitter:description" content="Twitter card description.">
				<meta name="twitter:image" content="https://blog.example/twitter.jpg">
			</head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/twitter-only' );

		$this->assertSame( 'Twitter Card Title', $result['title'] );
		$this->assertSame( 'Twitter card description.', $result['description'] );
		$this->assertSame( 'https://blog.example/twitter.jpg', $result['image'] );
	}

	/** A plain page with neither Open Graph nor Twitter Card tags falls back to <title>/meta description. */
	public function test_resolve_falls_back_to_title_tag_and_meta_description(): void {
		$this->mock_response(
			'https://blog.example/plain',
			'<html><head>
				<title>Plain Page Title</title>
				<meta name="description" content="A plain meta description.">
			</head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/plain' );

		$this->assertSame( 'Plain Page Title', $result['title'] );
		$this->assertSame( 'A plain meta description.', $result['description'] );
		$this->assertSame( '', $result['image'] );
	}

	/** A page with no title anywhere (og/twitter/<title>) has nothing worth previewing. */
	public function test_resolve_returns_empty_when_no_title_found(): void {
		$this->mock_response( 'https://blog.example/no-title', '<html><head></head><body>Nothing here.</body></html>' );

		$this->assertSame( array(), Daymark_Subscription_Opengraph::resolve( 'https://blog.example/no-title' ) );
	}

	/** A non-200 response never gets parsed. */
	public function test_resolve_returns_empty_on_non_200_response(): void {
		$this->mock_response( 'https://blog.example/missing', '<html></html>', 404 );

		$this->assertSame( array(), Daymark_Subscription_Opengraph::resolve( 'https://blog.example/missing' ) );
	}

	/** A long description is trimmed to the same word count every other subscription-post excerpt uses. */
	public function test_resolve_trims_long_description(): void {
		$long_description = implode( ' ', array_fill( 0, 60, 'word' ) );

		$this->mock_response(
			'https://blog.example/long-description',
			'<html><head><meta property="og:title" content="Long description test"><meta property="og:description" content="'
				. esc_attr( $long_description )
				. '"></head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/long-description' );

		$this->assertNotSame( $long_description, $result['description'] );
		$this->assertSame( wp_trim_words( $long_description, 40 ), $result['description'] );
	}

	/** A relative og:image path is resolved against the page's own URL. */
	public function test_resolve_resolves_relative_image_url(): void {
		$this->mock_response(
			'https://blog.example/relative-image',
			'<html><head><meta property="og:title" content="Relative image test"><meta property="og:image" content="/uploads/cover.jpg"></head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/relative-image' );

		$this->assertSame( 'https://blog.example/uploads/cover.jpg', $result['image'] );
	}

	/** A javascript: image URL is never trusted, even when otherwise well-formed. */
	public function test_resolve_rejects_unsafe_image_scheme(): void {
		$this->mock_response(
			'https://blog.example/unsafe-image',
			'<html><head><meta property="og:title" content="Unsafe image test"><meta property="og:image" content="javascript:alert(1)"></head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/unsafe-image' );

		$this->assertSame( '', $result['image'] );
	}

	/** A second lookup for the same URL is served from the transient cache. */
	public function test_resolve_caches_result(): void {
		$this->mock_response(
			'https://blog.example/cached',
			'<html><head><meta property="og:title" content="First title"></head></html>'
		);

		$first = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/cached' );

		// Change the mapped response — if resolve() actually re-fetched, the
		// second call would return this instead of the cached result.
		$this->mock_response(
			'https://blog.example/cached',
			'<html><head><meta property="og:title" content="Second title"></head></html>'
		);

		$second = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/cached' );

		$this->assertSame( $first, $second );
	}
}
