<?php
/**
 * Best-effort reverse geocoding for the Checkin Mark type (issue #143).
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a human-readable place name from raw coordinates.
 */
class Daymark_Geocoder {

	/**
	 * Best-effort reverse-geocode a lat/lng pair into a short, human-
	 * readable place name via Nominatim (OpenStreetMap's own free, keyless
	 * geocoder) — chosen for the same reason Daymark_Publisher::fetch_weather()
	 * chose Open-Meteo: no API key or account signup, so this doesn't add a
	 * second third-party credential to store, matching the spirit of this
	 * plugin's "no AI provider API key storage" non-goal even though this
	 * isn't AI.
	 *
	 * Nominatim's usage policy (https://operations.osmfoundation.org/policies/nominatim/)
	 * requires a descriptive User-Agent identifying the calling application
	 * and caps automated use to roughly one request per second — trivially
	 * satisfied by a single personal-site user occasionally tapping "Check
	 * In," so no client-side or server-side throttling beyond the ordinary
	 * per-user REST rate limit (Daymark_Rate_Limiter::ACTION_LOCATION_LOOKUP)
	 * is needed here.
	 *
	 * No SSRF surface applies: the only externally-influenced input is a
	 * numeric lat/lng pair the caller has already range-validated, passed
	 * as query params to one fixed, hardcoded host — the same reasoning
	 * fetch_weather()'s own docblock already gives for not invoking
	 * Daymark_Subscription_Url_Guard here.
	 *
	 * Never throws (wrapped in try/catch) and degrades to null on any
	 * failure (timeout, non-200, malformed JSON, or a response with
	 * nothing name-like in it) — the composer's own Place field simply
	 * stays blank for the author to fill in by hand.
	 *
	 * @since 0.17.0
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string|null A short place name, or null when nothing could be resolved.
	 */
	public static function reverse( float $lat, float $lng ): ?string {
		// Cached by rounded coordinates (~110m at the equator) so a device's
		// small GPS jitter between two nearby check-ins, or a retry, doesn't
		// cost a second live lookup.
		$cache_key = 'daymark_geocode_' . md5( round( $lat, 3 ) . ',' . round( $lng, 3 ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return '' !== $cached ? $cached : null;
		}

		$place = self::fetch( $lat, $lng );

		/**
		 * Filters how long a reverse-geocode result (including a failed
		 * lookup, cached as an empty string) is cached for.
		 *
		 * @since 0.17.0
		 *
		 * @param int $seconds Defaults to a day.
		 */
		$ttl = max( MINUTE_IN_SECONDS, (int) apply_filters( 'daymark_geocode_cache_ttl', DAY_IN_SECONDS ) );

		set_transient( $cache_key, $place ?? '', $ttl );

		return $place;
	}

	/**
	 * The actual live lookup, split out from reverse() so its own early
	 * returns don't have to duplicate the caching wrapper above.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string|null
	 */
	private static function fetch( float $lat, float $lng ): ?string {
		try {
			$url = add_query_arg(
				array(
					'format'         => 'jsonv2',
					'lat'            => $lat,
					'lon'            => $lng,
					'zoom'           => 18, // Building/POI-level detail, not a whole city.
					'addressdetails' => 1,
				),
				'https://nominatim.openstreetmap.org/reverse'
			);

			/**
			 * Filters the HTTP timeout, in seconds, used for the best-effort
			 * Nominatim reverse-geocode lookup.
			 *
			 * @since 0.17.0
			 *
			 * @param int $seconds Defaults to 4.
			 */
			$timeout = max( 1, (int) apply_filters( 'daymark_geocode_fetch_timeout', 4 ) );

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout' => $timeout,
					// Nominatim's usage policy requires a descriptive
					// User-Agent identifying the calling application.
					'headers' => array(
						'User-Agent' => 'Daymark WordPress Plugin (' . home_url( '/' ) . ')',
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) ) {
				return null;
			}

			return self::extract_place_name( $body );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Pick the shortest, most POI-specific name out of a Nominatim jsonv2
	 * response — a named point of interest ("Blue Bottle Coffee") reads far
	 * better on a checkin than the full postal address Nominatim's own
	 * `display_name` carries by default.
	 *
	 * @param array<string, mixed> $body Decoded Nominatim response.
	 * @return string|null
	 */
	private static function extract_place_name( array $body ): ?string {
		if ( isset( $body['name'] ) && is_string( $body['name'] ) && '' !== $body['name'] ) {
			return self::trim_place( $body['name'] );
		}

		$address = is_array( $body['address'] ?? null ) ? $body['address'] : array();

		// Roughly most-to-least specific: a named venue first, then a
		// street-level fallback, matching Nominatim's own address hierarchy.
		foreach ( array( 'amenity', 'shop', 'tourism', 'leisure', 'building', 'road' ) as $key ) {
			if ( isset( $address[ $key ] ) && is_string( $address[ $key ] ) && '' !== $address[ $key ] ) {
				$city = '';
				foreach ( array( 'city', 'town', 'village', 'suburb' ) as $city_key ) {
					if ( isset( $address[ $city_key ] ) && is_string( $address[ $city_key ] ) && '' !== $address[ $city_key ] ) {
						$city = $address[ $city_key ];
						break;
					}
				}

				return self::trim_place( '' !== $city ? $address[ $key ] . ', ' . $city : $address[ $key ] );
			}
		}

		if ( isset( $body['display_name'] ) && is_string( $body['display_name'] ) && '' !== $body['display_name'] ) {
			$segments = array_map( 'trim', explode( ',', $body['display_name'] ) );

			return self::trim_place( implode( ', ', array_slice( array_filter( $segments ), 0, 2 ) ) );
		}

		return null;
	}

	/**
	 * Cap a resolved place name to a reasonable length — matches
	 * Daymark_Publisher's own title/place character cap so a verbose
	 * Nominatim result can never blow out the composer's Place field or
	 * the generated "Checked in at …" title.
	 *
	 * @param string $place Raw place text.
	 * @return string
	 */
	private static function trim_place( string $place ): string {
		$max_chars = (int) apply_filters( 'daymark_title_max_chars', 60 );

		if ( mb_strlen( $place ) <= $max_chars ) {
			return $place;
		}

		return mb_substr( $place, 0, $max_chars - 1 ) . '…';
	}
}
