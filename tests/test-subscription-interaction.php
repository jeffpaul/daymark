<?php
/**
 * Tests for a followed post's interaction (issue #168): the shared
 * vocabulary in Daymark_Subscription_Interaction, the WordPress-format and
 * quote helpers in Daymark_Subscription_Content_Sniffer, storing both on
 * ingest (and filling them in on a later poll), the REST summary, the
 * interaction preview endpoint, and leaving a followed site's likes out of
 * the Timeline.
 *
 * @package Daymark
 */

/**
 * Exercises interactions from source output to the REST response.
 */
class Test_Subscription_Interaction extends WP_UnitTestCase {

	/** @var int */
	private $subscription_id;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->subscription_id = (int) ( new Daymark_Subscriptions() )->create(
			array(
				'site_url' => 'https://follow.example/',
				'feed_url' => 'https://follow.example/feed/',
			)
		);

		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );

		parent::tear_down();
	}

	/**
	 * Answers a request for the one page the preview tests use; blocks
	 * everything else.
	 *
	 * @param mixed  $preempt Existing short-circuit value.
	 * @param array  $args    Request args (unused).
	 * @param string $url     Requested URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $preempt, $args, $url ) {
		if ( 'https://other.example/original' === $url ) {
			return array(
				'headers'  => array( 'content-type' => 'text/html' ),
				'body'     => '<html><head><meta property="og:title" content="The original"></head></html>',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Blocked: ' . $url );
	}

	/**
	 * Ingest one item through the poller.
	 *
	 * @param array<string, mixed> $fields Normalized fields to merge in.
	 * @return int
	 */
	private function ingest( array $fields ): int {
		static $n = 0;
		++$n;

		return ( new Daymark_Subscription_Poller() )->maybe_ingest_item(
			$this->subscription_id,
			array_merge(
				array(
					'title'        => 'Item ' . $n,
					'excerpt'      => 'Excerpt ' . $n,
					'author'       => 'Pat',
					'published_at' => gmdate( 'Y-m-d H:i:s', time() - $n ),
					'permalink'    => 'https://follow.example/item-' . $n,
					'post_format'  => 'standard',
				),
				$fields
			)
		);
	}

	/**
	 * Run a REST request with a valid nonce.
	 *
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Query params.
	 * @return mixed
	 */
	private function get( string $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request )->get_data();
	}

	// -----------------------------------------------------------------
	// Daymark_Subscription_Interaction
	// -----------------------------------------------------------------

	public function test_sanitize_keeps_a_known_type_and_http_url() {
		$this->assertSame(
			array(
				'interaction'      => 'repost',
				'interaction_url'  => 'https://other.example/post',
				'interaction_rsvp' => '',
			),
			Daymark_Subscription_Interaction::sanitize( 'repost', 'https://other.example/post', 'yes' )
		);
	}

	public function test_sanitize_empties_an_unknown_type() {
		$this->assertSame(
			array(
				'interaction'      => '',
				'interaction_url'  => '',
				'interaction_rsvp' => '',
			),
			Daymark_Subscription_Interaction::sanitize( 'note', 'https://other.example/post' )
		);
	}

	public function test_sanitize_drops_a_non_http_target() {
		$this->assertSame( '', Daymark_Subscription_Interaction::sanitize( 'like', 'javascript:alert(1)' )['interaction_url'] );
	}

	public function test_sanitize_keeps_only_known_rsvp_answers() {
		$this->assertSame( 'interested', Daymark_Subscription_Interaction::sanitize( 'rsvp', '', ' Interested ' )['interaction_rsvp'] );
		$this->assertSame( '', Daymark_Subscription_Interaction::sanitize( 'rsvp', '', 'definitely' )['interaction_rsvp'] );
	}

	public function test_from_post_reads_the_earlier_reply_meta() {
		$post_id = $this->ingest( array() );
		update_post_meta( $post_id, 'in_reply_to', 'https://other.example/original' );

		$this->assertSame(
			array(
				'type' => 'reply',
				'url'  => 'https://other.example/original',
				'rsvp' => '',
			),
			Daymark_Subscription_Interaction::from_post( $post_id )
		);
	}

	// -----------------------------------------------------------------
	// Daymark_Subscription_Content_Sniffer helpers
	// -----------------------------------------------------------------

	/**
	 * @dataProvider wordpress_format_provider
	 *
	 * @param string $wp_format WordPress post format.
	 * @param string $expected  Daymark post_format.
	 */
	public function test_wordpress_format( string $wp_format, string $expected ) {
		$this->assertSame( $expected, Daymark_Subscription_Content_Sniffer::wordpress_format( $wp_format ) );
	}

	/** @return array<int, array{0: string, 1: string}> */
	public function wordpress_format_provider(): array {
		return array(
			array( 'image', 'image' ),
			array( 'gallery', 'gallery' ),
			array( 'status', 'note' ),
			array( 'chat', 'note' ),
			array( 'aside', 'note' ),
			array( 'link', 'link' ),
			array( 'quote', 'quote' ),
			array( '', 'standard' ),
			array( 'standard', 'standard' ),
			array( 'some-future-format', 'standard' ),
		);
	}

	public function test_quote_reads_the_first_blockquote_and_its_cite() {
		$quote = Daymark_Subscription_Content_Sniffer::quote(
			'<p>Intro</p><blockquote><p>First line.</p><p>Second &amp; last.</p><cite>&mdash; Grace Hopper</cite></blockquote><blockquote>Another</blockquote>'
		);

		$this->assertSame( 'First line. Second & last.', $quote['text'] );
		$this->assertSame( 'Grace Hopper', $quote['credit'] );
	}

	public function test_quote_strips_markup_and_scripts() {
		$quote = Daymark_Subscription_Content_Sniffer::quote( '<blockquote><p>Hello <b>there</b><script>alert(1)</script></p></blockquote>' );

		$this->assertSame( 'Hello there', $quote['text'] );
	}

	public function test_quote_cuts_a_long_quote() {
		$quote = Daymark_Subscription_Content_Sniffer::quote( '<blockquote>' . str_repeat( 'word ', 200 ) . '</blockquote>' );

		$this->assertSame( Daymark_Subscription_Content_Sniffer::QUOTE_MAX_CHARS, mb_strlen( $quote['text'] ) );
		$this->assertStringEndsWith( "\u{2026}", $quote['text'] );
	}

	public function test_quote_is_empty_without_a_blockquote() {
		$this->assertSame(
			array(
				'text'   => '',
				'credit' => '',
			),
			Daymark_Subscription_Content_Sniffer::quote( '<p>No quote here.</p>' )
		);
	}

	// -----------------------------------------------------------------
	// Ingest
	// -----------------------------------------------------------------

	public function test_ingest_stores_the_interaction_and_quote() {
		$repost = $this->ingest(
			array(
				'interaction'     => 'repost',
				'interaction_url' => 'https://other.example/original',
			)
		);
		$quote  = $this->ingest(
			array(
				'post_format'  => 'quote',
				'quote_text'   => 'Less, but better.',
				'quote_credit' => 'Dieter Rams',
			)
		);
		$plain  = $this->ingest( array() );

		$this->assertSame( 'repost', get_post_meta( $repost, 'interaction', true ) );
		$this->assertSame( 'https://other.example/original', get_post_meta( $repost, 'interaction_url', true ) );
		$this->assertSame( 'Less, but better.', get_post_meta( $quote, 'quote_text', true ) );
		$this->assertSame( 'Dieter Rams', get_post_meta( $quote, 'quote_credit', true ) );
		$this->assertFalse( metadata_exists( 'post', $plain, 'interaction' ) );
	}

	public function test_a_later_poll_fills_in_what_an_earlier_one_stored_without() {
		$fields  = array( 'permalink' => 'https://follow.example/old-link-post' );
		$post_id = $this->ingest( $fields );

		$this->assertSame( 'standard', get_post_meta( $post_id, 'post_format', true ) );

		$again = $this->ingest(
			$fields + array(
				'post_format'     => 'link',
				'link_url'        => 'https://news.example/story',
				'interaction'     => 'bookmark',
				'interaction_url' => 'https://news.example/story',
			)
		);

		$this->assertSame( 0, $again, 'The item is not ingested twice.' );
		$this->assertSame( 'link', get_post_meta( $post_id, 'post_format', true ) );
		$this->assertSame( 'https://news.example/story', get_post_meta( $post_id, 'link_url', true ) );
		$this->assertSame( 'bookmark', get_post_meta( $post_id, 'interaction', true ) );
	}

	public function test_a_later_poll_never_changes_a_real_format() {
		$fields  = array(
			'permalink'   => 'https://follow.example/photo',
			'post_format' => 'image',
		);
		$post_id = $this->ingest( $fields );

		$this->ingest( array_merge( $fields, array( 'post_format' => 'quote' ) ) );

		$this->assertSame( 'image', get_post_meta( $post_id, 'post_format', true ) );
	}

	// -----------------------------------------------------------------
	// REST
	// -----------------------------------------------------------------

	public function test_timeline_reports_the_interaction_and_quote() {
		$this->ingest(
			array(
				'title'            => 'Going',
				'interaction'      => 'rsvp',
				'interaction_url'  => 'https://events.example/party',
				'interaction_rsvp' => 'yes',
			)
		);
		$this->ingest(
			array(
				'title'        => 'Quoted',
				'post_format'  => 'quote',
				'quote_text'   => 'Less, but better.',
				'quote_credit' => 'Dieter Rams',
			)
		);

		$items = array_column( $this->get( '/daymark/v1/timeline' ), null, 'title' );

		$this->assertSame(
			array(
				'type' => 'rsvp',
				'url'  => 'https://events.example/party',
				'rsvp' => 'yes',
			),
			$items['Going']['interaction']
		);
		$this->assertSame( '', $items['Quoted']['interaction']['type'] );
		$this->assertSame( 'Less, but better.', $items['Quoted']['quote_text'] );
		$this->assertSame( 'Dieter Rams', $items['Quoted']['quote_credit'] );
	}

	public function test_timeline_leaves_out_a_followed_sites_likes() {
		$this->ingest(
			array(
				'title'           => 'A like',
				'interaction'     => 'like',
				'interaction_url' => 'https://other.example/liked',
			)
		);
		$this->ingest(
			array(
				'title'           => 'A reblog',
				'interaction'     => 'repost',
				'interaction_url' => 'https://other.example/reblogged',
			)
		);
		$this->ingest( array( 'title' => 'A post' ) );

		$titles = wp_list_pluck( $this->get( '/daymark/v1/timeline' ), 'title' );

		$this->assertNotContains( 'A like', $titles );
		$this->assertContains( 'A reblog', $titles );
		$this->assertContains( 'A post', $titles );
	}

	public function test_preview_endpoint_resolves_the_interaction_target() {
		$post_id = $this->ingest(
			array(
				'interaction'     => 'repost',
				'interaction_url' => 'https://other.example/original',
			)
		);

		$data = $this->get( '/daymark/v1/subscription-posts/' . $post_id . '/oembed', array( 'target' => 'interaction' ) );

		$this->assertSame( 'The original', $data['title'] );
	}

	public function test_preview_endpoint_still_accepts_target_reply() {
		$post_id = $this->ingest( array() );
		update_post_meta( $post_id, 'in_reply_to', 'https://other.example/original' );

		$data = $this->get( '/daymark/v1/subscription-posts/' . $post_id . '/oembed', array( 'target' => 'reply' ) );

		$this->assertSame( 'The original', $data['title'] );
	}
}
