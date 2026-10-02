<?php
/**
 * REST tests for GET /daymark/v1/marks/{id}/content — the full-screen post
 * view's own fetch: just the post's own rendered content (`the_content`
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
 * Exercises the Mark/ordinary-post full-screen post-view content endpoint.
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

	/**
	 * Publish a post with the given content as author A, logged in as A.
	 *
	 * @param string $content Post content.
	 * @return int Post ID.
	 */
	private function published_post( string $content = '<p>Body.</p>' ): int {
		wp_set_current_user( $this->author_a );

		return (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	}

	/**
	 * An uploaded image attachment (a real file, so its URL and sizes resolve).
	 *
	 * @param int $post_id Parent post ID.
	 * @return int Attachment ID.
	 */
	private function image( int $post_id ): int {
		return (int) self::factory()->attachment->create_upload_object( __DIR__ . '/e2e/fixtures/test-image.png', $post_id );
	}

	/** A post with no Featured Content and no featured image has nothing to show above its body. */
	public function test_featured_is_empty_with_nothing_set() {
		$data = rest_do_request( $this->request_for( $this->published_post() ) )->get_data();

		$this->assertSame( '', $data['featured'] );
	}

	/** A featured image the content doesn't show is returned for the top of the post view. */
	public function test_featured_image_not_in_content_is_returned() {
		$post_id  = $this->published_post();
		$image_id = $this->image( $post_id );
		set_post_thumbnail( $post_id, $image_id );

		$featured = rest_do_request( $this->request_for( $post_id ) )->get_data()['featured'];

		$this->assertStringContainsString( '<img', $featured );
		$this->assertStringContainsString( wp_basename( (string) get_attached_file( $image_id ), '.png' ), $featured );
	}

	/**
	 * An image Mark's first photo is both its featured image and in its
	 * content (the publisher does that), so it is not shown twice.
	 */
	public function test_featured_image_already_in_content_is_not_repeated() {
		$post_id  = $this->published_post();
		$image_id = $this->image( $post_id );
		set_post_thumbnail( $post_id, $image_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => sprintf(
					'<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img src="%2$s" class="wp-image-%1$d" alt="" /></figure><!-- /wp:image -->',
					$image_id,
					esc_url( (string) wp_get_attachment_url( $image_id ) )
				),
			)
		);

		$this->assertSame( '', rest_do_request( $this->request_for( $post_id ) )->get_data()['featured'] );
	}

	/** A resized copy of the featured image in the content (no wp-image class) also counts as shown. */
	public function test_resized_copy_of_featured_image_in_content_is_not_repeated() {
		$post_id  = $this->published_post();
		$image_id = $this->image( $post_id );
		set_post_thumbnail( $post_id, $image_id );
		$resized = preg_replace( '/\.png$/', '-300x200.png', (string) wp_get_attachment_url( $image_id ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p><img src="' . esc_url( $resized ) . '" alt="" /></p>',
			)
		);

		$this->assertSame( '', rest_do_request( $this->request_for( $post_id ) )->get_data()['featured'] );
	}

	/** Featured Content wins over a featured image. */
	public function test_featured_content_is_returned_instead_of_the_featured_image() {
		$post_id = $this->published_post();
		set_post_thumbnail( $post_id, $this->image( $post_id ) );
		update_post_meta( $post_id, Daymark_Featured_Content::META_TYPE, 'quote' );
		update_post_meta(
			$post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode( array( 'quote' => array( 'text' => 'Seize the day.' ) ) )
		);

		$featured = rest_do_request( $this->request_for( $post_id ) )->get_data()['featured'];

		$this->assertStringContainsString( 'daymark-featured-quote', $featured );
		$this->assertStringContainsString( 'Seize the day.', $featured );
		$this->assertStringNotContainsString( '<img', $featured );
	}

	/**
	 * A gallery returns every image, even though a REST request is never a
	 * single-post view (where the front end shows only the first image).
	 */
	public function test_featured_gallery_returns_every_slide() {
		$post_id = $this->published_post();
		$ids     = array( $this->image( $post_id ), $this->image( $post_id ), $this->image( $post_id ) );
		update_post_meta( $post_id, Daymark_Featured_Content::META_TYPE, 'gallery' );
		update_post_meta(
			$post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode( array( 'gallery' => array( 'attachment_ids' => $ids ) ) )
		);

		$featured = rest_do_request( $this->request_for( $post_id ) )->get_data()['featured'];

		$this->assertSame( 3, substr_count( $featured, 'daymark-fc-gallery__slide' ) );
		$this->assertStringContainsString( 'data-daymark-gallery', $featured );
	}

	/**
	 * Gallery block markup in the shape Daymark_Publisher writes.
	 *
	 * @param int[] $ids Image attachment IDs.
	 * @return string
	 */
	private function gallery_block( array $ids ): string {
		$inner = array();
		foreach ( $ids as $id ) {
			$inner[] = sprintf(
				'<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
				$id,
				esc_url( (string) wp_get_attachment_url( $id ) )
			);
		}

		return '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->'
			. '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . implode( '', $inner ) . '</figure><!-- /wp:gallery -->'
			. '<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->';
	}

	/** A gallery block in the post shows as the app's slider, in place, keeping its photo order. */
	public function test_gallery_block_renders_as_a_slider_in_the_post_view() {
		$post_id = $this->published_post();
		$ids     = array( $this->image( $post_id ), $this->image( $post_id ), $this->image( $post_id ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->gallery_block( array( $ids[2], $ids[0], $ids[1] ) ),
			)
		);

		$content = rest_do_request( $this->request_for( $post_id ) )->get_data()['content'];

		$this->assertStringContainsString( 'data-daymark-gallery', $content );
		$this->assertSame( 3, substr_count( $content, 'daymark-fc-gallery__slide' ) );
		$this->assertStringContainsString( 'aria-label="Gallery"', $content );
		$this->assertStringNotContainsString( 'wp-block-gallery', $content );
		$this->assertStringNotContainsString( 'DAYMARKGALLERYSLOT', $content );
		$this->assertLessThan( strpos( $content, 'data-daymark-gallery' ), strpos( $content, 'Before.' ) );
		$this->assertLessThan( strpos( $content, 'After.' ), strpos( $content, 'data-daymark-gallery' ) );

		$first  = strpos( $content, wp_basename( (string) get_attached_file( $ids[2] ), '.png' ) );
		$second = strpos( $content, wp_basename( (string) get_attached_file( $ids[0] ), '.png' ) );
		$this->assertNotFalse( $first );
		$this->assertLessThan( $second, $first, 'Slides follow the gallery block order' );
	}

	/** A one-photo gallery block is left as core renders it: there is nothing to slide. */
	public function test_single_image_gallery_block_is_left_alone() {
		$post_id = $this->published_post();
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->gallery_block( array( $this->image( $post_id ) ) ),
			)
		);

		$content = rest_do_request( $this->request_for( $post_id ) )->get_data()['content'];

		$this->assertStringContainsString( 'wp-block-gallery', $content );
		$this->assertStringNotContainsString( 'data-daymark-gallery', $content );
	}

	/** The swap is scoped to this request: a gallery rendered elsewhere afterwards is untouched. */
	public function test_gallery_swap_does_not_leak_past_the_request() {
		$post_id = $this->published_post();
		$ids     = array( $this->image( $post_id ), $this->image( $post_id ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->gallery_block( $ids ),
			)
		);

		rest_do_request( $this->request_for( $post_id ) );

		$this->assertStringContainsString( 'wp-block-gallery', do_blocks( $this->gallery_block( $ids ) ) );
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
	 * Another Author's post cannot carry Daymark's own overlay classes into an
	 * Editor's app (a `daymark-sheet` is a fixed, full-screen layer through
	 * app.css). Unrelated classes and the Check In map preview's own classes
	 * and pin style are kept.
	 */
	public function test_mark_content_strips_daymark_classes_but_keeps_the_checkin_map() {
		$content = '<div class="daymark-sheet wp-block-group">Spoof</div>'
			. '<figure class="daymark-checkin-map"><img src="https://tile.openstreetmap.org/1/1/1.png" width="256" height="256" alt="" />'
			. '<span class="daymark-checkin-map__pin" style="left:50%;top:50%" aria-hidden="true"></span></figure>';

		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'  => $this->author_a,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		wp_set_current_user( $this->author_b );

		$response = rest_do_request( $this->request_for( $post_id ) );

		$this->assertSame( 200, $response->get_status() );

		$html = $response->get_data()['content'];

		$this->assertStringNotContainsString( 'daymark-sheet', $html, 'An overlay class from another author is removed' );
		$this->assertStringContainsString( 'wp-block-group', $html, 'Unrelated classes are kept' );
		$this->assertStringContainsString( 'daymark-checkin-map', $html, "The Check In map's own class is kept" );
		$this->assertStringContainsString( 'daymark-checkin-map__pin', $html, "The map pin's class is kept" );
		$this->assertStringContainsString( 'left:50%;top:50%', $html, "The map pin's positioning style is kept" );
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
