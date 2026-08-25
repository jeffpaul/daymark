<?php
/**
 * Activation page content tests.
 *
 * @package Daymark
 */

/**
 * Section pages are created with dynamic block markup (block-theme
 * native), and blocks render identically to their shortcode twins.
 */
class Test_Activation_Pages extends WP_UnitTestCase {

	public function test_activation_creates_block_based_pages() {
		Daymark_Plugin::activate();

		foreach ( array( 'images', 'videos', 'audio', 'notes' ) as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );

			$this->assertInstanceOf( WP_Post::class, $page, "Page /{$slug} should exist" );
			$this->assertStringContainsString( "<!-- wp:daymark/{$slug} /-->", $page->post_content );
			$this->assertStringNotContainsString( 'wp:shortcode', $page->post_content );
			$this->assertTrue( has_blocks( $page ), 'Page content must parse as blocks' );
		}
	}

	/** Timeline is no longer one of the auto-created section pages (issue #78). */
	public function test_activation_does_not_create_a_timeline_page() {
		Daymark_Plugin::activate();

		$this->assertNull( get_page_by_path( 'timeline', OBJECT, 'page' ) );
		$this->assertArrayNotHasKey( 'timeline', Daymark_Plugin::get_daymark_pages() );
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

	/** A user page occupying a view slug is preserved; ours gets a prefixed slug. */
	public function test_slug_collision_falls_back_to_prefixed_slug() {
		$user_page = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'notes',
				'post_title'   => 'My Personal Notes',
				'post_content' => 'Nothing to do with Daymark.',
			)
		);

		Daymark_Plugin::activate();

		$this->assertSame( 'Nothing to do with Daymark.', get_post( $user_page )->post_content, 'User page must be untouched' );

		$fallback = get_page_by_path( 'daymark-notes', OBJECT, 'page' );
		$this->assertInstanceOf( WP_Post::class, $fallback );
		$this->assertStringContainsString( '<!-- wp:daymark/notes /-->', $fallback->post_content );

		$map = Daymark_Plugin::get_daymark_pages();
		$this->assertSame( $fallback->ID, $map['notes'], 'Mapping must point at the fallback page' );
	}

	/** Both candidate slugs taken by user content → view maps to 0 (link hidden). */
	public function test_both_slugs_taken_maps_view_to_zero() {
		foreach ( array( 'images', 'daymark-images' ) as $slug ) {
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_name'    => $slug,
					'post_content' => 'User content.',
				)
			);
		}

		Daymark_Plugin::activate();

		$map = Daymark_Plugin::get_daymark_pages();
		$this->assertSame( 0, $map['images'] );
		$this->assertGreaterThan( 0, $map['notes'], 'Uncontested views still get pages' );
	}

	/** Installs predating the mapping adopt their existing Mark pages. */
	public function test_mapping_self_heals_by_adopting_marked_pages() {
		$legacy = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'notes',
				'post_content' => '<!-- wp:shortcode -->[daymark_notes]<!-- /wp:shortcode -->',
			)
		);

		delete_option( 'daymark_pages' );

		$map = Daymark_Plugin::get_daymark_pages();
		$this->assertSame( $legacy, $map['notes'], 'Shortcode-era page must be adopted, not shadowed' );
	}

	/**
	 * The block's outer wrapper additionally carries whatever
	 * get_block_wrapper_attributes() generates (the auto wp-block-daymark-*
	 * class, plus any color/typography/spacing Global Styles selection) —
	 * that is how the block participates in those supports, which the
	 * shortcode has no mechanism to opt into. Everything else must still
	 * match byte-for-byte.
	 */
	public function test_block_and_shortcode_render_identically() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$publisher = new Daymark_Publisher();
		$publisher->publish( array( 'caption' => 'Parity check note' ) );

		foreach ( array( 'images', 'notes' ) as $view ) {
			$shortcode_html = do_shortcode( "[daymark_{$view}]" );
			$block_html     = do_blocks( "<!-- wp:daymark/{$view} /-->" );

			$shortcode_inner = preg_replace( '/^<div[^>]*>/', '', $shortcode_html );
			$block_inner     = preg_replace( '/^<div[^>]*>/', '', $block_html );

			$this->assertSame(
				$shortcode_inner,
				$block_inner,
				"daymark/{$view} block content must render identically to [daymark_{$view}], aside from the block-supports wrapper"
			);
			$this->assertStringContainsString(
				'wp-block-daymark-' . $view,
				$block_html,
				"daymark/{$view} block wrapper must carry its block-supports class"
			);
			$this->assertStringContainsString(
				'daymark-view daymark-view--' . $view,
				$block_html,
				"daymark/{$view} block wrapper must keep the plugin's own view class"
			);
		}
	}
}
