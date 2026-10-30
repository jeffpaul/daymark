<?php
/**
 * Stub `Activitypub\Proxy` (see load.php), the ActivityPub 9.4.0+ fetch.
 *
 * @package Daymark
 */

namespace Activitypub;

if ( ! class_exists( 'Activitypub\\Proxy' ) ) {
	/**
	 * Answers get() from Daymark_Test_ActivityPub_Stub::$remote_objects.
	 */
	class Proxy {

		/**
		 * Stub remote object fetch.
		 *
		 * @param string $id   URL.
		 * @param array  $args Ignored by the stub.
		 * @return array|\WP_Error
		 */
		public static function get( $id, $args = array() ) {
			unset( $args );

			\Daymark_Test_ActivityPub_Stub::$fetches[]     = (string) $id;
			\Daymark_Test_ActivityPub_Stub::$fetched_via[] = 'proxy';

			return \Daymark_Test_ActivityPub_Stub::$remote_objects[ $id ]
				?? new \WP_Error( 'activitypub_no_valid_object', 'Not an ActivityPub object.' );
		}
	}
}
