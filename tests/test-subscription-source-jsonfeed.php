<?php
/**
 * JSON Feed subscription source: discovery, a pasted feed URL, item
 * mapping, precedence after RSS/Atom, and OPML round trips.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Subscription_Source_Jsonfeed.
 */
class Test_Subscription_Source_Jsonfeed extends WP_UnitTestCase {

	/**
	 * URL => canned wp_remote_get()-shaped response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscription_Html_Cache::reset();
		Daymark_Subscriptions::install();

		$this->http_responses = array();
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

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
	 * @param string $url          URL to mock.
	 * @param string $body         Response body.
	 * @param string $content_type Content type.
	 * @return void
	 */
	private function mock_response( string $url, string $body, string $content_type = 'text/html' ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => $content_type ),
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
	 * A JSON Feed 1.1 body.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return string
	 */
	private function feed_body( array $items ): string {
		return (string) wp_json_encode(
			array(
				'version'       => 'https://jsonfeed.org/version/1.1',
				'title'         => 'Jo’s microblog',
				'home_page_url' => 'https://micro.example/',
				'authors'       => array( array( 'name' => 'Jo' ) ),
				'items'         => $items,
			)
		);
	}

	/** A page advertising only a JSON Feed is discovered through it. */
	public function test_discover_finds_an_advertised_json_feed() {
		$this->mock_response( 'https://micro.example/', '<html><head><link rel="alternate" type="application/feed+json" href="/feed.json" title="Jo"></head></html>' );
		$this->mock_response( 'https://micro.example/feed.json', $this->feed_body( array() ), 'application/feed+json' );

		$found = ( new Daymark_Subscription_Source_Jsonfeed() )->discover( 'https://micro.example/' );

		$this->assertCount( 1, $found );
		$this->assertSame( 'https://micro.example/feed.json', $found[0]['url'] );
		$this->assertSame( 'Jo', $found[0]['title'] );
	}

	/** An advertised URL that isn't really a JSON Feed is not offered. */
	public function test_discover_ignores_a_link_that_is_not_a_json_feed() {
		$this->mock_response( 'https://micro.example/', '<html><head><link rel="alternate" type="application/feed+json" href="/feed.json"></head></html>' );
		$this->mock_response( 'https://micro.example/feed.json', '{"not":"a feed"}', 'application/json' );

		$this->assertSame( array(), ( new Daymark_Subscription_Source_Jsonfeed() )->discover( 'https://micro.example/' ) );
	}

	/** Subscribing to a JSON-Feed-only site records the jsonfeed source. */
	public function test_subscribe_to_a_json_feed_only_site() {
		$this->mock_response( 'https://micro.example/', '<html><head><title>Jo</title><link rel="alternate" type="application/feed+json" href="https://micro.example/feed.json"></head></html>' );
		$this->mock_response( 'https://micro.example/feed.json', $this->feed_body( array() ), 'application/feed+json' );

		$id = Daymark_Plugin::instance()->subscriptions->subscribe_to_site( 'https://micro.example/' );

		$this->assertIsInt( $id );
		$row = Daymark_Plugin::instance()->subscriptions->get( $id );
		$this->assertSame( 'jsonfeed', $row['source_type'] );
		$this->assertSame( 'https://micro.example/feed.json', $row['feed_url'] );
	}

	/** A site offering RSS and JSON Feed is still followed through RSS automatically, and the picker lists both. */
	public function test_rss_keeps_precedence_and_both_are_listed() {
		$this->mock_response(
			'https://both.example/',
			'<html><head><link rel="alternate" type="application/rss+xml" href="https://both.example/feed/"><link rel="alternate" type="application/feed+json" href="https://both.example/feed.json"></head></html>'
		);
		$this->mock_response( 'https://both.example/feed.json', $this->feed_body( array() ), 'application/feed+json' );

		$registry = Daymark_Plugin::instance()->subscription_source_registry;
		$first    = $registry->discover_feeds( 'https://both.example/' );
		$all      = $registry->discover_all_feeds( 'https://both.example/' );

		$this->assertSame( 'feed', $first[0]['source_type'] );
		$this->assertContains( 'jsonfeed', wp_list_pluck( $all, 'source_type' ) );
	}

