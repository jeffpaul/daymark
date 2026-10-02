<?php
/**
 * Featured Content Phase 4 (issue #408): share image and description,
 * oEmbed response, embed card, Open Graph / Twitter Card output, and the
 * hand-off to Yoast SEO, Rank Math, All in One SEO, and Jetpack.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Featured_Content_Social.
 */
class Test_Featured_Content_Social extends WP_UnitTestCase {

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * The class under test.
	 *
	 * @var Daymark_Featured_Content_Social
	 */
	private $social;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create(
			array(
				'post_title'   => 'A post with Featured Content',
				'post_excerpt' => 'The post excerpt.',
				'post_status'  => 'publish',
			)
		);
		$this->social  = new Daymark_Featured_Content_Social();

		// The URL guard resolves hostnames; give it a public address so a
		// mocked provider is not refused for resolving nowhere.
		add_filter( 'daymark_subscription_url_guard_resolved_addresses', array( $this, 'public_address' ) );
		add_filter( 'daymark_featured_content_seo_plugin', '__return_empty_string' );
	}

	public function tear_down(): void {
		remove_all_filters( 'daymark_subscription_url_guard_resolved_addresses' );
		remove_all_filters( 'daymark_featured_content_seo_plugin' );
		remove_all_filters( 'daymark_featured_content_replaces_featured_image' );
		remove_all_filters( 'daymark_featured_content_thumbnail_url' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * A public IP for the URL guard.
	 *
	 * @return string[]
	 */
	public function public_address(): array {
		return array( '93.184.216.34' );
	}

	/**
	 * Upload a real image attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function image_attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( __DIR__ . '/e2e/fixtures/test-image.png', $this->post_id );
	}

	/**
	 * Set the test post's Featured Content.
	 *
	 * @param string               $type Type.
	 * @param array<string, mixed> $data Data for that type.
	 * @return void
	 */
	private function set_featured( string $type, array $data ): void {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, $type );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_DATA, wp_slash( wp_json_encode( array( $type => $data ) ) ) );
	}

	/**
	 * Answer every outbound request with a canned body.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header.
	 * @return void
	 */
	private function mock_http( string $body, string $content_type = 'application/json' ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $body, $content_type ) {
				return array(
					'headers'  => array( 'content-type' => $content_type ),
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	/**
	 * Render wp_head's share tags for the test post's own page.
	 *
	 * @return string
	 */
	private function head_tags(): string {
		$this->go_to( get_permalink( $this->post_id ) );

		ob_start();
		$this->social->print_meta_tags();

		return (string) ob_get_clean();
	}

	// -- Resolution ---------------------------------------------------------

	public function test_gallery_shares_its_first_image() {
		$first = $this->image_attachment();
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first, $this->image_attachment() ) ) );

		$image = Daymark_Featured_Content_Social::image( $this->post_id );

		$this->assertSame( wp_get_attachment_image_src( $first, 'large' )[0], $image['url'] );
		$this->assertGreaterThan( 0, $image['width'] );
	}

	public function test_library_video_shares_its_cover_art() {
		$video = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'clip.mp4',
				'post_mime_type' => 'video/mp4',
				'post_parent'    => $this->post_id,
			)
		);
		$cover = $this->image_attachment();
		set_post_thumbnail( $video, $cover );
		$this->set_featured(
			'video',
			array(
				'source'        => 'library',
				'attachment_id' => $video,
			)
		);

		$this->assertSame( wp_get_attachment_image_src( $cover, 'large' )[0], Daymark_Featured_Content_Social::image( $this->post_id )['url'] );
	}

	public function test_quote_has_no_image_but_shares_its_text_as_the_description() {
		$this->set_featured(
			'quote',
			array(
				'text'   => 'Simplicity is prerequisite for reliability.',
				'author' => 'Edsger Dijkstra',
			)
		);

		$this->assertSame( array(), Daymark_Featured_Content_Social::image( $this->post_id ) );
		$this->assertSame( '“Simplicity is prerequisite for reliability.” — Edsger Dijkstra', Daymark_Featured_Content_Social::description( $this->post_id ) );
	}

	/**
	 * A URL video's thumbnail comes from the provider's oEmbed data, but only
	 * once it has been resolved and stored — a page view never fetches.
	 */
	public function test_url_video_thumbnail_is_resolved_ahead_of_time_and_stored() {
		$this->set_featured(
			'video',
			array(
				'source' => 'url',
				'url'    => 'https://www.youtube.com/watch?v=BZtL1NVlxgQ',
			)
		);

		$this->assertSame( array(), Daymark_Featured_Content_Social::image( $this->post_id ), 'nothing before resolution' );

		$this->mock_http(
			wp_json_encode(
				array(
					'type'             => 'video',
					'version'          => '1.0',
					'html'             => '<iframe src="https://www.youtube.com/embed/BZtL1NVlxgQ"></iframe>',
					'thumbnail_url'    => 'https://i.ytimg.com/vi/BZtL1NVlxgQ/hqdefault.jpg',
					'thumbnail_width'  => 480,
					'thumbnail_height' => 360,
				)
			)
		);
		$this->social->resolve_remote_image( $this->post_id );

		$image = Daymark_Featured_Content_Social::image( $this->post_id );

		$this->assertSame( 'https://i.ytimg.com/vi/BZtL1NVlxgQ/hqdefault.jpg', $image['url'] );
		$this->assertSame( 480, $image['width'] );
		$this->assertSame( 360, $image['height'] );
	}

	/** A stored image resolved for an earlier URL is never shown for a new one. */
	public function test_stored_image_is_ignored_once_the_url_changes() {
		$this->test_url_video_thumbnail_is_resolved_ahead_of_time_and_stored();

		$this->set_featured(
			'video',
			array(
				'source' => 'url',
				'url'    => 'https://vimeo.com/76979871',
			)
		);

		$this->assertSame( array(), Daymark_Featured_Content_Social::image( $this->post_id ) );
	}

	/** A plain-http provider thumbnail is not stored as a share image. */
	public function test_http_only_thumbnail_is_rejected() {
		$this->set_featured(
			'video',
			array(
				'source' => 'url',
				'url'    => 'https://www.youtube.com/watch?v=abcdefghijk',
			)
		);
		$this->mock_http(
			wp_json_encode(
				array(
					'type'          => 'video',
					'version'       => '1.0',
					'html'          => '<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>',
					'thumbnail_url' => 'http://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg',
				)
			)
		);

		$this->social->resolve_remote_image( $this->post_id );

		$this->assertSame( '', get_post_meta( $this->post_id, Daymark_Featured_Content_Social::META_IMAGE, true ) );
	}

	public function test_link_shares_the_linked_pages_open_graph_image() {
		set_post_format( $this->post_id, 'link' );
		$this->set_featured( 'link', array( 'url' => 'https://example.com/article' ) );
		$this->mock_http(
			'<html><head><title>An article</title><meta property="og:title" content="An article"><meta property="og:image" content="https://example.com/cover.jpg"></head><body></body></html>',
			'text/html'
		);

		$this->social->resolve_remote_image( $this->post_id );

		$this->assertSame( 'https://example.com/cover.jpg', Daymark_Featured_Content_Social::image( $this->post_id )['url'] );
	}

	/** The background lookup also saves the linked page's preview for the post's own page. */
	public function test_link_resolution_saves_the_page_preview() {
		set_post_format( $this->post_id, 'link' );
		$this->set_featured( 'link', array( 'url' => 'https://example.com/article' ) );
		$this->mock_http(
			'<html><head><meta property="og:title" content="An article"><meta property="og:description" content="What it says."><meta property="og:image" content="https://example.com/cover.jpg"></head><body></body></html>',
			'text/html'
		);

		$this->assertNull( Daymark_Featured_Content_Social::link_preview( $this->post_id ), 'nothing saved before resolution' );

		$this->social->resolve_remote_image( $this->post_id );

		$this->assertSame(
			array(
				'title'       => 'An article',
				'description' => 'What it says.',
				'image'       => 'https://example.com/cover.jpg',
			),
			Daymark_Featured_Content_Social::link_preview( $this->post_id )
		);
	}

	/** A preview saved for an earlier link is never shown for a new one, and an http image is dropped. */
	public function test_saved_link_preview_follows_the_current_link_and_requires_https_images() {
		set_post_format( $this->post_id, 'link' );
		$this->set_featured( 'link', array( 'url' => 'https://example.com/old' ) );
		$fc = Daymark_Featured_Content::get_featured_content( $this->post_id );

		$saved = Daymark_Featured_Content_Social::store_link_preview(
			$this->post_id,
			$fc,
			array(
				'title' => 'Old page',
				'image' => 'http://example.com/insecure.jpg',
			)
		);
		$this->assertSame( '', $saved['image'] );
		$this->assertSame( 'Old page', Daymark_Featured_Content_Social::link_preview( $this->post_id )['title'] );

		$this->set_featured( 'link', array( 'url' => 'https://example.com/new' ) );
		$this->assertNull( Daymark_Featured_Content_Social::link_preview( $this->post_id ) );
	}

	public function test_changing_featured_content_schedules_a_resolution() {
		wp_clear_scheduled_hook( Daymark_Featured_Content_Social::CRON_HOOK, array( $this->post_id ) );
		Daymark_Plugin::instance()->featured_content_social->register();

		$this->set_featured(
			'video',
			array(
				'source' => 'url',
				'url'    => 'https://www.youtube.com/watch?v=BZtL1NVlxgQ',
			)
		);

		$this->assertNotFalse( wp_next_scheduled( Daymark_Featured_Content_Social::CRON_HOOK, array( $this->post_id ) ) );
	}

	public function test_thumbnail_url_filter_can_change_or_remove_the_image() {
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $this->image_attachment() ) ) );

		add_filter(
			'daymark_featured_content_thumbnail_url',
			static function () {
				return 'https://cdn.example.com/share.jpg';
			}
		);
		$this->assertSame( 'https://cdn.example.com/share.jpg', Daymark_Featured_Content_Social::image( $this->post_id )['url'] );

		remove_all_filters( 'daymark_featured_content_thumbnail_url' );
		add_filter( 'daymark_featured_content_thumbnail_url', '__return_empty_string' );
		$this->assertSame( array(), Daymark_Featured_Content_Social::image( $this->post_id ) );
	}

	// -- oEmbed -------------------------------------------------------------

	public function test_oembed_response_carries_the_featured_content_thumbnail() {
		$first = $this->image_attachment();
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first ) ) );

		$data = $this->social->filter_oembed_response( array( 'type' => 'rich' ), get_post( $this->post_id ) );

		$this->assertSame( wp_get_attachment_image_src( $first, 'large' )[0], $data['thumbnail_url'] );
		$this->assertArrayHasKey( 'thumbnail_width', $data );
	}

	/** Through WordPress's own oEmbed response builder, with the plugin's registered hooks. */
	public function test_core_oembed_response_includes_the_thumbnail() {
		$first = $this->image_attachment();
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first ) ) );

		$data = get_oembed_response_data( $this->post_id, 600 );

		$this->assertSame( wp_get_attachment_image_src( $first, 'large' )[0], $data['thumbnail_url'] );
	}

	/** With the replace filter off, Featured Content only fills in a missing thumbnail. */
	public function test_oembed_keeps_the_featured_image_when_not_replacing() {
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $this->image_attachment() ) ) );
		add_filter( 'daymark_featured_content_replaces_featured_image', '__return_false' );

		$data = $this->social->filter_oembed_response( array( 'thumbnail_url' => 'https://example.org/featured.jpg' ), get_post( $this->post_id ) );

		$this->assertSame( 'https://example.org/featured.jpg', $data['thumbnail_url'] );
	}

	public function test_oembed_false_passes_through() {
		$this->assertFalse( $this->social->filter_oembed_response( false, get_post( $this->post_id ) ) );
	}

	public function test_embed_card_uses_the_gallerys_first_image() {
		$first = $this->image_attachment();
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first ) ) );
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertSame( $first, $this->social->filter_embed_thumbnail_id( 0 ) );
	}

	// -- Daymark's own tags --------------------------------------------------

	public function test_prints_minimal_tags_when_no_seo_plugin_is_active() {
		$first = $this->image_attachment();
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first ) ) );

		$html = $this->head_tags();

		$this->assertStringContainsString( '<meta property="og:image" content="' . esc_url( wp_get_attachment_image_src( $first, 'large' )[0] ) . '" />', $html );
		$this->assertStringContainsString( 'property="og:title" content="A post with Featured Content"', $html );
		$this->assertStringContainsString( 'name="twitter:card" content="summary_large_image"', $html );
		$this->assertStringContainsString( 'property="og:description" content="The post excerpt."', $html );
	}

	public function test_quote_tags_use_the_quote_as_the_description() {
		$this->set_featured(
			'quote',
			array(
				'text'   => 'Simplicity is prerequisite for reliability.',
				'author' => 'Edsger Dijkstra',
			)
		);

		$html = $this->head_tags();

		$this->assertStringContainsString( 'property="og:description" content="“Simplicity is prerequisite for reliability.” — Edsger Dijkstra"', $html );
		$this->assertStringContainsString( 'name="twitter:card" content="summary"', $html );
		$this->assertStringNotContainsString( 'og:image', $html );
	}

	public function test_prints_nothing_when_an_seo_plugin_handles_the_tags() {
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $this->image_attachment() ) ) );
		remove_all_filters( 'daymark_featured_content_seo_plugin' );
		add_filter(
			'daymark_featured_content_seo_plugin',
			static function () {
				return 'yoast';
			}
		);

		$this->assertSame( '', $this->head_tags() );
	}

	public function test_prints_nothing_for_a_post_without_featured_content() {
		$this->assertSame( '', $this->head_tags() );
	}

	public function test_prints_nothing_for_a_password_protected_post() {
		wp_update_post(
			array(
				'ID'            => $this->post_id,
				'post_password' => 'secret',
			)
		);
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $this->image_attachment() ) ) );

		$this->assertSame( '', $this->head_tags() );
	}

	// -- Hand-offs -----------------------------------------------------------

	public function test_hands_the_image_to_yoast_and_rank_math_image_lists() {
		$first = $this->image_attachment();
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first ) ) );
		$this->go_to( get_permalink( $this->post_id ) );

		$container = new class() {
			/**
			 * Images added.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public $images = array();

			/**
			 * Record an image.
			 *
			 * @param array<string, mixed> $image Image.
			 * @return void
			 */
			public function add_image( $image ) {
				$this->images[] = $image;
			}
		};

		$this->social->add_yoast_image( $container );
		$this->social->add_rank_math_image( $container );

		$this->assertCount( 2, $container->images );
		$this->assertSame( wp_get_attachment_image_src( $first, 'large' )[0], $container->images[0]['url'] );
	}

	public function test_overrides_aioseo_and_jetpack_image_tags() {
		$first = $this->image_attachment();
		$url   = wp_get_attachment_image_src( $first, 'large' )[0];
		$this->set_featured( 'gallery', array( 'attachment_ids' => array( $first ) ) );
		$this->go_to( get_permalink( $this->post_id ) );

		$facebook = $this->social->filter_facebook_tags(
			array(
				'og:image'            => 'https://example.org/old.jpg',
				'og:image:secure_url' => 'https://example.org/old.jpg',
			)
		);
		$this->assertSame( $url, $facebook['og:image'] );
		$this->assertSame( $url, $facebook['og:image:secure_url'] );

		$twitter = $this->social->filter_twitter_tags( array() );
		$this->assertSame( $url, $twitter['twitter:image'] );

		$jetpack = $this->social->filter_jetpack_tags( array( 'og:title' => 'Title' ) );
		$this->assertSame( $url, $jetpack['og:image'] );
		$this->assertSame( $url, $jetpack['twitter:image'] );
		$this->assertSame( 'Title', $jetpack['og:title'] );

		$this->assertSame( $url, $this->social->filter_twitter_image_url( '' ) );
	}

	/** Off a Featured Content post, the hand-off filters change nothing. */
	public function test_hand_offs_leave_other_pages_alone() {
		$this->go_to( get_permalink( $this->post_id ) );

		$tags = array( 'og:image' => 'https://example.org/keep.jpg' );

		$this->assertSame( $tags, $this->social->filter_facebook_tags( $tags ) );
		$this->assertSame( 'https://example.org/keep.jpg', $this->social->filter_twitter_image_url( 'https://example.org/keep.jpg' ) );
	}
}
