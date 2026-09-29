<?php
/**
 * Real ActivityPub Like/Announce activities for a subscribed post whose
 * origin is a fediverse object (issue #439).
 *
 * Since a Like Mark moved onto its own hidden `daymark_like` post type, the
 * ActivityPub plugin no longer federates it (it only federates the post
 * types in `activitypub_support_post_types`), and it never mapped a local
 * `u-like-of` post to a `Like` anyway. So a Like on a Mastodon status, or on
 * a fediverse-only WordPress site with no Webmention receiver, reached
 * nobody. This class rides the ActivityPub plugin's own public outbox API
 * instead — no ActivityPub protocol code (signing, inbox discovery,
 * delivery) lives in Daymark:
 *
 * - resolve_target() runs the permalink through
 *   Daymark_Subscription_Url_Guard, then `\Activitypub\Http::get_remote_object()`
 *   (a signed GET with `Accept: application/activity+json`), and keeps an
 *   object that has an `id` and an `attributedTo`. Cached by permalink,
 *   negative results included, like Daymark_Jetpack_Engagement::resolve_origin().
 * - like() builds a `Like` (`object` = the resolved id, `to` = its author)
 *   and queues it with `\Activitypub\add_to_outbox()` at private visibility,
 *   so the plugin's dispatcher delivers it to the author's inbox only.
 * - Reblog: when a Mark carrying `_daymark_repost_of` goes live and that URL
 *   resolves, an `Announce` is queued too (see announce() for its
 *   addressing). The Reblog Mark itself still federates as its own Create.
 * - Undo: trashing (or deleting) the Mark queues an `Undo` of whichever
 *   activity it carries, via `\Activitypub\Collection\Outbox::undo()`.
 * - Exactly one delivery: a Mark carrying an outbox ID (OUTBOX_META) stops
 *   the Webmention plugin sending that same like/repost target — see
 *   suppressed_webmention_target() and Daymark_Microformats::add_webmention_targets().
 *
 * Gated on the ActivityPub plugin being active at >= MIN_VERSION (the Like
 * outbox handler and `liked` collection both date to 8.1.0) and on the
 * acting user being an enabled ActivityPub author: `add_to_outbox()` would
 * otherwise quietly send from the blog actor, which Daymark never wants.
 *
 * Every call is guarded (class_exists/function_exists plus try/catch), and
 * every failure means "no ActivityPub route" — Like and Reblog fall back to
 * whatever else is available, never break. The plugin API used here comes
 * from reading Automattic/wordpress-activitypub 9.3.1's source (see issue
 * #439); NOT independently run against a live install.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ActivityPub route detection, target resolution, and Like/Announce/Undo.
 */
class Daymark_ActivityPub_Engagement {

	/**
	 * Minimum ActivityPub plugin version (Like outbox handler, `liked` collection).
	 *
	 * @var string
	 */
	public const MIN_VERSION = '8.1.0';

	/**
	 * Post meta on a Like/Reblog Mark: the ActivityPub plugin outbox post ID
	 * of the Like/Announce queued for it. Its presence is what suppresses the
	 * Webmention for the same target.
	 *
	 * @var string
	 */
	public const OUTBOX_META = '_daymark_activitypub_outbox_id';

	/**
	 * Post meta: 'Like' or 'Announce' — which activity OUTBOX_META holds.
	 *
	 * @var string
	 */
	public const TYPE_META = '_daymark_activitypub_activity_type';

	/**
	 * Post meta: the outbox post ID of the Undo queued when the Mark was
	 * trashed/deleted. Also the "already undone" guard.
	 *
	 * @var string
	 */
	public const UNDO_META = '_daymark_activitypub_undo_id';

	/**
	 * Post meta: 'sent' or 'failed', from the plugin's `activitypub_sent_to_inbox` action.
	 *
	 * @var string
	 */
	public const DELIVERY_META = '_daymark_activitypub_delivery';

