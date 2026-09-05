<?php
/**
 * REST tests for Bookmarks (issue #193): the POST/DELETE
 * /daymark/v1/bookmarks/{id} toggle routes, the `bookmarked` field on GET
 * /timeline's Mark/subscription-post items, and the `bookmarked` filter on
 * GET /timeline itself.
 *
 * @package Daymark
 */

/**
 * Exercises the REST toggle routes and GET /timeline's bookmarked field/filter.
 */
class Test_Rest_Bookmarks extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var int */
	private $author_b;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author_a      = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->author_b      = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriptions = new Daymark_Subscriptions();
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * Create a published Mark post.
	 *
	 * @param int $author Post author.
	 * @return int
	 */
	private function create_mark( int $author ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );

		return $post_id;
	}

	/**
	 * Create a cached `daymark_subscription_post`.
	 *
	 * @return int
	 */
	private function create_subscription_post(): int {
		$subscription_id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/' . wp_generate_password( 8, false ),
			)
		);

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', 'https://example.com/post/' );

		return $post_id;
	}

	public function test_post_bookmarks_a_mark() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_mark( $this->author_a );

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/bookmarks/{$post_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['bookmarked'] );
		$this->assertTrue( Daymark_Plugin::instance()->bookmarks->is_bookmarked( $this->author_a, $post_id ) );
	}

	public function test_delete_removes_a_bookmark() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_mark( $this->author_a );
		Daymark_Plugin::instance()->bookmarks->add( $this->author_a, $post_id );

		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/bookmarks/{$post_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['bookmarked'] );
		$this->assertFalse( Daymark_Plugin::instance()->bookmarks->is_bookmarked( $this->author_a, $post_id ) );
	}

	/** Bookmarking isn't gated on Mark ownership — anything visible on this user's own Timeline is fair game. */
	public function test_can_bookmark_another_authors_mark() {
		wp_set_current_user( $this->author_b );
		$post_id = $this->create_mark( $this->author_a );

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/bookmarks/{$post_id}" ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_can_bookmark_a_subscription_post() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/bookmarks/{$post_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( Daymark_Plugin::instance()->bookmarks->is_bookmarked( $this->author_a, $post_id ) );
	}

	public function test_draft_mark_returns_404() {
		wp_set_current_user( $this->author_a );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $this->author_a,
				'post_status' => 'draft',
			)
		);

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/bookmarks/{$post_id}" ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_nonexistent_post_returns_404() {
		wp_set_current_user( $this->author_a );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/bookmarks/999999' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_unrelated_post_type_returns_404() {
		wp_set_current_user( $this->author_a );
		$attachment_id = (int) self::factory()->attachment->create( array( 'post_status' => 'publish' ) );

		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/bookmarks/{$attachment_id}" ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_unauthenticated_request_returns_401() {
		wp_set_current_user( 0 );
		$post_id = $this->create_mark( $this->author_a );

		$request  = new WP_REST_Request( 'POST', "/daymark/v1/bookmarks/{$post_id}" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_timeline_item_reports_bookmarked_true() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_mark( $this->author_a );
		Daymark_Plugin::instance()->bookmarks->add( $this->author_a, $post_id );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$this->assertTrue( $items[0]['bookmarked'] );
	}

	public function test_timeline_item_reports_bookmarked_false_by_default() {
		wp_set_current_user( $this->author_a );
		$this->create_mark( $this->author_a );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$this->assertFalse( $items[0]['bookmarked'] );
	}

	/** A bookmark is per-user: it never leaks into another user's own Timeline view. */
	public function test_bookmarked_flag_is_scoped_per_user() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_mark( $this->author_a );
		Daymark_Plugin::instance()->bookmarks->add( $this->author_a, $post_id );

		wp_set_current_user( $this->author_b );
		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();

		$this->assertFalse( $items[0]['bookmarked'] );
	}

	public function test_bookmarked_filter_returns_only_bookmarked_marks() {
		wp_set_current_user( $this->author_a );
		$bookmarked_id = $this->create_mark( $this->author_a );
		$this->create_mark( $this->author_a );
		Daymark_Plugin::instance()->bookmarks->add( $this->author_a, $bookmarked_id );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline?bookmarked=1' ) );
		$items    = $response->get_data();

		$this->assertCount( 1, $items );
		$this->assertSame( $bookmarked_id, $items[0]['id'] );
	}

	public function test_bookmarked_filter_includes_bookmarked_subscription_posts() {
		wp_set_current_user( $this->author_a );
		$this->create_mark( $this->author_a );
		$sub_post_id = $this->create_subscription_post();
		Daymark_Plugin::instance()->bookmarks->add( $this->author_a, $sub_post_id );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline?bookmarked=1' ) );
		$items    = $response->get_data();

		$this->assertCount( 1, $items );
		$this->assertSame( $sub_post_id, $items[0]['id'] );
		$this->assertSame( 'subscription_post', $items[0]['item_type'] );
	}

	public function test_bookmarked_filter_returns_nothing_when_no_bookmarks_exist() {
		wp_set_current_user( $this->author_a );
		$this->create_mark( $this->author_a );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline?bookmarked=1' ) );

		$this->assertSame( array(), $response->get_data() );
	}
}
