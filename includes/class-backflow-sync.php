<?php
/**
 * Automatic conversation backflow syncing.
 *
 * Replies come back without anyone asking:
 *
 * - Push channels (ActivityPub, ATmosphere, Webmention) are real-time by
 *   nature — replies arrive as comments as soon as the network delivers
 *   them; nothing here is involved.
 * - API-polling connectors (Bluesky, Mastodon) are synced automatically:
 *   an hourly WP-Cron baseline over recent Marks, plus an opportunistic
 *   background freshen whenever the notifications feed is viewed and the
 *   last sync has gone stale. No manual sync control exists in the UI.
 *
 * Only real syndicated posts are auto-synced (references a connector
 * marked `backflow_supported`) — mocked demo references stay manual via
 * the sync-responses REST endpoint so demo comments never appear
 * unprompted. Per-response deduplication (by external ID) makes repeated
 * syncs safe; a per-post cooldown keeps polling polite.
 *
 * WP-Cron caveat (acceptable at personal-site scale): schedules fire on
 * page traffic unless the site wires system cron to wp-cron.php.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs automatic backflow syncs.
 */
class Daymark_Backflow_Sync {

	/**
	 * Recurring cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'daymark_backflow_sync';

	/**
	 * Transient marking the feed-view freshen as recent.
	 *
	 * @var string
	 */
	private const FRESHEN_TRANSIENT = 'daymark_backflow_freshened';

	/**
	 * Per-post cooldown transient prefix.
	 *
	 * @var string
	 */
	private const POST_COOLDOWN_PREFIX = 'daymark_backflow_cooldown_';

	/**
	 * Cooldown duration for a synced Mark.
	 *
	 * @var int
	 */
	public const POST_COOLDOWN_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Object-cache group used for the atomic cooldown lock.
	 *
	 * @var string
	 */
	private const POST_COOLDOWN_CACHE_GROUP = 'daymark_backflow_cooldown';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'sync_recent_marks' ) );
		// The freshen path schedules a single event on this hook; the
		// handler must be registered on every request, since the event
		// fires on a later one.
		add_action( self::CRON_HOOK . '_now', array( $this, 'sync_recent_marks' ) );
		// Self-heal the recurring schedule: sites where the plugin was
		// already active when this feature arrived never ran activation.
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/**
	 * Schedule the recurring sync. Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the schedule. Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Freshen the feed in the background when it goes stale.
	 *
	 * Called from the notifications endpoint: viewing the feed is not a
	 * sync request, but it is exactly when freshness matters — so a stale
	 * feed schedules an immediate single cron event (async; the view
	 * request itself is never slowed down).
	 *
	 * @return void
	 */
	public function maybe_freshen(): void {
		if ( false !== get_transient( self::FRESHEN_TRANSIENT ) ) {
			return;
		}

		/**
		 * Filters how long a notifications view treats the last backflow
		 * sync as fresh, in seconds.
		 *
		 * @param int $seconds Freshness window. Default 5 minutes.
		 */
		$window = (int) apply_filters( 'daymark_backflow_freshen_window', 5 * MINUTE_IN_SECONDS );

		set_transient( self::FRESHEN_TRANSIENT, time(), max( MINUTE_IN_SECONDS, $window ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK . '_now' ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK . '_now' );
		}
	}

	/**
	 * Sync replies for recent Marks with real syndicated posts.
	 *
	 * @return int Comments imported across all synced Marks.
	 */
	public function sync_recent_marks(): int {
		/**
		 * Filters how far back automatic backflow syncing looks, in days.
		 *
		 * Conversations on social posts have a shelf life; older Marks
		 * stop being polled.
		 *
		 * @param int $days Look-back window. Default 14.
		 */
		$days = (int) apply_filters( 'daymark_backflow_sync_days', 14 );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'after' => $days . ' days ago' ),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Mark lookup.
				'meta_query'     => array(
					array(
						'key'   => '_daymark_is_mark',
						'value' => '1',
					),
					array(
						'key'   => '_daymark_comment_backflow_enabled',
						'value' => '1',
					),
				),
			)
		);

		$imported      = 0;
		$notifications = Daymark_Plugin::instance()->notifications;

		foreach ( $query->posts as $post_id ) {
			$post_id  = (int) $post_id;
			$networks = $this->real_backflow_networks( $post_id );

			if ( array() === $networks ) {
				continue;
			}

			// Atomic acquire: only one process wins the lock per post, so
			// overlapping cron runs cannot both poll the same Mark.
			if ( ! $this->mark_cooldown( $post_id ) ) {
				continue;
			}

			$result = $notifications->import_responses( $post_id, $networks );

			if ( ! is_wp_error( $result ) && isset( $result['imported_count'] ) ) {
				$imported += (int) $result['imported_count'];
			}
		}

		return $imported;
	}

	/**
	 * Whether a Mark is inside its per-post sync cooldown.
	 *
	 * @param int $post_id Mark post ID.
	 * @return bool
	 */
	public function on_cooldown( int $post_id ): bool {
		$key = self::POST_COOLDOWN_PREFIX . $post_id;

		if ( false !== get_transient( $key ) ) {
			return true;
		}

		return false !== wp_cache_get( $key, self::POST_COOLDOWN_CACHE_GROUP );
	}

	/**
	 * Start the cooldown for a Mark, atomically.
	 *
	 * Acquires an object-cache lock so concurrent processes cannot double-
	 * poll the same post, then mirrors it into a transient so the cooldown
	 * survives cache flushes and separate cron requests. Returns whether
	 * this call won the lock (and therefore should do the work).
	 *
	 * @param int $post_id Mark post ID.
	 * @return bool True when the cooldown was just set (work may proceed).
	 */
	public function mark_cooldown( int $post_id ): bool {
		$key = self::POST_COOLDOWN_PREFIX . $post_id;

		if ( ! wp_cache_add( $key, time(), self::POST_COOLDOWN_CACHE_GROUP, self::POST_COOLDOWN_SECONDS ) ) {
			return false;
		}

		set_transient( $key, time(), self::POST_COOLDOWN_SECONDS );

		return true;
	}

	/**
	 * Whether syncing the given networks would hit a real connector.
	 *
	 * Mocked demo references never auto-sync and never trigger the manual
	 * sync cooldown, so repeated demo syncs keep deduplicating instead of
	 * returning 429s. Only real (backflow_supported) references are worth
	 * protecting from hammering.
	 *
	 * @param int      $post_id  Mark post ID.
	 * @param string[] $networks Network IDs to check.
	 * @return bool
	 */
	public function is_real_backflow_sync( int $post_id, array $networks ): bool {
		$external_posts = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );

		if ( ! is_array( $external_posts ) ) {
			return false;
		}

		foreach ( $networks as $network ) {
			$reference = $external_posts[ $network ] ?? null;

			if ( is_array( $reference ) && ! empty( $reference['backflow_supported'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Networks on a Mark eligible for automatic sync.
	 *
	 * Only references from real connectors (backflow_supported) qualify —
	 * mocked demo references are excluded so fake replies never appear
	 * without an explicit demo action.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string[] Network IDs.
	 */
	private function real_backflow_networks( int $post_id ): array {
		$external_posts = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );

		if ( ! is_array( $external_posts ) ) {
			return array();
		}

		$networks = array();

		foreach ( $external_posts as $network => $reference ) {
			if ( is_array( $reference ) && ! empty( $reference['backflow_supported'] ) ) {
				$networks[] = sanitize_key( (string) $network );
			}
		}

		return $networks;
	}
}
