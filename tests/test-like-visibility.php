<?php
/**
 * Tests for Daymark_Like_Visibility: a Like Mark's own auto-published post
 * stays out of every public discovery surface except its own permalink.
 *
 * @package Daymark
 */

/**
 * Exercises the front-end query, REST, sitemap, and oEmbed exclusions.
 */
class Test_Like_Visibility extends WP_UnitTestCase {

	/**
	 * A plain, unregistered instance for the direct method-call tests below
	 * (exclude_from_sitemap()/suppress_oembed()) — pure functions with no
	 * hook side effects. The hook-driven tests (go_to()/rest_do_request())
	 * exercise the real, already-registered instance the plugin bootstraps
	 * once per test run (tests/bootstrap.php); registering a second one
	 * here would double up every filter it hooks.
	 *
	 * @var Daymark_Like_Visibility
	 */
	private $visibility;

	public function set_up(): void {
		parent::set_up();

		$this->visibility = new Daymark_Like_Visibility();
	}

	/**
	 * Create a published Mark, optionally carrying like_of/repost_of meta.
	 *
	 * @param array<string, string> $meta Extra post meta to set.
	 * @return int
	 */
	private function create_mark( array $meta = array() ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'A Mark',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/** A Like Mark never appears in the site's own home-page query. */
	public function test_like_mark_excluded_from_home_query() {
		$ordinary_id = $this->create_mark();
		$like_id     = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		$this->go_to( home_url( '/' ) );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertContains( $ordinary_id, $ids );
		$this->assertNotContains( $like_id, $ids );
	}

	/**
	 * A Repost Mark is real, publishable content and must stay completely
	 * unaffected — this class is scoped to _daymark_like_of only.
	 */
	public function test_repost_mark_not_excluded_from_home_query() {
		$repost_id = $this->create_mark( array( '_daymark_repost_of' => 'https://example.com/post/' ) );

		$this->go_to( home_url( '/' ) );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertContains( $repost_id, $ids );
	}

	/** A Like Mark never appears in the site's own RSS/Atom feed. */
	public function test_like_mark_excluded_from_main_feed() {
		$ordinary_id = $this->create_mark();
		$like_id     = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		$this->go_to( home_url( '/feed/' ) );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertContains( $ordinary_id, $ids );
		$this->assertNotContains( $like_id, $ids );
	}

	/**
	 * The whole point: a Like Mark's own singular permalink page must stay
	 * completely reachable — Webmention verification depends on it.
	 */
	public function test_like_mark_reachable_at_its_own_permalink() {
		$like_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		$this->go_to( get_permalink( $like_id ) );

		$this->assertTrue( is_singular() );
		$this->assertSame( $like_id, get_the_ID() );
	}

	/** A Like Mark is excluded from GET /wp/v2/posts, even by explicit ID. */
	public function test_like_mark_excluded_from_rest_collection() {
		$ordinary_id = $this->create_mark();
		$like_id     = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'include', array( $ordinary_id, $like_id ) );
		$request->set_param( 'per_page', 50 );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $ordinary_id, $ids );
		$this->assertNotContains( $like_id, $ids );
	}

	/** A Repost Mark stays visible via GET /wp/v2/posts, unlike a Like Mark. */
	public function test_repost_mark_not_excluded_from_rest_collection() {
		$repost_id = $this->create_mark( array( '_daymark_repost_of' => 'https://example.com/post/' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'include', array( $repost_id ) );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $repost_id, $ids );
	}

	/** An anonymous single-item REST fetch of a Like Mark 404s. */
	public function test_like_mark_single_item_rest_blocked_for_anonymous() {
		$like_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $like_id );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The post's own author/editor can still fetch it via the single-item
	 * REST route — needed so wp-admin's own block editor keeps working.
	 */
	public function test_like_mark_single_item_rest_allowed_for_editor() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$like_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		wp_set_current_user( $editor );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $like_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/** A Like Mark is excluded from core's XML sitemap query args. */
	public function test_like_mark_excluded_from_sitemap_args() {
		$args = $this->visibility->exclude_from_sitemap( array(), 'post' );

		$this->assertSame(
			array(
				'key'     => '_daymark_like_of',
				'compare' => 'NOT EXISTS',
			),
			$args['meta_query'][0]
		);
	}

	/** A non-'post' post type's sitemap args are left untouched. */
	public function test_sitemap_args_untouched_for_other_post_types() {
		$args = $this->visibility->exclude_from_sitemap( array( 'foo' => 'bar' ), 'page' );

		$this->assertSame( array( 'foo' => 'bar' ), $args );
	}

	/** oEmbed is suppressed for a Like Mark. */
	public function test_oembed_suppressed_for_like_mark() {
		$like_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		$result = $this->visibility->suppress_oembed( array( 'some' => 'data' ), get_post( $like_id ) );

		$this->assertFalse( $result );
	}

	/** oEmbed is untouched for an ordinary Mark. */
	public function test_oembed_untouched_for_ordinary_mark() {
		$ordinary_id = $this->create_mark();

		$result = $this->visibility->suppress_oembed( array( 'some' => 'data' ), get_post( $ordinary_id ) );

		$this->assertSame( array( 'some' => 'data' ), $result );
	}
}
