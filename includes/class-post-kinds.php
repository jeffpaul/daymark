<?php
/**
 * Works alongside the Post Kinds plugin, in both directions.
 *
 * Post Kinds (https://github.com/dshanske/indieweb-post-kinds) files every
 * `post` under one "kind" term (Note, Photo, Reply, Repost, Check-in, ...).
 * Since 4.0.0 it gives a post with no kind its Default Kind setting (Note,
 * unless the site owner changed it) when the post is inserted, including
 * posts other plugins create. So without this class, every Mark on a site
 * running both plugins is filed as a Note in Post Kinds' archives, filters,
 * and icons, whatever it really is.
 *
 * On this site: after Daymark saves a Mark, set_mark_kind() files it under
 * the kind that matches its type (a photo Mark as Photo, a Reblog as
 * Repost, and so on). It only ever labels Daymark's own posts, writes only
 * the `kind` term, and never calls Post Kinds' code. Daymark also turns off
 * Post Kinds' response box on Marks (suppress_response_box()): that box is
 * built from Post Kinds' own citation data, which a Mark doesn't have, so a
 * Repost or Reply Mark would otherwise get an empty "Reposted" heading
 * marked up as a bogus `p-repost-of`, next to Daymark's own markup.
 *
 * From followed sites: Post Kinds adds a `kind` field to each post in
 * `wp/v2/posts` (since 3.1.0; 4.0.0 lists the allowed values). The
 * WordPress REST API subscription source reads it through
 * interaction_from_rest(), so a followed post that is a reply, repost,
 * like, bookmark, or RSVP shows that on its card, and a Check-in is shown
 * as one. The field holds only the kind, not the post it acts on; the
 * target URL is taken from the response markup Post Kinds adds to the
 * post's rendered content, when it is there.
 *
 * Confirmed against Post Kinds 4.0.0's source: `kind` taxonomy and its
 * default term (includes/class-kind-taxonomy.php), the kinds and their
 * microformats properties (includes/register-kinds.php), the
 * `kind_response_display` filter (includes/class-kind-view.php), and the
 * REST field (Kind_Taxonomy::rest_kind()). The `kind_content_response`
 * filter is from 4.0.1 and does nothing on earlier versions.
 *
 * @package Daymark
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post Kinds integration.
 */
class Daymark_Post_Kinds {

	/**
	 * Post Kinds' taxonomy.
	 *
	 * @var string
	 */
	public const TAXONOMY = 'kind';

