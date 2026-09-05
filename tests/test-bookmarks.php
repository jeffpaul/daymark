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
}
