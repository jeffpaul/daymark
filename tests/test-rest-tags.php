<?php
/**
 * GET /daymark/v1/tags — existing post_tag suggestions for the composer's
 * tag autocomplete.
 *
 * @package Daymark
 */

/**
 * Backs the "minimal text entry" tag autocomplete: matching existing site
 * tags by search string, so the user can tap instead of typing the full
 * name.
 */
class Test_Rest_Tags extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Sunrise',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Sunset',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Mountains',
			)
		);
	}

	private function request( string $search = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/tags' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( '' !== $search ) {
			$request->set_param( 'search', $search );
		}
		return $request;
	}

	/** Requires the shared nonce + edit_posts check, same as every other endpoint. */
	public function test_requires_authentication() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/daymark/v1/tags' );
		$request->set_param( 'search', 'sun' );

		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/** A matching search string returns only the matching tags, by name. */
	public function test_search_returns_matching_tags() {
		$data  = rest_do_request( $this->request( 'sun' ) )->get_data();
		$names = wp_list_pluck( $data, 'name' );

		$this->assertContains( 'Sunrise', $names );
		$this->assertContains( 'Sunset', $names );
		$this->assertNotContains( 'Mountains', $names );
	}

	/** Every result carries the term id alongside its name (for future exact matching). */
	public function test_results_include_term_id() {
		$data = rest_do_request( $this->request( 'Sunrise' ) )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Sunrise', $data[0]['name'] );
		$this->assertGreaterThan( 0, $data[0]['id'] );
	}

	/** An empty search string returns no results rather than every tag on the site. */
	public function test_empty_search_returns_no_results() {
		$data = rest_do_request( $this->request() )->get_data();

		$this->assertSame( array(), $data );
	}

	/** No matches for the search string returns an empty array, not an error. */
	public function test_no_match_returns_empty_array() {
		$data = rest_do_request( $this->request( 'zzz-no-such-tag' ) )->get_data();

		$this->assertSame( array(), $data );
	}
}
