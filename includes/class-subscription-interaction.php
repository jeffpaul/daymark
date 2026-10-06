<?php
/**
 * What a followed post does to another post: reply to it, reblog it, like
 * it, bookmark it, or RSVP to it (issue #168).
 *
 * This is the IndieWeb post type of a subscribed post, kept separate from
 * its `post_format`. `post_format` says what media a post carries and drives
 * the Timeline card's media slot, Search's type chips, and the `type`
 * filter; an interaction says what the author is doing, and a reblog can
 * also be a photo. So a followed post keeps its media format and gains an
 * interaction alongside it.
 *
 * Sources report three normalized keys (see sanitize()): `interaction`,
 * `interaction_url` (the post it acts on), and `interaction_rsvp` (an
 * RSVP's answer). The poller stores them as subscription-post meta, and the
 * REST summary sends them to the app as one `interaction` object, which
 * shows a context line on the card and a preview in the post view.
 *
 * Replaces #479's reply-only `in_reply_to` meta, which never shipped in a
 * release. from_post() still reads that key, so a reply ingested by a
 * development build before this change keeps its context.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The interaction vocabulary and its subscription-post storage.
 */
class Daymark_Subscription_Interaction {

	/**
	 * The interactions Daymark records. Same names as IndieWeb post type
	 * discovery uses.
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'reply', 'repost', 'like', 'bookmark', 'rsvp' );

	/**
	 * Interactions left out of the Timeline: a like of some other post says
	 * little on its own, the same reason your own Like Marks are left out.
	 * The post is still stored.
	 *
	 * @var string[]
	 */
	public const HIDDEN_FROM_TIMELINE = array( 'like' );

	/**
	 * The RSVP answers microformats2 defines.
	 *
	 * @var string[]
	 */
	public const RSVP_VALUES = array( 'yes', 'no', 'maybe', 'interested' );

	/**
	 * Subscription-post meta: the interaction type.
	 *
	 * @var string
	 */
	public const META_TYPE = 'interaction';

	/**
	 * Subscription-post meta: the URL of the post acted on.
	 *
	 * @var string
	 */
	public const META_URL = 'interaction_url';

	/**
	 * Subscription-post meta: an RSVP's answer.
	 *
	 * @var string
	 */
	public const META_RSVP = 'interaction_rsvp';

	/**
	 * #479's unreleased reply-only meta key, read as a fallback.
	 *
	 * @var string
	 */
	private const LEGACY_REPLY_META = 'in_reply_to';

	/**
	 * Normalize a source's interaction into the three keys normalize()
	 * returns. An unknown type gives three empty strings. A target URL that
	 * isn't http(s) is dropped, and an RSVP answer is only kept for an RSVP.
	 *
	 * @param string $type Interaction type, e.g. 'repost'.
	 * @param string $url  The post it acts on.
	 * @param string $rsvp An RSVP's answer.
	 * @return array{interaction: string, interaction_url: string, interaction_rsvp: string}
	 */
	public static function sanitize( string $type, string $url = '', string $rsvp = '' ): array {
		$type = sanitize_key( $type );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array(
				'interaction'      => '',
				'interaction_url'  => '',
				'interaction_rsvp' => '',
			);
		}

		$rsvp = 'rsvp' === $type ? strtolower( trim( $rsvp ) ) : '';

		return array(
			'interaction'      => $type,
			'interaction_url'  => self::sanitize_url( $url ),
			'interaction_rsvp' => in_array( $rsvp, self::RSVP_VALUES, true ) ? $rsvp : '',
		);
	}

	/**
	 * An http(s) URL, or ''.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function sanitize_url( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );

		return '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Store a normalized item's interaction on a subscription post. A post
	 * with no interaction gets no meta.
	 *
	 * @param int                  $post_id    Subscription post ID.
	 * @param array<string, mixed> $normalized A source's normalize() output.
	 * @return void
	 */
	public static function store( int $post_id, array $normalized ): void {
		$clean = self::sanitize(
			(string) ( $normalized['interaction'] ?? '' ),
			(string) ( $normalized['interaction_url'] ?? '' ),
			(string) ( $normalized['interaction_rsvp'] ?? '' )
		);

		if ( '' === $clean['interaction'] ) {
			return;
		}

		update_post_meta( $post_id, self::META_TYPE, $clean['interaction'] );

		if ( '' !== $clean['interaction_url'] ) {
			update_post_meta( $post_id, self::META_URL, $clean['interaction_url'] );
		}

		if ( '' !== $clean['interaction_rsvp'] ) {
			update_post_meta( $post_id, self::META_RSVP, $clean['interaction_rsvp'] );
		}
	}

	/**
	 * A subscription post's stored interaction.
	 *
	 * @param int $post_id Subscription post ID.
	 * @return array{type: string, url: string, rsvp: string}
	 */
	public static function from_post( int $post_id ): array {
		$type = (string) get_post_meta( $post_id, self::META_TYPE, true );
		$url  = (string) get_post_meta( $post_id, self::META_URL, true );
		$rsvp = (string) get_post_meta( $post_id, self::META_RSVP, true );

		if ( '' === $type ) {
			$legacy = (string) get_post_meta( $post_id, self::LEGACY_REPLY_META, true );

			if ( '' !== $legacy ) {
				$type = 'reply';
				$url  = $legacy;
			}
		}

		$clean = self::sanitize( $type, $url, $rsvp );

		return array(
			'type' => $clean['interaction'],
			'url'  => $clean['interaction_url'],
			'rsvp' => $clean['interaction_rsvp'],
		);
	}
}
