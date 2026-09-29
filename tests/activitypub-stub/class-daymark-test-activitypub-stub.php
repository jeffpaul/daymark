<?php
/**
 * Shared, resettable state for the ActivityPub plugin stub (see load.php).
 *
 * @package Daymark
 */

if ( ! class_exists( 'Daymark_Test_ActivityPub_Stub' ) ) {
	/**
	 * Records every call the stub receives and holds the answers it gives.
	 */
	class Daymark_Test_ActivityPub_Stub {

		/**
		 * Whether the stub (not a real plugin) owns the Activitypub API.
		 *
		 * @var bool
		 */
		public static $loaded = false;

		/**
		 * User IDs user_can_activitypub() says yes for.
		 *
		 * @var int[]
		 */
		public static $enabled_users = array();

		/**
		 * URL => ActivityStreams object array (or WP_Error) for get_remote_object().
		 *
		 * @var array<string, mixed>
		 */
		public static $remote_objects = array();

		/**
		 * Every get_remote_object() URL asked for, in order.
		 *
		 * @var string[]
		 */
		public static $fetches = array();

		/**
		 * Every add_to_outbox() call: {activity, user_id, visibility, outbox_id}.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public static $queued = array();

		/**
		 * Every Outbox::undo() call: {outbox_id, undo_id}.
		 *
		 * @var array<int, array<string, int>>
		 */
		public static $undone = array();

		/**
		 * When true, add_to_outbox() fails.
		 *
		 * @var bool
		 */
		public static $fail_queue = false;

		/**
		 * Reset to the inert default.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$enabled_users  = array();
			self::$remote_objects = array();
			self::$fetches        = array();
			self::$queued         = array();
			self::$undone         = array();
			self::$fail_queue     = false;
		}

		/**
		 * Whether the stub is the loaded implementation.
		 *
		 * @return bool
		 */
		public static function is_loaded(): bool {
			return self::$loaded;
		}

		/**
		 * Store a fake outbox row, the way the real plugin's `ap_outbox`
		 * post type does (starts `pending`).
		 *
		 * @param string $type      Activity type.
		 * @param string $object_id Object ID.
		 * @param int    $user_id   Actor user ID.
		 * @return int Outbox post ID.
		 */
		public static function insert_outbox_row( string $type, string $object_id, int $user_id ): int {
			$id = wp_insert_post(
				array(
					'post_type'   => 'ap_outbox',
					'post_status' => 'pending',
					'post_title'  => $type,
					'post_author' => $user_id,
				)
			);

			update_post_meta( $id, '_activitypub_activity_type', $type );
			update_post_meta( $id, '_activitypub_object_id', $object_id );

			return (int) $id;
		}
	}
}
