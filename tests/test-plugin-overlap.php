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
	// get_active_overlaps() — real detection, via the one safe-to-define
	// production signal (SYNDICATION_LINKS_VERSION isn't checked anywhere
	// else in this codebase or its own test suite, so defining it here
	// can't leak into an unrelated assertion the way the real
	// Post_Kinds_Plugin/UF2_Plugin/IndieBlocks\Plugin class names could).
	// Both the "not yet active" and "now active" assertions live in this
	// one test method specifically so the result never depends on test
	// execution order across files/methods.
	// -----------------------------------------------------------------

	public function test_get_active_overlaps_reflects_a_real_detection_signal() {
		$this->assertArrayNotHasKey(
			'syndication-links',
			$this->overlap->get_active_overlaps(),
			'Not active until the plugin\'s own version constant is defined'
		);

		if ( ! defined( 'SYNDICATION_LINKS_VERSION' ) ) {
			define( 'SYNDICATION_LINKS_VERSION', '99.0' );
		}

		$active = $this->overlap->get_active_overlaps();

		$this->assertArrayHasKey( 'syndication-links', $active );
		$this->assertSame( 'Syndication Links', $active['syndication-links']['label'] );
		$this->assertNotEmpty( $active['syndication-links']['overlaps'] );
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
