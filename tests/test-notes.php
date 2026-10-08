<?php
/**
 * Daymark_Notes tests: Notes (Aside-format posts) on the home page and
 * main feed, and titles for untitled Notes.
 *
 * @package Daymark
 */

/**
 * Daymark_Notes coverage.
 */
class Test_Notes extends WP_UnitTestCase {

	/**
	 * Administrator used for REST requests.
	 *
	 * @var int
	 */
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Daymark_Settings::SHOW_NOTES_ON_HOME );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down(): void {
		delete_option( Daymark_Settings::SHOW_NOTES_ON_HOME );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create a published post, optionally with a post format.
	 *
	 * @param string $format Post format, or '' for Standard.
	 * @param string $title  Post title.
	 * @return int Post ID.
	 */
	private function make_post( string $format = '', string $title = 'A post' ): int {
		$id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => 'Some words.',
				'post_status'  => 'publish',
			)
		);

		if ( '' !== $format ) {
			set_post_format( $id, $format );
		}

		return $id;
	}

	/**
	 * IDs of the posts the main query found.
	 *
	 * @return int[]
	 */
	private function main_query_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/** Notes show on the home page and in the main feed by default. */
	public function test_notes_show_on_home_and_feed_by_default(): void {
		$note = $this->make_post( 'aside' );

		$this->go_to( home_url( '/' ) );
		$this->assertContains( $note, $this->main_query_ids() );

		$this->go_to( get_feed_link() );
		$this->assertContains( $note, $this->main_query_ids() );
	}

	/** With the setting off, the home page and main feed leave Notes out. */
	public function test_setting_off_hides_notes_from_home_and_feed(): void {
		update_option( Daymark_Settings::SHOW_NOTES_ON_HOME, '' );
		$note     = $this->make_post( 'aside' );
		$standard = $this->make_post();
		$image    = $this->make_post( 'image' );

		$this->go_to( home_url( '/' ) );
		$ids = $this->main_query_ids();
		$this->assertNotContains( $note, $ids );
		$this->assertContains( $standard, $ids );
		$this->assertContains( $image, $ids );

		$this->go_to( get_feed_link() );
		$ids = $this->main_query_ids();
		$this->assertNotContains( $note, $ids );
		$this->assertContains( $standard, $ids );
	}

	/** With the setting off, a Note's own page and archives still show it. */
	public function test_setting_off_keeps_notes_elsewhere(): void {
		update_option( Daymark_Settings::SHOW_NOTES_ON_HOME, '' );
		$category = self::factory()->category->create( array( 'name' => 'Thoughts' ) );
		$note     = $this->make_post( 'aside' );
		wp_set_post_categories( $note, array( $category ) );

		$this->go_to( get_permalink( $note ) );
		$this->assertContains( $note, $this->main_query_ids() );

		$this->go_to( get_category_link( $category ) );
		$this->assertContains( $note, $this->main_query_ids() );

		$this->go_to( get_post_format_link( 'aside' ) );
		$this->assertContains( $note, $this->main_query_ids() );

		$this->go_to( html_entity_decode( get_category_feed_link( $category ) ) );
		$this->assertContains( $note, $this->main_query_ids() );
	}

	/** A developer filter wins over the stored option. */
	public function test_filter_wins_over_option(): void {
		$note = $this->make_post( 'aside' );
		add_filter( 'daymark_show_notes_on_home', '__return_false' );

		$this->go_to( home_url( '/' ) );
		$this->assertNotContains( $note, $this->main_query_ids() );

		remove_filter( 'daymark_show_notes_on_home', '__return_false' );
	}

	/**
	 * Send a block-editor-style REST request to create or update a post.
	 *
	 * @param array<string, mixed> $params Request body.
	 * @param int                  $id     Post ID to update, or 0 to create.
	 * @return WP_REST_Response
	 */
	private function rest_save( array $params, int $id = 0 ): WP_REST_Response {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' . ( $id ? '/' . $id : '' ) );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/** An untitled Aside post published from the block editor gets a title. */
	public function test_rest_publish_fills_title_and_slug(): void {
		$response = $this->rest_save(
			array(
				'title'   => '',
				'content' => "<!-- wp:paragraph -->\n<p>Coffee with Sam at the corner place this morning, great chat.</p>\n<!-- /wp:paragraph -->",
				'format'  => 'aside',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$post = get_post( $response->get_data()['id'] );
		$this->assertSame( 'Coffee with Sam at the corner place this…', $post->post_title );
		$this->assertSame( 'coffee-with-sam-at-the-corner-place-this', $post->post_name );
	}

	/** Words in neighboring blocks don't run together in the title. */
	public function test_title_keeps_words_from_separate_blocks_apart(): void {
		$this->assertSame(
			'One. Two.',
			Daymark_Notes::title_for( '<p>One.</p><p>Two.</p>' )
		);
	}

	/** A Note with no text gets the date-and-time title the app uses. */
	public function test_note_without_text_gets_timestamp_title(): void {
		$title = Daymark_Notes::title_for( '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->' );

		$this->assertStringStartsWith( 'Mark — ', $title );
	}

	/** A draft keeps its empty title; the title is filled on publish. */
	public function test_rest_draft_is_not_titled_until_published(): void {
		$draft = $this->rest_save(
			array(
				'title'   => '',
				'content' => '<p>First draft words</p>',
				'format'  => 'aside',
				'status'  => 'draft',
			)
		);
		$id    = $draft->get_data()['id'];
		$this->assertSame( '', get_post( $id )->post_title );

		$this->rest_save(
			array(
				'content' => '<p>Final words for this note</p>',
				'status'  => 'publish',
			),
			$id
		);

		$this->assertSame( 'Final words for this note', get_post( $id )->post_title );
	}

	/** A title someone typed is never replaced. */
	public function test_rest_typed_title_is_kept(): void {
		$response = $this->rest_save(
			array(
				'title'   => 'My own title',
				'content' => '<p>Some words</p>',
				'format'  => 'aside',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 'My own title', get_post( $response->get_data()['id'] )->post_title );
	}

	/** An untitled post in any other format is left untitled. */
	public function test_rest_standard_post_is_not_titled(): void {
		$response = $this->rest_save(
			array(
				'title'   => '',
				'content' => '<p>Some words</p>',
				'status'  => 'publish',
			)
		);

		$this->assertSame( '', get_post( $response->get_data()['id'] )->post_title );
	}

	/** Changing a draft Note to Standard as it's published leaves it untitled. */
	public function test_rest_format_change_away_from_aside_is_respected(): void {
		$id = self::factory()->post->create(
			array(
				'post_title'   => '',
				'post_content' => '<p>Some words</p>',
				'post_status'  => 'draft',
			)
		);
		set_post_format( $id, 'aside' );

		$this->rest_save(
			array(
				'format' => 'standard',
				'status' => 'publish',
			),
			$id
		);

		$this->assertSame( '', get_post( $id )->post_title );
		$this->assertFalse( get_post_format( $id ) );
	}

	/** Publishing an untitled Note outside REST (classic editor, code) fills the title too. */
	public function test_non_rest_publish_fills_title(): void {
		$id = self::factory()->post->create(
			array(
				'post_title'   => '',
				'post_content' => '<p>Written in the classic editor</p>',
				'post_status'  => 'draft',
			)
		);
		set_post_format( $id, 'aside' );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $id );
		$this->assertSame( 'Written in the classic editor', $post->post_title );
		$this->assertSame( 'written-in-the-classic-editor', $post->post_name );
	}

	/** The daymark_fill_note_title filter can turn automatic titles off. */
	public function test_fill_filter_turns_titles_off(): void {
		add_filter( 'daymark_fill_note_title', '__return_false' );

		$response = $this->rest_save(
			array(
				'title'   => '',
				'content' => '<p>Some words</p>',
				'format'  => 'aside',
				'status'  => 'publish',
			)
		);

		remove_filter( 'daymark_fill_note_title', '__return_false' );

		$this->assertSame( '', get_post( $response->get_data()['id'] )->post_title );
	}
}
