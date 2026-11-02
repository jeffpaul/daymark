<?php
/**
 * Note length counter, in the block editor and the app's composer.
 *
 * Loads assets/note-length-editor.js, which shows, for an Aside-format post
 * (a Note) or a Standard post with no title, how many characters are left
 * before the 300-character length of one Bluesky post, and whether the post
 * will be shared as a short post (its text, no title) or a long post (a link
 * card to it). The app's composer shows the same count under a Note's
 * caption. Both count with assets/note-length.js (registered here as
 * SHARED_HANDLE), which describes the rule and how it counts.
 *
 * It shows on those posts whether or not a plugin that posts to
 * Bluesky is active. When ATmosphere is connected and set to publish
 * automatically, the wording names Bluesky's exact behavior; otherwise it
 * describes what sharing tools usually do.
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the block editor's Note length counter, and registers the
 * counting script it shares with the app.
 */
class Daymark_Note_Length {

	/**
	 * Script handle of the block editor's counter.
	 */
	public const HANDLE = 'daymark-note-length-editor';

	/**
	 * Script handle of the counting script (assets/note-length.js), shared
	 * by the block editor's counter and the app's composer.
	 */
	public const SHARED_HANDLE = 'daymark-note-length';

	/**
	 * Default length of a short note: one Bluesky post, in characters.
	 */
	public const DEFAULT_LIMIT = 300;

	/**
	 * Hook up. Called from Daymark_Plugin::on_init().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Load the counter in the block editor for a post type with post formats.
	 * The script itself shows the counter only for an Aside post or an
	 * untitled Standard post.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || '' === (string) $screen->post_type || ! post_type_supports( $screen->post_type, 'post-formats' ) ) {
			return;
		}

		self::register_shared_script();

		wp_enqueue_script(
			self::HANDLE,
			DAYMARK_PLUGIN_URL . 'assets/note-length-editor.js',
			array( self::SHARED_HANDLE, 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-i18n', 'wp-a11y', 'wp-block-editor' ),
			DAYMARK_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'daymark' );

		wp_localize_script( self::HANDLE, 'daymarkNoteLength', self::app_config() );
	}

	/**
	 * Register the shared counting script, once. It has no dependencies, no
	 * strings, and no inline script, so the offline app shell (whose CSP
	 * allows no inline script) can print it as one plain <script src>.
	 *
	 * @return void
	 */
	public static function register_shared_script(): void {
		if ( wp_script_is( self::SHARED_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script(
			self::SHARED_HANDLE,
			DAYMARK_PLUGIN_URL . 'assets/note-length.js',
			array(),
			DAYMARK_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * What both counters need to know: the limit, and whether ATmosphere
	 * will post a Note to Bluesky. Passed to the block editor as
	 * window.daymarkNoteLength and to the app as its noteLength config.
	 *
	 * @return array{limit: int, atmosphere: bool}
	 */
	public static function app_config(): array {
		return array(
			'limit'      => self::limit(),
			'atmosphere' => self::atmosphere_auto_publishes(),
		);
	}

	/**
	 * Length of a short note, in characters.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * The number of characters the Note length counter counts down
		 * from, in the block editor and the app's composer.
		 *
		 * @since 0.20.0
		 *
		 * @param int $limit Default 300, the length of one Bluesky post.
		 */
		return max( 1, (int) apply_filters( 'daymark_note_short_form_limit', self::DEFAULT_LIMIT ) );
	}

	/**
	 * Whether ATmosphere will post this site's new posts to Bluesky: it is
	 * connected and set to publish automatically.
	 *
	 * @return bool
	 */
	private static function atmosphere_auto_publishes(): bool {
		return function_exists( 'Atmosphere\\is_connected' )
			&& function_exists( 'Atmosphere\\is_auto_publish_enabled' )
			&& \Atmosphere\is_connected()
			&& \Atmosphere\is_auto_publish_enabled();
	}
}
