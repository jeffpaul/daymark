<?php
/**
 * REST tests for GET /daymark/v1/subscription-posts/{id}/replies (issue
 * #559): the replies on a followed post, read from its own site.
 *
 * All HTTP is mocked via `pre_http_request`.
 *
 * @package Daymark
 */

/**
 * Exercises the followed-post replies REST endpoint.
 */
class Test_Rest_Subscription_Post_Replies extends WP_UnitTestCase {

	/** @var int */
	private int $user_id;

	/**
	 * URL => canned response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->user_id        = (int) self::factory()->user->create( array( 'role' => 'author' ) );

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

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string $url          URL to mock.
	 * @param string $body         Response body.
	 * @param string $content_type Content type.
	 * @return void
	 */
	private function mock_response( string $url, string $body, string $content_type = 'application/json' ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => $content_type ),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A followed WordPress post whose comments the mock serves.
	 *
	 * @param string                           $permalink Origin permalink.
	 * @param int                              $remote    Its post ID there.
	 * @param array<int, array<string, mixed>> $comments  What the comments API returns.
	 * @return int Subscription post ID.
	 */
	private function followed_wordpress_post( string $permalink, int $remote, array $comments ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'An origin post',
			)
		);
		update_post_meta( $post_id, 'permalink', $permalink );

		$this->mock_response(
			$permalink,
			'<html><head><link rel="https://api.w.org/" href="https://origin.example/wp-json/"><link rel="alternate" type="application/json" href="https://origin.example/wp-json/wp/v2/posts/' . $remote . '"></head><body></body></html>',
			'text/html; charset=UTF-8'
		);
		$this->mock_comments( $remote, $comments );

		return $post_id;
	}

	/**
	 * What the origin's comments API returns for a post.
	 *
	 * @param int                              $remote   Its post ID there.
	 * @param array<int, array<string, mixed>> $comments The comments.
	 * @return void
	 */
	private function mock_comments( int $remote, array $comments ): void {
		$this->mock_response(
			Daymark_Remote_Replies::comments_url(
				'https://origin.example/wp-json/',
				array(
					'post'     => $remote,
					'per_page' => Daymark_Remote_Replies::max_replies(),
					'orderby'  => 'date_gmt',
					'order'    => 'desc',
					'_fields'  => Daymark_Remote_Replies::COMMENT_FIELDS,
				)
			),
			(string) wp_json_encode( $comments )
		);
	}

	/**
	 * A comment as the comments API returns it.
	 *
	 * @param int    $id        Comment ID.
	 * @param int    $parent_id Parent comment ID.
	 * @param string $author    Author name.
	 * @param string $date      GMT date.
	 * @return array<string, mixed>
	 */
	private function comment( int $id, int $parent_id, string $author, string $date ): array {
		return array(
			'id'          => $id,
			'parent'      => $parent_id,
			'author_name' => $author,
			'author_url'  => 'https://' . strtolower( $author ) . '.example/',
			'date_gmt'    => $date,
			'link'        => 'https://origin.example/post/#comment-' . $id,
			'content'     => array( 'rendered' => '<p>From ' . $author . '</p>' ),
		);
	}

	/**
	 * @param int $post_id Subscription post ID.
	 * @return WP_REST_Response
	 */
	private function get_replies( int $post_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', "/daymark/v1/subscription-posts/{$post_id}/replies" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	/** An unauthenticated request is rejected. */
	public function test_requires_authentication(): void {
		$this->assertSame( 401, $this->get_replies( 123 )->get_status() );
	}

	/** Only followed posts have replies to load. */
	public function test_a_post_that_is_not_followed_is_not_found(): void {
		wp_set_current_user( $this->user_id );

		$this->assertSame( 404, $this->get_replies( (int) self::factory()->post->create() )->get_status() );
	}

	/** Replies come back threaded, and only the comment Daymark sent for you is "yours". */
	public function test_returns_replies_with_your_own_comment_marked(): void {
		wp_set_current_user( $this->user_id );

		$post_id = $this->followed_wordpress_post(
			'https://origin.example/post/',
			55,
			array(
				$this->comment( 12, 9, 'Ana', '2026-10-01T11:00:00' ),
				$this->comment( 9, 0, 'You', '2026-10-01T10:00:00' ),
				// Someone typing your name and address isn't you.
				$this->comment( 8, 0, 'You', '2026-10-01T09:00:00' ),
			)
		);
		Daymark_Comment_Replies::start_watch(
			$this->user_id,
			$post_id,
			array(
				'root'    => 'https://origin.example/wp-json/',
				'remote'  => 55,
				'comment' => 9,
			)
		);

		$response = $this->get_replies( $post_id );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( Daymark_Remote_Replies::SOURCE_WORDPRESS, $data['source'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 'https://origin.example/post/#comments', $data['url'] );
		$this->assertSame( array( '8', '9', '12' ), wp_list_pluck( $data['replies'], 'id' ) );
		$this->assertSame( array( 0, 0, 1 ), wp_list_pluck( $data['replies'], 'depth' ) );
		$this->assertSame( array( false, true, false ), wp_list_pluck( $data['replies'], 'is_own' ) );
	}

	/** Another person's watched comment doesn't make it yours. */
	public function test_someone_elses_comment_is_not_yours(): void {
		$other = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );

		$post_id = $this->followed_wordpress_post(
			'https://origin.example/other-post/',
			56,
			array( $this->comment( 9, 0, 'Bo', '2026-10-01T10:00:00' ) )
		);
		Daymark_Comment_Replies::start_watch(
			$other,
			$post_id,
			array(
				'root'    => 'https://origin.example/wp-json/',
				'remote'  => 56,
				'comment' => 9,
			)
		);

		$this->assertSame( array( false ), wp_list_pluck( $this->get_replies( $post_id )->get_data()['replies'], 'is_own' ) );
	}

	/** Loading replies already looked up is free; only a real lookup spends the allowance. */
	public function test_only_a_real_lookup_spends_the_allowance(): void {
		wp_set_current_user( $this->user_id );
		add_filter(
			'daymark_rate_limits',
			static function ( $all ) {
				$all = is_array( $all ) ? $all : array();
				$all[ Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_OPEN ] = array(
					'limit'  => 1,
					'window' => 5 * MINUTE_IN_SECONDS,
				);

				return $all;
			}
		);

		$first  = $this->followed_wordpress_post( 'https://origin.example/first/', 57, array() );
		$second = $this->followed_wordpress_post( 'https://origin.example/second/', 58, array() );

		$this->assertSame( 200, $this->get_replies( $first )->get_status(), 'The first lookup fits the allowance' );
		$this->assertSame( 200, $this->get_replies( $first )->get_status(), 'Asking again is served from cache, for free' );
		$this->assertSame( 429, $this->get_replies( $second )->get_status(), 'A new lookup past the allowance is refused' );
	}

	/** Replies cached before you comment are dropped, so your comment shows on the next load. */
	public function test_commenting_drops_the_cached_replies(): void {
		wp_set_current_user( $this->user_id );

		$permalink = 'https://origin.example/commented/';
		$post_id   = $this->followed_wordpress_post(
			$permalink,
			59,
			array( $this->comment( 20, 0, 'Bo', '2026-10-01T10:00:00' ) )
		);

		$this->assertSame( array( '20' ), wp_list_pluck( $this->get_replies( $post_id )->get_data()['replies'], 'id' ) );

		// The site accepts the comment when Daymark sends it, and lists it.
		$this->mock_comments(
			59,
			array(
				$this->comment( 21, 0, 'You', '2026-10-01T11:00:00' ),
				$this->comment( 20, 0, 'Bo', '2026-10-01T10:00:00' ),
			)
		);
		$this->mock_response(
			'https://origin.example/wp-json/wp/v2/comments',
			(string) wp_json_encode(
				array(
					'id'     => 21,
					'status' => 'approved',
				)
			)
		);

		$request = new WP_REST_Request( 'POST', "/daymark/v1/subscription-posts/{$post_id}/comment" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'text', 'Lovely post.' );
		$sent = rest_do_request( $request );

		$this->assertSame( 200, $sent->get_status() );
		$this->assertSame( 'native', $sent->get_data()['method'] );
		$this->assertSame( 'published', $sent->get_data()['status'] );

		$replies = $this->get_replies( $post_id )->get_data()['replies'];

		$this->assertSame( array( '20', '21' ), wp_list_pluck( $replies, 'id' ) );
		$this->assertSame( array( false, true ), wp_list_pluck( $replies, 'is_own' ) );
	}
}
