<?php
/**
 * Featured Content, Phase 4 (issue #408): a post's share image and
 * description for oEmbed and Open Graph / Twitter Card, so a post whose
 * Featured Content is a gallery, a video, a quote, or a link still shows
 * something real when it's shared on a social platform or embedded in
 * another WordPress post.
 *
 * Resolution is dispatched by Featured Content kind:
 *
 *  - gallery: the first image (a local attachment).
 *  - audio/video from the Media Library: the attachment's own cover art,
 *    which WordPress stores as that attachment's own featured image.
 *  - audio/video from a URL (YouTube, Vimeo, a podcast page): the
 *    provider's oEmbed thumbnail, via Daymark_Subscription_Oembed.
 *  - link: the linked page's Open Graph image, via
 *    Daymark_Subscription_Opengraph.
 *  - quote: no image; the quote text becomes the share description.
 *
 * The two remote kinds need an outbound request, so they are resolved
 * after the Featured Content meta changes (a single cron event, so saving
 * a post never waits on a third-party host) and stored in META_IMAGE with a
 * hash of the value they were resolved from. A page view, an oEmbed request,
 * or an Open Graph tag only ever reads that stored result — a visitor can
 * never trigger an outbound fetch.
 *
 * Open Graph handoff, "awareness, not control" like
 * Daymark_Publish_Helpers: when Yoast SEO, Rank Math, All in One SEO, or
 * Jetpack's Open Graph output is active, the image is handed to that
 * plugin's own documented hook (each confirmed against the plugin's
 * public source), and Daymark prints nothing itself. Only when none of
 * them is active does Daymark print its own minimal og:* and twitter:*
 * tags, so a page never gets two conflicting sets.
 *
 * Featured Content wins over a regular Featured Image wherever
 * `daymark_featured_content_replaces_featured_image` is true (the default),
 * matching how it already replaces the image in the theme; with that
 * filter false, it only fills in when there is no Featured Image at all.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Share image/description resolution and the oEmbed + Open Graph wiring.
 */
class Daymark_Featured_Content_Social {

	/**
	 * Post meta holding a remotely-resolved share image, as JSON:
	 * `{ url, width, height, source }`, where `source` is the hash of the
	 * Featured Content value it was resolved from (see source_hash()).
	 *
	 * @var string
	 */
	public const META_IMAGE = '_daymark_featured_content_image';

	/**
	 * Cron hook that resolves a post's remote share image.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'daymark_featured_content_resolve_image';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'maybe_schedule_resolution' ), 10, 3 );
		}

		add_action( self::CRON_HOOK, array( $this, 'resolve_remote_image' ) );

		add_filter( 'oembed_response_data', array( $this, 'filter_oembed_response' ), 10, 2 );
		add_filter( 'embed_thumbnail_id', array( $this, 'filter_embed_thumbnail_id' ) );

		// Hand-offs to SEO plugins. Each is a no-op unless that plugin
		// fires it, so registering all of them is harmless.
		add_action( 'wpseo_add_opengraph_images', array( $this, 'add_yoast_image' ) );
		add_filter( 'wpseo_twitter_image', array( $this, 'filter_twitter_image_url' ) );
		add_action( 'rank_math/opengraph/facebook/add_images', array( $this, 'add_rank_math_image' ) );
		add_action( 'rank_math/opengraph/twitter/add_images', array( $this, 'add_rank_math_image' ) );
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_facebook_tags' ) );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_twitter_tags' ) );
		add_filter( 'jetpack_open_graph_tags', array( $this, 'filter_jetpack_tags' ) );

		add_action( 'wp_head', array( $this, 'print_meta_tags' ), 5 );
	}

	// -- Resolution ---------------------------------------------------------

	/**
	 * The share image for a post's Featured Content.
	 *
	 * @param int|WP_Post|null $post Post ID/object, or null for the current post.
	 * @return array{url: string, width: int, height: int, alt: string}|array{}
	 */
	public static function image( $post = null ): array {
		$post = get_post( $post );
		$fc   = $post ? Daymark_Featured_Content::get_featured_content( $post ) : array();

		if ( empty( $fc ) ) {
			return array();
		}

		$image = array();
		$data  = $fc['data'];

		if ( 'gallery' === $fc['type'] ) {
			$first = absint( ( $data['attachment_ids'] ?? array() )[0] ?? 0 );
			$image = self::attachment_image( $first );
		} elseif ( in_array( $fc['type'], array( 'audio', 'video' ), true ) && 'library' === ( $data['source'] ?? '' ) ) {
			$image = self::attachment_image( (int) get_post_thumbnail_id( absint( $data['attachment_id'] ?? 0 ) ) );
		} elseif ( self::is_remote_kind( $fc ) ) {
			$image = self::stored_image( $post->ID, $fc );
		}

		if ( empty( $image['url'] ) ) {
			return array();
		}

		/**
		 * Filters the share image URL resolved from a post's Featured Content,
		 * used for its oEmbed `thumbnail_url` and its Open Graph / Twitter
		 * Card image. Return an empty string to share no image.
		 *
		 * @since 0.19.0
		 *
		 * @param string  $url  Image URL.
		 * @param WP_Post $post The post.
		 * @param array   $fc   The post's Featured Content (`type`, `data`).
		 */
		$url = esc_url_raw( (string) apply_filters( 'daymark_featured_content_thumbnail_url', $image['url'], $post, $fc ) );

		if ( '' === $url ) {
			return array();
		}

		$image['url'] = $url;

		return $image;
	}

