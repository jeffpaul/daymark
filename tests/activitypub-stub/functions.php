<?php
/**
 * Stub `Activitypub\` namespace functions and constants (see load.php).
 *
 * @package Daymark
 */

namespace Activitypub;

if ( ! function_exists( 'Activitypub\\add_to_outbox' ) ) {
	\Daymark_Test_ActivityPub_Stub::$loaded = true;

	if ( ! defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
		define( 'ACTIVITYPUB_PLUGIN_VERSION', '9.3.1' );
	}

	if ( ! defined( 'ACTIVITYPUB_CONTENT_VISIBILITY_PRIVATE' ) ) {
		define( 'ACTIVITYPUB_CONTENT_VISIBILITY_PRIVATE', 'private' );
	}

	/**
	 * Stub of the plugin's add_to_outbox(): records the call, stores a row.
	 *
	 * @param mixed       $data               Activity.
	 * @param string|null $activity_type      Unused.
	 * @param int         $user_id            Actor user ID.
	 * @param string|null $content_visibility Visibility.
	 * @return int|false
	 */
	function add_to_outbox( $data, $activity_type = null, $user_id = 0, $content_visibility = null ) {
		unset( $activity_type );

		if ( \Daymark_Test_ActivityPub_Stub::$fail_queue ) {
			return false;
		}

		$activity  = $data->to_array();
		$outbox_id = \Daymark_Test_ActivityPub_Stub::insert_outbox_row( (string) $activity['type'], (string) $activity['object'], (int) $user_id );

		\Daymark_Test_ActivityPub_Stub::$queued[] = array(
			'activity'   => $activity,
			'user_id'    => (int) $user_id,
			'visibility' => (string) $content_visibility,
			'outbox_id'  => $outbox_id,
		);

		return $outbox_id;
	}

	/**
	 * Stub of the plugin's user_can_activitypub().
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	function user_can_activitypub( $user_id ) {
		return in_array( (int) $user_id, \Daymark_Test_ActivityPub_Stub::$enabled_users, true );
	}
}
