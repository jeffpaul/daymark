<?php
/**
 * Comment delivery for a subscription post's origin (issue #317).
 *
 * Replaces the old subscription-post "Reply" action (compose a full Mark in
 * the composer) with an instant action that delivers a short comment
 * directly to the origin post, preferring Webmention when it's usable on
 * both ends and falling back to a native WordPress REST comment POST
 * otherwise — see CLAUDE.md's "Replace subscription-post 'Reply' with a
 * 'Comment' action" decision row for the full reasoning.
 *
 * Both branches need exactly one fresh fetch of the subscription post's own
 * permalink, resolved on demand at send time (never at ingest/poll time) —
 * the same "avoids widening this plugin's SSRF/outbound-request surface for
 * every ingested post, most of which a reader never opens" reasoning
 * Daymark_Subscription_Oembed already established for its own on-demand
 * resolution.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static comment-delivery resolver: Webmention-preferred, native WP REST
 * comment fallback.
 */
class Daymark_Comment_Delivery {

	/**
	 * Maximum response size, in bytes, for the origin permalink fetch (head
	 * discovery) and the native-comment POST alike. A page's `<head>` and a
	 * REST comment response are both small — generous headroom, not a
	 * realistic expectation. Filterable via
	 * `daymark_subscription_comment_max_bytes`.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 512 * 1024; // 512 KB.

	/**
	 * Maximum comment length Daymark itself will submit, regardless of
	 * delivery method — a sane bound on what "a short comment" means here,
	 * not a limit either delivery target necessarily enforces itself.
	 *
	 * @var int
	 */
	private const MAX_COMMENT_LENGTH = 2000;

