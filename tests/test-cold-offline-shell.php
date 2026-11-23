<?php
/**
 * Cold-offline-load support tests (issue #126): the shared config builder,
 * the new config/worker/offline routes, and the offline-fallback
 * shell template itself.
 *
 * The endpoint templates and access guard can exit, so these tests exercise
 * their shared builders, framework route registration, canonical redirect
 * handling, and the offline shell. HTTP response contracts are also covered
 * by tests/smoke.sh against a live site.
 *
 * @package Daymark
 */

/**
 * Exercises build_app_config(), the new rewrite rules, and the
 * offline-shell template's own rendered markup.
 */
class Test_Cold_Offline_Shell extends WP_UnitTestCase {

	/** @var int */
	private $author_id;

	public function set_up(): void {
		parent::set_up();
		$this->author_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author_id );
	}

	/** Render through the same framework configuration and scoped hooks as HTTP. */
	private function render_offline_shell(): string {
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		$this->go_to( '/' );
		set_query_var( 'wp_app_path', Daymark_Routes::app_base() );
		set_query_var( 'wp_app_request', 'offline' );
		$GLOBALS['wp_app_route'] = array(
			'app_path' => Daymark_Routes::app_base(),
			'pattern'  => 'offline',
		);
		$routes                  = new Daymark_Routes();
		$routes->register_app();
		$routes->register_offline_shell();
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		return (string) ob_get_clean();
	}

	/** Read the actual framework-generated worker endpoint. */
	private function worker_script(): string {
		$this->render_offline_shell();
		ob_start();
		\WpApp\Pwa::maybe_handle_app_request( Daymark_Routes::app_base(), 'worker' );
		return (string) ob_get_clean();
	}

	/** build_app_config() always includes a fresh, real nonce — the live path's own contract, unchanged from the inline-script era. */
	public function test_build_app_config_includes_a_live_nonce() {
		$config = Daymark_Routes::build_app_config();

		$this->assertArrayHasKey( 'nonce', $config );
		$this->assertNotSame( '', $config['nonce'] );
		$this->assertTrue( (bool) wp_verify_nonce( $config['nonce'], 'wp_rest' ), 'Must be a real, currently-valid REST nonce' );
	}

	/** The new appUrl field is a trailing-slash directory URL, distinct from the existing bare app_url(). */
	public function test_build_app_config_appurl_is_directory_shaped() {
		$config = Daymark_Routes::build_app_config();

		$this->assertArrayHasKey( 'appUrl', $config );
		$this->assertStringEndsWith( '/', $config['appUrl'] );
		$this->assertSame( Daymark_Routes::app_url() . '/', $config['appUrl'] );
	}

	/** app-shell.php's own inline config and build_app_config()'s default output carry the same shape — one source of truth, not two that could drift. */
	public function test_build_app_config_matches_app_shell_inline_config_shape() {
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		$this->go_to( '/' );
		set_query_var( 'wp_app_path', Daymark_Routes::app_base() );
		set_query_var( 'wp_app_request', '' );

		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/app-shell.php';
		$html = (string) ob_get_clean();

		preg_match( '/window\.daymarkApp = (\{.*\});/', $html, $matches );
		$this->assertNotEmpty( $matches, 'Inline config must be present' );
		$inline_config = json_decode( $matches[1], true );

		$config = Daymark_Routes::build_app_config( 'home' );

		$this->assertSame( array_keys( $config ), array_keys( $inline_config ), 'Both must build the exact same set of keys' );
	}

	/** The offline endpoints use exact framework paths and dedicated templates. */
	public function test_new_routes_are_registered() {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		$routes = new Daymark_Routes();
		$routes->register_app();
		\WpApp\Registry::add_all_rewrite_rules();
		$router = \WpApp\Registry::get_apps()[ Daymark_Routes::app_base() ];
		$this->assertSame( 'app-config.php', $router->get_routes()['config']['template'] );
		$this->assertSame( 'app-worker.php', $router->get_routes()['worker']['template'] );
		$this->assertSame( 'app-offline.php', $router->get_routes()['offline']['template'] );
	}

	/** All three new routes skip the canonical trailing-slash redirect, same as manifest already did. */
	public function test_new_routes_skip_canonical_redirect() {
		$routes = new Daymark_Routes();
		$routes->register_app();
		\WpApp\Registry::add_all_rewrite_rules();

		foreach ( array( 'config', 'worker', 'offline' ) as $screen ) {
			set_query_var( 'wp_app_path', Daymark_Routes::app_base() );
			set_query_var( 'wp_app_request', $screen );
			$this->assertFalse(
				apply_filters( 'redirect_canonical', home_url( "/daymark/{$screen}/" ) ),
				"The '{$screen}' screen must serve directly, not bounce through a 301"
			);
		}

		set_query_var( 'wp_app_path', '' );
		set_query_var( 'wp_app_request', '' );
	}

	/** All placeholders in the served worker are filled, so no literal token reaches a browser. */
	public function test_served_service_worker_has_no_unfilled_placeholders() {
		$script = $this->worker_script();

		$this->assertNotSame( '', $script );
		$this->assertStringNotContainsString( '__DAYMARK_', $script );
		$this->assertStringContainsString( str_replace( '/', '\\/', DAYMARK_PLUGIN_URL . 'assets/' ), $script );
		$this->assertStringContainsString( str_replace( '/', '\\/', esc_url_raw( rest_url( 'daymark/v1/' ) ) ), $script );
	}

	/**
	 * The cache name carries a version. The worker re-installs (and so
	 * re-downloads the cached app.js/app.css) only when its own bytes change,
	 * so a fixed cache name kept an installed app on its install-day
	 * JavaScript through every later release.
	 */
	public function test_service_worker_cache_name_carries_the_version() {
		$version = Daymark_Routes::service_worker_cache_version();

		$this->assertMatchesRegularExpression( '/^' . preg_quote( DAYMARK_VERSION, '/' ) . '-[0-9a-f]{8}$/', $version );
		$this->assertStringContainsString( 'wp-app-' . Daymark_Routes::app_base() . '-' . $version, $this->worker_script() );
		$this->assertSame( $version, Daymark_Routes::service_worker_cache_version(), 'Stable across calls when nothing changed' );
	}

	/** Offline data is selected explicitly and live credentials never enter the cache. */
	public function test_offline_data_excludes_credentials() {
		$config = Daymark_Routes::build_offline_config();
		$this->assertSame( $this->author_id, $config['userId'] );
		$this->assertSame( $this->author_id, $config['currentUser']['id'] );
		$this->assertArrayNotHasKey( 'logoutUrl', $config['currentUser'] );
		$this->assertArrayNotHasKey( 'nonce', $config );
		$this->assertArrayNotHasKey( 'pendingDraftId', $config );
		$this->assertArrayHasKey( 'localeData', $config );
		$script = $this->worker_script();
		$this->assertStringNotContainsString( 'redactAndCacheConfig', $script );
		$this->assertStringNotContainsString( "scopePath + 'config'", $script );
	}

	/** The offline-fallback shell renders with no per-request nonce anywhere, and no inline <script> at all. */
	public function test_offline_shell_has_no_nonce_or_inline_script() {
		$html = $this->render_offline_shell();

		$this->assertStringNotContainsString( 'nonce=', $html, 'The offline shell must never carry a per-request nonce' );
		$this->assertDoesNotMatchRegularExpression( '/<script(?![^>]*\bsrc=)[^>]*>/', $html, 'Every script must be external (src=), never inline' );
	}

	/** The offline shell's own CSP (meta tag, since it may be replayed from Cache Storage well after being generated) allows only 'self' scripts — no nonce, no unsafe-inline. */
	public function test_offline_shell_csp_has_no_nonce_or_unsafe_inline() {
		$html = $this->render_offline_shell();

		preg_match( '/<meta http-equiv="Content-Security-Policy" content="([^"]*)"/', $html, $matches );
		$this->assertNotEmpty( $matches, 'A CSP meta tag must be present' );

		$csp = html_entity_decode( $matches[1] );
		$this->assertStringContainsString( "script-src 'self'", $csp );
		$this->assertStringNotContainsString( 'nonce-', $csp, 'No per-request nonce anywhere in the CSP' );

		// style-src is allowed its own 'unsafe-inline' (matching the real
		// app shell's own CSP) — only script-src must never carry it.
		preg_match( '/script-src[^;]*/', $csp, $script_src_matches );
		$this->assertStringNotContainsString( 'unsafe-inline', $script_src_matches[0] ?? '', 'script-src specifically must never fall back to unsafe-inline' );
	}

	/** The entry adapter uses the framework bootstrap and its extensible worker. */
	public function test_offline_shell_wires_up_offline_boot_script() {
		$html = $this->render_offline_shell();

		$this->assertStringContainsString( 'offline-boot.js', $html );
		preg_match( '/data-wp-app-bootstrap="([^"]*)"/', $html, $matches );
		$this->assertNotEmpty( $matches );
		$bootstrap = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
		$this->assertSame( Daymark_Routes::app_url( 'config' ), $bootstrap['url'] );
		$this->assertSame( $this->author_id, $bootstrap['offline']['userId'] );
		$this->assertSame( Daymark_Routes::app_url( 'worker' ), $bootstrap['worker']['url'] );
		$this->assertSame( 'daymark-refresh-offline-shell', $bootstrap['worker']['cacheMessageType'] );
		$this->assertStringContainsString( "self.addEventListener('push'", $this->worker_script() );
		$this->assertStringContainsString( 'offline/client.js', $html );
		$this->assertStringContainsString( 'offline\\/client.js', $this->worker_script() );
	}

	/**
	 * The offline shell loads the Featured Content gallery slider and its
	 * styles, and the worker precaches and serves both, so a bookmarked
	 * gallery is a slider offline too, not stacked photos.
	 */
	public function test_offline_shell_loads_and_worker_caches_the_gallery_slider() {
		$html = $this->render_offline_shell();

		$this->assertStringContainsString( 'assets/featured-content.js', $html );
		$this->assertStringContainsString( 'assets/featured-content.css', $html );
		$this->assertLessThan( strpos( $html, 'id="daymark-offline-offline-boot-js"' ), strpos( $html, 'featured-content.js' ), 'The slider loads before app.js is injected' );

		$script = $this->worker_script();

		$this->assertStringContainsString( 'assets\\/featured-content.js', $script );
		$this->assertStringContainsString( 'assets\\/featured-content.css', $script );
	}

	/**
	 * The offline shell loads the Note length count before app.js is
	 * injected, and the worker precaches and serves it, so a Note written
	 * offline still shows its count.
	 */
	public function test_offline_shell_loads_and_worker_caches_the_note_length_count() {
		$html = $this->render_offline_shell();

		$this->assertStringContainsString( 'assets/note-length.js', $html );
		$this->assertLessThan( strpos( $html, 'id="daymark-offline-offline-boot-js"' ), strpos( $html, 'note-length.js' ), 'The count loads before app.js is injected' );

		$script = $this->worker_script();

		$this->assertStringContainsString( 'assets\\/note-length.js', $script );
		$this->assertContains( DAYMARK_PLUGIN_URL . 'assets/note-length.js?ver=' . DAYMARK_VERSION, \WpApp\Pwa::get_config( Daymark_Routes::app_base() )['precache'] );
	}

	/** The offline shell's own body/container markup matches the real app shell's, so app.js renders into the same structure either way. */
	public function test_offline_shell_matches_real_shell_container_markup() {
		$html = $this->render_offline_shell();

		$this->assertStringContainsString( '<div id="daymark-app" class="daymark-shell">', $html );
	}
	/** Every declared document asset must match an exact shell precache URL. */
	public function test_rendered_offline_assets_match_the_framework_precache() {
		$html   = $this->render_offline_shell();
		$cached = \WpApp\Pwa::get_config( Daymark_Routes::app_base() )['precache'];
		preg_match_all( '/<(?:script|link)[^>]+(?:src|href)="([^"]+)"/', $html, $matches );
		foreach ( $matches[1] as $url ) {
			$url = html_entity_decode( $url, ENT_QUOTES );
			if ( preg_match( '/\.(js|css)(\?|$)/', $url ) ) {
				$this->assertContains( $url, $cached, 'Exact offline asset URL must be precached: ' . $url );
			}
		}
	}
}
