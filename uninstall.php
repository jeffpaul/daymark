<?php
/**
 * Daymark uninstall cleanup.
 *
 * Removes plugin bookkeeping: options, per-user destination preferences,
 * backflow transients, and scheduled events.
 *
 * Content is deliberately preserved. Marks are standard WordPress posts,
 * their meta, comments, and the section pages created on activation all
 * remain intact and readable after the plugin is deleted — that is the
 * plugin's core portability promise.
 *
 * @package Daymark
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'daymark_activated' );
delete_option( 'daymark_version' );
delete_option( 'daymark_pages' );
delete_option( 'daymark_app_base' );

// Per-user routing/filing preferences and notification read-state,
// across all users.
delete_metadata( 'user', 0, 'daymark_destination_prefs', '', true );
delete_metadata( 'user', 0, 'daymark_category_prefs', '', true );
delete_metadata( 'user', 0, 'daymark_notifications_seen', '', true );

// Scheduled backflow sync events (recurring + pending one-off freshen).
wp_clear_scheduled_hook( 'daymark_backflow_sync' );
wp_clear_scheduled_hook( 'daymark_backflow_sync_now' );

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

// Legacy Moment (≤ 0.5.0) bookkeeping, in case the plugin is uninstalled
// before its one-time migration ever ran. Remove this block together with
// includes/class-migration.php.
delete_option( 'moment_activated' );
delete_option( 'moment_version' );
delete_option( 'moment_pages' );
delete_option( 'moment_app_base' );
delete_metadata( 'user', 0, 'moment_destination_prefs', '', true );
delete_metadata( 'user', 0, 'moment_category_prefs', '', true );
delete_metadata( 'user', 0, 'moment_notifications_seen', '', true );
wp_clear_scheduled_hook( 'moment_backflow_sync' );
wp_clear_scheduled_hook( 'moment_backflow_sync_now' );
delete_transient( 'moment_backflow_freshened' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time discovery of dynamically named transients.
$daymark_legacy_cooldowns = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_moment_backflow_cooldown_' ) . '%'
	)
);

foreach ( $daymark_legacy_cooldowns as $daymark_legacy_option_name ) {
	delete_transient( str_replace( '_transient_', '', $daymark_legacy_option_name ) );
}
