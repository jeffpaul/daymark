<?php
/**
 * Daymark_Comment_Replies tests (issue #559) with this site's typography
 * turned off. Once a `run_wptexturize` filter turns core's wptexturize()
 * off, it stays off for the rest of the PHP process, so these tests each
 * run in a process of their own.
 *
 * All HTTP is mocked via `pre_http_request`, matching
 * tests/test-comment-replies.php.
 *
 * @package Daymark
 */

/**
 * Tests that a comment is matched as the original site shows it, with
 * WordPress's default typography, when this site turned typography off.
 */
class Test_Comment_Replies_Typography extends WP_UnitTestCase {

	private const ROOT      = 'https://origin.example/wp-json/';
	private const PERMALINK = 'https://origin.example/post/';

	/**
	 * WP_TESTS_SKIP_INSTALL as it was before these tests.
	 *
	 * @var string|false
	 */
	private static $skip_install = false;

	/**
	 * URL => canned response.
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	/** @var int */
	private int $user_id;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// The isolated processes use the test site as installed: installing
		// it again from one of them would empty its tables mid-run.
		self::$skip_install = getenv( 'WP_TESTS_SKIP_INSTALL' );
		putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Read by the isolated processes' bootstrap, restored below.
	}

	public static function tear_down_after_class(): void {
		putenv( false === self::$skip_install ? 'WP_TESTS_SKIP_INSTALL' : 'WP_TESTS_SKIP_INSTALL=' . self::$skip_install ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restored as found.

		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();

		$this->http_responses = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );

		Daymark_Comment_Replies::flush_local_keys();
		delete_transient( Daymark_Comment_Replies::LOCK );
		_set_cron_array( array() );
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
	 * The forms a comment is matched by still include WordPress's default
	 * typography ("×" between numbers, "™" for "(tm)").
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_match_variants_keep_default_typography(): void {
		$this->turn_typography_off();

		$method = new ReflectionMethod( Daymark_Comment_Replies::class, 'match_variants' );

		$this->assertContains( 'use a 2 4', $method->invoke( null, 'Use a 2x4 :)' ) );
		$this->assertContains( 'got the 1920 1080 version ™', $method->invoke( null, 'Got the 1920x1080 version (tm)' ) );
	}

	/**
	 * A comment the other site shows with "×" and "™" is still found.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_comment_is_found_as_the_other_site_shows_it(): void {
		$this->turn_typography_off();

		$cases = array(
			array( 'Use a 2x4 :)', 'Use a 2&#215;4 :)' ),
			array( 'Got the 1920x1080 version (tm)', 'Got the 1920&#215;1080 version &#8482;' ),
		);

		foreach ( $cases as $case ) {
			$this->assertSame( 60, $this->find_comment_shown_as( $case[0], $case[1] ), "Sent \"{$case[0]}\", shown as \"{$case[1]}\"" );
		}
	}

	/** The tests above leave typography on in the shared test process. */
	public function test_typography_stays_on_in_the_shared_process(): void {
		$this->assertSame( 'Use a 2&#215;4', wptexturize( 'Use a 2x4' ) );
	}

	/**
	 * Turn typography off, as a theme or plugin does, for this process.
	 *
	 * @return void
	 */
	private function turn_typography_off(): void {
		add_filter( 'run_wptexturize', '__return_false' );
		wptexturize( 'x', true );

		$this->assertSame( 'Use a 2x4', wptexturize( 'Use a 2x4' ), 'Typography is off' );
	}

	/**
	 * Watch a comment sent as a Webmention (no comment ID), let the site
	 * show it as given, and return the comment ID the first check finds.
	 *
	 * @param string $sent  The comment as sent.
	 * @param string $shown The comment as the site shows it (HTML).
	 * @return int
	 */
	private function find_comment_shown_as( string $sent, string $shown ): int {
		delete_user_meta( $this->user_id, Daymark_Comment_Replies::META );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'An origin post',
			)
		);

		update_post_meta( $post_id, 'permalink', self::PERMALINK );

		$this->assertTrue(
			Daymark_Comment_Replies::start_watch(
				$this->user_id,
				$post_id,
				array(
					'root'    => self::ROOT,
					'remote'  => 55,
					'comment' => 0,
					'mark'    => 77,
					'text'    => $sent,
				)
			)
		);

		$this->mock_json(
			$this->comments_url(
				array(
					'search'  => 'example.org',
					'_fields' => 'id,author_url,date_gmt,content',
				)
			),
			array(
				array(
					'id'         => 60,
					'author_url' => home_url( '/' ),
					'date_gmt'   => gmdate( 'Y-m-d\TH:i:s' ),
					'content'    => array( 'rendered' => '<p>' . $shown . '</p>' ),
				),
			)
		);
		$this->mock_json(
			$this->comments_url(
				array(
					'parent'  => 60,
					'_fields' => Daymark_Remote_Replies::COMMENT_FIELDS,
				)
			),
			array()
		);

		Daymark_Comment_Replies::check_due();

		$watches = Daymark_Comment_Replies::get_watches( $this->user_id );

		$this->assertCount( 1, $watches );

		return (int) $watches[0]['comment'];
	}

	/**
	 * A comments URL a check asks for on the origin post.
	 *
	 * @param array<string, mixed> $args Query arguments besides the post and order.
	 * @return string
	 */
	private function comments_url( array $args ): string {
		$query = array( 'post' => 55 );

		if ( isset( $args['search'] ) ) {
			$query['search'] = $args['search'];
		}

		if ( isset( $args['parent'] ) ) {
			$query['parent'] = $args['parent'];
		}

		$query['per_page'] = 20;
		$query['orderby']  = 'date_gmt';
		$query['order']    = 'desc';
		$query['_fields']  = $args['_fields'];

		return Daymark_Remote_Replies::comments_url( self::ROOT, $query );
	}

	/**
	 * @param string $url  URL to mock.
	 * @param mixed  $data JSON body (encoded here).
	 * @return void
	 */
	private function mock_json( string $url, $data ): void {
		$this->http_responses[ $url ] = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
