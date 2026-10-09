<?php
/**
 * Jetpack-native Like/Comment fast path for a subscribed post whose origin
 * is itself WordPress.com-hosted or Jetpack-connected (issue #391).
 *
 * Requested directly, following on from issue #389's Like-duplication/
 * Jetpack-leak investigation: rather than always creating a local Like Mark
 * (relying on a federation plugin to send an outbound Webmention/ActivityPub
 * Like) or sending a reader to a browser view of the origin post to comment
 * (issue #351), this rides WordPress.com's own real, documented Like/Comment
 * REST API — the same one the official Jetpack app itself uses — when both:
 *
 * 1. The current user has personally linked their own WordPress.com account
 *    through Jetpack (a separate, one-time step from the site's own
 *    blog-level Jetpack connection most Jetpack users already have for
 *    Stats/Backup/Social) — required because a Like/Comment is inherently
 *    attributed to a specific person's WordPress.com identity, and WP.com's
 *    API needs a user-level access token for that, not a site-level one.
 * 2. The subscribed post's own origin resolves via WordPress.com's public
 *    API at all — i.e. it's WordPress.com-hosted, or a Jetpack-connected
 *    self-hosted site WP.com's own infrastructure already knows about.
 *
 * Neither condition can be assumed from "Jetpack is active" alone, and most
 * of Daymark's subscription sources (plain RSS/Atom, non-Jetpack WordPress
 * REST, Friends-plugin follows, microformats2, non-WordPress sites) will
 * never resolve at all — for those, Daymark_Comment_Delivery's existing
 * Webmention-preferred/native-fallback path (and the classic local Like Mark
 * created via Daymark_Publisher::publish()) remains the only option and is
 * completely unaffected by this class.
 *
 * NOT independently confirmed against a live Jetpack-connected install —
 * this environment can't install a third-party plugin for testing, the same
 * posture already used for ATmosphere/the Friends plugin/Bridgy Fed
 * elsewhere in this codebase (see CLAUDE.md). Every call here is
 * defensively wrapped so a wrong assumption about Jetpack's own API shape
 * degrades to "this fast path isn't available right now" — falling through
 * to the existing, unaffected classic path — rather than breaking Like or
 * Comment outright. Flagged for verification against a real Jetpack site
 * before relying on it alone.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detection, per-user WordPress.com connection state, origin resolution, and
 * the actual Like/Unlike/Comment calls against WordPress.com's REST API.
 */
class Daymark_Jetpack_Engagement {

	/**
	 * Multi-value user meta: subscription post IDs the user has Jetpack-liked.
	 *
	 * @var string
	 */
	public const META_LIKE = 'daymark_jetpack_like';

	/**
	 * Multi-value user meta: subscription post IDs the user has sent a
	 * Jetpack-native comment to. Write-once — unlike a Like, a comment isn't
	 * a toggle, so this only ever grows.
	 *
	 * @var string
	 */
	public const META_COMMENT = 'daymark_jetpack_comment';

	/**
	 * Post meta on one of this site's own Marks: the WordPress.com likes it
	 * has received, as last fetched by sync_own_likes(). JSON:
	 * `{count: int, likers: {wpcom_user_id: {name, url, avatar, liked_at}}, fetched_at: int}`.
	 *
	 * @var string
	 */
	public const META_OWN_LIKES = '_daymark_jetpack_likes';

	/**
	 * Per-Mark minimum gap between two own-likes fetches, in seconds.
	 *
	 * @var int
	 */
	private const OWN_LIKES_COOLDOWN = 10 * MINUTE_IN_SECONDS;

	/**
	 * Option holding the post ID sync_older_likes() continues below, or 0
	 * to start from the newest post.
	 *
	 * @var string
	 */
	public const OLDER_LIKES_CURSOR = 'daymark_jetpack_older_likes_cursor';

