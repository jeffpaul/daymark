<?php
/**
 * The `daymark_subscription` table: subscribed sites/feeds the user follows.
 *
 * A custom DB table, not a CPT (settled decision — see CLAUDE.md's
 * Subscriptions architecture notes and issue #78). This is simple
 * relational config: no revisions, no taxonomy, no trash lifecycle.
 * Unsubscribing deletes the row outright; there is no soft-deleted status.
 * `daymark_subscription_post` (the cached-content CPT, which does use core's
 * trash lifecycle) is a separate concern owned elsewhere.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + schema management for the `{$wpdb->prefix}daymark_subscriptions`
 * table.
 */
class Daymark_Subscriptions {

	/**
	 * Schema version. Bump this and extend get_schema_sql() when the table
	 * shape changes; install() re-runs dbDelta and records the new version.
	 *
	 * @var string
	 */
	private const DB_VERSION = '1.3.0';

	/**
	 * Option name storing the currently installed schema version.
	 *
	 * @var string
	 */
	private const DB_VERSION_OPTION = 'daymark_subscriptions_db_version';

	/**
	 * Allowed `status` values.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'active', 'error' );

	/**
	 * Allowed `websub_status` values (issue #82). 'none' is the default for
	 * every existing/new subscription — most feeds never advertise a hub at
	 * all, and the polling cron is the only path they ever use. 'pending'
	 * covers the window between sending a subscribe request and the hub's
	 * own verification GET completing; 'verified' means the hub confirmed
	 * the subscription and push delivery is expected; 'failed' means the
	 * hub rejected the request or verification never completed. None of
	 * these ever stop the existing polling cron from running — WebSub is
	 * purely an additive, faster delivery path layered on top of it, not a
	 * replacement (see Daymark_Websub_Subscriber).
	 *
	 * @var string[]
	 */
	private const WEBSUB_STATUSES = array( 'none', 'pending', 'verified', 'failed' );

	/**
	 * Unprefixed table name.
	 *
	 * @var string
	 */
	private const TABLE = 'daymark_subscriptions';

