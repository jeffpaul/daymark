<?php
/**
 * Outbound HTTP safety tests (issue #438).
 *
 * Daymark_Subscription_Url_Guard::check() vets the URL Daymark is about to
 * request; Daymark_Outbound_Guard re-runs it on every redirect hop. WordPress
 * core does a narrower version of this itself, but only from 7.0.3 — 7.0.0
 * through 7.0.2 accept 169.254/16 (cloud instance metadata), CGNAT, and
 * 240/4 as redirect targets — so these tests do not depend on which core
 * version is installed: they drive a real Requests redirect through a stub
 * transport (no network) and assert Daymark's own guard is what refuses it.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Outbound_Guard.
 */
class Test_Outbound_Http_Safety extends WP_UnitTestCase {

	/**
	 * Every URL the stub transport was asked for, in order.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	/**
	 * Where the stub transport's first response redirects to.
	 *
	 * @var string
	 */
	private string $redirect_to = '';

	public function set_up(): void {
		parent::set_up();

		$this->requested   = array();
		$this->redirect_to = '';
	}

	public function tear_down(): void {
		remove_all_filters( 'daymark_subscription_url_guard_resolved_addresses' );

		parent::tear_down();
	}

	/**
	 * Redirect targets the guard must refuse.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function unsafe_redirect_target_provider(): array {
		return array(
			'cloud metadata'     => array( 'http://169.254.169.254/latest/meta-data/iam/security-credentials/role' ),
			'link-local'         => array( 'http://169.254.1.1/' ),
			'CGNAT'              => array( 'http://100.64.0.1/' ),
			'CGNAT upper edge'   => array( 'http://100.127.255.254/' ),
			'reserved 240/4'     => array( 'http://240.0.0.1/' ),
			'loopback'           => array( 'http://127.0.0.1/' ),
			'private 10/8'       => array( 'http://10.0.0.1/' ),
			'private 172.16/12'  => array( 'http://172.16.0.1/' ),
			'private 192.168/16' => array( 'http://192.168.1.1/' ),
			'IPv6 loopback'      => array( 'http://[::1]/' ),
			'IPv6 unique-local'  => array( 'http://[fd00::1]/' ),
			'IPv6 link-local'    => array( 'http://[fe80::1]/' ),
			'IPv4-mapped IPv6'   => array( 'http://[::ffff:169.254.169.254]/' ),
			'non-standard port'  => array( 'http://93.184.216.34:9200/' ),
			'embedded userinfo'  => array( 'http://user:pass@93.184.216.34/' ),
		);
	}

	/**
	 * A Requests transport that answers the first request with a redirect
	 * and every later one with a plain 200, recording each URL it is asked
	 * for on this test instance. No network is involved.
	 *
	 * @return WpOrg\Requests\Transport
	 */
	private function stub_transport(): WpOrg\Requests\Transport {
		$test = $this;

		return new class( $test ) implements WpOrg\Requests\Transport {

			/** @var Test_Outbound_Http_Safety */
			private $test;

			/** @param Test_Outbound_Http_Safety $test Owning test. */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param string               $url     Request URL.
			 * @param array<string,string> $headers Request headers.
			 * @param array|string         $data    Request body.
			 * @param array<string,mixed>  $options Request options.
			 * @return string Raw HTTP response.
			 */
			public function request( $url, $headers = array(), $data = array(), $options = array() ) {
				return $this->test->serve( $url );
			}

			/**
			 * @param array<int,array<string,mixed>> $requests Requests.
			 * @param array<string,mixed>            $options  Options.
			 * @return array<int,mixed>
			 */
			public function request_multiple( $requests, $options ) {
				return array();
			}

			/**
			 * @param array<string,bool> $capabilities Capabilities to check.
			 * @return bool
			 */
			public static function test( $capabilities = array() ) {
				return true;
			}
		};
	}

	/**
	 * Stub transport entry point: record the URL and return a raw response.
	 *
	 * @param string $url Requested URL.
	 * @return string
	 */
	public function serve( string $url ): string {
		$this->requested[] = $url;

		if ( 1 === count( $this->requested ) ) {
			return "HTTP/1.1 302 Found\r\nLocation: " . $this->redirect_to . "\r\nContent-Length: 0\r\n\r\n";
		}

		return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
	}

	/**
	 * Issue a request the way WP_Http does: Requests, with
	 * WP_HTTP_Requests_Hooks so WordPress fires its `requests-*` actions.
	 *
	 * @param string $url First URL to request.
	 * @return WpOrg\Requests\Response
	 */
	private function request_like_wp_http( string $url ) {
		return WpOrg\Requests\Requests::request(
			$url,
			array(),
			null,
			'GET',
			array(
				'transport' => $this->stub_transport(),
				'hooks'     => new WP_HTTP_Requests_Hooks( $url, array() ),
			)
		);
	}

