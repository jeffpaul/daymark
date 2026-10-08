<?php
/**
 * Bookmarklet tests: Reblog and Like the page you're reading elsewhere.
 *
 * The request wrapper (Daymark_Bookmarklet::handle()) calls auth_redirect(),
 * wp_die(), and exit, so it's left to manual and E2E coverage, like
 * Daymark_Share_Target::handle(). What's tested here is the logic it calls.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Bookmarklet.
 */
class Test_Bookmarklet extends WP_UnitTestCase {

	/** @var int */
	private $author;

	/** @var string */
	private $url = 'https://example.com/a-great-post/';

	/** @var callable|null */
	private $webmention_filter = null;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );

		add_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		// No real network in tests: any oEmbed or page fetch fails fast.
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
	}

	public function tear_down(): void {
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );

		if ( null !== $this->webmention_filter ) {
			remove_filter( 'option_active_plugins', $this->webmention_filter );
			$this->webmention_filter = null;
			$dir                     = WP_PLUGIN_DIR . '/webmention';
			wp_delete_file( $dir . '/webmention.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $dir );
		}

		parent::tear_down();
	}

	/**
	 * Resolve every host to a public address, so the URL guard passes.
	 *
	 * @return string[]
	 */
	public function public_address(): array {
		return array( '93.184.216.34' );
	}

	/**
	 * Fail every outbound request.
	 *
	 * @return WP_Error
	 */
	public function block_http() {
		return new WP_Error( 'http_blocked', 'No network in tests.' );
	}

	/**
	 * Fake the Webmention plugin being active, and the target advertising
	 * a Webmention endpoint.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	private function enable_webmention_route( string $url ): void {
		$this->webmention_filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'webmention/webmention.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $this->webmention_filter );
		$dir = WP_PLUGIN_DIR . '/webmention';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/webmention.php', "<?php\n/**\n * Plugin Name: Fake Webmention\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_plugins_cache( false );

		set_transient(
			'daymark_comment_sig_' . md5( $url ),
			array(
				'webmention_endpoint' => 'https://example.com/webmention',
				'rest_root'           => '',
				'post_id'             => 0,
				'jetpack_site_id'     => 0,
				'jetpack_post_id'     => 0,
				'activitypub_object'  => false,
				'bluesky'             => false,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * The page details the bookmarklet would pass for $this->url.
	 *
	 * @return array{url: string, title: string, author: string, host: string}
	 */
	private function target(): array {
		return Daymark_Bookmarklet::read_target(
			array(
				'u' => $this->url,
				't' => 'A Great Post',
				'a' => 'Jane Writer',
			)
		);
	}

	public function test_script_is_a_javascript_url_that_opens_the_popup() {
		$script = Daymark_Bookmarklet::script();

		$this->assertStringStartsWith( 'javascript:', $script );
		$this->assertStringNotContainsString( ' ', $script, 'Fully encoded, so it survives being dragged to a bookmarks bar.' );

		$js = rawurldecode( substr( $script, strlen( 'javascript:' ) ) );
		$this->assertStringContainsString( 'window.open(', $js );
		$this->assertStringContainsString( wp_json_encode( Daymark_Bookmarklet::popup_url(), JSON_UNESCAPED_SLASHES ), $js );
		$this->assertStringContainsString( 'link[rel=canonical]', $js );
	}

	public function test_popup_url_is_on_the_app_base() {
		$this->assertSame( Daymark_Routes::app_url( 'bookmarklet' ), Daymark_Bookmarklet::popup_url() );
	}

	public function test_bookmarklet_route_is_registered() {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();
		( new Daymark_Routes() )->register();

		$this->assertSame(
			'index.php?daymark_app=bookmarklet',
			$wp_rewrite->extra_rules_top[ '^' . Daymark_Routes::app_base() . '/bookmarklet/?$' ] ?? null
		);
	}

	public function test_read_target_rejects_non_http_urls() {
		$target = Daymark_Bookmarklet::read_target( array( 'u' => 'javascript:alert(1)' ) );

		$this->assertSame( '', $target['url'] );
	}

	public function test_read_target_falls_back_to_the_host_for_a_missing_title() {
		$target = Daymark_Bookmarklet::read_target( array( 'u' => $this->url ) );

		$this->assertSame( $this->url, $target['url'] );
		$this->assertSame( 'example.com', $target['title'] );
	}

	public function test_read_target_strips_markup_and_caps_length() {
		$target = Daymark_Bookmarklet::read_target(
			array(
				'u' => $this->url,
				't' => '<b>Bold</b> ' . str_repeat( 'x', 500 ),
			)
		);

		$this->assertStringStartsWith( 'Bold ', $target['title'] );
		$this->assertSame( 200, mb_strlen( $target['title'] ) );
	}

	public function test_reblog_leads_with_an_embed_then_the_comment() {
		$post_id = Daymark_Bookmarklet::reblog( $this->target(), 'My edited title', "Worth a read.\n\nSecond thought." );

		$this->assertIsInt( $post_id );
		$post = get_post( $post_id );

		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'My edited title', $post->post_title );
		$this->assertSame( $this->url, get_post_meta( $post_id, '_daymark_repost_of', true ) );

		$blocks = array_values( array_filter( parse_blocks( $post->post_content ), static fn( $block ) => null !== $block['blockName'] ) );
		$this->assertSame( 'core/embed', $blocks[0]['blockName'] );
		$this->assertSame( $this->url, $blocks[0]['attrs']['url'] );
		$this->assertSame( 'core/paragraph', $blocks[1]['blockName'], 'The comment comes after the embed.' );
		$this->assertStringContainsString( 'Worth a read.', $blocks[1]['innerHTML'] );
		$this->assertStringContainsString( 'Second thought.', $post->post_content );
		$this->assertStringContainsString( 'Jane Writer, example.com', $post->post_content );
	}

	public function test_reblog_without_a_title_uses_the_default() {
		$post_id = Daymark_Bookmarklet::reblog( $this->target(), '', 'Great.' );

		$this->assertSame( 'Reblog: A Great Post', get_the_title( $post_id ) );
	}

	public function test_reblog_without_a_comment_still_publishes() {
		$post_id = Daymark_Bookmarklet::reblog( $this->target(), '', '' );

		$this->assertIsInt( $post_id );
		$this->assertStringContainsString( '<!-- wp:embed', get_post( $post_id )->post_content );
	}

	public function test_reblog_needs_a_url() {
		$result = Daymark_Bookmarklet::reblog( Daymark_Bookmarklet::read_target( array() ), '', 'Hi' );

		$this->assertWPError( $result );
	}

	public function test_reblog_twice_reuses_the_first_reblog() {
		$first  = Daymark_Bookmarklet::reblog( $this->target(), '', 'One' );
		$second = Daymark_Bookmarklet::reblog( $this->target(), '', 'Two' );

		$this->assertSame( $first, $second );
	}

	public function test_state_reports_an_existing_reblog() {
		$post_id = Daymark_Bookmarklet::reblog( $this->target(), '', 'Hi' );
		$state   = Daymark_Bookmarklet::state( $this->target(), false );

		$this->assertSame( $post_id, $state['reblog_id'] );
	}

	public function test_like_is_refused_when_nothing_can_deliver_it() {
		$result = Daymark_Bookmarklet::like( $this->target() );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_like_undeliverable', $result->get_error_code() );
		$this->assertSame( 0, Daymark_Like_Delivery::own_mark_id( '_daymark_like_of', $this->url ) );
		$this->assertFalse( Daymark_Bookmarklet::state( $this->target(), false )['like_available'] );
	}

	public function test_like_publishes_a_like_mark_and_unlike_trashes_it() {
		$this->enable_webmention_route( $this->url );

		$this->assertTrue( Daymark_Bookmarklet::state( $this->target(), true )['like_available'] );

		$result = Daymark_Bookmarklet::like( $this->target() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['liked'] );
		$mark = get_post( $result['mark_id'] );
		$this->assertSame( Daymark_Like_Visibility::POST_TYPE, $mark->post_type );
		$this->assertSame( $this->url, get_post_meta( $mark->ID, '_daymark_like_of', true ) );
		$this->assertTrue( Daymark_Bookmarklet::state( $this->target(), false )['liked'] );

		$again = Daymark_Bookmarklet::like( $this->target() );
		$this->assertSame( $result['mark_id'], $again['mark_id'], 'A second Like reuses the first.' );

		Daymark_Bookmarklet::unlike( $this->target() );
		$this->assertSame( 'trash', get_post_status( $result['mark_id'] ) );
		$this->assertFalse( Daymark_Bookmarklet::state( $this->target(), false )['liked'] );
	}

	public function test_like_on_a_followed_post_uses_the_subscription_post_route() {
		$this->enable_webmention_route( $this->url );

		$subscription_id = ( new Daymark_Subscriptions() )->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);
		$sub_post        = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'A Great Post',
			)
		);
		update_post_meta( $sub_post, 'subscription_id', $subscription_id );
		update_post_meta( $sub_post, 'permalink', $this->url );

		$this->assertSame( $sub_post, Daymark_Bookmarklet::subscription_post_id( $this->url ) );

		$result = Daymark_Bookmarklet::like( $this->target() );

		$this->assertIsArray( $result );
		$this->assertSame( 'classic', $result['method'] );
		$this->assertSame( $this->url, get_post_meta( $result['mark_id'], '_daymark_like_of', true ) );

		$undo = Daymark_Bookmarklet::unlike( $this->target() );
		$this->assertFalse( $undo['liked'] );
		$this->assertSame( 'trash', get_post_status( $result['mark_id'] ) );
	}

	/**
	 * Render templates/bookmarklet.php the way handle() does.
	 *
	 * @param array<string, mixed> $target A read_target() result.
	 * @param array<string, mixed> $extra  Overrides for the template data.
	 * @return string
	 */
	private function render( array $target, array $extra = array() ): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the template's own variable.
		$daymark_bookmarklet = array_merge(
			array(
				'target' => $target,
				'state'  => '' !== $target['url'] ? Daymark_Bookmarklet::state( $target, false ) : null,
				'done'   => '',
				'mark'   => null,
				'error'  => '',
				'script' => Daymark_Bookmarklet::script(),
			),
			$extra
		);

		ob_start();
		require DAYMARK_PLUGIN_DIR . 'templates/bookmarklet.php';

		return (string) ob_get_clean();
	}

	public function test_install_page_shows_the_draggable_link() {
		$html = $this->render( Daymark_Bookmarklet::read_target( array() ) );

		$this->assertStringContainsString( 'href="javascript:', $html );
		$this->assertStringNotContainsString( 'name="daymark_action"', $html );
	}

	public function test_popup_shows_the_reblog_form_with_editable_title_and_comment() {
		$html = $this->render( $this->target() );

		$this->assertStringContainsString( 'name="daymark_comment"', $html );
		$this->assertStringContainsString( 'name="daymark_title" class="daymark-input" value="Reblog: A Great Post"', $html );
		$this->assertStringContainsString( 'value="reblog"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'can’t receive a Like', $html );
	}

	public function test_popup_shows_the_embed_preview_and_a_like_button_when_available() {
		$this->enable_webmention_route( $this->url );
		set_transient(
			'daymark_oembed_' . md5( $this->url ),
			array(
				'type' => 'iframe',
				'html' => '<iframe src="https://example.com/embed/"></iframe>',
			),
			HOUR_IN_SECONDS
		);

		$html = $this->render( $this->target() );

		$this->assertStringContainsString( '<iframe src="https://example.com/embed/"></iframe>', $html );
		$this->assertStringContainsString( 'value="like"', $html );
	}

	public function test_popup_links_to_an_existing_reblog_instead_of_the_form() {
		Daymark_Bookmarklet::reblog( $this->target(), '', 'Hi' );

		$html = $this->render( $this->target() );

		$this->assertStringContainsString( 'You reblogged this post.', $html );
		$this->assertStringNotContainsString( 'name="daymark_comment"', $html );
	}

	public function test_app_config_carries_the_bookmarklet_url() {
		$config = Daymark_Routes::build_app_config();

		$this->assertSame( Daymark_Bookmarklet::popup_url(), $config['bookmarkletUrl'] );
	}
}
