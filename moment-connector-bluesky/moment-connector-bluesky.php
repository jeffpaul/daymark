<?php
/**
 * Plugin Name: Moment Connector for Bluesky
 * Description: Real Bluesky syndication and conversation backflow for Moment, with credentials managed through the WordPress 7.0 Connectors API.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: moment
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: moment-connector-bluesky
 *
 * @package Moment_Bluesky
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOMENT_BLUESKY_VERSION', '0.1.0' );
define( 'MOMENT_BLUESKY_PLUGIN_FILE', __FILE__ );
define( 'MOMENT_BLUESKY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Option name holding the Bluesky app password.
 *
 * Registered in the `connectors` settings group so the WordPress 7.0
 * Connectors screen (Settings → Connectors) manages, masks, and stores it.
 */
define( 'MOMENT_BLUESKY_PASSWORD_SETTING', 'connectors_social_bluesky_app_password' );

/**
 * Option name holding the Bluesky handle (e.g. demo.bsky.social).
 */
define( 'MOMENT_BLUESKY_HANDLE_SETTING', 'moment_bluesky_handle' );

require_once MOMENT_BLUESKY_PLUGIN_DIR . 'includes/class-bluesky-client.php';
require_once MOMENT_BLUESKY_PLUGIN_DIR . 'includes/class-bluesky-atmosphere.php';
require_once MOMENT_BLUESKY_PLUGIN_DIR . 'includes/class-bluesky-integration.php';

// The connector class implements Moment's interface, so it can only load
// once Moment has loaded. Registration happens on Moment's own hook.
add_action(
	'moment_register_connectors',
	static function ( $registry ) {
		if ( ! interface_exists( 'Moment_Syndication_Connector' ) ) {
			return;
		}

		require_once MOMENT_BLUESKY_PLUGIN_DIR . 'includes/class-bluesky-connector.php';

		// Replaces Moment's built-in mocked Bluesky connector (same ID).
		$registry->register_connector( new Moment_Bluesky_Connector() );
	}
);

Moment_Bluesky_Integration::init();
