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
	 * A wp_safe_remote_get() whose redirect hops are also validated.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args wp_safe_remote_get() arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( string $url, array $args = array() ) {
		return self::run(
			static function () use ( $url, $args ) {
				return wp_safe_remote_get( $url, $args );
			}
		);
	}

	/**
	 * A wp_safe_remote_post() whose redirect hops are also validated.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args wp_safe_remote_post() arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function post( string $url, array $args = array() ) {
		return self::run(
			static function () use ( $url, $args ) {
				return wp_safe_remote_post( $url, $args );
			}
		);
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
			// Last in line, so a test's or another plugin's canned response
			// still wins and only a request that would really go out is
			// checked (and so is never resolved through DNS needlessly).
			add_filter( self::PRE_REQUEST_HOOK, array( __CLASS__, 'validate_first_request' ), PHP_INT_MAX, 3 );
		}

		++self::$depth;

		try {
			return $request();
		} finally {
			--self::$depth;

			if ( 0 === self::$depth ) {
				remove_action( self::HOOK, array( __CLASS__, 'validate_redirect' ) );
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
			throw new WpOrg\Requests\Exception( esc_html( $check->get_error_message() ), 'daymark.redirect_blocked' );
		}
	}
}
