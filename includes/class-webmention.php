<?php
/**
 * Built-in Webmention: sending.
 *
 * Likes, reblogs, and comments only reach another site through a delivery
 * route (Jetpack, ActivityPub, Webmention, Bridgy Fed). Two plain Daymark
 * sites had none of them, so a Like was never offered, a Comment sent the
 * reader to the other site's comment form, and a Reblog was never counted
 * there. Webmention (https://www.w3.org/TR/webmention/) is the open
 * standard for "my page links to yours": the sender POSTs `source` and
 * `target` to the target's advertised endpoint, and the receiver fetches
 * the source to check the link and read what it is (a like, a reblog, a
 * reply).
 *
 * When the Webmention plugin (https://wordpress.org/plugins/webmention/)
 * is active, it does all of this and Daymark's own code stays off, so
 * nothing is sent or received twice. Otherwise Daymark does it itself:
 * this class sends, Daymark_Webmention_Receiver receives. Both work for
 * every public post, not only Marks: a reply or a like on a post written in
 * the block editor is as useful as one on a Mark.
 *
 * Sending reuses the Webmention plugin's own post meta (`_mentionme`,
 * `_webmentioned`, `_webmention_content_hash`), so Daymark_Like_Delivery's
 * delivery state reads the same way for either sender, and a site that
 * installs the plugin later knows what was already sent. The target list
 * goes through the same Daymark_Microformats::add_webmention_targets() the
 * plugin's `webmention_links` filter uses, so a Like, Reblog, or reply Mark
 * notifies its target and a Bridgy Fed route keeps working.
 *
 * Turn it off with `add_filter( 'daymark_builtin_webmention', '__return_false' )`.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daymark's own Webmention sender, plus the switches both halves share.
 */
class Daymark_Webmention {

	/**
	 * Cron hook that sends a post's Webmentions.
	 *
	 * @var string
	 */
	public const CRON_SEND = 'daymark_webmention_send';

	/**
	 * Post meta (shared with the Webmention plugin): queued, not yet sent.
	 *
	 * @var string
	 */
	public const META_PENDING = '_mentionme';

	/**
	 * Post meta (shared with the Webmention plugin): targets that accepted.
	 *
	 * @var string
	 */
	public const META_SENT = '_webmentioned';

	/**
	 * Post meta (shared with the Webmention plugin): hash of the last attempt.
	 *
	 * @var string
	 */
	public const META_HASH = '_webmention_content_hash';

	/**
	 * Post meta: the permalink Webmentions were sent from, kept so a
	 * deletion notice can name the same source after the post is trashed
	 * (a trashed post's permalink changes).
	 *
	 * @var string
	 */
	public const META_SOURCE = '_daymark_webmention_source';

	/**
	 * Post meta: send attempts that failed in a way worth retrying.
	 *
	 * @var string
	 */
	public const META_ATTEMPTS = '_daymark_webmention_attempts';

	/**
	 * Most links sent per post, so one long post can't fan out without end.
	 *
	 * @var int
	 */
	private const MAX_TARGETS = 30;

