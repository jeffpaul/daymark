<?php
/**
 * Daymark bookmarklet: Reblog or Like the page you're reading, from anywhere
 * on the web.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bookmarklet is a `javascript:` link (see script()) that reads the
 * current page's URL, title, and author from the page itself and opens a
 * small popup at {app base}/bookmarklet on this site. That popup is an
 * ordinary same-origin page, so it runs with the reader's own WordPress
 * login and a normal WordPress nonce — nothing is ever sent from the other
 * site's page to this one except the three query args.
 *
 * The popup offers:
 *
 * - **Reblog**: a preview of the post (its oEmbed when it has one, else a
 *   linked title), then an editable "Your thoughts" field and Title. Publishing goes through
 *   Daymark_Publisher::publish() exactly like the in-app Reblog screen, so
 *   the Reblog Mark lands on this site with `_daymark_repost_of`, and the
 *   usual Webmention / ActivityPub Announce routes apply.
 * - **Like**: offered only when a Like can reach the page's site (the same
 *   rule as in the app; see Daymark_Like_Delivery). When the page is a post
 *   from a site the reader follows, the subscription-post Like route runs
 *   (including the Jetpack route); otherwise Daymark_Like_Delivery::publish_like().
 *
 * The popup sends `frame-ancestors 'none'`, so another site can't load it
 * in a frame and trick a click on Reblog or Like.
 */
class Daymark_Bookmarklet {

	/**
	 * Nonce action for the popup's forms.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'daymark_bookmarklet';

	/**
	 * Longest page title or author name kept from the query string.
	 *
	 * @var int
	 */
	private const MAX_TEXT = 200;

	/**
	 * The popup page's URL.
	 *
	 * @return string
	 */
	public static function popup_url(): string {
		return Daymark_Routes::app_url( 'bookmarklet' );
	}

	/**
	 * The bookmarklet itself: a `javascript:` URL to drag to the bookmarks
	 * bar. It prefers the page's canonical URL and Open Graph title, falls
	 * back to the address bar and document title, and opens the popup (or,
	 * if a popup blocker stops that, navigates the current tab to it).
	 *
	 * @return string
	 */
	public static function script(): string {
		$js = '(function(){'
			. 'var d=document,m=function(s,k){var e=d.querySelector(s);return e&&e[k]?String(e[k]):"";};'
			. 'var u=m("link[rel=canonical]","href")||location.href;'
			. 'var t=m("meta[property=\'og:title\']","content")||d.title||"";'
			. 'var a=m("meta[name=author]","content");'
			. 'var q="u="+encodeURIComponent(u)+"&t="+encodeURIComponent(t.slice(0,200))+"&a="+encodeURIComponent(a.slice(0,200));'
			. 'var h=' . wp_json_encode( self::popup_url(), JSON_UNESCAPED_SLASHES ) . '+"?"+q;'
			. 'var w=window.open(h,"daymark","width=520,height=720,resizable=yes,scrollbars=yes");'
			. 'if(!w){location.href=h;}else{w.focus();}'
			. '})();';

		return 'javascript:' . rawurlencode( $js );
	}

	/**
	 * Read and sanitize the page details the bookmarklet passed.
	 *
	 * @param array<string, mixed> $source $_GET or $_POST (unslashed).
	 * @return array{url: string, title: string, author: string, host: string}
	 */
	public static function read_target( array $source ): array {
		$url    = esc_url_raw( trim( (string) ( $source['u'] ?? '' ) ) );
		$scheme = '' !== $url ? strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$url = '';
		}

		$host   = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
		$title  = mb_substr( sanitize_text_field( (string) ( $source['t'] ?? '' ) ), 0, self::MAX_TEXT );
		$author = mb_substr( sanitize_text_field( (string) ( $source['a'] ?? '' ) ), 0, self::MAX_TEXT );

