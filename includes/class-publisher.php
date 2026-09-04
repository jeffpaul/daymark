<?php
/**
 * Daymark publisher.
 *
 * Creates the canonical Mark as a standard WordPress `post` (never a
 * custom post type), attaches uploaded media, writes the _daymark_*
 * metadata, and fires `daymark_published`.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates Marks as standard WordPress posts with attached media.
 */
class Daymark_Publisher {

	/**
	 * Allowed primary Mark types.
	 *
	 * @var string[]
	 */
	public const PRIMARY_TYPES = array( 'image', 'video', 'audio', 'note', 'gallery', 'mixed' );

	/**
	 * Per-type policy for the composer's optional Title field.
	 *
	 * Returns a map of primary type => 'optional' | 'hidden'. Audio and video
	 * Marks show an optional, editable Title field by default (a spoken or
	 * filmed Mark reads better with a real title); every other type derives
	 * its title from the caption or a timestamp, so the field stays hidden.
	 *
	 * The title itself is always optional in storage — when no title reaches
	 * the publisher, it falls back to a caption/timestamp-derived title. This
	 * policy only governs whether the composer surfaces the field.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, string> Map of primary type to 'optional' or 'hidden'.
	 */
	public static function title_field_policy(): array {
		$policy = array(
			'audio'   => 'optional',
			'video'   => 'optional',
			'note'    => 'hidden',
			'image'   => 'hidden',
			'gallery' => 'hidden',
			'mixed'   => 'hidden',
		);

		/**
		 * Filters the per-type policy for the composer's optional Title field.
		 *
		 * Each primary Mark type maps to 'optional' (the composer shows an
		 * editable, AI-pre-filled Title field) or 'hidden' (the title is
		 * derived from the caption or a timestamp). Return a modified map to
		 * show or hide the field for a given type.
		 *
		 * @since 0.5.0
		 *
		 * @param array<string, string> $policy Map of primary type to 'optional' or 'hidden'.
		 */
		return (array) apply_filters( 'daymark_title_field_policy', $policy );
	}

	/**
	 * Mark type → WordPress standard post format.
	 *
	 * Set explicitly so a Mark lands in the right format regardless of
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
		'note'    => 'aside',
		'mixed'   => 'standard',
	);

	/**
	 * Allowed MIME types for Daymark media uploads.
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
	 * Maximum accepted total size, in bytes, across all files in one request.
	 *
	 * @var int
	 */
	public const MAX_TOTAL_FILE_BYTES = 200 * 1024 * 1024; // 200 MB.

	/**
	 * Character-count backstop for a generated title.
	 *
	 * WordPress's own `wp_trim_words()` only trims by character for a locale
	 * whose translation marks word-count as character-based (core does this
	 * for its own ja/zh/ko translations). On any other locale, a caption
	 * with no spaces — e.g. Japanese typed on an English-locale site —
	 * counts as one "word", so the normal 8-word limit never fires. This is
	 * a second, locale-independent limit applied only when that happens.
	 *
	 * @var int
	 */
	public const MAX_TITLE_CHARS = 60;

	/**
	 * Character-count cap on a stored transcript (AI-generated or
	 * hand-typed/edited). Generous relative to the AI adapter's own
	 * generation cap (Daymark_AI_Assist::MAX_TRANSCRIPT_CHARS) since an
	 * author editing a transcript by hand isn't bound by that limit — this
	 * is purely a sanity backstop against unbounded post meta.
	 *
	 * @var int
	 */
	public const MAX_TRANSCRIPT_CHARS = 8000;

	/**
	 * Clock-skew tolerance, in seconds, for a client-supplied `captured_at`
	 * timestamp. A value further in the future than this is treated as
	 * unusable (ignored, falling through to the next signal) rather than
	 * rejected with an error — quiet metadata capture never blocks
	 * publishing.
	 *
	 * @since 0.11.0
	 *
	 * @var int
	 */
	private const CAPTURE_TIME_FUTURE_TOLERANCE = 5 * MINUTE_IN_SECONDS;

	/**
	 * Average silent-reading speed, in words per minute, used to derive
	 * `_daymark_reading_time_minutes` from a Mark's caption (and transcript,
	 * when present). A commonly-cited average; named as a constant so it's
	 * easy to tune later rather than a magic number in the calculation.
	 *
	 * @since 0.11.0
	 *
	 * @var int
	 */
	public const WORDS_PER_MINUTE = 225;

	/**
	 * Minimum word count before a reading-time estimate is stored at all.
	 * Below this, "1 min read" is noise on a two-sentence caption rather
	 * than useful information.
	 *
	 * @since 0.11.0
	 *
	 * @var int
	 */
	private const READING_TIME_MIN_WORDS = 40;

	/**
	 * Camera/shooting-condition EXIF fields copied into `_daymark_camera`
	 * when present and non-empty, from the first image attachment's own
	 * `wp_get_attachment_metadata()['image_meta']` (populated by core's own
	 * `wp_generate_attachment_metadata()` during sideload — nothing here
	 * re-parses EXIF itself).
	 *
	 * @since 0.11.0
	 *
	 * @var string[]
	 */
	private const CAMERA_META_FIELDS = array( 'camera', 'aperture', 'iso', 'focal_length', 'shutter_speed' );

