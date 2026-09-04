<?php
/**
 * Daymark_Subscription_Source_Friends tests (issue #88): resolving a site
 * URL to a Friends `WP_User`, mapping cached `friend_post_cache` posts into
 * Daymark's source-agnostic shape, and graceful degradation when Friends
 * isn't active or hasn't added a given site as a friend.
 *
 * Simulates the Friends plugin being active by defining a minimal stub
 * `Friends` class carrying only the one constant this connector depends on
 * (`Friends::CPT`) and registering that post type directly — this sandbox
 * has no way to install the actual third-party plugin, but every WordPress
 * core function the connector calls (get_users(), WP_Query,
 * get_post_format(), get_the_post_thumbnail_url()) runs for real here,
 * unlike a fully-mocked unit test.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Source_Friends. The `Friends` stub class (just
 * enough to stand in for the real plugin's `Friends::CPT` constant) is
 * defined once in tests/bootstrap.php rather than here, since this repo's
 * WordPress Coding Standards ruleset disallows a second top-level class in
 * the same file as the test class itself.
 */
class Test_Subscription_Source_Friends extends WP_UnitTestCase {

	/** @var Daymark_Subscription_Source_Friends */
	private $source;

	/** @var int */
	private $friend_id;

	public static function wpSetUpBeforeClass( $factory ) {
		unset( $factory );

		register_post_type(
			Friends::CPT,
			array(
				'public'   => false,
				'supports' => array( 'title', 'editor', 'author', 'excerpt', 'thumbnail', 'post-formats' ),
			)
		);
	}

