<?php
/**
 * The built-in RSS/Atom feed subscription source.
 *
 * Discovers a site's feed via `<link rel="alternate">` autodiscovery, fetches
 * and parses it via WordPress core's bundled SimplePie (`fetch_feed()`), and
 * normalizes items into Daymark's source-agnostic subscription post shape.
 * Also resolves a site's favicon at subscribe time (a separate, related
 * one-time lookup that reuses the same site-HTML fetch as feed
 * autodiscovery rather than issuing a second request), and — for a feed
 * that advertises one — a WebSub (PubSubHubbub) hub URL, SimplePie's own
 * `get_links( 'hub' )` (see get_last_hub_url(), consulted by
 * Daymark_Websub_Subscriber after every fetch()/parse_raw_feed_body() call).
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
	 * Default maximum feed response size, in bytes, fetch() will download
	 * via fetch_feed()/SimplePie. Filterable via
	 * `daymark_subscription_max_feed_bytes` (issue #81).
	 *
	 * @var int
	 */
	private const MAX_FEED_BYTES = 2 * 1024 * 1024; // 2 MB.

	/**
	 * The WebSub (PubSubHubbub) hub URL the most recent fetch()/
	 * parse_raw_feed_body() call found advertised on the feed, if any — see
	 * get_last_hub_url(). Reset at the top of each call so a hub link found
	 * on an earlier, unrelated feed is never carried over.
	 *
	 * @var string
	 */
	private string $last_hub_url = '';

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
		$this->last_hub_url = '';

		$validated = $this->validate_source_url( $feed_url );

		if ( is_wp_error( $validated ) ) {
			return new WP_Error(
				'daymark_subscription_invalid_feed_url',
				$validated->get_error_message()
			);
		}

		$feed_url = esc_url_raw( trim( $feed_url ) );

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		// Scoped narrowly to this one fetch_feed() call, per the standard WP
		// idiom for a filter with no per-call argument of its own: added
		// immediately before, removed immediately after. Injects a response
		// size cap (issue #81) — fetch_feed()'s own signature (via
		// WP_SimplePie_File) has no direct way to pass limit_response_size.
		// A feed truncated mid-download almost always fails XML parsing on
		// its own, which SimplePie already reports as a WP_Error below; no
		// separate truncation detection is needed for this call site.
		add_filter( 'http_request_args', array( $this, 'inject_feed_response_size_limit' ), 10, 1 );
		// The official WP-documented hook for configuring a SimplePie feed's
		// HTTP behavior (e.g. timeout) before fetch_feed() calls $feed->init().
		add_action( 'wp_feed_options', array( $this, 'configure_feed_timeout' ), 10, 1 );

		// fetch_feed() itself goes through SimplePie's WP_SimplePie_File,
		// which wraps WP's own HTTP API — never a raw remote fetch.
		$feed = fetch_feed( $feed_url );

		remove_filter( 'http_request_args', array( $this, 'inject_feed_response_size_limit' ), 10 );
		remove_action( 'wp_feed_options', array( $this, 'configure_feed_timeout' ), 10 );

		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$this->last_hub_url = $this->extract_hub_url( $feed );

		return $this->build_raw_items( $feed );
	}

	/**
	 * Parse an already-in-hand feed document (issue #82: a WebSub hub's
	 * content-distribution POST body) the same way fetch() parses a
	 * downloaded one — same item shape, same normalize() contract — but
	 * without making an HTTP request of its own, since the hub already
	 * pushed the content.
	 *
	 * Deliberately mirrors fetch()'s own error handling: a body SimplePie
	 * cannot parse (malformed/truncated XML) returns a WP_Error, same as an
	 * unreachable feed would.
	 *
	 * @param string $raw_body Raw feed document (RSS or Atom XML).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function parse_raw_feed_body( string $raw_body ) {
		$this->last_hub_url = '';

		if ( '' === trim( $raw_body ) ) {
			return new WP_Error( 'daymark_websub_empty_body', __( 'The delivered content was empty.', 'daymark' ) );
		}

		if ( ! class_exists( 'SimplePie', false ) ) {
			require_once ABSPATH . WPINC . '/class-simplepie.php';
		}

		$feed = new SimplePie();
		$feed->enable_cache( false );
		$feed->set_raw_data( $raw_body );
		$feed->set_output_encoding( get_option( 'blog_charset', 'UTF-8' ) );
		$feed->init();
		$feed->handle_content_type();

		if ( $feed->error() ) {
			return new WP_Error( 'daymark_websub_parse_failed', (string) $feed->error() );
		}

		$this->last_hub_url = $this->extract_hub_url( $feed );

		return $this->build_raw_items( $feed );
	}

	/**
	 * The WebSub hub URL the most recent fetch()/parse_raw_feed_body() call
	 * found advertised on the feed (a `<link rel="hub">`/`<atom:link
	 * rel="hub">` element — SimplePie parses this automatically), or '' when
	 * the feed advertised none. Consulted by Daymark_Websub_Subscriber right
	 * after a poll to decide whether a WebSub subscription is possible for
	 * this feed at all.
	 *
	 * @return string
	 */
	public function get_last_hub_url(): string {
		return $this->last_hub_url;
	}

	/**
	 * First hub link a parsed feed advertises, or '' when it advertises
	 * none. A feed may list more than one hub; Daymark only ever needs one
	 * to subscribe through.
	 *
	 * @param SimplePie $feed Parsed feed.
	 * @return string
	 */
	private function extract_hub_url( $feed ): string {
		$hubs = $feed->get_links( 'hub' );

		return is_array( $hubs ) && isset( $hubs[0] ) ? esc_url_raw( (string) $hubs[0] ) : '';
	}

	/**
	 * Map every item on a parsed SimplePie feed to fetch()'s raw item shape.
	 * Shared by fetch() (a freshly downloaded feed) and parse_raw_feed_body()
	 * (a WebSub-delivered one) so both funnel through the exact same
	 * SimplePie-item-to-raw-item mapping.
	 *
	 * @param SimplePie $feed Parsed feed.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_raw_items( $feed ): array {
		$items     = $feed->get_items( 0, $feed->get_item_quantity() );
		$raw_items = array();

		foreach ( $items as $item ) {
			$raw_items[] = $this->extract_raw_item( $item );
		}

		return $raw_items;
	}

	/**
	 * `http_request_args` callback, scoped to the single fetch_feed() call in
	 * fetch() above: injects a maximum response size for the feed download.
	 *
	 * @param array<string, mixed> $args HTTP request args.
	 * @return array<string, mixed>
	 */
	public function inject_feed_response_size_limit( array $args ): array {
		/**
		 * Filters the maximum feed response size, in bytes, fetch() will
		 * download via fetch_feed()/SimplePie.
		 *
		 * @since 0.10.0
		 *
		 * @param int $max_bytes Defaults to 2 MB.
		 */
		$args['limit_response_size'] = (int) apply_filters( 'daymark_subscription_max_feed_bytes', self::MAX_FEED_BYTES );

		return $args;
	}

	/**
	 * `wp_feed_options` callback, scoped to the single fetch_feed() call in
	 * fetch() above: sets an explicit, filterable timeout instead of
	 * SimplePie's own default.
	 *
	 * @param SimplePie $feed Feed instance, passed by reference by fetch_feed().
	 * @return void
	 */
	public function configure_feed_timeout( $feed ): void {
		/**
		 * Filters the HTTP timeout, in seconds, used when fetching a
		 * subscription's feed.
		 *
		 * @since 0.10.0
		 *
		 * @param int $seconds Defaults to 10.
		 */
		$feed->set_timeout( (int) apply_filters( 'daymark_subscription_feed_fetch_timeout', 10 ) );
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
	 *   from enclosures first, falling back to the item's own content HTML
	 *   when no enclosure carries a signal — see sniff_content_media())
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

		// An enclosure is an explicit "this is the media of this item"
		// signal, but plenty of ordinary posts (an inline <img> in the body,
		// no <enclosure> at all) carry media the enclosure loop above never
		// sees. Only fall back to sniffing the content HTML when enclosures
		// found nothing — an enclosure-confirmed signal always wins.
		if ( ! $has_video && ! $has_audio && 0 === $image_count ) {
			// Read the raw fields directly rather than the $description
			// variable above: that one may already have been swapped to
			// content (when the raw description's own text was empty),
			// which would silently drop an image-only description here.
			$content_html = (string) ( $raw_item['content'] ?? '' );

			if ( '' === trim( wp_strip_all_tags( $content_html ) ) ) {
				$content_html = (string) ( $raw_item['description'] ?? '' );
			}

			$sniffed = $this->sniff_content_media( $content_html );

			if ( $sniffed['has_video'] ) {
				$has_video = true;
			} elseif ( $sniffed['has_audio'] ) {
				$has_audio = true;
			} elseif ( $sniffed['photo_count'] > 0 ) {
				// A microformats2 u-photo class is an explicit, author-intended
				// signal (an IndieWeb theme marking this as THE photo of the
				// post) — trusted regardless of how much text accompanies it.
				$image_count = $sniffed['photo_count'];
			} elseif ( $sniffed['plain_image_count'] > 0 && str_word_count( wp_strip_all_tags( $content_html ) ) <= 40 ) {
				// A bare <img> with no mf2 markup is a weaker signal — plenty
				// of ordinary articles carry one illustrative header image —
				// so it only counts when there's little text alongside it,
				// the same threshold the excerpt above trims to. A bare <img>
				// in a substantial article stays 'standard': that is an
				// illustrated article, not a photo post.
				$image_count = $sniffed['plain_image_count'];
			}

			// A thumbnail is worth showing even when the image wasn't
			// confident enough to change post_format itself (e.g. a header
			// image on a long article) — unlike the format classification
			// above, there's no real downside to a "maybe" here.
			if ( '' === $featured_image_url && '' !== $sniffed['image_src'] ) {
				$featured_image_url = esc_url_raw( $sniffed['image_src'] );
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
	 * Scan an item's content HTML for media the enclosure loop in normalize()
	 * never sees — an ordinary `<img>`/`<video>`/`<audio>` embedded directly
	 * in the post body, with no `<enclosure>` at all (an extremely common
	 * shape for a plain WordPress image post). Also recognizes microformats2
	 * `u-photo`/`u-video`/`u-audio` classes when present — an explicit,
	 * author-intended "this is the post's media" signal from an IndieWeb
	 * theme, distinct from (and more trustworthy than) a bare tag with no
	 * such markup.
	 *
	 * Uses WP_HTML_Tag_Processor (core since WP 6.2, so always available on
	 * Daymark's WP 7.0+ baseline) rather than a full DOM parser or an
	 * external library — this only ever needs to walk tags and read two
	 * attributes, not build a tree.
	 *
	 * @since 0.8.0
	 *
	 * @param string $html Item content or description HTML.
	 * @return array{has_video: bool, has_audio: bool, photo_count: int, plain_image_count: int, image_src: string}
	 */
	private function sniff_content_media( string $html ): array {
		$result = array(
			'has_video'         => false,
			'has_audio'         => false,
			'photo_count'       => 0,
			'plain_image_count' => 0,
			'image_src'         => '',
		);

		if ( '' === trim( $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $result;
		}

		// Untrusted external HTML from a feed — never let a pathological
		// document (issue #81 tracks broader feed hardening) turn a
		// classification hint into a fatal that breaks the whole poll run.
		try {
			$processor = new WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag() ) {
				$tag   = $processor->get_tag();
				$class = (string) ( $processor->get_attribute( 'class' ) ?? '' );

				if ( false !== stripos( $class, 'u-video' ) || 'VIDEO' === $tag ) {
					$result['has_video'] = true;
					continue;
				}

				if ( false !== stripos( $class, 'u-audio' ) || 'AUDIO' === $tag ) {
					$result['has_audio'] = true;
					continue;
				}

				$is_mf2_photo = false !== stripos( $class, 'u-photo' );

				if ( $is_mf2_photo || 'IMG' === $tag ) {
					if ( $is_mf2_photo ) {
						++$result['photo_count'];
					} else {
						++$result['plain_image_count'];
					}

					if ( '' === $result['image_src'] ) {
						$result['image_src'] = (string) ( $processor->get_attribute( 'src' ) ?? '' );
					}
				}
			}
		} catch ( Throwable $e ) {
			return array(
				'has_video'         => false,
				'has_audio'         => false,
				'photo_count'       => 0,
				'plain_image_count' => 0,
				'image_src'         => '',
			);
		}

		return $result;
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
	 * Resolve a site's plain name from its own `<title>` tag — used to
	 * refine a subscription's site_title beyond the feed autodiscovery
	 * `<link>` tag's title attribute, which often carries WordPress's
	 * default "{Site Name} » Feed" suffix (or a category/tag name) rather
	 * than just the site's name.
	 *
	 * Reuses the same site-HTML fetch discover()/get_favicon_url() would
	 * make for the same URL rather than issuing a second request.
	 *
	 * @since 0.8.0
	 *
	 * @param string $site_url Site URL.
	 * @return string Plain site title, or '' when the site URL is invalid,
	 *                unreachable, or has no `<title>` tag.
	 */
	public function get_site_title( string $site_url ): string {
		$site_url = $this->sanitize_source_url( $site_url );

		if ( '' === $site_url ) {
			return '';
		}

		$html = $this->fetch_html( $site_url );

		if ( '' === $html ) {
			return '';
		}

		return $this->extract_site_title( $html );
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
	 * Extract the plain text of a document's `<title>` tag, e.g. "Jeff
	 * Paul" — deliberately not the feed autodiscovery `<link>` tag's title
	 * attribute (is_default_feed_title() above), which carries WordPress's
	 * "{Site Name} » Feed" convention or a category/tag name instead of
	 * just the site's own name.
	 *
	 * @since 0.8.0
	 *
	 * @param string $html Fetched HTML.
	 * @return string Plain-text title, or '' when no `<title>` tag is found.
	 */
	private function extract_site_title( string $html ): string {
		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', $this->extract_head_section( $html ), $matches ) ) {
			return '';
		}

		$title = html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES );

		return sanitize_text_field( trim( $title ) );
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
	 * Fetch a URL's response body for autodiscovery/favicon/site-title.
	 *
	 * Delegates to Daymark_Subscription_Html_Cache (issue #137), a static,
	 * request-scoped cache shared across every built-in subscription
	 * source's discovery-time homepage fetch — not just this instance's own
	 * discover()/get_favicon_url()/get_site_title() calls, but also
	 * Daymark_Subscription_Source_WordPress's and
	 * Daymark_Subscription_Source_Microformats's own discover() calls
	 * against the very same URL. Daymark_Subscription_Source_Registry tries
	 * each registered source in turn until one succeeds, so without a
	 * shared cache, a site only this source can resolve would still be
	 * fetched once per source tried ahead of it in
	 * Daymark_Subscription_Source_Registry::discover_feeds() — this is what
	 * keeps a single subscribe-by-URL call to exactly one live request
	 * regardless of how many sources are registered.
	 *
	 * @param string $url Already-sanitized, http(s) URL to fetch.
	 * @return string Response body, or '' on any failure (including a
	 *                response that hit the size cap).
	 */
	private function fetch_html( string $url ): string {
		$result = Daymark_Subscription_Html_Cache::fetch( $url );

		return is_wp_error( $result ) ? '' : $result;
	}

	/**
	 * Validate a user-supplied site or feed URL for safety: `http`/`https`
	 * scheme, and — per issue #81's SSRF hardening — a host that does not
	 * resolve to a private/internal/reserved address, no embedded userinfo,
	 * and a standard port (see Daymark_Subscription_Url_Guard).
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
	 * Sanitize and validate a user-supplied site or feed URL: reject
	 * anything that is not `http`/`https`, or that fails the SSRF guard
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