	/**
	 * Open-Meteo WMO weather codes mapped to a short human-readable label.
	 * Not exhaustive — an unmapped code still stores its raw numeric value
	 * with a generic label rather than being dropped.
	 *
	 * @since 0.11.0
	 *
	 * @var array<int, string>
	 */
	private const WEATHER_CONDITION_LABELS = array(
		0  => 'Clear',
		1  => 'Mostly clear',
		2  => 'Partly cloudy',
		3  => 'Overcast',
		45 => 'Fog',
		48 => 'Fog',
		51 => 'Drizzle',
		53 => 'Drizzle',
		55 => 'Drizzle',
		56 => 'Freezing drizzle',
		57 => 'Freezing drizzle',
		61 => 'Rain',
		63 => 'Rain',
		65 => 'Rain',
		66 => 'Freezing rain',
		67 => 'Freezing rain',
		71 => 'Snow',
		73 => 'Snow',
		75 => 'Snow',
		77 => 'Snow',
		80 => 'Rain showers',
		81 => 'Rain showers',
		82 => 'Rain showers',
		85 => 'Snow showers',
		86 => 'Snow showers',
		95 => 'Thunderstorm',
		96 => 'Thunderstorm',
		99 => 'Thunderstorm',
	);

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
	 * Map a content-sniffed (or extension-checked) MIME type to its
	 * canonical ALLOWED_MIME_TYPES form, so any caller checking a sniffed
	 * MIME against that list (this class's own validate_uploaded_file(),
	 * and Daymark_REST_Controller::ai_transcript()) sees the same aliases
	 * resolved the same way, rather than each maintaining its own list.
	 *
	 * @param string $mime Sniffed or checked MIME type.
	 * @return string Canonical MIME type.
	 */
	public static function canonical_mime( string $mime ): string {
		return self::MIME_ALIASES[ $mime ] ?? $mime;
	}

	/**
	 * Publish a Mark.
	 *
	 * Validates and sideloads media, detects the primary Mark type,
	 * creates a standard `post` with block markup, writes all `_daymark_*`
	 * metadata, and fires `daymark_published`.
	 *
	 * @param array<string, mixed>                $data  Sanitized Mark input. Supported keys:
	 *                                                   caption, title, primary_type,
	 *                                                   syndication_targets, default_destinations,
	 *                                                   ai_assist_used, captured_at, location_lat,
	 *                                                   location_lng, location_accuracy.
	 * @param array<string, array<string, mixed>> $files $_FILES-style array of uploaded media.
	 * @return int|WP_Error Post ID on success.
	 */
	public function publish( array $data, array $files = array() ) {
		$caption    = trim( wp_kses_post( (string) ( $data['caption'] ?? '' ) ) );
		$transcript = mb_substr( sanitize_textarea_field( (string) ( $data['transcript'] ?? '' ) ), 0, self::MAX_TRANSCRIPT_CHARS );

		$file_list = $this->normalize_files( $files );

		if ( empty( $file_list ) && '' === $caption ) {
			return new WP_Error(
				'daymark_empty',
				__( 'A Mark needs media or text.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// Validate every file (and the request total) BEFORE uploading any.
		$valid = $this->validate_file_list( $file_list );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$media_ids = $this->sideload_files( $file_list );

		if ( is_wp_error( $media_ids ) ) {
			return $media_ids;
		}

		$requested_type = sanitize_key( (string) ( $data['primary_type'] ?? '' ) );
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
			// defaults are still recorded in _daymark_default_destinations;
			// explicit selections (e.g. tests, API callers) are honored
			// as-is, mocked or not. The user's remembered selection for
			// this Mark type wins over the model defaults.
			$targets = $this->filter_connected( $this->get_effective_defaults( $type ) );
		}

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );

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

		// Quiet capture: camera EXIF (first image only — video/audio never
		// carry EXIF) is read once here, before the post exists, so its
		// created_timestamp is available as the second-priority signal for
		// the capture-time resolution immediately below. The camera fields
		// themselves are written to post meta after the insert succeeds.
		$camera_info = $this->extract_camera_info( $media_ids );
		$captured_at = $this->resolve_captured_at( $data['captured_at'] ?? null, $camera_info['exif_timestamp'] );

		$post_data = array(
			'post_type'    => 'post', // NEVER a custom post type — the Daymark is a standard post.
			'post_status'  => ( $final_publish && ! $defer_helpers ) ? 'publish' : 'draft',
			'post_author'  => get_current_user_id(),
			'post_title'   => $title,
			'post_content' => $this->build_block_markup( $media_ids, $caption ),
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( $caption ), 24, '…' ),
		);

		// A resolved capture time (client-supplied or EXIF) becomes the
		// Mark's real post_date; with neither signal, wp_insert_post() keeps
		// its own unchanged default of "now".
		if ( null !== $captured_at ) {
			$post_data['post_date_gmt'] = $captured_at;
			$post_data['post_date']     = get_date_from_gmt( $captured_at );
		}

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

		// File the Mark under the chosen (or remembered) categories. An
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

		$alt_text = sanitize_text_field( (string) ( $data['alt_text'] ?? '' ) );
		if ( '' !== $alt_text && $media_ids && ! isset( $data['alt'] ) ) {
			$first_id = (int) $media_ids[0];
			if ( wp_attachment_is_image( $first_id ) && '' === (string) get_post_meta( $first_id, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $first_id, '_wp_attachment_image_alt', $alt_text );
			}
		}

		$ai_assist_used = ! empty( $data['ai_assist_used'] ) ? '1' : '0';

		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		// Raw caption, so editing can reopen the composer losslessly
		// (post_content is derived block markup, post_excerpt is trimmed).
		update_post_meta( $post_id, '_daymark_caption', $caption );
		update_post_meta( $post_id, '_daymark_transcript', $transcript );
		update_post_meta( $post_id, '_daymark_primary_type', $type );
		update_post_meta( $post_id, '_daymark_media_ids', wp_json_encode( array_map( 'intval', $media_ids ) ) );
		update_post_meta( $post_id, '_daymark_syndication_targets', wp_json_encode( $targets ) );
		update_post_meta( $post_id, '_daymark_default_destinations', wp_json_encode( $defaults ) );
		update_post_meta( $post_id, '_daymark_syndication_status', 'not_attempted' );
		update_post_meta( $post_id, '_daymark_external_posts', wp_json_encode( (object) array() ) );
		update_post_meta( $post_id, '_daymark_comment_backflow_enabled', '1' );
		update_post_meta( $post_id, '_daymark_ai_assist_used', $ai_assist_used );
		update_post_meta( $post_id, '_daymark_created_from', 'mobile' );

		// Quiet metadata capture: date/time, camera EXIF, location, weather,
		// reading time, all best-effort and never blocking. See this
		// method's own captured_at/camera_info resolution above and the
		// per-helper docblocks below for how each degrades to "field simply
		// absent" on failure.
		if ( ! empty( $camera_info['camera'] ) ) {
			update_post_meta( $post_id, '_daymark_camera', wp_json_encode( $camera_info['camera'] ) );
		}

		if ( null !== $captured_at ) {
			update_post_meta( $post_id, '_daymark_captured_at', $captured_at );
		}

		$location = $this->resolve_location( $data );

		if ( null !== $location ) {
			update_post_meta( $post_id, '_daymark_location', wp_json_encode( $location ) );

			// Weather is only ever attempted alongside a resolved location,
			// and only on publish — not on every autosave/update — to avoid
			// firing a third-party request on every keystroke-triggered save.
			$weather = $this->fetch_weather( (float) $location['lat'], (float) $location['lng'] );

			if ( null !== $weather ) {
				update_post_meta( $post_id, '_daymark_weather', wp_json_encode( $weather ) );
			}
		}

		$in_reply_to = $this->resolve_in_reply_to( $data );

		if ( null !== $in_reply_to ) {
			update_post_meta( $post_id, '_daymark_in_reply_to', $in_reply_to );
		}

		$this->apply_reading_time( $post_id, $caption, $transcript );

		if ( $has_helper_selection ) {
			update_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, wp_json_encode( $helper_selection ) );
		}

