<?php
/**
 * Follow link and "Follow me with Daymark" block tests (issue #560).
 *
 * Daymark_Follow_Link::maybe_redirect() exits, so what's tested is the
 * redirect_url() it acts on.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Follow_Link.
 */
class Test_Follow_Link extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		set_query_var( Daymark_Routes::QUERY_VAR, '' );

		parent::tear_down();
	}

	/**
	 * Addresses read_url() accepts, and what it makes of them.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function addresses(): array {
		return array(
			'bare host gets https'       => array( 'example.com', 'https://example.com' ),
			'scheme kept'                => array( 'http://example.com/', 'http://example.com/' ),
			'subdirectory kept'          => array( 'example.com/blog/', 'https://example.com/blog/' ),
			'protocol-relative'          => array( '//example.com', 'https://example.com' ),
			'whitespace trimmed'         => array( "  example.com\n", 'https://example.com' ),
			'other schemes refused'      => array( 'ftp://example.com/', '' ),
			'script refused'             => array( 'javascript:alert(1)', '' ),
			'empty'                      => array( '', '' ),
			'not a string'               => array( array( 'example.com' ), '' ),
			'longer than 2000 refused'   => array( 'https://example.com/' . str_repeat( 'a', 2000 ), '' ),
			'no host refused'            => array( 'https://', '' ),
			'port kept'                  => array( 'example.com:8080', 'https://example.com:8080' ),
			'query kept, as a page link' => array( 'example.com/?p=1', 'https://example.com/?p=1' ),
		);
	}

	/**
	 * @dataProvider addresses
	 *
	 * @param mixed  $raw      Query arg value.
	 * @param string $expected Expected address, or '' when refused.
	 */
	public function test_read_url( $raw, string $expected ) {
		$this->assertSame( $expected, Daymark_Follow_Link::read_url( $raw ) );
	}

	public function test_app_url_carries_the_site_to_follow() {
		$url = Daymark_Follow_Link::app_url( 'https://example.com/' );

		$this->assertStringStartsWith( Daymark_Routes::app_url(), $url );
		$this->assertStringContainsString( 'daymark_follow_site=https%3A%2F%2Fexample.com%2F', $url );
	}

	public function test_a_follow_link_anywhere_on_the_site_goes_to_the_app() {
		$_GET[ Daymark_Follow_Link::QUERY_VAR ] = 'example.com';

		$this->assertSame( Daymark_Follow_Link::app_url( 'https://example.com' ), Daymark_Follow_Link::redirect_url() );
	}

	public function test_the_app_reads_a_follow_link_itself() {
		$_GET[ Daymark_Follow_Link::QUERY_VAR ] = 'example.com';
		set_query_var( Daymark_Routes::QUERY_VAR, 'home' );

		$this->assertSame( '', Daymark_Follow_Link::redirect_url() );
	}

	public function test_no_redirect_without_a_usable_address() {
		$this->assertSame( '', Daymark_Follow_Link::redirect_url() );

		$_GET[ Daymark_Follow_Link::QUERY_VAR ] = 'ftp://example.com/';
		$this->assertSame( '', Daymark_Follow_Link::redirect_url() );
	}

	public function test_redirect_only_ever_goes_to_this_sites_app() {
		$_GET[ Daymark_Follow_Link::QUERY_VAR ] = 'https://evil.example/';

		$this->assertStringStartsWith( Daymark_Routes::app_url() . '?', Daymark_Follow_Link::redirect_url() );
	}

	public function test_block_is_registered() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Daymark_Follow_Link::BLOCK ) );
	}

	public function test_block_renders_a_hidden_form_and_the_feed_address() {
		$html = do_blocks( '<!-- wp:daymark/follow-me /-->' );

		$this->assertStringContainsString( 'wp-block-daymark-follow-me', $html );
		$this->assertStringContainsString( 'data-daymark-follow-me', $html );
		$this->assertStringContainsString( 'data-site="' . esc_attr( home_url( '/' ) ) . '"', $html );
		// Hidden until the front-end script reveals it, so with scripts off
		// only the feed address shows.
		$this->assertStringContainsString( 'novalidate hidden>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_feed_link() ) . '"', $html );
	}

	public function test_block_preview_shows_the_form() {
		$html = do_blocks( '<!-- wp:daymark/follow-me {"preview":true} /-->' );

		$this->assertStringContainsString( '<form class="daymark-follow-me__form" novalidate>', $html );
	}

	public function test_block_in_a_feed_shows_only_the_feed_address() {
		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );

		$html = do_blocks( '<!-- wp:daymark/follow-me /-->' );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'data-site', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_feed_link() ) . '"', $html );
	}

	public function test_app_config_carries_the_site_to_follow() {
		$this->assertSame( 'https://example.com', Daymark_Routes::build_app_config( 'home', '', 0, 0, 'example.com' )['pendingFollowUrl'] );
		$this->assertSame( '', Daymark_Routes::build_app_config( 'home', '', 0, 0, 'ftp://example.com/' )['pendingFollowUrl'] );
		$this->assertSame( '', Daymark_Routes::build_app_config()['pendingFollowUrl'] );
	}
}
