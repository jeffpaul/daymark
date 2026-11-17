<?php
/**
 * E2E fixture: a pretend Microsub server, its owner's web address with an
 * IndieAuth sign-in page, and three sites it follows.
 *
 * The Microsub import (issue #592) sends the browser to the person's own
 * sign-in page and then talks to their token endpoint and Microsub server.
 * None of that exists on the E2E site, so this fixture stands in for all
 * of it:
 *
 * - Server-side requests to the made-up `microsub-*.example` hosts are
 *   answered here through `pre_http_request`, shaped as the IndieAuth and
 *   Microsub specs describe. Requests to any other host are untouched.
 * - The sign-in page is served by this site itself, at
 *   `/?daymark_e2e_indieauth=1`, with an Approve and a Deny link, because
 *   the browser really has to go there and come back.
 * - `/?daymark_e2e_microsub_reset=1` unfollows the pretend sites and
 *   forgets any half-finished import, so a retried test starts clean.
 *
 * Copied into mu-plugins by the Playwright CI job's Seed step, never part
 * of the distributed plugin.
 */

const DAYMARK_E2E_MICROSUB_CODE  = 'e2e-code';
const DAYMARK_E2E_MICROSUB_TOKEN = 'e2e-token';

/**
 * The pretend sites the pretend server follows, by channel.
 *
 * @return array<string, string[]> Hosts by channel uid.
 */
function daymark_e2e_microsub_follows(): array {
	return array(
		'friends' => array( 'microsub-ada.example', 'microsub-grace.example' ),
		'news'    => array( 'microsub-news.example' ),
	);
}

/**
 * A canned HTTP API answer.
 *
 * @param string $body Body.
 * @param int    $code Status code.
 * @param string $type Content type.
 * @return array<string, mixed>
 */
