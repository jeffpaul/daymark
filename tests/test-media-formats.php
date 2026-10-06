<?php
/**
 * Tests for AVIF and HEIC/HEIF uploads (issue #481) and full-size GIFs
 * (issue #482).
 *
 * The HEIC and AVIF fixtures are tiny real images made with macOS `sips`.
 * This test environment's PHP usually has no Imagick, so the server can't
 * convert HEIC here: the default refusal is tested as-is, and acceptance by
 * filtering `daymark_accept_heic_uploads`.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Publisher's media-format handling end to end.
 */
class Test_Media_Formats extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'daymark_accept_heic_uploads' );

		parent::tear_down();
	}

	/**
	 * A disposable copy of a fixture, since sideloading moves the file.
	 *
	 * @param string $name Fixture file name in tests/e2e/fixtures/.
	 * @return string
	 */
	private function copy_fixture( string $name ): string {
		$tmp = wp_tempnam( 'daymark-format-' ) . '.' . pathinfo( $name, PATHINFO_EXTENSION );
		copy( __DIR__ . '/e2e/fixtures/' . $name, $tmp );

		return $tmp;
	}

	/**
	 * Publish one file as a Mark.
	 *
	 * @param string $path Path to the file.
	 * @param string $name Uploaded file name.
	 * @param string $type Browser-reported type.
	 * @return int|WP_Error
	 */
	private function publish_file( string $path, string $name, string $type ) {
		return ( new Daymark_Publisher() )->publish(
			array( 'caption' => 'Format test' ),
			array(
				'files' => array(
					'name'     => $name,
					'type'     => $type,
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $path ),
				),
			)
		);
	}

	/**
	 * The Mark's one attachment ID.
	 *
	 * @param int $post_id Mark ID.
	 * @return int
	 */
	private function first_media_id( int $post_id ): int {
		$ids = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );

		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * A GIF wider than every default image size, so WordPress makes
	 * resized copies of it.
	 *
	 * @return string
	 */
	private function wide_gif(): string {
		$tmp   = wp_tempnam( 'daymark-gif-' ) . '.gif';
		$image = imagecreatetruecolor( 1400, 40 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 80, 20 ) );
		imagegif( $image, $tmp );

		return $tmp;
	}

	// -----------------------------------------------------------------
	// AVIF and HEIC (#481)
	// -----------------------------------------------------------------

	public function test_avif_publishes_as_an_image_mark() {
		$post_id = $this->publish_file( $this->copy_fixture( 'test-image.avif' ), 'photo.avif', 'image/avif' );

		$this->assertIsInt( $post_id );
		$this->assertSame( 'image', get_post_meta( $post_id, '_daymark_primary_type', true ) );
		$this->assertSame( 'image/avif', get_post_mime_type( $this->first_media_id( $post_id ) ) );
	}

	public function test_heic_is_refused_when_the_server_cannot_convert_it() {
		add_filter( 'daymark_accept_heic_uploads', '__return_false' );
		$before = count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);

		$result = $this->publish_file( $this->copy_fixture( 'test-image.heic' ), 'IMG_0001.HEIC', 'image/heic' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_heic_unsupported', $result->get_error_code() );
		$this->assertCount(
			$before,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			),
			'Nothing is stored.'
		);
	}

	public function test_heic_is_accepted_when_the_server_can_convert_it() {
		add_filter( 'daymark_accept_heic_uploads', '__return_true' );

		$post_id = $this->publish_file( $this->copy_fixture( 'test-image.heic' ), 'IMG_0001.HEIC', 'image/heic' );

		$this->assertIsInt( $post_id );
		$this->assertSame( 'image', get_post_meta( $post_id, '_daymark_primary_type', true ) );
	}

	public function test_heic_support_defaults_to_the_servers_image_editor() {
		$this->assertSame(
			wp_image_editor_supports( array( 'mime_type' => 'image/heic' ) ),
			Daymark_Publisher::accepts_heic()
		);
	}

	public function test_app_config_tells_the_composer_about_heic() {
		add_filter( 'daymark_accept_heic_uploads', '__return_true' );
		$this->assertTrue( Daymark_Routes::build_app_config()['heicUploads'] );

		remove_all_filters( 'daymark_accept_heic_uploads' );
		add_filter( 'daymark_accept_heic_uploads', '__return_false' );
		$this->assertFalse( Daymark_Routes::build_app_config()['heicUploads'] );
	}

	public function test_a_text_file_named_heic_is_refused() {
		add_filter( 'daymark_accept_heic_uploads', '__return_true' );

		$path = wp_tempnam( 'daymark-fake-' ) . '.heic';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $path, 'not really a photo' );

		$result = $this->publish_file( $path, 'fake.heic', 'image/heic' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_mime', $result->get_error_code() );
	}

	public function test_alt_text_is_not_requested_for_an_avif() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/alt-text' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_file_params(
			array(
				'image' => array(
					'name'     => 'photo.avif',
					'type'     => 'image/avif',
					'tmp_name' => $this->copy_fixture( 'test-image.avif' ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( __DIR__ . '/e2e/fixtures/test-image.avif' ),
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'daymark_alt_text_format_unsupported', $response->get_data()['code'] );
	}

	// -----------------------------------------------------------------
	// Full-size GIFs (#482)
	// -----------------------------------------------------------------

	public function test_display_size_keeps_gifs_at_full_size() {
		$gif_post = $this->publish_file( $this->wide_gif(), 'wide.gif', 'image/gif' );
		$png_post = $this->publish_file( $this->copy_fixture( 'test-image.png' ), 'shot.png', 'image/png' );

		$this->assertSame( 'full', Daymark_Publisher::display_size( $this->first_media_id( $gif_post ), 'medium_large' ) );
		$this->assertSame( 'medium_large', Daymark_Publisher::display_size( $this->first_media_id( $png_post ), 'medium_large' ) );
	}

	public function test_a_gif_marks_image_block_uses_the_original_file() {
		$post_id       = $this->publish_file( $this->wide_gif(), 'wide.gif', 'image/gif' );
		$attachment_id = $this->first_media_id( $post_id );
		$content       = (string) get_post_field( 'post_content', $post_id );

		$this->assertStringContainsString( '"sizeSlug":"full"', $content );
		$this->assertStringContainsString( 'src="' . esc_url( wp_get_attachment_url( $attachment_id ) ) . '"', $content );
	}

	public function test_a_gif_marks_card_thumbnail_is_the_original_file() {
		$post_id       = $this->publish_file( $this->wide_gif(), 'wide.gif', 'image/gif' );
		$attachment_id = $this->first_media_id( $post_id );

		$this->assertNotEmpty( wp_get_attachment_metadata( $attachment_id )['sizes'] ?? array(), 'WordPress made resized copies.' );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$items = array_column( rest_do_request( $request )->get_data(), null, 'id' );

		$this->assertSame( wp_get_attachment_url( $attachment_id ), $items[ $post_id ]['thumbnail'] );
	}
}
