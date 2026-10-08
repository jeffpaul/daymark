<?php
/**
 * Note length counter in the block editor.
 *
 * Loads assets/note-length-editor.js, which shows, for an Aside-format post
 * (a Note), how many characters are left before the 300-character length of
 * one Bluesky post, and whether the Note will be shared as a short note
 * (its text, no title) or a long note (its title and a link). See that file
 * for the rule and how it counts.
 *
 * It shows on every Aside post, whether or not a plugin that posts to
 * Bluesky is active. When ATmosphere is connected and set to publish
 * automatically, the help text names Bluesky's exact behavior; otherwise it
 * describes what sharing tools usually do.
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the block editor's Note length counter.
 */
class Daymark_Note_Length {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'daymark-note-length-editor';

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
	 * The script itself shows the counter only while the format is Aside.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || '' === (string) $screen->post_type || ! post_type_supports( $screen->post_type, 'post-formats' ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			DAYMARK_PLUGIN_URL . 'assets/note-length-editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-i18n', 'wp-a11y', 'wp-block-editor' ),
			DAYMARK_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'daymark' );

		wp_localize_script(
			self::HANDLE,
			'daymarkNoteLength',
			array(
				'limit'      => self::limit(),
				'atmosphere' => self::atmosphere_auto_publishes(),
			)
		);
	}

	/**
	 * Length of a short note, in characters.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * The number of characters the block editor's Note length counter
		 * counts down from.
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
