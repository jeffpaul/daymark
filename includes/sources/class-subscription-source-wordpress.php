<?php
/**
 * The built-in WordPress REST API subscription source (issue #137).
 *
 * A preferred companion to Daymark_Subscription_Source_Feed for a subscribed
 * site that happens to be WordPress itself: `GET /wp-json/wp/v2/posts`
 * returns an exact `format` taxonomy value per post — `standard`, `image`,
 * `gallery`, `video`, `audio`, `aside`, `link`, `quote`, `status`, `chat` —
 * plus already-rendered content/excerpt HTML and structured author/
 * featured-media data, none of which RSS/Atom's own XML carries. Where the
 * RSS/Atom connector has to *guess* a post's type from enclosures or content
 * sniffing (Daymark_Subscription_Content_Sniffer), this source reads the
 * real thing first — but a `format` of `standard` is ambiguous rather than
 * confirmed (most WordPress sites never assign a post format at all), so
 * normalize() still falls back to that same shared content sniffer for a
 * `standard`-reporting post, the same way Daymark_Subscription_Source_Friends
 * does for a cached post with no Friends-assigned format.
 *
 * Registered first in
 * Daymark_Subscription_Source_Registry::register_built_in_sources() — ahead
 * of both the feed and microformats sources — so a WordPress site with a
 * discoverable, *working* REST API is always preferred over its RSS/Atom
 * feed; any other site (including a WordPress site with the REST API
 * disabled or unreachable) falls straight through to the existing feed
 * connector with no behavior change, exactly as issue #137 asks.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress REST API source: `Daymark_Subscription_Source` built on a
 * subscribed site's own `wp/v2/posts` collection endpoint.
 */
class Daymark_Subscription_Source_WordPress implements Daymark_Subscription_Source {

	/**
	 * Default maximum `wp/v2/posts` response size, in bytes. Shares the
	 * same filter (`daymark_subscription_max_feed_bytes`) the feed source's
	 * own feed download uses — this is the WP-REST-flavored equivalent of
	 * "fetch the feed."
	 *
	 * @var int
	 */
	private const MAX_FEED_BYTES = 2 * 1024 * 1024; // 2 MB.

	/**
	 * How many posts to request per poll. Deliberately a single page, no
	 * further pagination — matches the feed source's own behavior, which
	 * only ever ingests whatever one fetch returns.
	 *
	 * @var int
	 */
	private const POSTS_PER_PAGE = 20;

