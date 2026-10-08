<?php
/**
 * Resumable, chunked uploads for the composer (issue #483).
 *
 * The composer used to send a Mark and every file in one request, so a
 * dropped connection partway through a large video failed the whole request
 * and every byte was sent again. Now each file goes up on its own, in parts,
 * before the Mark is saved:
 *
 * 1. POST /daymark/v1/uploads starts a session for one file.
 * 2. PUT (or POST) /daymark/v1/uploads/{id} appends one part. A part is only
 *    stored when its offset matches the bytes already received, so a retry
 *    after a dropped response can never write the same bytes twice.
 * 3. After the last part, the whole file goes through the publisher's usual
 *    checks and media_handle_sideload(), and becomes an unattached
 *    attachment "staged" for this user.
 * 4. POST/PUT /marks names the staged attachments in `media_ids[]`, and the
 *    publisher attaches them (resolve_media_ids() / release_staged()).
 *
 * Parts are written to a folder under the uploads directory, one session
 * per random 32-character ID: `{id}.json` (owner, name, size, type, and the
 * attachment ID once finished), `{id}.part` (the bytes so far) and
 * `{id}.lock`. A daily cron removes sessions untouched for a day and staged
 * attachments no Mark used within a week.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunked upload sessions and staged attachments.
 */
class Daymark_Uploads {

	/**
	 * Daily cleanup cron hook.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'daymark_uploads_cleanup';

	/**
	 * Attachment meta marking a finished upload no Mark has used yet. The
	 * value is the uploading user's ID.
	 *
	 * @var string
	 */
	public const STAGED_META = '_daymark_staged_upload';

	/**
	 * Attachment meta: Unix time the staged upload finished.
	 *
	 * @var string
	 */
	public const STAGED_AT_META = '_daymark_staged_at';

	/**
	 * Default part size, in bytes.
	 *
	 * Small enough that a dropped connection on a phone costs little, and
	 * under most hosts' request limits. Lowered further when the host's own
	 * post_max_size is smaller; see chunk_size().
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_BYTES = 4 * 1024 * 1024;

	/**
	 * Smallest part size the server accepts as a regular part, in bytes.
	 * The client halves its part size after a 413 from a proxy, down to this.
	 *
	 * @var int
	 */
	public const MIN_CHUNK_BYTES = 256 * 1024;

	/**
	 * Folder under the uploads directory that holds unfinished parts.
	 *
	 * @var string
	 */
	private const DIR_NAME = 'daymark-uploads';

	/**
	 * Session IDs are 32 lowercase hex characters, and nothing else ever
	 * becomes part of a path.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[a-f0-9]{32}$/';

	/**
	 * Register the cleanup cron.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'cleanup' ) );
		// Self-heal the schedule on sites where the plugin was already active
		// when this feature arrived, the same way the backflow sync does.
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/**
	 * Schedule the daily cleanup.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the cleanup schedule (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The part size the client should use, in bytes.
	 *
	 * DEFAULT_CHUNK_BYTES (filterable), kept under the host's post_max_size
	 * with room for headers, and never below MIN_CHUNK_BYTES.
	 *
	 * @return int
	 */
	public static function chunk_size(): int {
		/**
		 * Filters the size of each upload part, in bytes.
		 *
		 * @since 0.20.0
		 *
		 * @param int $bytes Defaults to Daymark_Uploads::DEFAULT_CHUNK_BYTES (4 MB).
		 */
		$size = (int) apply_filters( 'daymark_upload_chunk_bytes', self::DEFAULT_CHUNK_BYTES );

		$post_max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		if ( $post_max > 0 ) {
			$size = min( $size, $post_max - 64 * 1024 );
		}

		return max( self::MIN_CHUNK_BYTES, $size );
	}

