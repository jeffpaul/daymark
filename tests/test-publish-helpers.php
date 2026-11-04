<?php
/**
 * Third-party publishing-plugin detection tests.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Publish_Helpers detection (awareness only).
 */
class Test_Publish_Helpers extends WP_UnitTestCase {

	/** With no syndication plugins active, nothing is detected. */
	public function test_detects_nothing_by_default() {
		$this->assertSame( array(), Daymark_Publish_Helpers::detect() );
	}

	/** The known publishing plugins ship in the default definitions. */
	public function test_known_plugins_are_defined() {
		$defs = Daymark_Publish_Helpers::definitions();

		foreach ( array( 'jetpack', 'atmosphere', 'autoblue', 'share-on-mastodon', 'xposter', 'autoshare-for-twitter', 'blog2social', 'snap', 'revive-old-posts' ) as $id ) {
			$this->assertArrayHasKey( $id, $defs, "Expected {$id} in default definitions" );
		}
	}

	/** An active plugin slug is detected and reported with its label. */
	public function test_detects_by_active_plugin_slug() {
		$filter = static function ( $plugins ) {
			$plugins['share-on-mastodon'] = array(
				'label' => 'Share on Mastodon',
				'slugs' => array( 'share-on-mastodon' ),
			);

			return $plugins;
		};

		// Simulate the plugin being active.
		add_filter(
			'option_active_plugins',
			static function ( $value ) {
				$value   = (array) $value;
				$value[] = 'share-on-mastodon/share-on-mastodon.php';

				return $value;
			}
		);

		$found = Daymark_Publish_Helpers::detect();
		$ids   = wp_list_pluck( $found, 'id' );

		$this->assertContains( 'share-on-mastodon', $ids );
		$label = $found[ array_search( 'share-on-mastodon', $ids, true ) ]['label'];
		$this->assertSame( 'Share on Mastodon', $label );

		unset( $filter );
	}

	/** A runtime class signature is detected (module-precise plugins). */
	public function test_detects_by_class_signature() {
		// A dummy plugin definition pointing at a class we define here.
		if ( ! class_exists( 'Daymark_Test_Publicize' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only stub class; no user input.
			eval( 'class Daymark_Test_Publicize {}' );
		}

		$filter = static function ( $plugins ) {
			$plugins['dummy'] = array(
				'label'   => 'Dummy Publicize',
				'classes' => array( 'Daymark_Test_Publicize' ),
			);

			return $plugins;
		};
		add_filter( 'daymark_publish_helper_plugins', $filter );

		$ids = wp_list_pluck( Daymark_Publish_Helpers::detect(), 'id' );
		$this->assertContains( 'dummy', $ids );

		remove_filter( 'daymark_publish_helper_plugins', $filter );
		$this->assertNotContains( 'dummy', wp_list_pluck( Daymark_Publish_Helpers::detect(), 'id' ) );
	}

	/** Third parties can register their own plugin via the filter. */
	public function test_definitions_are_filterable() {
		$filter = static function ( $plugins ) {
			$plugins['custom'] = array(
				'label'     => 'Custom',
				'constants' => array( 'DAYMARK_TEST_CUSTOM_HELPER' ),
			);

			return $plugins;
		};
		add_filter( 'daymark_publish_helper_plugins', $filter );

		$this->assertArrayHasKey( 'custom', Daymark_Publish_Helpers::definitions() );

		remove_filter( 'daymark_publish_helper_plugins', $filter );
	}

	/** Mark a plugin folder slug active for the current test. */
	private function activate_slug( string $slug ): void {
		update_option( 'active_plugins', array( $slug . '/' . $slug . '.php' ) );
	}

	// --- Controllable adapters (per-Mark toggle) ---

	/** Only active adapter plugins are offered as controllable helpers. */
	public function test_controllable_lists_active_adapters_only() {
		$this->assertSame( array(), Daymark_Publish_Helpers::controllable() );

		$this->activate_slug( 'share-on-mastodon' );

		$ids = Daymark_Publish_Helpers::controllable_ids();
		$this->assertContains( 'share-on-mastodon', $ids );
		$this->assertNotContains( 'autoshare-for-twitter', $ids, 'Inactive adapters are not controllable' );
	}

	/** Share on Mastodon's toggle reads as a Mastodon destination and starts off. */
	public function test_share_on_mastodon_reads_as_destination_and_starts_off() {
		$this->activate_slug( 'share-on-mastodon' );

		$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );

		$this->assertSame( 'Mastodon via Share on Mastodon', $helpers['share-on-mastodon']['label'] );
		$this->assertSame( 'Mastodon', $helpers['share-on-mastodon']['network'] );
		$this->assertFalse( $helpers['share-on-mastodon']['default'] );
	}

