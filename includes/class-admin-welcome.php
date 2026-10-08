<?php
/**
 * The welcome notice shown once after Daymark is first activated.
 *
 * Activation used to lead nowhere: nothing told a new site owner where the
 * app lives or what to do next. The first activation on a site sets the
 * `daymark_welcome_pending` option, and this notice shows three next steps
 * to anyone who can manage the site until one of them dismisses it.
 * Reactivating later doesn't bring it back.
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post-activation welcome notice.
 */
class Daymark_Admin_Welcome {

	/**
	 * Option set on first activation while the notice is waiting.
	 */
	public const OPTION = 'daymark_welcome_pending';

	/**
	 * Query var on the dismiss link.
	 */
	private const DISMISS_QUERY_VAR = 'daymark_dismiss_welcome';

	/**
	 * Capability needed to see the notice: the same one Settings -> Daymark
	 * needs, since two of the steps link there.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
	}

	/**
	 * Called from activation: queue the notice on a site's first-ever
	 * activation only.
	 *
	 * @param bool $first_activation Whether Daymark had never been activated on this site.
	 * @return void
	 */
	public static function queue( bool $first_activation ): void {
		if ( $first_activation ) {
			update_option( self::OPTION, '1', false );
		}
	}

	/**
	 * Whether the notice is waiting to be shown.
	 *
	 * @return bool
	 */
	public static function is_pending(): bool {
		return '1' === (string) get_option( self::OPTION, '' );
	}

	/**
	 * URL that dismisses the notice and returns to the current screen.
	 *
	 * @return string
	 */
	private static function dismiss_url(): string {
		return wp_nonce_url( add_query_arg( self::DISMISS_QUERY_VAR, '1' ), 'daymark_dismiss_welcome' );
	}

	/**
	 * Handle the dismiss link: forget the notice, then redirect without the
	 * query args so a reload doesn't repeat it.
	 *
	 * @return void
	 */
	public function maybe_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks whether to verify the nonce below.
		if ( ! isset( $_GET[ self::DISMISS_QUERY_VAR ] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'daymark_dismiss_welcome' );
		self::dismiss();

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_QUERY_VAR, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Forget the notice.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		delete_option( self::OPTION );
	}

	/**
	 * A QR code of the app's address, so it can be opened on a phone by
	 * pointing the camera at the screen. Shared by this notice and
	 * Settings -> Daymark -> General.
	 *
	 * @param int $size Width and height in pixels.
	 * @return string SVG markup, or '' if the address is too long to encode.
	 */
	public static function app_qr_code( int $size = 128 ): string {
		return Daymark_QR_Code::svg(
			Daymark_Routes::app_url(),
			$size,
			__( 'QR code for the Daymark app address', 'daymark' )
		);
	}

	/**
	 * Render the notice: where the app is, and two things worth doing first.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! self::is_pending() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$app_url = Daymark_Routes::app_url();
		$qr_code = self::app_qr_code();
		?>
		<div class="notice notice-info daymark-welcome" style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:0 2em;">
			<div style="flex:1 1 24em;">
			<p><strong><?php esc_html_e( 'Daymark is ready. Here is how to start:', 'daymark' ); ?></strong></p>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: the Daymark app's address, as a link */
						esc_html__( 'Open Daymark on your phone at %s, or scan the code, and sign in. Then add it to your home screen, so it opens like an app.', 'daymark' ),
						'<a href="' . esc_url( $app_url ) . '">' . esc_html( $app_url ) . '</a>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: link to the Subscriptions tab */
						esc_html__( '%s you like to read. Their new posts show up in your Daymark Timeline.', 'daymark' ),
						'<a href="' . esc_url( Daymark_Admin_Subscriptions::tab_url( 'subscriptions' ) ) . '">' . esc_html__( 'Follow a few sites', 'daymark' ) . '</a>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: link to the Connectors tab */
						esc_html__( '%s to let your Marks reach the fediverse and Bluesky, and bring replies back to you.', 'daymark' ),
						'<a href="' . esc_url( Daymark_Admin_Subscriptions::tab_url( 'connectors' ) ) . '">' . esc_html__( 'Review Connectors', 'daymark' ) . '</a>'
					);
					?>
				</li>
			</ol>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $app_url ); ?>"><?php esc_html_e( 'Open Daymark', 'daymark' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::dismiss_url() ); ?>"><?php esc_html_e( 'Dismiss', 'daymark' ); ?></a>
			</p>
			</div>
			<?php if ( '' !== $qr_code ) : ?>
				<figure style="margin:0.75em 0;text-align:center;">
					<?php echo $qr_code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by Daymark_QR_Code from numbers only; its one text attribute is escaped there. ?>
					<figcaption class="description" style="max-width:128px;"><?php esc_html_e( 'Scan with your phone\'s camera to open Daymark.', 'daymark' ); ?></figcaption>
				</figure>
			<?php endif; ?>
		</div>
		<?php
	}
}
