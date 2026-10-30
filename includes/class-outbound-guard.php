<?php
/**
 * Outbound request guard: apply Daymark_Subscription_Url_Guard to every
 * request Daymark makes, first URL and every redirect hop (issue #438).
 *
 * Daymark_Subscription_Url_Guard::check() vets a URL a call site already has
 * in hand. Two kinds of request never pass through it: the redirect hops an
 * HTTP client follows on its own (a trusted site can answer with
 * `302 Location: http://169.254.169.254/…`), and the first request to a URL
 * that a remote page hands a library, such as an oEmbed provider endpoint
 * found by discovery or a feed found by SimplePie autodiscovery.
 *
 * WordPress core narrows this for wp_safe_remote_*() by re-validating each
 * hop with wp_http_validate_url(), but only for the address ranges core knows
 * about, and only from 7.0.3: WordPress 7.0.0 through 7.0.2 (which this
 * plugin's "Requires at least: 7.0" still permits) accept link-local
 * `169.254/16` (cloud instance metadata), CGNAT `100.64/10`, and `240/4`
 * as redirect targets. Running the plugin's own, stricter check on each hop
 * gives one behavior on every supported WordPress version.
 *
 * Every Daymark outbound request goes through get()/post()/run() here.
 * tests/test-outbound-http-safety.php fails if a new call site bypasses it.
 * get() and post() also tell a refused redirect from a request that got no
 * answer, which WordPress reports the same way (is_temporary_failure()).
 *
 * @package Daymark
 */

/**
 * Wraps Daymark's outbound HTTP calls with per-redirect-hop URL validation.
 */
final class Daymark_Outbound_Guard {

	/**
	 * The Requests library hook WordPress bridges to a WordPress action of
	 * the same name, fired before each redirect is followed.
	 *
	 * @var string
	 */
	private const HOOK = 'requests-requests.before_redirect';

	/**
	 * The Requests library hook, bridged the same way, fired for every
	 * response before Requests decides whether to follow a redirect.
	 *
	 * @var string
	 */
	private const CHECK_HOOK = 'requests-requests.before_redirect_check';

	/**
	 * Core's filter that can answer a request before it is sent; a WP_Error
	 * returned from it is the request's result.
	 *
	 * @var string
	 */
	private const PRE_REQUEST_HOOK = 'pre_http_request';

	/**
	 * How many run() calls are currently in flight, so a nested call does not
	 * unhook the handler out from under the outer one.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Whether a redirect was refused in the current run(): by this class,
	 * or because the chain ran longer than the request allows.
	 *
	 * @var bool
	 */
	private static bool $redirect_refused = false;

	/**
	 * The redirect the current run() is about to follow, until this class's
	 * own check passes it. Still set after a failed request means
	 * WordPress's own check refused it first, since that runs earlier and
	 * stops the request before this class's check is reached.
	 *
	 * @var string|null
	 */
	private static ?string $pending_redirect = null;

	/**
	 * A wp_safe_remote_get() whose redirect hops are also validated.
	 *
	 * A request that failed because a redirect was refused comes back as
	 * `daymark_redirect_refused`, not `http_request_failed`: see
	 * is_temporary_failure().
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args wp_safe_remote_get() arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( string $url, array $args = array() ) {
		return self::run(
			static function () use ( $url, $args ) {
				return self::name_refusal( wp_safe_remote_get( $url, $args ) );
			}
		);
	}

	/**
	 * A wp_safe_remote_post() whose redirect hops are also validated.
	 *
	 * Names a refused redirect the same way get() does.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args wp_safe_remote_post() arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function post( string $url, array $args = array() ) {
		return self::run(
			static function () use ( $url, $args ) {
				return self::name_refusal( wp_safe_remote_post( $url, $args ) );
			}
		);
	}

	/**
	 * Whether a failed get() or post() might work if tried again soon.
	 *
	 * WordPress reports a request that went out and got no answer (a
	 * timeout, a refused connection, a failed DNS lookup) as
	 * `http_request_failed`, and only that can change on a retry. A request
	 * refused before it went out (by this site's settings or Daymark's
	 * address check), or on a redirect (get() and post() name that
	 * `daymark_redirect_refused`), fails the same way every time.
	 *
	 * @param WP_Error $error A failed get() or post() result.
	 * @return bool
	 */
	public static function is_temporary_failure( WP_Error $error ): bool {
		return 'http_request_failed' === $error->get_error_code();
	}

	/**
	 * Run any callable that makes HTTP requests (fetch_feed(),
	 * wp_oembed_get(), a raw WP_Http call) with redirect-hop validation on.
	 *
	 * The handler is attached only while the callable runs, so it never
	 * touches a request made by another plugin. Attach/detach is the same
	 * scoped add-before, remove-after idiom this plugin already uses for its
	 * `http_request_args` size-limit filters.
	 *
	 * @param callable $request Makes the request(s) and returns their result.
	 * @return mixed Whatever $request returned.
	 */
	public static function run( callable $request ) {
		if ( 0 === self::$depth ) {
			add_action( self::HOOK, array( __CLASS__, 'validate_redirect' ) );
			add_action( self::CHECK_HOOK, array( __CLASS__, 'note_redirect' ), 10, 4 );
			// Last in line, so a test's or another plugin's canned response
			// still wins and only a request that would really go out is
			// checked (and so is never resolved through DNS needlessly).
			add_filter( self::PRE_REQUEST_HOOK, array( __CLASS__, 'validate_first_request' ), PHP_INT_MAX, 3 );
		}

		// A nested run() reports on its own requests, then hands the outer
		// one's state back.
		$outer_refused          = self::$redirect_refused;
		$outer_pending          = self::$pending_redirect;
		self::$redirect_refused = false;
		self::$pending_redirect = null;

		++self::$depth;

		try {
			return $request();
		} finally {
			--self::$depth;

			self::$redirect_refused = $outer_refused;
			self::$pending_redirect = $outer_pending;

			if ( 0 === self::$depth ) {
				remove_action( self::HOOK, array( __CLASS__, 'validate_redirect' ) );
				remove_action( self::CHECK_HOOK, array( __CLASS__, 'note_redirect' ), 10 );
				remove_filter( self::PRE_REQUEST_HOOK, array( __CLASS__, 'validate_first_request' ), PHP_INT_MAX );
			}
		}
	}

