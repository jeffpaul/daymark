<?php
/**
 * TEMPORARY diagnostic-only admin page, added while chasing a report on
 * issue #402/PR #402 (a Featured Content Vimeo/YouTube oEmbed lookup
 * resolving to "no preview" only through Daymark's own code path, reported
 * from a WordPress Playground preview — see
 * Daymark_Subscription_Oembed::log_debug()'s own WP_DEBUG-gated logging,
 * added for the same reason). Playground has no file manager/SSH to read
 * `wp-content/debug.log` with directly, so this exposes its tail as a
 * plain wp-admin Tools page instead — the one thing anyone testing a
 * Playground preview already has: a logged-in browser session against the
 * running site.
 *
 * Registers nothing at all unless WP_DEBUG is on, and even then only a
 * capability-gated read of a log file — no write path, no user input
 * accepted. Remove this whole file (and its require_once in daymark.php,
 * and the ->register() call in class-plugin.php) once root-caused; it is
 * not meant to ship in a release.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools -> Daymark Debug Log: a read-only tail of wp-content/debug.log.
 */
class Daymark_Debug_Log_Viewer {

	/**
	 * How many of the log file's final lines to display.
	 *
	 * @var int
	 */
	private const TAIL_LINES = 400;

	/**
	 * Register the admin page — only when WP_DEBUG is on, so this never
	 * appears at all on a site not already configured for debug logging.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * Add the Tools submenu page.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_management_page(
			__( 'Daymark Debug Log', 'daymark' ),
			__( 'Daymark Debug Log', 'daymark' ),
			'manage_options',
			'daymark-debug-log',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the page: the log's last TAIL_LINES lines, most recent last
	 * (matching the file's own natural order), plainly escaped.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$log_path = WP_CONTENT_DIR . '/debug.log';

		echo '<div class="wrap"><h1>' . esc_html__( 'Daymark Debug Log', 'daymark' ) . '</h1>';
		echo '<p>' . esc_html__( 'Temporary diagnostic page — the last lines of wp-content/debug.log. Reload this page after retesting to see fresh output.', 'daymark' ) . '</p>';

		if ( ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			echo '<p><em>' . esc_html__( 'No debug.log file found yet at wp-content/debug.log.', 'daymark' ) . '</em></p></div>';

			return;
		}

		$contents = (string) file_get_contents( $log_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local log file for a WP_DEBUG-gated, manage_options-only diagnostic page; not a remote URL, and no WP_Filesystem API call is guaranteed to work in every environment this needs to run in (e.g. WordPress Playground).
		$lines    = explode( "\n", $contents );
		$tail     = array_slice( $lines, -self::TAIL_LINES );

		echo '<textarea readonly rows="30" style="width:100%;font-family:monospace;font-size:12px;">' . esc_textarea( implode( "\n", $tail ) ) . '</textarea>';
		echo '</div>';
	}
}
