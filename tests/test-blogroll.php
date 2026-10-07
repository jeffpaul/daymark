<?php
/**
 * Daymark_Blogroll tests: the public OPML file, its head link, the
 * Blogroll block, and reading the old Links Manager for import.
 *
 * maybe_serve_opml() ends in exit when it serves the file, so its serving
 * path is covered through the pieces it is built from (sites() and
 * Daymark_Subscription_OPML::export_public()); its 404 path returns
 * normally and is called directly.
 *
 * @package Daymark
 */

/**
 * Daymark_Blogroll coverage.
 */
class Test_Blogroll extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		$this->subscriptions = new Daymark_Subscriptions();

		delete_option( Daymark_Settings::BLOGROLL_PUBLIC );
	}

	public function tear_down(): void {
		delete_option( Daymark_Settings::BLOGROLL_PUBLIC );
		set_query_var( Daymark_Blogroll::QUERY_VAR, '' );

		parent::tear_down();
	}

	/**
	 * Create an active subscription.
	 *
	 * @param string $site  Site URL.
	 * @param string $title Site title.
	 * @param string $type  Source type.
	 * @return int
	 */
	private function subscribe( string $site, string $title, string $type = 'feed' ): int {
		return (int) $this->subscriptions->create(
			array(
				'site_url'    => $site,
				'feed_url'    => 'feed' === $type ? $site . 'feed/' : $site . 'wp-json/wp/v2/posts',
				'site_title'  => $title,
				'source_type' => $type,
				'status'      => 'active',
			)
		);
	}

	/** Nothing is advertised while the blogroll is private (the default). */
	public function test_head_link_absent_by_default(): void {
		ob_start();
		Daymark_Plugin::instance()->blogroll->print_head_link();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/** Once public, every page links to the OPML file with rel="blogroll". */
	public function test_head_link_present_when_public(): void {
		update_option( Daymark_Settings::BLOGROLL_PUBLIC, '1' );

		ob_start();
		Daymark_Plugin::instance()->blogroll->print_head_link();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="blogroll"', $output );
		$this->assertStringContainsString( esc_url( Daymark_Blogroll::opml_url() ), $output );
	}

	/** Asking for the OPML file while the blogroll is private is a 404. */
	public function test_opml_url_is_404_while_private(): void {
		global $wp_query;

		set_query_var( Daymark_Blogroll::QUERY_VAR, 'opml' );
		Daymark_Plugin::instance()->blogroll->maybe_serve_opml();

		$this->assertTrue( $wp_query->is_404() );
	}

	/** sites() lists only active subscriptions, sorted by name. */
	public function test_sites_lists_active_subscriptions_by_name(): void {
		$this->subscribe( 'https://zeta.example/', 'Zeta' );
		$this->subscribe( 'https://alpha.example/', 'Alpha' );
		$dead = $this->subscribe( 'https://dead.example/', 'Dead' );
		$this->subscriptions->update( $dead, array( 'status' => 'error' ) );

		$labels = array_map( array( 'Daymark_Blogroll', 'label' ), Daymark_Blogroll::sites() );

		$this->assertSame( array( 'Alpha', 'Zeta' ), $labels );
	}

	/**
	 * The public OPML carries each site's name and address, and a public
	 * feed URL: a WordPress REST API subscription is listed by the site's
	 * own RSS feed, which any feed reader can use.
	 */
	public function test_export_public_lists_sites_with_reader_friendly_feeds(): void {
		$this->subscribe( 'https://rss.example/', 'RSS Site' );
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the lowercase machine ID (source_type value), not prose.
		$this->subscribe( 'https://wp.example/', 'WP Site', 'wordpress' );

		$opml = ( new Daymark_Subscription_OPML() )->export_public( Daymark_Blogroll::sites(), 'Sites I follow' );

		$this->assertStringContainsString( '<title>Sites I follow</title>', $opml );
		$this->assertStringContainsString( 'xmlUrl="https://rss.example/feed/"', $opml );
		$this->assertStringContainsString( 'xmlUrl="https://wp.example/feed/"', $opml );
		$this->assertStringContainsString( 'htmlUrl="https://wp.example/"', $opml );
		$this->assertStringContainsString( 'text="WP Site"', $opml );
		$this->assertStringNotContainsString( 'wp-json', $opml );
		$this->assertStringNotContainsString( 'daymark:', $opml );
	}

	/** The block lists each followed site as a link, with its icon unless turned off. */
	public function test_block_renders_links(): void {
		$id = $this->subscribe( 'https://alpha.example/', 'Alpha' );
		$this->subscriptions->update( $id, array( 'site_icon_url' => 'https://alpha.example/icon.png' ) );

		$with_icons = Daymark_Plugin::instance()->blogroll->render_block( array() );
		$no_icons   = Daymark_Plugin::instance()->blogroll->render_block( array( 'showIcons' => false ) );

		$this->assertStringContainsString( '<a href="https://alpha.example/">Alpha</a>', $with_icons );
		$this->assertStringContainsString( 'https://alpha.example/icon.png', $with_icons );
		$this->assertStringNotContainsString( '<img', $no_icons );
	}

	/** Rendered as a real block, it carries the block's wrapper class. */
	public function test_block_renders_through_do_blocks(): void {
		$this->subscribe( 'https://alpha.example/', 'Alpha' );

		$output = do_blocks( '<!-- wp:daymark/blogroll /-->' );

		$this->assertStringContainsString( 'wp-block-daymark-blogroll', $output );
		$this->assertStringContainsString( '>Alpha</a>', $output );
	}

	/** The block renders nothing when no sites are followed. */
	public function test_block_empty_without_subscriptions(): void {
		$this->assertSame( '', Daymark_Plugin::instance()->blogroll->render_block( array() ) );
	}

	/** The block is registered. */
	public function test_block_registered(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Daymark_Blogroll::BLOCK ) );
	}

	/** Links Manager entries are offered for import, using a link's RSS address as its feed. */
	public function test_links_for_import_reads_links_manager(): void {
		require_once ABSPATH . 'wp-admin/includes/bookmark.php';

		$with_rss = wp_insert_link(
			array(
				'link_name' => 'Feedy',
				'link_url'  => 'https://feedy.example/',
				'link_rss'  => 'https://feedy.example/rss',
			)
		);
		$plain    = wp_insert_link(
			array(
				'link_name'    => 'Plain',
				'link_url'     => 'https://plain.example/',
				'link_visible' => 'N',
			)
		);

		$links = Daymark_Blogroll::links_for_import();

		$this->assertSame( 'https://feedy.example/rss', $links[ $with_rss ]['xml_url'] );
		$this->assertSame( 'Feedy', $links[ $with_rss ]['label'] );
		$this->assertSame( '', $links[ $plain ]['xml_url'] );
		$this->assertSame( 'https://plain.example/', $links[ $plain ]['html_url'] );
	}
}
