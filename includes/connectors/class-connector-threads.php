<?php
/**
 * Threads connector (mocked).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mocked Threads destination.
 *
 * Real implementation: authenticate via the Threads API (Meta OAuth),
 * create a media container via POST /{threads-user-id}/threads, then
 * publish via POST /{threads-user-id}/threads_publish — or adapt an
 * existing social publishing plugin that already speaks Threads.
 */
class Daymark_Connector_Threads extends Daymark_Connector_Base {

	/**
	 * Connector ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'threads';
	}

	/**
	 * Connector label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Threads', 'daymark' );
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
		return 'mock-th';
	}

	/**
	 * Mock external URL base.
	 *
	 * @return string
	 */
	protected function get_mock_url_base(): string {
		return 'https://threads.net/@demo/post/';
	}
}
