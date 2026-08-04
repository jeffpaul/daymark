<?php
/**
 * One-time migration from the plugin's previous identity, Moment (≤ 0.5.0).
 *
 * The plugin was renamed for its wordpress.org release: Moment became
 * Daymark, and posts ("Moments") became Marks. Every stored identifier
 * changed with it — options, user meta, post/comment meta, the block and
 * shortcode markup written into the section pages, cron hooks, and
 * transients. This routine converts an existing Moment install in place so
 * nothing is stranded: content keeps rendering, preferences survive, and
 * the persisted app base is carried over (a home-screen-installed app URL
 * must never move underneath its users, so migrated installs keep /moment
 * while fresh installs get /daymark).
 *
 * Lifecycle: ships in 0.6.0, runs at most once (keyed on the legacy
 * `moment_version` option), and is scheduled for removal — soft-deprecated
 * in the next minor release, removed in the one after. When it goes, the
 * legacy cleanup block in uninstall.php goes with it.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a Moment (≤ 0.5.0) install to Daymark storage. Runs once.
 */
final class Daymark_Migration {

	/**
	 * Migrate if a legacy Moment install is present. Idempotent and cheap:
	 * the guard is a single autoloaded-option read, and migration deletes
	 * that option, so this is a no-op on every later request.
	 *
	 * Hooked early on init (before routes read the app base) and called
	 * first thing on activation (before the app base is resolved fresh).
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( false === get_option( 'moment_version', false ) ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Perform the one-time conversion.
	 *
	 * @return void
	 */
	private static function migrate(): void {
		self::migrate_options();
		self::migrate_user_meta();
		self::migrate_post_and_comment_meta();
		self::migrate_section_pages();
		self::clear_legacy_cron_and_transients();
		self::deactivate_legacy_plugin();

		delete_option( 'moment_version' );

		if ( false === get_option( 'daymark_version', false ) ) {
			update_option( 'daymark_version', DAYMARK_VERSION );
		}

		// The meta renames below wrote straight to the database; drop any
		// stale object-cache entries so this request reads the new keys.
		wp_cache_flush();

		// Rewrite rules must be rebuilt for the carried-over app base. On
		// the init path our rules register at priority 10, so flush after;
		// on the activation path activate() registers and flushes itself.
		if ( doing_action( 'init' ) ) {
			add_action( 'init', 'flush_rewrite_rules', 99 );
		} else {
			flush_rewrite_rules();
		}
	}

	/**
	 * Carry options across: the section-page map and the persisted app
	 * base keep their values under the new names; bookkeeping-only options
	 * are dropped (activation rewrites them).
	 *
	 * @return void
	 */
	private static function migrate_options(): void {
		$renames = array(
			'moment_pages'    => 'daymark_pages',
			'moment_app_base' => 'daymark_app_base',
		);

		foreach ( $renames as $old => $new ) {
			$value = get_option( $old, null );

			if ( null !== $value && false === get_option( $new, false ) ) {
				update_option( $new, $value );
			}

			delete_option( $old );
		}

		delete_option( 'moment_activated' );
	}

	/**
	 * Rename per-user preferences (destination routing, category filing,
	 * notification read-state) for all users.
	 *
	 * @return void
	 */
	private static function migrate_user_meta(): void {
		global $wpdb;

		foreach ( array( 'destination_prefs', 'category_prefs', 'notifications_seen' ) as $suffix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk key rename across all users.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
					'daymark_' . $suffix,
					'moment_' . $suffix
				)
			);
		}
	}

	/**
	 * Rename all `_moment_*` post meta and comment meta to `_daymark_*`,
	 * including dynamic keys (`_moment_backflow_synced_{network}`), then
	 * special-case the marker: `_moment_is_moment` becomes
	 * `_daymark_is_mark` (a post is a Mark now).
	 *
	 * @return void
	 */
	private static function migrate_post_and_comment_meta(): void {
		global $wpdb;

		$like = $wpdb->esc_like( '_moment_' ) . '%';

		foreach ( array( $wpdb->postmeta, $wpdb->commentmeta ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk key rename; the prefix swap must cover dynamically named keys.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET meta_key = REPLACE(meta_key, '_moment_', '_daymark_') WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name.
					$like
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Marker rename, part of the same one-time migration.
		$wpdb->query(
			"UPDATE {$wpdb->postmeta} SET meta_key = '_daymark_is_mark' WHERE meta_key = '_daymark_is_moment'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name, literal keys.
		);
	}

	/**
	 * Rewrite the section pages' stored markup: `moment/*` blocks and
	 * `[moment_*]` shortcodes become their `daymark`/`[daymark_*]`
	 * equivalents, so the pages keep rendering and page adoption
	 * (find_view_page) keeps recognizing them.
	 *
	 * @return void
	 */
	private static function migrate_section_pages(): void {
		$map = get_option( 'daymark_pages', array() );

		if ( ! is_array( $map ) ) {
			return;
		}

		foreach ( $map as $page_id ) {
			$page = get_post( absint( $page_id ) );

			if ( ! $page instanceof WP_Post ) {
				continue;
			}

			$content = str_replace(
				array( '<!-- wp:moment/', '[moment_' ),
				array( '<!-- wp:daymark/', '[daymark_' ),
				$page->post_content
			);

			if ( $content !== $page->post_content ) {
				wp_update_post(
					array(
						'ID'           => $page->ID,
						'post_content' => $content,
					)
				);
			}
		}
	}

	/**
	 * Clear legacy scheduled events and transients. The daymark-named
	 * schedule self-heals on init, and cooldowns are cheap to re-earn.
	 *
	 * @return void
	 */
	private static function clear_legacy_cron_and_transients(): void {
		global $wpdb;

		wp_clear_scheduled_hook( 'moment_backflow_sync' );
		wp_clear_scheduled_hook( 'moment_backflow_sync_now' );
		delete_transient( 'moment_backflow_freshened' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discovery of dynamically named legacy transients.
		$cooldowns = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_moment_backflow_cooldown_' ) . '%'
			)
		);

		foreach ( $cooldowns as $option_name ) {
			delete_transient( str_replace( '_transient_', '', $option_name ) );
		}
	}

	/**
	 * Deactivate a still-active legacy Moment plugin (the zip-upload path,
	 * where Daymark is installed alongside rather than over it). Both
	 * plugins would otherwise register the same routes and blocks.
	 *
	 * @return void
	 */
	private static function deactivate_legacy_plugin(): void {
		$active = (array) get_option( 'active_plugins', array() );

		if ( ! in_array( 'moment/moment.php', $active, true ) ) {
			return;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( 'moment/moment.php' );
	}
}
