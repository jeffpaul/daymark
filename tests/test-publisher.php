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
}
