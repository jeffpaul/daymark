<?php
/**
 * Shared inline-media content sniffer for subscription sources.
 *
 * Extracted from Daymark_Subscription_Source_Feed's own sniff_content_media()
 * (originally added for the "Subscription content-type inference" pass) so
 * every built-in source can fall back to the same "guess a post_format from
 * an ordinary `<img>`/`<video>`/`<audio>` embedded directly in the body,
 * with no structured signal (an RSS enclosure, a real WordPress `format`
 * field, a Friends-assigned post_format) available" logic, instead of each
 * source either re-implementing it or — as
 * Daymark_Subscription_Source_WordPress and the previous
 * Daymark_Subscription_Source_Friends did — skipping it for a plain
 * `standard`/unset result and losing a real, detectable signal. See the
 * "Subscription type-mapping audit" decision in CLAUDE.md.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static content-sniffing helper shared by every built-in
 * `Daymark_Subscription_Source` that can fall back to guessing a
 * post_format from a post's own content HTML.
 */
class Daymark_Subscription_Content_Sniffer {

	/**
	 * An `<img>`'s own `src`, when a lazy-loading pattern leaves it empty or
	 * pointing at a placeholder (most commonly a `data:` URI blank pixel,
	 * swapped for the real image by JavaScript this sniffer never runs),
	 * checked in the order a real browser's own lazy-load fallback chain
	 * typically uses: the common `data-src`/`data-lazy-src`/`data-original`
	 * attributes several major lazy-load implementations (native WP lazy
	 * loading's own JS shims, common caching/performance plugins) write
	 * alongside `src`, then the first URL in `srcset`/`data-srcset` if
	 * present. Falls back to whatever `src` already had (even if empty) when
	 * none of these resolve anything — this is a best-effort improvement,
	 * not a guarantee every lazy-load pattern is covered.
	 *
	 * @param WP_HTML_Tag_Processor $processor Positioned on an `<img>` tag.
	 * @return string Best available image URL, or '' when nothing usable was found.
	 */
	private static function resolve_image_src( WP_HTML_Tag_Processor $processor ): string {
		$src = (string) ( $processor->get_attribute( 'src' ) ?? '' );

		if ( '' !== $src && ! str_starts_with( $src, 'data:' ) ) {
			return $src;
		}

		foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $lazy_attr ) {
			$lazy = (string) ( $processor->get_attribute( $lazy_attr ) ?? '' );

			if ( '' !== $lazy && ! str_starts_with( $lazy, 'data:' ) ) {
				return $lazy;
			}
		}

		foreach ( array( 'srcset', 'data-srcset' ) as $srcset_attr ) {
			$srcset = (string) ( $processor->get_attribute( $srcset_attr ) ?? '' );

			if ( '' === $srcset ) {
				continue;
			}

			$candidates = explode( ',', $srcset );
			$first      = trim( (string) ( $candidates[0] ?? '' ) );
			$url        = trim( (string) strtok( $first, ' ' ) );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return $src;
	}

	/**
	 * The most gallery image URLs kept for one subscription post. A
	 * Timeline card shows four, plus "+N" for the rest.
	 */
	public const GALLERY_MAX_IMAGES = 20;

