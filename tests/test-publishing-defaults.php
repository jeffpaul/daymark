<?php
/**
 * Site defaults for new Marks: the destinations and categories each Mark
 * type starts with (Settings -> Daymark -> General), how they sit under a
 * person's remembered choices, and the app config that lets the Publish
 * screen offer a way back to them.
 *
 * @package Daymark
 */

/**
 * Publishing defaults coverage.
 */
class Test_Publishing_Defaults extends WP_UnitTestCase {

	/** @var Daymark_Admin_Subscriptions */
	private $admin;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		$this->admin = new Daymark_Admin_Subscriptions();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Daymark_Settings::DEFAULT_DESTINATIONS );
		delete_option( Daymark_Settings::DEFAULT_CATEGORIES );
	}

	public function tear_down(): void {
		delete_option( Daymark_Settings::DEFAULT_DESTINATIONS );
		delete_option( Daymark_Settings::DEFAULT_CATEGORIES );
		$this->unregister_connector( 'testnet' );

		parent::tear_down();
	}

	/**
	 * Register a connected destination that takes images and notes.
	 *
	 * @return void
	 */
	private function register_connected_destination(): void {
		Daymark_Syndication_Registry::instance()->register_connector(
			new class() implements Daymark_Syndication_Connector {
				public function get_id(): string {
					return 'testnet';
				}

				public function get_label(): string {
					return 'Testnet';
				}

				public function supports_daymark_type( string $type ): bool {
					return in_array( $type, array( 'image', 'note' ), true );
				}

				public function is_connected(): bool {
					return true;
				}

				public function publish( int $post_id, array $payload ): array {
					unset( $post_id, $payload );
					return array( 'status' => 'published' );
				}

				public function get_status_label(): string {
					return 'Connected';
				}
			}
		);
	}

	/**
	 * Remove a connector from the shared registry, so it can't leak into
	 * other tests in the same process.
	 *
	 * @param string $id Connector ID.
	 * @return void
	 */
	private function unregister_connector( string $id ): void {
		$registry   = Daymark_Syndication_Registry::instance();
		$property   = new ReflectionProperty( $registry, 'connectors' );
		$connectors = $property->getValue( $registry );
		unset( $connectors[ $id ] );
		$property->setValue( $registry, $connectors );
	}

	/**
	 * Call an admin_post handler, stopping it at its redirect instead of at
	 * exit(). Returns the URL it redirected to.
	 *
	 * @param array<string, mixed> $post Submitted fields.
	 * @return string
	 */
	private function save( array $post ): string {
		$stop = static function ( $location ) {
			throw new RuntimeException( esc_url_raw( (string) $location ) );
		};

		$_POST = $post;
		$_REQUEST['daymark_publishing_defaults_save_nonce'] = wp_create_nonce( 'daymark_publishing_defaults_save' );
		add_filter( 'wp_redirect', $stop );

		try {
			$this->admin->handle_publishing_defaults_save();
			$location = '';
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $stop );
			$_POST = array();
			unset( $_REQUEST['daymark_publishing_defaults_save_nonce'] );
		}

		return $location;
	}

	/**
	 * Render Settings -> Daymark's General tab.
	 *
	 * @return string
	 */
	private function render_general(): string {
		$_GET['tab'] = 'general';
		ob_start();
		$this->admin->render_page();
		$output = (string) ob_get_clean();
		unset( $_GET['tab'] );

		return $output;
	}

	/** Until the site saves its own, the built-in defaults apply. */
	public function test_builtin_destination_defaults(): void {
		$this->assertSame( array( 'instagram' ), Daymark_Settings::destination_defaults( 'image' ) );
		$this->assertSame( array( 'bluesky' ), Daymark_Settings::destination_defaults( 'note' ) );
		$this->assertSame( array(), Daymark_Settings::destination_defaults( 'checkin' ) );
		$this->assertSame( array(), Daymark_Settings::category_defaults( 'image' ) );
	}

	/** A saved site default replaces the built-in one, and the registry reads it. */
	public function test_stored_destination_defaults_replace_builtin(): void {
		update_option( Daymark_Settings::DEFAULT_DESTINATIONS, array( 'image' => array( 'mastodon' ) ) );

		$this->assertSame( array( 'mastodon' ), Daymark_Settings::destination_defaults( 'image' ) );
		$this->assertSame( array(), Daymark_Settings::destination_defaults( 'note' ) );
		$this->assertSame( array( 'mastodon' ), Daymark_Syndication_Registry::instance()->get_defaults_for_type( 'image' ) );
	}

	/** The filters still win over the saved site defaults. */
	public function test_filters_win_over_site_defaults(): void {
		update_option( Daymark_Settings::DEFAULT_DESTINATIONS, array( 'image' => array( 'mastodon' ) ) );
		update_option( Daymark_Settings::DEFAULT_CATEGORIES, array( 'image' => array( 5 ) ) );

		$destinations = static function () {
			return array( 'threads' );
		};
		$categories   = static function () {
			return array( 9 );
		};
		add_filter( 'daymark_default_destinations', $destinations );
		add_filter( 'daymark_default_categories', $categories );

		$this->assertSame( array( 'threads' ), Daymark_Settings::destination_defaults( 'image' ) );
		$this->assertSame( array( 9 ), Daymark_Settings::category_defaults( 'image' ) );

		remove_filter( 'daymark_default_destinations', $destinations );
		remove_filter( 'daymark_default_categories', $categories );
	}

	/** A Mark published with no category choice is filed under the type's site default. */
	public function test_publish_uses_site_default_categories(): void {
		$travel = self::factory()->category->create( array( 'name' => 'Travel' ) );
		update_option( Daymark_Settings::DEFAULT_CATEGORIES, array( 'note' => array( $travel ) ) );

		$post_id = ( new Daymark_Publisher() )->publish(
			array(
				'caption'      => 'A note with no category choice',
				'primary_type' => 'note',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( array( $travel ), wp_get_post_categories( $post_id ) );
	}

	/** A remembered category choice wins over the site default. */
	public function test_remembered_categories_win_over_site_default(): void {
		$travel = self::factory()->category->create( array( 'name' => 'Travel' ) );
		$food   = self::factory()->category->create( array( 'name' => 'Food' ) );
		update_option( Daymark_Settings::DEFAULT_CATEGORIES, array( 'note' => array( $travel ) ) );

		$publisher = new Daymark_Publisher();
		$publisher->publish(
			array(
				'caption'      => 'Filed under Food',
				'primary_type' => 'note',
				'categories'   => array( $food ),
			)
		);

		$this->assertSame( array( $food ), $publisher->get_effective_categories( 'note' ) );
	}

	/** The app config carries the site defaults beside the effective ones, Check Ins included. */
	public function test_app_config_sends_site_defaults(): void {
		$this->register_connected_destination();
		$travel = self::factory()->category->create( array( 'name' => 'Travel' ) );
		update_option( Daymark_Settings::DEFAULT_DESTINATIONS, array( 'image' => array( 'testnet' ) ) );
		update_option( Daymark_Settings::DEFAULT_CATEGORIES, array( 'checkin' => array( $travel ) ) );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( array( 'testnet' ), $config['siteDefaults']['image'] );
		$this->assertSame( array( 'testnet' ), $config['defaults']['image'] );
		$this->assertSame( array( $travel ), $config['siteCategoryDefaults']['checkin'] );
		$this->assertArrayHasKey( 'checkin', $config['defaults'] );
	}

	/** With nothing to choose from, the General tab says so instead of showing an empty table. */
	public function test_general_tab_says_nothing_to_set(): void {
		$output = $this->render_general();

		$this->assertStringContainsString( 'Nothing to set yet.', $output );
		$this->assertStringNotContainsString( 'daymark_default_categories', $output );
	}

	/** The General tab shows a row per type with destination and category checkboxes. */
	public function test_general_tab_renders_defaults_table(): void {
		$this->register_connected_destination();
		$travel = self::factory()->category->create( array( 'name' => 'Travel' ) );
		update_option( Daymark_Settings::DEFAULT_DESTINATIONS, array( 'image' => array( 'testnet' ) ) );

		$output = $this->render_general();

		$this->assertStringContainsString( 'New Marks', $output );
		$this->assertMatchesRegularExpression( '/name="daymark_default_destinations\[image\]\[\]"\s+value="testnet"\s+checked/', $output );
		$this->assertMatchesRegularExpression( '/name="daymark_default_destinations\[note\]\[\]"\s+value="testnet"\s+\/>/', $output );
		$this->assertStringNotContainsString( 'daymark_default_destinations[video]', $output );
		$this->assertStringContainsString( 'name="daymark_default_categories[image][]"', $output );
		$this->assertStringContainsString( 'value="' . $travel . '"', $output );
	}

	/** A type a filter overrides is shown disabled, with a note. */
	public function test_overridden_type_is_disabled(): void {
		$this->register_connected_destination();
		$filter = static function ( $ids, $type ) {
			return 'note' === $type ? array( 'testnet' ) : $ids;
		};
		add_filter( 'daymark_default_destinations', $filter, 10, 2 );

		$output = $this->render_general();

		remove_filter( 'daymark_default_destinations', $filter, 10 );

		$this->assertMatchesRegularExpression( '/name="daymark_default_destinations\[note\]\[\]"\s+value="testnet"\s+checked=\'checked\'\s+disabled/', $output );
		$this->assertStringContainsString( 'Set by code on this site', $output );
	}

	/** Saving stores the ticked choices for every shown type and returns to the General tab. */
	public function test_save_stores_choices(): void {
		$this->register_connected_destination();
		$travel = self::factory()->category->create( array( 'name' => 'Travel' ) );

		$location = $this->save(
			array(
				'daymark_default_destinations' => array( 'note' => array( 'testnet' ) ),
				'daymark_default_categories'   => array( 'image' => array( (string) $travel, '999999' ) ),
			)
		);

		$this->assertStringContainsString( 'tab=general', $location );
		$this->assertStringContainsString( 'daymark_notice=publishing_defaults_saved', $location );
		// Bluesky, the built-in note default, isn't connected here, so it's kept.
		$this->assertSame( array( 'testnet', 'bluesky' ), Daymark_Settings::destination_defaults( 'note' ) );
		$this->assertSame( array(), Daymark_Settings::category_defaults( 'note' ) );
		$this->assertSame( array( $travel ), Daymark_Settings::category_defaults( 'image' ) );
	}

	/**
	 * A stored destination that isn't shown (not connected, so it has no
	 * checkbox) survives a save, so disconnecting a destination doesn't
	 * erase its defaults.
	 */
	public function test_save_keeps_destinations_not_shown(): void {
		$this->register_connected_destination();

		// Built-in image default is Instagram, which isn't connected here.
		$this->save( array( 'daymark_default_destinations' => array( 'image' => array( 'testnet' ) ) ) );

		$this->assertSame( array( 'testnet', 'instagram' ), Daymark_Settings::destination_defaults( 'image' ) );
		// Video has no connected destination, so its built-in default is kept as is.
		$this->assertSame( array( 'youtube' ), Daymark_Settings::destination_defaults( 'video' ) );
	}

	/** Saving leaves a filter-overridden type's stored value alone. */
	public function test_save_skips_overridden_type(): void {
		$this->register_connected_destination();
		update_option( Daymark_Settings::DEFAULT_DESTINATIONS, array( 'note' => array( 'testnet' ) ) );
		$filter = static function ( $ids, $type ) {
			return 'note' === $type ? array() : $ids;
		};
		add_filter( 'daymark_default_destinations', $filter, 10, 2 );

		$this->save( array() );

		remove_filter( 'daymark_default_destinations', $filter, 10 );

		$this->assertSame( array( 'testnet' ), Daymark_Settings::stored_destination_defaults( 'note' ) );
	}
}
