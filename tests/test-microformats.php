<?php
/**
 * POSSE-quality outbound microformats2 markup tests (issue #78).
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Microformats' pure, public rendering methods.
 */
class Test_Microformats extends WP_UnitTestCase {

	/**
	 * Class under test.
	 *
	 * @var Daymark_Microformats
	 */
	private Daymark_Microformats $microformats;

	/**
	 * A published Mark post.
	 *
	 * @var int
	 */
	private int $daymark_id;

	/**
	 * The Mark's author.
	 *
	 * @var int
	 */
	private int $author_id;

	public function set_up(): void {
		parent::set_up();

		$this->microformats = new Daymark_Microformats();
		$this->author_id    = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->daymark_id = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);
		update_post_meta( $this->daymark_id, '_daymark_is_mark', '1' );
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'image' );
	}

	/** h_entry_class() appends h-entry for a Mark. */
	public function test_h_entry_class_appended_for_mark() {
		$classes = $this->microformats->h_entry_class( array( 'post' ), '', $this->daymark_id );

		$this->assertContains( 'h-entry', $classes );
	}

	/** h_entry_class() leaves non-Mark posts alone. */
	public function test_h_entry_class_skipped_for_non_mark() {
		$other_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$classes = $this->microformats->h_entry_class( array( 'post' ), '', $other_id );

		$this->assertNotContains( 'h-entry', $classes );
	}

	/** h_entry_class() ignores the extra $class argument WP passes as #2. */
	public function test_h_entry_class_receives_post_id_as_third_argument() {
		// Regression test: post_class's real filter signature is
		// ($classes, $class, $post_id). A callback registered with the
		// wrong arg count silently receives $class in place of $post_id
		// and throws on any post_class() call that passes extra classes
		// (e.g. a Query Loop block) — this only surfaces on a live render,
		// not a direct method call, so it's asserted explicitly here.
		$classes = $this->microformats->h_entry_class( array( 'post' ), array( 'extra-class' ), $this->daymark_id );

		$this->assertContains( 'h-entry', $classes );
	}

	/** wrap_title() emits p-name for a non-note Mark on its singular main-query page. */
	public function test_wrap_title_emits_p_name_for_non_note() {
		$this->go_to( get_permalink( $this->daymark_id ) );

		$title = '';
		while ( have_posts() ) {
			the_post();
			$title = apply_filters( 'the_title', get_the_title(), get_the_ID() );
		}

		$this->assertStringContainsString( 'class="p-name"', $title );
		$this->assertStringNotContainsString( 'p-summary', $title );
	}

	/** wrap_title() emits p-summary for a note Mark, since its title is really its caption. */
	public function test_wrap_title_emits_p_summary_for_note() {
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'note' );

		$this->go_to( get_permalink( $this->daymark_id ) );

		$title = '';
		while ( have_posts() ) {
			the_post();
			$title = apply_filters( 'the_title', get_the_title(), get_the_ID() );
		}

		$this->assertStringContainsString( 'class="p-summary"', $title );
	}

	/** append_entry_markup() wraps content in e-content on the singular main-query page. */
	public function test_content_filter_wraps_e_content_on_singular() {
		$this->go_to( get_permalink( $this->daymark_id ) );
		$this->assertTrue( is_singular() );

		$content = '';
		while ( have_posts() ) {
			the_post();
			$content = apply_filters( 'the_content', get_the_content() );
		}

		$this->assertStringContainsString( 'class="e-content"', $content );
	}

	/** entry_metadata_markup() contains u-url matching the permalink and a parseable dt-published. */
	public function test_entry_metadata_has_u_url_and_dt_published() {
		$markup    = $this->microformats->entry_metadata_markup( $this->daymark_id );
		$permalink = get_permalink( $this->daymark_id );

		$this->assertStringContainsString( 'class="u-url"', $markup );
		$this->assertStringContainsString( (string) $permalink, $markup );
		$this->assertMatchesRegularExpression( '/class="dt-published" datetime="([^"]+)"/', $markup );
	}

	/** dt-published carries a datetime PHP can parse back into a real date. */
	public function test_dt_published_datetime_is_parseable() {
		$markup = $this->microformats->entry_metadata_markup( $this->daymark_id );

		$this->assertMatchesRegularExpression( '/class="dt-published" datetime="([^"]+)"/', $markup );

		preg_match( '/class="dt-published" datetime="([^"]+)"/', $markup, $matches );
		$parsed = strtotime( $matches[1] );

		$this->assertIsInt( $parsed );
		$this->assertGreaterThan( 0, $parsed );
	}

	/** rich_media_markup() emits u-photo/u-video/u-audio for attached media of each kind. */
	public function test_rich_media_markup_covers_photo_video_audio() {
		$image_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'photo.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		$video_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'clip.mp4',
				'post_mime_type' => 'video/mp4',
				'post_type'      => 'attachment',
			)
		);
		$audio_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'track.mp3',
				'post_mime_type' => 'audio/mpeg',
				'post_type'      => 'attachment',
			)
		);

		update_post_meta(
			$this->daymark_id,
			'_daymark_media_ids',
			wp_json_encode( array( $image_id, $video_id, $audio_id ) )
		);

		$markup = $this->microformats->rich_media_markup( $this->daymark_id );

		$this->assertStringContainsString( 'class="u-photo"', $markup );
		$this->assertStringContainsString( 'class="u-video"', $markup );
		$this->assertStringContainsString( 'class="u-audio"', $markup );
	}

	/** rich_media_markup() is empty when a Mark has no attached media. */
	public function test_rich_media_markup_empty_without_media() {
		$this->assertSame( '', $this->microformats->rich_media_markup( $this->daymark_id ) );
	}

	/** render_author_hcard() emits p-author h-card, p-name, and u-photo. */
	public function test_author_hcard_has_required_properties() {
		$markup = $this->microformats->render_author_hcard( $this->author_id );

		$this->assertStringContainsString( 'class="p-author h-card"', $markup );
		$this->assertStringContainsString( 'class="p-name"', $markup );
		$this->assertStringContainsString( 'class="u-photo"', $markup );
	}

	/** render_author_hcard() adds rel=me only when the author has set the profile field. */
	public function test_author_hcard_rel_me_when_set() {
		update_user_meta( $this->author_id, Daymark_Microformats::REL_ME_META_KEY, 'https://example.com/me' );

		$markup = $this->microformats->render_author_hcard( $this->author_id );

		$this->assertStringContainsString( 'rel="me"', $markup );
		$this->assertStringContainsString( 'https://example.com/me', $markup );
	}

	/** render_author_hcard() adds no rel=me link when the profile field is unset. */
	public function test_author_hcard_no_rel_me_when_unset() {
		$markup = $this->microformats->render_author_hcard( $this->author_id );

		$this->assertStringNotContainsString( 'rel="me"', $markup );
	}

	/** No u-email ever renders — a deliberate privacy decision, not an oversight. */
	public function test_author_hcard_has_no_u_email() {
		$markup = $this->microformats->render_author_hcard( $this->author_id );

		$this->assertStringNotContainsString( 'u-email', $markup );
	}

	/** location_markup() emits p-geo/h-geo when _daymark_location is present. */
	public function test_location_markup_present_when_location_set() {
		update_post_meta(
			$this->daymark_id,
			'_daymark_location',
			wp_json_encode(
				array(
					'lat'      => 40.7128,
					'lng'      => -74.006,
					'accuracy' => 15.5,
				)
			)
		);

		$markup = $this->microformats->location_markup( $this->daymark_id );

		$this->assertStringContainsString( 'class="p-geo h-geo"', $markup );
		$this->assertStringContainsString( 'class="p-latitude"', $markup );
		$this->assertStringContainsString( 'class="p-longitude"', $markup );
		$this->assertStringContainsString( '40.7128', $markup );
		$this->assertStringContainsString( '-74.006', $markup );
	}

	/** location_markup() is empty when a Mark carries no location meta. */
	public function test_location_markup_absent_without_location() {
		$this->assertSame( '', $this->microformats->location_markup( $this->daymark_id ) );
	}

	/**
	 * entry_metadata_markup() never includes location markup by default,
	 * even when a Mark has a captured location — publishing exact
	 * device-GPS coordinates on a public, search-indexable page is a
	 * separate decision from quietly capturing the location for the
	 * authenticated app's own use (Timeline card, REST summary). See the
	 * `daymark_publish_location_publicly` filter.
	 */
	public function test_entry_metadata_omits_location_markup_by_default() {
		update_post_meta(
			$this->daymark_id,
			'_daymark_location',
			wp_json_encode(
				array(
					'lat' => 51.5074,
					'lng' => -0.1278,
				)
			)
		);

		$markup = $this->microformats->entry_metadata_markup( $this->daymark_id );

		$this->assertStringNotContainsString( 'p-geo', $markup );
	}

	/** The daymark_publish_location_publicly filter opts a site in to public location markup. */
	public function test_entry_metadata_includes_location_markup_when_filter_enabled() {
		update_post_meta(
			$this->daymark_id,
			'_daymark_location',
			wp_json_encode(
				array(
					'lat' => 51.5074,
					'lng' => -0.1278,
				)
			)
		);

		add_filter( 'daymark_publish_location_publicly', '__return_true' );
		$markup = $this->microformats->entry_metadata_markup( $this->daymark_id );
		remove_filter( 'daymark_publish_location_publicly', '__return_true' );

		$this->assertStringContainsString( 'class="p-geo h-geo"', $markup );
	}
}
