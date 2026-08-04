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
}
