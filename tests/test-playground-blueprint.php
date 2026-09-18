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
	 * The public blueprint also seeds one plain-text demo Mark using a
	 * YouTube video as its Featured Content (issue #402 follow-up), so a
	 * fresh preview shows that feature already working. Deliberately no
	 * picked/generated media of its own — only the same publisher call the
	 * subscription-seeding step above already makes, plus the two Featured
	 * Content meta keys set directly afterward.
	 */
	public function test_public_blueprint_seeds_featured_content_demo_mark(): void {
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
		$code = (string) $runphp_step[0]['code'];

		$this->assertStringContainsString( 'publisher->publish', $code );
		$this->assertStringContainsString( '_daymark_featured_content_type', $code );
		$this->assertStringContainsString( "'video'", $code );
		$this->assertStringContainsString( '_daymark_featured_content', $code );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=BZtL1NVlxgQ', $code );
	}
}
