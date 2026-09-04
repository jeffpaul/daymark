<?php
/**
 * A tiny per-request cache for "fetch a subscribed site's own homepage
 * HTML" (issue #137's registration-order fix).
 *
 * Every built-in subscription source's discover() step needs to scan the
 * exact same page for a different signal — Daymark_Subscription_Source_WordPress
 * looks for a `<link rel="https://api.w.org/">` REST API root,
 * Daymark_Subscription_Source_Feed for a `<link rel="alternate">` feed, and
 * Daymark_Subscription_Source_Microformats for h-feed/h-entry markup.
 * Daymark_Subscription_Source_Registry::discover_feeds() tries each
 * registered source in turn until one returns a non-empty result, so
 * without this cache, a site that only the *last*-checked source can
 * handle would be fetched once per source tried ahead of it — up to three
 * live requests to the same URL for one subscribe-by-URL call, instead of
 * one. All three sources fetch with identical parameters (the shared
 * `daymark_subscription_max_html_bytes`/`daymark_subscription_html_fetch_timeout`
 * filters), so caching the raw result by URL alone is safe.
 *
 * Deliberately request-scoped only (a plain static array, reset with each
 * fresh PHP process) — this exists purely to de-duplicate the handful of
 * fetches one discover_feeds() call can trigger, not to persist across
 * requests or polling cycles. It is never used for a subscription's actual
 * per-poll content fetch (fetch_feed(), a source's own fetch(), or the
 * wp/v2/posts poll) — only for the one-shot discovery-time homepage scan.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static, request-scoped cache for a subscribed site's homepage HTML.
 */
class Daymark_Subscription_Html_Cache {

	/**
	 * Default maximum response size, in bytes. Shares the same filter
	 * (`daymark_subscription_max_html_bytes`) every subscription source's
	 * own site-HTML fetch already used before this cache existed.
	 *
	 * @var int
	 */
	private const MAX_HTML_BYTES = 1024 * 1024; // 1 MB.

	/**
	 * Cached results, keyed by exact URL.
	 *
	 * @var array<string, string|WP_Error>
	 */
	private static array $cache = array();

	/**
	 * Clear every cached entry.
	 *
	 * Production code never needs this — a real request is a fresh PHP
	 * process, so the static cache starts empty every time regardless. It
	 * exists for PHPUnit, where every test in a file runs in one continuous
	 * process: without resetting between tests, an earlier test's fixture
	 * for a reused URL (several subscription tests across this codebase
	 * reuse `https://example.com/`) would otherwise leak into a later test
	 * expecting a different body for that same URL.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = array();
	}

	/**
	 * Fetch a site's homepage HTML via `wp_safe_remote_get()`, memoized by
	 * URL for the lifetime of the current PHP process.
	 *
	 * `wp_safe_remote_get()` rather than `wp_remote_get()` deliberately: the
	 * URL here is derived from a user-supplied site (the site URL entered at
	 * subscribe time) and this same request recurs unattended via the
	 * polling cron for as long as a subscription exists — the same
	 * SSRF-hardening reasoning every subscription source fetch documents.
	 *
	 * @param string $url Already-sanitized, http(s) URL to fetch.
	 * @return string|WP_Error Response body, or a WP_Error describing why
	 *                         the fetch failed (network error, non-2xx
	 *                         status, or a response at/beyond the size
	 *                         cap — a truncated body is discarded rather
	 *                         than treated as complete).
	 */
	public static function fetch( string $url ) {
		if ( array_key_exists( $url, self::$cache ) ) {
			return self::$cache[ $url ];
		}

		/**
		 * Filters the maximum site-HTML response size, in bytes, a
		 * subscription source's discovery-time homepage fetch will
		 * download.
		 *
		 * @since 0.10.0
		 *
		 * @param int $max_bytes Defaults to 1 MB.
		 */
		$max_bytes = (int) apply_filters( 'daymark_subscription_max_html_bytes', self::MAX_HTML_BYTES );

		$response = wp_safe_remote_get(
			$url,
			array(
				/**
				 * Filters the HTTP timeout, in seconds, used when fetching a
				 * subscribed site's homepage HTML during discovery.
				 *
				 * @since 0.10.0
				 *
				 * @param int $seconds Defaults to 10.
				 */
				'timeout'             => (int) apply_filters( 'daymark_subscription_html_fetch_timeout', 10 ),
				'redirection'         => 5,
				'limit_response_size' => $max_bytes,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		self::$cache[ $url ] = self::extract_result( $response, $max_bytes );

		return self::$cache[ $url ];
	}

	/**
	 * Turn a raw `wp_safe_remote_get()` result into this cache's own
	 * string-or-WP_Error contract.
	 *
	 * @param array<string, mixed>|WP_Error $response  Raw HTTP response.
	 * @param int                           $max_bytes Response size cap that was requested.
	 * @return string|WP_Error
	 */
	private static function extract_result( $response, int $max_bytes ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'daymark_subscription_html_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The page returned HTTP status %d.', 'daymark' ),
					$code
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) >= $max_bytes ) {
			return new WP_Error( 'daymark_subscription_html_too_large', __( 'The page response exceeded the allowed size and was discarded.', 'daymark' ) );
		}

		return $body;
	}
}
