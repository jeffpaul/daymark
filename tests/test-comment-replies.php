<?php
/**
 * Daymark_Comment_Replies tests (issue #559): watching the comments you
 * send to followed WordPress posts, and telling you when someone replies.
 *
 * All HTTP is mocked via `pre_http_request`, matching
 * tests/test-comment-delivery.php.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Comment_Replies.
 */
class Test_Comment_Replies extends WP_UnitTestCase {

	private const ROOT      = 'https://origin.example/wp-json/';
	private const PERMALINK = 'https://origin.example/post/';

	/**
	 * URL => canned response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/**
	 * Every URL requested, in order.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	/** @var int */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->requested      = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );

		Daymark_Comment_Replies::flush_local_keys();
		delete_transient( Daymark_Comment_Replies::LOCK );
		_set_cron_array( array() );
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

		$this->requested[] = $url;

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string $url  URL to mock.
	 * @param mixed  $data JSON body (encoded here).
	 * @param int    $code HTTP status code.
	 * @return void
	 */
	private function mock_json( string $url, $data, int $code = 200 ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $data ),
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
	private function create_subscription_post( string $permalink = self::PERMALINK ): int {
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

	/**
	 * Watch a comment on a followed post.
	 *
	 * @param int                  $sub_id    Subscription post ID.
	 * @param array<string, mixed> $overrides Target fields to change.
	 * @return bool
	 */
	private function watch( int $sub_id, array $overrides = array() ): bool {
		return $this->watch_as( $this->user_id, $sub_id, $overrides );
	}

	/**
	 * Watch someone's comment on a followed post.
	 *
	 * @param int                  $user_id   Commenter.
	 * @param int                  $sub_id    Subscription post ID.
	 * @param array<string, mixed> $overrides Target fields to change.
	 * @return bool
	 */
	private function watch_as( int $user_id, int $sub_id, array $overrides = array() ): bool {
		return Daymark_Comment_Replies::start_watch(
			$user_id,
			$sub_id,
			array_merge(
				array(
					'root'    => self::ROOT,
					'remote'  => 55,
					'comment' => 9,
					'text'    => 'Great post!',
				),
				$overrides
			)
		);
	}

	/**
	 * The replies URL a check asks for.
	 *
	 * @param int $comment Watched comment ID.
	 * @return string
	 */
	private function replies_url( int $comment = 9 ): string {
		return Daymark_Remote_Replies::comments_url(
			self::ROOT,
			array(
				'post'     => 55,
				'parent'   => $comment,
				'per_page' => 20,
				'orderby'  => 'date_gmt',
				'order'    => 'desc',
				'_fields'  => Daymark_Remote_Replies::COMMENT_FIELDS,
			)
		);
	}

	/**
	 * The URL a check asks for to find a Webmention comment's ID.
	 *
	 * @return string
	 */
	private function search_url(): string {
		return Daymark_Remote_Replies::comments_url(
			self::ROOT,
			array(
				'post'     => 55,
				'search'   => 'example.org',
				'per_page' => 20,
				'orderby'  => 'date_gmt',
				'order'    => 'desc',
				'_fields'  => 'id,author_url,date_gmt,content',
			)
		);
	}

	/**
	 * A comment from this site, as the search returns it.
	 *
	 * @param int    $id   Comment ID.
	 * @param string $text Text, as the site shows it (HTML).
	 * @param int    $ago  How many seconds ago the site stored it.
	 * @return array<string, mixed>
	 */
	private function own_site_comment( int $id, string $text, int $ago = 0 ): array {
		return array(
			'id'         => $id,
			'author_url' => home_url( '/' ),
			'date_gmt'   => gmdate( 'Y-m-d\TH:i:s', time() - $ago ),
			'content'    => array( 'rendered' => '<p>' . $text . '</p>' ),
		);
	}

	/**
	 * A reply as the comments API returns it.
	 *
	 * @param int    $id     Comment ID.
	 * @param string $author Author name.
	 * @param string $text   Text.
	 * @param string $url    Author URL.
	 * @return array<string, mixed>
	 */
	private function reply( int $id, string $author, string $text, string $url = 'https://reader.example/' ): array {
		return array(
			'id'                 => $id,
			'parent'             => 9,
			'author_name'        => $author,
			'author_url'         => $url,
			'author_avatar_urls' => array( '96' => 'https://secure.gravatar.com/avatar/' . $id . '?s=96' ),
			'date_gmt'           => '2026-10-01T10:00:00',
			'link'               => self::PERMALINK . '#comment-' . $id,
			'content'            => array( 'rendered' => '<p>' . $text . '</p>' ),
		);
	}

	/**
	 * Move every watch back in time, as if the comment were sent (and last
	 * checked) earlier.
	 *
	 * @param int $created_by Seconds to move `created` back.
	 * @param int $checked_by Seconds to move `checked` back (when checked).
	 * @return void
	 */
	private function rewind( int $created_by, int $checked_by ): void {
		foreach ( Daymark_Comment_Replies::get_watches( $this->user_id ) as $watch ) {
			$aged            = $watch;
			$aged['created'] = (int) $watch['created'] - $created_by;

			if ( (int) $watch['checked'] > 0 ) {
				$aged['checked'] = (int) $watch['checked'] - $checked_by;
			}

			update_user_meta( $this->user_id, Daymark_Comment_Replies::META, wp_slash( $aged ), $watch );
		}
	}

	/**
	 * Change someone's watches as stored.
	 *
	 * @param int      $user_id User.
	 * @param callable $change  Gets a watch, returns it changed.
	 * @return void
	 */
	private function alter_watches( int $user_id, callable $change ): void {
		foreach ( Daymark_Comment_Replies::get_watches( $user_id ) as $watch ) {
			update_user_meta( $user_id, Daymark_Comment_Replies::META, wp_slash( $change( $watch ) ), $watch );
		}
	}

	/**
	 * Set when each of someone's watches was sent and last checked.
	 *
	 * @param int               $user_id User.
	 * @param array<int, int[]> $times   Mark => [seconds since sent, seconds since checked].
	 * @return void
	 */
	private function set_times( int $user_id, array $times ): void {
		$this->alter_watches(
			$user_id,
			static function ( array $watch ) use ( $times ): array {
				if ( isset( $times[ (int) $watch['mark'] ] ) ) {
					$watch['created'] = time() - $times[ (int) $watch['mark'] ][0];
					$watch['checked'] = time() - $times[ (int) $watch['mark'] ][1];
				}
				return $watch;
			}
		);
	}

	/**
	 * Someone's watch of the comment a Mark sent.
	 *
	 * @param int $user_id User.
	 * @param int $mark    The comment's Mark.
	 * @return array<string, mixed>
	 */
	private function watch_by_mark( int $user_id, int $mark ): array {
		foreach ( Daymark_Comment_Replies::get_watches( $user_id ) as $watch ) {
			if ( (int) $watch['mark'] === $mark ) {
				return $watch;
			}
		}

		$this->fail( "No watch for Mark {$mark}." );
	}

	/** The one watch this user has. */
	private function only_watch(): array {
		$watches = Daymark_Comment_Replies::get_watches( $this->user_id );

		$this->assertCount( 1, $watches );

		return $watches[0];
	}

	/**
	 * A push subscription for a browser, valid enough to be stored.
	 *
	 * @return array<string, mixed>
	 */
	private function browser_subscription(): array {
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
			'endpoint' => 'https://web.push.apple.com/QGuQyavXutnMH6',
			'keys'     => array(
				'p256dh' => $b64( $point ),
				'auth'   => $b64( random_bytes( 16 ) ),
			),
		);
	}

	/**
	 * The pushes queued so far, as [user ID, message].
	 *
	 * @return array<int, array>
	 */
	private function scheduled_pushes(): array {
		$events = array();

		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Daymark_Push::CRON_HOOK ] ?? array() ) as $event ) {
				$events[] = $event['args'];
			}
		}

		return $events;
	}

	/** A comment Daymark sent is remembered with what it needs to check later. */
	public function test_start_watch_records_the_comment(): void {
		$sub_id = $this->create_subscription_post();

		$this->assertTrue( $this->watch( $sub_id ) );

		$watch = $this->only_watch();

		$this->assertSame( $sub_id, (int) $watch['post'] );
		$this->assertSame( self::PERMALINK, $watch['permalink'] );
		$this->assertSame( 'An origin post', $watch['title'] );
		$this->assertSame( 'origin.example', $watch['site'] );
		$this->assertSame( self::ROOT, $watch['root'] );
		$this->assertSame( 55, (int) $watch['remote'] );
		$this->assertSame( 9, (int) $watch['comment'] );
		$this->assertSame( 0, (int) $watch['mark'] );
		$this->assertSame( 'Great post!', $watch['excerpt'] );
		$this->assertSame( 0, (int) $watch['checked'] );
		$this->assertFalse( $watch['done'] );
		$this->assertSame( array(), $watch['replies'] );
		$this->assertSame( array(), $watch['seen'] );
	}

	/** The same comment twice, or one Daymark can't check, isn't watched. */
	public function test_start_watch_ignores_repeats_and_what_cannot_be_checked(): void {
		$sub_id = $this->create_subscription_post();

		$this->assertTrue( $this->watch( $sub_id ) );
		$this->assertFalse( $this->watch( $sub_id ), 'The same comment again.' );
		$this->assertFalse( $this->watch( $sub_id, array( 'root' => '' ) ), 'No comments API.' );
		$this->assertFalse( $this->watch( $sub_id, array( 'remote' => 0 ) ), 'No post ID there.' );
		$this->assertFalse( $this->watch( (int) self::factory()->post->create() ), 'Not a followed post.' );
		$this->assertFalse(
			Daymark_Comment_Replies::start_watch(
				0,
				$sub_id,
				array(
					'root'    => self::ROOT,
					'remote'  => 55,
					'comment' => 10,
				)
			),
			'No user.'
		);

		$no_permalink = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertFalse( $this->watch( $no_permalink ), 'No permalink.' );

		add_filter( 'daymark_watch_comment_replies', '__return_false' );
		$this->assertFalse( $this->watch( $sub_id, array( 'comment' => 11 ) ), 'Turned off.' );
		remove_filter( 'daymark_watch_comment_replies', '__return_false' );

		$this->assertCount( 1, Daymark_Comment_Replies::get_watches( $this->user_id ) );
	}

	/** Past 25 watched comments, the oldest is dropped. */
	public function test_the_oldest_watch_is_dropped_past_the_limit(): void {
		$sub_id = $this->create_subscription_post();

		for ( $i = 1; $i <= 26; $i++ ) {
			$this->assertTrue( $this->watch( $sub_id, array( 'comment' => $i ) ) );
		}

		$comments = array_map(
			static function ( array $watch ): int {
				return (int) $watch['comment'];
			},
			Daymark_Comment_Replies::get_watches( $this->user_id )
		);

		$this->assertCount( 25, $comments );
		$this->assertNotContains( 1, $comments );
		$this->assertContains( 26, $comments );
	}

	/** A new reply is remembered, listed in Notifications, and pushed once. */
	public function test_a_new_reply_is_found_listed_and_pushed(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sub_id = $this->create_subscription_post();
		$this->watch( $sub_id );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );

		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );

		$watch = $this->only_watch();
		$this->assertGreaterThan( 0, (int) $watch['checked'] );
		$this->assertSame( array( 12 ), array_map( 'intval', $watch['seen'] ) );
		$this->assertCount( 1, $watch['replies'] );

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 1, $pushes );
		$this->assertSame( $this->user_id, $pushes[0][0] );
		$this->assertSame( 'New reply to your comment on An origin post', $pushes[0][1]['title'] );
		$this->assertSame( 'Ana: Thanks for reading!', $pushes[0][1]['body'] );
		$this->assertSame(
			add_query_arg(
				array(
					'daymark_post'    => $sub_id,
					'daymark_replies' => 1,
				),
				Daymark_Routes::app_url()
			),
			$pushes[0][1]['url']
		);
		$this->assertSame( 'comment-reply-' . $watch['id'], $pushes[0][1]['tag'] );

		$items = Daymark_Comment_Replies::notification_items( $this->user_id );
		$this->assertCount( 1, $items );

		$item = $items[0]['item'];
		$this->assertSame( 'comment_reply', $item['type'] );
		$this->assertSame( 'Ana', $item['author'] );
		$this->assertSame( 'https://reader.example/', $item['author_url'] );
		$this->assertSame( 'https://secure.gravatar.com/avatar/12?s=96', $item['avatar'] );
		$this->assertSame( 'Thanks for reading!', $item['content'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $item['reply_date'] );
		$this->assertSame( self::PERMALINK . '#comment-12', $item['reply_url'] );
		$this->assertSame( 'Great post!', $item['comment_excerpt'] );
		$this->assertSame( $sub_id, $item['subscription_post_id'] );
		$this->assertSame( 'An origin post', $item['post_title'] );
		$this->assertSame( self::PERMALINK, $item['post_url'] );
		$this->assertSame( 'origin.example', $item['site'] );
		$this->assertSame( 'followed', $item['source'] );
		$this->assertSame( $watch['id'] . '-12', $item['reply_key'] );

		$this->assertTrue( Daymark_Comment_Replies::has_new_since( $this->user_id, $items[0]['timestamp'] - 1 ) );
		$this->assertFalse( Daymark_Comment_Replies::has_new_since( $this->user_id, $items[0]['timestamp'] ) );
	}

	/** A reply already found isn't pushed again; a later one is. */
	public function test_each_reply_is_pushed_only_once(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sub_id = $this->create_subscription_post();
		$this->watch( $sub_id );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );

		Daymark_Comment_Replies::check_due();
		$this->assertCount( 1, $this->scheduled_pushes() );

		// Checked a moment ago: not due again.
		$this->assertSame( 0, Daymark_Comment_Replies::check_due() );

		$this->rewind( 2 * HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );
		$this->assertCount( 1, $this->scheduled_pushes() );

		$this->mock_json(
			$this->replies_url(),
			array(
				$this->reply( 13, 'Bo', 'Same here.' ),
				$this->reply( 12, 'Ana', 'Thanks for reading!' ),
			)
		);
		$this->rewind( HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 2, $pushes );
		$this->assertSame( 'Bo: Same here.', $pushes[1][1]['body'] );
		$this->assertCount( 2, $this->only_watch()['replies'] );
	}

	/** Several new replies in one check become one push that counts them. */
	public function test_several_new_replies_are_one_push(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$this->watch( $this->create_subscription_post() );
		$this->mock_json(
			$this->replies_url(),
			array(
				$this->reply( 13, 'Bo', 'Same here.' ),
				$this->reply( 12, 'Ana', 'Thanks for reading!' ),
			)
		);

		Daymark_Comment_Replies::check_due();

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 1, $pushes );
		$this->assertSame( '2 new replies', $pushes[0][1]['body'] );
	}

	/** When the followed post is gone, the push opens Notifications instead. */
	public function test_the_push_opens_notifications_when_the_post_is_gone(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sub_id = $this->create_subscription_post();
		$this->watch( $sub_id );
		wp_trash_post( $sub_id );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );

		Daymark_Comment_Replies::check_due();

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 1, $pushes );
		$this->assertSame( Daymark_Routes::app_url( 'notifications' ), $pushes[0][1]['url'] );
		$this->assertSame( 0, Daymark_Comment_Replies::notification_items( $this->user_id )[0]['item']['subscription_post_id'] );
	}

	/** A run that lost a race with another one saves nothing and pushes nothing. */
	public function test_a_check_that_lost_a_race_does_nothing(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$this->watch( $this->create_subscription_post() );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );

		$stale = $this->only_watch();

		// Another run saved the watch first.
		$newer            = $stale;
		$newer['checked'] = 5;
		update_user_meta( $this->user_id, Daymark_Comment_Replies::META, wp_slash( $newer ), $stale );

		$method = new ReflectionMethod( Daymark_Comment_Replies::class, 'check_watch' );
		$method->setAccessible( true );
		$method->invoke( null, $this->user_id, $stale );

		$this->assertCount( 0, $this->scheduled_pushes() );

		$watch = $this->only_watch();
		$this->assertSame( 5, (int) $watch['checked'] );
		$this->assertSame( array(), $watch['replies'] );
	}

	/** Your own answers on that post aren't news to you. */
	public function test_replies_from_your_own_site_are_ignored(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$this->watch( $this->create_subscription_post() );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'You', 'Thanks!', home_url( '/2026/10/a-mark/' ) ) ) );

		Daymark_Comment_Replies::check_due();

		$watch = $this->only_watch();
		$this->assertSame( array( 12 ), array_map( 'intval', $watch['seen'] ) );
		$this->assertSame( array(), $watch['replies'] );
		$this->assertCount( 0, $this->scheduled_pushes() );
	}

	/** A comment sent as a Webmention is found once, by this site's address and its text. */
	public function test_a_webmention_comment_is_found_by_its_text(): void {
		$sub_id = $this->create_subscription_post();
		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
			)
		);

		$now = gmdate( 'Y-m-d\TH:i:s' );
		$this->mock_json(
			$this->search_url(),
			array(
				array(
					'id'         => 40,
					'author_url' => 'https://someone.example/',
					'date_gmt'   => $now,
					'content'    => array( 'rendered' => '<p>Great post!</p>' ),
				),
				array(
					'id'         => 42,
					'author_url' => home_url( '/2026/10/older-mark/' ),
					'date_gmt'   => $now,
					'content'    => array( 'rendered' => '<p>Something else entirely.</p>' ),
				),
				array(
					'id'         => 41,
					'author_url' => home_url( '/2026/10/a-mark/' ),
					'date_gmt'   => $now,
					'content'    => array( 'rendered' => '<p>Great post! Really.</p>' ),
				),
			)
		);
		$this->mock_json( $this->replies_url( 41 ), array() );

		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );

		$this->assertSame( 41, (int) $this->only_watch()['comment'] );
		$this->assertContains( $this->replies_url( 41 ), $this->requested );
		$this->assertSame( array( '41' ), Daymark_Comment_Replies::own_comment_ids( $this->user_id, $sub_id, self::PERMALINK ) );
	}

	/**
	 * A comment with this site's address but other words isn't yours:
	 * anyone can type your address as their website. Daymark keeps
	 * looking, without counting it as a failed check.
	 */
	public function test_this_sites_address_alone_does_not_make_a_comment_yours(): void {
		$this->watch(
			$this->create_subscription_post(),
			array(
				'comment' => 0,
				'mark'    => 77,
			)
		);

		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 43, 'Cheap watches, click here.' ),
				$this->own_site_comment( 30, 'An older comment.', 3 * DAY_IN_SECONDS ),
			)
		);
		$this->mock_json( $this->replies_url( 43 ), array() );

		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );

		$watch = $this->only_watch();

		$this->assertSame( 0, (int) $watch['comment'] );
		$this->assertSame( 0, (int) $watch['fails'] );
		$this->assertFalse( $watch['done'] );
		$this->assertNotContains( $this->replies_url( 43 ), $this->requested );
	}

	/** Each watch is checked once a run, however many comments a person watches. */
	public function test_each_watch_is_checked_once_a_run(): void {
		$sub_id = $this->create_subscription_post();

		foreach ( array( 9, 10, 11 ) as $comment ) {
			$this->watch( $sub_id, array( 'comment' => $comment ) );
			$this->mock_json( $this->replies_url( $comment ), array() );
		}

		$this->assertSame( 3, Daymark_Comment_Replies::check_due() );

		foreach ( array( 9, 10, 11 ) as $comment ) {
			$this->assertCount( 1, array_keys( $this->requested, $this->replies_url( $comment ), true ), "Comment {$comment} is asked about once" );
		}
	}

	/** A comment another watch already found isn't taken; the second one waits for its own. */
	public function test_a_second_comment_on_the_same_post_finds_its_own(): void {
		$sub_id = $this->create_subscription_post();
		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
			)
		);
		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 78,
				'text'    => 'Thanks for writing this.',
			)
		);

		// Only the first comment has reached the site so far.
		$this->mock_json( $this->search_url(), array( $this->own_site_comment( 41, 'Great post!' ) ) );
		$this->mock_json( $this->replies_url( 41 ), array() );

		$this->assertSame( 2, Daymark_Comment_Replies::check_due() );
		$this->assertSame(
			array( 41, 0 ),
			array_map( 'intval', wp_list_pluck( Daymark_Comment_Replies::get_watches( $this->user_id ), 'comment' ) ),
			"The second watch doesn't take the first comment"
		);

		// The second comment arrives, with a note the site added after it.
		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 60, 'Thanks for writing this. (Approved by the site.)' ),
				$this->own_site_comment( 41, 'Great post!' ),
			)
		);
		$this->mock_json( $this->replies_url( 60 ), array() );
		$this->rewind( 0, 2 * HOUR_IN_SECONDS );

		Daymark_Comment_Replies::check_due();

		$this->assertSame(
			array( 41, 60 ),
			array_map( 'intval', wp_list_pluck( Daymark_Comment_Replies::get_watches( $this->user_id ), 'comment' ) )
		);
		$this->assertSame( array( '41', '60' ), Daymark_Comment_Replies::own_comment_ids( $this->user_id, $sub_id, self::PERMALINK ) );
	}

	/**
	 * Two people on this site comment on the same post, one comment
	 * starting with the other's words. Each finds their own, whichever
	 * is checked first.
	 *
	 * @param bool $short_first Whether the shorter comment is checked first.
	 * @return void
	 */
	private function check_two_peoples_comments( bool $short_first ): void {
		$sub_id = $this->create_subscription_post();
		$other  = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
				'text'    => 'Nice.',
			)
		);
		$this->watch_as(
			$other,
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 90,
				'text'    => 'Nice work on this, really.',
			)
		);

		// Both were sent three hours ago; the one checked longest ago goes first.
		$this->set_times( $this->user_id, array( 77 => array( 3 * HOUR_IN_SECONDS, $short_first ? 2 * HOUR_IN_SECONDS : HOUR_IN_SECONDS ) ) );
		$this->set_times( $other, array( 90 => array( 3 * HOUR_IN_SECONDS, $short_first ? HOUR_IN_SECONDS : 2 * HOUR_IN_SECONDS ) ) );

		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 61, 'Nice work on this, really.', 2 * HOUR_IN_SECONDS ),
				$this->own_site_comment( 60, 'Nice.', 3 * HOUR_IN_SECONDS ),
			)
		);
		$this->mock_json( $this->replies_url( 60 ), array( $this->reply( 70, 'Ana', 'Thanks!' ) ) );
		$this->mock_json( $this->replies_url( 61 ), array( $this->reply( 71, 'Bo', 'Glad you liked it.' ) ) );

		$this->assertSame( 2, Daymark_Comment_Replies::check_due() );

		$mine   = $this->watch_by_mark( $this->user_id, 77 );
		$theirs = $this->watch_by_mark( $other, 90 );

		$this->assertSame( 60, (int) $mine['comment'] );
		$this->assertSame( 61, (int) $theirs['comment'] );
		$this->assertSame( array( 'Thanks!' ), wp_list_pluck( $mine['replies'], 'text' ) );
		$this->assertSame( array( 'Glad you liked it.' ), wp_list_pluck( $theirs['replies'], 'text' ) );
	}

	/** The shorter comment checked first doesn't take the longer one that starts with its words. */
	public function test_two_peoples_comments_are_not_swapped_short_first(): void {
		$this->check_two_peoples_comments( true );
	}

	/** Checked the other way round, each still finds their own. */
	public function test_two_peoples_comments_are_not_swapped_long_first(): void {
		$this->check_two_peoples_comments( false );
	}

	/** Two comments with the same words go to the watches by when each was sent. */
	public function test_the_same_words_are_told_apart_by_when_they_were_sent(): void {
		$sub_id = $this->create_subscription_post();
		$other  = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
			)
		);
		$this->watch_as(
			$other,
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 90,
			)
		);

		// Mine was sent three hours ago and is checked first; theirs 90 minutes ago.
		$this->set_times( $this->user_id, array( 77 => array( 3 * HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS ) ) );
		$this->set_times( $other, array( 90 => array( 90 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS ) ) );

		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 61, 'Great post!', 89 * MINUTE_IN_SECONDS ),
				$this->own_site_comment( 60, 'Great post!', 179 * MINUTE_IN_SECONDS ),
			)
		);
		$this->mock_json( $this->replies_url( 60 ), array() );
		$this->mock_json( $this->replies_url( 61 ), array() );

		$this->assertSame( 2, Daymark_Comment_Replies::check_due() );
		$this->assertSame( 60, (int) $this->watch_by_mark( $this->user_id, 77 )['comment'] );
		$this->assertSame( 61, (int) $this->watch_by_mark( $other, 90 )['comment'] );
	}

	/**
	 * Two people send the same words a minute apart. However the two
	 * comments pair up, each watch finds one of them, never both.
	 */
	public function test_each_watch_finds_at_most_one_comment(): void {
		$sub_id = $this->create_subscription_post();
		$other  = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
			)
		);
		$this->watch_as(
			$other,
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 90,
			)
		);

		// Mine was sent four minutes ago and is checked first; theirs three.
		$this->set_times( $this->user_id, array( 77 => array( 4 * MINUTE_IN_SECONDS, 3 * HOUR_IN_SECONDS ) ) );
		$this->set_times( $other, array( 90 => array( 3 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS ) ) );

		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 60, 'Great post!', MINUTE_IN_SECONDS ),
				$this->own_site_comment( 61, 'Great post!', 2 * MINUTE_IN_SECONDS ),
			)
		);
		$this->mock_json( $this->replies_url( 60 ), array() );
		$this->mock_json( $this->replies_url( 61 ), array() );

		$this->assertSame( 2, Daymark_Comment_Replies::check_due() );

		$found = array(
			(int) $this->watch_by_mark( $this->user_id, 77 )['comment'],
			(int) $this->watch_by_mark( $other, 90 )['comment'],
		);
		sort( $found );

		$this->assertSame( array( 60, 61 ), $found );
	}

	/** A comment of one emoji is found by that emoji, and matches no comment with words. */
	public function test_an_emoji_only_comment_matches_only_itself(): void {
		$sub_id = $this->create_subscription_post();

		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
				'text'    => '🔥',
			)
		);
		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 78,
				'text'    => 'Great point about the second half.',
			)
		);

		// The emoji was sent first, and is checked first.
		$this->set_times(
			$this->user_id,
			array(
				77 => array( 3 * HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS ),
				78 => array( 2 * HOUR_IN_SECONDS, HOUR_IN_SECONDS ),
			)
		);

		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 61, 'Great point about the second half.', 119 * MINUTE_IN_SECONDS ),
				$this->own_site_comment( 60, '🔥', 179 * MINUTE_IN_SECONDS ),
			)
		);
		$this->mock_json( $this->replies_url( 60 ), array() );
		$this->mock_json( $this->replies_url( 61 ), array() );

		$this->assertSame( 2, Daymark_Comment_Replies::check_due() );
		$this->assertSame( 60, (int) $this->watch_by_mark( $this->user_id, 77 )['comment'] );
		$this->assertSame( 61, (int) $this->watch_by_mark( $this->user_id, 78 )['comment'] );
	}

	/**
	 * Older watches that never found their comment, one no longer checked
	 * after failing and one past its 30 days, don't take a new comment
	 * with the same words, and don't keep it from being found.
	 */
	public function test_old_unfound_comments_do_not_hold_up_a_new_one(): void {
		$sub_id = $this->create_subscription_post();

		foreach ( array( 75, 76, 78 ) as $mark ) {
			$this->watch(
				$sub_id,
				array(
					'comment' => 0,
					'mark'    => $mark,
					'text'    => 'Use a 2x4 here.',
				)
			);
		}

		$this->set_times(
			$this->user_id,
			array(
				75 => array( 6 * DAY_IN_SECONDS, 6 * DAY_IN_SECONDS - 5 * HOUR_IN_SECONDS ),
				76 => array( 40 * DAY_IN_SECONDS, 11 * DAY_IN_SECONDS ),
			)
		);
		$this->alter_watches(
			$this->user_id,
			static function ( array $watch ): array {
				if ( 75 === (int) $watch['mark'] ) {
					$watch['fails'] = 5;
					$watch['done']  = true;
				}

				return $watch;
			}
		);

		// The site shows "2x4" as "2×4".
		$this->mock_json( $this->search_url(), array( $this->own_site_comment( 60, 'Use a 2&#215;4 here.' ) ) );
		$this->mock_json( $this->replies_url( 60 ), array() );

		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );
		$this->assertSame( 60, (int) $this->watch_by_mark( $this->user_id, 78 )['comment'] );
		$this->assertSame( 0, (int) $this->watch_by_mark( $this->user_id, 75 )['comment'] );
		$this->assertSame( 0, (int) $this->watch_by_mark( $this->user_id, 76 )['comment'] );
	}

	/**
	 * A watch that isn't checked anymore keeps the comment that fits its
	 * words best, so a newer, shorter comment doesn't take it; the newer
	 * one waits for its own.
	 */
	public function test_an_unfound_comment_keeps_its_words_from_a_newer_one(): void {
		$sub_id = $this->create_subscription_post();

		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 77,
				'text'    => 'Great post, really enjoyed it.',
			)
		);
		$this->watch(
			$sub_id,
			array(
				'comment' => 0,
				'mark'    => 78,
				'text'    => 'Great post',
			)
		);

		$this->set_times( $this->user_id, array( 77 => array( 40 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS ) ) );
		$this->alter_watches(
			$this->user_id,
			static function ( array $watch ): array {
				if ( 77 === (int) $watch['mark'] ) {
					$watch['done'] = true;
				}

				return $watch;
			}
		);

		$this->mock_json( $this->search_url(), array( $this->own_site_comment( 60, 'Great post, really enjoyed it.', 39 * MINUTE_IN_SECONDS ) ) );

		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );
		$this->assertSame( 0, (int) $this->watch_by_mark( $this->user_id, 78 )['comment'] );
		$this->assertNotContains( $this->replies_url( 60 ), $this->requested );

		// The newer comment reaches the site.
		$this->mock_json(
			$this->search_url(),
			array(
				$this->own_site_comment( 61, 'Great post' ),
				$this->own_site_comment( 60, 'Great post, really enjoyed it.', 39 * MINUTE_IN_SECONDS ),
			)
		);
		$this->mock_json( $this->replies_url( 61 ), array() );
		$this->rewind( 0, 2 * HOUR_IN_SECONDS );

		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );
		$this->assertSame( 61, (int) $this->watch_by_mark( $this->user_id, 78 )['comment'] );
		$this->assertSame( 0, (int) $this->watch_by_mark( $this->user_id, 77 )['comment'] );
	}

	/** The site's curly quotes, dashes, "×", and smileys don't stop a comment from being found. */
	public function test_a_comment_is_found_through_the_sites_typography(): void {
		$this->watch(
			$this->create_subscription_post(),
			array(
				'comment' => 0,
				'mark'    => 77,
				'text'    => "It's a 2x4 -- isn't it? :)",
			)
		);

		$this->mock_json( $this->search_url(), array( $this->own_site_comment( 60, 'It&#8217;s a 2&#215;4 &#8212; isn&#8217;t it? 🙂' ) ) );
		$this->mock_json( $this->replies_url( 60 ), array() );

		Daymark_Comment_Replies::check_due();

		$this->assertSame( 60, (int) $this->only_watch()['comment'] );
	}

	/** A comment in two paragraphs is found, however the site joins them. */
	public function test_a_comment_in_paragraphs_is_found(): void {
		$this->watch(
			$this->create_subscription_post(),
			array(
				'comment' => 0,
				'mark'    => 77,
				'text'    => "First thought\n\nSecond thought",
			)
		);

		$this->mock_json( $this->search_url(), array( $this->own_site_comment( 60, 'First thought</p><p>Second thought' ) ) );
		$this->mock_json( $this->replies_url( 60 ), array() );

		Daymark_Comment_Replies::check_due();

		$this->assertSame( 60, (int) $this->only_watch()['comment'] );
	}

	/** A long comment, kept only up to a word cut short, is found by its start. */
	public function test_a_long_comment_is_found_by_its_start(): void {
		$text = 'Hi ' . str_repeat( 'Long thoughts. ', 10 );

		$this->watch(
			$this->create_subscription_post(),
			array(
				'comment' => 0,
				'mark'    => 77,
				'text'    => $text,
			)
		);

		$this->assertStringEndsWith( ' L…', (string) $this->only_watch()['excerpt'] );

		$this->mock_json( $this->search_url(), array( $this->own_site_comment( 60, trim( $text ) ) ) );
		$this->mock_json( $this->replies_url( 60 ), array() );

		Daymark_Comment_Replies::check_due();

		$this->assertSame( 60, (int) $this->only_watch()['comment'] );
	}

	/** A comment the site sends without a date is still found by its words. */
	public function test_an_undated_comment_is_found_by_its_words(): void {
		$this->watch(
			$this->create_subscription_post(),
			array(
				'comment' => 0,
				'mark'    => 77,
			)
		);

		$comment             = $this->own_site_comment( 60, 'Great post!' );
		$comment['date_gmt'] = '';
		$this->mock_json( $this->search_url(), array( $comment ) );
		$this->mock_json( $this->replies_url( 60 ), array() );

		Daymark_Comment_Replies::check_due();

		$this->assertSame( 60, (int) $this->only_watch()['comment'] );
	}

	/** A site that stops sharing comments is no longer checked. */
	public function test_a_site_that_stops_sharing_comments_is_no_longer_checked(): void {
		$this->watch( $this->create_subscription_post() );
		$this->mock_json( $this->replies_url(), array( 'code' => 'rest_forbidden' ), 401 );

		Daymark_Comment_Replies::check_due();

		$this->assertTrue( $this->only_watch()['done'] );

		$this->rewind( DAY_IN_SECONDS, DAY_IN_SECONDS );
		$this->assertSame( 0, Daymark_Comment_Replies::check_due() );
	}

	/** Five failed checks in a row end the watch. */
	public function test_five_failed_checks_end_the_watch(): void {
		$this->watch( $this->create_subscription_post() );
		$this->mock_json( $this->replies_url(), array(), 500 );

		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertSame( 1, Daymark_Comment_Replies::check_due() );
			$this->assertSame( $i, (int) $this->only_watch()['fails'] );
			$this->assertFalse( $this->only_watch()['done'] );
			$this->rewind( 0, 2 * HOUR_IN_SECONDS );
		}

		Daymark_Comment_Replies::check_due();
		$this->assertTrue( $this->only_watch()['done'] );
	}

	/** Older comments are checked less often. */
	public function test_older_comments_are_checked_less_often(): void {
		$this->watch( $this->create_subscription_post() );
		$this->mock_json( $this->replies_url(), array() );

		Daymark_Comment_Replies::check_due();

		// Three days old, checked two hours ago: every six hours now.
		$this->rewind( 3 * DAY_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		$this->assertSame( 0, Daymark_Comment_Replies::check_due() );

		$this->rewind( 0, 5 * HOUR_IN_SECONDS );
		$this->assertSame( 1, Daymark_Comment_Replies::check_due() );
	}

	/** Past 30 days a comment isn't checked; past 45 its watch is removed. */
	public function test_old_watches_stop_and_are_removed(): void {
		$this->watch( $this->create_subscription_post() );

		$this->rewind( 31 * DAY_IN_SECONDS, 0 );
		$this->assertSame( 0, Daymark_Comment_Replies::check_due() );
		$this->assertSame( array(), $this->requested );
		$this->assertCount( 1, Daymark_Comment_Replies::get_watches( $this->user_id ) );

		$this->rewind( 15 * DAY_IN_SECONDS, 0 );
		Daymark_Comment_Replies::check_due();
		$this->assertSame( array(), Daymark_Comment_Replies::get_watches( $this->user_id ) );
	}

	/** A second run waits while one is already checking. */
	public function test_a_run_waits_while_another_is_checking(): void {
		$this->watch( $this->create_subscription_post() );
		set_transient( Daymark_Comment_Replies::LOCK, time(), MINUTE_IN_SECONDS );

		$this->assertSame( 0, Daymark_Comment_Replies::check_due() );
		$this->assertSame( array(), $this->requested );
	}

	/**
	 * A reply found by checking, then received as a Webmention on the
	 * comment's Mark, is pushed once and listed once (as the Mark's comment).
	 */
	public function test_a_reply_found_first_is_not_pushed_again_as_a_webmention(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sub_id  = $this->create_subscription_post();
		$mark_id = (int) self::factory()->post->create( array( 'post_author' => $this->user_id ) );
		update_post_meta( $mark_id, '_daymark_is_mark', '1' );
		update_post_meta( $mark_id, '_daymark_in_reply_to', self::PERMALINK );

		$this->watch( $sub_id, array( 'mark' => $mark_id ) );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );

		Daymark_Comment_Replies::check_due();
		$this->assertCount( 1, $this->scheduled_pushes() );
		$this->assertTrue( Daymark_Comment_Replies::already_found( $mark_id, self::PERMALINK . '#comment-12' ) );
		$this->assertFalse( Daymark_Comment_Replies::already_found( $mark_id, self::PERMALINK . '#comment-99' ) );

		self::factory()->comment->create(
			array(
				'comment_post_ID' => $mark_id,
				'comment_author'  => 'Ana',
				'comment_content' => 'Thanks for reading!',
				'comment_meta'    => array( 'webmention_source_url' => self::PERMALINK . '#comment-12' ),
			)
		);

		$this->assertCount( 1, $this->scheduled_pushes() );

		Daymark_Comment_Replies::flush_local_keys();
		$this->assertSame( array(), Daymark_Comment_Replies::notification_items( $this->user_id ) );
	}

	/** The same when Daymark's own Webmention receiver stores the reply, approved on arrival. */
	public function test_a_reply_found_first_is_not_pushed_again_by_the_built_in_receiver(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sub_id  = $this->create_subscription_post();
		$mark_id = (int) self::factory()->post->create( array( 'post_author' => $this->user_id ) );
		update_post_meta( $mark_id, '_daymark_is_mark', '1' );
		update_post_meta( $mark_id, '_daymark_in_reply_to', self::PERMALINK );

		$this->watch( $sub_id, array( 'mark' => $mark_id ) );
		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );

		Daymark_Comment_Replies::check_due();
		$this->assertCount( 1, $this->scheduled_pushes() );

		// With no "previously approved" rule, the reply is approved as it's
		// stored, so the push check runs during the insert.
		update_option( 'comment_previously_approved', 0 );
		$comment_id = Daymark_Webmention_Receiver::store(
			$mark_id,
			self::PERMALINK . '#comment-12',
			(string) get_permalink( $mark_id ),
			array(
				'type'       => 'comment',
				'author'     => 'Ana',
				'author_url' => 'https://reader.example/',
				'content'    => 'Thanks for reading!',
			)
		);

		$this->assertGreaterThan( 0, $comment_id );
		$this->assertSame( '1', get_comment( $comment_id )->comment_approved );
		$this->assertSame( self::PERMALINK . '#comment-12', get_comment_meta( $comment_id, 'webmention_source_url', true ) );
		$this->assertSame( 'webmention', get_comment_meta( $comment_id, 'protocol', true ) );
		$this->assertCount( 1, $this->scheduled_pushes() );
	}

	/**
	 * A reply that reached the Mark as a Webmention first is pushed by that
	 * comment, and the check that finds it later stays quiet.
	 */
	public function test_a_reply_received_first_as_a_webmention_is_not_pushed_again(): void {
		Daymark_Push::save_subscription( $this->user_id, $this->browser_subscription() );
		$sub_id  = $this->create_subscription_post();
		$mark_id = (int) self::factory()->post->create( array( 'post_author' => $this->user_id ) );
		update_post_meta( $mark_id, '_daymark_is_mark', '1' );
		update_post_meta( $mark_id, '_daymark_in_reply_to', self::PERMALINK );

		$this->watch( $sub_id, array( 'mark' => $mark_id ) );

		self::factory()->comment->create(
			array(
				'comment_post_ID' => $mark_id,
				'comment_author'  => 'Ana',
				'comment_content' => 'Thanks for reading!',
				'comment_meta'    => array( 'webmention_source_url' => self::PERMALINK . '?replytocom=12#respond' ),
			)
		);

		$pushes = $this->scheduled_pushes();
		$this->assertCount( 1, $pushes );
		$this->assertSame( 'New reply to your comment on An origin post', $pushes[0][1]['title'] );

		$this->mock_json( $this->replies_url(), array( $this->reply( 12, 'Ana', 'Thanks for reading!' ) ) );
		Daymark_Comment_Replies::check_due();

		$this->assertCount( 1, $this->scheduled_pushes() );
		$this->assertTrue( $this->only_watch()['replies'][0]['local'] );
		$this->assertSame( array(), Daymark_Comment_Replies::notification_items( $this->user_id ) );
	}

	/** Reply keys identify one comment on one site, whichever URL form it comes in. */
	public function test_reply_key_reads_comment_urls(): void {
		$this->assertSame( 'origin.example#12', Daymark_Comment_Replies::reply_key( 'https://www.Origin.example/post/#comment-12' ) );
		$this->assertSame( 'origin.example#12', Daymark_Comment_Replies::reply_key( 'https://origin.example/post/comment-page-2/#comment-12' ) );
		$this->assertSame( 'origin.example#7', Daymark_Comment_Replies::reply_key( 'https://origin.example/post/?replytocom=7#respond' ) );
		$this->assertSame( '', Daymark_Comment_Replies::reply_key( 'https://origin.example/post/' ) );
		$this->assertSame( '', Daymark_Comment_Replies::reply_key( '' ) );
	}
}
