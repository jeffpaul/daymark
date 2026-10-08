<?php
/**
 * Whether a Like on a subscription post can actually reach its origin, and
 * whether an engagement Mark's Webmention was actually delivered.
 *
 * Before this, Comment pre-checked where it could deliver
 * (Daymark_Comment_Delivery::resolve_comment_target()) but Like never did:
 * tapping it always created a local Like Mark, even on an origin nothing
 * could notify — a Like the origin's author would never see. A Like is now
 * only offered when one of three real delivery routes exists:
 *
 * 1. Jetpack-native (issue #391): the current user has linked their own
 *    WordPress.com account and the origin resolves via WordPress.com's API.
 * 2. ActivityPub (issue #439): the ActivityPub plugin (>= 8.1.0) is active,
 *    the current user is an enabled ActivityPub author, and the origin
 *    permalink resolves to an ActivityPub object (Daymark_ActivityPub_Engagement).
 * 3. Webmention: the local Webmention plugin is active AND the origin
 *    advertises a Webmention endpoint.
 * 4. Bridgy Fed (issue #441): this site is bridged (Daymark_Bridgy_Fed), the
 *    Webmention plugin is active, and the origin is a fediverse or Bluesky
 *    post that doesn't take Webmentions itself.
 *
 * The Jetpack and Webmention signals come from Daymark_Comment_Delivery's
 * existing, cached permalink discovery; the ActivityPub signal from
 * Daymark_ActivityPub_Engagement's own cached resolution (a signed
 * ActivityStreams GET, a different request from the HTML page fetch).
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
		return self::page_mechanisms_exist( $user_id )
			|| Daymark_ActivityPub_Engagement::available_for_user( $user_id );
	}

	/**
	 * Whether a route that reads the origin's page signals (Jetpack,
	 * Webmention) exists — i.e. whether fetching those signals is worth it.
	 *
	 * @param int $user_id User ID; 0 for the current user.
	 * @return bool
	 */
	private static function page_mechanisms_exist( int $user_id = 0 ): bool {
		return Daymark_Jetpack_Engagement::current_user_connected( $user_id )
			|| Daymark_Webmention::can_send();
	}

	/**
	 * Resolve, live if needed, whether a Like on this subscription post can
	 * reach its origin, and by which route(s). One cached permalink fetch at
	 * most, shared with the Comment action.
	 *
	 * @param int $subscription_post_id A `daymark_sub_post` post ID.
	 * @return array{available: bool, method: string, jetpack: bool, activitypub: bool, webmention: bool, bridgy_fed: bool}
	 */
	public static function resolve( int $subscription_post_id ): array {
		if ( ! self::mechanisms_exist() ) {
			return self::result( false, false, false, false );
		}

		$activitypub = false;

		if ( Daymark_ActivityPub_Engagement::available_for_user() ) {
			$permalink   = esc_url_raw( (string) get_post_meta( $subscription_post_id, 'permalink', true ) );
			$activitypub = '' !== $permalink && null !== Daymark_ActivityPub_Engagement::resolve_target( $permalink );
		}

		$signals = self::page_mechanisms_exist()
			? Daymark_Comment_Delivery::origin_signals_for_post( $subscription_post_id )
			: array();

		if ( is_wp_error( $signals ) ) {
			return self::result( false, $activitypub, false, false );
		}

		return self::evaluate( $signals, $activitypub );
	}

	/**
	 * Resolve, live if needed, whether a Like on any http(s) URL can reach
	 * it, and by which route(s) — resolve() for a page that isn't a cached
	 * subscription post (the bookmarklet, on a page someone is reading
	 * outside Daymark). Same cached discovery, so a URL already looked up
	 * costs nothing.
	 *
	 * @param string $url Target URL.
	 * @return array{available: bool, method: string, jetpack: bool, activitypub: bool, webmention: bool, bridgy_fed: bool}
	 */
	public static function resolve_url( string $url ): array {
		if ( '' === $url || ! self::mechanisms_exist() ) {
			return self::result( false, false, false, false );
		}

		$activitypub = Daymark_ActivityPub_Engagement::available_for_user()
			&& null !== Daymark_ActivityPub_Engagement::resolve_target( $url );

		$signals = self::page_mechanisms_exist()
			? Daymark_Comment_Delivery::origin_signals_for_url( $url )
			: array();

		if ( is_wp_error( $signals ) ) {
			return self::result( false, $activitypub, false, false );
		}

		return self::evaluate( $signals, $activitypub );
	}

	/**
	 * Like a URL with a local Like Mark: an ActivityPub `Like` when that
	 * route exists (the Mark's own Webmention is then suppressed, so the
	 * origin receives one Like), otherwise the Mark's Webmention, sent
	 * directly or through Bridgy Fed. Shared by the subscription-post Like
	 * route (after its Jetpack attempt) and the bookmarklet.
	 *
	 * Never creates a Like Mark nothing can deliver. Reuses an existing
	 * published Like Mark for the same URL instead of creating a second one.
	 *
	 * @param string               $permalink    The liked post's URL (already validated).
	 * @param string               $title        The liked post's title, for the Mark's caption; '' for none.
	 * @param array<string, mixed> $availability A resolve()/resolve_url() result.
	 * @return array{method: string, liked: bool, mark_id: int, delivery: string}|WP_Error
	 */
	public static function publish_like( string $permalink, string $title, array $availability ) {
		$existing = self::own_mark_id( '_daymark_like_of', $permalink );

		if ( $existing > 0 ) {
			return array(
				'method'   => absint( get_post_meta( $existing, Daymark_ActivityPub_Engagement::OUTBOX_META, true ) ) > 0 ? 'activitypub' : 'classic',
				'liked'    => true,
				'mark_id'  => $existing,
				'delivery' => self::like_state( false, $existing, $permalink ),
			);
		}

		// ActivityPub route (issue #439): queue a real `Like` through the
		// ActivityPub plugin's outbox. The local Like Mark is still published
		// below (the liked-state UI reads it), but its Webmention is
		// suppressed so the origin receives exactly one Like. 0 when the
		// route isn't available or the queue failed — then Webmention alone.
		$outbox_id = ! empty( $availability['activitypub'] )
			? Daymark_ActivityPub_Engagement::like( get_current_user_id(), $permalink )
			: 0;

		// Without an ActivityPub, Webmention, or Bridgy Fed route the
		// origin's author would never see the Like. A Bridgy Fed Like is an
		// ordinary Like Mark; Daymark_Bridgy_Fed marks it for Bridgy Fed
		// when it's published.
		if ( 0 === $outbox_id && empty( $availability['webmention'] ) && empty( $availability['bridgy_fed'] ) ) {
			return new WP_Error(
				'daymark_like_undeliverable',
				__( "This post's site can't receive a Like from Daymark.", 'daymark' ),
				array( 'status' => 422 )
			);
		}

		$caption = sprintf(
			/* translators: %s: title of the liked post */
			__( 'Liked "%s"', 'daymark' ),
			'' !== $title ? $title : $permalink
		);

		$mark_id = Daymark_Plugin::instance()->publisher->publish(
			array(
				'caption'        => $caption,
				'primary_type'   => 'note',
				'status'         => 'publish',
				'ai_assist_used' => false,
				'like_of'        => $permalink,
			)
		);

		if ( is_wp_error( $mark_id ) ) {
			// Don't leave a queued Like with no local record to undo it from.
			if ( $outbox_id > 0 ) {
				Daymark_ActivityPub_Engagement::undo_outbox_item( $outbox_id );
			}

			return $mark_id;
		}

		if ( $outbox_id > 0 ) {
			Daymark_ActivityPub_Engagement::attach_to_mark( (int) $mark_id, $outbox_id, 'Like' );
		}

		return array(
			'method'   => $outbox_id > 0 ? 'activitypub' : 'classic',
			'liked'    => true,
			'mark_id'  => (int) $mark_id,
			'delivery' => self::like_state( false, (int) $mark_id, $permalink ),
		);
	}

	/**
	 * The ID of the current user's own Mark, if any, carrying the given
	 * target-URL meta value (a published or draft Like, Reblog, or reply).
	 * A plain per-call lookup, fine at personal-site scale.
	 *
	 * @param string $meta_key One of '_daymark_in_reply_to', '_daymark_like_of', '_daymark_repost_of'.
	 * @param string $url      The target URL to match.
	 * @return int Mark post ID, or 0 when absent or no match.
	 */
	public static function own_mark_id( string $meta_key, string $url ): int {
		if ( '' === $url ) {
			return 0;
		}

		$found = get_posts(
			array(
				// Both types: a Like Mark lives on its own post type (see
				// Daymark_Like_Visibility::POST_TYPE); a legacy one may not
				// have been migrated off 'post' yet.
				'post_type'      => array( 'post', Daymark_Like_Visibility::POST_TYPE ),
				'post_status'    => array( 'publish', 'draft' ),
				'author'         => get_current_user_id(),
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match lookup on a single-value meta key, no alternative query shape.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match is the point; personal-site scale.
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $found ) ? absint( $found[0] ) : 0;
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

		$unknown = false;

		if ( Daymark_ActivityPub_Engagement::available_for_user() ) {
			$target = Daymark_ActivityPub_Engagement::cached_target( $permalink );

			if ( is_array( $target ) ) {
				return true;
			}

			$unknown = null === $target;
		}

		if ( self::page_mechanisms_exist() ) {
			$signals = Daymark_Comment_Delivery::cached_origin_signals( $permalink );

			if ( null === $signals ) {
				$unknown = true;
			} elseif ( self::evaluate( $signals, false )['available'] ) {
				return true;
			}
		}

		return $unknown ? null : false;
	}

	/**
	 * Which routes a set of origin signals supports for the current user.
	 *
	 * @param array<string, mixed> $signals     Daymark_Comment_Delivery origin signals.
	 * @param bool                 $activitypub Whether the ActivityPub route resolved.
	 * @return array{available: bool, method: string, jetpack: bool, activitypub: bool, webmention: bool, bridgy_fed: bool}
	 */
	private static function evaluate( array $signals, bool $activitypub ): array {
		$jetpack    = (int) ( $signals['jetpack_site_id'] ?? 0 ) > 0
			&& (int) ( $signals['jetpack_post_id'] ?? 0 ) > 0
			&& Daymark_Jetpack_Engagement::current_user_connected();
		$webmention = '' !== (string) ( $signals['webmention_endpoint'] ?? '' )
			&& Daymark_Webmention::can_send();
		$bridgy_fed = Daymark_Bridgy_Fed::available() && Daymark_Bridgy_Fed::target_needs_bridge( $signals );

		return self::result( $jetpack, $activitypub, $webmention, $bridgy_fed );
	}

	/**
	 * Shape a resolve()/evaluate() result. `method` is the route a Like
	 * would take first: Jetpack, then ActivityPub, then Webmention, then
	 * Bridgy Fed.
	 *
	 * @param bool $jetpack     Jetpack-native route available.
	 * @param bool $activitypub ActivityPub route available.
	 * @param bool $webmention  Webmention route available.
	 * @param bool $bridgy_fed  Bridgy Fed route available.
	 * @return array{available: bool, method: string, jetpack: bool, activitypub: bool, webmention: bool, bridgy_fed: bool}
	 */
	private static function result( bool $jetpack, bool $activitypub, bool $webmention, bool $bridgy_fed ): array {
		$method = '';

		if ( $jetpack ) {
			$method = 'jetpack';
		} elseif ( $activitypub ) {
			$method = 'activitypub';
		} elseif ( $webmention ) {
			$method = 'webmention';
		} elseif ( $bridgy_fed ) {
			$method = 'bridgy_fed';
		}

		return array(
			'available'   => $jetpack || $activitypub || $webmention || $bridgy_fed,
			'method'      => $method,
			'jetpack'     => $jetpack,
			'activitypub' => $activitypub,
			'webmention'  => $webmention,
			'bridgy_fed'  => $bridgy_fed,
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
	 * A Like Mark that queued an ActivityPub Like reports that activity's
	 * state instead of its (suppressed) Webmention's.
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

		$activitypub = Daymark_ActivityPub_Engagement::delivery_state( $mark_id );

		if ( '' !== $activitypub ) {
			return $activitypub;
		}

		// Through Bridgy Fed, the Webmention goes to Bridgy Fed, not the
		// origin, so that's the target whose delivery to report.
		if ( Daymark_Bridgy_Fed::routes_mark( $mark_id ) ) {
			return self::webmention_state( $mark_id, Daymark_Bridgy_Fed::TARGET );
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
