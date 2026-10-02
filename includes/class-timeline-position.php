<?php
/**
 * Per-user "last seen" marker for Home's Timeline.
 *
 * Records the newest Timeline item a user has actually seen on screen, so a
 * later visit can open the Timeline anchored on that item — newer posts sit
 * above it, one scroll or one tap away — instead of always starting at the
 * newest post. Stored as one `daymark_timeline_last_seen` user meta value,
 * so it follows the user across devices.
 *
 * The marker only moves forward: an item replaces it only when it sorts
 * newer on the Timeline. Scrolling back down through older posts never
 * moves it backward, the same "newest thing you've read" rule a chat app's
 * unread marker follows.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and advances the Timeline's per-user last-seen marker.
 */
class Daymark_Timeline_Position {

	/**
	 * User meta key. A single array value: id, item_type, sort_key, seen_at.
	 *
	 * @var string
	 */
	public const META_KEY = 'daymark_timeline_last_seen';

	/**
	 * The marker as the app shell needs it, or null when there is none (or
	 * the item it pointed at is no longer on the Timeline).
	 *
	 * @param int $user_id User ID.
	 * @return array{id: int, item_type: string}|null
	 */
	public static function get( int $user_id ): ?array {
		$stored = self::stored( $user_id );

		if ( null === $stored || null === self::sort_key( $stored['id'] ) ) {
			return null;
		}

		return array(
			'id'        => $stored['id'],
			'item_type' => $stored['item_type'],
		);
	}

	/**
	 * Record that a user has seen a Timeline item. Moves the marker only
	 * when the item sorts newer than the current one, or when the current
	 * one is gone from the Timeline.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id A published Mark/post or subscription post.
	 * @return array{id: int, item_type: string}|WP_Error The marker after the
	 *                                                    update, or an error
	 *                                                    for an unknown item.
	 */
	public static function mark_seen( int $user_id, int $post_id ) {
		$sort_key = self::sort_key( $post_id );

		if ( null === $sort_key ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$stored = self::stored( $user_id );

		// The stored item's own current sort key, not the copy saved with
		// it: an edited post date is what the Timeline actually sorts by.
		$stored_key = null !== $stored ? self::sort_key( $stored['id'] ) : null;

		if ( null === $stored_key || strcmp( $sort_key, $stored_key ) > 0 ) {
			update_user_meta(
				$user_id,
				self::META_KEY,
				array(
					'id'        => $post_id,
					'item_type' => self::item_type( $post_id ),
					'sort_key'  => $sort_key,
					'seen_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		return self::get( $user_id );
	}

	/**
	 * The stored marker, shape-checked, or null.
	 *
	 * @param int $user_id User ID.
	 * @return array{id: int, item_type: string}|null
	 */
	private static function stored( int $user_id ): ?array {
		$value = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $value ) || empty( $value['id'] ) ) {
			return null;
		}

		$id = absint( $value['id'] );

		return array(
			'id'        => $id,
			'item_type' => self::item_type( $id ),
		);
	}

	/**
	 * The key GET /timeline sorts an item by — a post's own post_date_gmt,
	 * or a subscription post's published_at meta (see get_timeline()) — or
	 * null when the ID isn't a published Timeline item.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private static function sort_key( int $post_id ): ?string {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( 'post' === $post->post_type ) {
			return (string) $post->post_date_gmt;
		}

		if ( Daymark_Subscription_Post_Type::POST_TYPE === $post->post_type ) {
			return sanitize_text_field( (string) get_post_meta( $post_id, 'published_at', true ) );
		}

		return null;
	}

	/**
	 * The Timeline's own `item_type` discriminator for a post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return string 'subscription_post' or 'mark'.
	 */
	private static function item_type( int $post_id ): string {
		return Daymark_Subscription_Post_Type::POST_TYPE === get_post_type( $post_id ) ? 'subscription_post' : 'mark';
	}
}
