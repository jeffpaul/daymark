<?php
/**
 * Bookmarklet tests: Reblog and Like the page you're reading elsewhere,
 * and follow the site it's on.
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

	/**
	 * URL => HTML served instead of the blocked network (see serve_pages()).
	 *
	 * @var array<string, string>
	 */
	private array $pages = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );

		add_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		// No real network in tests: any oEmbed or page fetch fails fast,
		// except the pages a test lists in $this->pages.
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
		add_filter( 'pre_http_request', array( $this, 'serve_pages' ), 20, 3 );

		$this->pages = array();
		Daymark_Subscription_Html_Cache::reset();
	}

	public function tear_down(): void {
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		remove_filter( 'pre_http_request', array( $this, 'serve_pages' ), 20 );

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
	 * Serve a page listed in $this->pages; leave anything else blocked.
	 *
	 * @param mixed  $preempt The earlier filter's answer.
	 * @param array  $args    Request args (unused).
	 * @param string $url     Requested URL.
	 * @return mixed
	 */
	public function serve_pages( $preempt, $args, $url ) {
		if ( ! isset( $this->pages[ $url ] ) ) {
			return $preempt;
		}

		return array(
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			'body'     => $this->pages[ $url ],
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A page that advertises one RSS feed.
	 *
	 * @param string $title Page title.
	 * @param string $feed  Feed URL.
	 * @return string
	 */
	private function page_with_feed( string $title, string $feed ): string {
		return '<html><head><title>' . $title . '</title>'
			. '<link rel="alternate" type="application/rss+xml" href="' . $feed . '" />'
			. '</head><body></body></html>';
	}

	/**
	 * Sign in as a new administrator, who can follow sites.
	 *
	 * @return void
	 */
	private function sign_in_as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

	public function test_reblog_appears_on_the_timeline() {
		$post_id = Daymark_Bookmarklet::reblog( $this->target(), '', 'Worth a read.' );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $post_id, $ids );
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
				'follow' => Daymark_Bookmarklet::follow_state( $target ),
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
		$this->assertStringContainsString( 'href="' . esc_url( Daymark_Routes::app_url( 'me' ) ) . '"', $html );
		$this->assertStringContainsString( 'aria-label="Back to Me"', $html );
	}

	public function test_popup_shows_the_reblog_form_with_editable_title_and_comment() {
		$html = $this->render( $this->target() );

		$this->assertStringContainsString( 'name="daymark_comment"', $html );
		$this->assertStringContainsString( 'name="daymark_title" class="daymark-input" value="Reblog: A Great Post"', $html );
		$this->assertStringContainsString( 'value="reblog"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'can’t receive a Like', $html );
	}

	public function test_reblog_popup_has_no_back_link() {
		$this->assertStringNotContainsString( 'Back to Me', $this->render( $this->target() ) );
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

	public function test_admin_button_is_draggable_but_does_nothing_on_click() {
		$html = Daymark_Bookmarklet::button_html( 'button-primary' );

		$this->assertStringContainsString( 'href="javascript:', $html );
		$this->assertStringContainsString( 'onclick="return false;"', $html );
		$this->assertStringContainsString( 'class="button button-primary"', $html );
	}

	public function test_settings_general_tab_offers_the_bookmarklet() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['tab'] = 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.
		ob_start();
		( new Daymark_Admin_Subscriptions() )->render_page();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'Reblog, Like, and Follow from your browser', $html );
		$this->assertStringContainsString( 'href="javascript:', $html );
	}

	public function test_welcome_notice_offers_the_bookmarklet() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Daymark_Admin_Welcome::queue( true );

		ob_start();
		( new Daymark_Admin_Welcome() )->render_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'drag', $html );
		$this->assertStringContainsString( 'href="javascript:', $html );
	}

	/**
	 * Render the profile screen's Daymark section for a user.
	 *
	 * @param WP_User $user Profile being shown.
	 * @return string
	 */
	private function render_profile_section( WP_User $user ): string {
		ob_start();
		( new Daymark_Microformats() )->render_rel_me_field( $user );

		return (string) ob_get_clean();
	}

	public function test_own_profile_offers_the_bookmarklet() {
		$html = $this->render_profile_section( wp_get_current_user() );

		$this->assertStringContainsString( 'Bookmarklet', $html );
		$this->assertStringContainsString( 'href="javascript:', $html );
	}

	public function test_someone_elses_profile_does_not_offer_the_bookmarklet() {
		$other = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_profile_section( $other );

		$this->assertStringContainsString( 'rel=me link', $html );
		$this->assertStringNotContainsString( 'href="javascript:', $html );
	}

	public function test_profile_of_a_user_who_cannot_write_marks_has_no_bookmarklet() {
		$subscriber = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber->ID );

		$this->assertStringNotContainsString( 'href="javascript:', $this->render_profile_section( $subscriber ) );
	}

	public function test_app_config_carries_the_bookmarklet_url() {
		$config = Daymark_Routes::build_app_config();

		$this->assertSame( Daymark_Bookmarklet::popup_url(), $config['bookmarkletUrl'] );
	}
	// -----------------------------------------------------------------
	// Follow this site (issue #560).
	// -----------------------------------------------------------------

	public function test_only_people_who_can_manage_subscriptions_can_follow() {
		$this->assertFalse( Daymark_Bookmarklet::can_follow() );
		$this->assertNull( Daymark_Bookmarklet::follow_state( $this->target() ) );

		$result = Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_bookmarklet_follow', $result->get_error_code() );
		$this->assertStringNotContainsString( 'value="follow_find"', $this->render( $this->target() ) );
	}

	public function test_popup_offers_to_follow_the_site() {
		$this->sign_in_as_admin();

		$html = $this->render( $this->target() );

		$this->assertStringContainsString( 'value="follow_find"', $html );
		$this->assertStringContainsString( 'Follow example.com', $html );
		// Nothing is looked up until the reader asks.
		$this->assertStringNotContainsString( 'name="daymark_feed_index"', $html );
	}

	public function test_install_page_mentions_following_only_to_people_who_can() {
		$this->assertStringNotContainsString( 'follow the site you’re reading', $this->render( Daymark_Bookmarklet::read_target( array() ) ) );

		$this->sign_in_as_admin();

		$this->assertStringContainsString( 'follow the site you’re reading', $this->render( Daymark_Bookmarklet::read_target( array() ) ) );
	}

	public function test_find_feeds_starts_at_the_site_root() {
		$this->sign_in_as_admin();
		$this->pages['https://example.com/'] = $this->page_with_feed( 'Example Site', '/feed/' );

		$result = Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/', $result['site_url'] );
		$this->assertSame( 'Example Site', $result['site_title'] );
		$this->assertContains( 'https://example.com/feed/', wp_list_pluck( $result['candidates'], 'url' ) );
		// Kept for this page only.
		$this->assertSame( $result, Daymark_Bookmarklet::follow_lookup( $this->url ) );
		$this->assertNull( Daymark_Bookmarklet::follow_lookup( 'https://example.com/another-post/' ) );
	}

	public function test_find_feeds_tries_the_page_when_the_root_has_no_feed() {
		$this->sign_in_as_admin();
		$this->pages['https://example.com/'] = '<html><head><title>Host</title></head><body></body></html>';
		$this->pages[ $this->url ]           = $this->page_with_feed( 'A Great Post', 'https://example.com/a-great-post/feed/' );

		$result = Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );

		$this->assertIsArray( $result );
		$this->assertSame( $this->url, $result['site_url'] );
		$this->assertContains( 'https://example.com/a-great-post/feed/', wp_list_pluck( $result['candidates'], 'url' ) );
	}

	public function test_popup_shows_the_feed_picker_after_a_lookup() {
		$this->sign_in_as_admin();
		$this->pages['https://example.com/'] = $this->page_with_feed( 'Example Site', '/feed/' );
		Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );

		$html = $this->render( $this->target() );

		$this->assertStringContainsString( 'name="daymark_feed_index"', $html );
		$this->assertStringContainsString( 'https://example.com/feed/', $html );
		$this->assertStringContainsString( 'name="daymark_site_title" class="daymark-input" value="Example Site"', $html );
		$this->assertStringContainsString( 'name="daymark_follow_cancel"', $html );
		$this->assertStringContainsString( esc_url( Daymark_Follow_Link::app_url( $this->url ) ), $html );
		// The picker, not the Reblog field, takes the page's focus.
		$this->assertDoesNotMatchRegularExpression( '#<textarea[^>]*autofocus#', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio"[^>]*autofocus#', $html );
	}

	public function test_follow_subscribes_to_the_chosen_feed_under_the_given_name() {
		$this->sign_in_as_admin();
		$this->pages['https://example.com/'] = $this->page_with_feed( 'Example Site', '/feed/' );

		$lookup = Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );
		$index  = array_search( 'https://example.com/feed/', wp_list_pluck( $lookup['candidates'], 'url' ), true );

		$result = Daymark_Bookmarklet::follow_action( 'follow', $this->target(), (int) $index, 'My Friend' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/feed/', $result['feed_url'] );
		$this->assertSame( 'My Friend', $result['site_title'] );
		// The lookup is used up, and the popup now says the site is followed.
		$this->assertNull( Daymark_Bookmarklet::follow_lookup( $this->url ) );
		$this->assertStringContainsString( 'You follow My Friend.', $this->render( $this->target() ) );
	}

	public function test_follow_without_a_lookup_for_this_page_is_refused() {
		$this->sign_in_as_admin();

		$result = Daymark_Bookmarklet::follow_action( 'follow', $this->target(), 0, '' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_follow_expired', $result->get_error_code() );
	}

	public function test_follow_refuses_a_lookup_the_app_replaced() {
		$this->sign_in_as_admin();
		$this->pages['https://example.com/']   = $this->page_with_feed( 'Example Site', '/feed/' );
		$this->pages['https://other.example/'] = $this->page_with_feed( 'Other', '/feed/' );
		Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );

		// The app's Follow a site sheet looks up another site since: each
		// user keeps one lookup, so index 0 now means the other site's feed.
		$request = new WP_REST_Request( 'POST', '/daymark/v1/subscriptions/discover' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'site_url', 'https://other.example/' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$result = Daymark_Bookmarklet::follow_action( 'follow', $this->target(), 0, '' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_follow_expired', $result->get_error_code() );
		$this->assertNull( ( new Daymark_Subscriptions() )->get_by_feed_url( 'https://other.example/feed/' ) );
	}

	public function test_cancel_drops_the_lookup() {
		$this->sign_in_as_admin();
		$this->pages['https://example.com/'] = $this->page_with_feed( 'Example Site', '/feed/' );
		Daymark_Bookmarklet::follow_action( 'follow_find', $this->target() );

		$this->assertTrue( Daymark_Bookmarklet::follow_action( 'follow_cancel', $this->target() ) );
		$this->assertNull( Daymark_Bookmarklet::follow_lookup( $this->url ) );
	}

	public function test_followed_subscription_matches_a_cached_post_by_its_subscription() {
		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = (int) $subscriptions->create(
			array(
				'site_url' => 'https://elsewhere.example/',
				'feed_url' => 'https://elsewhere.example/feed/',
			)
		);
		$sub_post        = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $sub_post, 'subscription_id', $subscription_id );
		update_post_meta( $sub_post, 'permalink', $this->url );

		$this->assertSame( $subscription_id, (int) Daymark_Bookmarklet::followed_subscription( $this->url )['id'] );
	}

	public function test_followed_subscription_matches_by_site_address() {
		$subscriptions = new Daymark_Subscriptions();
		$root          = (int) $subscriptions->create(
			array(
				'site_url'   => 'http://www.example.com/',
				'feed_url'   => 'https://example.com/feed/',
				'site_title' => 'Example',
			)
		);
		$blog          = (int) $subscriptions->create(
			array(
				'site_url' => 'https://example.com/blog/',
				'feed_url' => 'https://example.com/blog/feed/',
			)
		);

		// Scheme and a leading www. don't matter; the most specific site wins.
		$this->assertSame( $root, (int) Daymark_Bookmarklet::followed_subscription( $this->url )['id'] );
		$this->assertSame( $blog, (int) Daymark_Bookmarklet::followed_subscription( 'https://example.com/blog/a-post/' )['id'] );
		// A path only matches whole segments of the site's own path.
		$this->assertSame( $root, (int) Daymark_Bookmarklet::followed_subscription( 'https://example.com/blogroll/' )['id'] );
		$this->assertNull( Daymark_Bookmarklet::followed_subscription( 'https://example.org/' ) );
		$this->assertSame( 'Example', Daymark_Bookmarklet::subscription_name( Daymark_Bookmarklet::followed_subscription( $this->url ) ) );
	}

	public function test_popup_says_when_the_site_is_already_followed() {
		$this->sign_in_as_admin();
		( new Daymark_Subscriptions() )->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);

		$html = $this->render( $this->target() );

		$this->assertStringContainsString( 'You follow example.com.', $html );
		$this->assertStringNotContainsString( 'value="follow_find"', $html );
	}
}
