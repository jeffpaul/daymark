<?php
/**
 * Daymark_Settings tests: each setting reads its option, a developer
 * filter still wins, and the places that read a setting honor it.
 *
 * @package Daymark
 */

/**
 * Daymark_Settings coverage.
 */
class Test_Settings extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( Daymark_Settings::options() as $option ) {
			delete_option( $option );
		}
	}

	public function tear_down(): void {
		foreach ( Daymark_Settings::options() as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	/** With nothing stored, every setting has its documented default. */
	public function test_defaults(): void {
		$this->assertTrue( Daymark_Settings::capture_location() );
		$this->assertTrue( Daymark_Settings::capture_weather() );
		$this->assertTrue( Daymark_Settings::capture_camera_metadata() );
		$this->assertFalse( Daymark_Settings::publish_location_publicly() );
		$this->assertSame( 1, Daymark_Settings::imported_reply_approved() );
		$this->assertTrue( Daymark_Settings::ai_auto_suggest() );
		$this->assertSame( DAY_IN_SECONDS, Daymark_Settings::poll_interval() );
		$this->assertFalse( Daymark_Settings::blogroll_public() );
		$this->assertFalse( Daymark_Settings::hide_notes_on_home() );
	}

	/** Each setting follows its stored option. */
	public function test_stored_options_are_read(): void {
		update_option( Daymark_Settings::CAPTURE_LOCATION, '' );
		update_option( Daymark_Settings::HOLD_IMPORTED_REPLIES, '1' );
		update_option( Daymark_Settings::AI_AUTO_SUGGEST, '' );
		update_option( Daymark_Settings::POLL_INTERVAL, HOUR_IN_SECONDS );
		update_option( Daymark_Settings::BLOGROLL_PUBLIC, '1' );
		update_option( Daymark_Settings::HIDE_NOTES_ON_HOME, '1' );

		$this->assertFalse( Daymark_Settings::capture_location() );
		$this->assertSame( 0, Daymark_Settings::imported_reply_approved() );
		$this->assertFalse( Daymark_Settings::ai_auto_suggest() );
		$this->assertSame( HOUR_IN_SECONDS, Daymark_Settings::poll_interval() );
		$this->assertTrue( Daymark_Settings::blogroll_public() );
		$this->assertTrue( Daymark_Settings::hide_notes_on_home() );
	}

	/** A developer filter wins over the stored option. */
	public function test_filter_wins_over_option(): void {
		update_option( Daymark_Settings::AI_AUTO_SUGGEST, '1' );
		update_option( Daymark_Settings::HOLD_IMPORTED_REPLIES, '1' );

		add_filter( 'daymark_ai_auto_suggest', '__return_false' );
		add_filter( 'daymark_comment_import_approved', '__return_true' );

		$ai       = Daymark_Settings::ai_auto_suggest();
		$approved = Daymark_Settings::imported_reply_approved();

		remove_filter( 'daymark_ai_auto_suggest', '__return_false' );
		remove_filter( 'daymark_comment_import_approved', '__return_true' );

		$this->assertFalse( $ai );
		$this->assertSame( 1, $approved );
	}

	/** Uninstall's list covers every option this class reads. */
	public function test_options_lists_every_setting(): void {
		$this->assertCount( 12, array_unique( Daymark_Settings::options() ) );
	}

	/** The app config carries the AI auto-suggest and location capture settings. */
	public function test_app_config_exposes_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		update_option( Daymark_Settings::AI_AUTO_SUGGEST, '' );
		update_option( Daymark_Settings::CAPTURE_LOCATION, '' );

		$config = Daymark_Routes::build_app_config();

		$this->assertFalse( $config['ai']['autoSuggest'] );
		$this->assertFalse( $config['capture']['location'] );
		$this->assertIsArray( $config['reach'] );
	}

	/** The app knows whether the user can publish, so a Contributor isn't told a draft was published. */
	public function test_app_config_says_whether_user_can_publish(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertFalse( Daymark_Routes::build_app_config()['canPublish'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( Daymark_Routes::build_app_config()['canPublish'] );
	}

	/** An Author gets no links into Settings -> Daymark, which they can't open. */
	public function test_app_config_omits_settings_links_for_non_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( '', $config['adminSubscriptionsUrl'] );
		$this->assertSame( '', $config['adminConnectorsUrl'] );
	}

	/** An administrator's config links to the Subscriptions and Connectors tabs. */
	public function test_app_config_links_settings_tabs_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( Daymark_Admin_Subscriptions::tab_url( 'subscriptions' ), $config['adminSubscriptionsUrl'] );
		$this->assertSame( Daymark_Admin_Subscriptions::tab_url( 'connectors' ), $config['adminConnectorsUrl'] );
	}

	/** With location capture off, the reverse-geocode route refuses before any lookup. */
	public function test_reverse_geocode_refuses_when_location_capture_off(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		update_option( Daymark_Settings::CAPTURE_LOCATION, '' );

		$requests = 0;
		$filter   = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/location/reverse-geocode' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'lat', 37.7749 );
		$request->set_param( 'lng', -122.4194 );
		$response = rest_do_request( $request );

		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'daymark_location_capture_off', $response->get_data()['code'] );
		$this->assertSame( 0, $requests );
	}

	/** With location capture off, a Check In sent with coordinates stores none. */
	public function test_checkin_location_dropped_when_capture_off(): void {
		update_option( Daymark_Settings::CAPTURE_LOCATION, '' );

		$post_id = ( new Daymark_Publisher() )->publish(
			array(
				'primary_type' => 'checkin',
				'place_name'   => 'Blue Bottle Coffee',
				'location_lat' => 37.7749,
				'location_lng' => -122.4194,
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_location', true ) );
	}
}
