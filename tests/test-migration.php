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
		$this->setExpectedDeprecated( 'Daymark_Migration::migrate' );

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

		// Options: carried under new names, legacy names gone. The app base
		// itself is NOT carried — the old Moment-era base is remembered
		// separately, purely so Daymark_Routes can redirect it to /daymark.
		$this->assertFalse( get_option( 'daymark_app_base' ), 'The app base is left unresolved, not carried from Moment — it resolves fresh to daymark' );
		$this->assertSame( 'moment', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ), 'The old base is kept only so the redirect knows where to send visitors from' );
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
		$this->setExpectedDeprecated( 'Daymark_Migration::migrate' );

		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_app_base', 'moment' );

		Daymark_Migration::maybe_migrate();
		$this->assertSame( 'moment', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ) );

		// A second run must not clobber post-migration state. moment_version
		// is already gone at this point, so maybe_migrate() short-circuits.
		update_option( Daymark_Routes::OPTION_LEGACY_APP_BASE, 'daymark-app' );
		Daymark_Migration::maybe_migrate();
		$this->assertSame( 'daymark-app', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ), 'Second run is a no-op' );
	}

	/** An existing legacy-base option is never overwritten by migration. */
	public function test_existing_legacy_base_option_wins() {
		$this->setExpectedDeprecated( 'Daymark_Migration::migrate' );

		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_app_base', 'moment' );
		update_option( Daymark_Routes::OPTION_LEGACY_APP_BASE, 'already-set' );

		Daymark_Migration::maybe_migrate();

		$this->assertSame( 'already-set', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ) );
		$this->assertFalse( get_option( 'moment_app_base' ), 'Legacy option still removed' );
	}

	/** Activation resolves the real app base fresh, even over a migrated install. */
	public function test_activation_resolves_fresh_base_over_a_legacy_install() {
		$this->setExpectedDeprecated( 'Daymark_Migration::migrate' );

		delete_option( 'daymark_app_base' ); // See test_migrates_legacy_install.
		update_option( 'moment_version', '0.5.0' );
		update_option( 'moment_app_base', 'moment' );

		Daymark_Plugin::activate();

		$this->assertSame( 'daymark', get_option( 'daymark_app_base' ), 'The app must claim /daymark itself, not carry the old /moment base forward' );
		$this->assertSame( 'moment', get_option( Daymark_Routes::OPTION_LEGACY_APP_BASE ), 'The old base survives only for the redirect' );
	}

	/** Section pages with shortcode markup are rewritten too. */
	public function test_migrates_shortcode_pages() {
		$this->setExpectedDeprecated( 'Daymark_Migration::migrate' );

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
