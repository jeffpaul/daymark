<?php
/**
 * PHPUnit bootstrap for the Daymark plugin.
 *
 * Requires the WordPress PHPUnit test library. Set WP_TESTS_DIR or install
 * to /tmp/wordpress-tests-lib.
 *
 * @package Daymark
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Pre-bootstrap STDERR notice; WP_Filesystem is not loaded yet.
	fwrite(
		STDERR,
		"SKIPPED: WordPress test library not found at {$_tests_dir}.\n\n"
		. "Install it with:\n"
		. "  bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 7.0\n"
		. "Then run:\n"
		. "  WP_TESTS_DIR=\$TMPDIR/wordpress-tests-lib composer test   # macOS\n"
		. "  WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test      # Linux/CI\n"
	);
	exit( 1 );
}

// Polyfills for cross-PHPUnit-version compatibility (required by the WP suite).
$_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( file_exists( $_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the Daymark plugin.
 */
function _daymark_manually_load_plugin() {
	require dirname( __DIR__ ) . '/daymark.php';
}
tests_add_filter( 'muplugins_loaded', '_daymark_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

// The daymark_subscription table is normally created on plugin activation,
// which the WP test suite never runs (it loads the plugin directly via
// muplugins_loaded above, not through the real activation flow). Any test
// that ends up calling Daymark_Notifications::get_notifications()/
// has_unread() now queries this table (issue #78, "Dead feed detection"),
// including tests that predate Subscriptions and know nothing about it —
// so it's installed once here for the whole suite, matching what's always
// true on a real site post-activation, rather than requiring every such
// test file to know to call Daymark_Subscriptions::install() itself.
Daymark_Subscriptions::install();
