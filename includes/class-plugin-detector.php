<?php
/**
 * Shared "is this wordpress.org plugin installed/active" detection.
 *
 * Extracted from Daymark_Admin_Subscriptions::connector_plugin_file()/
 * connector_status() (the Connectors tab's own detection, unchanged in
 * behavior) once a second caller needed the identical check: issue #317's
 * Comment action needs to know at send time whether the local Webmention
 * plugin is active, the same get_plugins()/is_plugin_active() lookup the
 * Connectors tab already does for its own UI. One shared implementation
 * rather than two copies, matching this codebase's standing preference.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static wordpress.org plugin install/active detector, matched by folder
 * slug (the `folder/` segment of the `folder/main-file.php` key
 * get_plugins() returns each installed plugin under) — never a guessed
 * main-file name, since that isn't always identical to the plugin's slug.
 */
class Daymark_Plugin_Detector {

	/**
	 * Find a plugin's own installed plugin file by its folder slug.
	 *
	 * @param string $folder_slug The plugin's installed folder name (e.g. `webmention`).
	 * @return string|null The plugin file (e.g. `webmention/webmention.php`), or null if not installed.
	 */
	public static function find_plugin_file( string $folder_slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( strtok( $plugin_file, '/' ) === $folder_slug ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Whether a plugin, identified by its folder slug, is installed AND active.
	 *
	 * @param string $folder_slug The plugin's installed folder name.
	 * @return bool
	 */
	public static function is_active( string $folder_slug ): bool {
		$plugin_file = self::find_plugin_file( $folder_slug );

		return null !== $plugin_file && is_plugin_active( $plugin_file );
	}
}
