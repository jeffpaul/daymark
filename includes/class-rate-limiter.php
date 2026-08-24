<?php
/**
 * Per-user rate limiting for expensive REST actions.
 *
 * Uses a sliding-window counter stored in a per-user transient. Limits are
 * filterable via `daymark_rate_limits` so hosts can tighten or relax them.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed per-user rate limiter.
 */
class Daymark_Rate_Limiter {

	/**
	 * AI Assist endpoints (suggestions, title, alt-text).
	 *
	 * @var string
	 */
	public const ACTION_AI = 'ai';

	/**
	 * Mark create (POST /marks).
	 *
	 * @var string
	 */
	public const ACTION_PUBLISH = 'publish';

	/**
	 * Manual response sync (POST /marks/{id}/sync-responses).
	 *
	 * @var string
	 */
	public const ACTION_SYNC = 'sync';

	/**
	 * Subscribe by URL (POST /subscriptions) — makes an outbound feed
	 * discovery/favicon request, same risk class as ACTION_SYNC.
	 *
	 * @var string
	 */
	public const ACTION_SUBSCRIBE = 'subscribe';

	/**
	 * Default limits: action => [ limit, window_seconds ].
	 *
	 * @var array<string, array{limit: int, window: int}>
	 */
	private const DEFAULTS = array(
		self::ACTION_AI        => array(
			'limit'  => 20,
			'window' => 5 * MINUTE_IN_SECONDS,
		),
		self::ACTION_PUBLISH   => array(
			'limit'  => 20,
			'window' => 5 * MINUTE_IN_SECONDS,
		),
		self::ACTION_SYNC      => array(
			'limit'  => 10,
			'window' => 5 * MINUTE_IN_SECONDS,
		),
		self::ACTION_SUBSCRIBE => array(
			'limit'  => 10,
			'window' => 5 * MINUTE_IN_SECONDS,
		),
	);

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'daymark_rl_';

	/**
	 * Attempt an action for the current (or given) user.
	 *
	 * Increments the counter when under the limit. Returns true on success,
	 * or a WP_Error with status 429 and a `retry_after` data field when the
	 * limit has been reached.
	 *
	 * @param string   $action  One of the ACTION_* constants.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return true|WP_Error
	 */
	public function attempt( string $action, ?int $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'daymark_rate_limit_no_user',
				__( 'Rate limiting requires an authenticated user.', 'daymark' ),
				array( 'status' => 401 )
			);
		}

		$limits = $this->limits_for( $action );
		$key    = self::TRANSIENT_PREFIX . $action . '_' . $user_id;
		$now    = time();
		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['start'] ) ) {
			$bucket = array(
				'count' => 0,
				'start' => $now,
			);
		}

		// Window expired: start a fresh bucket.
		if ( ( $now - (int) $bucket['start'] ) >= $limits['window'] ) {
			$bucket = array(
				'count' => 0,
				'start' => $now,
			);
		}

		if ( (int) $bucket['count'] >= $limits['limit'] ) {
			$retry_after = max( 1, $limits['window'] - ( $now - (int) $bucket['start'] ) );

			return new WP_Error(
				'daymark_rate_limit_exceeded',
				__( 'Too many requests. Please try again shortly.', 'daymark' ),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		++$bucket['count'];
		$ttl = max( 1, $limits['window'] - ( $now - (int) $bucket['start'] ) );
		set_transient( $key, $bucket, $ttl );

		return true;
	}

	/**
	 * Remaining attempts in the current window (for tests / diagnostics).
	 *
	 * @param string   $action  Action key.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return int
	 */
	public function remaining( string $action, ?int $user_id = null ): int {
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		$limits  = $this->limits_for( $action );
		$key     = self::TRANSIENT_PREFIX . $action . '_' . $user_id;
		$bucket  = get_transient( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['start'] ) ) {
			return $limits['limit'];
		}

		if ( ( time() - (int) $bucket['start'] ) >= $limits['window'] ) {
			return $limits['limit'];
		}

		return max( 0, $limits['limit'] - (int) $bucket['count'] );
	}

	/**
	 * Resolved limit + window for an action.
	 *
	 * @param string $action Action key.
	 * @return array{limit: int, window: int}
	 */
	private function limits_for( string $action ): array {
		$defaults = self::DEFAULTS[ $action ] ?? array(
			'limit'  => 20,
			'window' => 5 * MINUTE_IN_SECONDS,
		);

		/**
		 * Filter per-action rate limits.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, array{limit: int, window: int}> $limits  Map of action => limit config.
		 * @param string                                         $action Action being checked.
		 */
		$all = apply_filters( 'daymark_rate_limits', self::DEFAULTS, $action );

		$config = is_array( $all ) && isset( $all[ $action ] ) && is_array( $all[ $action ] )
			? $all[ $action ]
			: $defaults;

		return array(
			'limit'  => max( 1, absint( $config['limit'] ?? $defaults['limit'] ) ),
			'window' => max( 1, absint( $config['window'] ?? $defaults['window'] ) ),
		);
	}
}
