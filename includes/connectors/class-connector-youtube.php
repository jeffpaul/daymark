<?php
/**
 * YouTube connector (mocked).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mocked YouTube destination.
 *
 * Real implementation: Google OAuth 2.0, then a resumable upload via
 * the YouTube Data API v3 (videos.insert) with snippet/status parts.
 * Long uploads would be queued (e.g. Action Scheduler) rather than run
 * inside the publish request.
 */
class Daymark_Connector_YouTube extends Daymark_Connector_Base {

	/**
	 * Connector ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'youtube';
	}

	/**
	 * Connector label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'YouTube', 'daymark' );
	}

	/**
	 * Supported Mark types.
	 *
	 * @return string[]
	 */
	protected function get_supported_types(): array {
		return array( 'video', 'mixed' );
	}

	/**
	 * Mock external-ID prefix.
	 *
	 * @return string
	 */
	protected function get_mock_id_prefix(): string {
		return 'mock-yt';
	}

	/**
	 * Mock external URL base.
	 *
	 * @return string
	 */
	protected function get_mock_url_base(): string {
		return 'https://youtube.com/watch?v=';
	}
}
