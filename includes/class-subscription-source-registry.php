<?php
/**
 * Daymark Subscription Source Registry
 *
 * Manages inbound subscription sources for Daymark — the inbound mirror of
 * Daymark_Syndication_Registry (outbound publishing connectors).
 *
 * Integration path for real sources:
 *
 *     add_action( 'daymark_register_subscription_sources', function( $registry ) {
 *         $registry->register_source( new My_Subscription_Source() );
 *     } );
 *
 * This lets a future Friends `friend_post` adapter, an ActivityPub
 * actor-post adapter, or any other source plugin hook in without modifying
 * core Daymark.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry of inbound subscription sources.
 *
 * The built-in RSS/Atom feed source (`Daymark_Subscription_Source_Feed`) is
 * registered at construction, mirroring how Daymark_Syndication_Registry
 * registers its own built-in connectors, so it always exists before
 * third-party sources register. Third-party (and any future built-in)
 * sources register via the `daymark_register_subscription_sources` action
 * fired from Daymark_Plugin::on_init(), at the same point in the request
 * lifecycle `daymark_register_connectors` fires for outbound connectors.
 */
class Daymark_Subscription_Source_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Daymark_Subscription_Source_Registry|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered sources, keyed by source ID.
	 *
	 * @var array<string, Daymark_Subscription_Source>
	 */
	private array $sources = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Daymark_Subscription_Source_Registry
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor. Use instance().
	 *
	 * Registers the built-in RSS/Atom feed source immediately so it is
	 * available before external sources register on `init`.
	 */
	private function __construct() {
		$this->register_built_in_sources();
	}

	/**
	 * Register the built-in sources, WordPress REST API first, then feed,
	 * then microformats.
	 *
	 * Registration order is what implements every documented precedence
	 * rule across these sources: discover_feeds() below returns the first
	 * non-empty discover() result in registration order.
	 *
	 * - Issue #137: a subscribed WordPress site with a discoverable,
	 *   working REST API is always preferred over its own RSS/Atom feed —
	 *   the real thing beats a guess — so Daymark_Subscription_Source_WordPress
	 *   is registered first of all. Any other site (including a WordPress
	 *   site with the REST API disabled or unreachable) falls straight
	 *   through to the feed source with no behavior change.
	 * - Issue #84: where a site exposes both a traditional feed and h-feed/
	 *   h-entry markup, the feed source wins — the microformats source only
	 *   ever wins discovery for a site with h-feed/h-entry markup but no
	 *   discoverable feed (and, per the row above, no working WP REST API)
	 *   at all.
	 *
	 * @return void
	 */
	private function register_built_in_sources(): void {
		$this->register_source( new Daymark_Subscription_Source_WordPress() );
		$this->register_source( new Daymark_Subscription_Source_Feed() );
		$this->register_source( new Daymark_Subscription_Source_Microformats() );
	}

	/**
	 * Register a subscription source.
	 *
	 * Third-party (and future built-in) sources register via the
	 * `daymark_register_subscription_sources` action, which receives this
	 * registry instance.
	 *
	 * @param Daymark_Subscription_Source $source Source instance.
	 * @return void
	 */
	public function register_source( Daymark_Subscription_Source $source ): void {
		$id = sanitize_key( $source->get_id() );

		if ( '' === $id ) {
			return;
		}

		$this->sources[ $id ] = $source;
	}

	/**
	 * Get all registered sources.
	 *
	 * @return array<string, Daymark_Subscription_Source>
	 */
	public function get_sources(): array {
		return $this->sources;
	}

	/**
	 * Get a single source by ID.
	 *
	 * @param string $id Source ID.
	 * @return Daymark_Subscription_Source|null
	 */
	public function get_source( string $id ): ?Daymark_Subscription_Source {
		return $this->sources[ $id ] ?? null;
	}

	/**
	 * Ask every registered source to discover feed(s)/locator(s) for a site
	 * URL, in registration order, and return the first non-empty result.
	 *
	 * Keeps callers (the subscribe-by-URL REST handler) source-agnostic —
	 * a future source (Friends, ActivityPub) registering via
	 * `daymark_register_subscription_sources` needs no caller-side changes.
	 * This registration-order, first-non-empty-wins behavior is also what
	 * implements every precedence rule documented on register_built_in_sources()
	 * above (issues #84 and #137).
	 *
	 * Each returned candidate carries its producing source's ID under
	 * `source_type`, added here rather than by the source itself, so every
	 * `Daymark_Subscription_Source::discover()` implementation can stay
	 * focused on its own candidate shape (`url`/`title`/`type`) without
	 * needing to know its own registered ID.
	 *
	 * @param string $site_url Site URL entered by the user (not a feed URL).
	 * @return array<int, array<string, mixed>> The first source's non-empty
	 *                                          discover() result, each entry
	 *                                          augmented with `source_type`;
	 *                                          empty when no registered
	 *                                          source discovers anything.
	 */
	public function discover_feeds( string $site_url ): array {
		foreach ( $this->sources as $id => $source ) {
			$discovered = $source->discover( $site_url );

			if ( empty( $discovered ) ) {
				continue;
			}

			return array_map(
				static function ( $candidate ) use ( $id ) {
					if ( ! is_array( $candidate ) ) {
						return $candidate;
					}

					$candidate['source_type'] = $id;

					return $candidate;
				},
				$discovered
			);
		}

		return array();
	}
}
