<?php
/**
 * Stub `ParseThis\Parser` (see load.php).
 *
 * @package Daymark
 */

namespace ParseThis;

if ( ! class_exists( 'ParseThis\\Parser' ) ) {
	/**
	 * Returns Daymark_Test_Parse_This_Stub::$jf2 instead of parsing.
	 */
	class Parser {

		/**
		 * Parsed result.
		 *
		 * @var array<string, mixed>|null
		 */
		private $jf2 = null;

		/**
		 * Record the content to parse.
		 *
		 * @param mixed  $content Content.
		 * @param string $url     URL.
		 * @return void
		 */
		public function set( $content, $url ) {
			\Daymark_Test_Parse_This_Stub::$calls[] = array(
				'content' => $content,
				'url'     => $url,
			);
		}

		/**
		 * "Parse": take the canned jf2.
		 *
		 * @param array<string, mixed> $args Parse arguments.
		 * @return \WP_Error|null
		 */
		public function parse( $args = array() ) {
			\Daymark_Test_Parse_This_Stub::$args         = $args;
			\Daymark_Test_Parse_This_Stub::$max_requests = (int) apply_filters( 'parse_this_max_requests', 10, '' );

			if ( null === \Daymark_Test_Parse_This_Stub::$jf2 ) {
				return new \WP_Error( 'Missing Content' );
			}

			$this->jf2 = \Daymark_Test_Parse_This_Stub::$jf2;

			return null;
		}

		/**
		 * The parsed jf2.
		 *
		 * @return array<string, mixed>|null
		 */
		public function get() {
			return $this->jf2;
		}
	}
}
