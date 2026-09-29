<?php
/**
 * Stub `Activitypub\Collection\Outbox` (see load.php).
 *
 * @package Daymark
 */

namespace Activitypub\Collection;

if ( ! class_exists( 'Activitypub\\Collection\\Outbox' ) ) {
	/**
	 * Records undo() and stores an Undo outbox row.
	 */
	class Outbox {

		/**
		 * Stub undo.
		 *
		 * @param \WP_Post|int $outbox_item Outbox item.
		 * @return int
		 */
		public static function undo( $outbox_item ) {
			$item    = get_post( $outbox_item );
			$undo_id = \Daymark_Test_ActivityPub_Stub::insert_outbox_row( 'Undo', (string) $item->ID, (int) $item->post_author );

			\Daymark_Test_ActivityPub_Stub::$undone[] = array(
				'outbox_id' => (int) $item->ID,
				'undo_id'   => $undo_id,
			);

			return $undo_id;
		}
	}
}