	/**
	 * A redirect to an unsafe address is refused inside the guard, and the
	 * unsafe address is never requested.
	 *
	 * @dataProvider unsafe_redirect_target_provider
	 *
	 * @param string $target Redirect target.
	 */
	public function test_guard_refuses_a_redirect_to_an_unsafe_address( string $target ) {
		$this->redirect_to = $target;

		$refused = false;

		try {
			Daymark_Outbound_Guard::run(
				function () {
					return $this->request_like_wp_http( 'http://93.184.216.34/start' );
				}
			);
		} catch ( WpOrg\Requests\Exception $e ) {
			$refused = 'daymark.redirect_blocked' === $e->getType();
		}

		$this->assertTrue( $refused, "A redirect to {$target} must be refused by Daymark_Outbound_Guard" );
		$this->assertSame(
			array( 'http://93.184.216.34/start' ),
			$this->requested,
			'The redirect target was never requested'
		);
	}

	/** A redirect to an ordinary public address is still followed. */
	public function test_guard_follows_a_redirect_to_a_public_address() {
		$this->redirect_to = 'https://93.184.216.35/final';

		$response = Daymark_Outbound_Guard::run(
			function () {
				return $this->request_like_wp_http( 'http://93.184.216.34/start' );
			}
		);

		$this->assertSame( 200, $response->status_code );
		$this->assertSame(
			array( 'http://93.184.216.34/start', 'https://93.184.216.35/final' ),
			$this->requested
		);
	}

	/**
	 * The first request to an unsafe URL is refused too, not only redirect
	 * hops: this is the path a discovered oEmbed endpoint or an autodiscovered
	 * feed takes, which no call site ever vetted.
	 *
	 * @dataProvider unsafe_redirect_target_provider
	 *
	 * @param string $url Request URL.
	 */
	public function test_first_request_to_an_unsafe_url_is_refused( string $url ) {
		$result = Daymark_Outbound_Guard::validate_first_request( false, array(), $url );

		$this->assertInstanceOf( WP_Error::class, $result, "A first request to {$url} must be refused" );
	}

	/** An ordinary public URL passes the first-request check untouched. */
	public function test_first_request_to_a_public_url_passes() {
		$this->assertFalse( Daymark_Outbound_Guard::validate_first_request( false, array(), 'https://93.184.216.34/feed/' ) );
	}

	/** A response another filter already supplied is never second-guessed. */
	public function test_an_existing_response_is_returned_unchanged() {
		$canned = array( 'response' => array( 'code' => 200 ) );

		$this->assertSame( $canned, Daymark_Outbound_Guard::validate_first_request( $canned, array(), 'http://169.254.169.254/' ) );
	}

	/** The first-request filter is attached only while a Daymark call runs. */
	public function test_first_request_filter_is_attached_only_while_a_request_runs() {
		$callback = array( 'Daymark_Outbound_Guard', 'validate_first_request' );

		$this->assertFalse( has_filter( 'pre_http_request', $callback ) );

		Daymark_Outbound_Guard::run(
			function () use ( $callback ) {
				$this->assertNotFalse( has_filter( 'pre_http_request', $callback ) );
			}
		);

		$this->assertFalse( has_filter( 'pre_http_request', $callback ) );
	}

	/**
	 * The handler exists only while a Daymark request runs, so it can never
	 * refuse (or slow down) a redirect made by another plugin.
	 */
	public function test_handler_is_attached_only_while_a_request_runs() {
		$hook = 'requests-requests.before_redirect';

		$this->assertFalse( has_action( $hook, array( 'Daymark_Outbound_Guard', 'validate_redirect' ) ) );

		Daymark_Outbound_Guard::run(
			function () use ( $hook ) {
				$this->assertNotFalse( has_action( $hook, array( 'Daymark_Outbound_Guard', 'validate_redirect' ) ) );

				// A nested call must not unhook the outer one when it finishes.
				Daymark_Outbound_Guard::run( '__return_true' );

				$this->assertNotFalse( has_action( $hook, array( 'Daymark_Outbound_Guard', 'validate_redirect' ) ) );
			}
		);

		$this->assertFalse( has_action( $hook, array( 'Daymark_Outbound_Guard', 'validate_redirect' ) ) );
	}

