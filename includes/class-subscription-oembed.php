<?php
/**
 * Read-time oEmbed preview resolution for a subscription post's own detected
 * outbound link (`link_url`, see Daymark_Subscription_Content_Sniffer::sniff()
 * and Daymark_Subscription_Poller::maybe_ingest_item()) — issue #279.
 *
 * Deliberately resolved on demand (the full-screen post view's own request,
 * GET /subscription-posts/{id}/oembed), never at ingest/poll time: unlike
 * the rich-media formats' own `embed_data` (resolved eagerly because every
 * Timeline card needs it to render at all), a link-format item's oEmbed
 * preview is an optional enhancement only the full-screen view shows, so
 * eagerly fetching it for every ingested link-format post — most of which a
 * reader will never open — would be pure waste and needlessly widen this
 * plugin's own SSRF/outbound-request surface.
 *
 * Never trusts a provider's raw oEmbed HTML directly: `wp_oembed_get()`'s
 * response can carry a `<script>` tag some providers rely on (a widget
 * loader) that this app's strict script-src CSP would block anyway, or
 * arbitrary markup with no real security review behind it. Only ever
 * re-emits a single, minimal, attribute-allowlisted `<iframe>` or `<img>`
 * this class rebuilds itself from whatever it found in the response — never
 * the provider's own markup verbatim.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static oEmbed preview resolver for a subscription post's detected link.
 */
class Daymark_Subscription_Oembed {

	/**
	 * Default maximum oEmbed response size, in bytes. A JSON/XML oEmbed
	 * response is always small — this is generous headroom, not a realistic
	 * expectation. Filterable via `daymark_subscription_oembed_max_bytes`.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 512 * 1024; // 512 KB.

	/**
	 * Default cache TTL for a resolved (or failed) lookup, in seconds.
	 * Filterable via `daymark_subscription_oembed_cache_ttl`. A failure is
	 * cached too — an unembeddable link stays unembeddable — so a
	 * repeatedly-viewed post never re-attempts the same failing fetch on
	 * every open.
	 *
	 * @var int
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Maximum width/height, in pixels, an extracted <iframe>/<img> is
	 * clamped to — a provider reporting an implausible or malicious
	 * dimension should never be able to blow out the post view's layout.
	 *
	 * @var int
	 */
	private const MAX_DIMENSION = 600;

	/**
	 * Resolve an oEmbed preview for a URL, cached by URL for CACHE_TTL.
	 *
	 * Never throws and never blocks — any failure (an unsafe URL, no
	 * provider, a network error, a response with nothing this class trusts
	 * enough to re-emit) resolves to an empty array, exactly like "no
	 * preview available" rather than an error the caller has to branch on.
	 *
	 * @param string $url Candidate link URL (e.g. a subscription post's own `link_url` meta).
	 * @return array{type: string, html: string} 'type' is 'iframe' or
	 *                                             'photo'; empty array when
	 *                                             no safely-embeddable
	 *                                             preview was found.
	 */
	public static function resolve( string $url ): array {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			return array();
		}

		$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			self::log_debug( sprintf( 'Rejected %1$s: scheme "%2$s" is not http(s).', $url, $scheme ) );

