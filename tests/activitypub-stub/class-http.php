<?php
/**
 * Stub `Activitypub\Http` (see load.php).
 *
 * @package Daymark
 */

namespace Activitypub;

if ( ! class_exists( 'Activitypub\\Http' ) ) {
	/**
	 * Answers get_remote_object() from Daymark_Test_ActivityPub_Stub::$remote_objects.
	 */
	class Http {

		/**
		 * Stub remote object fetch.
		 *
		 * @param string $url URL.
		 * @return array|\WP_Error
		 */
		public static function get_remote_object( $url ) {
			\Daymark_Test_ActivityPub_Stub::$fetches[] = (string) $url;

			return \Daymark_Test_ActivityPub_Stub::$remote_objects[ $url ]
				?? new \WP_Error( 'activitypub_no_valid_object', 'Not an ActivityPub object.' );
		}
	}
}
