<?php
/**
 * "Format" column on wp-admin's post list screens.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a sortable "Format" column, immediately after Title, on every
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
 * Purely additive to WordPress core's own post-format UI: the column links
 * back to the exact same `post_format` query-string filter core's own "All
 * formats" dropdown already submits on this same screen (Daymark never
 * renders a second dropdown), and reads with `get_post_format()` /
 * `get_post_format_string()` — the same core APIs that dropdown and Quick
 * Edit already use — rather than any Daymark-owned format concept.
 */
class Daymark_Admin_Post_Format_Column {

	/**
	 * Column id — reused for the columns filter, the custom-column render
	 * callback, the sortable-columns filter, and the `orderby` key this
	 * class teaches `posts_clauses` to understand.
	 *
	 * @var string
	 */
	private const COLUMN = 'daymark_post_format';

	/**
	 * Post types Daymark integrates with — see the class docblock above for
	 * why `daymark_sub_post` is named here even though it never currently
	 * qualifies (no post-formats support, no list table to add a column to).
	 *
	 * @var string[]
	 */
	private const DAYMARK_POST_TYPES = array( 'post', Daymark_Subscription_Post_Type::POST_TYPE );

	/**
	 * Register hooks for every currently-qualifying post type.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::supported_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'add_sortable_column' ) );
		}

		add_filter( 'posts_clauses', array( $this, 'sort_by_format' ), 10, 2 );
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
	 * Insert the Format column immediately after Title — falls back to
	 * appending it if a customized screen has no Title column to anchor to.
	 *
	 * @param array<string, string> $columns Existing column id => label map.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$with_format = array();
		$inserted    = false;

		foreach ( $columns as $key => $label ) {
			$with_format[ $key ] = $label;

			if ( 'title' === $key ) {
				$with_format[ self::COLUMN ] = __( 'Format', 'daymark' );
				$inserted                    = true;
			}
		}

		if ( ! $inserted ) {
			$with_format[ self::COLUMN ] = __( 'Format', 'daymark' );
		}

		return $with_format;
	}

	/**
	 * Render the Format column's cell: the human-readable format from
	 * `get_post_format_string()`, linked to core's own `post_format`
	 * query-string filter for anything other than Standard
	 * (`get_post_format()` returning false).
	 *
	 * @param string $column  Current column id.
	 * @param int    $post_id Current row's post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$format = get_post_format( $post_id );

		if ( false === $format ) {
			echo esc_html( get_post_format_string( 'standard' ) );
			return;
		}

		$url = add_query_arg(
			array(
				'post_type'   => get_post_type( $post_id ),
				'post_format' => $format,
			),
			admin_url( 'edit.php' )
		);

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( get_post_format_string( $format ) )
		);
	}

	/**
	 * Register the Format column as sortable.
	 *
	 * @param array<string, string> $columns Existing sortable-column id => orderby-key map.
	 * @return array<string, string>
	 */
	public function add_sortable_column( array $columns ): array {
		$columns[ self::COLUMN ] = self::COLUMN;

		return $columns;
	}

	/**
	 * Sort by format when the Format column's own sort link is followed.
	 *
	 * Post formats are `post_format` taxonomy terms, not a plain post
	 * column, so this can't be a simple `orderby => 'meta_value'`-style
	 * swap — it joins to the term tables directly, the same way any
	 * taxonomy-backed admin column sort has to. A LEFT JOIN (never an INNER
	 * JOIN) is required so a Standard post — one with no `post_format` term
	 * at all — still appears in the results; `COALESCE` gives that missing
	 * term a stable sort key (the same string a real 'standard' term would
	 * have used, had one existed) so every Standard post sorts together,
	 * consistently, in both directions, rather than landing wherever a raw
	 * `NULL` happens to sort in MySQL. Post title is always the secondary
	 * sort key, preserving the normal tie-break a reader would expect when
	 * several posts share one format.
	 *
	 * Scoped narrowly: only the exact `orderby` key this class itself
	 * registers as sortable, and only on the matching post type's own list
	 * table screen — `get_current_screen()`, not `is_admin()`, is the check
	 * here, since it's the one signal that's both reliably set for the
	 * intended screen and reliably absent everywhere else this must never
	 * run: a REST request, a front-end request, or any other admin screen.
	 *
	 * @param array<string, string> $clauses SQL clause pieces.
	 * @param WP_Query              $query   The current query.
	 * @return array<string, string>
	 */
	public function sort_by_format( array $clauses, WP_Query $query ): array {
		if ( self::COLUMN !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base ) {
			return $clauses;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = '' === $post_type ? 'post' : $post_type;

		if (
			! is_string( $post_type )
			|| $screen->post_type !== $post_type
			|| ! in_array( $post_type, self::supported_post_types(), true )
		) {
			return $clauses;
		}

		global $wpdb;

		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';

		$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS daymark_pf_tr ON ( {$wpdb->posts}.ID = daymark_pf_tr.object_id )"
			. " LEFT JOIN {$wpdb->term_taxonomy} AS daymark_pf_tt ON ( daymark_pf_tr.term_taxonomy_id = daymark_pf_tt.term_taxonomy_id AND daymark_pf_tt.taxonomy = 'post_format' )"
			. " LEFT JOIN {$wpdb->terms} AS daymark_pf_t ON ( daymark_pf_tt.term_id = daymark_pf_t.term_id )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names only, not user input.

		$clauses['groupby'] = $clauses['groupby'] ? $clauses['groupby'] : "{$wpdb->posts}.ID";

		$clauses['orderby'] = "COALESCE( daymark_pf_t.slug, 'post-format-standard' ) {$order}, {$wpdb->posts}.post_title ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $order is validated to one of two literal values above, not user input.

		return $clauses;
	}
}
