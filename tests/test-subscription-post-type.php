<?php
/**
 * daymark_subscription_post CPT registration tests (issue #78 — the CPT
 * registration and meta schema only; content ingest, autodiscovery,
 * polling, and pruning are a later task).
 *
 * @package Daymark
 */

/**
 * Tests the `daymark_subscription_post` CPT registration and its privacy
 * guarantees.
 */
class Test_Subscription_Post_Type extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase::tear_down() unregisters every registered post
		// meta key after each test (core test-isolation behavior — see
		// unregister_all_meta_keys() in the WP test library), and this
		// plugin only registers meta once, for real, when `init` actually
		// fires (before the test suite's first test runs). Without
		// re-registering here, every test after the first one anywhere in
		// the suite would see none of these meta keys registered. Post
		// type registration itself is unaffected by that teardown, so it
		// is not repeated here.
		( new Daymark_Subscription_Post_Type() )->register_meta();
	}

	/**
	 * Scenario: the CPT is registered with exactly the visibility flags
	 * that prevent it from ever getting its own public permalink on this
	 * site. This is a real privacy guarantee (a cached copy of someone
	 * else's content must never be republished on this site's own domain
	 * without their knowledge) — not just an implementation detail.
	 */
	public function test_cpt_registered_with_correct_visibility_flags() {
		$this->assertTrue( post_type_exists( Daymark_Subscription_Post_Type::POST_TYPE ) );

		$post_type_object = get_post_type_object( Daymark_Subscription_Post_Type::POST_TYPE );
		$this->assertInstanceOf( 'WP_Post_Type', $post_type_object );

		$this->assertFalse( $post_type_object->public );
		$this->assertFalse( $post_type_object->publicly_queryable );
		$this->assertTrue( $post_type_object->show_in_rest );
		$this->assertFalse( $post_type_object->show_ui );
		$this->assertFalse( $post_type_object->has_archive );
	}

	/** Scenario: the CPT supports at least title, excerpt, and thumbnail. */
	public function test_cpt_supports_expected_features() {
		$this->assertTrue( post_type_supports( Daymark_Subscription_Post_Type::POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( Daymark_Subscription_Post_Type::POST_TYPE, 'excerpt' ) );
		$this->assertTrue( post_type_supports( Daymark_Subscription_Post_Type::POST_TYPE, 'thumbnail' ) );
	}

	/**
	 * Scenario: a post created with `daymark_subscription_post` is not
	 * reachable via its own permalink.
	 *
	 * `public => false` / `publicly_queryable => false` do not, by
	 * themselves, stop WordPress's main query from resolving a directly
	 * constructed `?post_type=<slug>&p=<id>` URL on the front end (`p` and
	 * `post_type` are parsed as public query vars regardless of a post
	 * type's own visibility flags) — the sanity check below demonstrates
	 * that. `Daymark_Subscription_Post_Type::force_404_on_front_end()`
	 * (hooked to `template_redirect` in production) is what actually
	 * closes that gap. WP_UnitTestCase::go_to() simulates query parsing
	 * only and never fires `template_redirect`, so the guard is invoked
	 * directly here exactly as WordPress would on a real request.
	 */
	public function test_subscription_post_has_no_public_permalink() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Cached Post From A Subscribed Site',
			)
		);

		$permalink = get_permalink( $post_id );

		$this->go_to( $permalink );

		$this->assertTrue(
			is_singular( Daymark_Subscription_Post_Type::POST_TYPE ),
			'Sanity check: the raw query resolves as singular before the template_redirect guard runs.'
		);

		( new Daymark_Subscription_Post_Type() )->force_404_on_front_end();

		$this->assertTrue( is_404() );
	}

	/**
	 * Scenario: a `daymark_subscription_post` does not appear in a normal
	 * public query (no explicit `post_type` argument) even though it
	 * exists in the DB.
	 */
	public function test_subscription_post_excluded_from_normal_public_query() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Should Not Appear In Default Queries',
			)
		);

		$query = new WP_Query( array( 's' => 'Should Not Appear In Default Queries' ) );

		$found_ids = wp_list_pluck( $query->posts, 'ID' );
		$this->assertNotContains( $post_id, $found_ids );
	}

	/**
	 * Scenario: registered meta fields are present with the documented
	 * types and are exposed to REST (needed by the authenticated app
	 * shell).
	 */
	public function test_meta_fields_registered_with_expected_types() {
		$registered = get_registered_meta_keys( 'post', Daymark_Subscription_Post_Type::POST_TYPE );

		$expected_types = array(
			'subscription_id'    => 'integer',
			'body_content'       => 'string',
			'embed_data'         => 'string',
			'content_state'      => 'string',
			'fetched_full_at'    => 'string',
			'post_format'        => 'string',
			'featured_image_url' => 'string',
			'permalink'          => 'string',
			'author'             => 'string',
			'published_at'       => 'string',
		);

		foreach ( $expected_types as $key => $type ) {
			$this->assertArrayHasKey( $key, $registered, "Missing registered meta key: {$key}" );
			$this->assertSame( $type, $registered[ $key ]['type'] );
			$this->assertTrue( $registered[ $key ]['show_in_rest'] );
		}
	}

	/** Scenario: content_state sanitizes to the enum, falling back safely. */
	public function test_content_state_sanitizes_to_enum() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => Daymark_Subscription_Post_Type::POST_TYPE )
		);

		update_post_meta( $post_id, 'content_state', 'pruned' );
		$this->assertSame( 'pruned', get_post_meta( $post_id, 'content_state', true ) );

		update_post_meta( $post_id, 'content_state', 'not-a-real-state' );
		$this->assertSame( 'excerpt_only', get_post_meta( $post_id, 'content_state', true ) );
	}

	/** Scenario: embed_data rejects invalid JSON rather than storing it. */
	public function test_embed_data_rejects_invalid_json() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => Daymark_Subscription_Post_Type::POST_TYPE )
		);

		update_post_meta( $post_id, 'embed_data', '{"valid":true}' );
		$this->assertSame( '{"valid":true}', get_post_meta( $post_id, 'embed_data', true ) );

		update_post_meta( $post_id, 'embed_data', '{not valid json' );
		$this->assertSame( '', get_post_meta( $post_id, 'embed_data', true ) );
	}

	/** Scenario: permalink and featured_image_url are stored as escaped URLs. */
	public function test_url_meta_fields_sanitized() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => Daymark_Subscription_Post_Type::POST_TYPE )
		);

		update_post_meta( $post_id, 'permalink', 'https://example.com/a-post/' );
		$this->assertSame( 'https://example.com/a-post/', get_post_meta( $post_id, 'permalink', true ) );

		update_post_meta( $post_id, 'featured_image_url', 'javascript:alert(1)' );
		$this->assertSame( '', get_post_meta( $post_id, 'featured_image_url', true ) );
	}
}
