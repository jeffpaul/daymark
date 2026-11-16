<?php
/**
 * Crop and rotate from the composer's photo sheet: the edit a Mark save
 * names for one of its photos is made on the site, from the original file,
 * which is kept.
 *
 * @package Daymark
 */

/**
 * Daymark_Image_Edit, and how a Mark save uses it.
 */
class Test_Image_Edit extends WP_UnitTestCase {

	/**
	 * The saving author.
	 *
	 * @var int
	 */
	private int $author;

	public function set_up(): void {
		parent::set_up();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	/**
	 * A 400 x 200 PNG: left half red, right half blue.
	 *
	 * @return string Temp path.
	 */
	private function wide_png(): string {
		$tmp   = wp_tempnam( 'daymark-edit-' ) . '.png';
		$image = imagecreatetruecolor( 400, 200 );

		imagefilledrectangle( $image, 0, 0, 199, 199, imagecolorallocate( $image, 255, 0, 0 ) );
		imagefilledrectangle( $image, 200, 0, 399, 199, imagecolorallocate( $image, 0, 0, 255 ) );
		imagepng( $image, $tmp );

		return $tmp;
	}

	/**
	 * A $_FILES-style `files[]` array holding one PNG.
	 *
	 * @param string $path Temp path.
	 * @return array<string, array<string, mixed>>
	 */
	private function files_array( string $path ): array {
		return array(
			'files' => array(
				'name'     => array( 'photo.png' ),
				'type'     => array( 'image/png' ),
				'tmp_name' => array( $path ),
				'error'    => array( UPLOAD_ERR_OK ),
				'size'     => array( (int) filesize( $path ) ),
			),
		);
	}

	/**
	 * A draft Mark with the 400 x 200 photo; returns [ post ID, attachment ID ].
	 *
	 * @return int[]
	 */
	private function draft_with_photo(): array {
		$post_id = Daymark_Plugin::instance()->publisher->publish(
			array(
				'caption' => 'A wide photo',
				'status'  => 'draft',
			),
			$this->files_array( $this->wide_png() )
		);

		$this->assertIsInt( $post_id );

		$media = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );

		return array( $post_id, (int) $media[0] );
	}

	/**
	 * Save the draft again, naming an edit for its photo.
	 *
	 * @param int                  $post_id       The Mark.
	 * @param int                  $attachment_id Its photo.
	 * @param array<string, mixed> $edit          The edit.
	 * @return void
	 */
	private function save_with_edit( int $post_id, int $attachment_id, array $edit ): void {
		$result = Daymark_Plugin::instance()->publisher->update(
			$post_id,
			array(
				'caption'     => 'A wide photo',
				'status'      => 'draft',
				'media_edits' => wp_json_encode( array( (string) $attachment_id => $edit ) ),
			)
		);

		$this->assertSame( $post_id, $result );
	}

	/**
	 * The attachment's current width and height.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int[]
	 */
	private function size_of( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$real = getimagesize( (string) get_attached_file( $attachment_id ) );

		// What WordPress says and what the file is must agree.
		$this->assertSame( array( (int) $meta['width'], (int) $meta['height'] ), array( $real[0], $real[1] ) );

		return array( (int) $meta['width'], (int) $meta['height'] );
	}

	/**
	 * Whether the pixel at a point of the attachment's file is mostly red
	 * or mostly blue.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $x             X.
	 * @param int $y             Y.
	 * @return string 'red' or 'blue'.
	 */
	private function color_at( int $attachment_id, int $x, int $y ): string {
		$image = imagecreatefrompng( (string) get_attached_file( $attachment_id ) );
		$rgb   = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );

