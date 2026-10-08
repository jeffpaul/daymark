<?php
/**
 * Daymark_Post_Export tests: the `daymark` field other Daymark sites read
 * from `wp/v2/posts`.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Post_Export.
 */
class Test_Post_Export extends WP_UnitTestCase {

	/**
	 * A published post with optional meta.
	 *
	 * @param array<string, string> $meta   Post meta.
	 * @param array<string, mixed>  $fields Post fields.
	 * @return int
	 */
	private function make_post( array $meta = array(), array $fields = array() ): int {
		$post_id = self::factory()->post->create( $fields + array( 'post_status' => 'publish' ) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/** The field is on `wp/v2/posts` for anyone, with a Reblog Mark's target. */
	public function test_rest_response_carries_a_reblog() {
		$post_id = $this->make_post(
			array(
				'_daymark_is_mark'      => '1',
				'_daymark_primary_type' => 'note',
				'_daymark_repost_of'    => 'https://other.example/post/',
			)
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$field    = $response->get_data()['daymark'] ?? null;

		$this->assertIsArray( $field );
		$this->assertSame( Daymark_Post_Export::VERSION, $field['version'] );
		$this->assertSame( 'note', $field['type'] );
		$this->assertSame(
			array(
				'type' => 'repost',
				'url'  => 'https://other.example/post/',
			),
			$field['interaction']
		);
		$this->assertNull( $field['featured_content'] );
	}

	/** A Check In shares its place name but never its coordinates. */
	public function test_checkin_shares_place_name_not_location() {
		$post_id = $this->make_post(
			array(
				'_daymark_is_mark'      => '1',
				'_daymark_primary_type' => 'checkin',
				'_daymark_place_name'   => 'Wildcat Stadium',
				'_daymark_location'     => wp_json_encode(
					array(
						'lat' => 1.5,
						'lng' => 2.5,
					)
				),
			)
		);

		$field = Daymark_Post_Export::data_for_post( $post_id );

		$this->assertSame( 'checkin', $field['type'] );
		$this->assertSame( 'Wildcat Stadium', $field['place_name'] );
		$this->assertStringNotContainsString( '1.5', (string) wp_json_encode( $field ) );
	}

	/** An ordinary post has no Mark type but still shares its Featured Content. */
	public function test_ordinary_post_shares_featured_content() {
		$post_id = $this->make_post(
			array(
				Daymark_Featured_Content::META_TYPE => 'quote',
				Daymark_Featured_Content::META_DATA => wp_json_encode(
					array(
						'quote' => array(
							'text'   => 'Be kind.',
							'author' => 'Ada',
						),
					)
				),
			)
		);

		$field = Daymark_Post_Export::data_for_post( $post_id );

		$this->assertSame( '', $field['type'] );
		$this->assertNull( $field['interaction'] );
		$this->assertSame( 'quote', $field['featured_content']['type'] );
		$this->assertSame( 'Be kind.', $field['featured_content']['text'] );
		$this->assertStringContainsString( 'Ada', $field['featured_content']['credit'] );
	}

	/** Drafts and password-protected posts share nothing. */
	public function test_non_public_posts_share_nothing() {
		$draft     = $this->make_post( array( '_daymark_is_mark' => '1' ), array( 'post_status' => 'draft' ) );
		$protected = $this->make_post( array( '_daymark_is_mark' => '1' ), array( 'post_password' => 'secret' ) );

		$this->assertNull( Daymark_Post_Export::data_for_post( $draft ) );
		$this->assertNull( Daymark_Post_Export::data_for_post( $protected ) );
	}
}
