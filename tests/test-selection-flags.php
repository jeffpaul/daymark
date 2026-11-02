<?php
/**
 * The composer's `targets_set` and `categories_set` flags.
 *
 * A form post can't send an empty list, so without these flags unticking
 * every destination or category looked exactly like a request that never
 * mentioned them, and the site applied the defaults anyway. With a flag,
 * an empty list means "none" and is remembered like any other choice.
 *
 * @package Daymark
 */

/**
 * Empty destination and category lists sent through REST.
 */
class Test_Selection_Flags extends WP_UnitTestCase {

	/**
	 * A publishing author.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * A category the defaults file Marks under.
	 *
	 * @var int
	 */
	private int $travel;

	public function set_up(): void {
		parent::set_up();

		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
		$this->travel = (int) self::factory()->category->create( array( 'name' => 'Travel' ) );

		$this->register_connected_destination();
		update_option( Daymark_Settings::DEFAULT_DESTINATIONS, array( 'note' => array( 'testnet' ) ) );
		update_option( Daymark_Settings::DEFAULT_CATEGORIES, array( 'note' => array( $this->travel ) ) );
	}

	public function tear_down(): void {
		delete_option( Daymark_Settings::DEFAULT_DESTINATIONS );
		delete_option( Daymark_Settings::DEFAULT_CATEGORIES );
		$this->unregister_connector( 'testnet' );

		parent::tear_down();
	}

	/**
	 * Register a connected destination that takes notes.
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
					return 'note' === $type;
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
	 * Send a Mark request the way the composer does.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route under daymark/v1.
	 * @param array<string, mixed> $params Request parameters.
	 * @return WP_REST_Response
	 */
	private function send( string $method, string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/daymark/v1/' . $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * A draft Note made without a selection, so the site filed it under
	 * the defaults (Testnet, Travel), the way a first autosave does.
	 *
	 * @return int
	 */
	private function draft_with_defaults(): int {
		$post_id = (int) ( new Daymark_Publisher() )->publish(
			array(
				'caption'      => 'Morning coffee',
				'primary_type' => 'note',
				'status'       => 'draft',
			)
		);

		$this->assertSame( array( 'testnet' ), $this->targets( $post_id ) );
		$this->assertSame( array( $this->travel ), $this->category_ids( $post_id ) );

		return $post_id;
	}

	private function targets( int $post_id ): array {
		return json_decode( (string) get_post_meta( $post_id, '_daymark_syndication_targets', true ), true );
	}

	private function category_ids( int $post_id ): array {
		return array_map( 'intval', wp_get_post_categories( $post_id ) );
	}

	private function remembered( string $key, string $type ) {
		$prefs = get_user_meta( $this->author, $key, true );

		return is_array( $prefs ) && array_key_exists( $type, $prefs ) ? $prefs[ $type ] : null;
	}

	/** With targets_set and no targets, a new Mark goes nowhere but the site, and "none" is remembered. */
	public function test_create_with_targets_set_and_no_targets_sends_nowhere(): void {
		$response = $this->send(
			'POST',
			'marks',
			array(
				'caption'      => 'Morning coffee',
				'primary_type' => 'note',
				'status'       => 'draft',
				'targets_set'  => '1',
			)
		);
		$post_id  = (int) $response->get_data()['id'];

		$this->assertSame( array(), $this->targets( $post_id ) );
		$this->assertSame( array(), $this->remembered( 'daymark_destination_prefs', 'note' ) );
	}

	/** Without the flag (an older app, an API caller), no targets still means the defaults. */
	public function test_create_without_targets_set_uses_defaults(): void {
		$response = $this->send(
			'POST',
			'marks',
			array(
				'caption'      => 'Morning coffee',
				'primary_type' => 'note',
				'status'       => 'draft',
			)
		);
		$post_id  = (int) $response->get_data()['id'];

		$this->assertSame( array( 'testnet' ), $this->targets( $post_id ) );
		$this->assertNull( $this->remembered( 'daymark_destination_prefs', 'note' ) );
	}

	/** With categories_set and no categories, a new Mark is filed under the site's default category, and "none" is remembered. */
	public function test_create_with_categories_set_and_no_categories_files_under_default(): void {
		$response = $this->send(
			'POST',
			'marks',
			array(
				'caption'        => 'Morning coffee',
				'primary_type'   => 'note',
				'status'         => 'draft',
				'categories_set' => '1',
			)
		);
		$post_id  = (int) $response->get_data()['id'];

		$this->assertSame( array( (int) get_option( 'default_category' ) ), $this->category_ids( $post_id ) );
		$this->assertSame( array(), $this->remembered( 'daymark_category_prefs', 'note' ) );
	}

	/** An autosaved draft carries the defaults; unticking every destination on the final save clears them. */
	public function test_update_with_targets_set_and_no_targets_clears_them(): void {
		$post_id = $this->draft_with_defaults();

		$this->send(
			'PUT',
			"marks/{$post_id}",
			array(
				'caption'     => 'Morning coffee',
				'status'      => 'draft',
				'targets_set' => '1',
			)
		);

		$this->assertSame( array(), $this->targets( $post_id ) );
		$this->assertSame( array(), $this->remembered( 'daymark_destination_prefs', 'note' ) );
	}

	/** Unticking every category on the final save files the Mark under the site's default category. */
	public function test_update_with_categories_set_and_no_categories_files_under_default(): void {
		$post_id = $this->draft_with_defaults();

		$this->send(
			'PUT',
			"marks/{$post_id}",
			array(
				'caption'        => 'Morning coffee',
				'status'         => 'draft',
				'categories_set' => '1',
			)
		);

		$this->assertSame( array( (int) get_option( 'default_category' ) ), $this->category_ids( $post_id ) );
		$this->assertSame( array(), $this->remembered( 'daymark_category_prefs', 'note' ) );
	}

	/** Without the flags, an update that sends no lists leaves the draft's destinations and categories alone. */
	public function test_update_without_flags_keeps_the_lists(): void {
		$post_id = $this->draft_with_defaults();

		$this->send(
			'PUT',
			"marks/{$post_id}",
			array(
				'caption' => 'Morning coffee, second cup',
				'status'  => 'draft',
			)
		);

		$this->assertSame( array( 'testnet' ), $this->targets( $post_id ) );
		$this->assertSame( array( $this->travel ), $this->category_ids( $post_id ) );
	}

	/** With a flag and a list, the list is used and remembered as before. */
	public function test_flag_with_a_list_uses_the_list(): void {
		$food     = (int) self::factory()->category->create( array( 'name' => 'Food' ) );
		$response = $this->send(
			'POST',
			'marks',
			array(
				'caption'        => 'Morning coffee',
				'primary_type'   => 'note',
				'status'         => 'draft',
				'categories'     => array( $food ),
				'categories_set' => '1',
			)
		);
		$post_id  = (int) $response->get_data()['id'];

		$this->assertSame( array( $food ), $this->category_ids( $post_id ) );
		$this->assertSame( array( $food ), $this->remembered( 'daymark_category_prefs', 'note' ) );
	}
}
