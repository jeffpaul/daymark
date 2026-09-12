<?php
/**
 * Test-only fixture standing in for Daymark_Plugin_Overlap's own real
 * detection, used by tests/test-notifications.php.
 *
 * The first version of this test suite defined the real
 * `SYNDICATION_LINKS_VERSION` constant directly in test code to exercise
 * "an overlap is active" — exactly the anti-pattern
 * class-plugin-detector-stub.php's own docblock already warns against: a
 * PHP constant (like a class) can never be undefined once declared, so it
 * silently leaked into every other test that ran afterward in the same
 * PHPUnit process, making `Test_Rest_Permissions::test_notifications_scoped_to_editable_posts`
 * see a plugin-overlap notification it never expected. Swapping
 * Daymark_Plugin::instance()->plugin_overlap for this fake instance for
 * the duration of one test (and restoring the real one in tear_down)
 * gets the same "an overlap is active" behavior with zero process-wide
 * side effects.
 *
 * A separate file (required from tests/bootstrap.php) rather than declared
 * inline in a test file, for the same WordPress Coding Standards
 * constraint class-plugin-detector-stub.php's own docblock already
 * documents (no second top-level class declaration in a test file).
 *
 * @package Daymark
 */

if ( ! class_exists( 'Daymark_Test_Fake_Plugin_Overlap' ) ) {
	/**
	 * Reports a single, fixed overlap as active — real dismissal logic
	 * (is_dismissed()/dismiss()/get_dismissed(), all plain per-user meta,
	 * with no process-wide state to leak) is inherited unchanged.
	 */
	class Daymark_Test_Fake_Plugin_Overlap extends Daymark_Plugin_Overlap {

		/**
		 * @return array<string, array{label: string, overlaps: string}>
		 */
		public function get_active_overlaps(): array {
			return array(
				'syndication-links' => array(
					'label'    => 'Syndication Links',
					'overlaps' => 'may duplicate the syndication markup Daymark already renders for Bridgy backfeed',
				),
			);
		}
	}
}
