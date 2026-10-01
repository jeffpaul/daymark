<?php
/**
 * REST tests for the POST/DELETE /daymark/v1/subscription-posts/{id}/like
 * route pair (issue #391).
 *
 * The real Jetpack classes are never loaded in this test environment (see
 * Test_Jetpack_Engagement's own docblock), so every request here exercises
 * the classic fallback path unconditionally — Daymark_Jetpack_Engagement::
 * current_user_connected() is always false, so
 * Daymark_REST_Controller::maybe_jetpack_like() always returns null and
 * falls through. That's exactly the regression coverage this route needs:
 * confirming the pre-existing classic Like behavior (a minimal 'note' Mark
 * carrying `_daymark_like_of`, idempotent, undoable) is completely
 * unaffected by the new Jetpack-first branch.
 *
 * @package Daymark
 */

/**
 * Exercises like_subscription_post()/unlike_subscription_post().
 */
class Test_Rest_Subscription_Like extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var string */
	private $permalink = 'https://example.com/hello-world/';

	/** @var callable|null */
	private $webmention_filter = null;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author_a      = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriptions = new Daymark_Subscriptions();
	}

	public function tear_down(): void {
		if ( null !== $this->webmention_filter ) {
			remove_filter( 'option_active_plugins', $this->webmention_filter );
			$this->webmention_filter = null;
			$dir                     = WP_PLUGIN_DIR . '/webmention';
			wp_delete_file( $dir . '/webmention.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $dir );
		}

		parent::tear_down();
	}

	/**
	 * Fake the local Webmention plugin being active.
	 *
	 * @return void
	 */
	private function activate_fake_webmention_plugin(): void {
		$this->webmention_filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'webmention/webmention.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $this->webmention_filter );
		$dir = WP_PLUGIN_DIR . '/webmention';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/webmention.php', "<?php\n/**\n * Plugin Name: Fake Webmention\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_plugins_cache( false );
	}

	/**
	 * Seed Daymark_Comment_Delivery's cached origin signals for $this->permalink.
	 *
	 * @param string $endpoint Webmention endpoint the origin advertises ('' for none).
	 * @return void
	 */
	private function seed_origin_signals( string $endpoint ): void {
		set_transient(
			'daymark_comment_sig_' . md5( $this->permalink ),
			array(
				'webmention_endpoint' => $endpoint,
				'rest_root'           => '',
				'post_id'             => 0,
				'jetpack_site_id'     => 0,
				'jetpack_post_id'     => 0,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Make a Webmention-route Like deliverable for $this->permalink.
	 *
	 * @return void
	 */
	private function enable_webmention_route(): void {
		$this->activate_fake_webmention_plugin();
		$this->seed_origin_signals( 'https://example.com/webmention' );
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
				'post_title'  => 'Example Post',
			)
		);
		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', $this->permalink );

		return $post_id;
	}

	public function test_post_like_creates_a_classic_mark() {
		wp_set_current_user( $this->author_a );
		$this->enable_webmention_route();
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'classic', $data['method'] );
		$this->assertTrue( $data['liked'] );
		$this->assertGreaterThan( 0, $data['mark_id'] );

		$mark = get_post( $data['mark_id'] );
		$this->assertSame( 'publish', $mark->post_status );
		$this->assertSame( $this->permalink, get_post_meta( $mark->ID, '_daymark_like_of', true ) );
	}

	public function test_post_like_is_idempotent_on_a_duplicate_tap() {
		wp_set_current_user( $this->author_a );
		$this->enable_webmention_route();
		$post_id = $this->create_subscription_post();

		$first  = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		$second = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( $first->get_data()['mark_id'], $second->get_data()['mark_id'] );

		$marks = get_posts(
			array(
				'post_type'      => Daymark_Like_Visibility::POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => '_daymark_like_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $this->permalink, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertCount( 1, $marks );
	}

	public function test_delete_like_trashes_the_classic_mark() {
		wp_set_current_user( $this->author_a );
		$this->enable_webmention_route();
		$post_id = $this->create_subscription_post();

		$created  = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		$mark_id  = $created->get_data()['mark_id'];
		$response = rest_do_request( $this->request( 'DELETE', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		$data     = $response->get_data();

		$this->assertSame( 'classic', $data['method'] );
		$this->assertFalse( $data['liked'] );
		$this->assertSame( 'trash', get_post_status( $mark_id ) );
	}

	public function test_delete_like_with_nothing_to_undo_is_a_safe_no_op() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'DELETE', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['liked'] );
	}

	public function test_like_route_404s_for_a_non_subscription_post() {
		wp_set_current_user( $this->author_a );
		$mark_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $mark_id . '/like' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_timeline_reports_jetpack_liked_false_by_default() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );
		$items    = $response->get_data();
		$item     = null;
		foreach ( $items as $candidate ) {
			if ( ( $candidate['id'] ?? null ) === $post_id ) {
				$item = $candidate;
			}
		}

		$this->assertNotNull( $item );
		$this->assertFalse( $item['jetpack_liked'] );
		$this->assertFalse( $item['jetpack_commented'] );
	}

	public function test_post_like_is_refused_when_no_delivery_mechanism_exists() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'daymark_like_undeliverable', $response->get_data()['code'] );
		$this->assertSame( 0, $this->count_like_marks() );
	}

	public function test_post_like_is_refused_when_origin_advertises_no_webmention_endpoint() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();
		$this->activate_fake_webmention_plugin();
		$this->seed_origin_signals( '' );

		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 0, $this->count_like_marks() );
	}

	public function test_post_like_reports_pending_delivery_for_a_queued_webmention() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();
		$this->enable_webmention_route();

		$first = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		update_post_meta( $first->get_data()['mark_id'], '_mentionme', '1' );
		$second = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );

		$this->assertSame( 'pending', $second->get_data()['delivery'] );
	}

	public function test_like_availability_is_false_with_no_mechanism() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/subscription-posts/' . $post_id . '/like-availability' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['available'] );
	}

	public function test_like_availability_is_true_for_a_webmention_route() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();
		$this->enable_webmention_route();

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/subscription-posts/' . $post_id . '/like-availability' ) );

		$this->assertTrue( $response->get_data()['available'] );
		$this->assertSame( 'webmention', $response->get_data()['method'] );
	}

	public function test_like_availability_404s_for_a_non_subscription_post() {
		wp_set_current_user( $this->author_a );
		$mark_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/subscription-posts/' . $mark_id . '/like-availability' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_like_availability_requires_a_nonce() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/daymark/v1/subscription-posts/' . $post_id . '/like-availability' ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	public function test_timeline_reports_like_availability_from_cache_only() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();

		$this->assertFalse( $this->timeline_item( $post_id )['like_available'], 'No mechanism at all: known false.' );

		$this->activate_fake_webmention_plugin();
		$this->assertNull( $this->timeline_item( $post_id )['like_available'], 'Mechanism exists, origin not looked up yet: unknown.' );

		$this->seed_origin_signals( 'https://example.com/webmention' );
		$this->assertTrue( $this->timeline_item( $post_id )['like_available'] );
	}

	public function test_timeline_reports_like_delivery_state() {
		wp_set_current_user( $this->author_a );
		$post_id = $this->create_subscription_post();
		$this->enable_webmention_route();

		$created = rest_do_request( $this->request( 'POST', '/daymark/v1/subscription-posts/' . $post_id . '/like' ) );
		update_post_meta( $created->get_data()['mark_id'], '_webmentioned', array( untrailingslashit( $this->permalink ) ) );

		$item = $this->timeline_item( $post_id );
		$this->assertSame( 'sent', $item['like_delivery'] );
		$this->assertSame( '', $item['comment_delivery'] );
	}

	/**
	 * @param int $post_id Subscription post ID.
	 * @return array<string, mixed>
	 */
	private function timeline_item( int $post_id ): array {
		$response = rest_do_request( $this->request( 'GET', '/daymark/v1/timeline' ) );

		foreach ( (array) $response->get_data() as $candidate ) {
			if ( ( $candidate['id'] ?? null ) === $post_id && 'subscription_post' === ( $candidate['item_type'] ?? '' ) ) {
				return $candidate;
			}
		}

		$this->fail( 'Subscription post missing from the Timeline.' );
	}

	/**
	 * @return int Like Marks carrying $this->permalink.
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
}
