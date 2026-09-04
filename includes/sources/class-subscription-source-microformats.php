<?php
/**
 * The built-in microformats2 (h-entry/h-card) subscription source (issue #84).
 *
 * A companion to Daymark_Subscription_Source_Feed for IndieWeb sites that
 * publish semantic h-entry/h-card markup directly on their pages. Discovers
 * an h-feed/h-entry-carrying page the same way the feed source discovers a
 * `<link rel="alternate">` feed, then parses each h-entry into Daymark's
 * source-agnostic subscription post shape — the same normalize() contract
 * the feed source implements, so ingest, caching, and Timeline rendering
 * never need to know which source produced an item.
 *
 * Registered after the feed source in
 * Daymark_Subscription_Source_Registry::register_built_in_sources(), which
 * is what implements this feature's documented precedence rule: the
 * registry's discover_feeds() returns the first non-empty discover() result
 * in registration order, so a site exposing both a traditional feed and
 * h-feed markup is always subscribed via the feed source — this source only
 * ever wins discovery for a site with h-feed/h-entry markup but no
 * discoverable RSS/Atom feed at all. See the "Microformats2 (h-entry/h-card)
 * subscription parsing" decision in CLAUDE.md.
 *
 * A deliberately minimal, purpose-built mf2 subset parser — not the full
 * mf2 vocabulary or spec-complete implied-property rules, and not a
 * DOMDocument-based implementation. It reuses the same lightweight,
 * regex-based tag scanning approach Daymark_Subscription_Source_Feed already
 * uses for `<link>` autodiscovery, extended here to track tag nesting well
 * enough to bound each h-entry (and each property within it) to its own
 * matching closing tag.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microformats2 (h-entry/h-card) source: `Daymark_Subscription_Source` built
 * on a minimal regex-based mf2 subset parser.
 */
class Daymark_Subscription_Source_Microformats implements Daymark_Subscription_Source {

	/**
	 * HTML void elements — never have a closing tag, so they never open a
	 * new nesting level for find_elements_by_class()'s depth tracking.
	 *
	 * @var string[]
	 */
	private const VOID_ELEMENTS = array(
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	);

