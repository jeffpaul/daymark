<?php
/**
 * "Format" column tests.
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
 * The sortable "Format" column added to wp-admin's post list screens.
 */
class Test_Admin_Post_Format_Column extends WP_UnitTestCase {

	/**
	 * @var Daymark_Admin_Post_Format_Column
	 */
	private Daymark_Admin_Post_Format_Column $column;

	public function set_up() {
		parent::set_up();

		$this->column = new Daymark_Admin_Post_Format_Column();

		// Post formats are a theme feature; without this, post_type_supports()
		// is false and get_post_format() always returns false regardless of
		// any stored term — matching core's own test convention for
		// exercising post-format behavior.
		add_theme_support( 'post-formats', array( 'aside', 'gallery', 'video', 'audio', 'image', 'status' ) );
	}

	public function tear_down() {
		remove_theme_support( 'post-formats' );
		remove_filter( 'manage_post_posts_columns', array( $this->column, 'add_column' ) );
		remove_action( 'manage_post_posts_custom_column', array( $this->column, 'render_column' ) );
		remove_filter( 'manage_edit-post_sortable_columns', array( $this->column, 'add_sortable_column' ) );
		remove_filter( 'posts_clauses', array( $this->column, 'sort_by_format' ) );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_column_inserted_immediately_after_title() {
		$columns = $this->column->add_column(
			array(
				'cb'     => '<input type="checkbox" />',
				'title'  => 'Title',
				'author' => 'Author',
				'date'   => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', 'daymark_post_format', 'author', 'date' ), array_keys( $columns ) );
		$this->assertSame( 'Format', $columns['daymark_post_format'] );
	}

	public function test_column_appended_when_title_column_is_missing() {
		$columns = $this->column->add_column(
			array(
				'cb'   => '<input type="checkbox" />',
				'date' => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'date', 'daymark_post_format' ), array_keys( $columns ) );
	}

	public function test_render_column_does_nothing_for_a_different_column() {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', get_echo( array( $this->column, 'render_column' ), array( 'author', $post_id ) ) );
	}

	public function test_render_column_outputs_plain_standard_text_with_no_format() {
		$post_id = self::factory()->post->create();

		$output = get_echo( array( $this->column, 'render_column' ), array( 'daymark_post_format', $post_id ) );

		$this->assertSame( 'Standard', $output );
		$this->assertStringNotContainsString( '<a', $output );
	}

	public function test_render_column_links_to_the_native_post_format_filter_for_a_real_format() {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$output = get_echo( array( $this->column, 'render_column' ), array( 'daymark_post_format', $post_id ) );

		$this->assertStringContainsString( '>Aside<', $output );
		$this->assertStringContainsString( admin_url( 'edit.php' ), $output );
		$this->assertStringContainsString( 'post_format=aside', $output );
		$this->assertStringContainsString( 'post_type=post', $output );
	}

	public function test_add_sortable_column_registers_the_orderby_key() {
		$columns = $this->column->add_sortable_column( array( 'date' => 'date' ) );

		$this->assertSame( 'daymark_post_format', $columns['daymark_post_format'] );
	}

	public function test_sort_by_format_is_a_no_op_for_a_different_orderby() {
		$query = new WP_Query();
		$query->set( 'orderby', 'date' );

		$clauses = array(
			'join'    => '',
			'orderby' => '',
			'groupby' => '',
		);

		$this->assertSame( $clauses, $this->column->sort_by_format( $clauses, $query ) );
	}

	public function test_sort_by_format_is_a_no_op_outside_the_matching_list_table_screen() {
		set_current_screen( 'front' );

		$query = new WP_Query();
		$query->set( 'orderby', 'daymark_post_format' );
		$query->set( 'post_type', 'post' );

		$clauses = array(
			'join'    => '',
			'orderby' => '',
			'groupby' => '',
		);

		$this->assertSame( $clauses, $this->column->sort_by_format( $clauses, $query ) );
	}

	public function test_sort_by_format_is_a_no_op_for_an_unsupported_post_type() {
		set_current_screen( 'edit-page' );

		$query = new WP_Query();
		$query->set( 'orderby', 'daymark_post_format' );
		$query->set( 'post_type', 'page' );

		$clauses = array(
			'join'    => '',
			'orderby' => '',
			'groupby' => '',
		);

		$this->assertSame( $clauses, $this->column->sort_by_format( $clauses, $query ) );
	}

	public function test_sort_by_format_builds_join_and_orderby_on_the_matching_screen() {
		set_current_screen( 'edit-post' );

		$query = new WP_Query();
		$query->set( 'orderby', 'daymark_post_format' );
		$query->set( 'post_type', 'post' );
		$query->set( 'order', 'ASC' );

		$clauses = $this->column->sort_by_format(
			array(
				'join'    => '',
				'orderby' => '',
				'groupby' => '',
			),
			$query
		);

		$this->assertStringContainsString( 'LEFT JOIN', $clauses['join'] );
		$this->assertStringContainsString( 'post_format', $clauses['join'] );
		$this->assertStringContainsString( 'COALESCE', $clauses['orderby'] );
		$this->assertStringContainsString( 'post-format-standard', $clauses['orderby'] );
		$this->assertStringContainsString( 'post_title', $clauses['orderby'] );
		// Deliberately no GROUP BY — see sort_by_format()'s own docblock:
		// post_format is a single-value taxonomy, so the join can't multiply
		// rows, and adding one anyway breaks under MySQL's default
		// ONLY_FULL_GROUP_BY mode.
		$this->assertSame( '', $clauses['groupby'] );
	}

	/**
	 * End-to-end: real posts, a real sort, grouped by format then title.
	 */
	public function test_sorting_groups_standard_posts_and_orders_by_format_then_title() {
		set_current_screen( 'edit-post' );
		// A real WP_Query only runs sort_by_format() via the posts_clauses
		// filter register() actually hooks — unlike the narrower unit tests
		// above, which call sort_by_format() directly and need no hook.
		$this->column->register();

		$video_banana    = self::factory()->post->create(
			array(
				'post_title'  => 'Banana',
				'post_status' => 'publish',
			)
		);
		$standard_apple  = self::factory()->post->create(
			array(
				'post_title'  => 'Apple',
				'post_status' => 'publish',
			)
		);
		$standard_cherry = self::factory()->post->create(
			array(
				'post_title'  => 'Cherry',
				'post_status' => 'publish',
			)
		);
		$aside_date      = self::factory()->post->create(
			array(
				'post_title'  => 'Date',
				'post_status' => 'publish',
			)
		);
		$video_elder     = self::factory()->post->create(
			array(
				'post_title'  => 'Elderberry',
				'post_status' => 'publish',
			)
		);

		set_post_format( $video_banana, 'video' );
		set_post_format( $video_elder, 'video' );
		set_post_format( $aside_date, 'aside' );
		// $standard_apple / $standard_cherry deliberately left with no format.

		$ascending = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'orderby'        => 'daymark_post_format',
				'order'          => 'ASC',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertSame(
			array( $aside_date, $standard_apple, $standard_cherry, $video_banana, $video_elder ),
			$ascending->posts
		);

		$descending = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'orderby'        => 'daymark_post_format',
				'order'          => 'DESC',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertSame(
			array( $video_banana, $video_elder, $standard_apple, $standard_cherry, $aside_date ),
			$descending->posts
		);
	}

	public function test_register_hooks_the_column_for_post_when_post_formats_is_supported() {
		$this->column->register();

		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', array( $this->column, 'add_column' ) ) );
		$this->assertNotFalse( has_action( 'manage_post_posts_custom_column', array( $this->column, 'render_column' ) ) );
		$this->assertNotFalse( has_filter( 'manage_edit-post_sortable_columns', array( $this->column, 'add_sortable_column' ) ) );
	}

	public function test_register_does_not_hook_the_column_for_post_when_post_formats_is_unsupported() {
		// remove_theme_support( 'post-formats' ) alone does not undo
		// add_theme_support()'s own add_post_type_support( 'post',
		// 'post-formats' ) side effect, so that flag needs clearing directly
		// to actually exercise the "unsupported" case — and, since (unlike
		// theme features) that flag isn't reset between tests by core's own
		// test suite, it must be restored before this test ends: other test
		// files (e.g. Test_Publisher, Test_Rest_Timeline) assume 'post'
		// supports post-formats for the rest of the PHPUnit process.
		remove_post_type_support( 'post', 'post-formats' );

		$this->column->register();

		$this->assertFalse( has_filter( 'manage_post_posts_columns', array( $this->column, 'add_column' ) ) );
		$this->assertFalse( has_action( 'manage_post_posts_custom_column', array( $this->column, 'render_column' ) ) );
		$this->assertFalse( has_filter( 'manage_edit-post_sortable_columns', array( $this->column, 'add_sortable_column' ) ) );

		add_post_type_support( 'post', 'post-formats' );
	}
}