	/**
	 * A share description from a post's Featured Content: the quote text
	 * (with its author) for a quote, otherwise an empty string so callers
	 * fall back to the post's own excerpt.
	 *
	 * @param int|WP_Post|null $post Post ID/object, or null for the current post.
	 * @return string Plain text.
	 */
	public static function description( $post = null ): string {
		$fc = Daymark_Featured_Content::get_featured_content( $post );

		if ( empty( $fc ) || 'quote' !== $fc['type'] ) {
			return '';
		}

		$text   = trim( (string) ( $fc['data']['text'] ?? '' ) );
		$author = trim( (string) ( $fc['data']['author'] ?? '' ) );

		if ( '' === $text ) {
			return '';
		}

		return '' !== $author
			/* translators: 1: quoted text, 2: the quote's author. */
			? sprintf( __( '“%1$s” — %2$s', 'daymark' ), $text, $author )
			/* translators: %s: quoted text. */
			: sprintf( __( '“%s”', 'daymark' ), $text );
	}

	/**
	 * A local attachment as a share image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{url: string, width: int, height: int, alt: string}|array{}
	 */
	private static function attachment_image( int $attachment_id ): array {
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return array();
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'large' );

		if ( ! $src ) {
			return array();
		}

		return array(
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
		);
	}

	/**
	 * The attachment ID a post's Featured Content would show in its embed
	 * card, when that image is a local attachment.
	 *
	 * @param WP_Post $post The post.
	 * @return int Attachment ID, or 0.
	 */
	private static function attachment_id( WP_Post $post ): int {
		$fc = Daymark_Featured_Content::get_featured_content( $post );

		if ( empty( $fc ) ) {
			return 0;
		}

		if ( 'gallery' === $fc['type'] ) {
			return absint( ( $fc['data']['attachment_ids'] ?? array() )[0] ?? 0 );
		}

		if ( in_array( $fc['type'], array( 'audio', 'video' ), true ) && 'library' === ( $fc['data']['source'] ?? '' ) ) {
			return (int) get_post_thumbnail_id( absint( $fc['data']['attachment_id'] ?? 0 ) );
		}

		return 0;
	}

	/**
	 * Whether a Featured Content value's share image has to be fetched
	 * from somewhere else (and so is resolved ahead of time and stored).
	 *
	 * @param array{type: string, data: array<string, mixed>} $fc Featured Content.
	 * @return bool
	 */
	private static function is_remote_kind( array $fc ): bool {
		if ( 'link' === $fc['type'] ) {
			return true;
		}

		return in_array( $fc['type'], array( 'audio', 'video' ), true ) && 'url' === ( $fc['data']['source'] ?? '' );
	}

	/**
	 * Hash identifying the value a stored image was resolved from, so an
	 * image resolved for an earlier URL is never shown for a new one.
	 *
	 * @param array{type: string, data: array<string, mixed>} $fc Featured Content.
	 * @return string
	 */
	private static function source_hash( array $fc ): string {
		return md5( $fc['type'] . '|' . (string) ( $fc['data']['url'] ?? '' ) );
	}

	/**
	 * The stored remote share image, when it was resolved from the post's
	 * current Featured Content.
	 *
	 * @param int                                             $post_id Post ID.
	 * @param array{type: string, data: array<string, mixed>} $fc      Featured Content.
	 * @return array{url: string, width: int, height: int, alt: string}|array{}
	 */
	private static function stored_image( int $post_id, array $fc ): array {
		$stored = json_decode( (string) get_post_meta( $post_id, self::META_IMAGE, true ), true );

		if ( ! is_array( $stored ) || self::source_hash( $fc ) !== ( $stored['source'] ?? '' ) || empty( $stored['url'] ) ) {
			return array();
		}

		return array(
			'url'    => (string) $stored['url'],
			'width'  => absint( $stored['width'] ?? 0 ),
			'height' => absint( $stored['height'] ?? 0 ),
			'alt'    => '',
		);
	}

	/**
	 * When a post's Featured Content changes, queue its remote share image
	 * to be resolved (once per post, a few seconds later) rather than doing
	 * the outbound request inside the save.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s), unused.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function maybe_schedule_resolution( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids );

		if ( ! in_array( $meta_key, array( Daymark_Featured_Content::META_TYPE, Daymark_Featured_Content::META_DATA ), true ) ) {
			return;
		}

		$post_id = absint( $object_id );

		if ( $post_id <= 0 || wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK, array( $post_id ) );
	}

	/**
	 * Resolve and store (or clear) a post's remote share image. The cron
	 * callback; also callable directly.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function resolve_remote_image( $post_id ): void {
		$post = get_post( absint( $post_id ) );
		$fc   = $post ? Daymark_Featured_Content::get_featured_content( $post ) : array();

		if ( empty( $fc ) || ! self::is_remote_kind( $fc ) ) {
			if ( $post ) {
				delete_post_meta( $post->ID, self::META_IMAGE );
			}
			return;
		}

		$url   = (string) ( $fc['data']['url'] ?? '' );
		$image = array();

		if ( 'link' === $fc['type'] ) {
			$og = Daymark_Subscription_Opengraph::resolve( $url );

			if ( ! empty( $og['image'] ) && 'https' === strtolower( (string) wp_parse_url( $og['image'], PHP_URL_SCHEME ) ) ) {
				$image = array(
					'url'    => (string) $og['image'],
					'width'  => 0,
					'height' => 0,
				);
			}
		} else {
			// The same discovery rule Featured Content already uses to render
			// this URL: only an author who may post unfiltered HTML lets an
			// unrecognized page name its own oEmbed endpoint.
			$discover = Daymark_Featured_Content::oembed_discovery_allowed( (int) $post->post_author, $post );
			$image    = Daymark_Subscription_Oembed::resolve_thumbnail( $url, $discover );
		}

		if ( empty( $image['url'] ) ) {
			delete_post_meta( $post->ID, self::META_IMAGE );
			return;
		}

		$image['source'] = self::source_hash( $fc );

		update_post_meta( $post->ID, self::META_IMAGE, wp_slash( wp_json_encode( $image ) ) );
	}

	// -- oEmbed -------------------------------------------------------------

	/**
	 * Whether Featured Content's image should take the place of a post's
	 * regular Featured Image (the same switch the theme substitution uses).
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	private static function replaces_featured_image( WP_Post $post ): bool {
		/** This filter is documented in includes/class-featured-content.php */
		return (bool) apply_filters( 'daymark_featured_content_replaces_featured_image', true, $post->ID );
	}

	/**
	 * Whether the Featured Content image should be used, given whether the
	 * other source already has an image of its own.
	 *
	 * @param WP_Post $post      The post.
	 * @param bool    $has_other Whether a regular Featured Image (or the SEO plugin's own image) exists.
	 * @return bool
	 */
	private static function should_use( WP_Post $post, bool $has_other ): bool {
		return ! $has_other || self::replaces_featured_image( $post );
	}

	/**
	 * Put the Featured Content image in a post's own oEmbed response — what
	 * another WordPress site's embed, or a social platform reading oEmbed,
	 * shows as the thumbnail.
	 *
	 * @param array<string, mixed> $data oEmbed response data.
	 * @param WP_Post              $post The post.
	 * @return array<string, mixed>|false
	 */
	public function filter_oembed_response( $data, $post ) {
		if ( ! is_array( $data ) || ! $post instanceof WP_Post ) {
			return $data;
		}

		$image = self::image( $post );

		if ( empty( $image ) || ! self::should_use( $post, ! empty( $data['thumbnail_url'] ) ) ) {
			return $data;
		}

		$data['thumbnail_url'] = $image['url'];

		if ( $image['width'] > 0 && $image['height'] > 0 ) {
			$data['thumbnail_width']  = $image['width'];
			$data['thumbnail_height'] = $image['height'];
		} else {
			unset( $data['thumbnail_width'], $data['thumbnail_height'] );
		}

		return $data;
	}

	/**
	 * Show the Featured Content image in WordPress's own embed card (the
	 * iframe another WordPress site shows), when it's a local attachment.
	 * The embed template only takes an attachment ID, so a remote thumbnail
	 * can't be shown here.
	 *
	 * @param int $thumbnail_id Attachment ID WordPress would use.
	 * @return int
	 */
	public function filter_embed_thumbnail_id( $thumbnail_id ) {
		$post = get_post();

		if ( ! $post ) {
			return $thumbnail_id;
		}

		$id = self::attachment_id( $post );

		if ( $id <= 0 || ! self::should_use( $post, (int) $thumbnail_id > 0 ) ) {
			return $thumbnail_id;
		}

		return $id;
	}

	// -- Open Graph / Twitter Card -----------------------------------------

	/**
	 * The singular post whose page is being rendered, when it has a share
	 * image or description from Featured Content; otherwise null.
	 *
	 * @return WP_Post|null
	 */
	private static function current_post(): ?WP_Post {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
			return null;
		}

		return Daymark_Featured_Content::has_featured_content( $post ) ? $post : null;
	}

	/**
	 * The share image for the current page, honoring the "replace or only
	 * fill in" rule against an image the page already has.
	 *
	 * @param bool $has_other Whether the page already has an image.
	 * @return array{url: string, width: int, height: int, alt: string}|array{}
	 */
	private static function current_image( bool $has_other ): array {
		$post = self::current_post();

		if ( ! $post ) {
			return array();
		}

		$image = self::image( $post );

		return ! empty( $image ) && self::should_use( $post, $has_other ) ? $image : array();
	}

	/**
	 * Yoast SEO: add the image to its Open Graph image list. Yoast calls this
	 * before adding the post's own images, so ours comes first and becomes
	 * the primary og:image.
	 *
	 * @param object $container Yoast's Open Graph Images value object.
	 * @return void
	 */
	public function add_yoast_image( $container ): void {
		$post  = self::current_post();
		$image = self::current_image( $post ? has_post_thumbnail( $post ) : false );

		if ( ! empty( $image ) && is_object( $container ) && method_exists( $container, 'add_image' ) ) {
			$container->add_image( self::yoast_or_rank_math_shape( $image ) );
		}
	}

	/**
	 * Rank Math: add the image to its Open Graph (or Twitter) image list,
	 * before it adds the post's own images.
	 *
	 * @param object $images Rank Math's Image object.
	 * @return void
	 */
	public function add_rank_math_image( $images ): void {
		$post  = self::current_post();
		$image = self::current_image( $post ? has_post_thumbnail( $post ) : false );

		if ( ! empty( $image ) && is_object( $images ) && method_exists( $images, 'add_image' ) ) {
			$images->add_image( self::yoast_or_rank_math_shape( $image ) );
		}
	}

	/**
	 * The image array Yoast's and Rank Math's add_image() both accept.
	 *
	 * @param array{url: string, width: int, height: int, alt: string} $image Share image.
	 * @return array<string, mixed>
	 */
	private static function yoast_or_rank_math_shape( array $image ): array {
		$shape = array( 'url' => $image['url'] );

		if ( $image['width'] > 0 && $image['height'] > 0 ) {
			$shape['width']  = $image['width'];
			$shape['height'] = $image['height'];
		}

		if ( '' !== $image['alt'] ) {
			$shape['alt'] = $image['alt'];
		}

		return $shape;
	}

	/**
	 * Yoast SEO's Twitter image (a single URL).
	 *
	 * @param string $url Image URL Yoast would use.
	 * @return string
	 */
	public function filter_twitter_image_url( $url ) {
		$image = self::current_image( '' !== (string) $url );

		return empty( $image ) ? $url : $image['url'];
	}

	/**
	 * All in One SEO: its Facebook (Open Graph) tags, keyed by property.
	 *
	 * @param array<string, mixed> $tags Tags.
	 * @return array<string, mixed>
	 */
	public function filter_facebook_tags( $tags ) {
		return self::apply_image_to_tags( (array) $tags, 'og' );
	}

	/**
	 * All in One SEO: its Twitter tags, keyed by name.
	 *
	 * @param array<string, mixed> $tags Tags.
	 * @return array<string, mixed>
	 */
	public function filter_twitter_tags( $tags ) {
		return self::apply_image_to_tags( (array) $tags, 'twitter' );
	}

	/**
	 * Jetpack: its combined Open Graph and Twitter tags.
	 *
	 * @param array<string, mixed> $tags Tags.
	 * @return array<string, mixed>
	 */
	public function filter_jetpack_tags( $tags ) {
		return self::apply_image_to_tags( self::apply_image_to_tags( (array) $tags, 'og' ), 'twitter' );
	}

	/**
	 * Set the image tags in a property-keyed tag array.
	 *
	 * @param array<string, mixed> $tags   Tags.
	 * @param string               $prefix 'og' or 'twitter'.
	 * @return array<string, mixed>
	 */
	private static function apply_image_to_tags( array $tags, string $prefix ): array {
		$key   = $prefix . ':image';
		$image = self::current_image( ! empty( $tags[ $key ] ) );

		if ( empty( $image ) ) {
			return $tags;
		}

		$tags[ $key ] = $image['url'];

		if ( 'og' === $prefix ) {
			if ( isset( $tags['og:image:secure_url'] ) ) {
				$tags['og:image:secure_url'] = $image['url'];
			}

			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$tags['og:image:width']  = $image['width'];
				$tags['og:image:height'] = $image['height'];
			} else {
				unset( $tags['og:image:width'], $tags['og:image:height'] );
			}
		}

		return $tags;
	}

	/**
	 * Which known SEO / Open Graph plugin is printing tags on this page, if
	 * any: 'yoast', 'rank_math', 'aioseo', 'jetpack', or ''.
	 *
	 * Jetpack only counts when its Open Graph output is actually on — it
	 * loads `jetpack_og_tags()` only then — since Jetpack is often active
	 * for other reasons.
	 *
	 * @return string
	 */
	public static function active_seo_plugin(): string {
		$plugins = array(
			'yoast'     => array( 'constants' => array( 'WPSEO_VERSION' ) ),
			'rank_math' => array(
				'constants' => array( 'RANK_MATH_VERSION' ),
				'classes'   => array( 'RankMath' ),
			),
			'aioseo'    => array(
				'constants' => array( 'AIOSEO_VERSION' ),
				'functions' => array( 'aioseo' ),
			),
			'jetpack'   => array( 'functions' => array( 'jetpack_og_tags' ) ),
		);

		$found = '';

		foreach ( $plugins as $key => $signals ) {
			if ( Daymark_Plugin_Detector::matches( $signals ) ) {
				$found = $key;
				break;
			}
		}

		/**
		 * Filters which SEO / Open Graph plugin Daymark treats as handling
		 * this page's share tags. Return '' to have Daymark print its own
		 * minimal tags; any other value makes Daymark hand off and print
		 * nothing.
		 *
		 * @since 0.19.0
		 *
		 * @param string $found 'yoast', 'rank_math', 'aioseo', 'jetpack', or ''.
		 */
		return (string) apply_filters( 'daymark_featured_content_seo_plugin', $found );
	}

	/**
	 * Print minimal og:* and twitter:* tags for a post with Featured
	 * Content — only when no known SEO / Open Graph plugin is handling
	 * them, so a page never gets two sets.
	 *
	 * @return void
	 */
	public function print_meta_tags(): void {
		$post = self::current_post();

		if ( ! $post || '' !== self::active_seo_plugin() ) {
			return;
		}

		$image = self::current_image( has_post_thumbnail( $post ) );

		if ( empty( $image ) && has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $post ), 'large' );

			if ( $src ) {
				$image = array(
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => '',
				);
			}
		}

		$description = self::description( $post );

		if ( '' === $description ) {
			$description = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		}

		$description = wp_html_excerpt( $description, 300, '…' );
		$title       = wp_strip_all_tags( get_the_title( $post ) );

		$tags = array(
			array( 'property', 'og:type', 'article' ),
			array( 'property', 'og:site_name', get_bloginfo( 'name' ) ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:url', (string) get_permalink( $post ) ),
		);

		if ( '' !== $description ) {
			$tags[] = array( 'property', 'og:description', $description );
		}

		if ( ! empty( $image ) ) {
			$tags[] = array( 'property', 'og:image', $image['url'] );

			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$tags[] = array( 'property', 'og:image:width', (string) $image['width'] );
				$tags[] = array( 'property', 'og:image:height', (string) $image['height'] );
			}

			if ( '' !== $image['alt'] ) {
				$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
			}
		}

		$tags[] = array( 'name', 'twitter:card', empty( $image ) ? 'summary' : 'summary_large_image' );
		$tags[] = array( 'name', 'twitter:title', $title );

		if ( '' !== $description ) {
			$tags[] = array( 'name', 'twitter:description', $description );
		}

		if ( ! empty( $image ) ) {
			$tags[] = array( 'name', 'twitter:image', $image['url'] );
		}

		foreach ( $tags as $tag ) {
			$is_url = in_array( $tag[1], array( 'og:url', 'og:image', 'twitter:image' ), true );

			printf(
				'<meta %1$s="%2$s" content="%3$s" />' . "\n",
				esc_attr( $tag[0] ),
				esc_attr( $tag[1] ),
				$is_url ? esc_url( $tag[2] ) : esc_attr( $tag[2] )
			);
		}
	}
}
