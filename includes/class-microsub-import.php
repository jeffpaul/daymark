<?php
/**
 * Import the sites someone follows on a Microsub server as Daymark
 * subscriptions (issue #592).
 *
 * Some IndieWeb users keep their reading list on a separate feed service
 * called a Microsub server (Aperture, Ekster, Yarns). This reads that list
 * once, shows it as a checklist grouped by the server's channels, and hands
 * the chosen sites to the same per-entry import the OPML and WordPress.com
 * Reader imports use (Daymark_Subscription_OPML::import_entries()). Each
 * site is then followed exactly as if it had been added by hand: Daymark
 * finds its feed itself, reads it itself, and Like, Comment, favorites, and
 * every other per-site feature work as they do for any other followed site.
 *
 * Why an import and not a live connection: a Microsub timeline is read per
 * channel, not per site, so reading posts through the server would put a
 * channel's name where a Timeline card shows a friend's site, and would
 * need a token kept on this site for as long as the connection lasted
 * (CLAUDE.md's non-goals rule out stored keys). Signing in once, reading
 * the list, and letting go of the token keeps both. See the "Microsub
 * import" row in docs/architecture-decisions.md.
 *
 * The flow, all started by an administrator on Settings -> Daymark ->
 * Import/Export:
 *
 * 1. discover(): fetch the web address they typed and read its
 *    `rel="microsub"`, `rel="indieauth-metadata"`, `rel="authorization_endpoint"`,
 *    and `rel="token_endpoint"` links (HTTP `Link` header first, then the
 *    page's `<link>` tags), per the Microsub and IndieAuth specs.
 * 2. begin(): build the IndieAuth authorization request (authorization
 *    code with PKCE). The browser is sent to the person's own sign-in page.
 * 3. complete(): back on this site, check `state`, trade the code for a
 *    token, ask the Microsub endpoint for its channels and each channel's
 *    follow list, then ask the token endpoint to revoke the token.
 *
 * The token lives only in memory for the length of step 3. Nothing but the
 * follow list (web addresses and channel names) is stored, for an hour,
 * while the person chooses.
 *
 * Every request goes through Daymark_Outbound_Guard. A request that
 * carries the code or the token follows no redirects, so neither can be
 * forwarded to an address the person's own page did not name.
 *
 * Written from the published specs (https://indieweb.org/Microsub-spec and
 * https://indieauth.spec.indieweb.org/). NOT confirmed against a live
 * Microsub server: this environment cannot reach one, the same posture as
 * Daymark_Reader_Import. Every remote answer is treated as untrusted, and a
 * wrong assumption degrades to a readable error, never a fatal one.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a follow list from a Microsub server after an IndieAuth sign-in.
 */
class Daymark_Microsub_Import {

	/**
	 * The admin-post action the sign-in page sends the browser back to.
	 *
	 * @var string
	 */
	public const CALLBACK_ACTION = 'daymark_microsub_callback';

	/**
	 * The most bytes read from the person's web page during discovery.
	 *
	 * @var int
	 */
	private const MAX_PAGE_BYTES = 1048576;

	/**
	 * The most bytes read from any JSON answer.
	 *
	 * @var int
	 */
	private const MAX_JSON_BYTES = 1048576;