	/** Pasting a JSON Feed's own address works, like pasting an RSS address. */
	public function test_a_pasted_json_feed_url_is_found() {
		$this->mock_response( 'https://micro.example/feed.json', $this->feed_body( array() ), 'application/feed+json' );

		$candidates = Daymark_Plugin::instance()->subscriptions->discover_candidates( 'https://micro.example/feed.json' );

		$this->assertIsArray( $candidates );
		$this->assertSame( 'jsonfeed', $candidates[0]['source_type'] );
	}

	/** Items map onto Daymark's post shape: notes, photos, galleries, audio, and the feed's author. */
	public function test_fetch_and_normalize_items() {
		$this->mock_response(
			'https://micro.example/feed.json',
			$this->feed_body(
				array(
					array(
						'id'             => 'https://micro.example/1',
						'url'            => 'https://micro.example/1',
						'content_html'   => '<p>Just a short thought.</p>',
						'date_published' => '2026-09-01T10:00:00-04:00',
					),
					array(
						'id'           => '2',
						'url'          => '/2',
						'title'        => 'Two photos',
						'content_html' => '<p>Look</p>',
						'attachments'  => array(
							array(
								'url'       => 'https://micro.example/a.jpg',
								'mime_type' => 'image/jpeg',
							),
							array(
								'url'       => 'https://micro.example/b.jpg',
								'mime_type' => 'image/jpeg',
							),
						),
						'authors'      => array( array( 'name' => 'Guest' ) ),
					),
					array(
						'id'           => '3',
						'url'          => 'https://micro.example/3',
						'title'        => 'Episode 1',
						'content_text' => 'Show notes',
						'attachments'  => array(
							array(
								'url'       => 'https://micro.example/ep1.mp3',
								'mime_type' => 'audio/mpeg',
							),
						),
					),
				)
			),
			'application/feed+json'
		);

		$source = new Daymark_Subscription_Source_Jsonfeed();
		$items  = array_map( array( $source, 'normalize' ), $source->fetch( 'https://micro.example/feed.json' ) );

		$this->assertSame( 'note', $items[0]['post_format'] );
		$this->assertSame( 'Jo', $items[0]['author'] );
		$this->assertSame( '2026-09-01 14:00:00', $items[0]['published_at'] );
		$this->assertSame( 'Just a short thought.', $items[0]['excerpt'] );

		$this->assertSame( 'gallery', $items[1]['post_format'] );
		$this->assertSame( 'https://micro.example/2', $items[1]['permalink'] );
		$this->assertSame( 'Guest', $items[1]['author'] );
		$this->assertSame( array( 'https://micro.example/a.jpg', 'https://micro.example/b.jpg' ), $items[1]['gallery_images'] );

		$this->assertSame( 'audio', $items[2]['post_format'] );
		$this->assertSame( 'Show notes', $items[2]['excerpt'] );
	}

	/** A body that isn't a JSON Feed is a failed fetch, not an empty one. */
	public function test_fetch_rejects_a_non_feed() {
		$this->mock_response( 'https://micro.example/feed.json', '<html></html>' );

		$this->assertWPError( ( new Daymark_Subscription_Source_Jsonfeed() )->fetch( 'https://micro.example/feed.json' ) );
	}

	/** A JSON Feed subscription exports with its source and imports back as itself. */
	public function test_opml_round_trip_keeps_the_source() {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscriptions->create(
			array(
				'site_url'    => 'https://micro.example/',
				'feed_url'    => 'https://micro.example/feed.json',
				'source_type' => 'jsonfeed',
				'site_title'  => 'Jo',
			)
		);

		$opml = ( new Daymark_Subscription_OPML() )->export();
		$this->assertStringContainsString( 'daymark:sourceType="jsonfeed"', $opml );

		foreach ( $subscriptions->get_all() as $row ) {
			$subscriptions->delete( (int) $row['id'] );
		}

		( new Daymark_Subscription_OPML() )->import( $opml );

		$row = $subscriptions->get_by_feed_url( 'https://micro.example/feed.json' );
		$this->assertSame( 'jsonfeed', $row['source_type'] );
	}
}
