<?php
/**
 * Third-party publishing-plugin detection tests.
 *
 * @package Moment
 */

/**
 * Tests Moment_Publish_Helpers detection (awareness only).
 */
class Test_Publish_Helpers extends WP_UnitTestCase {

	/** With no syndication plugins active, nothing is detected. */
	public function test_detects_nothing_by_default() {
		$this->assertSame( array(), Moment_Publish_Helpers::detect() );
	}

	/** The known publishing plugins ship in the default definitions. */
	public function test_known_plugins_are_defined() {
		$defs = Moment_Publish_Helpers::definitions();

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

		$found = Moment_Publish_Helpers::detect();
		$ids   = wp_list_pluck( $found, 'id' );

		$this->assertContains( 'share-on-mastodon', $ids );
		$label = $found[ array_search( 'share-on-mastodon', $ids, true ) ]['label'];
		$this->assertSame( 'Share on Mastodon', $label );

		unset( $filter );
	}

	/** A runtime class signature is detected (module-precise plugins). */
	public function test_detects_by_class_signature() {
		// A dummy plugin definition pointing at a class we define here.
		if ( ! class_exists( 'Moment_Test_Publicize' ) ) {
			// phpcs:ignore Squiz.Commenting.ClassComment.Missing, Generic.CodeAnalysis.EmptyStatement
			eval( 'class Moment_Test_Publicize {}' );
		}

		$filter = static function ( $plugins ) {
			$plugins['dummy'] = array(
				'label'   => 'Dummy Publicize',
				'classes' => array( 'Moment_Test_Publicize' ),
			);

			return $plugins;
		};
		add_filter( 'moment_publish_helper_plugins', $filter );

		$ids = wp_list_pluck( Moment_Publish_Helpers::detect(), 'id' );
		$this->assertContains( 'dummy', $ids );

		remove_filter( 'moment_publish_helper_plugins', $filter );
		$this->assertNotContains( 'dummy', wp_list_pluck( Moment_Publish_Helpers::detect(), 'id' ) );
	}

	/** Third parties can register their own plugin via the filter. */
	public function test_definitions_are_filterable() {
		$filter = static function ( $plugins ) {
			$plugins['custom'] = array(
				'label'     => 'Custom',
				'constants' => array( 'MOMENT_TEST_CUSTOM_HELPER' ),
			);

			return $plugins;
		};
		add_filter( 'moment_publish_helper_plugins', $filter );

		$this->assertArrayHasKey( 'custom', Moment_Publish_Helpers::definitions() );

		remove_filter( 'moment_publish_helper_plugins', $filter );
	}

	/** Mark a plugin folder slug active for the current test. */
	private function activate_slug( string $slug ): void {
		update_option( 'active_plugins', array( $slug . '/' . $slug . '.php' ) );
	}

	// --- Controllable adapters (per-Moment toggle) ---

	/** Only active adapter plugins are offered as controllable helpers. */
	public function test_controllable_lists_active_adapters_only() {
		$this->assertSame( array(), Moment_Publish_Helpers::controllable() );

		$this->activate_slug( 'share-on-mastodon' );

		$ids = Moment_Publish_Helpers::controllable_ids();
		$this->assertContains( 'share-on-mastodon', $ids );
		$this->assertNotContains( 'autoshare-for-twitter', $ids, 'Inactive adapters are not controllable' );
	}

	/** The adapter's control filter governs only Moment posts that recorded a selection. */
	public function test_adapter_filter_governs_by_selection() {
		$this->activate_slug( 'share-on-mastodon' );
		Moment_Publish_Helpers::register_adapters();

		$post_id = (int) self::factory()->post->create();

		// Non-Moment post: the plugin's own decision passes through untouched.
		$this->assertFalse( apply_filters( 'share_on_mastodon_enabled', false, $post_id ) );
		$this->assertTrue( apply_filters( 'share_on_mastodon_enabled', true, $post_id ) );

		// Moment post with no recorded selection: still untouched.
		update_post_meta( $post_id, '_moment_is_moment', '1' );
		$this->assertTrue( apply_filters( 'share_on_mastodon_enabled', true, $post_id ) );

		// Selected → forced on regardless of the plugin's fallback.
		update_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, wp_json_encode( array( 'share-on-mastodon' ) ) );
		$this->assertTrue( apply_filters( 'share_on_mastodon_enabled', false, $post_id ) );

		// Authoritative empty selection → forced off.
		update_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, wp_json_encode( array() ) );
		$this->assertFalse( apply_filters( 'share_on_mastodon_enabled', true, $post_id ) );
	}

	/** The publisher records the selection and publishes (deferred path). */
	public function test_publisher_records_selection_and_publishes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->activate_slug( 'share-on-mastodon' );

		$publisher = new Moment_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'         => 'Helper on',
				'publish_helpers' => array( 'share-on-mastodon' ),
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame(
			array( 'share-on-mastodon' ),
			json_decode( (string) get_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, true ), true )
		);
	}

	/** An absent publish_helpers field leaves no selection meta. */
	public function test_publisher_omits_meta_when_field_absent() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$publisher = new Moment_Publisher();
		$post_id   = (int) $publisher->publish( array( 'caption' => 'No helpers' ) );

		$this->assertSame( '', get_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, true ) );
	}

	/** Unknown/inactive helper ids are dropped from the stored selection. */
	public function test_publisher_drops_inactive_ids() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		// share-on-mastodon is NOT active here.
		$publisher = new Moment_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'         => 'x',
				'publish_helpers' => array( 'share-on-mastodon', 'bogus' ),
			)
		);

		$this->assertSame(
			array(),
			json_decode( (string) get_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, true ), true )
		);
	}
}
