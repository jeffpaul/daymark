<?php
/**
 * Stub `Activitypub\Collection\Actors` (see load.php).
 *
 * @package Daymark
 */

namespace Activitypub\Collection;

if ( ! class_exists( 'Activitypub\\Collection\\Actors' ) ) {
	/**
	 * Returns a minimal actor with get_id().
	 */
	class Actors {

		/**
		 * Stub actor lookup: an object whose get_id() is a stable actor URL.
		 *
		 * @param int $user_id User ID.
		 * @return \Daymark_Test_ActivityPub_Actor
		 */
		public static function get_by_id( $user_id ) {
			return new \Daymark_Test_ActivityPub_Actor( (int) $user_id );
		}
	}
}
