<?php
/**
 * Daymark_Remote_Replies tests (issue #559): a followed post's replies,
 * read from its own site. A Mastodon-family post through its server's
 * context API, a WordPress post through its comments API, threaded, cached,
 * and reduced to safe text HTML.
 *
 * All HTTP is mocked via `pre_http_request`, the same pattern as
 * tests/test-comment-delivery.php.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Remote_Replies.
 */
class Test_Remote_Replies extends WP_UnitTestCase {

	/**
	 * URL => canned response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/**
	 * Every URL a test requested, in order.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->requested      = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
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
	 * @param string               $url     URL to mock.
	 * @param string               $body    Response body.
	 * @param int                  $code    HTTP status code.
	 * @param array<string,string> $headers Response headers, lowercase names.
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
	 * Mock a WordPress post page that advertises its REST root and post ID.
	 *
	 * @param string $permalink The post's URL.
	 * @param int    $post_id   Its ID on that site.
	 * @return void
	 */
	private function mock_wordpress_post( string $permalink, int $post_id ): void {
		$this->mock_response(
			$permalink,
			'<html><head><link rel="https://api.w.org/" href="https://origin.example/wp-json/"><link rel="alternate" type="application/json" href="https://origin.example/wp-json/wp/v2/posts/' . $post_id . '"></head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);
	}

	/**
	 * The comments URL the WordPress path asks for.
	 *
	 * @param int $post_id Post ID on the origin.
	 * @return string
	 */
	private function wordpress_comments_url( int $post_id ): string {
		return Daymark_Remote_Replies::comments_url(
			'https://origin.example/wp-json/',
			array(
				'post'     => $post_id,
				'per_page' => 50,
				'orderby'  => 'date_gmt',
				'order'    => 'desc',
				'_fields'  => Daymark_Remote_Replies::COMMENT_FIELDS,
			)
		);
	}

	/**
	 * A Mastodon status as the context API returns it.
	 *
	 * @param string               $id        Status ID.
	 * @param string               $parent_id In-reply-to ID.
	 * @param string               $created   created_at.
	 * @param array<string, mixed> $overrides Extra fields.
	 * @return array<string, mixed>
	 */
	private function status( string $id, string $parent_id, string $created, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                => $id,
				'in_reply_to_id'    => $parent_id,
				'created_at'        => $created,
				'url'               => 'https://mastodon.example/@bo/' . $id,
				'uri'               => 'https://mastodon.example/users/bo/statuses/' . $id,
				'content'           => '<p>Reply ' . $id . '</p>',
				'spoiler_text'      => '',
				'media_attachments' => array(),
				'account'           => array(
					'username'      => 'bo',
					'acct'          => 'bo',
					'display_name'  => 'Bo',
					'url'           => 'https://mastodon.example/@bo',
					'avatar_static' => 'https://mastodon.example/avatars/bo.png',
				),
			),
			$overrides
		);
	}

	/** A Mastodon post's replies come from its server's context API, threaded oldest first. */
	public function test_mastodon_replies_are_loaded_and_threaded(): void {
		$permalink = 'https://mastodon.example/@ana/111';

		$this->mock_response(
			'https://mastodon.example/api/v1/statuses/111/context',
			(string) wp_json_encode(
				array(
					'ancestors'   => array(),
					'descendants' => array(
						$this->status( '112', '111', '2026-10-01T10:00:00.000Z' ),
						$this->status(
							'113',
							'112',
							'2026-10-01T11:00:00.000Z',
							array(
								'spoiler_text'      => 'Spoilers',
								'media_attachments' => array( array( 'id' => '1' ) ),
								'account'           => array(
									'username'     => 'cy',
									'acct'         => 'cy@other.example',
									'display_name' => '',
									'url'          => 'https://other.example/@cy',
								),
							)
						),
						$this->status( '114', '111', '2026-10-01T10:30:00.000Z' ),
					),
				)
			)
		);

		$result = Daymark_Remote_Replies::for_permalink( $permalink );

		$this->assertSame( Daymark_Remote_Replies::STATUS_OK, $result['status'] );
		$this->assertSame( Daymark_Remote_Replies::SOURCE_MASTODON, $result['source'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( $permalink, $result['url'] );
		$this->assertSame( array( '112', '113', '114' ), array_column( $result['replies'], 'id' ) );
		$this->assertSame( array( 0, 1, 0 ), array_column( $result['replies'], 'depth' ) );

		$first = $result['replies'][0];
		$this->assertSame( 'Bo', $first['author_name'] );
		$this->assertSame( '@bo@mastodon.example', $first['handle'] );
		$this->assertSame( 'https://mastodon.example/avatars/bo.png', $first['avatar'] );
		$this->assertSame( '<p>Reply 112</p>', $first['content'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $first['date'] );
		$this->assertSame( 'https://mastodon.example/@bo/112', $first['url'] );
		$this->assertArrayNotHasKey( '_ts', $first );

		$second = $result['replies'][1];
		$this->assertSame( 'cy', $second['author_name'], 'A blank display name falls back to the username' );
		$this->assertSame( '@cy@other.example', $second['handle'] );
		$this->assertSame( 'Spoilers', $second['warning'] );
		$this->assertSame( 1, $second['media_count'] );
	}

	/** A server that requires sign-in for replies keeps them on the site. */
	public function test_mastodon_server_that_requires_sign_in_is_unsupported(): void {
		$this->mock_response( 'https://mastodon.example/api/v1/statuses/222/context', '{"error":"This API requires an authenticated user"}', 401 );

		$result = Daymark_Remote_Replies::for_permalink( 'https://mastodon.example/@ana/222' );

		$this->assertSame( Daymark_Remote_Replies::STATUS_UNSUPPORTED, $result['status'] );
		$this->assertSame( Daymark_Remote_Replies::SOURCE_MASTODON, $result['source'] );
		$this->assertSame( array(), $result['replies'] );
	}

	/** A path that only looks like Mastodon's, on a site without its API, gets the WordPress path. */
	public function test_a_missing_context_api_falls_through_to_wordpress(): void {
		$permalink = 'https://origin.example/@ana/333';

		$this->mock_response( 'https://origin.example/api/v1/statuses/333/context', 'Not found', 404 );
		$this->mock_wordpress_post( $permalink, 77 );
		$this->mock_response( $this->wordpress_comments_url( 77 ), '[]', 200, array( 'x-wp-total' => '0' ) );

		$result = Daymark_Remote_Replies::for_permalink( $permalink );

		$this->assertSame( Daymark_Remote_Replies::STATUS_OK, $result['status'] );
		$this->assertSame( Daymark_Remote_Replies::SOURCE_WORDPRESS, $result['source'] );
	}

	/** A WordPress post's approved comments come from its comments API, threaded, with the site's own total. */
	public function test_wordpress_comments_are_loaded_and_threaded(): void {
		$permalink = 'https://origin.example/2026/10/a-post/';

		$this->mock_wordpress_post( $permalink, 55 );
		$this->mock_response(
			$this->wordpress_comments_url( 55 ),
			(string) wp_json_encode(
				array(
					array(
						'id'                 => 31,
						'parent'             => 30,
						'author_name'        => 'Bo',
						'author_url'         => '',
						'author_avatar_urls' => array(
							'24' => 'https://secure.gravatar.com/avatar/b?s=24',
							'96' => 'https://secure.gravatar.com/avatar/b?s=96',
						),
						'date_gmt'           => '2026-10-02T09:00:00',
						'link'               => $permalink . '#comment-31',
						'content'            => array( 'rendered' => "<p>Agreed!</p>\n" ),
					),
					array(
						'id'                 => 30,
						'parent'             => 0,
						'author_name'        => 'Ana &amp; Co',
						'author_url'         => 'https://ana.example/',
						'author_avatar_urls' => array( '96' => 'http://insecure.example/a.png' ),
						'date_gmt'           => '2026-10-01T09:00:00',
						'link'               => $permalink . '#comment-30',
						'content'            => array( 'rendered' => "<p>Lovely post.</p>\n" ),
					),
				)
			),
			200,
			array( 'x-wp-total' => '7' )
		);

		$result = Daymark_Remote_Replies::for_permalink( $permalink );

		$this->assertSame( Daymark_Remote_Replies::STATUS_OK, $result['status'] );
		$this->assertSame( Daymark_Remote_Replies::SOURCE_WORDPRESS, $result['source'] );
		$this->assertSame( 7, $result['total'], 'The total is the site\'s own count, not just the replies loaded' );
		$this->assertSame( $permalink . '#comments', $result['url'] );
		$this->assertSame( array( '30', '31' ), array_column( $result['replies'], 'id' ) );
		$this->assertSame( array( 0, 1 ), array_column( $result['replies'], 'depth' ) );

		$ana = $result['replies'][0];
		$this->assertSame( 'Ana & Co', $ana['author_name'] );
		$this->assertSame( 'https://ana.example/', $ana['author_url'] );
		$this->assertSame( '', $ana['avatar'], 'An avatar not on https is dropped' );
		$this->assertSame( '<p>Lovely post.</p>', $ana['content'] );
		$this->assertSame( '2026-10-01T09:00:00+00:00', $ana['date'], 'date_gmt has no zone and is read as UTC' );
		$this->assertSame( $permalink . '#comment-30', $ana['url'] );
		$this->assertSame( '', $ana['handle'] );

		$this->assertSame( 'https://secure.gravatar.com/avatar/b?s=96', $result['replies'][1]['avatar'] );
	}

	/** A site without a comments API, or one that keeps comments private, is unsupported. */
	public function test_a_site_that_does_not_share_comments_is_unsupported(): void {
		$this->mock_response( 'https://plain.example/page/', '<html><head></head><body>Hello</body></html>', 200, array( 'content-type' => 'text/html' ) );

		$plain = Daymark_Remote_Replies::for_permalink( 'https://plain.example/page/' );

		$this->assertSame( Daymark_Remote_Replies::STATUS_UNSUPPORTED, $plain['status'] );
		$this->assertSame( '', $plain['source'] );

		$permalink = 'https://origin.example/private-comments/';
		$this->mock_wordpress_post( $permalink, 56 );
		$this->mock_response( $this->wordpress_comments_url( 56 ), '{"code":"rest_cannot_read"}', 401 );

		$private = Daymark_Remote_Replies::for_permalink( $permalink );

		$this->assertSame( Daymark_Remote_Replies::STATUS_UNSUPPORTED, $private['status'] );
		$this->assertSame( Daymark_Remote_Replies::SOURCE_WORDPRESS, $private['source'] );
	}

	/** A result is cached, so opening the same post again makes no request. */
	public function test_results_are_cached(): void {
		$permalink = 'https://origin.example/cached/';

		$this->mock_wordpress_post( $permalink, 57 );
		$this->mock_response( $this->wordpress_comments_url( 57 ), '[]', 200, array( 'x-wp-total' => '0' ) );

		$this->assertNull( Daymark_Remote_Replies::cached( $permalink ) );

		$first    = Daymark_Remote_Replies::for_permalink( $permalink );
		$requests = count( $this->requested );

		$this->assertSame( $first, Daymark_Remote_Replies::cached( $permalink ) );
		$this->assertSame( $first, Daymark_Remote_Replies::for_permalink( $permalink ) );
		$this->assertCount( $requests, $this->requested, 'The second load used the cache' );

		$timeout = (int) get_option( '_transient_timeout_daymark_replies_' . md5( $permalink ) );
		$this->assertGreaterThanOrEqual( time() + 9 * MINUTE_IN_SECONDS, $timeout );
	}

	/** A site that fails is cached for one minute only, so a retry soon after can succeed. */
	public function test_a_failing_site_is_cached_briefly(): void {
		$permalink = 'https://origin.example/failing/';

		$this->mock_wordpress_post( $permalink, 58 );
		$this->mock_response( $this->wordpress_comments_url( 58 ), 'Oops', 500 );

		$result = Daymark_Remote_Replies::for_permalink( $permalink );

		$this->assertSame( Daymark_Remote_Replies::STATUS_ERROR, $result['status'] );

		$timeout = (int) get_option( '_transient_timeout_daymark_replies_' . md5( $permalink ) );
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, $timeout );
	}

	/** An answer that isn't a list of comments is an error, not an empty list. */
	public function test_a_response_that_is_not_a_list_is_an_error(): void {
		$permalink = 'https://origin.example/odd/';

		$this->mock_wordpress_post( $permalink, 59 );
		$this->mock_response( $this->wordpress_comments_url( 59 ), '{"comments":[]}' );

		$this->assertSame( Daymark_Remote_Replies::STATUS_ERROR, Daymark_Remote_Replies::for_permalink( $permalink )['status'] );
	}

	/** A permalink the URL guard refuses is never requested. */
	public function test_an_unsafe_permalink_makes_no_request(): void {
		$filter = static function () {
			return array( '10.0.0.5' );
		};
		add_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$result = Daymark_Remote_Replies::for_permalink( 'https://internal.example/@ana/444' );

		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', $filter );

		$this->assertSame( Daymark_Remote_Replies::STATUS_UNSUPPORTED, $result['status'] );
		$this->assertSame( array(), $this->requested );
	}

	/** A permalink that isn't http(s), or is empty, is unsupported without a request. */
	public function test_a_non_web_permalink_makes_no_request(): void {
		$this->assertSame( Daymark_Remote_Replies::STATUS_UNSUPPORTED, Daymark_Remote_Replies::for_permalink( '' )['status'] );
		$this->assertSame( Daymark_Remote_Replies::STATUS_UNSUPPORTED, Daymark_Remote_Replies::for_permalink( 'ftp://origin.example/post' )['status'] );
		$this->assertSame( array(), $this->requested );
	}

	/** The comments URL works under a pretty REST root and a plain one. */
	public function test_comments_url_handles_pretty_and_plain_roots(): void {
		$this->assertSame(
			'https://origin.example/wp-json/wp/v2/comments?post=5&_fields=id%2Cparent',
			Daymark_Remote_Replies::comments_url(
				'https://origin.example/wp-json/',
				array(
					'post'    => 5,
					'_fields' => 'id,parent',
				)
			)
		);
		$this->assertSame(
			'https://origin.example/?rest_route=/wp/v2/comments&post=5',
			Daymark_Remote_Replies::comments_url( 'https://origin.example/?rest_route=/', array( 'post' => 5 ) )
		);
		$this->assertSame( 'https://origin.example/wp-json/wp/v2/comments', Daymark_Remote_Replies::comments_url( 'https://origin.example/wp-json', array() ) );
	}

	/** A relative REST root from a page is made absolute against that page. */
	public function test_absolute_root_resolves_a_relative_root(): void {
		$this->assertSame( 'https://origin.example/wp-json/', Daymark_Remote_Replies::absolute_root( '/wp-json/', 'https://origin.example/2026/10/a-post/' ) );
		$this->assertSame( 'https://other.example/wp-json/', Daymark_Remote_Replies::absolute_root( 'https://other.example/wp-json/', 'https://origin.example/a/' ) );
		$this->assertSame( '', Daymark_Remote_Replies::absolute_root( '', 'https://origin.example/a/' ) );
	}

	/**
	 * A normalized reply for thread().
	 *
	 * @param string $id        Reply ID.
	 * @param string $parent_id Parent ID.
	 * @param int    $ts        Timestamp.
	 * @return array<string, mixed>
	 */
	private function reply( string $id, string $parent_id, int $ts ): array {
		return array(
			'id'     => $id,
			'parent' => $parent_id,
			'_ts'    => $ts,
		);
	}

	/** Threading places every reply exactly once, even when parents form a loop. */
	public function test_threading_never_loops(): void {
		$replies = array(
			$this->reply( 'a', 'b', 3 ),
			$this->reply( 'b', 'a', 2 ),
			$this->reply( 'c', '', 1 ),
			$this->reply( 'd', 'd', 4 ),
			$this->reply( 'e', 'missing', 5 ),
		);

		$threaded = Daymark_Remote_Replies::thread( $replies, 'root' );
		$ids      = array_column( $threaded, 'id' );

		$this->assertCount( 5, $threaded );
		$this->assertSame( array( 'a', 'b', 'c', 'd', 'e' ), array_values( array_unique( array_merge( array( 'a', 'b', 'c', 'd', 'e' ), $ids ) ) ) );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'No reply is placed twice' );
		$this->assertSame( array( 'c', 'd', 'e' ), array_slice( $ids, 0, 3 ), 'Top-level replies, oldest first; a self-parent or a missing parent is top level' );

		foreach ( $threaded as $reply ) {
			$this->assertArrayNotHasKey( '_ts', $reply );
		}
	}

	/** Deep replies are shown at the deepest level, under their parent. */
	public function test_threading_clamps_depth(): void {
		$replies = array(
			$this->reply( '1', '0', 1 ),
			$this->reply( '2', '1', 2 ),
			$this->reply( '3', '2', 3 ),
			$this->reply( '4', '3', 4 ),
			$this->reply( '5', '4', 5 ),
		);

		$threaded = Daymark_Remote_Replies::thread( $replies, '0' );

		$this->assertSame( array( '1', '2', '3', '4', '5' ), array_column( $threaded, 'id' ) );
		$this->assertSame( array( 0, 1, 2, 3, 3 ), array_column( $threaded, 'depth' ) );
	}

	/** Reply HTML keeps a few text tags; images become their alt text and links open safely in a new tab. */
	public function test_reply_html_is_reduced_to_text_tags(): void {
		$html = Daymark_Remote_Replies::clean_html(
			'<p>Hi <img src="https://cdn.example/wave.png" alt=":wave:" class="emoji"> <a href="/about" class="u-url mention">@ana</a> and <a href="javascript:alert(1)">click</a><script>alert(2)</script> <span style="color:red">red</span></p>',
			'https://origin.example/2026/10/a-post/'
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( ':wave:', $html );
		$this->assertStringContainsString( 'href="https://origin.example/about"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="nofollow noopener noreferrer ugc"', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringNotContainsString( 'class=', $html );
	}

	/** Mastodon's hidden parts of a long link are dropped, as a reader sees it. */
	public function test_mastodon_link_shortening_is_kept(): void {
		$html = Daymark_Remote_Replies::clean_html(
			'<p><a href="https://example.com/very/long/path"><span class="invisible">https://</span><span class="ellipsis">example.com/very/</span><span class="invisible">long/path</span></a></p>',
			'https://mastodon.example/'
		);

		$this->assertSame( 'example.com/very/…', Daymark_Remote_Replies::plain( $html, 100 ) );
	}

	/** A very long reply becomes a plain-text excerpt. */
	public function test_a_very_long_reply_becomes_an_excerpt(): void {
		$html = Daymark_Remote_Replies::clean_html( '<p>' . str_repeat( 'word <em>word</em> ', 300 ) . '</p>', 'https://origin.example/' );

		$this->assertStringStartsWith( '<p>word word', $html );
		$this->assertStringEndsWith( '…</p>', $html );
		$this->assertStringNotContainsString( '<em>', $html );
	}

	/** Only the replies Daymark sent for you are flagged as yours. */
	public function test_mark_own_flags_only_listed_ids(): void {
		$marked = Daymark_Remote_Replies::mark_own(
			array(
				array( 'id' => '5' ),
				array( 'id' => '6' ),
			),
			array( '6', '99' )
		);

		$this->assertFalse( $marked[0]['is_own'] );
		$this->assertTrue( $marked[1]['is_own'] );
	}

	/** Dates without a zone are UTC; unreadable dates are 0. */
	public function test_timestamp_reads_dates(): void {
		$expected = strtotime( '2026-10-01T10:00:00Z' );

		$this->assertSame( $expected, Daymark_Remote_Replies::timestamp( '2026-10-01T10:00:00' ) );
		$this->assertSame( $expected, Daymark_Remote_Replies::timestamp( '2026-10-01T10:00:00.000Z' ) );
		$this->assertSame( $expected, Daymark_Remote_Replies::timestamp( '2026-10-01T12:00:00+02:00' ) );
		$this->assertSame( 0, Daymark_Remote_Replies::timestamp( '' ) );
		$this->assertSame( 0, Daymark_Remote_Replies::timestamp( 'not a date' ) );
	}

	/** The number of replies loaded stays between 1 and 100. */
	public function test_max_replies_is_clamped(): void {
		$this->assertSame( 50, Daymark_Remote_Replies::max_replies() );

		$many = static function () {
			return 500;
		};
		add_filter( 'daymark_remote_replies_max', $many );
		$this->assertSame( 100, Daymark_Remote_Replies::max_replies() );
		remove_filter( 'daymark_remote_replies_max', $many );

		add_filter( 'daymark_remote_replies_max', '__return_zero' );
		$this->assertSame( 1, Daymark_Remote_Replies::max_replies() );
		remove_filter( 'daymark_remote_replies_max', '__return_zero' );
	}
}
