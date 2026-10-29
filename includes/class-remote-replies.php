<?php
/**
 * Replies on a followed post, read from the site it came from (issue #559).
 *
 * A followed post's own conversation lives on its own site: a WordPress
 * site's comments, or a fediverse post's replies. The full post view's
 * "Show replies" button asks for them here, once, on tap. Two sources:
 *
 * - A Mastodon-family post (Mastodon, Pleroma, Akkoma, GoToSocial): its
 *   server's public context API, `GET /api/v1/statuses/{id}/context`,
 *   which lists the replies under a status. A server that requires
 *   sign-in for it answers 401 or 403, and its replies stay on the site.
 * - Any WordPress site: its own REST API, `GET wp/v2/comments?post={id}`,
 *   using the REST root and post ID the origin page advertises (the same
 *   discovery Daymark_Comment_Delivery already does for Comment, cached
 *   and shared). Unauthenticated, it only ever returns approved comments.
 *
 * Read-only: nothing here posts anything. Every request goes through
 * Daymark_Subscription_Url_Guard and Daymark_Outbound_Guard, and a result
 * (including "this site doesn't share them") is cached for a few minutes,
 * so opening the same post again costs nothing. The HTML each reply
 * carries is reduced to a short allowlist of text tags, images become
 * their alt text, and links open in a new tab.
 *
 * @package Daymark
 * @since   0.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads a followed post's replies from its own site.
 */
class Daymark_Remote_Replies {

	/**
	 * The replies loaded.
	 *
	 * @var string
	 */
	public const STATUS_OK = 'ok';

	/**
	 * The site doesn't share its replies with Daymark (not WordPress or
	 * Mastodon, the API is off, or it requires sign-in).
	 *
	 * @var string
	 */
	public const STATUS_UNSUPPORTED = 'unsupported';

	/**
	 * The site didn't answer, or answered with something unusable. Cached
	 * only briefly, so a retry soon after can succeed.
	 *
	 * @var string
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * The replies came from a WordPress site's comments API.
	 *
	 * @var string
	 */
	public const SOURCE_WORDPRESS = 'wordpress';

	/**
	 * The replies came from a Mastodon-family server's context API.
	 *
	 * @var string
	 */
	public const SOURCE_MASTODON = 'mastodon';

	/**
	 * How deep replies nest when shown. Deeper replies are shown at this
	 * depth, under their parent.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 3;

	/**
	 * Transient key prefix for a permalink's cached result.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'daymark_replies_';

	/**
	 * Maximum response size, in bytes. Filterable via
	 * `daymark_remote_replies_max_bytes`.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 1048576; // 1 MB.

	/**
	 * Longest reply text kept as HTML; a longer one becomes a plain-text
	 * excerpt.
	 *
	 * @var int
	 */
	private const MAX_TEXT_CHARS = 2000;

	/**
	 * The comment fields Daymark asks a WordPress site for.
	 *
	 * @var string
	 */
	public const COMMENT_FIELDS = 'id,parent,author_name,author_url,author_avatar_urls,date_gmt,link,content';

	/**
	 * A followed post's replies, from cache or its own site.
	 *
	 * @param string $permalink The followed post's permalink.
	 * @return array{status: string, source: string, replies: array<int, array<string, mixed>>, total: int, url: string}
	 */
	public static function for_permalink( string $permalink ): array {
		$permalink = esc_url_raw( trim( $permalink ) );

		if ( '' === $permalink ) {
			return self::result( self::STATUS_UNSUPPORTED, '', '' );
		}

		$cached = self::cached( $permalink );

		if ( null !== $cached ) {
			return $cached;
		}

		$result = self::load( $permalink );

		/**
		 * Filters how long a followed post's replies are cached, in
		 * seconds. A failed load is always cached for one minute only.
		 *
		 * @since 0.22.0
		 *
		 * @param int $seconds Cache lifetime. Default 10 minutes.
		 */
		$ttl = self::STATUS_ERROR === $result['status']
			? MINUTE_IN_SECONDS
			: max( MINUTE_IN_SECONDS, (int) apply_filters( 'daymark_remote_replies_cache_ttl', 10 * MINUTE_IN_SECONDS ) );

		set_transient( self::cache_key( $permalink ), $result, $ttl );

		return $result;
	}