	/**
	 * Registers hooks — mirrors Daymark_Bookmarks' own cleanup convention.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'deleted_post', array( __CLASS__, 'clear_state_for_post' ) );
	}

	/**
	 * Clears every user's Jetpack-like/comment state for a post once it's
	 * actually gone — same `deleted_post` (not `wp_trash_post`) reasoning
	 * Daymark_Bookmarks::clear_bookmarks_for_post() already documents: a
	 * still-trashed-but-recoverable post keeps this state intact.
	 *
	 * @param int $post_id Deleted post ID.
	 * @return void
	 */
	public static function clear_state_for_post( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		delete_metadata( 'user', 0, self::META_LIKE, $post_id, true );
		delete_metadata( 'user', 0, self::META_COMMENT, $post_id, true );
	}

	/**
	 * Whether Jetpack's own Connection package (the full Jetpack plugin, not
	 * just the standalone Jetpack Social plugin already tracked separately
	 * in Daymark_Publish_Helpers for outbound auto-share awareness) is
	 * loaded. A class actually being loaded can only happen when the
	 * defining plugin is genuinely active — the same reasoning
	 * Daymark_Plugin_Detector::matches()'s own docblock already establishes.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'Automattic\\Jetpack\\Connection\\Client' )
			&& class_exists( 'Automattic\\Jetpack\\Connection\\Manager' );
	}

	/**
	 * Whether a user has personally linked their own WordPress.com account
	 * through Jetpack — the separate, one-time step from the site's own
	 * blog-level connection that a Like/Comment (attributed to a specific
	 * person, not "the site") actually requires.
	 *
	 * @param int $user_id User ID; 0 for the current user.
	 * @return bool
	 */
	public static function current_user_connected( int $user_id = 0 ): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		try {
			$manager = new \Automattic\Jetpack\Connection\Manager();

			return (bool) $manager->is_user_connected( $user_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether this site itself has a blog-level Jetpack connection — the
	 * only requirement for reading likes on its *own* posts (unlike a
	 * Like/Comment on someone else's post, which needs the acting user's
	 * own linked account). `is_connected()` is the current Connection
	 * package name; `is_active()` is its older equivalent.
	 *
	 * @return bool
	 */
	public static function site_connected(): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		try {
			$manager = new \Automattic\Jetpack\Connection\Manager();

			if ( method_exists( $manager, 'is_connected' ) ) {
				return (bool) $manager->is_connected();
			}

			return method_exists( $manager, 'is_active' ) && (bool) $manager->is_active();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * This site's own WordPress.com blog ID, or 0 when unknown.
	 *
	 * @return int
	 */
	private static function own_site_id(): int {
		try {
			$manager = new \Automattic\Jetpack\Connection\Manager();

			if ( method_exists( $manager, 'get_site_id' ) ) {
				$id = $manager->get_site_id();

				return is_wp_error( $id ) ? 0 : absint( $id );
			}
		} catch ( \Throwable $e ) {
			return 0;
		}

		return 0;
	}

	/**
	 * Fetch the WordPress.com likes one of this site's own Marks has
	 * received and store them via store_own_likes(). Jetpack likes are
	 * recorded on WordPress.com's servers, never in this site's own
	 * database, so without this they're invisible to Daymark's like count
	 * and Notifications. A Jetpack-connected site's posts keep the same
	 * post IDs on WordPress.com, so the local ID is used directly.
	 *
	 * Rate-limited per Mark (OWN_LIKES_COOLDOWN), made "as the blog" (a
	 * site owner reading their own post's likes needs no personal
	 * WordPress.com link), and never throws: any failure just leaves the
	 * last stored value in place.
	 *
	 * NOT independently confirmed against a live Jetpack install — the
	 * `sites/{site}/posts/{post}/likes` endpoint and its `found`/`likes[]`
	 * response shape come from WordPress.com's public API docs; flagged for
	 * verification.
	 *
	 * @param int $post_id           Mark post ID.
	 * @param int $fallback_liked_at Date for a new liker when WordPress.com
	 *                               sends no `date_liked`; 0 means now.
	 *                               See store_own_likes().
	 * @return bool Whether a fresh result was stored.
	 */
	public static function sync_own_likes( int $post_id, int $fallback_liked_at = 0 ): bool {
		if ( ! self::site_connected() ) {
			return false;
		}

		$cooldown_key = 'daymark_jp_own_likes_' . $post_id;

		if ( false !== get_transient( $cooldown_key ) ) {
			return false;
		}

		set_transient( $cooldown_key, 1, self::OWN_LIKES_COOLDOWN );

		$site_id = self::own_site_id();

		if ( 0 === $site_id ) {
			return false;
		}

		try {
			$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
				sprintf( 'sites/%d/posts/%d/likes', $site_id, $post_id )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return false;
		}

		self::store_own_likes( $post_id, $body, $fallback_liked_at );

		return true;
	}

	/**
	 * Copy WordPress.com likes for older posts, one batch per call.
	 *
	 * The backflow sync only covers recent Marks, so a like on an older
	 * post, or on an ordinary post, never reached Daymark's like count.
	 * This pass walks every published post from newest to oldest, a batch
	 * at a time, and starts again from the newest once it reaches the
	 * oldest. Each post keeps its own cooldown in sync_own_likes(), so a
	 * post the recent pass just checked isn't requested twice. A new liker
	 * with no `date_liked` is dated with the post's own date, so this pass
	 * never fills Notifications with likes that only look new.
	 *
	 * @return int Posts whose likes were refreshed.
	 */
	public static function sync_older_likes(): int {
		if ( ! self::site_connected() ) {
			return 0;
		}

		$synced = 0;

		foreach ( self::next_older_likes_batch() as $post_id ) {
			$post_date = (int) get_post_time( 'U', true, $post_id );

			if ( self::sync_own_likes( $post_id, $post_date ) ) {
				++$synced;
			}
		}

		return $synced;
	}

	/**
	 * The next batch of posts for sync_older_likes(), newest first, and
	 * move the stored position past them. When the walk reaches the oldest
	 * post, the position resets so the next call starts from the newest.
	 *
	 * @return int[] Post IDs.
	 */
	public static function next_older_likes_batch(): array {
		/**
		 * Filters how many posts each pass copies WordPress.com likes for.
		 * One request to WordPress.com per post. The pass runs hourly.
		 *
		 * @param int $size Posts per pass. Default 20.
		 */
		$size = max( 1, (int) apply_filters( 'daymark_jetpack_older_likes_batch', 20 ) );

		$cursor = absint( get_option( self::OLDER_LIKES_CURSOR, 0 ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A position-based walk WP_Query can't express (ID below a cursor); read once per hourly pass.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND ( %d = 0 OR ID < %d ) ORDER BY ID DESC LIMIT %d",
				$cursor,
				$cursor,
				$size
			)
		);

		$ids = array_map( 'absint', (array) $ids );

		// A short batch means the walk reached the oldest post: start over.
		update_option( self::OLDER_LIKES_CURSOR, count( $ids ) < $size ? 0 : (int) end( $ids ), false );

		return $ids;
	}

	/**
	 * Merge a decoded `sites/{site}/posts/{post}/likes` response into a
	 * Mark's META_OWN_LIKES. A liker keeps the `liked_at` it was first
	 * stored with (WordPress.com's own `date_liked` when present, else the
	 * time this site first saw it), so a Notifications item's date doesn't
	 * move on every refresh; a liker missing from the new response
	 * (unliked) is dropped. Separate from sync_own_likes() so it can be
	 * unit tested without the real Jetpack classes.
	 *
	 * A new liker with no `date_liked` gets $fallback_liked_at instead of
	 * now when one is given. The older-posts pass passes the post's own
	 * date, so likes it finds on an old post never show as new in
	 * Notifications.
	 *
	 * @param int                  $post_id           Mark post ID.
	 * @param array<string, mixed> $body              Decoded API response.
	 * @param int                  $fallback_liked_at Date for a new liker
	 *                                                with no `date_liked`;
	 *                                                0 means now.
	 * @return void
	 */
	public static function store_own_likes( int $post_id, array $body, int $fallback_liked_at = 0 ): void {
		$previous = self::own_likes( $post_id );
		$likers   = array();
		$now      = time();
		$fallback = $fallback_liked_at > 0 ? $fallback_liked_at : $now;

		foreach ( (array) ( $body['likes'] ?? array() ) as $like ) {
			if ( ! is_array( $like ) || empty( $like['ID'] ) ) {
				continue;
			}

			$wpcom_id = (string) absint( $like['ID'] );
			$liked_at = isset( $previous['likers'][ $wpcom_id ]['liked_at'] )
				? (int) $previous['likers'][ $wpcom_id ]['liked_at']
				: 0;

			if ( 0 === $liked_at && ! empty( $like['date_liked'] ) ) {
				$parsed   = strtotime( (string) $like['date_liked'] );
				$liked_at = false !== $parsed ? $parsed : 0;
			}

			$likers[ $wpcom_id ] = array(
				'name'     => sanitize_text_field( (string) ( $like['name'] ?? $like['login'] ?? '' ) ),
				'url'      => esc_url_raw( (string) ( $like['URL'] ?? $like['profile_URL'] ?? '' ) ),
				'avatar'   => esc_url_raw( (string) ( $like['avatar_URL'] ?? '' ) ),
				'liked_at' => $liked_at > 0 ? $liked_at : $fallback,
			);
		}

		$count = isset( $body['found'] ) ? absint( $body['found'] ) : count( $likers );

		update_post_meta(
			$post_id,
			self::META_OWN_LIKES,
			wp_json_encode(
				array(
					'count'      => max( $count, count( $likers ) ),
					'likers'     => (object) $likers,
					'fetched_at' => $now,
				)
			)
		);
	}

	/**
	 * A Mark's stored WordPress.com likes (see META_OWN_LIKES).
	 *
	 * @param int $post_id Mark post ID.
	 * @return array{count: int, likers: array<string, array{name: string, url: string, avatar: string, liked_at: int}>, fetched_at: int}
	 */
	public static function own_likes( int $post_id ): array {
		$decoded = json_decode( (string) get_post_meta( $post_id, self::META_OWN_LIKES, true ), true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'count'      => 0,
				'likers'     => array(),
				'fetched_at' => 0,
			);
		}

		return array(
			'count'      => absint( $decoded['count'] ?? 0 ),
			'likers'     => is_array( $decoded['likers'] ?? null ) ? $decoded['likers'] : array(),
			'fetched_at' => absint( $decoded['fetched_at'] ?? 0 ),
		);
	}

	/**
	 * Jetpack's own "connect your WordPress.com account" wp-admin screen —
	 * a link out, never something Daymark builds itself. The classic
	 * Jetpack dashboard slug is used since it's been stable across Jetpack
	 * versions; not independently confirmed this is still the best landing
	 * page for every current Jetpack version.
	 *
	 * @return string
	 */
	public static function connect_account_url(): string {
		return admin_url( 'admin.php?page=jetpack' );
	}

	/**
	 * Resolve a subscribed post's own permalink to WordPress.com's own site
	 * ID + post ID, when the origin resolves via WordPress.com's public API
	 * at all — the actual eligibility test for this whole fast path, since
	 * "Jetpack is active locally" says nothing about whether the *other*
	 * site is WordPress.com-hosted or Jetpack-connected.
	 *
	 * `GET /sites/{domain}/posts/slug:{slug}` accepts a plain domain for
	 * `$site` (confirmed via WordPress.com's own public API docs) and
	 * resolves to nothing at all for an ordinary non-WordPress.com,
	 * non-Jetpack-connected site — exactly the "not eligible, fall back"
	 * signal this needs, with no separate detection step required. Made via
	 * `wpcom_json_api_request_as_blog()` (the local site's own blog-level
	 * connection, already established for any other Jetpack feature to
	 * work) since this is a public, unauthenticated-as-a-person read — only
	 * the actual Like/Comment write below needs the acting user's own
	 * linked account.
	 *
	 * Cached by permalink (a failed/ineligible resolution is cached too, so
	 * a repeatedly-viewed non-eligible post never re-attempts the same
	 * lookup) — same "cache the negative result too" precedent
	 * Daymark_Subscription_Oembed/Opengraph already established.
	 *
	 * @param string $permalink Already URL-guard-checked, http(s) permalink.
	 * @return array{site_id: int, post_id: int}|null Null when unavailable or unresolvable.
	 */
	public static function resolve_origin( string $permalink ) {
		if ( ! self::is_available() ) {
			return null;
		}

		$cache_key = 'daymark_jetpack_origin_' . md5( $permalink );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$result = self::fetch_origin( $permalink );

		set_transient(
			$cache_key,
			null === $result ? array() : $result,
			(int) apply_filters( 'daymark_jetpack_engagement_cache_ttl', HOUR_IN_SECONDS )
		);

		return $result;
	}

	/**
	 * The actual, uncached lookup resolve_origin() wraps.
	 *
	 * @param string $permalink Already URL-guard-checked, http(s) permalink.
	 * @return array{site_id: int, post_id: int}|null
	 */
	private static function fetch_origin( string $permalink ) {
		$host = (string) wp_parse_url( $permalink, PHP_URL_HOST );
		$slug = self::extract_slug( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );

		if ( '' === $host || '' === $slug ) {
			return null;
		}

		try {
			$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
				sprintf( 'sites/%s/posts/slug:%s', rawurlencode( $host ), rawurlencode( $slug ) ),
				'v1.1'
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['ID'] ) || empty( $body['site_ID'] ) ) {
			return null;
		}

		return array(
			'site_id' => absint( $body['site_ID'] ),
			'post_id' => absint( $body['ID'] ),
		);
	}

	/**
	 * Best-effort slug extraction from a permalink's own path — the last
	 * non-empty path segment, matching WordPress's own most common permalink
	 * structures (`/%postname%/`, `/%year%/%monthnum%/%day%/%postname%/`).
	 * A permalink using a plain `?p=123` query structure (no slug in the
	 * path at all) simply won't resolve here, falling straight back to the
	 * classic path — an accepted, narrow gap rather than a second detection
	 * mechanism.
	 *
	 * @param string $path The permalink's own URL path.
	 * @return string
	 */
	private static function extract_slug( string $path ): string {
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );

		return sanitize_title( (string) end( $segments ) );
	}

