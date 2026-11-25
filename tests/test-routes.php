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
		delete_option( 'daymark_legacy_content_pages' );
		delete_option( 'daymark_nav_routes_added' );
		delete_option( 'daymark_bookmarklet_route_added' );
	}

	/** Screen routes use the shared framework and preserve Daymark's shell. */
	public function test_wp_app_registration(): void {
		$routes = new Daymark_Routes();
		$routes->register_app();
		$base   = Daymark_Routes::app_base();
		$router = \WpApp\Registry::get_apps()[ $base ];

		$this->assertTrue( \WpApp\Masterbar::get_instance_for_app( $base )->is_admin_bar_app_link_enabled() );
		$this->assertSame( 'edit_posts', \WpApp\Registry::get_app_capabilities()[ $base ] );
		foreach ( array( '', 'notifications', 'explore', 'search', 'me' ) as $path ) {
			$this->assertSame( 'app-shell.php', $router->get_routes()[ $path ]['template'] );
		}
		$routes->register_app();
		$this->assertSame( $router, \WpApp\Registry::get_apps()[ $base ], 'Registration is idempotent' );
	}

	/** Framework catch-all rules can identify screens without the legacy var. */
	public function test_current_screen_from_wp_app_route(): void {
		set_query_var( 'wp_app_path', Daymark_Routes::app_base() );
		$routes = new Daymark_Routes();
		$routes->register_app();
		foreach ( array(
			''               => 'home',
			'notifications'  => 'notifications',
			'notifications/' => 'notifications',
			'search'         => 'search',
			'unknown'        => '',
		) as $path => $expected ) {
			set_query_var( 'wp_app_request', $path );
			$this->assertSame( $expected, $routes->current_screen() );
		}
		set_query_var( 'wp_app_path', 'another-app' );
		set_query_var( 'wp_app_request', 'notifications' );
		$this->assertSame( '', $routes->current_screen() );
	}

	/** Framework request detection requires both resolved query variables. */
	public function test_app_request_requires_resolved_framework_route(): void {
		global $wp_query;
		$routes = new Daymark_Routes();
		set_query_var( 'wp_app_path', Daymark_Routes::app_base() );
		set_query_var( 'wp_app_request', '' );
		$this->assertFalse( $routes->is_app_request(), 'An unregistered instance owns no requests' );
		$routes->register_app();
		$this->assertTrue( $routes->is_app_request() );

		unset( $wp_query->query_vars['wp_app_request'] );
		$this->assertFalse( $routes->is_app_request(), 'An app path alone is not a resolved route' );
		$this->assertSame( '', $routes->current_screen() );
		$this->assertSame( 'canonical', $routes->skip_canonical_for_app_assets( 'canonical' ) );

		set_query_var( 'wp_app_request', 'worker' );
		set_query_var( 'wp_app_path', 'another-app' );
		$this->assertFalse( $routes->is_app_request() );
		$this->assertSame( 'canonical', $routes->skip_canonical_for_app_assets( 'canonical' ) );
	}

	/** Detection follows the registered instance rather than rereading its option. */
	public function test_request_detection_uses_registered_app_path(): void {
		$routes = new Daymark_Routes();
		$routes->register_app();
		$base = Daymark_Routes::app_base();
		update_option( Daymark_Routes::OPTION_APP_BASE, 'daymark' === $base ? 'daymark-app' : 'daymark' );
		set_query_var( 'wp_app_path', $base );
		set_query_var( 'wp_app_request', 'notifications/' );
		$this->assertTrue( $routes->is_app_request() );
		$this->assertSame( 'notifications', $routes->current_screen() );

		set_query_var( 'wp_app_request', 'worker/' );
		$this->assertFalse( $routes->skip_canonical_for_app_assets( 'canonical' ) );
		set_query_var( 'wp_app_request', array( 'worker' ) );
		$this->assertSame( 'canonical', $routes->skip_canonical_for_app_assets( 'canonical' ) );
		$this->assertSame( '', $routes->current_screen() );
	}

	/** Screens and extensionless endpoints share one framework namespace. */
	public function test_endpoints_share_the_app_router(): void {
		\WpApp\Registry::reset();
		$routes = new Daymark_Routes();
		$routes->register_app();
		$base = Daymark_Routes::app_base();
		$apps = \WpApp\Registry::get_apps();
		$this->assertSame( array( $base ), array_keys( $apps ) );
		foreach ( array(
			'manifest'    => 'app-manifest.php',
			'config'      => 'app-config.php',
			'worker'      => 'app-worker.php',
			'offline'     => 'app-offline.php',
			'share'       => 'app-share.php',
			'bookmarklet' => 'app-bookmarklet.php',
		) as $path => $template ) {
			$this->assertSame( $template, $apps[ $base ]->get_routes()[ $path ]['template'] );
		}
		$this->assertNull( $apps[ $base ]->get_routes()['manifest']['capability'] );
		$this->assertSame( 'edit_posts', $apps[ $base ]->get_routes()['']['capability'] );
	}

	/** The experiment deliberately does not register retired URL aliases. */
	public function test_no_legacy_route_aliases(): void {
		update_option( 'daymark_legacy_app_base', 'moment' );
		update_option( 'daymark_legacy_content_pages', array( 'notes' => true ) );
		$rules = $this->registered_rules();
		$this->assertNull( $this->query_for_path( $rules, 'moment' ) );
		$this->assertNull( $this->query_for_path( $rules, 'notes' ) );
		$this->assertNull( $this->query_for_path( $rules, 'daymark/config.json' ) );
	}

	private function registered_rule_patterns(): array {
		return array_keys( $this->registered_rules() );
	}

	/**
	 * Framework rewrite rules for this install's resolved paths.
	 *
	 * @return array<string, string>
	 */
	private function registered_rules(): array {
		global $wp_rewrite;
		// Top rules accumulate on the shared WP_Rewrite across in-process
		// tests; start from a clean slate for this registration.
		$wp_rewrite->extra_rules_top = array();
		\WpApp\Registry::reset();

		$routes = new Daymark_Routes();
		$routes->register_app();
		\WpApp\Registry::add_all_rewrite_rules();

		return $wp_rewrite->extra_rules_top;
	}

	/** Resolve a URL using the actual framework rewrite rules. */
	private function query_for_path( array $rules, string $path ): ?string {
		foreach ( $rules as $pattern => $query ) {
			if ( preg_match( '#' . $pattern . '#', $path, $matches ) ) {
				return preg_replace_callback( '/\$matches\[(\d+)\]/', static fn( $capture ) => $matches[ (int) $capture[1] ] ?? '', $query );
			}
		}
		return null;
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
		$this->assertContains( '^daymark\-app/?$', $patterns );
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

		$this->assertArrayHasKey( '^daymark\-app/?$', $rules );
		$this->assertArrayNotHasKey( '^daymark/?$', $rules, 'Must not shadow the page actually living at /daymark' );
	}

	/** A fresh, unmigrated install needs no redirect — the base already is 'daymark'. */
	public function test_default_base_gets_no_redirect_rule() {
		$rules = $this->registered_rules();

		$this->assertSame( 'index.php?wp_app_request=&wp_app_path=daymark', $this->query_for_path( $rules, 'daymark' ), 'The direct app route, not a redirect' );
		$this->assertSame( 'index.php?wp_app_request=notifications&wp_app_path=daymark', $this->query_for_path( $rules, 'daymark/notifications' ) );
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
		$routes->register_app();
		\WpApp\Registry::add_all_rewrite_rules();

		set_query_var( 'wp_app_path', Daymark_Routes::app_base() );
		set_query_var( 'wp_app_request', 'manifest' );
		$this->assertFalse(
			apply_filters( 'redirect_canonical', home_url( '/daymark/manifest/' ) ),
			'Manifest must serve directly, not bounce through a 301'
		);

		set_query_var( 'wp_app_path', '' );
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

	/**
	 * Camera-first: a home-screen shortcut jumps straight to the composer,
	 * and its URL tracks the resolved app base the same as start_url/scope.
	 */
	public function test_manifest_new_mark_shortcut_tracks_base() {
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'daymark',
			)
		);
		Daymark_Routes::resolve_app_base();

		$manifest = Daymark_Routes::build_manifest();

		$this->assertArrayHasKey( 'shortcuts', $manifest );
		$this->assertCount( 1, $manifest['shortcuts'] );
		$this->assertSame(
			home_url( '/daymark-app#create' ),
			$manifest['shortcuts'][0]['url']
		);
	}

	/** Explore/Search/Me are real routes under the app base, same as notifications already was. Search opens Explore in the app (issue #269), and its route stays so saved links work. */
	public function test_nav_destinations_get_real_routes() {
		$patterns = $this->registered_rules();

		$this->assertSame( 'index.php?wp_app_request=explore&wp_app_path=daymark', $this->query_for_path( $patterns, 'daymark/explore' ) );
		$this->assertSame( 'index.php?wp_app_request=search&wp_app_path=daymark', $this->query_for_path( $patterns, 'daymark/search' ) );
		$this->assertSame( 'index.php?wp_app_request=me&wp_app_path=daymark', $this->query_for_path( $patterns, 'daymark/me' ) );
	}

	/** The Web Share Target's action URL (the manifest's share_target.action) is a registered route. */
	public function test_share_target_route_registered() {
		$patterns = $this->registered_rules();

		$this->assertSame( 'index.php?wp_app_request=share&wp_app_path=daymark', $this->query_for_path( $patterns, 'daymark/share' ) );
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

		remove_filter( 'get_site_icon_url', $filter );
	}

	/**
	 * The manifest's own icons never use the site's Site Icon, even when one
	 * is set — a home-screen install is an install of Daymark, not of the
	 * site (issue #414).
	 */
	public function test_manifest_icons_never_use_site_icon() {
		$filter = static function () {
			return 'https://example.test/site-icon.png';
		};
		add_filter( 'get_site_icon_url', $filter );

		$manifest = Daymark_Routes::build_manifest();

		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringStartsWith( DAYMARK_PLUGIN_URL, $icon['src'] );
		}

		remove_filter( 'get_site_icon_url', $filter );
	}

	/**
	 * Android gets maskable icons alongside the transparent "any" ones, and
	 * every icon URL carries a version so a phone that cached an older or
	 * failed copy fetches it again.
	 */
	public function test_manifest_icons_include_maskable_and_are_versioned() {
		$manifest   = Daymark_Routes::build_manifest();
		$by_purpose = array();

		foreach ( $manifest['icons'] as $icon ) {
			$by_purpose[ $icon['purpose'] ][] = $icon['sizes'];
			$this->assertMatchesRegularExpression( '/\?ver=[0-9a-f]{8}$/', $icon['src'] );
		}

		$this->assertSame( array( '192x192', '512x512' ), $by_purpose['any'] );
		$this->assertSame( array( '192x192', '512x512' ), $by_purpose['maskable'] );
	}

	/**
	 * Every home-screen icon file is exactly the size it is declared as and
	 * fully opaque, corners included. iOS drops a transparent
	 * apple-touch-icon (a blank tile), and a maskable icon with transparent
	 * corners shows them on any launcher shape that isn't a circle.
	 */
	public function test_home_screen_icon_files_are_opaque_and_sized() {
		$files = array(
			'apple-touch-icon.png'  => 180,
			'icon-maskable-192.png' => 192,
			'icon-maskable-512.png' => 512,
		);

		foreach ( $files as $file => $size ) {
			$path = DAYMARK_PLUGIN_DIR . 'assets/' . $file;
			$info = getimagesize( $path );

			$this->assertSame( array( $size, $size ), array( $info[0], $info[1] ), "{$file} size" );
			$this->assertSame( 'image/png', $info['mime'], "{$file} type" );

			if ( ! function_exists( 'imagecreatefrompng' ) ) {
				continue;
			}

			$image = imagecreatefrompng( $path );
			foreach ( array( 0, $size - 1 ) as $x ) {
				foreach ( array( 0, $size - 1 ) as $y ) {
					$alpha = ( imagecolorat( $image, $x, $y ) >> 24 ) & 0x7F;
					$this->assertSame( 0, $alpha, "{$file} corner {$x},{$y} must be opaque" );
				}
			}
		}
	}

	/**
	 * The iPhone's fallback icon addresses at the top of the site are
	 * recognised, in every form iOS asks for: plain, sized, and
	 * "precomposed". Firefox for iOS hands iOS no page to read an icon from,
	 * so these addresses are all iOS has.
	 */
	public function test_root_touch_icon_request_matches_the_addresses_ios_asks_for() {
		$matches = array(
			'/apple-touch-icon.png',
			'/apple-touch-icon-precomposed.png',
			'/apple-touch-icon-180x180.png',
			'/apple-touch-icon-120x120-precomposed.png',
			'/apple-touch-icon.png?v=2',
		);

		foreach ( $matches as $uri ) {
			$this->assertTrue( Daymark_Routes::is_root_touch_icon_request( $uri ), $uri );
		}

		$others = array(
			'',
			'/',
			'/daymark',
			'/apple-touch-icon.jpg',
			'/apple-touch-icon',
			'/blog/apple-touch-icon.png',
			'/apple-touch-icon.png/',
			'/apple-touch-icon-big.png',
			'/apple-touch-icon.png.php',
			'/favicon.ico',
			'/?file=/apple-touch-icon.png',
			'?/apple-touch-icon.png',
			"/apple-touch-icon.png\n",
		);

		foreach ( $others as $uri ) {
			$this->assertFalse( Daymark_Routes::is_root_touch_icon_request( $uri ), $uri );
		}
	}

	/**
	 * A request for one of those addresses is answered with Daymark's own
	 * opaque home-screen icon, never the site's Site Icon (issue #414), and
	 * with the file itself rather than a redirect.
	 */
	public function test_root_touch_icon_response_is_daymarks_opaque_icon() {
		$filter = static function () {
			return 'https://example.test/site-icon.png';
		};
		add_filter( 'get_site_icon_url', $filter );

		$response = Daymark_Routes::root_touch_icon_response( 'GET', '/apple-touch-icon.png' );

		remove_filter( 'get_site_icon_url', $filter );

		$this->assertIsArray( $response );
		$this->assertSame( DAYMARK_PLUGIN_DIR . 'assets/apple-touch-icon.png', $response['file'] );
		$this->assertSame( 'image/png', $response['headers']['Content-Type'] );
		$this->assertSame( (string) filesize( $response['file'] ), $response['headers']['Content-Length'] );
		$this->assertStringContainsString( 'max-age=', $response['headers']['Cache-Control'] );
		$this->assertTrue( $response['send_body'] );
	}

	/** A HEAD request gets the same headers and no body. */
	public function test_root_touch_icon_response_to_head_has_no_body() {
		$response = Daymark_Routes::root_touch_icon_response( 'HEAD', '/apple-touch-icon-precomposed.png' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['send_body'] );
	}

	/** Anything else is left for WordPress to handle as usual. */
	public function test_root_touch_icon_response_ignores_other_requests() {
		$this->assertNull( Daymark_Routes::root_touch_icon_response( 'GET', '/daymark' ) );
		$this->assertNull( Daymark_Routes::root_touch_icon_response( 'POST', '/apple-touch-icon.png' ) );
	}

	/**
	 * A site can keep these addresses for itself (an empty value from the
	 * filter), or answer them with a PNG of its own.
	 */
	public function test_root_touch_icon_file_filter_turns_it_off_or_swaps_the_file() {
		add_filter( 'daymark_root_touch_icon_file', '__return_empty_string' );
		$this->assertNull( Daymark_Routes::root_touch_icon_response( 'GET', '/apple-touch-icon.png' ) );
		remove_filter( 'daymark_root_touch_icon_file', '__return_empty_string' );

		$own = static function () {
			return DAYMARK_PLUGIN_DIR . 'assets/icon-maskable-192.png';
		};
		add_filter( 'daymark_root_touch_icon_file', $own );
		$response = Daymark_Routes::root_touch_icon_response( 'GET', '/apple-touch-icon.png' );
		remove_filter( 'daymark_root_touch_icon_file', $own );

		$this->assertSame( DAYMARK_PLUGIN_DIR . 'assets/icon-maskable-192.png', $response['file'] );

		// Not a PNG, not a file, or not a string: nothing is served.
		foreach ( array( DAYMARK_PLUGIN_DIR . 'daymark.php', DAYMARK_PLUGIN_DIR . 'assets/missing.png', false, array() ) as $bad ) {
			$filter = static function () use ( $bad ) {
				return $bad;
			};
			add_filter( 'daymark_root_touch_icon_file', $filter );
			$this->assertNull( Daymark_Routes::root_touch_icon_response( 'GET', '/apple-touch-icon.png' ) );
			remove_filter( 'daymark_root_touch_icon_file', $filter );
		}
	}

	/**
	 * The check is hooked before the rest of init, so it answers ahead of
	 * anything else on the site and without loading a "not found" page.
	 */
	public function test_root_touch_icon_is_served_at_the_start_of_init() {
		$this->assertSame(
			0,
			has_action( 'init', array( Daymark_Plugin::instance()->routes, 'maybe_serve_root_touch_icon' ) )
		);
	}
}