	/**
	 * Mark type => Post Kinds kind. Gallery and mixed Marks are photo posts
	 * to Post Kinds, which has no gallery kind.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_KINDS = array(
		'image'   => 'photo',
		'gallery' => 'photo',
		'mixed'   => 'photo',
		'video'   => 'video',
		'audio'   => 'audio',
		'note'    => 'note',
		'checkin' => 'checkin',
	);

	/**
	 * Post Kinds kind => Daymark interaction, with the microformats
	 * property Post Kinds marks its target with. A favorite is a like to
	 * Daymark, and an RSVP's event is its `in-reply-to`.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const KIND_INTERACTIONS = array(
		'reply'    => array( 'reply', 'in-reply-to' ),
		'repost'   => array( 'repost', 'repost-of' ),
		'like'     => array( 'like', 'like-of' ),
		'favorite' => array( 'like', 'favorite-of' ),
		'bookmark' => array( 'bookmark', 'bookmark-of' ),
		'rsvp'     => array( 'rsvp', 'in-reply-to' ),
	);

	/**
	 * Hook up. Called from Daymark_Plugin::on_init().
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'kind_response_display', array( $this, 'suppress_response_box' ), 10, 2 );
		add_filter( 'kind_content_response', array( $this, 'skip_content_response' ), 10, 2 );
	}

	/**
	 * Whether this site has Post Kinds' taxonomy to file Marks under.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		/**
		 * Filters whether Daymark files its Marks under Post Kinds kinds.
		 *
		 * @param bool $available Default: whether the `kind` taxonomy exists.
		 */
		return (bool) apply_filters( 'daymark_set_post_kind', taxonomy_exists( self::TAXONOMY ) );
	}

	/**
	 * The Post Kinds kind for a Mark: Repost for a Reblog, Reply for a
	 * reply, otherwise the one matching its type.
	 *
	 * @param int    $post_id Mark post ID.
	 * @param string $type    Primary Mark type.
	 * @return string Kind slug, or '' when there is none.
	 */
	public static function kind_for_mark( int $post_id, string $type ): string {
		if ( '' !== (string) get_post_meta( $post_id, '_daymark_repost_of', true ) ) {
			return 'repost';
		}

		if ( '' !== (string) get_post_meta( $post_id, '_daymark_in_reply_to', true ) ) {
			return 'reply';
		}

		return self::TYPE_KINDS[ $type ] ?? '';
	}

	/**
	 * File a Mark under its Post Kinds kind. Does nothing without Post
	 * Kinds, for a post type the taxonomy doesn't cover (a Like Mark's own
	 * type), or when the site has no term for that kind.
	 *
	 * @param int    $post_id Mark post ID.
	 * @param string $type    Primary Mark type.
	 * @return void
	 */
	public static function set_mark_kind( int $post_id, string $type ): void {
		if ( ! self::available() ) {
			return;
		}

		$post_type = (string) get_post_type( $post_id );

		if ( '' === $post_type || ! is_object_in_taxonomy( $post_type, self::TAXONOMY ) ) {
			return;
		}

		$kind = self::kind_for_mark( $post_id, $type );

		if ( '' === $kind || ! term_exists( $kind, self::TAXONOMY ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $kind, self::TAXONOMY );
	}

	/**
	 * Leave Post Kinds' response box off a Mark. Daymark already renders a
	 * Mark's content and its microformats (Daymark_Microformats), and the
	 * box has none of Post Kinds' citation data to show. Other posts are
	 * untouched.
	 *
	 * @param string $content Post Kinds' response markup.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function suppress_response_box( $content, $post_id ) {
		if ( '1' === (string) get_post_meta( (int) $post_id, '_daymark_is_mark', true ) ) {
			return '';
		}

		return $content;
	}

	/**
	 * Tell Post Kinds (4.0.1+) not to build its response box for a Mark's
	 * content at all, rather than building it for suppress_response_box()
	 * to throw away. This filter only covers `the_content`; feeds,
	 * excerpts, and Post Kinds' template tags still go through
	 * `kind_response_display`, so suppress_response_box() stays.
	 *
	 * @param bool         $add  Whether to add the response.
	 * @param WP_Post|null $post The current post.
	 * @return bool
	 */
	public function skip_content_response( $add, $post ) {
		if ( $post instanceof WP_Post && '1' === (string) get_post_meta( $post->ID, '_daymark_is_mark', true ) ) {
			return false;
		}

		return $add;
	}

	/**
	 * The interaction a followed post's Post Kinds `kind` field describes.
	 *
	 * @param mixed  $kind         The post's raw `kind` REST field.
	 * @param string $content_html The post's rendered content.
	 * @param string $base_url     URL relative links resolve against.
	 * @return array{interaction: string, interaction_url: string, interaction_rsvp: string}|null
	 *         Null when the field names no interaction.
	 */
	public static function interaction_from_rest( $kind, string $content_html, string $base_url ): ?array {
		$kind = is_string( $kind ) ? sanitize_key( $kind ) : '';

		if ( ! isset( self::KIND_INTERACTIONS[ $kind ] ) ) {
			return null;
		}

		list( $interaction, $property ) = self::KIND_INTERACTIONS[ $kind ];

		return Daymark_Subscription_Interaction::sanitize(
			$interaction,
			self::target_url( $content_html, 'u-' . $property, $base_url )
		);
	}

	/**
	 * Whether a followed post's `kind` field says it is a Check-in.
	 *
	 * @param mixed $kind The post's raw `kind` REST field.
	 * @return bool
	 */
	public static function is_checkin( $kind ): bool {
		return is_string( $kind ) && 'checkin' === sanitize_key( $kind );
	}

	/**
	 * The URL Post Kinds' response markup gives for an interaction: the
	 * `href` of the element carrying the property class, or of the first
	 * `u-url` link after it (Post Kinds puts the class on a `<section>`
	 * holding the cited link).
	 *
	 * @param string $html        Rendered content.
	 * @param string $class_token Property class, e.g. 'u-repost-of'.
	 * @param string $base_url    URL relative links resolve against.
	 * @return string
	 */
	private static function target_url( string $html, string $class_token, string $base_url ): string {
		if ( '' === $html || false === strpos( $html, $class_token ) ) {
			return '';
		}

		$tags  = new WP_HTML_Tag_Processor( $html );
		$found = false;

		while ( $tags->next_tag() ) {
			if ( ! $found ) {
				if ( true !== $tags->has_class( $class_token ) ) {
					continue;
				}

				$found = true;
			} elseif ( true !== $tags->has_class( 'u-url' ) ) {
				continue;
			}

			$href = $tags->get_attribute( 'href' );

			if ( is_string( $href ) && '' !== trim( $href ) ) {
				return esc_url_raw( WP_Http::make_absolute_url( trim( $href ), $base_url ) );
			}
		}

		return '';
	}
}
