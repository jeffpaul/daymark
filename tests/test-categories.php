<?php
/**
 * Per-type category filing and remembered defaults.
 *
 * Categories are the site-filing counterpart to destinations: chosen (or
 * remembered) per Moment type, editable per publish, and remembered on
 * publish. Unlike post formats there is no built-in map — categories are a
 * per-site taxonomy — so a type never filed before falls back to the site's
 * default category.
 *
 * @package Moment
 */

/**
 * Category assignment + moment_category_prefs memory.
 */
class Test_Categories extends WP_UnitTestCase {

	/**
	 * A publishing author.
	 *
	 * @var int
	 */
	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	private function make_category( string $name ): int {
		return (int) self::factory()->category->create( array( 'name' => $name ) );
	}

	private function category_ids( int $post_id ): array {
		return array_map( 'intval', wp_get_post_categories( $post_id ) );
	}

	/** No remembered default until a type has been filed. */
	public function test_effective_categories_empty_by_default() {
		$publisher = new Moment_Publisher();

		$this->assertSame( array(), $publisher->get_effective_categories( 'image' ) );
	}

	/** An explicit selection is assigned to the post and remembered per type. */
	public function test_publish_assigns_and_remembers_categories() {
		$photos    = $this->make_category( 'Photos' );
		$publisher = new Moment_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption'      => 'A sunset',
				'primary_type' => 'image',
				'categories'   => array( $photos ),
			)
		);

		$this->assertSame( array( $photos ), $this->category_ids( $post_id ) );
		$this->assertSame( array( $photos ), $publisher->get_effective_categories( 'image' ) );
	}

	/** The remembered default is per Moment type — image does not leak to note. */
	public function test_remembered_default_is_per_type() {
		$photos    = $this->make_category( 'Photos' );
		$publisher = new Moment_Publisher();

		$publisher->publish(
			array(
				'caption'      => 'A sunset',
				'primary_type' => 'image',
				'categories'   => array( $photos ),
			)
		);

		$this->assertSame( array( $photos ), $publisher->get_effective_categories( 'image' ) );
		$this->assertSame( array(), $publisher->get_effective_categories( 'note' ) );
	}

	/** A later same-type publish with no selection inherits the remembered default. */
	public function test_next_publish_inherits_remembered_default() {
		$photos    = $this->make_category( 'Photos' );
		$publisher = new Moment_Publisher();

		$publisher->publish(
			array(
				'caption'      => 'First',
				'primary_type' => 'image',
				'categories'   => array( $photos ),
			)
		);

		// No categories field this time — should inherit the remembered default.
		$second = (int) $publisher->publish(
			array(
				'caption'      => 'Second',
				'primary_type' => 'image',
			)
		);

		$this->assertSame( array( $photos ), $this->category_ids( $second ) );
	}

	/** Unknown / non-category term IDs are dropped, never silently created. */
	public function test_nonexistent_terms_are_dropped() {
		$photos    = $this->make_category( 'Photos' );
		$tag        = (int) self::factory()->tag->create( array( 'name' => 'not-a-category' ) );
		$publisher = new Moment_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption'      => 'Filtered',
				'primary_type' => 'image',
				'categories'   => array( $photos, 999999, $tag ),
			)
		);

		$this->assertSame( array( $photos ), $this->category_ids( $post_id ) );
	}

	/** An explicit empty selection falls back to the site default and is remembered as "none". */
	public function test_explicit_empty_selection_uses_default_category() {
		$publisher = new Moment_Publisher();
		$default   = (int) get_option( 'default_category' );

		$post_id = (int) $publisher->publish(
			array(
				'caption'      => 'Nowhere in particular',
				'primary_type' => 'note',
				'categories'   => array(),
			)
		);

		$this->assertSame( array( $default ), $this->category_ids( $post_id ) );
		// Remembered as an explicit empty preference (distinct from "never set").
		$prefs = get_user_meta( $this->author, 'moment_category_prefs', true );
		$this->assertArrayHasKey( 'note', $prefs );
		$this->assertSame( array(), $prefs['note'] );
	}

	/** Editing a Moment replaces its categories and updates the remembered default. */
	public function test_update_replaces_categories() {
		$photos    = $this->make_category( 'Photos' );
		$travel    = $this->make_category( 'Travel' );
		$publisher = new Moment_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption'      => 'Original',
				'primary_type' => 'image',
				'status'       => 'draft',
				'categories'   => array( $photos ),
			)
		);

		$publisher->update(
			$post_id,
			array(
				'caption'    => 'Edited',
				'categories' => array( $travel ),
			)
		);

		$this->assertSame( array( $travel ), $this->category_ids( $post_id ) );
		$this->assertSame( array( $travel ), $publisher->get_effective_categories( 'image' ) );
	}

	/** REST create accepts categories[] and files the Moment accordingly. */
	public function test_rest_create_accepts_categories() {
		$photos = $this->make_category( 'Photos' );

		$request = new WP_REST_Request( 'POST', '/moment/v1/moments' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'Via REST' );
		$request->set_param( 'primary_type', 'image' );
		$request->set_param( 'categories', array( $photos ) );

		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );

		$this->assertSame( array( $photos ), $this->category_ids( $response->get_data()['id'] ) );
	}

	/** GET /moments/{id} returns the current categories for the composer to prefill. */
	public function test_get_moment_returns_categories() {
		$photos    = $this->make_category( 'Photos' );
		$publisher = new Moment_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption'      => 'Editable',
				'primary_type' => 'image',
				'status'       => 'draft',
				'categories'   => array( $photos ),
			)
		);

		$request = new WP_REST_Request( 'GET', "/moment/v1/moments/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( array( $photos ), rest_do_request( $request )->get_data()['categories'] );
	}
}
