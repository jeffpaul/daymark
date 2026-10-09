<?php
/**
 * Images for offline bookmarks (issue #455).
 *
 * When a post is bookmarked, the app shell caches its content and its
 * images for offline reading (issues #193, #236). The app's Content Security
 * Policy allows `fetch()` only to this site (`connect-src 'self'`), and most
 * other sites don't send the CORS headers a cross-origin `fetch()` needs
 * anyway, so an image hosted on another site could never be saved. This
 * class fetches such an image server-side so the app can save it.
 *
 * It is not an open image proxy. A request is honored only when:
 *
 *  - the post is bookmarked by the current user (the only time the app needs
 *    an image saved), and
 *  - the image URL appears in that post's own content, the same content the
 *    app caches (a Mark's rendered content, or a subscription post's cached
 *    body), and
 *  - the URL passes Daymark_Subscription_Url_Guard, and the fetch runs inside
 *    Daymark_Outbound_Guard, like every other outbound request.
 *
 * The response must be a real raster image (checked from its bytes, not its
 * Content-Type header) under a size cap. SVG is refused: an SVG can carry
 * script, and the app only ever needs a picture.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side fetch of a bookmarked post's off-site images.
 */
class Daymark_Bookmark_Images {

	/**
	 * Default maximum image size, in bytes. Filterable via
	 * `daymark_bookmark_image_max_bytes`.
	 *
	 * @var int
	 */
	private const MAX_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Image MIME types the app may save (from getimagesizefromstring()).
	 *
	 * @var string[]
	 */
	private const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/**
	 * Fetch one image of a bookmarked post.
	 *
	 * @param int    $post_id A bookmarked Mark, post, or subscription post ID.
	 * @param string $url     Image URL from that post's content.
	 * @param int    $user_id User asking; 0 for the current user.
	 * @return array{mime: string, data: string}|WP_Error `data` is base64.
	 */
	public static function fetch( int $post_id, string $url, int $user_id = 0 ) {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( ! Daymark_Plugin::instance()->bookmarks->is_bookmarked( $user_id, $post_id ) ) {
			return self::error( 'daymark_not_bookmarked', __( 'Only images in your bookmarks can be saved.', 'daymark' ), 404 );
		}

		$raw    = trim( $url );
		$url    = esc_url_raw( $raw );
		$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) );

		if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::error( 'daymark_image_bad_url', __( 'That image address is not valid.', 'daymark' ), 400 );
		}

		if ( ! self::appears_in_content( $post_id, $raw ) ) {
			return self::error( 'daymark_image_not_in_post', __( 'That image is not part of this bookmark.', 'daymark' ), 404 );
		}

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $url ) ) ) {
			return self::error( 'daymark_image_unsafe_url', __( "That image couldn't be safely reached.", 'daymark' ), 400 );
		}

		/**
		 * Filters the largest image, in bytes, Daymark saves for an offline bookmark.
		 *
		 * @since 0.19.0
		 *
		 * @param int $max_bytes Defaults to 5 MB.
		 */
		$max_bytes = max( 1, (int) apply_filters( 'daymark_bookmark_image_max_bytes', self::MAX_BYTES ) );

		$response = Daymark_Outbound_Guard::get(
			$url,
			array(
				/**
				 * Filters the timeout, in seconds, for fetching a bookmark image.
				 *
				 * @since 0.19.0
				 *
				 * @param int $seconds Defaults to 10.
				 */
				'timeout'             => (int) apply_filters( 'daymark_bookmark_image_fetch_timeout', 10 ),
				'redirection'         => 3,
				// One byte over the cap, so a too-large image is detectable
				// instead of silently truncated into a broken picture.
				'limit_response_size' => $max_bytes + 1,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::error( 'daymark_image_fetch_failed', __( "That image couldn't be downloaded.", 'daymark' ), 502 );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) > $max_bytes ) {
			return self::error( 'daymark_image_too_large', __( 'That image is too large to save.', 'daymark' ), 413 );
		}

		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Garbage input is expected and handled; the warning carries nothing useful.
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';

		if ( ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
			return self::error( 'daymark_image_not_an_image', __( 'That address did not return an image.', 'daymark' ), 415 );
		}

		return array(
			'mime' => $mime,
			// Raw image bytes can't travel in a JSON REST response; base64 can.
			'data' => base64_encode( $body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding for image bytes, not obfuscation.
		);
	}

	/**
	 * Whether an image URL appears in the content the app caches for this
	 * post: a subscription post's stored body, or a Mark's (or ordinary
	 * post's) rendered content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @return bool
	 */
	private static function appears_in_content( int $post_id, string $url ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( Daymark_Subscription_Post_Type::POST_TYPE === $post->post_type ) {
			$content = (string) get_post_meta( $post_id, 'body_content', true );
		} elseif ( Daymark_External_Notes::is_own_content_type( $post->post_type ) && ! post_password_required( $post ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying WordPress core's own 'the_content' filter, the same rendering GET /marks/{id}/content uses.
			$content = (string) apply_filters( 'the_content', $post->post_content );
		} else {
			return false;
		}

		// The app reads the URL from the rendered markup, where `&` is
		// usually written `&amp;` (and sometimes `&#038;`).
		foreach ( array( $url, str_replace( '&', '&amp;', $url ), str_replace( '&', '&#038;', $url ) ) as $form ) {
			if ( '' !== $form && false !== strpos( $content, $form ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A WP_Error with an HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
