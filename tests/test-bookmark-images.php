<?php
/**
 * Off-site images for offline bookmarks (issue #455):
 * Daymark_Bookmark_Images and GET /daymark/v1/bookmarks/{id}/image.
 *
 * @package Daymark
 */

/**
 * Exercises the bookmark image fetch and its REST route.
 */
class Test_Bookmark_Images extends WP_UnitTestCase {

	/**
	 * Current user.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Body the mocked image host returns.
	 *
	 * @var string
	 */
	private $image_body = '';

	/**
	 * URLs requested through the mock.
	 *
	 * @var string[]
	 */
	private $requested = array();

	public function set_up(): void {
		parent::set_up();

		$this->user_id    = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->image_body = (string) file_get_contents( __DIR__ . '/e2e/fixtures/test-image.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->requested  = array();
		wp_set_current_user( $this->user_id );

		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		add_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		remove_all_filters( 'daymark_bookmark_image_max_bytes' );
		parent::tear_down();
	}

	/**
	 * A public IP for every hostname, so the URL guard doesn't refuse a
	 * mocked host for resolving nowhere.
	 *
	 * @return string[]
	 */
	public function public_address(): array {
		return array( '93.184.216.34' );
	}

	/**
	 * Answer every request with $this->image_body.
	 *
	 * @param mixed  $preempt Existing short-circuit value.
	 * @param array  $args    Request args.
	 * @param string $url     Requested URL.
	 * @return array<string, mixed>
	 */
	public function mock_http( $preempt, $args, $url ) {
		unset( $preempt, $args );
		$this->requested[] = $url;

		return array(
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => $this->image_body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A subscription post whose cached body holds the given image URL.
	 *
	 * @param string $src Image src as it appears in the markup.
	 * @return int
	 */
	private function subscription_post( string $src ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, 'body_content', '<p>Hello</p><img src="' . $src . '" alt="">' );

		return $post_id;
	}

	/**
	 * GET /bookmarks/{id}/image.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @return WP_REST_Response
	 */
	private function request_image( int $post_id, string $url ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/bookmarks/' . $post_id . '/image' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'url', $url );

		return rest_do_request( $request );
	}

	public function test_returns_a_bookmarked_posts_off_site_image() {
		$post_id = $this->subscription_post( 'https://cdn.example.com/photo.png' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$response = $this->request_image( $post_id, 'https://cdn.example.com/photo.png' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'image/png', $data['mime'] );
		$this->assertSame( $this->image_body, base64_decode( $data['data'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the transport encoding under test.
	}

	/** Only a post the current user bookmarked is served, and nothing is fetched otherwise. */
	public function test_refuses_a_post_that_is_not_bookmarked() {
		$post_id = $this->subscription_post( 'https://cdn.example.com/photo.png' );

		$response = $this->request_image( $post_id, 'https://cdn.example.com/photo.png' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array(), $this->requested );
	}

	/** It is not an open proxy: the URL must be one of the post's own images. */
	public function test_refuses_a_url_that_is_not_in_the_post() {
		$post_id = $this->subscription_post( 'https://cdn.example.com/photo.png' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$response = $this->request_image( $post_id, 'https://elsewhere.example/other.png' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array(), $this->requested );
	}

	/** A URL with a query string matches its `&amp;`-encoded form in the markup. */
	public function test_matches_an_ampersand_encoded_src() {
		$post_id = $this->subscription_post( 'https://cdn.example.com/photo.png?w=800&amp;h=600' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$response = $this->request_image( $post_id, 'https://cdn.example.com/photo.png?w=800&h=600' );

		$this->assertSame( 200, $response->get_status() );
	}

	/** An image in a Mark's own rendered content works too. */
	public function test_works_for_a_mark() {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>A post</p><img src="https://cdn.example.com/inline.png" alt="">',
			)
		);
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$this->assertSame( 200, $this->request_image( $post_id, 'https://cdn.example.com/inline.png' )->get_status() );
	}

	/** A private address is refused by the URL guard before any request. */
	public function test_refuses_an_unsafe_address() {
		remove_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		$post_id = $this->subscription_post( 'http://127.0.0.1/photo.png' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$response = $this->request_image( $post_id, 'http://127.0.0.1/photo.png' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->requested );
	}

	/** Something that isn't a raster image (here an SVG) is refused, whatever its Content-Type. */
	public function test_refuses_a_non_image_response() {
		$this->image_body = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$post_id          = $this->subscription_post( 'https://cdn.example.com/photo.png' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$this->assertSame( 415, $this->request_image( $post_id, 'https://cdn.example.com/photo.png' )->get_status() );
	}

	/** An image over the size cap is refused rather than saved truncated. */
	public function test_refuses_an_image_over_the_size_cap() {
		add_filter(
			'daymark_bookmark_image_max_bytes',
			static function () {
				return 10;
			}
		);
		$post_id = $this->subscription_post( 'https://cdn.example.com/photo.png' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$this->assertSame( 413, $this->request_image( $post_id, 'https://cdn.example.com/photo.png' )->get_status() );
	}

	/** A non-http(s) URL is refused. */
	public function test_refuses_a_non_http_url() {
		$post_id = $this->subscription_post( 'ftp://cdn.example.com/photo.png' );
		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $post_id );

		$this->assertSame( 400, $this->request_image( $post_id, 'ftp://cdn.example.com/photo.png' )->get_status() );
	}
}
