<?php
/**
 * Demo content for WordPress Playground previews, all of it.
 *
 * This is the one script every Daymark preview runs once WordPress is loaded
 * and Daymark is active, so they all show the same thing:
 *
 * - the Live Preview on wordpress.org and the README's "Try it" link, which
 *   are one blueprint (`.wordpress-org/blueprints/blueprint.json`), and
 * - the preview button on each pull request
 *   (`.github/workflows/pr-playground-preview.yml`).
 *
 * Change what a preview shows here, not in those two files. They only
 * differ in which copy of Daymark they install. Both copy this folder to
 * `wp-content/daymark-demo/` on the preview site, because neither the
 * released plugin nor the zip of a branch carries `.github/`.
 *
 * The blueprint runs this file from `main` against the last *released*
 * Daymark, which may be older. Keep to calls that release has.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daymark_Plugin' ) ) {
	return;
}

$daymark_demo_plugin = Daymark_Plugin::instance();

// A new WordPress comes with a "Hello world!" post, which would sit at the
// top of the Timeline. Take it out, so the Timeline opens on the demo.
$daymark_demo_hello = get_page_by_path( 'hello-world', OBJECT, 'post' );

if ( $daymark_demo_hello instanceof WP_Post ) {
	wp_delete_post( $daymark_demo_hello->ID, true );
}

// A few real sites to follow, so the Timeline already has posts to scroll,
// search and open. Site address => the name to show for it (an empty name
// keeps the one the site gives itself).
$daymark_demo_sites = array(
	'https://jeffpaul.com'      => 'Jeffrey Paul',
	'https://jondesrosiers.com' => '',
	'https://aaron.jorb.in'     => 'Aaron Jorbin',
	'https://xkcd.com'          => 'xkcd',
);

// The same two calls the Follow a site form and its background check make.
// A site that does not answer is skipped, so it never stops the others.
foreach ( $daymark_demo_sites as $daymark_demo_site_url => $daymark_demo_site_name ) {
	$daymark_demo_followed = $daymark_demo_plugin->subscriptions->subscribe_to_site( $daymark_demo_site_url );

	if ( is_wp_error( $daymark_demo_followed ) ) {
		continue;
	}

	if ( '' !== $daymark_demo_site_name ) {
		$daymark_demo_plugin->subscriptions->update( (int) $daymark_demo_followed, array( 'site_title' => $daymark_demo_site_name ) );
	}

	$daymark_demo_plugin->subscription_poller->poll_subscription( (int) $daymark_demo_followed );
}

// Sample Marks, one for each Mark type and Featured Content kind.
require __DIR__ . '/seed-sample-content.php';
