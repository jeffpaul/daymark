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
delete_option( 'daymark_welcome_pending' );
delete_option( 'daymark_version' );
delete_option( 'daymark_pages' );
delete_option( 'daymark_legacy_content_pages' );
delete_option( 'daymark_app_base' );
delete_option( 'daymark_legacy_app_base' );
delete_option( 'daymark_redirect_rule_added' );
delete_option( 'daymark_nav_routes_added' );
delete_option( 'daymark_bookmarklet_route_added' );
delete_option( 'daymark_subscriptions_db_version' );
delete_option( 'daymark_bridgy_fed_bridged' );
delete_option( 'daymark_jetpack_older_likes_cursor' );
// Settings -> Daymark's own settings (Daymark_Settings::options()).
delete_option( 'daymark_capture_location' );
delete_option( 'daymark_capture_weather' );
delete_option( 'daymark_capture_camera_metadata' );
delete_option( 'daymark_publish_location_publicly' );
delete_option( 'daymark_hold_imported_replies' );
delete_option( 'daymark_ai_auto_suggest' );
delete_option( 'daymark_subscription_poll_interval' );
delete_option( 'daymark_blogroll_public' );
delete_option( 'daymark_hide_notes_on_home' );
delete_option( 'daymark_compact_timeline' );
delete_option( 'daymark_app_badge_unread' );

// Per-user routing/filing preferences, notification read-state, dismissed
// notices and hints, and the rel=me profile URL used in h-card markup,
// across all users.
delete_metadata( 'user', 0, 'daymark_destination_prefs', '', true );
delete_metadata( 'user', 0, 'daymark_category_prefs', '', true );
delete_metadata( 'user', 0, 'daymark_notifications_seen', '', true );
delete_metadata( 'user', 0, 'daymark_notifications_read_before', '', true );
delete_metadata( 'user', 0, 'daymark_notification_read', '', true );
delete_metadata( 'user', 0, 'daymark_notification_unread', '', true );
delete_metadata( 'user', 0, 'daymark_notification_archived', '', true );
delete_metadata( 'user', 0, 'daymark_bookmark', '', true );
delete_metadata( 'user', 0, 'daymark_timeline_last_seen', '', true );
delete_metadata( 'user', 0, 'daymark_timeline_position', '', true );
delete_metadata( 'user', 0, 'daymark_interaction_hint_seen', '', true );
delete_metadata( 'user', 0, 'daymark_plugin_overlap_dismissed', '', true );
delete_metadata( 'user', 0, 'daymark_rel_me_url', '', true );

// Scheduled backflow sync events (recurring + pending one-off freshen) and
// the subscriptions poller.
wp_clear_scheduled_hook( 'daymark_backflow_sync' );
wp_clear_scheduled_hook( 'daymark_backflow_sync_now' );
wp_clear_scheduled_hook( 'daymark_subscription_poll' );
wp_clear_scheduled_hook( 'daymark_uploads_cleanup' );
// Per-subscription WebSub retry checks carry an argument, so clear every one.
wp_unschedule_hook( 'daymark_websub_verify_timeout' );
// Pending Featured Content share-image lookups (one per post, with an argument).
wp_unschedule_hook( 'daymark_featured_content_resolve_image' );
// Built-in Webmention sends and verifications (one per post or per
// received Webmention, with arguments).
wp_unschedule_hook( 'daymark_webmention_send' );
wp_unschedule_hook( 'daymark_webmention_verify' );

// The share image Daymark resolved for a post's remote Featured Content
// (issue #408) is a cache derived from that content, not content itself,
// so it goes; the Featured Content meta it came from stays with the post.
delete_post_meta_by_key( '_daymark_featured_content_image' );
delete_post_meta_by_key( '_daymark_featured_content_link_preview' );
// Built-in Webmention bookkeeping. `_webmentioned` and the other keys shared
// with the Webmention plugin stay, since that plugin reads them too.
delete_post_meta_by_key( '_daymark_webmention_source' );
delete_post_meta_by_key( '_daymark_webmention_attempts' );

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

// WebSub pending-verification markers and attempt counts.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time discovery of dynamically named transients.
$daymark_websub_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_daymark_websub_pending_' ) . '%',
		$wpdb->esc_like( '_transient_daymark_websub_attempts_' ) . '%'
	)
);

foreach ( $daymark_websub_transients as $daymark_option_name ) {
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

// Unfinished composer uploads (issue #483): parts of files that never
// finished uploading. A finished upload is an ordinary attachment and stays
// in the Media Library; only its "not used by a Mark yet" markers go.
delete_post_meta_by_key( '_daymark_staged_upload' );
delete_post_meta_by_key( '_daymark_staged_at' );

$daymark_upload_dir = wp_upload_dir( null, false );

if ( empty( $daymark_upload_dir['error'] ) && ! empty( $daymark_upload_dir['basedir'] ) ) {
	$daymark_parts_dir = trailingslashit( $daymark_upload_dir['basedir'] ) . 'daymark-uploads';

	if ( is_dir( $daymark_parts_dir ) ) {
		$daymark_part_files = glob( $daymark_parts_dir . '/*' );

		foreach ( is_array( $daymark_part_files ) ? $daymark_part_files : array() as $daymark_part_file ) {
			wp_delete_file( $daymark_part_file );
		}

		wp_delete_file( $daymark_parts_dir . '/.htaccess' );
		@rmdir( $daymark_parts_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort; a non-empty folder is left alone.
	}
}
