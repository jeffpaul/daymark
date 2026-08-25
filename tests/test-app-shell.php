<?php
/**
 * App shell template output tests.
 *
 * @package Daymark
 */

/**
 * The shell must load its JS/CSS through the script/style API (wp.org
 * review requirement) while staying free of theme/admin chrome.
 */
class Test_App_Shell extends WP_UnitTestCase {

	private function render_shell(): string {
		// Fresh script/style registries: WP_Scripts marks handles as done
		// after printing, which would blank a second render in-process.
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$this->go_to( '/' );
		set_query_var( Daymark_Routes::QUERY_VAR, 'home' );

		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/app-shell.php';

		return (string) ob_get_clean();
	}

	/** Assets are emitted by the enqueue API, deferred, with inline config. */
	public function test_assets_are_enqueued_via_api() {
		$html = $this->render_shell();

		$this->assertStringContainsString( "id='daymark-app-css'", $html, 'Stylesheet must be printed by the style API' );
		$this->assertStringContainsString( 'id="daymark-app-js"', $html, 'Script must be printed by the script API' );
		$this->assertStringContainsString( 'defer', $html, 'App script must use the defer strategy' );
		$this->assertStringContainsString( 'window.daymarkApp = {', $html, 'Inline config must precede the app script' );
		$this->assertStringContainsString( 'ver=' . DAYMARK_VERSION, $html, 'Assets must carry the plugin version' );

		// Config must be printed before the deferred app script executes.
		$this->assertLessThan(
			strpos( $html, 'assets/app.js' ),
			strpos( $html, 'window.daymarkApp' ),
			'Inline config must come before the app script tag'
		);
	}

	/** The bootstrap config carries real section-page URLs. */
	public function test_config_carries_section_page_urls() {
		Daymark_Plugin::activate();

		$html = $this->render_shell();

		$images_id = Daymark_Plugin::get_daymark_pages()['images'];
		$this->assertStringContainsString( '"pages":', $html );
		$this->assertStringContainsString(
			str_replace( '/', '\/', (string) get_permalink( $images_id ) ),
			$html,
			'Config must carry the images page permalink'
		);
	}

	/** The shell stays hermetic: no admin bar, no theme head/footer output. */
	public function test_shell_has_no_admin_chrome() {
		$html = $this->render_shell();

		$this->assertStringNotContainsString( 'wpadminbar', $html );
		$this->assertStringNotContainsString( 'adminmenu', $html );
	}

	/** The shell emits a conservative, filterable CSP. */
	public function test_shell_emits_content_security_policy() {
		$captured = null;
		$capture  = static function ( $policy ) use ( &$captured ) {
			$captured = $policy;

			return $policy;
		};
		add_filter( 'daymark_app_content_security_policy', $capture );

		$this->render_shell();
		remove_filter( 'daymark_app_content_security_policy', $capture );

		$this->assertIsString( $captured );
		$this->assertStringContainsString( "default-src 'self'", (string) $captured );
		$this->assertStringContainsString( "object-src 'none'", (string) $captured );
		$this->assertStringContainsString( "frame-ancestors 'none'", (string) $captured );
		$this->assertStringContainsString( "script-src 'self'", (string) $captured );
	}

	/**
	 * script-src carries a per-request nonce (not 'unsafe-inline', which
	 * would let ANY injected <script> tag execute, not just the app's own),
	 * and that exact nonce is what actually appears on the inline bootstrap
	 * script — the property that matters, since a mismatch would mean the
	 * browser blocks the very script the app needs to run.
	 */
	public function test_inline_bootstrap_script_carries_the_csp_nonce() {
		$captured = null;
		$capture  = static function ( $policy ) use ( &$captured ) {
			$captured = $policy;

			return $policy;
		};
		add_filter( 'daymark_app_content_security_policy', $capture );

		$html = $this->render_shell();
		remove_filter( 'daymark_app_content_security_policy', $capture );

		preg_match( '/script-src[^;]*/', (string) $captured, $script_src_matches );
		$script_src = $script_src_matches[0] ?? '';

		$this->assertStringNotContainsString( 'unsafe-inline', $script_src, 'script-src must not fall back to unsafe-inline' );

		preg_match( '/nonce-([A-Za-z0-9+\/=]+)/', $script_src, $nonce_matches );
		$this->assertNotEmpty( $nonce_matches, 'script-src must carry a nonce' );

		$this->assertStringContainsString(
			'nonce="' . $nonce_matches[1] . '"',
			$html,
			'The inline bootstrap script must carry the exact nonce the CSP header allows'
		);
	}
}
