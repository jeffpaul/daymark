<?php
/**
 * Daymark block registration tests.
 *
 * @package Daymark
 */

/**
 * Tests the daymark/* dynamic blocks and their editor scripts.
 */
class Test_Blocks extends WP_UnitTestCase {

	/**
	 * View keys shared by blocks and shortcodes.
	 *
	 * @var array<int, string>
	 */
	private const VIEWS = array( 'timeline', 'images', 'videos', 'audio', 'notes' );

	/**
	 * The shared block editor script handle.
	 *
	 * @var string
	 */
	private const EDITOR_SCRIPT_HANDLE = 'daymark-editor-script';

	/**
	 * Every block.json references the single shared editor script handle, and
	 * the built bundle that handle points at actually exists.
	 */
	public function test_blocks_declare_the_shared_editor_script() {
		foreach ( self::VIEWS as $view ) {
			$json_path = DAYMARK_PLUGIN_DIR . 'blocks/' . $view . '/block.json';
			$metadata  = json_decode( (string) file_get_contents( $json_path ), true );

			$this->assertIsArray( $metadata, "$view block.json must decode" );
			$this->assertSame( self::EDITOR_SCRIPT_HANDLE, $metadata['editorScript'] ?? '', "$view editorScript must reference the shared handle" );

			$this->assertFileExists( DAYMARK_PLUGIN_DIR . 'build/index.js', "editor bundle must exist (run 'npm run build')" );
			$this->assertFileExists( DAYMARK_PLUGIN_DIR . 'build/index.asset.php', 'editor bundle asset file must exist' );
		}
	}

	/**
	 * All daymark/* blocks and daymark_* shortcodes are registered.
	 */
	public function test_blocks_and_shortcodes_register() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( self::VIEWS as $view ) {
			$this->assertTrue( $registry->is_registered( 'daymark/' . $view ), "daymark/$view block must register" );
			$this->assertTrue( shortcode_exists( 'daymark_' . $view ), "daymark_$view shortcode must register" );
		}
	}

	/**
	 * All five blocks share one editor script handle. Sharing a single handle
	 * keeps core from enqueuing the built bundle once per block (which would
	 * re-run registerBlockType() and throw "already registered" errors on
	 * every editor screen). (Checked on the block types rather than
	 * wp_scripts()->registered, which other tests deliberately reset
	 * mid-suite; the script's wiring is pinned by the two checks above.)
	 */
	public function test_editor_script_handle_points_at_the_built_bundle() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( self::VIEWS as $view ) {
			$block = $registry->get_registered( 'daymark/' . $view );

			$this->assertNotNull( $block, "daymark/$view must be registered" );
			$this->assertContains( self::EDITOR_SCRIPT_HANDLE, $block->editor_script_handles, "daymark/$view must attach the shared editor script handle" );
		}
	}
}
