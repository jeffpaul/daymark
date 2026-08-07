<?php
/**
 * E2E fixture: relax Daymark's per-user rate limits.
 *
 * The browser E2E suite legitimately publishes and syncs many Marks in a
 * couple of minutes, which would exhaust the production rate limits
 * (`Daymark_Rate_Limiter` defaults) and 429 the tail of the run. The rate
 * limiter's own behavior is covered by PHPUnit, so this fixture lifts the
 * limits for the E2E site only.
 *
 * Copied into mu-plugins by the Playwright CI job's Seed step — never part
 * of the distributed plugin.
 */

add_filter(
	'daymark_rate_limits',
	static function ( $limits ) {
		foreach ( $limits as $action => $config ) {
			$limits[ $action ] = array(
				'limit'  => 1000,
				'window' => $config['window'] ?? 5 * MINUTE_IN_SECONDS,
			);
		}
		return $limits;
	}
);
