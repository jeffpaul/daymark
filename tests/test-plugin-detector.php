<?php
/**
 * Daymark_Plugin_Detector tests (issue #317): find_plugin_file()/is_active(),
 * extracted from Daymark_Admin_Subscriptions' own Connectors-tab detection.
 *
 * Uses the same real-plugin-file fixture technique
 * Test_Admin_Subscriptions::install_fake_plugin() already established —
 * get_plugins() is a filesystem scan, not filterable, so a real file under
 * WP_PLUGIN_DIR is the only way to make it find something.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Plugin_Detector.
 */
class Test_Plugin_Detector extends WP_UnitTestCase {

	/**
	 * Writes a minimal, real plugin file under WP_PLUGIN_DIR.
	 *
	 * @param string $folder    Plugin folder name.
	 * @param string $main_file Main plugin file's basename.
	 * @return void
	 */
	private function install_fake_plugin( string $folder, string $main_file ): void {
		$dir = WP_PLUGIN_DIR . '/' . $folder;
		wp_mkdir_p( $dir );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture, not a runtime code path.
			$dir . '/' . $main_file,
			"<?php\n/**\n * Plugin Name: Fake {$folder}\n */\n"
		);

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
	}

	/**
	 * Removes a fixture plugin written by install_fake_plugin().
	 *
	 * @param string $folder Plugin folder name.
	 * @return void
	 */
	private function remove_fake_plugin( string $folder ): void {
		$dir = WP_PLUGIN_DIR . '/' . $folder;

		if ( file_exists( $dir . '/plugin.php' ) ) {
			wp_delete_file( $dir . '/plugin.php' );
		}

		$files = glob( $dir . '/*.php' );

		foreach ( (array) $files as $file ) {
			wp_delete_file( $file );
		}

		if ( is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup, not a runtime code path.
			rmdir( $dir );
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
	}

	/** A plugin that was never installed resolves to no plugin file, and is never active. */
	public function test_not_installed_plugin_resolves_to_null_and_inactive(): void {
		$this->assertNull( Daymark_Plugin_Detector::find_plugin_file( 'daymark-test-nonexistent' ) );
		$this->assertFalse( Daymark_Plugin_Detector::is_active( 'daymark-test-nonexistent' ) );
	}

	/** An installed-but-inactive plugin resolves to its plugin file, but is not active. */
	public function test_installed_inactive_plugin(): void {
		$this->install_fake_plugin( 'daymark-test-webmention', 'webmention.php' );

		$file = Daymark_Plugin_Detector::find_plugin_file( 'daymark-test-webmention' );
		$this->assertSame( 'daymark-test-webmention/webmention.php', $file );
		$this->assertFalse( Daymark_Plugin_Detector::is_active( 'daymark-test-webmention' ) );

		$this->remove_fake_plugin( 'daymark-test-webmention' );
	}

	/** An installed AND active plugin (per the option_active_plugins filter) reports active. */
	public function test_installed_active_plugin(): void {
		$this->install_fake_plugin( 'daymark-test-webmention', 'webmention.php' );

		$filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'daymark-test-webmention/webmention.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $filter );

		$this->assertTrue( Daymark_Plugin_Detector::is_active( 'daymark-test-webmention' ) );

		remove_filter( 'option_active_plugins', $filter );
		$this->remove_fake_plugin( 'daymark-test-webmention' );
	}

	/** find_plugin_file() matches by the folder segment only, never a guessed main-file name. */
	public function test_find_plugin_file_matches_by_folder_not_guessed_filename(): void {
		$this->install_fake_plugin( 'daymark-test-oddname', 'not-the-slug.php' );

		$this->assertSame(
			'daymark-test-oddname/not-the-slug.php',
			Daymark_Plugin_Detector::find_plugin_file( 'daymark-test-oddname' )
		);

		$this->remove_fake_plugin( 'daymark-test-oddname' );
	}
}
