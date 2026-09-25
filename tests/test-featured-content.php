<?php
/**
 * Daymark_Featured_Content tests (issue #401, Phase 3: audio/video + quote/link):
 * meta sanitization (including the quote/link shapes, whose empty results are
 * dropped whole), the link post-format read gate in get_featured_content(),
 * the post_thumbnail_html substitution (and its opt-out filter), the theme-facing
 * daymark_*_featured_content() template tags, render_audio()/render_video()'s
 * is_direct_media_url()-gated native-tag fallback, render_quote()/render_link()'s
 * markup, and the GET /daymark/v1/featured-content/oembed REST route.
 *
 * @package Daymark
 */

// get_current_screen()/set_current_screen() live in wp-admin/includes/screen.php,
// which the plain WP PHPUnit bootstrap never loads (it's only pulled in by a
// real wp-admin/admin.php request) — the same reason test-admin-post-format-icon.php
// conditionally requires it.
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

/**
 * Exercises Daymark_Featured_Content and its REST route.
 */
class Test_Featured_Content extends WP_UnitTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	/**
	 * A fake attachment with a real, resolvable mime type — `wp_attachment_is()`
	 * needs `get_attached_file()` to return something truthy, which the test
	 * factory's `create_object()` already sets via `_wp_attached_file`, with
	 * no real file on disk required.
	 *
	 * @param string $mime_type e.g. 'audio/mpeg', 'video/mp4', 'image/png'.
	 * @return int
	 */
	private function create_attachment( string $mime_type ): int {
		return (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'test.' . ( 'audio/mpeg' === $mime_type ? 'mp3' : ( 'video/mp4' === $mime_type ? 'mp4' : 'png' ) ),
				'post_parent'    => 0,
				'post_mime_type' => $mime_type,
				'post_type'      => 'attachment',
			)
		);
	}

	// -- sanitize_type() -----------------------------------------------

	public function test_sanitize_type_accepts_allowed_values() {
		$this->assertSame( 'audio', Daymark_Featured_Content::sanitize_type( 'audio' ) );
		$this->assertSame( 'video', Daymark_Featured_Content::sanitize_type( 'video' ) );
		$this->assertSame( 'quote', Daymark_Featured_Content::sanitize_type( 'quote' ) );
		$this->assertSame( 'link', Daymark_Featured_Content::sanitize_type( 'link' ) );
	}

	public function test_sanitize_type_rejects_unsupported_or_garbage_values() {
		$this->assertSame( '', Daymark_Featured_Content::sanitize_type( 'gallery' ) );
		$this->assertSame( '', Daymark_Featured_Content::sanitize_type( '<script>' ) );
		$this->assertSame( '', Daymark_Featured_Content::sanitize_type( '' ) );
	}

	// -- sanitize_data() -------------------------------------------------

	public function test_sanitize_data_keeps_a_valid_library_audio_shape() {
		$attachment_id = $this->create_attachment( 'audio/mpeg' );
		$encoded       = wp_json_encode(
			array(
				'audio' => array(
					'source'        => 'library',
					'attachment_id' => $attachment_id,
				),
			)
		);

		$clean = json_decode( Daymark_Featured_Content::sanitize_data( $encoded ), true );

		$this->assertSame(
			array(
				'source'        => 'library',
				'attachment_id' => $attachment_id,
			),
			$clean['audio']
		);
	}

	/** An attachment ID whose mime type isn't audio is never trusted as an audio source. */
	public function test_sanitize_data_rejects_mismatched_mime_type() {
		$image_id = $this->create_attachment( 'image/png' );
		$encoded  = wp_json_encode(
			array(
				'audio' => array(
					'source'        => 'library',
					'attachment_id' => $image_id,
				),
			)
		);

		$clean = json_decode( Daymark_Featured_Content::sanitize_data( $encoded ), true );

		$this->assertArrayNotHasKey( 'audio', $clean );
	}

	/** A video attachment ID sent under the video sub-key is validated against the video mime family, not audio's. */
	public function test_sanitize_data_validates_video_against_its_own_mime_family() {
		$video_id = $this->create_attachment( 'video/mp4' );
		$audio_id = $this->create_attachment( 'audio/mpeg' );

		$clean = json_decode(
			Daymark_Featured_Content::sanitize_data(
				wp_json_encode(
					array(
						'video' => array(
							'source'        => 'library',
							'attachment_id' => $video_id,
						),
						// A stray audio ID under the video key must be rejected, not the reverse mistake of accepting it.
					)
				)
			),
			true
		);
		$this->assertSame( $video_id, $clean['video']['attachment_id'] );

		$rejected = json_decode(
			Daymark_Featured_Content::sanitize_data(
				wp_json_encode(
					array(
						'video' => array(
							'source'        => 'library',
							'attachment_id' => $audio_id,
						),
					)
				)
			),
			true
		);
		$this->assertArrayNotHasKey( 'video', $rejected );
	}

	public function test_sanitize_data_keeps_a_valid_url_shape() {
		$encoded = wp_json_encode(
			array(
				'audio' => array(
					'source' => 'url',
					'url'    => 'https://example.com/episode.mp3',
				),
			)
		);

		$clean = json_decode( Daymark_Featured_Content::sanitize_data( $encoded ), true );

		$this->assertSame(
			array(
				'source' => 'url',
				'url'    => 'https://example.com/episode.mp3',
			),
			$clean['audio']
		);
	}

	public function test_sanitize_data_keeps_a_valid_quote_shape() {
		$clean = json_decode(
			Daymark_Featured_Content::sanitize_data(
				wp_json_encode(
					array(
						'quote' => array(
							'text'         => 'An insightful line worth quoting.',
							'author'       => 'Some Author',
							'citation_url' => 'https://example.com/source',
						),
					)
				)
			),
			true
		);

		$this->assertSame(
			array(
				'text'         => 'An insightful line worth quoting.',
				'author'       => 'Some Author',
				'citation_url' => 'https://example.com/source',
			),
			$clean['quote']
		);
	}

	public function test_sanitize_data_drops_a_textless_quote_shape() {
		$clean = json_decode(
			Daymark_Featured_Content::sanitize_data( wp_json_encode( array( 'quote' => array( 'text' => '   ' ) ) ) ),
			true
		);

		$this->assertArrayNotHasKey( 'quote', $clean );
	}

	public function test_sanitize_data_keeps_a_valid_link_shape() {
		$clean = json_decode(
			Daymark_Featured_Content::sanitize_data( wp_json_encode( array( 'link' => array( 'url' => 'https://example.com/article' ) ) ) ),
			true
		);

		$this->assertSame( array( 'url' => 'https://example.com/article' ), $clean['link'] );
	}

	public function test_sanitize_data_drops_a_javascript_url_link_shape() {
		$clean = json_decode(
			Daymark_Featured_Content::sanitize_data( wp_json_encode( array( 'link' => array( 'url' => 'javascript:alert(1)' ) ) ) ),
			true
		);

		$this->assertArrayNotHasKey( 'link', $clean );
	}

	/** A gallery sub-key isn't implemented yet (issue #406) — silently dropped, not stored unsanitized. */
	public function test_sanitize_data_drops_unrecognized_sub_keys() {
		$clean = json_decode(
			Daymark_Featured_Content::sanitize_data( wp_json_encode( array( 'gallery' => array( 'attachment_ids' => array( 1, 2 ) ) ) ) ),
			true
		);

		$this->assertSame( array(), $clean );
	}

	public function test_sanitize_data_handles_malformed_json() {
		$this->assertSame( '{}', Daymark_Featured_Content::sanitize_data( 'not json' ) );
	}

	// -- get_featured_content()/has_featured_content() -------------------

	public function test_get_featured_content_is_empty_when_unset() {
		$this->assertSame( array(), Daymark_Featured_Content::get_featured_content( $this->post_id ) );
		$this->assertFalse( Daymark_Featured_Content::has_featured_content( $this->post_id ) );
	}

	public function test_get_featured_content_resolves_the_paired_type_and_data() {
		$attachment_id = $this->create_attachment( 'audio/mpeg' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'audio' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'audio' => array(
						'source'        => 'library',
						'attachment_id' => $attachment_id,
					),
				)
			)
		);

		$fc = Daymark_Featured_Content::get_featured_content( $this->post_id );

		$this->assertSame( 'audio', $fc['type'] );
		$this->assertSame( $attachment_id, $fc['data']['attachment_id'] );
		$this->assertTrue( Daymark_Featured_Content::has_featured_content( $this->post_id ) );
	}

	/** The type meta says "video" but no video data was ever actually saved — resolves to empty, not a half-formed result. */
	public function test_get_featured_content_is_empty_when_type_set_but_data_missing() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'video' );

		$this->assertSame( array(), Daymark_Featured_Content::get_featured_content( $this->post_id ) );
	}

	/** A link-type Featured Content is read-gated to empty without the `link` post format — the same "real, link-format-only" rule render() itself depends on. */
	public function test_get_featured_content_is_empty_for_a_link_type_without_the_link_format() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'link' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_DATA, wp_json_encode( array( 'link' => array( 'url' => 'https://example.com/article' ) ) ) );

		$this->assertSame( array(), Daymark_Featured_Content::get_featured_content( $this->post_id ) );
	}

	public function test_get_featured_content_resolves_a_link_type_on_a_link_format_post() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'link' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_DATA, wp_json_encode( array( 'link' => array( 'url' => 'https://example.com/article' ) ) ) );
		wp_set_object_terms( $this->post_id, 'post-format-link', 'post_format' );

		$featured_content = Daymark_Featured_Content::get_featured_content( $this->post_id );

		$this->assertSame( 'link', $featured_content['type'] );
		$this->assertSame( 'https://example.com/article', $featured_content['data']['url'] );
	}

	// -- theme-facing daymark_*_featured_content() template tags ----------

	public function test_global_template_tags_delegate_to_the_class() {
		$attachment_id = $this->create_attachment( 'video/mp4' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'video' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'video' => array(
						'source'        => 'library',
						'attachment_id' => $attachment_id,
					),
				)
			)
		);

		$this->assertTrue( daymark_has_featured_content( $this->post_id ) );
		$this->assertSame( 'video', daymark_get_featured_content( $this->post_id )['type'] );

		ob_start();
		daymark_the_featured_content( $this->post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'daymark-featured-content--video', $output );
	}

	// -- post_thumbnail_html substitution ---------------------------------

	public function test_post_thumbnail_html_is_replaced_on_the_front_end_when_set() {
		$attachment_id = $this->create_attachment( 'audio/mpeg' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'audio' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'audio' => array(
						'source'        => 'library',
						'attachment_id' => $attachment_id,
					),
				)
			)
		);

		$featured_content = new Daymark_Featured_Content();
		$replaced         = $featured_content->maybe_replace_post_thumbnail_html( '<img src="fallback.jpg">', $this->post_id, 0, 'thumbnail', '' );

		$this->assertStringContainsString( 'daymark-featured-content', $replaced );
		$this->assertStringContainsString( 'daymark-featured-content--audio', $replaced );
	}

	public function test_post_thumbnail_html_is_untouched_when_nothing_set() {
		$featured_content = new Daymark_Featured_Content();
		$original         = '<img src="fallback.jpg">';

		$this->assertSame( $original, $featured_content->maybe_replace_post_thumbnail_html( $original, $this->post_id, 0, 'thumbnail', '' ) );
	}

	public function test_post_thumbnail_html_substitution_can_be_disabled_via_filter() {
		$attachment_id = $this->create_attachment( 'audio/mpeg' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'audio' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'audio' => array(
						'source'        => 'library',
						'attachment_id' => $attachment_id,
					),
				)
			)
		);

		add_filter( 'daymark_featured_content_replaces_featured_image', '__return_false' );

		$featured_content = new Daymark_Featured_Content();
		$original         = '<img src="fallback.jpg">';

		$this->assertSame( $original, $featured_content->maybe_replace_post_thumbnail_html( $original, $this->post_id, 0, 'thumbnail', '' ) );

		remove_filter( 'daymark_featured_content_replaces_featured_image', '__return_false' );
	}

	// -- render_audio()/render_video() direct-media-URL fallback gating ---
	//
	// A provider *page* URL (a Vimeo/YouTube watch page, a podcast episode
	// page with no file extension) can never be played by a plain native
	// <audio>/<video src> element — only a genuine direct media file URL
	// can. Forcing oEmbed resolution to fail (the same `pre_oembed_result`
	// technique the REST oembed tests below already use) exercises exactly
	// the fallback branch is_direct_media_url() gates.

	public function test_video_url_with_no_oembed_and_no_file_extension_renders_nothing() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'video' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'video' => array(
						'source' => 'url',
						'url'    => 'https://vimeo.com/1070507470/3ef796e429',
					),
				)
			)
		);

		add_filter( 'pre_oembed_result', '__return_false' );

		ob_start();
		Daymark_Featured_Content::the_featured_content( $this->post_id );
		$output = ob_get_clean();

		remove_filter( 'pre_oembed_result', '__return_false' );

		// Never a broken <video src="https://vimeo.com/..."> tag the browser
		// can't decode — and never the outer wrapper either, since render()
		// only emits it once render_video() actually returned markup.
		$this->assertStringNotContainsString( '<video', $output );
		$this->assertSame( '', $output );
	}

	public function test_audio_url_with_no_oembed_and_a_direct_file_extension_falls_back_to_native_audio() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'audio' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'audio' => array(
						'source' => 'url',
						'url'    => 'https://example.com/episode.mp3',
					),
				)
			)
		);

		add_filter( 'pre_oembed_result', '__return_false' );

		ob_start();
		Daymark_Featured_Content::the_featured_content( $this->post_id );
		$output = ob_get_clean();

		remove_filter( 'pre_oembed_result', '__return_false' );

		// A direct .mp3 URL genuinely can be played by a native <audio> tag,
		// so this is the one case the shortcode fallback should still fire.
		$this->assertStringContainsString( '<audio', $output );
		$this->assertStringContainsString( 'episode.mp3', $output );
	}

	public function test_the_featured_content_renders_a_quote() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'quote' );
		update_post_meta(
			$this->post_id,
			Daymark_Featured_Content::META_DATA,
			wp_json_encode(
				array(
					'quote' => array(
						'text'         => 'An insightful line worth quoting.',
						'author'       => 'Some Author',
						'citation_url' => 'https://example.com/source',
					),
				)
			)
		);

		ob_start();
		Daymark_Featured_Content::the_featured_content( $this->post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'daymark-featured-content--quote', $output );
		$this->assertStringContainsString( '<blockquote class="daymark-featured-quote">', $output );
		$this->assertStringContainsString( '<p>An insightful line worth quoting.</p>', $output );
		$this->assertStringContainsString( '<footer>', $output );
		$this->assertStringContainsString( 'Some Author — ', $output );
		$this->assertStringContainsString( 'https://example.com/source', $output );
	}

	public function test_the_featured_content_renders_a_link() {
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_TYPE, 'link' );
		update_post_meta( $this->post_id, Daymark_Featured_Content::META_DATA, wp_json_encode( array( 'link' => array( 'url' => 'https://example.com/article' ) ) ) );
		wp_set_object_terms( $this->post_id, 'post-format-link', 'post_format' );

		ob_start();
		Daymark_Featured_Content::the_featured_content( $this->post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'daymark-featured-content--link', $output );
		$this->assertStringContainsString( '<a class="daymark-featured-link" href="https://example.com/article" target="_blank" rel="noopener">example.com</a>', $output );
	}

	// wp-admin's own callers of this same core filter (is_admin()) are left
	// untested directly, matching Daymark_Like_Visibility's own established
	// precedent for the identical is_admin()-gated branch shape: faking
	// is_admin() true mid-test-run means defining the WP_ADMIN constant,
	// which is process-wide and can't be cleanly undone for later tests.

	// -- supported_post_types() -------------------------------------------

	public function test_supported_post_types_includes_post() {
		$this->assertContains( 'post', Daymark_Featured_Content::supported_post_types() );
	}

	/** daymark_sub_post supports 'thumbnail' but has no block-editor UI at all — excluded without a special case. */
	public function test_supported_post_types_excludes_the_subscription_post_cpt() {
		$this->assertNotContains( Daymark_Subscription_Post_Type::POST_TYPE, Daymark_Featured_Content::supported_post_types() );
	}

	public function test_supported_post_types_is_filterable() {
		add_filter( 'daymark_featured_content_supported_post_types', array( $this, 'filter_supported_post_types' ) );

		$this->assertSame( array( 'page' ), Daymark_Featured_Content::supported_post_types() );

		remove_filter( 'daymark_featured_content_supported_post_types', array( $this, 'filter_supported_post_types' ) );
	}

	/**
	 * @return string[]
	 */
	public function filter_supported_post_types(): array {
		return array( 'page' );
	}

	// -- REST: GET /featured-content/oembed --------------------------------

	public function test_rest_oembed_route_returns_null_embed_for_unresolvable_url() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		add_filter( 'pre_oembed_result', '__return_false' );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/featured-content/oembed' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'url', 'https://example.com/not-embeddable' );

		$response = rest_do_request( $request );

		remove_filter( 'pre_oembed_result', '__return_false' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['embed'] );
	}

	public function test_rest_oembed_route_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/featured-content/oembed' );
		$request->set_param( 'url', 'https://example.com/episode' );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_rest_oembed_route_returns_null_embed_for_blank_url() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/featured-content/oembed' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'url', '' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['embed'] );
	}
}
