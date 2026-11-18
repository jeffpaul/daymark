<?php
/**
 * When each user last opened Explore.
 *
 * One user meta value, so it follows the user across devices. Explore's
 * People row rings the people whose posts arrived after it: each visit
 * reads the time of the visit before and stores its own.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and moves the per-user "last opened Explore" time.
 */
class Daymark_Explore_Seen {

	/**
	 * User meta key. A `Y-m-d H:i:s` time, UTC.
	 *
	 * @var string
	 */
	public const META_KEY = 'daymark_explore_seen_at';

	/**
	 * Record a visit to Explore now, and return the visit before it.
	 *
	 * @since 0.23.0
	 *
	 * @param int $user_id User ID.
	 * @return array{previous: string, seen: string} Both `Y-m-d H:i:s`, UTC.
	 *                                               `previous` is '' on a
	 *                                               first visit.
	 */
	public static function touch( int $user_id ): array {
		$previous = Daymark_Subscription_Post_Type::sanitize_datetime( get_user_meta( $user_id, self::META_KEY, true ) );
		$now      = gmdate( 'Y-m-d H:i:s' );

		update_user_meta( $user_id, self::META_KEY, $now );

		return array(
			'previous' => $previous,
			'seen'     => $now,
		);
	}
}
