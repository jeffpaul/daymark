<?php
/**
 * Hardening tests for the /daymark/v1/subscription-posts/{id} routes
 * (issue #438):
 *
 *   - every route rejects an ID that isn't a published
 *     `daymark_subscription_post`, instead of reading a title, excerpt, or
 *     meta off whatever post the ID happens to be;
 *   - inline `style` attributes never reach the app shell in a cached body.
 *
 * @package Daymark
 */

/**
 * Exercises the post-type assertion and the inline-style stripping.
 */
class Test_Rest_Subscription_Post_Hardening extends WP_UnitTestCase {

	/** Title of the ordinary post the requests below must never read back. */
	private const SECRET_TITLE = 'Another Author Private Draft Title';

	/** @var int */
	private $author;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Every subscription-post route, as [ method, path suffix ].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function route_provider(): array {
		return array(
			'detail'         => array( 'GET', '' ),
			'oembed'         => array( 'GET', '/oembed' ),
			'comment'        => array( 'POST', '/comment' ),
			'comment-target' => array( 'GET', '/comment-target' ),
		);
	}

	/**
	 * Build an authenticated request carrying a valid REST nonce.
	 *
	 * @param string $method HTTP method.
	 * @param int    $id     Post ID.
	 * @param string $suffix Route suffix after the ID.
	 * @return WP_REST_Request
	 */
	private function request_for( string $method, int $id, string $suffix ): WP_REST_Request {
		$request = new WP_REST_Request( $method, "/daymark/v1/subscription-posts/{$id}{$suffix}" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( 'POST' === $method ) {
			$request->set_param( 'text', 'A comment.' );
		}

		return $request;
	}

	/**
	 * A post of another type — here, a draft belonging to someone else — is a
	 * clean 404 and never has its title or excerpt echoed back.
	 *
	 * @dataProvider route_provider
	 *
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix after the ID.
	 */
	public function test_ordinary_post_id_is_a_404_and_leaks_nothing( string $method, string $suffix ) {
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = (int) self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_author'  => $admin,
				'post_title'   => self::SECRET_TITLE,
				'post_excerpt' => 'Secret excerpt.',
			)
		);

		wp_set_current_user( $this->author );

