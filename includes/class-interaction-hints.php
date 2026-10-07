<?php
/**
 * Per-user "already seen" state for the interaction-row explainer overlays.
 *
 * The app shell shows a short, one-time overlay the first time someone
 * uses each interaction-row icon (Like, Comment, Reblog, Bookmark, "Open
 * original", Share — issue #321). Issue #322 moved the record of which
 * overlays a user has seen onto the server, so dismissing one on a phone
 * also dismisses it on a laptop. Stored as multi-value
 * `daymark_interaction_hint_seen` user meta, one row per hint key, the same
 * shape Daymark_Bookmarks and Daymark_Plugin_Overlap already use.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and records which interaction hints a user has seen.
 */
class Daymark_Interaction_Hints {

	/**
	 * User meta key. Multi-value: one row per seen hint key.
	 *
	 * @var string
	 */
	public const META_KEY = 'daymark_interaction_hint_seen';

	/**
	 * Every hint key the app shell knows. Must match the keys of
	 * INTERACTION_HINTS in assets/app.js. `launcher` and `checkin` (0.20.0)
	 * introduce the + New Mark launcher and the Check In composer.
	 *
	 * @var string[]
	 */
	public const KEYS = array( 'like', 'comment', 'repost', 'bookmark', 'external', 'share', 'launcher', 'checkin' );

	/**
	 * Whether a hint key is one the app shell knows.
	 *
	 * @param string $key Hint key.
	 * @return bool
	 */
	public static function is_known( string $key ): bool {
		return in_array( $key, self::KEYS, true );
	}

	/**
	 * Every known hint key this user has seen, in KEYS order.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function get_seen( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$stored = array_map( 'strval', get_user_meta( $user_id, self::META_KEY, false ) );

		// Filtering KEYS (not the stored rows) drops a key that's no longer
		// known and any duplicate row two simultaneous requests could add.
		return array_values( array_intersect( self::KEYS, $stored ) );
	}

	/**
	 * Record that a user has seen a hint. Idempotent.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Hint key.
	 * @return string[]|WP_Error Every hint key seen after the update, or an
	 *                           error for an unknown key.
	 */
	public static function mark_seen( int $user_id, string $key ) {
		if ( ! self::is_known( $key ) ) {
			return new WP_Error(
				'daymark_unknown_hint',
				__( 'Unknown hint.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		if ( $user_id > 0 && ! in_array( $key, self::get_seen( $user_id ), true ) ) {
			add_user_meta( $user_id, self::META_KEY, $key );
		}

		return self::get_seen( $user_id );
	}
}
