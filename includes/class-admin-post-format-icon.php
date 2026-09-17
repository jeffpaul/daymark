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
 * Prepends a small dashicon indicating a post's format immediately before
 * its title on every wp-admin post list screen for a post type Daymark
 * integrates with that also supports WordPress core's own post-formats
 * feature.
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
 * Deliberately no new "All formats" filter dropdown, and deliberately no
 * dedicated column: WordPress core already renders a filter dropdown on
 * this same screen automatically (`WP_Posts_List_Table::
 * formats_dropdown()`, confirmed directly against core source) once at
 * least one post uses a non-Standard format — building a second one here
 * would be exactly the duplicate UI a plain, purely visual icon avoids.
 * This class is a pure identifier, not a second filtering mechanism.
 */
class Daymark_Admin_Post_Format_Icon {

	/**
	 * Post types Daymark integrates with — see the class docblock above for
	 * why `daymark_sub_post` is named here even though it never currently
	 * qualifies (no post-formats support, no list table to add an icon to).
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
	 * icon at all, matching the plain "no badge" convention this same
	 * feature's earlier column-based design also used.
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
	 * @return void
	 */
	public function register(): void {
		// Priority 20: WP_Posts_List_Table::display_rows() itself hooks
		// `the_title` to `esc_html` at the default priority (10) every time
		// it renders the list — confirmed directly against core source, with
		// no matching remove_filter. Running at a later priority (a higher
		// number, since WordPress runs filters in ascending priority order)
		// is required so this icon's own HTML is appended *after* that
		// escaping pass, not escaped into literal, visible text alongside
		// the title itself.
		add_filter( 'the_title', array( $this, 'add_format_icon' ), 20, 2 );
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
	 * Prepend a small format icon to a post's title on the matching
	 * wp-admin list table screen.
	 *
	 * Hooked to the generic `the_title` filter — not a `manage_..._columns`
	 * pattern, since core owns rendering of the Title column itself — and
	 * scoped narrowly via `get_current_screen()`, the same "reliably set on
	 * the intended screen, reliably absent everywhere else" signal this
	 * feature's earlier column-based design already relied on, rather than
	 * `is_admin()` (also true for e.g. admin-ajax). Safe from duplicate
	 * rendering: `WP_Posts_List_Table::column_title()` computes a row's
	 * title exactly once (`_draft_or_post_title()`) and reuses that same
	 * value for every row action's own aria-label — confirmed directly
	 * against core source — so this filter never fires more than once per
	 * row. Quick Edit's own title input is pre-filled from the raw
	 * `$post->post_title` database field via JS, not `get_the_title()`, so
	 * it never sees this icon's markup either — also confirmed directly
	 * against core source.
	 *
	 * @param string $title   The post's title, already run through core's
	 *                        own `esc_html` pass (see register()'s own
	 *                        priority comment).
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function add_format_icon( string $title, int $post_id ): string {
		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base ) {
			return $title;
		}

		if (
			! in_array( $screen->post_type, self::supported_post_types(), true )
			|| get_post_type( $post_id ) !== $screen->post_type
		) {
			return $title;
		}

		$format = get_post_format( $post_id );

		if ( false === $format || ! isset( self::FORMAT_DASHICONS[ $format ] ) ) {
			return $title;
		}

		return sprintf(
			'<span class="dashicons %1$s daymark-format-icon" aria-hidden="true"></span><span class="screen-reader-text">%2$s </span>%3$s',
			esc_attr( self::FORMAT_DASHICONS[ $format ] ),
			esc_html( get_post_format_string( $format ) ),
			$title
		);
	}

	/**
	 * Size and color the icon — a small inline style on core's own always-
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
			'.daymark-format-icon { margin-right: 4px; color: #787c82; vertical-align: text-bottom; }'
		);
	}
}
