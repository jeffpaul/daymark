<?php
/**
 * Daymark_Renderer reaction-stat tests.
 *
 * @package Daymark
 */

/**
 * Tests the comment/like stat row rendered on each Mark item.
 */
class Test_Renderer extends WP_UnitTestCase {

	/**
	 * A published Mark post to render.
	 *
	 * @var int
	 */
	private int $daymark_id;

	public function set_up(): void {
		parent::set_up();

		$this->daymark_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->daymark_id, '_daymark_is_mark', '1' );
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'note' );
	}

	/**
	 * Insert an approved comment of the given type on the test Mark.
	 *
	 * @param string $comment_type 'comment' or 'like'.
	 * @return int Comment ID.
	 */
	private function add_comment( string $comment_type ): int {
		return (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->daymark_id,
				'comment_approved' => 1,
				'comment_type'     => $comment_type,
			)
		);
	}

	/** With no comments or likes, the stat row shows dimmed icons and no digits. */
	public function test_zero_counts_show_no_digits() {
		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringContainsString( 'daymark-stat daymark-stat--comments"', $html, 'Comment stat present without the active modifier' );
		$this->assertStringContainsString( 'daymark-stat daymark-stat--likes"', $html, 'Like stat present without the active modifier' );
		$this->assertStringNotContainsString( 'daymark-stat--active', $html );
		$this->assertStringNotContainsString( 'daymark-stat__count', $html, 'No count span when there is nothing to report' );
		$this->assertStringContainsString( 'aria-label="0 comments"', $html, 'Screen readers still get the real count' );
		$this->assertStringContainsString( 'aria-label="0 likes"', $html );
	}

	/** A reply bumps the comment stat only, and shows its digit + active styling. */
	public function test_reply_bumps_comment_stat_only() {
		$this->add_comment( 'comment' );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertMatchesRegularExpression(
			'/daymark-stat daymark-stat--comments daymark-stat--active"[^>]*aria-label="1 comment"/',
			$html
		);
		$this->assertStringContainsString( '<span class="daymark-stat__count" aria-hidden="true">1</span>', $html );
		$this->assertStringContainsString( 'daymark-stat daymark-stat--likes"', $html, 'Likes stat stays inactive' );
		$this->assertDoesNotMatchRegularExpression( '/daymark-stat--likes daymark-stat--active/', $html );
	}

	/** A like bumps the like stat only — likes and replies are counted independently. */
	public function test_like_bumps_like_stat_only() {
		$this->add_comment( 'like' );
		$this->add_comment( 'like' );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertMatchesRegularExpression(
			'/daymark-stat daymark-stat--likes daymark-stat--active"[^>]*aria-label="2 likes"/',
			$html
		);
		$this->assertStringContainsString( 'daymark-stat daymark-stat--comments"', $html, 'Comments stat stays inactive' );
		$this->assertDoesNotMatchRegularExpression( '/daymark-stat--comments daymark-stat--active/', $html );
	}

	/** Unapproved comments/likes never count. */
	public function test_unapproved_reactions_are_excluded() {
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->daymark_id,
				'comment_approved' => 0,
				'comment_type'     => 'comment',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->daymark_id,
				'comment_approved' => 0,
				'comment_type'     => 'like',
			)
		);

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringContainsString( 'aria-label="0 comments"', $html );
		$this->assertStringContainsString( 'aria-label="0 likes"', $html );
	}

	/** Reactions of a third comment_type (e.g. a federation repost) affect neither stat. */
	public function test_other_comment_types_are_ignored() {
		$this->add_comment( 'repost' );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringContainsString( 'aria-label="0 comments"', $html );
		$this->assertStringContainsString( 'aria-label="0 likes"', $html );
	}

	/** A note's caption is the whole Mark, so it gets the pull-quote treatment. */
	public function test_note_caption_gets_pull_quote_class() {
		self::factory()->post->update_object( $this->daymark_id, array( 'post_content' => 'A late-night thought.' ) );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringContainsString( 'daymark-item-caption daymark-item-caption--note', $html );
	}

	/**
	 * A note's pull-quote caption leads the card, the same way a
	 * thumbnail/player does for other types — not tucked after the
	 * badge/date row.
	 */
	public function test_note_caption_renders_before_badge() {
		self::factory()->post->update_object( $this->daymark_id, array( 'post_content' => 'A late-night thought.' ) );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$caption_pos = strpos( $html, 'daymark-item-caption--note' );
		$badge_pos   = strpos( $html, 'daymark-badge' );

		$this->assertNotFalse( $caption_pos );
		$this->assertNotFalse( $badge_pos );
		$this->assertLessThan( $badge_pos, $caption_pos, 'The pull-quote caption should render before the badge/date row' );
	}

	/** A non-note type's caption stays the plain (non-pull-quote) style. */
	public function test_non_note_caption_has_no_pull_quote_class() {
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'image' );
		self::factory()->post->update_object( $this->daymark_id, array( 'post_content' => 'A caption.' ) );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringContainsString( 'class="daymark-item-caption"', $html );
		$this->assertStringNotContainsString( 'daymark-item-caption--note', $html );
	}

	/** Set up the test Mark as a pure audio or video type with one real attachment. */
	private function attach_media( string $type, string $mime, string $fixture_filename ): void {
		update_post_meta( $this->daymark_id, '_daymark_primary_type', $type );

		$attachment_id = self::factory()->attachment->create_object(
			__DIR__ . '/e2e/fixtures/' . $fixture_filename,
			$this->daymark_id,
			array( 'post_mime_type' => $mime )
		);

		update_post_meta( $this->daymark_id, '_daymark_media_ids', wp_json_encode( array( $attachment_id ) ) );
	}

	/** A pure audio Mark gets an inline, unlinked <audio> player. */
	public function test_audio_mark_gets_inline_player() {
		$this->attach_media( 'audio', 'audio/wav', 'test-audio.wav' );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertMatchesRegularExpression( '/<audio class="daymark-item-player daymark-item-player--audio" controls preload="metadata" src="[^"]+test-audio\.wav"><\/audio>/', $html );
		$this->assertStringNotContainsString( 'daymark-item-media', $html, 'Audio uses the player, not the linked-thumbnail wrapper' );
	}

	/** A pure video Mark gets an inline, unlinked <video> player. */
	public function test_video_mark_gets_inline_player() {
		// No real video fixture in the repo; the mime type alone is enough
		// to exercise the branch — item_player() never opens the file.
		$this->attach_media( 'video', 'video/mp4', 'test-video.mp4' );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertMatchesRegularExpression( '/<video class="daymark-item-player daymark-item-player--video" controls preload="metadata" src="[^"]+test-video\.mp4"><\/video>/', $html );
		$this->assertStringNotContainsString( 'daymark-item-media', $html );
	}

	/** An audio/video Mark with no attached media renders no player and no broken tag. */
	public function test_audio_mark_without_media_renders_no_player() {
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'audio' );
		update_post_meta( $this->daymark_id, '_daymark_media_ids', wp_json_encode( array() ) );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringNotContainsString( '<audio', $html );
	}

	/** An audio Mark whose stored attachment doesn't actually match audio/* renders no player. */
	public function test_audio_mark_with_mismatched_attachment_renders_no_player() {
		$this->attach_media( 'audio', 'image/png', 'test-image.png' );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringNotContainsString( '<audio', $html );
	}

	/** An image Mark is unaffected: still the linked thumbnail, never a player. */
	public function test_image_mark_still_uses_linked_thumbnail() {
		$attachment_id = self::factory()->attachment->create_object(
			__DIR__ . '/e2e/fixtures/test-image.png',
			$this->daymark_id,
			array( 'post_mime_type' => 'image/png' )
		);
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'image' );
		update_post_meta( $this->daymark_id, '_daymark_media_ids', wp_json_encode( array( $attachment_id ) ) );

		$html = ( new Daymark_Renderer() )->render( 'timeline' );

		$this->assertStringContainsString( 'daymark-item-media', $html );
		$this->assertStringNotContainsString( '<audio', $html );
		$this->assertStringNotContainsString( '<video', $html );
	}
}