	/**
	 * Deliver a comment to a subscription post's origin.
	 *
	 * Bounded to an existing daymark_sub_post ID — never an arbitrary
	 * caller-supplied URL — so this can never be used as an open relay to
	 * comment on a site the current user hasn't already deliberately
	 * subscribed to.
	 *
	 * @param int    $subscription_post_id A `daymark_sub_post` post ID.
	 * @param string $text                 The comment text, already `wp_kses_post()`-safe or plain — sanitized here regardless.
	 * @return array{method: string, status: string, message: string, mark_id?: int}|WP_Error
	 */
	public static function deliver( int $subscription_post_id, string $text ) {
		$text = trim( wp_kses_post( $text ) );

		if ( '' === $text ) {
			return new WP_Error(
				'daymark_comment_empty',
				__( 'A comment needs some text.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$text = mb_substr( $text, 0, self::MAX_COMMENT_LENGTH );

		$post = get_post( $subscription_post_id );

		if ( ! $post instanceof WP_Post || Daymark_Subscription_Post_Type::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$permalink = esc_url_raw( (string) get_post_meta( $subscription_post_id, 'permalink', true ) );
		$scheme    = '' !== $permalink ? strtolower( (string) ( wp_parse_url( $permalink, PHP_URL_SCHEME ) ?? '' ) ) : '';

		if ( '' === $permalink || ! in_array( $scheme, array( 'http', 'https' ), true ) || is_wp_error( Daymark_Subscription_Url_Guard::check( $permalink ) ) ) {
			return new WP_Error(
				'daymark_comment_unsafe_url',
				__( "This post's source couldn't be safely reached.", 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$signals = self::discover_origin_signals( $permalink );

		if ( '' !== $signals['webmention_endpoint'] && Daymark_Plugin_Detector::is_active( 'webmention' ) ) {
			return self::send_via_webmention( $permalink, $text );
		}

		if ( '' === $signals['rest_root'] || 0 === $signals['post_id'] ) {
			return new WP_Error(
				'daymark_comment_undeliverable',
				__( "This post's site doesn't accept comments through its own API, and doesn't support Webmention either.", 'daymark' ),
				array( 'status' => 422 )
			);
		}

		return self::send_native_comment( $signals['rest_root'], $signals['post_id'], $text );
	}

	/**
	 * Webmention branch: publish a minimal, instant Note Mark carrying
	 * `_daymark_in_reply_to` = the origin permalink — the exact same
	 * mechanism the old composer-based Reply action already used
	 * (Daymark_Publisher::resolve_in_reply_to()/resolve_target_url(),
	 * Daymark_Microformats::reply_markup() for the outbound u-in-reply-to
	 * markup) — no new meta field, no new resolver. Calls
	 * Daymark_Publisher::publish() directly rather than looping back
	 * through the internal REST API, matching the exact precedent
	 * Daymark_Share_Target::handle() already set. Your installed Webmention
	 * plugin does the actual delivery, same as it always has.
	 *
	 * @param string $permalink Origin post permalink.
	 * @param string $text      Comment text — becomes the new Mark's caption.
	 * @return array{method: string, status: string, message: string, mark_id: int}|WP_Error
	 */
	private static function send_via_webmention( string $permalink, string $text ) {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'     => $text,
				'in_reply_to' => $permalink,
				'status'      => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'method'  => 'webmention',
			'status'  => 'published',
			'mark_id' => (int) $post_id,
			'message' => __( 'Your comment was published on your site and will be delivered to the original post via Webmention.', 'daymark' ),
		);
	}

	/**
	 * Native fallback: POST directly to the origin's own `wp/v2/comments`
	 * REST endpoint, unauthenticated — the same way an ordinary blog
	 * comment form works. No Mark is created on your own site in this
	 * path (see CLAUDE.md's "deliberately out of scope" note on a
	 * persistent indicator for this branch).
	 *
	 * @param string $rest_root Origin site's REST API root (e.g. `https://example.com/wp-json/`).
	 * @param int    $post_id   Origin post's own numeric ID.
	 * @param string $text      Comment text.
	 * @return array{method: string, status: string, message: string}|WP_Error
	 */
	private static function send_native_comment( string $rest_root, int $post_id, string $text ) {
		$comments_url = rtrim( $rest_root, '/' ) . '/wp/v2/comments';

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $comments_url ) ) ) {
			return new WP_Error(
				'daymark_comment_unsafe_url',
				__( "This post's site couldn't be safely reached.", 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$user = wp_get_current_user();

		$response = wp_safe_remote_post(
			$comments_url,
			array(
				'timeout'             => (int) apply_filters( 'daymark_subscription_comment_fetch_timeout', 10 ),
				'redirection'         => 5,
				'limit_response_size' => (int) apply_filters( 'daymark_subscription_comment_max_bytes', self::MAX_RESPONSE_BYTES ),
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
				'body'                => array(
					'post'         => $post_id,
					'content'      => $text,
					'author_name'  => $user->display_name,
					'author_email' => $user->user_email,
					'author_url'   => home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'daymark_comment_delivery_failed',
				__( "Your comment couldn't be delivered — the site didn't respond.", 'daymark' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$error_code = is_array( $body ) && ! empty( $body['code'] ) ? sanitize_key( (string) $body['code'] ) : '';

			// WordPress core's own REST comments controller rejects *any*
			// unauthenticated POST with this exact code before it ever reads
			// the request body — regardless of comment_registration, and
			// regardless of what the origin's own classic comment form
			// allows — unless the site opts in via the `rest_allow_anonymous_comments`
			// filter (default false). No additional field Daymark could send
			// (name/email/site are already included above) gets past this,
			// so it needs its own clearer message rather than the origin's
			// raw, easy-to-misread-as-a-Daymark-login-problem text.
			if ( 'rest_comment_login_required' === $error_code ) {
				return new WP_Error(
					'daymark_comment_requires_login',
					__( "This site's own API doesn't accept comments from anonymous visitors — a default WordPress restriction on the destination site, not a problem with your Daymark account. You'll need to comment on the original post directly, or ask the site owner to enable Webmention.", 'daymark' ),
					array( 'status' => 401 )
				);
			}

			$message = is_array( $body ) && ! empty( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : '';

			return new WP_Error(
				'daymark_comment_rejected',
				'' !== $message ? $message : __( "This site couldn't accept your comment.", 'daymark' ),
				array( 'status' => $code >= 400 && $code < 600 ? $code : 502 )
			);
		}

		$held = is_array( $body ) && 'hold' === ( $body['status'] ?? '' );

		return array(
			'method'  => 'native',
			'status'  => $held ? 'held' : 'published',
			'message' => $held
				? __( 'Your comment was sent and is awaiting moderation on the original site.', 'daymark' )
				: __( 'Your comment was posted directly to the original site.', 'daymark' ),
		);
	}

	/**
	 * Fetch the origin permalink once and pull both signals out of the same
	 * response: a Webmention receiver endpoint (the HTTP `Link` header,
	 * falling back to a `<link rel="webmention">` in `<head>`) and the
	 * origin's own REST API — its site-wide root (the same
	 * `<link rel="https://api.w.org/">` discovery tag
	 * Daymark_Subscription_Source_WordPress::find_rest_root() already reads
	 * for subscribing) plus this specific post's own numeric ID (WordPress
	 * core's own per-post `<link rel="alternate" type="application/json">`
	 * discovery tag, `rest_output_link_wp_head()` — the standard, reliable
	 * way to resolve a post's REST identity from its permalink page with no
	 * slug-guessing needed).
	 *
	 * @param string $permalink Already URL-guard-checked, http(s) permalink.
	 * @return array{webmention_endpoint: string, rest_root: string, post_id: int}
	 */
	private static function discover_origin_signals( string $permalink ): array {
		$empty = array(
			'webmention_endpoint' => '',
			'rest_root'           => '',
			'post_id'             => 0,
		);

		$response = wp_safe_remote_get(
			$permalink,
			array(
				'timeout'             => (int) apply_filters( 'daymark_subscription_comment_fetch_timeout', 10 ),
				'redirection'         => 5,
				'limit_response_size' => (int) apply_filters( 'daymark_subscription_comment_max_bytes', self::MAX_RESPONSE_BYTES ),
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $empty;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return $empty;
		}

		$webmention_endpoint = self::webmention_endpoint_from_header( wp_remote_retrieve_header( $response, 'link' ) );
		$html                = (string) wp_remote_retrieve_body( $response );

		if ( '' === $webmention_endpoint ) {
			$webmention_endpoint = self::find_link_href( $html, 'webmention' );
		}

		return array(
			'webmention_endpoint' => '' !== $webmention_endpoint ? esc_url_raw( WP_Http::make_absolute_url( $webmention_endpoint, $permalink ) ) : '',
			'rest_root'           => self::find_link_href( $html, 'https://api.w.org/' ),
			'post_id'             => self::extract_post_id( self::find_link_href( $html, 'alternate', 'application/json' ) ),
		);
	}

	/**
	 * Parse an HTTP `Link` response header for a `rel="webmention"` entry —
	 * the primary, spec-preferred Webmention discovery signal, checked
	 * before falling back to the `<head>` scan.
	 *
	 * @param string|string[] $header Raw `Link` header value(s), comma-joined by WP if multiple.
	 * @return string The endpoint URL (possibly relative), or '' when not present.
	 */
	private static function webmention_endpoint_from_header( $header ): string {
		$header = is_array( $header ) ? implode( ', ', $header ) : (string) $header;

		if ( '' === $header ) {
			return '';
		}

		foreach ( explode( ',', $header ) as $entry ) {
			if ( ! preg_match( '/<([^>]+)>/', $entry, $url_match ) ) {
				continue;
			}

			if ( preg_match( '/rel=["\']?([^"\';]+)/i', $entry, $rel_match ) && 'webmention' === strtolower( trim( $rel_match[1] ) ) ) {
				return trim( $url_match[1] );
			}
		}

		return '';
	}

	/**
	 * Find a `<link>` tag's `href` by `rel` (and optionally `type`) anywhere
	 * in an HTML document, via WP_HTML_Tag_Processor (core since WP 6.2, no
	 * new dependency) — the same "reuse what's already happening" instinct
	 * Daymark_Subscription_Oembed::extract_safe_embed() already established
	 * for this exact API, rather than the regex `<head>`-scan style
	 * Daymark_Subscription_Source_WordPress's own private helpers use.
	 *
	 * @param string $html HTML to scan.
	 * @param string $rel  Required `rel` value (case-insensitive).
	 * @param string $type Optional required `type` value; '' to ignore.
	 * @return string The `href` value (possibly relative), or '' when not found.
	 */
	private static function find_link_href( string $html, string $rel, string $type = '' ): string {
		if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return '';
		}

		try {
			$processor = new WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag( array( 'tag_name' => 'link' ) ) ) {
				$tag_rel = strtolower( trim( (string) ( $processor->get_attribute( 'rel' ) ?? '' ) ) );

				if ( strtolower( $rel ) !== $tag_rel ) {
					continue;
				}

				if ( '' !== $type ) {
					$tag_type = strtolower( trim( (string) ( $processor->get_attribute( 'type' ) ?? '' ) ) );

					if ( strtolower( $type ) !== $tag_type ) {
						continue;
					}
				}

				$href = trim( (string) ( $processor->get_attribute( 'href' ) ?? '' ) );

				if ( '' !== $href ) {
					return $href;
				}
			}
		} catch ( Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Extract the trailing numeric ID from a per-post REST discovery URL
	 * (e.g. `https://example.com/wp-json/wp/v2/posts/123` -> 123) —
	 * deliberately tolerant of any post-type route (posts/pages/a custom
	 * type), since /wp/v2/comments only ever needs the numeric ID itself.
	 *
	 * @param string $rest_post_url The discovered per-post REST URL, or ''.
	 * @return int The post ID, or 0 when not resolvable.
	 */
	private static function extract_post_id( string $rest_post_url ): int {
		if ( '' === $rest_post_url ) {
			return 0;
		}

		if ( ! preg_match( '#/(\d+)/?(?:\?.*)?$#', $rest_post_url, $matches ) ) {
			return 0;
		}

		return absint( $matches[1] );
	}
}