	/**
	 * A gallery post's distinct image URLs, for its Timeline card's 2x2 grid.
	 *
	 * `$known` (images a source already found in structured data, such as
	 * feed enclosures or microformats2 `u-photo` properties) come first,
	 * then every image in `$html`, in document order. An author-bio avatar
	 * is skipped, as in sniff(). Copies of the same photo are dropped by
	 * image_key(): a site often prints one photo twice (a lazy-loaded tag
	 * plus a `<noscript>` copy, or a WebP version next to the JPEG).
	 *
	 * @param string   $html  Content HTML, or '' to use only `$known`.
	 * @param string[] $known Image URLs already known for the post, in order.
	 * @return string[] Up to GALLERY_MAX_IMAGES http(s) URLs.
	 */
	public static function gallery_images( string $html, array $known = array() ): array {
		$candidates = array_map( 'strval', $known );

		if ( '' !== trim( $html ) && class_exists( 'WP_HTML_Tag_Processor' ) ) {
			try {
				$processor = new WP_HTML_Tag_Processor( $html );

				while ( $processor->next_tag() ) {
					$class        = (string) ( $processor->get_attribute( 'class' ) ?? '' );
					$is_mf2_photo = false !== stripos( $class, 'u-photo' );

					if ( 'IMG' !== $processor->get_tag() && ! $is_mf2_photo ) {
						continue;
					}

					if ( ! $is_mf2_photo && self::has_class_token( $class, 'avatar' ) ) {
						continue;
					}

					$src = 'IMG' === $processor->get_tag()
						? self::resolve_image_src( $processor )
						: (string) ( $processor->get_attribute( 'href' ) ?? '' );

					if ( '' !== $src ) {
						$candidates[] = $src;
					}
				}
			} catch ( Throwable $e ) {
				// Keep whatever was collected before the markup failed to parse.
				$candidates = array_values( $candidates );
			}
		}

		$images = array();
		$seen   = array();

		foreach ( $candidates as $url ) {
			$url    = html_entity_decode( trim( $url ), ENT_QUOTES );
			$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) );

			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}

			$key = self::image_key( $url );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$images[]     = esc_url_raw( $url );

			if ( count( $images ) >= self::GALLERY_MAX_IMAGES ) {
				break;
			}
		}

		return $images;
	}

	/**
	 * A key that is the same for every copy of one uploaded photo: the URL's
	 * path, lowercased, with its extension and WordPress's own suffixes
	 * removed (`-1024x768` sizes, `-scaled`, and the `-jpeg`/`-png` a WebP
	 * conversion appends). The host is ignored, so a CDN copy
	 * (`i0.wp.com/example.com/...`) matches the original.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function image_key( string $url ): string {
		$path = strtolower( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ) );
		$path = (string) preg_replace( '/\.[a-z0-9]+$/', '', $path );
		$path = (string) preg_replace( '/-(jpe?g|png|gif|webp)$/', '', $path );
		$path = (string) preg_replace( '/-\d+x\d+$/', '', $path );
		$path = (string) preg_replace( '/-scaled$/', '', $path );

		// A CDN path embeds the origin host and path; keep only the part
		// from wp-content/ on, when there is one, so both copies compare equal.
		$uploads = strpos( $path, '/wp-content/' );

		return false !== $uploads ? substr( $path, $uploads ) : $path;
	}

	/**
	 * Whether a space-separated class list contains a given class token —
	 * a plain, exact token match (not a substring match), matching how a
	 * browser's own `classList`/`hasClass` semantics work and avoiding a
	 * false positive on an unrelated class that merely contains the token
	 * as a substring (e.g. a theme's own `avatar-wrapper` or `my-avatar`).
	 *
	 * @param string $class_attr Raw `class` attribute value.
	 * @param string $token      Class token to look for.
	 * @return bool
	 */
	private static function has_class_token( string $class_attr, string $token ): bool {
		if ( '' === trim( $class_attr ) ) {
			return false;
		}

		return in_array( $token, preg_split( '/\s+/', trim( $class_attr ) ), true );
	}

	/**
	 * Scan a fragment of content HTML for inline media a structured signal
	 * (an RSS enclosure, a real `format` field, an already-assigned
	 * post_format) didn't already account for — an ordinary
	 * `<img>`/`<video>`/`<audio>` embedded directly in the post body.
	 * Recognizes microformats2 `u-photo`/`u-video`/`u-audio` classes when
	 * present — an explicit, author-intended "this is the post's media"
	 * signal from an IndieWeb theme, distinct from (and more trustworthy
	 * than) a bare tag with no such markup.
	 *
	 * A bare `<img>` carrying WordPress core's own `avatar` class — the
	 * literal class token every `get_avatar()`/`get_avatar_url()` call
	 * outputs (`class="avatar avatar-96 photo"`), regardless of theme — is
	 * excluded from both counting and `image_src` entirely, never treated
	 * as content media (issue #324). Many themes and author-bio plugins
	 * hook `the_content` to append a "written by" box with the author's
	 * own `get_avatar()` output; that photo is page furniture repeated on
	 * every single post, not something belonging to *this* post's own
	 * content — the same distinction already drawn for Jetpack's
	 * Sharedaddy/Related-Posts blocks and post-navigation links in
	 * Daymark_Subscription_Poller::extract_body_html(). An explicit
	 * `u-photo` class still always wins (an author's own avatar carrying
	 * that class is a deliberate override this method still respects), so
	 * only the *bare* `<img>` fallback path is affected.
	 *
	 * Also captures `link_url`: the first outbound `<a href>` found, when
	 * `$exclude_host` is given — a short "link" or "note"-format item (see
	 * resolveCardKind()'s own word-count heuristic in assets/app.js) is very
	 * often *about* a single external link (a bookmark, a share, a POSSE'd
	 * copy of a post elsewhere), and this is what lets the app shell offer
	 * an oEmbed preview of that specific link rather than none at all — see
	 * Daymark_Subscription_Oembed. An anchor on the item's own site (matched
	 * against `$exclude_host`, case-insensitively) is skipped — that's a
	 * "read more"/self-referential link, not the thing the post is about.
	 * Only the first qualifying link counts; a post linking out to several
	 * things has no reliable way to know which one is "the" link, and the
	 * first is the best available guess.
	 *
	 * Uses WP_HTML_Tag_Processor (core since WP 6.2, so always available on
	 * Daymark's WP 7.0+ baseline) rather than a full DOM parser or an
	 * external library — this only ever needs to walk tags and read two
	 * attributes, not build a tree.
	 *
	 * @param string $html          Content HTML to scan.
	 * @param string $exclude_host  Optional. The item's own host — an anchor
	 *                               pointing here is never treated as the
	 *                               item's outbound link. '' skips link
	 *                               detection entirely (leaves `link_url` at
	 *                               '') rather than risk picking an
	 *                               unrelated self-link.
	 * @return array{has_video: bool, has_audio: bool, photo_count: int, plain_image_count: int, image_src: string, link_url: string}
	 */
	public static function sniff( string $html, string $exclude_host = '' ): array {
		$result = array(
			'has_video'         => false,
			'has_audio'         => false,
			'photo_count'       => 0,
			'plain_image_count' => 0,
			'image_src'         => '',
			'link_url'          => '',
		);

		if ( '' === trim( $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $result;
		}

		// Untrusted external HTML from a subscribed site — never let a
		// pathological document turn a classification hint into a fatal
		// that breaks the whole poll run.
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

				if ( 'IMG' === $tag && ! $is_mf2_photo && self::has_class_token( $class, 'avatar' ) ) {
					continue;
				}

				if ( $is_mf2_photo || 'IMG' === $tag ) {
					if ( $is_mf2_photo ) {
						++$result['photo_count'];
					} else {
						++$result['plain_image_count'];
					}

					if ( '' === $result['image_src'] ) {
						$result['image_src'] = self::resolve_image_src( $processor );
					}

					continue;
				}

				if ( '' !== $exclude_host && '' === $result['link_url'] && 'A' === $tag ) {
					$href = (string) ( $processor->get_attribute( 'href' ) ?? '' );
					$host = '' !== $href ? (string) ( wp_parse_url( $href, PHP_URL_HOST ) ?? '' ) : '';

					if (
						'' !== $host
						&& 0 !== strcasecmp( $host, $exclude_host )
						&& in_array( strtolower( (string) ( wp_parse_url( $href, PHP_URL_SCHEME ) ?? '' ) ), array( 'http', 'https' ), true )
					) {
						$result['link_url'] = $href;
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
				'link_url'          => '',
			);
		}

		return $result;
	}

	/**
	 * Weigh a sniff() result into a single post_format guess, the same
	 * priority Daymark_Subscription_Source_Feed::normalize() already applies
	 * to its own content-sniffing fallback: a native `<video>`/`<audio>`
	 * player or an explicit mf2 `u-photo` always counts; a bare `<img>` with
	 * no mf2 markup only counts when the accompanying text is short (≤40
	 * words, the same threshold every source's own excerpt trims to) — a
	 * header image on a long article should stay unclassified rather than
	 * misclassifying every illustrated post as a photo post. More than one
	 * counted image is a gallery, matching how a multi-enclosure RSS item
	 * already becomes one.
	 *
	 * @param array{has_video: bool, has_audio: bool, photo_count: int, plain_image_count: int, image_src: string} $sniffed sniff()'s own result.
	 * @param string                                                                                               $html    The same content HTML sniff() scanned, for the word-count gate.
	 * @return string One of 'video'|'audio'|'gallery'|'image', or '' when no
	 *                usable signal was found — the caller should keep
	 *                whatever post_format it already had.
	 */
	public static function classify( array $sniffed, string $html ): string {
		if ( $sniffed['has_video'] ) {
			return 'video';
		}

		if ( $sniffed['has_audio'] ) {
			return 'audio';
		}

		if ( $sniffed['photo_count'] > 1 ) {
			return 'gallery';
		}

		if ( $sniffed['photo_count'] > 0 ) {
			return 'image';
		}

		if ( $sniffed['plain_image_count'] > 0 && str_word_count( wp_strip_all_tags( $html ) ) <= 40 ) {
			return $sniffed['plain_image_count'] > 1 ? 'gallery' : 'image';
		}

		return '';
	}

	/**
	 * Whether a manually-provided excerpt/description is really just a
	 * leftover placeholder value rather than genuine summary text — e.g. a
	 * post whose own excerpt field was typed as a reminder ("Excerpt",
	 * "TBD", "Summary") and never replaced before publishing. Every built-in
	 * source (`feed`, `wordpress`, `friends`) already falls back to a
	 * content-derived excerpt when the manual one is completely *empty*;
	 * this closes the gap for one that's *present* but contains nothing a
	 * reader would find useful, so it gets the same content fallback rather
	 * than being trusted as real summary text.
	 *
	 * Deliberately narrow: only a handful of known placeholder words, alone
	 * and with no other text (punctuation aside) — a genuinely short manual
	 * excerpt ("Big news today.") is still real, intentional content and
	 * must never be second-guessed just for being brief.
	 *
	 * @param string $plain_text The excerpt/description, HTML already stripped.
	 * @return bool True when this reads as a placeholder rather than real content.
	 */
	public static function is_placeholder_excerpt( string $plain_text ): bool {
		$normalized = strtolower( trim( $plain_text, " \t\n\r\0\x0B.!?" ) );

		return in_array( $normalized, array( 'excerpt', 'summary', 'tbd', 'todo', 'placeholder' ), true );
	}
}
