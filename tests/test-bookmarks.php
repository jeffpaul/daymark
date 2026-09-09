<?php
/**
 * Tests for Daymark_Bookmarks (issue #193): per-user bookmark set
 * membership, backed by multi-value `daymark_bookmark` user meta.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Bookmarks directly.
 */
class Test_Bookmarks extends WP_UnitTestCase {

	/** @var int */
	private $user_a;

	/** @var int */
	private $user_b;

	/** @var Daymark_Bookmarks */
	private $bookmarks;

	public function set_up(): void {
		parent::set_up();

		$this->user_a    = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->user_b    = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->bookmarks = new Daymark_Bookmarks();
	}

	public function test_not_bookmarked_by_default() {
		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
	}

	public function test_add_then_is_bookmarked() {
		$this->bookmarks->add( $this->user_a, 123 );

		$this->assertTrue( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
	}

	public function test_add_is_idempotent() {
		$this->bookmarks->add( $this->user_a, 123 );
		$this->bookmarks->add( $this->user_a, 123 );

		$this->assertSame( array( 123 ), $this->bookmarks->get_ids( $this->user_a ) );
	}

	public function test_remove() {
		$this->bookmarks->add( $this->user_a, 123 );
		$this->bookmarks->remove( $this->user_a, 123 );

		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
	}

	public function test_remove_when_never_bookmarked_is_a_safe_no_op() {
		$this->assertFalse( $this->bookmarks->remove( $this->user_a, 123 ) );
	}

	public function test_bookmarks_are_scoped_per_user() {
		$this->bookmarks->add( $this->user_a, 123 );

		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_b, 123 ) );
	}

	public function test_get_ids_returns_newest_bookmark_first() {
		$this->bookmarks->add( $this->user_a, 1 );
		$this->bookmarks->add( $this->user_a, 2 );
		$this->bookmarks->add( $this->user_a, 3 );

		$this->assertSame( array( 3, 2, 1 ), $this->bookmarks->get_ids( $this->user_a ) );
	}

	public function test_get_ids_empty_for_a_user_with_no_bookmarks() {
		$this->assertSame( array(), $this->bookmarks->get_ids( $this->user_a ) );
	}

	// -----------------------------------------------------------------
	// clear_bookmarks_for_post() — cleanup once a bookmarked post is gone
	// -----------------------------------------------------------------

	public function test_clear_bookmarks_for_post_removes_it_for_every_user() {
		$this->bookmarks->add( $this->user_a, 123 );
		$this->bookmarks->add( $this->user_b, 123 );

		$this->bookmarks->clear_bookmarks_for_post( 123 );

		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_b, 123 ) );
	}

	public function test_clear_bookmarks_for_post_leaves_other_bookmarks_alone() {
		$this->bookmarks->add( $this->user_a, 123 );
		$this->bookmarks->add( $this->user_a, 456 );

		$this->bookmarks->clear_bookmarks_for_post( 123 );

		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
		$this->assertTrue( $this->bookmarks->is_bookmarked( $this->user_a, 456 ) );
	}

	public function test_clear_bookmarks_for_post_is_a_safe_no_op_for_an_unbookmarked_post() {
		$this->bookmarks->clear_bookmarks_for_post( 123 );

		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
	}

	public function test_clear_bookmarks_for_post_ignores_an_invalid_id() {
		$this->bookmarks->add( $this->user_a, 123 );

		$this->bookmarks->clear_bookmarks_for_post( 0 );

		$this->assertTrue( $this->bookmarks->is_bookmarked( $this->user_a, 123 ) );
	}

	/**
	 * register()'s deleted_post hook actually fires cleanup once a post is
	 * truly removed — the real end-to-end path an unsubscribe's eventual
	 * trash-retention purge, or a Mark's own "Delete Permanently", takes.
	 */
	public function test_register_clears_bookmark_when_post_is_actually_deleted() {
		$this->bookmarks->register();

		$post_id = self::factory()->post->create();
		$this->bookmarks->add( $this->user_a, $post_id );

		wp_delete_post( $post_id, true );

		$this->assertFalse( $this->bookmarks->is_bookmarked( $this->user_a, $post_id ) );
	}

	/**
	 * Trashing alone (not yet a real deletion) must not clear a bookmark —
	 * a trashed-but-recoverable post (e.g. right after DELETE /marks/{id},
	 * or a subscription just unsubscribed from) is still a real post a
	 * user could reasonably expect their bookmark of to survive until it's
	 * actually gone for good.
	 */
	public function test_register_does_not_clear_bookmark_on_trash_alone() {
		$this->bookmarks->register();

		$post_id = self::factory()->post->create();
		$this->bookmarks->add( $this->user_a, $post_id );

		wp_trash_post( $post_id );

		$this->assertTrue( $this->bookmarks->is_bookmarked( $this->user_a, $post_id ) );
	}
}
