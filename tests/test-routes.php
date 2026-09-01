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
		delete_option( Daymark_Routes::OPTION_LEGACY_APP_BASE );
	}

	private function registered_rule_patterns(): array {
		return array_keys( $this->registered_rules() );
	}

	/**
	 * Full pattern => query-string map. Patterns alone can't distinguish the
	 * redirect rules from the direct ones: '^daymark/?$' is the literal
	 * pattern for both the normal home route (when the base is 'daymark')
	 * and the migrated-install redirect — only the mapped query string
	 * ('daymark_app=home' vs '=redirect-home') tells them apart.
	 *
	 * @return array<string, string>
	 */
	private function registered_rules(): array {
		global $wp_rewrite;
		// Top rules accumulate on the shared WP_Rewrite across in-process
		// tests; start from a clean slate for this registration.
		$wp_rewrite->extra_rules_top = array();

		$routes = new Daymark_Routes();
		$routes->register();

		return $wp_rewrite->extra_rules_top;
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
		self::factory()->post->create(
			array(
				'post_name'  => 'daymark',
				'post_title' => 'Daymark',
			)
		);

		$this->assertSame( 'daymark-app', Daymark_Routes::resolve_app_base() );
	}

	/** A migrated install's old /moment URL redirects to the real app at /daymark. */
	public function test_legacy_base_redirects_to_daymark() {
		update_option( Daymark_Routes::OPTION_LEGACY_APP_BASE, 'moment' );

		$rules = $this->registered_rules();

		$this->assertSame( 'index.php?daymark_app=home', $rules['^daymark/?$'] ?? null, 'The app itself only ever lives at /daymark' );
		$this->assertSame( 'index.php?daymark_app=redirect-home', $rules['^moment/?$'] ?? null, 'The old brand URL redirects rather than staying a second home for the app' );
		$this->assertSame( 'index.php?daymark_app=redirect-notifications', $rules['^moment/notifications/?$'] ?? null );
	}

	/**
	 * An install that migrated before an earlier version of the one-time
	 * Moment-to-Daymark migration stopped carrying the Moment-era base into
	 * daymark_app_base has it stuck there permanently — app_base() must
	 * self-heal that on the next resolution rather than trusting it forever,
	 * the way it does for a genuinely valid base.
	 */
	public function test_stale_app_base_self_heals_to_daymark() {
		update_option( Daymark_Routes::OPTION_APP_BASE, 'moment' );

		$this->assertSame( 'daymark', Daymark_Routes::app_base(), 'A stale pre-rename value must not be trusted as a real base' );
		$this->assertSame( 'daymark', get_option( Daymark_Routes::OPTION_APP_BASE ), 'The correction must persist, not just be returned once' );
		$this->assertSame( 'moment', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ), 'The old value survives only for the redirect' );
	}

	/** Once self-healed, the corrected base is a real value and stays sticky. */
	public function test_stale_app_base_self_heal_is_idempotent() {
		update_option( Daymark_Routes::OPTION_APP_BASE, 'moment' );

		Daymark_Routes::app_base();
		$this->assertSame( 'daymark', Daymark_Routes::app_base(), 'Second call sees an already-valid base and changes nothing' );
	}

	/** An existing legacy-base option is never overwritten by the self-heal either. */
	public function test_stale_app_base_self_heal_does_not_overwrite_existing_legacy_base() {
		update_option( Daymark_Routes::OPTION_APP_BASE, 'moment' );
		update_option( Daymark_Routes::OPTION_LEGACY_APP_BASE, 'already-set' );

		Daymark_Routes::app_base();

		$this->assertSame( 'already-set', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ) );
	}

	/** The self-healed base gets the exact same /daymark route + old-slug redirect a fresh migration once did. */
	public function test_stale_app_base_self_heal_registers_redirect() {
		update_option( Daymark_Routes::OPTION_APP_BASE, 'moment' );

		$rules = $this->registered_rules();

		$this->assertSame( 'index.php?daymark_app=home', $rules['^daymark/?$'] ?? null );
		$this->assertSame( 'index.php?daymark_app=redirect-home', $rules['^moment/?$'] ?? null );
	}

	/** A legacy-base redirect must never shadow real content that now lives at the old slug. */
	public function test_legacy_slug_collision_gets_no_redirect() {
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'moment',
			)
		);
		update_option( Daymark_Routes::OPTION_LEGACY_APP_BASE, 'moment' );

		$rules = $this->registered_rules();

		$this->assertArrayHasKey( '^daymark/?$', $rules );
		$this->assertArrayNotHasKey( '^moment/?$', $rules, 'Must not shadow the page actually living at the old slug' );
	}

	/** A slug collision at /daymark must never have /daymark rewritten out from under the real content. */
	public function test_slug_collision_gets_no_daymark_redirect() {
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'daymark',
			)
		);
		update_option( Daymark_Routes::OPTION_APP_BASE, 'daymark-app' );

		$rules = $this->registered_rules();

		$this->assertArrayHasKey( '^daymark-app/?$', $rules );
		$this->assertArrayNotHasKey( '^daymark/?$', $rules, 'Must not shadow the page actually living at /daymark' );
	}

	/** A fresh, unmigrated install needs no redirect — the base already is 'daymark'. */
	public function test_default_base_gets_no_redirect_rule() {
		$rules = $this->registered_rules();

		$this->assertSame( 'index.php?daymark_app=home', $rules['^daymark/?$'] ?? null, 'The direct app route, not a redirect' );
		$this->assertSame( 'index.php?daymark_app=notifications', $rules['^daymark/notifications/?$'] ?? null );
	}

	/** Once resolved, the base is stable until explicitly re-resolved. */
	public function test_base_is_sticky_between_resolutions() {
		$this->assertSame( 'daymark', Daymark_Routes::resolve_app_base() );

		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'daymark',
			)
		);

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
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'daymark',
			)
		);
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
