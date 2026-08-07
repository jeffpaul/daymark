<?php
/**
 * Per-image alt text: positional on new uploads, ID-mapped on edit, and
 * the vision AI suggestion endpoint (mock fallback without a provider).
 *
 * @package Daymark
 */

/**
 * Alt text assignment + AI alt suggestion.
 */
class Test_Alt_Text extends WP_UnitTestCase {

	/**
	 * Path to the fixture PNG.
	 *
	 * @var string
	 */
	private string $fixture;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->fixture = __DIR__ . '/e2e/fixtures/test-image.png';
	}

	/**
	 * A fresh temp copy of the fixture PNG (sideloading renames the temp
	 * file away, so each upload needs its own copy).
	 *
	 * @return string Temp path.
	 */
	private function temp_png(): string {
		$tmp = wp_tempnam( 'daymark-alt-' ) . '.png';
		copy( $this->fixture, $tmp );

		return $tmp;
	}

	/**
	 * Build a $_FILES-style `files[]` array for the given temp paths.
	 *
	 * @param string[] $paths Temp file paths.
	 * @return array<string, array<string, mixed>>
	 */
	private function files_array( array $paths ): array {
		$names = array();
		$types = array();
		$tmps  = array();
		$errs  = array();
		$sizes = array();

		foreach ( $paths as $i => $path ) {
			$names[] = "image-{$i}.png";
			$types[] = 'image/png';
			$tmps[]  = $path;
			$errs[]  = UPLOAD_ERR_OK;
			$sizes[] = (int) filesize( $path );
		}

		return array(
			'files' => array(
				'name'     => $names,
				'type'     => $types,
				'tmp_name' => $tmps,
				'error'    => $errs,
				'size'     => $sizes,
			),
		);
	}

	private function alt_of( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	}

	private function media_ids_of( int $post_id ): array {
		return array_map( 'intval', (array) json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true ) );
	}

	/** Positional alt maps each entry to the matching uploaded image. */
	public function test_positional_alt_on_publish() {
		$publisher = new Daymark_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption' => 'Two shots',
				'alt'     => array( 'A red door', 'A blue window' ),
			),
			$this->files_array( array( $this->temp_png(), $this->temp_png() ) )
		);

		$this->assertNotWPError( $post_id );
		$media = $this->media_ids_of( $post_id );
		$this->assertCount( 2, $media );
		$this->assertSame( 'A red door', $this->alt_of( $media[0] ) );
		$this->assertSame( 'A blue window', $this->alt_of( $media[1] ) );
	}

	/** An empty positional entry leaves that image's alt untouched. */
	public function test_positional_alt_skips_empty_entries() {
		$publisher = new Daymark_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption' => 'One described, one not',
				'alt'     => array( '', 'Only the second' ),
			),
			$this->files_array( array( $this->temp_png(), $this->temp_png() ) )
		);

		$media = $this->media_ids_of( $post_id );
		$this->assertSame( '', $this->alt_of( $media[0] ) );
		$this->assertSame( 'Only the second', $this->alt_of( $media[1] ) );
	}

	/** The legacy single alt_text still describes the first image alone. */
	public function test_legacy_single_alt_text_still_works() {
		$publisher = new Daymark_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption'  => 'Legacy alt',
				'alt_text' => 'Legacy description',
			),
			$this->files_array( array( $this->temp_png() ) )
		);

		$media = $this->media_ids_of( $post_id );
		$this->assertSame( 'Legacy description', $this->alt_of( $media[0] ) );
	}

	/** Editing a Mark updates alt on already-attached images by ID. */
	public function test_existing_alt_map_on_update() {
		$publisher = new Daymark_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption' => 'Editable gallery',
				'status'  => 'draft',
				'alt'     => array( 'First', 'Second' ),
			),
			$this->files_array( array( $this->temp_png(), $this->temp_png() ) )
		);

		$media = $this->media_ids_of( $post_id );

		$publisher->update(
			$post_id,
			array(
				'caption'      => 'Edited gallery',
				'existing_alt' => array(
					(string) $media[0] => 'First, revised',
					(string) $media[1] => '',
				),
			)
		);

		$this->assertSame( 'First, revised', $this->alt_of( $media[0] ) );
		$this->assertSame( '', $this->alt_of( $media[1] ), 'An empty map value clears alt' );
	}

	/** The ID-mapped alt editor is scoped to the Mark's own media (IDOR guard). */
	public function test_existing_alt_map_cannot_touch_foreign_attachments() {
		$publisher = new Daymark_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption' => 'Own gallery',
				'status'  => 'draft',
				'alt'     => array( 'Mine' ),
			),
			$this->files_array( array( $this->temp_png() ) )
		);

		$own = $this->media_ids_of( $post_id )[0];

		// A second attachment that belongs to a different post entirely.
		$other_post = (int) self::factory()->post->create();
		$foreign    = (int) self::factory()->attachment->create_upload_object( $this->temp_png(), $other_post );
		update_post_meta( $foreign, '_wp_attachment_image_alt', 'Keep me' );

		$publisher->update(
			$post_id,
			array(
				'caption'      => 'Edited gallery',
				'existing_alt' => array(
					(string) $own     => 'Mine, revised',
					(string) $foreign => 'Stolen',
				),
			)
		);

		$this->assertSame( 'Mine, revised', $this->alt_of( $own ) );
		$this->assertSame( 'Keep me', $this->alt_of( $foreign ), 'An attachment outside the Mark must be untouched' );
	}

	/** GET /marks/{id} exposes each image's current alt for the composer. */
	public function test_get_daymark_media_includes_alt() {
		$publisher = new Daymark_Publisher();

		$post_id = (int) $publisher->publish(
			array(
				'caption' => 'With alt',
				'status'  => 'draft',
				'alt'     => array( 'A described photo' ),
			),
			$this->files_array( array( $this->temp_png() ) )
		);

		$request = new WP_REST_Request( 'GET', "/daymark/v1/marks/{$post_id}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$media = rest_do_request( $request )->get_data()['media'];

		$this->assertSame( 'image', $media[0]['kind'] );
		$this->assertSame( 'A described photo', $media[0]['alt'] );
	}

	/** Without a provider, vision alt falls back to deterministic mock. */
	public function test_ai_alt_suggestion_mock_without_provider() {
		$ai         = new Daymark_AI_Assist();
		$suggestion = $ai->get_image_alt_suggestion( $this->fixture, array( 'type' => 'image' ) );

		$this->assertTrue( $suggestion['is_mocked'] );
		$this->assertNotSame( '', $suggestion['alt_text'] );
	}

	/** POST /ai/alt-text returns alt for an uploaded image. */
	public function test_rest_alt_text_returns_alt_for_image() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/alt-text' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_file_params(
			array(
				'image' => array(
					'name'     => 'shot.png',
					'type'     => 'image/png',
					'tmp_name' => $this->temp_png(),
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $this->fixture ),
				),
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'alt_text', $response->get_data() );
		$this->assertNotSame( '', $response->get_data()['alt_text'] );
	}

	/** A non-image upload is rejected. */
	public function test_rest_alt_text_rejects_non_image() {
		$txt = wp_tempnam( 'daymark-notimg-' ) . '.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write; WP_Filesystem is unnecessary here.
		file_put_contents( $txt, 'just text, not an image' );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/alt-text' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_file_params(
			array(
				'image' => array(
					'name'     => 'note.txt',
					'type'     => 'text/plain',
					'tmp_name' => $txt,
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $txt ),
				),
			)
		);

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/** A missing image is a 400. */
	public function test_rest_alt_text_requires_image() {
		$request = new WP_REST_Request( 'POST', '/daymark/v1/ai/alt-text' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
