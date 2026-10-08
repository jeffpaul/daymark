<?php
/**
 * Tests for chunked, resumable composer uploads (issue #483):
 * the /daymark/v1/uploads routes, staged attachments, and how a Mark save
 * names them in media_ids[].
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Uploads through the REST API and the publisher.
 */
class Test_Uploads extends WP_UnitTestCase {

	/** @var int */
	private $author;

	/** @var int */
	private $other_author;

	public function set_up(): void {
		parent::set_up();

		$this->author       = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_author = (int) self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $this->author );
	}

	public function tear_down(): void {
		remove_all_filters( 'daymark_upload_chunk_bytes' );
		remove_all_filters( 'daymark_staged_upload_ttl' );
		remove_all_filters( 'daymark_upload_session_ttl' );
		delete_transient( 'daymark_rl_upload_' . $this->author );

		parent::tear_down();
	}

	/**
	 * A REST request carrying a valid nonce.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * The small PNG test fixture.
	 *
	 * @return string
	 */
	private function fixture(): string {
		return (string) file_get_contents( __DIR__ . '/e2e/fixtures/test-image.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
	}

	/**
	 * Bytes of a valid PCM WAV file with this much audio data.
	 *
	 * @param int $data_bytes Audio data length.
	 * @return string
	 */
	private function wav_bytes( int $data_bytes ): string {
		return 'RIFF' . pack( 'V', 36 + $data_bytes ) . 'WAVE'
			. 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16 )
			. 'data' . pack( 'V', $data_bytes ) . str_repeat( "\0", $data_bytes );
	}

	/**
	 * POST /uploads.
	 *
	 * @param string $name File name.
	 * @param int    $size Size.
	 * @return WP_REST_Response
	 */
	private function start_upload( string $name, int $size ): WP_REST_Response {
		$request = $this->request( 'POST', '/daymark/v1/uploads' );
		$request->set_body_params(
			array(
				'name' => $name,
				'size' => $size,
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * POST one part.
	 *
	 * @param string $id     Session ID.
	 * @param string $bytes  Part.
	 * @param int    $offset Offset.
	 * @param int    $total  File size.
	 * @return WP_REST_Response
	 */
	private function send_part( string $id, string $bytes, int $offset, int $total ): WP_REST_Response {
		$request = $this->request( 'POST', '/daymark/v1/uploads/' . $id );
		$request->set_header( 'Content-Type', 'application/octet-stream' );
		$request->set_header( 'Content-Range', 'bytes ' . $offset . '-' . ( $offset + strlen( $bytes ) - 1 ) . '/' . $total );
		$request->set_body( $bytes );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Upload a whole file in parts; returns the attachment ID.
	 *
	 * @param string $name  File name.
	 * @param string $bytes Contents.
	 * @return int
	 */
	private function upload( string $name, string $bytes ): int {
		$session = $this->start_upload( $name, strlen( $bytes ) );
		$this->assertSame( 201, $session->get_status() );

		$data  = $session->get_data();
		$chunk = (int) $data['chunk_size'];
		$total = strlen( $bytes );
		$sent  = 0;

		do {
			$part     = substr( $bytes, $sent, $chunk );
			$response = $this->send_part( $data['id'], $part, $sent, $total );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$sent += strlen( $part );
		} while ( $sent < $total );

		$finished = $response->get_data();
		$this->assertNotEmpty( $finished['attachment_id'] );

		return (int) $finished['attachment_id'];
	}

	/** A file sent in several parts becomes one staged attachment. */
	public function test_parts_assemble_into_a_staged_attachment(): void {
		add_filter(
			'daymark_upload_chunk_bytes',
			static function () {
				return Daymark_Uploads::MIN_CHUNK_BYTES;
			}
		);

		$bytes = $this->wav_bytes( 600 * 1024 ); // Three parts.

		$session = $this->start_upload( 'clip.wav', strlen( $bytes ) );
		$data    = $session->get_data();
		$this->assertSame( 0, $data['received'] );
		$this->assertSame( Daymark_Uploads::MIN_CHUNK_BYTES, $data['chunk_size'] );
		$this->assertNull( $data['attachment_id'] );

		$first = $this->send_part( $data['id'], substr( $bytes, 0, Daymark_Uploads::MIN_CHUNK_BYTES ), 0, strlen( $bytes ) );
		$this->assertSame( Daymark_Uploads::MIN_CHUNK_BYTES, $first->get_data()['received'] );

		// Resuming: the status route reports what the site has.
		$status = rest_get_server()->dispatch( $this->request( 'GET', '/daymark/v1/uploads/' . $data['id'] ) );
		$this->assertSame( Daymark_Uploads::MIN_CHUNK_BYTES, $status->get_data()['received'] );

		$second = $this->send_part( $data['id'], substr( $bytes, Daymark_Uploads::MIN_CHUNK_BYTES, Daymark_Uploads::MIN_CHUNK_BYTES ), Daymark_Uploads::MIN_CHUNK_BYTES, strlen( $bytes ) );
		$this->assertNull( $second->get_data()['attachment_id'] );

		$offset = 2 * Daymark_Uploads::MIN_CHUNK_BYTES;
		$last   = $this->send_part( $data['id'], substr( $bytes, $offset ), $offset, strlen( $bytes ) );

		$attachment_id = (int) $last->get_data()['attachment_id'];
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( strlen( $bytes ), $last->get_data()['received'] );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( 0, (int) get_post_field( 'post_parent', $attachment_id ) );
		$this->assertSame( $this->author, (int) get_post_field( 'post_author', $attachment_id ) );
		$this->assertSame( $this->author, (int) get_post_meta( $attachment_id, Daymark_Uploads::STAGED_META, true ) );
		$this->assertSame( strlen( $bytes ), filesize( get_attached_file( $attachment_id ) ) );

		// A repeated last part (its response was lost) changes nothing.
		$again = $this->send_part( $data['id'], substr( $bytes, $offset ), $offset, strlen( $bytes ) );
		$this->assertSame( $attachment_id, (int) $again->get_data()['attachment_id'] );
	}

	/** A part at the wrong offset is refused with the site's real count. */
	public function test_out_of_step_part_reports_received_offset(): void {
		add_filter(
			'daymark_upload_chunk_bytes',
			static function () {
				return Daymark_Uploads::MIN_CHUNK_BYTES;
			}
		);

		$bytes = $this->wav_bytes( 400 * 1024 );
		$data  = $this->start_upload( 'clip.wav', strlen( $bytes ) )->get_data();

		$this->send_part( $data['id'], substr( $bytes, 0, Daymark_Uploads::MIN_CHUNK_BYTES ), 0, strlen( $bytes ) );

		// The same first part again, as after a lost response.
		$repeat = $this->send_part( $data['id'], substr( $bytes, 0, Daymark_Uploads::MIN_CHUNK_BYTES ), 0, strlen( $bytes ) );

		$this->assertSame( 409, $repeat->get_status() );
		$this->assertSame( 'daymark_upload_offset_mismatch', $repeat->get_data()['code'] );
		$this->assertSame( Daymark_Uploads::MIN_CHUNK_BYTES, $repeat->get_data()['data']['received'] );
	}

	/** A part whose length doesn't match its Content-Range is refused. */
	public function test_part_length_must_match_its_range(): void {
		$bytes = $this->wav_bytes( 1024 );
		$data  = $this->start_upload( 'clip.wav', strlen( $bytes ) )->get_data();

		$request = $this->request( 'POST', '/daymark/v1/uploads/' . $data['id'] );
		$request->set_header( 'Content-Range', 'bytes 0-9/' . strlen( $bytes ) );
		$request->set_body( $bytes );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/** A file whose bytes aren't an allowed type is refused after its first part. */
	public function test_disguised_file_is_refused_after_the_first_part(): void {
		$bytes = '<?php echo "not audio"; ?>' . str_repeat( ' ', 2000 );
		$data  = $this->start_upload( 'clip.wav', strlen( $bytes ) )->get_data();

		$response = $this->send_part( $data['id'], $bytes, 0, strlen( $bytes ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_mime', $response->get_data()['code'] );

		// The session is gone.
		$status = rest_get_server()->dispatch( $this->request( 'GET', '/daymark/v1/uploads/' . $data['id'] ) );
		$this->assertSame( 404, $status->get_status() );
	}

	/** Size caps are per type: video and audio may be far larger than images. */
	public function test_declared_size_cap_depends_on_type(): void {
		$image = $this->start_upload( 'photo.jpg', Daymark_Publisher::MAX_FILE_BYTES + 1 );
		$this->assertSame( 400, $image->get_status() );
		$this->assertSame( 'daymark_upload_too_large', $image->get_data()['code'] );

		$video = $this->start_upload( 'clip.mp4', 400 * 1024 * 1024 );
		$this->assertSame( 201, $video->get_status() );

		$too_big = $this->start_upload( 'clip.mp4', Daymark_Publisher::MAX_MEDIA_FILE_BYTES + 1 );
		$this->assertSame( 400, $too_big->get_status() );

		$script = $this->start_upload( 'evil.php', 100 );
		$this->assertSame( 400, $script->get_status() );
		$this->assertSame( 'invalid_mime', $script->get_data()['code'] );
	}

	/** Another user can't read, add to, or cancel someone else's upload. */
	public function test_sessions_belong_to_their_owner(): void {
		$bytes = $this->wav_bytes( 1024 );
		$data  = $this->start_upload( 'clip.wav', strlen( $bytes ) )->get_data();

		wp_set_current_user( $this->other_author );

		$this->assertSame( 404, rest_get_server()->dispatch( $this->request( 'GET', '/daymark/v1/uploads/' . $data['id'] ) )->get_status() );
		$this->assertSame( 404, $this->send_part( $data['id'], $bytes, 0, strlen( $bytes ) )->get_status() );
		$this->assertSame( 404, rest_get_server()->dispatch( $this->request( 'DELETE', '/daymark/v1/uploads/' . $data['id'] ) )->get_status() );
	}

	/** Starting an upload needs upload_files, not just edit_posts. */
	public function test_upload_routes_require_upload_files(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$response = $this->start_upload( 'clip.wav', 1024 );

		$this->assertSame( 403, $response->get_status() );
	}

	/** A Mark attaches staged uploads named in media_ids[], with positional alt text. */
	public function test_mark_attaches_staged_uploads(): void {
		$image = $this->upload( 'photo.png', $this->fixture() );
		$audio = $this->upload( 'clip.wav', $this->wav_bytes( 2048 ) );

		$request = $this->request( 'POST', '/daymark/v1/marks' );
		$request->set_body_params(
			array(
				'caption'   => 'Staged',
				'status'    => 'draft',
				'media_ids' => array( $image, $audio ),
				'alt'       => array( 'A tiny square', '' ),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$post_id = (int) $response->get_data()['id'];

		$this->assertSame( array( $image, $audio ), json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true ) );
		$this->assertSame( 'mixed', get_post_meta( $post_id, '_daymark_primary_type', true ) );
		$this->assertSame( $post_id, (int) get_post_field( 'post_parent', $image ) );
		$this->assertSame( 'A tiny square', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $image, Daymark_Uploads::STAGED_META, true ) );
	}

	/** A retried create whose first attempt succeeded points at that Mark, not a new one. */
	public function test_retried_create_reports_the_mark_already_holding_the_file(): void {
		$image = $this->upload( 'photo.png', $this->fixture() );

		$publisher = Daymark_Plugin::instance()->publisher;
		$first     = $publisher->publish(
			array(
				'caption'   => 'Once',
				'status'    => 'draft',
				'media_ids' => array( $image ),
			)
		);
		$this->assertIsInt( $first );

		$second = $publisher->publish(
			array(
				'caption'   => 'Once',
				'status'    => 'draft',
				'media_ids' => array( $image ),
			)
		);

		$this->assertWPError( $second );
		$this->assertSame( 'daymark_media_in_use', $second->get_error_code() );
		$this->assertSame( $first, $second->get_error_data()['post_id'] );
	}

	/** Someone else's file, or a library file never staged, can't be attached. */
	public function test_media_ids_refuse_files_that_are_not_your_staged_uploads(): void {
		wp_set_current_user( $this->other_author );
		$theirs = $this->upload( 'photo.png', $this->fixture() );

		wp_set_current_user( $this->author );
		$publisher = Daymark_Plugin::instance()->publisher;

		$result = $publisher->publish(
			array(
				'caption'   => 'Not mine',
				'media_ids' => array( $theirs ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_media_forbidden', $result->get_error_code() );

		// The author's own library upload that never went through Daymark.
		$library = (int) self::factory()->attachment->create(
			array(
				'post_author'    => $this->author,
				'post_mime_type' => 'image/png',
			)
		);
		$result  = $publisher->publish(
			array(
				'caption'   => 'Library',
				'media_ids' => array( $library ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_media_forbidden', $result->get_error_code() );

		$result = $publisher->publish(
			array(
				'caption'   => 'Gone',
				'media_ids' => array( 999999 ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_upload_expired', $result->get_error_code() );
	}

	/** An update can name files it already holds again, add new ones, and reorder all of them. */
	public function test_update_is_idempotent_and_orders_new_files(): void {
		$fixture   = $this->fixture();
		$first     = $this->upload( 'one.png', $fixture );
		$publisher = Daymark_Plugin::instance()->publisher;
		$post_id   = $publisher->publish(
			array(
				'caption'   => 'Draft',
				'status'    => 'draft',
				'media_ids' => array( $first ),
			)
		);
		$this->assertIsInt( $post_id );

		$second = $this->upload( 'two.png', $fixture );

		$result = $publisher->update(
			$post_id,
			array(
				'caption'     => 'Draft',
				'status'      => 'draft',
				'media_ids'   => array( $first, $second ),
				'media_order' => array( $second, $first ),
			)
		);
		$this->assertSame( $post_id, $result );

		$this->assertSame( array( $second, $first ), json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true ) );
		$this->assertSame( $post_id, (int) get_post_field( 'post_parent', $second ) );
	}

	/** Cancelling a finished, unused upload deletes its attachment. */
	public function test_cancel_deletes_an_unused_upload(): void {
		$bytes = $this->wav_bytes( 1024 );
		$data  = $this->start_upload( 'clip.wav', strlen( $bytes ) )->get_data();
		$done  = $this->send_part( $data['id'], $bytes, 0, strlen( $bytes ) )->get_data();

		$response = rest_get_server()->dispatch( $this->request( 'DELETE', '/daymark/v1/uploads/' . $data['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( get_post( (int) $done['attachment_id'] ) );
	}

	/** Cleanup removes old unused uploads and old sessions, and keeps recent ones. */
	public function test_cleanup_removes_stale_uploads_and_sessions(): void {
		$fixture = $this->fixture();
		$old     = $this->upload( 'old.png', $fixture );
		$fresh   = $this->upload( 'fresh.png', $fixture );
		update_post_meta( $old, Daymark_Uploads::STAGED_AT_META, time() - 8 * DAY_IN_SECONDS );

		$bytes   = $this->wav_bytes( 1024 );
		$session = $this->start_upload( 'clip.wav', strlen( $bytes ) )->get_data();
		$uploads = wp_upload_dir( null, false );
		$json    = trailingslashit( $uploads['basedir'] ) . 'daymark-uploads/' . $session['id'] . '.json';
		$this->assertFileExists( $json );
		touch( $json, time() - 2 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Backdating a local test file.

		Daymark_Plugin::instance()->uploads->cleanup();

		$this->assertNull( get_post( $old ) );
		$this->assertNotNull( get_post( $fresh ) );
		$this->assertFileDoesNotExist( $json );
	}

	/** The parts folder refuses direct web requests where the server honors .htaccess. */
	public function test_parts_folder_is_guarded(): void {
		$this->start_upload( 'clip.wav', 1024 );

		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'daymark-uploads';

		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
	}
}
