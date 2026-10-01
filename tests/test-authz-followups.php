<?php
/**
 * Authorization and data-exposure follow-ups from the whole-plugin audit run
 * after issue #438:
 *
 *   - a password-protected post's body is not served to a user who cannot
 *     edit it;
 *   - another user's quietly captured coordinates are not returned to
 *     every Author;
 *   - the share-target route requires `upload_files` before accepting a
 *     file, as the REST routes do.
 *
 * @package Daymark
 */

/**
 * Exercises the three follow-up fixes.
 */
class Test_Authz_Followups extends WP_UnitTestCase {

	/** @var int */
	private $owner;

	/** @var int */
	private $other_author;

	/** @var int */
	private $contributor;

	public function set_up(): void {
		parent::set_up();

		$this->owner        = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->contributor  = (int) self::factory()->user->create( array( 'role' => 'contributor' ) );
	}

	public function tear_down(): void {
		unset( $_FILES['media'] );
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tear_down();
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param string $route Route under /daymark/v1.
	 * @return WP_REST_Request
	 */
	private function request_for( string $route ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/daymark/v1' . $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * Create a published post, optionally a Mark with a captured location.
	 *
	 * @param int                  $author Post author.
	 * @param array<string,string> $meta   Post meta to set.
	 * @param array<string,mixed>  $args   Extra wp_insert_post() arguments.
	 * @return int
	 */
	private function create_post( int $author, array $meta = array(), array $args = array() ): int {
		$post_id = (int) self::factory()->post->create(
			array_merge(
				array(
					'post_author'  => $author,
					'post_status'  => 'publish',
					'post_content' => '<p>The secret body.</p>',
				),
				$args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/** Someone who cannot edit a password-protected post cannot read its body. */
	public function test_password_protected_post_content_is_refused_to_other_users() {
		$post_id = $this->create_post( $this->owner, array(), array( 'post_password' => 'hunter2' ) );

		wp_set_current_user( $this->other_author );

		$response = rest_do_request( $this->request_for( "/marks/{$post_id}/content" ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'daymark_password_protected', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'The secret body', (string) wp_json_encode( $response->get_data() ) );
	}

	/** A Contributor is refused too. */
	public function test_password_protected_post_content_is_refused_to_a_contributor() {
		$post_id = $this->create_post( $this->owner, array(), array( 'post_password' => 'hunter2' ) );

		wp_set_current_user( $this->contributor );

		$this->assertSame( 403, rest_do_request( $this->request_for( "/marks/{$post_id}/content" ) )->get_status() );
	}

	/** The post's own author, who can edit it, still reads it. */
	public function test_password_protected_post_content_is_served_to_someone_who_can_edit_it() {
		$post_id = $this->create_post( $this->owner, array(), array( 'post_password' => 'hunter2' ) );

		wp_set_current_user( $this->owner );

		$response = rest_do_request( $this->request_for( "/marks/{$post_id}/content" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'The secret body', $response->get_data()['content'] );
	}

	/** An ordinary post is unaffected. */
	public function test_unprotected_post_content_is_still_served_to_other_users() {
		$post_id = $this->create_post( $this->owner );

		wp_set_current_user( $this->other_author );

		$this->assertSame( 200, rest_do_request( $this->request_for( "/marks/{$post_id}/content" ) )->get_status() );
	}

	/**
	 * Find one item in a GET /timeline response.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	private function timeline_item( int $post_id ): ?array {
		$response = rest_do_request( $this->request_for( '/timeline' ) );

		$this->assertSame( 200, $response->get_status() );

		foreach ( $response->get_data() as $item ) {
			if ( ( $item['id'] ?? 0 ) === $post_id ) {
				return $item;
			}
		}

		return null;
	}

	/** Another user's exact coordinates are not sent to an Author. */
	public function test_other_users_coordinates_are_withheld() {
		$post_id = $this->create_post(
			$this->owner,
			array(
				'_daymark_is_mark'      => '1',
				'_daymark_primary_type' => 'image',
				'_daymark_location'     => wp_json_encode(
					array(
						'lat' => 43.0731,
						'lng' => -89.4012,
					)
				),
			)
		);

		wp_set_current_user( $this->other_author );

		$item = $this->timeline_item( $post_id );

		$this->assertNotNull( $item, 'The Mark is on the shared Timeline' );
		$this->assertArrayNotHasKey( 'location', $item );
	}

	/** The Mark's own author still gets them. */
	public function test_own_coordinates_are_returned() {
		$post_id = $this->create_post(
			$this->owner,
			array(
				'_daymark_is_mark'      => '1',
				'_daymark_primary_type' => 'image',
				'_daymark_location'     => wp_json_encode(
					array(
						'lat' => 43.0731,
						'lng' => -89.4012,
					)
				),
			)
		);

		wp_set_current_user( $this->owner );

		$item = $this->timeline_item( $post_id );

		$this->assertNotNull( $item );
		$this->assertEqualsWithDelta( 43.0731, $item['location']['lat'], 0.0001 );
	}

	/** A Check In's location is one the author chose to share, so it stays visible. */
	public function test_a_checkin_location_stays_visible_to_others() {
		$post_id = $this->create_post(
			$this->owner,
			array(
				'_daymark_is_mark'      => '1',
				'_daymark_primary_type' => 'checkin',
				'_daymark_place_name'   => 'Camp Randall Stadium',
				'_daymark_location'     => wp_json_encode(
					array(
						'lat' => 43.0699,
						'lng' => -89.4128,
					)
				),
			)
		);

		wp_set_current_user( $this->other_author );

		$item = $this->timeline_item( $post_id );

		$this->assertNotNull( $item );
		$this->assertArrayHasKey( 'location', $item );
	}

	/**
	 * A text-only share can arrive with an empty `media[]` field; that is not
	 * an upload and must not need `upload_files`.
	 */
	public function test_an_empty_file_field_is_not_an_upload() {
		$empty_single = array(
			'media' => array(
				'name'     => '',
				'type'     => '',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_NO_FILE,
				'size'     => 0,
			),
		);
		$empty_multi  = array(
			'media' => array(
				'name'     => array( '' ),
				'type'     => array( '' ),
				'tmp_name' => array( '' ),
				'error'    => array( UPLOAD_ERR_NO_FILE ),
				'size'     => array( 0 ),
			),
		);

		$this->assertFalse( Daymark_Share_Target::has_actual_upload( array() ) );
		$this->assertFalse( Daymark_Share_Target::has_actual_upload( $empty_single ) );
		$this->assertFalse( Daymark_Share_Target::has_actual_upload( $empty_multi ) );
	}

	/** A real file, alone or beside an empty slot, is an upload. */
	public function test_a_real_file_is_an_upload() {
		$single = array( 'media' => array( 'error' => UPLOAD_ERR_OK ) );
		$multi  = array( 'media' => array( 'error' => array( UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK ) ) );
		$failed = array( 'media' => array( 'error' => UPLOAD_ERR_INI_SIZE ) );

		$this->assertTrue( Daymark_Share_Target::has_actual_upload( $single ) );
		$this->assertTrue( Daymark_Share_Target::has_actual_upload( $multi ) );
		$this->assertTrue( Daymark_Share_Target::has_actual_upload( $failed ), 'A rejected upload attempt still counts as one' );
	}

	/** A Contributor cannot upload through the share target. */
	public function test_share_target_refuses_files_without_upload_files() {
		wp_set_current_user( $this->contributor );

		$this->assertFalse( current_user_can( 'upload_files' ), 'Precondition: a Contributor cannot upload' );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'Precondition: a Contributor can edit posts' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_FILES['media']           = array(
			'name'     => array( 'photo.jpg' ),
			'type'     => array( 'image/jpeg' ),
			'tmp_name' => array( '/tmp/does-not-matter' ),
			'error'    => array( 0 ),
			'size'     => array( 1024 ),
		);

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'permission to upload files' );

		( new Daymark_Share_Target() )->handle();
	}
}
