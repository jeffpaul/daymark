<?php
/**
 * Optional Parse This integration (Daymark_Parse_This) and its callers:
 * link previews, the microformats source, reply context, and the Reblog
 * quote's author credit.
 *
 * Runs against the stub ParseThis\Parser (tests/parse-this-stub/), which
 * returns canned jf2. With DAYMARK_PARSE_THIS_DIR set, the real plugin
 * loads instead and the tests that need the stub are skipped.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Parse_This with the stub parser.
 */
class Test_Parse_This extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		if ( class_exists( 'Daymark_Test_Parse_This_Stub' ) ) {
			Daymark_Test_Parse_This_Stub::reset();
		}

		Daymark_Subscription_Html_Cache::reset();
		Daymark_Subscriptions::install();

		$this->http_responses = array();
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );
		remove_all_filters( 'daymark_use_parse_this' );

		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		unset( $parsed_args );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * @param string $url  URL to mock.
	 * @param string $body Response body.
	 * @return void
	 */
	private function mock_response( string $url, string $body ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Skip a test that needs the stub when the real Parse This is loaded. */
	private function require_stub(): void {
		if ( ! class_exists( 'Daymark_Test_Parse_This_Stub' ) ) {
			$this->markTestSkipped( 'The real Parse This is loaded; this test uses the stub.' );
		}
	}

	/** Turn Parse This on, with the stub returning $jf2. */
	private function use_parse_this( ?array $jf2 ): void {
		$this->require_stub();
		add_filter( 'daymark_use_parse_this', '__return_true' );
		Daymark_Test_Parse_This_Stub::$jf2 = $jf2;
	}

	/** Without Parse This 2.0.0 or later loaded, nothing uses it. */
	public function test_not_available_by_default() {
		$this->require_stub();
		$this->assertFalse( Daymark_Parse_This::available() );
		$this->assertNull( Daymark_Parse_This::parse_html( '<p>hi</p>', 'https://example.com/' ) );
		$this->assertSame( array(), Daymark_Test_Parse_This_Stub::$calls );
	}

	/** The HTML Daymark fetched is parsed in place, with every extra request turned off. */
	public function test_parse_html_parses_given_html_with_no_extra_requests() {
		$this->use_parse_this(
			array(
				'type' => 'entry',
				'name' => 'Hello',
			)
		);

		$jf2 = Daymark_Parse_This::parse_html( '<html>page</html>', 'https://example.com/post', 'single' );

		$this->assertSame( 'Hello', $jf2['name'] );
		$this->assertSame( '<html>page</html>', Daymark_Test_Parse_This_Stub::$calls[0]['content'] );
		$this->assertSame( 'https://example.com/post', Daymark_Test_Parse_This_Stub::$calls[0]['url'] );
		$this->assertSame( 0, Daymark_Test_Parse_This_Stub::$max_requests );
		$this->assertFalse( Daymark_Test_Parse_This_Stub::$args['follow'] );
		$this->assertFalse( Daymark_Test_Parse_This_Stub::$args['require_content'] );
		$this->assertSame( 10, (int) apply_filters( 'parse_this_max_requests', 10, '' ), 'The request limit is restored afterwards.' );
	}

	/** A failed parse is null, so callers fall back to their own parser. */
	public function test_parse_html_returns_null_when_parsing_fails() {
		$this->use_parse_this( null );

		$this->assertNull( Daymark_Parse_This::parse_html( '<html></html>', 'https://example.com/' ) );
	}

	/** jf2 helpers read lists, objects, and cards, and keep only http(s) URLs. */
	public function test_jf2_helpers() {
		$item = array(
			'name'        => array( 'Title' ),
			'content'     => array(
				'html' => '<p>Body</p>',
				'text' => 'Body',
			),
			'author'      => array(
				array(
					'type' => 'card',
					'name' => 'Jane',
				),
			),
			'photo'       => array(
				array(
					'value' => 'https://example.com/a.jpg',
					'alt'   => 'A',
				),
				'https://example.com/b.jpg',
				'javascript:alert(1)',
			),
			'in-reply-to' => array( 'https://other.example/post' ),
		);

		$this->assertSame( 'Title', Daymark_Parse_This::text( $item, 'name' ) );
		$this->assertSame( 'Body', Daymark_Parse_This::text( $item, 'content' ) );
		$this->assertSame( '<p>Body</p>', Daymark_Parse_This::content_html( $item ) );
		$this->assertSame( 'Jane', Daymark_Parse_This::author_name( $item ) );
		$this->assertSame( array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ), Daymark_Parse_This::urls( $item, 'photo' ) );
		$this->assertSame( array( 'https://other.example/post' ), Daymark_Parse_This::urls( $item, 'in-reply-to' ) );
	}

	/** Link previews prefer Parse This's values and gain author and date; meta tags fill the rest. */
	public function test_link_preview_uses_parse_this_and_keeps_meta_tag_fallbacks() {
		$this->use_parse_this(
			array(
				'type'      => 'entry',
				'name'      => 'Real Title',
				'author'    => array(
					'type' => 'card',
					'name' => 'Jane Doe',
				),
				'published' => '2026-09-01T10:00:00-04:00',
			)
		);

		$this->mock_response(
			'https://blog.example/pt-post',
			'<html><head><meta property="og:title" content="OG Title"><meta property="og:description" content="OG description."><meta property="og:image" content="https://blog.example/cover.jpg"></head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/pt-post' );

		$this->assertSame( 'Real Title', $result['title'] );
		$this->assertSame( 'OG description.', $result['description'] );
		$this->assertSame( 'https://blog.example/cover.jpg', $result['image'] );
		$this->assertSame( 'Jane Doe', $result['author'] );
		$this->assertSame( '2026-09-01T14:00:00Z', $result['published'] );
	}

	/** Without Parse This, a preview has no author or date keys at all. */
	public function test_link_preview_without_parse_this_is_unchanged() {
		$this->require_stub();
		$this->mock_response(
			'https://blog.example/plain',
			'<html><head><meta property="og:title" content="OG Title"></head></html>'
		);

		$result = Daymark_Subscription_Opengraph::resolve( 'https://blog.example/plain' );

		$this->assertSame( 'OG Title', $result['title'] );
		$this->assertArrayNotHasKey( 'author', $result );
		$this->assertArrayNotHasKey( 'published', $result );
	}

	/** The microformats source reads Parse This's entries when it finds microformats. */
	public function test_microformats_source_uses_parse_this_entries() {
		$this->use_parse_this(
			array(
				'type'           => 'feed',
				'_source_format' => 'mf2+html',
				'items'          => array(
					array(
						'type'        => 'entry',
						'url'         => array( 'https://mf2.example/1' ),
						'content'     => array(
							'html' => '<p>Replying here</p>',
							'text' => 'Replying here',
						),
						'published'   => array( '2026-09-01T10:00:00Z' ),
						'author'      => array(
							array(
								'type' => 'card',
								'name' => 'Pat',
							),
						),
						'post-type'   => 'reply',
						'in-reply-to' => array( 'https://other.example/original' ),
					),
					array(
						'type'      => 'entry',
						'name'      => array( 'Photo day' ),
						'url'       => array( 'https://mf2.example/2' ),
						'photo'     => array( 'https://mf2.example/p1.jpg', 'https://mf2.example/p2.jpg' ),
						'post-type' => 'photo',
					),
				),
			)
		);

		$this->mock_response( 'https://mf2.example/', '<html><body><div class="h-feed">ignored by the stub</div></body></html>' );

		$source = new Daymark_Subscription_Source_Microformats();
		$raw    = $source->fetch( 'https://mf2.example/' );

		$this->assertCount( 2, $raw );

		$reply = $source->normalize( $raw[0] );
		$this->assertSame( 'https://mf2.example/1', $reply['permalink'] );
		$this->assertSame( 'Pat', $reply['author'] );
		$this->assertSame( 'note', $reply['post_format'] );
		$this->assertSame( 'https://other.example/original', $reply['in_reply_to'] );

		$photos = $source->normalize( $raw[1] );
		$this->assertSame( 'gallery', $photos['post_format'] );
		$this->assertSame( '', $photos['in_reply_to'] );
	}

	/** A page Parse This reads only from meta tags falls back to Daymark's own scanner. */
	public function test_microformats_source_falls_back_without_mf2() {
		$this->use_parse_this(
			array(
				'type'           => 'entry',
				'_source_format' => 'html',
				'name'           => 'From meta tags',
			)
		);

		$this->mock_response(
			'https://mf2.example/own',
			'<html><body><div class="h-entry"><a class="u-url" href="https://mf2.example/own/1">#</a><p class="p-name">Own scanner</p><a class="u-in-reply-to" href="https://other.example/x">re</a></div></body></html>'
		);

		$source = new Daymark_Subscription_Source_Microformats();
		$raw    = $source->fetch( 'https://mf2.example/own' );
		$item   = $source->normalize( $raw[0] );

		$this->assertSame( 'Own scanner', $item['title'] );
		$this->assertSame( 'https://other.example/x', $item['in_reply_to'] );
	}

	/** A reply's target is stored on ingest and sent to the app. */
	public function test_reply_target_is_stored_and_reported() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$subscription_id = (int) ( new Daymark_Subscriptions() )->create(
			array(
				'site_url'    => 'https://mf2.example/',
				'feed_url'    => 'https://mf2.example/',
				'source_type' => 'microformats',
			)
		);

		$post_id = ( new Daymark_Subscription_Poller() )->maybe_ingest_item(
			$subscription_id,
			array(
				'title'        => '',
				'excerpt'      => 'A reply',
				'author'       => 'Pat',
				'published_at' => gmdate( 'Y-m-d H:i:s' ),
				'permalink'    => 'https://mf2.example/reply-1',
				'post_format'  => 'note',
				'in_reply_to'  => 'https://other.example/original',
			)
		);

		$this->assertSame( 'https://other.example/original', get_post_meta( $post_id, 'in_reply_to', true ) );

		$this->mock_response( 'https://other.example/original', '<html><head><meta property="og:title" content="The original"></head></html>' );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/subscription-posts/' . $post_id . '/oembed' );
		$request->set_param( 'target', 'reply' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 'The original', $data['title'] );
		$this->assertSame( 'https://other.example/original', $data['url'] );
	}

	/** A Reblog's quote credits the author and the site. */
	public function test_reblog_quote_credits_the_author() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/daymark/v1/marks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'caption', 'Worth reading.' );
		$request->set_param( 'primary_type', 'note' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'repost_of', 'https://blog.example/great-post' );
		$request->set_param( 'quote_title', 'A Great Post' );
		$request->set_param( 'quote_author', 'Jane Doe' );

		$response = rest_do_request( $request );
		$this->assertLessThan( 300, $response->get_status() );

		$content = (string) get_post_field( 'post_content', (int) $response->get_data()['id'] );

		$this->assertStringContainsString( '<!-- wp:quote -->', $content );
		$this->assertStringContainsString( '<cite>Jane Doe, blog.example</cite>', $content );
	}
}
