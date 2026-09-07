<?php
/**
 * REST tests for subscription-post engagement indicators (issue #41
 * follow-up): GET /timeline's `replied_mark_id`/`liked_mark_id`/
 * `reposted_mark_id` fields on a subscription-post item, and that
 * POST /marks with `like_of`/`repost_of` is what makes them non-zero.
 *
 * Full remote engagement counts aren't obtainable in general (no built-in
 * subscription source exposes a reliable like/repost count for someone
 * else's post) — these fields are Daymark's own buildable fallback: whether
 * *this* user has already published a Mark engaging with the post, not the
 * origin site's real totals.
 *
 * @package Daymark
 */

/**
 * Exercises the three engagement-lookup fields on a subscription-post
 * Timeline item.
 */
class Test_Rest_Subscription_Engagement extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var int */
	private $author_b;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var string */
	private $permalink = 'https://example.com/post/';

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
	 * Create a cached `daymark_subscription_post` at $this->permalink.
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
		update_post_meta( $post_id, 'permalink', $this->permalink );

		return $post_id;
	}

	/**
	 * Create a published Mark carrying the given POSSE target-URL meta.
	 *
	 * @param int    $author   Post author.
	 * @param string $meta_key '_daymark_in_reply_to', '_daymark_like_of', or '_daymark_repost_of'.
	 * @param string $url      Target URL.
	 * @return int
	 */
	private function create_engaging_mark( int $author, string $meta_key, string $url ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );
		update_post_meta( $post_id, $meta_key, $url );

		return $post_id;
	}

	/**
	 * Find the subscription-post item in a GET /timeline response by ID —
	 * Marks and subscription posts share one response array, so this can't
	 * just assume index 0 the way a single-item fixture would.
	 *
	 * @param array<int, array<string, mixed>> $items    Timeline response items.
	 * @param int                              $post_id  Subscription post ID.
	 * @return array<string, mixed>|null
	 */
	private function find_item( array $items, int $post_id ): ?array {
		foreach ( $items as $item ) {
			if ( ( $item['id'] ?? null ) === $post_id ) {
				return $item;
			}
		}

		return null;
	}

	public function test_engagement_fields_are_zero_by_default() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$item     = $this->find_item( $response->get_data(), $post_id );

		$this->assertSame( 0, $item['replied_mark_id'] );
		$this->assertSame( 0, $item['liked_mark_id'] );
		$this->assertSame( 0, $item['reposted_mark_id'] );
	}

	public function test_replied_mark_id_reports_own_reply() {
		wp_set_current_user( $this->author_a );
		$post_id  = $this->create_subscription_post();
		$reply_id = $this->create_engaging_mark( $this->author_a, '_daymark_in_reply_to', $this->permalink );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$item     = $this->find_item( $response->get_data(), $post_id );

		$this->assertSame( $reply_id, $item['replied_mark_id'] );
	}

	public function test_liked_mark_id_reports_own_like() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();
		$like_id = $this->create_engaging_mark( $this->author_a, '_daymark_like_of', $this->permalink );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$item     = $this->find_item( $response->get_data(), $post_id );

		$this->assertSame( $like_id, $item['liked_mark_id'] );
	}

	public function test_reposted_mark_id_reports_own_repost() {
		wp_set_current_user( $this->author_a );
		$post_id   = $this->create_subscription_post();
		$repost_id = $this->create_engaging_mark( $this->author_a, '_daymark_repost_of', $this->permalink );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$item     = $this->find_item( $response->get_data(), $post_id );

		$this->assertSame( $repost_id, $item['reposted_mark_id'] );
	}

	/** Another user's like of the same post never shows up as this user's own. */
	public function test_engagement_is_scoped_per_user() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();
		$this->create_engaging_mark( $this->author_b, '_daymark_like_of', $this->permalink );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$item     = $this->find_item( $response->get_data(), $post_id );

		$this->assertSame( 0, $item['liked_mark_id'] );
	}

	/** POST /marks with like_of is the flow that actually creates a detectable like Mark. */
	public function test_post_marks_with_like_of_makes_engagement_detectable() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$create_request = $this->request( 'POST', '/daymark/v1/marks' );
		$create_request->set_param( 'caption', 'Liked "Example Post"' );
		$create_request->set_param( 'primary_type', 'note' );
		$create_request->set_param( 'status', 'publish' );
		$create_request->set_param( 'like_of', $this->permalink );
		$create_response = rest_do_request( $create_request );

		$this->assertSame( 201, $create_response->get_status() );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$item     = $this->find_item( $response->get_data(), $post_id );

		$this->assertSame( $create_response->get_data()['id'], $item['liked_mark_id'] );
	}
}