function daymark_e2e_microsub_response( string $body, int $code = 200, string $type = 'text/html; charset=UTF-8' ): array {
	return array(
		'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'content-type' => $type ) ),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * A canned JSON answer.
 *
 * @param array<string, mixed> $data Data.
 * @param int                  $code Status code.
 * @return array<string, mixed>
 */
function daymark_e2e_microsub_json( array $data, int $code = 200 ): array {
	return daymark_e2e_microsub_response( (string) wp_json_encode( $data ), $code, 'application/json' );
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( 0 !== strpos( $host, 'microsub-' ) || '.example' !== substr( $host, -8 ) ) {
			return $preempt;
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = array();

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		// The person's own web address.
		if ( 'microsub-me.example' === $host ) {
			if ( '/token' === $path ) {
				$body = is_array( $args['body'] ?? null ) ? $args['body'] : array();

				if ( 'revoke' === ( $body['action'] ?? '' ) ) {
					return daymark_e2e_microsub_response( '' );
				}

				if ( DAYMARK_E2E_MICROSUB_CODE !== ( $body['code'] ?? '' ) || empty( $body['code_verifier'] ) || 'authorization_code' !== ( $body['grant_type'] ?? '' ) ) {
					return daymark_e2e_microsub_json( array( 'error' => 'invalid_grant' ), 400 );
				}

				return daymark_e2e_microsub_json(
					array(
						'access_token' => DAYMARK_E2E_MICROSUB_TOKEN,
						'token_type'   => 'Bearer',
						'scope'        => 'read follow channels',
						'me'           => 'https://microsub-me.example/',
					)
				);
			}

			return daymark_e2e_microsub_response(
				'<html><head><title>Me</title>'
				. '<link rel="authorization_endpoint" href="' . esc_url( home_url( '/?daymark_e2e_indieauth=1' ) ) . '">'
				. '<link rel="token_endpoint" href="https://microsub-me.example/token">'
				. '<link rel="microsub" href="https://microsub-reader.example/microsub">'
				. '</head><body></body></html>'
			);
		}

		// The Microsub server.
		if ( 'microsub-reader.example' === $host ) {
			if ( 'Bearer ' . DAYMARK_E2E_MICROSUB_TOKEN !== ( $args['headers']['Authorization'] ?? '' ) ) {
				return daymark_e2e_microsub_json( array( 'error' => 'unauthorized' ), 401 );
			}

			if ( 'channels' === ( $query['action'] ?? '' ) ) {
				return daymark_e2e_microsub_json(
					array(
						'channels' => array(
							array(
								'uid'  => 'notifications',
								'name' => 'Notifications',
							),
							array(
								'uid'  => 'friends',
								'name' => 'Friends',
							),
							array(
								'uid'  => 'news',
								'name' => 'News',
							),
						),
					)
				);
			}

			$follows = daymark_e2e_microsub_follows();
			$channel = (string) ( $query['channel'] ?? '' );

			if ( 'follow' === ( $query['action'] ?? '' ) && isset( $follows[ $channel ] ) ) {
				return daymark_e2e_microsub_json(
					array(
						'items' => array_map(
							static function ( $followed_host ) {
								return array(
									'type' => 'feed',
									'url'  => 'https://' . $followed_host . '/',
								);
							},
							$follows[ $channel ]
						),
					)
				);
			}

			return daymark_e2e_microsub_json( array( 'error' => 'invalid_request' ), 400 );
		}

		// A followed site: a home page that links to a one-post RSS feed.
		$name = ucfirst( str_replace( array( 'microsub-', '.example' ), '', $host ) );

		if ( '/feed/' === $path ) {
			return daymark_e2e_microsub_response(
				'<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
				. '<title>' . esc_html( $name ) . '</title><link>https://' . $host . '/</link><description>E2E</description>'
				. '<item><title>Hello from ' . esc_html( $name ) . '</title><link>https://' . $host . '/hello/</link>'
				. '<guid>https://' . $host . '/hello/</guid><pubDate>Mon, 02 Nov 2026 10:00:00 +0000</pubDate>'
				. '<description>A first post.</description></item>'
				. '</channel></rss>',
				200,
				'application/rss+xml; charset=UTF-8'
			);
		}

		if ( '/' === $path || '' === $path ) {
			return daymark_e2e_microsub_response(
				'<html><head><title>' . esc_html( $name ) . '</title>'
				. '<link rel="alternate" type="application/rss+xml" href="https://' . $host . '/feed/">'
				. '</head><body></body></html>'
			);
		}

		return daymark_e2e_microsub_response( 'Not found', 404 );
	},
	10,
	3
);

add_action(
	'init',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- E2E fixture only.
		if ( isset( $_GET['daymark_e2e_microsub_reset'] ) && current_user_can( 'manage_options' ) ) {
			$subscriptions = Daymark_Plugin::instance()->subscriptions;

			foreach ( $subscriptions->get_all() as $subscription ) {
				if ( false !== strpos( (string) ( $subscription['feed_url'] ?? '' ), '//microsub-' ) ) {
					$subscriptions->unsubscribe( (int) $subscription['id'] );
				}
			}

			delete_transient( 'daymark_microsub_import_follows_' . get_current_user_id() );
			delete_transient( 'daymark_microsub_import_sign_in_' . get_current_user_id() );

			wp_die( 'Microsub fixture reset.', '', array( 'response' => 200 ) );
		}

		if ( ! isset( $_GET['daymark_e2e_indieauth'] ) ) {
			return;
		}

		$redirect = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$valid    = 'code' === ( $_GET['response_type'] ?? '' )
			&& 'S256' === ( $_GET['code_challenge_method'] ?? '' )
			&& ! empty( $_GET['code_challenge'] )
			&& ! empty( $_GET['client_id'] )
			&& '' !== $state
			&& 0 === strpos( $redirect, admin_url( 'admin-post.php' ) );
		// phpcs:enable

		if ( ! $valid ) {
			wp_die( 'Bad IndieAuth request.', '', array( 'response' => 400 ) );
		}

		$approve = add_query_arg(
			array(
				'code'  => DAYMARK_E2E_MICROSUB_CODE,
				'state' => rawurlencode( $state ),
			),
			$redirect
		);
		$deny    = add_query_arg(
			array(
				'error' => 'access_denied',
				'state' => rawurlencode( $state ),
			),
			$redirect
		);

		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in</title></head>';
		echo '<body style="font-family:sans-serif;max-width:28em;margin:3em auto;padding:0 1em;">';
		echo '<h1>Sign in as microsub-me.example</h1>';
		echo '<p>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . ' would like to read your channels and the sites you follow.</p>';
		echo '<p><a id="daymark-e2e-approve" href="' . esc_url( $approve ) . '">Approve</a> &nbsp; <a id="daymark-e2e-deny" href="' . esc_url( $deny ) . '">Deny</a></p>';
		echo '</body></html>';
		exit;
	}
);
