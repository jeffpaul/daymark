<?php
/**
 * The real Bluesky connector for Moment's syndication registry.
 *
 * Replaces Moment's built-in mocked Bluesky connector (same ID) when this
 * plugin is active. Publishes via the AT Protocol when credentials are
 * configured; degrades to a mocked publish when they are not, so the
 * Moment demo flow keeps working unconfigured.
 *
 * @package Moment_Bluesky
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Real Bluesky syndication connector.
 */
class Moment_Bluesky_Connector implements Moment_Syndication_Connector {

	/**
	 * Connector ID — matches Moment's built-in mock so this replaces it.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'bluesky';
	}

	/**
	 * Display label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Bluesky', 'moment-connector-bluesky' );
	}

	/**
	 * Bluesky is text-first, so every Moment type is supported — any
	 * Moment can be announced as caption + permalink.
	 *
	 * @param string $type Moment primary type.
	 * @return bool
	 */
	public function supports_moment_type( string $type ): bool {
		return in_array( $type, array( 'note', 'image', 'gallery', 'video', 'audio', 'podcast', 'mixed' ), true );
	}

	/**
	 * Connected when Moment can drive Bluesky — through ATmosphere's OAuth
	 * connection (preferred), else a configured app password.
	 *
	 * Withheld when ATmosphere is present and auto-posting every post
	 * itself: offering the toggle then would double-post, so ATmosphere
	 * surfaces in Moment's awareness note instead.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		if ( Moment_Bluesky_Atmosphere::can_drive() ) {
			return true;
		}

		if ( Moment_Bluesky_Atmosphere::would_autopost() ) {
			return false;
		}

		return Moment_Bluesky_Integration::is_configured();
	}

	/**
	 * Status label for the publish screen.
	 *
	 * @return string
	 */
	public function get_status_label(): string {
		if ( Moment_Bluesky_Atmosphere::can_drive() ) {
			return __( 'Connected · via ATmosphere', 'moment-connector-bluesky' );
		}

		return $this->is_connected()
			? __( 'Connected', 'moment-connector-bluesky' )
			: __( 'Not connected · Mocked', 'moment-connector-bluesky' );
	}

	/**
	 * Publish a Moment to Bluesky.
	 *
	 * Real path: caption + permalink posted via createRecord; the at:// URI
	 * is stored as the external ID so backflow can query the thread later.
	 * Unconfigured or on API failure: a mocked result, so publishing never
	 * blocks (mirrors Moment's AI Assist philosophy).
	 *
	 * @param int                  $post_id Moment post ID.
	 * @param array<string, mixed> $payload Moment context data.
	 * @return array<string, mixed>
	 */
	public function publish( int $post_id, array $payload ): array {
		if ( ! $this->is_connected() ) {
			return $this->mock_result( $post_id );
		}

		// Prefer ATmosphere's OAuth connection when it can drive publishing.
		if ( Moment_Bluesky_Atmosphere::can_drive() ) {
			return Moment_Bluesky_Atmosphere::publish( $post_id );
		}

		$caption = isset( $payload['caption'] ) ? wp_strip_all_tags( (string) $payload['caption'] ) : '';

		if ( '' === trim( $caption ) ) {
			$caption = get_the_title( $post_id );
		}

		$permalink = get_permalink( $post_id );
		$text      = trim( $caption . "\n\n" . ( $permalink ? $permalink : '' ) );

		$result = Moment_Bluesky_Integration::client()->create_post( $text );

		if ( is_wp_error( $result ) ) {
			// Never block publishing: record a failed-over mock result and
			// surface the reason for the demo/debug trail.
			$mock            = $this->mock_result( $post_id );
			$mock['message'] = $result->get_error_message();

			return $mock;
		}

		return array(
			'success'            => true,
			'external_id'        => $result['at_uri'],
			'external_url'       => $result['web_url'],
			'status'             => 'published',
			'backflow_supported' => true,
			'message'            => __( 'Published to Bluesky.', 'moment-connector-bluesky' ),
		);
	}

	/**
	 * Mocked publish result (unconfigured / failed-over path).
	 *
	 * Shape matches Moment's built-in mock connector so downstream
	 * handling is identical.
	 *
	 * @param int $post_id Moment post ID.
	 * @return array<string, mixed>
	 */
	private function mock_result( int $post_id ): array {
		return array(
			'success'            => true,
			'external_id'        => 'mock-bsky-' . $post_id,
			'external_url'       => 'https://bsky.app/profile/demo/post/mock-bsky-' . $post_id,
			'status'             => 'mocked',
			'backflow_supported' => false,
			'message'            => __( 'Demo mode — Bluesky not connected.', 'moment-connector-bluesky' ),
		);
	}
}
