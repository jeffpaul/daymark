<?php
/**
 * Test-only fixture class standing in for a third-party plugin's own
 * defining class — used by tests/test-plugin-detector.php's generic
 * Daymark_Plugin_Detector::matches() classes-signal coverage.
 *
 * Deliberately generic, never the real ATmosphere plugin's own
 * `Atmosphere\Publisher` name: this file is required once from
 * tests/bootstrap.php and stays defined for the entire PHPUnit process
 * (a class can't be undefined once declared), so aliasing it to that real,
 * production-checked class name — as an earlier version of this fixture
 * did — made `Daymark_Publish_Helpers`'s own ATmosphere detection report
 * "active" globally for every other test file in the same run, including
 * Test_Publish_Helpers::test_detects_nothing_by_default, which broke in CI
 * for exactly this reason. tests/test-admin-subscriptions.php's own
 * ATmosphere class-signal-fallback coverage (issue #342) instead calls
 * Daymark_Admin_Subscriptions::connector_status() directly via Reflection
 * with a synthetic connector array pointing at this same generic fixture
 * class, so it exercises the identical fallback logic without ever making
 * the real `Atmosphere\Publisher` string resolve to anything.
 *
 * A separate file (required from tests/bootstrap.php) rather than declared
 * inline in the test file itself, for the exact reason class-friends-stub.php's
 * own docblock already documents: this repo's WordPress Coding Standards
 * ruleset disallows a second top-level class declaration alongside a test
 * file's own test class. Guarded against redeclaration since a class can't
 * be undefined once declared.
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
