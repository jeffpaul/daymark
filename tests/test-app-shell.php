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

		$timeline_id = Daymark_Plugin::get_daymark_pages()['timeline'];
		$this->assertStringContainsString( '"pages":', $html );
		$this->assertStringContainsString(
			str_replace( '/', '\/', (string) get_permalink( $timeline_id ) ),
			$html,
			'Config must carry the timeline page permalink'
		);
	}

	/** The shell stays hermetic: no admin bar, no theme head/footer output. */
	public function test_shell_has_no_admin_chrome() {
		$html = $this->render_shell();

		$this->assertStringNotContainsString( 'wpadminbar', $html );
		$this->assertStringNotContainsString( 'adminmenu', $html );
	}
}
