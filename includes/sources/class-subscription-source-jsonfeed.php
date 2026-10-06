<?php
/**
 * JSON Feed subscription source.
 *
 * Reads JSON Feed 1.0 and 1.1 (https://www.jsonfeed.org/version/1.1/),
 * the format Micro.blog and many static-site generators publish. Daymark's
 * RSS/Atom source reads feeds through SimplePie, which can't read JSON
 * Feed, so a site that offers only JSON Feed could not be followed before.
 *
 * JSON Feed is plain JSON with a small, fixed shape, so this source parses
 * it itself and needs no other plugin. Discovery reads the
 * `<link rel="alternate" type="application/feed+json">` tag a site's
 * `<head>` advertises, then fetches that URL once to confirm it really is a
 * JSON Feed before offering it, the same "check, don't trust the tag"
 * rule the WordPress REST API source follows.
 *
 * Registered after the RSS/Atom source: a site offering both is still
 * followed through RSS/Atom by default, as before. The subscribe picker
 * lists both, so a person can choose JSON Feed instead.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON Feed source.
 */
class Daymark_Subscription_Source_Jsonfeed implements Daymark_Subscription_Source {

	/**
	 * Most items read from one fetch.
	 *
	 * @var int
	 */
	private const MAX_ITEMS = 50;

