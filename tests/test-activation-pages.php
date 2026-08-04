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

		foreach ( array( 'timeline', 'images', 'videos', 'audio', 'notes' ) as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );

			$this->assertInstanceOf( WP_Post::class, $page, "Page /{$slug} should exist" );
			$this->assertStringContainsString( "<!-- wp:daymark/{$slug} /-->", $page->post_content );
			$this->assertStringNotContainsString( 'wp:shortcode', $page->post_content );
			$this->assertTrue( has_blocks( $page ), 'Page content must parse as blocks' );
		}
	}

	/** A user page occupying a view slug is preserved; ours gets a prefixed slug. */
	public function test_slug_collision_falls_back_to_prefixed_slug() {
		$user_page = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'timeline',
				'post_title'   => 'My Career Timeline',
				'post_content' => 'Nothing to do with Daymark.',
			)
		);

		Daymark_Plugin::activate();

		$this->assertSame( 'Nothing to do with Daymark.', get_post( $user_page )->post_content, 'User page must be untouched' );

		$fallback = get_page_by_path( 'daymark-timeline', OBJECT, 'page' );
		$this->assertInstanceOf( WP_Post::class, $fallback );
		$this->assertStringContainsString( '<!-- wp:daymark/timeline /-->', $fallback->post_content );

		$map = Daymark_Plugin::get_daymark_pages();
		$this->assertSame( $fallback->ID, $map['timeline'], 'Mapping must point at the fallback page' );
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
		$this->assertGreaterThan( 0, $map['timeline'], 'Uncontested views still get pages' );
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

		foreach ( array( 'timeline', 'notes' ) as $view ) {
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
