<?php
/**
 * Tests for the "first Mark" flag the app celebrates with confetti.
 *
 * @package Daymark
 */

/**
 * Publishing from the app answers with `first_mark`: true for the first
 * Mark a person ever publishes, and never again for that person.
 */
class Test_First_Mark extends WP_UnitTestCase {

	/**
	 * The author publishing in each test.
	 *
	 * @var int
	 */
	private $author;

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	/**
	 * Save a Mark through one of Daymark's own routes.
	 *
	 * @param string               $route  REST route.
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, mixed> The response data.
	 */
	private function save( string $route, array $params ): array {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		$this->assertContains( $response->get_status(), array( 200, 201 ) );

		return (array) $response->get_data();
	}

	/** A Mark someone else already published, as it would be before this feature existed. */
	private function existing_mark( int $author ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		return $post_id;
	}

	/** The first Mark a person publishes is their first Mark; the second is not. */
	public function test_first_published_mark_is_flagged_once() {
		$first = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'My first Mark',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 'publish', $first['status'] );
		$this->assertTrue( $first['first_mark'] );

		$second = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'My second Mark',
				'status'  => 'publish',
			)
		);

		$this->assertFalse( $second['first_mark'] );
	}

	/** A draft, or an autosave, is not a published Mark and doesn't use up the celebration. */
	public function test_a_draft_is_not_a_first_mark_and_does_not_use_it_up() {
		$draft = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'Not yet',
				'status'  => 'draft',
			)
		);

		$this->assertFalse( $draft['first_mark'] );
		$this->assertSame( '', (string) get_user_meta( $this->author, Daymark_Publisher::FIRST_MARK_META, true ) );

		$autosave = $this->save(
			'/daymark/v1/marks/' . (int) $draft['id'],
			array(
				'caption'  => 'Still not yet',
				'autosave' => '1',
			)
		);

		$this->assertFalse( $autosave['first_mark'] );
		$this->assertSame( '', (string) get_user_meta( $this->author, Daymark_Publisher::FIRST_MARK_META, true ) );
	}

	/** A draft that goes live is a first Mark; editing it afterwards is not. */
	public function test_publishing_a_draft_is_flagged_and_editing_it_is_not() {
		$draft = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'Started as a draft',
				'status'  => 'draft',
			)
		);

		$published = $this->save(
			'/daymark/v1/marks/' . (int) $draft['id'],
			array(
				'caption' => 'Started as a draft',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 'publish', $published['status'] );
		$this->assertTrue( $published['first_mark'] );

		$edited = $this->save(
			'/daymark/v1/marks/' . (int) $draft['id'],
			array(
				'caption' => 'Started as a draft, then fixed a typo',
				'status'  => 'publish',
			)
		);

		$this->assertFalse( $edited['first_mark'] );
	}

	/** Someone who already had published Marks before this existed gets no confetti, now or later. */
	public function test_someone_with_earlier_marks_is_never_flagged() {
		$earlier = $this->existing_mark( $this->author );

		$next = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'Not my first',
				'status'  => 'publish',
			)
		);

		$this->assertFalse( $next['first_mark'] );

		// Even once the earlier Mark is gone, the question has been answered.
		wp_delete_post( $earlier, true );
		wp_delete_post( (int) $next['id'], true );

		$later = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'Still not my first',
				'status'  => 'publish',
			)
		);

		$this->assertFalse( $later['first_mark'] );
	}

	/** Editing the only Mark someone had before this existed is not a first Mark. */
	public function test_editing_an_earlier_only_mark_is_not_flagged() {
		$only = $this->existing_mark( $this->author );
		update_post_meta( $only, '_daymark_caption', 'My only Mark' );

		$edited = $this->save(
			'/daymark/v1/marks/' . $only,
			array(
				'caption' => 'My only Mark, edited',
				'status'  => 'publish',
			)
		);

		$this->assertFalse( $edited['first_mark'] );
	}

	/** Another person's Marks don't count against yours. */
	public function test_other_peoples_marks_do_not_count() {
		$this->existing_mark( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$first = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'My first, on a busy site',
				'status'  => 'publish',
			)
		);

		$this->assertTrue( $first['first_mark'] );
	}

	/** Only a published Mark of your own can be claimed. */
	public function test_claim_ignores_what_is_not_your_published_mark() {
		$plain_post = (int) self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
			)
		);
		$this->assertFalse( Daymark_Publisher::claim_first_mark( $plain_post ), 'A post that is not a Mark' );

		$someone_elses = $this->existing_mark( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( Daymark_Publisher::claim_first_mark( $someone_elses ), "Someone else's Mark" );

		$this->assertFalse( Daymark_Publisher::claim_first_mark( 0 ), 'No post' );

		$this->assertSame( '', (string) get_user_meta( $this->author, Daymark_Publisher::FIRST_MARK_META, true ) );
	}
}
