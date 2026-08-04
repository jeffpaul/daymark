<?php
/**
 * Mastodon connector (mocked).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mocked Mastodon destination.
 *
 * Real implementation: OAuth 2.0 against the user's home instance,
 * upload media via POST /api/v2/media, then create the status via
 * POST /api/v1/statuses — either directly or by delegating to an
 * existing Mastodon publishing plugin as a thin adapter.
 */
class Daymark_Connector_Mastodon extends Daymark_Connector_Base {

	/**
	 * Connector ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'mastodon';
	}

	/**
	 * Connector label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Mastodon', 'daymark' );
	}

	/**
	 * Supported Mark types.
	 *
	 * Text-first network: any Mark can be announced as caption + permalink.
	 *
	 * @return string[]
	 */
	protected function get_supported_types(): array {
		return array( 'note', 'image', 'gallery', 'video', 'audio', 'mixed' );
	}

	/**
	 * Mock external-ID prefix.
	 *
	 * @return string
	 */
	protected function get_mock_id_prefix(): string {
		return 'mock-mastodon';
	}

	/**
	 * Mock external URL base.
	 *
	 * @return string
	 */
	protected function get_mock_url_base(): string {
		return 'https://mastodon.social/@demo/';
	}
}
