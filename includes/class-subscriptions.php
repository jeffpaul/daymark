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
	private const DB_VERSION = '1.0.0';

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
			site_icon_url varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			consecutive_failure_count int(10) unsigned NOT NULL DEFAULT 0,
			last_checked_at datetime DEFAULT NULL,
			last_manual_refresh_at datetime DEFAULT NULL,
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
	 * `source_type` (default `feed`), `site_title`, `site_icon_url`,
	 * `status` (default `active`).
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
			'site_icon_url'             => isset( $args['site_icon_url'] ) ? esc_url_raw( (string) $args['site_icon_url'] ) : '',
			'status'                    => $this->sanitize_status( $args['status'] ?? 'active' ),
			'consecutive_failure_count' => 0,
			'created_at'                => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WP data API; $wpdb->insert() parameterizes values internally.
		$inserted = $wpdb->insert(
			self::table_name(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
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

		$registry   = Daymark_Plugin::instance()->subscription_source_registry;
		$discovered = $registry->discover_feeds( $site_url );
		$feed       = isset( $discovered[0] ) && is_array( $discovered[0] ) ? $discovered[0] : array();
		$feed_url   = isset( $feed['url'] ) ? (string) $feed['url'] : '';

		if ( '' === $feed_url ) {
			return new WP_Error(
				'daymark_subscription_no_feed_found',
				__( 'No feed could be found at this URL.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		$created = $this->create(
			array(
				'site_url'    => $site_url,
				'feed_url'    => $feed_url,
				'source_type' => 'feed',
				'site_title'  => isset( $feed['title'] ) ? sanitize_text_field( (string) $feed['title'] ) : '',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$subscription_id = (int) $created;

		// Best-effort favicon lookup: a one-time enhancement, never a reason
		// to fail the subscribe request itself.
		$feed_source = $registry->get_source( 'feed' );

		if ( $feed_source instanceof Daymark_Subscription_Source_Feed ) {
			$favicon_url = $feed_source->get_favicon_url( $site_url );

			if ( '' !== $favicon_url ) {
				$this->update( $subscription_id, array( 'site_icon_url' => $favicon_url ) );
			}
		}

		return $subscription_id;
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
	 * Update status/failure-count/checked-at fields on an existing
	 * subscription. Only recognized columns are written; unknown keys in
	 * `$fields` are ignored rather than passed through to the query.
	 *
	 * Recognized `$fields` keys: `status`, `consecutive_failure_count`,
	 * `last_checked_at`, `last_manual_refresh_at`, `site_title`,
	 * `site_icon_url`.
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

		if ( array_key_exists( 'site_icon_url', $fields ) ) {
			$data['site_icon_url'] = esc_url_raw( (string) $fields['site_icon_url'] );
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
