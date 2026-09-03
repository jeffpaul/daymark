<?php
/**
 * Plugin Name: Daymark
 * Plugin URI: https://github.com/jeffpaul/daymark
 * Description: Personal Site Publisher Mode for WordPress: capture, caption, and publish Marks from your phone. Your site stays the source of truth.
 * Version: 0.9.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Author: Jeff Paul
 * Author URI: https://github.com/jeffpaul
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: daymark
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAYMARK_VERSION', '0.9.0' );
define( 'DAYMARK_PLUGIN_FILE', __FILE__ );
define( 'DAYMARK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAYMARK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DAYMARK_PLUGIN_DIR . 'includes/class-plugin.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-routes.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-publisher.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-publish-helpers.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-ai-assist.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-federated-comments.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-syndication-links.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-microformats.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-backflow-sync.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/interface-syndication-connector.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-base.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-bluesky.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-mastodon.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-instagram.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-youtube.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-tiktok.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-threads.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/connectors/class-connector-x.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-syndication-registry.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-notifications.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-subscription-url-guard.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-subscriptions.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/sources/interface-subscription-source.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/sources/class-subscription-source-feed.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-subscription-source-registry.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-subscription-post-type.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-subscription-poller.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-admin-subscriptions.php';
require_once DAYMARK_PLUGIN_DIR . 'includes/class-share-target.php';

register_activation_hook( __FILE__, array( 'Daymark_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Daymark_Plugin', 'deactivate' ) );

Daymark_Plugin::instance();