	/**
	 * The `version` URL prefix every JSON Feed carries.
	 *
	 * @var string
	 */
	private const VERSION_PREFIX = 'https://jsonfeed.org/version/';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'jsonfeed';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'JSON Feed', 'daymark' );
	}

	/**
	 * Find a JSON Feed the site's page advertises, and confirm it parses.
	 *
	 * @param string $site_url Site URL entered by the user.
	 * @return array<int, array{url: string, title: string, type: string}>
	 */
	public function discover( string $site_url ): array {
		$site_url = $this->safe_url( $site_url );

		if ( '' === $site_url ) {
			return array();
		}

		$html = Daymark_Subscription_Html_Cache::fetch( $site_url );

		if ( is_wp_error( $html ) || '' === trim( $html ) ) {
			return array();
		}

		$candidates = array();

		foreach ( $this->find_feed_links( $html, $site_url ) as $link ) {
			$feed = $this->load( $link['url'] );

			if ( null === $feed ) {
				continue;
			}

			$title = '' !== $link['title'] ? $link['title'] : sanitize_text_field( (string) ( $feed['title'] ?? '' ) );

			$candidates[] = array(
				'url'   => $link['url'],
				'title' => $title,
				'type'  => 'application/feed+json',
			);
		}

		return $candidates;
	}

	/**
	 * Treat a URL as a JSON Feed itself, for a person who pasted the feed's
	 * own address rather than the site's. Used only after page-based
	 * discovery found nothing, like the RSS/Atom source's own fallback.
	 *
	 * @param string $url URL entered by the user.
	 * @return array<int, array{url: string, title: string, type: string}>
	 */
	public function discover_direct_feed( string $url ): array {
		$url = $this->safe_url( $url );

		if ( '' === $url ) {
			return array();
		}

		$feed = $this->load( $url );

		if ( null === $feed ) {
			return array();
		}

		return array(
			array(
				'url'   => $url,
				'title' => sanitize_text_field( (string) ( $feed['title'] ?? '' ) ),
				'type'  => 'application/feed+json',
			),
		);
	}

	/**
	 * Fetch a JSON Feed and return its items, each with the feed-level
	 * author and base URL attached so normalize() needs nothing else.
	 *
	 * @param string $feed_url JSON Feed URL.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch( string $feed_url ): array|WP_Error {
		$feed_url = $this->safe_url( $feed_url );

		if ( '' === $feed_url ) {
			return new WP_Error( 'daymark_subscription_invalid_feed_url', __( 'Invalid URL.', 'daymark' ) );
		}

		$body = Daymark_Subscription_Html_Cache::fetch( $feed_url );

		if ( is_wp_error( $body ) ) {
			return new WP_Error( 'daymark_subscription_jsonfeed_fetch_failed', $body->get_error_message() );
		}

		$feed = $this->decode( $body );

		if ( null === $feed ) {
			return new WP_Error( 'daymark_subscription_jsonfeed_invalid', __( 'The address did not return a valid JSON Feed.', 'daymark' ) );
		}

		$items       = is_array( $feed['items'] ?? null ) ? array_slice( $feed['items'], 0, self::MAX_ITEMS ) : array();
		$feed_author = $this->author_name( $feed );
		$raw_items   = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item['_feed_author'] = $feed_author;
			$item['_base_url']    = $feed_url;
			$raw_items[]          = $item;
		}

		return $raw_items;
	}

	/**
	 * Map one JSON Feed item to Daymark's source-agnostic post shape.
	 *
	 * @param array<string, mixed> $raw_item One item from fetch().
	 * @return array<string, mixed>
	 */
	public function normalize( array $raw_item ): array {
		$base_url  = (string) ( $raw_item['_base_url'] ?? '' );
		$permalink = $this->absolute_url( (string) ( $raw_item['url'] ?? '' ), $base_url );

		if ( '' === $permalink ) {
			// `id` is often the permalink too; use it only when it is a URL.
			$permalink = $this->absolute_url( (string) ( $raw_item['id'] ?? '' ), $base_url );
		}

		$content_html = (string) ( $raw_item['content_html'] ?? '' );

		if ( '' === trim( wp_strip_all_tags( $content_html ) ) && '' !== (string) ( $raw_item['content_text'] ?? '' ) ) {
			$content_html = wpautop( esc_html( (string) $raw_item['content_text'] ) );
		}

		$summary = (string) ( $raw_item['summary'] ?? '' );

		if ( '' === trim( $summary ) || Daymark_Subscription_Content_Sniffer::is_placeholder_excerpt( $summary ) ) {
			$summary = $content_html;
		}

		$excerpt = sanitize_text_field( wp_trim_words( wp_strip_all_tags( $summary ), 40 ) );
		$title   = sanitize_text_field( wp_strip_all_tags( (string) ( $raw_item['title'] ?? '' ) ) );

		$author = $this->author_name( $raw_item );

		if ( '' === $author ) {
			$author = (string) ( $raw_item['_feed_author'] ?? '' );
		}

		$published = (string) ( $raw_item['date_published'] ?? ( $raw_item['date_modified'] ?? '' ) );

		// Attachments are JSON Feed's enclosures.
		$images    = array();
		$raw_media = array();
		$has_video = false;
		$has_audio = false;

		foreach ( is_array( $raw_item['attachments'] ?? null ) ? $raw_item['attachments'] : array() as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$url = $this->absolute_url( (string) ( $attachment['url'] ?? '' ), $base_url );

			if ( '' === $url ) {
				continue;
			}

			$type        = strtolower( (string) ( $attachment['mime_type'] ?? '' ) );
			$raw_media[] = $url;

			if ( str_starts_with( $type, 'video/' ) ) {
				$has_video = true;
			} elseif ( str_starts_with( $type, 'audio/' ) ) {
				$has_audio = true;
			} elseif ( str_starts_with( $type, 'image/' ) ) {
				$images[] = $url;
			}
		}

		$featured = $this->absolute_url( (string) ( $raw_item['image'] ?? ( $raw_item['banner_image'] ?? '' ) ), $base_url );

		$image_count = count( $images );
		$link_url    = '';

		if ( ! $has_video && ! $has_audio && 0 === $image_count ) {
			// The same content check the RSS/Atom source makes for an item
			// with no enclosures (see Daymark_Subscription_Content_Sniffer).
			$exclude_host = '' !== $permalink ? (string) ( wp_parse_url( $permalink, PHP_URL_HOST ) ?? '' ) : '';
			$sniffed      = Daymark_Subscription_Content_Sniffer::sniff( $content_html, $exclude_host );
			$link_url     = '' !== $sniffed['link_url'] ? esc_url_raw( $sniffed['link_url'] ) : '';

			if ( $sniffed['has_video'] ) {
				$has_video = true;
			} elseif ( $sniffed['has_audio'] ) {
				$has_audio = true;
			} elseif ( $sniffed['photo_count'] > 0 ) {
				$image_count = $sniffed['photo_count'];
			} elseif ( $sniffed['plain_image_count'] > 0 && str_word_count( wp_strip_all_tags( $content_html ) ) <= 40 ) {
				$image_count = $sniffed['plain_image_count'];
			}

			if ( '' === $featured && '' !== $sniffed['image_src'] ) {
				$featured = esc_url_raw( $sniffed['image_src'] );
			}
		}

		if ( '' === $featured && ! empty( $images ) ) {
			$featured = $images[0];
		}

		if ( $has_video ) {
			$post_format = 'video';
		} elseif ( $has_audio ) {
			$post_format = 'audio';
		} elseif ( $image_count > 1 ) {
			$post_format = 'gallery';
		} elseif ( $image_count > 0 ) {
			$post_format = 'image';
		} elseif ( '' === $title && '' !== $excerpt ) {
			// A JSON Feed item with no title is a short post, as on Micro.blog.
			$post_format = 'note';
		} else {
			$post_format = 'standard';
		}

		return array(
			'title'              => $title,
			'excerpt'            => $excerpt,
			'author'             => $author,
			'published_at'       => $this->sanitize_datetime( $published ),
			'permalink'          => $permalink,
			'post_format'        => $post_format,
			'featured_image_url' => $featured,
			'raw_media'          => array_values( array_unique( $raw_media ) ),
			'link_url'           => $link_url,
			'gallery_images'     => 'gallery' === $post_format ? Daymark_Subscription_Content_Sniffer::gallery_images( $content_html, $images ) : array(),
		);
	}

	/**
	 * `<link rel="alternate" type="application/feed+json">` tags in a page.
	 *
	 * @param string $html     Page HTML.
	 * @param string $base_url Page URL, for relative hrefs.
	 * @return array<int, array{url: string, title: string}>
	 */
	private function find_feed_links( string $html, string $base_url ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$links     = array();
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( 'link' ) ) {
			$rel  = strtolower( (string) ( $processor->get_attribute( 'rel' ) ?? '' ) );
			$type = strtolower( trim( (string) ( $processor->get_attribute( 'type' ) ?? '' ) ) );

			if ( 'application/feed+json' !== $type || ! in_array( 'alternate', preg_split( '/\s+/', $rel ), true ) ) {
				continue;
			}

			$url = $this->safe_url( $this->absolute_url( (string) ( $processor->get_attribute( 'href' ) ?? '' ), $base_url ) );

			if ( '' === $url || isset( $links[ $url ] ) ) {
				continue;
			}

			$links[ $url ] = array(
				'url'   => $url,
				'title' => sanitize_text_field( (string) ( $processor->get_attribute( 'title' ) ?? '' ) ),
			);
		}

		return array_values( $links );
	}

	/**
	 * Fetch and decode a JSON Feed.
	 *
	 * @param string $url Feed URL.
	 * @return array<string, mixed>|null The feed, or null when it isn't one.
	 */
	private function load( string $url ): ?array {
		$body = Daymark_Subscription_Html_Cache::fetch( $url );

		return is_wp_error( $body ) ? null : $this->decode( $body );
	}

	/**
	 * Decode a body, returning it only when it is a JSON Feed.
	 *
	 * @param string $body Response body.
	 * @return array<string, mixed>|null
	 */
	private function decode( string $body ): ?array {
		$feed = json_decode( $body, true );

		if ( ! is_array( $feed ) || ! is_string( $feed['version'] ?? null ) || ! str_starts_with( $feed['version'], self::VERSION_PREFIX ) ) {
			return null;
		}

		return is_array( $feed['items'] ?? null ) ? $feed : null;
	}

	/**
	 * An author's name from a feed or item: 1.1's `authors` list, or 1.0's
	 * single `author`.
	 *
	 * @param array<string, mixed> $source Feed or item.
	 * @return string
	 */
	private function author_name( array $source ): string {
		$author = $source['authors'][0] ?? ( $source['author'] ?? null );

		return is_array( $author ) ? sanitize_text_field( (string) ( $author['name'] ?? '' ) ) : '';
	}

	/**
	 * Resolve a possibly relative URL and keep it only when it is http(s).
	 *
	 * @param string $url      URL from the feed.
	 * @param string $base_url URL the feed came from.
	 * @return string
	 */
	private function absolute_url( string $url, string $base_url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( '' !== $base_url && '' === (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			$url = WP_Http::make_absolute_url( $url, $base_url );
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * A URL Daymark may fetch: http(s) and passing the SSRF guard.
	 *
	 * @param string $url Raw URL.
	 * @return string Sanitized URL, or '' when unsafe.
	 */
	private function safe_url( string $url ): string {
		$url    = esc_url_raw( trim( $url ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return is_wp_error( Daymark_Subscription_Url_Guard::check( $url ) ) ? '' : $url;
	}

	/**
	 * An RFC 3339 date as a MySQL UTC datetime, or ''.
	 *
	 * @param string $value Raw date.
	 * @return string
	 */
	private function sanitize_datetime( string $value ): string {
		$timestamp = '' !== trim( $value ) ? strtotime( $value ) : false;

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
