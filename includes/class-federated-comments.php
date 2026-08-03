<?php
/**
 * Federated comment detection.
 *
 * Federation plugins deliver social replies as native WordPress comments
 * — exactly Daymark's backflow storage model, but push-based (no polling):
 *
 * - ActivityPub (wordpress.org/plugins/activitypub): fediverse replies
 *   (Mastodon, Threads, Pixelfed, …); comment meta `protocol` =
 *   'activitypub', source link in `source_url`.
 * - ATmosphere (wordpress.org/plugins/atmosphere): Bluesky / AT Protocol
 *   replies; comment meta `protocol` = 'atproto', source link in
 *   `source_url` (deliberately mirrors the ActivityPub keys).
 * - Webmention (wordpress.org/plugins/webmention): IndieWeb replies,
 *   including Bridgy backfeed from social silos; comment meta `protocol`
 *   = 'webmention', source link in `webmention_source_url`.
 *
 * This adapter maps those markers onto Daymark's source-label scheme at
 * read time so federated replies surface in Daymark notifications with
 * honest source context. Pure meta detection — no dependency on any of
 * the plugins being present.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects comments delivered by federation plugins.
 */
class Daymark_Federated_Comments {

	/**
	 * Identify a federated comment and its Daymark source labeling.
	 *
	 * @param WP_Comment $comment The comment.
	 * @return array{source: string, label: string, url: string}|null Null when not federated.
	 */
	public static function detect( WP_Comment $comment ): ?array {
		$comment_id = (int) $comment->comment_ID;
		$protocol   = (string) get_comment_meta( $comment_id, 'protocol', true );

		switch ( $protocol ) {
			case 'activitypub':
				return array(
					'source' => 'fediverse',
					'label'  => __( 'Reply from the Fediverse', 'daymark' ),
					'url'    => self::source_url( $comment, 'source_url' ),
				);

			case 'atproto':
				return array(
					'source' => 'bluesky',
					'label'  => __( 'Reply from Bluesky', 'daymark' ),
					'url'    => self::source_url( $comment, 'source_url' ),
				);

			case 'webmention':
				return array(
					'source' => 'webmention',
					'label'  => __( 'Reply via Webmention', 'daymark' ),
					'url'    => self::source_url( $comment, 'webmention_source_url' ),
				);
		}

		return null;
	}

	/**
	 * The comment's source link: protocol-specific meta, then author URL.
	 *
	 * @param WP_Comment $comment  The comment.
	 * @param string     $meta_key Protocol-specific source URL meta key.
	 * @return string
	 */
	private static function source_url( WP_Comment $comment, string $meta_key ): string {
		$url = (string) get_comment_meta( (int) $comment->comment_ID, $meta_key, true );

		if ( '' === $url ) {
			$url = (string) $comment->comment_author_url;
		}

		return $url;
	}
}