	/**
	 * Whether the Microsub import is offered at all.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filters whether Settings -> Daymark -> Import/Export offers the
		 * Microsub import.
		 *
		 * @since 0.23.0
		 *
		 * @param bool $enabled Whether the import is offered. Default true.
		 */
		return (bool) apply_filters( 'daymark_microsub_import_enabled', true );
	}

	/**
	 * The scopes asked for at sign-in.
	 *
	 * `read` and `follow` cover what the import does (list channels, list
	 * each channel's follows). `channels` is asked for as well because the
	 * spec leaves it to each server whether listing channels needs it, and
	 * the readers people already use with these servers ask for it too. The
	 * token is revoked as soon as the list has been read.
	 *
	 * @return string Space-separated scopes.
	 */
	public static function scope(): string {
		/**
		 * Filters the IndieAuth scopes the Microsub import asks for.
		 *
		 * @since 0.23.0
		 *
		 * @param string $scope Space-separated scopes. Default 'read follow channels'.
		 */
		$scope = (string) apply_filters( 'daymark_microsub_import_scope', 'read follow channels' );

		return trim( (string) preg_replace( '/[^a-z0-9_:. -]+/i', '', $scope ) );
	}

	/**
	 * The most channels one import reads.
	 *
	 * @return int
	 */
	public static function max_channels(): int {
		/**
		 * Filters how many Microsub channels one import reads.
		 *
		 * @since 0.23.0
		 *
		 * @param int $max Channel cap. Default 50.
		 */
		return max( 1, (int) apply_filters( 'daymark_microsub_import_max_channels', 50 ) );
	}

	/**
	 * The most follows one import will list: the same cap the OPML import
	 * applies to one file (`daymark_subscription_opml_max_entries`).
	 *
	 * @return int
	 */
	public static function max_entries(): int {
		/** This filter is documented in includes/class-subscription-opml.php. */
		return max( 0, (int) apply_filters( 'daymark_subscription_opml_max_entries', 1000 ) );
	}

	/**
	 * How long, in seconds, one request may spend on remote work (reading
	 * channel lists, or importing chosen sites) before it stops and lets
	 * the person carry on with what is left.
	 *
	 * @return int
	 */
	public static function time_budget(): int {
		/**
		 * Filters how many seconds one Microsub import request may spend
		 * reading channels or importing sites before it stops and offers to
		 * carry on.
		 *
		 * @since 0.23.0
		 *
		 * @param int $seconds Time budget. Default 20.
		 */
		return max( 0, (int) apply_filters( 'daymark_microsub_import_time_budget', 20 ) );
	}

	/**
	 * Where the sign-in page sends the browser back to.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return add_query_arg( 'action', self::CALLBACK_ACTION, admin_url( 'admin-post.php' ) );
	}

	/**
	 * The address this site signs in as (IndieAuth's `client_id`): the
	 * site's home address, or, on the rare install whose admin lives on a
	 * different host, the top of that host, because the redirect address
	 * must share the client's scheme, host, and port.
	 *
	 * @return string
	 */
	public static function client_id(): string {
		$home = home_url( '/' );

		if ( self::origin( $home ) === self::origin( admin_url() ) ) {
			return $home;
		}

		return self::origin( admin_url() ) . '/';
	}

	/**
	 * Step 1 and 2: look the web address up and build the sign-in request.
	 *
	 * @param string $me The web address the person typed.
	 * @return array{redirect: string, pending: array<string, string>}|WP_Error
	 *         `redirect` is where to send the browser; `pending` is what
	 *         complete() needs when it comes back (no secrets beyond the
	 *         one-time PKCE verifier and state).
	 */
	public static function begin( string $me ) {
		$endpoints = self::discover( $me );

		if ( is_wp_error( $endpoints ) ) {
			return $endpoints;
		}

		$state    = wp_generate_password( 40, false );
		$verifier = wp_generate_password( 64, false );

		$query = array(
			'response_type'         => 'code',
			'client_id'             => self::client_id(),
			'redirect_uri'          => self::redirect_uri(),
			'state'                 => $state,
			'code_challenge'        => self::base64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
			'scope'                 => self::scope(),
			'me'                    => $endpoints['me'],
		);

		$authorization = $endpoints['authorization_endpoint'];
		$separator     = false === strpos( $authorization, '?' ) ? '?' : '&';

		return array(
			'redirect' => $authorization . $separator . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
			'pending'  => array(
				'state'               => $state,
				'verifier'            => $verifier,
				'me'                  => $endpoints['me'],
				'token_endpoint'      => $endpoints['token_endpoint'],
				'microsub_endpoint'   => $endpoints['microsub_endpoint'],
				'revocation_endpoint' => $endpoints['revocation_endpoint'],
				'issuer'              => $endpoints['issuer'],
			),
		);
	}

	/**
	 * Step 3: finish the sign-in and read the follow list.
	 *
	 * @param array<string, mixed> $pending begin()'s `pending` value.
	 * @param array<string, mixed> $params  The query the sign-in page sent
	 *                                      back (`code`, `state`, `iss`,
	 *                                      `error`), already unslashed.
	 * @return array{server: string, entries: array<int, array<string, string>>, total: int, truncated: bool, incomplete: bool}|WP_Error
	 */
	public static function complete( array $pending, array $params ) {
		$expected = isset( $pending['state'] ) && is_string( $pending['state'] ) ? $pending['state'] : '';
		$state    = isset( $params['state'] ) && is_string( $params['state'] ) ? $params['state'] : '';

		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			return new WP_Error(
				'daymark_microsub_import_expired',
				__( 'That sign-in has expired. Please start again.', 'daymark' )
			);
		}

		if ( ! empty( $params['error'] ) ) {
			return new WP_Error(
				'daymark_microsub_import_cancelled',
				__( 'Sign-in was cancelled, so nothing was imported.', 'daymark' )
			);
		}

		$issuer = isset( $pending['issuer'] ) ? (string) $pending['issuer'] : '';
		$iss    = isset( $params['iss'] ) && is_string( $params['iss'] ) ? $params['iss'] : '';

		// IndieAuth: when the sign-in page names itself, it must be the one
		// the person's web address pointed to.
		if ( '' !== $issuer && '' !== $iss && ! hash_equals( $issuer, $iss ) ) {
			return self::sign_in_failed_error();
		}

		$code = isset( $params['code'] ) && is_string( $params['code'] ) ? $params['code'] : '';

		if ( '' === $code ) {
			return self::sign_in_failed_error();
		}

		$token = self::exchange_code( $pending, $code );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$follows = self::fetch_follows( (string) ( $pending['microsub_endpoint'] ?? '' ), $token );

		// Done with the token whether or not the list could be read.
		self::revoke( $pending, $token );

		return $follows;
	}

	/**
	 * Look a web address up for its Microsub and IndieAuth endpoints.
	 *
	 * @param string $me The web address the person typed.
	 * @return array{me: string, authorization_endpoint: string, token_endpoint: string, microsub_endpoint: string, revocation_endpoint: string, issuer: string}|WP_Error
	 */
	public static function discover( string $me ) {
		$me = self::normalize_me( $me );

		if ( '' === $me || is_wp_error( Daymark_Subscription_Url_Guard::check( $me ) ) ) {
			return new WP_Error(
				'daymark_microsub_import_invalid_url',
				__( 'Please enter your web address, such as https://example.com.', 'daymark' )
			);
		}

		$response = Daymark_Outbound_Guard::get(
			$me,
			self::request_args(
				array(
					'redirection'         => 5,
					'limit_response_size' => self::MAX_PAGE_BYTES,
					'headers'             => array( 'Accept' => 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1' ),
				)
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'daymark_microsub_import_unreachable',
				sprintf(
					/* translators: %s: the host name of the web address the person entered, such as example.com. */
					__( 'Daymark could not reach %s. Check the address and try again.', 'daymark' ),
					(string) wp_parse_url( $me, PHP_URL_HOST )
				)
			);
		}

		$links = self::links_from_header( wp_remote_retrieve_header( $response, 'link' ) )
			+ self::links_from_html( (string) wp_remote_retrieve_body( $response ) );

		$microsub = self::clean_endpoint( $links['microsub'] ?? '', $me );

		if ( '' === $microsub ) {
			return new WP_Error(
				'daymark_microsub_import_no_server',
				__( 'That web address doesn\'t point to a Microsub server, so there is no reading list to import.', 'daymark' )
			);
		}

		$endpoints = array(
			'me'                     => $me,
			'authorization_endpoint' => self::clean_endpoint( $links['authorization_endpoint'] ?? '', $me ),
			'token_endpoint'         => self::clean_endpoint( $links['token_endpoint'] ?? '', $me ),
			'microsub_endpoint'      => $microsub,
			'revocation_endpoint'    => '',
			'issuer'                 => '',
		);

		$metadata_url = self::clean_endpoint( $links['indieauth-metadata'] ?? '', $me );

		if ( '' !== $metadata_url ) {
			$endpoints = self::apply_metadata( $endpoints, $metadata_url );
		}

		if ( '' === $endpoints['authorization_endpoint'] || '' === $endpoints['token_endpoint'] ) {
			return new WP_Error(
				'daymark_microsub_import_no_sign_in',
				__( 'That web address doesn\'t offer a way to sign in with it (IndieAuth), which a Microsub server needs.', 'daymark' )
			);
		}

		return $endpoints;
	}

	/**
	 * Ask a Microsub endpoint for its channels and each channel's follows.
	 *
	 * @param string $endpoint Microsub endpoint.
	 * @param string $token    Access token.
	 * @return array{server: string, entries: array<int, array<string, string>>, total: int, truncated: bool, incomplete: bool}|WP_Error
	 */
	public static function fetch_follows( string $endpoint, string $token ) {
		$deadline = microtime( true ) + self::time_budget();
		$body     = self::microsub_get( $endpoint, array( 'action' => 'channels' ), $token );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$channels   = self::parse_channels( $body );
		$incomplete = count( $channels ) > self::max_channels();
		$channels   = array_slice( $channels, 0, self::max_channels() );
		$entries    = array();
		$seen       = array();
		$answered   = 0;

		foreach ( $channels as $index => $channel ) {
			// Always ask for the first channel; after that, stop once the
			// time budget is spent and say the list is incomplete.
			if ( $index > 0 && microtime( true ) >= $deadline ) {
				$incomplete = true;
				break;
			}

			$list = self::microsub_get(
				$endpoint,
				array(
					'action'  => 'follow',
					'channel' => $channel['uid'],
				),
				$token
			);

			if ( is_wp_error( $list ) ) {
				$incomplete = true;
				continue;
			}

			++$answered;

			$items = isset( $list['items'] ) && is_array( $list['items'] ) ? $list['items'] : array();

			foreach ( self::parse_follows( $items, $channel['name'] ) as $entry ) {
				$key = strtolower( untrailingslashit( $entry['html_url'] ) );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$entries[]    = $entry;
			}
		}

		if ( array() !== $channels && 0 === $answered ) {
			return self::list_failed_error();
		}

		$max   = self::max_entries();
		$total = count( $entries );

		return array(
			'server'     => (string) wp_parse_url( $endpoint, PHP_URL_HOST ),
			'entries'    => array_slice( $entries, 0, $max ),
			'total'      => $total,
			'truncated'  => $total > $max,
			'incomplete' => $incomplete,
		);
	}

	/**
	 * Read a `channels` answer into a plain list. The built-in
	 * `notifications` channel holds mentions, not follows, so it is left
	 * out.
	 *
	 * @param array<string, mixed> $body Decoded `action=channels` answer.
	 * @return array<int, array{uid: string, name: string}>
	 */
	public static function parse_channels( array $body ): array {
		$channels = array();
		$items    = isset( $body['channels'] ) && is_array( $body['channels'] ) ? $body['channels'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['uid'] ) || ! is_scalar( $item['uid'] ) ) {
				continue;
			}

			$uid = trim( (string) $item['uid'] );

			if ( '' === $uid || 'notifications' === $uid || strlen( $uid ) > 200 ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_scalar( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';

			$channels[] = array(
				'uid'  => $uid,
				'name' => '' !== $name ? $name : $uid,
			);
		}

		return $channels;
	}

	/**
	 * Turn one channel's `follow` items into
	 * Daymark_Subscription_OPML::import_entries() entries.
	 *
	 * A Microsub follow is one address with nothing to say whether it is a
	 * page, an RSS or Atom feed, or a JSON Feed. So it is handed over as a
	 * site address with no feed address, and the import looks it up the
	 * same way adding a site by URL does (which also accepts a feed's own
	 * address). Nothing here is trusted yet: that lookup runs every address
	 * through the same validation and SSRF guard as any other.
	 *
	 * @param array<int, mixed> $items   Raw `items` from an `action=follow` answer.
	 * @param string            $channel The channel's name.
	 * @return array<int, array{label: string, xml_url: string, html_url: string, icon_url: string, channel: string}>
	 */
	public static function parse_follows( array $items, string $channel ): array {
		$entries = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['url'] ) || ! is_string( $item['url'] ) ) {
				continue;
			}

			$url  = esc_url_raw( trim( $item['url'] ), array( 'http', 'https' ) );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );

			if ( '' === $url || '' === $host ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_string( $item['name'] )
				? sanitize_text_field( html_entity_decode( $item['name'], ENT_QUOTES, 'UTF-8' ) )
				: '';

			if ( '' === $name ) {
				$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$name = sanitize_text_field( $host . $path );
			}

			$entries[] = array(
				'label'    => $name,
				'xml_url'  => '',
				'html_url' => $url,
				'icon_url' => '',
				'channel'  => sanitize_text_field( $channel ),
			);
		}

		return $entries;
	}

	/**
	 * Tidy the web address the person typed: add `https://` when they left
	 * the scheme off, drop any fragment, and make sure there is a path.
	 *
	 * @param string $me Raw input.
	 * @return string The address, or '' when it isn't a usable http(s) one.
	 */
	public static function normalize_me( string $me ): string {
		$me = trim( $me );

		if ( '' === $me ) {
			return '';
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $me ) ) {
			$me = 'https://' . $me;
		}

		$me    = esc_url_raw( (string) strtok( $me, '#' ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $me );

		if ( '' === $me || ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		if ( ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
			$me    = self::origin( $me ) . '/' . $query;
		}

		return $me;
	}

	/**
	 * Fill in endpoints from an IndieAuth server metadata document, which
	 * the spec prefers over the page's own endpoint links.
	 *
	 * @param array<string, string> $endpoints    Endpoints found so far.
	 * @param string                $metadata_url Metadata document address.
	 * @return array<string, string>
	 */
	private static function apply_metadata( array $endpoints, string $metadata_url ): array {
		$response = Daymark_Outbound_Guard::get(
			$metadata_url,
			self::request_args(
				array(
					'redirection' => 3,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			)
		);

		$metadata = self::decode_json( $response );

		if ( is_wp_error( $metadata ) ) {
			return $endpoints;
		}

		foreach ( array( 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint' ) as $key ) {
			$value = isset( $metadata[ $key ] ) && is_string( $metadata[ $key ] ) ? self::clean_endpoint( $metadata[ $key ], $metadata_url ) : '';

			if ( '' !== $value ) {
				$endpoints[ $key ] = $value;
			}
		}

		if ( isset( $metadata['issuer'] ) && is_string( $metadata['issuer'] ) ) {
			$endpoints['issuer'] = $metadata['issuer'];
		}

		return $endpoints;
	}

	/**
	 * Trade the authorization code for an access token.
	 *
	 * @param array<string, mixed> $pending begin()'s `pending` value.
	 * @param string               $code    The code the sign-in page sent back.
	 * @return string|WP_Error The access token.
	 */
	private static function exchange_code( array $pending, string $code ) {
		$response = Daymark_Outbound_Guard::post(
			(string) ( $pending['token_endpoint'] ?? '' ),
			self::request_args(
				array(
					'headers' => array( 'Accept' => 'application/json' ),
					'body'    => array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'client_id'     => self::client_id(),
						'redirect_uri'  => self::redirect_uri(),
						'code_verifier' => (string) ( $pending['verifier'] ?? '' ),
					),
				)
			)
		);

		$body = self::decode_json( $response );

		if ( is_wp_error( $body ) ) {
			// Some older token endpoints answer form-encoded.
			$body = array();

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				wp_parse_str( (string) wp_remote_retrieve_body( $response ), $body );
			}
		}

		// The answer also names who signed in (`me`). That matters to an
		// app logging someone in; here the person is already this site's
		// administrator, and the token is only ever sent to the Microsub
		// server and token endpoint their own page named, so it isn't used.
		$token = isset( $body['access_token'] ) && is_string( $body['access_token'] ) ? trim( $body['access_token'] ) : '';

		// Only a token that is safe to put in a header.
		if ( '' === $token || preg_match( '/[^\x21-\x7E]/', $token ) ) {
			return self::sign_in_failed_error();
		}

		return $token;
	}

	/**
	 * Ask the person's own site to cancel the token, now that the list has
	 * been read. Best effort: a server that doesn't support it just lets
	 * the token run out on its own.
	 *
	 * @param array<string, mixed> $pending begin()'s `pending` value.
	 * @param string               $token   Access token.
	 * @return void
	 */
	private static function revoke( array $pending, string $token ): void {
		$revocation = (string) ( $pending['revocation_endpoint'] ?? '' );
		$body       = array( 'token' => $token );

		if ( '' === $revocation ) {
			// Before IndieAuth had a revocation endpoint, the token
			// endpoint took `action=revoke`.
			$revocation     = (string) ( $pending['token_endpoint'] ?? '' );
			$body['action'] = 'revoke';
		}

		if ( '' === $revocation ) {
			return;
		}

		Daymark_Outbound_Guard::post(
			$revocation,
			self::request_args(
				array(
					'timeout' => 5,
					'body'    => $body,
				)
			)
		);
	}

	/**
	 * One authenticated GET to the Microsub endpoint.
	 *
	 * @param string                $endpoint Microsub endpoint.
	 * @param array<string, string> $query    Query arguments.
	 * @param string                $token    Access token.
	 * @return array<string, mixed>|WP_Error Decoded answer.
	 */
	private static function microsub_get( string $endpoint, array $query, string $token ) {
		$response = Daymark_Outbound_Guard::get(
			add_query_arg( array_map( 'rawurlencode', $query ), $endpoint ),
			self::request_args(
				array(
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $token,
					),
				)
			)
		);

		$body = self::decode_json( $response );

		return is_wp_error( $body ) ? self::list_failed_error() : $body;
	}

	/**
	 * The arguments every request here shares. No redirects by default: a
	 * request that carries the code or the token must go only to the
	 * address the person's own page named.
	 *
	 * @param array<string, mixed> $extra Arguments to add or override.
	 * @return array<string, mixed>
	 */
	private static function request_args( array $extra ): array {
		/**
		 * Filters the timeout, in seconds, of each request the Microsub
		 * import makes.
		 *
		 * @since 0.23.0
		 *
		 * @param int $timeout Timeout in seconds. Default 10.
		 */
		$timeout = (int) apply_filters( 'daymark_microsub_import_timeout', 10 );

		return array_merge(
			array(
				'timeout'             => max( 1, $timeout ),
				'redirection'         => 0,
				'limit_response_size' => self::MAX_JSON_BYTES,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			),
			$extra
		);
	}

	/**
	 * Decode a 2xx JSON object answer.
	 *
	 * @param array<string, mixed>|WP_Error $response HTTP API response.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode_json( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = $code >= 200 && $code < 300 ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;

		if ( ! is_array( $body ) || ( array() !== $body && array_is_list( $body ) ) ) {
			return new WP_Error( 'daymark_microsub_import_bad_answer', 'Unexpected answer.' );
		}

		return $body;
	}

	/**
	 * Read the endpoint links out of an HTTP `Link` header.
	 *
	 * @param string|string[] $header Raw `Link` header value(s).
	 * @return array<string, string> Link address (possibly relative) by rel.
	 */
	private static function links_from_header( $header ): array {
		$header = is_array( $header ) ? implode( ', ', $header ) : (string) $header;
		$links  = array();

		if ( '' === $header ) {
			return $links;
		}

		foreach ( explode( ',', $header ) as $entry ) {
			if ( ! preg_match( '/<([^>]+)>/', $entry, $url_match ) || ! preg_match( '/;\s*rel=(?:"([^"]*)"|\'([^\']*)\'|([^;,\s]+))/i', $entry, $rel_match ) ) {
				continue;
			}

			$rels = (string) ( $rel_match[1] ?? '' ) . (string) ( $rel_match[2] ?? '' ) . (string) ( $rel_match[3] ?? '' );

			self::collect_rels( $links, $rels, trim( $url_match[1] ) );
		}

		return $links;
	}

	/**
	 * Read the endpoint links out of a page's `<link>` tags.
	 *
	 * @param string $html Page HTML.
	 * @return array<string, string> Link address (possibly relative) by rel.
	 */
	private static function links_from_html( string $html ): array {
		$links = array();

		if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $links;
		}

		try {
			$processor = new WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag( array( 'tag_name' => 'link' ) ) ) {
				$rel  = $processor->get_attribute( 'rel' );
				$href = $processor->get_attribute( 'href' );

				if ( is_string( $rel ) && is_string( $href ) ) {
					self::collect_rels( $links, $rel, trim( $href ) );
				}
			}
		} catch ( Throwable $e ) {
			return $links;
		}

		return $links;
	}

	/**
	 * Note a link under each rel value this import cares about, keeping
	 * the first address seen for each.
	 *
	 * @param array<string, string> $links Links so far, by rel (changed in place).
	 * @param string                $rels  A `rel` value, possibly several separated by spaces.
	 * @param string                $href  The link's address.
	 * @return void
	 */
	private static function collect_rels( array &$links, string $rels, string $href ): void {
		if ( '' === $href ) {
			return;
		}

		$wanted = array( 'microsub', 'indieauth-metadata', 'authorization_endpoint', 'token_endpoint' );

		foreach ( preg_split( '/\s+/', strtolower( trim( $rels ) ) ) as $rel ) {
			if ( in_array( $rel, $wanted, true ) && ! isset( $links[ $rel ] ) ) {
				$links[ $rel ] = $href;
			}
		}
	}

	/**
	 * Make a discovered endpoint address absolute, and keep it only when it
	 * is a plain http(s) address.
	 *
	 * @param string $url  Address as found (possibly relative).
	 * @param string $base The page it was found on.
	 * @return string The address, or ''.
	 */
	private static function clean_endpoint( string $url, string $base ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$url = esc_url_raw( WP_Http::make_absolute_url( $url, $base ), array( 'http', 'https' ) );

		return '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * A URL's scheme, host, and port.
	 *
	 * @param string $url URL.
	 * @return string Such as `https://example.com` or `http://example.com:8080`.
	 */
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) $parts['host'] );

		return isset( $parts['port'] ) ? $origin . ':' . (int) $parts['port'] : $origin;
	}

	/**
	 * Base64url without padding, as PKCE asks for.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE (RFC 7636) defines the code challenge as base64url of a SHA-256 hash.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * The error shown when the sign-in could not be finished.
	 *
	 * @return WP_Error
	 */
	private static function sign_in_failed_error(): WP_Error {
		return new WP_Error(
			'daymark_microsub_import_sign_in_failed',
			__( 'Daymark could not finish signing in. Please try again.', 'daymark' )
		);
	}

	/**
	 * The error shown when the Microsub server could not be read.
	 *
	 * @return WP_Error
	 */
	private static function list_failed_error(): WP_Error {
		return new WP_Error(
			'daymark_microsub_import_failed',
			__( 'Daymark could not load your list from the Microsub server. Please try again in a few minutes.', 'daymark' )
		);
	}
}
