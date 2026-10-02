<?php
/**
 * Gallery photos for subscription posts, so a followed site's gallery post
 * shows the Timeline card's 2x2 grid: the content sniffer's
 * gallery_images() (order, duplicate copies, avatars, cap), storing the list
 * on ingest, filling it in for an already-ingested post on its next poll,
 * refreshing it from the full page, and the `gallery` summary field.
 *
 * @package Daymark
 */

/**
 * Exercises gallery_images collection and its use on the Timeline.
 */
class Test_Subscription_Gallery_Images extends WP_UnitTestCase {

	/** @var Daymark_Subscription_Poller */
	private $poller;

	/** @var array<string, mixed> */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->poller         = new Daymark_Subscription_Poller();
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
		unset( $preempt, $parsed_args );

		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * A subscription to example.com.
	 *
	 * @return int Subscription ID.
	 */
	private function create_subscription(): int {
		return (int) ( new Daymark_Subscriptions() )->create(
			array(
				'site_url'    => 'https://example.com/',
				'feed_url'    => 'https://example.com/feed/',
				'source_type' => 'feed',
			)
		);
	}

	/**
	 * A normalized gallery item, as a source's normalize() returns it.
	 *
	 * @param string[] $gallery_images Gallery photo URLs.
	 * @return array<string, mixed>
	 */
	private function gallery_item( array $gallery_images ): array {
		return array(
			'title'              => 'Beach photos',
			'excerpt'            => 'A few photos.',
			'author'             => 'Jane',
			'published_at'       => gmdate( 'Y-m-d H:i:s' ),
			'permalink'          => 'https://example.com/beach/',
			'post_format'        => 'gallery',
			'featured_image_url' => $gallery_images[0] ?? '',
			'raw_media'          => array(),
			'link_url'           => '',
			'gallery_images'     => $gallery_images,
		);
	}

	/** Copies of one photo (WebP, sizes, -scaled, a CDN host, a <noscript> repeat) count once, in document order. */
	public function test_gallery_images_drops_copies_of_the_same_photo() {
		$html = '<figure><img src="https://i0.wp.com/example.com/wp-content/uploads/2026/07/beach-scaled-jpeg.webp?fit=1024%2C576" />'
			. '<noscript><img src="https://example.com/wp-content/uploads/2026/07/beach-scaled.jpeg" /></noscript></figure>'
			. '<img src="https://example.com/wp-content/uploads/2026/07/dunes-1024x768.jpg" />'
			. '<img src="https://example.com/wp-content/uploads/2026/07/dunes.jpg" />'
			. '<img src="https://example.com/wp-content/uploads/2026/07/pier.png" />';

		$images = Daymark_Subscription_Content_Sniffer::gallery_images( $html );

		$this->assertCount( 3, $images );
		$this->assertStringContainsString( 'beach-scaled-jpeg.webp', $images[0] );
		$this->assertStringContainsString( 'dunes-1024x768.jpg', $images[1] );
		$this->assertStringContainsString( 'pier.png', $images[2] );
	}

	/** Known images come first; an author avatar, a data: placeholder, and a non-http URL are skipped; a lazy-loaded image is read from data-src. */
	public function test_gallery_images_skips_avatars_and_unusable_urls() {
		$html = '<img class="avatar avatar-96" src="https://example.com/avatar.jpg" />'
			. '<img src="data:image/gif;base64,R0lGOD" data-src="https://example.com/lazy.jpg" />'
			. '<img src="javascript:alert(1)" />'
			. '<img src="https://example.com/two.jpg" />';

		$images = Daymark_Subscription_Content_Sniffer::gallery_images( $html, array( 'https://example.com/enclosure.jpg' ) );

		$this->assertSame(
			array( 'https://example.com/enclosure.jpg', 'https://example.com/lazy.jpg', 'https://example.com/two.jpg' ),
			$images
		);
	}

	/** At most GALLERY_MAX_IMAGES photos are kept. */
	public function test_gallery_images_is_capped() {
		$urls = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$urls[] = "https://example.com/photo-{$i}.jpg";
		}

		$this->assertCount( Daymark_Subscription_Content_Sniffer::GALLERY_MAX_IMAGES, Daymark_Subscription_Content_Sniffer::gallery_images( '', $urls ) );
	}

	/** Ingesting a gallery post stores its photo list. */
	public function test_ingest_stores_gallery_images() {
		$urls    = array( 'https://example.com/a.jpg', 'https://example.com/b.jpg', 'https://example.com/c.jpg' );
		$post_id = $this->poller->maybe_ingest_item( $this->create_subscription(), $this->gallery_item( $urls ) );

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( $urls, Daymark_Subscription_Poller::gallery_images_for( $post_id ) );
	}

	/** A gallery post ingested before photo lists existed gets one on its next poll, and isn't duplicated. */
	public function test_next_poll_fills_in_a_missing_gallery_list() {
		$subscription_id = $this->create_subscription();
		$post_id         = $this->poller->maybe_ingest_item( $subscription_id, $this->gallery_item( array() ) );

		$this->assertSame( array(), Daymark_Subscription_Poller::gallery_images_for( $post_id ) );

		$urls = array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' );

		$this->assertSame( 0, $this->poller->maybe_ingest_item( $subscription_id, $this->gallery_item( $urls ) ), 'still treated as a duplicate' );
		$this->assertSame( $urls, Daymark_Subscription_Poller::gallery_images_for( $post_id ) );
	}

	/** Fetching the full page replaces a shorter list with every photo the page shows. */
	public function test_full_fetch_refreshes_a_shorter_gallery_list() {
		$post_id = $this->poller->maybe_ingest_item(
			$this->create_subscription(),
			$this->gallery_item( array( 'https://example.com/wp-content/uploads/a.jpg', 'https://example.com/wp-content/uploads/b.jpg' ) )
		);

		$this->http_responses['https://example.com/beach/'] = array(
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			'body'     => '<html><body><article><img src="https://example.com/wp-content/uploads/a.jpg" /><img src="https://example.com/wp-content/uploads/b.jpg" /><img src="https://example.com/wp-content/uploads/c.jpg" /></article></body></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);

		$this->assertTrue( $this->poller->fetch_full_content( $post_id ) );
		$this->assertCount( 3, Daymark_Subscription_Poller::gallery_images_for( $post_id ) );
	}

	/** The Timeline summary carries the first four photos and the count; a non-gallery post gets no `gallery` field. */
	public function test_timeline_summary_reports_gallery_for_the_card_grid() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$urls = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$urls[] = "https://example.com/photo-{$i}.jpg";
		}
		$this->poller->maybe_ingest_item( $this->create_subscription(), $this->gallery_item( $urls ) );

		$request = new WP_REST_Request( 'GET', '/daymark/v1/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$items = rest_do_request( $request )->get_data();

		$gallery = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'Beach photos' === ( $item['title'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $gallery );
		$this->assertSame( 6, $gallery[0]['gallery']['count'] );
		$this->assertSame( array_slice( $urls, 0, 4 ), $gallery[0]['gallery']['images'] );
	}
}
