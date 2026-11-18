<?php
/**
 * The Me screen's Connections card: your site, where Marks are shared,
 * and the sites followed.
 *
 * @package Daymark
 */

/**
 * Daymark_Connections and GET /daymark/v1/connections.
 */
class Test_Connections extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	/**
	 * Follow a site, optionally one that has failed some checks.
	 *
	 * @param string $host     The site's host.
	 * @param int    $failures Failed checks in a row.
	 * @return int Subscription ID.
	 */
	private function follow( string $host, int $failures = 0 ): int {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$id            = $subscriptions->create(
			array(
				'site_url' => 'https://' . $host . '/',
				'feed_url' => 'https://' . $host . '/feed/',
			)
		);

		$this->assertIsInt( $id );

		if ( $failures > 0 ) {
			$this->assertTrue( $subscriptions->update( $id, array( 'consecutive_failure_count' => $failures ) ) );
		}

		return (int) $id;
	}

	/**
	 * Mark a plugin folder slug active for the current test.
	 *
	 * @param string $slug The plugin's folder.
	 */
	private function activate_slug( string $slug ): void {
		update_option( 'active_plugins', array( $slug . '/' . $slug . '.php' ) );
	}

	/**
	 * The sharing rows, keyed by id.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function sharing(): array {
		return array_column( Daymark_Connections::sharing(), null, 'id' );
	}

	/**
	 * The ids of the sharing rows that come from connectors and sharing
	 * plugins, in order. The fediverse row is left out: the test suite's
	 * ActivityPub stub defines the plugin's version constant, which this
	 * class reads as the plugin being active.
	 *
	 * @return string[]
	 */
	private function plugin_row_ids(): array {
		return array_values( array_diff( array_column( Daymark_Connections::sharing(), 'id' ), array( 'activitypub' ) ) );
	}

	/**
	 * A REST GET of the card, carrying a valid nonce.
	 *
	 * @return WP_REST_Response
	 */
	private function rest_get_connections() {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/connections' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_get_server()->dispatch( $request );
	}

	/** The site row is the site's own address, said plainly. */
	public function test_site_row_is_the_sites_own_address() {
		update_option( 'home', 'https://www.example.org/blog' );

		$summary = Daymark_Connections::summary();

		$this->assertSame( 'example.org/blog', $summary['site']['address'] );
		$this->assertSame( 'https://www.example.org/blog/', $summary['site']['url'] );
	}

	/** Following counts every followed site, and the failing ones apart. */
	public function test_following_counts_sites_and_failing_ones() {
		$this->follow( 'one.example.com' );
		$this->follow( 'two.example.com', 2 );
		$dead = $this->follow( 'three.example.com', 7 );
		Daymark_Plugin::instance()->subscriptions->update( $dead, array( 'status' => 'error' ) );

		$summary = Daymark_Connections::summary();

		$this->assertSame( 3, $summary['following']['count'] );
		$this->assertSame( 2, $summary['following']['failing'] );
	}

	/** Nothing followed is zero and zero, not an error. */
	public function test_following_nothing_is_zero() {
		$summary = Daymark_Connections::summary();

		$this->assertSame( 0, $summary['following']['count'] );
		$this->assertSame( 0, $summary['following']['failing'] );
		$this->assertSame( '', $summary['following']['url'] );
	}

	/** Only someone who can open Settings -> Daymark gets links into it. */
	public function test_settings_links_are_for_those_who_can_open_them() {
		$this->follow( 'failing.example.com', 1 );

		$summary = Daymark_Connections::summary();
		$this->assertSame( '', $summary['sharing_url'] );
		$this->assertSame( '', $summary['following']['url'] );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$summary = Daymark_Connections::summary();
		$this->assertStringContainsString( 'tab=connectors', $summary['sharing_url'] );
		$this->assertStringContainsString( 'view=failing', $summary['following']['url'] );
	}

	/** With every followed site healthy, Following has no settings link. */
	public function test_following_link_only_while_a_site_is_failing() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->follow( 'fine.example.com' );

		$this->assertSame( '', Daymark_Connections::summary()['following']['url'] );
	}

	/** A site with no sharing set up lists none. */
	public function test_no_sharing_rows_without_sharing_plugins() {
		update_option( 'active_plugins', array() );

		$this->assertSame( array(), $this->plugin_row_ids() );
	}

	/** A sharing plugin with a per-Mark switch reads as its network and account. */
	public function test_switchable_plugin_reads_as_network_and_account() {
		$this->activate_slug( 'share-on-mastodon' );
		update_option(
			'share_on_mastodon_settings',
			array(
				'mastodon_host'     => 'https://mastodon.example',
				'mastodon_username' => 'jp',
			)
		);

		$rows = $this->sharing();

		$this->assertArrayHasKey( 'helper-share-on-mastodon', $rows );
		$this->assertSame( 'Mastodon', $rows['helper-share-on-mastodon']['name'] );
		$this->assertSame( 'Mastodon', $rows['helper-share-on-mastodon']['network'] );
		$this->assertSame( '@jp@mastodon.example', $rows['helper-share-on-mastodon']['detail'] );
	}

	/** Without an account to name, the row says which plugin does the sharing. */
	public function test_switchable_plugin_without_account_names_the_plugin() {
		$this->activate_slug( 'share-on-mastodon' );
		delete_option( 'share_on_mastodon_settings' );

		$rows = $this->sharing();

		$this->assertSame( 'Through Share on Mastodon', $rows['helper-share-on-mastodon']['detail'] );
	}

	/** A plugin Daymark can't switch per Mark is listed once, as sharing on its own. */
	public function test_plugin_that_shares_on_its_own_is_listed_once() {
		update_option(
			'active_plugins',
			array(
				'blog2social/blog2social.php',
				'share-on-mastodon/share-on-mastodon.php',
			)
		);

		$rows = $this->sharing();

		$this->assertSame( array( 'helper-share-on-mastodon', 'helper-blog2social' ), $this->plugin_row_ids() );
		$this->assertSame( 'Blog2Social', $rows['helper-blog2social']['name'] );
		$this->assertSame( '', $rows['helper-blog2social']['network'] );
		$this->assertSame( 'Shares by its own settings', $rows['helper-blog2social']['detail'] );
	}

	/**
	 * The ActivityPub plugin shows as the fediverse. The test suite's
	 * ActivityPub stub defines the plugin's version constant, which is
	 * what marks it active here.
	 */
	public function test_activitypub_shows_as_the_fediverse() {
		$this->assertTrue( Daymark_Connections::activitypub_active() );

		$rows = $this->sharing();

		$this->assertArrayHasKey( 'activitypub', $rows );
		$this->assertSame( 'Fediverse', $rows['activitypub']['name'] );
		$this->assertSame( 'Through ActivityPub', $rows['activitypub']['detail'] );
	}

	/** The REST route returns the same card. */
	public function test_rest_route_returns_the_card() {
		$this->follow( 'one.example.com' );

		$response = $this->rest_get_connections();

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		// No notifications count: the Notifications tab in the bottom bar
		// shows what is new, so the card has no row for it.
		$this->assertSame( array( 'site', 'sharing', 'sharing_url', 'following' ), array_keys( $data ) );
		$this->assertSame( 1, $data['following']['count'] );
		$this->assertIsArray( $data['sharing'] );
	}

	/** The route needs the nonce and edit_posts, like the rest of the app. */
	public function test_rest_route_needs_nonce_and_edit_posts() {
		$no_nonce = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/daymark/v1/connections' ) );
		$this->assertSame( 403, $no_nonce->get_status() );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest_get_connections()->get_status() );
	}
}
