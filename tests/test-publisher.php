<?php
/**
 * Publisher tests — E2E scenarios 2, 3, 5 (post creation, metadata, overrides).
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Publisher and plugin activation basics.
 */
class Test_Publisher extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
	}

	/** Scenario: plugin activates without fatals. */
	public function test_plugin_loads() {
		$this->assertTrue( class_exists( 'Daymark_Plugin' ) );
	}

	/** Scenario: REST namespace registered. */
	public function test_rest_namespace_registered() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/daymark/v1', $routes );
		$this->assertArrayHasKey( '/daymark/v1/marks', $routes );
		$this->assertArrayHasKey( '/daymark/v1/ai/suggestions', $routes );
		$this->assertArrayHasKey( '/daymark/v1/notifications', $routes );
	}

	/** Scenario 3: note Mark creates a standard post with full metadata. */
	public function test_creates_standard_note_post() {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Test caption',
				'primary_type' => 'note',
			)
		);

		$this->assertIsInt( $post_id );
		$post = get_post( $post_id );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( '1', get_post_meta( $post_id, '_daymark_is_mark', true ) );
		$this->assertEquals( 'note', get_post_meta( $post_id, '_daymark_primary_type', true ) );
		$this->assertEquals( 'mobile', get_post_meta( $post_id, '_daymark_created_from', true ) );
	}

	/**
	 * A long CJK caption has no spaces, so wp_trim_words()'s normal 8-word
	 * limit treats it as a single "word" and never trims it (issue #74).
	 * The character-count backstop must still keep the title short and
	 * multibyte-safe.
	 */
	public function test_generated_title_trims_long_space_less_caption() {
		$caption = '今日は素晴らしい天気なので散歩に出かけます。新しい景色を見ながら写真を撮り、友人と楽しい時間を過ごしました。帰宅してから温かいお茶を飲みました。';

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => $caption,
				'primary_type' => 'note',
			)
		);

		$title = get_the_title( $post_id );
		$this->assertLessThanOrEqual(
			Daymark_Publisher::MAX_TITLE_CHARS + 1, // +1 for the appended ellipsis.
			mb_strlen( $title ),
			'Title must be trimmed even without word breaks'
		);
		$this->assertStringEndsWith( '…', $title );
		// The trim splits on whole UTF-8 codepoints, never mid-character.
		$this->assertSame( $title, wp_check_invalid_utf8( $title ) );
	}

	/** A normal space-delimited caption is unaffected by the character cap. */
	public function test_generated_title_unaffected_for_normal_caption() {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'A short caption that is well under the character cap threshold',
				'primary_type' => 'note',
			)
		);

		// Standard wp_trim_words() 8-word behavior, untouched by the new cap.
		$this->assertSame( 'A short caption that is well under the…', get_the_title( $post_id ) );
	}

	/** The character cap is filterable, matching other Daymark_Publisher limits. */
	public function test_title_max_chars_is_filterable() {
		$filter = static function () {
			return 10;
		};
		add_filter( 'daymark_title_max_chars', $filter );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'This caption is much longer than ten characters',
				'primary_type' => 'note',
			)
		);

		remove_filter( 'daymark_title_max_chars', $filter );

		$this->assertLessThanOrEqual( 11, mb_strlen( get_the_title( $post_id ) ) );
	}

	/** Each Mark type gets the matching post format, not the site default. */
	public function test_post_format_matches_type() {
		// Simulate a site whose default post format is Aside.
		update_option( 'default_post_format', 'aside' );

		$publisher = new Daymark_Publisher();
		$cases     = array(
			'note'  => 'aside',
			'mixed' => false, // Standard: no format term.
		);

		foreach ( $cases as $type => $expected ) {
			$post_id = $publisher->publish(
				array(
					'caption'      => "Format check {$type}",
					'primary_type' => $type,
				)
			);

			$label = false === $expected ? 'false (standard)' : (string) $expected;
			$this->assertSame( $expected, get_post_format( $post_id ), "Type {$type} should map to post format {$label}" );
		}
	}

	/** An image Mark lands in the image format even when it starts as a note draft. */
	public function test_post_format_updates_on_type_change() {
		update_option( 'default_post_format', 'aside' );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'Starts as a note',
				'primary_type' => 'note',
			)
		);
		$this->assertSame( 'aside', get_post_format( $post_id ) );

		// Re-classify as an image via update (mirrors adding media on edit).
		$publisher->update(
			$post_id,
			array(
				'caption'      => 'Now an image',
				'primary_type' => 'image',
			)
		);
		$this->assertSame( 'image', get_post_format( $post_id ) );
	}

	/**
	 * A transcript (AI-generated or hand-typed) is stored on the Mark and
	 * carried through an edit, matching how _daymark_caption already
	 * behaves. Not gated to audio/video at the storage layer — the
	 * composer decides when the field is shown; the publisher just stores
	 * whatever it's given.
	 */
	public function test_publish_and_update_store_transcript_meta() {
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'A podcast episode',
				'primary_type' => 'audio',
				'transcript'   => 'Welcome to the show.',
			)
		);

		$this->assertSame( 'Welcome to the show.', get_post_meta( $post_id, '_daymark_transcript', true ) );

		$publisher->update(
			$post_id,
			array(
				'caption'      => 'A podcast episode',
				'primary_type' => 'audio',
				'transcript'   => 'Welcome to the show. Today we discuss testing.',
			)
		);

		$this->assertSame(
			'Welcome to the show. Today we discuss testing.',
			get_post_meta( $post_id, '_daymark_transcript', true )
		);
	}

	/** A transcript longer than MAX_TRANSCRIPT_CHARS is truncated, not rejected. */
	public function test_transcript_is_truncated_to_max_chars() {
		$publisher = new Daymark_Publisher();
		$long      = str_repeat( 'a', Daymark_Publisher::MAX_TRANSCRIPT_CHARS + 500 );
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'Long transcript',
				'primary_type' => 'audio',
				'transcript'   => $long,
			)
		);

		$this->assertSame(
			Daymark_Publisher::MAX_TRANSCRIPT_CHARS,
			mb_strlen( get_post_meta( $post_id, '_daymark_transcript', true ) )
		);
	}

	/** A Mark with no media and no caption is rejected. */
	public function test_empty_daymark_rejected() {
		$publisher = new Daymark_Publisher();
		$result    = $publisher->publish( array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'daymark_empty', $result->get_error_code() );
	}

	/** A file larger than MAX_FILE_BYTES is rejected before sideloading. */
	public function test_oversized_file_rejected() {
		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';

		$publisher = new Daymark_Publisher();
		$result    = $publisher->publish(
			array( 'caption' => 'Too big to publish' ),
			array(
				'files' => array(
					'name'     => 'huge.png',
					'type'     => 'image/png',
					'tmp_name' => $fixture,
					'error'    => UPLOAD_ERR_OK,
					// One byte over the cap; the real fixture stays small on disk.
					'size'     => Daymark_Publisher::MAX_FILE_BYTES + 1,
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'daymark_upload_too_large', $result->get_error_code() );
	}

	/** A file at exactly MAX_FILE_BYTES is accepted (boundary is inclusive). */
	public function test_file_at_size_cap_accepted() {
		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';
		$tmp     = wp_tempnam( 'daymark-size-' ) . '.png';
		copy( $fixture, $tmp );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array( 'caption' => 'Right at the cap' ),
			array(
				'files' => array(
					'name'     => 'atcap.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => Daymark_Publisher::MAX_FILE_BYTES,
				),
			)
		);

		$this->assertIsInt( $post_id );
	}

	/** The per-file cap is filterable independently of the total budget. */
	public function test_per_file_cap_is_filterable() {
		add_filter( 'daymark_upload_max_bytes', '__return_zero' );

		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';

		$publisher = new Daymark_Publisher();
		$result    = $publisher->publish(
			array( 'caption' => 'Filtered per-file cap' ),
			array(
				'files' => array(
					'name'     => 'small.png',
					'type'     => 'image/png',
					'tmp_name' => $fixture,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $fixture ), // Well under the real default; only the filter makes this fail.
				),
			)
		);

		remove_filter( 'daymark_upload_max_bytes', '__return_zero' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'daymark_upload_too_large', $result->get_error_code() );
	}

	/** A per-request total byte budget stops many files from bypassing the per-file cap. */
	public function test_combined_upload_honors_total_budget() {
		add_filter( 'daymark_upload_total_max_bytes', '__return_zero' );

		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';

		$publisher = new Daymark_Publisher();
		$result    = $publisher->publish(
			array( 'caption' => 'Over budget' ),
			array(
				'files' => array(
					'name'     => array( 'one.png', 'two.png' ),
					'type'     => array( 'image/png', 'image/png' ),
					'tmp_name' => array( $fixture, $fixture ),
					'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_OK ),
					'size'     => array( filesize( $fixture ), filesize( $fixture ) ),
				),
			)
		);

		remove_filter( 'daymark_upload_total_max_bytes', '__return_zero' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'daymark_upload_total_too_large', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/** A single file under the total budget still uploads fine. */
	public function test_combined_upload_within_budget_passes() {
		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';
		$tmp     = wp_tempnam( 'daymark-budget-' ) . '.png';
		copy( $fixture, $tmp );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array( 'caption' => 'Under budget' ),
			array(
				'files' => array(
					'name'     => 'under.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				),
			)
		);

		$this->assertIsInt( $post_id );
		$media_ids = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );
		$this->assertCount( 1, (array) $media_ids );
	}

	/** Unauthenticated REST create is refused with 401. */
	public function test_unauthenticated_rest_create_returns_401() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/daymark/v1/marks' );
		$request->set_param( 'caption', 'nope' );
		$response = rest_do_request( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Scenario 4: with no selection sent, the model default (note → bluesky)
	 * is recorded, but auto-applied targets are filtered to CONNECTED
	 * connectors — with none configured, nothing is targeted.
	 */
	public function test_note_defaults_recorded_but_only_connected_targets_applied() {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Default routing note',
				'primary_type' => 'note',
			)
		);

		$defaults = json_decode( (string) get_post_meta( $post_id, '_daymark_default_destinations', true ), true );
		$this->assertContains( 'bluesky', $defaults, 'Model default should be recorded.' );

		$targets = json_decode( (string) get_post_meta( $post_id, '_daymark_syndication_targets', true ), true );
		$this->assertSame( array(), $targets, 'Unconnected defaults must not be auto-targeted.' );
		$this->assertSame( 'not_attempted', get_post_meta( $post_id, '_daymark_syndication_status', true ) );

		// An explicit selection is honored as-is, mocked or not.
		$explicit_id = $publisher->publish(
			array(
				'caption'             => 'Explicit routing note',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'bluesky' ),
			)
		);

		$explicit_targets = json_decode( (string) get_post_meta( $explicit_id, '_daymark_syndication_targets', true ), true );
		$this->assertContains( 'bluesky', $explicit_targets );
	}

	/**
	 * Destination memory: an explicit selection for a Mark type becomes
	 * that type's preselection next time (per user), including an explicit
	 * empty selection; types never published keep the model defaults.
	 */
	public function test_destination_selection_remembered_per_type() {
		$publisher = new Daymark_Publisher();

		// Explicit choice for notes is remembered.
		$publisher->publish(
			array(
				'caption'             => 'Remember me',
				'primary_type'        => 'note',
				'syndication_targets' => array( 'mastodon', 'x' ),
			)
		);

		$this->assertSame( array( 'mastodon', 'x' ), $publisher->get_effective_defaults( 'note' ) );

		// Explicit "none" is a real preference, remembered too.
		$publisher->publish(
			array(
				'caption'             => 'None for notes now',
				'primary_type'        => 'note',
				'syndication_targets' => array(),
			)
		);

		$this->assertSame( array(), $publisher->get_effective_defaults( 'note' ) );

		// A type never explicitly published keeps the model default.
		$this->assertSame( array( 'instagram' ), $publisher->get_effective_defaults( 'image' ) );

		// Prefs are per user: a different user still gets model defaults.
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertSame( array( 'bluesky' ), $publisher->get_effective_defaults( 'note', $other ) );
	}

	/** Scenario 5: explicit empty selection overrides defaults. */
	public function test_explicit_empty_targets_respected() {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'             => 'Override note',
				'primary_type'        => 'note',
				'syndication_targets' => array(),
			)
		);

		$targets = json_decode( (string) get_post_meta( $post_id, '_daymark_syndication_targets', true ), true );
		$this->assertSame( array(), $targets );

		// Defaults remain stored for future Marks.
		$defaults = json_decode( (string) get_post_meta( $post_id, '_daymark_default_destinations', true ), true );
		$this->assertContains( 'bluesky', $defaults );
	}

	// --- Quiet mark metadata capture ---

	/**
	 * Blocks any real network request to api.open-meteo.com for the
	 * duration of a test that resolves a location but doesn't itself care
	 * about the weather result — publish() always attempts a best-effort
	 * weather fetch once a location resolves, and this keeps that from
	 * ever reaching the real network during a test run.
	 *
	 * @return Closure The installed filter, for remove_filter().
	 */
	private function block_weather_requests(): Closure {
		$filter = static function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false !== strpos( $url, 'api.open-meteo.com' ) ) {
				return new WP_Error( 'daymark_test_http_blocked', 'Weather fetch blocked in test.' );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		return $filter;
	}

	/** A valid, past client-supplied captured_at becomes the Mark's post_date. */
	public function test_captured_at_client_value_used_when_valid() {
		$publisher = new Daymark_Publisher();
		$captured  = gmdate( 'c', strtotime( '-2 days' ) );
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'Captured two days ago',
				'primary_type' => 'note',
				'captured_at'  => $captured,
			)
		);

		$expected = gmdate( 'Y-m-d H:i:s', strtotime( $captured ) );
		$this->assertSame( $expected, get_post_meta( $post_id, '_daymark_captured_at', true ) );
		$this->assertSame( $expected, get_post( $post_id )->post_date_gmt );
	}

	/**
	 * A captured_at further in the future than the clock-skew tolerance is
	 * ignored (not an error) — the Mark still publishes, falling through to
	 * the ordinary "now" default since there's no EXIF signal either.
	 */
	public function test_captured_at_future_value_ignored() {
		$publisher = new Daymark_Publisher();
		$future    = gmdate( 'c', time() + 2 * DAY_IN_SECONDS );
		$before    = time();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'Future timestamp should be ignored',
				'primary_type' => 'note',
				'captured_at'  => $future,
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_captured_at', true ) );

		$post_date_gmt = strtotime( get_post( $post_id )->post_date_gmt . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 5, $post_date_gmt );
		$this->assertLessThanOrEqual( time() + 5, $post_date_gmt );
	}

	/**
	 * With no client captured_at, an image's EXIF created_timestamp is used
	 * as the capture-time fallback.
	 */
	public function test_captured_at_exif_fallback_used_without_client_value() {
		$exif_ts = strtotime( '2024-05-01 10:00:00 UTC' );
		$filter  = static function ( $metadata ) use ( $exif_ts ) {
			$metadata['image_meta'] = array( 'created_timestamp' => $exif_ts );
			return $metadata;
		};
		add_filter( 'wp_generate_attachment_metadata', $filter );

		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';
		$tmp     = wp_tempnam( 'daymark-exif-' ) . '.png';
		copy( $fixture, $tmp );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array( 'caption' => 'EXIF capture time' ),
			array(
				'files' => array(
					'name'     => 'exif.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				),
			)
		);

		remove_filter( 'wp_generate_attachment_metadata', $filter );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', $exif_ts ), get_post_meta( $post_id, '_daymark_captured_at', true ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $exif_ts ), get_post( $post_id )->post_date_gmt );
	}

	/** With neither a client value nor EXIF, behavior is unchanged: "now". */
	public function test_captured_at_defaults_to_now_without_any_signal() {
		$publisher = new Daymark_Publisher();
		$before    = time();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'No capture signal at all',
				'primary_type' => 'note',
			)
		);

		$this->assertSame( '', get_post_meta( $post_id, '_daymark_captured_at', true ) );
		$post_date_gmt = strtotime( get_post( $post_id )->post_date_gmt . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 5, $post_date_gmt );
		$this->assertLessThanOrEqual( time() + 5, $post_date_gmt );
	}

	/** A valid lat/lng is stored as _daymark_location. */
	public function test_valid_location_stored() {
		$filter    = $this->block_weather_requests();
		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'           => 'With location',
				'primary_type'      => 'note',
				'location_lat'      => 40.7128,
				'location_lng'      => -74.006,
				'location_accuracy' => 15.5,
			)
		);
		remove_filter( 'pre_http_request', $filter, 10 );

		$location = json_decode( (string) get_post_meta( $post_id, '_daymark_location', true ), true );
		$this->assertSame( 40.7128, $location['lat'] );
		$this->assertSame( -74.006, $location['lng'] );
		$this->assertSame( 15.5, $location['accuracy'] );
	}

	/** An out-of-range lat/lng is silently dropped, never stored, never an error. */
	public function test_out_of_range_location_silently_dropped() {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Bad location',
				'primary_type' => 'note',
				'location_lat' => 200,
				'location_lng' => -74.006,
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_location', true ) );
	}

	/** A long caption/transcript gets a reading-time estimate stored. */
	public function test_long_caption_gets_reading_time_meta() {
		$publisher = new Daymark_Publisher();
		$caption   = implode( ' ', array_fill( 0, 500, 'word' ) );
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => $caption,
				'primary_type' => 'note',
			)
		);

		$expected = max( 1, (int) round( 500 / Daymark_Publisher::WORDS_PER_MINUTE ) );
		$this->assertSame( $expected, (int) get_post_meta( $post_id, '_daymark_reading_time_minutes', true ) );
	}

	/** A short caption gets no reading-time meta at all. */
	public function test_short_caption_gets_no_reading_time_meta() {
		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Just a short caption',
				'primary_type' => 'note',
			)
		);

		$this->assertSame( '', get_post_meta( $post_id, '_daymark_reading_time_minutes', true ) );
	}

	/** Camera EXIF fields present on the first image are stored as _daymark_camera. */
	public function test_camera_metadata_stored_when_present() {
		$filter = static function ( $metadata ) {
			$metadata['image_meta'] = array(
				'camera'        => 'Canon EOS R5',
				'aperture'      => '2.8',
				'iso'           => '400',
				'focal_length'  => '50',
				'shutter_speed' => '0.005',
			);
			return $metadata;
		};
		add_filter( 'wp_generate_attachment_metadata', $filter );

		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';
		$tmp     = wp_tempnam( 'daymark-camera-' ) . '.png';
		copy( $fixture, $tmp );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array( 'caption' => 'With camera EXIF' ),
			array(
				'files' => array(
					'name'     => 'camera.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				),
			)
		);

		remove_filter( 'wp_generate_attachment_metadata', $filter );

		$camera = json_decode( (string) get_post_meta( $post_id, '_daymark_camera', true ), true );
		$this->assertSame( 'Canon EOS R5', $camera['camera'] );
		$this->assertSame( '2.8', $camera['aperture'] );
		$this->assertSame( '400', $camera['iso'] );
		$this->assertSame( '50', $camera['focal_length'] );
		$this->assertSame( '0.005', $camera['shutter_speed'] );
	}

	/** An attachment with no useful EXIF camera fields stores nothing. */
	public function test_camera_metadata_not_stored_when_no_useful_fields() {
		$fixture = __DIR__ . '/e2e/fixtures/test-image.png';
		$tmp     = wp_tempnam( 'daymark-nocamera-' ) . '.png';
		copy( $fixture, $tmp );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array( 'caption' => 'No camera EXIF' ),
			array(
				'files' => array(
					'name'     => 'nocamera.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				),
			)
		);

		$this->assertSame( '', get_post_meta( $post_id, '_daymark_camera', true ) );
	}

	/** A successful Open-Meteo response stores _daymark_weather in the right shape. */
	public function test_weather_stored_on_successful_fetch() {
		$filter = static function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false === strpos( $url, 'api.open-meteo.com' ) ) {
				return $preempt;
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'current' => array(
							'temperature_2m' => 21.5,
							'weather_code'   => 1,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$publisher = new Daymark_Publisher();
		$post_id   = (int) $publisher->publish(
			array(
				'caption'      => 'Weather test',
				'primary_type' => 'note',
				'location_lat' => 40.7128,
				'location_lng' => -74.006,
			)
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$weather = json_decode( (string) get_post_meta( $post_id, '_daymark_weather', true ), true );
		$this->assertSame( 21.5, $weather['temperature'] );
		$this->assertSame( 'C', $weather['unit'] );
		$this->assertSame( 'Mostly clear', $weather['condition'] );
		$this->assertSame( 1, $weather['code'] );
	}

	/** A WP_Error (e.g. timeout) leaves _daymark_weather unset and never fails publish(). */
	public function test_weather_not_stored_on_http_error() {
		$filter = static function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false === strpos( $url, 'api.open-meteo.com' ) ) {
				return $preempt;
			}
			return new WP_Error( 'http_request_failed', 'timeout' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Weather failure test',
				'primary_type' => 'note',
				'location_lat' => 40.7128,
				'location_lng' => -74.006,
			)
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsInt( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_weather', true ) );
	}

	/** A non-200 response leaves _daymark_weather unset. */
	public function test_weather_not_stored_on_non_200_response() {
		$filter = static function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false === strpos( $url, 'api.open-meteo.com' ) ) {
				return $preempt;
			}
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 503,
					'message' => 'Service Unavailable',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Weather non-200 test',
				'primary_type' => 'note',
				'location_lat' => 40.7128,
				'location_lng' => -74.006,
			)
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsInt( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_weather', true ) );
	}

	/** Malformed JSON leaves _daymark_weather unset. */
	public function test_weather_not_stored_on_malformed_json() {
		$filter = static function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false === strpos( $url, 'api.open-meteo.com' ) ) {
				return $preempt;
			}
			return array(
				'headers'  => array(),
				'body'     => 'not valid json{{{',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$publisher = new Daymark_Publisher();
		$post_id   = $publisher->publish(
			array(
				'caption'      => 'Weather malformed test',
				'primary_type' => 'note',
				'location_lat' => 40.7128,
				'location_lng' => -74.006,
			)
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsInt( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_weather', true ) );
	}
}