	/**
	 * Refuse the first request to a URL that fails
	 * Daymark_Subscription_Url_Guard, whoever chose that URL.
	 *
	 * Covers a URL a call site already vetted (a cheap second look), and,
	 * more to the point, one it never saw: an oEmbed provider endpoint or a
	 * feed that a remote page's markup pointed a library at.
	 *
	 * @param false|array|WP_Error $preempt Existing short-circuit value.
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error The existing value, or a WP_Error when the URL is unsafe.
	 */
	public static function validate_first_request( $preempt, $args, $url ) {
		unset( $args );

		if ( false !== $preempt ) {
			return $preempt;
		}

		$check = Daymark_Subscription_Url_Guard::check( (string) $url );

		return is_wp_error( $check ) ? $check : $preempt;
	}

	/**
	 * Refuse a redirect whose target fails Daymark_Subscription_Url_Guard.
	 *
	 * Throwing a Requests exception is how core's own
	 * WP_Http::validate_redirects() aborts a redirect; the HTTP API turns it
	 * into the WP_Error the caller already handles for any failed request.
	 *
	 * @param mixed $location Absolute URL the response redirects to.
	 * @return void
	 * @throws WpOrg\Requests\Exception When the redirect target is unsafe.
	 */
	public static function validate_redirect( $location ): void {
		$check = Daymark_Subscription_Url_Guard::check( is_string( $location ) ? $location : '' );

		if ( is_wp_error( $check ) ) {
			self::$redirect_refused = true;

			throw new WpOrg\Requests\Exception( esc_html( $check->get_error_message() ), 'daymark.redirect_blocked' );
		}

		// WordPress's own check let it through too.
		self::$pending_redirect = null;
	}

	/**
	 * Note a redirect a response asks for, before WordPress or this class
	 * checks it.
	 *
	 * WordPress checks a redirect target before validate_redirect() sees
	 * it, and one it refuses never reaches this class. Noting the target
	 * here first is what lets get() and post() tell that refusal from a
	 * connection that failed. Mirrors what Requests does next: give up on
	 * a chain that has reached the request's limit, or follow the
	 * `Location` header, made absolute.
	 *
	 * @param mixed $response The response, a WpOrg\Requests\Response.
	 * @param mixed $headers  Request headers (unused).
	 * @param mixed $data     Request body (unused).
	 * @param mixed $options  Request options.
	 * @return void
	 */
	public static function note_redirect( $response, $headers = array(), $data = array(), $options = array() ): void {
		unset( $headers, $data );

		if ( ! $response instanceof WpOrg\Requests\Response || ! $response->is_redirect() || ! is_array( $options ) || true !== ( $options['follow_redirects'] ?? false ) ) {
			return;
		}

		if ( (int) ( $options['redirected'] ?? 0 ) >= (int) ( $options['redirects'] ?? 0 ) ) {
			// Requests stops here with "Too many redirects", every time.
			self::$redirect_refused = true;

			return;
		}

		if ( ! isset( $response->headers['location'] ) ) {
			return;
		}

		$location = (string) $response->headers['location'];

		if ( 0 !== strpos( $location, 'http://' ) && 0 !== strpos( $location, 'https://' ) ) {
			$absolute = WpOrg\Requests\Iri::absolutize( $response->url, $location );
			$location = $absolute ? (string) $absolute->uri : $location;
		}

		self::$pending_redirect = $location;
	}

	/**
	 * Name a request that failed on a redirect refused for good, so callers
	 * can tell it from a connection that failed.
	 *
	 * WordPress reports both as `http_request_failed`; this keeps the
	 * message and changes the code to `daymark_redirect_refused`.
	 *
	 * @param array<string, mixed>|WP_Error $response A wp_safe_remote_*() result.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function name_refusal( $response ) {
		if ( ! is_wp_error( $response ) || 'http_request_failed' !== $response->get_error_code() || ! self::redirect_was_refused() ) {
			return $response;
		}

		return new WP_Error( 'daymark_redirect_refused', $response->get_error_message() );
	}

	/**
	 * Whether the current run()'s request failed on a redirect that will be
	 * refused every time.
	 *
	 * A target WordPress refused before this class saw it counts too, unless
	 * its host didn't resolve: WordPress refuses those as well, and a failed
	 * DNS lookup can pass.
	 *
	 * @return bool
	 */
	private static function redirect_was_refused(): bool {
		if ( self::$redirect_refused ) {
			return true;
		}

		if ( null === self::$pending_redirect ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( self::$pending_redirect, PHP_URL_SCHEME ) );

		return ! in_array( $scheme, array( 'http', 'https' ), true )
			|| is_wp_error( Daymark_Subscription_Url_Guard::check( self::$pending_redirect, false ) )
			|| Daymark_Subscription_Url_Guard::host_resolves( self::$pending_redirect );
	}
}
