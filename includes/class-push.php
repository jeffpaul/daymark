<?php
/**
 * Web push notifications: signing keys, device subscriptions, preferences,
 * and the events that send a push.
 *
 * A person turns push on per device in the app (Me screen). The browser
 * hands back a push subscription: an address at the browser vendor's push
 * service (Apple, Google, Mozilla, Microsoft) plus two keys used to encrypt
 * the message for that device only. Daymark stores it as user meta and,
 * when something worth a push happens, schedules a WP-Cron event that sends
 * through Daymark_Push_Sender, so a comment submission or poll never waits
 * on a push service.
 *
 * @package Daymark
 */

defined( 'ABSPATH' ) || exit;

/**
 * Push subscriptions, preferences, and triggers.
 */
class Daymark_Push {

	/**
	 * Option holding the site's VAPID key pair (base64url public/private).
	 */
	public const KEYS_OPTION = 'daymark_push_vapid';

	/**
	 * Multi-value user meta: one JSON-encoded subscription per device.
	 */
	public const SUBSCRIPTION_META = 'daymark_push_subscription';

	/**
	 * User meta: which kinds of push this person wants.
	 */
	public const PREFS_META = 'daymark_push_prefs';

	/**
	 * Comment meta marking a comment that already sent its push.
	 */
	public const COMMENT_SENT_META = '_daymark_push_sent';

	/**
	 * WP-Cron hook that sends one message to one user's devices.
	 */
	public const CRON_HOOK = 'daymark_push_dispatch';

	/**
	 * Most devices one person can register.
	 */
	public const MAX_SUBSCRIPTIONS = 10;

	/**
	 * Longest endpoint URL accepted.
	 */
	private const MAX_ENDPOINT_LENGTH = 1024;

	/**
	 * Push kinds and whether each is on before a person chooses.
	 *
	 * @var array<string, bool>
	 */
	public const PREF_DEFAULTS = array(
		'comments'  => true,
		'favorites' => true,
		'followers' => true,
	);

	/**
	 * A favorite site's post older than this when Daymark first sees it
	 * (a backfill, or a feed that just reappeared) doesn't push.
	 */
	public const FAVORITE_MAX_AGE = 3 * DAY_IN_SECONDS;

