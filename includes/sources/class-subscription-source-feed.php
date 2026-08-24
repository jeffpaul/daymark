<?php
/**
 * The built-in RSS/Atom feed subscription source.
 *
 * Discovers a site's feed via `<link rel="alternate">` autodiscovery, fetches
 * and parses it via WordPress core's bundled SimplePie (`fetch_feed()`), and
 * normalizes items into Daymark's source-agnostic subscription post shape.
 * Also resolves a site's favicon at subscribe time (a separate, related
 * one-time lookup that reuses the same site-HTML fetch as feed
 * autodiscovery rather than issuing a second request).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RSS/Atom feed source: `Daymark_Subscription_Source` built on SimplePie.
 */
class Daymark_Subscription_Source_Feed implements Daymark_Subscription_Source {

	/**
	 * MIME types treated as an RSS/Atom feed link during autodiscovery.
	 *
	 * @var string[]
	 */
	private const FEED_LINK_TYPES = array( 'application/rss+xml', 'application/atom+xml' );

	/**
	 * Per-request cache of fetched site HTML, keyed by URL, so a subscribe
	 * flow that calls discover() and then get_favicon_url() for the same
	 * site only issues one HTTP request.
	 *
	 * @var array<string, string>
	 */
	private array $html_cache = array();

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'feed';
	}

	/**
	 * Source label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'RSS/Atom Feed', 'daymark' );
	}

	/**
	 * Discover the feed(s) advertised by a site via `<link rel="alternate">`
	 * autodiscovery against its `<head>`.
	 *
	 * When more than one feed is found, they are returned in order of
	 * preference (the caller's likely pick is index 0), resolved by:
	 *
	 * 1. Shortest/most root-level path (`/feed/` beats `/category/x/feed/`
	 *    or `/feed/comments/`).
	 * 2. Where path depth ties, WordPress's default `"{Site Name} » Feed"`
	 *    title convention beats a title carrying a category/tag name or the
	 *    word "Comments".
	 * 3. Where still tied, document order.
	 *
	 * @param string $site_url Site URL entered by the user (not a feed URL).
	 * @return array<int, array{url: string, title: string, type: string}> Discovered
	 *                                                                     feeds, most
	 *                                                                     preferred
	 *                                                                     first; empty
	 *                                                                     when none
	 *                                                                     found.
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

		$candidates = $this->find_feed_links( $html, $site_url );

		if ( array() === $candidates ) {
			return array();
		}

		return $this->rank_feed_candidates( $candidates );
	}

	/**
	 * Fetch and parse a feed via SimplePie.
	 *
	 * Returns a list of raw item arrays, each built from SimplePie's own
	 * accessors (title, permalink, author, date, content, description,
	 * enclosures) — the shape normalize() knows how to consume, but not yet
	 * standardized into Daymark's source-agnostic post shape.
	 *
	 * An empty array is a genuinely successful fetch of a feed with no
	 * current items — distinct from a WP_Error, which means the feed itself
	 * could not be reached or parsed. The poller's dead-feed detection
	 * depends on this distinction: collapsing "empty" into "failed" would
	 * mark a quiet-but-healthy blog dead after enough poll cycles.
	 *
	 * @param string $feed_url Feed URL to fetch.
	 * @return array<int, array<string, mixed>>|WP_Error Raw items (possibly
	 *                                                    empty), or a
	 *                                                    WP_Error when the
	 *                                                    feed could not be
	 *                                                    reached or parsed.
	 */
	public function fetch( string $feed_url ): array|WP_Error {
		$feed_url = $this->sanitize_source_url( $feed_url );

		if ( '' === $feed_url ) {
			return new WP_Error(
				'daymark_subscription_invalid_feed_url',
				__( 'Invalid feed URL.', 'daymark' )
			);
		}

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		// fetch_feed() itself goes through SimplePie's WP_SimplePie_File,
		// which wraps WP's own HTTP API — never a raw remote fetch.
		$feed = fetch_feed( $feed_url );

		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$items     = $feed->get_items( 0, $feed->get_item_quantity() );
		$raw_items = array();

		foreach ( $items as $item ) {
			$raw_items[] = $this->extract_raw_item( $item );
		}

		return $raw_items;
	}

	/**
	 * Map one raw SimplePie-derived item to Daymark's source-agnostic post
	 * shape:
	 *
	 * - `title` (plain text)
	 * - `excerpt` (short plain-text summary)
	 * - `author` (plain text)
	 * - `published_at` (MySQL datetime string, or '' if undeterminable)
	 * - `permalink` (the item's own URL on the source site)
	 * - `post_format` (`image`|`video`|`audio`|`gallery`|`standard`, guessed
	 *   from enclosures)
	 * - `featured_image_url` (best available image URL, or '')
	 * - `raw_media` (enclosure URLs, for later embed/oEmbed resolution —
	 *   never resolved here)
	 *
	 * Every string field is sanitized; this is untrusted external input.
	 *
	 * @param array<string, mixed> $raw_item One item from fetch()'s raw result.
	 * @return array<string, mixed> Source-agnostic normalized post data.
	 */
	public function normalize( array $raw_item ): array {
		$title        = sanitize_text_field( wp_strip_all_tags( (string) ( $raw_item['title'] ?? '' ) ) );
		$author       = sanitize_text_field( (string) ( $raw_item['author'] ?? '' ) );
		$permalink    = esc_url_raw( (string) ( $raw_item['permalink'] ?? '' ) );
		$published_at = $this->sanitize_datetime( (string) ( $raw_item['date'] ?? '' ) );

		$description = (string) ( $raw_item['description'] ?? '' );

		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
			$description = (string) ( $raw_item['content'] ?? '' );
		}

		$excerpt = sanitize_text_field( wp_trim_words( $description, 40 ) );

		$enclosures = is_array( $raw_item['enclosures'] ?? null ) ? $raw_item['enclosures'] : array();

		$raw_media          = array();
		$featured_image_url = '';
		$has_video          = false;
		$has_audio          = false;
		$image_count        = 0;

		foreach ( $enclosures as $enclosure ) {
			$url = esc_url_raw( (string) ( $enclosure['url'] ?? '' ) );

			if ( '' === $url ) {
				continue;
			}

			$raw_media[] = $url;

			$medium = strtolower( (string) ( $enclosure['medium'] ?? '' ) );
			$type   = strtolower( (string) ( $enclosure['type'] ?? '' ) );

			if ( 'video' === $medium || str_starts_with( $type, 'video/' ) ) {
				$has_video = true;
			} elseif ( 'audio' === $medium || str_starts_with( $type, 'audio/' ) ) {
				$has_audio = true;
			} elseif ( 'image' === $medium || str_starts_with( $type, 'image/' ) ) {
				++$image_count;

				if ( '' === $featured_image_url ) {
					$featured_image_url = $url;
				}
			}
		}

		if ( $has_video ) {
			$post_format = 'video';
		} elseif ( $has_audio ) {
			$post_format = 'audio';
		} elseif ( $image_count > 1 ) {
			$post_format = 'gallery';
		} elseif ( $image_count > 0 ) {
			$post_format = 'image';
		} else {
			$post_format = 'standard';
		}

		return array(
			'title'              => $title,
			'excerpt'            => $excerpt,
			'author'             => $author,
			'published_at'       => $published_at,
			'permalink'          => $permalink,
			'post_format'        => $post_format,
			'featured_image_url' => $featured_image_url,
			'raw_media'          => $raw_media,
		);
	}

	/**
	 * Resolve a site's favicon: an explicit `<link rel="icon">` in its
	 * `<head>`, falling back to `{scheme}://{host}/favicon.ico` when no
	 * explicit link is found. The fallback URL is not verified to resolve to
	 * a real image — a one-time best-effort lookup at subscribe time.
	 *
	 * Reuses the same site-HTML fetch discover() would make for the same
	 * URL rather than issuing a second request.
	 *
	 * @param string $site_url Site URL.
	 * @return string Favicon URL, or '' if the site URL itself is invalid.
	 */
	public function get_favicon_url( string $site_url ): string {
		$site_url = $this->sanitize_source_url( $site_url );

		if ( '' === $site_url ) {
			return '';
		}

		$html = $this->fetch_html( $site_url );

		if ( '' !== $html ) {
			$icon = $this->find_icon_link( $html, $site_url );

			if ( '' !== $icon ) {
				return $icon;
			}
		}

		return $this->fallback_favicon_url( $site_url );
	}

	/**
	 * Build the raw, SimplePie-shaped item array fetch() returns per item.
	 *
	 * @param SimplePie_Item $item A parsed feed item.
	 * @return array<string, mixed>
	 */
	private function extract_raw_item( SimplePie_Item $item ): array {
		$author = $item->get_author();

		return array(
			'title'       => (string) $item->get_title(),
			'permalink'   => (string) $item->get_permalink(),
			'author'      => $author ? (string) $author->get_name() : '',
			'date'        => (string) $item->get_date( 'Y-m-d H:i:s' ),
			'content'     => (string) $item->get_content(),
			'description' => (string) $item->get_description(),
			'enclosures'  => $this->extract_enclosures( $item ),
		);
	}

	/**
	 * Build the raw enclosure array list for one item.
	 *
	 * @param SimplePie_Item $item A parsed feed item.
	 * @return array<int, array{url: string, type: string, medium: string}>
	 */
	private function extract_enclosures( SimplePie_Item $item ): array {
		$enclosures = $item->get_enclosures();

		if ( ! is_array( $enclosures ) ) {
			return array();
		}

		$raw = array();

		foreach ( $enclosures as $enclosure ) {
			$raw[] = array(
				'url'    => (string) $enclosure->get_link(),
				'type'   => (string) $enclosure->get_type(),
				'medium' => (string) $enclosure->get_medium(),
			);
		}

		return $raw;
	}

	/**
	 * Find every `<link rel="alternate">` feed link in a site's `<head>`.
	 *
	 * @param string $html     Fetched site HTML.
	 * @param string $site_url Site URL the HTML was fetched from (relative
	 *                         `href` values are resolved against it).
	 * @return array<int, array{url: string, title: string, type: string}>
	 */
	private function find_feed_links( string $html, string $site_url ): array {
		$candidates = array();

		foreach ( $this->extract_tags( $this->extract_head_section( $html ), 'link' ) as $tag ) {
			$attrs = $this->parse_tag_attributes( $tag );
			$rel   = strtolower( (string) ( $attrs['rel'] ?? '' ) );
			$type  = strtolower( (string) ( $attrs['type'] ?? '' ) );

			if ( ! in_array( 'alternate', preg_split( '/\s+/', $rel ), true ) ) {
				continue;
			}

			if ( ! in_array( $type, self::FEED_LINK_TYPES, true ) ) {
				continue;
			}

			$href = $this->resolve_href( (string) ( $attrs['href'] ?? '' ), $site_url );

			if ( '' === $href ) {
				continue;
			}

			$candidates[] = array(
				'url'   => $href,
				'title' => sanitize_text_field( (string) ( $attrs['title'] ?? '' ) ),
				'type'  => $type,
			);
		}

		return $candidates;
	}

	/**
	 * Find the first `<link rel="icon">` in a site's `<head>`.
	 *
	 * @param string $html     Fetched site HTML.
	 * @param string $site_url Site URL the HTML was fetched from.
	 * @return string Icon URL, or '' if none is found.
	 */
	private function find_icon_link( string $html, string $site_url ): string {
		foreach ( $this->extract_tags( $this->extract_head_section( $html ), 'link' ) as $tag ) {
			$attrs = $this->parse_tag_attributes( $tag );
			$rel   = strtolower( (string) ( $attrs['rel'] ?? '' ) );

			if ( ! in_array( 'icon', preg_split( '/\s+/', $rel ), true ) ) {
				continue;
			}

			$href = $this->resolve_href( (string) ( $attrs['href'] ?? '' ), $site_url );

			if ( '' !== $href ) {
				return $href;
			}
		}

		return '';
	}

	/**
	 * The `/favicon.ico`-at-the-root fallback for a site with no explicit
	 * `<link rel="icon">`.
	 *
	 * @param string $site_url Site URL.
	 * @return string Fallback favicon URL, or '' if the site URL has no host.
	 */
	private function fallback_favicon_url( string $site_url ): string {
		$scheme = (string) wp_parse_url( $site_url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $site_url, PHP_URL_HOST );

		if ( '' === $scheme || '' === $host ) {
			return '';
		}

		$port = wp_parse_url( $site_url, PHP_URL_PORT );

		return esc_url_raw( $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . '/favicon.ico' );
	}

	/**
	 * Resolve a `<link>` tag's `href` (possibly relative) against the site
	 * URL it was found on, and sanitize the result.
	 *
	 * @param string $href     Raw `href` attribute value.
	 * @param string $site_url Site URL the tag was found on.
	 * @return string Absolute, sanitized URL, or '' if `href` was empty or
	 *                did not resolve to a usable URL.
	 */
	private function resolve_href( string $href, string $site_url ): string {
		if ( '' === $href ) {
			return '';
		}

		return esc_url_raw( WP_Http::make_absolute_url( $href, $site_url ) );
	}

	/**
	 * Rank discovered feed candidates by the main-feed heuristic (path
	 * depth, then title convention, then document order) and return them
	 * most-preferred first.
	 *
	 * @param array<int, array{url: string, title: string, type: string}> $candidates Discovered feed links, in document order.
	 * @return array<int, array{url: string, title: string, type: string}>
	 */
	private function rank_feed_candidates( array $candidates ): array {
		$scored = array();

		foreach ( array_values( $candidates ) as $index => $candidate ) {
			$scored[] = array(
				'candidate' => $candidate,
				'depth'     => $this->path_depth( $candidate['url'] ),
				'tier'      => $this->is_default_feed_title( $candidate['title'] ) ? 1 : 0,
				'order'     => $index,
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				if ( $a['depth'] !== $b['depth'] ) {
					return $a['depth'] <=> $b['depth'];
				}

				if ( $a['tier'] !== $b['tier'] ) {
					return $b['tier'] <=> $a['tier'];
				}

				return $a['order'] <=> $b['order'];
			}
		);

		return array_map(
			static fn( array $entry ): array => $entry['candidate'],
			$scored
		);
	}

	/**
	 * Count of non-empty path segments in a URL, used to rank feed links by
	 * how root-level they are (`/feed/` = 1, `/category/x/feed/` = 3).
	 *
	 * @param string $url URL to inspect.
	 * @return int
	 */
	private function path_depth( string $url ): int {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

		if ( '' === $path ) {
			return 0;
		}

		return count( array_filter( explode( '/', $path ), static fn( string $segment ): bool => '' !== $segment ) );
	}

	/**
	 * Whether a feed link's title matches WordPress's default
	 * `"{Site Name} » Feed"` convention (as opposed to a title carrying a
	 * category/tag name or the word "Comments").
	 *
	 * @param string $title Feed link title attribute.
	 * @return bool
	 */
	private function is_default_feed_title( string $title ): bool {
		$title = trim( $title );

		if ( '' === $title ) {
			return false;
		}

		return (bool) preg_match( '/\x{00BB}\s*Feed\s*$/iu', $title );
	}

	/**
	 * Extract the `<head>...</head>` section of an HTML document, falling
	 * back to the whole document when no `<head>` tag is found.
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
	 * Extract every occurrence of a given tag (opening tag only, e.g.
	 * `<link ...>`) from an HTML fragment.
	 *
	 * @param string $section    HTML fragment to search.
	 * @param string $tag_name   Tag name, e.g. 'link'.
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
	 *
	 * Deliberately a lightweight regex parser rather than DOMDocument: this
	 * only ever needs to read `<link>` attributes out of a `<head>`
	 * fragment, and avoids taking on a hard dependency on the DOM extension.
	 *
	 * @param string $tag Raw tag markup, e.g. `<link rel="alternate" ...>`.
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
	 * Fetch a URL's response body via `wp_safe_remote_get()`, memoized per
	 * URL for the lifetime of this instance so discover() and
	 * get_favicon_url() can share one fetch for the same site.
	 *
	 * `wp_safe_remote_get()` rather than `wp_remote_get()` deliberately: the
	 * URL here is user-supplied (the site URL entered at subscribe time)
	 * and this same request recurs unattended via the polling cron for as
	 * long as the subscription exists, so it gets the same SSRF-hardening
	 * (blocking loopback/private/reserved IP ranges) WordPress core itself
	 * uses for feed fetching via fetch_feed().
	 *
	 * @param string $url Already-sanitized, http(s) URL to fetch.
	 * @return string Response body, or '' on any failure.
	 */
	private function fetch_html( string $url ): string {
		if ( array_key_exists( $url, $this->html_cache ) ) {
			return $this->html_cache[ $url ];
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'user-agent'  => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->html_cache[ $url ] = '';

			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$this->html_cache[ $url ] = '';

			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );

		$this->html_cache[ $url ] = $body;

		return $body;
	}

	/**
	 * Sanitize and validate a user-supplied site or feed URL: reject
	 * anything that is not `http`/`https` before it is ever used in a
	 * remote request.
	 *
	 * @param string $url Raw URL.
	 * @return string Sanitized URL, or '' if invalid.
	 */
	private function sanitize_source_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize a date string (as SimplePie or a click-through fetch might
	 * produce it) into a MySQL datetime, tolerating anything `strtotime()`
	 * can parse rather than only the exact `Y-m-d H:i:s` shape.
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
}
