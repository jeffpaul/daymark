<?php
/**
 * Daymark_Subscription_Oembed tests (issue #279): resolve() end to end,
 * with the network side short-circuited via WordPress core's own
 * `pre_oembed_result` filter (the documented way to make wp_oembed_get()
 * return canned HTML with no real HTTP request at all — see its own
 * docblock in wp-includes/class-wp-oembed.php) rather than mocking the two
 * separate HTTP requests (discovery-page fetch, then the discovered
 * endpoint) WP core's own oEmbed discovery makes internally.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Oembed::resolve().
 */
class Test_Subscription_Oembed extends WP_UnitTestCase {

	/**
	 * URL => canned HTML `pre_oembed_result` should return for it, consulted
	 * by intercept_oembed_result().
	 *
	 * @var array<string, string|false>
	 */
	private array $oembed_results = array();

	public function set_up(): void {
		parent::set_up();

		$this->oembed_results = array();

		add_filter( 'pre_oembed_result', array( $this, 'intercept_oembed_result' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_oembed_result', array( $this, 'intercept_oembed_result' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $result Existing short-circuit value (always null here).
	 * @param string $url    URL being resolved.
	 * @return mixed
	 */
	public function intercept_oembed_result( $result, $url ) {
		if ( array_key_exists( $url, $this->oembed_results ) ) {
			return $this->oembed_results[ $url ];
		}

		// Anything unmapped resolves to no oEmbed found — WP_oEmbed's own
		// "no provider" contract — never a real HTTP request either way,
		// since this filter always short-circuits before one would be made.
		return false;
	}

	/** An empty URL never even reaches wp_oembed_get(). */
	public function test_resolve_returns_empty_for_blank_url() {
		$this->assertSame( array(), Daymark_Subscription_Oembed::resolve( '' ) );
	}

	/** A non-http(s) scheme is rejected before any lookup is attempted. */
	public function test_resolve_rejects_non_http_scheme() {
		$this->assertSame( array(), Daymark_Subscription_Oembed::resolve( 'javascript:alert(1)' ) );
	}

	/**
	 * A URL the SSRF guard rejects (here, one that resolves to a private
	 * address via the test-only DNS-resolution filter) never reaches
	 * wp_oembed_get() at all.
	 */
	public function test_resolve_rejects_unsafe_url() {
		$filter = function () {
			return array( '10.0.0.5' );
		};

		add_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$this->oembed_results['https://internal.example/post'] = '<iframe src="https://internal.example/embed"></iframe>';

		$result = Daymark_Subscription_Oembed::resolve( 'https://internal.example/post' );

		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$this->assertSame( array(), $result );
	}

	/**
	 * A provider's "rich"/video-type oEmbed response (an <iframe>) is
	 * reduced to a minimal, attribute-allowlisted iframe of this class's
	 * own construction — never the provider's raw markup, and never a
	 * <script> tag it may have carried alongside it.
	 */
	public function test_resolve_extracts_safe_iframe() {
		$this->oembed_results['https://social.example/@person/1'] =
			'<iframe src="https://social.example/@person/1/embed" width="400" height="200" title="A toot"></iframe><script>doWidgetThings();</script>';

		$result = Daymark_Subscription_Oembed::resolve( 'https://social.example/@person/1' );

		$this->assertSame( 'iframe', $result['type'] );
		$this->assertStringContainsString( 'src="https://social.example/@person/1/embed"', $result['html'] );
		$this->assertStringContainsString( 'aspect-ratio:400/200', $result['html'] );
		$this->assertStringContainsString( 'title="A toot"', $result['html'] );
		$this->assertStringContainsString( 'sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"', $result['html'] );
		$this->assertStringNotContainsString( '<script', $result['html'] );
		$this->assertStringNotContainsString( 'doWidgetThings', $result['html'] );
	}

	/** A "photo"-type oEmbed response (a bare <img>) resolves to a photo preview. */
	public function test_resolve_extracts_safe_photo() {
		$this->oembed_results['https://photos.example/1'] = '<img src="https://photos.example/1.jpg" alt="A photo" width="800" height="600" />';

		$result = Daymark_Subscription_Oembed::resolve( 'https://photos.example/1' );

		$this->assertSame( 'photo', $result['type'] );
		$this->assertStringContainsString( 'src="https://photos.example/1.jpg"', $result['html'] );
		$this->assertStringContainsString( 'alt="A photo"', $result['html'] );
	}

	/**
	 * A "link"-type oEmbed (a bare blockquote relying on a provider widget
	 * script, no iframe/img at all) resolves to nothing rather than a
	 * broken, script-less blockquote — the documented "where possible"
	 * scope.
	 */
	public function test_resolve_returns_empty_when_no_safe_element_found() {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- a plain fixture string, not a real <script> tag output by this plugin.
		$this->oembed_results['https://text.example/1'] = '<blockquote>Some quoted text.</blockquote><script src="https://text.example/widgets.js"></script>';

		$this->assertSame( array(), Daymark_Subscription_Oembed::resolve( 'https://text.example/1' ) );
	}

	/** No provider/discovery match at all resolves to an empty array, not an error. */
	public function test_resolve_returns_empty_when_oembed_not_found() {
		$this->assertSame( array(), Daymark_Subscription_Oembed::resolve( 'https://unknown.example/post' ) );
	}

	/** A provider-reported dimension larger than the safety cap is clamped. */
	public function test_resolve_clamps_oversized_dimensions() {
		$this->oembed_results['https://video.example/1'] = '<iframe src="https://video.example/1/embed" width="4000" height="3000"></iframe>';

		$result = Daymark_Subscription_Oembed::resolve( 'https://video.example/1' );

		$this->assertStringContainsString( 'aspect-ratio:600/600', $result['html'] );
	}

	/** A second lookup for the same URL is served from the transient cache. */
	public function test_resolve_caches_result() {
		$this->oembed_results['https://social.example/@person/2'] = '<iframe src="https://social.example/@person/2/embed"></iframe>';

		$first = Daymark_Subscription_Oembed::resolve( 'https://social.example/@person/2' );

		// Change the mapped response — if resolve() actually re-fetched,
		// the second call would return this instead of the cached result.
		$this->oembed_results['https://social.example/@person/2'] = '<img src="https://social.example/changed.jpg" />';

		$second = Daymark_Subscription_Oembed::resolve( 'https://social.example/@person/2' );

		$this->assertSame( $first, $second );
	}
}
