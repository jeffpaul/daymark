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

	/**
	 * A Like Mark is never auto-shared by Jetpack Social/Publicize (defense
	 * in depth against issue #389's reported external leak) — this filter
	 * would otherwise be untested since it needs an active Jetpack install
	 * to actually fire in a real request.
	 */
	public function test_publicize_suppressed_for_like_mark() {
		$like_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		$result = $this->visibility->suppress_publicize( true, get_post( $like_id ) );

		$this->assertFalse( $result );
	}

	/** Publicize's own decision is untouched for a Repost Mark. */
	public function test_publicize_untouched_for_repost_mark() {
		$repost_id = $this->create_mark( array( '_daymark_repost_of' => 'https://example.com/post/' ) );

		$result = $this->visibility->suppress_publicize( true, get_post( $repost_id ) );

		$this->assertTrue( $result );
	}

	/** Publicize's own decision is untouched for an ordinary Mark. */
	public function test_publicize_untouched_for_ordinary_mark() {
		$ordinary_id = $this->create_mark();

		$result = $this->visibility->suppress_publicize( true, get_post( $ordinary_id ) );

		$this->assertTrue( $result );
	}

	/**
	 * The static insert-time helper sets Jetpack Publicize's own historical
	 * "already handled" post meta flag — the first of the two suppression
	 * layers (see suppress_publicize() above for the second).
	 */
	public function test_suppress_publicize_on_insert_sets_meta_flag() {
		$post_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );

		Daymark_Like_Visibility::suppress_publicize_on_insert( $post_id );

		$this->assertSame( '1', get_post_meta( $post_id, '_wpas_done_all', true ) );
	}

	/**
	 * Publish through the real publisher as a user who can publish.
	 *
	 * @param array<string, mixed> $data Publish data.
	 * @return int
	 */
	private function publish_as_editor( array $data ): int {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );

		return (int) ( new Daymark_Publisher() )->publish( $data );
	}

	/** A Like published through the publisher lives on its own post type, never `post`. */
	public function test_published_like_uses_its_own_post_type() {
		$like_id = $this->publish_as_editor(
			array(
				'caption'      => 'Liked "A post somewhere"',
				'primary_type' => 'note',
				'like_of'      => 'https://example.com/original-post/',
			)
		);

		$this->assertSame( Daymark_Like_Visibility::POST_TYPE, get_post_type( $like_id ) );
		$this->assertEmpty( wp_get_post_categories( $like_id ) );
		$this->assertFalse( get_post_format( $like_id ) );
	}

	/**
	 * A Reblog stays an ordinary post — only Likes move.
	 */
	public function test_published_repost_stays_a_post() {
		$repost_id = $this->publish_as_editor(
			array(
				'caption'      => 'Reblogged',
				'primary_type' => 'note',
				'repost_of'    => 'https://example.com/original-post/',
			)
		);

		$this->assertSame( 'post', get_post_type( $repost_id ) );
	}

	/**
	 * The reported gap: a secondary query (a block theme's Query Loop, a
	 * Recent Posts widget) never sees a Like, not just the main query.
	 */
	public function test_published_like_absent_from_secondary_post_query() {
		$like_id = $this->publish_as_editor(
			array(
				'caption'      => 'Liked "A post somewhere"',
				'primary_type' => 'note',
				'like_of'      => 'https://example.com/original-post/',
			)
		);

		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertNotContains( $like_id, $ids );
	}

	/** Its own permalink still resolves for Webmention verification. */
	public function test_published_like_permalink_still_reachable() {
		$like_id = $this->publish_as_editor(
			array(
				'caption'      => 'Liked "A post somewhere"',
				'primary_type' => 'note',
				'like_of'      => 'https://example.com/original-post/',
			)
		);

		$this->go_to( get_permalink( $like_id ) );

		$this->assertTrue( is_singular() );
		$this->assertSame( $like_id, get_the_ID() );
	}

	/** A legacy `post`-typed Like Mark is moved onto the new post type. */
	public function test_migration_moves_legacy_like_marks() {
		$legacy_id = $this->create_mark( array( '_daymark_like_of' => 'https://example.com/post/' ) );
		$ordinary  = $this->create_mark();
		$category  = (int) self::factory()->category->create();
		wp_set_post_categories( $legacy_id, array( $category ) );

		delete_option( Daymark_Like_Visibility::MIGRATED_OPTION );
		$this->visibility->maybe_migrate_legacy_likes();

		$this->assertSame( Daymark_Like_Visibility::POST_TYPE, get_post_type( $legacy_id ) );
		$this->assertSame( 'post', get_post_type( $ordinary ) );
		$this->assertEmpty( wp_get_object_terms( $legacy_id, 'category', array( 'fields' => 'ids' ) ) );
		$this->assertNotEmpty( get_option( Daymark_Like_Visibility::MIGRATED_OPTION ) );
	}

	/** Jetpack Sync is told to withhold a Like, and only a Like. */
	public function test_jetpack_sync_prevented_for_like_post_type_only() {
		$like_id  = (int) self::factory()->post->create( array( 'post_type' => Daymark_Like_Visibility::POST_TYPE ) );
		$ordinary = $this->create_mark();

		$this->assertTrue( $this->visibility->prevent_jetpack_sync( false, get_post( $like_id ) ) );
		$this->assertFalse( $this->visibility->prevent_jetpack_sync( false, get_post( $ordinary ) ) );
	}
}
