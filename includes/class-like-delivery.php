<?php
/**
 * Whether a Like on a subscription post can actually reach its origin, and
 * whether an engagement Mark's Webmention was actually delivered.
 *
 * Before this, Comment pre-checked where it could deliver
 * (Daymark_Comment_Delivery::resolve_comment_target()) but Like never did:
 * tapping it always created a local Like Mark, even on an origin nothing
 * could notify — a Like the origin's author would never see. A Like is now
 * only offered when one of two real delivery routes exists:
 *
 * 1. Jetpack-native (issue #391): the current user has linked their own
 *    WordPress.com account and the origin resolves via WordPress.com's API.
 * 2. Webmention: the local Webmention plugin is active AND the origin
 *    advertises a Webmention endpoint.
 *
 * Both signals come from Daymark_Comment_Delivery's existing, cached
 * permalink discovery — never a second fetch of the same page.
 *
 * Delivery state reads the Webmention plugin's own post meta (verified
 * against pfefferle/wordpress-webmention's `includes/class-sender.php`):
 * `_mentionme` is added on publish and removed once `do_pings` processes
 * the post (re-added on a 5xx retry); `_webmentioned` is the list of target
 * URLs a receiver accepted (HTTP < 400); `_webmention_content_hash` is
 * written after every send attempt.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Like availability and engagement delivery state.
 */
class Daymark_Like_Delivery {

	/**
	 * Queued with the Webmention plugin, not yet processed.
	 *
	 * @var string
	 */
	public const STATE_PENDING = 'pending';

	/**
	 * The origin (or WordPress.com) accepted it.
	 *
	 * @var string
	 */
	public const STATE_SENT = 'sent';

	/**
	 * A send attempt ran and this target wasn't accepted.
	 *
	 * @var string
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * Nothing has tried to send it (e.g. no Webmention plugin).
	 *
	 * @var string
	 */
	public const STATE_NOT_SENT = 'not_sent';

	/**
	 * Whether any Like delivery mechanism exists for this user at all —
	 * knowable with zero network cost. When false, no origin could ever
	 * receive a Like from here, so the client hides the icon outright.
	 *
	 * @param int $user_id User ID; 0 for the current user.
	 * @return bool
	 */
	public static function mechanisms_exist( int $user_id = 0 ): bool {
		return Daymark_Jetpack_Engagement::current_user_connected( $user_id )
			|| Daymark_Plugin_Detector::is_active( 'webmention' );
	}

	/**
	 * Resolve, live if needed, whether a Like on this subscription post can
	 * reach its origin, and by which route(s). One cached permalink fetch at
	 * most, shared with the Comment action.
	 *
	 * @param int $subscription_post_id A `daymark_sub_post` post ID.
	 * @return array{available: bool, method: string, jetpack: bool, webmention: bool}
	 */
	public static function resolve( int $subscription_post_id ): array {
		if ( ! self::mechanisms_exist() ) {
			return self::result( false, false );
		}

		$signals = Daymark_Comment_Delivery::origin_signals_for_post( $subscription_post_id );

		if ( is_wp_error( $signals ) ) {
			return self::result( false, false );
		}

		return self::evaluate( $signals );
	}

	/**
	 * Like availability from cache only, for a Timeline row: false when no
	 * mechanism exists at all, null when the origin hasn't been looked up
	 * yet (the client resolves it lazily), else the cached answer.
	 *
	 * @param string $permalink Subscription post permalink.
	 * @return bool|null
	 */
	public static function cached_availability( string $permalink ): ?bool {
		if ( ! self::mechanisms_exist() ) {
			return false;
		}

		$signals = Daymark_Comment_Delivery::cached_origin_signals( $permalink );

		if ( null === $signals ) {
			return null;
		}

		return self::evaluate( $signals )['available'];
	}

	/**
	 * Which routes a set of origin signals supports for the current user.
	 *
	 * @param array<string, mixed> $signals Daymark_Comment_Delivery origin signals.
	 * @return array{available: bool, method: string, jetpack: bool, webmention: bool}
	 */
	private static function evaluate( array $signals ): array {
		$jetpack    = (int) ( $signals['jetpack_site_id'] ?? 0 ) > 0
			&& (int) ( $signals['jetpack_post_id'] ?? 0 ) > 0
			&& Daymark_Jetpack_Engagement::current_user_connected();
		$webmention = '' !== (string) ( $signals['webmention_endpoint'] ?? '' )
			&& Daymark_Plugin_Detector::is_active( 'webmention' );

		return self::result( $jetpack, $webmention );
	}

	/**
	 * Shape a resolve()/evaluate() result.
	 *
	 * @param bool $jetpack    Jetpack-native route available.
	 * @param bool $webmention Webmention route available.
	 * @return array{available: bool, method: string, jetpack: bool, webmention: bool}
	 */
	private static function result( bool $jetpack, bool $webmention ): array {
		return array(
			'available'  => $jetpack || $webmention,
			'method'     => $jetpack ? 'jetpack' : ( $webmention ? 'webmention' : '' ),
			'jetpack'    => $jetpack,
			'webmention' => $webmention,
		);
	}

	/**
	 * Whether a local engagement Mark's Webmention to $target was actually
	 * delivered, read from the Webmention plugin's own post meta.
	 *
	 * @param int    $mark_id Local Like/Comment Mark ID.
	 * @param string $target  The origin permalink it notifies.
	 * @return string One of the STATE_* constants; '' when $mark_id is 0.
	 */
	public static function webmention_state( int $mark_id, string $target ): string {
		if ( $mark_id <= 0 || '' === $target ) {
			return '';
		}

		$mentioned = get_post_meta( $mark_id, '_webmentioned', true );
		$wanted    = untrailingslashit( $target );

		foreach ( is_array( $mentioned ) ? $mentioned : array() as $url ) {
			if ( is_string( $url ) && untrailingslashit( $url ) === $wanted ) {
				return self::STATE_SENT;
			}
		}

		if ( '' !== (string) get_post_meta( $mark_id, '_mentionme', true ) ) {
			return self::STATE_PENDING;
		}

		if ( metadata_exists( 'post', $mark_id, '_webmention_content_hash' ) ) {
			return self::STATE_FAILED;
		}

		return self::STATE_NOT_SENT;
	}

	/**
	 * Delivery state of the current user's Like on a subscription post.
	 *
	 * @param bool   $jetpack_liked Whether it was a Jetpack-native like (a successful WordPress.com call).
	 * @param int    $mark_id       Classic Like Mark ID, or 0.
	 * @param string $permalink     Origin permalink.
	 * @return string A STATE_* constant, or '' when not liked.
	 */
	public static function like_state( bool $jetpack_liked, int $mark_id, string $permalink ): string {
		if ( $jetpack_liked ) {
			return self::STATE_SENT;
		}

		return self::webmention_state( $mark_id, $permalink );
	}

	/**
	 * Delivery state of the current user's Comment on a subscription post.
	 * Only knowable for the Jetpack route (a successful WordPress.com call)
	 * and the Webmention route (a local Mark); a native REST comment leaves
	 * no local record, so reports ''.
	 *
	 * @param bool   $jetpack_commented Whether a Jetpack-native comment was sent.
	 * @param int    $mark_id           Webmention-route reply Mark ID, or 0.
	 * @param string $permalink         Origin permalink.
	 * @return string A STATE_* constant, or ''.
	 */
	public static function comment_state( bool $jetpack_commented, int $mark_id, string $permalink ): string {
		if ( $jetpack_commented ) {
			return self::STATE_SENT;
		}

		return self::webmention_state( $mark_id, $permalink );
	}
}
