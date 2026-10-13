<?php
/**
 * New followers: who has started following this site, from three places,
 * and the push that tells you.
 *
 * - Another Daymark (or IndieWeb) site: a follow Webmention. When this site
 *   follows a site, it publishes an unlisted page marked up with
 *   `u-follow-of` (the IndieWeb follow post, https://indieweb.org/follow)
 *   and sends that site a Webmention pointing at it. When it unfollows,
 *   the page answers 410 and the Webmention is sent again, so the other
 *   site removes the follow. Receiving works the other way round, through
 *   Daymark's own Webmention endpoint, with the site's home page as the
 *   target.
 * - The fediverse, through the ActivityPub plugin's `activitypub_handled_follow`
 *   action. The Friends plugin (4.x) follows over ActivityPub too.
 * - Jetpack email and WordPress.com subscribers: checked hourly through
 *   WordPress.com's followers list, using the site's own Jetpack
 *   connection.
 *
 * Followers are kept in one option, newest first, so a future screen can
 * list them; for now they only send a push.
 *
 * @package Daymark
 */

defined( 'ABSPATH' ) || exit;

/**
 * Followers and follow announcements.
 */
class Daymark_Followers {

	/**
	 * Option: known followers, keyed by a hash of where they came from.
	 */
	public const FOLLOWERS_OPTION = 'daymark_followers';

	/**
	 * Option: this site's follow pages, token => [ target, created ].
	 */
	public const PAGES_OPTION = 'daymark_follow_pages';

	/**
	 * Option: follow announcements still to send, as page tokens.
	 */
	public const QUEUE_OPTION = 'daymark_follow_queue';

	/**
	 * Option: newest WordPress.com subscriber date already seen (Unix time).
	 */
	public const JETPACK_SINCE_OPTION = 'daymark_jetpack_followers_since';

	/**
	 * Query var that shows a follow page.
	 */
	public const QUERY_VAR = 'daymark_follow';

	/**
	 * WP-Cron: send queued follow announcements.
	 */
	public const CRON_SEND = 'daymark_follow_send';

	/**
	 * WP-Cron: verify one received follow Webmention.
	 */
	public const CRON_VERIFY = 'daymark_follow_verify';

	/**
	 * WP-Cron: hourly check for new WordPress.com subscribers.
	 */
	public const CRON_JETPACK = 'daymark_followers_check_jetpack';

	/**
	 * Most followers kept.
	 */
	private const MAX_FOLLOWERS = 500;

