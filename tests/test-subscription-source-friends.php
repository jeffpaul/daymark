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

		// The Friends plugin keeps each person you follow as a user in its own
		// `subscription` role; the plugin is not active in this suite, so the
		// role is registered here the way it would be.
		add_role( 'subscription', 'Subscription' );

		$this->friend_id = self::factory()->user->create(
			array(
				'role'         => 'subscription',
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

	/**
	 * Any account can edit its own profile website. One that points at a
	 * friend's URL must not be matched as that friend (which would shadow
	 * the real one and leave the subscription empty), whatever its role.
	 *
	 * @dataProvider non_friend_role_provider
	 *
	 * @param string $role Role of the account claiming the URL.
	 */
	public function test_discover_ignores_a_non_friend_account_claiming_a_url( string $role ) {
		self::factory()->user->create(
			array(
				'role'         => $role,
				'display_name' => 'Impostor',
				'user_url'     => 'https://claimed.example/',
			)
		);

		$this->assertSame( array(), $this->source->discover( 'https://claimed.example/' ) );
	}

	/**
	 * Roles that hold a profile website but are not Friends relationships.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function non_friend_role_provider(): array {
		return array(
			'subscriber'  => array( 'subscriber' ),
			'contributor' => array( 'contributor' ),
			'author'      => array( 'author' ),
			'editor'      => array( 'editor' ),
			'admin'       => array( 'administrator' ),
		);
	}

	/** When an impostor and a real friend claim the same URL, the friend is the one found. */
	public function test_discover_prefers_the_real_friend_over_an_impostor() {
		self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Impostor',
				'user_url'     => 'https://jane.example/',
			)
		);

		$result = $this->source->discover( 'https://jane.example/' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Jane Doe', $result[0]['title'] );
	}

	/** The role list is filterable, for a Friends install that uses a role of its own. */
	public function test_the_friend_role_list_is_filterable() {
		add_role( 'custom_friend', 'Custom friend' );
		self::factory()->user->create(
			array(
				'role'         => 'custom_friend',
				'display_name' => 'Custom Friend',
				'user_url'     => 'https://custom.example/',
			)
		);

		$this->assertSame( array(), $this->source->discover( 'https://custom.example/' ) );

		$add = static function ( $roles ) {
			$roles[] = 'custom_friend';

			return $roles;
		};
		add_filter( 'daymark_subscription_friends_roles', $add );

		$result = $this->source->discover( 'https://custom.example/' );

		remove_filter( 'daymark_subscription_friends_roles', $add );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Custom Friend', $result[0]['title'] );
	}

	/** discover() finds nothing for a site nobody has added as a friend. */
	public function test_discover_finds_nothing_for_unknown_site() {
		$this->assertSame( array(), $this->source->discover( 'https://stranger.example/' ) );
	}

	/** A boost Friends cached from the fediverse becomes a reblog of the boosted post (issue #168). */
	public function test_a_boost_becomes_a_repost_of_the_boosted_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Friends::CPT,
				'post_author'  => $this->friend_id,
				'post_title'   => '',
				'post_content' => '<p>Someone else wrote this.</p>',
				'post_status'  => 'publish',
				'guid'         => 'https://social.example/@pat/123',
			)
		);
		update_post_meta( $post_id, 'activitypub', array( 'reblog' => true ) );

		$raw_items  = $this->source->fetch( 'https://jane.example/' );
		$normalized = $this->source->normalize( $raw_items[0] );

		$this->assertTrue( $raw_items[0]['reblog'] );
		$this->assertSame( 'repost', $normalized['interaction'] );
		$this->assertSame( 'https://social.example/@pat/123', $normalized['interaction_url'] );
	}

	/** An ordinary cached post has no interaction. */
	public function test_an_ordinary_post_has_no_interaction() {
		$normalized = $this->source->normalize(
			array(
				'title'     => 'Hello',
				'content'   => '<p>Hi.</p>',
				'permalink' => 'https://jane.example/hello/',
			)
		);

		$this->assertSame( '', $normalized['interaction'] );
	}

	/** Friends' link and quote formats get the same treatment as the WordPress REST source's (issue #168). */
	public function test_normalize_keeps_link_and_quote_formats() {
		$link  = $this->source->normalize(
			array(
				'title'       => 'A link',
				'content'     => '<p><a href="https://news.example/story">A story</a></p>',
				'permalink'   => 'https://jane.example/a-link/',
				'post_format' => 'link',
			)
		);
		$quote = $this->source->normalize(
			array(
				'title'       => 'A quote',
				'content'     => '<blockquote><p>Less, but better.</p></blockquote><p>&mdash; <cite>Dieter Rams</cite></p>',
				'permalink'   => 'https://jane.example/a-quote/',
				'post_format' => 'quote',
			)
		);

		$this->assertSame( 'link', $link['post_format'] );
		$this->assertSame( 'https://news.example/story', $link['link_url'] );
		$this->assertSame( 'quote', $quote['post_format'] );
		$this->assertSame( 'Less, but better.', $quote['quote_text'] );
		$this->assertSame( 'Dieter Rams', $quote['quote_credit'] );
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

	/** normalize() treats a cached post_excerpt that's just a leftover placeholder word (never replaced before publishing) the same as an empty one, falling back to the cached post's own content instead. */
	public function test_normalize_falls_back_to_content_for_placeholder_excerpt() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'A note',
				'content'      => 'The real body text readers actually want.',
				'excerpt'      => 'Excerpt',
				'permalink'    => 'https://jane.example/2024/a-note/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => '',
			)
		);

		$this->assertSame( 'The real body text readers actually want.', $normalized['excerpt'] );
	}

	/** normalize() still trusts a genuinely short-but-real cached excerpt — brevity alone is never a placeholder signal. */
	public function test_normalize_trusts_a_genuinely_short_manual_excerpt() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'A note',
				'content'      => 'An entirely different, much longer body.',
				'excerpt'      => 'Big news today.',
				'permalink'    => 'https://jane.example/2024/a-note/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => '',
			)
		);

		$this->assertSame( 'Big news today.', $normalized['excerpt'] );
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
				'title'        => 'A post',
				'content'      => '<img src="https://jane.example/inline.jpg">',
				'permalink'    => 'https://jane.example/2024/a-post/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => 'some-future-format',
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

	/**
	 * A confirmed media format (a real Friends-assigned post_format) with no
	 * structured thumbnail — no `post_id` here, matching a cached post whose
	 * get_the_post_thumbnail_url() came back empty, the classic "Image"
	 * post-format theme convention that shows a post's own first inline
	 * image without ever calling set_post_thumbnail() (issue #313) — still
	 * gets a thumbnail sniffed from its own content. The format itself
	 * stays exactly as assigned; only featured_image_url is filled in.
	 */
	public function test_normalize_sniffs_image_fallback_for_confirmed_format_with_no_thumbnail() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'A snap',
				'content'      => '<img src="https://jane.example/inline.jpg">',
				'permalink'    => 'https://jane.example/2024/a-snap/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => 'image',
			)
		);

		$this->assertSame( 'image', $normalized['post_format'] );
		$this->assertSame( 'https://jane.example/inline.jpg', $normalized['featured_image_url'] );
	}

	/** normalize() maps a Friends-assigned status/chat/aside post_format to Daymark's own 'note' bucket, matching Daymark_Subscription_Source_WordPress's own mapping. */
	public function test_normalize_maps_status_and_chat_formats_to_note() {
		foreach ( array( 'status', 'chat', 'aside' ) as $wp_format ) {
			$normalized = $this->source->normalize(
				array(
					'title'        => 'An update',
					'content'      => 'Just a quick note.',
					'permalink'    => 'https://jane.example/2024/an-update/',
					'published_at' => '2024-03-05 10:00:00',
					'author_name'  => 'Jane Doe',
					'post_format'  => $wp_format,
				)
			);

			$this->assertSame( 'note', $normalized['post_format'], "post_format '$wp_format' should map to 'note'" );
		}
	}

	/** The content-sniff fallback never runs for (nor overrides) a 'note' result — it's a real, confirmed signal, not an ambiguous 'standard'. */
	public function test_normalize_never_sniffs_a_note_format() {
		$normalized = $this->source->normalize(
			array(
				'title'        => 'An update',
				'content'      => '<img src="https://jane.example/inline.jpg">',
				'permalink'    => 'https://jane.example/2024/an-update/',
				'published_at' => '2024-03-05 10:00:00',
				'author_name'  => 'Jane Doe',
				'post_format'  => 'status',
			)
		);

		$this->assertSame( 'note', $normalized['post_format'] );
		$this->assertSame( '', $normalized['featured_image_url'] );
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
