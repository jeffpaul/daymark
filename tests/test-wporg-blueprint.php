<?php
/**
 * Tests for the wordpress.org Live Preview blueprint.
 *
 * @package Daymark
 */

/**
 * The plugin page's Live Preview runs `.wordpress-org/blueprints/blueprint.json`
 * (SVN `assets/blueprints/blueprint.json`). These tests hold it to what
 * wordpress.org accepts, and to running the same demo script as the README's
 * "Try it" demo (see tests/test-playground-blueprint.php for the script).
 */
class Test_Wporg_Blueprint extends WP_UnitTestCase {

	/**
	 * Path to a file in the repository.
	 *
	 * @param string $path Path from the repository root.
	 * @return string
	 */
	private function repo_path( string $path ): string {
		return dirname( DAYMARK_PLUGIN_FILE ) . '/' . $path;
	}

	/**
	 * Decode the wordpress.org blueprint from disk.
	 *
	 * @return array<string, mixed>
	 */
	private function blueprint(): array {
		$blueprint = json_decode(
			(string) file_get_contents( $this->repo_path( '.wordpress-org/blueprints/blueprint.json' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository file.
			true
		);

		$this->assertIsArray( $blueprint );

		return $blueprint;
	}

	/**
	 * The blueprint's steps of one kind.
	 *
	 * @param string $name Step name.
	 * @return array<int, array<string, mixed>>
	 */
	private function steps( string $name ): array {
		return array_values(
			array_filter(
				(array) ( $this->blueprint()['steps'] ?? array() ),
				static function ( $step ) use ( $name ) {
					return is_array( $step ) && ( $step['step'] ?? '' ) === $name;
				}
			)
		);
	}

	/**
	 * Wordpress.org reads one file, `blueprint.json`, skips it when it is not
	 * valid JSON, and skips it when it is over 100 KB.
	 */
	public function test_blueprint_is_a_file_wordpress_org_accepts(): void {
		$path = $this->repo_path( '.wordpress-org/blueprints/blueprint.json' );

		$this->assertFileExists( $path );
		$this->assertLessThan( 100 * KB_IN_BYTES, filesize( $path ) );
		$this->assertSame( '/daymark', $this->blueprint()['landingPage'] ?? '' );
		$this->assertTrue( $this->blueprint()['login'] ?? false );
	}

	/**
	 * The preview installs the released plugin from wordpress.org, never a
	 * development copy.
	 *
	 * The step names the plugin under both `pluginData` (the current key)
	 * and `pluginZipFile` (the older one). Wordpress.org's importer adds a
	 * second install step of its own to the end when it does not recognise
	 * one, and it has looked for each key at different times.
	 */
	public function test_blueprint_installs_the_released_plugin(): void {
		$installs = $this->steps( 'installPlugin' );
		$expected = array(
			'resource' => 'wordpress.org/plugins',
			'slug'     => 'daymark',
		);

		$this->assertCount( 1, $installs );
		$this->assertSame( $expected, $installs[0]['pluginData'] ?? null );
		$this->assertSame( $expected, $installs[0]['pluginZipFile'] ?? null );
		$this->assertTrue( $installs[0]['options']['activate'] ?? false );
	}

	/**
	 * The released plugin does not carry the demo files, so the blueprint
	 * copies this repository's `.github/blueprints/` folder onto the preview
	 * site and runs the shared demo script from the copy. That one step is
	 * all its PHP does.
	 */
	public function test_blueprint_copies_the_demo_files_then_runs_the_shared_script(): void {
		$writes = $this->steps( 'writeFiles' );

		$this->assertCount( 1, $writes );
		$this->assertSame( 'git:directory', $writes[0]['filesTree']['resource'] );
		$this->assertSame( 'https://github.com/jeffpaul/daymark', $writes[0]['filesTree']['url'] );
		$this->assertSame( 'main', $writes[0]['filesTree']['ref'] );
		$this->assertSame( '.github/blueprints', $writes[0]['filesTree']['path'] );
		$this->assertSame( '/wordpress/wp-content/daymark-demo', $writes[0]['writeToPath'] );

		$run = $this->steps( 'runPHP' );

		$this->assertCount( 1, $run );
		$this->assertStringContainsString( "WP_CONTENT_DIR . '/daymark-demo/set-up-demo.php'", (string) $run[0]['code'] );
		$this->assertFileExists( $this->repo_path( '.github/blueprints/set-up-demo.php' ) );

		// The plugin and the copy have to be in place before the step that
		// runs the script.
		$order = array_column( (array) $this->blueprint()['steps'], 'step' );

		$this->assertLessThan( array_search( 'runPHP', $order, true ), array_search( 'writeFiles', $order, true ) );
		$this->assertLessThan( array_search( 'runPHP', $order, true ), array_search( 'installPlugin', $order, true ) );
	}
}
