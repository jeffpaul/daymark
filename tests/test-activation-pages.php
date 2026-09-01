<?php
/**
 * Legacy page migration tests (issue: bottom nav rework).
 *
 * A fresh install creates no pages of its own anymore — Timeline, Explore,
 * Search, and Me all live inside the authenticated app shell. These tests
 * cover the two one-time migrations that retire a pre-existing install's
 * generated pages: the public Timeline page (hard-deleted) and the
 * Images/Videos/Audio/Notes section pages (trashed).
 *
 * @package Daymark
 */

/**
 * Both migrations only ever act on a page confidently identified as
 * Daymark-managed (carrying the view's own block or shortcode markup) —
 * never a page a site owner repurposed at that slug in the meantime.
 */
class Test_Activation_Pages extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'daymark_pages' );
		delete_option( 'daymark_legacy_content_pages' );
	}

	/** Activation creates no pages of its own on a fresh install. */
	public function test_activation_creates_no_pages() {
		Daymark_Plugin::activate();

		foreach ( array( 'images', 'videos', 'audio', 'notes', 'timeline' ) as $slug ) {
			$this->assertNull(
				get_page_by_path( $slug, OBJECT, 'page' ),
				"Activation must not create a page at /{$slug}"
			);
		}
		$this->assertFalse( get_option( 'daymark_pages', false ), 'daymark_pages must not exist for a fresh install' );
	}

	/** An existing install's Daymark-authored Timeline page is hard-deleted, not just unmapped. */
	public function test_existing_timeline_page_is_hard_deleted_on_upgrade() {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'timeline',
				'post_content' => '<!-- wp:daymark/timeline /-->',
			)
		);

		update_option( 'daymark_pages', array( 'timeline' => $page_id ) );

		Daymark_Plugin::remove_public_timeline_page();

		$this->assertNull( get_post( $page_id ), 'The page row itself must be gone, not merely trashed, for a real 404.' );
		$this->assertArrayNotHasKey( 'timeline', get_option( 'daymark_pages' ) );
	}

	/** A user page that happens to sit at the /timeline slug is never touched. */
	public function test_unrelated_timeline_page_is_preserved() {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'timeline',
				'post_content' => 'My own career timeline, nothing to do with Daymark.',
			)
		);

		update_option( 'daymark_pages', array( 'timeline' => $page_id ) );

		Daymark_Plugin::remove_public_timeline_page();

		$this->assertInstanceOf( WP_Post::class, get_post( $page_id ), 'A page not carrying Daymark markup must never be deleted.' );
		$this->assertArrayNotHasKey( 'timeline', get_option( 'daymark_pages' ), 'The stale map entry is still cleared either way.' );
	}

	/** A Daymark-managed content-type page is trashed, not hard-deleted, and its slug remembered for the Explore redirect. */
	public function test_content_type_page_is_trashed_on_upgrade() {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'notes',
				'post_content' => '<!-- wp:daymark/notes /-->',
			)
		);

		update_option( 'daymark_pages', array( 'notes' => $page_id ) );

		Daymark_Plugin::migrate_content_type_pages();

		$this->assertSame( 'trash', get_post_status( $page_id ), 'A retired content-type page is trashed, giving a way back — never hard-deleted.' );
		$this->assertSame(
			'<!-- wp:daymark/notes /-->',
			get_post( $page_id )->post_content,
			'The trashed page keeps its content exactly as it was.'
		);
		$this->assertArrayHasKey( 'notes', get_option( 'daymark_legacy_content_pages' ), 'The old slug must be remembered so its URL can redirect to Explore.' );
		$this->assertArrayNotHasKey( 'notes', get_option( 'daymark_pages', array() ) );
	}

	/** The shortcode-authored era of a content-type page is recognized too. */
	public function test_shortcode_authored_content_type_page_is_trashed() {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'images',
				'post_content' => '<!-- wp:shortcode -->[daymark_images]<!-- /wp:shortcode -->',
			)
		);

		update_option( 'daymark_pages', array( 'images' => $page_id ) );

		Daymark_Plugin::migrate_content_type_pages();

		$this->assertSame( 'trash', get_post_status( $page_id ) );
	}

	/** A page the map points at that doesn't actually carry Daymark markup (e.g. a stale/mismatched mapping) is left alone. */
	public function test_unrelated_page_at_a_legacy_slug_is_preserved() {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'audio',
				'post_content' => 'A site owner\'s own page about audio gear, nothing to do with Daymark.',
			)
		);

		update_option( 'daymark_pages', array( 'audio' => $page_id ) );

		Daymark_Plugin::migrate_content_type_pages();

		$this->assertSame( 'publish', get_post_status( $page_id ), 'A page not carrying Daymark markup must never be trashed.' );
		$this->assertArrayNotHasKey( 'audio', get_option( 'daymark_pages', array() ), 'The stale map entry is still cleared either way.' );
	}

	/** Once every legacy key is gone, the whole daymark_pages option is removed rather than left as an empty array. */
	public function test_daymark_pages_option_is_removed_once_empty() {
		$ids = array();
		foreach ( array( 'images', 'videos', 'audio', 'notes' ) as $slug ) {
			$ids[ $slug ] = (int) self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_content' => '<!-- wp:daymark/' . $slug . ' /-->',
				)
			);
		}
		update_option( 'daymark_pages', $ids );

		Daymark_Plugin::migrate_content_type_pages();

		$this->assertFalse( get_option( 'daymark_pages', false ), 'An empty map is deleted outright, not left as [].' );
		$legacy = get_option( 'daymark_legacy_content_pages', array() );
		foreach ( array( 'images', 'videos', 'audio', 'notes' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $legacy );
		}
	}

	/** Running the migration again once nothing is left is a cheap no-op. */
	public function test_content_type_migration_is_idempotent() {
		Daymark_Plugin::migrate_content_type_pages();
		Daymark_Plugin::migrate_content_type_pages();

		$this->assertFalse( get_option( 'daymark_pages', false ) );
	}
}
