<?php
/**
 * REST capability-scoping tests (wp.org review findings).
 *
 * @package Daymark
 */

/**
 * Beyond the shared edit_posts check, endpoints must scope drafts,
 * notifications, per-post actions, and uploads to the acting user.
 */
class Test_Rest_Permissions extends WP_UnitTestCase {

	/** @var int */
	private $author_a;

	/** @var int */
	private $author_b;

	public function set_up(): void {
		parent::set_up();
		$this->author_a = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->author_b = (int) self::factory()->user->create( array( 'role' => 'author' ) );
	}

	private function create_daymark_as( int $user_id, string $status ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_status' => $status,
				'post_title'  => "Mark by {$user_id} ({$status})",
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );
		update_post_meta( $post_id, '_daymark_comment_backflow_enabled', '1' );

		return $post_id;
	}

	private function request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** GET /marks hides other authors' drafts but lists published posts. */
	public function test_marks_list_hides_others_drafts() {
		$a_draft     = $this->create_daymark_as( $this->author_a, 'draft' );
		$a_published = $this->create_daymark_as( $this->author_a, 'publish' );
		$b_draft     = $this->create_daymark_as( $this->author_b, 'draft' );

		wp_set_current_user( $this->author_b );
		$ids = array_column( rest_do_request( $this->request( 'GET', '/daymark/v1/marks' ) )->get_data(), 'id' );

		$this->assertContains( $a_published, $ids, 'Published Marks are listed for everyone' );
		$this->assertContains( $b_draft, $ids, 'Own drafts are listed' );
		$this->assertNotContains( $a_draft, $ids, "Another author's draft must not be listed" );

		// An editor can edit all posts, so all drafts are listed.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$ids = array_column( rest_do_request( $this->request( 'GET', '/daymark/v1/marks' ) )->get_data(), 'id' );
		$this->assertContains( $a_draft, $ids, 'Editors see all drafts' );
	}

	/** Notifications only cover Marks the current user can edit. */
	public function test_notifications_scoped_to_editable_posts() {
		$a_daymark = $this->create_daymark_as( $this->author_a, 'publish' );
		self::factory()->comment->create( array( 'comment_post_ID' => $a_daymark ) );

		wp_set_current_user( $this->author_a );
		$notifications = new Daymark_Notifications();
		$this->assertNotEmpty( $notifications->get_notifications(), 'The post owner sees its replies' );

		wp_set_current_user( $this->author_b );
		$this->assertSame( array(), $notifications->get_notifications(), "Another author must not see A's replies" );
	}

	/** sync-responses requires edit_post on the targeted Mark. */
	public function test_sync_responses_requires_edit_post() {
		$a_daymark = $this->create_daymark_as( $this->author_a, 'publish' );

		wp_set_current_user( $this->author_b );
		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/marks/{$a_daymark}/sync-responses" ) );
		$this->assertSame( 403, $response->get_status(), "Another author cannot sync A's Mark" );

		wp_set_current_user( $this->author_a );
		$response = rest_do_request( $this->request( 'POST', "/daymark/v1/marks/{$a_daymark}/sync-responses" ) );
		$this->assertSame( 200, $response->get_status(), 'The owner can sync their own Mark' );

		// Nonexistent posts keep their 404 from the handler, not a 403.
		$response = rest_do_request( $this->request( 'POST', '/daymark/v1/marks/999999/sync-responses' ) );
		$this->assertSame( 404, $response->get_status() );
	}

	/** Attaching media requires the upload_files capability. */
	public function test_create_daymark_upload_requires_capability() {
		// Contributors can edit_posts (drafts) but cannot upload_files.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$request = $this->request( 'POST', '/daymark/v1/marks' );
		$request->set_param( 'caption', 'No uploads allowed' );
		$request->set_file_params(
			array(
				'daymark_media' => array(
					'name'     => 'x.png',
					'type'     => 'image/png',
					'tmp_name' => '/tmp/nonexistent.png',
					'error'    => 0,
					'size'     => 1,
				),
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cannot_upload', $response->get_data()['code'] );

		// Without files, the same contributor can still create a Mark.
		$request = $this->request( 'POST', '/daymark/v1/marks' );
		$request->set_param( 'caption', 'Caption-only note' );
		$this->assertSame( 201, rest_do_request( $request )->get_status() );
	}
}
