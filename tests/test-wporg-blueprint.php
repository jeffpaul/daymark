<?php
/**
 * Tests for the wordpress.org Live Preview blueprint.
 *
 * @package Daymark
 */

/**
 * The plugin page's Live Preview runs `.wordpress-org/blueprints/blueprint.json`
 * (SVN `assets/blueprints/blueprint.json`). These tests hold it to what
 * wordpress.org accepts, and to the same demo content as the README's
 * "Try it" demo (`.github/blueprints/blueprint.json`).
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
	 * site and runs the sample-content script from the copy.
	 */
	public function test_blueprint_reads_demo_files_that_exist(): void {
		$writes = $this->steps( 'writeFiles' );

		$this->assertCount( 1, $writes );
		$this->assertSame( 'git:directory', $writes[0]['filesTree']['resource'] );
		$this->assertSame( 'https://github.com/jeffpaul/daymark', $writes[0]['filesTree']['url'] );
		$this->assertSame( 'main', $writes[0]['filesTree']['ref'] );
		$this->assertSame( '.github/blueprints', $writes[0]['filesTree']['path'] );
		$this->assertSame( '/wordpress/wp-content/daymark-demo', $writes[0]['writeToPath'] );

		$run = $this->steps( 'runPHP' );

		$this->assertCount( 1, $run );
		$this->assertStringContainsString( "WP_CONTENT_DIR . '/daymark-demo/seed-sample-content.php'", (string) $run[0]['code'] );
		$this->assertFileExists( $this->repo_path( '.github/blueprints/seed-sample-content.php' ) );

		// The copy has to be in place before the step that runs it.
		$order = array_column( (array) $this->blueprint()['steps'], 'step' );

		$this->assertLessThan( array_search( 'runPHP', $order, true ), array_search( 'writeFiles', $order, true ) );
		$this->assertLessThan( array_search( 'runPHP', $order, true ), array_search( 'installPlugin', $order, true ) );
	}

	/**
	 * The preview follows the same sites, under the same names and in the
	 * same way, as the README's "Try it" demo. The two files each carry
	 * the list, so this fails when one is changed without the other.
	 */
	public function test_blueprint_follows_the_same_sites_as_the_readme_demo(): void {
		$readme = json_decode(
			(string) file_get_contents( $this->repo_path( '.github/blueprints/blueprint.json' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository file.
			true
		);

		$this->assertIsArray( $readme );

		$readme_code = '';

		foreach ( (array) $readme['steps'] as $step ) {
			if ( 'runPHP' === ( $step['step'] ?? '' ) ) {
				$readme_code = (string) $step['code'];
			}
		}

		$code   = (string) $this->steps( 'runPHP' )[0]['code'];
		$ours   = $this->follow_code( $code );
		$theirs = $this->follow_code( $readme_code );

		$this->assertNotSame( '', $theirs );
		$this->assertSame( $theirs, $ours );
		$this->assertSame( 4, substr_count( $ours, "'https://" ) );
		$this->assertStringContainsString( 'subscribe_to_site( $site_url )', $ours );
		$this->assertStringContainsString( 'poll_subscription(', $ours );
	}

	/**
	 * A new WordPress comes with a "Hello world!" post, which would sit at
	 * the top of the Timeline. The blueprint takes it out.
	 */
	public function test_blueprint_removes_the_hello_world_post(): void {
		$code = (string) $this->steps( 'runPHP' )[0]['code'];

		$this->assertStringContainsString( "get_page_by_path( 'hello-world', OBJECT, 'post' )", $code );
		$this->assertStringContainsString( 'wp_delete_post( $hello->ID, true )', $code );
	}

	/**
	 * The part of a blueprint's PHP that lists the sites and follows them:
	 * from the list to the end of the loop over it.
	 *
	 * @param string $code A `runPHP` step's code.
	 * @return string The code, or an empty string when it is not there.
	 */
	private function follow_code( string $code ): string {
		if ( ! preg_match( '/\$preset_sites = \[.*?\n\];.*?\nforeach \( \$preset_sites as .*?\n}\n/s', $code, $match ) ) {
			return '';
		}

		return $match[0];
	}
}
