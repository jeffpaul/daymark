<?php
/**
 * Test controls for the stub `ParseThis\Parser` (see load.php).
 *
 * @package Daymark
 */

/**
 * What the stub parser returns, and what it was asked to parse.
 */
class Daymark_Test_Parse_This_Stub {

	/**
	 * jf2 the next parse returns; null makes parse() fail.
	 *
	 * @var array<string, mixed>|null
	 */
	public static $jf2 = null;

	/**
	 * Every set() call: content and URL.
	 *
	 * @var array<int, array{content: mixed, url: string}>
	 */
	public static $calls = array();

	/**
	 * The `parse_this_max_requests` value seen during the last parse().
	 *
	 * @var int|null
	 */
	public static $max_requests = null;

	/**
	 * The arguments the last parse() was given.
	 *
	 * @var array<string, mixed>
	 */
	public static $args = array();

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$jf2          = null;
		self::$calls        = array();
		self::$max_requests = null;
		self::$args         = array();
	}
}