	/**
	 * Fully qualified, prefixed table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table if missing, or upgrade it if the schema version on
	 * disk is newer than the one recorded in the DB. dbDelta() itself is
	 * idempotent (CREATE TABLE / ALTER as needed), so this is safe to call
	 * more than once; the version option just avoids the dbDelta round trip
	 * on every request.
	 *
	 * Hooked to plugin activation only (Daymark_Plugin::activate()), matching
	 * this codebase's existing activation-hook pattern (e.g.
	 * Daymark_Backflow_Sync::schedule()).
	 *
	 * @return void
	 */
	public static function install(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

		if ( self::DB_VERSION === $installed_version ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::get_schema_sql() );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Builds the dbDelta-formatted CREATE TABLE statement for this table.
	 *
	 * `feed_url` is UNIQUE at the DB level — a duplicate active subscription
	 * to the same feed must fail, not silently succeed (PRD "Subscribing").
	 * It is capped at varchar(191) rather than 255 so the unique index stays
	 * under the 767-byte prefix limit older MySQL/InnoDB configurations
	 * impose on a 4-byte-per-character utf8mb4 index (191 * 4 = 764 bytes).
	 *
	 * `source_type` is a short string, not an ENUM, so future values
	 * (`friends`, `activitypub`, `custom`) never require a schema change.
	 *
	 * `feed_title` (added in 1.1.0) is the feed's own title — e.g. the
	 * autodiscovery `<link>` tag's title attribute, often "{Site Name} »
	 * Feed" or a category/tag-scoped feed's own name — kept distinct from
	 * `site_title` (the plain site name) so a future multi-feed-per-site
	 * subscription has something to tell otherwise-identical rows apart by.
	 *
	 * `last_error` (added in 1.2.0, issue #81) is a human-readable reason for
	 * the most recent failed check — the actual WP_Error message where one
	 * exists, or a specific message for a pre-flight rejection (oversized
	 * response, unsafe URL). Cleared back to '' on the next successful check.
	 * Distinct from `status`/`consecutive_failure_count`, which only ever
	 * capture *that* a subscription is failing, not *why*.
	 *
	 * `websub_hub_url`/`websub_status`/`websub_lease_expires_at`/`websub_secret`
	 * (added in 1.3.0, issue #82) track a WebSub (PubSubHubbub) push
	 * subscription for feeds that advertise a hub — see Daymark_Websub_Subscriber
	 * and Daymark_Websub_Endpoint. `websub_secret` is the per-subscription
	 * HMAC secret the hub echoes signed content deliveries with; it is
	 * never exposed outside this table.
	 *
	 * @return string
	 */
	private static function get_schema_sql(): string {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- dbDelta requires this exact formatting (two spaces before the PRIMARY KEY column list); do not reflow.
		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_url varchar(255) NOT NULL DEFAULT '',
			feed_url varchar(191) NOT NULL DEFAULT '',
			source_type varchar(32) NOT NULL DEFAULT 'feed',
			site_title varchar(255) NOT NULL DEFAULT '',
			feed_title varchar(255) NOT NULL DEFAULT '',
			site_icon_url varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			consecutive_failure_count int(10) unsigned NOT NULL DEFAULT 0,
			last_checked_at datetime DEFAULT NULL,
			last_manual_refresh_at datetime DEFAULT NULL,
			last_error varchar(255) NOT NULL DEFAULT '',
			websub_hub_url varchar(255) NOT NULL DEFAULT '',
			websub_status varchar(20) NOT NULL DEFAULT 'none',
			websub_lease_expires_at datetime DEFAULT NULL,
			websub_secret varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY feed_url (feed_url),
			KEY status (status)
		) {$charset_collate};";
		// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
	}

	/**
	 * Create a subscription row.
	 *
	 * Performs an application-level duplicate check first (for a clear,
	 * specific error message), then relies on the table's UNIQUE `feed_url`
	 * index as the authoritative guard against a race between the check and
	 * the insert — a duplicate insert fails cleanly with a WP_Error rather
	 * than a fatal either way.
	 *
	 * Recognized `$args` keys: `site_url`, `feed_url` (required),
	 * `source_type` (default `feed`), `site_title`, `feed_title`,
	 * `site_icon_url`, `status` (default `active`).
	 *
	 * @param array $args Subscription fields.
	 * @return int|WP_Error New row ID, or WP_Error on failure.
	 */
	public function create( array $args ) {
		global $wpdb;

		$feed_url = isset( $args['feed_url'] ) ? esc_url_raw( (string) $args['feed_url'] ) : '';

		if ( '' === $feed_url ) {
			return new WP_Error(
				'daymark_subscription_invalid_feed_url',
				__( 'A feed URL is required to create a subscription.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		if ( $this->get_by_feed_url( $feed_url ) ) {
			return new WP_Error(
				'daymark_subscription_duplicate',
				__( 'A subscription for this feed already exists.', 'daymark' ),
				array( 'status' => 409 )
			);
		}

		$data = array(
			'site_url'                  => isset( $args['site_url'] ) ? esc_url_raw( (string) $args['site_url'] ) : '',
			'feed_url'                  => $feed_url,
			'source_type'               => $this->sanitize_source_type( $args['source_type'] ?? 'feed' ),
			'site_title'                => isset( $args['site_title'] ) ? sanitize_text_field( (string) $args['site_title'] ) : '',
			'feed_title'                => isset( $args['feed_title'] ) ? sanitize_text_field( (string) $args['feed_title'] ) : '',
			'site_icon_url'             => isset( $args['site_icon_url'] ) ? esc_url_raw( (string) $args['site_icon_url'] ) : '',
			'status'                    => $this->sanitize_status( $args['status'] ?? 'active' ),
			'consecutive_failure_count' => 0,
			'created_at'                => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API; $wpdb->insert() parameterizes values internally.
		$inserted = $wpdb->insert(
			self::table_name(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			// Covers the race between the check above and this insert
			// (e.g. two concurrent subscribe requests for the same feed):
			// the UNIQUE key rejects the second row instead of a fatal.
			return new WP_Error(
				'daymark_subscription_insert_failed',
				__( 'This subscription could not be saved. It may already exist.', 'daymark' ),
				array( 'status' => 409 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Subscribe to a site by URL: validate the URL, discover its feed via
	 * the subscription source registry, create the row, then best-effort
	 * resolve its favicon.
	 *
	 * Extracted from what was originally inlined in
	 * Daymark_REST_Controller::create_subscription() (issue #78) so the
	 * REST endpoint and the wp-admin Settings screen's subscribe-by-URL form
	 * share one implementation. Deliberately excludes anything caller-surface
	 * specific: no rate limiting (each caller applies its own — the REST
	 * route and the admin-post handler use different limiter call shapes)
	 * and no response shaping.
	 *
	 * Reuses the exact WP_Error codes/messages/statuses this method's REST
	 * caller has always produced, so its contract does not change:
	 * `daymark_subscription_invalid_url` (400), `daymark_subscription_no_feed_found`
	 * (422), plus whatever create() itself returns for a duplicate/insert
	 * failure.
	 *
	 * @param string $site_url Site URL to subscribe to (not a feed URL directly).
	 * @return int|WP_Error New subscription row ID, or WP_Error on failure.
	 */
	public function subscribe_to_site( string $site_url ) {
		$site_url = $this->normalize_site_url( $site_url );
		$scheme   = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );
		$host     = (string) wp_parse_url( $site_url, PHP_URL_HOST );

		if ( '' === $host || false !== strpos( $host, ' ' ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'daymark_subscription_invalid_url',
				__( 'Please enter a valid site URL.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// SSRF hardening (issue #81): a rejected URL fails the exact same way
		// an invalid one already does above — no new failure shape for a
		// caller to special-case.
		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $site_url ) ) ) {
			return new WP_Error(
				'daymark_subscription_invalid_url',
				__( 'Please enter a valid site URL.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$registry = Daymark_Plugin::instance()->subscription_source_registry;

		// issue #183: try $site_url as a literal feed URL first, before ever
		// running page-based discovery — lets a caller who already knows a
		// specific feed's own URL (e.g. a Notes archive's `/notes/feed/`,
		// distinct from the same site's main `/feed/`) subscribe to exactly
		// that feed. This has to come *before* discover_feeds() below, not
		// merely as a fallback after it fails: an ordinary page URL almost
		// never also parses as a valid feed, so this adds no wrong turn for
		// the common case, but a URL that *is* already a feed would
		// otherwise be handed to page-based discovery — which fetches it as
		// HTML and finds nothing — instead of being used directly. See
		// Daymark_Subscription_Source_Feed::discover_direct_feed()'s own
		// docblock for why a second feed on an already-subscribed WordPress
		// site can never be reached any other way.
		$feed_source = $registry->get_source( 'feed' );
		$discovered  = ( $feed_source instanceof Daymark_Subscription_Source_Feed )
			? array_map(
				static function ( array $candidate ): array {
					$candidate['source_type'] = 'feed';

					return $candidate;
				},
				$feed_source->discover_direct_feed( $site_url )
			)
			: array();

		if ( empty( $discovered ) ) {
			$discovered = $registry->discover_feeds( $site_url );
		}

		$feed     = isset( $discovered[0] ) && is_array( $discovered[0] ) ? $discovered[0] : array();
		$feed_url = isset( $feed['url'] ) ? (string) $feed['url'] : '';

		// issue #183: the winning source resolved to a feed this site is
		// already subscribed to under a different page's URL — most often
		// Daymark_Subscription_Source_WordPress, which always resolves to
		// the same site-wide `wp/v2/posts` collection no matter which page
		// discovery started from. Retry with that source excluded so the
		// next-preferred source (typically the RSS/Atom `feed` source,
		// reading *this* page's own `<link rel="alternate">`) gets a chance
		// to find the differently-scoped feed the user actually meant,
		// rather than failing with a duplicate-subscription error for a feed
		// that's already subscribed.
		if ( '' !== $feed_url && $this->get_by_feed_url( $feed_url ) ) {
			$retry_source_type = isset( $feed['source_type'] ) ? sanitize_key( (string) $feed['source_type'] ) : '';
			$retry_discovered  = $registry->discover_feeds( $site_url, array( $retry_source_type ) );
			$retry_feed        = isset( $retry_discovered[0] ) && is_array( $retry_discovered[0] ) ? $retry_discovered[0] : array();
			$retry_feed_url    = isset( $retry_feed['url'] ) ? (string) $retry_feed['url'] : '';

			if ( '' !== $retry_feed_url && ! $this->get_by_feed_url( $retry_feed_url ) ) {
				$feed     = $retry_feed;
				$feed_url = $retry_feed_url;
			}
		}

		if ( '' === $feed_url ) {
			return new WP_Error(
				'daymark_subscription_no_feed_found',
				__( 'No feed could be found at this URL.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		// Which source actually discovered this (issue #84: 'feed' or
		// 'microformats', per discover_feeds()'s registration-order
		// precedence — never hardcoded, since either may win depending on
		// what the site publishes).
		$source_type = isset( $feed['source_type'] ) ? sanitize_key( (string) $feed['source_type'] ) : 'feed';

		// The feed's own title (often WordPress's default "{Site Name} »
		// Feed" convention, or a category/tag-scoped feed's own name) is
		// kept as feed_title — distinct from site_title, the plain site
		// name a reviewer actually wants to see on the Settings screen and
		// Timeline. Used as site_title's own initial value too, so there is
		// still a reasonable label if the plain-title lookup below fails.
		$feed_title = isset( $feed['title'] ) ? sanitize_text_field( (string) $feed['title'] ) : '';

		$created = $this->create(
			array(
				'site_url'    => $site_url,
				'feed_url'    => $feed_url,
				'source_type' => $source_type,
				'site_title'  => $feed_title,
				'feed_title'  => $feed_title,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$subscription_id = (int) $created;

		// Best-effort enhancements: never a reason to fail the subscribe
		// request itself.
		$feed_source = $registry->get_source( 'feed' );

		if ( $feed_source instanceof Daymark_Subscription_Source_Feed ) {
			$favicon_url = $feed_source->get_favicon_url( $site_url );

			if ( '' !== $favicon_url ) {
				$this->update( $subscription_id, array( 'site_icon_url' => $favicon_url ) );
			}

			// Refines site_title from the feed_title placeholder above to
			// the site's own plain <title> — e.g. "Jeff Paul" rather than
			// "Jeff Paul » Feed". Reuses get_favicon_url()'s already-fetched
			// site HTML (both call fetch_html() against the same $site_url,
			// which caches per instance) — no extra request.
			$site_title = $feed_source->get_site_title( $site_url );

			if ( '' !== $site_title ) {
				$this->update( $subscription_id, array( 'site_title' => $site_title ) );
			}
		}

		return $subscription_id;
	}

	/**
	 * Re-run site-icon discovery for an existing subscription and update its
	 * `site_icon_url` (issue #94).
	 *
	 * A dedicated method rather than folding this into
	 * Daymark_Subscription_Poller::poll_subscription()/manual_refresh():
	 * neither of those ever touches `site_icon_url` today — it is resolved
	 * once, at subscribe time, inside subscribe_to_site() above, and never
	 * revisited afterward. That is a real gap for a subscription imported
	 * from a non-Daymark OPML file (see Daymark_Subscription_OPML), which
	 * typically arrives with no cached icon at all, and for any site that
	 * simply added or changed its favicon after the original subscribe.
	 * This closes that gap on demand, without adding an automatic/scheduled
	 * icon refresh the issue explicitly does not ask for.
	 *
	 * Reuses Daymark_Subscription_Source_Feed::get_favicon_url()'s own
	 * per-instance HTML-fetch memoization, so this is exactly one outbound
	 * request per call — the same request subscribe_to_site() already made
	 * once, just repeated on demand.
	 *
	 * @since 0.10.0
	 *
	 * @param int $id Subscription ID.
	 * @return true|WP_Error True once `site_icon_url` is updated; WP_Error
	 *                       when the subscription doesn't exist, its source
	 *                       type doesn't support icon discovery, or no icon
	 *                       could be found (a real, expected outcome for a
	 *                       site with no favicon — not a fatal).
	 */
	public function refresh_icon( int $id ) {
		$subscription = $this->get( $id );

		if ( null === $subscription ) {
			return new WP_Error(
				'daymark_subscription_not_found',
				__( 'Subscription not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$registry = Daymark_Plugin::instance()->subscription_source_registry;
		$source   = $registry->get_source( sanitize_key( (string) ( $subscription['source_type'] ?? '' ) ) );

		$favicon_url = $source instanceof Daymark_Subscription_Source_Feed
			? $source->get_favicon_url( (string) ( $subscription['site_url'] ?? '' ) )
			: '';

		if ( '' === $favicon_url ) {
			return new WP_Error(
				'daymark_subscription_icon_not_found',
				__( 'No site icon could be found.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		$this->update( $id, array( 'site_icon_url' => $favicon_url ) );

		return true;
	}

	/**
	 * Assume `https://` for a site URL typed without a scheme (e.g.
	 * `example.com`) rather than reject it outright — the common case for
	 * someone typing a bare domain into the subscribe form, same as most
	 * browsers' own address bars. Anything that already has a scheme
	 * (`http://`, `https://`, or otherwise) is returned unchanged;
	 * subscribe_to_site()'s own scheme/host validation right after this
	 * call is what actually rejects a still-invalid result (e.g. a
	 * non-http(s) scheme, or input that never had a real host to begin
	 * with) — this method only fills in a missing scheme, it does not
	 * validate.
	 *
	 * @param string $site_url Raw input from the subscribe form.
	 * @return string
	 */
	private function normalize_site_url( string $site_url ): string {
		$site_url = trim( $site_url );

		if ( '' !== $site_url && ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $site_url ) ) {
			$site_url = 'https://' . $site_url;
		}

		return $site_url;
	}

	/**
	 * Get a subscription by ID.
	 *
	 * @param int $id Subscription ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 ) {
			return null;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a subscription by its feed URL (used for duplicate detection
	 * before insert, and for connector lookups).
	 *
	 * @param string $feed_url Feed URL.
	 * @return array<string, mixed>|null
	 */
	public function get_by_feed_url( string $feed_url ): ?array {
		global $wpdb;

		$feed_url = esc_url_raw( $feed_url );

		if ( '' === $feed_url ) {
			return null;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE feed_url = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
				$feed_url
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * List all active subscriptions, most recently created first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active(): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
				'active'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List every subscription regardless of status, most recently created
	 * first. Mirrors get_active()'s exact query shape, minus the `WHERE`
	 * clause — for a management screen (issue #78's wp-admin Settings
	 * screen) that needs to show active *and* error-status rows together,
	 * unlike get_active()/get_flagged() which each intentionally show only
	 * one status.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all(): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input; no user-supplied values in this query.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List all subscriptions flagged dead (`status` = 'error'), most
	 * recently created first.
	 *
	 * Mirrors get_active()'s exact query shape, just filtering on the
	 * opposite status value. This is a read-only accessor over a flag that
	 * Daymark_Subscription_Poller::record_failed_check() already sets after
	 * 7 consecutive failed checks (see DEAD_FEED_FAILURE_THRESHOLD there) —
	 * this method does not decide when a subscription becomes flagged, it
	 * only surfaces the ones that already are, for
	 * Daymark_Notifications::get_notifications() to turn into
	 * `dead_feed` notification items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_flagged(): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
				'error'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List every subscription currently in a failing state — `consecutive_failure_count`
	 * greater than zero — regardless of whether it's already been flagged
	 * fully dead (`status` = 'error', 7+ consecutive failures) or is still
	 * `active` but has failed at least once since its last success (issue
	 * #182). Unlike get_flagged() (only the fully-dead case), this is what
	 * lets Settings -> Daymark's admin notice and its table surface a
	 * developing problem before it reaches the dead threshold.
	 *
	 * `consecutive_failure_count` is reset to 0 on the very next successful
	 * check (Daymark_Subscription_Poller::record_successful_check()), so a
	 * subscription that recovers drops out of this list on its own, with no
	 * separate "clear the issue" step needed anywhere.
	 *
	 * @since 0.11.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_with_issues(): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE consecutive_failure_count > 0 ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update status/failure-count/checked-at fields on an existing
	 * subscription. Only recognized columns are written; unknown keys in
	 * `$fields` are ignored rather than passed through to the query.
	 *
	 * Recognized `$fields` keys: `status`, `consecutive_failure_count`,
	 * `last_checked_at`, `last_manual_refresh_at`, `site_title`,
	 * `feed_title`, `site_icon_url`, `last_error`, `websub_hub_url`,
	 * `websub_status`, `websub_lease_expires_at`, `websub_secret`.
	 *
	 * @param int   $id     Subscription ID.
	 * @param array $fields Fields to update.
	 * @return bool True on success (including a no-op with nothing
	 *              recognized to update), false on a DB error.
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 ) {
			return false;
		}

		$data   = array();
		$format = array();

		if ( array_key_exists( 'status', $fields ) ) {
			$data['status'] = $this->sanitize_status( (string) $fields['status'] );
			$format[]       = '%s';
		}

		if ( array_key_exists( 'consecutive_failure_count', $fields ) ) {
			$data['consecutive_failure_count'] = max( 0, absint( $fields['consecutive_failure_count'] ) );
			$format[]                          = '%d';
		}

		if ( array_key_exists( 'last_checked_at', $fields ) ) {
			$data['last_checked_at'] = $this->sanitize_datetime( $fields['last_checked_at'] );
			$format[]                = '%s';
		}

		if ( array_key_exists( 'last_manual_refresh_at', $fields ) ) {
			$data['last_manual_refresh_at'] = $this->sanitize_datetime( $fields['last_manual_refresh_at'] );
			$format[]                       = '%s';
		}

		if ( array_key_exists( 'site_title', $fields ) ) {
			$data['site_title'] = sanitize_text_field( (string) $fields['site_title'] );
			$format[]           = '%s';
		}

		if ( array_key_exists( 'feed_title', $fields ) ) {
			$data['feed_title'] = sanitize_text_field( (string) $fields['feed_title'] );
			$format[]           = '%s';
		}

		if ( array_key_exists( 'last_error', $fields ) ) {
			// Truncated to fit the varchar(255) column — a verbose WP_Error
			// message (e.g. a raw SimplePie parse error) should not risk a
			// DB-level truncation warning under strict SQL modes.
			$data['last_error'] = substr( sanitize_text_field( (string) $fields['last_error'] ), 0, 255 );
			$format[]           = '%s';
		}

		if ( array_key_exists( 'site_icon_url', $fields ) ) {
			$data['site_icon_url'] = esc_url_raw( (string) $fields['site_icon_url'] );
			$format[]              = '%s';
		}

		if ( array_key_exists( 'websub_hub_url', $fields ) ) {
			$data['websub_hub_url'] = esc_url_raw( (string) $fields['websub_hub_url'] );
			$format[]               = '%s';
		}

		if ( array_key_exists( 'websub_status', $fields ) ) {
			$data['websub_status'] = $this->sanitize_websub_status( (string) $fields['websub_status'] );
			$format[]              = '%s';
		}

		if ( array_key_exists( 'websub_lease_expires_at', $fields ) ) {
			$data['websub_lease_expires_at'] = $this->sanitize_datetime( $fields['websub_lease_expires_at'] );
			$format[]                        = '%s';
		}

		if ( array_key_exists( 'websub_secret', $fields ) ) {
			// Capped to fit the varchar(64) column; the generated secret
			// (Daymark_Websub_Subscriber) is well under this, but never trust
			// an input's length blindly against a fixed-width column.
			$data['websub_secret'] = substr( sanitize_text_field( (string) $fields['websub_secret'] ), 0, 64 );
			$format[]              = '%s';
		}

		if ( empty( $data ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API; $wpdb->update() parameterizes values internally.
		$updated = $wpdb->update(
			self::table_name(),
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Increment `consecutive_failure_count` by one (a failed poll check).
	 *
	 * @param int $id Subscription ID.
	 * @return int|false New failure count, or false if the subscription
	 *                   does not exist or the update failed.
	 */
	public function increment_failure_count( int $id ) {
		$subscription = $this->get( $id );

		if ( null === $subscription ) {
			return false;
		}

		$next = absint( $subscription['consecutive_failure_count'] ) + 1;

		if ( ! $this->update( $id, array( 'consecutive_failure_count' => $next ) ) ) {
			return false;
		}

		return $next;
	}

	/**
	 * Reset `consecutive_failure_count` to 0 (a successful poll check).
	 *
	 * @param int $id Subscription ID.
	 * @return bool
	 */
	public function reset_failure_count( int $id ): bool {
		return $this->update( $id, array( 'consecutive_failure_count' => 0 ) );
	}

	/**
	 * Delete a subscription outright. Unsubscribing removes this row
	 * entirely — the `status` enum only ever holds `active`/`error`; there
	 * is no soft-deleted state to fall into.
	 *
	 * @param int $id Subscription ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API; $wpdb->delete() parameterizes values internally.
		$deleted = $wpdb->delete(
			self::table_name(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return ! empty( $deleted );
	}

	/**
	 * Unsubscribe: trash every cached `daymark_subscription_post` ingested
	 * from this subscription (core's own 7-day trash retention handles
	 * eventual deletion — no custom cleanup logic needed), then delete the
	 * subscription row itself via delete() above.
	 *
	 * Shared by both surfaces that let a user unsubscribe — DELETE
	 * /daymark/v1/subscriptions/{id} and the wp-admin Settings -> Daymark
	 * screen — so a cached copy of a site's content is never orphaned
	 * (left behind forever with no subscription row to prune it) no matter
	 * which surface removed the subscription.
	 *
	 * @param int $id Subscription ID.
	 * @return array{deleted: bool, trashed_posts: int}
	 */
	public function unsubscribe( int $id ): array {
		$query = new WP_Query(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale unsubscribe cleanup.
				'meta_query'     => array(
					array(
						'key'     => 'subscription_id',
						'value'   => $id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$trashed_count = 0;

		foreach ( $query->posts as $post_id ) {
			if ( wp_trash_post( (int) $post_id ) ) {
				++$trashed_count;
			}
		}

		return array(
			'deleted'       => $this->delete( $id ),
			'trashed_posts' => $trashed_count,
		);
	}

	/**
	 * Sanitize a `status` value, falling back to 'active' for anything
	 * unrecognized rather than writing an invalid enum value.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function sanitize_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, self::STATUSES, true ) ? $status : 'active';
	}

	/**
	 * Sanitize a `websub_status` value, falling back to 'none' for anything
	 * unrecognized.
	 *
	 * @param string $status Raw WebSub status.
	 * @return string
	 */
	private function sanitize_websub_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, self::WEBSUB_STATUSES, true ) ? $status : 'none';
	}

	/**
	 * Sanitize a `source_type` value. Deliberately not restricted to a
	 * fixed enum (only 'feed' ships today, but 'friends', 'activitypub',
	 * and 'custom' are reserved future values) — just constrained to a
	 * short, safe key so it always fits the column.
	 *
	 * @param string $source_type Raw source type.
	 * @return string
	 */
	private function sanitize_source_type( string $source_type ): string {
		$source_type = sanitize_key( $source_type );

		return '' !== $source_type ? substr( $source_type, 0, 32 ) : 'feed';
	}

	/**
	 * Sanitize a nullable MySQL datetime value.
	 *
	 * @param string|null $value Raw value.
	 * @return string|null
	 */
	private function sanitize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = sanitize_text_field( (string) $value );

		// Reject anything that isn't a plausible MySQL datetime rather than
		// writing malformed data into a datetime column.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return null;
		}

		return $value;
	}
}
