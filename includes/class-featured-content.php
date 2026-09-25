<?php
/**
 * "Featured Content" — a "Set featured content" control rendered directly
 * inside core's own Featured Image panel, right after "Set featured image"
 * (via the documented `editor.PostFeaturedImage` wp.hooks filter — see
 * assets/featured-content-editor.js), letting an author set an audio file,
 * a video file (media library, or a profiled URL — YouTube/Vimeo/a podcast
 * episode link), a gallery, a quote, or (for the `link` post format) a
 * plain link as a post's featured content instead of a static image. See
 * issue #401 for the full design and phased build order this class
 * implements incrementally.
 *
 * Scoped site-wide, not to Marks: any post type that already shows a
 * Featured Image panel (declares `thumbnail` support) and actually uses the
 * block editor gets this panel too — a general content-authoring
 * enhancement, not something exclusive to Daymark's own composer/app-shell
 * flow. `daymark_sub_post` (Daymark's own cached-subscription-content CPT)
 * supports `thumbnail` but has no editor UI at all
 * (`use_block_editor_for_post_type()` is false for it), so it's excluded by
 * that same check with no special-case needed.
 *
 * Two post meta keys back this, not one: `_daymark_featured_content_type`
 * (a plain string enum, cheap to query/branch on without JSON-decoding —
 * matching the existing `_daymark_primary_type` convention) and
 * `_daymark_featured_content` (one JSON blob keyed by every content kind
 * this class has ever accepted, e.g. `{"audio": {...}, "video": {...}}`).
 * The two are sanitized independently rather than cross-validated against
 * each other at write time — `get_featured_content()` is what actually
 * resolves them together at read time, ignoring a `video` sub-key entirely
 * while the type meta reads `audio`. That's a deliberate simplification: a
 * REST client persists post meta as two independent PATCH-able fields (the
 * block editor's own `core/editor` entity store included), and there is no
 * reliable way for one field's `sanitize_callback` to see the other field's
 * pending value in the same request.
 *
 * `SUPPORTED_TYPES` (filterable via `daymark_featured_content_allowed_types`)
 * is deliberately scoped to what's actually implemented end-to-end —
 * `audio`, `video`, and (issue #406) `gallery` — so the editor panel and the
 * meta sanitizer can never accept or render a content kind before its own
 * phase has actually shipped a frontend renderer for it.
 *
 * No new build step: the editor panel (`assets/featured-content-editor.js`)
 * is plain ES2020 calling `wp.element.createElement` against WordPress
 * core's own bundled scripts, exactly the "vanilla, no build" posture
 * CLAUDE.md's "Block vs shortcode" decision already established for
 * `assets/app.js` — the one other place this codebase writes browser JS.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Featured Content's meta schema, editor panel, and frontend rendering.
 */
class Daymark_Featured_Content {

	/**
	 * Post meta key: which kind of Featured Content is set, if any.
	 *
	 * @var string
	 */
	public const META_TYPE = '_daymark_featured_content_type';

	/**
	 * Post meta key: JSON blob of Featured Content data, keyed by content kind.
	 *
	 * @var string
	 */
	public const META_DATA = '_daymark_featured_content';

	/**
	 * Content-kind values implemented end-to-end (editor UI + frontend
	 * rendering). Later phases widen this — quote, and a
	 * link-post-format-only `link` kind — as each one's own frontend
	 * rendering ships; see issue #401's phased plan.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_TYPES = array( 'audio', 'video', 'gallery' );

	/**
	 * Default cap on how many images a gallery Featured Content may store.
	 * Filterable via `daymark_featured_content_gallery_max`.
	 *
	 * @var int
	 */
	private const GALLERY_MAX = 20;

