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
	 * Register the one built-in source shipped in phase one: the RSS/Atom
	 * feed reader.
	 *
	 * @return void
	 */
	private function register_built_in_sources(): void {
		$this->register_source( new Daymark_Subscription_Source_Feed() );
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
	 * Keeps callers (the subscribe-by-URL REST handler) source-agnostic even
	 * though only the built-in RSS/Atom feed source ships today — a future
	 * source (Friends, ActivityPub) registering via
	 * `daymark_register_subscription_sources` needs no caller-side changes.
	 *
	 * @param string $site_url Site URL entered by the user (not a feed URL).
	 * @return array<int|string, mixed> The first source's non-empty discover()
	 *                                  result, or an empty array when no
	 *                                  registered source discovers anything.
	 */
	public function discover_feeds( string $site_url ): array {
		foreach ( $this->sources as $source ) {
			$discovered = $source->discover( $site_url );

			if ( ! empty( $discovered ) ) {
				return $discovered;
			}
		}

		return array();
	}
}
