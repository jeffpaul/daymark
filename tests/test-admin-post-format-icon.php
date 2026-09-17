<?php
/**
 * Post-format icon indicator tests.
 *
 * @package Daymark
 */

// get_current_screen()/set_current_screen() live in wp-admin/includes/screen.php,
// which the plain WP PHPUnit bootstrap never loads (it's only pulled in by a
// real wp-admin/admin.php request) — the same reason test-admin-bar.php
// conditionally requires WP_Admin_Bar's own class file.
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

/**
 * The post-format icon column added immediately before Title on wp-admin's
 * post list screens.
 */
class Test_Admin_Post_Format_Icon extends WP_UnitTestCase {

	/**
	 * @var Daymark_Admin_Post_Format_Icon
	 */
	private Daymark_Admin_Post_Format_Icon $icon;

	public function set_up() {
		parent::set_up();

		$this->icon = new Daymark_Admin_Post_Format_Icon();

		// Post formats are a theme feature; without this, post_type_supports()
		// is false and get_post_format() always returns false regardless of
		// any stored term — matching core's own test convention for
		// exercising post-format behavior.
		add_theme_support( 'post-formats', array( 'aside', 'gallery', 'video', 'audio', 'image', 'status', 'link' ) );
	}

	public function tear_down() {
		remove_theme_support( 'post-formats' );
		remove_filter( 'manage_post_posts_columns', array( $this->icon, 'add_format_icon_column' ) );
		remove_action( 'manage_post_posts_custom_column', array( $this->icon, 'render_format_icon_column' ), 10 );
		remove_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_icon_style' ) );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_register_hooks_the_columns_filter_and_custom_column_action_for_each_supported_post_type() {
		$this->icon->register();

		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', array( $this->icon, 'add_format_icon_column' ) ) );
		$this->assertSame( 10, has_action( 'manage_post_posts_custom_column', array( $this->icon, 'render_format_icon_column' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_icon_style' ) ) );

		// daymark_sub_post declares no post-formats support, so it must not
		// get either hook — confirming supported_post_types() is actually
		// filtering, not just hardcoding 'post'.
		$this->assertFalse( has_filter( 'manage_daymark_sub_post_posts_columns', array( $this->icon, 'add_format_icon_column' ) ) );
	}

	public function test_add_format_icon_column_inserts_the_column_immediately_before_title() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$result = $this->icon->add_format_icon_column( $columns );
		$keys   = array_keys( $result );

		$this->assertSame( array( 'cb', 'daymark_format_icon', 'title', 'date' ), $keys );
		$this->assertStringContainsString( 'screen-reader-text', $result['daymark_format_icon'] );
	}

	public function test_render_format_icon_column_is_a_no_op_for_a_different_column() {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		$this->expectOutputString( '' );
		$this->icon->render_format_icon_column( 'date', $post_id );
	}

	public function test_render_format_icon_column_is_a_no_op_for_standard_posts() {
		$post_id = self::factory()->post->create();

		$this->expectOutputString( '' );
		$this->icon->render_format_icon_column( 'daymark_format_icon', $post_id );
	}

	public function test_render_format_icon_column_echoes_the_matching_dashicon_for_a_real_format() {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		ob_start();
		$this->icon->render_format_icon_column( 'daymark_format_icon', $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'dashicons-format-video', $output );
		$this->assertStringContainsString( 'daymark-format-icon', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
		$this->assertStringContainsString( 'screen-reader-text', $output );
		$this->assertStringContainsString( 'Video', $output );
	}

	public function test_render_format_icon_column_uses_the_pluralized_dashicon_for_link_format() {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'link' );

		ob_start();
		$this->icon->render_format_icon_column( 'daymark_format_icon', $post_id );
		$output = ob_get_clean();

		// WordPress core's own dashicon class for the "link" post format is
		// pluralized ("format-links"), unlike every other format's own
		// slug-matching class name — confirmed directly against core's CSS.
		$this->assertStringContainsString( 'dashicons-format-links', $output );
		$this->assertStringNotContainsString( 'dashicons-format-link"', $output );
	}

	public function test_enqueue_icon_style_is_a_no_op_outside_the_matching_screen() {
		set_current_screen( 'edit-page' );

		$this->icon->enqueue_icon_style();

		$this->assertFalse( wp_styles()->get_data( 'common', 'after' ) );
	}

	public function test_enqueue_icon_style_adds_inline_style_on_the_matching_screen() {
		set_current_screen( 'edit-post' );
		wp_enqueue_style( 'common' );

		$this->icon->enqueue_icon_style();

		$inline = wp_styles()->get_data( 'common', 'after' );
		$this->assertIsArray( $inline );
		$this->assertStringContainsString( '.daymark-format-icon', implode( '', $inline ) );
	}
}
