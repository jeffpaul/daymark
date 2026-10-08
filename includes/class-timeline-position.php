<?php
/**
 * Per-user reading position on Home's Timeline.
 *
 * Two markers, each one user meta value, so both follow the user across
 * devices:
 *
 * - The reading position (`daymark_timeline_position`): the item at the top
 *   of the screen when the user last looked at Home. A later visit opens the
 *   Timeline on that item, with newer posts above it. It moves both ways,
 *   because it records where the user was, not how far they have read.
 * - The last-seen marker (`daymark_timeline_last_seen`): the newest item the
 *   user has seen. It only moves forward, like a chat app's unread marker.
 *   The app uses it to count the posts that are new since then.
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
	 * User meta key for the reading position. A single array value: id,
	 * item_type, saved_at.
	 *
	 * @var string
	 */
	public const POSITION_META_KEY = 'daymark_timeline_position';

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
	 * The reading position as the app shell needs it, or null when there is
	 * none (or the item it pointed at is no longer on the Timeline).
	 *
	 * @param int $user_id User ID.
	 * @return array{id: int, item_type: string}|null
	 */
	public static function get_position( int $user_id ): ?array {
		$stored = self::stored( $user_id, self::POSITION_META_KEY );

		if ( null === $stored || null === self::sort_key( $stored['id'] ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * Record the item at the top of a user's screen on Home. Unlike
	 * mark_seen(), this replaces the stored item whichever way it sorts.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id A published Mark/post or subscription post.
	 * @return array{id: int, item_type: string}|WP_Error The position after
	 *                                                    the update, or an
	 *                                                    error for an
	 *                                                    unknown item.
	 */
	public static function set_position( int $user_id, int $post_id ) {
		if ( null === self::sort_key( $post_id ) ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		update_user_meta(
			$user_id,
			self::POSITION_META_KEY,
			array(
				'id'        => $post_id,
				'item_type' => self::item_type( $post_id ),
				'saved_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return self::get_position( $user_id );
	}

	/**
	 * A stored marker, shape-checked, or null.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key META_KEY or POSITION_META_KEY.
	 * @return array{id: int, item_type: string}|null
	 */
	private static function stored( int $user_id, string $meta_key = self::META_KEY ): ?array {
		$value = get_user_meta( $user_id, $meta_key, true );

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
