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
	 * Scan a fragment of content HTML for inline media a structured signal
	 * (an RSS enclosure, a real `format` field, an already-assigned
	 * post_format) didn't already account for — an ordinary
	 * `<img>`/`<video>`/`<audio>` embedded directly in the post body.
	 * Recognizes microformats2 `u-photo`/`u-video`/`u-audio` classes when
	 * present — an explicit, author-intended "this is the post's media"
	 * signal from an IndieWeb theme, distinct from (and more trustworthy
	 * than) a bare tag with no such markup.
	 *
	 * Uses WP_HTML_Tag_Processor (core since WP 6.2, so always available on
	 * Daymark's WP 7.0+ baseline) rather than a full DOM parser or an
	 * external library — this only ever needs to walk tags and read two
	 * attributes, not build a tree.
	 *
	 * @param string $html Content HTML to scan.
	 * @return array{has_video: bool, has_audio: bool, photo_count: int, plain_image_count: int, image_src: string}
	 */
	public static function sniff( string $html ): array {
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
}