	/**
	 * A permalink's cached result, or null when nothing is cached. Never
	 * a request.
	 *
	 * @param string $permalink The followed post's permalink.
	 * @return array<string, mixed>|null
	 */
	public static function cached( string $permalink ): ?array {
		$permalink = esc_url_raw( trim( $permalink ) );

		if ( '' === $permalink ) {
			return null;
		}

		$cached = get_transient( self::cache_key( $permalink ) );

		return is_array( $cached ) && isset( $cached['status'], $cached['replies'] ) ? $cached : null;
	}

	/**
	 * Flags the replies the current user wrote, by their IDs on the
	 * original site (which Daymark only knows for comments it sent).
	 *
	 * @param array<int, array<string, mixed>> $replies Replies from for_permalink().
	 * @param string[]                         $own_ids Reply IDs the user wrote.
	 * @return array<int, array<string, mixed>>
	 */
	public static function mark_own( array $replies, array $own_ids ): array {
		$own = array_fill_keys( array_map( 'strval', $own_ids ), true );

		foreach ( $replies as $index => $reply ) {
			$replies[ $index ]['is_own'] = isset( $own[ (string) ( $reply['id'] ?? '' ) ] );
		}

		return $replies;
	}

	/**
	 * GET a URL and decode its JSON body, through the URL guard and the
	 * outbound guard.
	 *
	 * @param string $url URL to fetch.
	 * @return array{code: int, data: mixed, total: int}|WP_Error
	 */
	public static function get_json( string $url ) {
		$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || is_wp_error( Daymark_Subscription_Url_Guard::check( $url ) ) ) {
			return new WP_Error( 'daymark_replies_unsafe_url', __( "This post's site couldn't be safely reached.", 'daymark' ) );
		}

		$response = Daymark_Outbound_Guard::get(
			$url,
			array(
				/**
				 * Filters the timeout for loading a followed post's
				 * replies, in seconds.
				 *
				 * @since 0.22.0
				 *
				 * @param int $seconds Timeout. Default 10.
				 */
				'timeout'             => (int) apply_filters( 'daymark_remote_replies_fetch_timeout', 10 ),
				'redirection'         => 3,
				/**
				 * Filters the largest replies response Daymark reads, in
				 * bytes. A bigger one is cut off and fails to parse.
				 *
				 * @since 0.22.0
				 *
				 * @param int $bytes Size cap. Default 1 MB.
				 */
				'limit_response_size' => (int) apply_filters( 'daymark_remote_replies_max_bytes', self::MAX_RESPONSE_BYTES ),
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$total = wp_remote_retrieve_header( $response, 'x-wp-total' );

		if ( is_array( $total ) ) {
			$total = reset( $total );
		}

		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'data'  => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
			'total' => absint( $total ),
		);
	}

	/**
	 * A WordPress site's comments, as raw REST items.
	 *
	 * @param string               $root REST API root (absolute).
	 * @param array<string, mixed> $args Query arguments for `wp/v2/comments`.
	 * @return array{status: string, items: array<int, array<string, mixed>>, total: int}
	 */
	public static function fetch_comments( string $root, array $args ): array {
		$response = self::get_json( self::comments_url( $root, $args ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'daymark_replies_unsafe_url' === $response->get_error_code() ? self::STATUS_UNSUPPORTED : self::STATUS_ERROR,
				'items'  => array(),
				'total'  => 0,
			);
		}

		// Signed-in only, comments off for this post, the comments route
		// removed, or the post gone: the site doesn't share them.
		if ( in_array( $response['code'], array( 401, 403, 404, 410 ), true ) ) {
			return array(
				'status' => self::STATUS_UNSUPPORTED,
				'items'  => array(),
				'total'  => 0,
			);
		}

		if ( 200 !== $response['code'] || ! is_array( $response['data'] ) || ! array_is_list( $response['data'] ) ) {
			return array(
				'status' => self::STATUS_ERROR,
				'items'  => array(),
				'total'  => 0,
			);
		}

		return array(
			'status' => self::STATUS_OK,
			'items'  => array_values( array_filter( $response['data'], 'is_array' ) ),
			'total'  => $response['total'],
		);
	}

