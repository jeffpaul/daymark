<?php
/**
 * Explore's "Sites you might follow": sites from the owner's own circle
 * (followers, and people who replied to or liked a post here) that this
 * site doesn't follow yet.
 *
 * @package Daymark
 */

/**
 * Daymark_Site_Suggestions and its REST routes.
 */
class Test_Site_Suggestions extends WP_UnitTestCase {

	/**
	 * The signed-in administrator.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Follow a site.
	 *
	 * @param string $site_url Its address.
	 */
	private function follow( string $site_url ): void {
		$id = Daymark_Plugin::instance()->subscriptions->create(
			array(
				'site_url' => $site_url,
				'feed_url' => trailingslashit( $site_url ) . 'feed/',
			)
		);

		$this->assertIsInt( $id );
	}

	/**
	 * An approved response from another site on one of this site's posts.
	 *
	 * @param int                  $post_id The post.
	 * @param string               $url     The responder's address.
	 * @param string               $type    comment, like, repost, or mention.
	 * @param array<string, mixed> $extra   Extra comment fields.
	 * @return int Comment ID.
	 */
	private function respond( int $post_id, string $url, string $type = 'comment', array $extra = array() ): int {
		return (int) self::factory()->comment->create(
			array_merge(
				array(
					'comment_post_ID'    => $post_id,
					'comment_author'     => 'Leo Brandt',
					'comment_author_url' => $url,
					'comment_type'       => $type,
					'comment_approved'   => 1,
					'user_id'            => 0,
				),
				$extra
			)
		);
	}

	/**
	 * Each suggestion as "address: reason[ count], ...", in the order returned.
	 *
	 * @param int $user_id Whose suggestions. The administrator by default.
	 * @return string[]
	 */
	private function suggestions( int $user_id = 0 ): array {
		return array_map(
			static function ( array $site ): string {
				$reasons = array_map(
					static function ( array $reason ): string {
						return $reason['kind'] . ( $reason['count'] > 0 ? ' ' . $reason['count'] : '' );
					},
					$site['reasons']
				);

				return $site['url'] . ': ' . implode( ', ', $reasons );
			},
			Daymark_Site_Suggestions::for_user( $user_id > 0 ? $user_id : $this->admin_id )
		);
	}

	/**
	 * A REST request carrying a valid nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @return WP_REST_Request
	 */
	private function rest( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** A site that follows this one, and isn't followed back, is suggested. */
	public function test_a_follower_you_dont_follow_is_suggested(): void {
		Daymark_Followers::add( 'webmention', 'https://dana.example/following/', 'Dana Kim', 'https://dana.example/', false );

		$sites = Daymark_Site_Suggestions::for_user( $this->admin_id );

		$this->assertCount( 1, $sites );
		$this->assertSame( 'Dana Kim', $sites[0]['name'] );
		$this->assertSame( 'https://dana.example/', $sites[0]['url'] );
		$this->assertSame(
			array(
				array(
					'kind'  => 'follows_you',
					'count' => 0,
				),
			),
			$sites[0]['reasons']
		);
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $sites[0]['key'] );
	}

	/** A follower with no address (an email subscriber) can't be followed, so isn't suggested. */
	public function test_a_follower_without_an_address_is_left_out(): void {
		Daymark_Followers::add( 'jetpack', '42', 'Someone', '', false );

		$this->assertSame( array(), $this->suggestions() );
	}

	/** A site already followed is never suggested, however its address is written. */
	public function test_a_site_you_follow_is_left_out(): void {
		$this->follow( 'https://dana.example/' );
		Daymark_Followers::add( 'webmention', 'a', 'Dana Kim', 'http://www.dana.example', false );
		$this->respond( self::factory()->post->create(), 'https://dana.example/about/' );

		$this->assertSame( array(), $this->suggestions() );
	}

	/** This site itself is never suggested. */
	public function test_your_own_site_is_left_out(): void {
		$this->respond( self::factory()->post->create(), home_url( '/author/someone/' ) );

		$this->assertSame( array(), $this->suggestions() );
	}

