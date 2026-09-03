<?php
/**
 * SSRF defense-in-depth for every subscription-related URL Daymark fetches
 * (issue #81, "Malformed and malicious feed hardening").
 *
 * Every remote fetch in the Subscriptions feature already goes through
 * `wp_safe_remote_get()` (or, for the feed source, SimplePie's own
 * `WP_SimplePie_File`, itself a `wp_safe_remote_request()` wrapper), so
 * WordPress core's own `wp_http_validate_url()` — which blocks IPv4
 * loopback/private/reserved ranges — already applies to every request this
 * plugin makes. That check is IPv4-only and does not pre-flight the target
 * host before a fetch is attempted from three different call sites (the
 * feed source's discover()/fetch()/get_favicon_url()/get_site_title(), the
 * subscribe-by-URL flow, and the click-through full-content fetch); this
 * class is the shared, reusable belt-and-suspenders check those call sites
 * apply on top of core's own protection — not a replacement for it.
 *
 * DNS-rebinding across the fetch window (resolve safe here, then have the
 * name re-resolve to a private address by the time the real request goes
 * out) is a known, accepted residual risk — the same one core's own
 * `wp_http_validate_url()` already carries — and is explicitly out of scope
 * for a personal-site plugin; nothing here tries to close it.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static SSRF pre-flight guard for a subscription/feed/site URL.
 */
class Daymark_Subscription_Url_Guard {

	/**
	 * Ports a subscription-related URL is allowed to use.
	 *
	 * @var int[]
	 */
	private const ALLOWED_PORTS = array( 80, 443, 8080 );

	/**
	 * Check whether a URL is safe to fetch: standard port, no embedded
	 * userinfo, and a host that does not resolve to a private/internal/
	 * reserved address.
	 *
	 * Deliberately narrow in scope — this does not re-validate scheme
	 * (callers already require http/https before reaching here) or general
	 * URL well-formedness; it only adds the checks core's own
	 * `wp_http_validate_url()` either skips (userinfo, port) or only partly
	 * covers (IPv4-only address-range blocking).
	 *
	 * @param string $url Already scheme-validated http(s) URL.
	 * @return true|WP_Error True when safe to fetch; WP_Error with a
	 *                       specific, human-readable rejection reason
	 *                       otherwise (suitable both for a user-facing
	 *                       message and for a `last_error` value).
	 */
	public static function check( string $url ) {
		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return new WP_Error(
				'daymark_subscription_unsafe_url',
				__( 'This URL could not be validated.', 'daymark' )
			);
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return new WP_Error(
				'daymark_subscription_unsafe_url',
				__( 'URLs with embedded login credentials are not allowed.', 'daymark' )
			);
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		$port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'https' === $scheme ? 443 : 80 );

		if ( ! in_array( $port, self::ALLOWED_PORTS, true ) ) {
			return new WP_Error(
				'daymark_subscription_unsafe_url',
				__( 'This URL uses a port that is not allowed.', 'daymark' )
			);
		}

		// PHP's (and wp_parse_url()'s) host component keeps a literal IPv6
		// address's enclosing brackets, e.g. "[::1]" — strip them before
		// treating it as a candidate address or hostname, or a bracketed
		// literal would fail FILTER_VALIDATE_IP, fall through to a
		// hostname-style DNS lookup that finds nothing, and be waved through
		// as "unresolvable" instead of being recognized and rejected as the
		// literal private/internal address it actually is.
		$host = trim( (string) $parsed['host'], '[]' );

		foreach ( self::resolve_addresses( $host ) as $address ) {
			if ( self::is_unsafe_address( $address ) ) {
				return new WP_Error(
					'daymark_subscription_unsafe_url',
					__( 'This URL resolves to a private or internal network address.', 'daymark' )
				);
			}
		}

		return true;
	}

	/**
	 * Resolve a host to the IP address(es) it points at.
	 *
	 * An empty result (the host could not be resolved at all) is treated by
	 * check() as safe to proceed — that is not a private-network address,
	 * it is a plain connectivity failure the fetch itself will surface a
	 * moment later on its own, the same as it always has.
	 *
	 * @param string $host Hostname, or an IPv4/IPv6 literal.
	 * @return string[] Resolved IP addresses (may be empty).
	 */
	private static function resolve_addresses( string $host ): array {
		/**
		 * Filters the resolved IP addresses for a host, short-circuiting the
		 * real DNS lookup below.
		 *
		 * Production code has no reason to use this — it exists so tests
		 * can supply a deterministic resolution instead of depending on a
		 * real, and possibly sandboxed-network-unavailable, DNS lookup.
		 *
		 * @since 0.10.0
		 *
		 * @param string[]|null $addresses Resolved addresses to use instead of a
		 *                                 real lookup, or null to perform one.
		 * @param string        $host      Hostname being resolved.
		 */
		$addresses = apply_filters( 'daymark_subscription_url_guard_resolved_addresses', null, $host );

		if ( is_array( $addresses ) ) {
			return $addresses;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$addresses = array();

		$ipv4_addresses = gethostbynamel( $host );

		if ( is_array( $ipv4_addresses ) ) {
			$addresses = array_merge( $addresses, $ipv4_addresses );
		}

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() emits a warning for a host with no AAAA record; that is an expected, non-exceptional outcome here, not an error to surface.
			$records = @dns_get_record( $host, DNS_AAAA );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$addresses[] = (string) $record['ipv6'];
					}
				}
			}
		}

		return $addresses;
	}

	/**
	 * Whether a resolved IP address falls in a private/loopback/link-local/
	 * reserved/CGNAT range.
	 *
	 * PHP's own `FILTER_VALIDATE_IP` flags already cover almost everything
	 * this needs: `FILTER_FLAG_NO_PRIV_RANGE` rejects the standard IPv4
	 * private ranges (10/8, 172.16/12, 192.168/16) and the IPv6 unique local
	 * range (fc00::/7); `FILTER_FLAG_NO_RES_RANGE` rejects the IPv4 reserved
	 * ranges (0/8, 169.254/16, 127/8, 240/4) and, for IPv6, loopback (::1),
	 * unspecified (::), link-local (fe80::/10), and — usefully, since it
	 * rejects the entire block regardless of the embedded address — the
	 * IPv4-mapped range (::ffff:0:0/96), which is exactly what catches a
	 * literal like `::ffff:127.0.0.1`. Only the CGNAT range (100.64.0.0/10)
	 * has no built-in flag and is checked separately below.
	 *
	 * @param string $address A resolved (or literal) IP address.
	 * @return bool
	 */
	private static function is_unsafe_address( string $address ): bool {
		if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return self::is_cgnat_ipv4( $address );
	}

	/**
	 * Whether a dotted IPv4 address falls in the CGNAT range
	 * (100.64.0.0/10, i.e. 100.64.0.0–100.127.255.255).
	 *
	 * @param string $address Candidate address (IPv4 or IPv6).
	 * @return bool False for anything that is not a dotted IPv4 address —
	 *              CGNAT is an IPv4-only range.
	 */
	private static function is_cgnat_ipv4( string $address ): bool {
		$long = ip2long( $address );

		if ( false === $long ) {
			return false;
		}

		return $long >= ip2long( '100.64.0.0' ) && $long <= ip2long( '100.127.255.255' );
	}
}
