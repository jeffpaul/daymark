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
 *
 * The Daymark_Test_Fake_Connector_Class/daymark_test_fake_connector_function
 * fixtures used by matches()'s classes/functions-signal tests below are
 * declared in tests/class-plugin-detector-stub.php (required from
 * tests/bootstrap.php) — see that file's own docblock for why they can't
 * live in this one.
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

	/** matches() finds an active plugin via its slugs signal, same as is_active(). */
	public function test_matches_true_for_slug_signal(): void {
		$this->install_fake_plugin( 'daymark-test-slug-signal', 'plugin.php' );

		$filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'daymark-test-slug-signal/plugin.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $filter );

		$this->assertTrue(
			Daymark_Plugin_Detector::matches( array( 'slugs' => array( 'daymark-test-slug-signal' ) ) )
		);

		remove_filter( 'option_active_plugins', $filter );
		$this->remove_fake_plugin( 'daymark-test-slug-signal' );
	}

	/**
	 * matches() finds an active plugin via a defining class present at
	 * runtime — the fallback issue #342 needed, for a plugin whose
	 * installed folder doesn't match any known slug at all.
	 */
	public function test_matches_true_for_class_signal(): void {
		$this->assertTrue(
			Daymark_Plugin_Detector::matches( array( 'classes' => array( 'Daymark_Test_Fake_Connector_Class' ) ) )
		);
	}

	/**
	 * matches() finds an active plugin via a defining function present at
	 * runtime — asserted against a real, always-present PHP core function
	 * rather than a fixture (see class-plugin-detector-stub.php's own
	 * docblock for why no fixture function lives there alongside its
	 * fixture class).
	 */
	public function test_matches_true_for_function_signal(): void {
		$this->assertTrue(
			Daymark_Plugin_Detector::matches( array( 'functions' => array( 'array_map' ) ) )
		);
	}

	/** matches() finds an active plugin via a defining constant present at runtime. */
	public function test_matches_true_for_constant_signal(): void {
		if ( ! defined( 'DAYMARK_TEST_FAKE_CONNECTOR_CONSTANT' ) ) {
			define( 'DAYMARK_TEST_FAKE_CONNECTOR_CONSTANT', true );
		}

		$this->assertTrue(
			Daymark_Plugin_Detector::matches( array( 'constants' => array( 'DAYMARK_TEST_FAKE_CONNECTOR_CONSTANT' ) ) )
		);
	}

	/** matches() reports false when none of the given signals resolve to anything present. */
	public function test_matches_false_when_nothing_matches(): void {
		$this->assertFalse(
			Daymark_Plugin_Detector::matches(
				array(
					'slugs'     => array( 'daymark-test-nonexistent-slug' ),
					'classes'   => array( 'Daymark_Test_Nonexistent_Class' ),
					'functions' => array( 'daymark_test_nonexistent_function' ),
					'constants' => array( 'DAYMARK_TEST_NONEXISTENT_CONSTANT' ),
				)
			)
		);
	}

	/** matches() with no signals at all (every key absent) reports false. */
	public function test_matches_false_for_empty_signals(): void {
		$this->assertFalse( Daymark_Plugin_Detector::matches( array() ) );
	}
}
