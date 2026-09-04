<?php
/**
 * Daymark_Subscription_Source_Registry tests (issue #78 — the inbound
 * subscription source interface/registry only; the built-in RSS/Atom feed
 * source, autodiscovery, fetching, and ingest are a later task).
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Source_Registry registration.
 */
class Test_Subscription_Source_Registry extends WP_UnitTestCase {

	/**
	 * Build a minimal stub source used only to exercise the registry
	 * contract. An anonymous class keeps this file to one named test
	 * class (this repo's test-file convention).
	 *
	 * @return Daymark_Subscription_Source
	 */
	private function make_stub_source(): Daymark_Subscription_Source {
		return new class() implements Daymark_Subscription_Source {

			public function get_id(): string {
				return 'stub-source';
			}

			public function get_label(): string {
				return 'Stub Source';
			}

			public function discover( string $site_url ): array {
				return array( $site_url . 'feed/' );
			}

			public function fetch( string $feed_url ): array {
				return array();
			}

			public function normalize( array $raw_item ): array {
				return $raw_item;
			}
		};
	}

	/** Scenario: the registry registers a source and returns it. */
	public function test_registry_registers_and_returns_source() {
		$registry = Daymark_Subscription_Source_Registry::instance();
		$source   = $this->make_stub_source();

		$registry->register_source( $source );

		$this->assertSame( $source, $registry->get_source( 'stub-source' ) );
		$this->assertArrayHasKey( 'stub-source', $registry->get_sources() );
	}

	/**
	 * Scenario: the `daymark_register_subscription_sources` action actually
	 * fires (against the same singleton registry Daymark_Plugin holds) and
	 * a source registered through it is retrievable — the inbound mirror
	 * of `daymark_register_connectors` for outbound connectors.
	 *
	 * Deliberately does not re-fire WordPress's `init` hook to exercise
	 * this: Daymark_Plugin::on_init() (already run once for real during
	 * plugin bootstrap) also re-registers blocks/routes/post types on
	 * every call, and re-running it mid-suite pollutes global
	 * registration state for later tests. Firing the action directly
	 * against the actual singleton registry the plugin holds exercises
	 * the same registration path without that side effect.
	 */
	public function test_register_subscription_sources_action_fires() {
		// Confirms this is the exact registry instance Daymark_Plugin wires
		// up, not a fresh, unrelated one.
		$this->assertSame(
			Daymark_Subscription_Source_Registry::instance(),
			Daymark_Plugin::instance()->subscription_source_registry
		);

		$source = $this->make_stub_source();

		$callback = static function ( Daymark_Subscription_Source_Registry $registry ) use ( $source ) {
			$registry->register_source( $source );
		};

		add_action( 'daymark_register_subscription_sources', $callback );

		do_action( 'daymark_register_subscription_sources', Daymark_Subscription_Source_Registry::instance() );

		remove_action( 'daymark_register_subscription_sources', $callback );

		$registry = Daymark_Subscription_Source_Registry::instance();
		$this->assertSame( $source, $registry->get_source( 'stub-source' ) );
	}

	/**
	 * Scenario: both built-in sources are registered, feed before
	 * microformats — the registration order that implements this feature's
	 * documented feed-wins precedence rule (issue #84).
	 */
	public function test_built_in_sources_registered_feed_before_microformats() {
		$ids = array_keys( Daymark_Subscription_Source_Registry::instance()->get_sources() );

		$feed_position         = array_search( 'feed', $ids, true );
		$microformats_position = array_search( 'microformats', $ids, true );

		$this->assertIsInt( $feed_position );
		$this->assertIsInt( $microformats_position );
		$this->assertLessThan( $microformats_position, $feed_position );
	}

	/**
	 * Scenario: discover_feeds() augments each candidate with its producing
	 * source's own ID under `source_type` — what Daymark_Subscriptions::
	 * subscribe_to_site() relies on to record the right source_type instead
	 * of hardcoding 'feed' (issue #84).
	 */
	public function test_discover_feeds_tags_candidates_with_source_type() {
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10, 3 );

		$shaped_source = new class() implements Daymark_Subscription_Source {

			public function get_id(): string {
				return 'shaped-stub';
			}

			public function get_label(): string {
				return 'Shaped Stub';
			}

			public function discover( string $site_url ): array {
				return array(
					array(
						'url'   => $site_url . 'shaped/',
						'title' => 'Shaped',
						'type'  => 'text/html',
					),
				);
			}

			public function fetch( string $feed_url ): array {
				return array();
			}

			public function normalize( array $raw_item ): array {
				return $raw_item;
			}
		};

		// The built-in 'feed'/'microformats' sources are registered ahead of
		// this stub and run first; with HTTP blocked, both legitimately find
		// nothing, so control falls through to this newly registered source
		// and its result is what's returned.
		Daymark_Subscription_Source_Registry::instance()->register_source( $shaped_source );

		$result = Daymark_Subscription_Source_Registry::instance()->discover_feeds( 'https://example.test/' );

		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );

		$this->assertSame( 'shaped-stub', $result[0]['source_type'] ?? null );
		$this->assertSame( 'https://example.test/shaped/', $result[0]['url'] ?? null );
	}

	/**
	 * Short-circuits every HTTP request made during a test with a WP_Error,
	 * so discover() calls against the built-in sources never attempt a real
	 * network request.
	 *
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL (unused).
	 * @return WP_Error
	 */
	public function block_http_request( $preempt, $parsed_args, $url ) {
		unset( $preempt, $parsed_args, $url );

		return new WP_Error( 'daymark_test_http_blocked', 'HTTP blocked in test' );
	}
}
