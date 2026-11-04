<?php
/**
 * REST title serialization tests.
 *
 * @package Daymark
 */

/**
 * Titles returned by the REST API must be plain text, not HTML-entity
 * encoded — the app escapes at render time, so an encoded title would
 * double-escape (regression: "Tonight&#8217;s sky" shown literally).
 */
class Test_Rest_Titles extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
	}

	private function publish_apostrophe_daymark(): int {
		$publisher = new Daymark_Publisher();

		return (int) $publisher->publish(
			array(
				'caption'      => "Tonight's sky. No filter.",
				'primary_type' => 'note',
			)
		);
	}

	/** GET /marks summaries carry decoded plain-text titles. */
	public function test_marks_list_title_is_plain_text() {
		$post_id = $this->publish_apostrophe_daymark();

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$data = rest_do_request( $request )->get_data();

		$summary = null;
		foreach ( $data as $item ) {
			if ( $post_id === $item['id'] ) {
				$summary = $item;
			}
		}

		$this->assertNotNull( $summary, 'Published Mark should be listed' );
		$this->assertStringNotContainsString( '&#', $summary['title'], 'Title must not be entity-encoded' );
		$this->assertStringContainsString( 's sky', $summary['title'] );
	}

	/**
	 * Publish a Mark as the current user.
	 *
	 * @param array<string, mixed> $data Publisher fields.
	 * @return int
	 */
	private function publish( array $data ): int {
		return (int) ( new Daymark_Publisher() )->publish( $data + array( 'primary_type' => 'note' ) );
	}

	/**
	 * A post's summary on GET /timeline.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	private function timeline_summary( int $post_id ): ?array {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( rest_do_request( $request )->get_data() as $item ) {
			if ( ( $item['id'] ?? 0 ) === $post_id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * A title made from the caption's first words, because none was typed,
	 * says so, so the app can show the caption once instead of under a title
	 * that repeats it (publishing audit R10). The captions have an
	 * apostrophe and an ampersand, which WordPress stores differently in an
	 * Author's title and excerpt, and the short one is its own whole title.
	 */
	public function test_title_made_from_caption_is_flagged() {
		$long  = $this->publish( array( 'caption' => "Fish & chips on the pier, then the city's lights came on one by one." ) );
		$short = $this->publish_apostrophe_daymark();

		$this->assertTrue( $this->timeline_summary( $long )['title_from_caption'] );
		$this->assertTrue( $this->timeline_summary( $short )['title_from_caption'] );
	}

	/** A typed title, and a Check In's title made from its place, are titles of their own. */
	public function test_title_not_made_from_caption_is_not_flagged() {
		$typed      = $this->publish(
			array(
				'caption' => 'Golden hour from the pier, then the city lights came on one by one.',
				'title'   => 'Pier at dusk',
			)
		);
		$checkin    = $this->publish(
			array(
				'caption'      => 'Great coffee',
				'primary_type' => 'checkin',
				'place_name'   => 'Blue Bottle Coffee',
			)
		);
		$no_caption = $this->publish(
			array(
				'primary_type' => 'checkin',
				'place_name'   => 'Blue Bottle Coffee',
			)
		);

		foreach ( array( $typed, $checkin, $no_caption ) as $post_id ) {
			$this->assertFalse( $this->timeline_summary( $post_id )['title_from_caption'] );
		}
	}

	/**
	 * A Mark with no caption isn't flagged, even when its title is the date
	 * and time generate_title() makes for it, as it is in the minute it was
	 * published.
	 */
	public function test_mark_without_caption_is_not_flagged() {
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => get_current_user_id(),
				'post_title'   => Daymark_Publisher::generate_title( '' ),
				'post_excerpt' => '',
				'meta_input'   => array(
					'_daymark_is_mark'      => '1',
					'_daymark_primary_type' => 'image',
				),
			)
		);

		$this->assertFalse( $this->timeline_summary( $post_id )['title_from_caption'] );
	}

	/**
	 * Publish an Aside post the way the classic editor does, with no title,
	 * so Daymark_Notes fills one in from its text.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	private function publish_untitled_aside( string $content ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => $content,
				'post_status'  => 'draft',
			)
		);
		set_post_format( $post_id, 'aside' );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}

	/**
	 * A Note written in the block editor and published without a title gets
	 * one from its first words too (Daymark_Notes::title_for()), so it shows
	 * its text once, like a Note from the app.
	 */
	public function test_block_editor_note_titled_from_its_text_is_flagged() {
		$post_id = $this->publish_untitled_aside( "<!-- wp:paragraph -->\n<p>Golden hour from the pier, then the city lights came on one by one.</p>\n<!-- /wp:paragraph -->" );

		$this->assertSame( 'Golden hour from the pier, then the city…', get_post_field( 'post_title', $post_id ) );
		$this->assertTrue( $this->timeline_summary( $post_id )['title_from_caption'] );
	}

	/** A Note with no text, titled with the date and time, isn't flagged. */
	public function test_block_editor_note_without_text_is_not_flagged() {
		$post_id = $this->publish_untitled_aside( "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/photo.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->" );

		$this->assertStringStartsWith( 'Mark — ', get_post_field( 'post_title', $post_id ) );
		$this->assertFalse( $this->timeline_summary( $post_id )['title_from_caption'] );
	}

	/** A title changed later, in wp-admin say, is no longer the one made from the caption. */
	public function test_title_changed_after_publishing_is_not_flagged() {
		$post_id = $this->publish( array( 'caption' => 'Golden hour from the pier, then the city lights came on one by one.' ) );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Pier at dusk',
			)
		);

		$this->assertFalse( $this->timeline_summary( $post_id )['title_from_caption'] );
	}

	/** A Mark saved before its caption was kept on its own is checked against its excerpt. */
	public function test_mark_without_caption_meta_is_checked_against_its_excerpt() {
		$post_id = $this->publish( array( 'caption' => "Fish & chips on the pier, then the city's lights came on one by one." ) );

		delete_post_meta( $post_id, '_daymark_caption' );

		$this->assertTrue( $this->timeline_summary( $post_id )['title_from_caption'] );
	}

	/**
	 * Someone who can't open a password-protected Mark gets no caption, so
	 * its card keeps the title.
	 */
	public function test_protected_mark_keeps_its_title_for_other_users() {
		$post_id = $this->publish( array( 'caption' => 'Golden hour from the pier, then the city lights came on one by one.' ) );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_password' => 'hunter2',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$summary = $this->timeline_summary( $post_id );

		$this->assertSame( '', $summary['excerpt'] );
		$this->assertFalse( $summary['title_from_caption'] );
	}

	/** Notification items carry decoded plain-text post titles. */
	public function test_notification_post_title_is_plain_text() {
		$post_id = $this->publish_apostrophe_daymark();
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$notifications = new Daymark_Notifications();
		$items         = $notifications->get_notifications();

		$this->assertNotEmpty( $items );
		$this->assertStringNotContainsString( '&#', $items[0]['post_title'], 'post_title must not be entity-encoded' );
	}
}
