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
	 * Every block.json declares a built editor script that actually exists.
	 */
	public function test_blocks_declare_an_existing_editor_script() {
		foreach ( self::VIEWS as $view ) {
			$json_path = DAYMARK_PLUGIN_DIR . 'blocks/' . $view . '/block.json';
			$metadata  = json_decode( (string) file_get_contents( $json_path ), true );

			$this->assertIsArray( $metadata, "$view block.json must decode" );
			$this->assertArrayHasKey( 'editorScript', $metadata, "$view block must declare an editor script" );
			$this->assertStringStartsWith( 'file:', $metadata['editorScript'], "$view editorScript must be a file: path" );

			$script_path = dirname( $json_path ) . '/' . substr( $metadata['editorScript'], strlen( 'file:' ) );

			$this->assertFileExists( $script_path, "$view editor script must exist (run 'npm run build')" );
			$this->assertFileExists( substr_replace( $script_path, '.asset.php', -3 ), "$view editor script asset file must exist" );
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
	 * The registered editor script handle is the generated one, so WordPress
	 * enqueues the built bundle whenever the block appears in the editor.
	 * (Checked on the block type rather than wp_scripts()->registered, which
	 * other tests deliberately reset mid-suite.)
	 */
	public function test_editor_script_handle_points_at_the_built_bundle() {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'daymark/timeline' );

		$this->assertNotNull( $block, 'daymark/timeline must be registered' );
		$this->assertContains( 'daymark-timeline-editor-script', $block->editor_script_handles, 'editor script handle must be attached to the block type' );
	}
}