	/**
	 * File extensions a profiled URL's own path can end in and still be
	 * played by a plain native `<audio>`/`<video src>` element (via
	 * `wp_audio_shortcode()`/`wp_video_shortcode()`) — mirrors the
	 * AUDIO_EXTENSIONS/VIDEO_EXTENSIONS lists
	 * assets/featured-content-editor.js already keeps for the same reason,
	 * so is_direct_media_url() and that file's own isDirectMediaUrl() never
	 * disagree about which URLs qualify.
	 *
	 * @var string[]
	 */
	private const DIRECT_MEDIA_EXTENSIONS = array( 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'wma', 'mp4', 'm4v', 'mov', 'webm', 'ogv', 'avi', 'wmv' );

	/**
	 * Hook up. Called from Daymark_Plugin::on_init(), itself an `init`
	 * callback — register_meta() runs directly rather than via a nested
	 * `add_action( 'init', ... )`, matching the pattern
	 * Daymark_Subscription_Post_Type::register() already established for
	 * the same reason.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_meta();

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_style' ) );
		add_filter( 'post_thumbnail_html', array( $this, 'maybe_replace_post_thumbnail_html' ), 10, 2 );
	}

	/**
	 * Which post types get the "Set featured content" control: every
	 * registered post type that already declares Featured Image
	 * (`thumbnail`) support
	 * AND is genuinely edited in the block editor — `use_block_editor_for_post_type()`
	 * accounts for a type with no editor UI at all (`daymark_sub_post`) or one
	 * a site owner has explicitly opted back into the classic editor, neither
	 * of which should register (or ever need) this meta/panel.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		$types = array();

		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			if ( post_type_supports( $post_type, 'thumbnail' ) && use_block_editor_for_post_type( $post_type ) ) {
				$types[] = $post_type;
			}
		}

		/**
		 * Filters which post types show the "Set featured content" control.
		 *
		 * @since 0.18.0
		 *
		 * @param string[] $types Defaults to every post type with Featured
		 *                        Image support that also uses the block editor.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'daymark_featured_content_supported_post_types', $types ) ) ) );
	}

	/**
	 * Which Featured Content type values are currently accepted.
	 *
	 * @return string[]
	 */
	private static function allowed_types(): array {
		/**
		 * Filters which Featured Content type values are accepted by the
		 * meta sanitizer and offered by the editor panel. Scoped to what
		 * is implemented end-to-end; later phases widen this as quote/link
		 * ship — see issue #401.
		 *
		 * @since 0.18.0
		 *
		 * @param string[] $types Defaults to `array( 'audio', 'video', 'gallery' )`.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'daymark_featured_content_allowed_types', self::SUPPORTED_TYPES ) ) ) );
	}

	/**
	 * Register both meta keys on every supported post type.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth_callback = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		foreach ( self::supported_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_TYPE,
				array(
					'type'              => 'string',
					'description'       => __( 'Which kind of Featured Content this post has set, if any.', 'daymark' ),
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_type' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				$post_type,
				self::META_DATA,
				array(
					'type'              => 'string',
					'description'       => __( 'JSON-encoded Featured Content data, keyed by content kind.', 'daymark' ),
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_data' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}
	}

	/**
	 * Sanitize `_daymark_featured_content_type` — an empty string ("none")
	 * or one of allowed_types(); anything else (an unsupported/future kind,
	 * garbage input) also collapses to "none" rather than being rejected
	 * outright, matching this codebase's general "never error a meta save,
	 * just don't trust what you can't validate" posture.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_type( $value ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, self::allowed_types(), true ) ? $value : '';
	}

	/**
	 * Sanitize `_daymark_featured_content` — keeps only sub-keys this class
	 * currently recognizes (`audio`/`video`/`gallery`), each narrowed to its
	 * own allowed shape; a quote/link sub-key sent by a future client ahead
	 * of that phase's own rollout is silently dropped rather than stored
	 * unsanitized, since nothing here can validate a shape this class
	 * doesn't implement yet.
	 *
	 * @param mixed $value Raw JSON string.
	 * @return string Re-encoded, sanitized JSON (possibly `'{}'`).
	 */
	public static function sanitize_data( $value ): string {
		$decoded = json_decode( (string) $value, true );

		if ( ! is_array( $decoded ) ) {
			return '{}';
		}

		$clean = array();

		if ( isset( $decoded['audio'] ) && is_array( $decoded['audio'] ) ) {
			$audio = self::sanitize_media_or_url_shape( $decoded['audio'] );

			if ( ! empty( $audio ) ) {
				$clean['audio'] = $audio;
			}
		}

		if ( isset( $decoded['video'] ) && is_array( $decoded['video'] ) ) {
			$video = self::sanitize_media_or_url_shape( $decoded['video'], 'video' );

			if ( ! empty( $video ) ) {
				$clean['video'] = $video;
			}
		}

		if ( isset( $decoded['gallery'] ) && is_array( $decoded['gallery'] ) ) {
			$gallery = self::sanitize_gallery_shape( $decoded['gallery'] );

			if ( ! empty( $gallery ) ) {
				$clean['gallery'] = $gallery;
			}
		}

		$encoded = wp_json_encode( $clean );

		return false === $encoded ? '{}' : $encoded;
	}

	/**
	 * Sanitize the shared `{ source: 'library'|'url', attachment_id?, url? }`
	 * shape audio and video both use. A `library` source is only kept when
	 * the attachment genuinely exists and its MIME type actually matches
	 * (an image ID sent for `source: 'library', attachment_id: <image>`
	 * never gets trusted as audio/video) — `wp_attachment_is()` is core's
	 * own MIME-family check, the same kind of defense-in-depth
	 * `Daymark_Publisher::validate_file_list()` already applies to an
	 * uploaded file before trusting its declared type.
	 *
	 * @param array<string, mixed> $raw   Decoded sub-array for one content kind.
	 * @param string               $kind  'audio' or 'video' — which
	 *                                    `wp_attachment_is()` family to check.
	 * @return array<string, mixed> Empty when nothing usable was found.
	 */
	private static function sanitize_media_or_url_shape( array $raw, string $kind = 'audio' ): array {
		$source = isset( $raw['source'] ) && 'url' === $raw['source'] ? 'url' : 'library';

		if ( 'url' === $source ) {
			$url = isset( $raw['url'] ) ? esc_url_raw( trim( (string) $raw['url'] ) ) : '';

			return '' === $url ? array() : array(
				'source' => 'url',
				'url'    => $url,
			);
		}

		$attachment_id = isset( $raw['attachment_id'] ) ? absint( $raw['attachment_id'] ) : 0;

		if ( $attachment_id <= 0 || ! wp_attachment_is( $kind, $attachment_id ) ) {
			return array();
		}

		return array(
			'source'        => 'library',
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * How many images a gallery Featured Content may store.
	 *
	 * @return int
	 */
	private static function gallery_max(): int {
		/**
		 * Filters the maximum number of images stored for gallery Featured Content.
		 *
		 * @since 0.18.0
		 *
		 * @param int $max Defaults to 20.
		 */
		return max( 1, (int) apply_filters( 'daymark_featured_content_gallery_max', self::GALLERY_MAX ) );
	}

	/**
	 * Sanitize a gallery sub-key: `{ attachment_ids: [int, ...] }`. IDs are
	 * absint'd, de-duplicated (first occurrence wins, order preserved), and
	 * kept only when they are real image attachments. Anything else — a
	 * video ID, a missing attachment, a non-numeric value — is dropped.
	 * An empty result is rejected entirely so a type of `gallery` with no
	 * usable images never gets stored.
	 *
	 * @param array<string, mixed> $raw Decoded gallery sub-array.
	 * @return array{attachment_ids: int[]}|array{}
	 */
	private static function sanitize_gallery_shape( array $raw ): array {
		$ids   = isset( $raw['attachment_ids'] ) && is_array( $raw['attachment_ids'] ) ? $raw['attachment_ids'] : array();
		$clean = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );

			if ( $id <= 0 || isset( $clean[ $id ] ) || ! wp_attachment_is_image( $id ) ) {
				continue;
			}

			$clean[ $id ] = $id;

			if ( count( $clean ) >= self::gallery_max() ) {
				break;
			}
		}

		if ( empty( $clean ) ) {
			return array();
		}

		return array(
			'attachment_ids' => array_values( $clean ),
		);
	}

	/**
	 * Whether a post has a genuinely usable Featured Content set.
	 *
	 * @param int|WP_Post|null $post Post ID/object, or null for the current post.
	 * @return bool
	 */
	public static function has_featured_content( $post = null ): bool {
		return ! empty( self::get_featured_content( $post ) );
	}

	/**
	 * Resolve a post's Featured Content: its type, plus that type's own
	 * sanitized data sub-array. Empty when unset, an unrecognized type, or
	 * the paired data sub-key is missing/empty (e.g. the type meta says
	 * `audio` but the data blob was never actually saved with one).
	 *
	 * @param int|WP_Post|null $post Post ID/object, or null for the current post.
	 * @return array{type: string, data: array<string, mixed>}|array{}
	 */
	public static function get_featured_content( $post = null ): array {
		$post = get_post( $post );

		if ( ! $post ) {
			return array();
		}

		$type = (string) get_post_meta( $post->ID, self::META_TYPE, true );

		if ( '' === $type || ! in_array( $type, self::allowed_types(), true ) ) {
			return array();
		}

		$raw  = json_decode( (string) get_post_meta( $post->ID, self::META_DATA, true ), true );
		$data = ( is_array( $raw ) && isset( $raw[ $type ] ) && is_array( $raw[ $type ] ) ) ? $raw[ $type ] : array();

		if ( empty( $data ) ) {
			return array();
		}

		return array(
			'type' => $type,
			'data' => $data,
		);
	}

	/**
	 * Echo a post's rendered Featured Content markup — the theme-facing
	 * template tag for a theme that wants to render Featured Content
	 * somewhere other than (or in addition to) its own Featured Image slot.
	 * A theme relying purely on `the_post_thumbnail()` needs to call this
	 * at all — see maybe_replace_post_thumbnail_html() for the automatic
	 * substitution path every other theme already gets for free.
	 *
	 * @param int|WP_Post|null     $post Post ID/object, or null for the current post.
	 * @param array<string, mixed> $args Reserved for future rendering options.
	 * @return void
	 */
	public static function the_featured_content( $post = null, array $args = array() ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() only ever returns markup this class itself built from sanitized, already-escaped pieces (esc_url()'d src attributes, wp_audio_shortcode()/wp_video_shortcode()'s own escaped output, or a caller-filtered override via daymark_featured_content_html, which callers are responsible for escaping themselves — the same contract every other *_html filter in this codebase already carries).
		echo self::render( $post, $args );
	}

	/**
	 * Build a post's Featured Content markup.
	 *
	 * @param int|WP_Post|null     $post Post ID/object, or null for the current post.
	 * @param array<string, mixed> $args Reserved for future rendering options.
	 * @return string
	 */
	private static function render( $post, array $args = array() ): string {
		$post = get_post( $post );
		$fc   = self::get_featured_content( $post );

		if ( empty( $fc ) || ! $post ) {
			return '';
		}

		$html = '';

		if ( 'audio' === $fc['type'] ) {
			$html = self::render_audio( $fc['data'] );
		} elseif ( 'video' === $fc['type'] ) {
			$html = self::render_video( $fc['data'] );
		} elseif ( 'gallery' === $fc['type'] ) {
			$html = self::render_gallery( $fc['data'] );
		}

		/**
		 * Filters a post's rendered Featured Content markup — the escape
		 * hatch for a theme that wants Featured Content to look different
		 * from this class's own default rendering.
		 *
		 * @since 0.18.0
		 *
		 * @param string                $html Default rendered markup, or '' when nothing rendered.
		 * @param array{type: string, data: array<string, mixed>} $fc   Resolved Featured Content.
		 * @param WP_Post               $post The post.
		 * @param array<string, mixed>  $args Rendering options passed to the_featured_content()/get_the_featured_content().
		 */
		$html = (string) apply_filters( 'daymark_featured_content_html', $html, $fc, $post, $args );

		if ( '' === $html ) {
			return '';
		}

		// A stable wrapper (assets/featured-content.css targets this for
		// responsive iframe/audio/video sizing) plus a type modifier class,
		// regardless of whether $html came from this class's own rendering
		// or a theme's daymark_featured_content_html override.
		return sprintf(
			'<div class="daymark-featured-content daymark-featured-content--%1$s">%2$s</div>',
			esc_attr( $fc['type'] ),
			$html
		);
	}

	/**
	 * Whether a URL itself is a direct media file (matches
	 * DIRECT_MEDIA_EXTENSIONS) rather than a provider *page* URL (a Vimeo/
	 * YouTube watch page, a podcast episode page with no file extension,
	 * etc.). Only a direct file URL can ever be played by a plain native
	 * `<audio>`/`<video src>` element — falling back to one for a provider
	 * page URL whenever oEmbed resolution finds nothing (e.g. a private or
	 * unlisted video oEmbed declines to embed) always renders a broken
	 * player, never a degraded-but-working one, so render_audio()/
	 * render_video() only take that fallback path when this returns true.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function is_direct_media_url( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::DIRECT_MEDIA_EXTENSIONS, true );
	}

	/**
	 * Render an audio-type Featured Content: an attachment via core's own
	 * `wp_audio_shortcode()`, or a URL via a resolved oEmbed preview
	 * (Daymark_Subscription_Oembed::resolve() — already fully generic, no
	 * subscription-specific logic in it, so reused rather than forked),
	 * falling back to a plain native `<audio>` element via the same
	 * shortcode function only for a direct file URL (e.g. a podcast host's
	 * own `.mp3` episode link) an oEmbed provider can't resolve — never for
	 * a provider page URL, which that native fallback can't play either
	 * way (see is_direct_media_url()).
	 *
	 * @param array{source: string, attachment_id?: int, url?: string} $data Sanitized audio data.
	 * @return string
	 */
	private static function render_audio( array $data ): string {
		if ( 'library' === ( $data['source'] ?? '' ) && ! empty( $data['attachment_id'] ) ) {
			return (string) wp_audio_shortcode( array( 'src' => wp_get_attachment_url( (int) $data['attachment_id'] ) ) );
		}

		$url = (string) ( $data['url'] ?? '' );

		if ( '' === $url ) {
			return '';
		}

		$embed = class_exists( 'Daymark_Subscription_Oembed' ) ? Daymark_Subscription_Oembed::resolve( $url ) : array();

		if ( ! empty( $embed['html'] ) ) {
			return (string) $embed['html'];
		}

		return self::is_direct_media_url( $url ) ? (string) wp_audio_shortcode( array( 'src' => $url ) ) : '';
	}

	/**
	 * Render a video-type Featured Content — same source/oEmbed/fallback
	 * order as render_audio(), via `wp_video_shortcode()` instead.
	 *
	 * @param array{source: string, attachment_id?: int, url?: string} $data Sanitized video data.
	 * @return string
	 */
	private static function render_video( array $data ): string {
		if ( 'library' === ( $data['source'] ?? '' ) && ! empty( $data['attachment_id'] ) ) {
			return (string) wp_video_shortcode( array( 'src' => wp_get_attachment_url( (int) $data['attachment_id'] ) ) );
		}

		$url = (string) ( $data['url'] ?? '' );

		if ( '' === $url ) {
			return '';
		}

		$embed = class_exists( 'Daymark_Subscription_Oembed' ) ? Daymark_Subscription_Oembed::resolve( $url ) : array();

		if ( ! empty( $embed['html'] ) ) {
			return (string) $embed['html'];
		}

		return self::is_direct_media_url( $url ) ? (string) wp_video_shortcode( array( 'src' => $url ) ) : '';
	}

	/**
	 * Render a gallery Featured Content as a slider shell. Every slide is in
	 * the markup (a no-JS visitor sees them stacked; assets/featured-content.js
	 * adds `is-enhanced` and shows one at a time). Controls, dots, and the
	 * live region are omitted for a single image — there is nothing to slide.
	 *
	 * @param array{attachment_ids?: int[]} $data Sanitized gallery data.
	 * @return string
	 */
	private static function render_gallery( array $data ): string {
		$ids    = isset( $data['attachment_ids'] ) && is_array( $data['attachment_ids'] ) ? $data['attachment_ids'] : array();
		$images = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );

			if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
				continue;
			}

