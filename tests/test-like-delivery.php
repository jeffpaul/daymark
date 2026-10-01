<?php
/**
 * Tests for Daymark_Like_Delivery's pure logic: the Webmention delivery
 * state read from the Webmention plugin's own post meta, and the
 * "no mechanism at all" availability short-circuit.
 *
 * The real Jetpack classes are never loaded in tests, so the Jetpack route
 * is always unavailable here; the Webmention plugin is never active unless
 * a test fakes it (see Test_Rest_Subscription_Like).
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Like_Delivery.
 */
class Test_Like_Delivery extends WP_UnitTestCase {

	/** @var string */
	private $target = 'https://origin.example/a-post/';

	/**
	 * @return int A fresh post standing in for a local Like/Comment Mark.
	 */
	private function mark(): int {
		return (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function test_state_is_empty_without_a_mark() {
		$this->assertSame( '', Daymark_Like_Delivery::webmention_state( 0, $this->target ) );
	}

	public function test_state_is_not_sent_when_nothing_tried_to_send() {
		$this->assertSame( Daymark_Like_Delivery::STATE_NOT_SENT, Daymark_Like_Delivery::webmention_state( $this->mark(), $this->target ) );
	}

	public function test_state_is_pending_while_queued() {
		$mark = $this->mark();
		update_post_meta( $mark, '_mentionme', '1' );

		$this->assertSame( Daymark_Like_Delivery::STATE_PENDING, Daymark_Like_Delivery::webmention_state( $mark, $this->target ) );
	}

	public function test_state_is_sent_when_the_target_was_notified_ignoring_trailing_slash() {
		$mark = $this->mark();
		update_post_meta( $mark, '_webmentioned', array( 'https://other.example/', 'https://origin.example/a-post' ) );
		update_post_meta( $mark, '_webmention_content_hash', 'abc' );

		$this->assertSame( Daymark_Like_Delivery::STATE_SENT, Daymark_Like_Delivery::webmention_state( $mark, $this->target ) );
	}

	public function test_sent_wins_over_a_pending_resend() {
		$mark = $this->mark();
		update_post_meta( $mark, '_webmentioned', array( $this->target ) );
		update_post_meta( $mark, '_mentionme', '1' );

		$this->assertSame( Daymark_Like_Delivery::STATE_SENT, Daymark_Like_Delivery::webmention_state( $mark, $this->target ) );
	}

	public function test_state_is_failed_when_a_send_ran_without_notifying_the_target() {
		$mark = $this->mark();
		update_post_meta( $mark, '_webmentioned', array( 'https://other.example/' ) );
		update_post_meta( $mark, '_webmention_content_hash', 'abc' );

		$this->assertSame( Daymark_Like_Delivery::STATE_FAILED, Daymark_Like_Delivery::webmention_state( $mark, $this->target ) );
	}

	public function test_jetpack_route_always_reports_sent() {
		$this->assertSame( Daymark_Like_Delivery::STATE_SENT, Daymark_Like_Delivery::like_state( true, 0, $this->target ) );
		$this->assertSame( Daymark_Like_Delivery::STATE_SENT, Daymark_Like_Delivery::comment_state( true, 0, $this->target ) );
	}

	public function test_nothing_to_report_without_any_engagement() {
		$this->assertSame( '', Daymark_Like_Delivery::like_state( false, 0, $this->target ) );
		$this->assertSame( '', Daymark_Like_Delivery::comment_state( false, 0, $this->target ) );
	}

	public function test_no_mechanism_exists_by_default() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse( Daymark_Like_Delivery::mechanisms_exist() );
		$this->assertFalse( Daymark_Like_Delivery::cached_availability( $this->target ) );
	}

	public function test_resolve_is_unavailable_without_a_mechanism() {
		$result = Daymark_Like_Delivery::resolve( 123456 );

		$this->assertFalse( $result['available'] );
		$this->assertSame( '', $result['method'] );
	}
}
