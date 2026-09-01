<?php
/**
 * Daymark uninstall cleanup.
 *
 * Removes plugin bookkeeping: options, per-user destination preferences,
 * backflow and rate-limiter transients, scheduled events, the
 * subscriptions table, and cached subscription content.
 *
 * Marks are deliberately preserved. They are standard WordPress posts, and
 * their meta and comments remain intact and readable after the plugin is
 * deleted — that is the plugin's core portability promise. (Any trashed
 * Images/Videos/Audio/Notes section page from a pre-Unreleased install is
 * left exactly as WordPress's own trash lifecycle already has it — this
 * file only forgets which slugs those were.) The subscriptions table and cached
 * `daymark_sub_post` content are different: they hold plugin-owned config
 * and copies of other sites' content, not the user's own work, so this
 * file removes both outright instead of preserving them.
 *
 * @package Daymark
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'daymark_activated' );
delete_option( 'daymark_version' );
delete_option( 'daymark_pages' );
delete_option( 'daymark_legacy_content_pages' );
delete_option( 'daymark_app_base' );
delete_option( 'daymark_legacy_app_base' );
delete_option( 'daymark_redirect_rule_added' );
delete_option( 'daymark_nav_routes_added' );
delete_option( 'daymark_subscriptions_db_version' );

// Per-user routing/filing preferences, notification read-state, and the
// rel=me profile URL used in h-card markup, across all users.
delete_metadata( 'user', 0, 'daymark_destination_prefs', '', true );
delete_metadata( 'user', 0, 'daymark_category_prefs', '', true );
delete_metadata( 'user', 0, 'daymark_notifications_seen', '', true );
delete_metadata( 'user', 0, 'daymark_rel_me_url', '', true );

// Scheduled backflow sync events (recurring + pending one-off freshen) and
// the subscriptions poller.
wp_clear_scheduled_hook( 'daymark_backflow_sync' );
wp_clear_scheduled_hook( 'daymark_backflow_sync_now' );
wp_clear_scheduled_hook( 'daymark_subscription_poll' );

// Backflow transients: the freshen marker plus per-post sync cooldowns.
delete_transient( 'daymark_backflow_freshened' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time discovery of dynamically named transients.
$daymark_cooldowns = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_daymark_backflow_cooldown_' ) . '%'
	)
);

foreach ( $daymark_cooldowns as $daymark_option_name ) {
	delete_transient( str_replace( '_transient_', '', $daymark_option_name ) );
}

// Rate-limiter transients: one per user per throttled action (AI, publish,
// sync). Same dynamic-name lookup as the backflow cooldowns above.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time discovery of dynamically named transients.
$daymark_rate_limits = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_daymark_rl_' ) . '%'
	)
);

foreach ( $daymark_rate_limits as $daymark_rate_limit_option_name ) {
	delete_transient( str_replace( '_transient_', '', $daymark_rate_limit_option_name ) );
}

// Cached copies of other sites' content, ingested through Subscriptions.
// This is not the user's own content, so the portability promise above
// does not cover it. A normal unsubscribe relies on WordPress's own trash
// and 7-day retention, but the whole plugin is gone here, so delete every
// cached post outright instead of waiting on that retention window.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time discovery of cached subscription posts in every status, including already-trashed ones.
$daymark_subscription_post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		'daymark_sub_post'
	)
);

foreach ( $daymark_subscription_post_ids as $daymark_subscription_post_id ) {
	wp_delete_post( (int) $daymark_subscription_post_id, true );
}

// The subscriptions config table (site URL, feed URL, status, failure
// count). The plugin creates this table and owns it fully, unlike Marks,
// so uninstall drops it outright.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time removal of the plugin's own table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}daymark_subscriptions" );