			$img = wp_get_attachment_image(
				$id,
				'large',
				false,
				array(
					'class' => 'daymark-fc-gallery__img',
				)
			);

			if ( '' === $img ) {
				continue;
			}

			$images[] = $img;
		}

		$count = count( $images );

		if ( 0 === $count ) {
			return '';
		}

		$slides = array();

		foreach ( $images as $index => $img ) {
			$slides[] = sprintf(
				'<div class="daymark-fc-gallery__slide" role="group" aria-roledescription="%1$s" aria-label="%2$s">%3$s</div>',
				esc_attr__( 'slide', 'daymark' ),
				esc_attr(
					sprintf(
						/* translators: 1: current slide number, 2: total slides. */
						__( 'Slide %1$d of %2$d', 'daymark' ),
						$index + 1,
						$count
					)
				),
				$img
			);
		}

		$controls = '';

		if ( $count > 1 ) {
			$dots = array();

			for ( $i = 0; $i < $count; $i++ ) {
				$dots[] = sprintf(
					'<button type="button" class="daymark-fc-gallery__dot" data-daymark-gallery-dot data-index="%1$d" aria-label="%2$s"></button>',
					$i,
					esc_attr(
						sprintf(
							/* translators: %d: slide number. */
							__( 'Show slide %d', 'daymark' ),
							$i + 1
						)
					)
				);
			}

			$controls = sprintf(
				'<button type="button" class="daymark-fc-gallery__nav daymark-fc-gallery__nav--prev" data-daymark-gallery-prev aria-label="%1$s"></button><button type="button" class="daymark-fc-gallery__nav daymark-fc-gallery__nav--next" data-daymark-gallery-next aria-label="%2$s"></button><div class="daymark-fc-gallery__dots">%3$s</div><div class="screen-reader-text" data-daymark-gallery-live aria-live="polite" data-template="%4$s"></div>',
				esc_attr__( 'Previous slide', 'daymark' ),
				esc_attr__( 'Next slide', 'daymark' ),
				implode( '', $dots ),
				esc_attr(
					/* translators: 1: current slide number, 2: total slides. */
					__( 'Slide %1$d of %2$d', 'daymark' )
				)
			);
		}

		return sprintf(
			'<div class="daymark-fc-gallery" data-daymark-gallery tabindex="0" role="region" aria-roledescription="%1$s" aria-label="%2$s"><div class="daymark-fc-gallery__track">%3$s</div>%4$s</div>',
			esc_attr__( 'carousel', 'daymark' ),
			esc_attr__( 'Featured gallery', 'daymark' ),
			implode( '', $slides ),
			$controls
		);
	}

	/**
	 * Enqueue the Featured Content editor control, only on a post-edit
	 * screen for a post type this class actually supports.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
			return;
		}

		// The block editor's own Featured Image panel never calls wp.media()
		// at all (it reads/writes media via REST directly), so — unlike
		// Featured Image support alone — nothing else on this screen
		// guarantees the classic media modal's scripts/templates are
		// actually present. wp_enqueue_media() is what wp-admin's own
		// classic editor/media-library screens call for the same reason.
		wp_enqueue_media();

		wp_enqueue_script(
			'daymark-featured-content-editor',
			DAYMARK_PLUGIN_URL . 'assets/featured-content-editor.js',
			array( 'wp-hooks', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'media-editor', 'media-models' ),
			DAYMARK_VERSION,
			true
		);

		wp_enqueue_style(
			'daymark-featured-content-editor',
			DAYMARK_PLUGIN_URL . 'assets/featured-content-editor.css',
			array(),
			DAYMARK_VERSION
		);

		wp_localize_script(
			'daymark-featured-content-editor',
			'daymarkFeaturedContent',
			array(
				'metaType'       => self::META_TYPE,
				'metaData'       => self::META_DATA,
				'allowedTypes'   => self::allowed_types(),
				'oembedEndpoint' => rest_url( 'daymark/v1/featured-content/oembed' ),
			)
		);
	}

	/**
	 * Enqueue the frontend Featured Content stylesheet — only on a singular
	 * request for a post that actually has Featured Content set, never
	 * site-wide, matching this class's own conservative-enqueue posture
	 * elsewhere.
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend_style(): void {
		$post_id = (int) get_queried_object_id();

		if ( ! is_singular() || ! self::has_featured_content( $post_id ) ) {
			return;
		}

		wp_enqueue_style(
			'daymark-featured-content',
			DAYMARK_PLUGIN_URL . 'assets/featured-content.css',
			array(),
			DAYMARK_VERSION
		);

		$fc = self::get_featured_content( $post_id );

		if ( isset( $fc['type'] ) && 'gallery' === $fc['type'] ) {
			wp_enqueue_script(
				'daymark-featured-content',
				DAYMARK_PLUGIN_URL . 'assets/featured-content.js',
				array(),
				DAYMARK_VERSION,
				true
			);
		}
	}

	/**
	 * Swap in Featured Content's own rendering wherever a theme calls
	 * `the_post_thumbnail()`/`get_the_post_thumbnail()` — the "presented in
	 * place of a Featured Image" half of the request, for a theme that
	 * needs zero changes of its own to pick this up. Front-end only
	 * (`!is_admin()`): wp-admin's own post-list "Featured Image" column (or
	 * any other admin-side caller of this same core filter) is left
	 * completely alone, matching the "public discovery surfaces only"
	 * precedent `Daymark_Like_Visibility` already established for a
	 * different reason.
	 *
	 * Only the first two of `post_thumbnail_html`'s five filter args are
	 * used, so `add_filter()` above registers this with `$accepted_args = 2`
	 * — WordPress slices the args passed to the callback to that count
	 * before calling it, so the unused `$post_thumbnail_id`/`$size`/`$attr`
	 * params simply aren't declared here at all, rather than declared and
	 * ignored.
	 *
	 * @param string $html    Existing Featured Image HTML.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function maybe_replace_post_thumbnail_html( $html, $post_id ) {
		if ( is_admin() ) {
			return $html;
		}

		/**
		 * Filters whether Featured Content, when set, replaces a theme's
		 * ordinary Featured Image rendering wherever it calls
		 * `the_post_thumbnail()`. Default true. A theme that wants Featured
		 * Content to render somewhere different from its Featured Image
		 * slot (rather than in its place) can set this to false and call
		 * `daymark_the_featured_content()`/`daymark_get_featured_content()`
		 * directly instead.
		 *
		 * @since 0.18.0
		 *
		 * @param bool $replace Defaults to true.
		 * @param int  $post_id The post ID.
		 */
		if ( ! apply_filters( 'daymark_featured_content_replaces_featured_image', true, $post_id ) ) {
			return $html;
		}

		$rendered = self::render( $post_id );

		return '' !== $rendered ? $rendered : $html;
	}
}