	/**
	 * The `wp/v2/comments` URL under a REST root, with query arguments.
	 * Works for a pretty root (`/wp-json/`) and a plain one
	 * (`/?rest_route=/`).
	 *
	 * @param string               $root REST API root (absolute).
	 * @param array<string, mixed> $args Query arguments.
	 * @return string
	 */
	public static function comments_url( string $root, array $args ): string {
		$url   = rtrim( $root, '/' ) . '/wp/v2/comments';
		$query = array();

		foreach ( $args as $key => $value ) {
			$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		if ( array() === $query ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $query );
	}

	/**
	 * A REST root as advertised by a page (possibly relative), made
	 * absolute against that page's URL.
	 *
	 * @param string $root      REST root from the page.
	 * @param string $permalink The page's URL.
	 * @return string
	 */
	public static function absolute_root( string $root, string $permalink ): string {
		return '' === $root ? '' : esc_url_raw( WP_Http::make_absolute_url( $root, $permalink ) );
	}

	/**
	 * How many replies Daymark loads for one post.
	 *
	 * @return int
	 */
	public static function max_replies(): int {
		/**
		 * Filters how many replies "Show replies" loads for one post. A
		 * post with more links to the rest on its own site.
		 *
		 * @since 0.22.0
		 *
		 * @param int $max Replies to load, 1 to 100. Default 50.
		 */
		return min( 100, max( 1, (int) apply_filters( 'daymark_remote_replies_max', 50 ) ) );
	}

	/**
	 * One WordPress REST comment, reduced to Daymark's reply shape.
	 * Null when it has no ID.
	 *
	 * @param array<string, mixed> $comment REST comment.
	 * @param string               $base    URL relative links resolve against.
	 * @return array<string, mixed>|null
	 */
	public static function normalize_wordpress_comment( array $comment, string $base ): ?array {
		$id = absint( $comment['id'] ?? 0 );

		if ( 0 === $id ) {
			return null;
		}

		$avatars = is_array( $comment['author_avatar_urls'] ?? null ) ? $comment['author_avatar_urls'] : array();
		$avatar  = '';

		foreach ( array( '96', '48', '24' ) as $size ) {
			if ( ! empty( $avatars[ $size ] ) && is_string( $avatars[ $size ] ) ) {
				$avatar = $avatars[ $size ];
				break;
			}
		}

		$content   = is_array( $comment['content'] ?? null ) ? (string) ( $comment['content']['rendered'] ?? '' ) : '';
		$timestamp = self::timestamp( (string) ( $comment['date_gmt'] ?? '' ) );

		return array(
			'id'          => (string) $id,
			'parent'      => (string) absint( $comment['parent'] ?? 0 ),
			'author_name' => self::plain( (string) ( $comment['author_name'] ?? '' ), 80 ),
			'author_url'  => self::link_url( (string) ( $comment['author_url'] ?? '' ) ),
			'handle'      => '',
			'avatar'      => esc_url_raw( $avatar, array( 'https' ) ),
			'content'     => self::clean_html( $content, $base ),
			'date'        => $timestamp > 0 ? gmdate( 'c', $timestamp ) : '',
			'url'         => self::link_url( (string) ( $comment['link'] ?? '' ) ),
			'warning'     => '',
			'media_count' => 0,
			'_ts'         => $timestamp,
		);
	}

	/**
	 * Plain text from reply HTML, for a push message or a notification.
	 *
	 * @param string $html Reply HTML.
	 * @param int    $max  Longest text kept.
	 * @return string
	 */
	public static function plain( string $html, int $max ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * An http(s) URL, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function link_url( string $url ): string {
		$url    = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Load a permalink's replies, uncached.
	 *
	 * @param string $permalink The followed post's permalink.
	 * @return array<string, mixed>
	 */
	private static function load( string $permalink ): array {
		$scheme = strtolower( (string) ( wp_parse_url( $permalink, PHP_URL_SCHEME ) ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || is_wp_error( Daymark_Subscription_Url_Guard::check( $permalink ) ) ) {
			return self::result( self::STATUS_UNSUPPORTED, '', $permalink );
		}

		$mastodon = self::load_mastodon( $permalink );

		if ( null !== $mastodon ) {
			return $mastodon;
		}

		return self::load_wordpress( $permalink );
	}

	/**
	 * A Mastodon-family post's replies, or null when the permalink isn't
	 * one (so the WordPress path gets a turn).
	 *
	 * @param string $permalink The followed post's permalink.
	 * @return array<string, mixed>|null
	 */
	private static function load_mastodon( string $permalink ): ?array {
		$status_id = self::mastodon_status_id( $permalink );

		if ( '' === $status_id ) {
			return null;
		}

		$scheme   = strtolower( (string) wp_parse_url( $permalink, PHP_URL_SCHEME ) );
		$host     = strtolower( (string) wp_parse_url( $permalink, PHP_URL_HOST ) );
		$response = self::get_json( $scheme . '://' . $host . '/api/v1/statuses/' . rawurlencode( $status_id ) . '/context' );

		if ( is_wp_error( $response ) ) {
			return self::result( self::STATUS_ERROR, self::SOURCE_MASTODON, $permalink );
		}

		if ( in_array( $response['code'], array( 401, 403 ), true ) ) {
			return self::result( self::STATUS_UNSUPPORTED, self::SOURCE_MASTODON, $permalink );
		}

		$data = $response['data'];

		if ( 200 !== $response['code'] || ! is_array( $data ) || ! isset( $data['descendants'] ) || ! is_array( $data['descendants'] ) ) {
			// Not a Mastodon API after all (or the post is gone): let the
			// WordPress path try, unless the server is simply failing.
			return $response['code'] >= 500 || 429 === $response['code']
				? self::result( self::STATUS_ERROR, self::SOURCE_MASTODON, $permalink )
				: null;
		}

		$replies = array();
		$base    = $scheme . '://' . $host . '/';

		foreach ( array_slice( $data['descendants'], 0, self::max_replies() ) as $status ) {
			$reply = is_array( $status ) ? self::normalize_mastodon_status( $status, $host, $base ) : null;

			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}

		return self::result( self::STATUS_OK, self::SOURCE_MASTODON, $permalink, self::thread( $replies, $status_id ), count( $data['descendants'] ) );
	}

	/**
	 * A WordPress post's comments.
	 *
	 * @param string $permalink The followed post's permalink.
	 * @return array<string, mixed>
	 */
	private static function load_wordpress( string $permalink ): array {
		$signals = Daymark_Comment_Delivery::origin_signals_for_url( $permalink );

		if ( is_wp_error( $signals ) || '' === (string) ( $signals['rest_root'] ?? '' ) || (int) ( $signals['post_id'] ?? 0 ) <= 0 ) {
			return self::result( self::STATUS_UNSUPPORTED, '', $permalink );
		}

		$root    = self::absolute_root( (string) $signals['rest_root'], $permalink );
		$fetched = self::fetch_comments(
			$root,
			array(
				'post'     => (int) $signals['post_id'],
				'per_page' => self::max_replies(),
				'orderby'  => 'date_gmt',
				'order'    => 'desc',
				'_fields'  => self::COMMENT_FIELDS,
			)
		);

		$url = $permalink . ( false === strpos( $permalink, '#' ) ? '#comments' : '' );

		if ( self::STATUS_OK !== $fetched['status'] ) {
			return self::result( $fetched['status'], self::SOURCE_WORDPRESS, $url );
		}

		$replies = array();

		foreach ( $fetched['items'] as $comment ) {
			$reply = self::normalize_wordpress_comment( $comment, $permalink );

			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}

		return self::result( self::STATUS_OK, self::SOURCE_WORDPRESS, $url, self::thread( $replies, '0' ), $fetched['total'] );
	}

	/**
	 * The status ID in a Mastodon-family permalink, or ''.
	 *
	 * @param string $permalink Permalink.
	 * @return string
	 */
	private static function mastodon_status_id( string $permalink ): string {
		$path     = (string) ( wp_parse_url( $permalink, PHP_URL_PATH ) ?? '' );
		$patterns = array(
			'#^/@[^/]+/(\d{1,25})/?$#',                      // Mastodon: /@user/123.
			'#^/users/[^/]+/statuses/([0-9A-Za-z]{1,40})/?$#', // Mastodon and GoToSocial activity IDs.
			'#^/@[^/]+/statuses/([0-9A-Za-z]{10,40})/?$#',   // GoToSocial: /@user/statuses/01H….
			'#^/notice/([0-9A-Za-z]{1,40})/?$#',             // Pleroma and Akkoma.
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $path, $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * One Mastodon status, reduced to Daymark's reply shape.
	 *
	 * @param array<string, mixed> $status Status from the context API.
	 * @param string               $host   The server's host.
	 * @param string               $base   URL relative links resolve against.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_mastodon_status( array $status, string $host, string $base ): ?array {
		$id = (string) ( $status['id'] ?? '' );

		if ( ! preg_match( '/^[0-9A-Za-z]{1,40}$/', $id ) ) {
			return null;
		}

		$account  = is_array( $status['account'] ?? null ) ? $status['account'] : array();
		$username = self::plain( (string) ( $account['username'] ?? '' ), 60 );
		$acct     = self::plain( (string) ( $account['acct'] ?? $username ), 120 );
		$name     = self::plain( (string) ( $account['display_name'] ?? '' ), 80 );

		if ( '' !== $acct && false === strpos( $acct, '@' ) ) {
			$acct .= '@' . $host;
		}

		$timestamp = self::timestamp( (string) ( $status['created_at'] ?? '' ) );
		$media     = is_array( $status['media_attachments'] ?? null ) ? count( $status['media_attachments'] ) : 0;
		$url       = self::link_url( (string) ( $status['url'] ?? '' ) );

		return array(
			'id'          => $id,
			'parent'      => (string) ( $status['in_reply_to_id'] ?? '' ),
			'author_name' => '' !== $name ? $name : $username,
			'author_url'  => self::link_url( (string) ( $account['url'] ?? '' ) ),
			'handle'      => '' !== $acct ? '@' . $acct : '',
			'avatar'      => esc_url_raw( (string) ( $account['avatar_static'] ?? '' ), array( 'https' ) ),
			'content'     => self::clean_html( (string) ( $status['content'] ?? '' ), $base ),
			'date'        => $timestamp > 0 ? gmdate( 'c', $timestamp ) : '',
			'url'         => '' !== $url ? $url : self::link_url( (string) ( $status['uri'] ?? '' ) ),
			'warning'     => self::plain( (string) ( $status['spoiler_text'] ?? '' ), 200 ),
			'media_count' => $media,
			'_ts'         => $timestamp,
		);
	}

	/**
	 * Replies in reading order: each top-level reply followed by its
	 * replies, oldest first at every level, with a `depth` (0 to
	 * MAX_DEPTH). A reply whose parent isn't in the list (it was more than
	 * Daymark loads, or deleted) is shown at the top level. Never loops:
	 * every reply is placed exactly once.
	 *
	 * @param array<int, array<string, mixed>> $replies Normalized replies.
	 * @param string                           $root_id The post's own ID (a parent meaning "top level").
	 * @return array<int, array<string, mixed>>
	 */
	public static function thread( array $replies, string $root_id ): array {
		$known = array();

		foreach ( $replies as $reply ) {
			$known[ 'r' . $reply['id'] ] = true;
		}

		$children = array();

		foreach ( $replies as $reply ) {
			$parent = (string) $reply['parent'];
			$key    = ( '' === $parent || '0' === $parent || $root_id === $parent || $parent === $reply['id'] || ! isset( $known[ 'r' . $parent ] ) )
				? ''
				: 'r' . $parent;

			$children[ $key ][] = $reply;
		}

		foreach ( $children as $key => $list ) {
			usort(
				$list,
				static function ( array $a, array $b ): int {
					return array( (int) $a['_ts'], strlen( (string) $a['id'] ), (string) $a['id'] ) <=> array( (int) $b['_ts'], strlen( (string) $b['id'] ), (string) $b['id'] );
				}
			);
			$children[ $key ] = $list;
		}

		$out     = array();
		$visited = array();

		self::walk( '', 0, $children, $visited, $out );

		// A reply whose parents form a loop never hangs off the top level;
		// place whatever is left there.
		foreach ( $children as $list ) {
			foreach ( $list as $reply ) {
				if ( ! isset( $visited[ 'r' . $reply['id'] ] ) ) {
					$children['loose'] = array( $reply );
					self::walk( 'loose', 0, $children, $visited, $out );
				}
			}
		}

		foreach ( $out as $index => $reply ) {
			unset( $out[ $index ]['_ts'] );
		}

		return $out;
	}

	/**
	 * Depth-first placement for thread().
	 *
	 * @param string                                          $key      Parent key ('' for top level).
	 * @param int                                             $level    Nesting level.
	 * @param array<string, array<int, array<string, mixed>>> $children Replies by parent key.
	 * @param array<string, bool>                             $visited  Placed reply keys.
	 * @param array<int, array<string, mixed>>                $out      Replies in order.
	 * @return void
	 */
	private static function walk( string $key, int $level, array $children, array &$visited, array &$out ): void {
		foreach ( $children[ $key ] ?? array() as $reply ) {
			$reply_key = 'r' . $reply['id'];

			if ( isset( $visited[ $reply_key ] ) ) {
				continue;
			}

			$visited[ $reply_key ] = true;
			$reply['depth']        = min( $level, self::MAX_DEPTH );
			$out[]                 = $reply;

			self::walk( $reply_key, $level + 1, $children, $visited, $out );
		}
	}

	/**
	 * Reply HTML reduced to plain text tags, safe to show in the app.
	 *
	 * @param string $html Reply HTML from another site.
	 * @param string $base URL relative links resolve against.
	 * @return string
	 */
	public static function clean_html( string $html, string $base ): string {
		// Mastodon hides parts of long links; keep only what a reader sees.
		$html = (string) preg_replace( '#<span class="invisible">.*?</span>#is', '', $html );
		$html = (string) preg_replace( '#<span class="ellipsis">(.*?)</span>#is', '$1…', $html );

		// Images (emoji, smilies) become their alt text, so showing a reply
		// never loads anything from another site.
		$html = (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $matches ): string {
				return preg_match( '#\balt\s*=\s*(["\'])(.*?)\1#is', $matches[0], $alt ) ? $alt[2] : '';
			},
			$html
		);

		$html = wp_kses(
			$html,
			array(
				'p'          => array(),
				'br'         => array(),
				'a'          => array( 'href' => true ),
				'strong'     => array(),
				'b'          => array(),
				'em'         => array(),
				'i'          => array(),
				'blockquote' => array(),
				'code'       => array(),
				'pre'        => array(),
				'ul'         => array(),
				'ol'         => array(),
				'li'         => array(),
			),
			array( 'http', 'https' )
		);

		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'a' ) ) {
			$href = $tags->get_attribute( 'href' );
			$href = is_string( $href ) && '' !== $href ? self::link_url( WP_Http::make_absolute_url( $href, $base ) ) : '';

			if ( '' === $href ) {
				$tags->remove_attribute( 'href' );
				continue;
			}

			$tags->set_attribute( 'href', $href );
			$tags->set_attribute( 'target', '_blank' );
			$tags->set_attribute( 'rel', 'nofollow noopener noreferrer ugc' );
		}

		$html = trim( $tags->get_updated_html() );
		$text = self::plain( $html, PHP_INT_MAX );

		if ( mb_strlen( $text ) > self::MAX_TEXT_CHARS ) {
			return '<p>' . esc_html( rtrim( mb_substr( $text, 0, self::MAX_TEXT_CHARS - 1 ) ) . '…' ) . '</p>';
		}

		return $html;
	}

	/**
	 * A date from a remote site as a Unix timestamp; 0 when unreadable. A
	 * date with no zone (WordPress's `date_gmt`) is read as UTC.
	 *
	 * @param string $date Date string.
	 * @return int
	 */
	public static function timestamp( string $date ): int {
		$date = trim( $date );

		if ( '' === $date ) {
			return 0;
		}

		if ( ! preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $date ) ) {
			$date .= 'Z';
		}

		$timestamp = strtotime( $date );

		return false === $timestamp ? 0 : max( 0, $timestamp );
	}

	/**
	 * The result shape for_permalink() returns and caches.
	 *
	 * @param string                           $status  One of the STATUS_* values.
	 * @param string                           $source  One of the SOURCE_* values, or ''.
	 * @param string                           $url     Where the replies are on the original site.
	 * @param array<int, array<string, mixed>> $replies Threaded replies.
	 * @param int                              $total   How many replies the site reports.
	 * @return array{status: string, source: string, replies: array<int, array<string, mixed>>, total: int, url: string}
	 */
	private static function result( string $status, string $source, string $url, array $replies = array(), int $total = 0 ): array {
		return array(
			'status'  => $status,
			'source'  => $source,
			'replies' => $replies,
			'total'   => max( $total, count( $replies ) ),
			'url'     => $url,
		);
	}

	/**
	 * Transient key for a permalink's cached result.
	 *
	 * @param string $permalink Permalink.
	 * @return string
	 */
	private static function cache_key( string $permalink ): string {
		return self::CACHE_PREFIX . md5( $permalink );
	}
}
