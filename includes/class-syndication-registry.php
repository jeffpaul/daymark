<?php
/**
 * Daymark Syndication Registry
 *
 * Manages outbound publishing connectors for Daymark.
 *
 * Integration paths for real connectors:
 *
 * 1. WordPress Connector plugins (preferred for WP 7.0+ environments)
 *    Register via the `daymark_register_connectors` action hook.
 *
 * 2. Existing WordPress social publishing plugins
 *    Implement Daymark_Syndication_Connector as a thin adapter that
 *    delegates to the existing plugin's publish method.
 *
 * 3. Native Daymark connector plugins
 *    Standalone plugins that implement the interface and register
 *    via `daymark_register_connectors`.
 *
 * 4. Hosted provider integrations
 *    A hosting provider can register connectors at platform level
 *    for managed social connections.
 *
 * Core Daymark does not own any network API credentials or OAuth flows.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry of syndication connectors and default routing rules.
 *
 * Built-in mocked connectors are registered at construction, so they
 * always exist before `daymark_register_connectors` fires on `init`
 * (see Daymark_Plugin::on_init()) for third-party connectors.
 *
 * Future real connectors can be registered via:
 *
 *     add_action( 'daymark_register_connectors', function( $registry ) {
 *         $registry->register_connector( new My_Connector() );
 *     } );
 *
 * This allows WordPress Connector plugins, social plugins, or
 * native Daymark connector plugins to hook in without modifying core.
 */