	/**
	 * New favorite-site posts seen in this request, by subscription ID,
	 * sent as one push per site when the request ends.
	 *
	 * @var array<int, int[]>
	 */
	private static $favorite_queue = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch' ), 10, 2 );
		add_action( 'wp_insert_comment', array( $this, 'maybe_push_new_comment' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'maybe_push_approved_comment' ), 10, 3 );
		add_action( 'daymark_subscription_post_ingested', array( __CLASS__, 'queue_favorite_post' ), 10, 2 );
	}

	/**
	 * Hosts (and their subdomains) a push subscription may point at.
	 *
	 * The browser supplies the endpoint, so a tampered browser could name
	 * any URL. Only the real browser push services are accepted.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts(): array {
		$hosts = array(
			'fcm.googleapis.com',        // Chrome, Edge (Chromium), Opera, Brave, Samsung Internet.
			'push.services.mozilla.com', // Firefox.
			'push.apple.com',            // Safari on macOS, iOS, iPadOS.
			'notify.windows.com',        // Legacy Edge (WNS).
		);

		/**
		 * Filter the push service hosts a subscription endpoint may use.
		 * A subdomain of a listed host is accepted too.
		 *
		 * @param string[] $hosts Host names.
		 */
		$hosts = apply_filters( 'daymark_push_service_hosts', $hosts );

		return array_values( array_filter( array_map( 'strtolower', array_map( 'strval', (array) $hosts ) ) ) );
	}

	/**
	 * Whether an endpoint URL points at an allowed push service.
	 *
	 * @param string $url Endpoint URL.
	 * @return bool
	 */
	public static function is_allowed_endpoint( string $url ): bool {
		if ( '' === $url || strlen( $url ) > self::MAX_ENDPOINT_LENGTH ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		foreach ( self::allowed_hosts() as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode a base64url string, or return null when it isn't one.
	 *
	 * @param string $value Encoded value.
	 * @return string|null Raw bytes.
	 */
	private static function base64url_decode( string $value ): ?string {
		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+={0,2}$/', $value ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding browser-supplied push keys, not obfuscated code.
		$decoded = base64_decode( strtr( rtrim( $value, '=' ), '-_', '+/' ), true );

		return false === $decoded ? null : $decoded;
	}

	/**
	 * Validate and normalize a subscription from the browser.
	 *
	 * Accepts PushSubscription.toJSON() shape: endpoint plus keys.p256dh and
	 * keys.auth (base64url). p256dh must be an uncompressed P-256 point
	 * (65 bytes, leading 0x04) and auth 16 bytes, as the Push API defines.
	 *
	 * @param array<string, mixed> $raw Subscription data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize_subscription( array $raw ) {
		$endpoint = is_string( $raw['endpoint'] ?? null ) ? trim( $raw['endpoint'] ) : '';
		$keys     = is_array( $raw['keys'] ?? null ) ? $raw['keys'] : array();
		$p256dh   = is_string( $keys['p256dh'] ?? null ) ? trim( $keys['p256dh'] ) : '';
		$auth     = is_string( $keys['auth'] ?? null ) ? trim( $keys['auth'] ) : '';

		if ( ! self::is_allowed_endpoint( $endpoint ) ) {
			return new WP_Error(
				'daymark_push_endpoint',
				__( 'This browser’s push service isn’t supported.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$p256dh_raw = self::base64url_decode( $p256dh );
		$auth_raw   = self::base64url_decode( $auth );

		if ( null === $p256dh_raw || 65 !== strlen( $p256dh_raw ) || "\x04" !== $p256dh_raw[0] || null === $auth_raw || 16 !== strlen( $auth_raw ) ) {
			return new WP_Error(
				'daymark_push_keys',
				__( 'The browser sent push keys Daymark can’t use.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'endpoint'   => $endpoint,
			'p256dh'     => rtrim( $p256dh, '=' ),
			'auth'       => rtrim( $auth, '=' ),
			'created_at' => time(),
		);
	}

	/**
	 * A user's stored subscriptions.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_subscriptions( int $user_id ): array {
		$subs = array();

		foreach ( (array) get_user_meta( $user_id, self::SUBSCRIPTION_META, false ) as $row ) {
			$data = is_string( $row ) ? json_decode( $row, true ) : null;

			if ( is_array( $data ) && is_string( $data['endpoint'] ?? null ) && self::is_allowed_endpoint( $data['endpoint'] ) ) {
				$subs[] = $data;
			}
		}

		return $subs;
	}

	/**
	 * Save a device subscription for a user, replacing an earlier copy of
	 * the same endpoint, and removing that endpoint from any other user
	 * (a shared browser now signed in as someone else).
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $raw     Subscription from the browser.
	 * @return array<string, mixed>|WP_Error The stored subscription.
	 */
	public static function save_subscription( int $user_id, array $raw ) {
		$sub = self::normalize_subscription( $raw );

		if ( is_wp_error( $sub ) ) {
			return $sub;
		}

		self::delete_endpoint_everywhere( (string) $sub['endpoint'] );

		$existing = self::get_subscriptions( $user_id );

		if ( count( $existing ) >= self::MAX_SUBSCRIPTIONS ) {
			// Drop the oldest device to make room.
			usort(
				$existing,
				static function ( $a, $b ) {
					return (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 );
				}
			);
			self::delete_subscription( $user_id, (string) $existing[0]['endpoint'] );
		}

		add_user_meta( $user_id, self::SUBSCRIPTION_META, self::encode( $sub ) );

		return $sub;
	}

	/**
	 * Remove one endpoint from a user. Also drops any unreadable row.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $endpoint Endpoint URL.
	 * @return bool Whether the endpoint was removed.
	 */
	public static function delete_subscription( int $user_id, string $endpoint ): bool {
		$removed = false;

		foreach ( (array) get_user_meta( $user_id, self::SUBSCRIPTION_META, false ) as $row ) {
			$data = is_string( $row ) ? json_decode( $row, true ) : null;

			if ( ! is_array( $data ) ) {
				delete_user_meta( $user_id, self::SUBSCRIPTION_META, $row );
				continue;
			}

			if ( (string) ( $data['endpoint'] ?? '' ) === $endpoint ) {
				$removed = delete_user_meta( $user_id, self::SUBSCRIPTION_META, $row ) || $removed;
			}
		}

		return $removed;
	}

	/**
	 * Remove an endpoint from every user.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return void
	 */
	private static function delete_endpoint_everywhere( string $endpoint ): void {
		$user_ids = get_users(
			array(
				'fields'       => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare write path, personal-site scale.
				'meta_key'     => self::SUBSCRIPTION_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Rare write path, personal-site scale.
				'meta_value'   => $endpoint,
				'meta_compare' => 'LIKE',
			)
		);

		foreach ( $user_ids as $user_id ) {
			self::delete_subscription( (int) $user_id, $endpoint );
		}
	}

	/**
	 * JSON-encode a subscription for storage. Slashes stay unescaped so a
	 * LIKE search on the endpoint URL matches.
	 *
	 * @param array<string, mixed> $sub Subscription.
	 * @return string
	 */
	private static function encode( array $sub ): string {
		return (string) wp_json_encode( $sub, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * A user's push preferences, with defaults filled in.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, bool>
	 */
	public static function get_prefs( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::PREFS_META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$prefs  = array();

		foreach ( self::PREF_DEFAULTS as $key => $default ) {
			$prefs[ $key ] = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : $default;
		}

		return $prefs;
	}

	/**
	 * Update a user's push preferences. Unknown keys are ignored.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $changes Key => bool.
	 * @return array<string, bool> The preferences after the update.
	 */
	public static function update_prefs( int $user_id, array $changes ): array {
		$prefs = self::get_prefs( $user_id );

		foreach ( $changes as $key => $value ) {
			if ( array_key_exists( (string) $key, self::PREF_DEFAULTS ) ) {
				$prefs[ (string) $key ] = rest_sanitize_boolean( $value );
			}
		}

		update_user_meta( $user_id, self::PREFS_META, $prefs );

		return $prefs;
	}

	/**
	 * The site's VAPID public key (base64url), creating the key pair the
	 * first time. Empty string when the keys can't be made.
	 *
	 * @return string
	 */
	public static function public_key(): string {
		$keys = self::keys();

		return $keys ? $keys['publicKey'] : '';
	}

	/**
	 * The site's VAPID key pair, created on first use.
	 *
	 * @return array{publicKey: string, privateKey: string}|null
	 */
	public static function keys(): ?array {
		$stored = self::stored_keys();

		if ( $stored ) {
			return $stored;
		}

		if ( ! Daymark_Push_Sender::load_library() ) {
			return null;
		}

		try {
			$keys = \Daymark\Vendor\Minishlink\WebPush\VAPID::createVapidKeys();
		} catch ( \Throwable $e ) {
			return null;
		}

		// Not autoloaded: only push code reads it. add_option() does nothing
		// when another request stored a pair first, so read back the winner.
		add_option(
			self::KEYS_OPTION,
			array(
				'publicKey'  => (string) $keys['publicKey'],
				'privateKey' => (string) $keys['privateKey'],
			),
			'',
			false
		);

		return self::stored_keys();
	}

	/**
	 * The stored key pair, or null.
	 *
	 * @return array{publicKey: string, privateKey: string}|null
	 */
	private static function stored_keys(): ?array {
		$stored = get_option( self::KEYS_OPTION );

		if ( ! is_array( $stored ) || empty( $stored['publicKey'] ) || empty( $stored['privateKey'] ) ) {
			return null;
		}

		return array(
			'publicKey'  => (string) $stored['publicKey'],
			'privateKey' => (string) $stored['privateKey'],
		);
	}

	/**
	 * Queue a push to every device a user has registered.
	 *
	 * Does nothing when the user has no devices or has turned this kind of
	 * push off. The send itself runs in WP-Cron.
	 *
	 * @param int                  $user_id User ID.
	 * @param string               $kind    Preference key, e.g. 'comments'.
	 * @param array<string, mixed> $message title, body, url, tag.
	 * @return bool Whether a send was scheduled.
	 */
	public static function notify_user( int $user_id, string $kind, array $message ): bool {
		if ( $user_id <= 0 || empty( self::get_subscriptions( $user_id ) ) ) {
			return false;
		}

		$prefs = self::get_prefs( $user_id );

		if ( empty( $prefs[ $kind ] ) ) {
			return false;
		}

		/**
		 * Filter a push message before it's queued. Return a non-array to
		 * skip it.
		 *
		 * @param array<string, string> $message title, body, url, tag.
		 * @param int                   $user_id Recipient.
		 * @param string                $kind    Push kind.
		 */
		$message = apply_filters( 'daymark_push_message', self::sanitize_message( $message ), $user_id, $kind );

		if ( ! is_array( $message ) ) {
			return false;
		}

		return true === wp_schedule_single_event( time(), self::CRON_HOOK, array( $user_id, self::sanitize_message( $message ) ) );
	}

	/**
	 * Queue a push to everyone with devices who wants this kind.
	 *
	 * For site-wide events (a favorite site publishing, a new follower),
	 * which aren't tied to one author. Only people who can write Marks get
	 * them.
	 *
	 * @param string               $kind    Preference key.
	 * @param array<string, mixed> $message title, body, url, tag.
	 * @return int How many people a push was queued for.
	 */
	public static function notify_everyone( string $kind, array $message ): int {
		$user_ids = get_users(
			array(
				'fields'       => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only users who turned push on; personal-site scale.
				'meta_key'     => self::SUBSCRIPTION_META,
				'meta_compare' => 'EXISTS',
			)
		);
		$queued   = 0;

		foreach ( $user_ids as $user_id ) {
			if ( user_can( (int) $user_id, 'edit_posts' ) && self::notify_user( (int) $user_id, $kind, $message ) ) {
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Keep a message to plain, short strings and a URL on this site.
	 *
	 * @param array<string, mixed> $message Message.
	 * @return array<string, string>
	 */
	public static function sanitize_message( array $message ): array {
		$url = esc_url_raw( (string) ( $message['url'] ?? '' ), array( 'http', 'https' ) );

		if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$url = Daymark_Routes::app_url();
		}

		return array(
			'title' => self::clip( (string) ( $message['title'] ?? '' ), 80 ),
			'body'  => self::clip( (string) ( $message['body'] ?? '' ), 180 ),
			'url'   => $url,
			'tag'   => substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $message['tag'] ?? '' ) ) ), 0, 64 ),
		);
	}

	/**
	 * Plain text, whitespace collapsed, shortened with an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Most characters.
	 * @return string
	 */
	private static function clip( string $text, int $max ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * WP-Cron handler: send one message to one user's devices.
	 *
	 * @param int|string           $user_id User ID.
	 * @param array<string, mixed> $message Message.
	 * @return void
	 */
	public static function dispatch( $user_id, $message ): void {
		Daymark_Push_Sender::send_to_user( (int) $user_id, is_array( $message ) ? $message : array() );
	}

	/**
	 * Push a newly inserted comment when it's already approved.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment.
	 * @return void
	 */
	public function maybe_push_new_comment( $comment_id, $comment ): void {
		if ( $comment instanceof WP_Comment && '1' === (string) $comment->comment_approved ) {
			self::push_comment( $comment );
		}
	}

	/**
	 * Push a held comment once it's approved.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 * @return void
	 */
	public function maybe_push_approved_comment( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status && 'approved' !== $old_status && $comment instanceof WP_Comment ) {
			self::push_comment( $comment );
		}
	}

	/**
	 * Push a reply on one of a user's Marks to that user, once.
	 *
	 * Only replies (comment type `comment`), the ones the Notifications
	 * Comments tab lists; likes, reblogs, and mentions stored as comments
	 * don't push. The author's own replies never push.
	 *
	 * On a Mark that is the user's comment on a followed post, the push
	 * says so ("New reply to your comment on …"), and a reply Daymark
	 * already pushed after finding it on the original site (issue #559)
	 * isn't pushed again when it also arrives here as a Webmention.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return bool Whether a push was queued.
	 */
	public static function push_comment( WP_Comment $comment ): bool {
		if ( ! in_array( (string) $comment->comment_type, array( '', 'comment' ), true ) ) {
			return false;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post->ID, '_daymark_is_mark', true ) ) {
			return false;
		}

		$author_id = (int) $post->post_author;

		if ( $author_id <= 0 || (int) $comment->user_id === $author_id || get_comment_meta( (int) $comment->comment_ID, self::COMMENT_SENT_META, true ) ) {
			return false;
		}

		$reply_to = Daymark_Notifications::find_reply_target( (int) $post->ID );

		if ( null !== $reply_to && Daymark_Comment_Replies::already_found( (int) $post->ID, (string) get_comment_meta( (int) $comment->comment_ID, 'webmention_source_url', true ) ) ) {
			return false;
		}

		$name  = '' !== trim( (string) $comment->comment_author ) ? (string) $comment->comment_author : __( 'Someone', 'daymark' );
		$title = '' !== trim( (string) $post->post_title ) ? (string) $post->post_title : __( 'your Mark', 'daymark' );

		if ( null !== $reply_to ) {
			$push_title = sprintf(
				/* translators: %s: the original post's title, or its site when it has none. */
				__( 'New reply to your comment on %s', 'daymark' ),
				$reply_to['title']
			);
		} else {
			$push_title = sprintf(
				/* translators: %s: the post's title. */
				__( 'New comment on %s', 'daymark' ),
				$title
			);
		}

		$queued = self::notify_user(
			$author_id,
			'comments',
			array(
				'title' => $push_title,
				/* translators: 1: commenter's name, 2: the comment text. */
				'body'  => sprintf( __( '%1$s: %2$s', 'daymark' ), $name, (string) $comment->comment_content ),
				'url'   => Daymark_Routes::app_url( 'notifications' ),
				'tag'   => 'comment-' . (int) $comment->comment_ID,
			)
		);

		if ( $queued ) {
			update_comment_meta( (int) $comment->comment_ID, self::COMMENT_SENT_META, '1' );
		}

		return $queued;
	}

	/**
	 * Note a favorite site's new post, to push when the request ends.
	 *
	 * Only a post published after the site was starred, and not older than
	 * FAVORITE_MAX_AGE, so starring a site or a feed catching up never
	 * floods anyone with old posts. Several new posts from one site in one
	 * check become one push.
	 *
	 * @param int $post_id         Subscription post ID.
	 * @param int $subscription_id Subscription ID.
	 * @return bool Whether the post was queued.
	 */
	public static function queue_favorite_post( $post_id, $subscription_id ): bool {
		$subscription = Daymark_Plugin::instance()->subscriptions->get( (int) $subscription_id );

		if ( ! Daymark_Subscriptions::is_favorite( $subscription ) ) {
			return false;
		}

		$published = (string) get_post_meta( (int) $post_id, 'published_at', true );

		if ( '' !== $published ) {
			$published_ts = (int) strtotime( $published . ' UTC' );
			$starred_ts   = (int) strtotime( (string) $subscription['favorited_at'] . ' UTC' );

			if ( $published_ts < $starred_ts || $published_ts < time() - self::FAVORITE_MAX_AGE ) {
				return false;
			}
		}

		if ( empty( self::$favorite_queue ) ) {
			add_action( 'shutdown', array( __CLASS__, 'send_favorite_pushes' ) );
		}

		self::$favorite_queue[ (int) $subscription_id ][] = (int) $post_id;

		return true;
	}

	/**
	 * Queue one push per favorite site with new posts this request.
	 *
	 * @return void
	 */
	public static function send_favorite_pushes(): void {
		$queue                = self::$favorite_queue;
		self::$favorite_queue = array();

		foreach ( $queue as $subscription_id => $post_ids ) {
			$subscription = Daymark_Plugin::instance()->subscriptions->get( (int) $subscription_id );

			if ( null === $subscription ) {
				continue;
			}

			$site  = trim( (string) $subscription['site_title'] );
			$site  = '' !== $site ? $site : (string) wp_parse_url( (string) $subscription['site_url'], PHP_URL_HOST );
			$count = count( $post_ids );

			if ( 1 === $count ) {
				$post_id = (int) $post_ids[0];
				$title   = trim( (string) get_the_title( $post_id ) );
				$body    = '' !== $title ? $title : (string) get_post_field( 'post_excerpt', $post_id );
				$url     = add_query_arg( 'daymark_post', $post_id, Daymark_Routes::app_url() );
			} else {
				/* translators: %d: number of new posts. */
				$body = sprintf( _nx( '%d new post', '%d new posts', $count, 'push notification about a favorite site', 'daymark' ), $count );
				$url  = Daymark_Routes::app_url();
			}

			self::notify_everyone(
				'favorites',
				array(
					'title' => $site,
					'body'  => $body,
					'url'   => $url,
					'tag'   => 'favorite-' . (int) $subscription_id,
				)
			);
		}
	}
}
