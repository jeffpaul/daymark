<?php
/**
 * Post-format icon indicator on wp-admin's post list screens.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a narrow, icon-only column immediately before Title on every
 * wp-admin post list screen for a post type Daymark integrates with that
 * also supports WordPress core's own post-formats feature.
 *
 * Today that's `post` alone — Marks live there (Daymark_Publisher already
 * writes the real `post_format` taxonomy via `set_post_format()`, see its
 * `TYPE_POST_FORMATS` map) alongside any ordinary, non-Mark post the site
 * already had. `daymark_sub_post` (Daymark_Subscription_Post_Type) never
 * currently qualifies — it declares no post-formats support, and has no
 * admin list table at all (`show_ui => false`) — but it's still named
 * explicitly in DAYMARK_POST_TYPES below rather than this class only ever
 * checking `post`, so a future post type Daymark integrates with picks
 * this up automatically instead of needing a second change here.
 *
 * This is the class's SECOND implementation. The first hooked the generic
 * `the_title` filter to prepend icon markup directly into the title string,
 * on the theory that a later priority (20) than core's own `the_title` ->
 * `esc_html` pass (added inside `display_rows()`, priority 10) would let
 * the icon's HTML survive uncscaped. That shipped, then broke in
 * production: `WP_Posts_List_Table::column_title()` doesn't render the
 * `the_title` filter chain's result directly — it calls
 * `_draft_or_post_title()`, which wraps the ENTIRE chain's output (icon
 * markup included) in its own second, unconditional `esc_html()` call,
 * confirmed directly against core source
 * (`wp-admin/includes/template.php`). No filter priority can outrun a
 * separate escape applied by the calling function itself, so the icon's
 * markup always rendered as literal, visible text instead of an icon.
 *
 * A narrow custom column (this implementation) sidesteps the problem
 * entirely: `WP_Posts_List_Table::column_default()` fires a plain action
 * hook (`manage_{$post_type}_posts_custom_column`) for any non-built-in
 * column and never escapes or otherwise post-processes whatever that
 * action echoes — confirmed directly against core source — the same
 * mechanism every plugin that renders a thumbnail, a star rating, or any
 * other inline markup in the list table already relies on. Positioning is
 * controlled by where a key is inserted into the array the matching
 * `manage_{$post_type}_posts_columns` filter returns (also confirmed
 * against core source), so inserting this column's key immediately before
 * `title` puts the icon exactly where the mockup showed it — visually
 * beside the post name — without ever touching the title string itself.
 *
 * Deliberately no header text (screen-reader-only instead) and
 * deliberately no sorting: WordPress core already renders a filter
 * dropdown on this same screen automatically
 * (`WP_Posts_List_Table::formats_dropdown()`, confirmed directly against
 * core source) once at least one post uses a non-Standard format —
 * building a second one here would be exactly the duplicate UI a plain,
 * purely visual icon avoids. This class is a pure identifier, not a
 * second filtering mechanism.
 */
class Daymark_Admin_Post_Format_Icon {

	/**
	 * The column key this class adds.
	 *
	 * @var string
	 */
	private const COLUMN = 'daymark_format_icon';

	/**
	 * Post types Daymark integrates with — see the class docblock above for
	 * why `daymark_sub_post` is named here even though it never currently
	 * qualifies (no post-formats support, no list table to add a column to).
	 *
	 * @var string[]
	 */
	private const DAYMARK_POST_TYPES = array( 'post', Daymark_Subscription_Post_Type::POST_TYPE );

	/**
	 * Post format slug => dashicon class. WordPress core already ships one
	 * dashicon per post format (`wp-includes/css/dashicons.css`), so no new
	 * icon asset is needed here — except `link`, whose dashicon class is
	 * pluralized ("format-links") unlike every other format's own
	 * slug-matching class name; confirmed directly against core's own CSS
	 * rather than assumed. `standard` has no entry: a Standard post gets no
	 * icon at all.
	 *
	 * @var array<string, string>
	 */
	private const FORMAT_DASHICONS = array(
		'aside'   => 'dashicons-format-aside',
		'audio'   => 'dashicons-format-audio',
		'chat'    => 'dashicons-format-chat',
		'gallery' => 'dashicons-format-gallery',
		'image'   => 'dashicons-format-image',
		'link'    => 'dashicons-format-links',
		'quote'   => 'dashicons-format-quote',
		'status'  => 'dashicons-format-status',
		'video'   => 'dashicons-format-video',
	);

	/**
	 * Register hooks.
	 *
	 * One column-filter/custom-column-action pair per currently-supported
	 * post type, since both hook names are post-type-specific
	 * (`manage_{$post_type}_posts_columns` /
	 * `manage_{$post_type}_posts_custom_column`) — there is no single,
	 * post-type-agnostic hook for either. Registered at `init` (this
	 * method's own caller), after a theme's `after_setup_theme` has already
	 * run any `add_theme_support( 'post-formats', ... )` call, so
	 * `supported_post_types()` reflects real support by the time this runs.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::supported_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_format_icon_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_format_icon_column' ), 10, 2 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_icon_style' ) );
	}

	/**
	 * Daymark's own post types that also currently support WordPress core's
	 * post-formats feature — computed live, not cached, since a theme can
	 * change (and therefore change post-formats support) without this
	 * plugin reloading.
	 *
	 * @return string[]
	 */
	private static function supported_post_types(): array {
		return array_values(
			array_filter(
				self::DAYMARK_POST_TYPES,
				static function ( string $post_type ): bool {
					return post_type_supports( $post_type, 'post-formats' );
				}
			)
		);
	}

	/**
	 * Insert the icon column immediately before Title.
	 *
	 * @param array<string, string> $columns Existing column key => label map.
	 * @return array<string, string>
	 */
	public function add_format_icon_column( array $columns ): array {
		$positioned = array();

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$positioned[ self::COLUMN ] = '<span class="screen-reader-text">' . esc_html__( 'Format', 'daymark' ) . '</span>';
			}

			$positioned[ $key ] = $label;
		}

		return $positioned;
	}

	/**
	 * Render the icon for this column.
	 *
	 * A plain action hook — WordPress core never escapes or otherwise
	 * post-processes what this echoes (confirmed directly against core
	 * source), unlike the `the_title` filter this class's earlier design
	 * relied on; see the class docblock for why that distinction is exactly
	 * what makes this implementation actually work.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_format_icon_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$format = get_post_format( $post_id );

		if ( false === $format || ! isset( self::FORMAT_DASHICONS[ $format ] ) ) {
			return;
		}

		printf(
			'<span class="dashicons %1$s daymark-format-icon" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span>',
			esc_attr( self::FORMAT_DASHICONS[ $format ] ),
			esc_html( get_post_format_string( $format ) )
		);
	}

	/**
	 * Size and color the icon, and tighten the column itself so it reads as
	 * sitting immediately beside the title rather than as its own
	 * wide, padded column — a small inline style on core's own always-
	 * loaded `common` handle, matching this codebase's established pattern
	 * for a narrow, screen-specific style rule (e.g. Daymark_Admin_
	 * Subscriptions's `<details>`-marker suppression) rather than
	 * registering a new stylesheet for one rule.
	 *
	 * @return void
	 */
	public function enqueue_icon_style(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
			return;
		}

		wp_add_inline_style(
			'common',
			'.fixed .column-' . self::COLUMN . ' { width: 2em; padding: 8px 0 8px 8px; text-align: center; }'
			. ' .daymark-format-icon { color: #787c82; vertical-align: text-bottom; }'
		);
	}
}
