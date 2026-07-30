<?php
/**
 * Moment publisher.
 *
 * Creates the canonical Moment as a standard WordPress `post` (never a
 * custom post type), attaches uploaded media, writes the _moment_*
 * metadata, and fires `moment_published`.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates Moments as standard WordPress posts with attached media.
 */
class Moment_Publisher {

	/**
	 * Allowed primary Moment types.
	 *
	 * @var string[]
	 */
	public const PRIMARY_TYPES = array( 'image', 'video', 'audio', 'podcast', 'note', 'gallery', 'mixed' );

	/**
	 * Moment type → WordPress standard post format.
	 *
	 * Set explicitly so a Moment lands in the right format regardless of
	 * the site's default post format (Settings → Writing). 'mixed' maps to
	 * the standard format (no term). Themes without post-format support
	 * simply ignore the term.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_POST_FORMATS = array(
		'image'   => 'image',
		'gallery' => 'gallery',
		'video'   => 'video',
		'audio'   => 'audio',
		'podcast' => 'audio',
		'note'    => 'aside',
		'mixed'   => 'standard',
	);

	/**
	 * Allowed MIME types for Moment media uploads.
	 *
	 * MIME must be validated against file content (finfo +
	 * wp_check_filetype_and_ext()) before upload — never trust the file
	 * extension alone.
	 *
	 * @var string[]
	 */
	public const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'video/mp4',
		'video/quicktime',
		'audio/mpeg',
		'audio/wav',
	);

	/**
	 * Maximum accepted size, in bytes, for a single uploaded media file.
	 *
	 * Enforced per file during validation (against the reported upload size)
	 * before the file is handed to WordPress for sideloading.
	 *
	 * @since 0.5.0
	 *
	 * @var int
	 */
	public const MAX_FILE_BYTES = 50 * 1024 * 1024; // 50 MB.

	/**
	 * Content-sniffed MIME aliases mapped to their canonical allowed type.
	 *
	 * Content sniffing (finfo) reports some formats with non-canonical names (e.g. WAV).
	 *
	 * @var array<string, string>
	 */
	private const MIME_ALIASES = array(
		'audio/x-wav' => 'audio/wav',
		'audio/wave'  => 'audio/wav',
		'audio/mp3'   => 'audio/mpeg',
		'video/x-m4v' => 'video/mp4',
	);

	/**
	 * Publish a Moment.
	 *
	 * Validates and sideloads media, detects the primary Moment type,
	 * creates a standard `post` with block markup, writes all `_moment_*`
	 * metadata, and fires `moment_published`.
	 *
	 * @param array<string, mixed>                $data  Sanitized Moment input. Supported keys:
	 *                                                   caption, title, primary_type,
	 *                                                   syndication_targets, default_destinations,
	 *                                                   ai_assist_used.
	 * @param array<string, array<string, mixed>> $files $_FILES-style array of uploaded media.
	 * @return int|WP_Error Post ID on success.
	 */
	public function publish( array $data, array $files = array() ) {
		$caption = isset( $data['caption'] ) ? wp_kses_post( (string) $data['caption'] ) : '';
		$caption = trim( $caption );

		$file_list = $this->normalize_files( $files );

		if ( empty( $file_list ) && '' === $caption ) {
			return new WP_Error(
				'moment_empty',
				__( 'A Moment needs media or text.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		// Validate every file BEFORE uploading any of them.
		foreach ( $file_list as $file ) {
			$valid = $this->validate_file( $file );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$media_ids = $this->sideload_files( $file_list );

		if ( is_wp_error( $media_ids ) ) {
			return $media_ids;
		}

		$requested_type = isset( $data['primary_type'] ) ? sanitize_key( (string) $data['primary_type'] ) : '';
		$type           = $this->detect_primary_type( $media_ids, $requested_type );

		$defaults = $this->sanitize_connector_ids( $data['default_destinations'] ?? array() );

		if ( empty( $defaults ) ) {
			$defaults = $this->get_registry_defaults( $type );
		}

		$raw_targets = $data['syndication_targets'] ?? null;
		$targets     = $this->sanitize_connector_ids( $raw_targets ?? array() );

		// Distinguish "no selection sent" (fall back to defaults) from an
		// explicit empty selection (user deselected every destination).
		$selection_provided = is_array( $raw_targets ) || ( is_string( $raw_targets ) && '' !== trim( $raw_targets ) );

		// Categories: the site-filing counterpart to destinations. Same
		// "provided vs fall back to the remembered per-type default" rule.
		// Unlike post formats there is no built-in map — categories are a
		// per-site taxonomy — so an empty effective default just leaves the
		// WordPress default category in place.
		$raw_categories    = $data['categories'] ?? null;
		$categories        = $this->sanitize_category_ids( $raw_categories ?? array() );
		$category_provided = is_array( $raw_categories ) || ( is_string( $raw_categories ) && '' !== trim( $raw_categories ) );

		if ( ! $category_provided ) {
			$categories = $this->get_effective_categories( $type );
		}

		if ( ! $selection_provided ) {
			// Auto-applied defaults only go to destinations that can
			// actually publish (connected connectors). The raw model
			// defaults are still recorded in _moment_default_destinations;
			// explicit selections (e.g. tests, API callers) are honored
			// as-is, mocked or not. The user's remembered selection for
			// this Moment type wins over the model defaults.
			$targets = $this->filter_connected( $this->get_effective_defaults( $type ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			$title = $this->generate_title( $caption );
		}

		// An explicitly requested draft always wins; a requested (or
		// implied) publish still requires the capability.
		$wants_draft   = isset( $data['status'] ) && 'draft' === $data['status'];
		$final_publish = ! $wants_draft && current_user_can( 'publish_posts' );

		// Controllable third-party helpers (Share on Mastodon, Autoshare
		// for Twitter, …). A present `publish_helpers` field means the app
		// rendered the toggles and the user's choice is authoritative; an
		// absent field means don't interfere with those plugins' defaults.
		$has_helper_selection = array_key_exists( 'publish_helpers', $data );
		$helper_selection     = $has_helper_selection ? $this->sanitize_helper_ids( $data['publish_helpers'] ) : array();

		// When helpers are in play we insert as a draft first so the
		// selection meta is in place before the publish transition fires
		// each plugin's control filter, then transition to publish below.
		$defer_helpers = $final_publish && $has_helper_selection;

		$post_data = array(
			'post_type'    => 'post', // NEVER a custom post type — the Moment is a standard post.
			'post_status'  => ( $final_publish && ! $defer_helpers ) ? 'publish' : 'draft',
			'post_author'  => get_current_user_id(),
			'post_title'   => $title,
			'post_content' => $this->build_block_markup( $media_ids, $caption ),
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( $caption ), 24, '…' ),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			// Clean up orphaned attachments so failed publishes leave no debris.
			foreach ( $media_ids as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}

			$post_id->add_data( array( 'status' => 500 ) );

			return $post_id;
		}

		$this->attach_media( $post_id, $media_ids, $type );

		if ( $selection_provided ) {
			$this->remember_destination_prefs( $type, $targets );
		}

		// File the Moment under the chosen (or remembered) categories. An
		// explicit selection — even an empty one — is remembered as the new
		// per-type default; wp_set_post_categories() with an empty list falls
		// back to the site's default category, which is the intended "none".
		if ( $category_provided ) {
			$this->remember_category_prefs( $type, $categories );
		}
		if ( $category_provided || ! empty( $categories ) ) {
			wp_set_post_categories( $post_id, $categories, false );
		}

		// Apply AI-Assist-accepted tags and alt text when provided.
		$tags = array_filter( array_map( 'sanitize_text_field', (array) ( $data['tags'] ?? array() ) ) );
		if ( $tags ) {
			wp_set_post_tags( $post_id, $tags, true );
		}

		// Per-image alt text, positional against the uploaded files (and so
		// against $media_ids in the same order). Falls back to the legacy
		// single alt_text for the first image when no positional list is sent.
		$this->apply_positional_alt( $media_ids, $data['alt'] ?? null );

		$alt_text = isset( $data['alt_text'] ) ? sanitize_text_field( (string) $data['alt_text'] ) : '';
		if ( '' !== $alt_text && $media_ids && ! isset( $data['alt'] ) ) {
			$first_id = (int) $media_ids[0];
			if ( wp_attachment_is_image( $first_id ) && '' === (string) get_post_meta( $first_id, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $first_id, '_wp_attachment_image_alt', $alt_text );
			}
		}

		$ai_assist_used = ! empty( $data['ai_assist_used'] ) ? '1' : '0';

		update_post_meta( $post_id, '_moment_is_moment', '1' );
		// Raw caption, so editing can reopen the composer losslessly
		// (post_content is derived block markup, post_excerpt is trimmed).
		update_post_meta( $post_id, '_moment_caption', $caption );
		update_post_meta( $post_id, '_moment_primary_type', $type );
		update_post_meta( $post_id, '_moment_media_ids', wp_json_encode( array_map( 'intval', $media_ids ) ) );
		update_post_meta( $post_id, '_moment_syndication_targets', wp_json_encode( $targets ) );
		update_post_meta( $post_id, '_moment_default_destinations', wp_json_encode( $defaults ) );
		update_post_meta( $post_id, '_moment_syndication_status', 'not_attempted' );
		update_post_meta( $post_id, '_moment_external_posts', wp_json_encode( (object) array() ) );
		update_post_meta( $post_id, '_moment_comment_backflow_enabled', '1' );
		update_post_meta( $post_id, '_moment_ai_assist_used', $ai_assist_used );
		update_post_meta( $post_id, '_moment_created_from', 'mobile' );

		if ( $has_helper_selection ) {
			update_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, wp_json_encode( $helper_selection ) );
		}

		$this->apply_post_format( $post_id, $type );

		$moment_data = array(
			'post_id'              => $post_id,
			'primary_type'         => $type,
			'media_ids'            => array_map( 'intval', $media_ids ),
			'caption'              => $caption,
			'syndication_targets'  => $targets,
			'default_destinations' => $defaults,
			'post_status'          => get_post_status( $post_id ),
			'ai_assist_used'       => $ai_assist_used,
			'created_from'         => 'mobile',
		);

		/**
		 * Fires after a Moment has been successfully created.
		 *
		 * @param int                  $post_id     The Moment post ID.
		 * @param array<string, mixed> $moment_data Moment context data.
		 */
		do_action( 'moment_published', $post_id, $moment_data );

		if ( $defer_helpers ) {
			// Meta (incl. the helper selection) is now in place; go live.
			// The draft→publish transition runs connector syndication via
			// syndicate_on_publish() and lets each opted-in third-party
			// plugin publish through its own control filter.
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		} elseif ( 'publish' === get_post_status( $post_id ) ) {
			// Drafts never syndicate. Targets stay stored in post meta with
			// status 'not_attempted'; syndicate_on_publish() runs them when
			// the Moment goes live — from the app or wp-admin alike.
			$this->maybe_syndicate( $post_id, $targets, $moment_data );
		}

		return $post_id;
	}

	/**
	 * Sanitize a list of controllable helper ids to the currently active
	 * ones, dropping anything unknown or inactive.
	 *
	 * @param mixed $raw Raw ids (array or JSON string).
	 * @return string[]
	 */
	private function sanitize_helper_ids( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_map( 'sanitize_key', array_map( 'strval', $raw ) );

		return array_values( array_intersect( $ids, Moment_Publish_Helpers::controllable_ids() ) );
	}

	/**
	 * Update an existing Moment: caption, media (additive), targets,
	 * and status.
	 *
	 * Meta is written before the post update so a draft→publish here
	 * fires syndicate_on_publish() against the fresh targets. Existing
	 * media is kept; new files are appended.
	 *
	 * @param int                                 $post_id The Moment post ID.
	 * @param array<string, mixed>                $data    Sanitized input: caption, title,
	 *                                                     primary_type, syndication_targets,
	 *                                                     status, tags, alt_text.
	 * @param array<string, array<string, mixed>> $files   $_FILES-style array of new media.
	 * @return int|WP_Error Post ID on success.
	 */
	public function update( int $post_id, array $data, array $files = array() ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_moment_is_moment', true ) ) {
			return new WP_Error(
				'moment_not_found',
				__( 'Not a Moment post.', 'moment' ),
				array( 'status' => 404 )
			);
		}

		$caption = isset( $data['caption'] ) ? trim( wp_kses_post( (string) $data['caption'] ) ) : '';

		$existing_media = json_decode( (string) get_post_meta( $post_id, '_moment_media_ids', true ), true );
		$existing_media = is_array( $existing_media ) ? array_values( array_map( 'intval', $existing_media ) ) : array();

		$file_list = $this->normalize_files( $files );

		if ( '' === $caption && empty( $existing_media ) && empty( $file_list ) ) {
			return new WP_Error(
				'moment_empty',
				__( 'A Moment needs media or text.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $file_list as $file ) {
			$valid = $this->validate_file( $file );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$new_ids = $this->sideload_files( $file_list );

		if ( is_wp_error( $new_ids ) ) {
			return $new_ids;
		}

		$media_ids = array_merge( $existing_media, array_map( 'intval', $new_ids ) );

		$requested_type = isset( $data['primary_type'] ) ? sanitize_key( (string) $data['primary_type'] ) : '';

		// A caption-only edit (no media to detect from, no explicit type)
		// must not silently reclassify the Moment to 'note' — keep its stored
		// type so per-type memory (destinations, categories) stays coherent.
		if ( '' === $requested_type && empty( $media_ids ) ) {
			$requested_type = (string) get_post_meta( $post_id, '_moment_primary_type', true );
		}

		$type = $this->detect_primary_type( $media_ids, $requested_type );

		$raw_targets = $data['syndication_targets'] ?? null;

		if ( is_array( $raw_targets ) || ( is_string( $raw_targets ) && '' !== trim( $raw_targets ) ) ) {
			$targets = $this->sanitize_connector_ids( $raw_targets );
			update_post_meta( $post_id, '_moment_syndication_targets', wp_json_encode( $targets ) );
			$this->remember_destination_prefs( $type, $targets );
		}

		$raw_categories      = $data['categories'] ?? null;
		$categories_provided = is_array( $raw_categories ) || ( is_string( $raw_categories ) && '' !== trim( $raw_categories ) );
		$categories          = $categories_provided ? $this->sanitize_category_ids( $raw_categories ) : array();

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			$title = $this->generate_title( $caption );
		}

		$new_status = $post->post_status;

		if ( isset( $data['status'] ) && 'draft' === $data['status'] ) {
			$new_status = 'draft';
		} elseif ( isset( $data['status'] ) && 'publish' === $data['status'] && current_user_can( 'publish_posts' ) ) {
			$new_status = 'publish';
		}

		if ( ! empty( $new_ids ) ) {
			$this->attach_media( $post_id, array_map( 'intval', $new_ids ), $type );
		}

		$tags = array_filter( array_map( 'sanitize_text_field', (array) ( $data['tags'] ?? array() ) ) );
		if ( $tags ) {
			wp_set_post_tags( $post_id, $tags, true );
		}

		// Alt text: positional for the newly added files, plus a map keyed
		// by attachment ID for media already on the Moment (edited in place).
		$this->apply_positional_alt( array_map( 'intval', $new_ids ), $data['alt'] ?? null );
		$this->apply_alt_map( $data['existing_alt'] ?? null );

		$alt_text = isset( $data['alt_text'] ) ? sanitize_text_field( (string) $data['alt_text'] ) : '';
		if ( '' !== $alt_text && $media_ids && ! isset( $data['alt'] ) && ! isset( $data['existing_alt'] ) ) {
			$first_id = (int) $media_ids[0];
			if ( wp_attachment_is_image( $first_id ) && '' === (string) get_post_meta( $first_id, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $first_id, '_wp_attachment_image_alt', $alt_text );
			}
		}

		update_post_meta( $post_id, '_moment_caption', $caption );
		update_post_meta( $post_id, '_moment_primary_type', $type );
		update_post_meta( $post_id, '_moment_media_ids', wp_json_encode( $media_ids ) );

		// Helper selection is written before the status update below so a
		// draft→publish transition here fires each plugin's control filter
		// against the fresh choice.
		if ( array_key_exists( 'publish_helpers', $data ) ) {
			update_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, wp_json_encode( $this->sanitize_helper_ids( $data['publish_helpers'] ) ) );
		}

		// Re-apply in case the type changed on edit (e.g. adding media to a note).
		$this->apply_post_format( $post_id, $type );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $this->build_block_markup( $media_ids, $caption ),
				'post_excerpt' => wp_trim_words( wp_strip_all_tags( $caption ), 24, '…' ),
				'post_status'  => $new_status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );

			return $result;
		}

		// File under the chosen categories AFTER wp_update_post: an update
		// with no post_category re-applies $post_before's categories (core
		// post.php), which would otherwise clobber a fresh selection here.
		if ( $categories_provided ) {
			wp_set_post_categories( $post_id, $categories, false );
			$this->remember_category_prefs( $type, $categories );
		}

		return $post_id;
	}

	/**
	 * Register the deferred-syndication hook. Called on init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'syndicate_on_publish' ), 10, 3 );
	}

	/**
	 * Run stored, never-attempted syndication targets when a Moment draft
	 * becomes published — regardless of where the publish happened.
	 *
	 * Safe against the inline create path (Moment meta does not exist yet
	 * when wp_insert_post fires this transition) and against repeats (the
	 * syndication status leaves 'not_attempted' after the first run).
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function syndicate_on_publish( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
			return;
		}

		if ( '1' !== get_post_meta( $post->ID, '_moment_is_moment', true ) ) {
			return;
		}

		if ( 'not_attempted' !== get_post_meta( $post->ID, '_moment_syndication_status', true ) ) {
			return;
		}

		$targets = json_decode( (string) get_post_meta( $post->ID, '_moment_syndication_targets', true ), true );
		$targets = is_array( $targets ) ? array_values( array_filter( array_map( 'sanitize_key', $targets ) ) ) : array();

		if ( empty( $targets ) ) {
			return;
		}

		$media_ids = json_decode( (string) get_post_meta( $post->ID, '_moment_media_ids', true ), true );

		$moment_data = array(
			'post_id'             => (int) $post->ID,
			'primary_type'        => (string) get_post_meta( $post->ID, '_moment_primary_type', true ),
			'media_ids'           => is_array( $media_ids ) ? array_map( 'intval', $media_ids ) : array(),
			'caption'             => '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_title,
			'syndication_targets' => $targets,
			'post_status'         => 'publish',
			'created_from'        => (string) get_post_meta( $post->ID, '_moment_created_from', true ),
		);

		$this->maybe_syndicate( (int) $post->ID, $targets, $moment_data );
	}

	/**
	 * Normalize a $_FILES-style array into a flat list of single files.
	 *
	 * Handles both `moment_media` (single) and `moment_media[]` (PHP's
	 * transposed multi-file structure), across any number of field names.
	 *
	 * @param array<string, array<string, mixed>> $files $_FILES-style array.
	 * @return array<int, array<string, mixed>> Flat list of file arrays.
	 */
	private function normalize_files( array $files ): array {
		$list = array();

		foreach ( $files as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
				continue;
			}

			if ( is_array( $field['name'] ) ) {
				foreach ( array_keys( $field['name'] ) as $i ) {
					$list[] = array(
						'name'     => (string) ( $field['name'][ $i ] ?? '' ),
						'type'     => (string) ( $field['type'][ $i ] ?? '' ),
						'tmp_name' => (string) ( $field['tmp_name'][ $i ] ?? '' ),
						'error'    => (int) ( $field['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ),
						'size'     => (int) ( $field['size'][ $i ] ?? 0 ),
					);
				}
			} else {
				$list[] = array(
					'name'     => (string) $field['name'],
					'type'     => (string) ( $field['type'] ?? '' ),
					'tmp_name' => (string) ( $field['tmp_name'] ?? '' ),
					'error'    => (int) ( $field['error'] ?? UPLOAD_ERR_NO_FILE ),
					'size'     => (int) ( $field['size'] ?? 0 ),
				);
			}
		}

		// Drop empty rows (e.g. an optional file input submitted with no file).
		return array_values(
			array_filter(
				$list,
				static function ( array $file ): bool {
					return UPLOAD_ERR_NO_FILE !== $file['error'] && '' !== $file['tmp_name'];
				}
			)
		);
	}

	/**
	 * Validate a single uploaded file: upload status and real MIME type.
	 *
	 * MIME is validated from file CONTENT (finfo) and cross-checked with
	 * wp_check_filetype_and_ext() — the extension alone is never trusted.
	 *
	 * @param array<string, mixed> $file Single $_FILES-style entry.
	 * @return true|WP_Error
	 */
	private function validate_file( array $file ) {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error(
				'moment_upload_error',
				__( 'The file failed to upload.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $file['tmp_name'] || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error(
				'moment_upload_error',
				__( 'The uploaded file could not be read.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_FILE_BYTES ) {
			return new WP_Error(
				'moment_upload_too_large',
				sprintf(
					/* translators: 1: file name, 2: maximum upload size (e.g. "50 MB"). */
					__( '"%1$s" is too large. Maximum upload size is %2$s.', 'moment' ),
					sanitize_text_field( (string) $file['name'] ),
					size_format( self::MAX_FILE_BYTES )
				),
				array( 'status' => 400 )
			);
		}

		// 1) Content-based MIME sniff.
		$finfo        = new finfo( FILEINFO_MIME_TYPE );
		$content_mime = (string) $finfo->file( $file['tmp_name'] );
		$content_mime = self::MIME_ALIASES[ $content_mime ] ?? $content_mime;

		if ( ! in_array( $content_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_mime',
				__( 'File type not allowed.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		// 2) WordPress filename/extension cross-check.
		$check      = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$check_mime = isset( $check['type'] ) ? (string) $check['type'] : '';
		$check_mime = self::MIME_ALIASES[ $check_mime ] ?? $check_mime;

		if ( '' === $check_mime || ! in_array( $check_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_mime',
				__( 'File type not allowed.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Sideload validated files into the Media Library.
	 *
	 * Attachments are created unattached; attach_media() re-parents them
	 * once the Moment post exists.
	 *
	 * @param array<int, array<string, mixed>> $file_list Validated file list.
	 * @return int[]|WP_Error Attachment IDs.
	 */
	private function sideload_files( array $file_list ) {
		if ( empty( $file_list ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$media_ids = array();

		foreach ( $file_list as $file ) {
			$attachment_id = media_handle_sideload( $file, 0 );

			if ( is_wp_error( $attachment_id ) ) {
				// Roll back anything already sideloaded.
				foreach ( $media_ids as $existing_id ) {
					wp_delete_attachment( $existing_id, true );
				}

				$attachment_id->add_data( array( 'status' => 500 ) );

				return $attachment_id;
			}

			$media_ids[] = (int) $attachment_id;
		}

		return $media_ids;
	}

	/**
	 * Detect the primary Moment type from attached media.
	 *
	 * Rules: no media → note; 1 image → image; 2+ images → gallery;
	 * video only → video; audio only → audio; mixed media → mixed.
	 * An explicit valid override (e.g. `podcast`) wins.
	 *
	 * @param int[]  $media_ids      Attachment IDs.
	 * @param string $requested_type Explicit override from input, if any.
	 * @return string One of PRIMARY_TYPES.
	 */
	private function detect_primary_type( array $media_ids, string $requested_type = '' ): string {
		if ( '' !== $requested_type && in_array( $requested_type, self::PRIMARY_TYPES, true ) ) {
			return $requested_type;
		}

		$groups = $this->group_media_ids( $media_ids );

		$has_image = ! empty( $groups['image'] );
		$has_video = ! empty( $groups['video'] );
		$has_audio = ! empty( $groups['audio'] );

		if ( ! $has_image && ! $has_video && ! $has_audio ) {
			return 'note';
		}

		if ( $has_image && ! $has_video && ! $has_audio ) {
			return count( $groups['image'] ) > 1 ? 'gallery' : 'image';
		}

		if ( $has_video && ! $has_image && ! $has_audio ) {
			return 'video';
		}

		if ( $has_audio && ! $has_image && ! $has_video ) {
			return 'audio';
		}

		return 'mixed';
	}

	/**
	 * Group attachment IDs by media kind.
	 *
	 * @param int[] $media_ids Attachment IDs.
	 * @return array{image: int[], video: int[], audio: int[]}
	 */
	private function group_media_ids( array $media_ids ): array {
		$groups = array(
			'image' => array(),
			'video' => array(),
			'audio' => array(),
		);

		foreach ( $media_ids as $attachment_id ) {
			$mime = (string) get_post_mime_type( $attachment_id );

			if ( str_starts_with( $mime, 'image/' ) ) {
				$groups['image'][] = (int) $attachment_id;
			} elseif ( str_starts_with( $mime, 'video/' ) ) {
				$groups['video'][] = (int) $attachment_id;
			} elseif ( str_starts_with( $mime, 'audio/' ) ) {
				$groups['audio'][] = (int) $attachment_id;
			}
		}

		return $groups;
	}

	/**
	 * Build standard block markup for the Moment content.
	 *
	 * Uses core/image, core/gallery, core/video, core/audio, and
	 * core/paragraph so the Moment renders in any theme.
	 *
	 * @param int[]  $media_ids Attachment IDs.
	 * @param string $caption   Caption text (already run through wp_kses_post).
	 * @return string Block markup.
	 */
	private function build_block_markup( array $media_ids, string $caption ): string {
		$groups = $this->group_media_ids( $media_ids );
		$blocks = array();

		if ( count( $groups['image'] ) > 1 ) {
			$blocks[] = $this->build_gallery_block( $groups['image'] );
		} elseif ( 1 === count( $groups['image'] ) ) {
			$blocks[] = $this->build_image_block( $groups['image'][0] );
		}

		foreach ( $groups['video'] as $video_id ) {
			$blocks[] = $this->build_video_block( $video_id );
		}

		foreach ( $groups['audio'] as $audio_id ) {
			$blocks[] = $this->build_audio_block( $audio_id );
		}

		if ( '' !== $caption ) {
			foreach ( preg_split( '/\n\s*\n/', $caption ) as $chunk ) {
				$chunk = trim( $chunk );

				if ( '' === $chunk ) {
					continue;
				}

				$blocks[] = sprintf(
					"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
					wp_kses_post( $chunk )
				);
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Build a core/image block for an attachment.
	 *
	 * @param int $attachment_id Image attachment ID.
	 * @return string Block markup.
	 */
	private function build_image_block( int $attachment_id ): string {
		$url = wp_get_attachment_image_url( $attachment_id, 'large' );

		if ( ! $url ) {
			$url = (string) wp_get_attachment_url( $attachment_id );
		}

		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return sprintf(
			"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n" .
			"<figure class=\"wp-block-image size-large\"><img src=\"%2\$s\" alt=\"%3\$s\" class=\"wp-image-%1\$d\"/></figure>\n" .
			'<!-- /wp:image -->',
			$attachment_id,
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Build a core/gallery block wrapping core/image blocks.
	 *
	 * @param int[] $image_ids Image attachment IDs.
	 * @return string Block markup.
	 */
	private function build_gallery_block( array $image_ids ): string {
		$inner = array();

		foreach ( $image_ids as $image_id ) {
			$inner[] = $this->build_image_block( $image_id );
		}

		return sprintf(
			"<!-- wp:gallery {\"linkTo\":\"none\"} -->\n" .
			"<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">%s</figure>\n" .
			'<!-- /wp:gallery -->',
			implode( "\n\n", $inner )
		);
	}

	/**
	 * Build a core/video block for an attachment.
	 *
	 * @param int $attachment_id Video attachment ID.
	 * @return string Block markup.
	 */
	private function build_video_block( int $attachment_id ): string {
		return sprintf(
			"<!-- wp:video {\"id\":%1\$d} -->\n" .
			"<figure class=\"wp-block-video\"><video controls src=\"%2\$s\"></video></figure>\n" .
			'<!-- /wp:video -->',
			$attachment_id,
			esc_url( (string) wp_get_attachment_url( $attachment_id ) )
		);
	}

	/**
	 * Build a core/audio block for an attachment.
	 *
	 * @param int $attachment_id Audio attachment ID.
	 * @return string Block markup.
	 */
	private function build_audio_block( int $attachment_id ): string {
		return sprintf(
			"<!-- wp:audio {\"id\":%1\$d} -->\n" .
			"<figure class=\"wp-block-audio\"><audio controls src=\"%2\$s\"></audio></figure>\n" .
			'<!-- /wp:audio -->',
			$attachment_id,
			esc_url( (string) wp_get_attachment_url( $attachment_id ) )
		);
	}

	/**
	 * Attach media to the Moment post and set the featured image.
	 *
	 * @param int    $post_id   Moment post ID.
	 * @param int[]  $media_ids Attachment IDs.
	 * @param string $type      Primary Moment type.
	 * @return void
	 */
	private function attach_media( int $post_id, array $media_ids, string $type ): void {
		foreach ( $media_ids as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $post_id,
				)
			);
		}

		if ( ! in_array( $type, array( 'image', 'gallery' ), true ) ) {
			return;
		}

		$groups = $this->group_media_ids( $media_ids );

		if ( ! empty( $groups['image'] ) ) {
			set_post_thumbnail( $post_id, $groups['image'][0] );
		}
	}

	/**
	 * Apply per-image alt text positionally against a list of attachments.
	 *
	 * The Nth alt entry describes the Nth uploaded file (the app appends
	 * files[] and alt[] together, and sideloading preserves that order).
	 * Non-image attachments and empty entries are skipped; alt is only ever
	 * set on images.
	 *
	 * @param int[] $media_ids Attachment IDs in upload order.
	 * @param mixed $alt_list  Positional alt values (array or JSON string).
	 * @return void
	 */
	private function apply_positional_alt( array $media_ids, $alt_list ): void {
		if ( is_string( $alt_list ) ) {
			$decoded  = json_decode( $alt_list, true );
			$alt_list = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $alt_list ) ) {
			return;
		}

		foreach ( array_values( $media_ids ) as $i => $attachment_id ) {
			if ( ! array_key_exists( $i, $alt_list ) ) {
				continue;
			}

			$alt = sanitize_text_field( (string) $alt_list[ $i ] );

			if ( '' !== $alt && wp_attachment_is_image( (int) $attachment_id ) ) {
				update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}
	}

	/**
	 * Apply alt text to already-attached images from an ID-keyed map.
	 *
	 * Used when editing a Moment: { attachmentId: altText }. An empty value
	 * clears that image's alt (an intentional edit); non-image IDs are
	 * ignored.
	 *
	 * @param mixed $map Alt map (array or JSON string) keyed by attachment ID.
	 * @return void
	 */
	private function apply_alt_map( $map ): void {
		if ( is_string( $map ) ) {
			$decoded = json_decode( $map, true );
			$map     = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $map ) ) {
			return;
		}

		foreach ( $map as $id => $alt ) {
			$id = absint( $id );

			if ( $id && wp_attachment_is_image( $id ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
			}
		}
	}

	/**
	 * Set the post format matching the Moment type, so a Moment is not
	 * left in the site's default post format (e.g. an image Moment
	 * landing under Asides).
	 *
	 * @param int    $post_id Moment post ID.
	 * @param string $type    Primary Moment type.
	 * @return void
	 */
	private function apply_post_format( int $post_id, string $type ): void {
		$format = self::TYPE_POST_FORMATS[ $type ] ?? 'standard';

		// 'standard' clears the format term (set_post_format( , false )).
		set_post_format( $post_id, 'standard' === $format ? false : $format );
	}

	/**
	 * Generate a post title from the caption (first ~8 words) or a
	 * timestamp fallback like "Moment — March 3, 2026 4:12 pm".
	 *
	 * @param string $caption Caption text.
	 * @return string Title.
	 */
	private function generate_title( string $caption ): string {
		$plain = trim( wp_strip_all_tags( $caption ) );

		if ( '' !== $plain ) {
			return wp_trim_words( $plain, 8, '…' );
		}

		return sprintf(
			/* translators: 1: localized date, 2: localized time. */
			__( 'Moment — %1$s %2$s', 'moment' ),
			wp_date( get_option( 'date_format', 'F j, Y' ) ),
			wp_date( get_option( 'time_format', 'g:i a' ) )
		);
	}

	/**
	 * Sanitize a list of connector IDs.
	 *
	 * Accepts an array, a JSON-encoded string, or a comma-separated
	 * string (multipart form fields arrive as strings).
	 *
	 * @param mixed $raw Raw input.
	 * @return string[] Sanitized connector IDs.
	 */
	private function sanitize_connector_ids( $raw ): array {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();

		foreach ( $raw as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = sanitize_key( (string) $id );

			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Get default destinations for a type from the syndication registry.
	 *
	 * @param string $type Primary Moment type.
	 * @return string[] Connector IDs.
	 */
	private function get_registry_defaults( string $type ): array {
		if ( ! class_exists( 'Moment_Plugin' ) ) {
			return array();
		}

		$registry = Moment_Plugin::instance()->syndication_registry;

		return $this->sanitize_connector_ids( $registry->get_default_destinations( $type ) );
	}

	/**
	 * User meta key remembering per-type destination selections.
	 *
	 * @var string
	 */
	private const DESTINATION_PREFS_META = 'moment_destination_prefs';

	/**
	 * The preselected destinations for a Moment type.
	 *
	 * The user's last explicit selection for the type wins; the registry's
	 * model defaults are the fallback for types never published before.
	 *
	 * @param string $type    Moment primary type.
	 * @param int    $user_id User ID; defaults to the current user.
	 * @return string[]
	 */
	public function get_effective_defaults( string $type, int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$prefs   = $user_id ? get_user_meta( $user_id, self::DESTINATION_PREFS_META, true ) : array();

		if ( is_array( $prefs ) && isset( $prefs[ $type ] ) && is_array( $prefs[ $type ] ) ) {
			return $this->sanitize_connector_ids( $prefs[ $type ] );
		}

		return $this->get_registry_defaults( $type );
	}

	/**
	 * Remember an explicit destination selection for a Moment type.
	 *
	 * Called on successful publish so the next Moment of the same type
	 * preselects the same networks. An explicit empty selection is
	 * remembered too — "none for notes" is a real preference.
	 *
	 * @param string   $type    Moment primary type.
	 * @param string[] $targets Selected connector IDs.
	 * @return void
	 */
	private function remember_destination_prefs( string $type, array $targets ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$prefs = get_user_meta( $user_id, self::DESTINATION_PREFS_META, true );

		if ( ! is_array( $prefs ) ) {
			$prefs = array();
		}

		$prefs[ $type ] = $this->sanitize_connector_ids( $targets );

		update_user_meta( $user_id, self::DESTINATION_PREFS_META, $prefs );
	}

	/**
	 * User meta key remembering per-type category selections.
	 *
	 * @var string
	 */
	private const CATEGORY_PREFS_META = 'moment_category_prefs';

	/**
	 * The preselected categories for a Moment type.
	 *
	 * The user's last explicit selection for the type wins. Unlike
	 * destinations there is no model fallback — categories are a per-site
	 * taxonomy with no universal mapping — so a type never filed before
	 * returns an empty list and the site's default category applies.
	 *
	 * @param string $type    Moment primary type.
	 * @param int    $user_id User ID; defaults to the current user.
	 * @return int[]
	 */
	public function get_effective_categories( string $type, int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$prefs   = $user_id ? get_user_meta( $user_id, self::CATEGORY_PREFS_META, true ) : array();

		if ( is_array( $prefs ) && isset( $prefs[ $type ] ) && is_array( $prefs[ $type ] ) ) {
			return $this->sanitize_category_ids( $prefs[ $type ] );
		}

		return array();
	}

	/**
	 * Remember an explicit category selection for a Moment type.
	 *
	 * Called on successful publish so the next Moment of the same type
	 * preselects the same categories. An explicit empty selection is
	 * remembered too — "file notes nowhere in particular" is a real choice.
	 *
	 * @param string $type Moment primary type.
	 * @param int[]  $ids  Selected category term IDs.
	 * @return void
	 */
	private function remember_category_prefs( string $type, array $ids ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$prefs = get_user_meta( $user_id, self::CATEGORY_PREFS_META, true );

		if ( ! is_array( $prefs ) ) {
			$prefs = array();
		}

		$prefs[ $type ] = $this->sanitize_category_ids( $ids );

		update_user_meta( $user_id, self::CATEGORY_PREFS_META, $prefs );
	}

	/**
	 * Sanitize a list of category term IDs to those that actually exist.
	 *
	 * Accepts an array, a JSON-encoded string, or a comma-separated string
	 * (multipart form fields arrive as strings). Every ID is verified
	 * against the category taxonomy — an unknown or non-category term is
	 * dropped rather than silently created.
	 *
	 * @param mixed $raw Raw input.
	 * @return int[] Sanitized, existing category term IDs.
	 */
	private function sanitize_category_ids( $raw ): array {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();

		foreach ( $raw as $id ) {
			$id = absint( $id );

			if ( $id && term_exists( $id, 'category' ) ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Reduce a connector ID list to those with a connected connector.
	 *
	 * @param string[] $ids Connector IDs.
	 * @return string[]
	 */
	private function filter_connected( array $ids ): array {
		if ( ! class_exists( 'Moment_Plugin' ) ) {
			return array();
		}

		$registry = Moment_Plugin::instance()->syndication_registry;

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $registry ): bool {
					$connector = $registry->get_connector( (string) $id );

					return $connector && $connector->is_connected();
				}
			)
		);
	}

	/**
	 * Defensively hand off to the syndication registry (Phase 5).
	 *
	 * Selection is stored as meta regardless; if the registry gains a
	 * publish_to_targets() method in Phase 5, it is invoked here.
	 *
	 * @param int                  $post_id     Moment post ID.
	 * @param string[]             $targets     Selected connector IDs.
	 * @param array<string, mixed> $moment_data Moment context data.
	 * @return void
	 */
	private function maybe_syndicate( int $post_id, array $targets, array $moment_data ): void {
		if ( empty( $targets ) || ! class_exists( 'Moment_Plugin' ) ) {
			return;
		}

		$registry = Moment_Plugin::instance()->syndication_registry;

		if ( method_exists( $registry, 'publish_to_targets' ) ) {
			$registry->publish_to_targets( $post_id, $targets, $moment_data );
		}
	}
}
