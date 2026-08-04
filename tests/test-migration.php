<?php
/**
 * Migration tests — converting a legacy Moment (≤ 0.5.0) install.
 *
 * Remove together with includes/class-migration.php when the migration
 * is retired.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Migration's one-time legacy conversion.
 */
class Test_Migration extends WP_UnitTestCase {

	/** A fresh install (no legacy option) is a strict no-op. */
	public function test_noop_without_legacy_install() {
		delete_option( 'moment_version' );
		delete_option( 'daymark_version' );

		Daymark_Migration::maybe_migrate();

		$this->assertFalse( get_option( 'daymark_version' ), 'No version stamp should appear on a fresh install' );
	}

	/** Full conversion: options, user meta, post/comment meta, pages, cron, transients. */
	public function test_migrates_legacy_install() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		// --- Seed a legacy Moment install. ---
		// The test bootstrap already ran init, so routes resolved and
		// persisted a fresh app base; on a real legacy site the migration
		// runs first (init@5, before routes at 10). Clear the resolved
		// state to simulate that.
		delete_option( 'daymark_app_base' );
		delete_option( 'daymark_pages' );
		delete_option( 'daymark_version' );

		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_activated', 12345 );
		update_option( 'moment_app_base', 'moment' );

		update_user_meta( $user_id, 'moment_destination_prefs', array( 'note' => array( 'bluesky' ) ) );
		update_user_meta( $user_id, 'moment_category_prefs', array( 'image' => array( 7 ) ) );
		update_user_meta( $user_id, 'moment_notifications_seen', 99 );

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $post_id, '_moment_is_moment', '1' );
		update_post_meta( $post_id, '_moment_primary_type', 'note' );
		update_post_meta( $post_id, '_moment_backflow_synced_bluesky', '2026-08-01' );

		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		update_comment_meta( $comment_id, '_moment_comment_source', 'bluesky' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'timeline',
				'post_content' => '<!-- wp:moment/timeline /-->',
			)
		);
		update_option( 'moment_pages', array( 'timeline' => $page_id ) );

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'moment_backflow_sync' );
		set_transient( 'moment_backflow_freshened', time(), HOUR_IN_SECONDS );
		set_transient( 'moment_backflow_cooldown_' . $post_id, time(), HOUR_IN_SECONDS );

		// --- Migrate. ---
		Daymark_Migration::maybe_migrate();

		// Options: carried under new names, legacy names gone.
		$this->assertSame( 'moment', get_option( 'daymark_app_base' ), 'The persisted app base keeps its value — home-screen URLs must not move' );
		$this->assertSame( array( 'timeline' => $page_id ), get_option( 'daymark_pages' ) );
		$this->assertSame( DAYMARK_VERSION, get_option( 'daymark_version' ) );
		foreach ( array( 'moment_version', 'moment_activated', 'moment_app_base', 'moment_pages' ) as $legacy ) {
			$this->assertFalse( get_option( $legacy ), "Legacy option {$legacy} should be gone" );
		}

		// User meta.
		$this->assertSame( array( 'note' => array( 'bluesky' ) ), get_user_meta( $user_id, 'daymark_destination_prefs', true ) );
		$this->assertSame( array( 'image' => array( 7 ) ), get_user_meta( $user_id, 'daymark_category_prefs', true ) );
		$this->assertSame( '99', (string) get_user_meta( $user_id, 'daymark_notifications_seen', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'moment_destination_prefs', true ) );

		// Post meta, including the marker special case and a dynamic key.
		$this->assertSame( '1', get_post_meta( $post_id, '_daymark_is_mark', true ), 'Marker renamed to _daymark_is_mark' );
		$this->assertSame( 'note', get_post_meta( $post_id, '_daymark_primary_type', true ) );
		$this->assertSame( '2026-08-01', get_post_meta( $post_id, '_daymark_backflow_synced_bluesky', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_moment_is_moment', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_daymark_is_moment', true ), 'Half-renamed marker must not survive' );

		// Comment meta.
		$this->assertSame( 'bluesky', get_comment_meta( $comment_id, '_daymark_comment_source', true ) );

		// Section-page markup.
		$this->assertSame( '<!-- wp:daymark/timeline /-->', get_post( $page_id )->post_content );

		// Cron + transients.
		$this->assertFalse( wp_next_scheduled( 'moment_backflow_sync' ), 'Legacy cron cleared' );
		$this->assertFalse( get_transient( 'moment_backflow_freshened' ) );
		$this->assertFalse( get_transient( 'moment_backflow_cooldown_' . $post_id ) );
	}

	/** Running twice is safe and changes nothing the second time. */
	public function test_idempotent() {
		delete_option( 'daymark_app_base' ); // See test_migrates_legacy_install.
		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_app_base', 'moment' );

		Daymark_Migration::maybe_migrate();
		$this->assertSame( 'moment', get_option( 'daymark_app_base' ) );

		// A second run must not clobber post-migration state.
		update_option( 'daymark_app_base', 'daymark' );
		Daymark_Migration::maybe_migrate();
		$this->assertSame( 'daymark', get_option( 'daymark_app_base' ), 'Second run is a no-op' );
	}

	/** An existing daymark option is never overwritten by a legacy value. */
	public function test_existing_new_option_wins() {
		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_app_base', 'moment' );
		update_option( 'daymark_app_base', 'daymark' );

		Daymark_Migration::maybe_migrate();

		$this->assertSame( 'daymark', get_option( 'daymark_app_base' ) );
		$this->assertFalse( get_option( 'moment_app_base' ), 'Legacy option still removed' );
	}

	/** Full activation over a legacy install keeps the carried app base. */
	public function test_activation_preserves_carried_app_base() {
		delete_option( 'daymark_app_base' ); // See test_migrates_legacy_install.
		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_app_base', 'moment' );

		Daymark_Plugin::activate();

		$this->assertSame( 'moment', get_option( 'daymark_app_base' ), 'Activation must not re-resolve a persisted app base' );
	}

	/** Section pages with shortcode markup are rewritten too. */
	public function test_migrates_shortcode_pages() {
		update_option( 'moment_version', '0.5.0' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'notes',
				'post_content' => '[moment_notes]',
			)
		);
		update_option( 'moment_pages', array( 'notes' => $page_id ) );

		Daymark_Migration::maybe_migrate();

		$this->assertSame( '[daymark_notes]', get_post( $page_id )->post_content );
	}
}
