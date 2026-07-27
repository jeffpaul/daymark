<?php
/**
 * Bluesky connector ATmosphere-transport tests (stubbed ATmosphere API).
 *
 * @package Moment
 */

namespace Atmosphere {
	// Minimal fake of ATmosphere's public API, toggled via globals.
	if ( ! function_exists( 'Atmosphere\\is_connected' ) ) {
		/**
		 * @return bool
		 */
		function is_connected(): bool {
			return ! empty( $GLOBALS['atmo_connected'] );
		}
		/**
		 * @return bool
		 */
		function is_auto_publish_enabled(): bool {
			return ! empty( $GLOBALS['atmo_autopub'] );
		}
		/**
		 * Fake ATmosphere publisher.
		 */
		class Publisher {
			/**
			 * @param \WP_Post $post          Post.
			 * @param bool     $original_time Unused.
			 * @return array|\WP_Error
			 */
			public static function publish_post( \WP_Post $post, bool $original_time = false ) {
				if ( ! empty( $GLOBALS['atmo_fail'] ) ) {
					return new \WP_Error( 'atmo', 'boom' );
				}
				update_post_meta( $post->ID, '_atmosphere_bsky_uri', 'at://did:plc:test/app.bsky.feed.post/abc123' );
				return array( 'ok' => true );
			}
		}
	}
}

namespace {

	/**
	 * Exercises the Bluesky connector's ATmosphere transport cascade.
	 */
	class Test_Bluesky_Atmosphere extends WP_UnitTestCase {

		public static function set_up_before_class() {
			parent::set_up_before_class();
			// The connector's main file isn't loaded in the test suite; its
			// setting-name constants are needed by the integration class.
			if ( ! defined( 'MOMENT_BLUESKY_PASSWORD_SETTING' ) ) {
				define( 'MOMENT_BLUESKY_PASSWORD_SETTING', 'connectors_social_bluesky_app_password' );
			}
			if ( ! defined( 'MOMENT_BLUESKY_HANDLE_SETTING' ) ) {
				define( 'MOMENT_BLUESKY_HANDLE_SETTING', 'moment_bluesky_handle' );
			}
			$dir = dirname( __DIR__ ) . '/moment-connector-bluesky/includes/';
			require_once $dir . 'class-bluesky-atmosphere.php';
			require_once $dir . 'class-bluesky-client.php';
			require_once $dir . 'class-bluesky-integration.php';
			require_once $dir . 'class-bluesky-connector.php';
		}

		public function set_up(): void {
			parent::set_up();
			$GLOBALS['atmo_connected'] = false;
			$GLOBALS['atmo_autopub']   = false;
			$GLOBALS['atmo_fail']      = false;
		}

		/** Connected + auto-publish off → Moment drives via ATmosphere. */
		public function test_drives_via_atmosphere_when_autopublish_off() {
			$GLOBALS['atmo_connected'] = true;
			$GLOBALS['atmo_autopub']   = false;

			$connector = new Moment_Bluesky_Connector();
			$this->assertTrue( $connector->is_connected() );
			$this->assertStringContainsString( 'ATmosphere', $connector->get_status_label() );

			$post_id = (int) self::factory()->post->create();
			update_post_meta( $post_id, 'atmosphere_disabled', '1' ); // should be cleared on publish
			$result = $connector->publish( $post_id, array( 'caption' => 'Hi' ) );

			$this->assertTrue( $result['success'] );
			$this->assertSame( 'published', $result['status'] );
			$this->assertSame( 'at://did:plc:test/app.bsky.feed.post/abc123', $result['external_id'] );
			$this->assertStringContainsString( 'bsky.app/profile/did:plc:test/post/abc123', $result['external_url'] );
			$this->assertFalse( $result['backflow_supported'], 'ATmosphere owns backflow for its posts' );
			$this->assertSame( '', get_post_meta( $post_id, 'atmosphere_disabled', true ), 'Opt-out must be cleared' );
		}

		/** Connected + auto-publish on → destination withheld (no double-post). */
		public function test_withheld_when_atmosphere_autoposts() {
			$GLOBALS['atmo_connected'] = true;
			$GLOBALS['atmo_autopub']   = true;

			// Even with an app password configured, don't offer the toggle.
			update_option( MOMENT_BLUESKY_HANDLE_SETTING, 'demo.bsky.social' );
			update_option( MOMENT_BLUESKY_PASSWORD_SETTING, 'app-pass' );

			$connector = new Moment_Bluesky_Connector();
			$this->assertTrue( Moment_Bluesky_Atmosphere::would_autopost() );
			$this->assertFalse( $connector->is_connected(), 'ATmosphere posts everything; withhold the toggle' );
		}

		/** ATmosphere present but not connected → app-password path governs. */
		public function test_falls_back_to_app_password_when_atmosphere_disconnected() {
			$GLOBALS['atmo_connected'] = false;

			update_option( MOMENT_BLUESKY_HANDLE_SETTING, 'demo.bsky.social' );
			update_option( MOMENT_BLUESKY_PASSWORD_SETTING, 'app-pass' );

			$connector = new Moment_Bluesky_Connector();
			$this->assertFalse( Moment_Bluesky_Atmosphere::can_drive() );
			$this->assertTrue( $connector->is_connected(), 'App-password connection still offered' );
			$this->assertSame( 'Connected', $connector->get_status_label() );
		}

		/** A failed ATmosphere publish never blocks — returns a failed result. */
		public function test_atmosphere_publish_failure_is_soft() {
			$GLOBALS['atmo_connected'] = true;
			$GLOBALS['atmo_fail']      = true;

			$post_id = (int) self::factory()->post->create();
			$result  = ( new Moment_Bluesky_Connector() )->publish( $post_id, array( 'caption' => 'Hi' ) );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 'failed', $result['status'] );
			$this->assertSame( 'boom', $result['message'] );
		}
	}
}
