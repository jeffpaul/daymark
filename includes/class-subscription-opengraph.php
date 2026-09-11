<?php
/**
 * Read-time Open Graph link-preview resolution for a subscription post's own
 * detected outbound link (`link_url`, see Daymark_Subscription_Content_Sniffer::sniff()
 * and Daymark_Subscription_Poller::maybe_ingest_item()) — issue #349.
 *
 * Daymark_Subscription_Oembed (issue #279) already resolves an oEmbed
 * preview for the same link, but per its own docblock a "link"-type oEmbed
 * response — no iframe or img, the shape most ordinary article links
 * produce, since they have no oEmbed endpoint at all — "resolves to nothing
 * rather than a broken, script-less blockquote." Most ordinary web pages
 * carry Open Graph (or Twitter Card) meta tags a title/excerpt/image
 * preview can be built from directly, with no provider-specific oEmbed
 * endpoint required — this class fills exactly that gap. It is tried first
 * (see Daymark_REST_Controller::get_subscription_post_oembed()); the
 * existing oEmbed resolver stays the fallback for a genuine media provider
 * (YouTube, Mastodon, etc.) whose real embeddable markup Open Graph tags
 * alone can't reconstruct.
 *
 * Mirrors Daymark_Subscription_Oembed's own conventions throughout — SSRF
 * guard, response-size cap, timeout, URL-keyed transient caching including
 * a cached failure — rather than inventing a second set for the same shape
 * of problem. Extracted image/title/description text is sanitized before
 * ever reaching the client; the image URL itself only needs the same
 * scheme check Daymark_Subscription_Oembed::safe_src() already applies to
 * an extracted `src`, not the outbound SSRF guard — it is rendered as a
 * plain `<img src>` the visitor's own browser fetches, never a URL this
 * server itself requests.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static Open Graph link-preview resolver for a subscription post's detected link.
 */
class Daymark_Subscription_Opengraph {

	/**
	 * Default maximum page-fetch size, in bytes — generous for an ordinary
	 * HTML page's `<head>`, without downloading an entire large page body
	 * just to read a handful of meta tags. Filterable via
	 * `daymark_subscription_opengraph_max_bytes`.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 512 * 1024; // 512 KB.

	/**
	 * Default cache TTL for a resolved (or failed) lookup, in seconds.
	 * Filterable via `daymark_subscription_opengraph_cache_ttl`. A failure
	 * is cached too — an unembeddable/tagless link stays that way — so a
	 * repeatedly-viewed post never re-fetches the same page on every open.
	 *
	 * @var int
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Defensive length caps on extracted text — a malformed or hostile page
	 * could otherwise hand back an implausibly long "title"/"description".
	 * `sanitize_text_field()` already collapses whitespace/strips tags; this
	 * bounds the result on top of that.
	 *
	 * @var int
	 */
	private const MAX_TITLE_LENGTH = 200;

	/**
	 * Word count an extracted description is trimmed to, matching
	 * Daymark_Subscription_Source_Feed::normalize()'s own excerpt length so
	 * a link preview's description reads the same as any other card's
	 * excerpt.
	 *
	 * @var int
	 */
	private const DESCRIPTION_WORDS = 40;

	/**
	 * Resolve an Open Graph link preview for a URL, cached by URL for
	 * CACHE_TTL.
	 *
	 * Never throws and never blocks — any failure (an unsafe URL, a network
	 * error, a non-200 response, a page with no usable title) resolves to
	 * an empty array, exactly like "no preview available" rather than an
	 * error the caller has to branch on.
	 *
	 * @param string $url Candidate link URL (e.g. a subscription post's own `link_url` meta).
	 * @return array{type: string, title: string, description: string, image: string}
	 *               'type' is always 'link'; empty array when no usable
	 *               preview was found.
	 */
	public static function resolve( string $url ): array {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			return array();
		}

