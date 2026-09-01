<?php
/**
 * Web Share Target handler: lets Daymark appear in the OS share sheet.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the POST the OS delivers when a user shares a photo, video, audio
 * clip, or link "to Daymark" (declared via the PWA manifest's
 * `share_target` — see Daymark_Routes::build_manifest()). Always creates a
 * draft: syndication only ever runs when a Mark actually goes live, so a
 * share landing as a draft can't accidentally cross-post anything before
 * the author has even seen it.
 */
class Daymark_Share_Target {

	/**
	 * Handle a share-target request.
	 *
	 * A GET (someone navigating to the action URL directly, not a real
	 * share) and an empty share (no file, no usable text) both just
	 * redirect to the app rather than creating anything. Always exits.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_safe_redirect( Daymark_Routes::app_url() );
			exit;
		}

		if ( ! is_user_logged_in() ) {
			// Not auth_redirect(): that preserves the current URL to return
			// to after login, but the redirect-after-login is always a GET,
			// which would drop the shared file entirely. A share arriving
			// while logged out can't be recovered either way, so this is
			// just the clearest way to say so.
			wp_die(
				sprintf(
					/* translators: %s: log in link */
					esc_html__( 'You need to be logged in to share to Daymark. %s and try sharing again.', 'daymark' ),
					'<a href="' . esc_url( wp_login_url( Daymark_Routes::app_url() ) ) . '">' . esc_html__( 'Log in', 'daymark' ) . '</a>'
				),
				esc_html__( 'Log in required', 'daymark' ),
				array( 'response' => 401 )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to publish Marks.', 'daymark' ),
				esc_html__( 'Daymark', 'daymark' ),
				array( 'response' => 403 )
			);
		}

		// The OS share sheet has no way to carry a REST nonce, so this leans
		// on two things instead: WordPress's auth cookie is SameSite=Lax by
		// browser default, which is never attached to a cross-site POST, so
		// a forged submission from another site never reaches the
		// capability check above in the first place; and, as defense in
		// depth, this Sec-Fetch-Site check rejects the two values that mean
		// "a different site's page originated this request"
		// (`cross-site`/`same-site`) — the actual CSRF threat model. A
		// genuine OS-delivered share isn't attributed to any originating
		// page at all, so browsers that send this header at all report
		// either `same-origin` or `none` for it, both accepted; the header
		// is simply absent on older browsers, which also passes through to
		// the checks above rather than being rejected outright.
		$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( ! in_array( $fetch_site, array( '', 'same-origin', 'none' ), true ) ) {
			wp_die( esc_html__( 'Invalid request.', 'daymark' ), '', array( 'response' => 400 ) );
		}

		$rate_limiter = new Daymark_Rate_Limiter();
		$allowed      = $rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_PUBLISH );

		if ( is_wp_error( $allowed ) ) {
			wp_die( esc_html( $allowed->get_error_message() ), '', array( 'response' => 429 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the OS share sheet cannot supply a nonce; see the Sec-Fetch-Site check above.
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$text  = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		$url   = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$caption = self::compose_caption( $title, $text, $url );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same as $_POST above; Daymark_Publisher::publish() validates every file's real content/MIME/size before anything is sideloaded.
		$files = isset( $_FILES['media'] ) && is_array( $_FILES['media'] ) ? array( 'media' => $_FILES['media'] ) : array();

		if ( '' === $caption && empty( $files ) ) {
			wp_safe_redirect( Daymark_Routes::app_url() );
			exit;
		}

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption' => $caption,
				'status'  => 'draft',
			),
			$files
		);

		if ( is_wp_error( $post_id ) ) {
			wp_die(
				esc_html( $post_id->get_error_message() ),
				'',
				array(
					'response'  => (int) ( $post_id->get_error_data()['status'] ?? 500 ),
					'back_link' => true,
				)
			);
		}

		// #create (not a query param — fragments never reach the server, but
		// the browser still lands on it after following this redirect) so
		// the app boots straight into the composer, not Home.
		wp_safe_redirect( Daymark_Routes::app_url() . '?daymark_draft=' . absint( $post_id ) . '#create' );
		exit;
	}

	/**
	 * Compose a caption from the share's title/text/url fields. Shared apps
	 * rarely fill all three, and some duplicate the same string into more
	 * than one — a plain join with exact duplicates dropped reads naturally
	 * without guessing at structure. The result stays fully editable before
	 * the draft ever goes live.
	 *
	 * @param string $title Shared title, already sanitized.
	 * @param string $text  Shared text, already sanitized.
	 * @param string $url   Shared URL, already sanitized.
	 * @return string
	 */
	public static function compose_caption( string $title, string $text, string $url ): string {
		$parts = array_unique( array_filter( array( $title, $text, $url ), static fn( $part ) => '' !== trim( $part ) ) );

		return implode( "\n\n", $parts );
	}
}