		$this->apply_post_format( $post_id, $type );

		$daymark_data = array(
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
		 * Fires after a Mark has been successfully created.
		 *
		 * @param int                  $post_id     The Mark post ID.
		 * @param array<string, mixed> $daymark_data Mark context data.
		 */
		do_action( 'daymark_published', $post_id, $daymark_data );

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
			// the Mark goes live — from the app or wp-admin alike.
			$this->maybe_syndicate( $post_id, $targets, $daymark_data );
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

		return array_values( array_intersect( $ids, Daymark_Publish_Helpers::controllable_ids() ) );
	}

	/**
	 * Update an existing Mark: caption, media (additive), targets,
	 * and status.
	 *
	 * Meta is written before the post update so a draft→publish here
	 * fires syndicate_on_publish() against the fresh targets. Existing
	 * media is kept; new files are appended.
	 *
	 * @param int                                 $post_id The Mark post ID.
	 * @param array<string, mixed>                $data    Sanitized input: caption, title,
	 *                                                     primary_type, syndication_targets,
	 *                                                     status, tags, alt_text, captured_at,
	 *                                                     location_lat, location_lng,
	 *                                                     location_accuracy.
	 * @param array<string, array<string, mixed>> $files   $_FILES-style array of new media.
	 * @return int|WP_Error Post ID on success.
	 */
	public function update( int $post_id, array $data, array $files = array() ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Not a Mark post.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$caption    = trim( wp_kses_post( (string) ( $data['caption'] ?? '' ) ) );
		$transcript = mb_substr( sanitize_textarea_field( (string) ( $data['transcript'] ?? '' ) ), 0, self::MAX_TRANSCRIPT_CHARS );

		$existing_media = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );
		$existing_media = is_array( $existing_media ) ? array_values( array_map( 'intval', $existing_media ) ) : array();

		$file_list = $this->normalize_files( $files );

