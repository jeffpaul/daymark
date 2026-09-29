<?php
/**
 * WebSub (PubSubHubbub) subscribing (issue #82).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscribes to a feed's advertised WebSub hub so its updates can arrive by
 * push instead of waiting for the next polling cron tick. Purely additive:
 * the existing `Daymark_Subscription_Poller` polling cron keeps running
 * unchanged for every subscription regardless of WebSub status — a failed,
 * lapsed, or never-attempted WebSub subscription simply means content still
 * arrives the way it always has, on the next poll, never a lost update.
 */
class Daymark_Websub_Subscriber {

	/**
	 * Default requested lease, in seconds, for a WebSub subscription.
	 * Filterable via `daymark_websub_lease_seconds`. Most hubs cap this
	 * lower than the request anyway; the hub's own granted lease (not this
	 * requested value) is what `websub_lease_expires_at` actually records —
	 * see Daymark_Websub_Endpoint's GET handler.
	 *
	 * @var int
	 */
	private const DEFAULT_LEASE_SECONDS = 10 * DAY_IN_SECONDS;

	/**
	 * Renew (re-subscribe) once the granted lease has this little time left.
	 * Filterable via `daymark_websub_renewal_window`.
	 *
	 * @var int
	 */
	private const RENEWAL_WINDOW_SECONDS = DAY_IN_SECONDS;

	/**
	 * After a successful poll, subscribe to the feed's advertised hub (if
	 * any) when not already pending/verified, or renew a verified
	 * subscription nearing lease expiry.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $feed_url        The subscription's own feed URL (the
	 *                                WebSub "topic").
	 * @param string $hub_url         Hub URL the feed advertised on this
	 *                                fetch, or '' when it advertised none.
	 * @return void
	 */
	public function maybe_subscribe( int $subscription_id, string $feed_url, string $hub_url ): void {
		if ( '' === $hub_url || '' === $feed_url ) {
			return;
		}

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscription  = $subscriptions->get( $subscription_id );

		if ( null === $subscription ) {
			return;
		}

		$status = (string) ( $subscription['websub_status'] ?? 'none' );

		if ( 'pending' === $status ) {
			return; // Already waiting on the hub's own verification GET.
		}

		if ( 'verified' === $status ) {
			$this->maybe_renew( $subscription, $hub_url, $feed_url );
			return;
		}

		$this->send_subscribe_request( $subscription_id, $hub_url, $feed_url );
	}

	/**
	 * Re-subscribe a verified subscription once its granted lease is close
	 * to expiring. A hub that has changed (a feed migrating providers) is
	 * treated as a fresh subscribe, not a renewal of the old hub.
	 *
	 * @param array<string, mixed> $subscription Subscription row.
	 * @param string               $hub_url      Hub URL this fetch found.
	 * @param string               $feed_url     Feed URL (topic).
	 * @return void
	 */
	private function maybe_renew( array $subscription, string $hub_url, string $feed_url ): void {
		$id           = absint( $subscription['id'] ?? 0 );
		$previous_hub = (string) ( $subscription['websub_hub_url'] ?? '' );
		$expires_at   = (string) ( $subscription['websub_lease_expires_at'] ?? '' );
		$expires_ts   = '' !== $expires_at ? strtotime( $expires_at . ' +00:00' ) : false;

		if ( $hub_url !== $previous_hub ) {
			$this->send_subscribe_request( $id, $hub_url, $feed_url );
			return;
		}

		/**
		 * Filters how long before lease expiry a WebSub subscription renews,
		 * in seconds.
		 *
		 * @since 0.10.0
		 *
		 * @param int $seconds Defaults to 1 day.
		 */
		$window = max( 0, (int) apply_filters( 'daymark_websub_renewal_window', self::RENEWAL_WINDOW_SECONDS ) );

		if ( false === $expires_ts || ( $expires_ts - time() ) <= $window ) {
			$this->send_subscribe_request( $id, $hub_url, $feed_url );
		}
	}

	/**
	 * The unguessable token carried in a subscription's callback URL.
	 *
	 * Derived from the subscription's own random secret (the one already
	 * kept in `websub_secret`), so it needs no column of its own and changes
	 * whenever the secret does (on each subscribe or renewal). The secret is
	 * never in the URL: this is a one-way HMAC of the subscription ID keyed by
	 * it, so the URL a hub logs reveals nothing that could forge a signed
	 * delivery.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $secret          The subscription's `websub_secret`.
	 * @return string 32 hex characters.
	 */
	public static function callback_token( int $subscription_id, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'daymark-websub-callback|' . $subscription_id, $secret ), 0, 32 );
	}

	/**
	 * The callback URL sent to a hub, carrying the token above as a query
	 * argument. Daymark_Websub_Endpoint answers a verification request only
	 * when it presents that token, so knowing the (sequential) subscription
	 * ID and the public feed URL is not enough to mark a pending
	 * subscription verified.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $secret          The subscription's `websub_secret`.
	 * @return string
	 */
	public static function callback_url( int $subscription_id, string $secret ): string {
		return add_query_arg(
			'daymark_token',
			self::callback_token( $subscription_id, $secret ),
			rest_url( 'daymark/v1/websub/' . $subscription_id )
		);
	}

	/**
	 * Send a `hub.mode=subscribe` request to the hub, per the WebSub spec.
	 * A hub typically responds 202 Accepted and independently issues its own
	 * verification GET back to the callback endpoint
	 * (Daymark_Websub_Endpoint) before the subscription is actually
	 * considered `verified` — this method only gets as far as `pending`.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $hub_url         Hub URL.
	 * @param string $feed_url        Feed URL (topic).
	 * @return void
	 */
	private function send_subscribe_request( int $subscription_id, string $hub_url, string $feed_url ): void {
		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $hub_url ) ) ) {
			return;
		}

		$secret = wp_generate_password( 48, false );

		/**
		 * Filters the requested WebSub subscription lease, in seconds.
		 *
		 * @since 0.10.0
		 *
		 * @param int $seconds Defaults to 10 days.
		 */
		$lease_seconds = max( 1, (int) apply_filters( 'daymark_websub_lease_seconds', self::DEFAULT_LEASE_SECONDS ) );

		$callback_url  = self::callback_url( $subscription_id, $secret );
		$subscriptions = Daymark_Plugin::instance()->subscriptions;

		// Record the secret and the pending state before contacting the hub,
		// not after: a hub may send its verification request while it is still
		// answering this one, and the endpoint can only check the callback
		// token against a secret that is already stored.
		$subscriptions->update(
			$subscription_id,
			array(
				'websub_hub_url' => $hub_url,
				'websub_secret'  => $secret,
				'websub_status'  => 'pending',
			)
		);

		$response = Daymark_Outbound_Guard::post(
			$hub_url,
			array(
				'timeout' => 10,
				'body'    => array(
					'hub.mode'          => 'subscribe',
					'hub.topic'         => $feed_url,
					'hub.callback'      => $callback_url,
					'hub.secret'        => $secret,
					'hub.lease_seconds' => (string) $lease_seconds,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$subscriptions->update( $subscription_id, array( 'websub_status' => 'failed' ) );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// The spec allows 2xx broadly, but a hub accepting a subscribe
		// request always returns 202 in practice; treat any 2xx as accepted
		// rather than special-casing 202 alone.
		if ( $code < 200 || $code >= 300 ) {
			$subscriptions->update( $subscription_id, array( 'websub_status' => 'failed' ) );
		}
	}
}
