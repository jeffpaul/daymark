<?php
/**
 * Tests for the wordpress.org Live Preview blueprint and its made-up friends.
 *
 * @package Daymark
 */

/**
 * The plugin page's Live Preview runs `.wordpress-org/blueprints/blueprint.json`
 * (SVN `assets/blueprints/blueprint.json`). These tests hold it to what
 * wordpress.org accepts, and run the made-up friends it follows for real,
 * with every other outbound request refused.
 */
class Test_Wporg_Blueprint extends WP_UnitTestCase {

	/**
	 * Requests that reached the network filter without an answer.
	 *
	 * @var string[]
	 */
	private $unanswered = array();

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
	 * Decode the wordpress.org blueprint from disk.
	 *
	 * @return array<string, mixed>
	 */
	private function blueprint(): array {
		$blueprint = json_decode(
			(string) file_get_contents( $this->repo_path( '.wordpress-org/blueprints/blueprint.json' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository file.
			true
		);

		$this->assertIsArray( $blueprint );

		return $blueprint;
	}

	/**
	 * The blueprint's steps of one kind.
	 *
	 * @param string $name Step name.
	 * @return array<int, array<string, mixed>>
	 */
	private function steps( string $name ): array {
		return array_values(
			array_filter(
				(array) ( $this->blueprint()['steps'] ?? array() ),
				static function ( $step ) use ( $name ) {
					return is_array( $step ) && ( $step['step'] ?? '' ) === $name;
				}
			)
		);
	}

	/**
	 * Where the blueprint puts the demo files on the preview site.
	 *
	 * @return string
	 */
	private static function demo_dir(): string {
		return WP_CONTENT_DIR . '/daymark-demo';
	}

	/**
	 * Remove the copied demo files.
	 *
	 * @return void
	 */
	public static function tear_down_after_class() {
		$dir = self::demo_dir();

		if ( is_dir( $dir ) ) {
			foreach ( array_merge( (array) glob( $dir . '/sample-images/*' ), (array) glob( $dir . '/*.*' ) ) as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}

			rmdir( $dir . '/sample-images' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		}

		parent::tear_down_after_class();
	}

	/**
	 * Lay the demo files out as the blueprint does (a copy of
	 * `.github/blueprints/` in `wp-content/daymark-demo/`), load the made-up
	 * friends from there, and put their answering filter back (the test
	 * framework resets hooks between tests, and the file only loads once).
	 *
	 * @return void
	 */
	private function load_friends(): void {
		$source = $this->repo_path( '.github/blueprints' );
		$dir    = self::demo_dir();

		if ( ! is_dir( $dir . '/sample-images' ) ) {
			wp_mkdir_p( $dir . '/sample-images' );

			foreach ( array_merge( (array) glob( $source . '/*.*' ), (array) glob( $source . '/sample-images/*' ) ) as $file ) {
				copy( $file, $dir . substr( $file, strlen( $source ) ) );
			}
		}

		$first_load = ! function_exists( 'daymark_sample_friends_answer' );

		require_once $dir . '/sample-friends.php';

		if ( $first_load ) {
			// On the preview site nothing else attaches the filter, so the
			// file has to do it itself when it is loaded.
			$this->assertSame( 1, has_filter( 'pre_http_request', 'daymark_sample_friends_answer' ) );
		} else {
			add_filter( 'pre_http_request', 'daymark_sample_friends_answer', 1, 3 );
		}

		add_filter( 'pre_http_request', array( $this, 'refuse_unanswered' ), 99, 3 );
	}

	/**
	 * Refuse, and remember, any request the made-up friends did not answer.
	 *
	 * @param false|array|WP_Error $preempt The answer so far.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return array|WP_Error
	 */
	public function refuse_unanswered( $preempt, $args, $url ) {
		unset( $args );

		if ( false !== $preempt ) {
			return $preempt;
		}

		$this->unanswered[] = (string) $url;

		return new WP_Error( 'blocked', 'Outbound requests are blocked in this test.' );
	}

	/**
	 * Wordpress.org reads one file, `blueprint.json`, skips it when it is not
	 * valid JSON, and skips it when it is over 100 KB.
	 */
	public function test_blueprint_is_a_file_wordpress_org_accepts(): void {
		$path = $this->repo_path( '.wordpress-org/blueprints/blueprint.json' );

		$this->assertFileExists( $path );
		$this->assertLessThan( 100 * KB_IN_BYTES, filesize( $path ) );
		$this->assertSame( '/daymark', $this->blueprint()['landingPage'] ?? '' );
		$this->assertTrue( $this->blueprint()['login'] ?? false );
	}

	/**
	 * The preview installs the released plugin from wordpress.org, never a
	 * development copy.
	 *
	 * The step names the plugin under both `pluginData` (the current key)
	 * and `pluginZipFile` (the older one). Wordpress.org's importer looked
	 * for the older key when this was written, and adds a second install
	 * step of its own to the end when it finds none.
	 */
	public function test_blueprint_installs_the_released_plugin(): void {
		$installs = $this->steps( 'installPlugin' );
		$expected = array(
			'resource' => 'wordpress.org/plugins',
			'slug'     => 'daymark',
		);

		$this->assertCount( 1, $installs );
		$this->assertSame( $expected, $installs[0]['pluginData'] ?? null );
		$this->assertSame( $expected, $installs[0]['pluginZipFile'] ?? null );
		$this->assertTrue( $installs[0]['options']['activate'] ?? false );
	}

	/**
	 * The demo files come from this repository's `.github/blueprints/`
	 * folder, and the blueprint names files that are really there.
	 */
	public function test_blueprint_reads_demo_files_that_exist(): void {
		$from_git = array_values(
			array_filter(
				$this->steps( 'writeFiles' ),
				static function ( $step ) {
					return 'git:directory' === ( $step['filesTree']['resource'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $from_git );
		$this->assertSame( 'https://github.com/jeffpaul/daymark', $from_git[0]['filesTree']['url'] );
		$this->assertSame( 'main', $from_git[0]['filesTree']['ref'] );
		$this->assertSame( '.github/blueprints', $from_git[0]['filesTree']['path'] );
		$this->assertSame( '/wordpress/wp-content/daymark-demo', $from_git[0]['writeToPath'] );

		$encoded = (string) wp_json_encode( $this->blueprint(), JSON_UNESCAPED_SLASHES );

		foreach ( array( 'sample-friends.php', 'seed-sample-content.php' ) as $file ) {
			$this->assertStringContainsString( "/daymark-demo/$file", $encoded );
			$this->assertFileExists( $this->repo_path( ".github/blueprints/$file" ) );
		}

		$run = $this->steps( 'runPHP' );

		$this->assertCount( 1, $run );
		$this->assertStringContainsString( 'daymark_sample_friends_follow()', (string) $run[0]['code'] );
	}

	/**
	 * The friends are made up: every address is under `.example`, which is
	 * reserved and can never belong to a real person, and every picture and
	 * avatar they use is in the repository.
	 */
	public function test_friends_are_made_up_and_their_pictures_exist(): void {
		$this->load_friends();

		$friends = daymark_sample_friends();

		$this->assertCount( 4, $friends );

		foreach ( $friends as $host => $friend ) {
			$this->assertStringEndsWith( '.example', $host );
			$this->assertFileExists( $this->repo_path( '.github/blueprints/sample-images/' . $friend['icon'] ) );

			foreach ( $friend['posts'] as $post ) {
				foreach ( $post['images'] as $image ) {
					$this->assertFileExists( $this->repo_path( ".github/blueprints/sample-images/$image.jpg" ) );
				}
			}
		}
	}

	/**
	 * Following the friends goes through Daymark's own follow and read
	 * steps and fills the Timeline, with no request leaving the site.
	 */
	public function test_following_the_friends_fills_the_timeline(): void {
		$this->load_friends();

		$this->assertSame( 4, daymark_sample_friends_follow() );
		$this->assertSame( 0, daymark_sample_friends_follow(), 'A second run follows nobody twice.' );

		$subscriptions = Daymark_Plugin::instance()->subscriptions->get_active();
		$titles        = wp_list_pluck( $subscriptions, 'site_title' );

		$this->assertEqualsCanonicalizing( array( 'Sarah Chen', 'Ana Ruiz', 'Marcus Webb', 'Priya Nair' ), $titles );

		foreach ( $subscriptions as $subscription ) {
			$this->assertSame( 'feed', $subscription['source_type'] );
			$this->assertStringStartsWith( content_url( '/daymark-demo/sample-images/friend-' ), (string) $subscription['site_icon_url'] );
			$this->assertSame( 0, (int) $subscription['consecutive_failure_count'] );
		}

		$posts = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$this->assertCount( 8, $posts );

		$formats = array();

		foreach ( $posts as $post ) {
			$format    = (string) get_post_meta( $post->ID, 'post_format', true );
			$formats[] = $format;

			// Pictures come from this site: a made-up address serves none.
			if ( 'standard' !== $format ) {
				$this->assertStringStartsWith( content_url( '/daymark-demo/sample-images/' ), (string) get_post_meta( $post->ID, 'featured_image_url', true ) );
			}

			if ( 'gallery' === $format ) {
				$this->assertCount( 3, Daymark_Subscription_Poller::gallery_images_for( $post->ID ) );
			}

			$this->assertStringContainsString( '.example/', (string) get_post_meta( $post->ID, 'permalink', true ) );
			$this->assertNotSame( '', $post->post_excerpt );
			$this->assertLessThan( time(), strtotime( (string) get_post_meta( $post->ID, 'published_at', true ) . ' UTC' ) );
		}

		$counts = array_count_values( $formats );

		$this->assertSame( 2, $counts['image'] ?? 0, 'two photo posts' );
		$this->assertSame( 1, $counts['gallery'] ?? 0, 'one gallery' );
		$this->assertSame( 5, $counts['standard'] ?? 0, 'five written posts' );

		$this->assertSame( array(), $this->unanswered, 'No request should leave the site.' );
	}

	/**
	 * Opening a friend's post reads its page, and refreshing reads the feed
	 * again without adding a post twice.
	 */
	public function test_a_friends_post_opens_and_a_refresh_adds_nothing(): void {
		$this->load_friends();
		daymark_sample_friends_follow();

		$plugin = Daymark_Plugin::instance();
		$posts  = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'posts_per_page' => 1,
				'title'          => 'What a year of Sunday bread taught me',
			)
		);

		$this->assertCount( 1, $posts );
		$this->assertTrue( $plugin->subscription_poller->fetch_full_content( $posts[0]->ID ) );
		$this->assertSame( 'full', get_post_meta( $posts[0]->ID, 'content_state', true ) );
		$this->assertStringContainsString( 'Fifty-two loaves', (string) get_post_meta( $posts[0]->ID, 'body_content', true ) );
		$this->assertStringNotContainsString( '<h1', (string) get_post_meta( $posts[0]->ID, 'body_content', true ) );

		foreach ( $plugin->subscriptions->get_active() as $subscription ) {
			$this->assertTrue( $plugin->subscription_poller->poll_subscription( (int) $subscription['id'] ) );
		}

		$this->assertSame( 8, (int) wp_count_posts( Daymark_Subscription_Post_Type::POST_TYPE )->publish );
		$this->assertSame( array(), $this->unanswered, 'No request should leave the site.' );
	}

	/**
	 * A request to any other address is left alone.
	 */
	public function test_other_addresses_are_not_answered(): void {
		$this->load_friends();

		$this->assertFalse( daymark_sample_friends_answer( false, array(), 'https://wordpress.org/news/feed/' ) );
		$this->assertSame( 404, daymark_sample_friends_answer( false, array(), 'https://sarahchen.example/wp-json/' )['response']['code'] );
	}
}
