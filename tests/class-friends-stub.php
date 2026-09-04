<?php
/**
 * Test-only stand-in for the real akirk/friends plugin's `Friends` class
 * (https://wordpress.org/plugins/friends/) — only the one constant
 * Daymark_Subscription_Source_Friends depends on (issue #88).
 *
 * This sandbox has no way to install the actual third-party plugin for
 * tests/test-subscription-source-friends.php, but every WordPress core
 * function that connector calls (get_users(), WP_Query, get_post_format(),
 * get_the_post_thumbnail_url()) still runs for real against this stub's
 * registered post type.
 *
 * A separate file (required from tests/bootstrap.php) rather than defined
 * inline in either bootstrap.php or the test file itself, since this
 * repo's WordPress Coding Standards ruleset disallows mixing a class
 * declaration into a file that already declares functions (bootstrap.php)
 * and disallows a second top-level class alongside a test class (the test
 * file itself).
 *
 * @package Daymark
 */

if ( ! class_exists( 'Friends' ) ) {
	/**
	 * Minimal stand-in for the real plugin's `Friends` class.
	 */
	class Friends {
		const CPT = 'friend_post_cache';
	}
}
