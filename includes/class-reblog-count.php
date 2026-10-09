<?php
/**
 * How many times a post has been reblogged.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One reblog count for a post, shared by the Timeline card's
 * `repost_count` and the Reblogs column on wp-admin's Posts list, so the
 * two can never show different numbers.
 */
class Daymark_Reblog_Count {

	/**
	 * The full reblog count: reblogs stored on this site, plus the reblogs
	 * polling connectors reported for the post's syndicated copies (see
	 * Daymark_Backflow_Sync::store_reactions()).
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function for_post( int $post_id ): int {
		return self::counted_reblogs( $post_id ) + Daymark_Backflow_Sync::reaction_totals( $post_id )['reposts'];
	}

	/**
	 * How many times a Mark has been reblogged, with or without the
	 * reblogger's own words (issue #396).
	 *
	 * - `repost` comments: a plain reblog or boost, as the ActivityPub,
	 *   ATmosphere, and Webmention plugins store it (issue #41). A Webmention
	 *   reblog with commentary is still a `repost`, since the Webmention
	 *   plugin types by `u-repost-of` and ignores any text alongside it.
	 * - `quote` comments: a quote post (a reblog with commentary) from the
	 *   fediverse, which the ActivityPub plugin stores under its own `quote`
	 *   type (`Activitypub\Comment::register_comment_types()`).
	 * - Reblog Marks published on this same site (another author here
	 *   reblogging it), which no plugin turns into a comment. One that the
	 *   Webmention plugin already recorded as a comment (by its source URL)
	 *   is not counted twice.
	 *
	 * @param int $post_id Mark post ID.
	 * @return int
	 */
	private static function counted_reblogs( int $post_id ): int {
		$comments = get_comments(
			array(
				'post_id'  => $post_id,
				'type__in' => array( 'repost', 'quote' ),
				'status'   => 'approve',
				'fields'   => 'ids',
			)
		);

		$counted_sources = array();

		foreach ( $comments as $comment_id ) {
			$source = (string) get_comment_meta( (int) $comment_id, 'webmention_source_url', true );

			if ( '' !== $source ) {
				$counted_sources[ untrailingslashit( $source ) ] = true;
			}
		}

		$permalink = (string) get_permalink( $post_id );
		$targets   = array_values( array_unique( array_filter( array( $permalink, untrailingslashit( $permalink ), trailingslashit( $permalink ) ) ) ) );
		$local     = empty( $targets ) ? array() : get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array( $post_id ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match lookup, one per Mark card, at personal-site scale (same posture as Daymark_REST_Controller::find_own_mark_id_by_target_url()).
					array(
						'key'     => '_daymark_repost_of',
						'value'   => $targets,
						'compare' => 'IN',
					),
				),
			)
		);

		$local_uncounted = 0;

		foreach ( $local as $reblog_id ) {
			if ( ! isset( $counted_sources[ untrailingslashit( (string) get_permalink( (int) $reblog_id ) ) ] ) ) {
				++$local_uncounted;
			}
		}

		return count( $comments ) + $local_uncounted;
	}
}