			return array();
		}

		$guard_result = Daymark_Subscription_Url_Guard::check( $url );

		if ( is_wp_error( $guard_result ) ) {
			// TEMPORARY diagnostic, see fetch_and_extract()'s own note —
			// resolve() returns before ever reaching that method's own
			// logging when the guard itself rejects a URL, so this is the
			// one place that failure mode gets surfaced at all.
			self::log_debug( sprintf( 'Rejected %1$s by Daymark_Subscription_Url_Guard: %2$s', $url, $guard_result->get_error_message() ) );

			return array();
		}

		$cache_key = 'daymark_oembed_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			self::log_debug( sprintf( 'Serving cached result for %1$s (%2$s).', $url, empty( $cached ) ? 'empty/failed' : 'has embed' ) );

			return $cached;
		}

		$result = self::fetch_and_extract( $url );

		set_transient(
			$cache_key,
			$result,
			/**
			 * Filters how long a resolved (or failed) oEmbed lookup is cached, in seconds.
			 *
			 * @since 0.14.0
			 *
			 * @param int $seconds Defaults to DAY_IN_SECONDS.
			 */
			(int) apply_filters( 'daymark_subscription_oembed_cache_ttl', self::CACHE_TTL )
		);

		return $result;
	}

	/**
	 * Call WP core's own `wp_oembed_get()` (with discovery enabled, since a
	 * provider outside core's own fixed whitelist — e.g. a Mastodon
	 * instance's own oEmbed endpoint — is the common case for a subscribed
	 * link) and reduce whatever it returns to a safely re-emittable preview.
	 *
	 * @param string $url Already URL-guard-checked, http(s) URL.
	 * @return array{type: string, html: string} See resolve()'s own return contract.
	 */
	private static function fetch_and_extract( string $url ): array {
		if ( ! function_exists( 'wp_oembed_get' ) ) {
			return array();
		}

		// Scoped narrowly to this one wp_oembed_get() call, the same idiom
		// Daymark_Subscription_Source_Feed::fetch_simplepie_feed() already
		// uses for fetch_feed(): wp_oembed_get()'s own signature has no
		// direct way to pass a response size cap or timeout through to the
		// underlying HTTP request.
		add_filter( 'http_request_args', array( __CLASS__, 'inject_request_limits' ), 10, 1 );
		// TEMPORARY, WP_DEBUG-only diagnostic while chasing a report of
		// several known-public Vimeo/YouTube URLs all resolving to nothing —
		// see log_http_debug()'s own docblock. Remove once root-caused.
		add_action( 'http_api_debug', array( __CLASS__, 'log_http_debug' ), 10, 5 );

		try {
			$html = wp_oembed_get( $url, array( 'discover' => true ) );
		} catch ( Throwable $e ) {
			$html = false;
			self::log_debug( sprintf( 'wp_oembed_get(%1$s) threw: %2$s', $url, $e->getMessage() ) );
		}

		remove_filter( 'http_request_args', array( __CLASS__, 'inject_request_limits' ), 10 );
		remove_action( 'http_api_debug', array( __CLASS__, 'log_http_debug' ), 10 );

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			self::log_debug(
				sprintf(
					'wp_oembed_get(%1$s) returned nothing embeddable (%2$s).',
					$url,
					is_string( $html ) ? 'empty string' : 'false'
				)
			);

			return array();
		}

		self::log_debug( sprintf( 'wp_oembed_get(%1$s) returned %2$d bytes of HTML.', $url, strlen( $html ) ) );

		$extracted = self::extract_safe_embed( $html );

		if ( empty( $extracted ) ) {
			self::log_debug( sprintf( 'extract_safe_embed() found no iframe/img in the oEmbed response for %s.', $url ) );
		}

		return $extracted;
	}

	/**
	 * TEMPORARY diagnostic, added while chasing a report of several
	 * known-public Vimeo/YouTube URLs all resolving to "no preview" —
	 * hooked/unhooked around the single wp_oembed_get() call above the same
	 * way inject_request_limits() already is, so it only ever observes the
	 * HTTP request(s) that call itself makes (a direct provider-endpoint
	 * fetch, or a page fetch + endpoint fetch when discovery is needed).
	 * Logs the real cause a caller has otherwise had no visibility into:
	 * a network-level failure (WP_Error — e.g. a blocked/refused outbound
	 * connection) vs. a non-2xx response from the provider (e.g. embedding
	 * declined) vs. a 200 response whose body wp_oembed_get()/
	 * extract_safe_embed() still found nothing usable in. Remove once
	 * root-caused.
	 *
	 * @param array|WP_Error $response HTTP response array, or WP_Error on failure.
	 * @param string         $context  Debug context string core passes (e.g. 'response').
	 * @param string         $transport_class HTTP transport class name.
	 * @param array          $args            Request args.
	 * @param string         $url             Request URL.
	 * @return void
	 */
	public static function log_http_debug( $response, string $context, string $transport_class, array $args, string $url ): void {
		if ( is_wp_error( $response ) ) {
			self::log_debug( sprintf( 'HTTP request to %1$s failed: %2$s', $url, $response->get_error_message() ) );

			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		self::log_debug(
			sprintf(
				'HTTP request to %1$s returned %2$d, body starts: %3$s',
				$url,
				$code,
				substr( (string) wp_remote_retrieve_body( $response ), 0, 300 )
			)
		);
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
			error_log( '[Daymark Subscription Oembed] ' . $message );
		}
	}

	/**
	 * `http_request_args` filter callback — see fetch_and_extract()'s own
	 * docblock for why this is added/removed around a single call rather
	 * than left registered.
	 *
	 * @param array<string, mixed> $args HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public static function inject_request_limits( array $args ): array {
		/**
		 * Filters the maximum oEmbed response size, in bytes, this class will download.
		 *
		 * @since 0.14.0
		 *
		 * @param int $max_bytes Defaults to 512 KB.
		 */
		$args['limit_response_size'] = (int) apply_filters( 'daymark_subscription_oembed_max_bytes', self::MAX_RESPONSE_BYTES );

		/**
		 * Filters the HTTP timeout, in seconds, used when resolving an oEmbed preview.
		 *
		 * @since 0.14.0
		 *
		 * @param int $seconds Defaults to 8.
		 */
		$args['timeout'] = (int) apply_filters( 'daymark_subscription_oembed_fetch_timeout', 8 );

		return $args;
	}

	/**
	 * Reduce a provider's raw oEmbed HTML to a single, minimal,
	 * attribute-allowlisted `<iframe>` or `<img>` this class rebuilds
	 * itself — see class docblock for why the provider's own markup is
	 * never re-emitted verbatim. The first `<iframe>` found wins (a "rich"/
	 * "video" type oEmbed); failing that, the first `<img>` (a "photo" type
	 * oEmbed). A "link"-type oEmbed (no iframe or img at all — typically a
	 * bare title/author, sometimes with a `<blockquote>` that relies on a
	 * provider's own widget script to render anything) resolves to nothing:
	 * rendering the bare blockquote text with no working widget would look
	 * broken, so this skips cleanly instead, per issue #279's "where
	 * possible" scope.
	 *
	 * @param string $html Raw oEmbed HTML from the provider.
	 * @return array{type: string, html: string} See resolve()'s own return contract.
	 */
	private static function extract_safe_embed( string $html ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		try {
			$processor = new WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag() ) {
				$tag = $processor->get_tag();

				if ( 'IFRAME' === $tag ) {
					$src = self::safe_src( $processor->get_attribute( 'src' ) );

					if ( '' === $src ) {
						continue;
					}

					$width  = self::clamp_dimension( $processor->get_attribute( 'width' ) );
					$height = self::clamp_dimension( $processor->get_attribute( 'height' ) );
					$title  = sanitize_text_field( (string) ( $processor->get_attribute( 'title' ) ?? '' ) );

					return array(
						'type' => 'iframe',
						// width/height set via an inline aspect-ratio (this
						// app shell's style-src already allows
						// 'unsafe-inline', unlike its script-src) rather
						// than fixed HTML attributes, so the app shell's own
						// CSS (.daymark-oembed-preview iframe) can size the
						// iframe to its own container's width while keeping
						// the provider's reported aspect ratio, instead of
						// a fixed pixel box that either overflows a narrow
						// phone screen or leaves the provider's own
						// reported size unused.
						'html' => sprintf(
							'<iframe src="%1$s" title="%2$s" loading="lazy" style="aspect-ratio:%3$d/%4$d" frameborder="0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"></iframe>',
							esc_url( $src ),
							esc_attr( $title ),
							$width,
							$height
						),
					);
				}

				if ( 'IMG' === $tag ) {
					$src = self::safe_src( $processor->get_attribute( 'src' ) );

					if ( '' === $src ) {
						continue;
					}

					$alt = sanitize_text_field( (string) ( $processor->get_attribute( 'alt' ) ?? '' ) );

					return array(
						'type' => 'photo',
						'html' => sprintf(
							'<img src="%1$s" alt="%2$s" loading="lazy">',
							esc_url( $src ),
							esc_attr( $alt )
						),
					);
				}
			}
		} catch ( Throwable $e ) {
			return array();
		}

		return array();
	}

	/**
	 * Validate and normalize a candidate `src` attribute pulled from a
	 * provider's response — http(s) only, never `javascript:`/`data:`/a
	 * relative path with no real host.
	 *
	 * @param string|true|null $value Raw attribute value from WP_HTML_Tag_Processor::get_attribute().
	 * @return string Sanitized absolute URL, or '' when not safely usable.
	 */
	private static function safe_src( $value ): string {
		$src = esc_url_raw( (string) ( is_string( $value ) ? $value : '' ) );

		if ( '' === $src ) {
			return '';
		}

		$scheme = strtolower( (string) ( wp_parse_url( $src, PHP_URL_SCHEME ) ?? '' ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $src : '';
	}

	/**
	 * Clamp a provider-reported width/height to a sane, layout-safe bound.
	 *
	 * @param string|true|null $value Raw attribute value.
	 * @return int A positive integer no larger than MAX_DIMENSION.
	 */
	private static function clamp_dimension( $value ): int {
		$int = absint( (string) ( is_string( $value ) ? $value : '' ) );

		return $int > 0 ? min( $int, self::MAX_DIMENSION ) : self::MAX_DIMENSION;
	}
}
