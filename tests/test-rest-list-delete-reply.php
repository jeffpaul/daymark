<?php
/**
 * REST tests for list filtering, Mark deletion, and comment replies.
 *
 * Covers the three additions on Daymark_REST_Controller:
 *   - GET  /marks type + search filters
 *   - DELETE /marks/{id}
 *   - POST /notifications/{comment_id}/reply
 *
 * @package Daymark
 */

/**
 * Exercises the list filters, delete, and reply endpoints end to end.
 */
class Test_Rest_List_Delete_Reply extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var int */
	private $author_b;

	public function set_up(): void {
		parent::set_up();
		$this->author_a = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->author_b = (int) self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Create a Mark post of a given primary type.
	 *
	 * @param int    $user_id Author.
	 * @param string $type    Primary type meta value.
	 * @param string $status  Post status.
	 * @param string $title   Post title.
	 * @return int
	 */
	private function create_mark( int $user_id, string $type, string $status = 'publish', string $title = 'Daymark' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $user_id,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => 'Body copy for ' . $title,
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', $type );

		return $post_id;
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** The type filter returns only Marks of that primary type. */
	public function test_list_type_filter() {
		wp_set_current_user( $this->author_a );

		$image = $this->create_mark( $this->author_a, 'image', 'publish', 'A photo' );
		$note  = $this->create_mark( $this->author_a, 'note', 'publish', 'A note' );

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$request->set_param( 'type', 'image' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $image, $ids, 'The image Mark is returned' );
		$this->assertNotContains( $note, $ids, 'The note Mark is excluded by the type filter' );
	}

	/**
	 * A Mark with no featured image (e.g. one migrated from Moment, which
	 * never set one) still gets a thumbnail from its own media in the
	 * GET /marks list Home reads from.
	 */
	public function test_list_thumbnail_falls_back_to_media_ids_without_featured_image() {
		wp_set_current_user( $this->author_a );

		$post_id       = $this->create_mark( $this->author_a, 'image', 'publish', 'No featured image' );
		$attachment_id = self::factory()->attachment->create_object(
			__DIR__ . '/e2e/fixtures/test-image.png',
			$post_id,
			array( 'post_mime_type' => 'image/png' )
		);
		update_post_meta( $post_id, '_daymark_media_ids', wp_json_encode( array( $attachment_id ) ) );

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$marks   = rest_do_request( $request )->get_data();
		$mark    = current( array_filter( $marks, static fn( $m ) => $m['id'] === $post_id ) );

		$this->assertNotSame( '', $mark['thumbnail'], 'A thumbnail must be found via _daymark_media_ids' );
		$this->assertStringContainsString( 'test-image', $mark['thumbnail'] );
	}

	/** A real featured image still wins over _daymark_media_ids when both exist. */
	public function test_list_thumbnail_prefers_featured_image_over_media_ids() {
		wp_set_current_user( $this->author_a );

		$post_id             = $this->create_mark( $this->author_a, 'image', 'publish', 'Has featured image' );
		$featured_id         = self::factory()->attachment->create_object(
			__DIR__ . '/e2e/fixtures/test-image.png',
			$post_id,
			array( 'post_mime_type' => 'image/png' )
		);
		$other_attachment_id = self::factory()->attachment->create_upload_object( $this->temp_png() );
		set_post_thumbnail( $post_id, $featured_id );
		update_post_meta( $post_id, '_daymark_media_ids', wp_json_encode( array( $other_attachment_id ) ) );

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$marks   = rest_do_request( $request )->get_data();
		$mark    = current( array_filter( $marks, static fn( $m ) => $m['id'] === $post_id ) );

		$this->assertSame( wp_get_attachment_image_url( $featured_id, 'medium' ), $mark['thumbnail'] );
	}

	/**
	 * The list's comment_count/like_count/repost_count reflect the app
	 * shell's stat row: approved comments, approved 'like'-type comments,
	 * and approved 'repost'-type comments, counted separately, with
	 * unapproved ones excluded. 'repost' is the comment_type the
	 * ActivityPub/ATmosphere/Webmention plugins all write for a repost/
	 * reblog/reshare (see issue #41).
	 */
	public function test_list_reports_approved_comment_like_and_repost_counts() {
		wp_set_current_user( $this->author_a );

		$post_id = $this->create_mark( $this->author_a, 'note', 'publish', 'Has engagement' );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'comment_type'     => 'comment',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'comment_type'     => 'comment',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 0,
				'comment_type'     => 'comment',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'comment_type'     => 'like',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'comment_type'     => 'repost',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 0,
				'comment_type'     => 'repost',
			)
		);

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$marks   = rest_do_request( $request )->get_data();
		$mark    = current( array_filter( $marks, static fn( $m ) => $m['id'] === $post_id ) );

		$this->assertSame( 2, $mark['comment_count'], 'Only the 2 approved comments are counted' );
		$this->assertSame( 1, $mark['like_count'] );
		$this->assertSame( 1, $mark['repost_count'], 'Only the 1 approved repost is counted' );
	}

	/** A temporary PNG file for attachment tests that don't care about its content. */
	private function temp_png(): string {
		$file = wp_tempnam( 'daymark-thumb-' ) . '.png';
		copy( __DIR__ . '/e2e/fixtures/test-image.png', $file );

		return $file;
	}

	/** The search param matches on title/content and excludes non-matches. */
	public function test_list_search_filter() {
		wp_set_current_user( $this->author_a );

		$match = $this->create_mark( $this->author_a, 'note', 'publish', 'Sunrise over the bay' );
		$other = $this->create_mark( $this->author_a, 'note', 'publish', 'City lights' );

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$request->set_param( 's', 'Sunrise' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $match, $ids, 'The matching Mark is returned' );
		$this->assertNotContains( $other, $ids, 'Non-matching Marks are excluded' );
	}

	/** An empty type/search behaves like the unfiltered list. */
	public function test_list_empty_filters_return_all() {
		wp_set_current_user( $this->author_a );

		$image = $this->create_mark( $this->author_a, 'image', 'publish', 'A photo' );
		$note  = $this->create_mark( $this->author_a, 'note', 'publish', 'A note' );

		$request = $this->request( 'GET', '/daymark/v1/marks' );
		$request->set_param( 'type', '' );
		$request->set_param( 's', '' );
		$ids = array_column( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $image, $ids );
		$this->assertContains( $note, $ids );
	}

	/** Deleting a non-Mark post is a 404. */
	public function test_delete_non_daymark_is_404() {
		wp_set_current_user( $this->author_a );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $this->author_a,
				'post_status' => 'publish',
			)
		);

		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/marks/{$post_id}" ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'daymark_not_found', $response->get_data()['code'] );
	}

	/** A successful delete trashes the Mark (reversible). */
	public function test_delete_trashes_daymark() {
		wp_set_current_user( $this->author_a );

		$post_id = $this->create_mark( $this->author_a, 'note' );

		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/marks/{$post_id}" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['trashed'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertNotNull( get_post( $post_id ), 'Trashed, not permanently deleted' );
	}

	/** Deleting an already-trashed Mark is idempotent success. */
	public function test_delete_is_idempotent() {
		wp_set_current_user( $this->author_a );

		$post_id = $this->create_mark( $this->author_a, 'note' );
		wp_trash_post( $post_id );

		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/marks/{$post_id}" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['trashed'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/** Deletion requires the delete_post capability for that Mark. */
	public function test_delete_requires_capability() {
		$post_id = $this->create_mark( $this->author_a, 'note' );

		// Author B cannot delete author A's post.
		wp_set_current_user( $this->author_b );
		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/marks/{$post_id}" ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertNotSame( 'trash', get_post_status( $post_id ), 'The Mark stays published' );

		// The owner can delete their own Mark.
		wp_set_current_user( $this->author_a );
		$response = rest_do_request( $this->request( 'DELETE', "/daymark/v1/marks/{$post_id}" ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Happy path: a reply creates a nested comment on the Mark. */
	public function test_reply_creates_nested_comment() {
		wp_set_current_user( $this->author_a );

		$post_id    = $this->create_mark( $this->author_a, 'note' );
		$comment_id = (int) self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$request = $this->request( 'POST', "/daymark/v1/notifications/{$comment_id}/reply" );
		$request->set_param( 'content', 'Thanks for the reply!' );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$data     = $response->get_data();
		$new_id   = (int) $data['comment_ID'];
		$new_comm = get_comment( $new_id );

		$this->assertInstanceOf( 'WP_Comment', $new_comm );
		$this->assertSame( $comment_id, (int) $new_comm->comment_parent, 'Reply is nested under the parent comment' );
		$this->assertSame( $post_id, (int) $new_comm->comment_post_ID, 'Reply is attached to the Mark post' );
		$this->assertSame( $this->author_a, (int) $new_comm->user_id, 'Reply is authored by the current user' );
		$this->assertSame( '1', (string) $new_comm->comment_approved, 'Reply is approved' );
	}

	/** Disallowed/duplicate content returns a clean JSON WP_Error, not a fatal. */
	public function test_reply_disallowed_content_returns_json_error() {
		wp_set_current_user( $this->author_a );

		$post_id    = $this->create_mark( $this->author_a, 'note' );
		$comment_id = (int) self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$request = $this->request( 'POST', "/daymark/v1/notifications/{$comment_id}/reply" );
		$request->set_param( 'content', 'Exact duplicate body' );
		$first = rest_do_request( $request );
		$this->assertSame( 201, $first->get_status(), 'First reply succeeds' );

		// An identical reply trips wp_new_comment()'s duplicate/flood guard,
		// which (with $avoid_die = true) returns a WP_Error instead of wp_die().
		$again = $this->request( 'POST', "/daymark/v1/notifications/{$comment_id}/reply" );
		$again->set_param( 'content', 'Exact duplicate body' );
		$response = rest_do_request( $again );

		$this->assertGreaterThanOrEqual( 400, $response->get_status(), 'Rejected with a client/JSON error' );
		$this->assertNotSame( 201, $response->get_status(), 'The duplicate was not created' );
		$this->assertArrayHasKey( 'code', $response->get_data(), 'A JSON error payload is returned' );
	}

	/** A comment that does not exist is a 404. */
	public function test_reply_missing_comment_is_404() {
		wp_set_current_user( $this->author_a );

		$request = $this->request( 'POST', '/daymark/v1/notifications/999999/reply' );
		$request->set_param( 'content', 'Hello?' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'daymark_comment_not_found', $response->get_data()['code'] );
	}

	/** A comment on a non-Mark post cannot be replied to (403). */
	public function test_reply_on_non_daymark_is_403() {
		wp_set_current_user( $this->author_a );

		$normal_post = (int) self::factory()->post->create( array( 'post_author' => $this->author_a ) );
		$comment_id  = (int) self::factory()->comment->create( array( 'comment_post_ID' => $normal_post ) );

		$request = $this->request( 'POST', "/daymark/v1/notifications/{$comment_id}/reply" );
		$request->set_param( 'content', 'Should be blocked' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** A reply to a comment on a Mark the user cannot edit is a 403. */
	public function test_reply_on_non_editable_daymark_is_403() {
		$post_id    = $this->create_mark( $this->author_a, 'note' );
		$comment_id = (int) self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		// Author B cannot edit author A's Mark.
		wp_set_current_user( $this->author_b );
		$request = $this->request( 'POST', "/daymark/v1/notifications/{$comment_id}/reply" );
		$request->set_param( 'content', 'Not my Mark' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}
}