		$response = rest_do_request( $this->request_for( $method, $other, $suffix ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertStringNotContainsString( self::SECRET_TITLE, (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'Secret excerpt', (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * An unsubscribed (trashed) subscription post is gone, not still readable
	 * by ID.
	 *
	 * @dataProvider route_provider
	 *
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix after the ID.
	 */
	public function test_trashed_subscription_post_is_a_404( string $method, string $suffix ) {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'trash',
				'post_title'  => 'Unsubscribed Post',
			)
		);

		wp_set_current_user( $this->author );

		$response = rest_do_request( $this->request_for( $method, $post_id, $suffix ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Create a cached, already-'full' subscription post with a stored body.
	 *
	 * @param string $body_content Stored body HTML.
	 * @return int
	 */
	private function create_full_subscription_post( string $body_content ): int {
		$subscriptions   = new Daymark_Subscriptions();
		$subscription_id = $subscriptions->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/',
			)
		);

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'A Subscription Post',
			)
		);

		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', 'https://example.com/post/' );
		update_post_meta( $post_id, 'post_format', 'standard' );
		update_post_meta( $post_id, 'content_state', 'full' );
		update_post_meta( $post_id, 'body_content', $body_content );

		return $post_id;
	}

	/**
	 * A body cached before style stripping existed still comes back without
	 * any inline style: the strip happens on read as well as on store.
	 */
	public function test_previously_cached_body_is_served_without_inline_style() {
		$post_id = $this->create_full_subscription_post(
			'<p style="position:fixed;top:0;left:0;z-index:9999">Overlay</p><img src="https://example.com/a.jpg" width="10" STYLE=\'width:99999px\'>'
		);

		wp_set_current_user( $this->author );

		$response = rest_do_request( $this->request_for( 'GET', $post_id, '' ) );

		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data()['body_content'];
		$this->assertStringNotContainsStringIgnoringCase( 'style=', $body );
		$this->assertStringContainsString( 'Overlay', $body, 'The content itself is kept' );
		$this->assertStringContainsString( 'width="10"', $body, 'Real dimension attributes are kept' );
	}

	/**
	 * A remote page borrowing Daymark's own overlay classes is neutralized in
	 * a previously cached body too; unrelated classes survive.
	 */
	public function test_previously_cached_body_is_served_without_daymark_classes() {
		$post_id = $this->create_full_subscription_post(
			'<div class="daymark-sheet wp-block-group"><p class="Daymark-Backdrop">Spoof</p><img class="alignleft" src="https://example.com/a.jpg" width="10"></div>'
		);

		wp_set_current_user( $this->author );

		$response = rest_do_request( $this->request_for( 'GET', $post_id, '' ) );

		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data()['body_content'];
		$this->assertStringNotContainsStringIgnoringCase( 'daymark-', $body );
		$this->assertStringContainsString( 'wp-block-group', $body, 'Unrelated classes are kept' );
		$this->assertStringContainsString( 'alignleft', $body, 'Unrelated classes are kept' );
		$this->assertStringContainsString( 'Spoof', $body, 'The content itself is kept' );
	}

	/** Class-token edge cases for the helper. */
	public function test_strip_untrusted_presentation_class_edge_cases() {
		$strip = static function ( string $html ): string {
			return (string) preg_replace( '/\s+>/', '>', Daymark_Subscription_Poller::strip_untrusted_presentation( $html ) );
		};

		$this->assertSame( '<p class="a c">x</p>', $strip( '<p class="a daymark-sheet c">x</p>' ), 'Only the daymark- token is removed' );
		$this->assertSame( '<p>x</p>', $strip( '<p class="daymark-sheet">x</p>' ), 'A class attribute left empty is removed' );
		$this->assertSame( '<p>x</p>', $strip( '<p class="DAYMARK-Sheet  daymark-x">x</p>' ), 'Case-insensitive, whatever the spacing' );
		$this->assertSame( '<p class="my-daymark-x">x</p>', $strip( '<p class="my-daymark-x">x</p>' ), 'A token that only contains the word is kept' );
		$this->assertSame( '<p class="a">x</p>', $strip( '<p class="a" style="position:fixed">x</p>' ), 'Style and classes are handled together' );
		$this->assertSame( '<p>x</p>', $strip( '<p class="daymark&#45;sheet">x</p>' ), 'An entity-encoded hyphen is decoded before the check' );
		$this->assertSame( '<p class="a">x</p>', $strip( '<p class="a DAYMARK&#x2d;sheet">x</p>' ), 'A hex entity and mixed case are handled too' );
	}

	/** The optional flags a Mark's own content uses: keep styles, and keep only allowlisted daymark- classes. */
	public function test_strip_untrusted_presentation_can_keep_styles_and_allowlisted_classes() {
		$html = '<div class="daymark-sheet daymark-checkin-map__pin Other" style="left:1%">x</div>';

		$out = (string) preg_replace( '/\s+>/', '>', Daymark_Subscription_Poller::strip_untrusted_presentation( $html, false, array( 'daymark-checkin-map__pin' ) ) );

		$this->assertSame( '<div class="daymark-checkin-map__pin Other" style="left:1%">x</div>', $out );

		$out = (string) preg_replace( '/\s+>/', '>', Daymark_Subscription_Poller::strip_untrusted_presentation( '<p class="DAYMARK-Checkin-Map">x</p>', false, array( 'daymark-checkin-map' ) ) );

		$this->assertSame( '<p class="DAYMARK-Checkin-Map">x</p>', $out, 'The allowlist compares case-insensitively' );
	}

	/** Direct coverage of the stripping helper's edge cases. */
	public function test_strip_untrusted_presentation_edge_cases() {
		$this->assertSame( '', Daymark_Subscription_Poller::strip_untrusted_presentation( '' ) );
		$this->assertSame(
			'<p class="x">Plain <em>text</em></p>',
			Daymark_Subscription_Poller::strip_untrusted_presentation( '<p class="x">Plain <em>text</em></p>' ),
			'Markup with no style attribute is returned unchanged'
		);
		$this->assertSame(
			'<p>Mentions style in text only</p>',
			Daymark_Subscription_Poller::strip_untrusted_presentation( '<p>Mentions style in text only</p>' ),
			'The word "style" in text content is not touched'
		);
		// The tag processor leaves a harmless space where an attribute was; only
		// the attributes themselves matter here.
		$stripped = Daymark_Subscription_Poller::strip_untrusted_presentation( '<div class="a" style="color:red"><span style=\'x:y\'>One</span><b STYLE="a:b">Two</b></div>' );
		$this->assertSame(
			'<div class="a"><span>One</span><b>Two</b></div>',
			preg_replace( '/\s+>/', '>', $stripped ),
			'Every tag is stripped, whatever the quoting or case'
		);
	}

	/**
	 * The Comment pre-check spends the open allowance only when it has to
	 * look the origin up; once its answer is cached, asking again is free.
	 */
	public function test_comment_target_spends_the_open_allowance_only_on_a_lookup() {
		wp_set_current_user( $this->author );
		add_filter(
			'daymark_rate_limits',
			static function ( $all ) {
				$all = is_array( $all ) ? $all : array();
				$all[ Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_OPEN ] = array(
					'limit'  => 1,
					'window' => 5 * MINUTE_IN_SECONDS,
				);

				return $all;
			}
		);
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'daymark_test_http_blocked', 'Blocked in test.' );
			}
		);

		$first  = $this->create_full_subscription_post( '<p>One.</p>' );
		$second = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Another Subscription Post',
			)
		);
		update_post_meta( $second, 'subscription_id', get_post_meta( $first, 'subscription_id', true ) );
		update_post_meta( $second, 'permalink', 'https://example.com/another-post/' );

		$this->assertSame( 200, rest_do_request( $this->request_for( 'GET', $first, '/comment-target' ) )->get_status() );
		$this->assertSame( 200, rest_do_request( $this->request_for( 'GET', $first, '/comment-target' ) )->get_status(), 'A cached answer is free' );
		$this->assertSame( 429, rest_do_request( $this->request_for( 'GET', $second, '/comment-target' ) )->get_status(), 'A new lookup past the allowance is refused' );
	}
}