	/**
	 * Like a post via WordPress.com's own real Like API, as the current user:
	 * POST `/sites/{site}/posts/{post}/likes/new` (v1.1) — confirmed against
	 * wp-calypso's own REST client (see unlike()).
	 *
	 * @param int $site_id WordPress.com site ID (from resolve_origin()).
	 * @param int $post_id WordPress.com post ID (from resolve_origin()).
	 * @return true|WP_Error
	 */
	public static function like( int $site_id, int $post_id ) {
		return self::write_action( sprintf( 'sites/%d/posts/%d/likes/new', $site_id, $post_id ) );
	}

	/**
	 * Undo a Like via WordPress.com's own API: a POST to
	 * `/sites/{site}/posts/{post}/likes/mine/delete` on the v1.1 REST API.
	 * Confirmed against WordPress.com's own first-party client, Automattic/
	 * wp-calypso (`packages/calypso-e2e/src/rest-api-client.ts`,
	 * `postLikeAction()`), which likes via POST `.../likes/new` and unlikes
	 * via POST `.../likes/mine/delete`, both at apiVersion 1.1 — the same
	 * paths like() and this method use.
	 *
	 * @param int $site_id WordPress.com site ID.
	 * @param int $post_id WordPress.com post ID.
	 * @return true|WP_Error
	 */
	public static function unlike( int $site_id, int $post_id ) {
		return self::write_action( sprintf( 'sites/%d/posts/%d/likes/mine/delete', $site_id, $post_id ) );
	}

