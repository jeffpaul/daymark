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
				// TEMPORARY diagnostic while chasing issue #402's Playground
				// report — the gethostbyname()-vs-gethostbynamel() hypothesis
				// (see the removed comment this replaces) is now refuted:
				// confirmed real data shows both functions return the
				// identical synthetic, per-host-incrementing 172.29.x.0
				// address. Now also logging php_uname('s') to test a second,
				// independently-researched hypothesis — see
				// should_skip_dns_resolution()'s own updated docblock.
				// Remove alongside the rest of this PR's oEmbed diagnostics
				// once root-caused.
				self::log_debug(
					sprintf(
						'Rejected %1$s: host "%2$s" resolved to "%3$s" (unsafe). PHP_SAPI=%4$s, php_uname(s)=%5$s, gethostbyname()=%6$s, gethostbynamel()=%7$s, dns_get_record(A)=%8$s, dns_get_record(AAAA)=%9$s',
						$url,
						$host,
						$address,
						PHP_SAPI,
						function_exists( 'php_uname' ) ? php_uname( 's' ) : 'undefined',
						function_exists( 'gethostbyname' ) ? gethostbyname( $host ) : 'undefined',
						function_exists( 'gethostbynamel' ) ? wp_json_encode( gethostbynamel( $host ) ) : 'undefined',
						function_exists( 'dns_get_record' ) ? wp_json_encode( @dns_get_record( $host, DNS_A ) ) : 'undefined', // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same "no record is expected, not an error" reasoning as resolve_addresses()'s own call.
						function_exists( 'dns_get_record' ) ? wp_json_encode( @dns_get_record( $host, DNS_AAAA ) ) : 'undefined' // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same reasoning.
					)
				);

				return new WP_Error(
					'daymark_subscription_unsafe_url',
					__( 'This URL resolves to a private or internal network address.', 'daymark' )
				);
			}
		}

		return true;
	}

	/**
	 * Log a debug message when WP_DEBUG is enabled. Never throws. Matches
	 * Daymark_AI_Assist::log_debug()'s own established convention.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only fallback logging.
			error_log( '[Daymark Subscription Url Guard] ' . $message );
		}
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
			// Filtered through the same keep_valid_addresses() every other
			// source below is, so a callback supplying something other than
			// genuine IP addresses — deliberately, in a test exercising this
			// exact defensive behavior, or by mistake — gets the same safe
			// "couldn't resolve" treatment as a real DNS function returning
			// garbage would.
			return self::keep_valid_addresses( $addresses );
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		if ( self::should_skip_dns_resolution() ) {
			return array();
		}

		$addresses = array();

		// function_exists() guards both DNS calls the same way — not just
		// dns_get_record() as before. A core DNS-resolution function isn't
		// guaranteed to exist in every PHP runtime this plugin's own SSRF
		// guard might execute in: WordPress Playground's browser-sandboxed
		// PHP-WASM build documents dns_get_record() itself as undefined
		// there (https://github.com/WordPress/wordpress-playground/issues/1042).
		// gethostbynamel() is a plausible second casualty of that same
		// constraint too, though in practice (issue #365) it turned out to
		// exist there but not perform a genuine lookup — see
		// should_skip_dns_resolution(), which is what actually catches that
		// case (real DNS resolution is skipped entirely under Playground's
		// SAPI before either call below is ever reached). This function_exists()
		// guard stays regardless, for a runtime where one of these two truly
		// is undefined: an unconditional call would throw an uncaught "Call
		// to undefined function" error and fatal the whole request, not just
		// this one check.
		if ( function_exists( 'gethostbynamel' ) ) {
			$addresses = array_merge( $addresses, self::keep_valid_addresses( gethostbynamel( $host ) ) );
		}

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() emits a warning for a host with no AAAA record; that is an expected, non-exceptional outcome here, not an error to surface.
			$records = @dns_get_record( $host, DNS_AAAA );

			if ( is_array( $records ) ) {
				$ipv6_candidates = array();

				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ipv6_candidates[] = (string) $record['ipv6'];
					}
				}

				$addresses = array_merge( $addresses, self::keep_valid_addresses( $ipv6_candidates ) );
			}
		}

		return $addresses;
	}

	/**
	 * Whether to skip real DNS resolution entirely, trusting the exact same
	 * "nothing to check, safe to proceed" path an ordinary unresolvable host
	 * already takes (issue #365).
	 *
	 * Originally (issue #365) this defaulted to `PHP_SAPI === 'wasm'` alone.
	 * A later report (issue #402) showed that check silently stopping a
	 * different Playground build/version from ever engaging: `PHP_SAPI` came
	 * back `'cli'` there instead, with `gethostbyname()`/`gethostbynamel()`
	 * (confirmed — both singular and plural forms, ruling out a
	 * function-specific gap) still handing back the exact same synthetic,
	 * per-host-incrementing `172.29.x.0`-pattern address issue #365 already
	 * diagnosed. Root-caused by reading WordPress Playground's own php-wasm
	 * C source (`packages/php-wasm/compile/php/php_wasm.c`): the `"wasm"`
	 * SAPI name is only a compile-time *default* — the same file exposes a
	 * `wasm_set_sapi_name()` hook the embedding layer can call before SAPI
	 * startup to override it, which is almost certainly why a newer/different
	 * Playground build no longer reports it. `PHP_SAPI`'s exact string can
	 * therefore never be a fully reliable signal on its own, however tempting
	 * a single hardcoded value looks.
	 *
	 * A second, more intrinsic signal now backs it up: `php_uname( 's' )`.
	 * Emscripten's own C runtime — what php-wasm itself compiles against —
	 * hardcodes its `uname()` syscall emulation to always report `sysname`
	 * as the literal string `"Emscripten"` (confirmed against Emscripten's
	 * own public documentation/source; e.g. the emscripten-core project's own
	 * `library_syscall.js`). Unlike the SAPI name, this is baked into the
	 * WASM runtime's libc layer itself, not something the embedding
	 * application configures per build/version — and it is not a value any
	 * real production Linux server's own WP-CLI/cron invocation could ever
	 * report, so trusting it costs nothing for the real-site case this guard
	 * exists to protect.
	 *
	 * Either signal matching is enough: there is no real internal network for
	 * this client-side, single-user sandbox to protect against SSRF-wise in
	 * the first place, and the actual outbound `wp_safe_remote_get()` call
	 * still goes through Playground's own genuinely proxied networking layer
	 * regardless of what this pre-flight decides — so skipping it there costs
	 * nothing real.
	 *
	 * Not independently confirmed against a live Playground instance — this
	 * environment's own egress policy has no route to
	 * playground.wordpress.net to execute against directly; diagnosed from
	 * the reported symptom plus wordpress-playground's/Emscripten's own
	 * public source, the same "researched against public source, flagged for
	 * verification" posture already used for several other Playground/
	 * environment-constrained diagnoses in this codebase.
	 *
	 * @since 0.16.0
	 *
	 * @return bool
	 */
	private static function should_skip_dns_resolution(): bool {
		/**
		 * Filters whether Daymark_Subscription_Url_Guard should skip its own
		 * DNS-resolution pre-flight entirely.
		 *
		 * @since 0.16.0
		 *
		 * @param bool $skip Whether to skip DNS resolution. Defaults to true
		 *                    when either `PHP_SAPI === 'wasm'` or
		 *                    `php_uname( 's' ) === 'Emscripten'` — see this
		 *                    method's own docblock for why both are checked.
		 */
		return (bool) apply_filters( 'daymark_subscription_url_guard_skip_dns_resolution', self::is_php_wasm_runtime() );
	}

	/**
	 * Whether this request is running under a php-wasm (e.g. WordPress
	 * Playground) build, via either of the two signals
	 * should_skip_dns_resolution()'s own docblock explains.
	 *
	 * @return bool
	 */
	private static function is_php_wasm_runtime(): bool {
		if ( 'wasm' === PHP_SAPI ) {
			return true;
		}

		return function_exists( 'php_uname' ) && 'Emscripten' === php_uname( 's' );
	}

	/**
	 * Keep only the entries that are genuinely well-formed IP addresses,
	 * discarding anything else a DNS-resolution function (or the
	 * `daymark_subscription_url_guard_resolved_addresses` filter above)
	 * might hand back instead of cleanly failing — most notably a
	 * constrained runtime's DNS shim echoing the unresolved hostname back
	 * rather than returning `false`/an empty result. Trusting a non-IP
	 * value as a "resolved address" would run it through
	 * is_unsafe_address() below, which correctly rejects it as not a valid
	 * IP at all — wrongly treating an ordinary, resolvable public site as
	 * unsafe rather than as the plain resolution failure it actually was.
	 *
	 * @param mixed $candidates Whatever a DNS-resolution function (or the
	 *                          filter above) returned; anything but an
	 *                          array of strings is treated as no
	 *                          candidates at all.
	 * @return string[] Only the entries that parse as a valid IP address.
	 */
	private static function keep_valid_addresses( $candidates ): array {
		if ( ! is_array( $candidates ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$candidates,
				static function ( $candidate ) {
					return is_string( $candidate ) && false !== filter_var( $candidate, FILTER_VALIDATE_IP );
				}
			)
		);
	}

	/**
	 * Whether a resolved IP address falls in a private/loopback/link-local/
	 * reserved/CGNAT range.
	 *
	 * PHP's own `FILTER_VALIDATE_IP` flags cover most of what this needs:
	 * `FILTER_FLAG_NO_PRIV_RANGE` rejects the standard IPv4 private ranges
	 * (10/8, 172.16/12, 192.168/16) and the IPv6 unique local range (fc00::/7);
	 * `FILTER_FLAG_NO_RES_RANGE` rejects the IPv4 reserved ranges (0/8,
	 * 169.254/16, 127/8, 240/4) and, for IPv6, loopback (::1), unspecified
	 * (::), and link-local (fe80::/10). Whether that same flag also rejects
	 * the IPv4-mapped block (::ffff:0:0/96) as a whole turns out to be
	 * PHP-version-dependent (observed: rejected on 8.4, accepted on 8.2), so
	 * an IPv4-mapped literal like `::ffff:127.0.0.1` is unwrapped explicitly
	 * below and its embedded IPv4 address re-checked on its own — not left to
	 * that flag. Only the CGNAT range (100.64.0.0/10) has no built-in flag
	 * and is checked separately below.
	 *
	 * @param string $address A resolved (or literal) IP address.
	 * @return bool
	 */
	private static function is_unsafe_address( string $address ): bool {
		$mapped_ipv4 = self::extract_ipv4_mapped_address( $address );

		if ( null !== $mapped_ipv4 ) {
			return self::is_unsafe_address( $mapped_ipv4 );
		}

		if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return self::is_cgnat_ipv4( $address );
	}

	/**
	 * Unwrap an IPv4-mapped IPv6 literal (the ::ffff:0:0/96 block, e.g.
	 * `::ffff:127.0.0.1`) to its embedded IPv4 address.
	 *
	 * @param string $address Candidate address.
	 * @return string|null The embedded dotted IPv4 address, or null when
	 *                      $address is not an IPv4-mapped IPv6 address.
	 */
	private static function extract_ipv4_mapped_address( string $address ): ?string {
		if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return null;
		}

		$binary = inet_pton( $address );

		if ( false === $binary || 16 !== strlen( $binary ) ) {
			return null;
		}

		if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" !== substr( $binary, 0, 12 ) ) {
			return null;
		}

		$ipv4 = inet_ntop( substr( $binary, 12, 4 ) );

		return false !== $ipv4 ? $ipv4 : null;
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
