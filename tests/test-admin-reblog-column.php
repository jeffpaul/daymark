<?php
/**
 * Reblogs column tests.
 *
 * @package Daymark
 */

/**
 * The Reblogs column on wp-admin's Posts list.
 */
class Test_Admin_Reblog_Column extends WP_UnitTestCase {

	/**
	 * @var Daymark_Admin_Reblog_Column
	 */
	private Daymark_Admin_Reblog_Column $column;

	public function set_up() {
		parent::set_up();
		$this->column = new Daymark_Admin_Reblog_Column();
	}

	/**
	 * Render one cell and return its HTML.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function render( int $post_id ): string {
		ob_start();
		$this->column->render_column( Daymark_Admin_Reblog_Column::COLUMN, $post_id );

		return (string) ob_get_clean();
	}

	public function test_register_hooks_the_posts_list() {
		$this->column->register();

		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', array( $this->column, 'add_column' ) ) );
		$this->assertSame( 10, has_action( 'manage_post_posts_custom_column', array( $this->column, 'render_column' ) ) );

		remove_filter( 'manage_post_posts_columns', array( $this->column, 'add_column' ) );
		remove_action( 'manage_post_posts_custom_column', array( $this->column, 'render_column' ), 10 );
		remove_action( 'admin_enqueue_scripts', array( $this->column, 'enqueue_style' ) );
	}

	public function test_column_sits_right_after_comments() {
		$columns = $this->column->add_column(
			array(
				'cb'       => '',
				'title'    => 'Title',
				'comments' => 'Comments',
				'date'     => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', 'comments', Daymark_Admin_Reblog_Column::COLUMN, 'date' ), array_keys( $columns ) );
		$this->assertStringContainsString( 'Reblogs', $columns[ Daymark_Admin_Reblog_Column::COLUMN ] );
	}

	public function test_column_sits_before_date_without_comments() {
		$columns = $this->column->add_column(
			array(
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( array( 'title', Daymark_Admin_Reblog_Column::COLUMN, 'date' ), array_keys( $columns ) );
	}

	public function test_column_is_appended_when_neither_exists() {
		$columns = $this->column->add_column( array( 'title' => 'Title' ) );

		$this->assertSame( array( 'title', Daymark_Admin_Reblog_Column::COLUMN ), array_keys( $columns ) );
	}

	public function test_other_columns_render_nothing() {
		$post_id = self::factory()->post->create();

		ob_start();
		$this->column->render_column( 'title', $post_id );

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_cell_shows_zero_for_a_post_never_reblogged() {
		$post_id = self::factory()->post->create();

		$this->assertStringContainsString( '<span class="daymark-reblogs-count" aria-hidden="true">0</span>', $this->render( $post_id ) );
	}

	/** Federation reposts and connector-reported reblogs both count. */
	public function test_cell_counts_reposts_and_connector_reblogs() {
		$post_id = self::factory()->post->create();

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'repost',
				'comment_approved' => 1,
			)
		);
		Daymark_Backflow_Sync::store_reactions( $post_id, 'bluesky', array( 'reposts' => 2 ) );

		$html = $this->render( $post_id );

		$this->assertStringContainsString( '>3</span>', $html );
		$this->assertStringContainsString( '3 reblogs', $html );
	}

	/** The column and the Timeline card show the same number. */
	public function test_cell_matches_the_timeline_count() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'quote',
				'comment_approved' => 1,
			)
		);
		Daymark_Backflow_Sync::store_reactions( $post_id, 'mastodon', array( 'reposts' => 4 ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$timeline_count = rest_do_request( $request )->get_data()['repost_count'];

		$this->assertSame( 5, $timeline_count );
		$this->assertSame( $timeline_count, Daymark_Reblog_Count::for_post( $post_id ) );
		$this->assertStringContainsString( '>5</span>', $this->render( $post_id ) );
	}
}
