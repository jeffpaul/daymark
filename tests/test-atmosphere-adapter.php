<?php
/**
 * ATmosphere controllable-adapter tests.
 *
 * ATmosphere has no per-post filter; its documented per-post control is the
 * `atmosphere_disabled` opt-out meta. Daymark offers a per-Mark toggle only
 * when ATmosphere is connected and auto-publishing, and translates the
 * toggle into that meta on the publish transition.
 *
 * @package Daymark
 */

namespace Atmosphere {
	// Minimal stubs for ATmosphere's public API, toggled via globals.
	if ( ! function_exists( 'Atmosphere\\is_connected' ) ) {
		function is_connected() {
			return $GLOBALS['daymark_test_atmo_connected'] ?? true;
		}
	}
	if ( ! function_exists( 'Atmosphere\\is_auto_publish_enabled' ) ) {
		function is_auto_publish_enabled() {
			return $GLOBALS['daymark_test_atmo_autopub'] ?? true;
		}
	}
}

namespace {

	/**
	 * Tests the ATmosphere controllable adapter.
	 */
	class Test_Atmosphere_Adapter extends WP_UnitTestCase {

		public function set_up(): void {
			parent::set_up();
			$GLOBALS['daymark_test_atmo_connected'] = true;
			$GLOBALS['daymark_test_atmo_autopub']   = true;
			update_option( 'active_plugins', array( 'wordpress-atmosphere/wordpress-atmosphere.php' ) );
		}

		public function tear_down(): void {
			unset( $GLOBALS['daymark_test_atmo_connected'], $GLOBALS['daymark_test_atmo_autopub'] );
			parent::tear_down();
		}

		/** Offered as a toggle when connected and auto-publishing. */
		public function test_offered_when_autopublishing() {
			$this->assertContains( 'atmosphere', Daymark_Publish_Helpers::controllable_ids() );
		}

		/** Not offered in connection-only mode (auto-publish off). */
		public function test_not_offered_in_connection_only_mode() {
			$GLOBALS['daymark_test_atmo_autopub'] = false;
			$this->assertNotContains( 'atmosphere', Daymark_Publish_Helpers::controllable_ids() );
		}

		private function publish_daymark_with_selection( array $selection ): int {
			$post_id = (int) self::factory()->post->create(
				array(
					'post_status' => 'draft',
					'post_type'   => 'post',
				)
			);
			update_post_meta( $post_id, '_daymark_is_mark', '1' );
			update_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, wp_json_encode( $selection ) );

			Daymark_Publish_Helpers::register_adapters();
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			return $post_id;
		}

		/** Toggling ATmosphere off sets its opt-out meta before it publishes. */
		public function test_toggle_off_sets_disabled_meta() {
			$post_id = $this->publish_daymark_with_selection( array( 'share-on-mastodon' ) );
			$this->assertSame( '1', (string) get_post_meta( $post_id, 'atmosphere_disabled', true ) );
		}

		/** Toggling ATmosphere on clears any opt-out meta so it publishes. */
		public function test_toggle_on_clears_disabled_meta() {
			$post_id = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );
			update_post_meta( $post_id, '_daymark_is_mark', '1' );
			update_post_meta( $post_id, 'atmosphere_disabled', '1' );
			update_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, wp_json_encode( array( 'atmosphere' ) ) );

			Daymark_Publish_Helpers::register_adapters();
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			$this->assertSame( '', (string) get_post_meta( $post_id, 'atmosphere_disabled', true ) );
		}

		/** A Mark with no recorded selection leaves ATmosphere untouched. */
		public function test_no_selection_leaves_atmosphere_untouched() {
			$post_id = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );
			update_post_meta( $post_id, '_daymark_is_mark', '1' );
			update_post_meta( $post_id, 'atmosphere_disabled', '1' );

			Daymark_Publish_Helpers::register_adapters();
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			$this->assertSame( '1', (string) get_post_meta( $post_id, 'atmosphere_disabled', true ), 'No selection must not touch ATmosphere' );
		}
	}
}