	public function set_up(): void {
		parent::set_up();

		$this->source = new Daymark_Subscription_Source_Friends();

		$this->friend_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Jane Doe',
				'user_url'     => 'https://jane.example/',
			)
		);
	}

	/** discover() finds an already-added friend by URL, ignoring scheme and a missing trailing slash. */
	public function test_discover_finds_existing_friend() {
		$result = $this->source->discover( 'jane.example' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://jane.example/', $result[0]['url'] );
		$this->assertSame( 'Jane Doe', $result[0]['title'] );
		$this->assertSame( 'friends', $result[0]['type'] );
	}

	/** discover() finds nothing for a site nobody has added as a friend. */
	public function test_discover_finds_nothing_for_unknown_site() {
		$this->assertSame( array(), $this->source->discover( 'https://stranger.example/' ) );
	}

	/** fetch() builds raw items from the friend's own cached posts entirely via a local query — guid (not get_permalink(), which would be useless for a non-public post type) becomes the permalink. */
	public function test_fetch_builds_raw_items_from_cached_posts() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Friends::CPT,
				'post_author'  => $this->friend_id,
				'post_title'   => 'Hello World',
				'post_content' => '<p>Body</p>',
				'post_status'  => 'publish',
				'guid'         => 'https://jane.example/2024/hello-world/',
			)
		);
		set_post_format( $post_id, 'video' );

		$raw_items = $this->source->fetch( 'https://jane.example/' );

		$this->assertCount( 1, $raw_items );
		$this->assertSame( 'Hello World', $raw_items[0]['title'] );
		$this->assertSame( 'https://jane.example/2024/hello-world/', $raw_items[0]['permalink'] );
		$this->assertSame( 'Jane Doe', $raw_items[0]['author_name'] );
		$this->assertSame( 'video', $raw_items[0]['post_format'] );
	}

	/** normalize() falls back to sniffing an inline image (and promoting the format to 'image') only when Friends set no post_format and no structured thumbnail. */
	public function test_normalize_sniffs_inline_image_without_structured_signals() {
		$normalized = $this->source->normalize(
			array(
				'post_id'      => 1,
				'title'        => 'A note',
				'content'      => '<p>Text with <img src="https://jane.example/inline.jpg"></p>',
				'excerpt'      => '',
				'permalink'    => 'https://jane.example/2024/a-note/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => '',
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://jane.example/inline.jpg', $normalized['featured_image_url'] );
	}

	/** normalize()'s content-sniff fallback also recognizes video, matching Daymark_Subscription_Source_Feed's own richer sniffing, not just a lone image. */
	public function test_normalize_sniffs_inline_video_without_structured_signals() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'A clip',
				'content'      => '<video src="https://jane.example/clip.mp4"></video>',
				'permalink'    => 'https://jane.example/2024/a-clip/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => '',
			)
		);

		$this->assertSame( 'video', $normalized['post_format'] );
	}

	/**
	 * A WordPress-native format with no dedicated Daymark bucket (e.g.
	 * 'quote') still allows the content-sniff fallback to promote it — it
	 * collapses to 'standard' the same as a genuinely unset format, so it's
	 * treated identically once the sniff runs. This matches
	 * Daymark_Subscription_Source_Microformats's own precedent: post_format
	 * tracks visual media, not a post's semantic kind, so an entry can carry
	 * both a real kind signal (there, u-in-reply-to; here, 'quote') and a
	 * separate, still-honored photo signal.
	 */
	public function test_normalize_sniffs_inline_image_even_with_an_unmapped_format() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'A quote',
				'content'      => '<img src="https://jane.example/inline.jpg">',
				'permalink'    => 'https://jane.example/2024/a-quote/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => 'quote',
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://jane.example/inline.jpg', $normalized['featured_image_url'] );
	}

	/** The content-sniff fallback never overrides a real, Daymark-mapped media format — even one that conflicts with what the content itself sniffs as. */
	public function test_normalize_never_sniffs_when_a_real_media_format_is_already_assigned() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'A clip',
				'content'      => '<img src="https://jane.example/inline.jpg">',
				'permalink'    => 'https://jane.example/2024/a-clip/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => 'video',
			)
		);

		// A real 'video' format wins even though the content itself sniffs as image.
		$this->assertSame( 'video', $normalized['post_format'] );
	}

	/** normalize() defaults to 'standard' with no featured image when there is no post_format and no image to sniff either. */
	public function test_normalize_defaults_to_standard_without_media() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'Plain text',
				'content'      => 'No markup here at all.',
				'permalink'    => 'https://jane.example/2024/plain/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => '',
			)
		);

		$this->assertSame( 'standard', $normalized['post_format'] );
		$this->assertSame( '', $normalized['featured_image_url'] );
	}

	/** fetch() returns an empty (not error) array for a friend with no current cached posts — a healthy, quiet state, not a failure. */
	public function test_fetch_returns_empty_array_when_no_cached_posts() {
		$this->assertSame( array(), $this->source->fetch( 'https://jane.example/' ) );
	}

	/** fetch() returns a WP_Error for a site that was never added as a friend. */
	public function test_fetch_returns_error_for_unknown_friend() {
		$this->assertWPError( $this->source->fetch( 'https://never-added.example/' ) );
	}

	/**
	 * discover()/fetch() themselves never make a network request for a site
	 * Friends already follows — the one place subscribe_to_site() still
	 * can (its own favicon/site-title enhancement, which runs uniformly
	 * for every source_type and isn't this connector's concern) is blocked
	 * here to prove it, and the subscription still ends up correct even
	 * when that orthogonal, best-effort lookup fails.
	 */
	public function test_subscribe_records_friends_source_type_and_canonical_url() {
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10, 3 );

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$result        = $subscriptions->subscribe_to_site( 'jane.example' );

		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );

		$this->assertIsInt( $result );

		$row = $subscriptions->get( $result );
		$this->assertSame( 'friends', $row['source_type'] );
		$this->assertSame( 'https://jane.example/', $row['feed_url'] );
	}

	/**
	 * Short-circuits every HTTP request made during a test with a WP_Error.
	 *
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL (unused).
	 * @return WP_Error
	 */
	public function block_http_request( $preempt, $parsed_args, $url ) {
		unset( $preempt, $parsed_args, $url );

		return new WP_Error( 'daymark_test_http_blocked', 'HTTP blocked in test' );
	}
}