	/**
	 * Start an upload session for one file.
	 *
	 * The file name's extension decides the expected type, and the declared
	 * size is checked against that type's cap, so an oversized or
	 * disallowed file is refused before any bytes are sent. The content is
	 * checked again after the first part and once more after the last.
	 *
	 * @param string $name    Original file name.
	 * @param int    $size    Total size in bytes.
	 * @param int    $user_id Uploading user.
	 * @return array<string, mixed>|WP_Error Public session shape (see public_session()).
	 */
	public function create_session( string $name, int $size, int $user_id ) {
		$name = sanitize_file_name( $name );

		if ( '' === $name || $user_id <= 0 ) {
			return new WP_Error(
				'daymark_upload_invalid',
				__( 'The upload is missing a file name.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$check = wp_check_filetype( $name );
		$mime  = Daymark_Publisher::canonical_mime( (string) ( $check['type'] ?? '' ) );

		if ( in_array( $mime, Daymark_Publisher::HEIC_MIME_TYPES, true ) && ! Daymark_Publisher::accepts_heic() ) {
			return new WP_Error(
				'daymark_heic_unsupported',
				__( "This site can't convert HEIC photos. Please share the photo as a JPEG instead.", 'daymark' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $mime || ! in_array( $mime, Daymark_Publisher::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_mime',
				__( 'File type not allowed.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$size_error = $this->check_size( $name, $size, $mime );

		if ( is_wp_error( $size_error ) ) {
			return $size_error;
		}

		$dir = self::dir();

		if ( '' === $dir ) {
			return new WP_Error(
				'daymark_upload_storage',
				__( 'The site could not prepare a place to store the upload.', 'daymark' ),
				array( 'status' => 500 )
			);
		}

		$session = array(
			'id'            => bin2hex( random_bytes( 16 ) ),
			'user_id'       => $user_id,
			'name'          => $name,
			'size'          => $size,
			'type'          => $mime,
			'created'       => time(),
			'attachment_id' => 0,
		);

		if ( ! $this->write_session( $session ) ) {
			return new WP_Error(
				'daymark_upload_storage',
				__( 'The site could not prepare a place to store the upload.', 'daymark' ),
				array( 'status' => 500 )
			);
		}

		return $this->public_session( $session );
	}

	/**
	 * A session's progress, for resuming.
	 *
	 * @param string $id      Session ID.
	 * @param int    $user_id Requesting user.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_session( string $id, int $user_id ) {
		$session = $this->read_owned_session( $id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return $this->public_session( $session );
	}

	/**
	 * Append one part to a session, and finish the upload after the last.
	 *
	 * @param string $id      Session ID.
	 * @param int    $user_id Requesting user.
	 * @param int    $offset  Byte offset this part starts at.
	 * @param int    $total   Total file size the client believes it is sending.
	 * @param string $bytes   The part's raw bytes.
	 * @return array<string, mixed>|WP_Error Public session shape, with attachment_id set once finished.
	 */
	public function append_chunk( string $id, int $user_id, int $offset, int $total, string $bytes ) {
		$session = $this->read_owned_session( $id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$lock = fopen( $this->path( $id, 'lock' ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A lock file needs a real handle for flock().

		if ( false === $lock ) {
			return new WP_Error(
				'daymark_upload_storage',
				__( 'The site could not store this part of the upload.', 'daymark' ),
				array( 'status' => 500 )
			);
		}

		flock( $lock, LOCK_EX );

		try {
			return $this->append_locked( $id, $user_id, $offset, $total, $bytes );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() above.
		}
	}

	/**
	 * Cancel a session: remove its parts, and its attachment too when it
	 * finished but no Mark has used it.
	 *
	 * @param string $id      Session ID.
	 * @param int    $user_id Requesting user.
	 * @return true|WP_Error
	 */
	public function cancel_session( string $id, int $user_id ) {
		$session = $this->read_owned_session( $id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$attachment_id = (int) $session['attachment_id'];

		if ( $attachment_id > 0 && self::is_staged_for( $attachment_id, $user_id ) ) {
			wp_delete_attachment( $attachment_id, true );
		}

		$this->delete_session_files( $id );

		return true;
	}

	/**
	 * Resolve the `media_ids[]` a Mark save names into attachment IDs.
	 *
	 * Every ID must be an attachment uploaded by $user_id that is either:
	 * - staged (finished through this class, not yet used by any Mark), or
	 * - already attached to $post_id (an earlier save of the same Mark).
	 *
	 * Anything else is refused, so a request can't attach someone else's
	 * media, or media already used by another post. Two failures carry
	 * extra meaning for the client:
	 * - `daymark_upload_expired` (410): the attachment no longer exists
	 *   (the cleanup cron removed it). The client uploads it again.
	 * - `daymark_media_in_use` (409, `post_id` in data): it was already
	 *   attached to another Mark by this user. On a create, that means an
	 *   earlier attempt succeeded and only its response was lost; the
	 *   client treats that Mark as the result instead of making a second.
	 *
	 * @param mixed $raw     IDs as an array, or a JSON array string.
	 * @param int   $post_id The Mark being updated, or 0 when creating one.
	 * @param int   $user_id The saving user.
	 * @return array{ids: int[], new: int[]}|WP_Error `ids` is every accepted ID in the
	 *                                                client's order; `new` the staged
	 *                                                ones to attach now.
	 */
	public static function resolve_media_ids( $raw, int $post_id, int $user_id ) {
		$result = array(
			'ids' => array(),
			'new' => array(),
		);

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : ( '' === trim( $raw ) ? array() : array( $raw ) );
		}

		if ( ! is_array( $raw ) ) {
			return $result;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

		foreach ( $ids as $id ) {
			$attachment = get_post( $id );

			if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
				return new WP_Error(
					'daymark_upload_expired',
					__( 'An uploaded file is no longer available. It will be uploaded again.', 'daymark' ),
					array(
						'status'   => 410,
						'media_id' => $id,
					)
				);
			}

			if ( (int) $attachment->post_author !== $user_id || $user_id <= 0 ) {
				return self::forbidden_media_error( $id );
			}

			$parent = (int) $attachment->post_parent;

			if ( $post_id > 0 && $parent === $post_id ) {
				$result['ids'][] = $id;
				continue;
			}

			if ( 0 === $parent && self::is_staged_for( $id, $user_id ) ) {
				$result['ids'][] = $id;
				$result['new'][] = $id;
				continue;
			}

			if ( 0 === $post_id && $parent > 0 && '1' === get_post_meta( $parent, '_daymark_is_mark', true ) && current_user_can( 'edit_post', $parent ) ) {
				return new WP_Error(
					'daymark_media_in_use',
					__( 'This file is already part of another Mark.', 'daymark' ),
					array(
						'status'   => 409,
						'post_id'  => $parent,
						'media_id' => $id,
					)
				);
			}

			return self::forbidden_media_error( $id );
		}

		return $result;
	}

	/**
	 * Clear the staged marker once a Mark has attached these uploads.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return void
	 */
	public static function release_staged( array $attachment_ids ): void {
		foreach ( $attachment_ids as $attachment_id ) {
			delete_post_meta( (int) $attachment_id, self::STAGED_META );
			delete_post_meta( (int) $attachment_id, self::STAGED_AT_META );
		}
	}

	/**
	 * Daily cleanup: sessions nobody touched for a day, and staged
	 * attachments no Mark used within a week.
	 *
	 * A device that stayed offline longer than that still has the file in
	 * its own queue and uploads it again, so nothing is lost.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		/**
		 * Filters how long an unfinished upload session is kept, in seconds,
		 * counted from its last activity.
		 *
		 * @since 0.20.0
		 *
		 * @param int $seconds Defaults to one day.
		 */
		$session_ttl = max( HOUR_IN_SECONDS, (int) apply_filters( 'daymark_upload_session_ttl', DAY_IN_SECONDS ) );

		$dir = self::dir( false );

		if ( '' !== $dir ) {
			$cutoff = time() - $session_ttl;
			// No GLOB_BRACE: it isn't available on every C library (Alpine).
			$files = glob( trailingslashit( $dir ) . '*.json' );

			foreach ( is_array( $files ) ? $files : array() as $file ) {
				$id = (string) pathinfo( $file, PATHINFO_FILENAME );

				if ( ! preg_match( self::ID_PATTERN, $id ) ) {
					continue;
				}

				if ( $this->last_activity( $id ) < $cutoff ) {
					$this->delete_session_files( $id );
				}
			}
		}

		/**
		 * Filters how long a finished upload no Mark has used is kept, in
		 * seconds.
		 *
		 * @since 0.20.0
		 *
		 * @param int $seconds Defaults to seven days.
		 */
		$staged_ttl = max( DAY_IN_SECONDS, (int) apply_filters( 'daymark_staged_upload_ttl', 7 * DAY_IN_SECONDS ) );

		$stale = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_parent'      => 0,
				'fields'           => 'ids',
				'posts_per_page'   => 100,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Daily cron, bounded to 100 rows.
				'meta_query'       => array(
					array(
						'key'     => self::STAGED_AT_META,
						'value'   => time() - $staged_ttl,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $stale as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	/**
	 * The append itself, run while holding the session's lock.
	 *
	 * @param string $id      Session ID.
	 * @param int    $user_id Requesting user.
	 * @param int    $offset  Byte offset this part starts at.
	 * @param int    $total   Total size the client believes it is sending.
	 * @param string $bytes   The part.
	 * @return array<string, mixed>|WP_Error
	 */
	private function append_locked( string $id, int $user_id, int $offset, int $total, string $bytes ) {
		// Re-read under the lock: a concurrent request may have finished it.
		$session = $this->read_owned_session( $id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( (int) $session['attachment_id'] > 0 ) {
			return $this->public_session( $session );
		}

		$size = (int) $session['size'];

		if ( $total !== $size ) {
			return new WP_Error(
				'daymark_upload_size_mismatch',
				__( 'This part does not belong to the file being uploaded.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$part     = $this->path( $id, 'part' );
		$received = $this->received_bytes( $id );

		if ( $offset !== $received ) {
			return new WP_Error(
				'daymark_upload_offset_mismatch',
				__( 'The upload is out of step. It will continue from where the site has it.', 'daymark' ),
				array(
					'status'   => 409,
					'received' => $received,
				)
			);
		}

		$length = strlen( $bytes );

		if ( 0 === $length || $offset + $length > $size ) {
			return new WP_Error(
				'daymark_upload_bad_chunk',
				__( 'This part of the upload is the wrong size.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// A part larger than the size the server handed out. The client
		// only ever sends smaller ones (after a 413), never larger.
		if ( $length > max( self::chunk_size(), self::DEFAULT_CHUNK_BYTES ) ) {
			return new WP_Error(
				'daymark_upload_bad_chunk',
				__( 'This part of the upload is too large.', 'daymark' ),
				array( 'status' => 413 )
			);
		}

		$written = file_put_contents( $part, $bytes, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Appending raw bytes; WP_Filesystem has no append.

		if ( $written !== $length ) {
			// Put the file back to the offset it had, so a retry lines up.
			$this->truncate( $part, $received );

			return new WP_Error(
				'daymark_upload_storage',
				__( 'The site could not store this part of the upload.', 'daymark' ),
				array( 'status' => 500 )
			);
		}

		// Touch the session so cleanup measures from the last activity.
		$this->write_session( $session );

		// After the first part, check the content: a file whose bytes are
		// not an allowed type is refused now, not after the whole upload.
		if ( 0 === $offset ) {
			$content_mime = Daymark_Publisher::sniff_mime( $part );

			if ( '' === $content_mime || ! in_array( $content_mime, Daymark_Publisher::ALLOWED_MIME_TYPES, true ) ) {
				$this->delete_session_files( $id );

				return new WP_Error(
					'invalid_mime',
					__( 'File type not allowed.', 'daymark' ),
					array( 'status' => 400 )
				);
			}

			$size_error = $this->check_size( (string) $session['name'], $size, $content_mime );

			if ( is_wp_error( $size_error ) ) {
				$this->delete_session_files( $id );

				return $size_error;
			}
		}

		if ( $offset + $length < $size ) {
			return $this->public_session( $session );
		}

		return $this->finish( $session );
	}

	/**
	 * Validate the assembled file and add it to the Media Library as an
	 * unattached, staged attachment.
	 *
	 * @param array<string, mixed> $session Session record.
	 * @return array<string, mixed>|WP_Error
	 */
	private function finish( array $session ) {
		$id   = (string) $session['id'];
		$part = $this->path( $id, 'part' );

		$file = array(
			'name'     => (string) $session['name'],
			'type'     => (string) $session['type'],
			'tmp_name' => $part,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) $session['size'],
		);

		$valid = Daymark_Plugin::instance()->publisher->validate_upload( $file );

		if ( is_wp_error( $valid ) ) {
			$this->delete_session_files( $id );

			return $valid;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Moves the part into the uploads folder (copy, then unlink).
		$attachment_id = media_handle_sideload( $file, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			$this->delete_session_files( $id );
			$attachment_id->add_data( array( 'status' => 500 ) );

			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::STAGED_META, (int) $session['user_id'] );
		update_post_meta( $attachment_id, self::STAGED_AT_META, time() );

		$session['attachment_id'] = (int) $attachment_id;
		$this->write_session( $session );
		wp_delete_file( $part );

		return $this->public_session( $session );
	}

	/**
	 * Refuse a declared size above the cap for its type.
	 *
	 * @param string $name File name, for the message.
	 * @param int    $size Size in bytes.
	 * @param string $mime Content (or expected) MIME type.
	 * @return true|WP_Error
	 */
	private function check_size( string $name, int $size, string $mime ) {
		if ( $size < 1 ) {
			return new WP_Error(
				'daymark_upload_invalid',
				__( 'The file is empty.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$max = Daymark_Publisher::max_bytes_for_mime( $mime );

		if ( $size > $max ) {
			return new WP_Error(
				'daymark_upload_too_large',
				sprintf(
					/* translators: 1: file name, 2: maximum upload size (e.g. "50 MB"). */
					__( '"%1$s" is too large. Maximum upload size is %2$s.', 'daymark' ),
					$name,
					size_format( $max )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Whether an attachment is a staged upload belonging to a user.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       User ID.
	 * @return bool
	 */
	private static function is_staged_for( int $attachment_id, int $user_id ): bool {
		return $user_id > 0
			&& (int) get_post_meta( $attachment_id, self::STAGED_META, true ) === $user_id
			&& 0 === (int) get_post_field( 'post_parent', $attachment_id );
	}

	/**
	 * The error for a media ID the user may not attach.
	 *
	 * @param int $id Attachment ID.
	 * @return WP_Error
	 */
	private static function forbidden_media_error( int $id ): WP_Error {
		return new WP_Error(
			'daymark_media_forbidden',
			__( 'You can only add files you uploaded for this Mark.', 'daymark' ),
			array(
				'status'   => 403,
				'media_id' => $id,
			)
		);
	}

	/**
	 * Read a session, refusing anyone but its owner.
	 *
	 * A session that isn't found and one that belongs to someone else get
	 * the same 404, so IDs can't be probed.
	 *
	 * @param string $id      Session ID.
	 * @param int    $user_id Requesting user.
	 * @return array<string, mixed>|WP_Error
	 */
	private function read_owned_session( string $id, int $user_id ) {
		$not_found = new WP_Error(
			'daymark_upload_not_found',
			__( 'This upload is no longer available. It will start again.', 'daymark' ),
			array( 'status' => 404 )
		);

		if ( ! preg_match( self::ID_PATTERN, $id ) || $user_id <= 0 || '' === self::dir( false ) ) {
			return $not_found;
		}

		$path = $this->path( $id, 'json' );

		if ( ! is_readable( $path ) ) {
			return $not_found;
		}

		$session = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		if ( ! is_array( $session ) || (int) ( $session['user_id'] ?? 0 ) !== $user_id || ( $session['id'] ?? '' ) !== $id ) {
			return $not_found;
		}

		return $session;
	}

	/**
	 * The session shape returned to the client.
	 *
	 * @param array<string, mixed> $session Session record.
	 * @return array<string, mixed>
	 */
	private function public_session( array $session ): array {
		$attachment_id = (int) $session['attachment_id'];
		$size          = (int) $session['size'];

		return array(
			'id'            => (string) $session['id'],
			'size'          => $size,
			'received'      => $attachment_id > 0 ? $size : $this->received_bytes( (string) $session['id'] ),
			'chunk_size'    => self::chunk_size(),
			'attachment_id' => $attachment_id > 0 ? $attachment_id : null,
		);
	}

	/**
	 * Bytes stored so far for a session.
	 *
	 * @param string $id Session ID.
	 * @return int
	 */
	private function received_bytes( string $id ): int {
		$part = $this->path( $id, 'part' );
		clearstatcache( true, $part );

		return is_file( $part ) ? (int) filesize( $part ) : 0;
	}

	/**
	 * Shorten a part file back to a known length after a failed write.
	 *
	 * @param string $part   Path.
	 * @param int    $length Length to keep.
	 * @return void
	 */
	private function truncate( string $part, int $length ): void {
		$handle = fopen( $part, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- ftruncate() needs a handle.

		if ( false !== $handle ) {
			ftruncate( $handle, $length );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() above.
		}
	}

	/**
	 * The latest modification time across a session's files.
	 *
	 * @param string $id Session ID.
	 * @return int Unix time, 0 when none exist.
	 */
	private function last_activity( string $id ): int {
		$latest = 0;

		foreach ( array( 'json', 'part', 'lock' ) as $ext ) {
			$path = $this->path( $id, $ext );
			clearstatcache( true, $path );

			if ( is_file( $path ) ) {
				$latest = max( $latest, (int) filemtime( $path ) );
			}
		}

		return $latest;
	}

	/**
	 * Write a session record.
	 *
	 * @param array<string, mixed> $session Session record.
	 * @return bool
	 */
	private function write_session( array $session ): bool {
		$written = file_put_contents( $this->path( (string) $session['id'], 'json' ), (string) wp_json_encode( $session ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local bookkeeping file.

		return false !== $written;
	}

	/**
	 * Delete a session's files.
	 *
	 * @param string $id Session ID.
	 * @return void
	 */
	private function delete_session_files( string $id ): void {
		foreach ( array( 'part', 'json', 'lock' ) as $ext ) {
			$path = $this->path( $id, $ext );

			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Path to one of a session's files.
	 *
	 * @param string $id  Session ID (already checked against ID_PATTERN).
	 * @param string $ext One of json, part, lock.
	 * @return string
	 */
	private function path( string $id, string $ext ): string {
		return trailingslashit( self::dir( false ) ) . $id . '.' . $ext;
	}

	/**
	 * The parts folder, created on first use with web access denied.
	 *
	 * Under the uploads directory rather than the system temp folder, so
	 * parts survive across requests on hosts that clear /tmp or run several
	 * web servers against one shared uploads folder. File names are random
	 * 32-character IDs; the .htaccess and index.php also refuse listing and
	 * direct requests where the server honors them.
	 *
	 * @param bool $create Create the folder when missing.
	 * @return string Absolute path, or '' when unavailable.
	 */
	private static function dir( bool $create = true ): string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;

		if ( is_dir( $dir ) ) {
			return $dir;
		}

		if ( ! $create || ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time guard files.
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		// phpcs:enable

		return $dir;
	}
}