	/** An adapter is found by its plugin's class, like the awareness note finds it, not only by folder. */
	public function test_adapter_detected_by_its_plugins_class() {
		$filter = static function ( $plugins ) {
			$plugins['share-on-mastodon']['classes'] = array( 'Daymark_Test_Fake_Connector_Class' );

			return $plugins;
		};
		add_filter( 'daymark_publish_helper_plugins', $filter );

		$this->assertContains( 'share-on-mastodon', Daymark_Publish_Helpers::controllable_ids() );

		remove_filter( 'daymark_publish_helper_plugins', $filter );
		$this->assertNotContains( 'share-on-mastodon', Daymark_Publish_Helpers::controllable_ids() );
	}

	/** An adapter added through the filter can bring its own signals, network, and default. */
	public function test_filtered_adapter_brings_its_own_signals() {
		$filter = static function ( $adapters ) {
			$adapters['custom'] = array(
				'label'   => 'Custom Poster',
				'network' => 'Example',
				'default' => true,
				'classes' => array( 'Daymark_Test_Fake_Connector_Class' ),
				'bind'    => '__return_null',
			);

			return $adapters;
		};
		add_filter( 'daymark_publish_helper_adapters', $filter );

		$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );

		$this->assertSame( 'Example via Custom Poster', $helpers['custom']['label'] );
		$this->assertSame( 'Custom Poster', $helpers['custom']['plugin'] );
		$this->assertTrue( $helpers['custom']['default'] );