	/**
	 * Follow announcements sent per cron run.
	 */
	private const SEND_BATCH = 10;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'daymark_subscription_created', array( __CLASS__, 'on_subscription_created' ), 10, 2 );
		add_action( 'daymark_subscription_deleted', array( __CLASS__, 'on_subscription_deleted' ), 10, 2 );
		add_action( self::CRON_SEND, array( __CLASS__, 'send_queued' ) );
		add_action( self::CRON_VERIFY, array( __CLASS__, 'verify' ) );
		add_action( self::CRON_JETPACK, array( __CLASS__, 'check_jetpack' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_follow_page' ), 0 );
		add_action( 'activitypub_handled_follow', array( __CLASS__, 'on_activitypub_follow' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/**
	 * Schedule the hourly Jetpack check.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_JETPACK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON_JETPACK );
		}
	}

	/**
	 * Remove the schedule (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_JETPACK );
		wp_clear_scheduled_hook( self::CRON_SEND );
		wp_unschedule_hook( self::CRON_VERIFY );
	}

	// --- Followers ---

	/**
	 * Known followers, newest first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::FOLLOWERS_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Record a follower, and push about it when it's new.
	 *
	 * @param string $via  'webmention', 'activitypub', or 'jetpack'.
	 * @param string $id   Stable ID within that source (a URL or numeric ID).
	 * @param string $name Display name.
	 * @param string $url  Their site or profile.
	 * @param bool   $push Whether a new follower sends a push.
	 * @return bool Whether this follower is new.
	 */
	public static function add( string $via, string $id, string $name, string $url = '', bool $push = true ): bool {
		$key       = substr( md5( $via . '|' . $id ), 0, 16 );
		$followers = self::all();
		$is_new    = ! isset( $followers[ $key ] );
		$name      = trim( sanitize_text_field( $name ) );
		$url       = esc_url_raw( $url, array( 'http', 'https' ) );

		$entry = array(
			'via'         => sanitize_key( $via ),
			'name'        => '' !== $name ? mb_substr( $name, 0, 120 ) : (string) wp_parse_url( $url, PHP_URL_HOST ),
			'url'         => $url,
			'followed_at' => $is_new ? time() : (int) ( $followers[ $key ]['followed_at'] ?? time() ),
		);

		unset( $followers[ $key ] );
		$followers = array( $key => $entry ) + $followers;
		$followers = array_slice( $followers, 0, self::MAX_FOLLOWERS, true );

		update_option( self::FOLLOWERS_OPTION, $followers, false );

		if ( $is_new && $push ) {
			self::push( $entry );
		}

		return $is_new;
	}

	/**
	 * Forget a follower (an unfollow).
	 *
	 * @param string $via Source.
	 * @param string $id  Stable ID within that source.
	 * @return void
	 */
	public static function remove( string $via, string $id ): void {
		$key       = substr( md5( $via . '|' . $id ), 0, 16 );
		$followers = self::all();

		if ( isset( $followers[ $key ] ) ) {
			unset( $followers[ $key ] );
			update_option( self::FOLLOWERS_OPTION, $followers, false );
		}
	}

	/**
	 * Push about one new follower.
	 *
	 * @param array<string, mixed> $entry Follower.
	 * @return void
	 */
	private static function push( array $entry ): void {
		$name = (string) $entry['name'];

		switch ( $entry['via'] ) {
			case 'activitypub':
				/* translators: %s: follower's name. */
				$body = sprintf( __( '%s followed you on the fediverse.', 'daymark' ), $name );
				break;
			case 'jetpack':
				/* translators: %s: subscriber's name. */
				$body = sprintf( __( '%s subscribed to your site.', 'daymark' ), $name );
				break;
			default:
				/* translators: %s: the following site's name. */
				$body = sprintf( __( '%s follows your site.', 'daymark' ), $name );
		}

		Daymark_Push::notify_everyone(
			'followers',
			array(
				'title' => __( 'New follower', 'daymark' ),
				'body'  => $body,
				'url'   => Daymark_Routes::app_url(),
				'tag'   => 'follower-' . substr( md5( $name . $entry['via'] ), 0, 12 ),
			)
		);
	}

	// --- Telling a site you follow it ---

	/**
	 * The site address a subscription follows: its scheme and host, with a
	 * trailing slash, so two feeds from one site count as one follow.
	 *
	 * @param string $site_url Subscription site URL.
	 * @return string Empty when unusable.
	 */
	public static function site_root( string $site_url ): string {
		$parts = wp_parse_url( $site_url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . '/';
	}

	/**
	 * This site's follow pages.
	 *
	 * @return array<string, array{target: string, created: int}>
	 */
	public static function pages(): array {
		$pages = get_option( self::PAGES_OPTION, array() );

		return is_array( $pages ) ? $pages : array();
	}

	/**
	 * The follow page URL for a token.
	 *
	 * @param string $token Page token.
	 * @return string
	 */
	public static function page_url( string $token ): string {
		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
	}

	/**
	 * The token of the follow page for a site, or ''.
	 *
	 * @param string $root Site root.
	 * @return string
	 */
	private static function token_for( string $root ): string {
		foreach ( self::pages() as $token => $page ) {
			if ( ( $page['target'] ?? '' ) === $root ) {
				return (string) $token;
			}
		}

		return '';
	}

	/**
	 * Following a site: publish a follow page for it and queue the
	 * Webmention. One page per site, however many of its feeds are followed.
	 *
	 * @param int                  $id  Subscription ID.
	 * @param array<string, mixed> $row Subscription row.
	 * @return void
	 */
	public static function on_subscription_created( $id, $row ): void {
		if ( ! Daymark_Settings::announce_follows() || ! Daymark_Webmention::can_send() ) {
			return;
		}

		$root = self::site_root( (string) ( $row['site_url'] ?? '' ) );

		if ( '' === $root || self::site_root( home_url( '/' ) ) === $root || '' !== self::token_for( $root ) ) {
			return;
		}

		$token           = strtolower( wp_generate_password( 24, false ) );
		$pages           = self::pages();
		$pages[ $token ] = array(
			'target'  => $root,
			'created' => time(),
		);
		update_option( self::PAGES_OPTION, $pages, false );

		self::enqueue( $token . '|' . $root );
	}

	/**
	 * Unfollowing a site: when no other feed from it is still followed,
	 * remove its follow page (it then answers 410) and tell the site again,
	 * so it removes the follow.
	 *
	 * @param int                  $id  Subscription ID.
	 * @param array<string, mixed> $row Deleted row.
	 * @return void
	 */
	public static function on_subscription_deleted( $id, $row ): void {
		$root  = self::site_root( (string) ( $row['site_url'] ?? '' ) );
		$token = '' !== $root ? self::token_for( $root ) : '';

		if ( '' === $token ) {
			return;
		}

		foreach ( Daymark_Plugin::instance()->subscriptions->get_all() as $other ) {
			if ( self::site_root( (string) $other['site_url'] ) === $root ) {
				return;
			}
		}

		$pages = self::pages();
		unset( $pages[ $token ] );
		update_option( self::PAGES_OPTION, $pages, false );

		if ( Daymark_Webmention::can_send() ) {
			self::enqueue( $token . '|' . $root );
		}
	}

	/**
	 * Add an announcement to the queue and make sure it gets sent.
	 *
	 * @param string $item "token|target".
	 * @return void
	 */
	private static function enqueue( string $item ): void {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();

		if ( ! in_array( $item, $queue, true ) ) {
			$queue[] = $item;
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		if ( ! wp_next_scheduled( self::CRON_SEND ) ) {
			wp_schedule_single_event( time(), self::CRON_SEND );
		}
	}

	/**
	 * Send up to SEND_BATCH queued announcements; schedule the rest.
	 *
	 * @return int How many were sent (accepted or not).
	 */
	public static function send_queued(): int {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? array_values( $queue ) : array();
		$batch = array_slice( $queue, 0, self::SEND_BATCH );
		$rest  = array_slice( $queue, self::SEND_BATCH );

		update_option( self::QUEUE_OPTION, $rest, false );

		foreach ( $batch as $item ) {
			list( $token, $target ) = array_pad( explode( '|', (string) $item, 2 ), 2, '' );

			if ( '' !== $token && '' !== $target ) {
				Daymark_Webmention::send_one( self::page_url( $token ), $target );
			}
		}

		if ( $rest && ! wp_next_scheduled( self::CRON_SEND ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_SEND );
		}

		return count( $batch );
	}

	/**
	 * Serve a follow page: a minimal, unindexed h-entry saying this site
	 * follows another. An unknown token answers 410 Gone, which is how a
	 * followed site learns of an unfollow.
	 *
	 * @return void
	 */
	public static function maybe_serve_follow_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public page.
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? strtolower( sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) ) : '';

		if ( '' === $token ) {
			return;
		}

		echo self::follow_page_html( $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in follow_page_html().
		exit;
	}

	/**
	 * Set headers for, and build, a follow page.
	 *
	 * @param string $token Page token.
	 * @return string
	 */
	public static function follow_page_html( string $token ): string {
		$pages = self::pages();
		$page  = $pages[ $token ] ?? null;

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		if ( ! is_array( $page ) || empty( $page['target'] ) ) {
			status_header( 410 );

			return '<!doctype html><title>' . esc_html__( 'Gone', 'daymark' ) . '</title>';
		}

		status_header( 200 );

		$target = (string) $page['target'];
		$host   = (string) wp_parse_url( $target, PHP_URL_HOST );
		$name   = get_bloginfo( 'name' );

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
			/* translators: %s: the followed site's host name. */
			. '<title>' . esc_html( sprintf( __( 'Following %s', 'daymark' ), $host ) ) . '</title></head><body>'
			. '<div class="h-entry"><p>'
			. '<a class="p-author h-card" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $name ) . '</a> '
			. esc_html__( 'follows', 'daymark' ) . ' '
			. '<a class="u-follow-of" href="' . esc_url( $target ) . '">' . esc_html( $host ) . '</a>'
			. '</p><time class="dt-published" datetime="' . esc_attr( gmdate( 'c', (int) ( $page['created'] ?? time() ) ) ) . '"></time>'
			. '</div></body></html>';
	}

	// --- Being told someone follows you ---

	/**
	 * Whether a Webmention target is this site itself (its home page).
	 *
	 * @param string $target Target URL.
	 * @return bool
	 */
	public static function is_site_target( string $target ): bool {
		$path = (string) wp_parse_url( $target, PHP_URL_PATH );

		return '' !== $target && self::site_root( $target ) === self::site_root( home_url( '/' ) )
			&& in_array( $path, array( '', '/' ), true )
			&& '' === (string) wp_parse_url( $target, PHP_URL_QUERY );
	}

	/**
	 * Queue the check of a follow Webmention, within the receiver's
	 * per-host limit.
	 *
	 * @param string $source Follow page URL.
	 * @return bool False when the sender is over its limit.
	 */
	public static function queue_verification( string $source ): bool {
		if ( false !== wp_next_scheduled( self::CRON_VERIFY, array( $source ) ) ) {
			return true;
		}

		if ( ! Daymark_Webmention_Receiver::within_limit( $source ) ) {
			return false;
		}

		wp_schedule_single_event( time(), self::CRON_VERIFY, array( $source ) );

		return true;
	}

	/**
	 * Check a follow Webmention: fetch the page, and record or remove the
	 * follower.
	 *
	 * @param string $source Follow page URL.
	 * @return void
	 */
	public static function verify( $source ): void {
		$source = (string) $source;

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $source ) ) ) {
			return;
		}

		$response = Daymark_Outbound_Guard::get(
			$source,
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => 262144,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code || 410 === $code ) {
			self::remove( 'webmention', $source );
			return;
		}

		if ( $code < 200 || $code >= 300 ) {
			return;
		}

		$follower = self::read_follow_page( (string) wp_remote_retrieve_body( $response ), $source );

		if ( null === $follower ) {
			self::remove( 'webmention', $source );
			return;
		}

		self::add( 'webmention', $source, $follower['name'], $follower['url'] );
	}

	/**
	 * Read a follow page: it must link to this site with `u-follow-of`.
	 *
	 * @param string $html   Page HTML.
	 * @param string $source Page URL.
	 * @return array{name: string, url: string}|null Null when it isn't a follow of this site.
	 */
	public static function read_follow_page( string $html, string $source ): ?array {
		if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return null;
		}

		$follows   = false;
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( 'a' ) ) {
			$class = ' ' . (string) ( $processor->get_attribute( 'class' ) ?? '' ) . ' ';
			$href  = trim( (string) ( $processor->get_attribute( 'href' ) ?? '' ) );

			if ( false !== strpos( preg_replace( '/\s+/', ' ', $class ), ' u-follow-of ' ) && '' !== $href && self::is_site_target( WP_Http::make_absolute_url( $href, $source ) ) ) {
				$follows = true;
				break;
			}
		}

		if ( ! $follows ) {
			return null;
		}

		$parser = new Daymark_Subscription_Source_Microformats();
		$author = $parser->first_class_inner_html( $html, 'p-author' );
		$name   = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $parser->first_class_inner_html( $author, 'p-name' ) ) ) );

		if ( '' === $name ) {
			$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $author ) ) );
		}

		$url = self::site_root( $source );

		return array(
			'name' => '' !== $name ? html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) : (string) wp_parse_url( $source, PHP_URL_HOST ),
			'url'  => $url,
		);
	}

	// --- Fediverse (ActivityPub plugin) ---

	/**
	 * A new fediverse follower, from the ActivityPub plugin's
	 * `activitypub_handled_follow( $activity, $user_ids, $success, $remote_actor )`.
	 * `$success` is false for someone who already followed. User ID 0 is
	 * the site's own (blog) actor.
	 *
	 * @param mixed $activity     The Follow activity (array or object).
	 * @param mixed $user_ids     Local actors followed.
	 * @param mixed $success      Whether this is a new follow.
	 * @param mixed $remote_actor The follower (WP_Post) or WP_Error.
	 * @return void
	 */
	public static function on_activitypub_follow( $activity, $user_ids = array(), $success = true, $remote_actor = null ): void {
		if ( ! $success ) {
			return;
		}

		$actor = '';

		if ( is_array( $activity ) ) {
			$actor = $activity['actor'] ?? '';
		} elseif ( is_object( $activity ) && method_exists( $activity, 'get_actor' ) ) {
			$actor = $activity->get_actor();
		}

		if ( is_array( $actor ) ) {
			$actor = $actor['id'] ?? '';
		} elseif ( is_object( $actor ) && method_exists( $actor, 'get_id' ) ) {
			$actor = $actor->get_id();
		}

		$actor = is_string( $actor ) ? esc_url_raw( $actor, array( 'https', 'http' ) ) : '';

		if ( '' === $actor ) {
			return;
		}

		$name = $remote_actor instanceof WP_Post ? trim( (string) $remote_actor->post_title ) : '';

		if ( '' === $name ) {
			$path = trim( (string) wp_parse_url( $actor, PHP_URL_PATH ), '/' );
			$user = '' !== $path ? basename( $path ) : '';
			$name = ( '' !== $user ? '@' . ltrim( $user, '@' ) . '@' : '' ) . (string) wp_parse_url( $actor, PHP_URL_HOST );
		}

		self::add( 'activitypub', $actor, $name, $actor );
	}

	// --- Jetpack / WordPress.com subscribers ---

	/**
	 * Hourly: look for new WordPress.com subscribers (email and Reader).
	 *
	 * The first check only notes where the list stands, so nobody gets a
	 * push for subscribers they already had.
	 *
	 * Not confirmed against a live Jetpack site: the
	 * `sites/{id}/stats/followers` endpoint and its `subscribers[]` fields
	 * (`ID`, `label`, `url`, `date_subscribed`) come from Jetpack's own
	 * stats package and test fixtures.
	 *
	 * @return int New subscribers found.
	 */
	public static function check_jetpack(): int {
		if ( ! Daymark_Jetpack_Engagement::site_connected() ) {
			return 0;
		}

		$site_id = Daymark_Jetpack_Engagement::own_site_id();

		if ( 0 === $site_id ) {
			return 0;
		}

		/**
		 * Short-circuit the WordPress.com followers request (tests).
		 *
		 * @param array|null $body    Decoded response body, or null to fetch.
		 * @param int        $site_id WordPress.com site ID.
		 */
		$body = apply_filters( 'daymark_jetpack_followers_pre_fetch', null, $site_id );

		if ( null === $body ) {
			try {
				$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
					sprintf( 'sites/%d/stats/followers?type=all&max=20', $site_id )
				);
			} catch ( \Throwable $e ) {
				return 0;
			}

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return 0;
			}

			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		}

		return self::record_jetpack_subscribers( is_array( $body ) ? $body : array() );
	}

	/**
	 * Record subscribers newer than the last check, and push about them.
	 *
	 * @param array<string, mixed> $body WordPress.com followers response.
	 * @return int New subscribers found.
	 */
	public static function record_jetpack_subscribers( array $body ): int {
		$since    = get_option( self::JETPACK_SINCE_OPTION, null );
		$newest   = null === $since ? 0 : (int) $since;
		$first    = null === $since;
		$new_ones = array();

		foreach ( (array) ( $body['subscribers'] ?? array() ) as $subscriber ) {
			if ( ! is_array( $subscriber ) ) {
				continue;
			}

			$date = (int) strtotime( (string) ( $subscriber['date_subscribed'] ?? '' ) );

			if ( $date <= 0 ) {
				continue;
			}

			$newest = max( $newest, $date );

			if ( ! $first && $date > (int) $since ) {
				$new_ones[] = $subscriber;
			}
		}

		update_option( self::JETPACK_SINCE_OPTION, max( $newest, $first ? time() : 0 ), false );

		// Several at once become one push.
		$single = 1 === count( $new_ones );

		foreach ( $new_ones as $subscriber ) {
			$name = (string) ( $subscriber['label'] ?? ( $subscriber['display_name'] ?? '' ) );
			self::add( 'jetpack', (string) ( $subscriber['ID'] ?? $name ), '' !== $name ? $name : __( 'Someone', 'daymark' ), (string) ( $subscriber['url'] ?? '' ), $single );
		}

		if ( count( $new_ones ) > 1 ) {
			$count = count( $new_ones );

			Daymark_Push::notify_everyone(
				'followers',
				array(
					'title' => __( 'New subscribers', 'daymark' ),
					/* translators: %d: number of new subscribers. */
					'body'  => sprintf( _n( '%d person subscribed to your site.', '%d people subscribed to your site.', $count, 'daymark' ), $count ),
					'url'   => Daymark_Routes::app_url(),
					'tag'   => 'followers-jetpack',
				)
			);
		}

		return count( $new_ones );
	}
}
