<?php
/**
 * Reads Likes the site owner already made with the IndieBlocks plugin.
 *
 * IndieBlocks (https://wordpress.org/plugins/indieblocks/) can store Likes
 * in its own `indieblocks_like` post type. Someone who liked posts that way
 * before installing Daymark would otherwise see an empty heart on a followed
 * post they already liked, and a tap would send the origin a second Like.
 *
 * Daymark only reads these posts. It never creates, edits, or deletes one,
 * and it never calls IndieBlocks' own helpers, because some of them write
 * post meta as a side effect. Confirmed against IndieBlocks 0.13.3's source:
 *
 * - `includes/class-post-types.php` registers `indieblocks_like` (public)
 *   only when the plugin's "Likes" option is on.
 * - The same file's `set_post_meta()` stores the liked URL in
 *   `_indieblocks_linked_url` (and the kind, `like`, in `_indieblocks_kind`)
 *   whenever a Like is saved.
 *
 * A Like saved before IndieBlocks started writing that meta has no
 * `_indieblocks_linked_url` until IndieBlocks itself fills it in; Daymark
 * does not parse the content to find it.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only lookup of IndieBlocks Likes.
 */
class Daymark_IndieBlocks_Likes {

	/**
	 * IndieBlocks' Like post type.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'indieblocks_like';

	/**
	 * Post meta holding the liked URL.
	 *
	 * @var string
	 */
	public const URL_META = '_indieblocks_linked_url';

	/**
	 * Whether Daymark should read IndieBlocks Likes at all.
	 *
	 * True only while IndieBlocks is active with its Likes option on, which
	 * is exactly when it registers the post type. With IndieBlocks off, its
	 * Like pages no longer load, so those Likes stop counting.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		/**
		 * Filters whether Daymark reads Likes made with IndieBlocks.
		 *
		 * @param bool $available Default: whether the `indieblocks_like` post type exists.
		 */
		return (bool) apply_filters( 'daymark_read_indieblocks_likes', post_type_exists( self::POST_TYPE ) );
	}

	/**
	 * The ID of a user's published IndieBlocks Like of a URL.
	 *
	 * Matches the URL with and without a trailing slash, since the two
	 * plugins store whatever form they were given.
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     The liked post's URL.
	 * @return int IndieBlocks Like post ID, or 0.
	 */
	public static function find_like_id( int $user_id, string $url ): int {
		if ( $user_id <= 0 || '' === $url || ! self::available() ) {
			return 0;
		}

		$bare     = untrailingslashit( $url );
		$variants = array_values( array_unique( array( $url, $bare, trailingslashit( $bare ) ) ) );

		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match lookup, one per card, at personal-site scale (same posture as Daymark_REST_Controller::find_own_mark_id_by_target_url()).
					array(
						'key'     => self::URL_META,
						'value'   => $variants,
						'compare' => 'IN',
					),
				),
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $found ) ? absint( $found[0] ) : 0;
	}
}
