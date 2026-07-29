<?php
/**
 * Abstract base for mocked syndication connectors.
 *
 * Centralizes the demo connectors' behavior: no real credentials, no
 * real network calls, deterministic fake external IDs and URLs. A real
 * connector may extend this class or implement the interface directly.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared mock behavior for the built-in demo connectors.
 */
abstract class Moment_Connector_Base implements Moment_Syndication_Connector {

	/**
	 * Moment types this connector supports.
	 *
	 * @return string[] Subset of: note, image, video, audio, podcast, gallery, mixed.
	 */
	abstract protected function get_supported_types(): array;

	/**
	 * Deterministic mock external-ID prefix, e.g. 'mock-bsky'.
	 *
	 * @return string
	 */
	abstract protected function get_mock_id_prefix(): string;

	/**
	 * Mock external URL base; the mock external ID is appended to it.
	 *
	 * E.g. 'https://bsky.app/profile/demo/post/' → https://bsky.app/profile/demo/post/mock-bsky-123
	 *
	 * @return string
	 */
	abstract protected function get_mock_url_base(): string;

	/**
	 * Whether this connector supports the given Moment type.
	 *
	 * @param string $type Primary Moment type.
	 * @return bool
	 */
	public function supports_moment_type( string $type ): bool {
		return in_array( $type, $this->get_supported_types(), true );
	}

	/**
	 * Demo connectors are never connected — no credentials exist.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return false;
	}

	/**
	 * Short status label for the publish UI.
	 *
	 * @return string
	 */
	public function get_status_label(): string {
		return $this->is_connected()
			? __( 'Connected', 'moment' )
			: __( 'Mocked · Demo', 'moment' );
	}

	/**
	 * Mock publish. Returns deterministic demo data; never throws.
	 *
	 * NOTE: Real implementation would authenticate via the WordPress
	 * Connector API or an existing social publishing plugin, then call
	 * the platform API. See:
	 * https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/
	 *
	 * @param int                  $post_id Moment post ID.
	 * @param array<string, mixed> $payload Moment context data (unused by mocks).
	 * @return array{success: bool, external_id: ?string, external_url: ?string, status: string, message: string}
	 */
	public function publish( int $post_id, array $payload ): array {
		unset( $payload ); // Mocks ignore the payload; real connectors will not.

		$external_id = $this->get_mock_id_prefix() . '-' . $post_id;

		return array(
			'success'      => true,
			'external_id'  => $external_id,
			'external_url' => $this->get_mock_url_base() . $external_id,
			'status'       => 'mocked',
			'message'      => sprintf(
				/* translators: %s: connector label, e.g. Bluesky. */
				__( 'Demo mode — %s not connected.', 'moment' ),
				$this->get_label()
			),
		);
	}
}
