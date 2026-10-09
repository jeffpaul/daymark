<?php
/**
 * Tests for showing notes written with Shortnotes or IndieBlocks
 * (Daymark_External_Notes, issue #492).
 *
 * Neither plugin is loaded in CI, so each test registers stand-in
 * `shortnote` and `indieblocks_note` post types, the way the plugins
 * register them.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_External_Notes and the routes that read its notes.
 */
class Test_External_Notes extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		foreach ( array_keys( Daymark_External_Notes::POST_TYPES ) as $post_type ) {
			register_post_type(
				$post_type,
				array(
					'public'   => true,
					'supports' => array( 'author', 'title', 'editor' ),
				)
			);
		}

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		foreach ( array_keys( Daymark_External_Notes::POST_TYPES ) as $post_type ) {
			unregister_post_type( $post_type );
		}

		parent::tear_down();
	}

	/**
	 * Create a published post of a type.
	 *
	 * @param string $post_type Post type.
	 * @param string $date      Local post date.
	 * @param string $content   Content.
	 * @param string $status    Status.
	 * @return int
	 */
	private function create( string $post_type, string $date, string $content = 'A short thought.', string $status = 'publish' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_date'    => $date,
				'post_title'   => wp_trim_words( $content, 8, '' ),
				'post_content' => $content,
				'post_author'  => $this->user_id,
			)
		);
	}

	/**
	 * Run GET /timeline with the given parameters.
	 *
	 * @param array $params Query parameters.
	 * @return array Items.
	 */
	private function timeline( array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request )->get_data();
	}

	public function test_post_types_lists_only_registered_types() {
		$this->assertSame( array( 'shortnote', 'indieblocks_note' ), Daymark_External_Notes::post_types() );

		unregister_post_type( 'shortnote' );

		$this->assertSame( array( 'indieblocks_note' ), Daymark_External_Notes::post_types() );
	}

	public function test_filter_can_turn_reading_off_but_not_add_unknown_types() {
		add_filter( 'daymark_read_external_notes', '__return_empty_array' );
		$this->assertSame( array(), Daymark_External_Notes::post_types() );
		remove_filter( 'daymark_read_external_notes', '__return_empty_array' );

		$add_page = static function () {
			return array( 'shortnote', 'page' );
		};
		add_filter( 'daymark_read_external_notes', $add_page );
		$this->assertSame( array( 'shortnote' ), Daymark_External_Notes::post_types() );
		remove_filter( 'daymark_read_external_notes', $add_page );
	}

	public function test_notes_appear_on_the_timeline_as_notes_in_date_order() {
		$post      = $this->create( 'post', '2026-09-01 10:00:00', 'An ordinary post.' );
		$shortnote = $this->create( 'shortnote', '2026-09-03 10:00:00' );
		$ib_note   = $this->create( 'indieblocks_note', '2026-09-02 10:00:00' );

		$items = $this->timeline();

		$this->assertSame( array( $shortnote, $ib_note, $post ), array_column( $items, 'id' ) );
		$this->assertSame( 'note', $items[0]['type'] );
		$this->assertSame( 'mark', $items[0]['item_type'] );
		$this->assertSame( 'note', $items[1]['type'] );
	}

	public function test_drafts_and_unregistered_types_are_left_out() {
		$draft = $this->create( 'shortnote', '2026-09-03 10:00:00', 'Not yet.', 'draft' );
		$note  = $this->create( 'indieblocks_note', '2026-09-02 10:00:00' );

		unregister_post_type( 'indieblocks_note' );

		$ids = array_column( $this->timeline(), 'id' );

		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $note, $ids );
	}

	public function test_note_type_filter_includes_notes_and_other_types_exclude_them() {
		$note = $this->create( 'shortnote', '2026-09-03 10:00:00' );

		$this->assertContains( $note, array_column( $this->timeline( array( 'type' => 'note' ) ), 'id' ) );
		$this->assertNotContains( $note, array_column( $this->timeline( array( 'type' => 'image' ) ), 'id' ) );
	}

	public function test_search_my_marks_and_bookmarks_include_notes() {
		$match = $this->create( 'shortnote', '2026-09-03 10:00:00', 'Coffee on the porch.' );
		$other = $this->create( 'indieblocks_note', '2026-09-02 10:00:00', 'Rain again today.' );

		$found = array_column( $this->timeline( array( 's' => 'porch' ) ), 'id' );
		$this->assertSame( array( $match ), $found );

		$mine = array_column( $this->timeline( array( 'mine' => true ) ), 'id' );
		$this->assertContains( $match, $mine );
		$this->assertContains( $other, $mine );

		Daymark_Plugin::instance()->bookmarks->add( $this->user_id, $other );
		$this->assertSame( array( $other ), array_column( $this->timeline( array( 'bookmarked' => true ) ), 'id' ) );
	}

	public function test_count_includes_notes() {
		$this->create( 'post', '2026-09-01 10:00:00', 'An ordinary post.' );
		$this->create( 'shortnote', '2026-09-03 10:00:00' );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'count', true );

		$this->assertSame( 2, (int) rest_do_request( $request )->get_headers()['X-WP-Total'] );
	}

	public function test_note_opens_in_the_post_view() {
		$note = $this->create( 'shortnote', '2026-09-03 10:00:00', 'Coffee on the porch.' );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $note . '/content' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Coffee on the porch.', $response->get_data()['content'] );
	}

	public function test_note_can_be_bookmarked_and_saved_as_the_reading_position() {
		$note = $this->create( 'indieblocks_note', '2026-09-03 10:00:00' );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/bookmarks/' . $note );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertTrue( Daymark_Plugin::instance()->bookmarks->is_bookmarked( $this->user_id, $note ) );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/timeline/position' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'id', $note );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( $note, Daymark_Timeline_Position::get_position( $this->user_id )['id'] );
	}

	public function test_daymark_never_writes_to_a_note() {
		$note   = $this->create( 'shortnote', '2026-09-03 10:00:00', 'Coffee on the porch.' );
		$before = get_post( $note );

		$this->timeline();

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $note . '/content' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		rest_do_request( $request );

		$after = get_post( $note );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
		$this->assertSame( '', get_post_meta( $note, '_daymark_is_mark', true ) );
	}
}
