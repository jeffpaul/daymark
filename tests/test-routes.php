<?php
/**
 * App route base resolution tests.
 *
 * @package Moment
 */

/**
 * The /moment route must step aside (to /moment-app) when existing site
 * content already owns that path, instead of silently shadowing it.
 */
class Test_Routes extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Moment_Routes::OPTION_APP_BASE );
	}

	private function registered_rule_patterns(): array {
		global $wp_rewrite;
		// Top rules accumulate on the shared WP_Rewrite across in-process
		// tests; start from a clean slate for this registration.
		$wp_rewrite->extra_rules_top = array();

		$routes = new Moment_Routes();
		$routes->register();

		return array_keys( $wp_rewrite->extra_rules_top );
	}

	/** Default: no content at /moment, the app claims it. */
	public function test_default_base_is_moment() {
		$this->assertSame( 'moment', Moment_Routes::resolve_app_base() );
		$this->assertSame( home_url( '/moment' ), Moment_Routes::app_url() );
		$this->assertSame( home_url( '/moment/notifications' ), Moment_Routes::app_url( 'notifications' ) );
		$this->assertContains( '^moment/?$', $this->registered_rule_patterns() );
	}

	/** A page at /moment pushes the app to /moment-app. */
	public function test_existing_page_moves_app_to_fallback_base() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'moment',
				'post_title'   => 'A Moment in Time',
				'post_content' => 'User content that must stay reachable.',
			)
		);

		$this->assertSame( 'moment-app', Moment_Routes::resolve_app_base() );
		$this->assertSame( home_url( '/moment-app' ), Moment_Routes::app_url() );

		$patterns = $this->registered_rule_patterns();
		$this->assertContains( '^moment-app/?$', $patterns );
		$this->assertNotContains( '^moment/?$', $patterns, 'The user page URL must not be shadowed' );

		// The Plugins-page link follows the resolved base.
		$links = apply_filters(
			'plugin_action_links_' . plugin_basename( MOMENT_PLUGIN_FILE ),
			array()
		);
		$this->assertStringContainsString( home_url( '/moment-app' ), $links['open-moment'] );
	}

	/** A post (not page) at /moment also counts as taken. */
	public function test_existing_post_also_moves_app() {
		self::factory()->post->create( array( 'post_name' => 'moment', 'post_title' => 'Moment' ) );

		$this->assertSame( 'moment-app', Moment_Routes::resolve_app_base() );
	}

	/** Once resolved, the base is stable until explicitly re-resolved. */
	public function test_base_is_sticky_between_resolutions() {
		$this->assertSame( 'moment', Moment_Routes::resolve_app_base() );

		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'moment' ) );

		$this->assertSame( 'moment', Moment_Routes::app_base(), 'app_base() must not move once persisted' );
		$this->assertSame( 'moment-app', Moment_Routes::resolve_app_base(), 'Re-activation re-resolves' );
	}

	/** Manifest requests skip the canonical trailing-slash redirect. */
	public function test_manifest_skips_canonical_redirect() {
		$routes = new Moment_Routes();
		$routes->register();

		set_query_var( Moment_Routes::QUERY_VAR, 'manifest' );
		$this->assertFalse(
			apply_filters( 'redirect_canonical', home_url( '/moment/manifest.json/' ) ),
			'Manifest must serve directly, not bounce through a 301'
		);

		set_query_var( Moment_Routes::QUERY_VAR, '' );
		$this->assertSame(
			home_url( '/somewhere/' ),
			apply_filters( 'redirect_canonical', home_url( '/somewhere/' ) ),
			'Other requests keep normal canonical redirects'
		);
	}

	/** The manifest tracks the resolved base and uses PNG plugin-URL icons. */
	public function test_manifest_tracks_base() {
		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'moment' ) );
		Moment_Routes::resolve_app_base();

		$manifest = Moment_Routes::build_manifest();

		$this->assertSame( home_url( '/moment-app' ), $manifest['start_url'] );
		$this->assertSame( home_url( '/moment-app' ), $manifest['scope'] );
		$this->assertStringStartsWith( MOMENT_PLUGIN_URL, $manifest['icons'][0]['src'] );
		// No SVG icon — iOS shows no home-screen icon when one is present.
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringNotContainsString( '.svg', $icon['src'] );
		}
	}

	/** Falls back to Moment's bundled PNG when the site has no Site Icon. */
	public function test_icon_url_falls_back_to_bundled_png() {
		$this->assertStringEndsWith( 'assets/icon-192.png', Moment_Routes::icon_url( 180 ) );
		$this->assertStringEndsWith( 'assets/icon-512.png', Moment_Routes::icon_url( 512 ) );
	}

	/** Prefers the site's own Site Icon when one is set. */
	public function test_icon_url_prefers_site_icon() {
		$filter = static function () {
			return 'https://example.test/site-icon.png';
		};
		add_filter( 'get_site_icon_url', $filter );

		$this->assertSame( 'https://example.test/site-icon.png', Moment_Routes::icon_url( 180 ) );
		$this->assertSame( 'https://example.test/site-icon.png', Moment_Routes::build_manifest()['icons'][0]['src'] );

		remove_filter( 'get_site_icon_url', $filter );
	}
}
