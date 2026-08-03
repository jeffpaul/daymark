<?php
/**
 * Bluesky connector (mocked).
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mocked Bluesky destination.
 *
 * Real implementation: authenticate against the AT Protocol (app
 * password or OAuth session via com.atproto.server.createSession),
 * then create a post record via com.atproto.repo.createRecord with an
 * app.bsky.feed.post record — either directly or through a WordPress
 * Connector plugin registered on `moment_register_connectors`.
 */
class Moment_Connector_Bluesky extends Moment_Connector_Base {

	/**
	 * Connector ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'bluesky';
	}

	/**
	 * Connector label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Bluesky', 'moment' );
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
		return 'mock-bsky';
	}

	/**
	 * Mock external URL base.
	 *
	 * @return string
	 */
	protected function get_mock_url_base(): string {
		return 'https://bsky.app/profile/demo/post/';
	}
}