	/**
	 * ActivityStreams public collection.
	 *
	 * @var string
	 */
	public const PUBLIC_COLLECTION = 'https://www.w3.org/ns/activitystreams#Public';

	/**
	 * Object types a Like/Announce can target — content, never an actor or collection.
	 *
	 * @var string[]
	 */
	private const OBJECT_TYPES = array( 'Note', 'Article', 'Page', 'Question', 'Image', 'Video', 'Audio', 'Event' );

	/**
	 * ActivityStreams actor types, for picking an `attributedTo` entry.
	 *
	 * @var string[]
	 */
	private const ACTOR_TYPES = array( 'Person', 'Service', 'Application', 'Group', 'Organization' );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Reblog → Announce. Both hooks, since neither alone sees every
		// publish: on the inline create path `transition_post_status` fires
		// inside wp_insert_post() before the Mark's meta exists, while
		// `daymark_published` fires after it; on the deferred path (and a
		// draft published later) the order is the other way round. The
		// OUTBOX_META check in maybe_announce() makes the pair idempotent.
		add_action( 'daymark_published', array( __CLASS__, 'on_daymark_published' ), 20, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 20, 3 );

		add_action( 'trashed_post', array( __CLASS__, 'maybe_undo' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_undo' ) );

		add_action( 'activitypub_sent_to_inbox', array( __CLASS__, 'record_inbox_result' ), 10, 5 );
	}

	/**
	 * Whether the ActivityPub plugin is active at a version whose outbox API
	 * this class relies on. Zero network cost.
	 *
	 * @return bool
	 */
	public static function plugin_available(): bool {
		$detected = Daymark_Plugin_Detector::matches(
			array(
				'slugs'     => array( 'activitypub' ),
				'constants' => array( 'ACTIVITYPUB_PLUGIN_VERSION' ),
				'functions' => array( 'Activitypub\\add_to_outbox' ),
			)
		);

		if ( ! $detected || ! defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
			return false;
		}

		if ( ! version_compare( (string) constant( 'ACTIVITYPUB_PLUGIN_VERSION' ), self::MIN_VERSION, '>=' ) ) {
			return false;
		}

		return function_exists( 'Activitypub\\add_to_outbox' )
			&& function_exists( 'Activitypub\\user_can_activitypub' )
			&& class_exists( 'Activitypub\\Activity\\Activity' )
			&& class_exists( 'Activitypub\\Http' );
	}

	/**
	 * Whether a user can send ActivityPub activities as themselves. Never
	 * falls back to the blog actor: a user who isn't an enabled ActivityPub
	 * author gets no ActivityPub route at all.
	 *
	 * @param int $user_id User ID; 0 for the current user.
	 * @return bool
	 */
	public static function available_for_user( int $user_id = 0 ): bool {
		if ( ! self::plugin_available() ) {
			return false;
		}

		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		try {
			return (bool) \Activitypub\user_can_activitypub( $user_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Resolve a permalink to an ActivityPub object Daymark can Like/Announce,
	 * live if not cached. One signed GET at most per permalink per TTL.
	 *
	 * @param string $permalink Subscription post permalink.
	 * @return array{id: string, attributed_to: string}|null
	 */
	public static function resolve_target( string $permalink ): ?array {
		if ( '' === $permalink || ! self::plugin_available() ) {
			return null;
		}

		$cached = self::cached_target( $permalink );

		if ( null !== $cached ) {
			return false === $cached ? null : $cached;
		}

		$result = self::fetch_target( $permalink );

		set_transient(
			self::cache_key( $permalink ),
			null === $result ? array() : $result,
			(int) apply_filters( 'daymark_activitypub_engagement_cache_ttl', HOUR_IN_SECONDS )
		);

		return $result;
	}

	/**
	 * The cached resolution for a permalink — never a fetch.
	 *
	 * @param string $permalink Subscription post permalink.
	 * @return array{id: string, attributed_to: string}|false|null The target; false for a cached "not a fediverse object"; null when not looked up yet.
	 */
	public static function cached_target( string $permalink ) {
		if ( '' === $permalink ) {
			return null;
		}

		$cached = get_transient( self::cache_key( $permalink ) );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		return empty( $cached ) ? false : $cached;
	}

	/**
	 * Transient key for a permalink's resolution.
	 *
	 * @param string $permalink Permalink.
	 * @return string
	 */
	private static function cache_key( string $permalink ): string {
		return 'daymark_ap_target_' . md5( $permalink );
	}

	/**
	 * The uncached lookup resolve_target() wraps.
	 *
	 * @param string $permalink Permalink.
	 * @return array{id: string, attributed_to: string}|null
	 */
	private static function fetch_target( string $permalink ): ?array {
		$scheme = strtolower( (string) ( wp_parse_url( $permalink, PHP_URL_SCHEME ) ?? '' ) );

		// Same guard Daymark_Comment_Delivery applies to this permalink
		// before its own fetch, even though the plugin's own GET goes
		// through wp_safe_remote_get() too.
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || true !== Daymark_Subscription_Url_Guard::check( $permalink ) ) {
			return null;
		}

		try {
			$object = \Activitypub\Http::get_remote_object( $permalink );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $object ) || ! is_array( $object ) ) {
			return null;
		}

		$id   = isset( $object['id'] ) && is_string( $object['id'] ) ? esc_url_raw( $object['id'] ) : '';
		$type = $object['type'] ?? '';
		$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;

		if ( '' === $id || ! in_array( $type, self::OBJECT_TYPES, true ) ) {
			return null;
		}

		$author = self::first_actor_id( $object['attributedTo'] ?? null );

		if ( '' === $author ) {
			return null;
		}

		return array(
			'id'            => $id,
			'attributed_to' => $author,
		);
	}

	/**
	 * The first actor ID in an `attributedTo` value, which may be a string,
	 * an object with an `id`, or a list of either.
	 *
	 * @param mixed $value The raw `attributedTo`.
	 * @return string Actor ID URL, or ''.
	 */
	private static function first_actor_id( $value ): string {
		if ( is_string( $value ) ) {
			return esc_url_raw( $value );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( isset( $value['id'] ) && is_string( $value['id'] ) ) {
			return esc_url_raw( $value['id'] );
		}

		foreach ( $value as $entry ) {
			// A typed entry that isn't an actor (e.g. a Link) is skipped.
			if ( is_array( $entry ) && isset( $entry['type'] ) && ! in_array( $entry['type'], self::ACTOR_TYPES, true ) ) {
				continue;
			}

			$id = self::first_actor_id( $entry );

			if ( '' !== $id ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * Queue a `Like` of a resolved permalink from a user's own actor.
	 *
	 * Private visibility, addressed `to` the object's author only: the
	 * plugin's dispatcher then delivers it to that author's inbox and skips
	 * both follower fan-out and relays — a Like is a signal to the author,
	 * not a post for our followers.
	 *
	 * @param int    $user_id   Acting user.
	 * @param string $permalink Subscription post permalink.
	 * @return int The outbox post ID, or 0 when not queued.
	 */
	public static function like( int $user_id, string $permalink ): int {
		if ( ! self::available_for_user( $user_id ) ) {
			return 0;
		}

		$target = self::resolve_target( $permalink );

		if ( null === $target ) {
			return 0;
		}

		return self::queue( 'Like', $user_id, $target, array( $target['attributed_to'] ), array() );
	}

	/**
	 * Queue an `Announce` (a boost) of a resolved permalink.
	 *
	 * Addressing: `to` the author, `cc` the public collection — an unlisted
	 * boost. Public addressing is what makes the origin (Mastodon et al.)
	 * accept it as a real, counted boost rather than a direct message; `cc`
	 * rather than `to` keeps it off public timelines. Queued at private
	 * visibility with no followers collection in the audience, so the
	 * plugin delivers it to the author only: the Reblog Mark already reaches
	 * our followers as its own Create, and a second, boost-shaped copy in
	 * their timelines would show them the same reblog twice.
	 *
	 * @param int    $user_id   Acting user.
	 * @param string $permalink Reblogged post permalink.
	 * @return int The outbox post ID, or 0 when not queued.
	 */
	public static function announce( int $user_id, string $permalink ): int {
		if ( ! self::available_for_user( $user_id ) ) {
			return 0;
		}

		$target = self::resolve_target( $permalink );

		if ( null === $target ) {
			return 0;
		}

		return self::queue( 'Announce', $user_id, $target, array( $target['attributed_to'] ), array( self::PUBLIC_COLLECTION ) );
	}

	/**
	 * Build an activity and hand it to the plugin's outbox.
	 *
	 * @param string                                   $type    'Like' or 'Announce'.
	 * @param int                                      $user_id Acting user.
	 * @param array{id: string, attributed_to: string} $target  Resolved object.
	 * @param string[]                                 $to      `to` audience.
	 * @param string[]                                 $cc      `cc` audience.
	 * @return int Outbox post ID, or 0.
	 */
	private static function queue( string $type, int $user_id, array $target, array $to, array $cc ): int {
		try {
			$activity = new \Activitypub\Activity\Activity();
			$activity->set_type( $type );
			$activity->set_object( $target['id'] );
			$activity->set_to( $to );

			if ( ! empty( $cc ) ) {
				$activity->set_cc( $cc );
			}

			$actor = self::actor_id( $user_id );

			if ( '' !== $actor ) {
				$activity->set_actor( $actor );
			}

			$visibility = defined( 'ACTIVITYPUB_CONTENT_VISIBILITY_PRIVATE' ) ? constant( 'ACTIVITYPUB_CONTENT_VISIBILITY_PRIVATE' ) : 'private';
			$outbox_id  = \Activitypub\add_to_outbox( $activity, null, $user_id, $visibility );
		} catch ( \Throwable $e ) {
			return 0;
		}

		return is_wp_error( $outbox_id ) ? 0 : absint( $outbox_id );
	}

	/**
	 * A user's own ActivityPub actor ID, or '' when unknown (add_to_outbox()
	 * sets the actor from the user ID itself in that case).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function actor_id( int $user_id ): string {
		if ( ! class_exists( 'Activitypub\\Collection\\Actors' ) ) {
			return '';
		}

		try {
			$actor = \Activitypub\Collection\Actors::get_by_id( $user_id );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( ! is_object( $actor ) || is_wp_error( $actor ) || ! method_exists( $actor, 'get_id' ) ) {
			return '';
		}

		return esc_url_raw( (string) $actor->get_id() );
	}

	/**
	 * Record a queued activity on the local Mark it belongs to, and stop the
	 * Webmention plugin sending the same like/repost from that Mark.
	 *
	 * For a Like Mark the like target is its only Webmention target, so the
	 * plugin's `_mentionme` send flag is deleted as well (its `do_pings`
	 * handler only processes posts carrying it); a Reblog Mark keeps it, so
	 * any other links in the reader's own comment still get their
	 * Webmentions — only the reposted permalink is filtered out.
	 *
	 * @param int    $mark_id   Local Mark ID.
	 * @param int    $outbox_id Outbox post ID.
	 * @param string $type      'Like' or 'Announce'.
	 * @return void
	 */
	public static function attach_to_mark( int $mark_id, int $outbox_id, string $type ): void {
		if ( $mark_id <= 0 || $outbox_id <= 0 ) {
			return;
		}

		update_post_meta( $mark_id, self::OUTBOX_META, $outbox_id );
		update_post_meta( $mark_id, self::TYPE_META, $type );

		if ( 'Like' === $type ) {
			delete_post_meta( $mark_id, '_mentionme' );
		}
	}

	/**
	 * The like/repost target URL the Webmention plugin must NOT send to from
	 * this Mark, because an ActivityPub Like/Announce already went to it.
	 *
	 * @param int $post_id Mark ID.
	 * @return string The target URL, or '' when nothing is suppressed.
	 */
	public static function suppressed_webmention_target( int $post_id ): string {
		if ( $post_id <= 0 || absint( get_post_meta( $post_id, self::OUTBOX_META, true ) ) <= 0 ) {
			return '';
		}

		$meta_key = 'Announce' === get_post_meta( $post_id, self::TYPE_META, true ) ? '_daymark_repost_of' : '_daymark_like_of';

		return esc_url_raw( (string) get_post_meta( $post_id, $meta_key, true ) );
	}

	/**
	 * `daymark_published` handler: Announce a Reblog Mark that went live inline.
	 *
	 * @param int $post_id Mark ID.
	 * @return void
	 */
	public static function on_daymark_published( $post_id ): void {
		self::maybe_announce( (int) $post_id );
	}

	/**
	 * `transition_post_status` handler: Announce a Reblog Mark that went live
	 * after its meta was already in place (deferred publish, a draft later
	 * published).
	 *
	 * @param string       $new_status New status.
	 * @param string       $old_status Old status.
	 * @param WP_Post|null $post       The post.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
			return;
		}

		self::maybe_announce( (int) $post->ID );
	}

	/**
	 * Queue an Announce for a published Reblog Mark whose reposted URL is a
	 * fediverse object, at most once per Mark.
	 *
	 * @param int $post_id Mark ID.
	 * @return void
	 */
	public static function maybe_announce( int $post_id ): void {
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		if ( '1' !== (string) get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return;
		}

		$repost_of = esc_url_raw( (string) get_post_meta( $post_id, '_daymark_repost_of', true ) );

		if ( '' === $repost_of || absint( get_post_meta( $post_id, self::OUTBOX_META, true ) ) > 0 ) {
			return;
		}

		$outbox_id = self::announce( (int) get_post_field( 'post_author', $post_id ), $repost_of );

		if ( $outbox_id > 0 ) {
			self::attach_to_mark( $post_id, $outbox_id, 'Announce' );
		}
	}

	/**
	 * Queue an Undo of the Like/Announce a Mark carries, once, when it's
	 * trashed or deleted (unlike, unreblog). A restored Mark does not
	 * re-send it.
	 *
	 * @param int $post_id Mark ID.
	 * @return void
	 */
	public static function maybe_undo( $post_id ): void {
		$post_id   = (int) $post_id;
		$outbox_id = absint( get_post_meta( $post_id, self::OUTBOX_META, true ) );

		if ( $outbox_id <= 0 || metadata_exists( 'post', $post_id, self::UNDO_META ) ) {
			return;
		}

		// Recorded first, so a failure below can't loop on the delete that follows a trash.
		update_post_meta( $post_id, self::UNDO_META, 0 );

		$undo_id = self::undo_outbox_item( $outbox_id );

		if ( $undo_id > 0 ) {
			update_post_meta( $post_id, self::UNDO_META, $undo_id );
		}
	}

	/**
	 * Queue an `Undo` of an outbox item via the plugin's own
	 * `Outbox::undo()`, which turns the stored Like/Announce into an Undo
	 * addressed to the same audience.
	 *
	 * @param int $outbox_id Outbox post ID of the Like/Announce.
	 * @return int The Undo's outbox post ID, or 0.
	 */
	public static function undo_outbox_item( int $outbox_id ): int {
		if ( $outbox_id <= 0 || ! self::plugin_available() || ! class_exists( 'Activitypub\\Collection\\Outbox' ) ) {
			return 0;
		}

		$outbox_item = get_post( $outbox_id );

		if ( ! $outbox_item instanceof WP_Post ) {
			return 0;
		}

		try {
			$undo_id = \Activitypub\Collection\Outbox::undo( $outbox_item );
		} catch ( \Throwable $e ) {
			return 0;
		}

		return is_wp_error( $undo_id ) ? 0 : absint( $undo_id );
	}

	/**
	 * `activitypub_sent_to_inbox` handler: record whether a Like/Announce
	 * this site's Marks queued reached an inbox. Cheap for every other
	 * delivery (one cached meta read on the outbox item).
	 *
	 * @param mixed  $result         The HTTP response or WP_Error.
	 * @param string $inbox          Inbox URL. Unused.
	 * @param string $json           Activity JSON. Unused.
	 * @param int    $actor_id       Actor user ID. Unused.
	 * @param int    $outbox_item_id Outbox post ID.
	 * @return void
	 */
	public static function record_inbox_result( $result, $inbox = '', $json = '', $actor_id = 0, $outbox_item_id = 0 ): void {
		unset( $inbox, $json, $actor_id );

		$outbox_item_id = absint( $outbox_item_id );

		if ( $outbox_item_id <= 0 ) {
			return;
		}

		$type = (string) get_post_meta( $outbox_item_id, '_activitypub_activity_type', true );

		if ( '' !== $type && 'Like' !== $type && 'Announce' !== $type ) {
			return;
		}

		$mark_id = self::find_mark_by_outbox_id( $outbox_item_id );

		if ( 0 === $mark_id ) {
			return;
		}

		$code = is_wp_error( $result ) ? 0 : (int) wp_remote_retrieve_response_code( $result );
		$ok   = $code >= 200 && $code < 400;

		// One success wins: a later failed retry to another inbox doesn't
		// turn a delivered Like back into a failed one.
		if ( $ok ) {
			update_post_meta( $mark_id, self::DELIVERY_META, Daymark_Like_Delivery::STATE_SENT );
		} elseif ( Daymark_Like_Delivery::STATE_SENT !== get_post_meta( $mark_id, self::DELIVERY_META, true ) ) {
			update_post_meta( $mark_id, self::DELIVERY_META, Daymark_Like_Delivery::STATE_FAILED );
		}
	}

	/**
	 * The local Mark carrying an outbox ID.
	 *
	 * @param int $outbox_id Outbox post ID.
	 * @return int Mark ID, or 0.
	 */
	private static function find_mark_by_outbox_id( int $outbox_id ): int {
		$found = get_posts(
			array(
				'post_type'      => array( 'post', Daymark_Like_Visibility::POST_TYPE ),
				'post_status'    => 'any',
				'meta_key'       => self::OUTBOX_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match lookup, only reached for a Like/Announce delivery.
				'meta_value'     => (string) $outbox_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match is the point.
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $found ) ? absint( $found[0] ) : 0;
	}

	/**
	 * Delivery state of a Mark's ActivityPub Like/Announce, or '' when it
	 * has none. Modest on purpose: an inbox result from the plugin's
	 * `activitypub_sent_to_inbox` action is the only thing reported as
	 * sent/failed; an outbox row the dispatcher hasn't confirmed yet
	 * (`pending`, or already processed with no inbox result recorded) is
	 * reported as pending, since "processed" isn't "delivered".
	 *
	 * @param int $mark_id Mark ID.
	 * @return string A Daymark_Like_Delivery::STATE_* constant, or ''.
	 */
	public static function delivery_state( int $mark_id ): string {
		$outbox_id = $mark_id > 0 ? absint( get_post_meta( $mark_id, self::OUTBOX_META, true ) ) : 0;

		if ( 0 === $outbox_id ) {
			return '';
		}

		$recorded = (string) get_post_meta( $mark_id, self::DELIVERY_META, true );

		if ( Daymark_Like_Delivery::STATE_SENT === $recorded || Daymark_Like_Delivery::STATE_FAILED === $recorded ) {
			return $recorded;
		}

		return false === get_post_status( $outbox_id ) ? Daymark_Like_Delivery::STATE_NOT_SENT : Daymark_Like_Delivery::STATE_PENDING;
	}
}