class Daymark_Syndication_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Daymark_Syndication_Registry|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered connectors, keyed by connector ID.
	 *
	 * @var array<string, Daymark_Syndication_Connector>
	 */
	private array $connectors = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Daymark_Syndication_Registry
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
	 * Registers the built-in mocked connectors immediately so they are
	 * available before external connectors register on `init`.
	 */
	private function __construct() {
		$this->register_built_in_connectors();
	}

	/**
	 * Register the seven built-in mocked connectors.
	 *
	 * @return void
	 */
	private function register_built_in_connectors(): void {
		$this->register_connector( new Daymark_Connector_Bluesky() );
		$this->register_connector( new Daymark_Connector_Mastodon() );
		$this->register_connector( new Daymark_Connector_Instagram() );
		$this->register_connector( new Daymark_Connector_YouTube() );
		$this->register_connector( new Daymark_Connector_TikTok() );
		$this->register_connector( new Daymark_Connector_Threads() );
		$this->register_connector( new Daymark_Connector_X() );
	}

	/**
	 * Register a connector.
	 *
	 * Third-party adapters register via the `daymark_register_connectors`
	 * action, which receives this registry instance.
	 *
	 * @param Daymark_Syndication_Connector $connector Connector instance.
	 * @return void
	 */
	public function register_connector( Daymark_Syndication_Connector $connector ): void {
		$id = sanitize_key( $connector->get_id() );

		if ( '' === $id ) {
			return;
		}

		$this->connectors[ $id ] = $connector;
	}

	/**
	 * Get all registered connectors.
	 *
	 * @return array<string, Daymark_Syndication_Connector>
	 */
	public function get_connectors(): array {
		return $this->connectors;
	}

	/**
	 * Get a single connector by ID.
	 *
	 * @param string $id Connector ID.
	 * @return Daymark_Syndication_Connector|null
	 */
	public function get_connector( string $id ): ?Daymark_Syndication_Connector {
		return $this->connectors[ $id ] ?? null;
	}

	/**
	 * Get all registered connectors that support a Mark type.
	 *
	 * @param string $type Primary Mark type.
	 * @return array<string, Daymark_Syndication_Connector>
	 */
	public function get_supported_for_type( string $type ): array {
		return array_filter(
			$this->connectors,
			static fn( Daymark_Syndication_Connector $connector ): bool => $connector->supports_daymark_type( $type )
		);
	}

	/**
	 * Get default destination connector IDs for a Mark type.
	 *
	 * Routing: note→bluesky, image→instagram, gallery→instagram,
	 * video→youtube; audio and mixed have no defaults (mixed asks each
	 * time; audio awaits a configured destination).
	 *
	 * @param string $type Primary Mark type.
	 * @return string[] Connector IDs.
	 */
	public function get_defaults_for_type( string $type ): array {
		$defaults = array(
			'note'    => array( 'bluesky' ),
			'image'   => array( 'instagram' ),
			'gallery' => array( 'instagram' ),
			'video'   => array( 'youtube' ),
			'audio'   => array(),
			'mixed'   => array(),
		);

		/**
		 * Filters the default destination connector IDs for a Mark type.
		 *
		 * Lets a host, settings screen, or onboarding flow supply
		 * per-site routing without modifying core Daymark.
		 *
		 * @param string[] $type_defaults Default connector IDs for this type.
		 * @param string   $type          Primary Mark type.
		 */
		return apply_filters( 'daymark_default_destinations', $defaults[ $type ] ?? array(), $type );
	}

	/**
	 * Alias kept for Daymark_Publisher, which calls this method name.
	 *
	 * @param string $type Primary Mark type.
	 * @return string[] Connector IDs.
	 */
	public function get_default_destinations( string $type ): array {
		return $this->get_defaults_for_type( $type );
	}

	/**
	 * Publish a Mark to the selected destinations (built-in demo
	 * connectors mock; connector plugins publish for real) and record the
	 * results in post meta.
	 *
	 * Unknown connector IDs are skipped. Successful results are merged
	 * into `_daymark_external_posts` (a JSON object keyed by connector
	 * ID) and `_daymark_syndication_status` becomes 'mocked' when at
	 * least one destination succeeded.
	 *
	 * @param int                  $post_id    Mark post ID.
	 * @param string[]             $target_ids Selected connector IDs.
	 * @param array<string, mixed> $payload    Mark context data.
	 * @return array<string, array<string, mixed>> Results keyed by connector ID.
	 */
	public function publish_to_targets( int $post_id, array $target_ids, array $payload ): array {
		$results = array();
		$type    = isset( $payload['primary_type'] ) ? sanitize_key( (string) $payload['primary_type'] ) : '';

		foreach ( $target_ids as $id ) {
			$connector = $this->get_connector( (string) $id );

			if ( ! $connector ) {
				continue;
			}

			// A note can't become an Instagram post or a YouTube video:
			// skip targets that can't represent this Mark type. The
			// publish UI disables these toggles, but the REST API accepts
			// arbitrary targets, so enforce here too.
			if ( '' !== $type && ! $connector->supports_daymark_type( $type ) ) {
				$results[ $connector->get_id() ] = array(
					'success' => false,
					'status'  => 'unsupported',
					'message' => sprintf(
						/* translators: 1: connector label, 2: Mark type. */
						__( '%1$s does not support %2$s Marks.', 'daymark' ),
						$connector->get_label(),
						$type
					),
				);

				continue;
			}

			$results[ $connector->get_id() ] = $connector->publish( $post_id, $payload );
		}

		if ( $results ) {
			$this->store_results( $post_id, $results );
		}

		/**
		 * Fires after Daymark has attempted publishing to all selected
		 * destinations.
		 *
		 * @param int                                  $post_id Mark post ID.
		 * @param array<string, array<string, mixed>> $results Publish results keyed by connector ID.
		 */
		do_action( 'daymark_syndication_complete', $post_id, $results );

		return $results;
	}

	/**
	 * Merge publish results into post meta.
	 *
	 * `_daymark_external_posts` is initialized as "{}" at publish time
	 * (Phase 2), so existing entries are decoded, merged, and re-encoded
	 * as a JSON object keyed by connector ID. The stored reference
	 * carries what conversation backflow needs later: external ID/URL,
	 * connector label, timestamp, status, and backflow capability.
	 *
	 * @param int                                 $post_id Mark post ID.
	 * @param array<string, array<string, mixed>> $results Publish results keyed by connector ID.
	 * @return void
	 */
	private function store_results( int $post_id, array $results ): void {
		$external_posts = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );

		if ( ! is_array( $external_posts ) ) {
			$external_posts = array();
		}

		$any_success = false;

		foreach ( $results as $connector_id => $result ) {
			if ( empty( $result['success'] ) ) {
				continue;
			}

			$any_success = true;
			$connector   = $this->get_connector( $connector_id );

			$external_posts[ $connector_id ] = array(
				'external_id'        => isset( $result['external_id'] ) ? (string) $result['external_id'] : null,
				'external_url'       => isset( $result['external_url'] ) ? (string) $result['external_url'] : null,
				'label'              => $connector ? $connector->get_label() : $connector_id,
				'published_at'       => current_time( 'mysql' ),
				'status'             => isset( $result['status'] ) ? (string) $result['status'] : 'mocked',
				// Real connectors report backflow support in their publish result.
				'backflow_supported' => ! empty( $result['backflow_supported'] ),
			);
		}

		update_post_meta( $post_id, '_daymark_external_posts', wp_json_encode( (object) $external_posts ) );

		if ( $any_success ) {
			$statuses = wp_list_pluck( array_values( $results ), 'status' );

			// A real publish outranks mocked ones for the overall status.
			update_post_meta(
				$post_id,
				'_daymark_syndication_status',
				in_array( 'published', $statuses, true ) ? 'published' : 'mocked'
			);
		}
	}
}
