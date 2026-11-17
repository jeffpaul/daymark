<?php
/**
 * GET /daymark/v1/search/suggestions — the tags and Check In places Search
 * offers for what is typed, and the most used tags for its starting screen
 * (issue #269).
 *
 * @package Daymark
 */

/**
 * Exercises Search's tag and place suggestions.
 */
class Test_Rest_Search_Suggestions extends WP_UnitTestCase {

	/** @var int */
	private $author;

	public function set_up(): void {
		parent::set_up();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	/**
	 * Create a published post with tags and, optionally, a Check In place.
	 *
	 * @param string[] $tags  Tag names.
	 * @param string   $place Place name, or '' for none.
	 * @param string   $status Post status.
	 * @return int
	 */
	private function create_post( array $tags = array(), string $place = '', string $status = 'publish' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => $status,
			)
		);

		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $post_id, $tags );
		}

		if ( '' !== $place ) {
			update_post_meta( $post_id, '_daymark_place_name', $place );
		}

		return $post_id;
	}

	/**
	 * Ask for suggestions.
	 *
	 * @param string $query What was typed.
	 * @return WP_REST_Response
	 */
	private function suggest( string $query = '' ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/search/suggestions' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( '' !== $query ) {
			$request->set_param( 'q', $query );
		}

		return rest_do_request( $request );
	}

	/** Requires the shared nonce + edit_posts check, same as every other endpoint. */
	public function test_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/search/suggestions' );
		$request->set_param( 'q', 'lake' );

		$this->assertSame( 401, rest_do_request( $request )->get_status() );
	}

	/** A subscriber can't read them either. */
	public function test_requires_edit_posts() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->suggest( 'lake' )->get_status() );
	}

	/** With nothing typed, it returns the most used tags, most used first, and no places. */
	public function test_empty_query_returns_the_most_used_tags() {
		$this->create_post( array( 'Family', 'Travel' ), 'Lake Geneva, Wisconsin' );
		$this->create_post( array( 'Family' ) );
		$this->create_post( array( 'Family', 'Coffee' ) );
		$this->create_post( array( 'Travel' ) );

		$data = $this->suggest()->get_data();

		$this->assertSame( array( 'Family', 'Travel', 'Coffee' ), array_column( $data['tags'], 'name' ) );
		$this->assertSame( array( 3, 2, 1 ), array_column( $data['tags'], 'count' ) );
		$this->assertSame( array(), $data['places'] );
	}

	/** The starting screen shows at most eight tags. */
	public function test_empty_query_returns_at_most_eight_tags() {
		$this->create_post( array( 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10' ) );

		$this->assertCount( 8, $this->suggest()->get_data()['tags'] );
	}

	/** A tag no published post uses is never offered. */
	public function test_unused_tags_are_left_out() {
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Lakehouse',
			)
		);
		$this->create_post( array( 'Lakefront' ), '', 'draft' );
		$this->create_post( array( 'Lake' ) );

		$this->assertSame( array( 'Lake' ), array_column( $this->suggest( 'lake' )->get_data()['tags'], 'name' ) );
	}

	/** A query matches tag names anywhere in the name, at most three, most used first. */
	public function test_query_matches_tags_by_name() {
		$this->create_post( array( 'Lake', 'Mountains' ) );
		$this->create_post( array( 'Lake', 'Lakeside' ) );
		$this->create_post( array( 'Snowflake', 'Lake Michigan' ) );

		$tags = $this->suggest( 'lake' )->get_data()['tags'];

		$this->assertCount( 3, $tags );
		$this->assertSame( 'Lake', $tags[0]['name'] );
		$this->assertSame( 2, $tags[0]['count'] );
		$this->assertNotContains( 'Mountains', array_column( $tags, 'name' ) );
		$this->assertIsInt( $tags[0]['id'] );
	}

	/** A tag name with an ampersand comes back as plain text. */
	public function test_tag_names_are_plain_text() {
		$this->create_post( array( 'Salt & Pepper' ) );

		$this->assertSame( array( 'Salt & Pepper' ), array_column( $this->suggest( 'salt' )->get_data()['tags'], 'name' ) );
	}

	/** A query matches Check In places, grouped by name with how many Marks each has. */
	public function test_query_matches_places_with_counts() {
		$this->create_post( array(), 'Lake Geneva, Wisconsin' );
		$this->create_post( array(), 'Lake Geneva, Wisconsin' );
		$this->create_post( array(), 'Crater Lake Lodge' );
		$this->create_post( array(), 'Union Station' );

		$places = $this->suggest( 'lake' )->get_data()['places'];

		$this->assertSame(
			array(
				array(
					'name'  => 'Lake Geneva, Wisconsin',
					'count' => 2,
				),
				array(
					'name'  => 'Crater Lake Lodge',
					'count' => 1,
				),
			),
			$places
		);
	}

	/** One letter is too little to look up places with. */
	public function test_one_letter_returns_no_places() {
		$this->create_post( array(), 'Lake Geneva, Wisconsin' );

		$this->assertSame( array(), $this->suggest( 'l' )->get_data()['places'] );
	}

	/** A draft's place is never offered. */
	public function test_draft_places_are_left_out() {
		$this->create_post( array(), 'Lake Draft', 'draft' );

		$this->assertSame( array(), $this->suggest( 'lake' )->get_data()['places'] );
	}

	/** A password-protected Mark's place stays out for someone who couldn't read the Mark. */
	public function test_protected_places_are_hidden_from_authors_but_not_editors() {
		$post_id = $this->create_post( array(), 'Lake Secret' );
		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_password' => 'secret',
			)
		);

		$this->assertSame( array(), $this->suggest( 'lake' )->get_data()['places'] );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( array( 'Lake Secret' ), array_column( $this->suggest( 'lake' )->get_data()['places'], 'name' ) );
	}

	/** A LIKE wildcard typed into Search is matched as the character itself. */
	public function test_like_wildcards_in_the_query_are_literal() {
		$this->create_post( array(), 'Lake Geneva, Wisconsin' );
		$this->create_post( array(), '100% Coffee' );

		$this->assertSame( array( '100% Coffee' ), array_column( $this->suggest( '0%' )->get_data()['places'], 'name' ) );
		$this->assertSame( array(), $this->suggest( '__' )->get_data()['places'] );
	}

	public function test_first_post_year_is_the_oldest_published_posts_year() {
		self::factory()->post->create( array( 'post_date' => '2019-06-01 10:00:00' ) );
		self::factory()->post->create( array( 'post_date' => '2023-02-01 10:00:00' ) );
		self::factory()->post->create(
			array(
				'post_date'   => '2012-02-01 10:00:00',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 2019, Daymark_Routes::first_post_year(), 'A draft does not count.' );
	}

	public function test_first_post_year_is_this_year_on_a_site_with_no_posts() {
		$this->assertSame( (int) wp_date( 'Y' ), Daymark_Routes::first_post_year() );
	}
}