	/**
	 * WordPress post_format values with no dedicated Daymark post_format
	 * bucket. Mapped down to 'standard' in normalize() — the same "no
	 * natural equivalent" treatment issue #84's h-entry post types
	 * (reply/like/repost/bookmark/rsvp) already get.
	 *
	 * @var string[]
	 */
	private const DAYMARK_MEDIA_FORMATS = array( 'image', 'video', 'audio', 'gallery' );

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- a lowercase machine ID (source_type value), matching the 'feed'/'microformats' sibling sources, not prose.
		return 'wordpress';
	}

	/**
	 * Source label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordPress REST API', 'daymark' );
	}

	/**
	 * Discover whether a site is WordPress with a working REST API: finds
	 * the `<link rel="https://api.w.org/">` root URL WordPress core always
	 * renders in a theme's `<head>`, then actively probes
	 * `{root}wp/v2/posts` to confirm it genuinely responds — some sites
	 * disable the REST API (or `wp/v2/posts` specifically) while leaving the
	 * discovery `<link>` in place, and the issue's own acceptance criteria
	 * calls for noting real reachability, not just the tag's presence.
	 *
	 * @param string $site_url Site URL entered by the user.
	 * @return array<int, array{url: string, title: string, type: string}> A
	 *         single-element array carrying the site's `wp/v2/posts`
	 *         collection URL when the REST API is discoverable and reachable;
	 *         empty otherwise (not WordPress, REST API disabled/unreachable,
	 *         or the site itself could not be fetched).
	 */
	public function discover( string $site_url ): array {
		$site_url = $this->sanitize_source_url( $site_url );

		if ( '' === $site_url ) {
			return array();
		}

		$html = $this->fetch_html( $site_url );

		if ( '' === $html ) {
			return array();
		}

		$rest_root = $this->find_rest_root( $html, $site_url );

		if ( '' === $rest_root ) {
			return array();
		}

		$posts_url = rtrim( $rest_root, '/' ) . '/wp/v2/posts';

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $posts_url ) ) ) {
			return array();
		}

		if ( ! $this->probe_posts_endpoint( $posts_url ) ) {
			return array();
		}

		return array(
			array(
				'url'   => $posts_url,
				'title' => $this->extract_site_title( $html ),
				'type'  => 'application/json',
			),
		);
	}

	/**
	 * Fetch and parse a page of `wp/v2/posts`.
	 *
	 * An empty array is a genuinely successful fetch of a site with no
	 * current posts — distinct from a WP_Error, which means the endpoint
	 * itself could not be reached or returned something other than a JSON
	 * post list. Matches the same distinction every other subscription
	 * source documents, for the same reason: the poller's dead-feed
	 * detection must not mark a quiet-but-healthy site dead after enough
	 * empty-but-successful polls.
	 *
	 * @param string $url The `wp/v2/posts` collection URL discover() found.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch( string $url ): array|WP_Error {
		$validated = $this->validate_source_url( $url );

		if ( is_wp_error( $validated ) ) {
			return new WP_Error( 'daymark_subscription_invalid_feed_url', $validated->get_error_message() );
		}

		$request_url = add_query_arg(
			array(
				'per_page' => self::POSTS_PER_PAGE,
				'orderby'  => 'date',
				'order'    => 'desc',
				'_embed'   => 'author,wp:featuredmedia',
			),
			esc_url_raw( trim( $url ) )
		);

		$body = $this->fetch_body( $request_url, self::MAX_FEED_BYTES, 'daymark_subscription_max_feed_bytes', 'daymark_subscription_feed_fetch_timeout' );

		if ( is_wp_error( $body ) ) {
			return new WP_Error( 'daymark_subscription_wp_rest_fetch_failed', $body->get_error_message() );
		}

		$posts = json_decode( $body, true );

		if ( ! is_array( $posts ) ) {
			return new WP_Error( 'daymark_subscription_wp_rest_invalid_response', __( 'The REST API did not return a valid list of posts.', 'daymark' ) );
		}

		$raw_items = array();

		foreach ( $posts as $post ) {
			if ( is_array( $post ) ) {
				$raw_items[] = $post;
			}
		}

		return $raw_items;
	}

	/**
	 * Map one raw `wp/v2/posts` item to Daymark's source-agnostic post
	 * shape — the same eight-key shape Daymark_Subscription_Source_Feed::
	 * normalize() produces, so ingest code never needs to know which source
	 * produced an item.
	 *
	 * `post_format` reads the site's own real `format` field directly
	 * rather than guessing from content — the entire point of this source —
	 * except for the five WordPress formats with no dedicated Daymark
	 * bucket (`aside`/`link`/`quote`/`status`/`chat`), which map down to
	 * `standard`. A `standard` result (whether genuinely assigned or mapped
	 * down from one of those five) then falls back to
	 * Daymark_Subscription_Content_Sniffer against the post's own rendered
	 * content, the same inline-`<img>`/`<video>`/`<audio>`/mf2 signal
	 * Daymark_Subscription_Source_Feed already looks for — most WordPress
	 * sites never assign post formats at all, so trusting `standard` at
	 * face value would under-detect media on exactly the sites this source
	 * is supposed to read *more* accurately than the feed connector, not
	 * less.
	 *
	 * @param array<string, mixed> $raw_item One item from fetch()'s raw result.
	 * @return array<string, mixed> Source-agnostic normalized post data.
	 */
	public function normalize( array $raw_item ): array {
		// `.rendered` fields are real HTML, entity-encoded the way a theme
		// would insert them into a page (e.g. a title's apostrophe arrives
		// as `&#8217;`) — unlike Daymark_Subscription_Source_Feed's own
		// title/excerpt, which come from SimplePie accessors that already
		// decode entities during XML parsing. Strip tags first, then decode
		// entities, matching Daymark_Subscription_Source_Microformats::
		// plain_text()'s identical reasoning for the same genuine-HTML case.
		$title = sanitize_text_field( html_entity_decode( wp_strip_all_tags( (string) ( $raw_item['title']['rendered'] ?? '' ) ), ENT_QUOTES ) );

		$excerpt_html = (string) ( $raw_item['excerpt']['rendered'] ?? '' );

		if ( '' === trim( wp_strip_all_tags( $excerpt_html ) ) ) {
			$excerpt_html = (string) ( $raw_item['content']['rendered'] ?? '' );
		}

		$excerpt = sanitize_text_field( wp_trim_words( html_entity_decode( wp_strip_all_tags( $excerpt_html ), ENT_QUOTES ), 40 ) );

		$author = '';

		if ( isset( $raw_item['_embedded']['author'][0]['name'] ) ) {
			$author = sanitize_text_field( html_entity_decode( (string) $raw_item['_embedded']['author'][0]['name'], ENT_QUOTES ) );
		}

		$published_at = $this->sanitize_datetime( (string) ( $raw_item['date_gmt'] ?? '' ) );
		$permalink    = esc_url_raw( (string) ( $raw_item['link'] ?? '' ) );

		$format = sanitize_key( (string) ( $raw_item['format'] ?? 'standard' ) );
		if ( ! in_array( $format, self::DAYMARK_MEDIA_FORMATS, true ) ) {
			$format = 'standard';
		}

		$featured_image_url = '';

		if ( isset( $raw_item['_embedded']['wp:featuredmedia'][0]['source_url'] ) ) {
			$featured_image_url = esc_url_raw( (string) $raw_item['_embedded']['wp:featuredmedia'][0]['source_url'] );
		}

		// The real `format` field is only ever set when the site's theme
		// actually supports and uses post formats — most WordPress sites,
		// especially block themes, never assign one, so every post reports
		// 'standard' regardless of what it actually contains. Rather than
		// trust that silence, fall back to sniffing the post's own rendered
		// content for the same inline-media signal
		// Daymark_Subscription_Source_Feed already looks for, exactly like
		// Daymark_Subscription_Source_Friends does for a cached post with no
		// Friends-assigned format either — a confirmed `format` or a real
		// featured-media embed always wins, this only ever promotes away
		// from an unconfirmed 'standard'.
		if ( 'standard' === $format ) {
			$content_html   = (string) ( $raw_item['content']['rendered'] ?? '' );
			$sniffed        = Daymark_Subscription_Content_Sniffer::sniff( $content_html );
			$sniffed_format = Daymark_Subscription_Content_Sniffer::classify( $sniffed, $content_html );

			if ( '' !== $sniffed_format ) {
				$format = $sniffed_format;
			}

			if ( '' === $featured_image_url && '' !== $sniffed['image_src'] ) {
				$featured_image_url = esc_url_raw( $sniffed['image_src'] );
			}
		}

		return array(
			'title'              => $title,
			'excerpt'            => $excerpt,
			'author'             => $author,
			'published_at'       => $published_at,
			'permalink'          => $permalink,
			'post_format'        => $format,
			'featured_image_url' => $featured_image_url,
			'raw_media'          => '' !== $featured_image_url ? array( $featured_image_url ) : array(),
		);
	}

	/**
	 * Find the WordPress REST API root URL a theme's `<head>` advertises via
	 * `<link rel="https://api.w.org/">` — the same discovery mechanism a
	 * browser or client library uses, and the exact sibling of the
	 * `<link rel="alternate">` feed tag Daymark_Subscription_Source_Feed
	 * already scans for.
	 *
	 * @param string $html     Fetched site HTML.
	 * @param string $site_url Site URL the HTML was fetched from (a relative
	 *                         `href` is resolved against it).
	 * @return string Absolute REST root URL, or '' if not found.
	 */
	private function find_rest_root( string $html, string $site_url ): string {
		foreach ( $this->extract_tags( $this->extract_head_section( $html ), 'link' ) as $tag ) {
			$attrs = $this->parse_tag_attributes( $tag );
			$rel   = strtolower( trim( (string) ( $attrs['rel'] ?? '' ) ) );

			if ( 'https://api.w.org/' !== $rel ) {
				continue;
			}

			$href = trim( (string) ( $attrs['href'] ?? '' ) );

			if ( '' === $href ) {
				continue;
			}

			return esc_url_raw( WP_Http::make_absolute_url( $href, $site_url ) );
		}

		return '';
	}

	/**
	 * Actively confirm `{root}wp/v2/posts` responds with a genuine post
	 * list — some sites leave the `<link rel="https://api.w.org/">`
	 * discovery tag in place while disabling the REST API entirely, or
	 * blocking `wp/v2/posts` specifically (a common security-hardening
	 * choice), so presence of the tag alone is not enough to prefer this
	 * source over the existing feed connector.
	 *
	 * @param string $posts_url The `wp/v2/posts` collection URL to probe.
	 * @return bool True when the endpoint returns a genuine (possibly empty)
	 *              JSON post list.
	 */
	private function probe_posts_endpoint( string $posts_url ): bool {
		$body = $this->fetch_body(
			add_query_arg( 'per_page', 1, $posts_url ),
			self::MAX_FEED_BYTES,
			'daymark_subscription_max_feed_bytes',
			'daymark_subscription_feed_fetch_timeout'
		);

		if ( is_wp_error( $body ) ) {
			return false;
		}

		return is_array( json_decode( $body, true ) );
	}

	/**
	 * Fetch a URL's response body via `wp_safe_remote_get()`, shared by
	 * every fetch this class makes: discover()'s site-HTML scan, its
	 * `wp/v2/posts` reachability probe, and fetch()'s real poll. Generic
	 * on purpose (no `Accept` header forced) since the site-HTML fetch is
	 * not a JSON request at all.
	 *
	 * `wp_safe_remote_get()` rather than `wp_remote_get()` deliberately: the
	 * URL here is derived from a user-supplied site (the site URL entered
	 * at subscribe time) and this same request recurs unattended via the
	 * polling cron for as long as the subscription exists — the same
	 * SSRF-hardening reasoning every other subscription source fetch
	 * documents.
	 *
	 * @param string $url               Already-sanitized, http(s) URL to fetch.
	 * @param int    $default_max_bytes Default response size cap.
	 * @param string $size_filter       Filter name for the size cap.
	 * @param string $timeout_filter    Filter name for the timeout.
	 * @return string|WP_Error Response body, or a WP_Error describing why
	 *                         the fetch failed (network error, non-2xx
	 *                         status, or a response at/beyond the size
	 *                         cap — a truncated body is discarded rather
	 *                         than treated as complete).
	 */
	private function fetch_body( string $url, int $default_max_bytes, string $size_filter, string $timeout_filter ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- every call site passes a literal 'daymark_subscription_*' filter name; this helper is shared by three call sites so the name itself is a parameter, not user input.
		$max_bytes = (int) apply_filters( $size_filter, $default_max_bytes );

		$response = wp_safe_remote_get(
			$url,
			array(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- same as above.
				'timeout'             => (int) apply_filters( $timeout_filter, 10 ),
				'redirection'         => 5,
				'limit_response_size' => $max_bytes,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'daymark_subscription_wp_rest_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The REST API returned HTTP status %d.', 'daymark' ),
					$code
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) >= $max_bytes ) {
			return new WP_Error( 'daymark_subscription_wp_rest_too_large', __( 'The REST API response exceeded the allowed size and was discarded.', 'daymark' ) );
		}

		return $body;
	}

	/**
	 * Find every occurrence of a given tag (opening tag only) in an HTML
	 * fragment. Deliberately mirrors
	 * Daymark_Subscription_Source_Feed::extract_tags() — a lightweight
	 * regex scan, not DOMDocument.
	 *
	 * @param string $section  HTML fragment to search.
	 * @param string $tag_name Tag name, e.g. 'link'.
	 * @return string[] Matched tags, in document order.
	 */
	private function extract_tags( string $section, string $tag_name ): array {
		if ( ! preg_match_all( '#<' . preg_quote( $tag_name, '#' ) . '\b[^>]*>#i', $section, $matches ) ) {
			return array();
		}

		return $matches[0];
	}

	/**
	 * Parse a single tag's attributes into a lowercase-keyed array.
	 * Deliberately mirrors
	 * Daymark_Subscription_Source_Feed::parse_tag_attributes() — a
	 * lightweight regex parser, not DOMDocument.
	 *
	 * @param string $tag Raw tag markup, e.g. `<link rel="..." href="...">`.
	 * @return array<string, string>
	 */
	private function parse_tag_attributes( string $tag ): array {
		$attrs = array();

		if (
			! preg_match_all(
				'/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*"([^"]*)"|([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*\'([^\']*)\'/',
				$tag,
				$matches,
				PREG_SET_ORDER
			)
		) {
			return $attrs;
		}

		foreach ( $matches as $match ) {
			if ( isset( $match[1] ) && '' !== $match[1] ) {
				$attrs[ strtolower( $match[1] ) ] = html_entity_decode( $match[2], ENT_QUOTES );
			} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
				$attrs[ strtolower( $match[3] ) ] = html_entity_decode( $match[4], ENT_QUOTES );
			}
		}

		return $attrs;
	}

	/**
	 * Extract the `<head>...</head>` section of an HTML document, falling
	 * back to the whole document when no `<head>` tag is found. Deliberately
	 * mirrors Daymark_Subscription_Source_Feed::extract_head_section().
	 *
	 * @param string $html Fetched HTML.
	 * @return string
	 */
	private function extract_head_section( string $html ): string {
		if ( preg_match( '#<head[^>]*>(.*?)</head>#is', $html, $matches ) ) {
			return $matches[1];
		}

		return $html;
	}

	/**
	 * Extract a document's plain `<title>` tag text. Deliberately mirrors
	 * Daymark_Subscription_Source_Feed::extract_site_title().
	 *
	 * @param string $html Fetched page HTML.
	 * @return string
	 */
	private function extract_site_title( string $html ): string {
		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', $this->extract_head_section( $html ), $matches ) ) {
			return '';
		}

		$title = html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES );

		return sanitize_text_field( trim( $title ) );
	}

	/**
	 * Fetch a URL's response body for the `discover()` HTML scan
	 * specifically.
	 *
	 * Delegates to Daymark_Subscription_Html_Cache (issue #137), a static,
	 * request-scoped cache shared across every built-in subscription
	 * source's discovery-time homepage fetch — this source is always
	 * registered first (see Daymark_Subscription_Source_Registry), so
	 * without this shared cache, every site this source doesn't end up
	 * matching would still cost a live request before the feed/microformats
	 * sources ever get a chance to try the same URL.
	 *
	 * @param string $url Already-sanitized, http(s) URL to fetch.
	 * @return string Response body, or '' on any failure (including a
	 *                response that hit the size cap).
	 */
	private function fetch_html( string $url ): string {
		$body = Daymark_Subscription_Html_Cache::fetch( $url );

		return is_wp_error( $body ) ? '' : $body;
	}

	/**
	 * Sanitize a date string (a `date_gmt` value, always
	 * `Y-m-d\TH:i:s` with no offset) into a MySQL datetime, tolerating
	 * anything `strtotime()` can parse. Matches
	 * Daymark_Subscription_Source_Feed::sanitize_datetime()'s own logic:
	 * Daymark_Subscription_Post_Type::sanitize_datetime() (the ingest-side
	 * sanitizer normalize() output ultimately passes through) only accepts
	 * an already-MySQL-shaped string, so the ISO 8601 shape must be
	 * converted here or it would be silently dropped downstream.
	 *
	 * @param string $value Raw date string.
	 * @return string MySQL datetime, or '' if undeterminable.
	 */
	private function sanitize_datetime( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Validate a user-supplied site URL for safety: `http`/`https` scheme,
	 * and — per issue #81's SSRF hardening — a host that does not resolve to
	 * a private/internal/reserved address, no embedded userinfo, and a
	 * standard port (see Daymark_Subscription_Url_Guard).
	 *
	 * @param string $url Raw URL.
	 * @return true|WP_Error True when safe to fetch; WP_Error with a
	 *                       specific, human-readable rejection reason
	 *                       otherwise.
	 */
	private function validate_source_url( string $url ) {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			return new WP_Error( 'daymark_subscription_invalid_url', __( 'Invalid URL.', 'daymark' ) );
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'daymark_subscription_invalid_url', __( 'Invalid URL.', 'daymark' ) );
		}

		return Daymark_Subscription_Url_Guard::check( $url );
	}

	/**
	 * Sanitize and validate a user-supplied site URL: reject anything that
	 * is not `http`/`https`, or that fails the SSRF guard
	 * (Daymark_Subscription_Url_Guard), before it is ever used in a remote
	 * request.
	 *
	 * @param string $url Raw URL.
	 * @return string Sanitized URL, or '' if invalid or unsafe.
	 */
	private function sanitize_source_url( string $url ): string {
		if ( is_wp_error( $this->validate_source_url( $url ) ) ) {
			return '';
		}

		return esc_url_raw( trim( $url ) );
	}
}
