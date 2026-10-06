<?php
/**
 * Parse This for the test suite.
 *
 * With DAYMARK_PARSE_THIS_DIR set to a checkout of the real plugin
 * (https://github.com/dshanske/parse-this), that plugin is loaded, so the
 * whole suite runs with Parse This active, as on a site that has it.
 *
 * Otherwise a stub `ParseThis\Parser` is defined. It is inert: Daymark only
 * uses Parse This when PARSE_THIS_VERSION is defined, which the stub never
 * does, so tests opt in with the `daymark_use_parse_this` filter and give
 * the stub the jf2 it should return (Daymark_Test_Parse_This_Stub::$jf2).
 *
 * @package Daymark
 */

$daymark_parse_this_dir = getenv( 'DAYMARK_PARSE_THIS_DIR' );

if ( is_string( $daymark_parse_this_dir ) && '' !== $daymark_parse_this_dir && file_exists( $daymark_parse_this_dir . '/parse-this.php' ) ) {
	require_once $daymark_parse_this_dir . '/parse-this.php';
	parse_this_loader();
	return;
}

require_once __DIR__ . '/class-daymark-test-parse-this-stub.php';
require_once __DIR__ . '/class-parser.php';
