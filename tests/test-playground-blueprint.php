<?php
/**
 * Public WordPress Playground blueprint tests.
 *
 * @package Daymark
 */

/**
 * Every Playground preview (the README's "Try it" demo, the button on each
 * pull request, and the wordpress.org Live Preview) shows the same demo,
 * because each one only runs `.github/blueprints/set-up-demo.php`. These
 * tests hold the three to that, and run the script.
 *
 * The script must never generate its own media: a GD-produced placeholder
 * image once made every preview fatal inside Playground (see
 * `daymark_demo_image_file`'s own removal history).
 */
class Test_Playground_Blueprint extends WP_UnitTestCase {

	/**
	 * Path to a file in the repository.
	 *
	 * @param string $path Path from the repository root.
	 * @return string
	 */
	private function repo_path( string $path ): string {
		return dirname( DAYMARK_PLUGIN_FILE ) . '/' . $path;
	}

	/**
	 * The PHP a blueprint file runs: the code of its `runPHP` steps.
	 *
	 * @param string $path Blueprint path from the repository root.
	 * @return string[]
	 */
	private function blueprint_php( string $path ): array {
		$blueprint = json_decode(
			(string) file_get_contents( $this->repo_path( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading this plugin's own bundled blueprint file off disk, not a remote fetch (matches this codebase's other local-file reads, e.g. class-routes.php's own sw.js read).
			true
		);

		$this->assertIsArray( $blueprint );

		$code = array();

		foreach ( (array) ( $blueprint['steps'] ?? array() ) as $step ) {
			if ( is_array( $step ) && 'runPHP' === ( $step['step'] ?? '' ) ) {
				$code[] = (string) $step['code'];
			}
		}

		return $code;
	}

	/**
	 * The README demo's one PHP step only runs the shared script, from the
	 * plugin's own folder (this preview installs the repository as the
	 * plugin).
	 */
	public function test_readme_demo_runs_the_shared_script(): void {
		$code = $this->blueprint_php( '.github/blueprints/blueprint.json' );

		$this->assertCount( 1, $code );
		$this->assertStringContainsString( "dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/set-up-demo.php'", $code[0] );
		$this->assertFileExists( $this->repo_path( '.github/blueprints/set-up-demo.php' ) );
	}

	/**
	 * None of the three previews carries demo content of its own: no list
	 * of sites to follow, no call that follows one, no sample Mark. If one
	 * did, the previews would drift apart again.
	 */
	public function test_no_preview_carries_its_own_demo_content(): void {
		$workflow = (string) file_get_contents( $this->repo_path( '.github/workflows/pr-playground-preview.yml' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository file.
		$sources  = array(
			'the README demo'           => implode( "\n", $this->blueprint_php( '.github/blueprints/blueprint.json' ) ),
			'the wordpress.org preview' => implode( "\n", $this->blueprint_php( '.wordpress-org/blueprints/blueprint.json' ) ),
			'the pull request preview'  => $workflow,
		);

		$this->assertStringContainsString( "'/.github/blueprints/set-up-demo.php'", $workflow );

		foreach ( $sources as $name => $source ) {
			$this->assertStringContainsString( 'set-up-demo.php', $source, "$name runs the shared script." );

			foreach ( array( 'subscribe_to_site', 'poll_subscription', 'seed-sample-content.php', 'wp_delete_post', 'xkcd.com', 'jondesrosiers.com' ) as $own ) {
				$this->assertStringNotContainsString( $own, $source, "$name should leave \"$own\" to set-up-demo.php." );
			}
		}
	}

	/**
	 * The shared script follows sites with Daymark's own two calls, hands
	 * the sample Marks to the seed script next to it, and never generates
	 * media at run time.
	 */
	public function test_shared_script_follows_sites_and_requires_the_sample_marks(): void {
		$source = (string) file_get_contents( $this->repo_path( '.github/blueprints/set-up-demo.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository file.

		$this->assertStringContainsString( 'subscribe_to_site', $source );
		$this->assertStringContainsString( 'poll_subscription', $source );
		$this->assertStringContainsString( "require __DIR__ . '/seed-sample-content.php';", $source );
		$this->assertFileExists( $this->repo_path( '.github/blueprints/seed-sample-content.php' ) );

		foreach ( array( $source, (string) file_get_contents( $this->repo_path( '.github/blueprints/seed-sample-content.php' ) ) ) as $script ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository file.
			$this->assertStringNotContainsString( 'daymark_demo_image_file', $script );
			$this->assertStringNotContainsString( 'imagecreate', $script );
		}
	}

	/**
	 * Running the shared script, as every preview does, with no logged-in
	 * user: it takes out WordPress's "Hello world!" post, follows the four
	 * sites under the names it gives them, reads their posts, and makes the
	 * sample Marks. The four sites are answered here, not reached.
	 */
	public function test_shared_script_sets_up_the_whole_demo(): void {
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->post->create(
			array(
				'post_title' => 'Hello world!',
				'post_name'  => 'hello-world',
			)
		);
		wp_set_current_user( 0 );
		add_filter( 'pre_http_request', array( $this, 'answer_demo_sites' ), 1, 3 );

		include $this->repo_path( '.github/blueprints/set-up-demo.php' );

		remove_filter( 'pre_http_request', array( $this, 'answer_demo_sites' ), 1 );

		$this->assertNull( get_page_by_path( 'hello-world', OBJECT, 'post' ) );

		$titles = array();

		foreach ( Daymark_Plugin::instance()->subscriptions->get_active() as $subscription ) {
			$titles[ (string) wp_parse_url( (string) $subscription['site_url'], PHP_URL_HOST ) ] = (string) $subscription['site_title'];
		}

		ksort( $titles );

		$this->assertSame(
			array(
				'aaron.jorb.in'     => 'Aaron Jorbin',
				'jeffpaul.com'      => 'Jeffrey Paul',
				'jondesrosiers.com' => 'Site at jondesrosiers.com',
				'xkcd.com'          => 'xkcd',
			),
			$titles
		);

		$this->assertSame( 4, (int) wp_count_posts( Daymark_Subscription_Post_Type::POST_TYPE )->publish, 'one post read from each site' );

		$marks = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_daymark_is_mark', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small test fixture set.
				'fields'         => 'ids',
			)
		);

		$this->assertCount( 8, $marks );
	}

	/**
	 * Stand in for the demo's four sites: a home page that names its feed,
	 * and a feed with one post. Anything else is refused.
	 *
	 * @param false|array|WP_Error $preempt The answer so far.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return array|WP_Error
	 */
	public function answer_demo_sites( $preempt, $args, $url ) {
		unset( $preempt, $args );

		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$path = trim( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/' );

		if ( ! in_array( $host, array( 'jeffpaul.com', 'jondesrosiers.com', 'aaron.jorb.in', 'xkcd.com' ), true ) || ! in_array( $path, array( '', 'feed' ), true ) ) {
			return new WP_Error( 'blocked', 'Outbound requests are blocked in this test.' );
		}

		$name = 'Site at ' . $host;

		if ( '' === $path ) {
			$type = 'text/html; charset=UTF-8';
			$body = '<!doctype html><html lang="en"><head><meta charset="utf-8" /><title>' . $name . '</title>'
				. '<link rel="alternate" type="application/rss+xml" title="' . $name . '" href="https://' . $host . '/feed/" /></head>'
				. '<body><h1>' . $name . '</h1></body></html>';
		} else {
			$type = 'application/rss+xml; charset=UTF-8';
			$body = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . $name . '</title>'
				. '<link>https://' . $host . '/</link><description>A site.</description>'
				. '<item><title>A post</title><link>https://' . $host . '/a-post/</link>'
				. '<guid isPermaLink="true">https://' . $host . '/a-post/</guid>'
				. '<pubDate>' . gmdate( 'D, d M Y H:i:s', time() - HOUR_IN_SECONDS ) . ' +0000</pubDate>'
				. '<description>Something written.</description></item></channel></rss>';
		}

		$headers = array( 'content-type' => $type );

		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The seed script loads wp-admin/includes/file.php itself before its
	 * first wp_tempnam() call. A Playground runPHP step loads only
	 * wp-load.php, so without this every preview failed with a fatal
	 * "undefined function wp_tempnam()" error. This test suite always has
	 * that file loaded, so running the script here can't catch it.
	 */
	public function test_seed_script_loads_file_helpers_before_using_them(): void {
		$source  = (string) file_get_contents( dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/seed-sample-content.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		$require = strpos( $source, "require_once ABSPATH . 'wp-admin/includes/file.php';" );
		$first   = strpos( $source, "wp_tempnam( '" );

		$this->assertNotFalse( $require );
		$this->assertNotFalse( $first );
		$this->assertLessThan( $first, $require );
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
	 * weather and place lookups are blocked here). Runs with no logged-in
	 * user, as Playground's runPHP step does: the script must sign in as the
	 * site's administrator itself, or every sample is saved as a draft.
	 */
	public function test_seed_script_creates_each_sample_mark(): void {
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
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
			$this->assertSame( 'publish', $post->post_status, "Sample \"{$post->post_title}\" should be published." );
			$by_type[ (string) get_post_meta( $post->ID, '_daymark_primary_type', true ) ][] = $post->ID;
			$this->assertTrue( user_can( (int) $post->post_author, 'manage_options' ), 'authored by an administrator' );
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
