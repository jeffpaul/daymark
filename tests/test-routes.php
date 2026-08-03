<?php
/**
 * App route base resolution tests.
 *
 * @package Daymark
 */

/**
 * The /daymark route must step aside (to /daymark-app) when existing site
 * content already owns that path, instead of silently shadowing it.
 */
class Test_Routes extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Daymark_Routes::OPTION_APP_BASE );
	}

	private function registered_rule_patterns(): array {
		global $wp_rewrite;
		// Top rules accumulate on the shared WP_Rewrite across in-process
		// tests; start from a clean slate for this registration.
		$wp_rewrite->extra_rules_top = array();

		$routes = new Daymark_Routes();
		$routes->register();

		return array_keys( $wp_rewrite->extra_rules_top );
	}

	/** Default: no content at /daymark, the app claims it. */
	public function test_default_base_is_daymark() {
		$this->assertSame( 'daymark', Daymark_Routes::resolve_app_base() );
		$this->assertSame( home_url( '/daymark' ), Daymark_Routes::app_url() );
		$this->assertSame( home_url( '/daymark/notifications' ), Daymark_Routes::app_url( 'notifications' ) );
		$this->assertContains( '^daymark/?$', $this->registered_rule_patterns() );
	}

	/** A page at /daymark pushes the app to /daymark-app. */
	public function test_existing_page_moves_app_to_fallback_base() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'daymark',
				'post_title'   => 'A Mark in Time',
				'post_content' => 'User content that must stay reachable.',
			)
		);

		$this->assertSame( 'daymark-app', Daymark_Routes::resolve_app_base() );
		$this->assertSame( home_url( '/daymark-app' ), Daymark_Routes::app_url() );

		$patterns = $this->registered_rule_patterns();
		$this->assertContains( '^daymark-app/?$', $patterns );
		$this->assertNotContains( '^daymark/?$', $patterns, 'The user page URL must not be shadowed' );

		// The Plugins-page link follows the resolved base.
		$links = apply_filters(
			'plugin_action_links_' . plugin_basename( DAYMARK_PLUGIN_FILE ),
			array()
		);
		$this->assertStringContainsString( home_url( '/daymark-app' ), $links['open-daymark'] );
	}

	/** A post (not page) at /daymark also counts as taken. */
	public function test_existing_post_also_moves_app() {
		self::factory()->post->create( array( 'post_name' => 'daymark', 'post_title' => 'Daymark' ) );

		$this->assertSame( 'daymark-app', Daymark_Routes::resolve_app_base() );
	}

	/** Once resolved, the base is stable until explicitly re-resolved. */
	public function test_base_is_sticky_between_resolutions() {
		$this->assertSame( 'daymark', Daymark_Routes::resolve_app_base() );

		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'daymark' ) );

		$this->assertSame( 'daymark', Daymark_Routes::app_base(), 'app_base() must not move once persisted' );
		$this->assertSame( 'daymark-app', Daymark_Routes::resolve_app_base(), 'Re-activation re-resolves' );
	}

	/** Manifest requests skip the canonical trailing-slash redirect. */
	public function test_manifest_skips_canonical_redirect() {
		$routes = new Daymark_Routes();
		$routes->register();

		set_query_var( Daymark_Routes::QUERY_VAR, 'manifest' );
		$this->assertFalse(
			apply_filters( 'redirect_canonical', home_url( '/daymark/manifest.json/' ) ),
			'Manifest must serve directly, not bounce through a 301'
		);

		set_query_var( Daymark_Routes::QUERY_VAR, '' );
		$this->assertSame(
			home_url( '/somewhere/' ),
			apply_filters( 'redirect_canonical', home_url( '/somewhere/' ) ),
			'Other requests keep normal canonical redirects'
		);
	}

	/** The manifest tracks the resolved base and uses PNG plugin-URL icons. */
	public function test_manifest_tracks_base() {
		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'daymark' ) );
		Daymark_Routes::resolve_app_base();

		$manifest = Daymark_Routes::build_manifest();

		$this->assertSame( home_url( '/daymark-app' ), $manifest['start_url'] );
		$this->assertSame( home_url( '/daymark-app' ), $manifest['scope'] );
		$this->assertStringStartsWith( DAYMARK_PLUGIN_URL, $manifest['icons'][0]['src'] );
		// No SVG icon — iOS shows no home-screen icon when one is present.
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringNotContainsString( '.svg', $icon['src'] );
		}
	}

	/** Falls back to Daymark's bundled PNG when the site has no Site Icon. */
	public function test_icon_url_falls_back_to_bundled_png() {
		$this->assertStringEndsWith( 'assets/icon-32.png', Daymark_Routes::icon_url( 32 ) );
		$this->assertStringEndsWith( 'assets/icon-192.png', Daymark_Routes::icon_url( 180 ) );
		$this->assertStringEndsWith( 'assets/icon-512.png', Daymark_Routes::icon_url( 512 ) );
	}

	/** Prefers the site's own Site Icon when one is set. */
	public function test_icon_url_prefers_site_icon() {
		$filter = static function () {
			return 'https://example.test/site-icon.png';
		};
		add_filter( 'get_site_icon_url', $filter );

		$this->assertSame( 'https://example.test/site-icon.png', Daymark_Routes::icon_url( 180 ) );
		$this->assertSame( 'https://example.test/site-icon.png', Daymark_Routes::build_manifest()['icons'][0]['src'] );

		remove_filter( 'get_site_icon_url', $filter );
	}
}
