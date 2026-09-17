<?php
/**
 * Post-format icon indicator tests.
 *
 * @package Daymark
 */

// get_current_screen()/set_current_screen() live in wp-admin/includes/screen.php,
// which the plain WP PHPUnit bootstrap never loads (it's only pulled in by a
// real wp-admin/admin.php request) — the same reason test-admin-bar.php
// conditionally requires WP_Admin_Bar's own class file. This also means
// add_format_icon()'s own function_exists( 'get_current_screen' ) guard (a
// real, CI-crash-confirmed fix — the_title fires on every front-end/login/
// feed request too, where that function is genuinely undefined, not merely
// returning null) can't be exercised by a dedicated test in this same
// process: once this require runs, the function stays defined for every
// later test here regardless of what it checks.
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

/**
 * The post-format icon indicator prepended to a post's title on wp-admin's
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
		remove_filter( 'the_title', array( $this->icon, 'add_format_icon' ), 20 );
		remove_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_icon_style' ) );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_add_format_icon_is_a_no_op_outside_the_matching_list_table_screen() {
		set_current_screen( 'front' );
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		$this->assertSame( 'A title', $this->icon->add_format_icon( 'A title', $post_id ) );
	}

	public function test_add_format_icon_is_a_no_op_for_an_unsupported_post_type() {
		set_current_screen( 'edit-page' );
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		set_post_format( $post_id, 'video' );

		$this->assertSame( 'A title', $this->icon->add_format_icon( 'A title', $post_id ) );
	}

	public function test_add_format_icon_is_a_no_op_when_post_type_does_not_match_the_screen() {
		set_current_screen( 'edit-post' );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 'A title', $this->icon->add_format_icon( 'A title', $page_id ) );
	}

	public function test_add_format_icon_returns_title_unchanged_for_standard_posts() {
		set_current_screen( 'edit-post' );
		$post_id = self::factory()->post->create();

		$this->assertSame( 'A title', $this->icon->add_format_icon( 'A title', $post_id ) );
	}

	public function test_add_format_icon_prepends_the_matching_dashicon_for_a_real_format() {
		set_current_screen( 'edit-post' );
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		$output = $this->icon->add_format_icon( 'A title', $post_id );

		$this->assertStringContainsString( 'dashicons-format-video', $output );
		$this->assertStringContainsString( 'daymark-format-icon', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
		$this->assertStringContainsString( 'screen-reader-text', $output );
		$this->assertStringContainsString( 'Video', $output );
		$this->assertStringEndsWith( 'A title', $output );
	}

	public function test_add_format_icon_uses_the_pluralized_dashicon_for_link_format() {
		set_current_screen( 'edit-post' );
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'link' );

		$output = $this->icon->add_format_icon( 'A title', $post_id );

		// WordPress core's own dashicon class for the "link" post format is
		// pluralized ("format-links"), unlike every other format's own
		// slug-matching class name — confirmed directly against core's CSS.
		$this->assertStringContainsString( 'dashicons-format-links', $output );
		$this->assertStringNotContainsString( 'dashicons-format-link"', $output );
	}

	public function test_add_format_icon_does_not_double_escape_an_already_escaped_title() {
		// register() runs this filter at priority 20, deliberately after
		// core's own `the_title` -> `esc_html` pass (priority 10) — so by
		// the time this method runs, $title already arrives pre-escaped.
		// This asserts the icon is prepended without re-escaping it.
		set_current_screen( 'edit-post' );
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'quote' );

		$escaped_title = esc_html( 'Fish & Chips' );
		$output        = $this->icon->add_format_icon( $escaped_title, $post_id );

		$this->assertStringEndsWith( $escaped_title, $output );
		$this->assertStringNotContainsString( '&amp;amp;', $output );
	}

	public function test_register_hooks_the_title_filter_at_priority_20() {
		$this->icon->register();

		// Priority 20, not the default 10: this must run after core's own
		// `the_title` -> `esc_html` pass (also priority 10, added inside
		// WP_Posts_List_Table::display_rows()) so this icon's markup is
		// appended post-escaping rather than escaped into literal text.
		$this->assertSame( 20, has_filter( 'the_title', array( $this->icon, 'add_format_icon' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->icon, 'enqueue_icon_style' ) ) );
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
