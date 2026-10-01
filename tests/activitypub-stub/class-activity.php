<?php
/**
 * Stub `Activitypub\Activity\Activity` (see load.php).
 *
 * @package Daymark
 */

namespace Activitypub\Activity;

if ( ! class_exists( 'Activitypub\\Activity\\Activity' ) ) {
	/**
	 * Magic set_*()/get_*() property bag, like the real Base_Object.
	 */
	class Activity {

		/**
		 * Properties set so far.
		 *
		 * @var array<string, mixed>
		 */
		private $props = array();

		/**
		 * Handle set_x()/get_x().
		 *
		 * @param string $name Method name.
		 * @param array  $args Arguments.
		 * @return mixed
		 */
		public function __call( $name, $args ) {
			$key = substr( $name, 4 );

			if ( 0 === strpos( $name, 'set_' ) ) {
				$this->props[ $key ] = $args[0] ?? null;
				return $this;
			}

			if ( 0 === strpos( $name, 'get_' ) ) {
				return $this->props[ $key ] ?? null;
			}

			return null;
		}

		/**
		 * Every set property.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array() {
			return $this->props;
		}
	}
}
