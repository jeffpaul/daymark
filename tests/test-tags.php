<?php
/**
 * Tags on a Mark: set on publish, replaced on update, returned for the
 * composer to prefill.
 *
 * @package Daymark
 */

/**
 * Tag assignment through the publisher and REST.
 */
class Test_Tags extends WP_UnitTestCase {

	/**
	 * A publishing author.
	 *
	 * @var int
	 */
	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	private function tag_names( int $post_id ): array {
		$names = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
		sort( $names );
		return $names;
	}

	private function draft_with_tags( array $tags ): int {
		return (int) ( new Daymark_Publisher() )->publish(
			array(
				'caption'      => 'A sunset',
				'primary_type' => 'image',
				'status'       => 'draft',
				'tags'         => $tags,
			)
		);
	}

	/** Publishing with tags files the Mark under them. */
	public function test_publish_assigns_tags() {
		$post_id = $this->draft_with_tags( array( 'sunset', 'travel' ) );

		$this->assertSame( array( 'sunset', 'travel' ), $this->tag_names( $post_id ) );
	}

	/** With tags_set, an update replaces the tags, so a removed tag goes away. */
	public function test_update_with_tags_set_replaces_tags() {
		$post_id = $this->draft_with_tags( array( 'sunset', 'travel' ) );

		( new Daymark_Publisher() )->update(
			$post_id,
			array(
				'caption'  => 'A sunset',
				'tags'     => array( 'beach' ),
				'tags_set' => true,
			)
		);

		$this->assertSame( array( 'beach' ), $this->tag_names( $post_id ) );
	}

	/** With tags_set and no tags, an update clears them. */
	public function test_update_with_tags_set_and_no_tags_clears_them() {
		$post_id = $this->draft_with_tags( array( 'sunset' ) );

		( new Daymark_Publisher() )->update(
			$post_id,
			array(
				'caption'  => 'A sunset',
				'tags'     => array(),
				'tags_set' => true,
			)
		);

		$this->assertSame( array(), $this->tag_names( $post_id ) );
	}

	/** Without tags_set (an older client or API caller), tags are only added. */
	public function test_update_without_tags_set_only_adds() {
		$post_id = $this->draft_with_tags( array( 'sunset' ) );

		( new Daymark_Publisher() )->update(
			$post_id,
			array(
				'caption' => 'A sunset',
				'tags'    => array( 'beach' ),
			)
		);

		$this->assertSame( array( 'beach', 'sunset' ), $this->tag_names( $post_id ) );
	}

	/** PUT /marks/{id} passes tags_set through to the publisher. */
	public function test_rest_update_replaces_tags_when_tags_set() {
		$post_id = $this->draft_with_tags( array( 'sunset', 'travel' ) );

		$request = new WP_REST_Request( 'PUT', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'A sunset' );
		$request->set_param( 'tags', array( 'travel' ) );
		$request->set_param( 'tags_set', '1' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'travel' ), $this->tag_names( $post_id ) );
	}

	/** GET /marks/{id} returns the tags, so a resumed draft starts from them. */
	public function test_get_mark_returns_tags() {
		$post_id = $this->draft_with_tags( array( 'sunset', 'travel' ) );

		$request = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$tags = rest_do_request( $request )->get_data()['tags'];
		sort( $tags );

		$this->assertSame( array( 'sunset', 'travel' ), $tags );
	}
}
