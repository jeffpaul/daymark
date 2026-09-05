<?php
/**
 * Per-user bookmarks: "save for offline viewing" on a Timeline item.
 *
 * Every prior per-user preference in this codebase (destination prefs,
 * category prefs, notifications-seen, rel=me) is a single scalar or small
 * associative value on the user — none is keyed per post. A bookmark is a
 * per-user *set membership* (this user did/didn't bookmark this post), so
 * it's stored as multiple `daymark_bookmark` user meta rows, one per
 * bookmarked post ID, rather than a single serialized array — WordPress's
 * own multi-value meta support (`add_user_meta()`/`delete_user_meta()` with
 * a value) already gives add/remove/lookup-by-value without hand-rolling
 * array read-modify-write, which would risk a lost update between two
 * requests editing the same serialized value.
 *
 * Works identically for a Mark (`post`) or a cached `daymark_subscription_post`
 * — both are real `WP_Post` IDs, so this class never needs to know which
 * kind of post it's holding a bookmark for.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookmark set membership, stored as multi-value user meta.
 */
class Daymark_Bookmarks {

	/**
	 * User meta key. Multi-value: one row per bookmarked post ID.
	 *
	 * @var string
	 */
	public const META_KEY = 'daymark_bookmark';

	/**
	 * Whether a user has bookmarked a given post.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Post ID (Mark or subscription post).
	 * @return bool
	 */
	public function is_bookmarked( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		return in_array( $post_id, $this->get_ids( $user_id ), true );
	}

	/**
	 * Bookmarks a post for a user. A no-op if already bookmarked.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Post ID.
	 * @return bool True if added (or already present), false on invalid input.
	 */
	public function add( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		if ( $this->is_bookmarked( $user_id, $post_id ) ) {
			return true;
		}

		return false !== add_user_meta( $user_id, self::META_KEY, $post_id, false );
	}

	/**
	 * Removes a bookmark for a user. A no-op if not bookmarked.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function remove( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		return delete_user_meta( $user_id, self::META_KEY, $post_id );
	}

	/**
	 * All post IDs a user has bookmarked, most-recently-bookmarked first.
	 *
	 * `get_user_meta()` with `$single = false` returns rows in insertion
	 * order (ascending `umeta_id`), so reversing gives newest-first — there
	 * is no separate "bookmarked at" timestamp to sort by.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public function get_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_KEY, false );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_map( 'absint', $raw );
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		return array_reverse( $ids );
	}
}
