<?php
/**
 * Optional Parse This integration.
 *
 * Parse This (https://wordpress.org/plugins/parse-this/, by David Shanske)
 * turns a page into jf2: microformats2 first, then JSON-LD, Open Graph and
 * other meta tags. When it is active, Daymark uses it in two places:
 *
 *  - Link previews (Daymark_Subscription_Opengraph): the page Daymark
 *    already fetched is parsed again by Parse This, which adds the author,
 *    the publish date, and JSON-LD/mf2 values a page's meta tags lack.
 *  - The microformats2 subscription source: an h-feed page Daymark already
 *    fetched is parsed by Parse This's full mf2 parser instead of Daymark's
 *    own minimal scanner.
 *
 * Every call here parses HTML Daymark has already fetched itself through
 * Daymark_Outbound_Guard. Parse This never fetches the page. Its own extra
 * requests (author pages, short-link expansion, a WordPress REST fallback)
 * are turned off for the call, and the call still runs inside
 * Daymark_Outbound_Guard::run() in case a future Parse This version adds a
 * request this class does not know about.
 *
 * Without Parse This, or with a version older than 2.0.0, available()
 * is false and every caller keeps its own parser. Nothing here is required.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, defensive wrapper around Parse This 2.x.
 */
final class Daymark_Parse_This {

	/**
	 * Oldest Parse This with the namespaced API this class calls.
	 *
	 * @var string
	 */
	public const MIN_VERSION = '2.0.0';

	/**
	 * The plugin's wordpress.org slug and installed folder name.
	 *
	 * @var string
	 */
	public const SLUG = 'parse-this';

	/**
	 * Whether a usable Parse This (2.0.0 or later) is loaded.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		$available = defined( 'PARSE_THIS_VERSION' )
			&& version_compare( (string) PARSE_THIS_VERSION, self::MIN_VERSION, '>=' )
			&& class_exists( 'ParseThis\\Parser' );

		/**
		 * Filters whether Daymark uses Parse This when it is active.
		 *
		 * Return false to keep Daymark's own parsers even with Parse This
		 * installed.
		 *
		 * @since 0.20.0
		 *
		 * @param bool $available Whether Parse This 2.0.0 or later is loaded.
		 */
		return (bool) apply_filters( 'daymark_use_parse_this', $available ) && class_exists( 'ParseThis\\Parser' );
	}

	/**
	 * Parse already-fetched HTML into jf2.
	 *
	 * @param string $html   Page HTML Daymark already fetched.
	 * @param string $url    The URL the HTML came from (for relative links).
	 * @param string $format 'single' for the page's main item, 'feed' for a list.
	 * @return array<string, mixed>|null jf2, or null when Parse This is not
	 *                                    available or the parse failed.
	 */
	public static function parse_html( string $html, string $url, string $format = 'single' ): ?array {
		if ( '' === trim( $html ) || ! self::available() ) {
			return null;
		}

		$args = array(
			'return'          => 'feed' === $format ? 'feed' : 'single',
			'follow'          => false,
			// Nested citations become plain URLs, the shape Daymark stores.
			'references'      => true,
			// A summary is enough: never fetch the page's REST API version.
			'require_content' => false,
			'always_arrays'   => true,
			'limit'           => 50,
		);

		// No author pages, no short-link expansion: zero extra requests.
		$no_requests = static function () {
			return 0;
		};

		add_filter( 'parse_this_max_requests', $no_requests, PHP_INT_MAX );

		try {
			$jf2 = Daymark_Outbound_Guard::run(
				static function () use ( $html, $url, $args ) {
					$parser = new \ParseThis\Parser();
					$parser->set( $html, $url );
					$result = $parser->parse( $args );

					return is_wp_error( $result ) ? null : $parser->get();
				}
			);
		} catch ( Throwable $e ) {
			// A bug in a third-party parser must never break a poll or a preview.
			$jf2 = null;
		} finally {
			remove_filter( 'parse_this_max_requests', $no_requests, PHP_INT_MAX );
		}

		return is_array( $jf2 ) && ! empty( $jf2 ) ? $jf2 : null;
	}

	/**
	 * First plain-text value of a jf2 property.
	 *
	 * A jf2 value may be a string, a list (with always_arrays), or an object
	 * such as `content` (`{html, text}`) or a card (`{name, url}`).
	 *
	 * @param array<string, mixed> $item jf2 object.
	 * @param string               $key  Property name.
	 * @return string
	 */
	public static function text( array $item, string $key ): string {
		$value = $item[ $key ] ?? '';

		if ( is_array( $value ) && array_is_list( $value ) ) {
			$value = $value[0] ?? '';
		}

		if ( is_array( $value ) ) {
			$value = $value['text'] ?? ( $value['name'] ?? ( $value['value'] ?? '' ) );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Every http(s) URL in a jf2 property, in order.
	 *
	 * Handles a plain URL, a list of URLs, and objects carrying `url`
	 * (a cited post) or `value` (an image with alt text).
	 *
	 * Relative URLs are resolved against $base_url here: Parse This only
	 * resolves them when the page's host passes wp_http_validate_url(),
	 * which needs a working DNS lookup.
	 *
	 * @param array<string, mixed> $item     jf2 object.
	 * @param string               $key      Property name.
	 * @param string               $base_url URL of the parsed page, for relative URLs.
	 * @return string[]
	 */
	public static function urls( array $item, string $key, string $base_url = '' ): array {
		$value  = $item[ $key ] ?? array();
		$values = is_array( $value ) && array_is_list( $value ) ? $value : array( $value );
		$urls   = array();

		foreach ( $values as $entry ) {
			if ( is_array( $entry ) ) {
				$entry = $entry['url'] ?? ( $entry['value'] ?? '' );

				if ( is_array( $entry ) ) {
					$entry = $entry[0] ?? '';
				}
			}

			$entry = is_string( $entry ) ? trim( $entry ) : '';

			if ( '' !== $entry && '' !== $base_url && '' === (string) wp_parse_url( $entry, PHP_URL_SCHEME ) ) {
				$entry = WP_Http::make_absolute_url( $entry, $base_url );
			}

			$scheme = strtolower( (string) wp_parse_url( $entry, PHP_URL_SCHEME ) );

			if ( '' !== $entry && in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$urls[] = esc_url_raw( $entry );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * The author's name from a jf2 entry (`author` is always a card, or a
	 * list of cards, in Parse This 2.x).
	 *
	 * @param array<string, mixed> $item jf2 object.
	 * @return string
	 */
	public static function author_name( array $item ): string {
		$author = $item['author'] ?? null;

		if ( is_array( $author ) && array_is_list( $author ) ) {
			$author = $author[0] ?? null;
		}

		if ( is_array( $author ) ) {
			return sanitize_text_field( self::text( $author, 'name' ) );
		}

		return is_string( $author ) && ! wp_http_validate_url( $author ) ? sanitize_text_field( $author ) : '';
	}

	/**
	 * The HTML of a jf2 entry's `content`, or ''.
	 *
	 * Parse This limits it to a safe tag set; callers still run their own
	 * sanitizing on it, as for any remote HTML.
	 *
	 * @param array<string, mixed> $item jf2 object.
	 * @return string
	 */
	public static function content_html( array $item ): string {
		$content = $item['content'] ?? '';

		if ( is_array( $content ) && array_is_list( $content ) ) {
			$content = $content[0] ?? '';
		}

		if ( is_array( $content ) ) {
			$content = $content['html'] ?? ( $content['text'] ?? '' );
		}

		return is_string( $content ) ? $content : '';
	}
}