		if ( '' === $caption && empty( $existing_media ) && empty( $file_list ) ) {
			return new WP_Error(
				'daymark_empty',
				__( 'A Mark needs media or text.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$valid = $this->validate_file_list( $file_list );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$new_ids = $this->sideload_files( $file_list );

		if ( is_wp_error( $new_ids ) ) {
			return $new_ids;
		}

		$media_ids = array_merge( $existing_media, array_map( 'intval', $new_ids ) );

		$requested_type = sanitize_key( (string) ( $data['primary_type'] ?? '' ) );

		// A caption-only edit (no media to detect from, no explicit type)
		// must not silently reclassify the Mark to 'note' — keep its stored
		// type so per-type memory (destinations, categories) stays coherent.
		if ( '' === $requested_type && empty( $media_ids ) ) {
			$requested_type = (string) get_post_meta( $post_id, '_daymark_primary_type', true );
		}

		$type = $this->detect_primary_type( $media_ids, $requested_type );

		$raw_targets = $data['syndication_targets'] ?? null;

		if ( is_array( $raw_targets ) || ( is_string( $raw_targets ) && '' !== trim( $raw_targets ) ) ) {
			$targets = $this->sanitize_connector_ids( $raw_targets );
			update_post_meta( $post_id, '_daymark_syndication_targets', wp_json_encode( $targets ) );
			$this->remember_destination_prefs( $type, $targets );
		}

		$raw_categories      = $data['categories'] ?? null;
		$categories_provided = is_array( $raw_categories ) || ( is_string( $raw_categories ) && '' !== trim( $raw_categories ) );
		$categories          = $categories_provided ? $this->sanitize_category_ids( $raw_categories ) : array();

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );

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
		// by attachment ID for media already on the Mark (edited in place).
		// The map is scoped to the Mark's own media so an edit can never
		// overwrite alt text on an attachment that belongs elsewhere.
		$this->apply_positional_alt( array_map( 'intval', $new_ids ), $data['alt'] ?? null );
		$this->apply_alt_map( $data['existing_alt'] ?? null, $media_ids );

		$alt_text = sanitize_text_field( (string) ( $data['alt_text'] ?? '' ) );
		if ( '' !== $alt_text && $media_ids && ! isset( $data['alt'] ) && ! isset( $data['existing_alt'] ) ) {
			$first_id = (int) $media_ids[0];
			if ( wp_attachment_is_image( $first_id ) && '' === (string) get_post_meta( $first_id, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $first_id, '_wp_attachment_image_alt', $alt_text );
			}
		}

		update_post_meta( $post_id, '_daymark_caption', $caption );
		update_post_meta( $post_id, '_daymark_transcript', $transcript );
		update_post_meta( $post_id, '_daymark_primary_type', $type );
		update_post_meta( $post_id, '_daymark_media_ids', wp_json_encode( $media_ids ) );

		// Helper selection is written before the status update below so a
		// draft→publish transition here fires each plugin's control filter
		// against the fresh choice.
		if ( array_key_exists( 'publish_helpers', $data ) ) {
			update_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, wp_json_encode( $this->sanitize_helper_ids( $data['publish_helpers'] ) ) );
		}

		// Re-apply in case the type changed on edit (e.g. adding media to a note).
		$this->apply_post_format( $post_id, $type );

		// Quiet capture, resolved against the FULL (existing + new) media
		// list — see extract_camera_info()'s own docblock for why only the
		// first image is considered. Mirrors publish()'s own resolution;
		// unlike publish(), a null result here simply leaves the Mark's
		// existing post_date/_daymark_captured_at untouched rather than
		// falling back to "now" — an edit with no fresh signal should never
		// silently move an already-correct capture time.
		$camera_info = $this->extract_camera_info( $media_ids );
		$captured_at = $this->resolve_captured_at( $data['captured_at'] ?? null, $camera_info['exif_timestamp'] );

		$update_data = array(
			'ID'           => $post_id,
			'post_title'   => $title,
			'post_content' => $this->build_block_markup( $media_ids, $caption ),
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( $caption ), 24, '…' ),
			'post_status'  => $new_status,
		);

		if ( null !== $captured_at ) {
			$update_data['post_date_gmt'] = $captured_at;
			$update_data['post_date']     = get_date_from_gmt( $captured_at );
		}

		$result = wp_update_post( $update_data, true );

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

		// Quiet metadata capture, continued (see publish()'s matching block
		// for the full rationale). Weather is deliberately NOT re-fetched
		// on every edit/autosave — only publish() attempts it.
		if ( ! empty( $camera_info['camera'] ) ) {
			update_post_meta( $post_id, '_daymark_camera', wp_json_encode( $camera_info['camera'] ) );
		}

		if ( null !== $captured_at ) {
			update_post_meta( $post_id, '_daymark_captured_at', $captured_at );
		}

		$location = $this->resolve_location( $data );

		if ( null !== $location ) {
			update_post_meta( $post_id, '_daymark_location', wp_json_encode( $location ) );
		}

		$in_reply_to = $this->resolve_in_reply_to( $data );

		if ( null !== $in_reply_to ) {
			update_post_meta( $post_id, '_daymark_in_reply_to', $in_reply_to );
		}

		$this->apply_reading_time( $post_id, $caption, $transcript );

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
	 * Run stored, never-attempted syndication targets when a Mark draft
	 * becomes published — regardless of where the publish happened.
	 *
	 * Safe against the inline create path (Mark meta does not exist yet
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

		if ( '1' !== get_post_meta( $post->ID, '_daymark_is_mark', true ) ) {
			return;
		}

		if ( 'not_attempted' !== get_post_meta( $post->ID, '_daymark_syndication_status', true ) ) {
			return;
		}

		$targets = json_decode( (string) get_post_meta( $post->ID, '_daymark_syndication_targets', true ), true );
		$targets = is_array( $targets ) ? array_values( array_filter( array_map( 'sanitize_key', $targets ) ) ) : array();

		if ( empty( $targets ) ) {
			return;
		}

		$media_ids = json_decode( (string) get_post_meta( $post->ID, '_daymark_media_ids', true ), true );

		$daymark_data = array(
			'post_id'             => (int) $post->ID,
			'primary_type'        => (string) get_post_meta( $post->ID, '_daymark_primary_type', true ),
			'media_ids'           => is_array( $media_ids ) ? array_map( 'intval', $media_ids ) : array(),
			'caption'             => '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_title,
			'syndication_targets' => $targets,
			'post_status'         => 'publish',
			'created_from'        => (string) get_post_meta( $post->ID, '_daymark_created_from', true ),
		);

		$this->maybe_syndicate( (int) $post->ID, $targets, $daymark_data );
	}

