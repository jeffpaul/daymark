<?php
/**
 * Threads connector (mocked).
 *
 * @package Moment
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
class Moment_Connector_Threads extends Moment_Connector_Base {

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
		return __( 'Threads', 'moment' );
	}

	/**
	 * Supported Moment types.
	 *
	 * Text-first network: any Moment can be announced as caption + permalink.
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
