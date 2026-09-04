<?php
/**
 * WebSub (PubSubHubbub) callback endpoint (issue #82).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hub-facing half of WebSub subscribing: verifies a hub's subscription
 * challenge (GET) and accepts its content-distribution pushes (POST).
 * Necessarily unauthenticated — the hub calls this, not a logged-in user —
 * so every request is verified against per-subscription state
 * (Daymark_Websub_Subscriber) before anything it carries is trusted.
 */
class Daymark_Websub_Endpoint {

	/**
	 * Default maximum WebSub content-delivery body size, in bytes.
	 * Filterable via `daymark_websub_max_delivery_bytes`. Matches the
	 * regular feed-fetch cap by default — a WebSub delivery is, in shape,
	 * the same kind of document.
	 *
	 * @var int
	 */
	private const MAX_DELIVERY_BYTES = 2 * 1024 * 1024; // 2 MB.

	/**
	 * Marker used to tell serve_raw_challenge() this response's data is a
	 * raw challenge string to echo verbatim, not JSON to encode.
	 *
	 * @var string
	 */
	private const RAW_CHALLENGE_HEADER = 'X-Daymark-Websub-Raw-Challenge';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_challenge' ), 10, 4 );
	}

	/**
	 * Register the callback route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'daymark/v1',
			'/websub/(?P<id>\d+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				// Necessarily public: a WebSub hub calls this endpoint, not
				// an authenticated Daymark user. Every request is instead
				// verified against per-subscription state below — a
				// pending/verified match for GET, an HMAC signature for
				// POST — before anything it carries is trusted.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Dispatch a request to the GET (hub verification) or POST (content
	 * delivery) handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$subscription_id = absint( $request->get_param( 'id' ) );

		if ( 'POST' === $request->get_method() ) {
			return $this->handle_content_delivery( $subscription_id, $request );
		}

		return $this->handle_verification( $subscription_id, $request );
	}

	/**
	 * GET: the hub's own subscription verification challenge. Succeeds only
	 * when the subscription is genuinely `pending` for the exact topic
	 * (feed URL) the hub is asking about — anyone can GET this URL, so the
	 * match against known, expected state is what actually verifies the
	 * request, not the URL alone.
	 *
	 * @param int             $subscription_id Subscription ID from the URL.
	 * @param WP_REST_Request $request         Request.
	 * @return WP_REST_Response
	 */
	private function handle_verification( int $subscription_id, WP_REST_Request $request ): WP_REST_Response {
		$mode      = (string) $request->get_param( 'hub_mode' );
		$topic     = esc_url_raw( (string) $request->get_param( 'hub_topic' ) );
		$challenge = (string) $request->get_param( 'hub_challenge' );
		$lease     = absint( $request->get_param( 'hub_lease_seconds' ) );

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscription  = $subscriptions->get( $subscription_id );

		$is_valid = null !== $subscription
			&& in_array( $mode, array( 'subscribe', 'unsubscribe' ), true )
			&& '' !== $challenge
			&& 'pending' === (string) ( $subscription['websub_status'] ?? '' )
			&& (string) ( $subscription['feed_url'] ?? '' ) === $topic;

		if ( ! $is_valid ) {
			return new WP_REST_Response( null, 404 );
		}

		if ( 'subscribe' === $mode ) {
			/**
			 * Filters the WebSub lease duration used when the hub's own
			 * verification GET carries no `hub.lease_seconds` of its own.
			 *
			 * @since 0.10.0
			 *
			 * @param int $seconds Defaults to 10 days.
			 */
			$fallback_lease = (int) apply_filters( 'daymark_websub_lease_seconds', 10 * DAY_IN_SECONDS );
			$lease_seconds  = $lease > 0 ? $lease : $fallback_lease;

			$subscriptions->update(
				$subscription_id,
				array(
					'websub_status'           => 'verified',
					'websub_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $lease_seconds ),
				)
			);
		} else {
			$subscriptions->update( $subscription_id, array( 'websub_status' => 'none' ) );
		}

		// The spec requires the exact challenge string as the entire
		// response body — no JSON envelope. See serve_raw_challenge().
		$response = new WP_REST_Response( $challenge, 200 );
		$response->header( self::RAW_CHALLENGE_HEADER, '1' );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );

		return $response;
	}

	/**
	 * Bypass the REST server's JSON envelope for a verification-challenge
	 * response (marked via a private header above) — the WebSub spec
	 * requires the hub's challenge string back byte-for-byte, not
	 * JSON-quoted.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server instance (unused).
	 * @return bool
	 */
	public function serve_raw_challenge( $served, $result, $request, $server ) {
		unset( $server );

		if ( $served || ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		if ( ! $request instanceof WP_REST_Request || '/daymark/v1/websub/' !== substr( $request->get_route(), 0, 20 ) ) {
			return $served;
		}

		if ( empty( $result->get_header( self::RAW_CHALLENGE_HEADER ) ) ) {
			return $served;
		}

		status_header( $result->get_status() );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo (string) $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verbatim per the WebSub spec: the hub's own challenge string, echoed back unmodified. Not attacker-controlled HTML context (plain text response), and only ever reached after handle_verification()'s own subscription/topic/status match.

		return true;
	}

	/**
	 * POST: a hub's content-distribution push. Verified against the stored
	 * per-subscription HMAC secret before anything in the body is trusted —
	 * an unsigned or mismatched request is rejected outright, never parsed.
	 *
	 * @param int             $subscription_id Subscription ID from the URL.
	 * @param WP_REST_Request $request         Request.
	 * @return WP_REST_Response
	 */
	private function handle_content_delivery( int $subscription_id, WP_REST_Request $request ): WP_REST_Response {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscription  = $subscriptions->get( $subscription_id );

		if ( null === $subscription || 'verified' !== (string) ( $subscription['websub_status'] ?? '' ) ) {
			return new WP_REST_Response( null, 404 );
		}

		$body = $request->get_body();

		/**
		 * Filters the maximum WebSub content-delivery body size, in bytes.
		 *
		 * @since 0.10.0
		 *
		 * @param int $max_bytes Defaults to 2 MB.
		 */
		$max_bytes = (int) apply_filters( 'daymark_websub_max_delivery_bytes', self::MAX_DELIVERY_BYTES );

		if ( strlen( $body ) >= $max_bytes ) {
			return new WP_REST_Response( null, 413 );
		}

		$secret    = (string) ( $subscription['websub_secret'] ?? '' );
		$signature = (string) $request->get_header( 'x_hub_signature_256' );

		if ( '' === $secret || ! $this->signature_matches( $signature, $body, $secret ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$registry = Daymark_Plugin::instance()->subscription_source_registry;
		$source   = $registry->get_source( sanitize_key( (string) ( $subscription['source_type'] ?? '' ) ) );

		if ( ! $source instanceof Daymark_Subscription_Source_Feed ) {
			return new WP_REST_Response( null, 404 );
		}

		$raw_items = $source->parse_raw_feed_body( $body );

		// A malformed delivery still gets a 2xx (the hub's job ends at
		// successful delivery, per spec) — but it's neither ingested nor
		// treated as evidence the subscription is healthy.
		if ( ! is_wp_error( $raw_items ) ) {
			$poller = Daymark_Plugin::instance()->subscription_poller;
			$poller->record_successful_check( $subscription_id );

			foreach ( $raw_items as $raw_item ) {
				$poller->maybe_ingest_item( $subscription_id, $source->normalize( $raw_item ) );
			}
		}

		return new WP_REST_Response( null, 202 );
	}

	/**
	 * Whether a `X-Hub-Signature-256` header value matches the expected
	 * HMAC-SHA256 of the request body under the given secret, per the
	 * WebSub spec (`sha256=<hex digest>`).
	 *
	 * @param string $signature Header value.
	 * @param string $body      Raw request body.
	 * @param string $secret    Subscription's stored WebSub secret.
	 * @return bool
	 */
	private function signature_matches( string $signature, string $body, string $secret ): bool {
		if ( ! str_starts_with( $signature, 'sha256=' ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );
		$provided = substr( $signature, strlen( 'sha256=' ) );

		return hash_equals( $expected, $provided );
	}
}