	/**
	 * Normalize a $_FILES-style array into a flat list of single files.
	 *
	 * Handles both `daymark_media` (single) and `daymark_media[]` (PHP's
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
				'daymark_upload_error',
				__( 'The file failed to upload.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $file['tmp_name'] || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error(
				'daymark_upload_error',
				__( 'The uploaded file could not be read.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filters the maximum accepted size of a single uploaded file, in bytes.
		 *
		 * @param int $max_bytes Defaults to Daymark_Publisher::MAX_FILE_BYTES.
		 */
		$max_file_bytes = (int) apply_filters( 'daymark_upload_max_bytes', self::MAX_FILE_BYTES );
		$max_file_bytes = max( 1, $max_file_bytes );

		if ( (int) ( $file['size'] ?? 0 ) > $max_file_bytes ) {
			return new WP_Error(
				'daymark_upload_too_large',
				sprintf(
					/* translators: 1: file name, 2: maximum upload size (e.g. "50 MB"). */
					__( '"%1$s" is too large. Maximum upload size is %2$s.', 'daymark' ),
					sanitize_text_field( (string) $file['name'] ),
					size_format( $max_file_bytes )
				),
				array( 'status' => 400 )
			);
		}

		// 1) Content-based MIME sniff.
		$finfo        = new finfo( FILEINFO_MIME_TYPE );
		$content_mime = (string) $finfo->file( $file['tmp_name'] );
		$content_mime = self::canonical_mime( $content_mime );