		return $rgb['red'] > $rgb['blue'] ? 'red' : 'blue';
	}

	/** An edit is tidied to a known shape and a quarter turn. */
	public function test_an_edit_is_tidied_to_a_known_shape_and_quarter_turn(): void {
		$this->assertSame(
			array(
				'shape'  => 'square',
				'rotate' => 90,
			),
			Daymark_Image_Edit::sanitize(
				array(
					'shape'  => 'square',
					'rotate' => '90',
				)
			)
		);
		$this->assertSame(
			array(
				'shape'  => 'original',
				'rotate' => 0,
			),
			Daymark_Image_Edit::sanitize(
				array(
					'shape'  => 'circle',
					'rotate' => 45,
				)
			)
		);
		$this->assertSame(
			array(
				'shape'  => 'tall',
				'rotate' => 270,
			),
			Daymark_Image_Edit::sanitize(
				array(
					'shape'  => 'tall',
					'rotate' => 630,
				)
			)
		);
		$this->assertSame(
			array(
				'shape'  => 'original',
				'rotate' => 0,
			),
			Daymark_Image_Edit::sanitize( 'nonsense' )
		);
	}

	/** Each shape is cut from the middle of the photo. */
	public function test_each_shape_is_cut_from_the_middle(): void {
		$this->assertSame( array( 100, 0, 200, 200 ), Daymark_Image_Edit::crop_box( 400, 200, 'square' ) );
		$this->assertSame( array( 120, 0, 160, 200 ), Daymark_Image_Edit::crop_box( 400, 200, 'tall' ) );
		$this->assertSame( array( 22, 0, 356, 200 ), Daymark_Image_Edit::crop_box( 400, 200, 'wide' ) );
		$this->assertSame( array( 0, 143, 200, 113 ), Daymark_Image_Edit::crop_box( 200, 400, 'wide' ) );
		$this->assertNull( Daymark_Image_Edit::crop_box( 400, 200, 'original' ) );
		$this->assertNull( Daymark_Image_Edit::crop_box( 200, 200, 'square' ) );
	}

	/** Square keeps the middle of a wide photo, and the Mark shows the new file. */
	public function test_square_keeps_the_middle_and_the_mark_shows_it(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$original_file = (string) get_attached_file( $attachment_id );
		$original_url  = (string) wp_get_attachment_url( $attachment_id );

		$this->save_with_edit( $post_id, $attachment_id, array( 'shape' => 'square' ) );

		$this->assertSame( array( 200, 200 ), $this->size_of( $attachment_id ) );
		// The middle of the photo: red to the left of center, blue to the right.
		$this->assertSame( 'red', $this->color_at( $attachment_id, 50, 100 ) );
		$this->assertSame( 'blue', $this->color_at( $attachment_id, 150, 100 ) );

		$edited_url = (string) wp_get_attachment_url( $attachment_id );
		$content    = (string) get_post_field( 'post_content', $post_id );

		$this->assertNotSame( $original_url, $edited_url );
		$this->assertStringContainsString( $edited_url, $content );
		$this->assertStringNotContainsString( $original_url, $content );

		// The original stays on the site, where WordPress's own "Restore
		// original image" looks for it.
		$this->assertFileExists( $original_file );
		$backup = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$this->assertSame( basename( $original_file ), $backup['full-orig']['file'] );
		$this->assertSame( array( 400, 200 ), array( (int) $backup['full-orig']['width'], (int) $backup['full-orig']['height'] ) );
	}

	/** Rotate turns the photo a quarter turn clockwise. */
	public function test_rotate_turns_the_photo_clockwise(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$this->save_with_edit( $post_id, $attachment_id, array( 'rotate' => 90 ) );

		$this->assertSame( array( 200, 400 ), $this->size_of( $attachment_id ) );
		// What was on the left is now on top.
		$this->assertSame( 'red', $this->color_at( $attachment_id, 100, 50 ) );
		$this->assertSame( 'blue', $this->color_at( $attachment_id, 100, 350 ) );
	}

	/** A shape is cut from the photo as it stands after turning. */
	public function test_shape_is_cut_after_turning(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$this->save_with_edit(
			$post_id,
			$attachment_id,
			array(
				'shape'  => 'wide',
				'rotate' => 90,
			)
		);

		$this->assertSame( array( 200, 113 ), $this->size_of( $attachment_id ) );
	}

	/** Saving the same edit again leaves the file alone. */
	public function test_same_edit_again_leaves_the_file_alone(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$this->save_with_edit( $post_id, $attachment_id, array( 'shape' => 'square' ) );
		$first = (string) get_attached_file( $attachment_id );

		$this->save_with_edit( $post_id, $attachment_id, array( 'shape' => 'square' ) );

		$this->assertSame( $first, (string) get_attached_file( $attachment_id ) );
	}

	/** A second edit starts from the original, and the first edit's file goes. */
	public function test_second_edit_starts_from_the_original(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$original_file = (string) get_attached_file( $attachment_id );

		$this->save_with_edit( $post_id, $attachment_id, array( 'shape' => 'square' ) );
		$square_file = (string) get_attached_file( $attachment_id );

		$this->save_with_edit( $post_id, $attachment_id, array( 'shape' => 'wide' ) );

		// 356 wide can only come from the 400-wide original, not the square.
		$this->assertSame( array( 356, 200 ), $this->size_of( $attachment_id ) );
		$this->assertFileDoesNotExist( $square_file );
		$this->assertFileExists( $original_file );
	}

	/** Back to Original with no turn puts the original file back. */
	public function test_original_puts_the_original_back(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$original_file = (string) get_attached_file( $attachment_id );
		$original_url  = (string) wp_get_attachment_url( $attachment_id );

		$this->save_with_edit( $post_id, $attachment_id, array( 'shape' => 'square' ) );
		$square_file = (string) get_attached_file( $attachment_id );

		$this->save_with_edit(
			$post_id,
			$attachment_id,
			array(
				'shape'  => 'original',
				'rotate' => 0,
			)
		);

		$this->assertSame( $original_file, (string) get_attached_file( $attachment_id ) );
		$this->assertSame( array( 400, 200 ), $this->size_of( $attachment_id ) );
		$this->assertFileDoesNotExist( $square_file );
		$this->assertSame( '', (string) get_post_meta( $attachment_id, Daymark_Image_Edit::META, true ) );
		$this->assertStringContainsString( $original_url, (string) get_post_field( 'post_content', $post_id ) );
	}

	/** A photo nobody edited is never touched, whatever a save says about "original". */
	public function test_unedited_photo_is_left_alone(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$original_file = (string) get_attached_file( $attachment_id );

		$this->save_with_edit(
			$post_id,
			$attachment_id,
			array(
				'shape'  => 'original',
				'rotate' => 0,
			)
		);

		$this->assertSame( $original_file, (string) get_attached_file( $attachment_id ) );
		$this->assertSame( '', (string) get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) );
	}

	/** The photo's date and camera details survive an edit. */
	public function test_camera_details_survive_an_edit(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$meta                                    = wp_get_attachment_metadata( $attachment_id );
		$meta['image_meta']['camera']            = 'Test Camera';
		$meta['image_meta']['created_timestamp'] = 1700000000;
		wp_update_attachment_metadata( $attachment_id, $meta );

		$this->save_with_edit( $post_id, $attachment_id, array( 'rotate' => 180 ) );

		$after = wp_get_attachment_metadata( $attachment_id );

		$this->assertSame( 'Test Camera', $after['image_meta']['camera'] );
		$this->assertSame( 1700000000, (int) $after['image_meta']['created_timestamp'] );
	}

	/** An edit can only name the Mark's own photos. */
	public function test_edit_cannot_reach_another_posts_photo(): void {
		list( $post_id )    = $this->draft_with_photo();
		list( , $other_id ) = $this->draft_with_photo();

		$other_file = (string) get_attached_file( $other_id );

		$this->save_with_edit( $post_id, $other_id, array( 'shape' => 'square' ) );

		$this->assertSame( $other_file, (string) get_attached_file( $other_id ) );
		$this->assertSame( array( 400, 200 ), $this->size_of( $other_id ) );
	}

	/** A photo uploaded ahead of the save is edited when the Mark is first made. */
	public function test_staged_upload_is_edited_when_the_mark_is_made(): void {
		$attachment_id = (int) self::factory()->attachment->create_upload_object( $this->wide_png() );
		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_author' => $this->author,
			)
		);
		update_post_meta( $attachment_id, Daymark_Uploads::STAGED_META, $this->author );
		update_post_meta( $attachment_id, Daymark_Uploads::STAGED_AT_META, time() );

		$post_id = Daymark_Plugin::instance()->publisher->publish(
			array(
				'caption'     => 'Staged and squared',
				'status'      => 'draft',
				'media_ids'   => array( $attachment_id ),
				'media_edits' => array( $attachment_id => array( 'shape' => 'square' ) ),
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( array( 200, 200 ), $this->size_of( $attachment_id ) );
		$this->assertStringContainsString( (string) wp_get_attachment_url( $attachment_id ), (string) get_post_field( 'post_content', $post_id ) );
	}

	/** An animated-capable GIF is never offered for editing or edited. */
	public function test_a_gif_is_not_edited(): void {
		$this->assertNotContains( 'image/gif', Daymark_Image_Edit::editable_types() );
		$this->assertContains( 'image/png', Daymark_Image_Edit::editable_types() );
	}

	/** The composer is told each photo's edit and where its original is. */
	public function test_composer_gets_each_photos_edit_and_original(): void {
		list( $post_id, $attachment_id ) = $this->draft_with_photo();

		$original_url = (string) wp_get_attachment_url( $attachment_id );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'caption'     => 'A wide photo',
				'status'      => 'draft',
				'media_edits' => wp_json_encode(
					array(
						(string) $attachment_id => array(
							'shape'  => 'tall',
							'rotate' => 0,
						),
					)
				),
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( array( 160, 200 ), $this->size_of( $attachment_id ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$media = rest_get_server()->dispatch( $request )->get_data()['media'][0];

		$this->assertTrue( $media['editable'] );
		$this->assertSame(
			array(
				'shape'  => 'tall',
				'rotate' => 0,
			),
			$media['edit']
		);
		$this->assertSame( $original_url, $media['original'] );
	}

	/** A photo with no edit says so, and names no original. */
	public function test_composer_gets_no_edit_for_an_unedited_photo(): void {
		list( $post_id ) = $this->draft_with_photo();

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$media = rest_get_server()->dispatch( $request )->get_data()['media'][0];

		$this->assertTrue( $media['editable'] );
		$this->assertArrayNotHasKey( 'edit', $media );
		$this->assertArrayNotHasKey( 'original', $media );
	}

	/** The app is told which photo types the site can edit. */
	public function test_app_config_lists_editable_types(): void {
		$this->assertSame( Daymark_Image_Edit::editable_types(), Daymark_Routes::build_app_config()['imageEdit']['types'] );
	}
}
