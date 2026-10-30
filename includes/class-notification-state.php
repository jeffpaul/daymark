<?php
/**
 * Per-user read and archived state for Notifications items.
 *
 * Every notification item has a stable string ID (see
 * Daymark_Notifications::item_id()), e.g. `comment-42` or
 * `jetpack_like-17-123456`. This class stores, per user:
 *
 * - a read watermark (`daymark_notifications_read_before`): every dated
 *   item at or before it counts as read, which is how "Mark all as read"
 *   works without writing one row per notification;
 * - read IDs (`daymark_notification_read`, multi-value): items read one
 *   at a time after the watermark;
 * - unread IDs (`daymark_notification_unread`, multi-value): items the
 *   person marked unread again although the watermark covers them;
 * - archived IDs (`daymark_notification_archived`, multi-value): items
 *   removed from Notifications. Archiving never deletes the comment or
 *   like itself; it only hides the notification.
 *
 * Multi-value meta (one row per ID) is the same shape Daymark_Bookmarks
 * uses: add and remove are single-row writes, so two quick taps can't
 * lose each other's update.
 *
 * A reply Daymark found on a followed site (`comment_reply-…`) and the
 * approved Webmention comment that brings the same reply to a Mark
 * (`comment-…`) are one notification (issue #559): Notifications shows the
 * comment in the reply's place, and the two share their read and archived
 * state (see carry_over() and with_links()).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and records which notifications a user has read or archived.
 */
class Daymark_Notification_State {

	/**
	 * Read watermark (Unix time).
	 *
	 * @var string
	 */
	public const READ_BEFORE_META = 'daymark_notifications_read_before';

	/**
	 * Multi-value: IDs read one at a time.
	 *
	 * @var string
	 */
	public const READ_META = 'daymark_notification_read';

	/**
	 * Multi-value: IDs marked unread although the watermark covers them.
	 *
	 * @var string
	 */
	public const UNREAD_META = 'daymark_notification_unread';

	/**
	 * Multi-value: archived IDs.
	 *
	 * @var string
	 */
	public const ARCHIVED_META = 'daymark_notification_archived';

	/**
	 * Most IDs accepted in one request.
	 *
	 * @var int
	 */
	public const MAX_IDS_PER_REQUEST = 100;

	/**
	 * Item types whose ID is not tied to a comment or like on this site.
	 * Their state is dropped once the item no longer exists (see prune()):
	 * a reply found on a followed site goes once Daymark stops remembering
	 * it (issue #559).
	 *
	 * @var string[]
	 */
	public const TRANSIENT_TYPES = array( 'comment_reply', 'feed_issue', 'feed_issues', 'plugin_overlap' );

