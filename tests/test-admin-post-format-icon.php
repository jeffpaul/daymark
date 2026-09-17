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
 * post list screens, and the Quick Edit Format field it also adds.
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
		// exercising post-format behavior. 'chat' is deliberately excluded
		// so tests can assert the Quick Edit dropdown only offers formats
		// this theme actually declared, not every format Daymark itself
		// knows a dashicon for.
		add_theme_support( 'post-formats', array( 'aside', 'gallery', 'video', 'audio', 'image', 'status', 'link' ) );
	}

	public function tear_down() {
		remove_theme_support( 'post-formats' );
		remove_filter( 'manage_post_posts_columns', array( $this->icon, 'add_format_icon_column' ) );
		remove_action( 'manage_post_posts_custom_column', array( $this->icon, 'render_format_icon_column' ), 10 );
		remove_action( 'quick_edit_custom_box', array( $this->icon, 'render_quick_edit_field' ), 10 );
		remove_action( 'save_post', array( $this->icon, 'save_quick_edit_format' ) );
		remove_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_icon_style' ) );
		remove_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_quick_edit_script' ) );
		set_current_screen( 'front' );
		unset( $_POST['action'], $_POST['_inline_edit'], $_POST['daymark_post_format'] );

		parent::tear_down();
	}

	public function test_register_hooks_the_columns_filter_and_custom_column_action_for_each_supported_post_type() {
		$this->icon->register();

		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', array( $this->icon, 'add_format_icon_column' ) ) );
		$this->assertSame( 10, has_action( 'manage_post_posts_custom_column', array( $this->icon, 'render_format_icon_column' ) ) );
		$this->assertSame( 10, has_action( 'quick_edit_custom_box', array( $this->icon, 'render_quick_edit_field' ) ) );
		$this->assertNotFalse( has_action( 'save_post', array( $this->icon, 'save_quick_edit_format' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_icon_style' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_quick_edit_script' ) ) );

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

	public function test_render_format_icon_column_shows_the_standard_icon_with_an_empty_data_format_attribute() {
		$post_id = self::factory()->post->create();

		ob_start();
		$this->icon->render_format_icon_column( 'daymark_format_icon', $post_id );
		$output = ob_get_clean();

		// The data-format attribute stays the raw, empty taxonomy value
		// (matching Quick Edit's own value="" option for Standard, and what
		// assets/admin-post-format.js reads to prefill it) even though the
		// icon itself falls back to the literal 'standard' dashicon lookup.
		$this->assertStringContainsString( 'data-format=""', $output );
		$this->assertStringContainsString( 'dashicons-format-standard', $output );
		$this->assertStringContainsString( 'Standard', $output );
	}

	public function test_render_format_icon_column_echoes_the_matching_dashicon_for_a_real_format() {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		ob_start();
		$this->icon->render_format_icon_column( 'daymark_format_icon', $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-format="video"', $output );
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

	public function test_render_quick_edit_field_is_a_no_op_for_a_different_column() {
		$this->expectOutputString( '' );
		$this->icon->render_quick_edit_field( 'date', 'post' );
	}

	public function test_render_quick_edit_field_is_a_no_op_for_an_unsupported_post_type() {
		$this->expectOutputString( '' );
		$this->icon->render_quick_edit_field( 'daymark_format_icon', 'page' );
	}

	public function test_render_quick_edit_field_is_a_no_op_when_the_theme_declares_no_post_formats() {
		remove_theme_support( 'post-formats' );

		$this->expectOutputString( '' );
		$this->icon->render_quick_edit_field( 'daymark_format_icon', 'post' );

		// Restore for tear_down()'s own remove_theme_support() call, which
		// is harmless either way but keeps this test's intent explicit.
		add_theme_support( 'post-formats', array( 'aside', 'gallery', 'video', 'audio', 'image', 'status', 'link' ) );
	}

	public function test_render_quick_edit_field_offers_only_the_themes_own_declared_formats() {
		ob_start();
		$this->icon->render_quick_edit_field( 'daymark_format_icon', 'post' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="daymark_post_format"', $output );
		$this->assertStringContainsString( '<option value="">', $output );
		$this->assertStringContainsString( '<option value="video">', $output );
		$this->assertStringContainsString( '<option value="link">', $output );

		// 'chat' has a dashicon mapping in this class but was never declared
		// by the theme in set_up() — it must not appear as an option.
		$this->assertStringNotContainsString( 'value="chat"', $output );
	}

	public function test_save_quick_edit_format_is_a_no_op_without_the_expected_post_fields() {
		$post_id = self::factory()->post->create();

		$this->icon->save_quick_edit_format( $post_id );

		$this->assertFalse( get_post_format( $post_id ) );
	}

	public function test_save_quick_edit_format_is_a_no_op_for_a_non_inline_save_action() {
		$post_id = self::factory()->post->create();

		$_POST['action']              = 'editpost';
		$_POST['_inline_edit']        = wp_create_nonce( 'inlineeditnonce' );
		$_POST['daymark_post_format'] = 'video';

		$this->icon->save_quick_edit_format( $post_id );

		$this->assertFalse( get_post_format( $post_id ) );
	}

	public function test_save_quick_edit_format_is_a_no_op_with_an_invalid_nonce() {
		$post_id = self::factory()->post->create();

		$_POST['action']              = 'inline-save';
		$_POST['_inline_edit']        = 'not-a-real-nonce';
		$_POST['daymark_post_format'] = 'video';

		$this->icon->save_quick_edit_format( $post_id );

		$this->assertFalse( get_post_format( $post_id ) );
	}

	public function test_save_quick_edit_format_is_a_no_op_for_an_unsupported_post_type() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$_POST['action']              = 'inline-save';
		$_POST['_inline_edit']        = wp_create_nonce( 'inlineeditnonce' );
		$_POST['daymark_post_format'] = 'video';

		$this->icon->save_quick_edit_format( $page_id );

		$this->assertFalse( get_post_format( $page_id ) );
	}

	public function test_save_quick_edit_format_requires_edit_post_capability() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$post_id = self::factory()->post->create();

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_POST['action']              = 'inline-save';
		$_POST['_inline_edit']        = wp_create_nonce( 'inlineeditnonce' );
		$_POST['daymark_post_format'] = 'video';

		$this->icon->save_quick_edit_format( $post_id );

		$this->assertFalse( get_post_format( $post_id ) );
	}

	public function test_save_quick_edit_format_sets_a_valid_theme_supported_format() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$post_id = self::factory()->post->create();

		$_POST['action']              = 'inline-save';
		$_POST['_inline_edit']        = wp_create_nonce( 'inlineeditnonce' );
		$_POST['daymark_post_format'] = 'video';

		$this->icon->save_quick_edit_format( $post_id );

		$this->assertSame( 'video', get_post_format( $post_id ) );
	}

	public function test_save_quick_edit_format_clears_the_format_for_an_unrecognized_value() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		// Not declared by the theme in set_up() (and not a known dashicon
		// format at all) — a tampered or stale value should clear back to
		// Standard rather than being trusted.
		$_POST['action']              = 'inline-save';
		$_POST['_inline_edit']        = wp_create_nonce( 'inlineeditnonce' );
		$_POST['daymark_post_format'] = 'not-a-real-format';

		$this->icon->save_quick_edit_format( $post_id );

		$this->assertFalse( get_post_format( $post_id ) );
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

	public function test_enqueue_quick_edit_script_is_a_no_op_outside_the_matching_screen() {
		set_current_screen( 'edit-page' );

		$this->icon->enqueue_quick_edit_script();

		$this->assertFalse( wp_script_is( 'daymark-admin-post-format', 'enqueued' ) );
	}

	public function test_enqueue_quick_edit_script_enqueues_on_the_matching_screen() {
		set_current_screen( 'edit-post' );

		$this->icon->enqueue_quick_edit_script();

		$this->assertTrue( wp_script_is( 'daymark-admin-post-format', 'enqueued' ) );
	}
}
