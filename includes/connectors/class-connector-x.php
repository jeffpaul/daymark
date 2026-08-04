<?php
/**
 * X (Twitter) connector (mocked).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mocked X destination.
 *
 * Real implementation: OAuth 2.0 PKCE (or OAuth 1.0a for media),
 * upload media via the media upload endpoint, then create the post via
 * X API v2 POST /2/tweets. Given API access pricing, this is a prime
 * candidate for delegation to a hosted provider connector.
 */
class Daymark_Connector_X extends Daymark_Connector_Base {

	/**
	 * Connector ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'x';
	}

	/**
	 * Connector label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'X', 'daymark' );
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
		return 'mock-x';
	}

	/**
	 * Mock external URL base.
	 *
	 * @return string
	 */
	protected function get_mock_url_base(): string {
		return 'https://x.com/demo/status/';
	}
}
