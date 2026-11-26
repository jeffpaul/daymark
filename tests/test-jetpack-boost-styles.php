<?php
/**
 * Daymark's own pages keep their stylesheets on when Jetpack Boost's
 * Critical CSS is active.
 *
 * Boost itself isn't installed here. as_boost_would() below repeats what
 * its Display_Critical_CSS::asynchronize_stylesheets() does to a stylesheet
 * tag (Jetpack Boost, app/lib/critical-css/class-display-critical-css.php),
 * including the `jetpack_boost_async_style` filter that turns it off.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Routes::print_styles() on the three pages that use it.
 */
class Test_Jetpack_Boost_Styles extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );

		// Fresh registries: a handle already printed in this process would
		// print nothing the second time.
		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );

		add_filter( 'style_loader_tag', array( $this, 'as_boost_would' ), 10, 4 );
	}

	/**
	 * What Jetpack Boost does to a stylesheet tag once it has Critical CSS
	 * for the page.
	 *
	 * @param string $html   The stylesheet's link tag.
	 * @param string $handle The style's handle.
	 * @param string $href   The stylesheet's address.
	 * @param string $media  The stylesheet's media attribute.
	 * @return string
	 */
	public function as_boost_would( $html, $handle, $href, $media ) {
		// Jetpack Boost's own filter, under its own name.
		$method = apply_filters( 'jetpack_boost_async_style', 'async', $handle, $media );

		if ( ! in_array( $method, array( 'async', 'deferred' ), true ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( 'link' ) || 'stylesheet' !== $processor->get_attribute( 'rel' ) ) {
			return $html;
		}

		$processor->set_attribute( 'media', 'not all' );
		$processor->set_attribute( 'data-media', $media );

		if ( 'async' === $method ) {
			$processor->set_attribute( 'onload', "this.media=this.dataset.media; delete this.dataset.media; this.removeAttribute( 'onload' );" );
		}

		return '<noscript>' . $html . '</noscript>' . $processor->get_updated_html();
	}

	/**
	 * A page's stylesheets were left as WordPress prints them.
	 *
	 * @param string $html The rendered page.
	 * @return void
	 */
	private function assert_stylesheets_stay_on( string $html ): void {
		$this->assertStringContainsString( "id='daymark-app-css'", $html, 'The page prints the app stylesheet' );
		$this->assertStringNotContainsString( 'not all', $html, 'No stylesheet may be switched off waiting for a handler' );
		$this->assertStringNotContainsString( 'onload=', $html, "The page's script-src blocks an inline handler" );
		$this->assertStringNotContainsString( '<noscript><link', $html, 'No stylesheet is printed twice' );
	}

	/** The stand-in really does switch a stylesheet off on any other page, so the tests below would fail without the fix. */
	public function test_an_ordinary_stylesheet_is_switched_off() {
		wp_register_style( 'daymark-test-ordinary', 'https://example.org/ordinary.css', array(), '1' );

		ob_start();
		wp_print_styles( array( 'daymark-test-ordinary' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'media="not all"', $html );
		$this->assertStringContainsString( 'onload=', $html );
	}

	public function test_app_shell_stylesheets_stay_on() {
		$this->go_to( '/' );
		set_query_var( Daymark_Routes::QUERY_VAR, 'home' );

		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/app-shell.php';
		$html = (string) ob_get_clean();

		$this->assert_stylesheets_stay_on( $html );
		$this->assertStringContainsString( "id='daymark-featured-content-css'", $html );
	}

	public function test_offline_shell_stylesheets_stay_on() {
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		$html = (string) ob_get_clean();

		$this->assert_stylesheets_stay_on( $html );
		$this->assertStringContainsString( "id='daymark-featured-content-css'", $html );
	}

	public function test_bookmarklet_popup_stylesheet_stays_on() {
		$target = Daymark_Bookmarklet::read_target( array() );

		$daymark_bookmarklet = array(
			'target' => $target,
			'state'  => null,
			'follow' => Daymark_Bookmarklet::follow_state( $target ),
			'done'   => '',
			'mark'   => null,
			'error'  => '',
			'script' => Daymark_Bookmarklet::script(),
		);

		ob_start();
		require DAYMARK_PLUGIN_DIR . 'templates/bookmarklet.php';
		$html = (string) ob_get_clean();

		$this->assert_stylesheets_stay_on( $html );
	}
}
