<?php
/**
 * Tests for Daymark_Followers: follow announcements (follow pages and
 * their Webmentions), receiving follow Webmentions, fediverse followers
 * through the ActivityPub plugin's hook, Jetpack subscribers, and the push
 * each new follower sends.
 *
 * @package Daymark
 */

/**
 * Exercises new followers end to end.
 */
class Test_Followers extends WP_UnitTestCase {

	/** @var array<string, array> */
	private array $http_responses = array();

	/** @var array<int, array{0: string, 1: string, 2: mixed}> */
	private array $requests = array();

	/** @var int */
	private $admin_id;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		$this->subscriptions  = Daymark_Plugin::instance()->subscriptions;
		$this->http_responses = array();
		$this->requests       = array();
		$this->admin_id       = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		_set_cron_array( array() );

		// tests/bootstrap.php turns the built-in Webmention support off.
		add_filter( 'daymark_builtin_webmention', '__return_true', 20 );
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );
		remove_filter( 'daymark_builtin_webmention', '__return_true', 20 );
		remove_all_filters( 'daymark_announce_follows' );
		parent::tear_down();
	}

	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		unset( $preempt );
		$this->requests[] = array( (string) ( $parsed_args['method'] ?? 'GET' ), $url, $parsed_args['body'] ?? null );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	private function mock_response( string $url, string $body, int $code = 200, array $headers = array() ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function follow( string $feed_url = 'https://ana.example/feed/' ): int {
		return (int) $this->subscriptions->create(
			array(
				'site_url' => 'https://ana.example/blog/',
				'feed_url' => $feed_url,
			)
		);
	}

	private function give_admin_a_device(): void {
		update_user_meta( $this->admin_id, Daymark_Push::SUBSCRIPTION_META, wp_json_encode( array( 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc' ), JSON_UNESCAPED_SLASHES ) );
	}

	private function scheduled( string $hook ): array {
		$events = array();

		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
				$events[] = $event['args'];
			}
		}

		return $events;
	}

	public function test_site_root_normalizes_to_scheme_and_host() {
		$this->assertSame( 'https://ana.example/', Daymark_Followers::site_root( 'HTTPS://Ana.Example/blog/?x=1' ) );
		$this->assertSame( '', Daymark_Followers::site_root( 'ftp://ana.example/' ) );
		$this->assertSame( '', Daymark_Followers::site_root( 'not a url' ) );
	}

	public function test_following_a_site_publishes_one_follow_page_and_queues_it() {
		$this->follow();
		$this->follow( 'https://ana.example/comments/feed/' );

		$pages = Daymark_Followers::pages();
		$this->assertCount( 1, $pages );
		$this->assertSame( 'https://ana.example/', array_values( $pages )[0]['target'] );
		$this->assertCount( 1, get_option( Daymark_Followers::QUEUE_OPTION ) );
		$this->assertCount( 1, $this->scheduled( Daymark_Followers::CRON_SEND ) );
	}

	public function test_nothing_is_announced_when_the_setting_is_off() {
		add_filter( 'daymark_announce_follows', '__return_false' );

		$this->follow();

		$this->assertSame( array(), Daymark_Followers::pages() );
	}

	public function test_queued_announcement_sends_a_webmention_from_the_follow_page() {
		$this->follow();
		$token = (string) array_key_first( Daymark_Followers::pages() );

		$this->mock_response( 'https://ana.example/', '<html><head></head></html>', 200, array( 'link' => '<https://ana.example/wm>; rel="webmention"' ) );
		$this->mock_response( 'https://ana.example/wm', '', 202 );

		$this->assertSame( 1, Daymark_Followers::send_queued() );

		$posts = array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return 'POST' === $request[0];
				}
			)
		);
		$this->assertCount( 1, $posts );
		$this->assertSame( 'https://ana.example/wm', $posts[0][1] );
		$this->assertSame( Daymark_Followers::page_url( $token ), $posts[0][2]['source'] );
		$this->assertSame( 'https://ana.example/', $posts[0][2]['target'] );
		$this->assertSame( array(), get_option( Daymark_Followers::QUEUE_OPTION ) );
	}

	public function test_follow_page_marks_up_the_follow_and_an_unknown_one_is_gone() {
		$this->follow();
		$token = (string) array_key_first( Daymark_Followers::pages() );

		$html = Daymark_Followers::follow_page_html( $token );
		$this->assertStringContainsString( 'class="u-follow-of" href="https://ana.example/"', $html );
		$this->assertStringContainsString( 'class="p-author h-card"', $html );
		$this->assertStringContainsString( 'noindex', $html );

		$this->assertStringContainsString( 'Gone', Daymark_Followers::follow_page_html( 'nope' ) );
	}

	public function test_unfollowing_the_last_feed_removes_the_page_and_tells_the_site() {
		$first  = $this->follow();
		$second = $this->follow( 'https://ana.example/comments/feed/' );
		update_option( Daymark_Followers::QUEUE_OPTION, array() );

		$this->subscriptions->unsubscribe( $first );
		$this->assertCount( 1, Daymark_Followers::pages() );
		$this->assertSame( array(), get_option( Daymark_Followers::QUEUE_OPTION ) );

		$this->subscriptions->unsubscribe( $second );
		$this->assertSame( array(), Daymark_Followers::pages() );
		$this->assertCount( 1, get_option( Daymark_Followers::QUEUE_OPTION ) );
	}

	public function test_a_follow_webmention_to_the_home_page_is_accepted() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/webmention' );
		$request->set_param( 'source', 'https://bo.example/?daymark_follow=abc' );
		$request->set_param( 'target', home_url( '/' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array( array( 'https://bo.example/?daymark_follow=abc' ) ), $this->scheduled( Daymark_Followers::CRON_VERIFY ) );
	}

	public function test_a_verified_follow_records_the_follower_and_pushes() {
		$this->give_admin_a_device();
		$source = 'https://bo.example/?daymark_follow=abc';
		$this->mock_response(
			$source,
			'<div class="h-entry"><a class="p-author h-card" href="https://bo.example/">Bo’s Notes</a> follows <a class="u-follow-of" href="' . esc_url( home_url( '/' ) ) . '">us</a></div>'
		);

		Daymark_Followers::verify( $source );

		$followers = array_values( Daymark_Followers::all() );
		$this->assertCount( 1, $followers );
		$this->assertSame( 'Bo’s Notes', $followers[0]['name'] );
		$this->assertSame( 'https://bo.example/', $followers[0]['url'] );

		$pushes = $this->scheduled( Daymark_Push::CRON_HOOK );
		$this->assertCount( 1, $pushes );
		$this->assertSame( 'New follower', $pushes[0][1]['title'] );
		$this->assertSame( 'Bo’s Notes follows your site.', $pushes[0][1]['body'] );

		// The same follow again doesn't push twice.
		Daymark_Followers::verify( $source );
		$this->assertCount( 1, $this->scheduled( Daymark_Push::CRON_HOOK ) );
	}

	public function test_an_unfollow_or_a_page_without_the_follow_removes_it() {
		$source = 'https://bo.example/?daymark_follow=abc';
		Daymark_Followers::add( 'webmention', $source, 'Bo', 'https://bo.example/', false );

		$this->mock_response( $source, '<p>Just a page linking <a href="' . esc_url( home_url( '/' ) ) . '">here</a></p>' );
		Daymark_Followers::verify( $source );
		$this->assertSame( array(), Daymark_Followers::all() );

		Daymark_Followers::add( 'webmention', $source, 'Bo', 'https://bo.example/', false );
		$this->mock_response( $source, 'Gone', 410 );
		Daymark_Followers::verify( $source );
		$this->assertSame( array(), Daymark_Followers::all() );
	}

	public function test_a_fediverse_follow_pushes_once() {
		$this->give_admin_a_device();

		do_action( 'activitypub_handled_follow', array( 'actor' => 'https://mastodon.example/users/ana' ), array( 0 ), true, null );
		do_action( 'activitypub_handled_follow', array( 'actor' => 'https://mastodon.example/users/ana' ), array( 0 ), false, null );

		$followers = array_values( Daymark_Followers::all() );
		$this->assertSame( '@ana@mastodon.example', $followers[0]['name'] );

		$pushes = $this->scheduled( Daymark_Push::CRON_HOOK );
		$this->assertCount( 1, $pushes );
		$this->assertSame( '@ana@mastodon.example followed you on the fediverse.', $pushes[0][1]['body'] );
	}

	public function test_fediverse_follow_uses_the_actor_name_when_known() {
		$actor = self::factory()->post->create_and_get( array( 'post_title' => 'Ana Lima' ) );

		Daymark_Followers::on_activitypub_follow( array( 'actor' => 'https://mastodon.example/users/ana' ), array( 0 ), true, $actor );

		$this->assertSame( 'Ana Lima', array_values( Daymark_Followers::all() )[0]['name'] );
	}

	public function test_jetpack_subscribers_push_only_after_the_first_check() {
		$this->give_admin_a_device();
		$older = gmdate( 'c', time() - DAY_IN_SECONDS );

		// First check: note where the list stands, push nothing.
		$this->assertSame( 0, Daymark_Followers::record_jetpack_subscribers( array( 'subscribers' => array( array( 'ID' => 1, 'label' => 'Old', 'date_subscribed' => $older ) ) ) ) );
		$this->assertCount( 0, $this->scheduled( Daymark_Push::CRON_HOOK ) );

		// One new subscriber.
		$new = array(
			'ID'              => 2,
			'label'           => 'Cy',
			'date_subscribed' => gmdate( 'c', time() + 60 ),
		);
		$this->assertSame( 1, Daymark_Followers::record_jetpack_subscribers( array( 'subscribers' => array( $new ) ) ) );
		$pushes = $this->scheduled( Daymark_Push::CRON_HOOK );
		$this->assertCount( 1, $pushes );
		$this->assertSame( 'Cy subscribed to your site.', $pushes[0][1]['body'] );

		// Already seen: nothing new.
		$this->assertSame( 0, Daymark_Followers::record_jetpack_subscribers( array( 'subscribers' => array( $new ) ) ) );
	}

	public function test_several_jetpack_subscribers_become_one_push() {
		$this->give_admin_a_device();
		Daymark_Followers::record_jetpack_subscribers( array() );
		_set_cron_array( array() );

		$later = gmdate( 'c', time() + 120 );
		Daymark_Followers::record_jetpack_subscribers(
			array(
				'subscribers' => array(
					array(
						'ID'              => 3,
						'label'           => 'Di',
						'date_subscribed' => $later,
					),
					array(
						'ID'              => 4,
						'label'           => 'Ed',
						'date_subscribed' => $later,
					),
				),
			)
		);

		$pushes = $this->scheduled( Daymark_Push::CRON_HOOK );
		$this->assertCount( 1, $pushes );
		$this->assertSame( '2 people subscribed to your site.', $pushes[0][1]['body'] );
		$this->assertCount( 2, Daymark_Followers::all() );
	}

	public function test_no_push_when_followers_are_turned_off() {
		$this->give_admin_a_device();
		Daymark_Push::update_prefs( $this->admin_id, array( 'followers' => false ) );

		Daymark_Followers::add( 'activitypub', 'https://mastodon.example/users/ana', 'Ana' );

		$this->assertCount( 0, $this->scheduled( Daymark_Push::CRON_HOOK ) );
	}
}
