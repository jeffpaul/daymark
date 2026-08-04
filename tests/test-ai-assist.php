<?php
/**
 * AI Assist tests — E2E scenario 6 (AI is optional, mock is deterministic).
 *
 * @package Daymark
 */

/**
 * Tests the Daymark_AI_Assist adapter mock fallback contract.
 */
class Test_AI_Assist extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Force the mock path: core kill switch off.
		add_filter( 'wp_supports_ai', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_supports_ai', '__return_false' );
		parent::tear_down();
	}

	/** Mock suggestions contain the full contract keys. */
	public function test_mock_suggestions_have_required_keys() {
		$ai          = new Daymark_AI_Assist();
		$suggestions = $ai->get_suggestions(
			array(
				'text'        => 'Morning walk in the park',
				'media_count' => 1,
				'media_types' => array( 'image' ),
			)
		);

		$this->assertArrayHasKey( 'caption', $suggestions );
		$this->assertArrayHasKey( 'alt_text', $suggestions );
		$this->assertArrayHasKey( 'tags', $suggestions );
		$this->assertArrayHasKey( 'is_mocked', $suggestions );
		$this->assertArrayHasKey( 'provider_label', $suggestions );
		$this->assertTrue( $suggestions['is_mocked'] );
		$this->assertEquals( 'Demo Mode', $suggestions['provider_label'] );
	}

	/** Mock suggestions are deterministic: same input, same output. */
	public function test_mock_suggestions_are_deterministic() {
		$context = array(
			'text'        => 'Morning walk in the park',
			'media_count' => 1,
			'media_types' => array( 'image' ),
		);

		$first  = ( new Daymark_AI_Assist() )->get_suggestions( $context );
		$second = ( new Daymark_AI_Assist() )->get_suggestions( $context );

		$this->assertSame( $first, $second );
	}

	/** Publishing never requires AI. */
	public function test_publishing_does_not_require_ai() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'No AI test',
				'primary_type' => 'note',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertEquals( '0', get_post_meta( $post_id, '_daymark_ai_assist_used', true ) );
	}
}