	/**
	 * Post a comment via WordPress.com's own real Comment API, as the
	 * current user: POST `/sites/{site}/posts/{post}/replies/new` with a
	 * `content` field — confirmed against Jetpack's own endpoint definition
	 * (`json-endpoints/class.wpcom-json-api-update-comment-endpoint.php`)
	 * and wp-calypso's REST client (`createComment()`, apiVersion 1.1).
	 *
	 * @param int    $site_id WordPress.com site ID.
	 * @param int    $post_id WordPress.com post ID.
	 * @param string $text    Comment text.
	 * @return true|WP_Error
	 */
	public static function comment( int $site_id, int $post_id, string $text ) {
		return self::write_action(
			sprintf( 'sites/%d/posts/%d/replies/new', $site_id, $post_id ),
			array( 'content' => $text )
		);
	}

	/**
	 * Shared POST call to WordPress.com's API "as the current user" —
	 * `Client::wpcom_json_api_request_as_user()` resolves the acting
	 * WordPress.com identity from `get_current_user_id()` internally, which
	 * is always the authenticated Daymark user for any caller of this class.
	 *
	 * @param string                    $path WordPress.com API path (e.g. `sites/{id}/posts/{id}/likes/new`).
	 * @param array<string, mixed>|null $body POST body fields, if any.
	 * @return true|WP_Error
	 */
	private static function write_action( string $path, ?array $body = null ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'daymark_jetpack_unavailable',
				__( 'Jetpack is not available.', 'daymark' )
			);
		}

		try {
			$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_user(
				$path,
				'v1.1',
				array( 'method' => 'POST' ),
				$body,
				// WordPress.com's v1.1 like/reply endpoints live under
				// `rest`, not `wpcom` (the wpcom/v2 base) — the Client
				// builds `{base}/{base_api_path}/v{version}/{path}`,
				// confirmed against Automattic/jetpack's connection package.
				'rest'
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'daymark_jetpack_request_failed', $e->getMessage() );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body_data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$message   = is_array( $body_data ) && ! empty( $body_data['message'] )
				? sanitize_text_field( (string) $body_data['message'] )
				: __( 'WordPress.com declined the request.', 'daymark' );

			return new WP_Error( 'daymark_jetpack_rejected', $message, array( 'status' => $code ) );
		}

		return true;
	}

	/**
	 * Whether a user has Jetpack-liked a given subscription post — the
	 * "have I already done this" signal for a native Like, since it creates
	 * no local Mark to key off of the way the classic path's
	 * `liked_mark_id` does.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Subscription post ID.
	 * @return bool
	 */
	public static function is_liked( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		return in_array( $post_id, self::liked_ids( $user_id ), true );
	}

	/**
	 * Records that a user has Jetpack-liked a subscription post. A no-op if
	 * already recorded.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Subscription post ID.
	 * @return void
	 */
	public static function mark_liked( int $user_id, int $post_id ): void {
		if ( $user_id <= 0 || $post_id <= 0 || self::is_liked( $user_id, $post_id ) ) {
			return;
		}

		add_user_meta( $user_id, self::META_LIKE, $post_id, false );
	}

	/**
	 * Clears a user's Jetpack-like record for a subscription post.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Subscription post ID.
	 * @return void
	 */
	public static function unmark_liked( int $user_id, int $post_id ): void {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, self::META_LIKE, $post_id );
	}

	/**
	 * Whether a user has already sent a Jetpack-native comment to a
	 * subscription post — write-once, unlike Like; there's no "undo" action
	 * for a sent comment.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Subscription post ID.
	 * @return bool
	 */
	public static function is_commented( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		$raw = get_user_meta( $user_id, self::META_COMMENT, false );

		return is_array( $raw ) && in_array( $post_id, array_map( 'absint', $raw ), true );
	}

	/**
	 * Records that a user has sent a Jetpack-native comment to a
	 * subscription post. A no-op if already recorded.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Subscription post ID.
	 * @return void
	 */
	public static function mark_commented( int $user_id, int $post_id ): void {
		if ( $user_id <= 0 || $post_id <= 0 || self::is_commented( $user_id, $post_id ) ) {
			return;
		}

		add_user_meta( $user_id, self::META_COMMENT, $post_id, false );
	}

	/**
	 * All subscription post IDs a user has Jetpack-liked.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	private static function liked_ids( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_LIKE, false );

		return is_array( $raw ) ? array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ) : array();
	}
}