	/**
	 * IndieWeb post-type-discovery class tokens, in priority order (rsvp
	 * checked first, bookmark last) — the same fixed priority the algorithm
	 * defines. Only the types with no natural Daymark post_format equivalent
	 * need a fallback title (see fallback_title_for_post_type()); an
	 * "article"/"note" entry (none of these classes present) needs none.
	 *
	 * @var array<string, string>
	 */
	private const POST_TYPE_CLASSES = array(
		'rsvp'     => 'p-rsvp',
		'reply'    => 'u-in-reply-to',
		'repost'   => 'u-repost-of',
		'like'     => 'u-like-of',
		'bookmark' => 'u-bookmark-of',
	);

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'microformats';
	}

	/**
	 * Source label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Microformats2 (h-feed)', 'daymark' );
	}

	/**
	 * Discover whether a site publishes h-feed/h-entry markup on the given
	 * URL. Unlike the feed source, there is no separate autodiscovery step
	 * to a different locator — the site URL itself, once confirmed to carry
	 * the markup, is what fetch() re-fetches and parses on every poll.
	 *
	 * @param string $site_url Site URL entered by the user.
	 * @return array<int, array{url: string, title: string, type: string}> A
	 *         single-element array when the page carries h-feed or h-entry
	 *         markup; empty when it carries neither or could not be fetched.
	 */
	public function discover( string $site_url ): array {
		$site_url = $this->sanitize_source_url( $site_url );

		if ( '' === $site_url ) {
			return array();
		}

		$html = $this->fetch_page( $site_url );

		if ( is_wp_error( $html ) ) {
			return array();
		}

		if ( empty( $this->find_elements_by_class( $html, 'h-entry' ) )
			&& empty( $this->find_elements_by_class( $html, 'h-feed' ) )
		) {
			return array();
		}

		return array(
			array(
				'url'   => $site_url,
				'title' => $this->extract_site_title( $html ),
				'type'  => 'text/html',
			),
		);
	}

	/**
	 * Fetch and parse a page's h-entry elements.
	 *
	 * An empty array is a genuinely successful fetch of a page with no
	 * current h-entry elements — distinct from a WP_Error, which means the
	 * page itself could not be reached. Matches the same distinction
	 * Daymark_Subscription_Source_Feed::fetch() documents, for the same
	 * reason: the poller's dead-feed detection must not mark a
	 * quiet-but-healthy site dead after enough empty-but-successful polls.
	 *
	 * @param string $url Page URL to fetch (the site URL discover() found
	 *                    markup on).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch( string $url ): array|WP_Error {
		$validated = $this->validate_source_url( $url );

		if ( is_wp_error( $validated ) ) {
			return new WP_Error( 'daymark_subscription_invalid_feed_url', $validated->get_error_message() );
		}

		$url  = esc_url_raw( trim( $url ) );
		$html = $this->fetch_page( $url );

		if ( is_wp_error( $html ) ) {
			return new WP_Error( 'daymark_subscription_mf2_fetch_failed', $html->get_error_message() );
		}

		$entries = $this->find_elements_by_class( $html, 'h-entry' );

		if ( empty( $entries ) ) {
			return array();
		}

		$raw_items = array();

		foreach ( $entries as $entry ) {
			$raw_items[] = $this->parse_entry( $entry['outer_html'], $url );
		}

		return $raw_items;
	}

	/**
	 * Map one raw h-entry item to Daymark's source-agnostic post shape — the
	 * same eight-key shape Daymark_Subscription_Source_Feed::normalize()
	 * produces, so ingest code never needs to know which source produced an
	 * item.
	 *
	 * `post_format` is derived purely from explicit u-photo/u-video/u-audio
	 * counts, matching how the feed source weighs media — an h-entry
	 * carrying both, say, u-in-reply-to and u-photo still gets an `image`
	 * post_format, since Daymark's post_format vocabulary is about a
	 * Timeline card's visual treatment, not the reply/like/repost semantic
	 * itself (which Daymark's Timeline does not render distinctly).
	 *
	 * @param array<string, mixed> $raw_item One item from fetch()'s raw result.
	 * @return array<string, mixed> Source-agnostic normalized post data.
	 */
	public function normalize( array $raw_item ): array {
		$title = sanitize_text_field( (string) ( $raw_item['name'] ?? '' ) );

		if ( '' === $title ) {
			$title = $this->fallback_title_for_post_type( (string) ( $raw_item['post_type'] ?? '' ) );
		}

		$author       = sanitize_text_field( (string) ( $raw_item['author_name'] ?? '' ) );
		$permalink    = esc_url_raw( (string) ( $raw_item['permalink'] ?? '' ) );
		$published_at = $this->sanitize_datetime( (string) ( $raw_item['published'] ?? '' ) );

		$summary = (string) ( $raw_item['summary'] ?? '' );

		if ( '' === trim( wp_strip_all_tags( $summary ) ) ) {
			$summary = (string) ( $raw_item['content_html'] ?? '' );
		}

		$excerpt = sanitize_text_field( wp_trim_words( wp_strip_all_tags( $summary ), 40 ) );

		$photos = is_array( $raw_item['photos'] ?? null ) ? array_values( array_filter( $raw_item['photos'] ) ) : array();
		$videos = is_array( $raw_item['videos'] ?? null ) ? array_values( array_filter( $raw_item['videos'] ) ) : array();
		$audios = is_array( $raw_item['audios'] ?? null ) ? array_values( array_filter( $raw_item['audios'] ) ) : array();

		if ( count( $videos ) > 0 ) {
			$post_format = 'video';
		} elseif ( count( $audios ) > 0 ) {
			$post_format = 'audio';
		} elseif ( count( $photos ) > 1 ) {
			$post_format = 'gallery';
		} elseif ( count( $photos ) > 0 ) {
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
			'featured_image_url' => $photos[0] ?? '',
			'raw_media'          => array_values( array_merge( $photos, $videos, $audios ) ),
		);
	}

	/**
	 * Parse one h-entry element's outer HTML into a raw item.
	 *
	 * @param string $entry_html This entry's own outer HTML.
	 * @param string $base_url   Page URL the entry was found on (relative
	 *                           `href`/`src` values are resolved against it).
	 * @return array<string, mixed>
	 */
	private function parse_entry( string $entry_html, string $base_url ): array {
		// A nested compound object (an author h-card, a quoted h-cite) has
		// its own separate property namespace — its p-name/u-photo belong to
		// it, not to the outer entry. detect_post_type() below still needs
		// the unstripped $entry_html, since a post-type class like
		// u-repost-of commonly sits on the very same element as a nested
		// h-cite, but every entry-level property lookup here uses this
		// scoped copy so e.g. an author's own avatar photo is never picked
		// up as one of the entry's own u-photo values.
		$scoped_html = $this->strip_nested_compound_objects( $entry_html );

		$name    = $this->first_property_text( $scoped_html, 'p-name' );
		$summary = $this->first_property_text( $scoped_html, 'p-summary' );

		$content_matches = $this->find_elements_by_class( $scoped_html, 'e-content' );
		$content_html    = $content_matches[0]['inner_html'] ?? '';

		$url_matches = $this->find_elements_by_class( $scoped_html, 'u-url' );
		$permalink   = isset( $url_matches[0] ) ? $this->resolve_href_attribute( $url_matches[0], $base_url ) : '';

		$published_matches = $this->find_elements_by_class( $scoped_html, 'dt-published' );
		$published         = isset( $published_matches[0] ) ? $this->extract_datetime_value( $published_matches[0] ) : '';

		$photos = $this->resolve_media_urls( $scoped_html, 'u-photo', $base_url );
		$videos = $this->resolve_media_urls( $scoped_html, 'u-video', $base_url );
		$audios = $this->resolve_media_urls( $scoped_html, 'u-audio', $base_url );

		$author_matches = $this->find_elements_by_class( $entry_html, 'h-card' );
		$author_name    = isset( $author_matches[0] ) ? $this->first_property_text( $author_matches[0]['inner_html'], 'p-name' ) : '';

		if ( '' === $author_name && isset( $author_matches[0] ) ) {
			$author_name = $this->plain_text( $author_matches[0]['inner_html'] );
		}

		return array(
			'name'         => $name,
			'summary'      => $summary,
			'content_html' => $content_html,
			'permalink'    => $permalink,
			'published'    => $published,
			'author_name'  => $author_name,
			'photos'       => $photos,
			'videos'       => $videos,
			'audios'       => $audios,
			'post_type'    => $this->detect_post_type( $entry_html ),
		);
	}

	/**
	 * The IndieWeb post-type-discovery algorithm, reduced to the fixed
	 * priority order Daymark needs: the first matching class token found
	 * anywhere in the entry wins. An entry matching none of these is a plain
	 * article/note — the two are not distinguished since neither has a
	 * dedicated Daymark post_format.
	 *
	 * @param string $entry_html This entry's own outer HTML.
	 * @return string One of 'rsvp'|'reply'|'repost'|'like'|'bookmark'|'note'.
	 */
	private function detect_post_type( string $entry_html ): string {
		foreach ( self::POST_TYPE_CLASSES as $type => $class_token ) {
			if ( ! empty( $this->find_elements_by_class( $entry_html, $class_token ) ) ) {
				return $type;
			}
		}

		return 'note';
	}

	/**
	 * A fallback title for a post type whose h-entry conventionally carries
	 * no `p-name` at all (e.g. a bare "like of" has no name distinct from
	 * its target) — better than an empty title for a Timeline card. An
	 * ordinary article/note entry (post_type 'note') gets no fallback here;
	 * normalize()'s caller falls back to a timestamp, same as an untitled
	 * Mark would.
	 *
	 * @param string $post_type One of detect_post_type()'s return values.
	 * @return string
	 */
	private function fallback_title_for_post_type( string $post_type ): string {
		$labels = array(
			'rsvp'     => __( 'RSVP', 'daymark' ),
			'reply'    => __( 'Reply', 'daymark' ),
			'repost'   => __( 'Repost', 'daymark' ),
			'like'     => __( 'Like', 'daymark' ),
			'bookmark' => __( 'Bookmark', 'daymark' ),
		);

		return $labels[ $post_type ] ?? '';
	}

	/**
	 * Plain text of the first element carrying a given mf2 property class
	 * within a fragment, e.g. the `p-name` inside an `h-card`.
	 *
	 * @param string $html        Fragment to search.
	 * @param string $class_token Property class token, e.g. 'p-name'.
	 * @return string
	 */
	private function first_property_text( string $html, string $class_token ): string {
		$matches = $this->find_elements_by_class( $html, $class_token );

		return isset( $matches[0] ) ? $this->plain_text( $matches[0]['inner_html'] ) : '';
	}

	/**
	 * Resolve every element carrying a given media property class (u-photo/
	 * u-video/u-audio) within an entry into an absolute, sanitized URL list.
	 *
	 * @param string $entry_html  This entry's own outer HTML.
	 * @param string $class_token Media property class token.
	 * @param string $base_url    Page URL the entry was found on.
	 * @return string[]
	 */
	private function resolve_media_urls( string $entry_html, string $class_token, string $base_url ): array {
		$urls = array();

		foreach ( $this->find_elements_by_class( $entry_html, $class_token ) as $match ) {
			$src = $this->resolve_media_src( $match, $base_url );

			if ( '' !== $src ) {
				$urls[] = $src;
			}
		}

		return $urls;
	}

	/**
	 * Resolve one matched element's media source: its `src` attribute (an
	 * `<img>`/`<video>`/`<audio>` element), falling back to `href` (an
	 * `<a class="u-photo">` link, a pattern some IndieWeb themes use).
	 *
	 * @param array{tag: string, attrs: string, inner_html: string, outer_html: string} $element Matched element.
	 * @param string                                                                    $base_url Page URL the element was found on.
	 * @return string Absolute, sanitized URL, or '' if neither attribute is present.
	 */
	private function resolve_media_src( array $element, string $base_url ): string {
		$src = $this->get_attribute_value( $element['attrs'], 'src' );

		if ( '' === $src ) {
			$src = $this->get_attribute_value( $element['attrs'], 'href' );
		}

		if ( '' === $src ) {
			return '';
		}

		return esc_url_raw( WP_Http::make_absolute_url( $src, $base_url ) );
	}

	/**
	 * Resolve a matched element's `href` attribute (a `u-url` link) into an
	 * absolute, sanitized URL.
	 *
	 * @param array{tag: string, attrs: string, inner_html: string, outer_html: string} $element Matched element.
	 * @param string                                                                    $base_url Page URL the element was found on.
	 * @return string
	 */
	private function resolve_href_attribute( array $element, string $base_url ): string {
		$href = $this->get_attribute_value( $element['attrs'], 'href' );

		if ( '' === $href ) {
			return '';
		}

		return esc_url_raw( WP_Http::make_absolute_url( $href, $base_url ) );
	}

	/**
	 * Extract a dt-* property's value: its `datetime` attribute (the
	 * standard mf2 convention on a `<time>` element), falling back to a
	 * `title` attribute (the older `<abbr title="...">` convention), falling
	 * back to the element's own plain text.
	 *
	 * @param array{tag: string, attrs: string, inner_html: string, outer_html: string} $element Matched element.
	 * @return string
	 */
	private function extract_datetime_value( array $element ): string {
		$value = $this->get_attribute_value( $element['attrs'], 'datetime' );

		if ( '' === $value ) {
			$value = $this->get_attribute_value( $element['attrs'], 'title' );
		}

		if ( '' === $value ) {
			$value = $this->plain_text( $element['inner_html'] );
		}

		return $value;
	}

	/**
	 * Remove every nested `h-card`/`h-cite` compound object's own outer HTML
	 * from an entry fragment, so a subsequent entry-level property search
	 * (p-name, e-content, u-photo, ...) never picks up a property that
	 * actually belongs to a nested author card or quoted citation — e.g. an
	 * author's own `u-photo` avatar inside `p-author h-card` is never
	 * mistaken for one of the entry's own photos.
	 *
	 * Deliberately narrow to these two common embedded-object types rather
	 * than every possible nested `h-*` class: unlike h-card/h-cite, an
	 * `h-feed` wrapping top-level `h-entry` children is a container
	 * relationship, not a "belongs to a different object" one, and stripping
	 * it would remove the entries themselves.
	 *
	 * @param string $entry_html This entry's own outer HTML.
	 * @return string
	 */
	private function strip_nested_compound_objects( string $entry_html ): string {
		$nested = array_merge(
			$this->find_elements_by_class( $entry_html, 'h-card' ),
			$this->find_elements_by_class( $entry_html, 'h-cite' )
		);

		if ( empty( $nested ) ) {
			return $entry_html;
		}

		$scoped = $entry_html;

		foreach ( $nested as $match ) {
			if ( '' !== $match['outer_html'] ) {
				$scoped = str_replace( $match['outer_html'], '', $scoped );
			}
		}

		return $scoped;
	}

	/**
	 * Find every element in an HTML fragment/document whose `class`
	 * attribute contains a given microformats2 class token (e.g. `h-entry`,
	 * `p-name`, `e-content`), returning each match's own tag name, attribute
	 * string, and inner/outer HTML. A match nested inside an earlier match
	 * is excluded — a `p-name` that happens to appear inside a quoted/nested
	 * h-entry is never picked up for the outer entry, and a nested h-entry
	 * (e.g. a quoted repost) is never counted as a separate top-level entry.
	 *
	 * A deliberately simple, regex-based tag-depth scanner — matching the
	 * lightweight, DOMDocument-free approach Daymark_Subscription_Source_Feed
	 * already uses for `<link>` autodiscovery — not a spec-complete HTML
	 * parser. Untrusted external HTML from a subscribed site; a malformed or
	 * pathological document degrades to "fewer/no matches found" rather than
	 * a fatal, since parsing is exception-guarded.
	 *
	 * @param string $html        HTML to scan.
	 * @param string $class_token Microformats2 class token to match, e.g. 'h-entry'.
	 * @return array<int, array{tag: string, attrs: string, inner_html: string, outer_html: string}>
	 */
	private function find_elements_by_class( string $html, string $class_token ): array {
		try {
			return $this->scan_for_class( $html, $class_token );
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * The actual scan performed by find_elements_by_class(), split out so
	 * that method can wrap it in one exception guard.
	 *
	 * @param string $html        HTML to scan.
	 * @param string $class_token Microformats2 class token to match.
	 * @return array<int, array{tag: string, attrs: string, inner_html: string, outer_html: string}>
	 */
	private function scan_for_class( string $html, string $class_token ): array {
		if (
			'' === trim( $html )
			|| ! preg_match_all(
				'#<(/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^<>]*?)?)(/?)>#s',
				$html,
				$tags,
				PREG_SET_ORDER | PREG_OFFSET_CAPTURE
			)
		) {
			return array();
		}

		$results = array();
		$stack   = array();

		foreach ( $tags as $tag ) {
			$is_closing = '/' === $tag[1][0];
			$name       = strtolower( $tag[2][0] );
			$attrs      = trim( $tag[3][0] );
			$self_close = '/' === $tag[4][0] || in_array( $name, self::VOID_ELEMENTS, true );
			$start      = (int) $tag[0][1];
			$end        = $start + strlen( $tag[0][0] );

			if ( $is_closing ) {
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $stack[ $i ]['name'] !== $name ) {
						continue;
					}

					$closed = array_splice( $stack, $i );
					$root   = $closed[0];

					if ( $root['matches'] && ! $root['nested'] ) {
						$results[] = array(
							'tag'        => $root['name'],
							'attrs'      => $root['attrs'],
							'inner_html' => substr( $html, $root['content_start'], $start - $root['content_start'] ),
							'outer_html' => substr( $html, $root['start'], $end - $root['start'] ),
						);
					}

					break;
				}

				continue;
			}

			$class         = $this->get_attribute_value( $attrs, 'class' );
			$matches_token = $this->has_class_token( $class, $class_token );

			if ( $self_close ) {
				// A void/self-closing element (most commonly `<img>` for
				// u-photo) can never open a nesting level, but it can still
				// itself carry the class token — it just has no inner HTML,
				// and can never be "nested" since it wasn't pushed on the
				// stack in the first place.
				if ( $matches_token && ! $this->stack_has_match( $stack ) ) {
					$results[] = array(
						'tag'        => $name,
						'attrs'      => $attrs,
						'inner_html' => '',
						'outer_html' => substr( $html, $start, $end - $start ),
					);
				}

				continue;
			}

			$already_nested = $matches_token && $this->stack_has_match( $stack );

			$stack[] = array(
				'name'          => $name,
				'attrs'         => $attrs,
				'start'         => $start,
				'content_start' => $end,
				'matches'       => $matches_token,
				'nested'        => $already_nested,
			);
		}

		return $results;
	}

	/**
	 * Whether any unnested matching frame is already open on the stack —
	 * used to mark a matching element found while already inside another
	 * match as "nested" so it is excluded from the results.
	 *
	 * @param array<int, array{matches: bool, nested: bool}> $stack Current open-tag stack.
	 * @return bool
	 */
	private function stack_has_match( array $stack ): bool {
		foreach ( $stack as $frame ) {
			if ( $frame['matches'] && ! $frame['nested'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a `class` attribute value contains an exact microformats2
	 * class token (space-separated, like every HTML class list).
	 *
	 * @param string $class_attr Raw `class` attribute value.
	 * @param string $token      Token to look for, e.g. 'h-entry'.
	 * @return bool
	 */
	private function has_class_token( string $class_attr, string $token ): bool {
		if ( '' === trim( $class_attr ) ) {
			return false;
		}

		return in_array( $token, preg_split( '/\s+/', trim( $class_attr ) ), true );
	}

	/**
	 * Extract one named attribute's value from a tag's raw attribute text.
	 *
	 * Deliberately a lightweight regex parser rather than DOMDocument,
	 * matching Daymark_Subscription_Source_Feed::parse_tag_attributes()'s own
	 * documented reasoning for the same choice.
	 *
	 * @param string $attrs Raw attribute text captured after the tag name.
	 * @param string $name  Attribute name, e.g. 'class', 'href', 'datetime'.
	 * @return string
	 */
	private function get_attribute_value( string $attrs, string $name ): string {
		$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*"([^"]*)"|\b' . preg_quote( $name, '/' ) . '\s*=\s*\'([^\']*)\'/i';

		if ( ! preg_match( $pattern, $attrs, $match ) ) {
			return '';
		}

		if ( isset( $match[1] ) && '' !== $match[1] ) {
			return html_entity_decode( $match[1], ENT_QUOTES );
		}

		return isset( $match[2] ) ? html_entity_decode( $match[2], ENT_QUOTES ) : '';
	}

	/**
	 * Plain text of an HTML fragment: tags stripped, entities decoded,
	 * sanitized.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private function plain_text( string $html ): string {
		return sanitize_text_field( trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ) ) );
	}

	/**
	 * Extract a document's plain `<title>` tag text, the same way
	 * Daymark_Subscription_Source_Feed::extract_site_title() does, for use
	 * as this source's discover() candidate title.
	 *
	 * @param string $html Fetched page HTML.
	 * @return string
	 */
	private function extract_site_title( string $html ): string {
		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $matches ) ) {
			return '';
		}

		return $this->plain_text( $matches[1] );
	}

	/**
	 * Sanitize a date string (an mf2 `datetime`/`title` attribute value, or
	 * plain text fallback) into a MySQL datetime, tolerating anything
	 * `strtotime()` can parse. Matches
	 * Daymark_Subscription_Source_Feed::sanitize_datetime()'s own logic:
	 * Daymark_Subscription_Post_Type::sanitize_datetime() (the ingest-side
	 * sanitizer normalize() output ultimately passes through) only accepts
	 * an already-MySQL-shaped string, so an ISO 8601 `dt-published` value
	 * must be converted here or it would be silently dropped downstream.
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
	 * Fetch a page's HTML body.
	 *
	 * Delegates to Daymark_Subscription_Html_Cache (issue #137), a static,
	 * request-scoped cache shared across every built-in subscription
	 * source's discovery-time homepage fetch — this is what keeps a single
	 * subscribe-by-URL call to exactly one live request to a given site
	 * regardless of how many sources Daymark_Subscription_Source_Registry
	 * tries before one succeeds.
	 *
	 * @param string $url Already-sanitized, http(s) URL to fetch.
	 * @return string|WP_Error Response body, or a WP_Error describing why
	 *                         the fetch failed (network error, non-2xx
	 *                         status, or a response at/beyond the size cap —
	 *                         a truncated body is discarded rather than
	 *                         treated as complete).
	 */
	private function fetch_page( string $url ) {
		return Daymark_Subscription_Html_Cache::fetch( $url );
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