		remove_filter( 'daymark_publish_helper_adapters', $filter );
	}

	/** A Mark that never recorded a choice reports null helpers, so the composer starts each toggle at its default. */
	public function test_mark_detail_reports_null_helpers_without_a_choice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$publisher = new Daymark_Publisher();
		$none      = (int) $publisher->publish(
			array(
				'caption' => 'No choice yet',
				'status'  => 'draft',
			)
		);
		$chosen    = (int) $publisher->publish(
			array(
				'caption'         => 'Everything off',
				'status'          => 'draft',
				'publish_helpers' => array(),
			)
		);

		$this->assertNull( $this->mark_detail( $none )['helpers'] );
		$this->assertSame( array(), $this->mark_detail( $chosen )['helpers'] );
	}

	/**
	 * GET /daymark/v1/marks/{id} as the current user.
	 *
	 * @param int $post_id Mark ID.
	 * @return array<string, mixed>
	 */
	private function mark_detail( int $post_id ): array {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/marks/' . $post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return (array) rest_do_request( $request )->get_data();
	}

	/** The adapter's control filter governs only Mark posts that recorded a selection. */
	public function test_adapter_filter_governs_by_selection() {
		$this->activate_slug( 'share-on-mastodon' );
		Daymark_Publish_Helpers::register_adapters();

		$post_id = (int) self::factory()->post->create();

		// Non-Mark post: the plugin's own decision passes through untouched.
		$this->assertFalse( apply_filters( 'share_on_mastodon_enabled', false, $post_id ) );
		$this->assertTrue( apply_filters( 'share_on_mastodon_enabled', true, $post_id ) );

		// Mark post with no recorded selection: still untouched.
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		$this->assertTrue( apply_filters( 'share_on_mastodon_enabled', true, $post_id ) );

		// Selected → forced on regardless of the plugin's fallback.
		update_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, wp_json_encode( array( 'share-on-mastodon' ) ) );
		$this->assertTrue( apply_filters( 'share_on_mastodon_enabled', false, $post_id ) );

		// Authoritative empty selection → forced off.
		update_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, wp_json_encode( array() ) );
		$this->assertFalse( apply_filters( 'share_on_mastodon_enabled', true, $post_id ) );
	}

	/** The publisher records the selection and publishes (deferred path). */
	public function test_publisher_records_selection_and_publishes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->activate_slug( 'share-on-mastodon' );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'         => 'Helper on',
				'publish_helpers' => array( 'share-on-mastodon' ),
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame(
			array( 'share-on-mastodon' ),
			json_decode( (string) get_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, true ), true )
		);
	}

	/** An absent publish_helpers field leaves no selection meta. */
	public function test_publisher_omits_meta_when_field_absent() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish( array( 'caption' => 'No helpers' ) );

		$this->assertSame( '', get_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, true ) );
	}

	// --- Share on Mastodon's own per-post switch ---

	/**
	 * Share on Mastodon won't share a post dated more than 12 hours ago
	 * unless the post's own switch (`_share_on_mastodon`) is on, and it
	 * checks that before it asks its filter. A Mark dated by a photo taken
	 * yesterday is that old, so the Mark's choice is copied into that
	 * switch as the Mark goes live.
	 */
	public function test_mastodon_choice_is_copied_into_share_on_mastodons_switch() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->activate_slug( 'share-on-mastodon' );
		Daymark_Publish_Helpers::register_adapters();

		$publisher = new Daymark_Publisher();
		$yesterday = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$on        = (int) $publisher->publish(
			array(
				'caption'         => 'Mastodon on',
				'captured_at'     => $yesterday,
				'publish_helpers' => array( 'share-on-mastodon' ),
			)
		);
		$off       = (int) $publisher->publish(
			array(
				'caption'         => 'Mastodon off',
				'captured_at'     => $yesterday,
				'publish_helpers' => array(),
			)
		);

		$this->assertSame( 'publish', get_post_status( $on ) );
		$this->assertSame( '1', get_post_meta( $on, '_share_on_mastodon', true ) );
		$this->assertSame( '0', get_post_meta( $off, '_share_on_mastodon', true ) );
	}

	/** A Mark with no recorded choice, a draft, and a post that isn't a Mark keep Share on Mastodon's switch as it was. */
	public function test_share_on_mastodons_switch_left_alone_without_a_published_choice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->activate_slug( 'share-on-mastodon' );
		Daymark_Publish_Helpers::register_adapters();

		$publisher = new Daymark_Publisher();
		$no_choice = (int) $publisher->publish( array( 'caption' => 'No choice' ) );
		$draft     = (int) $publisher->publish(
			array(
				'caption'         => 'Not yet',
				'status'          => 'draft',
				'publish_helpers' => array( 'share-on-mastodon' ),
			)
		);
		// Saved again as a draft: the choice is on record by now.
		$publisher->update(
			$draft,
			array(
				'caption'         => 'Still not yet',
				'status'          => 'draft',
				'publish_helpers' => array( 'share-on-mastodon' ),
			)
		);
		$not_mark = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( 'publish', get_post_status( $no_choice ) );
		$this->assertSame( 'draft', get_post_status( $draft ) );
		foreach ( array( $no_choice, $draft, $not_mark ) as $post_id ) {
			$this->assertSame( '', get_post_meta( $post_id, '_share_on_mastodon', true ), "Post {$post_id}" );
		}
	}

	/**
	 * Share on Mastodon and Autopost for X both put off sharing a post
	 * saved over the REST API until `rest_after_insert_post`. When the app
	 * publishes a Mark dated yesterday with both switches on, that action
	 * fires and each plugin finds the Mark's choice in place.
	 */
	public function test_sharing_plugins_find_the_marks_choice_when_the_app_publishes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		update_option(
			'active_plugins',
			array( 'share-on-mastodon/share-on-mastodon.php', 'autoshare-for-twitter/autoshare-for-twitter.php' )
		);
		Daymark_Publish_Helpers::register_adapters();

		$seen = array();
		add_action(
			'rest_after_insert_post',
			static function ( $post ) use ( &$seen ) {
				$seen[] = array(
					'mastodon_switch' => get_post_meta( $post->ID, '_share_on_mastodon', true ),
					'mastodon'        => apply_filters( 'share_on_mastodon_enabled', false, $post->ID ),
					'x'               => apply_filters( 'autoshare_for_twitter_enabled_default', false, $post->post_type, $post->ID ),
				);
			}
		);

		$request = new WP_REST_Request( 'POST', '/daymark/v1/marks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'Last night' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'captured_at', gmdate( 'c', time() - DAY_IN_SECONDS ) );
		$request->set_param( 'publish_helpers', wp_json_encode( array( 'share-on-mastodon', 'autoshare-for-twitter' ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'mastodon_switch' => '1',
					'mastodon'        => true,
					'x'               => true,
				),
			),
			$seen
		);
	}

	/** Unknown/inactive helper ids are dropped from the stored selection. */
	public function test_publisher_drops_inactive_ids() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		// share-on-mastodon is NOT active here.
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'         => 'x',
				'publish_helpers' => array( 'share-on-mastodon', 'bogus' ),
			)
		);

		$this->assertSame(
			array(),
			json_decode( (string) get_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, true ), true )
		);
	}
}
