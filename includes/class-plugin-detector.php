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

	/**
	 * Whether any of a set of detection signals indicates an active plugin —
	 * a folder slug (via is_active() above), or a defining class/function/
	 * constant present at runtime. The latter can only ever be true for a
	 * plugin that's genuinely active (inactive plugin code is never loaded
	 * by PHP), so it's a safe fallback for a plugin whose installed folder
	 * name isn't reliably fixed — e.g. issue #342: ATmosphere's own folder
	 * historically was `wordpress-atmosphere`, but a republished build can
	 * ship under a different one, which made a folder-only check wrongly
	 * report a genuinely active connector as not installed at all.
	 *
	 * Mirrors the multi-signal shape Daymark_Publish_Helpers::PLUGINS
	 * already uses for the identical problem (detecting a third-party
	 * publishing plugin whose folder name isn't the only reliable signal),
	 * extracted here so a second caller with the same need doesn't
	 * duplicate the same four-signal check.
	 *
	 * @since 0.16.0
	 *
	 * @param array{slugs?: string[], classes?: string[], functions?: string[], constants?: string[]} $signals Detection signals; any present, empty key is simply skipped.
	 * @return bool
	 */
	public static function matches( array $signals ): bool {
		foreach ( (array) ( $signals['slugs'] ?? array() ) as $slug ) {
			if ( self::is_active( (string) $slug ) ) {
				return true;
			}
		}

		foreach ( (array) ( $signals['classes'] ?? array() ) as $class ) {
			if ( class_exists( (string) $class ) ) {
				return true;
			}
		}

		foreach ( (array) ( $signals['functions'] ?? array() ) as $fn ) {
			if ( function_exists( (string) $fn ) ) {
				return true;
			}
		}

		foreach ( (array) ( $signals['constants'] ?? array() ) as $const ) {
			if ( defined( (string) $const ) ) {
				return true;
			}
		}

		return false;
	}
}
