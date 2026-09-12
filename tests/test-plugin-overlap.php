<?php
/**
 * Tests for Daymark_Plugin_Overlap (issue #346): detecting an active
 * overlapping IndieWeb plugin, and per-user dismissal — backed by
 * multi-value `daymark_plugin_overlap_dismissed` user meta, mirroring
 * Daymark_Bookmarks' own convention.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Plugin_Overlap directly.
 */
class Test_Plugin_Overlap extends WP_UnitTestCase {

	/** @var int */
	private $user_a;

	/** @var int */
	private $user_b;

	/** @var Daymark_Plugin_Overlap */
	private $overlap;

	public function set_up(): void {
		parent::set_up();

		$this->user_a  = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->user_b  = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->overlap = new Daymark_Plugin_Overlap();
	}

	// -----------------------------------------------------------------
	// is_known()
	// -----------------------------------------------------------------

	public function test_is_known_true_for_every_documented_plugin_key() {
		foreach ( array( 'post-kinds', 'microformats2', 'syndication-links', 'indieblocks' ) as $key ) {
			$this->assertTrue( $this->overlap->is_known( $key ), "{$key} should be a known overlap key" );
		}
	}

	public function test_is_known_false_for_an_unrecognized_key() {
		$this->assertFalse( $this->overlap->is_known( 'not-a-real-plugin' ) );
	}

	// -----------------------------------------------------------------
	// get_active_overlaps() — deliberately never defines a real plugin's
	// own detection class/constant here: a PHP class or constant can
	// never be undefined once declared, so doing that would leak into
	// every other test that runs afterward in the same PHPUnit process
	// (this is exactly the anti-pattern class-plugin-detector-stub.php's
	// own docblock already warns against, and what an earlier version of
	// this test actually did, breaking an unrelated REST permissions
	// test). tests/test-notifications.php exercises "an overlap is
	// active" behavior via a swappable fake instance instead — see
	// tests/class-plugin-overlap-fake.php.
	// -----------------------------------------------------------------

	/** With none of the four plugins' real signals present, nothing is reported active. */
	public function test_get_active_overlaps_is_empty_by_default() {
		$this->assertSame( array(), $this->overlap->get_active_overlaps() );
	}

	/**
	 * The private OVERLAPS map itself carries a label and non-empty
	 * overlap description for every documented plugin key — read directly
	 * via Reflection rather than by triggering real detection, so this
	 * needs no live signal at all.
	 */
	public function test_overlaps_map_has_label_and_description_for_every_known_key() {
		$constant = new ReflectionClassConstant( Daymark_Plugin_Overlap::class, 'OVERLAPS' );
		$overlaps = $constant->getValue();

		foreach ( array( 'post-kinds', 'microformats2', 'syndication-links', 'indieblocks' ) as $key ) {
			$this->assertArrayHasKey( $key, $overlaps );
			$this->assertNotEmpty( $overlaps[ $key ]['label'] );
			$this->assertNotEmpty( $overlaps[ $key ]['overlaps'] );
		}
	}

	// -----------------------------------------------------------------
	// is_dismissed() / dismiss() / get_dismissed()
	// -----------------------------------------------------------------

	public function test_not_dismissed_by_default() {
		$this->assertFalse( $this->overlap->is_dismissed( $this->user_a, 'post-kinds' ) );
	}

	public function test_dismiss_then_is_dismissed() {
		$this->overlap->dismiss( $this->user_a, 'post-kinds' );

		$this->assertTrue( $this->overlap->is_dismissed( $this->user_a, 'post-kinds' ) );
	}

	public function test_dismiss_is_idempotent() {
		$this->overlap->dismiss( $this->user_a, 'post-kinds' );
		$this->overlap->dismiss( $this->user_a, 'post-kinds' );

		$this->assertSame( array( 'post-kinds' ), $this->overlap->get_dismissed( $this->user_a ) );
	}

	public function test_dismissal_is_scoped_per_plugin() {
		$this->overlap->dismiss( $this->user_a, 'post-kinds' );

		$this->assertFalse( $this->overlap->is_dismissed( $this->user_a, 'microformats2' ) );
	}

	public function test_dismissal_is_scoped_per_user() {
		$this->overlap->dismiss( $this->user_a, 'post-kinds' );

		$this->assertFalse( $this->overlap->is_dismissed( $this->user_b, 'post-kinds' ) );
	}

	public function test_get_dismissed_empty_for_a_user_with_no_dismissals() {
		$this->assertSame( array(), $this->overlap->get_dismissed( $this->user_a ) );
	}

	public function test_dismiss_ignores_an_unknown_or_empty_plugin_key() {
		// dismiss() itself doesn't validate is_known() — that check lives
		// at the REST layer — but an empty key is always rejected outright.
		$this->overlap->dismiss( $this->user_a, '' );

		$this->assertSame( array(), $this->overlap->get_dismissed( $this->user_a ) );
	}
}
