<?php
/**
 * Tests for web push: Daymark_Push (keys, device subscriptions,
 * preferences, the comment trigger), Daymark_Push_Sender (encrypted
 * sending through the outbound guard), and the /push/* REST routes.
 *
 * @package Daymark
 */

/**
 * Exercises push storage, triggers, sending, and routes.
 */
class Test_Push extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<int, array{url: string, args: array}> Requests a test intercepted. */
	private $requests = array();

	/** @var int Status code the fake push service answers with. */
	private $status = 201;

	public function set_up(): void {
		parent::set_up();

		$this->user_id  = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->requests = array();
		$this->status   = 201;
		wp_set_current_user( $this->user_id );

		// Push hosts resolve to a public address, so the URL guard passes
		// without real DNS.
		add_filter(
			'daymark_subscription_url_guard_resolved_addresses',
			static function () {
				return array( '17.188.0.1' );
			}
		);
		add_filter( 'pre_http_request', array( $this, 'fake_push_service' ), 10, 3 );
		_set_cron_array( array() );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_push_service' ), 10 );
		parent::tear_down();
	}

	/**
	 * Answer every outbound request like a push service would.
	 *
	 * @param false|array $preempt Preempted response.
	 * @param array       $args    Request arguments.
	 * @param string      $url     URL.
	 * @return array
	 */
	public function fake_push_service( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $this->status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * A valid browser subscription with real P-256 keys.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return array<string, mixed>
	 */
	private function browser_subscription( string $endpoint = 'https://web.push.apple.com/QGuQyavXutnMH6' ): array {
		$key     = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		$details = openssl_pkey_get_details( $key );
		$point   = "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
		$b64     = static function ( string $bytes ): string {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding test push keys.
			return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
		};

		return array(
			'endpoint' => $endpoint,
			'keys'     => array(
				'p256dh' => $b64( $point ),
				'auth'   => $b64( random_bytes( 16 ) ),
			),
		);
	}

	private function rest( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/daymark/v1' . $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	private function mark( int $author ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_title'  => 'Sunset over the bay',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		return $post_id;
	}

	private function scheduled_pushes(): array {
		$events = array();

		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Daymark_Push::CRON_HOOK ] ?? array() ) as $event ) {
				$events[] = $event['args'];
			}
		}

		return $events;
	}

	public function test_only_real_push_services_are_allowed() {
		$this->assertTrue( Daymark_Push::is_allowed_endpoint( 'https://fcm.googleapis.com/fcm/send/abc' ) );
		$this->assertTrue( Daymark_Push::is_allowed_endpoint( 'https://web.push.apple.com/abc' ) );
		$this->assertTrue( Daymark_Push::is_allowed_endpoint( 'https://updates.push.services.mozilla.com/wpush/v2/abc' ) );
		$this->assertTrue( Daymark_Push::is_allowed_endpoint( 'https://wns2-by3p.notify.windows.com/w/?token=abc' ) );

		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'http://fcm.googleapis.com/fcm/send/abc' ) );
		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'https://fcm.googleapis.com:8443/fcm/send/abc' ) );
		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'https://user:pass@fcm.googleapis.com/abc' ) );
		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'https://fcm.googleapis.com.evil.test/abc' ) );
		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'https://evilfcm.googleapis.com/abc' ) );
		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'https://127.0.0.1/abc' ) );
		$this->assertFalse( Daymark_Push::is_allowed_endpoint( 'https://example.com/' . str_repeat( 'a', 2000 ) ) );
	}

	public function test_subscription_keys_are_checked() {
		$good = $this->browser_subscription();

		$this->assertIsArray( Daymark_Push::normalize_subscription( $good ) );

		$short_key                   = $good;
		$short_key['keys']['p256dh'] = 'AAAA';
		$this->assertWPError( Daymark_Push::normalize_subscription( $short_key ) );

		$bad_auth                 = $good;
		$bad_auth['keys']['auth'] = 'not base64!';
		$this->assertWPError( Daymark_Push::normalize_subscription( $bad_auth ) );

		$elsewhere             = $good;
		$elsewhere['endpoint'] = 'https://internal.example.test/push';
		$this->assertSame( 'daymark_push_endpoint', Daymark_Push::normalize_subscription( $elsewhere )->get_error_code() );
	}

	public function test_saving_the_same_device_twice_keeps_one_copy() {
		$sub = $this->browser_subscription();

		Daymark_Push::save_subscription( $this->user_id, $sub );
		Daymark_Push::save_subscription( $this->user_id, $sub );

		$this->assertCount( 1, Daymark_Push::get_subscriptions( $this->user_id ) );
	}

	public function test_a_device_moves_to_whoever_saved_it_last() {
		$other = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$sub   = $this->browser_subscription();

		Daymark_Push::save_subscription( $other, $sub );
		Daymark_Push::save_subscription( $this->user_id, $sub );

		$this->assertCount( 0, Daymark_Push::get_subscriptions( $other ) );
		$this->assertCount( 1, Daymark_Push::get_subscriptions( $this->user_id ) );
	}

	public function test_oldest_device_is_dropped_past_the_limit() {
		for ( $i = 0; $i < Daymark_Push::MAX_SUBSCRIPTIONS + 1; $i++ ) {
			Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription( 'https://fcm.googleapis.com/fcm/send/device-' . $i ) );
		}

		$endpoints = wp_list_pluck( Daymark_Push::get_subscriptions( $this->user_id ), 'endpoint' );

		$this->assertCount( Daymark_Push::MAX_SUBSCRIPTIONS, $endpoints );
		$this->assertContains( 'https://fcm.googleapis.com/fcm/send/device-' . Daymark_Push::MAX_SUBSCRIPTIONS, $endpoints );
	}

	public function test_preferences_default_on_and_ignore_unknown_keys() {
		$this->assertTrue( Daymark_Push::get_prefs( $this->user_id )['comments'] );

		$prefs = Daymark_Push::update_prefs(
			$this->user_id,
			array(
				'comments' => false,
				'unknown'  => true,
			)
		);

		$this->assertFalse( $prefs['comments'] );
		$this->assertArrayNotHasKey( 'unknown', $prefs );
	}

	public function test_keys_are_made_once_and_kept() {
		delete_option( Daymark_Push::KEYS_OPTION );

		$first  = Daymark_Push::keys();
		$second = Daymark_Push::keys();

		$this->assertNotEmpty( $first['publicKey'] );
		$this->assertSame( $first, $second );
		$this->assertSame( $first['publicKey'], Daymark_Push::public_key() );
	}

	public function test_a_reply_on_your_mark_queues_a_push() {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$post_id = $this->mark( $this->user_id );

		$comment_id = (int) self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Ana',
				'comment_content' => 'Gorgeous light!',
			)
		);

		$pushes = $this->scheduled_pushes();

		$this->assertCount( 1, $pushes );
		$this->assertSame( $this->user_id, $pushes[0][0] );
		$this->assertSame( 'New comment on Sunset over the bay', $pushes[0][1]['title'] );
		$this->assertSame( 'Ana: Gorgeous light!', $pushes[0][1]['body'] );
		$this->assertSame( Daymark_Routes::app_url( 'notifications' ), $pushes[0][1]['url'] );
		$this->assertSame( '1', get_comment_meta( $comment_id, Daymark_Push::COMMENT_SENT_META, true ) );

		// Approving it again doesn't push twice.
		wp_set_comment_status( $comment_id, 'hold' );
		wp_set_comment_status( $comment_id, 'approve' );
		$this->assertCount( 1, $this->scheduled_pushes() );
	}

	public function test_a_reply_to_your_comment_on_a_followed_post_says_so() {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );

		$sub_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'An origin post',
			)
		);
		update_post_meta( $sub_id, 'permalink', 'https://origin.example/post/' );

		$followed = $this->mark( $this->user_id );
		update_post_meta( $followed, '_daymark_in_reply_to', 'https://origin.example/post/' );
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $followed,
				'comment_author'  => 'Ana',
				'comment_content' => 'Thanks!',
			)
		);

		$elsewhere = $this->mark( $this->user_id );
		update_post_meta( $elsewhere, '_daymark_in_reply_to', 'https://www.elsewhere.example/a-post/' );
		self::factory()->comment->create( array( 'comment_post_ID' => $elsewhere ) );

		$titles = wp_list_pluck( wp_list_pluck( $this->scheduled_pushes(), 1 ), 'title' );

		$this->assertSame(
			array( 'New reply to your comment on An origin post', 'New reply to your comment on elsewhere.example' ),
			$titles
		);
	}

	public function test_a_held_reply_pushes_once_approved() {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$post_id = $this->mark( $this->user_id );

		$comment_id = (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '0',
			)
		);
		$this->assertCount( 0, $this->scheduled_pushes() );

		wp_set_comment_status( $comment_id, 'approve' );
		$this->assertCount( 1, $this->scheduled_pushes() );
	}

	public function test_no_push_for_other_comments() {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$post_id = $this->mark( $this->user_id );

		// Your own reply.
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'user_id'         => $this->user_id,
			)
		);
		// A like stored as a comment.
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'like',
			)
		);
		// A reply on an ordinary post, not a Mark.
		self::factory()->comment->create(
			array(
				'comment_post_ID' => self::factory()->post->create( array( 'post_author' => $this->user_id ) ),
			)
		);

		$this->assertCount( 0, $this->scheduled_pushes() );
	}

	public function test_no_push_without_devices_or_with_comments_off() {
		$post_id = $this->mark( $this->user_id );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$this->assertCount( 0, $this->scheduled_pushes() );

		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		Daymark_Push::update_prefs( $this->user_id, array( 'comments' => false ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$this->assertCount( 0, $this->scheduled_pushes() );
	}

	public function test_notify_everyone_reaches_writers_with_devices() {
		$subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$no_device  = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		unset( $no_device );
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		Daymark_Push::save_subscription( $subscriber, $this->browser_subscription( 'https://fcm.googleapis.com/fcm/send/reader' ) );

		$this->assertSame( 1, Daymark_Push::notify_everyone( 'comments', array( 'title' => 'Hi' ) ) );
		$this->assertSame( $this->user_id, $this->scheduled_pushes()[0][0] );
	}

	public function test_message_url_stays_on_this_site() {
		$message = Daymark_Push::sanitize_message(
			array(
				'title' => '<b>Hello</b>',
				'url'   => 'https://evil.example/phish',
				'tag'   => 'Comment 12!',
			)
		);

		$this->assertSame( 'Hello', $message['title'] );
		$this->assertSame( Daymark_Routes::app_url(), $message['url'] );
		$this->assertSame( 'comment12', $message['tag'] );
	}

	public function test_send_encrypts_and_signs_for_each_device() {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );

		$result = Daymark_Push_Sender::send_to_user(
			$this->user_id,
			array(
				// Long enough that random ciphertext can't contain it by chance.
				'title' => 'Plain text marker 7f3a9c',
				'tag'   => 'comment-1',
			)
		);

		$this->assertSame(
			array(
				'sent'    => 1,
				'failed'  => 0,
				'removed' => 0,
			),
			$result
		);
		$this->assertCount( 1, $this->requests );

		$request = $this->requests[0];
		$headers = array_change_key_case( $request['args']['headers'], CASE_LOWER );

		$this->assertSame( 'https://web.push.apple.com/QGuQyavXutnMH6', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 'aes128gcm', $headers['content-encoding'] );
		$this->assertSame( (string) DAY_IN_SECONDS, $headers['ttl'] );
		$this->assertSame( Daymark_Push_Sender::topic( 'comment-1' ), $headers['topic'] );
		$this->assertStringStartsWith( 'vapid t=', $headers['authorization'] );
		$this->assertArrayNotHasKey( 'host', $headers );
		$this->assertGreaterThan( 100, strlen( $request['args']['body'] ) );
		$this->assertStringNotContainsString( 'Plain text marker 7f3a9c', $request['args']['body'] );

		// The token is for Apple's push service.
		$jwt = explode( '.', substr( strtok( $headers['authorization'], ',' ), strlen( 'vapid t=' ) ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reading the test JWT's claims.
		$claims = json_decode( base64_decode( strtr( $jwt[1], '-_', '+/' ) ), true );
		$this->assertSame( 'https://web.push.apple.com', $claims['aud'] );
	}

	public function test_a_gone_device_is_removed() {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$this->status = 410;

		$result = Daymark_Push_Sender::send_to_user( $this->user_id, array( 'title' => 'Hi' ) );

		$this->assertSame( 1, $result['removed'] );
		$this->assertCount( 0, Daymark_Push::get_subscriptions( $this->user_id ) );
	}

	public function test_topic_fits_push_service_limits() {
		$topic = Daymark_Push_Sender::topic( 'comment-123456789' );

		$this->assertSame( 32, strlen( $topic ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $topic );
	}

	public function test_rest_saves_and_removes_a_device() {
		$sub = $this->browser_subscription();

		$saved = $this->rest( 'POST', '/push/subscriptions', array( 'subscription' => $sub ) );
		$this->assertSame( 201, $saved->get_status() );
		$this->assertSame( 1, $saved->get_data()['devices'] );

		$bad = $this->rest( 'POST', '/push/subscriptions', array( 'subscription' => array( 'endpoint' => 'https://example.test/x' ) ) );
		$this->assertSame( 400, $bad->get_status() );

		$request = new WP_REST_Request( 'DELETE', '/daymark/v1/push/subscriptions' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'endpoint', $sub['endpoint'] );
		$removed = rest_do_request( $request );

		$this->assertSame( 0, $removed->get_data()['devices'] );
	}

	public function test_rest_preferences_and_test_push() {
		$prefs = $this->rest( 'POST', '/push/preferences', array( 'comments' => false ) );
		$this->assertFalse( $prefs->get_data()['prefs']['comments'] );

		$no_devices = $this->rest( 'POST', '/push/test' );
		$this->assertSame( 400, $no_devices->get_status() );

		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sent = $this->rest( 'POST', '/push/test' );
		$this->assertSame( 1, $sent->get_data()['sent'] );
	}

	public function test_rest_requires_a_nonce() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/push/subscriptions' );
		$request->set_param( 'subscription', $this->browser_subscription() );

		$this->assertSame( rest_authorization_required_code(), rest_do_request( $request )->get_status() );
	}
}
