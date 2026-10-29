<?php
/**
 * Daymark_Post_Kinds tests: Marks filed under the matching Post Kinds kind,
 * and Post Kinds' response box left off Marks.
 *
 * Post Kinds isn't loaded in CI, so each test registers a `kind` taxonomy
 * shaped like Post Kinds 4.0.0's: on `post`, with the Default Kind (Note)
 * as its default term, which core gives any post saved without one.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Post_Kinds.
 */
class Test_Post_Kinds extends WP_UnitTestCase {

	/**
	 * Register the stand-in taxonomy and its terms.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		register_taxonomy(
			Daymark_Post_Kinds::TAXONOMY,
			array( 'post' ),
			array(
				'public'       => true,
				'default_term' => array(
					'name' => 'note',
					'slug' => 'note',
				),
			)
		);

		foreach ( array( 'article', 'photo', 'video', 'audio', 'checkin', 'repost', 'reply' ) as $kind ) {
			if ( ! term_exists( $kind, Daymark_Post_Kinds::TAXONOMY ) ) {
				wp_insert_term( $kind, Daymark_Post_Kinds::TAXONOMY );
			}
		}
	}

	/**
	 * Remove the stand-in taxonomy.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unregister_taxonomy( Daymark_Post_Kinds::TAXONOMY );
		parent::tear_down();
	}

	/**
	 * A Mark's kind slugs.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function kinds( int $post_id ): array {
		return wp_get_object_terms( $post_id, Daymark_Post_Kinds::TAXONOMY, array( 'fields' => 'slugs' ) );
	}

	/** Each Mark type lands under its own kind instead of the Default Kind. */
	public function test_marks_are_filed_under_their_type_kind() {
		$publisher = new Daymark_Publisher();
		$cases     = array(
			'checkin' => 'checkin',
			'note'    => 'note',
		);

		foreach ( $cases as $type => $kind ) {
			$post_id = (int) $publisher->publish(
				array(
					'caption'      => 'A ' . $type,
					'primary_type' => $type,
				)
			);

			$this->assertSame( array( $kind ), $this->kinds( $post_id ), $type );
		}
	}

	/** A Reblog is a Repost and a reply is a Reply, whatever the type. */
	public function test_reblog_and_reply_marks_get_their_kind() {
		$publisher = new Daymark_Publisher();

		$reblog = (int) $publisher->publish(
			array(
				'caption'      => 'Worth reading',
				'primary_type' => 'note',
				'repost_of'    => 'https://example.com/original-post/',
			)
		);
		$reply  = (int) $publisher->publish(
			array(
				'caption'      => 'Agreed',
				'primary_type' => 'note',
				'in_reply_to'  => 'https://example.com/original-post/',
			)
		);

		$this->assertSame( array( 'repost' ), $this->kinds( $reblog ) );
		$this->assertSame( array( 'reply' ), $this->kinds( $reply ) );
	}

	/** Editing a Mark refiles it when its type changes. */
	public function test_update_refiles_the_mark() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'At the stadium',
				'primary_type' => 'note',
			)
		);

		$publisher->update(
			$post_id,
			array(
				'caption'      => 'At the stadium',
				'primary_type' => 'checkin',
			)
		);

		$this->assertSame( array( 'checkin' ), $this->kinds( $post_id ) );
	}

	/** The filter turns filing off, leaving Post Kinds' Default Kind. */
	public function test_filter_turns_filing_off() {
		add_filter( 'daymark_set_post_kind', '__return_false' );

		$post_id = (int) ( new Daymark_Publisher() )->publish(
			array(
				'caption'      => 'At the stadium',
				'primary_type' => 'checkin',
			)
		);

		remove_filter( 'daymark_set_post_kind', '__return_false' );

		$this->assertSame( array( 'note' ), $this->kinds( $post_id ) );
	}

	/** A kind the site has no term for is left alone rather than created. */
	public function test_missing_term_is_not_created() {
		wp_delete_term( (int) get_term_by( 'slug', 'checkin', Daymark_Post_Kinds::TAXONOMY )->term_id, Daymark_Post_Kinds::TAXONOMY );

		$post_id = (int) ( new Daymark_Publisher() )->publish(
			array(
				'caption'      => 'At the stadium',
				'primary_type' => 'checkin',
			)
		);

		$this->assertSame( array( 'note' ), $this->kinds( $post_id ) );
		$this->assertNull( term_exists( 'checkin', Daymark_Post_Kinds::TAXONOMY ) );
	}

	/** Without Post Kinds, publishing is unchanged and nothing is filed. */
	public function test_nothing_happens_without_the_taxonomy() {
		unregister_taxonomy( Daymark_Post_Kinds::TAXONOMY );

		$post_id = (int) ( new Daymark_Publisher() )->publish(
			array(
				'caption'      => 'Hello',
				'primary_type' => 'note',
			)
		);

		$this->assertGreaterThan( 0, $post_id );
		$this->assertFalse( Daymark_Post_Kinds::available() );
	}

	/** Post Kinds' response box is dropped on a Mark and kept elsewhere. */
	public function test_response_box_suppressed_only_on_marks() {
		$mark  = self::factory()->post->create();
		$other = self::factory()->post->create();
		update_post_meta( $mark, '_daymark_is_mark', '1' );

		$box = '<section class="h-cite response p-repost-of">Reposted</section>';

		$this->assertSame( '', apply_filters( 'kind_response_display', $box, $mark ) );
		$this->assertSame( $box, apply_filters( 'kind_response_display', $box, $other ) );
	}
}
