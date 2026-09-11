<?php
/**
 * Test-only fixture classes/functions standing in for a third-party
 * plugin's own defining class/function — used by
 * tests/test-plugin-detector.php's generic Daymark_Plugin_Detector::matches()
 * coverage, and by tests/test-admin-subscriptions.php's ATmosphere
 * class-signal-fallback coverage (issue #342), where it's aliased to the
 * real plugin's own `Atmosphere\Publisher` class name — the literal value
 * Daymark_Admin_Subscriptions::recommended_connectors() checks for.
 *
 * A separate file (required from tests/bootstrap.php) rather than declared
 * inline in either test file, for the exact reason class-friends-stub.php's
 * own docblock already documents: this repo's WordPress Coding Standards
 * ruleset disallows a second top-level class/function declaration alongside
 * a test file's own test class. A class/function/constant also can't be
 * undefined once declared, so each one here is guarded against
 * redeclaration rather than scoped to a single test.
 *
 * @package Daymark
 */

if ( ! class_exists( 'Daymark_Test_Fake_Connector_Class' ) ) {
	/**
	 * Generic fixture class for Daymark_Plugin_Detector::matches()'s own
	 * classes-signal coverage. No matching function fixture lives here too
	 * — this repo's coding standards ruleset disallows mixing a function
	 * declaration into a file that already declares a class, the same
	 * constraint that put this class in its own file to begin with — so
	 * the functions-signal test asserts against a real, always-present PHP
	 * core function instead of a fixture.
	 */
	class Daymark_Test_Fake_Connector_Class {}
}

if ( ! class_exists( 'Atmosphere\\Publisher' ) ) {
	class_alias( 'Daymark_Test_Fake_Connector_Class', 'Atmosphere\\Publisher' );
}
