<?php
/**
 * Moment uninstall cleanup.
 *
 * Removes plugin bookkeeping: options, per-user destination preferences,
 * backflow transients, and scheduled events.
 *
 * Content is deliberately preserved. Moments are standard WordPress posts,
 * their meta, comments, and the section pages created on activation all
 * remain intact and readable after the plugin is deleted — that is the
 * plugin's core portability promise.
 *
 * @package Moment
 * @since   0.4.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'moment_activated' );
delete_option( 'moment_version' );
delete_option( 'moment_pages' );
delete_option( 'moment_app_base' );

// Per-user routing/filing preferences and notification read-state,
// across all users.
delete_metadata( 'user', 0, 'moment_destination_prefs', '', true );
delete_metadata( 'user', 0, 'moment_category_prefs', '', true );
delete_metadata( 'user', 0, 'moment_notifications_seen', '', true );

// Scheduled backflow sync events (recurring + pending one-off freshen).
wp_clear_scheduled_hook( 'moment_backflow_sync' );
wp_clear_scheduled_hook( 'moment_backflow_sync_now' );

// Backflow transients: freshen marker + per-post, per-network sync cooldowns.
delete_transient( 'moment_backflow_freshened' );

$moment_ids = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'meta_key'       => '_moment_is_moment',
		'meta_value'     => '1',
	)
);

foreach ( $moment_ids as $post_id ) {
	$external_posts = json_decode( (string) get_post_meta( $post_id, '_moment_external_posts', true ), true );
	if ( ! is_array( $external_posts ) ) {
		continue;
	}
	foreach ( array_keys( $external_posts ) as $network ) {
		delete_transient( 'moment_backflow_cooldown_' . $post_id . '_' . $network );
	}
}
