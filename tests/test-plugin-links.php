<?php
/**
 * Plugins list table action link tests.
 *
 * @package Daymark
 */

/**
 * The plugin row must offer an "Open Daymark" link to /daymark.
 */
class Test_Plugin_Links extends WP_UnitTestCase {

	public function test_open_daymark_action_link_is_prepended() {
		$links = apply_filters(
			'plugin_action_links_' . plugin_basename( DAYMARK_PLUGIN_FILE ),
			array( 'deactivate' => '<a href="#">Deactivate</a>' )
		);

		$this->assertArrayHasKey( 'open-daymark', $links );
		$this->assertStringContainsString( home_url( '/daymark' ), $links['open-daymark'] );
		$this->assertStringContainsString( 'Open Daymark', $links['open-daymark'] );
		$this->assertSame( 'open-daymark', array_key_first( $links ), 'Open Daymark should come before Deactivate' );
		$this->assertArrayHasKey( 'deactivate', $links, 'Existing links must be preserved' );
	}
}
