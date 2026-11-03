<?php
/**
 * Tests for AVIF and HEIC/HEIF uploads (issue #481), full-size GIFs
 * (issue #482), and sound files and recordings.
 *
 * The HEIC and AVIF fixtures are tiny real images made with macOS `sips`.
 * This test environment's PHP usually has no Imagick, so the server can't
 * convert HEIC here: the default refusal is tested as-is, and acceptance by
 * filtering `daymark_accept_heic_uploads`. The sound and video fixtures are
 * described in tests/test-media-container.php.
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
	 * @param string               $path Path to the file.
	 * @param string               $name Uploaded file name.
	 * @param string               $type Browser-reported type.
	 * @param array<string, mixed> $data More publish data (primary_type).
	 * @return int|WP_Error
	 */
	private function publish_file( string $path, string $name, string $type, array $data = array() ) {
		return ( new Daymark_Publisher() )->publish(
			array_merge( array( 'caption' => 'Format test' ), $data ),
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

	// -----------------------------------------------------------------
	// Sound files and recordings
	// -----------------------------------------------------------------

	/**
	 * Fixture, uploaded name, browser type, and the type it's stored as.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public function data_sound_files(): array {
		return array(
			// WordPress calls every .m4a file audio/mpeg; browsers play it anyway.
			'Voice Memo (.m4a)'                     => array( 'test-audio.m4a', 'Voice Memo.m4a', 'audio/x-m4a', 'audio/mpeg' ),
			'AAC sound in a file named .mp4'        => array( 'test-audio.m4a', 'clip.mp4', 'video/mp4', 'audio/mp4' ),
			'AAC (.aac)'                            => array( 'test-audio.aac', 'clip.aac', 'audio/aac', 'audio/aac' ),
			'Ogg'                                   => array( 'test-audio.ogg', 'clip.ogg', 'audio/ogg', 'audio/ogg' ),
			'Chrome recording (.webm)'              => array( 'test-recording.webm', 'Recording.webm', 'audio/webm', 'audio/webm' ),
			'Chrome recording, streamed (.webm)'    => array( 'test-recording-streamed.webm', 'Recording.webm', 'audio/webm', 'audio/webm' ),
			'MP4 recording named .m4a'              => array( 'test-recording.mp4', 'Recording.m4a', 'audio/mp4', 'audio/mp4' ),
			'MP4 recording named .mp4'              => array( 'test-recording.mp4', 'Recording.mp4', 'audio/mp4', 'audio/mp4' ),
			'MP4 recording the browser calls video' => array( 'test-recording.mp4', 'Recording.mp4', 'video/mp4', 'audio/mp4' ),
		);
	}

	/**
	 * @dataProvider data_sound_files
	 *
	 * @param string $fixture Fixture.
	 * @param string $name    Uploaded file name.
	 * @param string $type    Browser-reported type.
	 * @param string $stored  Expected attachment type.
	 */
	public function test_sound_publishes_as_an_audio_mark( string $fixture, string $name, string $type, string $stored ): void {
		$post_id = $this->publish_file( $this->copy_fixture( $fixture ), $name, $type );

		$this->assertIsInt( $post_id, is_wp_error( $post_id ) ? $post_id->get_error_message() : '' );

		$attachment_id = $this->first_media_id( $post_id );

		$this->assertSame( 'audio', get_post_meta( $post_id, '_daymark_primary_type', true ) );
		$this->assertSame( $stored, get_post_mime_type( $attachment_id ) );
		$this->assertStringEndsWith( '.' . pathinfo( $name, PATHINFO_EXTENSION ), (string) get_attached_file( $attachment_id ), 'The file keeps its name.' );
		$this->assertStringContainsString( '<!-- wp:audio', (string) get_post_field( 'post_content', $post_id ) );
	}

	public function test_a_video_with_sound_stays_a_video_mark(): void {
		$post_id = $this->publish_file( $this->copy_fixture( 'test-video.mp4' ), 'clip.mp4', 'video/mp4' );

		$this->assertIsInt( $post_id );
		$this->assertSame( 'video', get_post_meta( $post_id, '_daymark_primary_type', true ) );
		$this->assertSame( 'video/mp4', get_post_mime_type( $this->first_media_id( $post_id ) ) );
	}

	public function test_a_webm_video_is_refused_with_a_reason(): void {
		$result = $this->publish_file( $this->copy_fixture( 'test-video.webm' ), 'clip.webm', 'video/webm' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_mime', $result->get_error_code() );
		$this->assertSame( "Daymark can't publish WebM video. Please use MP4 or MOV.", $result->get_error_message() );
	}

	public function test_a_recording_is_refused_where_the_site_turns_off_webm(): void {
		$filter = static function ( $mimes ) {
			unset( $mimes['webm'] );

			return $mimes;
		};

		add_filter( 'upload_mimes', $filter );

		try {
			$result = $this->publish_file( $this->copy_fixture( 'test-recording.webm' ), 'Recording.webm', 'audio/webm' );
		} finally {
			remove_filter( 'upload_mimes', $filter );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_mime', $result->get_error_code() );
		$this->assertSame( 'File type not allowed.', $result->get_error_message() );
	}

	/** A composer that went by a file's name may call a recording video; sound alone is an Audio Mark. */
	public function test_sound_sent_as_video_is_an_audio_mark(): void {
		foreach ( array( 'video', 'mixed' ) as $requested ) {
			$post_id = $this->publish_file( $this->copy_fixture( 'test-recording.webm' ), 'Recording.webm', 'video/webm', array( 'primary_type' => $requested ) );

			$this->assertIsInt( $post_id );
			$this->assertSame( 'audio', get_post_meta( $post_id, '_daymark_primary_type', true ), $requested );
		}

		$video = $this->publish_file( $this->copy_fixture( 'test-video.mp4' ), 'clip.mp4', 'video/mp4', array( 'primary_type' => 'video' ) );

		$this->assertSame( 'video', get_post_meta( $video, '_daymark_primary_type', true ) );
	}

	/**
	 * While WordPress reads a recording's details, an unlimited memory limit
	 * is capped (a streamed WebM recording otherwise makes it try to read the
	 * whole file at once), then put back.
	 */
	public function test_an_unlimited_memory_limit_is_capped_while_reading_a_recording(): void {
		$original = (string) ini_get( 'memory_limit' );
		$during   = null;
		$capture  = static function ( $metadata ) use ( &$during ) {
			$during = (string) ini_get( 'memory_limit' );

			return $metadata;
		};

		ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The case under test.
		add_filter( 'wp_generate_attachment_metadata', $capture );

		try {
			$post_id = $this->publish_file( $this->copy_fixture( 'test-recording-streamed.webm' ), 'Recording.webm', 'audio/webm' );
			$after   = (string) ini_get( 'memory_limit' );
		} finally {
			remove_filter( 'wp_generate_attachment_metadata', $capture );
			ini_set( 'memory_limit', $original ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the test runner's limit.
		}

		$this->assertIsInt( $post_id );
		$this->assertNotNull( $during, 'WordPress read the file.' );
		$this->assertGreaterThan( 0, wp_convert_hr_to_bytes( $during ) );
		$this->assertSame( '-1', $after );
	}

	public function test_a_set_memory_limit_is_left_alone(): void {
		$original = (string) ini_get( 'memory_limit' );
		$limit    = ( (int) ceil( memory_get_usage( true ) / MB_IN_BYTES ) + 512 ) . 'M';
		$during   = null;
		$capture  = static function ( $metadata ) use ( &$during ) {
			$during = (string) ini_get( 'memory_limit' );

			return $metadata;
		};

		ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The case under test.
		add_filter( 'wp_generate_attachment_metadata', $capture );

		try {
			$this->publish_file( $this->copy_fixture( 'test-recording.webm' ), 'Recording.webm', 'audio/webm' );
		} finally {
			remove_filter( 'wp_generate_attachment_metadata', $capture );
			ini_set( 'memory_limit', $original ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the test runner's limit.
		}

		$this->assertSame( $limit, $during );
	}

	/**
	 * An upload's temporary file has no extension, so the AI Client can't
	 * name its type; AI Assist passes the type read from the content.
	 */
	public function test_ai_assist_names_a_files_type_from_its_content(): void {
		// Like PHP's own upload names (/tmp/phpAbC123): no extension.
		$path = get_temp_dir() . 'daymark-sound-' . wp_generate_password( 8, false );
		copy( __DIR__ . '/e2e/fixtures/test-audio.ogg', $path );

		$this->assertSame( '', pathinfo( $path, PATHINFO_EXTENSION ) );

		$file_mime = new ReflectionMethod( Daymark_AI_Assist::class, 'file_mime' );
		$ai        = Daymark_Plugin::instance()->ai_assist;

		$this->assertSame( 'audio/ogg', $file_mime->invoke( $ai, $path, '' ) );
		$this->assertSame( 'audio/webm', $file_mime->invoke( $ai, $path, 'audio/webm' ), 'A type the caller already read is used as is.' );

		$file_class = '\WordPress\AiClient\Files\DTO\File';

		if ( class_exists( $file_class ) ) {
			try {
				new $file_class( $path );
				$this->fail( 'The AI Client named the type of a file with no extension.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'MIME type', $e->getMessage() );
			}

			$this->assertSame( 'audio/ogg', ( new $file_class( $path, 'audio/ogg' ) )->getMimeType() );
		}

		wp_delete_file( $path );
	}

	// -----------------------------------------------------------------
	// Which files the composer lets start uploading
	// -----------------------------------------------------------------

	public function test_accepted_extensions_cover_what_daymark_publishes(): void {
		add_filter( 'daymark_accept_heic_uploads', '__return_false' );

		$extensions = Daymark_Uploads::accepted_extensions();

		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'm4v', 'mov', 'mp3', 'm4a', 'wav', 'aac', 'ogg', 'oga', 'webm' ) as $ext ) {
			$this->assertContains( $ext, $extensions, $ext );
		}

		foreach ( array( 'php', 'txt', 'pdf', 'zip', 'flac', 'opus', 'mkv', 'avi', 'heic' ) as $ext ) {
			$this->assertNotContains( $ext, $extensions, $ext );
		}
	}

	public function test_accepted_extensions_include_heic_only_where_it_converts(): void {
		add_filter( 'daymark_accept_heic_uploads', '__return_true' );

		$this->assertContains( 'heic', Daymark_Uploads::accepted_extensions() );
	}

	public function test_accepted_extensions_drop_a_type_the_site_turns_off(): void {
		$filter = static function ( $mimes ) {
			unset( $mimes['webm'] );

			return $mimes;
		};

		add_filter( 'upload_mimes', $filter );

		try {
			$this->assertNotContains( 'webm', Daymark_Uploads::accepted_extensions() );
		} finally {
			remove_filter( 'upload_mimes', $filter );
		}
	}

	public function test_app_config_lists_the_extensions_an_upload_can_start_with(): void {
		$this->assertSame( Daymark_Uploads::accepted_extensions(), Daymark_Routes::build_app_config()['uploadExtensions'] );
	}
}
