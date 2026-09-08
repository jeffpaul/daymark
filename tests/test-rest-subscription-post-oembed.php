<?php
/**
 * REST tests for GET /daymark/v1/subscription-posts/{id}/oembed (issue
 * #279) — the best-effort oEmbed preview endpoint for a link-format
 * subscription post's own detected outbound link.
 *
 * The network side of Daymark_Subscription_Oembed::resolve() is
 * short-circuited via `pre_oembed_result` (see tests/test-subscription-oembed.php's
 * own docblock for why) rather than mocking the two separate HTTP requests
 * WP core's own oEmbed discovery makes internally.
 *
 * @package Daymark
 */

/**
 * Exercises the subscription post oEmbed REST endpoint.
 */
class Test_Rest_Subscription_Post_Oembed extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/**
	 * URL => canned HTML `pre_oembed_result` should return for it.
	 *
	 * @var array<string, string|false>
	 */
	private array $oembed_results = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author_a       = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriptions  = new Daymark_Subscriptions();
		$this->oembed_results = array();

		add_filter( 'pre_oembed_result', array( $this, 'intercept_oembed_result' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_oembed_result', array( $this, 'intercept_oembed_result' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $result Existing short-circuit value (always null here).
	 * @param string $url    URL being resolved.
	 * @return mixed
	 */
	public function intercept_oembed_result( $result, $url ) {
		return array_key_exists( $url, $this->oembed_results ) ? $this->oembed_results[ $url ] : false;
	}

	/**
	 * Create a `daymark_subscription_post`, optionally with a link_url.
	 *
	 * @param int    $subscription_id Owning subscription ID.
	 * @param string $link_url        Detected outbound link, if any.
	 * @return int
	 */
	private function create_subscription_post( int $subscription_id, string $link_url = '' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'A Link Post',
				'post_excerpt' => 'Worth a read.',
			)
		);

		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', 'https://example.com/notes/' . $post_id );
		update_post_meta( $post_id, 'post_format', 'standard' );
		update_post_meta( $post_id, 'link_url', $link_url );

		return $post_id;
	}

	/**
	 * @param int $post_id `daymark_subscription_post` ID.
	 * @return WP_REST_Request
	 */
	private function request_for( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', "/daymark/v1/subscription-posts/{$post_id}/oembed" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** An unauthenticated request is rejected. */
	public function test_requires_authentication() {
		$response = rest_do_request( $this->request_for( 123 ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/** A post with a resolvable link_url returns its extracted preview. */
	public function test_returns_resolved_preview() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);
		$post_id         = $this->create_subscription_post( $subscription_id, 'https://social.example/@person/1' );

		$this->oembed_results['https://social.example/@person/1'] = '<iframe src="https://social.example/@person/1/embed" width="400" height="200"></iframe>';

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'iframe', $data['type'] );
		$this->assertStringContainsString( 'src="https://social.example/@person/1/embed"', $data['html'] );
	}

	/** A post with no link_url at all returns an empty result, not an error. */
	public function test_returns_empty_when_no_link_url() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed2/',
			)
		);
		$post_id         = $this->create_subscription_post( $subscription_id, '' );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['html'] );
	}

	/** A link_url with no embeddable oEmbed result also resolves to empty, not an error. */
	public function test_returns_empty_when_link_has_no_embed() {
		wp_set_current_user( $this->author_a );

		$subscription_id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed3/',
			)
		);
		$post_id         = $this->create_subscription_post( $subscription_id, 'https://unknown.example/post' );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['html'] );
	}
}
