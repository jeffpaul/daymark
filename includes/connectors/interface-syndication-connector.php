<?php
/**
 * Syndication connector interface.
 *
 * Contract for all Moment outbound publishing connectors — built-in
 * mocks and future real integrations alike. Real connectors implement
 * this interface and register via the `moment_register_connectors`
 * action; core Moment code never changes.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a syndication destination (e.g. Bluesky, Instagram).
 */
interface Moment_Syndication_Connector {

	/**
	 * Unique machine identifier, e.g. 'bluesky'.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human label for UI, e.g. 'Bluesky'.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Whether this connector can publish a given Moment type.
	 *
	 * @param string $type One of: note, image, video, audio, gallery, mixed.
	 * @return bool
	 */
	public function supports_moment_type( string $type ): bool;

	/**
	 * True only if credentials are configured and the connection is live.
	 *
	 * Always false for the built-in demo connectors; real connector
	 * plugins report their configured state.
	 *
	 * @return bool
	 */
	public function is_connected(): bool;

	/**
	 * Publish a Moment to this destination.
	 *
	 * Must return a result array even on mock/failure — never throw.
	 *
	 * @param int                  $post_id Moment post ID.
	 * @param array<string, mixed> $payload Moment context data.
	 * @return array{
	 *     success: bool,
	 *     external_id: string|null,
	 *     external_url: string|null,
	 *     status: string,
	 *     message: string,
	 *     backflow_supported?: bool,
	 * } Result with status one of 'published'|'mocked'|'failed'. The optional
	 *   `backflow_supported` key marks a reference whose replies can be pulled
	 *   back into the Moment: real connectors set it true, mocks omit it, and it
	 *   defaults to false everywhere it is checked.
	 *
	 * @since 0.5.0 Documented the optional `backflow_supported` result key.
	 */
	public function publish( int $post_id, array $payload ): array;

	/**
	 * Short status for UI: 'Connected', 'Mocked · Demo', 'Not connected'.
	 *
	 * @return string
	 */
	public function get_status_label(): string;
}
