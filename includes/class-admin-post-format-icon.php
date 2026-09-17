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
 *
 * Also adds a Format field to Quick Edit, which core's own Quick Edit row
 * has never exposed at all (only the classic-editor meta box and the
 * block editor's own sidebar can set a post's format) — a real gap a site
 * owner flagged directly once the icon column above made a post's format
 * visible on this screen for the first time. `quick_edit_custom_box`
 * fires once per non-core column while WordPress builds the *shared*
 * hidden Quick Edit template row (confirmed directly against core
 * source: it already fires for this class's own `daymark_format_icon`
 * column, so no new hook registration was needed to reach it), inside an
 * already-open `<fieldset class="inline-edit-col-right">` — no wrapper of
 * our own needed. Since that template is shared by every row, prefilling
 * the dropdown with a specific post's current format is a client-side
 * job: `render_format_icon_column()` now wraps its icon markup in a
 * `data-format` attribute the new `assets/admin-post-format.js` reads
 * once Quick Edit opens for a given row, using the same
 * `inlineEditPost.edit` override technique the WordPress Developer
 * Handbook documents for exactly this "prefill a custom Quick Edit field"
 * case (not independently re-confirmed against `inline-edit-post.js`'s
 * own source in this environment — GitHub's raw/API hosts both returned
 * 403/404 to this session's outbound fetches — but this is one of the
 * most widely-documented, decade-stable WordPress extension patterns
 * there is, unlike the internal `_draft_or_post_title()` escaping
 * behavior above that took three iterations to get right; flagged here
 * for Jeff to verify Quick Edit's prefill against a real site before
 * relying on it further). Saving reuses Quick Edit's own existing
 * `inlineeditnonce`/`_inline_edit` nonce (confirmed directly against
 * `wp_ajax_inline_save()`'s own source: it already verifies this nonce,
 * and already calls `edit_post()`, which fires the normal `save_post`
 * hook chain) rather than adding a second nonce field — `save_post`
 * itself gates on `$_POST['action'] === 'inline-save'` so it only ever
 * acts during a genuine Quick Edit save, never a normal post save.
 * Deliberately Quick Edit only, not Bulk Edit: `inline_edit()` fires a
 * distinct `bulk_edit_custom_box` action for Bulk Edit's own template
 * (confirmed against core source), which this class never hooks, so
 * Bulk Edit's own row simply never shows this field — a deliberate scope
 * match to what was actually asked for, not an oversight; bulk-setting a
 * format across several posts at once would need its own "leave
 * unchanged" sentinel handling Quick Edit's single-post save doesn't.
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
	 * rather than assumed. `standard` maps to `dashicons-format-standard` —
	 * reported directly as visibly missing once every other format's own
	 * icon made its absence obvious; confirmed against core's own CSS that
	 * this class genuinely exists (it's the same glyph as
	 * `dashicons-admin-post`, core's own icon for "an ordinary written
	 * post") rather than assumed missing, the same verification-first
	 * posture as the `link` pluralization above.
	 *
	 * @var array<string, string>
	 */
	private const FORMAT_DASHICONS = array(
		'standard' => 'dashicons-format-standard',
		'aside'    => 'dashicons-format-aside',
		'audio'    => 'dashicons-format-audio',
		'chat'     => 'dashicons-format-chat',
		'gallery'  => 'dashicons-format-gallery',
		'image'    => 'dashicons-format-image',
		'link'     => 'dashicons-format-links',
		'quote'    => 'dashicons-format-quote',
		'status'   => 'dashicons-format-status',
		'video'    => 'dashicons-format-video',
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

		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_field' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_quick_edit_format' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_icon_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_edit_script' ) );
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
	 * what makes this implementation actually work. The output is always
	 * wrapped in a `data-format` attribute (empty string for a Standard
	 * post, never omitted) so `assets/admin-post-format.js` can read this
	 * row's current format when Quick Edit opens for it — the shared
	 * Quick Edit template row has no per-post data of its own to prefill
	 * from, see the class docblock's Quick Edit section.
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
		$format = false === $format ? '' : $format;

		// The `data-format` attribute stays the raw taxonomy value (empty
		// for Standard, matching Quick Edit's own `value=""` option) — only
		// the icon lookup below falls back to the literal 'standard' key.
		$icon_key = '' === $format ? 'standard' : $format;

		printf( '<span class="daymark-format-icon-cell" data-format="%s">', esc_attr( $format ) );

		if ( isset( self::FORMAT_DASHICONS[ $icon_key ] ) ) {
			printf(
				'<span class="dashicons %1$s daymark-format-icon" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span>',
				esc_attr( self::FORMAT_DASHICONS[ $icon_key ] ),
				esc_html( get_post_format_string( $icon_key ) )
			);
		}

		echo '</span>';
	}

	/**
	 * Render Quick Edit's own Format field.
	 *
	 * Fires once per non-core column while WordPress builds the *shared*
	 * hidden Quick Edit template row (inside an already-open
	 * `<fieldset class="inline-edit-col-right">` — confirmed directly
	 * against core source, no wrapper of our own needed), so this can't
	 * know which post is being edited; `assets/admin-post-format.js`
	 * prefills the select's value once Quick Edit actually opens for a
	 * specific row. Options are restricted to the current theme's own
	 * declared post formats (`get_theme_support( 'post-formats' )`,
	 * confirmed against `post_format_meta_box()`'s own accessor pattern) —
	 * the same list the classic editor's Format meta box offers — rather
	 * than every format this class knows a dashicon for, so Quick Edit
	 * never offers a format the current theme doesn't actually support.
	 *
	 * @param string $column_name Column key WordPress is building a field for.
	 * @param string $post_type   Post type of the screen being edited.
	 * @return void
	 */
	public function render_quick_edit_field( string $column_name, string $post_type ): void {
		if ( self::COLUMN !== $column_name || ! in_array( $post_type, self::supported_post_types(), true ) ) {
			return;
		}

		$formats = self::theme_supported_formats();

		if ( empty( $formats ) ) {
			return;
		}
		?>
		<label class="alignleft daymark-quick-edit-format">
			<span class="title"><?php esc_html_e( 'Format', 'daymark' ); ?></span>
			<select name="daymark_post_format">
				<option value=""><?php echo esc_html( get_post_format_string( 'standard' ) ); ?></option>
				<?php foreach ( $formats as $format ) : ?>
					<option value="<?php echo esc_attr( $format ); ?>"><?php echo esc_html( get_post_format_string( $format ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/**
	 * Save Quick Edit's Format field.
	 *
	 * Reuses Quick Edit's own existing `inlineeditnonce`/`_inline_edit`
	 * nonce rather than adding a second one — `wp_ajax_inline_save()`
	 * already verifies it before ever calling `edit_post()` (confirmed
	 * directly against core source), so by the time this fires the
	 * request is already known-genuine; re-checking here is defense in
	 * depth against this same `save_post` hook somehow firing from
	 * another path with a spoofed `action`/`daymark_post_format` pair.
	 * Gating on `$_POST['action'] === 'inline-save'` is what keeps this a
	 * no-op on every *other* `save_post` firing (a normal Publish/Update,
	 * an autosave, a REST create) — those never carry that action value.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_quick_edit_format( int $post_id ): void {
		if (
			! isset( $_POST['action'], $_POST['_inline_edit'], $_POST['daymark_post_format'] )
			|| 'inline-save' !== $_POST['action']
		) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		if ( ! $post_type || ! in_array( $post_type, self::supported_post_types(), true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$format = sanitize_key( wp_unslash( $_POST['daymark_post_format'] ) );

		set_post_format( $post_id, in_array( $format, self::theme_supported_formats(), true ) ? $format : '' );
	}

	/**
	 * The current theme's own declared post formats, restricted to ones
	 * this class actually knows a dashicon/label expectation for.
	 *
	 * @return string[]
	 */
	private static function theme_supported_formats(): array {
		$post_formats = get_theme_support( 'post-formats' );

		if ( empty( $post_formats[0] ) || ! is_array( $post_formats[0] ) ) {
			return array();
		}

		return array_values( array_intersect( $post_formats[0], array_keys( self::FORMAT_DASHICONS ) ) );
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
		if ( ! self::current_screen_matches() ) {
			return;
		}

		wp_add_inline_style(
			'common',
			'.fixed .column-' . self::COLUMN . ' { width: 2em; padding: 8px 0 8px 8px; text-align: center; }'
			. ' .daymark-format-icon { color: #787c82; vertical-align: text-bottom; }'
		);
	}

	/**
	 * Enqueue the Quick Edit prefill script — see the class docblock's
	 * Quick Edit section for what it does and why. Depends on core's own
	 * `inline-edit-post` handle (already enqueued on this exact screen
	 * regardless) purely for load-order: our script overrides
	 * `inlineEditPost.edit`, which must already exist.
	 *
	 * @return void
	 */
	public function enqueue_quick_edit_script(): void {
		if ( ! self::current_screen_matches() ) {
			return;
		}

		wp_enqueue_script(
			'daymark-admin-post-format',
			DAYMARK_PLUGIN_URL . 'assets/admin-post-format.js',
			array( 'jquery', 'inline-edit-post' ),
			DAYMARK_VERSION,
			true
		);
	}

	/**
	 * Whether the current wp-admin screen is a matching post type's own
	 * Edit Posts list table — the one screen both the inline style and
	 * the Quick Edit script are relevant to.
	 *
	 * @return bool
	 */
	private static function current_screen_matches(): bool {
		$screen = get_current_screen();

		return $screen && 'edit' === $screen->base && in_array( $screen->post_type, self::supported_post_types(), true );
	}
}
