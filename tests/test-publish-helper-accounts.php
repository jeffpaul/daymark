<?php
/**
 * Tests for the account each sharing plugin posts as.
 *
 * The "Where this goes" sheet shows that account under a plugin's toggle.
 * Each built-in adapter reads it from where its plugin keeps it; these
 * tests cover Share on Mastodon, Autopost for X, and adapters added through
 * the filter. ATmosphere's is covered beside its other adapter tests.
 *
 * The curly-brace namespace below stubs the one Autopost for X function the
 * adapter calls, so it can be exercised without loading the real plugin.
 * PHPCS forbids that syntax and mixed OO/function files; both are
 * intentional here and scoped to this fixture only.
 *
 * @package Daymark
 */

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden
// phpcs:disable Universal.Namespaces.OneDeclarationPerFile.MultipleFound
// phpcs:disable Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

namespace TenUp\AutoshareForTwitter\Utils {
	// Minimal stub for Autopost for X's default-accounts helper.
	if ( ! function_exists( 'TenUp\\AutoshareForTwitter\\Utils\\get_default_autoshare_accounts' ) ) {
		function get_default_autoshare_accounts() {
			return $GLOBALS['daymark_test_x_default_accounts'] ?? array();
		}
	}
}

namespace {

	/**
	 * Tests the account line of the sharing-plugin toggles.
	 */
	class Test_Publish_Helper_Accounts extends WP_UnitTestCase {

		public function tear_down(): void {
			unset( $GLOBALS['daymark_test_x_default_accounts'] );
			parent::tear_down();
		}

		/** The helper with this id, as the app receives it. */
		private function helper( string $id ): array {
			$helpers = array_column( Daymark_Publish_Helpers::controllable(), null, 'id' );

			$this->assertArrayHasKey( $id, $helpers );

			return $helpers[ $id ];
		}

		/** Mark a plugin folder slug active for the current test. */
		private function activate_slug( string $slug ): void {
			update_option( 'active_plugins', array( $slug . '/' . $slug . '.php' ) );
		}

		/** Share on Mastodon's name and server read as a full Mastodon address. */
		public function test_share_on_mastodon_account_is_name_and_server() {
			$this->activate_slug( 'share-on-mastodon' );
			update_option(
				'share_on_mastodon_settings',
				array(
					'mastodon_host'     => 'https://mastodon.social',
					'mastodon_username' => 'jeffpaul',
				)
			);

			$this->assertSame( '@jeffpaul@mastodon.social', $this->helper( 'share-on-mastodon' )['account'] );
		}

		/** Not signed in yet (no name saved) means no account line. */
		public function test_share_on_mastodon_has_no_account_until_signed_in() {
			$this->activate_slug( 'share-on-mastodon' );

			$this->assertSame( '', $this->helper( 'share-on-mastodon' )['account'] );

			update_option(
				'share_on_mastodon_settings',
				array(
					'mastodon_host'     => 'https://mastodon.social',
					'mastodon_username' => '',
				)
			);

			$this->assertSame( '', $this->helper( 'share-on-mastodon' )['account'] );
		}

		/** A name without a readable server still shows, as the name alone. */
		public function test_share_on_mastodon_account_without_a_server() {
			$this->activate_slug( 'share-on-mastodon' );
			update_option( 'share_on_mastodon_settings', array( 'mastodon_username' => '@jeffpaul' ) );

			$this->assertSame( '@jeffpaul', $this->helper( 'share-on-mastodon' )['account'] );
		}

		/** Autopost for X names the accounts a new post is shared to by default. */
		public function test_autopost_for_x_names_its_default_accounts() {
			$this->activate_slug( 'autoshare-for-twitter' );
			update_option(
				'autoshare_for_twitter_accounts',
				array(
					'111' => array(
						'id'       => '111',
						'name'     => 'JP',
						'username' => 'jeffpaul',
					),
					'222' => array(
						'id'       => '222',
						'name'     => 'Daymark',
						'username' => 'daymarkapp',
					),
					'333' => array(
						'id'       => '333',
						'name'     => 'Not a default',
						'username' => 'someoneelse',
					),
				)
			);

			$GLOBALS['daymark_test_x_default_accounts'] = array( '111' );
			$this->assertSame( '@jeffpaul', $this->helper( 'autoshare-for-twitter' )['account'] );

			$GLOBALS['daymark_test_x_default_accounts'] = array( '111', '222' );
			$this->assertSame( '@jeffpaul, @daymarkapp', $this->helper( 'autoshare-for-twitter' )['account'] );
		}

		/** No default account, or one that is no longer connected, means no account line. */
		public function test_autopost_for_x_has_no_account_without_a_known_default() {
			$this->activate_slug( 'autoshare-for-twitter' );

			$this->assertSame( '', $this->helper( 'autoshare-for-twitter' )['account'] );

			$GLOBALS['daymark_test_x_default_accounts'] = array( '999' );
			update_option(
				'autoshare_for_twitter_accounts',
				array(
					'111' => array(
						'id'       => '111',
						'username' => 'jeffpaul',
					),
				)
			);

			$this->assertSame( '', $this->helper( 'autoshare-for-twitter' )['account'] );

			$GLOBALS['daymark_test_x_default_accounts'] = 'not-a-list';
			$this->assertSame( '', $this->helper( 'autoshare-for-twitter' )['account'] );
		}

		/** An adapter added through the filter can name its own account; markup is stripped. */
		public function test_filtered_adapter_names_its_account() {
			$filter = static function ( $adapters ) {
				$adapters['custom'] = array(
					'label'   => 'Custom Poster',
					'network' => 'Example',
					'classes' => array( 'WP_UnitTestCase' ),
					'account' => static function () {
						return '<b>@you</b>@example.social';
					},
					'bind'    => '__return_null',
				);

				return $adapters;
			};
			add_filter( 'daymark_publish_helper_adapters', $filter );

			$this->assertSame( '@you@example.social', $this->helper( 'custom' )['account'] );

			remove_filter( 'daymark_publish_helper_adapters', $filter );
		}

		/** An adapter that names no account, answers with something else, or throws shows none. */
		public function test_adapter_without_a_usable_account_shows_none() {
			$filter = static function ( $adapters ) {
				$base = array(
					'label'   => 'Custom Poster',
					'classes' => array( 'WP_UnitTestCase' ),
					'bind'    => '__return_null',
				);

				$adapters['none']   = $base;
				$adapters['text']   = $base + array( 'account' => '@not-a-callable' );
				$adapters['list']   = $base + array( 'account' => '__return_empty_array' );
				$adapters['throws'] = $base + array(
					'account' => static function () {
						throw new RuntimeException( 'The plugin is not set up.' );
					},
				);

				return $adapters;
			};
			add_filter( 'daymark_publish_helper_adapters', $filter );

			foreach ( array( 'none', 'text', 'list', 'throws' ) as $id ) {
				$this->assertSame( '', $this->helper( $id )['account'], $id );
			}

			remove_filter( 'daymark_publish_helper_adapters', $filter );
		}
	}
}

// phpcs:enable
