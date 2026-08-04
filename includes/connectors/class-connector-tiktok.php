<?php
/**
 * TikTok connector (mocked).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mocked TikTok destination.
 *
 * Real implementation: OAuth via TikTok Login Kit, then the Content
 * Posting API — initialize an upload (POST /v2/post/publish/video/init/),
 * transfer the video bytes, and poll publish status. Best delivered by
 * a dedicated connector plugin hooked to `daymark_register_connectors`.
 */
class Daymark_Connector_TikTok extends Daymark_Connector_Base {

	/**
	 * Connector ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'tiktok';
	}

	/**
	 * Connector label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'TikTok', 'daymark' );
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
		return 'mock-tt';
	}

	/**
	 * Mock external URL base.
	 *
	 * @return string
	 */
	protected function get_mock_url_base(): string {
		return 'https://tiktok.com/@demo/video/';
	}
}
