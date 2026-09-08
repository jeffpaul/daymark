<?php
/**
 * Cold-offline-load support tests (issue #126): the shared config builder,
 * the new config.json/sw.js/offline.html routes, and the offline-fallback
 * shell template itself.
 *
 * The config.json/sw.js branches inside
 * Daymark_Routes::maybe_load_app_shell() call exit() directly (same as the
 * pre-existing manifest.json branch), so — matching this codebase's own
 * established pattern for that branch (see Test_Routes' manifest tests) —
 * this file exercises the underlying, non-exiting building blocks
 * (build_app_config(), the registered rewrite rules, the canonical-redirect
 * skip, and the offline-shell template's own rendered output) rather than
 * the exit-calling branch itself; real HTTP-level behavior (the 401 gate,
 * actual JSON/JS response bodies) is covered by tests/smoke.sh against a
 * live site.
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
		set_query_var( Daymark_Routes::QUERY_VAR, 'home' );

		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/app-shell.php';
		$html = (string) ob_get_clean();

		preg_match( '/window\.daymarkApp = (\{.*\});/', $html, $matches );
		$this->assertNotEmpty( $matches, 'Inline config must be present' );
		$inline_config = json_decode( $matches[1], true );

		$config = Daymark_Routes::build_app_config( 'home' );

		$this->assertSame( array_keys( $config ), array_keys( $inline_config ), 'Both must build the exact same set of keys' );
	}

	/** The three new routes are registered, same convention as every other app-base rewrite rule. */
	public function test_new_routes_are_registered() {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		$routes = new Daymark_Routes();
		$routes->register();
		$rules = $wp_rewrite->extra_rules_top;

		$this->assertSame( 'index.php?daymark_app=config', $rules['^daymark/config\.json$'] ?? null );
		$this->assertSame( 'index.php?daymark_app=sw', $rules['^daymark/sw\.js$'] ?? null );
		$this->assertSame( 'index.php?daymark_app=offline', $rules['^daymark/offline\.html$'] ?? null );
	}

	/** All three new routes skip the canonical trailing-slash redirect, same as manifest.json already did. */
	public function test_new_routes_skip_canonical_redirect() {
		$routes = new Daymark_Routes();
		$routes->register();

		foreach ( array( 'config', 'sw', 'offline' ) as $screen ) {
			set_query_var( Daymark_Routes::QUERY_VAR, $screen );
			$this->assertFalse(
				apply_filters( 'redirect_canonical', home_url( "/daymark/{$screen}/" ) ),
				"The '{$screen}' screen must serve directly, not bounce through a 301"
			);
		}

		set_query_var( Daymark_Routes::QUERY_VAR, '' );
	}

	/** The offline-fallback shell renders with no per-request nonce anywhere, and no inline <script> at all. */
	public function test_offline_shell_has_no_nonce_or_inline_script() {
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'nonce=', $html, 'The offline shell must never carry a per-request nonce' );
		$this->assertDoesNotMatchRegularExpression( '/<script(?![^>]*\bsrc=)[^>]*>/', $html, 'Every script must be external (src=), never inline' );
	}

	/** The offline shell's own CSP (meta tag, since it may be replayed from Cache Storage well after being generated) allows only 'self' scripts — no nonce, no unsafe-inline. */
	public function test_offline_shell_csp_has_no_nonce_or_unsafe_inline() {
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		$html = (string) ob_get_clean();

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

	/** offline-boot.js is wired up with the two data attributes it reads via document.currentScript. */
	public function test_offline_shell_wires_up_offline_boot_script() {
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'offline-boot.js', $html );
		$this->assertStringContainsString( 'data-config-url="' . esc_url( Daymark_Routes::app_url( 'config.json' ) ), $html );
		$this->assertStringContainsString( 'data-app-js-url="' . esc_url( DAYMARK_PLUGIN_URL . 'assets/app.js' ), $html );
	}

	/** The offline shell's own body/container markup matches the real app shell's, so app.js renders into the same structure either way. */
	public function test_offline_shell_matches_real_shell_container_markup() {
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div id="daymark-app" class="daymark-shell">', $html );
	}
}