	/** The handler is detached even when the wrapped request throws. */
	public function test_handler_is_detached_after_an_exception() {
		try {
			Daymark_Outbound_Guard::run(
				static function () {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertFalse( has_action( 'requests-requests.before_redirect', array( 'Daymark_Outbound_Guard', 'validate_redirect' ) ) );
	}

	/** Outside the guard, another plugin's redirect is left alone. */
	public function test_a_redirect_outside_the_guard_is_not_touched() {
		$this->redirect_to = 'http://100.64.0.1/';

		$response = $this->request_like_wp_http( 'http://93.184.216.34/start' );

		$this->assertSame( 200, $response->status_code );
	}

	/**
	 * Every raw HTTP call is either a `Daymark_Outbound_Guard::get()`/`post()`
	 * (which do the work inside the guard themselves) or, for the library
	 * calls that fetch on their own, sits lexically inside the argument list
	 * of a `Daymark_Outbound_Guard::run( … )` call. A second, unguarded
	 * `fetch_feed()` beside a guarded one in the same file is a failure.
	 * Tokenized, so a mention in a comment (there are several, explaining
	 * exactly this rule) is not a hit.
	 */
	public function test_no_call_site_bypasses_the_guard() {
		$direct  = array(
			'wp_safe_remote_get',
			'wp_safe_remote_post',
			'wp_safe_remote_request',
			'wp_safe_remote_head',
			'wp_remote_get',
			'wp_remote_post',
			'wp_remote_head',
			'wp_remote_request',
			'curl_init',
			'curl_exec',
			'fsockopen',
			'stream_socket_client',
		);
		$wrapped = array( 'fetch_feed', 'wp_oembed_get', '_wp_oembed_get_object', 'download_url', 'get_remote_object' );

		$root      = dirname( __DIR__ ) . '/includes';
		$files     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$offenders = array();

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'class-outbound-guard.php' === $file->getFilename() ) {
				continue;
			}

			// A local source file, not a remote URL.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$ranges = $this->guarded_ranges( $tokens );

			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
					continue;
				}

				$name = strtolower( $token[1] );

				if ( in_array( $name, $direct, true ) ) {
					$offenders[] = $file->getFilename() . ':' . $token[2] . ' ' . $token[1] . '() — use Daymark_Outbound_Guard::get()/post()';
				} elseif ( in_array( $name, $wrapped, true ) && ! $this->inside_any_range( $index, $ranges ) ) {
					$offenders[] = $file->getFilename() . ':' . $token[2] . ' ' . $token[1] . '() — wrap this call in Daymark_Outbound_Guard::run()';
				}
			}
		}

		$this->assertSame( array(), $offenders );
	}

	/**
	 * Parse This can fetch pages, so Daymark calls it in one place only:
	 * Daymark_Parse_This, inside a Daymark_Outbound_Guard::run( … ) call.
	 * Any `ParseThis\…` class or function name in code elsewhere fails.
	 */
	public function test_parse_this_is_only_called_through_the_guarded_adapter() {
		$name_tokens = array_filter( array( T_STRING, defined( 'T_NAME_QUALIFIED' ) ? T_NAME_QUALIFIED : null, defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : null ) );
		$root        = dirname( __DIR__ ) . '/includes';
		$files       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$offenders   = array();
		$guarded     = 0;

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			// A local source file, not a remote URL.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$tokens  = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$ranges  = $this->guarded_ranges( $tokens );
			$adapter = 'class-parse-this.php' === $file->getFilename();

			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], $name_tokens, true ) || 0 !== stripos( ltrim( $token[1], '\\' ), 'ParseThis\\' ) ) {
					continue;
				}

				if ( $adapter && $this->inside_any_range( $index, $ranges ) ) {
					++$guarded;
					continue;
				}

				$offenders[] = $file->getFilename() . ':' . $token[2] . ' ' . $token[1] . ' — call Parse This through Daymark_Parse_This, inside Daymark_Outbound_Guard::run()';
			}
		}

		$this->assertSame( array(), $offenders );
		$this->assertGreaterThan( 0, $guarded, 'Daymark_Parse_This should construct the parser inside the guard.' );
	}

	/**
	 * Token-index ranges (open paren, matching close paren) of every
	 * `Daymark_Outbound_Guard::run( … )` call in a token stream.
	 *
	 * @param array<int, mixed> $tokens Output of token_get_all().
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function guarded_ranges( array $tokens ): array {
		$ranges = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count - 3; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || 'Daymark_Outbound_Guard' !== $tokens[ $i ][1] || T_DOUBLE_COLON !== ( $tokens[ $i + 1 ][0] ?? null ) ) {
				continue;
			}

			if ( ! is_array( $tokens[ $i + 2 ] ) || 'run' !== $tokens[ $i + 2 ][1] ) {
				continue;
			}

			$open = $i + 3;

			while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
				++$open;
			}

			if ( '(' !== ( $tokens[ $open ] ?? null ) ) {
				continue;
			}

			$depth = 0;

			for ( $j = $open; $j < $count; $j++ ) {
				if ( '(' === $tokens[ $j ] ) {
					++$depth;
				} elseif ( ')' === $tokens[ $j ] ) {
					--$depth;

					if ( 0 === $depth ) {
						$ranges[] = array( $open, $j );
						break;
					}
				}
			}
		}

		return $ranges;
	}

	/**
	 * Whether a token index falls inside any of the given ranges.
	 *
	 * @param int                            $index  Token index.
	 * @param array<int, array{0: int, 1: int}> $ranges Ranges from guarded_ranges().
	 * @return bool
	 */
	private function inside_any_range( int $index, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $index > $range[0] && $index < $range[1] ) {
				return true;
			}
		}

		return false;
	}
}
