<?php
/**
 * Built-in Webmention tests: Daymark_Webmention (sending) and
 * Daymark_Webmention_Receiver (receiving), for two Daymark sites with no
 * Webmention plugin. All HTTP is mocked via `pre_http_request`.
 *
 * @package Daymark
 */

/**
 * Tests Daymark's built-in Webmention support.
 */
class Test_Webmention extends WP_UnitTestCase {

	/**
	 * URL => canned response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/**
	 * Requests made: each [method, url, body].
	 *
	 * @var array<int, array{0: string, 1: string, 2: mixed}>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->requests       = array();

		// tests/bootstrap.php turns the built-in support off for the suite.
		add_filter( 'daymark_builtin_webmention', '__return_true', 20 );
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
		// Test hostnames don't resolve; give them a public address (IP literals keep their own).
		add_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ), 10, 2 );
	}

	/**
	 * @param mixed  $addresses Filtered addresses.
	 * @param string $host      Host.
	 * @return mixed
	 */
	public function public_address( $addresses, $host ) {
		return false !== filter_var( $host, FILTER_VALIDATE_IP ) ? $addresses : array( '93.184.216.34' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ), 10 );
		remove_filter( 'daymark_builtin_webmention', '__return_true', 20 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args.
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		unset( $preempt );

		$this->requests[] = array( (string) ( $parsed_args['method'] ?? 'GET' ), $url, $parsed_args['body'] ?? null );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string               $url     URL.
	 * @param string               $body    Body.
	 * @param int                  $code    Status.
	 * @param array<string,string> $headers Headers.
	 * @return void
	 */
	private function mock_response( string $url, string $body, int $code = 200, array $headers = array() ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * POSTs made to a URL.
	 *
	 * @param string $url URL.
	 * @return array<int, mixed> Their bodies.
	 */
	private function posts_to( string $url ): array {
		$bodies = array();

		foreach ( $this->requests as $request ) {
			if ( 'POST' === $request[0] && $url === $request[1] ) {
				$bodies[] = $request[2];
			}
		}

		return $bodies;
	}

	/**
	 * A published local post with comments open, and its permalink.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function local_target(): array {
		$this->set_permalink_structure( '/%postname%/' );

		$post_id = self::factory()->post->create(
			array(
				'post_status'    => 'publish',
				'post_name'      => 'local-target',
				'comment_status' => 'open',
			)
		);

		return array( $post_id, (string) get_permalink( $post_id ) );
	}

	// -- Switches ---------------------------------------------------------

	/** The built-in support turns off when the Webmention plugin is active. */
	public function test_builtin_turns_off_for_the_webmention_plugin() {
		$this->assertTrue( Daymark_Webmention::builtin_active() );
		$this->assertTrue( Daymark_Webmention::can_send() );

		$filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'webmention/webmention.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $filter );
		$dir = WP_PLUGIN_DIR . '/webmention';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/webmention.php', "<?php\n/**\n * Plugin Name: Fake Webmention\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_plugins_cache( false );

		try {
			$this->assertFalse( Daymark_Webmention::builtin_active() );
			$this->assertTrue( Daymark_Webmention::can_send() );
		} finally {
			remove_filter( 'option_active_plugins', $filter );
			wp_delete_file( $dir . '/webmention.php' );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
			wp_clean_plugins_cache( false );
		}
	}

	/** same_url() ignores scheme, fragment, trailing slash, and host case. */
	public function test_same_url() {
		$this->assertTrue( Daymark_Webmention::same_url( 'https://Example.com/post/#c1', 'http://example.com/post' ) );
		$this->assertFalse( Daymark_Webmention::same_url( 'https://example.com/post/', 'https://example.com/other/' ) );
	}

	// -- Sending ----------------------------------------------------------

	/** A Like Mark notifies its target; links to this site are skipped. */
	public function test_targets_include_a_like_target_and_skip_own_links() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="https://elsewhere.example/a/">a</a> <a href="' . home_url( '/mine/' ) . '">me</a>',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_like_of', 'https://friend.example/post/' );

		$targets = Daymark_Webmention::targets_for_post( get_post( $post_id ) );

		$this->assertContains( 'https://elsewhere.example/a/', $targets );
		$this->assertContains( 'https://friend.example/post/', $targets );
		$this->assertCount( 2, $targets );
	}

