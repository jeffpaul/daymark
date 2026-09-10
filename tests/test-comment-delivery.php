<?php
/**
 * Daymark_Comment_Delivery tests (issue #317): Webmention-preferred delivery
 * (a minimal Mark, same mechanism the old composer-based Reply action used),
 * falling back to a native wp/v2/comments POST when Webmention isn't usable
 * on both ends.
 *
 * All HTTP is mocked via `pre_http_request`, matching the existing pattern
 * in tests/test-subscription-poller.php.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Comment_Delivery::deliver().
 */
class Test_Comment_Delivery extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()/wp_remote_post()-shaped response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test Author',
				'user_email'   => 'author@example.com',
			)
		);
		wp_set_current_user( $user_id );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		unset( $parsed_args );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string               $url     URL to mock.
	 * @param string               $body    Response body.
	 * @param int                  $code    HTTP status code.
	 * @param array<string,string> $headers Extra response headers (e.g. 'link').
	 * @return void
	 */
	private function mock_response( string $url, string $body, int $code = 200, array $headers = array() ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @param string $permalink Origin permalink.
	 * @return int Subscription post ID.
	 */
	private function create_subscription_post( string $permalink ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'An origin post',
			)
		);

		update_post_meta( $post_id, 'permalink', $permalink );

		return $post_id;
	}

	/** Empty text never reaches any lookup. */
	public function test_deliver_rejects_empty_text(): void {
		$post_id = $this->create_subscription_post( 'https://origin.example/post/' );

		$result = Daymark_Comment_Delivery::deliver( $post_id, '   ' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_comment_empty', $result->get_error_code() );
	}

	/** A nonexistent post, or one that isn't a daymark_sub_post, 404s. */
	public function test_deliver_rejects_non_subscription_post(): void {
		$mark_id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );

		$result = Daymark_Comment_Delivery::deliver( $mark_id, 'A comment.' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_not_found', $result->get_error_code() );
	}

	/** A URL the SSRF guard rejects never reaches any HTTP request. */
	public function test_deliver_rejects_unsafe_permalink(): void {
		$post_id = $this->create_subscription_post( 'https://internal.example/post/' );

		$filter = static function () {
			return array( '10.0.0.5' );
		};
		add_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$result = Daymark_Comment_Delivery::deliver( $post_id, 'A comment.' );

		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_comment_unsafe_url', $result->get_error_code() );
	}

	/**
	 * Webmention branch: origin advertises a webmention endpoint AND the
	 * local Webmention plugin is active (simulated via the same
	 * option_active_plugins filter Test_Admin_Subscriptions/
	 * Test_Plugin_Detector already use) -> a minimal Note Mark is published
	 * with the comment text as its caption and the permalink as
	 * _daymark_in_reply_to, no direct outbound POST to the origin at all.
	 */
	public function test_deliver_prefers_webmention_when_available_on_both_ends(): void {
		$permalink = 'https://origin.example/webmention-post/';
		$post_id   = $this->create_subscription_post( $permalink );

		$this->mock_response(
			$permalink,
			'<html><head><link rel="webmention" href="https://origin.example/webmention"></head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);

		$filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'webmention/webmention.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $filter );
		$dir = WP_PLUGIN_DIR . '/webmention';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/webmention.php', "<?php\n/**\n * Plugin Name: Fake Webmention\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}

		$result = Daymark_Comment_Delivery::deliver( $post_id, 'Great point!' );

		remove_filter( 'option_active_plugins', $filter );
		wp_delete_file( $dir . '/webmention.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		rmdir( $dir );

		$this->assertIsArray( $result );
		$this->assertSame( 'webmention', $result['method'] );
		$this->assertSame( 'published', $result['status'] );
		$this->assertGreaterThan( 0, $result['mark_id'] );

		$mark_id = (int) $result['mark_id'];
		$this->assertSame( $permalink, get_post_meta( $mark_id, '_daymark_in_reply_to', true ) );
		$this->assertStringContainsString( 'Great point!', (string) get_post_field( 'post_content', $mark_id ) );
	}

	/**
	 * Native fallback: no local Webmention plugin active, but the origin
	 * exposes a discoverable REST API and post ID -> a comment is POSTed
	 * directly to wp/v2/comments, no local Mark created at all.
	 */
	public function test_deliver_falls_back_to_native_comment_without_webmention_plugin(): void {
		$permalink = 'https://origin.example/native-post/';
		$post_id   = $this->create_subscription_post( $permalink );

		$this->mock_response(
			$permalink,
			'<html><head><link rel="https://api.w.org/" href="https://origin.example/wp-json/"><link rel="alternate" type="application/json" href="https://origin.example/wp-json/wp/v2/posts/55"></head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);

		$this->mock_response(
			'https://origin.example/wp-json/wp/v2/comments',
			wp_json_encode(
				array(
					'id'     => 9,
					'status' => 'approved',
				)
			),
			201
		);

		$counts_before = wp_count_posts( 'post' );

		$result = Daymark_Comment_Delivery::deliver( $post_id, 'Nice write-up.' );

		$this->assertIsArray( $result );
		$this->assertSame( 'native', $result['method'] );
		$this->assertSame( 'published', $result['status'] );

		$counts_after = wp_count_posts( 'post' );
		$this->assertSame(
			(int) $counts_before->publish + (int) $counts_before->draft,
			(int) $counts_after->publish + (int) $counts_after->draft
		);
	}

	/** A 'hold' status in the native response surfaces as the 'held' status. */
	public function test_deliver_native_comment_can_be_held_for_moderation(): void {
		$permalink = 'https://origin.example/held-post/';
		$post_id   = $this->create_subscription_post( $permalink );

		$this->mock_response(
			$permalink,
			'<html><head><link rel="https://api.w.org/" href="https://origin.example/wp-json/"><link rel="alternate" type="application/json" href="https://origin.example/wp-json/wp/v2/posts/56"></head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);

		$this->mock_response(
			'https://origin.example/wp-json/wp/v2/comments',
			wp_json_encode(
				array(
					'id'     => 10,
					'status' => 'hold',
				)
			),
			201
		);

		$result = Daymark_Comment_Delivery::deliver( $post_id, 'Awaiting review.' );

		$this->assertIsArray( $result );
		$this->assertSame( 'held', $result['status'] );
	}

	/** A rejected native comment surfaces the origin's own error message. */
	public function test_deliver_native_comment_rejection_surfaces_message(): void {
		$permalink = 'https://origin.example/closed-post/';
		$post_id   = $this->create_subscription_post( $permalink );

		$this->mock_response(
			$permalink,
			'<html><head><link rel="https://api.w.org/" href="https://origin.example/wp-json/"><link rel="alternate" type="application/json" href="https://origin.example/wp-json/wp/v2/posts/57"></head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);

		$this->mock_response(
			'https://origin.example/wp-json/wp/v2/comments',
			wp_json_encode(
				array(
					'code'    => 'rest_comment_closed',
					'message' => 'Comments are closed.',
				)
			),
			403
		);

		$result = Daymark_Comment_Delivery::deliver( $post_id, 'Too late.' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_comment_rejected', $result->get_error_code() );
		$this->assertSame( 'Comments are closed.', $result->get_error_message() );
	}

	/** Neither a Webmention endpoint nor a discoverable REST post -> undeliverable. */
	public function test_deliver_returns_error_when_nothing_is_discoverable(): void {
		$permalink = 'https://origin.example/plain-post/';
		$post_id   = $this->create_subscription_post( $permalink );

		$this->mock_response(
			$permalink,
			'<html><head><title>Just a plain page</title></head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);

		$result = Daymark_Comment_Delivery::deliver( $post_id, 'Anyone home?' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_comment_undeliverable', $result->get_error_code() );
	}
}
