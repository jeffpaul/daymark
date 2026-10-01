<?php
/**
 * Minimal actor model returned by the stub Actors::get_by_id() (see load.php).
 *
 * @package Daymark
 */

if ( ! class_exists( 'Daymark_Test_ActivityPub_Actor' ) ) {
	/**
	 * Just enough of the real plugin's actor model: get_id().
	 */
	class Daymark_Test_ActivityPub_Actor {

		/**
		 * User ID.
		 *
		 * @var int
		 */
		private $user_id;

		/**
		 * Constructor.
		 *
		 * @param int $user_id User ID.
		 */
		public function __construct( int $user_id ) {
			$this->user_id = $user_id;
		}

		/**
		 * Actor ID URL.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'https://example.org/?author=' . $this->user_id;
		}
	}
}
