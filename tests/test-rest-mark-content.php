<?php
/**
 * REST tests for GET /daymark/v1/marks/{id}/content — a Timeline card's
 * inline-expand fetch: just the post's own rendered content (`the_content`
 * on `post_content`), not the page a permalink visit would otherwise
 * render around it (theme chrome, comments, etc.).
 *
 * Deliberately not gated on `_daymark_is_mark`, matching GET /timeline's
 * own Marks-side query — see get_timeline()'s docblock in
 * class-rest-controller.php and test_ordinary_block_editor_post... below.
 *
 * @package Daymark
 */

/**
 * Exercises the Mark/ordinary-post inline-expand content endpoint.
 */
class Test_Rest_Mark_Content extends WP_UnitTestCase {

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
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Request
	 */
	private function request_for( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}/content" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/** A true Mark's own content is returned, rendered — not the raw block markup. */
	public function test_mark_content_is_rendered() {
		wp_set_current_user( $this->author_a );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'publish',
				'post_content' => "<!-- wp:paragraph -->\n<p>Hello from a Mark.</p>\n<!-- /wp:paragraph -->",
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Hello from a Mark.', $response->get_data()['content'] );
		$this->assertStringNotContainsString( 'wp:paragraph', $response->get_data()['content'], 'Block comment markup is not leaked as visible text' );
	}

	/**
	 * Not gated on _daymark_is_mark: an ordinary post published straight
	 * through the block editor is fair game too, same as GET /timeline's
	 * own inclusive query.
	 */
	public function test_ordinary_block_editor_post_content_is_served() {
		wp_set_current_user( $this->author_a );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'publish',
				'post_content' => '<p>An ordinary blog post, never touching Daymark.</p>',
			)
		);
		// Deliberately no _daymark_is_mark meta — this is the whole point.

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'An ordinary blog post, never touching Daymark.', $response->get_data()['content'] );
	}

	/**
	 * The whole reason this endpoint exists instead of fetching the real
	 * permalink page: rendering straight from post_content never carries
	 * any theme chrome, nav, footer, or comments to begin with — there's no
	 * page markup to accidentally leak, unlike a subscription post's
	 * external click-through fetch.
	 */
	public function test_content_never_carries_theme_chrome() {
		wp_set_current_user( $this->author_a );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'publish',
				'post_content' => '<p>Just the post.</p>',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		$response = rest_do_request( $this->request_for( $post_id ) );
		$content  = $response->get_data()['content'];

		$this->assertStringNotContainsString( '<nav', $content );
		$this->assertStringNotContainsString( '<footer', $content );
		$this->assertStringNotContainsString( 'comments-area', $content );
	}

	/** Visible to any logged-in Daymark user, not just the post's own author — matching Timeline's own visibility. */
	public function test_visible_to_a_different_logged_in_user_than_the_author() {
		wp_set_current_user( $this->author_b );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'publish',
				'post_content' => '<p>Someone else&#8217;s Mark.</p>',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/** A draft Mark is not exposed here — the Timeline itself never surfaces drafts. */
	public function test_draft_post_returns_404() {
		wp_set_current_user( $this->author_a );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'draft',
				'post_content' => '<p>Not published yet.</p>',
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/** A non-`post` post type (e.g. an attachment) is not exposed here. */
	public function test_non_post_post_type_returns_404() {
		wp_set_current_user( $this->author_a );

		$attachment_id = (int) self::factory()->attachment->create( array( 'post_status' => 'publish' ) );

		$response = rest_do_request( $this->request_for( $attachment_id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/** A nonexistent post ID is a clean 404, not a fatal. */
	public function test_nonexistent_post_id_returns_404() {
		wp_set_current_user( $this->author_a );

		$response = rest_do_request( $this->request_for( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/** An unauthenticated request is rejected with 401. */
	public function test_unauthenticated_request_returns_401() {
		wp_set_current_user( 0 );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Content.</p>',
			)
		);

		$request  = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}/content" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}
