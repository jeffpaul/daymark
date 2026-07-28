<?php
/**
 * Plugin Name: Moment Connector for Mastodon
 * Description: Real Mastodon syndication and conversation backflow for Moment, with credentials managed through the WordPress 7.0 Connectors API.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: moment
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: moment-connector-mastodon
 *
 * @package Moment_Mastodon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOMENT_MASTODON_VERSION', '0.1.0' );
define( 'MOMENT_MASTODON_PLUGIN_FILE', __FILE__ );
define( 'MOMENT_MASTODON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Option name holding the Mastodon access token.
 *
 * Registered in the `connectors` settings group so the WordPress 7.0
 * Connectors screen (Settings → Connectors) manages, masks, and stores it.
 */
define( 'MOMENT_MASTODON_TOKEN_SETTING', 'connectors_social_mastodon_access_token' );

/**
 * Option name holding the Mastodon instance URL (e.g. https://mastodon.social).
 */
define( 'MOMENT_MASTODON_INSTANCE_SETTING', 'moment_mastodon_instance' );

require_once MOMENT_MASTODON_PLUGIN_DIR . 'includes/class-mastodon-client.php';
require_once MOMENT_MASTODON_PLUGIN_DIR . 'includes/class-mastodon-integration.php';

// The connector class implements Moment's interface, so it can only load
// once Moment has loaded. Registration happens on Moment's own hook.
add_action(
	'moment_register_connectors',
	static function ( $registry ) {
		if ( ! interface_exists( 'Moment_Syndication_Connector' ) ) {
			return;
		}

		require_once MOMENT_MASTODON_PLUGIN_DIR . 'includes/class-mastodon-connector.php';

		// Replaces Moment's built-in mocked Mastodon connector (same ID).
		$registry->register_connector( new Moment_Mastodon_Connector() );
	}
);

Moment_Mastodon_Integration::init();
