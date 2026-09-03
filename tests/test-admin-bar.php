<?php
/**
 * Admin bar shortcut tests.
 *
 * @package Daymark
 */

// WP_Admin_Bar is normally loaded by _wp_admin_bar_init(), which only runs
// while actually rendering a request's admin bar — never during a plain
// PHPUnit run. Load it directly so it's available to instantiate here.
if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
}

/**
 * "Open Daymark" under the site-name node, and "Daymark" (pre-set to an
 * image Mark) under the "+New" menu — both gated on edit_posts.
 */
class Test_Admin_Bar extends WP_UnitTestCase {

	private Daymark_Admin_Bar $admin_bar;

	public function set_up() {
		parent::set_up();
		$this->admin_bar = new Daymark_Admin_Bar();
	}

	private function bar_with_parents(): WP_Admin_Bar {
		$bar = new WP_Admin_Bar();
		$bar->initialize();
		$bar->add_node( array( 'id' => 'site-name' ) );
		$bar->add_node( array( 'id' => 'new-content' ) );
		return $bar;
	}

	public function test_open_daymark_node_added_under_site_name() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$bar = $this->bar_with_parents();

		$this->admin_bar->add_nodes( $bar );

		$node = $bar->get_node( 'daymark-open' );
		$this->assertNotNull( $node );
		$this->assertSame( 'site-name', $node->parent );
		$this->assertSame( home_url( '/daymark' ), $node->href );
	}

	public function test_new_content_node_added_with_image_type_preselected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$bar = $this->bar_with_parents();

		$this->admin_bar->add_nodes( $bar );

		$node = $bar->get_node( 'new-daymark' );
		$this->assertNotNull( $node );
		$this->assertSame( 'new-content', $node->parent );
		$this->assertStringContainsString( home_url( '/daymark' ), $node->href );
		$this->assertStringContainsString( 'daymark_type=image', $node->href );
		$this->assertStringContainsString( '#create', $node->href );
	}

	public function test_nothing_added_without_edit_posts_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$bar = $this->bar_with_parents();

		$this->admin_bar->add_nodes( $bar );

		$this->assertNull( $bar->get_node( 'daymark-open' ) );
		$this->assertNull( $bar->get_node( 'new-daymark' ) );
	}

	public function test_nothing_added_when_parent_nodes_are_missing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$bar = new WP_Admin_Bar();
		$bar->initialize();

		// Deliberately no site-name / new-content parents added — must not
		// error, and must not add either child node.
		$this->admin_bar->add_nodes( $bar );

		$this->assertNull( $bar->get_node( 'daymark-open' ) );
		$this->assertNull( $bar->get_node( 'new-daymark' ) );
	}
}
