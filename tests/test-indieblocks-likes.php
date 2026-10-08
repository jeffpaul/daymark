<?php
/**
 * Tests for reading Likes made with the IndieBlocks plugin
 * (Daymark_IndieBlocks_Likes) and how the Like routes treat them.
 *
 * IndieBlocks itself isn't loaded in CI, so each test registers a stand-in
 * `indieblocks_like` post type, the same way IndieBlocks registers it when
 * its Likes option is on, and stores the liked URL in the same meta key.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_IndieBlocks_Likes and the Like routes' handling of it.
 */
class Test_IndieBlocks_Likes extends WP_UnitTestCase {

	/** @var int */
	private $author_id;

	/** @var string */
	private $permalink = 'https://example.com/hello-world/';

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		register_post_type(
			Daymark_IndieBlocks_Likes::POST_TYPE,
			array(
				'public'   => true,
				'supports' => array( 'author', 'title', 'editor', 'custom-fields' ),
			)
		);

		$this->author_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author_id );
	}

	public function tear_down(): void {
		unregister_post_type( Daymark_IndieBlocks_Likes::POST_TYPE );

		parent::tear_down();
	}

	/**
	 * Create an IndieBlocks Like of a URL.
	 *
	 * @param string $url    Liked URL.
	 * @param int    $author Author ID (defaults to the test author).
	 * @param string $status Post status.
	 * @return int
	 */
	private function create_indieblocks_like( string $url, int $author = 0, string $status = 'publish' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_IndieBlocks_Likes::POST_TYPE,
				'post_status' => $status,
				'post_author' => $author > 0 ? $author : $this->author_id,
			)
		);
		update_post_meta( $id, Daymark_IndieBlocks_Likes::URL_META, $url );

		return $id;
	}

	/**
	 * Create a cached subscription post at $this->permalink.
	 *
	 * @return int
	 */
	private function create_subscription_post(): int {
		$subscription_id = ( new Daymark_Subscriptions() )->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/' . wp_generate_password( 8, false ),
			)
		);

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Example Post',
			)
		);
		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', $this->permalink );

		return $post_id;
	}

	/**
	 * An authenticated REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	/**
	 * @return int Daymark Like Marks carrying $this->permalink.
	 */
	private function count_like_marks(): int {
		return count(
			get_posts(
				array(
					'post_type'      => array( 'post', Daymark_Like_Visibility::POST_TYPE ),
					'post_status'    => 'any',
					'meta_key'       => '_daymark_like_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $this->permalink, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	public function test_finds_a_published_like_by_url() {
		$like_id = $this->create_indieblocks_like( $this->permalink );

		$this->assertSame( $like_id, Daymark_IndieBlocks_Likes::find_like_id( $this->author_id, $this->permalink ) );
	}

	public function test_matches_with_or_without_a_trailing_slash() {
		$like_id = $this->create_indieblocks_like( untrailingslashit( $this->permalink ) );

		$this->assertSame( $like_id, Daymark_IndieBlocks_Likes::find_like_id( $this->author_id, $this->permalink ) );
	}

	public function test_ignores_another_users_like() {
		$other = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->create_indieblocks_like( $this->permalink, $other );

		$this->assertSame( 0, Daymark_IndieBlocks_Likes::find_like_id( $this->author_id, $this->permalink ) );
	}

	public function test_ignores_a_draft_or_trashed_like() {
		$this->create_indieblocks_like( $this->permalink, 0, 'draft' );
		$this->create_indieblocks_like( $this->permalink, 0, 'trash' );

		$this->assertSame( 0, Daymark_IndieBlocks_Likes::find_like_id( $this->author_id, $this->permalink ) );
	}

	public function test_reads_nothing_when_the_post_type_is_not_registered() {
		$this->create_indieblocks_like( $this->permalink );
		unregister_post_type( Daymark_IndieBlocks_Likes::POST_TYPE );

		$this->assertFalse( Daymark_IndieBlocks_Likes::available() );
		$this->assertSame( 0, Daymark_IndieBlocks_Likes::find_like_id( $this->author_id, $this->permalink ) );
	}

	public function test_timeline_reports_the_indieblocks_like() {
		$like_id = $this->create_indieblocks_like( $this->permalink );
		$post_id = $this->create_subscription_post();

		$item = null;
		foreach ( (array) $this->dispatch( 'GET', '/daymark/v1/timeline' )->get_data() as $candidate ) {
			if ( ( $candidate['id'] ?? null ) === $post_id && 'subscription_post' === ( $candidate['item_type'] ?? '' ) ) {
				$item = $candidate;
			}
		}

		$this->assertNotNull( $item );
		$this->assertSame( $like_id, $item['indieblocks_like_id'] );
		$this->assertSame( 0, $item['liked_mark_id'] );
	}

	public function test_post_like_sends_nothing_when_already_liked_with_indieblocks() {
		$this->create_indieblocks_like( $this->permalink );
		$post_id = $this->create_subscription_post();

		$response = $this->dispatch( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'indieblocks', $data['method'] );
		$this->assertTrue( $data['liked'] );
		$this->assertSame( 0, $this->count_like_marks() );
	}

	public function test_delete_like_refuses_to_remove_an_indieblocks_like() {
		$like_id = $this->create_indieblocks_like( $this->permalink );
		$post_id = $this->create_subscription_post();

		$response = $this->dispatch( 'DELETE', '/daymark/v1/subscription-posts/' . $post_id . '/like' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'daymark_like_from_indieblocks', $response->get_data()['code'] );
		$this->assertSame( 'publish', get_post_status( $like_id ) );
	}

	public function test_delete_like_removes_daymarks_own_like_and_keeps_the_indieblocks_one() {
		$post_id = $this->create_subscription_post();
		$mark_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Like_Visibility::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);
		update_post_meta( $mark_id, '_daymark_like_of', $this->permalink );
		$like_id = $this->create_indieblocks_like( $this->permalink );

		$response = $this->dispatch( 'DELETE', '/daymark/v1/subscription-posts/' . $post_id . '/like' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['liked'] );
		$this->assertSame( 'indieblocks', $data['method'] );
		$this->assertSame( 'trash', get_post_status( $mark_id ) );
		$this->assertSame( 'publish', get_post_status( $like_id ) );
	}
}