	/**
	 * Retries after a network error or a 5xx answer.
	 *
	 * @var int
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Whether Daymark's own sender and receiver are on: the Webmention
	 * plugin isn't active, and nothing turned them off.
	 *
	 * @return bool
	 */
	public static function builtin_active(): bool {
		if ( Daymark_Plugin_Detector::is_active( 'webmention' ) ) {
			return false;
		}

		/**
		 * Whether Daymark sends and receives Webmentions itself when the
		 * Webmention plugin isn't active.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'daymark_builtin_webmention', true );
	}

	/**
	 * Whether this site can send a Webmention at all, by either sender.
	 * The check every delivery route (Like, Comment, Bridgy Fed) uses.
	 *
	 * @return bool
	 */
	public static function can_send(): bool {
		return Daymark_Plugin_Detector::is_active( 'webmention' ) || self::builtin_active();
	}

	/**
	 * The built-in receiver's endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint_url(): string {
		return rest_url( 'daymark/v1/webmention' );
	}

	/**
	 * Post types that send Webmentions.
	 *
	 * @return string[]
	 */
	public static function sending_post_types(): array {
		/**
		 * Post types Daymark's built-in sender sends Webmentions for.
		 *
		 * @param string[] $post_types Default: posts, pages, and Like Marks.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'daymark_webmention_post_types', array( 'post', 'page', Daymark_Like_Visibility::POST_TYPE ) ) ) );
	}

	/**
	 * Whether two URLs name the same page: fragment, scheme, a trailing
	 * slash, and host case are ignored.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_url( string $a, string $b ): bool {
		return '' !== $a && self::comparable_url( $a ) === self::comparable_url( $b );
	}

	/**
	 * A URL reduced for same_url().
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function comparable_url( string $url ): string {
		$url  = (string) preg_replace( '/#.*$/', '', trim( $url ) );
		$url  = (string) preg_replace( '#^https?://#i', '', $url );
		$host = strtolower( (string) strtok( $url, '/' ) );
		$rest = (string) substr( $url, strlen( $host ) );

		return $host . untrailingslashit( $rest );
	}

	/**
	 * Hook up. Sending is scheduled from status changes; the cron event
	 * does the work, so publishing never waits on another site.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'maybe_schedule' ), 20, 3 );
		add_action( self::CRON_SEND, array( $this, 'send_for_post' ) );
	}

	/**
	 * Queue a send when a post is published, updated while published, or
	 * leaves published (so earlier targets hear it's gone).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public function maybe_schedule( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || ! self::builtin_active() ) {
			return;
		}

		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::sending_post_types(), true ) ) {
			return;
		}

		self::schedule( (int) $post->ID );
	}

	/**
	 * Queue one send for a post, unless one is already queued.
	 *
	 * @param int $post_id Post ID.
	 * @param int $delay   Seconds from now.
	 * @return void
	 */
	public static function schedule( int $post_id, int $delay = 0 ): void {
		update_post_meta( $post_id, self::META_PENDING, '1' );

		if ( false === wp_next_scheduled( self::CRON_SEND, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::CRON_SEND, array( $post_id ) );
		}
	}

	/**
	 * Send a post's Webmentions. A published post notifies every target it
	 * links to now, plus every target notified before (so one it no longer
	 * links to can drop it); a post that isn't published any more notifies
	 * only those earlier targets, whose fetch of the source then fails.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function send_for_post( $post_id ): void {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! self::builtin_active() ) {
			return;
		}

		$is_public = 'publish' === $post->post_status && '' === (string) $post->post_password;
		$previous  = self::sent_targets( $post_id );
		$source    = $is_public ? (string) get_permalink( $post ) : (string) get_post_meta( $post_id, self::META_SOURCE, true );

		if ( '' === $source ) {
			delete_post_meta( $post_id, self::META_PENDING );
			return;
		}

		$current = $is_public ? self::targets_for_post( $post ) : array();
		$hash    = md5( $post->post_status . "\n" . $source . "\n" . implode( "\n", $current ) . "\n" . $post->post_content );

		if ( $is_public && (string) get_post_meta( $post_id, self::META_HASH, true ) === $hash && empty( array_diff( $current, $previous ) ) ) {
			delete_post_meta( $post_id, self::META_PENDING );
			return;
		}

		$targets = array_values( array_unique( array_merge( $current, $previous ) ) );
		$sent    = array();
		$retry   = false;

		foreach ( $targets as $target ) {
			$result = self::send( $source, $target );

			if ( true === $result ) {
				if ( in_array( $target, $current, true ) ) {
					$sent[] = $target;
				}
			} elseif ( 'retry' === $result ) {
				$retry = true;

				// Keep an earlier success on record until a retry settles it.
				if ( in_array( $target, $previous, true ) && in_array( $target, $current, true ) ) {
					$sent[] = $target;
				}
			}
		}

		if ( $is_public ) {
			update_post_meta( $post_id, self::META_SOURCE, $source );
		}

		update_post_meta( $post_id, self::META_SENT, $sent );
		update_post_meta( $post_id, self::META_HASH, $hash );
		delete_post_meta( $post_id, self::META_PENDING );

		$attempts = (int) get_post_meta( $post_id, self::META_ATTEMPTS, true );

		if ( $retry && $attempts < self::MAX_RETRIES ) {
			update_post_meta( $post_id, self::META_ATTEMPTS, $attempts + 1 );
			// Clear the hash so the retry isn't skipped as "unchanged".
			delete_post_meta( $post_id, self::META_HASH );
			self::schedule( $post_id, 15 * MINUTE_IN_SECONDS * ( 2 ** $attempts ) );
		} else {
			delete_post_meta( $post_id, self::META_ATTEMPTS );
		}
	}

	/**
	 * Targets a post notifies: other sites' pages its content links to, plus
	 * a Like, Reblog, or reply Mark's target (and Bridgy Fed, when routed),
	 * through the same filter callback the Webmention plugin would use.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function targets_for_post( WP_Post $post ): array {
		$urls = self::links_in_html( (string) $post->post_content );
		$urls = Daymark_Plugin::instance()->microformats->add_webmention_targets( $urls, (int) $post->ID );

		$own_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$targets  = array();

		foreach ( $urls as $url ) {
			$url    = esc_url_raw( (string) $url, array( 'http', 'https' ) );
			$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

			if ( '' === $host || $host === $own_host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}

			$url = (string) preg_replace( '/#.*$/', '', $url );

			if ( ! in_array( $url, $targets, true ) ) {
				$targets[] = $url;
			}

			if ( count( $targets ) >= self::MAX_TARGETS ) {
				break;
			}
		}

		return $targets;
	}

	/**
	 * Absolute `href` values of the links in some HTML.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private static function links_in_html( string $html ): array {
		$urls = array();

		if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $urls;
		}

		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$href = trim( (string) ( $processor->get_attribute( 'href' ) ?? '' ) );

			if ( '' !== $href && preg_match( '#^https?://#i', $href ) ) {
				$urls[] = $href;
			}
		}

		return $urls;
	}

	/**
	 * Targets a post's earlier sends reached.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function sent_targets( int $post_id ): array {
		$sent = get_post_meta( $post_id, self::META_SENT, true );

		return is_array( $sent ) ? array_values( array_filter( $sent, 'is_string' ) ) : array();
	}

	/**
	 * Send one Webmention: find the target's endpoint, then POST to it.
	 *
	 * @param string $source Our page.
	 * @param string $target Their page.
	 * @return true|string True when accepted; 'retry' for a network error or
	 *                     5xx; 'skip' when the target takes no Webmentions
	 *                     or refused this one.
	 */
	private static function send( string $source, string $target ) {
		$signals = Daymark_Comment_Delivery::origin_signals_for_url( $target );

		if ( is_wp_error( $signals ) ) {
			return 'skip';
		}

		$endpoint = (string) ( $signals['webmention_endpoint'] ?? '' );

		if ( '' === $endpoint || is_wp_error( Daymark_Subscription_Url_Guard::check( $endpoint ) ) ) {
			return 'skip';
		}

		$response = Daymark_Outbound_Guard::post(
			$endpoint,
			array(
				'timeout'             => (int) apply_filters( 'daymark_webmention_send_timeout', 10 ),
				'redirection'         => 3,
				'limit_response_size' => 65536,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
				'body'                => array(
					'source' => $source,
					'target' => $target,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'retry';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return $code >= 500 ? 'retry' : 'skip';
	}
}
