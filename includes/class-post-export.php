<?php
/**
 * The `daymark` field a Daymark site adds to each post in `wp/v2/posts`.
 *
 * A Daymark site is a WordPress site, so another Daymark site that follows
 * it reads it through the WordPress REST API source
 * (Daymark_Subscription_Source_WordPress). That API only carries WordPress's
 * own fields: the post format and the rendered HTML. It drops what makes a
 * Mark a Mark: the Mark type (a Check In becomes a Status post), whether it
 * reblogs or replies to another post, a Check In's place name, and the
 * post's Featured Content.
 *
 * This field adds them, in a small, versioned shape the subscribing site
 * reads first and falls back from. Any other client sees one extra field
 * and can ignore it. Only public facts are included: a Check In's place
 * name is part of its published content, but its coordinates are not, the
 * same line Daymark_Microformats::location_markup() draws.
 *
 * Shape (`version` 1):
 *
 *     {
 *       "version": 1,
 *       "type": "image|video|audio|note|gallery|mixed|checkin" or "",
 *       "interaction": {"type": "reply|repost", "url": "https://…"} or null,
 *       "place_name": "…",
 *       "featured_content": {"type": "audio|video|gallery|quote|link", …} or null
 *     }
 *
 * `type` is empty for an ordinary post written in the block editor; its
 * Featured Content is still included, since Featured Content works on any
 * post. A Like is never in `wp/v2/posts` at all (it has its own post type,
 * see Daymark_Like_Visibility), so `interaction` is only ever a reply or a
 * reblog.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes Daymark's own post data to other Daymark sites.
 */
class Daymark_Post_Export {

	/**
	 * REST field name on `wp/v2/posts`.
	 *
	 * @var string
	 */
	public const FIELD = 'daymark';

	/**
	 * Version of the field's shape. A later version only adds keys, so a
	 * reader reads the keys it knows from any version of 1 or more.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Most gallery image URLs sent per post.
	 *
	 * @var int
	 */
	private const MAX_GALLERY_IMAGES = 20;

	/**
	 * Interaction meta keys, mapped to the interaction they mean.
	 *
	 * @var array<string, string>
	 */
	private const INTERACTION_META = array(
		'_daymark_repost_of'   => 'repost',
		'_daymark_in_reply_to' => 'reply',
	);

	/**
	 * Hook into rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_field' ) );
	}

	/**
	 * Register the field on `post`.
	 *
	 * @return void
	 */
	public function register_field(): void {
		register_rest_field(
			'post',
			self::FIELD,
			array(
				'get_callback' => array( $this, 'get_field' ),
				'schema'       => array(
					'description' => __( 'Daymark data for this post, for other Daymark sites that follow this one.', 'daymark' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * REST get_callback.
	 *
	 * @param array<string, mixed> $prepared Prepared post response data.
	 * @return array<string, mixed>|null
	 */
	public function get_field( $prepared ): ?array {
		$post_id = absint( is_array( $prepared ) ? ( $prepared['id'] ?? 0 ) : 0 );

		return $post_id > 0 ? self::data_for_post( $post_id ) : null;
	}

	/**
	 * The field's value for one post. Null for a post that isn't public
	 * (draft, private, or password-protected): the REST API can show those
	 * to their author, and none of this is meant for anyone but the public.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public static function data_for_post( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return null;
		}

		$is_mark = '1' === (string) get_post_meta( $post_id, '_daymark_is_mark', true );
		$type    = $is_mark ? sanitize_key( (string) get_post_meta( $post_id, '_daymark_primary_type', true ) ) : '';

		$place_name = 'checkin' === $type
			? sanitize_text_field( (string) get_post_meta( $post_id, '_daymark_place_name', true ) )
			: '';

		return array(
			'version'          => self::VERSION,
			'type'             => $type,
			'interaction'      => $is_mark ? self::interaction( $post_id ) : null,
			'place_name'       => $place_name,
			'featured_content' => self::featured_content( $post ),
		);
	}

	/**
	 * A Mark's reblog or reply target.
	 *
	 * @param int $post_id Mark post ID.
	 * @return array{type: string, url: string}|null
	 */
	private static function interaction( int $post_id ): ?array {
		foreach ( self::INTERACTION_META as $meta_key => $interaction ) {
			$url = Daymark_Subscription_Interaction::sanitize_url( (string) get_post_meta( $post_id, $meta_key, true ) );

			if ( '' !== $url ) {
				return array(
					'type' => $interaction,
					'url'  => $url,
				);
			}
		}

		return null;
	}

	/**
	 * A post's Featured Content, reduced to what a reader needs to draw it:
	 * URLs and text, never attachment IDs (they mean nothing on another
	 * site).
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	private static function featured_content( WP_Post $post ): ?array {
		$fc = Daymark_Featured_Content::get_featured_content( $post );

		if ( empty( $fc ) ) {
			return null;
		}

		$data = (array) $fc['data'];
		$out  = array( 'type' => $fc['type'] );

		switch ( $fc['type'] ) {
			case 'audio':
			case 'video':
				$url = 'library' === ( $data['source'] ?? '' )
					? (string) wp_get_attachment_url( absint( $data['attachment_id'] ?? 0 ) )
					: (string) ( $data['url'] ?? '' );

				$out['url']   = esc_url_raw( $url, array( 'http', 'https' ) );
				$image        = Daymark_Featured_Content_Social::image( $post );
				$out['image'] = esc_url_raw( (string) ( $image['url'] ?? '' ), array( 'http', 'https' ) );
				break;

			case 'gallery':
				$out['images'] = array();

				foreach ( array_slice( array_map( 'absint', (array) ( $data['attachment_ids'] ?? array() ) ), 0, self::MAX_GALLERY_IMAGES ) as $attachment_id ) {
					$url = wp_get_attachment_image_url( $attachment_id, Daymark_Publisher::display_size( $attachment_id, 'medium_large' ) );

					if ( $url ) {
						$out['images'][] = esc_url_raw( $url );
					}
				}
				break;

			case 'quote':
				$out['text']   = sanitize_textarea_field( (string) ( $data['text'] ?? '' ) );
				$out['credit'] = Daymark_Featured_Content::quote_credit( $data );
				break;

			case 'link':
				$out['url'] = esc_url_raw( (string) ( $data['url'] ?? '' ), array( 'http', 'https' ) );
				break;
		}

		return $out;
	}
}
