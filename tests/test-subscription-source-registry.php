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
	 * Scenario: all three built-in sources are registered in the order
	 * WordPress, feed, microformats — the registration order that
	 * implements both documented precedence rules: WordPress's own REST API
	 * beats its RSS/Atom feed when both are reachable (issue #137), and the
	 * feed beats h-feed/h-entry markup when both exist (issue #84).
	 */
	public function test_built_in_sources_registered_wordpress_then_feed_then_microformats() {
		$ids = array_keys( Daymark_Subscription_Source_Registry::instance()->get_sources() );

		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the lowercase machine ID (source_type value), not prose.
		$wordpress_position    = array_search( 'wordpress', $ids, true );
		$feed_position         = array_search( 'feed', $ids, true );
		$microformats_position = array_search( 'microformats', $ids, true );

		$this->assertIsInt( $wordpress_position );
		$this->assertIsInt( $feed_position );
		$this->assertIsInt( $microformats_position );
		$this->assertLessThan( $feed_position, $wordpress_position );
		$this->assertLessThan( $microformats_position, $feed_position );
	}

	/**
	 * Scenario: discover_feeds() augments each candidate with its producing
	 * source's own ID under `source_type` — what Daymark_Subscriptions::
	 * subscribe_to_site() relies on to record the right source_type instead
	 * of hardcoding 'feed' (issue #84).
	 *
	 * Deliberately exercises this via the real built-in 'feed' source (mocked
	 * to succeed) rather than a dynamically registered stub: the registry is
	 * a process-wide singleton, so a stub source registered by an earlier
	 * test in this file (e.g. make_stub_source()'s 'stub-source', whose
	 * discover() unconditionally returns a non-empty result with no HTTP
	 * involved at all) can already be sitting ahead of a newly registered
	 * stub in iteration order and win discovery first — blocking HTTP alone
	 * cannot neutralize it. The built-in 'feed' source is always registered
	 * first of all, so mocking it to succeed is deterministic regardless of
	 * whatever else this test file has registered by the time this runs.
	 */
	public function test_discover_feeds_tags_candidates_with_source_type() {
		add_filter( 'pre_http_request', array( $this, 'mock_feed_discovery_request' ), 10, 3 );

		$result = Daymark_Subscription_Source_Registry::instance()->discover_feeds( 'https://tagging.example/' );

		remove_filter( 'pre_http_request', array( $this, 'mock_feed_discovery_request' ), 10 );

		$this->assertSame( 'feed', $result[0]['source_type'] ?? null );
		$this->assertSame( 'https://tagging.example/feed/', $result[0]['url'] ?? null );
	}

	/**
	 * Mocks a single site's HTML with a discoverable `<link rel="alternate">`
	 * feed, and blocks every other request — so only the built-in 'feed'
	 * source's discover() call for this exact URL succeeds.
	 *
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function mock_feed_discovery_request( $preempt, $parsed_args, $url ) {
		unset( $preempt, $parsed_args );

		if ( 'https://tagging.example/' !== $url ) {
			return new WP_Error( 'daymark_test_http_blocked', 'HTTP blocked in test' );
		}

		return array(
			'headers'  => array(),
			'body'     => '<html><head><link rel="alternate" type="application/rss+xml" title="Tagging &raquo; Feed" href="https://tagging.example/feed/"></head><body></body></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
