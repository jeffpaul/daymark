<?php
/**
 * ATmosphere controllable-adapter tests.
 *
 * ATmosphere has no per-post filter; its documented per-post control is the
 * `atmosphere_disabled` opt-out meta. Daymark offers a per-Mark toggle only
 * when ATmosphere is connected and auto-publishing, and translates the
 * toggle into that meta on the publish transition.
 *
 * Curly-brace namespaces below stub ATmosphere's public API in-process so
 * the adapter can be exercised without loading the real plugin. PHPCS
 * forbids that syntax and mixed OO/function files; both are intentional
 * here and scoped to this fixture only.
 *
 * @package Daymark
 */

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden
// phpcs:disable Universal.Namespaces.OneDeclarationPerFile.MultipleFound
// phpcs:disable Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

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
	if ( ! function_exists( 'Atmosphere\\get_identity' ) ) {
		function get_identity() {
			return $GLOBALS['daymark_test_atmo_identity'] ?? array();
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
			unset( $GLOBALS['daymark_test_atmo_connected'], $GLOBALS['daymark_test_atmo_autopub'], $GLOBALS['daymark_test_atmo_identity'] );
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

		/** The wordpress.org copy (folder `atmosphere`) is offered, not only the GitHub one. */
		public function test_offered_from_wordpress_org_folder() {
			update_option( 'active_plugins', array( 'atmosphere/atmosphere.php' ) );

			$this->assertContains( 'atmosphere', Daymark_Publish_Helpers::controllable_ids() );
		}

		/** The toggle reads as a Bluesky destination and starts on, as ATmosphere would. */
		public function test_toggle_reads_as_bluesky_and_starts_on() {
			$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );

			$this->assertSame( 'Bluesky via ATmosphere', $helpers['atmosphere']['label'] );
			$this->assertSame( 'ATmosphere', $helpers['atmosphere']['plugin'] );
			$this->assertSame( 'Bluesky', $helpers['atmosphere']['network'] );
			$this->assertTrue( $helpers['atmosphere']['default'] );
		}

		/** The toggle carries the Bluesky handle ATmosphere is connected as. */
		public function test_toggle_carries_the_connected_bluesky_handle() {
			$GLOBALS['daymark_test_atmo_identity'] = array(
				'did'    => 'did:plc:example',
				'handle' => 'jeffpaul.com',
			);

			$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );

			$this->assertSame( '@jeffpaul.com', $helpers['atmosphere']['account'] );
		}

		/** No handle on file means no account line, not an empty "@". */
		public function test_toggle_has_no_account_without_a_handle() {
			$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );
			$this->assertSame( '', $helpers['atmosphere']['account'] );

			$GLOBALS['daymark_test_atmo_identity'] = array(
				'did'    => 'did:plc:example',
				'handle' => '',
			);

			$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );
			$this->assertSame( '', $helpers['atmosphere']['account'] );
		}

		/** The "Where this goes" sheet names Bluesky only while ATmosphere would share, tied to its toggle. */
		public function test_reach_names_bluesky_tied_to_the_toggle() {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

			$this->assertContains(
				array(
					'label'  => 'Bluesky',
					'helper' => 'atmosphere',
				),
				Daymark_Routes::build_app_config()['reach']
			);

			$GLOBALS['daymark_test_atmo_autopub'] = false;
			$this->assertNotContains( 'atmosphere', wp_list_pluck( Daymark_Routes::build_app_config()['reach'], 'helper' ) );
		}

		/** Switching the toggle off skips Bluesky for that Mark only. */
		public function test_switching_off_skips_bluesky_for_that_mark_only() {
			update_option( 'active_plugins', array( 'atmosphere/atmosphere.php' ) );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
			Daymark_Publish_Helpers::register_adapters();

			$publisher = new Daymark_Publisher();
			$off       = (int) $publisher->publish(
				array(
					'caption'         => 'Not to Bluesky',
					'publish_helpers' => array(),
				)
			);
			$on        = (int) $publisher->publish(
				array(
					'caption'         => 'To Bluesky',
					'publish_helpers' => array( 'atmosphere' ),
				)
			);

			$this->assertSame( 'publish', get_post_status( $off ) );
			$this->assertSame( '1', (string) get_post_meta( $off, 'atmosphere_disabled', true ) );
			$this->assertSame( '', (string) get_post_meta( $on, 'atmosphere_disabled', true ) );
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

// phpcs:enable