		return array(
			'url'    => $url,
			'title'  => '' !== $title ? $title : $host,
			'author' => $author,
			'host'   => $host,
		);
	}

	/**
	 * The default Reblog title for a page.
	 *
	 * @param array{title: string} $target A read_target() result.
	 * @return string
	 */
	public static function default_title( array $target ): string {
		/* translators: %s: title of the reblogged post */
		return sprintf( __( 'Reblog: %s', 'daymark' ), $target['title'] );
	}

	/**
	 * The cached subscription post for a URL, when the reader follows the
	 * site it's from — so a Like from the bookmarklet and a Like from the
	 * Timeline are the same Like.
	 *
	 * @param string $url Page URL.
	 * @return int `daymark_sub_post` ID, or 0.
	 */
	public static function subscription_post_id( string $url ): int {
		if ( '' === $url ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => 'permalink', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match lookup, personal-site scale.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match is the point.
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $found ) ? absint( $found[0] ) : 0;
	}

	/**
	 * What the popup shows for a page: whether the reader already reblogged
	 * or liked it, whether a Like can reach it, and the embed preview.
	 *
	 * Looking things up makes outbound requests (the page's own signals and
	 * its oEmbed), so with `$resolve` false only cached answers are used;
	 * handle() passes false when the reader is over their rate limit.
	 *
	 * @param array{url: string} $target  A read_target() result.
	 * @param bool               $resolve Whether to resolve what isn't cached.
	 * @return array{reblog_id: int, liked: bool, like_available: bool, embed: array<string, string>}
	 */
	public static function state( array $target, bool $resolve = true ): array {
		$url    = $target['url'];
		$sub_id = self::subscription_post_id( $url );

		$liked = Daymark_Like_Delivery::own_mark_id( '_daymark_like_of', $url ) > 0
			|| ( $sub_id > 0 && Daymark_Jetpack_Engagement::is_liked( get_current_user_id(), $sub_id ) );

		if ( $resolve ) {
			$availability = $sub_id > 0 ? Daymark_Like_Delivery::resolve( $sub_id ) : Daymark_Like_Delivery::resolve_url( $url );
			$available    = $sub_id > 0 ? $availability['available'] : self::url_like_deliverable( $availability );
			$embed        = Daymark_Subscription_Oembed::resolve( $url );
		} else {
			$available = (bool) Daymark_Like_Delivery::cached_availability( $url );
			$embed     = (array) Daymark_Subscription_Oembed::cached( $url );
		}

		return array(
			'reblog_id'      => self::published_reblog_id( $url ),
			'liked'          => $liked,
			'like_available' => $liked || $available,
			'embed'          => $embed,
		);
	}

	/**
	 * Whether every lookup state() would make is already cached, so opening
	 * the popup costs no outbound request (and no rate-limit charge).
	 *
	 * @param string $url Page URL.
	 * @return bool
	 */
	public static function is_cached( string $url ): bool {
		return null !== Daymark_Like_Delivery::cached_availability( $url )
			&& null !== Daymark_Subscription_Oembed::cached( $url );
	}

	/**
	 * Publish a Reblog Mark for the page, the same Reblog the in-app Reblog
	 * screen publishes: it leads with an embed of the post (a plain link
	 * where WordPress can't embed it), then the reader's comment, under
	 * the reader's title.
	 *
	 * @param array{url: string, title: string, author: string} $target  A read_target() result.
	 * @param string                                            $title   The Reblog Mark's title; '' for the default.
	 * @param string                                            $comment The reader's own words, after the embed.
	 * @return int|WP_Error Reblog Mark post ID.
	 */
	public static function reblog( array $target, string $title, string $comment ) {
		if ( '' === $target['url'] ) {
			return new WP_Error( 'daymark_bookmarklet_no_url', __( 'Daymark could not read the address of this page.', 'daymark' ), array( 'status' => 400 ) );
		}

		$title = sanitize_text_field( $title );

		return Daymark_Plugin::instance()->publisher->publish(
			array(
				'title'          => '' !== $title ? $title : self::default_title( $target ),
				'caption'        => $comment,
				'primary_type'   => 'note',
				'status'         => 'publish',
				'ai_assist_used' => false,
				'repost_of'      => $target['url'],
				'reblog_author'  => $target['author'],
			)
		);
	}

	/**
	 * Like the page.
	 *
	 * @param array{url: string, title: string} $target A read_target() result.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function like( array $target ) {
		$sub_id = self::subscription_post_id( $target['url'] );

		if ( $sub_id > 0 ) {
			return self::dispatch( 'POST', '/daymark/v1/subscription-posts/' . $sub_id . '/like' );
		}

		if ( '' === $target['url'] ) {
			return new WP_Error( 'daymark_bookmarklet_no_url', __( 'Daymark could not read the address of this page.', 'daymark' ), array( 'status' => 400 ) );
		}

		$availability = Daymark_Like_Delivery::resolve_url( $target['url'] );

		// The Jetpack route records a like against a cached subscription
		// post, so it only applies above. Here the Like needs an
		// ActivityPub, Webmention, or Bridgy Fed route.
		$availability['jetpack'] = false;

		return Daymark_Like_Delivery::publish_like( $target['url'], $target['title'], $availability );
	}

	/**
	 * Undo the reader's Like on the page.
	 *
	 * @param array{url: string} $target A read_target() result.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function unlike( array $target ) {
		$sub_id = self::subscription_post_id( $target['url'] );

		if ( $sub_id > 0 ) {
			return self::dispatch( 'DELETE', '/daymark/v1/subscription-posts/' . $sub_id . '/like' );
		}

		$existing = Daymark_Like_Delivery::own_mark_id( '_daymark_like_of', $target['url'] );

		// Trashing also queues an ActivityPub Undo when the Mark carried a
		// queued Like (Daymark_ActivityPub_Engagement::maybe_undo()).
		if ( $existing > 0 ) {
			wp_trash_post( $existing );
		}

		return array( 'liked' => false );
	}

	/**
	 * Handle a request to the popup route. Always exits.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			// A GET: auth_redirect() brings the reader back here, query and
			// all, after they log in.
			auth_redirect();
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to publish Marks.', 'daymark' ),
				esc_html__( 'Daymark', 'daymark' ),
				array( 'response' => 403 )
			);
		}

		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'X-Frame-Options: DENY' );
			header( "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; frame-src 'self' https:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'" );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';

		if ( 'post' === $method ) {
			$this->handle_post();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render of the page details the bookmarklet passed.
		$target = self::read_target( wp_unslash( $_GET ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result flag set by handle_post()'s redirect.
		$done = isset( $_GET['daymark_done'] ) ? sanitize_key( wp_unslash( $_GET['daymark_done'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, the Mark is re-read below.
		$mark_id = isset( $_GET['daymark_mark'] ) ? absint( $_GET['daymark_mark'] ) : 0;
		$error   = (string) get_transient( self::error_key() );

		if ( '' !== $error ) {
			delete_transient( self::error_key() );
		}

		$state = null;

		if ( '' !== $target['url'] ) {
			$resolve = self::is_cached( $target['url'] )
				|| ! is_wp_error( ( new Daymark_Rate_Limiter() )->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_OPEN ) );
			$state   = self::state( $target, $resolve );
		}

		$mark = $mark_id > 0 ? get_post( $mark_id ) : null;

		if ( $mark instanceof WP_Post && get_current_user_id() !== (int) $mark->post_author ) {
			$mark = null;
		}

		$daymark_bookmarklet = array(
			'target' => $target,
			'state'  => $state,
			'done'   => $done,
			'mark'   => $mark,
			'error'  => $error,
			'script' => self::script(),
		);

		require DAYMARK_PLUGIN_DIR . 'templates/bookmarklet.php';
		exit;
	}

	/**
	 * Handle a Reblog, Like, or Unlike form post, then redirect back to the
	 * popup (POST-redirect-GET). Always exits.
	 *
	 * @return void
	 */
	private function handle_post(): void {
		check_admin_referer( self::NONCE_ACTION );

		$target = self::read_target( wp_unslash( $_POST ) );
		$action = isset( $_POST['daymark_action'] ) ? sanitize_key( wp_unslash( $_POST['daymark_action'] ) ) : '';
		$back   = add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'u' => $target['url'],
					't' => $target['title'],
					'a' => $target['author'],
				)
			),
			self::popup_url()
		);

		if ( 'reblog' === $action ) {
			$result = ( new Daymark_Rate_Limiter() )->attempt( Daymark_Rate_Limiter::ACTION_PUBLISH );

			if ( ! is_wp_error( $result ) ) {
				$result = self::reblog(
					$target,
					(string) wp_unslash( $_POST['daymark_title'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in reblog().
					sanitize_textarea_field( wp_unslash( $_POST['daymark_comment'] ?? '' ) )
				);
			}

			$done = 'reblogged';
			$mark = is_wp_error( $result ) ? 0 : (int) $result;
		} elseif ( 'like' === $action || 'unlike' === $action ) {
			$result = ( new Daymark_Rate_Limiter() )->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_LIKE );

			if ( ! is_wp_error( $result ) ) {
				$result = 'like' === $action ? self::like( $target ) : self::unlike( $target );
			}

			$done = 'like' === $action ? 'liked' : 'unliked';
			$mark = 0;
		} else {
			$result = new WP_Error( 'daymark_bookmarklet_action', __( 'Invalid request.', 'daymark' ) );
			$done   = '';
			$mark   = 0;
		}

		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			$done = '';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'daymark_done' => $done,
					'daymark_mark' => $mark,
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Whether a resolve_url() result has a route that works without a
	 * cached subscription post (anything but Jetpack).
	 *
	 * @param array<string, mixed> $availability A resolve_url() result.
	 * @return bool
	 */
	private static function url_like_deliverable( array $availability ): bool {
		return ! empty( $availability['activitypub'] ) || ! empty( $availability['webmention'] ) || ! empty( $availability['bridgy_fed'] );
	}

	/**
	 * The reader's published Reblog Mark for a URL, if any.
	 *
	 * @param string $url Page URL.
	 * @return int Mark post ID, or 0.
	 */
	private static function published_reblog_id( string $url ): int {
		$id = Daymark_Like_Delivery::own_mark_id( '_daymark_repost_of', $url );

		return $id > 0 && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Run a Daymark REST route as the current user, for the subscription
	 * post Like routes, so the bookmarklet and the Timeline share one path.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function dispatch( string $method, string $route ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		return (array) $response->get_data();
	}

	/**
	 * Per-user transient key carrying an error message across the
	 * redirect, so the message text never rides in the URL.
	 *
	 * @return string
	 */
	private static function error_key(): string {
		return 'daymark_bookmarklet_error_' . get_current_user_id();
	}
}