		if ( ! in_array( $content_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_mime',
				__( 'File type not allowed.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// 2) WordPress filename/extension cross-check.
		$check      = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$check_mime = (string) ( $check['type'] ?? '' );
		$check_mime = self::canonical_mime( $check_mime );

		if ( '' === $check_mime || ! in_array( $check_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_mime',
				__( 'File type not allowed.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate the whole file list before any upload: each file individually,
	 * plus a per-request total byte budget so many files cannot bypass the
	 * per-file cap.
	 *
	 * @param array<int, array<string, mixed>> $file_list Flat list of file arrays.
	 * @return true|WP_Error
	 */
	private function validate_file_list( array $file_list ) {
		/**
		 * Filters the maximum combined size of one upload request, in bytes.
		 *
		 * @param int $max_bytes Defaults to Daymark_Publisher::MAX_TOTAL_FILE_BYTES.
		 */
		$max_total = (int) apply_filters( 'daymark_upload_total_max_bytes', self::MAX_TOTAL_FILE_BYTES );
		$max_total = max( 1, $max_total );

		$total = 0;

		foreach ( $file_list as $file ) {
			$valid = $this->validate_file( $file );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$total += (int) ( $file['size'] ?? 0 );
		}

		if ( $total > $max_total ) {
			return new WP_Error(
				'daymark_upload_total_too_large',
				sprintf(
					/* translators: %s: maximum total upload size (e.g. "100 MB"). */
					__( 'The combined upload is too large. Maximum total is %s.', 'daymark' ),
					size_format( $max_total )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Sideload validated files into the Media Library.
	 *
	 * Attachments are created unattached; attach_media() re-parents them
	 * once the Mark post exists.
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
	 * Detect the primary Mark type from attached media.
	 *
	 * Rules: no media → note; 1 image → image; 2+ images → gallery;
	 * video only → video; audio only → audio; mixed media → mixed.
	 * An explicit valid override (e.g. `note`) wins.
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
	 * Build standard block markup for the Mark content.
	 *
	 * Uses core/image, core/gallery, core/video, core/audio, and
	 * core/paragraph so the Mark renders in any theme.
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
	 * Attach media to the Mark post and set the featured image.
	 *
	 * @param int    $post_id   Mark post ID.
	 * @param int[]  $media_ids Attachment IDs.
	 * @param string $type      Primary Mark type.
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
	 * Used when editing a Mark: { attachmentId: altText }. An empty value
	 * clears that image's alt (an intentional edit); non-image IDs are
	 * ignored. IDs are restricted to the Mark's own media list so this can
	 * never be used to edit alt text on an attachment that belongs to
	 * another post.
	 *
	 * @param mixed $map         Alt map (array or JSON string) keyed by attachment ID.
	 * @param int[] $allowed_ids Attachment IDs the map is allowed to touch.
	 * @return void
	 */
	private function apply_alt_map( $map, array $allowed_ids ): void {
		if ( is_string( $map ) ) {
			$decoded = json_decode( $map, true );
			$map     = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $map ) ) {
			return;
		}

		$allowed_ids = array_map( 'intval', $allowed_ids );

		foreach ( $map as $id => $alt ) {
			$id = absint( $id );

			if ( $id && in_array( $id, $allowed_ids, true ) && wp_attachment_is_image( $id ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
			}
		}
	}

	/**
	 * Read camera EXIF metadata from a Mark's first image attachment.
	 *
	 * Only the first image in $media_ids is considered: video/audio never
	 * carry EXIF, and a second image's own EXIF is no more authoritative
	 * about "when/how was this Mark captured" than the first. Reads from
	 * `wp_get_attachment_metadata()['image_meta']`, which core's own
	 * `wp_generate_attachment_metadata()` already populates during
	 * sideload — this never re-parses EXIF itself.
	 *
	 * @since 0.11.0
	 *
	 * @param int[] $media_ids Attachment IDs, in upload order.
	 * @return array{camera: array<string, string>, exif_timestamp: int|null}
	 */
	private function extract_camera_info( array $media_ids ): array {
		/**
		 * Whether camera EXIF fields (camera/aperture/iso/focal_length/
		 * shutter_speed) are copied into `_daymark_camera` post meta.
		 *
		 * Defaults to true. Set to false to opt a site out of storing this
		 * quietly-captured device/equipment metadata — see the "quiet Mark
		 * metadata capture" feature's privacy note in readme.txt/README.md.
		 * This does not affect the EXIF capture-timestamp fallback (see
		 * resolve_captured_at()), which is a separate date/time concern.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $capture Defaults to true.
		 */
		$capture_camera_metadata = (bool) apply_filters( 'daymark_capture_camera_metadata', true );

		foreach ( $media_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$metadata   = wp_get_attachment_metadata( $attachment_id );
			$image_meta = ( is_array( $metadata ) && isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) )
				? $metadata['image_meta']
				: array();

			$camera = array();

			if ( $capture_camera_metadata ) {
				foreach ( self::CAMERA_META_FIELDS as $field ) {
					if ( ! empty( $image_meta[ $field ] ) && is_scalar( $image_meta[ $field ] ) ) {
						$camera[ $field ] = sanitize_text_field( (string) $image_meta[ $field ] );
					}
				}
			}

			$exif_timestamp = null;

			if ( isset( $image_meta['created_timestamp'] ) && is_numeric( $image_meta['created_timestamp'] ) && (int) $image_meta['created_timestamp'] > 0 ) {
				$exif_timestamp = (int) $image_meta['created_timestamp'];
			}

			return array(
				'camera'         => $camera,
				'exif_timestamp' => $exif_timestamp,
			);
		}

		return array(
			'camera'         => array(),
			'exif_timestamp' => null,
		);
	}

	/**
	 * Resolve the capture timestamp to use for a Mark, in priority order:
	 * (a) a valid, parseable client-supplied value that isn't more than
	 * CAPTURE_TIME_FUTURE_TOLERANCE in the future (small clock-skew
	 * tolerance — further out is treated as unusable, not an error); (b)
	 * the first image attachment's EXIF created_timestamp. Returns null
	 * when neither signal resolves — the caller decides what "no signal"
	 * means for its own context (publish() falls back to "now" via
	 * wp_insert_post()'s own default; update() leaves the existing
	 * post_date untouched).
	 *
	 * @since 0.11.0
	 *
	 * @param mixed    $client_value   Raw captured_at input (expected ISO 8601 string, or empty/absent).
	 * @param int|null $exif_timestamp EXIF created_timestamp from extract_camera_info(), if any.
	 * @return string|null MySQL datetime (UTC), or null when nothing resolved.
	 */
	private function resolve_captured_at( $client_value, ?int $exif_timestamp ): ?string {
		$client_value = is_string( $client_value ) ? trim( $client_value ) : '';

		if ( '' !== $client_value ) {
			$timestamp = strtotime( $client_value );

			if ( false !== $timestamp && $timestamp <= time() + self::CAPTURE_TIME_FUTURE_TOLERANCE ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		if ( null !== $exif_timestamp && $exif_timestamp > 0 ) {
			return gmdate( 'Y-m-d H:i:s', $exif_timestamp );
		}

		return null;
	}

	/**
	 * Resolve an optional client-supplied location from Mark input.
	 *
	 * Silently returns null (never an error) for anything not usable: a
	 * missing lat/lng pair, a non-numeric value, or a value outside the
	 * valid range — "no location supplied" and "an invalid location was
	 * supplied" are treated identically, since a location is always
	 * optional and must never fail a publish.
	 *
	 * @since 0.11.0
	 *
	 * @param array<string, mixed> $data Sanitized Mark input (location_lat, location_lng,
	 *                                   location_accuracy).
	 * @return array{lat: float, lng: float, accuracy?: float}|null
	 */
	private function resolve_location( array $data ): ?array {
		/**
		 * Whether a Mark's quietly-captured location (lat/lng, best-effort
		 * from the composer's browser geolocation) is stored at all.
		 *
		 * Defaults to true. Set to false to opt a site out of location
		 * capture entirely — the client may still send it, but the server
		 * ignores it — see the "quiet Mark metadata capture" feature's
		 * privacy note in readme.txt/README.md. Disabling this also
		 * prevents fetch_weather() from ever running, since weather is only
		 * ever attempted alongside a resolved location.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $capture Defaults to true.
		 */
		if ( ! apply_filters( 'daymark_capture_location', true ) ) {
			return null;
		}

		if ( ! isset( $data['location_lat'] ) || ! isset( $data['location_lng'] ) ) {
			return null;
		}

		$lat = $data['location_lat'];
		$lng = $data['location_lng'];

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		$lat = (float) $lat;
		$lng = (float) $lng;

		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		$location = array(
			'lat' => $lat,
			'lng' => $lng,
		);

		if ( isset( $data['location_accuracy'] ) && is_numeric( $data['location_accuracy'] ) ) {
			$location['accuracy'] = (float) $data['location_accuracy'];
		}

		return $location;
	}

	/**
	 * Resolve a reply-to URL sent by the composer's "Reply" action on a
	 * subscribed post (issue #83), if present and a well-formed http(s) URL.
	 *
	 * Deliberately just a URL, never fetched or verified server-side — this
	 * is Daymark's own POSSE markup (Daymark_Microformats renders it as
	 * u-in-reply-to on the published Mark's permalink), not a Webmention
	 * protocol implementation. Whichever Webmention plugin the site owner
	 * has active already auto-sends a notification to any link a published
	 * post's own content links out to; Daymark never sends, receives, or
	 * verifies a Webmention itself. See CLAUDE.md's Webmention decision.
	 *
	 * @param array<string, mixed> $data Publisher input.
	 * @return string|null The URL, or null when absent/invalid.
	 */
	private function resolve_in_reply_to( array $data ): ?string {
		$url = isset( $data['in_reply_to'] ) ? trim( (string) $data['in_reply_to'] ) : '';

		if ( '' === $url ) {
			return null;
		}

		$url    = esc_url_raw( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Best-effort current-weather lookup via Open-Meteo (api.open-meteo.com),
	 * a free service requiring no API key or account signup — chosen
	 * specifically to avoid adding a second third-party credential to store,
	 * matching the spirit of this plugin's own "no AI provider API key
	 * storage" non-goal (see CLAUDE.md) even though weather isn't AI.
	 *
	 * No SSRF surface applies here: the only externally-influenced input is
	 * a numeric lat/lng pair already range-validated by resolve_location(),
	 * passed as query params to one fixed, hardcoded host. There is no
	 * user-controlled URL, host, or path component the way a subscription's
	 * site/feed URL has, so Daymark_Subscription_Url_Guard does not apply
	 * here and is deliberately not invoked.
	 *
	 * Never throws (wrapped in try/catch against any unexpected failure
	 * mode) and never delays the publish response meaningfully — a short,
	 * filterable timeout. Any failure (timeout, non-200, malformed JSON,
	 * WP_Error) simply returns null; the caller leaves _daymark_weather
	 * unset rather than surfacing an error or retrying.
	 *
	 * @since 0.11.0
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return array{temperature: float, unit: string, condition: string, code: int}|null
	 */
	private function fetch_weather( float $lat, float $lng ) {
		/**
		 * Whether a Mark's weather is looked up at all, given a resolved
		 * location.
		 *
		 * Defaults to true. Set to false to opt a site out of the outbound
		 * Open-Meteo request entirely, independent of
		 * `daymark_capture_location` — useful for a site that's fine
		 * capturing location but does not want any outbound request tied
		 * to it. See the "quiet Mark metadata capture" feature's privacy
		 * note in readme.txt/README.md.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $capture Defaults to true.
		 */
		if ( ! apply_filters( 'daymark_capture_weather', true ) ) {
			return null;
		}

		try {
			$url = add_query_arg(
				array(
					'latitude'         => $lat,
					'longitude'        => $lng,
					'current'          => 'temperature_2m,weather_code',
					'temperature_unit' => 'celsius',
				),
				'https://api.open-meteo.com/v1/forecast'
			);

			/**
			 * Filters the HTTP timeout, in seconds, used for the best-effort
			 * Open-Meteo current-weather lookup.
			 *
			 * @since 0.11.0
			 *
			 * @param int $seconds Defaults to 4.
			 */
			$timeout = max( 1, (int) apply_filters( 'daymark_weather_fetch_timeout', 4 ) );

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body )
				|| ! isset( $body['current']['temperature_2m'], $body['current']['weather_code'] )
				|| ! is_numeric( $body['current']['temperature_2m'] )
				|| ! is_numeric( $body['current']['weather_code'] )
			) {
				return null;
			}

			$code = (int) $body['current']['weather_code'];

			return array(
				'temperature' => (float) $body['current']['temperature_2m'],
				'unit'        => 'C',
				'condition'   => self::WEATHER_CONDITION_LABELS[ $code ] ?? '—',
				'code'        => $code,
			);
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Compute and store a reading-time estimate from a Mark's caption plus
	 * transcript (when present — audio/video Marks add the two word counts
	 * together). Only stored when there's enough text to make the estimate
	 * meaningful; a Mark that drops below the threshold on edit has its
	 * estimate removed rather than left stale.
	 *
	 * @since 0.11.0
	 *
	 * @param int    $post_id   Mark post ID.
	 * @param string $caption   Caption text.
	 * @param string $transcript Transcript text.
	 * @return void
	 */
	private function apply_reading_time( int $post_id, string $caption, string $transcript ): void {
		$words = str_word_count( wp_strip_all_tags( $caption . ' ' . $transcript ) );

		if ( $words < self::READING_TIME_MIN_WORDS ) {
			delete_post_meta( $post_id, '_daymark_reading_time_minutes' );

			return;
		}

		$minutes = max( 1, (int) round( $words / self::WORDS_PER_MINUTE ) );

		update_post_meta( $post_id, '_daymark_reading_time_minutes', $minutes );
	}

	/**
	 * Set the post format matching the Mark type, so a Mark is not
	 * left in the site's default post format (e.g. an image Mark
	 * landing under Asides).
	 *
	 * @param int    $post_id Mark post ID.
	 * @param string $type    Primary Mark type.
	 * @return void
	 */
	private function apply_post_format( int $post_id, string $type ): void {
		$format = self::TYPE_POST_FORMATS[ $type ] ?? 'standard';

		// 'standard' clears the format term (set_post_format( , false )).
		set_post_format( $post_id, 'standard' === $format ? false : $format );
	}

	/**
	 * Generate a post title from the caption (first ~8 words, with a
	 * character-count backstop for a space-less caption — see
	 * MAX_TITLE_CHARS) or a timestamp fallback like
	 * "Mark — March 3, 2026 4:12 pm".
	 *
	 * @param string $caption Caption text.
	 * @return string Title.
	 */
	private function generate_title( string $caption ): string {
		$plain = trim( wp_strip_all_tags( $caption ) );

		if ( '' !== $plain ) {
			$title     = wp_trim_words( $plain, 8, '…' );
			$max_chars = (int) apply_filters( 'daymark_title_max_chars', self::MAX_TITLE_CHARS );

			if ( mb_strlen( $title ) > $max_chars ) {
				$title = $this->trim_chars( $title, $max_chars, '…' );
			}

			return $title;
		}

		return sprintf(
			/* translators: 1: localized date, 2: localized time. */
			__( 'Mark — %1$s %2$s', 'daymark' ),
			wp_date( get_option( 'date_format', 'F j, Y' ) ),
			wp_date( get_option( 'time_format', 'g:i a' ) )
		);
	}

	/**
	 * Trim text to a character count, splitting on Unicode codepoints
	 * (via the same `/./u` approach wp_trim_words() itself uses for its
	 * character-based locales) so a multibyte character is never cut
	 * mid-byte-sequence.
	 *
	 * Note: this splits at the codepoint level, not the full grapheme-
	 * cluster level, so a multi-codepoint emoji could in principle still
	 * split at the boundary — the same tradeoff WordPress core accepts
	 * in its own equivalent CJK-trimming path, rather than requiring the
	 * `intl` extension for a case this rare.
	 *
	 * @param string $text      Already-trimmed title text.
	 * @param int    $max_chars Maximum characters to keep, before appending $more.
	 * @param string $more      Suffix appended when trimming occurs.
	 * @return string
	 */
	private function trim_chars( string $text, int $max_chars, string $more ): string {
		preg_match_all( '/./u', $text, $matches );
		$chars = array_slice( $matches[0], 0, $max_chars );

		return implode( '', $chars ) . $more;
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
	 * @param string $type Primary Mark type.
	 * @return string[] Connector IDs.
	 */
	private function get_registry_defaults( string $type ): array {
		if ( ! class_exists( 'Daymark_Plugin' ) ) {
			return array();
		}

		$registry = Daymark_Plugin::instance()->syndication_registry;

		return $this->sanitize_connector_ids( $registry->get_default_destinations( $type ) );
	}

	/**
	 * User meta key remembering per-type destination selections.
	 *
	 * @var string
	 */
	private const DESTINATION_PREFS_META = 'daymark_destination_prefs';

	/**
	 * The preselected destinations for a Mark type.
	 *
	 * The user's last explicit selection for the type wins; the registry's
	 * model defaults are the fallback for types never published before.
	 *
	 * @param string $type    Mark primary type.
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
	 * Remember an explicit destination selection for a Mark type.
	 *
	 * Called on successful publish so the next Mark of the same type
	 * preselects the same networks. An explicit empty selection is
	 * remembered too — "none for notes" is a real preference.
	 *
	 * @param string   $type    Mark primary type.
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
	private const CATEGORY_PREFS_META = 'daymark_category_prefs';

	/**
	 * The preselected categories for a Mark type.
	 *
	 * The user's last explicit selection for the type wins. Unlike
	 * destinations there is no model fallback — categories are a per-site
	 * taxonomy with no universal mapping — so a type never filed before
	 * returns an empty list and the site's default category applies.
	 *
	 * @param string $type    Mark primary type.
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
	 * Remember an explicit category selection for a Mark type.
	 *
	 * Called on successful publish so the next Mark of the same type
	 * preselects the same categories. An explicit empty selection is
	 * remembered too — "file notes nowhere in particular" is a real choice.
	 *
	 * @param string $type Mark primary type.
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
		if ( ! class_exists( 'Daymark_Plugin' ) ) {
			return array();
		}

		$registry = Daymark_Plugin::instance()->syndication_registry;

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
	 * @param int                  $post_id     Mark post ID.
	 * @param string[]             $targets     Selected connector IDs.
	 * @param array<string, mixed> $daymark_data Mark context data.
	 * @return void
	 */
	private function maybe_syndicate( int $post_id, array $targets, array $daymark_data ): void {
		if ( empty( $targets ) || ! class_exists( 'Daymark_Plugin' ) ) {
			return;
		}

		$registry = Daymark_Plugin::instance()->syndication_registry;

		if ( method_exists( $registry, 'publish_to_targets' ) ) {
			$registry->publish_to_targets( $post_id, $targets, $daymark_data );
		}
	}
}
