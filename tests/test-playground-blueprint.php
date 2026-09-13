<?php
/**
 * Public WordPress Playground blueprint tests.
 *
 * @package Daymark
 */

/**
 * The README-linked blueprint should stay on the known-safe install +
 * subscription-seeding path, not carry extra demo-Mark seeding steps that can
 * fatal inside Playground.
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
		$this->assertStringNotContainsString( 'publisher->publish', (string) $runphp_step[0]['code'] );
		$this->assertStringNotContainsString( 'daymark_demo_image_file', (string) $runphp_step[0]['code'] );
	}
}