	/**
	 * Hook comment cleanup.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'deleted_comment', array( $this, 'forget_comment' ) );
	}

	/**
	 * Whether a string is a well-formed notification ID.
	 *
	 * @param string $id Candidate ID.
	 * @return bool
	 */
	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^(comment|comment_reply|jetpack_like|feed_issue|feed_issues|plugin_overlap)-[a-z0-9_-]{1,120}$/', $id );
	}

	/**
	 * Keep only well-formed, distinct IDs, capped per request.
	 *
	 * @param mixed $ids Raw IDs.
	 * @return string[]
	 */
	public static function clean_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			$id = is_string( $id ) ? strtolower( trim( $id ) ) : '';

			if ( self::is_valid_id( $id ) ) {
				$clean[ $id ] = true;
			}
		}

		return array_slice( array_keys( $clean ), 0, self::MAX_IDS_PER_REQUEST );
	}

	/**
	 * The read watermark. Falls back to the last time the person opened
	 * Notifications, so everything they already saw before this feature
	 * existed doesn't arrive as unread.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function read_before( int $user_id ): int {
		if ( $this->has_read_watermark( $user_id ) ) {
			return (int) get_user_meta( $user_id, self::READ_BEFORE_META, true );
		}

		return (int) get_user_meta( $user_id, Daymark_Notifications::SEEN_META, true );
	}

	/**
	 * Whether the person has used "Mark all as read", so the watermark is
	 * their own and moves only when they ask. Until then read_before()
	 * falls back to their last visit, which moves on every visit.
	 *
	 * @since 0.22.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function has_read_watermark( int $user_id ): bool {
		$stored = get_user_meta( $user_id, self::READ_BEFORE_META, true );

		return '' !== $stored && false !== $stored;
	}

	/**
	 * A user's stored IDs for one meta key, as a lookup set.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @return array<string, true>
	 */
	public function ids( int $user_id, string $meta_key ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		return array_fill_keys( array_map( 'strval', get_user_meta( $user_id, $meta_key, false ) ), true );
	}

	/**
	 * Whether an item is read.
	 *
	 * @param string              $id          Item ID.
	 * @param int                 $timestamp   Item time; 0 = undated.
	 * @param int                 $read_before Watermark.
	 * @param array<string, true> $read        Read IDs.
	 * @param array<string, true> $unread      Unread IDs.
	 * @return bool
	 */
	public static function is_read( string $id, int $timestamp, int $read_before, array $read, array $unread ): bool {
		if ( isset( $read[ $id ] ) ) {
			return true;
		}

		if ( isset( $unread[ $id ] ) ) {
			return false;
		}

		$type = substr( $id, 0, (int) strpos( $id, '-' ) );

		// A plugin-overlap item is dated "now" on every request, so only an
		// explicit read row can mark it read.
		if ( 'plugin_overlap' === $type ) {
			return false;
		}

		return $timestamp > 0 && $timestamp <= $read_before;
	}

	/**
	 * Mark items read, and the items linked to them (see with_links()).
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $ids     Clean IDs.
	 * @return void
	 */
	public function mark_read( int $user_id, array $ids ): void {
		$this->write_read( $user_id, $this->with_linked( $user_id, $ids ) );
	}

	/**
	 * Mark items unread, and the items linked to them (see with_links()).
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $ids     Clean IDs.
	 * @return void
	 */
	public function mark_unread( int $user_id, array $ids ): void {
		$unread = $this->ids( $user_id, self::UNREAD_META );

		foreach ( $this->with_linked( $user_id, $ids ) as $id ) {
			delete_user_meta( $user_id, self::READ_META, $id );

			if ( ! isset( $unread[ $id ] ) ) {
				add_user_meta( $user_id, self::UNREAD_META, $id );
			}
		}
	}

	/**
	 * Record items as read, exactly these IDs.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $ids     Clean IDs.
	 * @return void
	 */
	private function write_read( int $user_id, array $ids ): void {
		$read = $this->ids( $user_id, self::READ_META );

		foreach ( $ids as $id ) {
			delete_user_meta( $user_id, self::UNREAD_META, $id );

			if ( ! isset( $read[ $id ] ) ) {
				add_user_meta( $user_id, self::READ_META, $id );
			}
		}
	}

	/**
	 * Mark everything read: move the watermark to now and clear the
	 * per-item rows. Undated items (subscription issues, plugin overlaps)
	 * get an explicit read row, since the watermark can't cover them.
	 *
	 * @param int      $user_id     User ID.
	 * @param string[] $undated_ids IDs of current items with no stable date.
	 * @return void
	 */
	public function mark_all_read( int $user_id, array $undated_ids ): void {
		update_user_meta( $user_id, self::READ_BEFORE_META, time() );
		delete_user_meta( $user_id, self::READ_META );
		delete_user_meta( $user_id, self::UNREAD_META );

		foreach ( array_unique( $undated_ids ) as $id ) {
			add_user_meta( $user_id, self::READ_META, $id );
		}
	}

	/**
	 * Archive items, and the items linked to them (see with_links()). An
	 * archived item also counts as read.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $ids     Clean IDs.
	 * @return void
	 */
	public function archive( int $user_id, array $ids ): void {
		$ids      = $this->with_linked( $user_id, $ids );
		$archived = $this->ids( $user_id, self::ARCHIVED_META );

		foreach ( $ids as $id ) {
			if ( ! isset( $archived[ $id ] ) ) {
				add_user_meta( $user_id, self::ARCHIVED_META, $id );
			}
		}

		$this->write_read( $user_id, $ids );
	}

	/**
	 * Bring archived items back, and the items linked to them (see
	 * with_links()).
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $ids     Clean IDs.
	 * @return void
	 */
	public function unarchive( int $user_id, array $ids ): void {
		foreach ( $this->with_linked( $user_id, $ids ) as $id ) {
			delete_user_meta( $user_id, self::ARCHIVED_META, $id );
		}
	}

	/**
	 * The current links between replies Daymark found on followed sites
	 * and the approved Webmention comments that brought the same replies
	 * to the user's Marks (see Daymark_Comment_Replies::superseded()).
	 *
	 * @param int $user_id User ID.
	 * @return array<string, string> Comment item ID => found reply item ID.
	 */
	public static function links_for( int $user_id ): array {
		return wp_list_pluck( Daymark_Comment_Replies::superseded( $user_id ), 'from' );
	}

	/**
	 * The IDs plus every ID linked to them: a Webmention comment brings
	 * the found reply it copies, and a found reply brings every comment
	 * that copies it. Archiving, reading, or bringing back one of them
	 * then does the same to the others, so the reply can't come back
	 * under its other ID.
	 *
	 * @param string[]              $ids   Clean IDs.
	 * @param array<string, string> $links Comment item ID => found reply item ID.
	 * @return string[]
	 */
	public static function with_links( array $ids, array $links ): array {
		if ( array() === $links ) {
			return $ids;
		}

		$selected = array_fill_keys( $ids, true );

		foreach ( $ids as $id ) {
			if ( isset( $links[ $id ] ) ) {
				$selected[ $links[ $id ] ] = true;
			}
		}

		foreach ( $links as $copy => $from ) {
			if ( isset( $selected[ $from ] ) ) {
				$selected[ (string) $copy ] = true;
			}
		}

		return array_map( 'strval', array_keys( $selected ) );
	}

	/**
	 * Bring a found reply's state to the Webmention comment that now
	 * stands in for it, and back (issue #559). Archived on either side
	 * means archived on both. For read and unread, a state set on the
	 * found reply wins, since the comment was hidden until it was approved;
	 * otherwise the comment's own state is given to the reply. Writes only
	 * what changes.
	 *
	 * @param int                   $user_id User ID.
	 * @param array<string, string> $links   Comment item ID => found reply item ID.
	 * @return void
	 */
	public function carry_over( int $user_id, array $links ): void {
		if ( $user_id <= 0 || array() === $links ) {
			return;
		}

		$archived = $this->ids( $user_id, self::ARCHIVED_META );
		$read     = $this->ids( $user_id, self::READ_META );
		$unread   = $this->ids( $user_id, self::UNREAD_META );

		foreach ( $links as $copy => $from ) {
			$copy = (string) $copy;
			$from = (string) $from;
			$pair = array( $copy, $from );

			if ( $copy === $from || ! self::is_valid_id( $copy ) || ! self::is_valid_id( $from ) ) {
				continue;
			}

			if ( isset( $archived[ $copy ] ) || isset( $archived[ $from ] ) ) {
				foreach ( $pair as $id ) {
					if ( ! isset( $archived[ $id ] ) ) {
						add_user_meta( $user_id, self::ARCHIVED_META, $id );
						$archived[ $id ] = true;
					}
				}
			}

			if ( isset( $read[ $from ] ) || isset( $unread[ $from ] ) ) {
				$is_read = isset( $read[ $from ] );
			} elseif ( isset( $read[ $copy ] ) || isset( $unread[ $copy ] ) ) {
				$is_read = isset( $read[ $copy ] );
			} else {
				continue;
			}

			foreach ( $pair as $id ) {
				if ( $is_read ? isset( $unread[ $id ] ) : isset( $read[ $id ] ) ) {
					delete_user_meta( $user_id, $is_read ? self::UNREAD_META : self::READ_META, $id );
				}

				if ( $is_read ? ! isset( $read[ $id ] ) : ! isset( $unread[ $id ] ) ) {
					add_user_meta( $user_id, $is_read ? self::READ_META : self::UNREAD_META, $id );
				}

				if ( $is_read ) {
					$read[ $id ] = true;
					unset( $unread[ $id ] );
				} else {
					$unread[ $id ] = true;
					unset( $read[ $id ] );
				}
			}
		}
	}

	/**
	 * The IDs plus the IDs linked to them now, for a write the user asked
	 * for. Only comment and found-reply IDs can be linked.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $ids     Clean IDs.
	 * @return string[]
	 */
	private function with_linked( int $user_id, array $ids ): array {
		foreach ( $ids as $id ) {
			if ( 0 === strpos( (string) $id, 'comment' ) ) {
				return self::with_links( $ids, self::links_for( $user_id ) );
			}
		}

		return $ids;
	}

	/**
	 * Drop stored state for subscription-issue, plugin-overlap, and
	 * followed-site reply IDs that no longer match a current item, so a
	 * site that recovers and fails again later, or a plugin turned back
	 * on, shows up again, and old replies leave no rows behind.
	 *
	 * @param int      $user_id     User ID.
	 * @param string[] $current_ids Every current item ID, archived included,
	 *                              plus found replies a comment stands in for.
	 * @return void
	 */
	public function prune( int $user_id, array $current_ids ): void {
		$current = array_fill_keys( $current_ids, true );

		foreach ( array( self::READ_META, self::UNREAD_META, self::ARCHIVED_META ) as $meta_key ) {
			foreach ( array_keys( $this->ids( $user_id, $meta_key ) ) as $id ) {
				$type = substr( $id, 0, (int) strpos( $id, '-' ) );

				if ( in_array( $type, self::TRANSIENT_TYPES, true ) && ! isset( $current[ $id ] ) ) {
					delete_user_meta( $user_id, $meta_key, $id );
				}
			}
		}
	}

	/**
	 * Forget a deleted comment's state for every user.
	 *
	 * @param int|string $comment_id Comment ID.
	 * @return void
	 */
	public function forget_comment( $comment_id ): void {
		$id = 'comment-' . absint( $comment_id );

		foreach ( array( self::READ_META, self::UNREAD_META, self::ARCHIVED_META ) as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, $id, true );
		}
	}
}
