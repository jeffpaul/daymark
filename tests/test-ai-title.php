<?php
/**
 * AI-suggested title for audio / podcast / video Moments.
 *
 * Reworked from #19 by @yusuf-saif: the AI Assist bundle now includes a
 * title, and the publisher applies an AI-suggested title to post_title for
 * audio, podcast, and video Moments only (other types keep the existing
 * caption/timestamp-derived title). AI is optional — with no provider
 * configured these tests exercise the deterministic mock path.
 *
 * @package Moment
 */

/**
 * Tests the title suggestion and its type-scoped application.
 */
class Test_AI_Title extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
	}

	/** The mock suggestion bundle includes a non-empty title. */
	public function test_mock_bundle_includes_title() {
		$ai          = new Moment_AI_Assist();
		$suggestions = $ai->get_suggestions(
			array(
				'text'        => 'Morning walk in the park',
				'media_count' => 0,
				'media_types' => array(),
			)
		);

		$this->assertArrayHasKey( 'title', $suggestions );
		$this->assertNotSame( '', $suggestions['title'] );
		$this->assertTrue( $suggestions['is_mocked'] );
	}

	/**
	 * Audio, podcast, and video Moments get the AI title, which (via the mock)
	 * keeps the full caption text; the caption/timestamp fallback used by other
	 * types trims to eight words. A caption of ten short words (< 60 chars)
	 * makes the difference observable: the ninth word survives only on the AI
	 * path.
	 */
	public function test_av_types_get_ai_title() {
		$publisher = new Moment_Publisher();
		$caption   = 'alpha beta gamma delta epsilon zeta eta theta iota kappa';

		foreach ( array( 'audio', 'podcast', 'video' ) as $type ) {
			$post_id = $publisher->publish(
				array(
					'caption'      => $caption,
					'primary_type' => $type,
				)
			);

			$this->assertIsInt( $post_id, "$type Moment should publish" );
			$this->assertStringContainsString(
				'iota',
				get_post( $post_id )->post_title,
				"$type Moment should use the fuller AI title"
			);
		}
	}

	/** Note (and other non-AV types) keep the eight-word derived title. */
	public function test_note_keeps_derived_title() {
		$publisher = new Moment_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'alpha beta gamma delta epsilon zeta eta theta iota kappa',
				'primary_type' => 'note',
			)
		);

		$this->assertStringNotContainsString(
			'iota',
			get_post( $post_id )->post_title,
			'A note keeps the 8-word derived title, not the AI title'
		);
	}

	/** An explicit title always wins, even for audio/podcast/video. */
	public function test_explicit_title_wins() {
		$publisher = new Moment_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'this caption is ignored for the title',
				'primary_type' => 'video',
				'title'        => 'My Explicit Title',
			)
		);

		$this->assertSame( 'My Explicit Title', get_post( $post_id )->post_title );
	}
}
