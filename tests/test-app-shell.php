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

	/** The bootstrap config carries the current user's identity and the wp-admin Subscriptions link, for the Me/Explore screens. */
	public function test_config_carries_me_and_subscriptions_links() {
		$html = $this->render_shell();

		$this->assertStringContainsString( '"currentUser":', $html );
		$this->assertStringContainsString( '"adminSubscriptionsUrl":', $html );
		$this->assertStringContainsString(
			str_replace( '/', '\/', Daymark_Admin_Subscriptions::page_url() ),
			$html,
			'Config must carry the wp-admin Subscriptions screen URL'
		);
	}

	/**
	 * The bootstrap config carries a site-level icon (Site Icon, falling
	 * back to Daymark's bundled icon via Daymark_Routes::icon_url()) and
	 * title, so Timeline's own-Mark leading icon can identify the site the
	 * same way a subscription post's leading icon already does, rather than
	 * the logged-in user's personal Gravatar.
	 */
	public function test_config_carries_site_icon_and_title() {
		$html = $this->render_shell();

		$this->assertStringContainsString( '"siteTitle":', $html );
		$this->assertStringContainsString( '"siteIconUrl":', $html );
		$this->assertStringContainsString(
			str_replace( '/', '\/', Daymark_Routes::icon_url( 96 ) ),
			$html,
			'Config must carry the resolved site icon URL'
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
	 * img-src/media-src must allow https: sources, not just 'self' — a
	 * subscribed site's own thumbnails/favicons (Subscriptions' whole
	 * point) and this site's own media served through a CDN or offload
	 * plugin (Jetpack Photon, S3, Cloudflare, ...) both come from a
	 * different origin than this site's own. A 'self'-only policy silently
	 * blocks all of those images/media rather than showing them.
	 */
	public function test_csp_allows_https_images_and_media_from_other_hosts() {
		$captured = null;
		$capture  = static function ( $policy ) use ( &$captured ) {
			$captured = $policy;

			return $policy;
		};
		add_filter( 'daymark_app_content_security_policy', $capture );

		$this->render_shell();
		remove_filter( 'daymark_app_content_security_policy', $capture );

		$this->assertStringContainsString( 'img-src', (string) $captured );
		$this->assertMatchesRegularExpression( '/img-src[^;]*\bhttps:/', (string) $captured );
		$this->assertMatchesRegularExpression( '/media-src[^;]*\bhttps:/', (string) $captured );
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
