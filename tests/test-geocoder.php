<?php
/**
 * Daymark_Geocoder tests (issue #143) — best-effort reverse geocoding for
 * the Checkin Mark type.
 *
 * All HTTP is mocked via `pre_http_request`, matching the existing pattern
 * in tests/test-comment-delivery.php.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Geocoder::reverse().
 */
class Test_Geocoder extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/**
	 * How many times the mocked HTTP filter was actually invoked for a
	 * request that matched a canned response — used to assert caching
	 * avoids a second live lookup.
	 *
	 * @var int
	 */
	private int $request_count = 0;

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();
		$this->request_count  = 0;

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		unset( $parsed_args );

		foreach ( $this->http_responses as $prefix => $response ) {
			if ( str_starts_with( $url, $prefix ) ) {
				++$this->request_count;
				return $response;
			}
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string $body Response body.
	 * @param int    $code HTTP status code.
	 * @return void
	 */
	private function mock_nominatim_response( string $body, int $code = 200 ): void {
		$this->http_responses['https://nominatim.openstreetmap.org/reverse'] = array(
			'headers'  => array(),
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
	 * @param string $body Response body.
	 * @param int    $code HTTP status code.
	 * @return void
	 */
	private function mock_nominatim_search_response( string $body, int $code = 200 ): void {
		$this->http_responses['https://nominatim.openstreetmap.org/search'] = array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** A named point-of-interest (amenity) plus city reads as "Venue, City". */
	public function test_reverse_prefers_a_named_amenity_over_the_full_address() {
		$this->mock_nominatim_response(
			wp_json_encode(
				array(
					'display_name' => 'Blue Bottle Coffee, 66 Mint St, San Francisco, CA, USA',
					'address'      => array(
						'amenity' => 'Blue Bottle Coffee',
						'city'    => 'San Francisco',
					),
				)
			)
		);

		$this->assertSame( 'Blue Bottle Coffee, San Francisco', Daymark_Geocoder::reverse( 37.7749, -122.4194 ) );
	}

	/** With no address hierarchy match, falls back to the first two display_name segments. */
	public function test_reverse_falls_back_to_display_name_segments() {
		$this->mock_nominatim_response(
			wp_json_encode(
				array(
					'display_name' => 'Golden Gate Park, San Francisco, California, USA',
					'address'      => array( 'city' => 'San Francisco' ),
				)
			)
		);

		$this->assertSame( 'Golden Gate Park, San Francisco', Daymark_Geocoder::reverse( 37.7694, -122.4862 ) );
	}

	/** A non-200 response degrades to null, never a thrown error. */
	public function test_reverse_returns_null_on_http_failure() {
		$this->mock_nominatim_response( '', 500 );

		$this->assertNull( Daymark_Geocoder::reverse( 0.0, 0.0 ) );
	}

	/** A malformed (non-JSON-object) body degrades to null. */
	public function test_reverse_returns_null_on_malformed_body() {
		$this->mock_nominatim_response( 'not json' );

		$this->assertNull( Daymark_Geocoder::reverse( 1.0, 1.0 ) );
	}

	/** A second lookup for the same (rounded) coordinates is served from cache, not a live request. */
	public function test_reverse_caches_by_rounded_coordinates() {
		$this->mock_nominatim_response(
			wp_json_encode( array( 'name' => 'Cached Place' ) )
		);

		$this->assertSame( 'Cached Place', Daymark_Geocoder::reverse( 10.12345, 20.12345 ) );
		$this->assertSame( 1, $this->request_count );

		// Same location, rounded to 3 decimals — must hit the cache, not fire a second request.
		$this->assertSame( 'Cached Place', Daymark_Geocoder::reverse( 10.12349, 20.12341 ) );
		$this->assertSame( 1, $this->request_count );
	}

	/** GET /daymark/v1/location/reverse-geocode wraps Daymark_Geocoder::reverse(). */
	public function test_rest_route_returns_resolved_place_name() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->mock_nominatim_response( wp_json_encode( array( 'name' => 'Blue Bottle Coffee' ) ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/location/reverse-geocode' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'lat', 37.7749 );
		$request->set_param( 'lng', -122.4194 );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 'Blue Bottle Coffee', $data['place_name'] );
	}

	/** An out-of-range coordinate never reaches Daymark_Geocoder — the endpoint just reports null. */
	public function test_rest_route_returns_null_place_name_for_invalid_coordinates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/location/reverse-geocode' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'lat', 999 );
		$request->set_param( 'lng', 0 );

		$data = rest_do_request( $request )->get_data();

		$this->assertNull( $data['place_name'] );
		$this->assertSame( 0, $this->request_count, 'An invalid coordinate must never reach the outbound geocoder.' );
	}

	/** search() reduces Nominatim's own search-result array into place_name/lat/lng entries. */
	public function test_search_returns_place_name_lat_lng_for_each_result() {
		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array(
						'name' => 'Blue Bottle Coffee',
						'lat'  => '37.7749',
						'lon'  => '-122.4194',
					),
					array(
						'display_name' => 'Golden Gate Park, San Francisco, California, USA',
						'lat'          => '37.7694',
						'lon'          => '-122.4862',
					),
				)
			)
		);

		$results = Daymark_Geocoder::search( 'coffee' );

		$this->assertCount( 2, $results );
		$this->assertSame( 'Blue Bottle Coffee', $results[0]['place_name'] );
		$this->assertSame( 37.7749, $results[0]['lat'] );
		$this->assertSame( -122.4194, $results[0]['lng'] );
		$this->assertSame( 'Golden Gate Park, San Francisco', $results[1]['place_name'] );
	}

	/** Each result also carries Nominatim's own full formatted address, for disambiguating similarly-named results. */
	public function test_search_includes_full_address_alongside_place_name() {
		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array(
						'name'         => 'Blue Bottle Coffee',
						'display_name' => 'Blue Bottle Coffee, 66 Mint St, San Francisco, CA, USA',
						'lat'          => '37.7749',
						'lon'          => '-122.4194',
					),
				)
			)
		);

		$results = Daymark_Geocoder::search( 'blue bottle' );

		$this->assertSame( 'Blue Bottle Coffee, 66 Mint St, San Francisco, CA, USA', $results[0]['address'] );
	}

	/** A missing display_name degrades to an empty address string, not a missing key or an error. */
	public function test_search_result_with_no_display_name_has_empty_address() {
		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array(
						'name' => 'Blue Bottle Coffee',
						'lat'  => '37.7749',
						'lon'  => '-122.4194',
					),
				)
			)
		);

		$results = Daymark_Geocoder::search( 'blue bottle' );

		$this->assertSame( '', $results[0]['address'] );
	}

	/** A very long display_name is capped, but to a longer limit than the short place-name cap. */
	public function test_search_address_is_capped_but_longer_than_place_name_cap() {
		$long_address = 'Blue Bottle Coffee, ' . str_repeat( 'Very Long Street Name ', 10 ) . 'San Francisco, CA, USA';

		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array(
						'name'         => 'Blue Bottle Coffee',
						'display_name' => $long_address,
						'lat'          => '37.7749',
						'lon'          => '-122.4194',
					),
				)
			)
		);

		$results = Daymark_Geocoder::search( 'blue bottle' );

		$this->assertLessThanOrEqual( 120, mb_strlen( $results[0]['address'] ) );
		$this->assertGreaterThan( 60, mb_strlen( $results[0]['address'] ) );
	}

	/** An empty query never reaches Nominatim at all. */
	public function test_search_returns_empty_array_for_blank_query() {
		$this->assertSame( array(), Daymark_Geocoder::search( '   ' ) );
		$this->assertSame( 0, $this->request_count );
	}

	/** A non-200 response degrades to an empty array, never a thrown error. */
	public function test_search_returns_empty_array_on_http_failure() {
		$this->mock_nominatim_search_response( '', 500 );

		$this->assertSame( array(), Daymark_Geocoder::search( 'anywhere' ) );
	}

	/** A malformed (non-JSON-array) body degrades to an empty array. */
	public function test_search_returns_empty_array_on_malformed_body() {
		$this->mock_nominatim_search_response( 'not json' );

		$this->assertSame( array(), Daymark_Geocoder::search( 'anywhere' ) );
	}

	/** A result missing usable coordinates is skipped rather than included with garbage lat/lng. */
	public function test_search_skips_results_missing_coordinates() {
		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array( 'name' => 'No Coordinates Here' ),
					array(
						'name' => 'Has Coordinates',
						'lat'  => '1.0',
						'lon'  => '2.0',
					),
				)
			)
		);

		$results = Daymark_Geocoder::search( 'anywhere' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Has Coordinates', $results[0]['place_name'] );
	}

	/** A second search for the same query is served from cache, not a live request. */
	public function test_search_caches_by_query() {
		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array(
						'name' => 'Cached Venue',
						'lat'  => '1.0',
						'lon'  => '2.0',
					),
				)
			)
		);

		$this->assertSame( 'Cached Venue', Daymark_Geocoder::search( 'Venue' )[0]['place_name'] );
		$this->assertSame( 1, $this->request_count );

		// Same query, different case — the cache key is lowercased.
		$this->assertSame( 'Cached Venue', Daymark_Geocoder::search( 'venue' )[0]['place_name'] );
		$this->assertSame( 1, $this->request_count );
	}

	/** GET /daymark/v1/location/search wraps Daymark_Geocoder::search(). */
	public function test_rest_search_route_returns_results() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->mock_nominatim_search_response(
			wp_json_encode(
				array(
					array(
						'name' => 'Blue Bottle Coffee',
						'lat'  => '37.7749',
						'lon'  => '-122.4194',
					),
				)
			)
		);

		$request = new WP_REST_Request( 'GET', '/daymark/v1/location/search' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'q', 'coffee' );

		$data = rest_do_request( $request )->get_data();

		$this->assertCount( 1, $data['results'] );
		$this->assertSame( 'Blue Bottle Coffee', $data['results'][0]['place_name'] );
	}

	/** An empty query never reaches the outbound geocoder — the endpoint just reports no results. */
	public function test_rest_search_route_returns_empty_results_for_blank_query() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/location/search' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'q', '   ' );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( array(), $data['results'] );
		$this->assertSame( 0, $this->request_count );
	}
}
