<?php
/**
 * Web Share Target ("Share -> Daymark") tests.
 *
 * @package Daymark
 */

/**
 * The request-handling wrapper itself (Daymark_Share_Target::handle()) is
 * exercised end-to-end via a real HTTP POST in the Playwright suite, not
 * here — it calls wp_die()/exit directly, which PHPUnit has no safe way to
 * intercept without special scaffolding this codebase doesn't otherwise
 * rely on (see class-admin-subscriptions.php's own POST handler, which is
 * the same shape and is likewise left to manual/E2E coverage). What's
 * genuinely worth a unit test is the caption-composition logic, and that
 * the manifest declares share_target correctly.
 */
class Test_Share_Target extends WP_UnitTestCase {

	/** No fields shared at all (shouldn't happen in practice — handle() redirects before calling this). */
	public function test_compose_caption_all_empty() {
		$this->assertSame( '', Daymark_Share_Target::compose_caption( '', '', '' ) );
	}

	/** A photo share with no text: title/text/url all empty. */
	public function test_compose_caption_only_title() {
		$this->assertSame( 'Sunset over the bay', Daymark_Share_Target::compose_caption( 'Sunset over the bay', '', '' ) );
	}

	/** A link share: title (page title) + url, joined on their own lines. */
	public function test_compose_caption_title_and_url() {
		$this->assertSame(
			"Example Article\n\nhttps://example.com/article",
			Daymark_Share_Target::compose_caption( 'Example Article', '', 'https://example.com/article' )
		);
	}

	/** Some apps duplicate the same string into both title and text — not repeated. */
	public function test_compose_caption_drops_exact_duplicates() {
		$this->assertSame(
			'Same string',
			Daymark_Share_Target::compose_caption( 'Same string', 'Same string', '' )
		);
	}

	/** All three present and distinct. */
	public function test_compose_caption_all_three() {
		$this->assertSame(
			"A title\n\nSome text\n\nhttps://example.com",
			Daymark_Share_Target::compose_caption( 'A title', 'Some text', 'https://example.com' )
		);
	}

	/**
	 * Camera-first thinking's follow-up: the manifest declares share_target
	 * pointing at the same resolved app base as start_url/scope/shortcuts.
	 */
	public function test_manifest_share_target_tracks_base() {
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'daymark',
			)
		);
		Daymark_Routes::resolve_app_base();

		$manifest = Daymark_Routes::build_manifest();

		$this->assertArrayHasKey( 'share_target', $manifest );
		$this->assertSame( home_url( '/daymark-app/share' ), $manifest['share_target']['action'] );
		$this->assertSame( 'POST', $manifest['share_target']['method'] );
		$this->assertSame( 'multipart/form-data', $manifest['share_target']['enctype'] );
		$this->assertSame( 'media[]', $manifest['share_target']['params']['files'][0]['name'] );
	}
}
