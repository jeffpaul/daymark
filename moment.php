<?php
/**
 * Plugin Name: Moment
 * Plugin URI: https://github.com/jeffpaul/moment
 * Description: Personal Site Publisher Mode for WordPress: capture, caption, and publish Moments from your phone. Your site stays the source of truth.
 * Version: 0.5.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Jeff Paul
 * Author URI: https://github.com/jeffpaul
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: moment
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOMENT_VERSION', '0.5.0' );
define( 'MOMENT_PLUGIN_FILE', __FILE__ );
define( 'MOMENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOMENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MOMENT_PLUGIN_DIR . 'includes/class-plugin.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-routes.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-publisher.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-publish-helpers.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-ai-assist.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-blocks.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-renderer.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-federated-comments.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-syndication-links.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-backflow-sync.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/interface-syndication-connector.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-base.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-bluesky.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-mastodon.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-instagram.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-youtube.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-tiktok.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-threads.php';
require_once MOMENT_PLUGIN_DIR . 'includes/connectors/class-connector-x.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-syndication-registry.php';
require_once MOMENT_PLUGIN_DIR . 'includes/class-notifications.php';

register_activation_hook( __FILE__, array( 'Moment_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Moment_Plugin', 'deactivate' ) );

Moment_Plugin::instance();