	/** Someone who replied is suggested, counted by how many posts they replied to. */
	public function test_a_reply_counts_once_per_post(): void {
		$first  = self::factory()->post->create();
		$second = self::factory()->post->create();
		$this->respond( $first, 'https://leo.example/' );
		$this->respond( $first, 'https://leo.example/' );
		$this->respond( $second, 'https://leo.example/' );

		$this->assertSame( array( 'https://leo.example/: replied 2' ), $this->suggestions() );
		$this->assertSame( 'Leo Brandt', Daymark_Site_Suggestions::for_user( $this->admin_id )[0]['name'] );
	}

	/** Likes, reblogs and mentions each say so, after any reply. */
	public function test_each_kind_of_response_has_its_own_reason(): void {
		$post = self::factory()->post->create();
		$this->respond( $post, 'https://leo.example/', 'mention' );
		$this->respond( $post, 'https://leo.example/', 'repost' );
		$this->respond( $post, 'https://leo.example/', 'like' );
		$this->respond( $post, 'https://leo.example/', 'comment' );

		$this->assertSame(
			array( 'https://leo.example/: replied 1, liked 1, reblogged 1, mentioned 1' ),
			$this->suggestions()
		);
	}

	/** A response that isn't approved, or is a pingback, doesn't count. */
	public function test_only_approved_responses_count(): void {
		$post = self::factory()->post->create();
		$this->respond( $post, 'https://pending.example/', 'comment', array( 'comment_approved' => 0 ) );
		$this->respond( $post, 'https://spam.example/', 'comment', array( 'comment_approved' => 'spam' ) );
		$this->respond( $post, 'https://ping.example/', 'pingback' );

		$this->assertSame( array(), $this->suggestions() );
	}

	/** A comment by someone with an account on this site isn't a site to follow. */
	public function test_a_members_comment_does_not_count(): void {
		$member = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->respond( self::factory()->post->create(), 'https://member.example/', 'comment', array( 'user_id' => $member ) );

		$this->assertSame( array(), $this->suggestions() );
	}

	/** The sample comment every new WordPress site starts with isn't a person to follow. */
	public function test_wordpress_sample_comment_does_not_count(): void {
		$this->respond(
			self::factory()->post->create(),
			'https://wordpress.org/',
			'comment',
			array(
				'comment_author'       => 'A WordPress Commenter',
				'comment_author_email' => 'wapuu@wordpress.example',
			)
		);

		$this->assertSame( array(), $this->suggestions() );
	}

	/** One row per site, with every reason, "Follows you" first. */
	public function test_one_row_per_site_with_every_reason(): void {
		$this->respond( self::factory()->post->create(), 'https://www.dana.example', 'like' );
		Daymark_Followers::add( 'webmention', 'a', 'Dana Kim', 'https://dana.example/', false );

		$this->assertSame( array( 'https://dana.example/: follows_you, liked 1' ), $this->suggestions() );
	}

	/** Two people on one shared host are two sites, and following one leaves the other. */
	public function test_people_on_a_shared_host_stay_apart(): void {
		$post = self::factory()->post->create();
		$this->respond( $post, 'https://social.example/@ana', 'like', array( 'comment_date_gmt' => '2026-11-02 10:00:00' ) );
		$this->respond( $post, 'https://social.example/@ben', 'like', array( 'comment_date_gmt' => '2026-11-01 10:00:00' ) );

		$this->assertSame(
			array( 'https://social.example/@ana: liked 1', 'https://social.example/@ben: liked 1' ),
			$this->suggestions()
		);

		$this->follow( 'https://social.example/@ana' );

		$this->assertSame( array( 'https://social.example/@ben: liked 1' ), $this->suggestions() );
	}

	/** Whoever was in touch most recently comes first, and the list is short. */
	public function test_newest_first_and_capped(): void {
		$post = self::factory()->post->create();

		for ( $day = 1; $day <= Daymark_Site_Suggestions::MAX + 2; $day++ ) {
			$this->respond(
				$post,
				'https://site' . $day . '.example/',
				'comment',
				array( 'comment_date_gmt' => sprintf( '2026-10-%02d 09:00:00', $day ) )
			);
		}

		$sites = Daymark_Site_Suggestions::for_user( $this->admin_id );

		$this->assertCount( Daymark_Site_Suggestions::MAX, $sites );
		$this->assertSame( 'https://site' . ( Daymark_Site_Suggestions::MAX + 2 ) . '.example/', $sites[0]['url'] );
	}

