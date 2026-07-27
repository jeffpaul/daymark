<?php
/**
 * ATmosphere transport for the Bluesky connector.
 *
 * When the ATmosphere plugin (with the Connectors-API integration from
 * PR #203) is active with a connected account AND its own auto-publish is
 * OFF, Moment drives Bluesky through ATmosphere's OAuth connection instead
 * of an app password — no app password needed.
 *
 * Auto-publish ON is deliberately left alone: ATmosphere already posts
 * every published post, so driving it too (or app-password posting) would
 * double-post. In that state the Bluesky destination is not offered and
 * ATmosphere surfaces in Moment's awareness note instead. ATmosphere's own
 * per-post opt-out meta (`atmosphere_disabled`) gates both auto-publish and
 * the imperative Publisher::publish_post(), so there is no safe way to
 * drive one without the other per post.
 *
 * @package Moment_Bluesky
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges Moment's Bluesky connector to ATmosphere's publishing API.
 */
class Moment_Bluesky_Atmosphere {

	/**
	 * ATmosphere's per-post opt-out meta key (value '1' disables sharing).
	 * Literal to avoid depending on ATmosphere's constant name/namespace.
	 */
	private const DISABLED_META = 'atmosphere_disabled';

	/**
	 * ATmosphere's stored AT-URI meta for the published record.
	 */
	private const URI_META = '_atmosphere_bsky_uri';

	/**
	 * Whether ATmosphere's public publishing API is present at all.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'Atmosphere\\is_connected' )
			&& function_exists( 'Atmosphere\\is_auto_publish_enabled' )
			&& class_exists( 'Atmosphere\\Publisher' );
	}

	/**
	 * Whether Moment can drive Bluesky through ATmosphere: present, an
	 * account connected, and ATmosphere's own auto-publish OFF.
	 *
	 * @return bool
	 */
	public static function can_drive(): bool {
		return self::is_active()
			&& \Atmosphere\is_connected()
			&& ! \Atmosphere\is_auto_publish_enabled();
	}

	/**
	 * Whether ATmosphere would auto-post every published post itself. In
	 * this state Moment must not also drive Bluesky (double-post), so the
	 * destination is withheld.
	 *
	 * @return bool
	 */
	public static function would_autopost(): bool {
		return self::is_active()
			&& \Atmosphere\is_connected()
			&& \Atmosphere\is_auto_publish_enabled();
	}

	/**
	 * Publish a Moment to Bluesky through ATmosphere.
	 *
	 * @param int $post_id Moment post ID.
	 * @return array<string, mixed> Connector result shape.
	 */
	public static function publish( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return self::error_result( __( 'Moment not found.', 'moment-connector-bluesky' ) );
		}

		// The user selected Bluesky for this Moment, so make sure a stray
		// opt-out isn't blocking it, then publish through ATmosphere.
		delete_post_meta( $post_id, self::DISABLED_META );

		$result = \Atmosphere\Publisher::publish_post( $post );

		if ( is_wp_error( $result ) ) {
			return self::error_result( $result->get_error_message() );
		}

		$at_uri = (string) get_post_meta( $post_id, self::URI_META, true );

		return array(
			'success'            => true,
			'external_id'        => '' !== $at_uri ? $at_uri : 'atmosphere-' . $post_id,
			'external_url'       => '' !== $at_uri ? self::web_url( $at_uri ) : '',
			// ATmosphere owns backflow for its posts (reply-sync imports
			// native comments, which Moment labels); Moment must not poll
			// them (no app-password session), so it does not auto-sync.
			'backflow_supported' => false,
			'status'             => 'published',
			'message'            => __( 'Published to Bluesky via ATmosphere.', 'moment-connector-bluesky' ),
		);
	}

	/**
	 * Build a bsky.app web URL from an at:// record URI.
	 *
	 * @param string $at_uri at://did/app.bsky.feed.post/rkey.
	 * @return string
	 */
	private static function web_url( string $at_uri ): string {
		$parts = explode( '/', $at_uri );
		$rkey  = end( $parts );
		$did   = '';

		if ( preg_match( '#^at://(did:[^/]+)/#', $at_uri, $m ) ) {
			$did = $m[1];
		}

		if ( '' === $did || '' === $rkey ) {
			return '';
		}

		return 'https://bsky.app/profile/' . $did . '/post/' . $rkey;
	}

	/**
	 * A failed-publish result that never blocks the Moment.
	 *
	 * @param string $message Reason.
	 * @return array<string, mixed>
	 */
	private static function error_result( string $message ): array {
		return array(
			'success'            => false,
			'external_id'        => '',
			'external_url'       => '',
			'backflow_supported' => false,
			'status'             => 'failed',
			'message'            => $message,
		);
	}
}
