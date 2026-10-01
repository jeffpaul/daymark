<?php
/**
 * Public WordPress Playground blueprint tests.
 *
 * @package Daymark
 */

/**
 * The README-linked blueprint should stay on the known-safe install +
 * subscription-seeding path, plus (issue #402's own follow-up) a single
 * media-free demo Mark seeding a Featured Content example — never a demo-Mark
 * seeding step that generates its own local media (the specific,
 * GD-extension-dependent step that used to fatal inside Playground; see
 * `daymark_demo_image_file`'s own removal history).
 */
class Test_Playground_Blueprint extends WP_UnitTestCase {

	/**
	 * Decode the public blueprint JSON from disk.
	 *
	 * @return array<string, mixed>
	 */
	private function public_blueprint(): array {
		$blueprint = json_decode(
			(string) file_get_contents( dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/blueprint.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading this plugin's own bundled blueprint file off disk, not a remote fetch (matches this codebase's other local-file reads, e.g. class-routes.php's own sw.js read).
			true
		);

		$this->assertIsArray( $blueprint );

		return $blueprint;
	}

	/** The public blueprint keeps only the known-safe runPHP preset step. */
	public function test_public_blueprint_uses_single_subscription_seed_step(): void {
		$blueprint   = $this->public_blueprint();
		$runphp_step = array_values(
			array_filter(
				(array) ( $blueprint['steps'] ?? array() ),
				static function ( $step ) {
					return is_array( $step ) && 'runPHP' === ( $step['step'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $runphp_step );
		$this->assertStringContainsString( 'subscribe_to_site', (string) $runphp_step[0]['code'] );
		$this->assertStringContainsString( 'poll_subscription', (string) $runphp_step[0]['code'] );
		// The past crash cause was local media generation (a GD-produced
		// placeholder image), not calling the publisher at all — this locks
		// in that the never-safe step stays gone, not that publish() itself
		// is forbidden. See test_public_blueprint_seeds_featured_content_demo_mark()
		// below for what the current, media-free publish() call must look like.
		$this->assertStringNotContainsString( 'daymark_demo_image_file', (string) $runphp_step[0]['code'] );
	}

	/**
	 * The public blueprint hands Mark seeding to the checked-in seed script
	 * (which Playground installs next to the sample photos it needs) instead
	 * of carrying it inline, and never generates media at run time.
	 */
	public function test_public_blueprint_requires_the_sample_content_script(): void {
		$blueprint   = $this->public_blueprint();
		$runphp_step = array_values(
			array_filter(
				(array) ( $blueprint['steps'] ?? array() ),
				static function ( $step ) {
					return is_array( $step ) && 'runPHP' === ( $step['step'] ?? '' );
				}
			)
		);

		$code = (string) $runphp_step[0]['code'];

		$this->assertStringContainsString( '.github/blueprints/seed-sample-content.php', $code );
		$this->assertStringNotContainsString( 'imagecreate', $code );
		$this->assertFileExists( dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/seed-sample-content.php' );
	}

	/** Every sample photo the seed script names is committed. */
	public function test_sample_photos_are_committed(): void {
		$dir = dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/sample-images/';

		foreach ( array( 'sunrise-harbor', 'pine-forest', 'city-dusk', 'desert-dunes', 'alpine-lake' ) as $name ) {
			$this->assertFileExists( $dir . $name . '.jpg' );
			$this->assertLessThan( 200 * KB_IN_BYTES, filesize( $dir . $name . '.jpg' ), "$name should stay small." );
		}
	}

	/**
	 * Running the seed script creates one Mark for each type and Featured
	 * Content kind it promises, with no outbound request succeeding (the
	 * weather and place lookups are blocked here).
	 */
	public function test_seed_script_creates_each_sample_mark(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );

		include dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/seed-sample-content.php';

		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );

		$by_type = array();

		foreach ( get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_daymark_is_mark', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small test fixture set.
			)
		) as $post ) {
			$by_type[ (string) get_post_meta( $post->ID, '_daymark_primary_type', true ) ][] = $post->ID;
		}

		$this->assertCount( 1, $by_type['image'] ?? array(), 'one single-photo Mark' );
		$this->assertCount( 1, $by_type['gallery'] ?? array(), 'one gallery Mark' );
		$this->assertCount( 2, $by_type['checkin'] ?? array(), 'a Check In with and without a photo' );
		$this->assertCount( 4, $by_type['note'] ?? array(), 'four Featured Content notes' );

		$with_photo = array_filter(
			$by_type['checkin'],
			static function ( $id ) {
				return '' !== (string) get_post_meta( $id, '_daymark_media_ids', true ) && '[]' !== (string) get_post_meta( $id, '_daymark_media_ids', true );
			}
		);
		$this->assertCount( 1, $with_photo, 'only one Check In carries a photo' );

		$featured = array();

		foreach ( $by_type['note'] as $id ) {
			$featured[ (string) get_post_meta( $id, '_daymark_featured_content_type', true ) ] = $id;
		}

		$this->assertEqualsCanonicalizing( array( 'gallery', 'quote', 'link', 'video' ), array_keys( $featured ) );

		$gallery = Daymark_Featured_Content::get_featured_content( $featured['gallery'] );
		$this->assertGreaterThanOrEqual( 4, count( $gallery['data']['attachment_ids'] ?? array() ) );

		$this->assertSame( 'link', get_post_format( $featured['link'] ) );
		$this->assertSame( 'link', Daymark_Featured_Content::get_featured_content( $featured['link'] )['type'] );
		$this->assertSame( 'quote', Daymark_Featured_Content::get_featured_content( $featured['quote'] )['type'] );
		$this->assertSame( 'video', Daymark_Featured_Content::get_featured_content( $featured['video'] )['type'] );
	}

	/**
	 * Short-circuit every outbound request with a failure.
	 *
	 * @return WP_Error
	 */
	public function block_http() {
		return new WP_Error( 'blocked', 'Outbound requests are blocked in this test.' );
	}
}