	/** Sending discovers the endpoint, POSTs source and target, and records it. */
	public function test_send_for_post_delivers_and_records_state() {
		$target = 'https://friend.example/post/';

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="' . $target . '">their post</a>',
			)
		);

		$this->mock_response( $target, '<html><head></head></html>', 200, array( 'link' => '<https://friend.example/wp-json/daymark/v1/webmention>; rel="webmention"' ) );
		$this->mock_response( 'https://friend.example/wp-json/daymark/v1/webmention', '{"status":"accepted"}', 202 );

		( new Daymark_Webmention() )->send_for_post( $post_id );

		$sent = $this->posts_to( 'https://friend.example/wp-json/daymark/v1/webmention' );
		$this->assertCount( 1, $sent );
		$this->assertSame( get_permalink( $post_id ), $sent[0]['source'] );
		$this->assertSame( $target, $sent[0]['target'] );
		$this->assertSame( Daymark_Like_Delivery::STATE_SENT, Daymark_Like_Delivery::webmention_state( $post_id, $target ) );

		// Unchanged: nothing is sent again.
		( new Daymark_Webmention() )->send_for_post( $post_id );
		$this->assertCount( 1, $this->posts_to( 'https://friend.example/wp-json/daymark/v1/webmention' ) );
	}

	/** Publishing queues a send while the built-in support is on. */
	public function test_publishing_schedules_a_send() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertNotFalse( wp_next_scheduled( Daymark_Webmention::CRON_SEND, array( $post_id ) ) );
		$this->assertSame( Daymark_Like_Delivery::STATE_PENDING, Daymark_Like_Delivery::webmention_state( $post_id, 'https://x.example/' ) );
	}

	// -- Receiving --------------------------------------------------------

	/** A target that isn't a post here is refused before any request. */
	public function test_receive_refuses_a_foreign_target() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/webmention' );
		$request->set_param( 'source', 'https://friend.example/like/' );
		$request->set_param( 'target', 'https://not-this-site.example/post/' );

		$response = ( new Daymark_Webmention_Receiver() )->receive( $request );

		$this->assertWPError( $response );
		$this->assertSame( array(), $this->requests );
	}

	/** A valid Webmention is accepted with 202 and verified later. */
	public function test_receive_accepts_and_queues() {
		list( $post_id, $permalink ) = $this->local_target();

		$request = new WP_REST_Request( 'POST', '/daymark/v1/webmention' );
		$request->set_param( 'source', 'https://friend.example/like/' );
		$request->set_param( 'target', $permalink );

		$response = ( new Daymark_Webmention_Receiver() )->receive( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 202, $response->get_status() );
		$this->assertNotFalse( wp_next_scheduled( Daymark_Webmention_Receiver::CRON_VERIFY, array( 'https://friend.example/like/', $permalink, $post_id ) ) );
	}

	/** A source whose host doesn't resolve is refused, so made-up hostnames can't dodge the per-host limit. */
	public function test_receive_refuses_an_unresolvable_source() {
		list( $post_id, $permalink ) = $this->local_target();
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ), 10 );
		add_filter( 'daymark_subscription_url_guard_resolved_addresses', '__return_empty_array' );
		add_filter( 'daymark_subscription_url_guard_skip_dns_resolution', '__return_false' );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/webmention' );
		$request->set_param( 'source', 'http://made-up-host.invalid/x' );
		$request->set_param( 'target', $permalink );

		try {
			$response = ( new Daymark_Webmention_Receiver() )->receive( $request );
		} finally {
			remove_filter( 'daymark_subscription_url_guard_resolved_addresses', '__return_empty_array' );
			remove_filter( 'daymark_subscription_url_guard_skip_dns_resolution', '__return_false' );
		}

		$this->assertWPError( $response );
		$this->assertSame( 'daymark_webmention_invalid_source', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertFalse( wp_next_scheduled( Daymark_Webmention_Receiver::CRON_VERIFY, array( 'http://made-up-host.invalid/x', $permalink, $post_id ) ) );
	}

	/** Where DNS is skipped (WordPress Playground), an unresolved host is still accepted. */
	public function test_unresolved_host_is_accepted_when_dns_is_skipped() {
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ), 10 );
		add_filter( 'daymark_subscription_url_guard_skip_dns_resolution', '__return_true' );

		try {
			$this->assertTrue( Daymark_Subscription_Url_Guard::host_resolves( 'https://friend.example/like/' ) );
		} finally {
			remove_filter( 'daymark_subscription_url_guard_skip_dns_resolution', '__return_true' );
		}
	}

	/** The site-wide hourly cap refuses new verifications from any host once reached. */
	public function test_receive_enforces_the_global_limit() {
		list( $post_id, $permalink ) = $this->local_target();

		$limit = static function () {
			return 2;
		};
		add_filter( 'daymark_webmention_receive_global_limit', $limit );

		try {
			$statuses = array();

			foreach ( array( 'one', 'two', 'three' ) as $host ) {
				$request = new WP_REST_Request( 'POST', '/daymark/v1/webmention' );
				$request->set_param( 'source', 'https://' . $host . '.example/like/' );
				$request->set_param( 'target', $permalink );
				$response = ( new Daymark_Webmention_Receiver() )->receive( $request );

				$statuses[] = is_wp_error( $response ) ? $response->get_error_data()['status'] : $response->get_status();
			}
		} finally {
			remove_filter( 'daymark_webmention_receive_global_limit', $limit );
		}

		$this->assertSame( array( 202, 202, 429 ), $statuses );
		$this->assertFalse( wp_next_scheduled( Daymark_Webmention_Receiver::CRON_VERIFY, array( 'https://three.example/like/', $permalink, $post_id ) ) );
	}

	/** A verified Like from another Daymark site becomes a like comment. */
	public function test_verify_stores_a_like() {
		list( $post_id, $permalink ) = $this->local_target();

		$source = 'https://friend.example/?daymark_like=liked';
		$this->mock_response(
			$source,
			'<article><div class="e-content">Liked "Local"</div><div style="display:none"><a class="u-like-of" href="' . esc_url( $permalink ) . '">x</a><a class="p-author h-card" href="https://friend.example/"><span class="p-name">Friend</span></a></div></article>'
		);

		( new Daymark_Webmention_Receiver() )->verify( $source, $permalink, $post_id );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'like',
			)
		);

		$this->assertCount( 1, $comments );
		$this->assertSame( 'Friend', $comments[0]->comment_author );
		$this->assertSame( 'webmention', get_comment_meta( (int) $comments[0]->comment_ID, 'protocol', true ) );
		$this->assertSame( $source, get_comment_meta( (int) $comments[0]->comment_ID, 'webmention_source_url', true ) );
	}

	/** A reply keeps its text; a second verify updates rather than duplicates. */
	public function test_verify_stores_and_updates_a_reply() {
		list( $post_id, $permalink ) = $this->local_target();

		$source = 'https://friend.example/reply/';
		$html   = '<div class="h-entry"><div class="e-content"><p>Great point!</p></div><a class="u-in-reply-to" href="' . esc_url( $permalink ) . '">x</a></div>';
		$this->mock_response( $source, $html );

		$receiver = new Daymark_Webmention_Receiver();
		$receiver->verify( $source, $permalink, $post_id );

		$this->mock_response( $source, str_replace( 'Great point!', 'Great point, edited.', $html ) );
		$receiver->verify( $source, $permalink, $post_id );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'all',
			)
		);

		$this->assertCount( 1, $comments );
		$this->assertSame( 'comment', $comments[0]->comment_type );
		$this->assertSame( 'Great point, edited.', $comments[0]->comment_content );
	}

	/** A source that stops linking, or is gone, removes its comment. */
	public function test_verify_removes_when_the_link_is_gone() {
		list( $post_id, $permalink ) = $this->local_target();

		$source = 'https://friend.example/repost/';
		$this->mock_response( $source, '<a class="u-repost-of" href="' . esc_url( $permalink ) . '">x</a>' );

		$receiver = new Daymark_Webmention_Receiver();
		$receiver->verify( $source, $permalink, $post_id );
		$this->assertSame( 1, (int) get_comments( array( 'post_id' => $post_id, 'type' => 'repost', 'count' => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test lookup.

		$this->mock_response( $source, '', 410 );
		$receiver->verify( $source, $permalink, $post_id );

		$this->assertSame( 0, (int) get_comments( array( 'post_id' => $post_id, 'type' => 'repost', 'count' => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test lookup.
	}

	/** A page that never linked to the target stores nothing. */
	public function test_verify_ignores_a_source_without_the_link() {
		list( $post_id, $permalink ) = $this->local_target();

		$source = 'https://friend.example/unrelated/';
		$this->mock_response( $source, '<p>Nothing to see.</p>' );

		( new Daymark_Webmention_Receiver() )->verify( $source, $permalink, $post_id );

		$this->assertSame( 0, (int) get_comments( array( 'post_id' => $post_id, 'status' => 'all', 'count' => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test lookup.
	}

	/** Entity-encoded markup in a source page is stored as plain text, never as HTML. */
	public function test_verify_strips_entity_encoded_markup() {
		list( $post_id, $permalink ) = $this->local_target();

		$source = 'https://friend.example/xss/';
		$this->mock_response(
			$source,
			'<div class="h-entry"><div class="e-content"><p>Hi &lt;img src=x onerror=alert(1)&gt; &lt;script&gt;alert(2)&lt;/script&gt;there</p></div>'
			. '<a class="p-author h-card" href="https://friend.example/"><span class="p-name">Eve &lt;img src=x onerror=alert(3)&gt;</span></a>'
			. '<a class="u-in-reply-to" href="' . esc_url( $permalink ) . '">x</a></div>'
		);

		( new Daymark_Webmention_Receiver() )->verify( $source, $permalink, $post_id );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'all',
			)
		);

		$this->assertCount( 1, $comments );
		$this->assertStringNotContainsString( '<', $comments[0]->comment_content );
		$this->assertStringNotContainsString( '<', $comments[0]->comment_author );
		$this->assertStringContainsString( 'Hi', $comments[0]->comment_content );
		$this->assertStringContainsString( 'Eve', $comments[0]->comment_author );
	}

	/** A like's placeholder text, built from the author, carries no markup either. */
	public function test_verify_like_placeholder_has_no_markup() {
		list( $post_id, $permalink ) = $this->local_target();

		$source = 'https://friend.example/xss-like/';
		$this->mock_response(
			$source,
			'<a class="u-like-of" href="' . esc_url( $permalink ) . '">x</a><a class="p-author h-card" href="https://friend.example/"><span class="p-name">&lt;script&gt;alert(1)&lt;/script&gt;Mallory</span></a>'
		);

		( new Daymark_Webmention_Receiver() )->verify( $source, $permalink, $post_id );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'like',
				'status'  => 'all',
			)
		);

		$this->assertCount( 1, $comments );
		$this->assertStringNotContainsString( '<', $comments[0]->comment_content );
		$this->assertStringNotContainsString( '<', $comments[0]->comment_author );
		$this->assertStringContainsString( 'Mallory', $comments[0]->comment_author );
	}
}