	/** "Not now" hides a site for the person who said it, and only for them. */
	public function test_dismissing_hides_a_site_for_that_user(): void {
		Daymark_Followers::add( 'webmention', 'a', 'Dana Kim', 'https://dana.example/', false );
		$other = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$key   = Daymark_Site_Suggestions::for_user( $this->admin_id )[0]['key'];

		Daymark_Site_Suggestions::dismiss( $this->admin_id, $key );

		$this->assertSame( array(), $this->suggestions() );
		$this->assertSame( array( 'https://dana.example/: follows_you' ), $this->suggestions( $other ) );
	}

	/** A dismissed site makes room for the next one. */
	public function test_a_dismissed_site_makes_room_for_the_next(): void {
		$post = self::factory()->post->create();

		for ( $day = 1; $day <= Daymark_Site_Suggestions::MAX + 1; $day++ ) {
			$this->respond(
				$post,
				'https://site' . $day . '.example/',
				'comment',
				array( 'comment_date_gmt' => sprintf( '2026-10-%02d 09:00:00', $day ) )
			);
		}

		$first = Daymark_Site_Suggestions::for_user( $this->admin_id )[0];
		Daymark_Site_Suggestions::dismiss( $this->admin_id, $first['key'] );

		$urls = wp_list_pluck( Daymark_Site_Suggestions::for_user( $this->admin_id ), 'url' );

		$this->assertCount( Daymark_Site_Suggestions::MAX, $urls );
		$this->assertNotContains( $first['url'], $urls );
		$this->assertContains( 'https://site1.example/', $urls );
	}

	/** An address that isn't a web address is never suggested. */
	public function test_an_unusable_address_is_left_out(): void {
		$this->respond( self::factory()->post->create(), 'javascript:alert(1)' );
		$this->respond( self::factory()->post->create(), 'mailto:leo@leo.example' );

		$this->assertSame( array(), $this->suggestions() );
	}

	/** GET /suggestions/sites lists them for someone who can follow sites. */
	public function test_rest_lists_suggestions(): void {
		Daymark_Followers::add( 'webmention', 'a', 'Dana Kim', 'https://dana.example/', false );

		$response = rest_do_request( $this->rest( 'GET', '/daymark/v1/suggestions/sites' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( array( 'key', 'name', 'url', 'host', 'reasons' ), array_keys( $data[0] ) );
		$this->assertSame( 'dana.example', $data[0]['host'] );
	}

	/** Following is for people who manage the site, so the list is too. */
	public function test_rest_refuses_someone_who_cannot_follow_sites(): void {
		Daymark_Followers::add( 'webmention', 'a', 'Dana Kim', 'https://dana.example/', false );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );

		$response = rest_do_request( $this->rest( 'GET', '/daymark/v1/suggestions/sites' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/** POST /suggestions/sites/{key}/dismiss hides one, and answers with what is left. */
	public function test_rest_dismiss_hides_a_suggestion(): void {
		Daymark_Followers::add( 'webmention', 'a', 'Dana Kim', 'https://dana.example/', false );
		$this->respond( self::factory()->post->create(), 'https://leo.example/' );
		$key = '';

		foreach ( Daymark_Site_Suggestions::for_user( $this->admin_id ) as $site ) {
			if ( 'https://dana.example/' === $site['url'] ) {
				$key = $site['key'];
			}
		}

		$response = rest_do_request( $this->rest( 'POST', '/daymark/v1/suggestions/sites/' . $key . '/dismiss' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'https://leo.example/' ), wp_list_pluck( $response->get_data(), 'url' ) );
		$this->assertSame( array( 'https://leo.example/: replied 1' ), $this->suggestions() );
	}

	/** Dismissing needs the same permission as the list. */
	public function test_rest_dismiss_refuses_someone_who_cannot_follow_sites(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );

		$response = rest_do_request( $this->rest( 'POST', '/daymark/v1/suggestions/sites/0123456789abcdef/dismiss' ) );

		$this->assertSame( 403, $response->get_status() );
	}
}
