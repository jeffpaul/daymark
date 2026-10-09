<?php
/**
 * Reblogs column on wp-admin's Posts list.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Reblogs column to the Posts list, right after the comments
 * column, showing the same number as a Timeline card's reblog count
 * (Daymark_Reblog_Count::for_post()).
 *
 * Only on `post`, where Marks and ordinary posts both live. Uses the same
 * column filter and custom-column action as
 * Daymark_Admin_Post_Format_Icon; core never escapes what that action
 * echoes, so the cell's own output is escaped here. Not sortable: the count
 * is computed per post, not stored where a query could order by it.
 */
class Daymark_Admin_Reblog_Column {

	/**
	 * Column key.
	 *
	 * @var string
	 */
	public const COLUMN = 'daymark_reblogs';

	/**
	 * The post type that gets the column.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'post';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );
	}

	/**
	 * Insert the column right after the comments column, or before Date
	 * when comments are turned off for posts, or at the end.
	 *
	 * @param array<string, string> $columns Existing column key => label map.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$header = sprintf(
			'<span class="dashicons dashicons-controls-repeat daymark-reblogs-icon" title="%1$s" aria-hidden="true"></span><span class="screen-reader-text">%1$s</span>',
			esc_attr__( 'Reblogs', 'daymark' )
		);

		$after      = isset( $columns['comments'] ) ? 'comments' : '';
		$before     = '' === $after && isset( $columns['date'] ) ? 'date' : '';
		$positioned = array();

		foreach ( $columns as $key => $label ) {
			if ( $key === $before ) {
				$positioned[ self::COLUMN ] = $header;
			}

			$positioned[ $key ] = $label;

			if ( $key === $after ) {
				$positioned[ self::COLUMN ] = $header;
			}
		}

		if ( ! isset( $positioned[ self::COLUMN ] ) ) {
			$positioned[ self::COLUMN ] = $header;
		}

		return $positioned;
	}

	/**
	 * Render one post's reblog count.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$count = Daymark_Reblog_Count::for_post( $post_id );

		printf(
			'<span class="daymark-reblogs-count" aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>',
			esc_html( number_format_i18n( $count ) ),
			esc_html(
				sprintf(
					/* translators: %s: number of reblogs. */
					_n( '%s reblog', '%s reblogs', $count, 'daymark' ),
					number_format_i18n( $count )
				)
			)
		);
	}

	/**
	 * Keep the column narrow and its icon in line with core's comment
	 * bubble. Attached to core's always-loaded `common` handle, the same
	 * way Daymark_Admin_Post_Format_Icon styles its column.
	 *
	 * @return void
	 */
	public function enqueue_style(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_add_inline_style(
			'common',
			'.fixed .column-' . self::COLUMN . ' { width: 3em; text-align: center; }'
			. ' .daymark-reblogs-icon { color: #787c82; }'
		);
	}
}