		$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array();
		}

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $url ) ) ) {
			return array();
		}

		$cache_key = 'daymark_og_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::fetch_and_extract( $url );

		set_transient(
			$cache_key,
			$result,
			/**
			 * Filters how long a resolved (or failed) Open Graph lookup is cached, in seconds.
			 *
			 * @since 0.16.0
			 *
			 * @param int $seconds Defaults to DAY_IN_SECONDS.
			 */
			(int) apply_filters( 'daymark_subscription_opengraph_cache_ttl', self::CACHE_TTL )
		);

		return $result;
	}

	/**
	 * Fetch the page and reduce it to a preview.
	 *
	 * @param string $url Already URL-guard-checked, http(s) URL.
	 * @return array{type: string, title: string, description: string, image: string}
	 *               See resolve()'s own return contract.
	 */
	private static function fetch_and_extract( string $url ): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				/**
				 * Filters the HTTP timeout, in seconds, used when resolving an Open Graph preview.
				 *
				 * @since 0.16.0
				 *
				 * @param int $seconds Defaults to 8.
				 */
				'timeout'             => (int) apply_filters( 'daymark_subscription_opengraph_fetch_timeout', 8 ),
				/**
				 * Filters the maximum page size, in bytes, this class will download.
				 *
				 * @since 0.16.0
				 *
				 * @param int $max_bytes Defaults to 512 KB.
				 */
				'limit_response_size' => (int) apply_filters( 'daymark_subscription_opengraph_max_bytes', self::MAX_RESPONSE_BYTES ),
				'redirection'         => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( '' === trim( $html ) ) {
			return array();
		}

		return self::extract_preview( $html, $url );
	}

	/**
	 * Extract a title/description/image preview from a page's `<meta>` tags
	 * — Open Graph first, Twitter Card as a fallback for each field
	 * individually, then a plain `<title>`/`<meta name="description">` as
	 * the final fallback for a page with neither. `<meta>` tags carry
	 * everything this needs in their own attributes (no inner text to
	 * read), so — matching Daymark_Subscription_Oembed::extract_safe_embed()'s
	 * own established use of WP_HTML_Tag_Processor for exactly this
	 * "iterate tags, read attributes" shape — this walks the raw HTML with
	 * the tag processor rather than a regex scan.
	 *
	 * @param string $html     Fetched page HTML.
	 * @param string $base_url The page's own URL, used only to resolve a
	 *                         relative og:image path to an absolute one.
	 * @return array{type: string, title: string, description: string, image: string}
	 *               See resolve()'s own return contract.
	 */
	private static function extract_preview( string $html, string $base_url ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$title                = '';
		$description          = '';
		$image                = '';
		$image_from_secure    = false;
		$title_from_twitter   = '';
		$description_fallback = '';

		try {
			$processor = new WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag() ) {
				if ( 'META' !== $processor->get_tag() ) {
					continue;
				}

				$property = strtolower( trim( (string) ( $processor->get_attribute( 'property' ) ?? '' ) ) );
				$name     = strtolower( trim( (string) ( $processor->get_attribute( 'name' ) ?? '' ) ) );
				$content  = (string) ( $processor->get_attribute( 'content' ) ?? '' );

				if ( '' === $content ) {
					continue;
				}

				if ( '' === $title && 'og:title' === $property ) {
					$title = $content;
				} elseif ( '' === $title_from_twitter && 'twitter:title' === $name ) {
					$title_from_twitter = $content;
				}

				if ( '' === $description && 'og:description' === $property ) {
					$description = $content;
				} elseif ( '' === $description_fallback && ( 'twitter:description' === $name || 'description' === $name ) ) {
					$description_fallback = $content;
				}

				if ( 'og:image:secure_url' === $property ) {
					$image             = $content;
					$image_from_secure = true;
				} elseif ( ! $image_from_secure && 'og:image' === $property ) {
					$image = $content;
				} elseif ( '' === $image && 'twitter:image' === $name ) {
					$image = $content;
				}
			}
		} catch ( Throwable $e ) {
			return array();
		}

		if ( '' === $title ) {
			$title = '' !== $title_from_twitter ? $title_from_twitter : self::extract_title_tag( $html );
		}

		if ( '' === $description ) {
			$description = $description_fallback;
		}

		$title = self::clean_text( $title, self::MAX_TITLE_LENGTH );

		// A page with no usable title has nothing worth showing as a link
		// preview — matching the existing oEmbed resolver's own "nothing
		// safely re-emittable" empty-array contract.
		if ( '' === $title ) {
			return array();
		}

		return array(
			'type'        => 'link',
			'title'       => $title,
			'description' => self::clean_description( $description ),
			'image'       => self::safe_image_url( $image, $base_url ),
		);
	}

	/**
	 * Fallback for a page with no og:title/twitter:title: the document's
	 * plain `<title>` text. A `<title>` element's inner text isn't
	 * something WP_HTML_Tag_Processor exposes directly (it reads tag
	 * attributes, not text content between an opening and closing tag), so
	 * this reuses the same lightweight regex approach
	 * Daymark_Subscription_Source_Feed::extract_site_title() already
	 * established for the identical "read a page's own <title>" need.
	 *
	 * @param string $html Fetched page HTML.
	 * @return string Plain-text title, or '' when none found.
	 */
	private static function extract_title_tag( string $html ): string {
		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $matches ) ) {
			return '';
		}

		return html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES );
	}

	/**
	 * Sanitize and length-cap a single-line text field (title).
	 *
	 * @param string $text   Raw extracted text.
	 * @param int    $length Maximum character length.
	 * @return string
	 */
	private static function clean_text( string $text, int $length ): string {
		$text = sanitize_text_field( html_entity_decode( $text, ENT_QUOTES ) );

		if ( strlen( $text ) > $length ) {
			$text = substr( $text, 0, $length );
		}

		return trim( $text );
	}

	/**
	 * Sanitize and trim a multi-word description, matching the word-count
	 * excerpt length every other subscription-post description already
	 * uses (Daymark_Subscription_Source_Feed::normalize()).
	 *
	 * @param string $description Raw extracted description.
	 * @return string
	 */
	private static function clean_description( string $description ): string {
		$description = sanitize_text_field( html_entity_decode( $description, ENT_QUOTES ) );

		if ( '' === $description ) {
			return '';
		}

		return wp_trim_words( $description, self::DESCRIPTION_WORDS );
	}

	/**
	 * Validate (and, when relative, resolve against the page's own URL) a
	 * candidate og:image/twitter:image value. http(s) only — the same
	 * scheme check Daymark_Subscription_Oembed::safe_src() already applies
	 * to an extracted `src`, since this image is rendered as a plain
	 * `<img src>` the visitor's own browser fetches, not a URL this server
	 * itself requests (no SSRF guard needed here, unlike the page fetch
	 * itself above).
	 *
	 * @param string $image    Raw extracted image URL, possibly relative.
	 * @param string $base_url The page's own URL.
	 * @return string Sanitized absolute URL, or '' when not safely usable.
	 */
	private static function safe_image_url( string $image, string $base_url ): string {
		$image = trim( $image );

		if ( '' === $image ) {
			return '';
		}

		$scheme = strtolower( (string) ( wp_parse_url( $image, PHP_URL_SCHEME ) ?? '' ) );

		if ( '' === $scheme && class_exists( 'WP_Http' ) && method_exists( 'WP_Http', 'make_absolute_url' ) ) {
			$image  = WP_Http::make_absolute_url( $image, $base_url );
			$scheme = strtolower( (string) ( wp_parse_url( $image, PHP_URL_SCHEME ) ?? '' ) );
		}

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return esc_url_raw( $image );
	}
}
