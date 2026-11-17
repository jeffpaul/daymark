<?php
/**
 * Microsub import tests (issue #592).
 *
 * Daymark_Microsub_Import's discovery, IndieAuth sign-in, and follow-list
 * reading, plus the checklist and admin-post handlers on Settings ->
 * Daymark -> Import/Export.
 *
 * No Microsub or IndieAuth server is reachable from the test suite, so
 * every request is answered from `pre_http_request`, with bodies shaped as
 * the Microsub and IndieAuth specs describe them. A request with no canned
 * answer fails, the same as an unreachable server would.
 *
 * @package Daymark
 */

/**
 * Exercises the Microsub import.
 */
class Test_Microsub_Import extends WP_UnitTestCase {

	private const ME        = 'https://me.example/';
	private const AUTH      = 'https://me.example/auth';
	private const TOKEN     = 'https://me.example/token';
	private const MICROSUB  = 'https://reader.example/microsub/7';
	private const TOKEN_VAL = 'secret-token-123';

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var Daymark_Admin_Subscriptions */
	private $admin;

	/**
	 * Canned answers by URL: a response array, a WP_Error, or a callable
	 * given the request args.
	 *
	 * @var array<string, mixed>
	 */
	private array $routes = array();

	/**
	 * Every request made, in order.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		Daymark_Subscription_Html_Cache::reset();

		$this->subscriptions = new Daymark_Subscriptions();
		$this->admin         = new Daymark_Admin_Subscriptions();
		$this->routes        = array();
		$this->requests      = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'pre_http_request', array( $this, 'answer_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_http_request' ), 10 );
		remove_all_filters( 'daymark_microsub_import_enabled' );
		remove_all_filters( 'daymark_microsub_import_time_budget' );
		remove_all_filters( 'daymark_microsub_import_max_channels' );
		remove_all_filters( 'daymark_microsub_import_scope' );
		remove_all_filters( 'daymark_subscription_opml_max_entries' );
		delete_transient( $this->follows_key() );
		delete_transient( $this->sign_in_key() );
		delete_transient( 'daymark_opml_import_result_' . get_current_user_id() );
		Daymark_Plugin::instance()->rate_limiter->reset( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );
		unset( $_GET['tab'], $_GET['daymark_notice'], $_GET['code'], $_GET['state'], $_GET['iss'], $_GET['error'] );

		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------

	/**
	 * @param mixed  $preempt Existing short-circuit value.
	 * @param array  $args    Request args.
	 * @param string $url     Requested URL.
	 * @return mixed
	 */
	public function answer_http_request( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => (string) $url,
			'args' => (array) $args,
		);

		if ( array_key_exists( $url, $this->routes ) ) {
			$route = $this->routes[ $url ];

			return is_callable( $route ) ? $route( $args ) : $route;
		}

		return new WP_Error( 'http_request_failed', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * A canned HTTP answer.
	 *
	 * @param string               $body    Body.
	 * @param int                  $code    Status code.
	 * @param array<string, mixed> $headers Headers.
	 * @return array<string, mixed>
	 */
	private function response( string $body, int $code = 200, array $headers = array() ): array {
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** A canned JSON answer. */
	private function json( array $data, int $code = 200 ): array {
		return $this->response( (string) wp_json_encode( $data ), $code, array( 'content-type' => 'application/json' ) );
	}

	/** The person's home page, linking to its endpoints in `<link>` tags. */
	private function me_page( string $extra = '' ): array {
		return $this->response(
			'<html><head><title>Me</title>'
			. '<link rel="authorization_endpoint" href="/auth">'
			. '<link rel="token_endpoint" href="/token">'
			. '<link rel="microsub" href="' . self::MICROSUB . '">'
			. $extra
			. '</head><body></body></html>'
		);
	}

	/** Answer everything a full sign-in and list read needs. */
	private function serve_microsub_server(): void {
		$this->routes[ self::ME ]    = $this->me_page();
		$this->routes[ self::TOKEN ] = function ( array $args ) {
			if ( isset( $args['body']['action'] ) && 'revoke' === $args['body']['action'] ) {
				return $this->response( '' );
			}

			return $this->json(
				array(
					'access_token' => self::TOKEN_VAL,
					'token_type'   => 'Bearer',
					'scope'        => 'read follow channels',
					'me'           => self::ME,
				)
			);
		};

		$this->routes[ self::MICROSUB . '?action=channels' ] = $this->json(
			array(
				'channels' => array(
					array(
						'uid'    => 'notifications',
						'name'   => 'Notifications',
						'unread' => 3,
					),
					array(
						'uid'  => 'friends 1',
						'name' => 'Friends',
					),
					array(
						'uid'  => 'news',
						'name' => 'News <b>&</b> more',
					),
				),
			)
		);

		$this->routes[ self::MICROSUB . '?action=follow&channel=friends%201' ] = $this->json(
			array(
				'items' => array(
					array(
						'type' => 'feed',
						'url'  => 'https://ada.example/',
					),
					array(
						'type' => 'feed',
						'url'  => 'https://grace.example/notes/feed.xml',
						'name' => 'Grace &amp; co',
					),
				),
			)
		);

		$this->routes[ self::MICROSUB . '?action=follow&channel=news' ] = $this->json(
			array(
				'items' => array(
					array(
						'type' => 'feed',
						'url'  => 'https://news.example/feed/',
					),
					// Already listed under Friends: shown once.
					array(
						'type' => 'feed',
						'url'  => 'https://ada.example',
					),
					array(
						'type' => 'feed',
						'url'  => 'javascript:alert(1)',
					),
					'not an item',
				),
			)
		);
	}

	/** The request made to a URL, or null. */
	private function request_to( string $url, int $nth = 0 ): ?array {
		$matches = array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $url ) {
					return $request['url'] === $url;
				}
			)
		);

		return $matches[ $nth ] ?? null;
	}

	/** The query arguments of a URL. */
	private function query_of( string $url ): array {
		$query = array();

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	private function follows_key(): string {
		return 'daymark_microsub_import_follows_' . get_current_user_id();
	}

	private function sign_in_key(): string {
		return 'daymark_microsub_import_sign_in_' . get_current_user_id();
	}

	/** Begin a sign-in and return its pending details. */
	private function pending(): array {
		$begun = Daymark_Microsub_Import::begin( self::ME );

		$this->assertIsArray( $begun );

		return $begun['pending'];
	}

	/** Three stashed follows in two channels. */
	private function stash( array $extra = array() ): array {
		$entries = array_merge(
			Daymark_Microsub_Import::parse_follows(
				array(
					array( 'url' => 'https://ada.example/' ),
					array( 'url' => 'https://grace.example/' ),
				),
				'Friends'
			),
			Daymark_Microsub_Import::parse_follows( array( array( 'url' => 'https://news.example/' ) ), 'News' )
		);

		$stash = array_merge(
			array(
				'server'     => 'reader.example',
				'entries'    => $entries,
				'total'      => count( $entries ),
				'truncated'  => false,
				'incomplete' => false,
			),
			$extra
		);

		set_transient( $this->follows_key(), $stash, HOUR_IN_SECONDS );

		return $stash;
	}

	/** Make a followed site discoverable: a page that links to an RSS feed. */
	private function serve_site( string $host ): void {
		$this->routes[ 'https://' . $host . '/' ] = $this->response(
			'<html><head><title>' . $host . '</title>'
			. '<link rel="alternate" type="application/rss+xml" href="https://' . $host . '/feed/" />'
			. '</head><body></body></html>',
			200,
			array( 'content-type' => 'text/html; charset=UTF-8' )
		);
	}

	/** Renders the settings page's Import/Export tab. */
	private function render_import_export_tab(): string {
		$_GET['tab'] = 'import-export';

		ob_start();
		$this->admin->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Call an admin_post handler, stopping it at its redirect instead of at
	 * exit(). Returns the URL it redirected to.
	 *
	 * @param string               $method Handler method name.
	 * @param string               $nonce  Nonce action ('' for none); its field is the action plus `_nonce`.
	 * @param array<string, mixed> $post   Submitted fields.
	 * @return string
	 */
	private function call_handler( string $method, string $nonce = '', array $post = array() ): string {
		$stop = static function ( $location ) {
			throw new RuntimeException( esc_url_raw( (string) $location ) );
		};

		$_POST = $post;

		if ( '' !== $nonce ) {
			$_REQUEST[ $nonce . '_nonce' ] = wp_create_nonce( $nonce );
		}

		add_filter( 'wp_redirect', $stop );

		try {
			$this->admin->$method();
			$location = '';
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $stop );
			$_POST = array();
			unset( $_REQUEST[ $nonce . '_nonce' ] );
		}

		return $location;
	}

	/** The error message the last redirect_with_error() left for this user. */
	private function stored_error(): string {
		return (string) get_transient( 'daymark_admin_error_' . get_current_user_id() );
	}

	// -----------------------------------------------------------------
	// The web address, and what this site signs in as.
	// -----------------------------------------------------------------

	public function test_normalize_me_tidies_what_was_typed() {
		$this->assertSame( 'https://me.example/', Daymark_Microsub_Import::normalize_me( ' me.example ' ) );
		$this->assertSame( 'http://me.example/', Daymark_Microsub_Import::normalize_me( 'http://me.example' ) );
		$this->assertSame( 'https://me.example/about', Daymark_Microsub_Import::normalize_me( 'https://me.example/about#top' ) );
	}

	public function test_normalize_me_refuses_what_is_not_a_plain_web_address() {
		$this->assertSame( '', Daymark_Microsub_Import::normalize_me( '' ) );
		$this->assertSame( '', Daymark_Microsub_Import::normalize_me( 'ftp://me.example/' ) );
		$this->assertSame( '', Daymark_Microsub_Import::normalize_me( 'javascript:alert(1)' ) );
		$this->assertSame( '', Daymark_Microsub_Import::normalize_me( 'https://user:pass@me.example/' ) );
	}

	public function test_redirect_uri_shares_the_client_ids_origin() {
		$client   = Daymark_Microsub_Import::client_id();
		$redirect = Daymark_Microsub_Import::redirect_uri();

		$this->assertSame( home_url( '/' ), $client );
		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $redirect );
		$this->assertSame( 'daymark_microsub_callback', $this->query_of( $redirect )['action'] );
		$this->assertSame( wp_parse_url( $client, PHP_URL_HOST ), wp_parse_url( $redirect, PHP_URL_HOST ) );
	}

	// -----------------------------------------------------------------
	// discover().
	// -----------------------------------------------------------------

	public function test_discover_reads_link_tags_and_resolves_relative_addresses() {
		$this->routes[ self::ME ] = $this->me_page();

		$found = Daymark_Microsub_Import::discover( 'me.example' );

		$this->assertSame( self::ME, $found['me'] );
		$this->assertSame( self::AUTH, $found['authorization_endpoint'] );
		$this->assertSame( self::TOKEN, $found['token_endpoint'] );
		$this->assertSame( self::MICROSUB, $found['microsub_endpoint'] );
		$this->assertSame( '', $found['revocation_endpoint'] );
	}

	public function test_discover_prefers_the_link_header() {
		$this->routes[ self::ME ] = $this->response(
			'<html><head><link rel="microsub" href="https://other.example/microsub"></head></html>',
			200,
			array(
				'link' => '<https://reader.example/microsub/7>; rel="microsub", </auth>; rel="authorization_endpoint", <https://me.example/token>; rel=token_endpoint',
			)
		);

		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertSame( self::MICROSUB, $found['microsub_endpoint'] );
		$this->assertSame( self::AUTH, $found['authorization_endpoint'] );
		$this->assertSame( self::TOKEN, $found['token_endpoint'] );
	}

	public function test_discover_prefers_the_metadata_document() {
		$this->routes[ self::ME ] = $this->me_page( '<link rel="indieauth-metadata" href="https://me.example/.well-known/oauth">' );

		$this->routes['https://me.example/.well-known/oauth'] = $this->json(
			array(
				'issuer'                 => 'https://me.example/',
				'authorization_endpoint' => 'https://me.example/new-auth',
				'token_endpoint'         => 'https://me.example/new-token',
				'revocation_endpoint'    => 'https://me.example/revoke',
			)
		);

		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertSame( 'https://me.example/new-auth', $found['authorization_endpoint'] );
		$this->assertSame( 'https://me.example/new-token', $found['token_endpoint'] );
		$this->assertSame( 'https://me.example/revoke', $found['revocation_endpoint'] );
		$this->assertSame( 'https://me.example/', $found['issuer'] );
	}

	public function test_discover_falls_back_to_link_tags_when_the_metadata_document_fails() {
		$this->routes[ self::ME ] = $this->me_page( '<link rel="indieauth-metadata" href="https://me.example/missing">' );

		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertSame( self::AUTH, $found['authorization_endpoint'] );
	}

	public function test_discover_says_when_there_is_no_microsub_server() {
		$this->routes[ self::ME ] = $this->response( '<html><head><link rel="authorization_endpoint" href="/auth"><link rel="token_endpoint" href="/token"></head></html>' );

		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertWPError( $found );
		$this->assertSame( 'daymark_microsub_import_no_server', $found->get_error_code() );
	}

	public function test_discover_says_when_there_is_no_sign_in() {
		$this->routes[ self::ME ] = $this->response( '<html><head><link rel="microsub" href="' . self::MICROSUB . '"></head></html>' );

		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertWPError( $found );
		$this->assertSame( 'daymark_microsub_import_no_sign_in', $found->get_error_code() );
	}

	public function test_discover_says_when_the_address_cannot_be_reached() {
		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertWPError( $found );
		$this->assertSame( 'daymark_microsub_import_unreachable', $found->get_error_code() );
		$this->assertStringContainsString( 'me.example', $found->get_error_message() );
	}

	public function test_discover_refuses_an_unusable_or_private_address_without_a_request() {
		foreach ( array( '', 'ftp://me.example/', 'http://127.0.0.1/', 'http://169.254.169.254/' ) as $address ) {
			$found = Daymark_Microsub_Import::discover( $address );

			$this->assertWPError( $found );
			$this->assertSame( 'daymark_microsub_import_invalid_url', $found->get_error_code() );
		}

		$this->assertSame( array(), $this->requests );
	}

	public function test_discover_ignores_endpoints_that_are_not_web_addresses() {
		$this->routes[ self::ME ] = $this->response(
			'<html><head>'
			. '<link rel="authorization_endpoint" href="/auth"><link rel="token_endpoint" href="/token">'
			. '<link rel="microsub" href="javascript:alert(1)">'
			. '</head></html>'
		);

		$found = Daymark_Microsub_Import::discover( self::ME );

		$this->assertWPError( $found );
		$this->assertSame( 'daymark_microsub_import_no_server', $found->get_error_code() );
	}

	// -----------------------------------------------------------------
	// begin().
	// -----------------------------------------------------------------

	public function test_begin_builds_an_indieauth_request_with_pkce() {
		$this->routes[ self::ME ] = $this->me_page();

		$begun = Daymark_Microsub_Import::begin( self::ME );
		$query = $this->query_of( $begun['redirect'] );

		$this->assertStringStartsWith( self::AUTH . '?', $begun['redirect'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( Daymark_Microsub_Import::client_id(), $query['client_id'] );
		$this->assertSame( Daymark_Microsub_Import::redirect_uri(), $query['redirect_uri'] );
		$this->assertSame( 'read follow channels', $query['scope'] );
		$this->assertSame( self::ME, $query['me'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( $begun['pending']['state'], $query['state'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $query['state'] ) );

		// PKCE: the challenge is the base64url SHA-256 of the kept verifier.
		$verifier = $begun['pending']['verifier'];

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{43,128}$/', $verifier );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Recomputing the PKCE challenge.
		$this->assertSame( rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), $query['code_challenge'] );

		$this->assertSame( self::TOKEN, $begun['pending']['token_endpoint'] );
		$this->assertSame( self::MICROSUB, $begun['pending']['microsub_endpoint'] );
	}

	public function test_begin_keeps_a_sign_in_pages_own_query() {
		$this->routes[ self::ME ] = $this->response(
			'<html><head>'
			. '<link rel="authorization_endpoint" href="https://me.example/?rest_route=/indieauth/1.0/auth">'
			. '<link rel="token_endpoint" href="/token"><link rel="microsub" href="' . self::MICROSUB . '">'
			. '</head></html>'
		);

		$begun = Daymark_Microsub_Import::begin( self::ME );
		$query = $this->query_of( $begun['redirect'] );

		$this->assertSame( '/indieauth/1.0/auth', $query['rest_route'] );
		$this->assertSame( 'code', $query['response_type'] );
	}

	public function test_begin_passes_a_discovery_error_through() {
		$begun = Daymark_Microsub_Import::begin( self::ME );

		$this->assertWPError( $begun );
	}

	// -----------------------------------------------------------------
	// complete().
	// -----------------------------------------------------------------

	public function test_complete_reads_the_follow_list_by_channel() {
		$this->serve_microsub_server();

		$pending = $this->pending();
		$follows = Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
			)
		);

		$this->assertSame( 'reader.example', $follows['server'] );
		$this->assertSame( 3, $follows['total'] );
		$this->assertFalse( $follows['truncated'] );
		$this->assertFalse( $follows['incomplete'] );
		$this->assertSame(
			array( 'https://ada.example/', 'https://grace.example/notes/feed.xml', 'https://news.example/feed/' ),
			wp_list_pluck( $follows['entries'], 'html_url' )
		);
		$this->assertSame( array( 'Friends', 'Friends', 'News & more' ), wp_list_pluck( $follows['entries'], 'channel' ) );
		$this->assertSame( array( 'ada.example', 'Grace & co', 'news.example/feed' ), wp_list_pluck( $follows['entries'], 'label' ) );
		$this->assertSame( array( '', '', '' ), wp_list_pluck( $follows['entries'], 'xml_url' ) );
	}

	public function test_complete_trades_the_code_the_way_indieauth_asks() {
		$this->serve_microsub_server();

		$pending = $this->pending();

		Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
			)
		);

		$exchange = $this->request_to( self::TOKEN );

		$this->assertSame( 'POST', $exchange['args']['method'] );
		$this->assertSame(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => 'the-code',
				'client_id'     => Daymark_Microsub_Import::client_id(),
				'redirect_uri'  => Daymark_Microsub_Import::redirect_uri(),
				'code_verifier' => $pending['verifier'],
			),
			$exchange['args']['body']
		);
		$this->assertSame( 'application/json', $exchange['args']['headers']['Accept'] );
	}

	public function test_requests_that_carry_the_code_or_token_follow_no_redirects() {
		$this->serve_microsub_server();

		$pending = $this->pending();

		Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
			)
		);

		foreach ( $this->requests as $request ) {
			if ( self::ME === $request['url'] ) {
				continue;
			}

			$this->assertSame( 0, $request['args']['redirection'], $request['url'] );
		}

		$channels = $this->request_to( self::MICROSUB . '?action=channels' );

		$this->assertSame( 'Bearer ' . self::TOKEN_VAL, $channels['args']['headers']['Authorization'] );
	}

	public function test_complete_asks_for_the_token_to_be_revoked_afterwards() {
		$this->serve_microsub_server();

		$pending = $this->pending();

		Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
			)
		);

		$last = end( $this->requests );

		$this->assertSame( self::TOKEN, $last['url'] );
		$this->assertSame(
			array(
				'token'  => self::TOKEN_VAL,
				'action' => 'revoke',
			),
			$last['args']['body']
		);
	}

	public function test_complete_uses_the_revocation_endpoint_when_there_is_one() {
		$this->serve_microsub_server();

		$this->routes['https://me.example/revoke'] = $this->response( '' );

		$pending                        = $this->pending();
		$pending['revocation_endpoint'] = 'https://me.example/revoke';

		Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
			)
		);

		$last = end( $this->requests );

		$this->assertSame( 'https://me.example/revoke', $last['url'] );
		$this->assertSame( array( 'token' => self::TOKEN_VAL ), $last['args']['body'] );
	}

	public function test_complete_refuses_a_state_that_does_not_match_without_a_request() {
		$this->serve_microsub_server();

		$pending        = $this->pending();
		$this->requests = array();

		foreach ( array( 'wrong', '' ) as $state ) {
			$result = Daymark_Microsub_Import::complete(
				$pending,
				array(
					'code'  => 'the-code',
					'state' => $state,
				)
			);

			$this->assertWPError( $result );
			$this->assertSame( 'daymark_microsub_import_expired', $result->get_error_code() );
		}

		$this->assertWPError( Daymark_Microsub_Import::complete( array(), array( 'code' => 'the-code' ) ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_complete_reports_a_cancelled_sign_in() {
		$this->serve_microsub_server();

		$pending        = $this->pending();
		$this->requests = array();

		$result = Daymark_Microsub_Import::complete(
			$pending,
			array(
				'error' => 'access_denied',
				'state' => $pending['state'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_microsub_import_cancelled', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_complete_refuses_an_answer_from_a_different_issuer() {
		$this->serve_microsub_server();

		$pending           = $this->pending();
		$pending['issuer'] = 'https://me.example/';
		$this->requests    = array();

		$result = Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
				'iss'   => 'https://evil.example/',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_microsub_import_sign_in_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_complete_fails_cleanly_when_no_token_comes_back() {
		$this->serve_microsub_server();

		$pending = $this->pending();

		foreach ( array( $this->json( array( 'error' => 'invalid_grant' ), 400 ), $this->json( array( 'access_token' => "bad\ntoken" ) ), $this->response( '<html>oops</html>' ) ) as $answer ) {
			$this->routes[ self::TOKEN ] = $answer;

			$result = Daymark_Microsub_Import::complete(
				$pending,
				array(
					'code'  => 'the-code',
					'state' => $pending['state'],
				)
			);

			$this->assertWPError( $result );
			$this->assertSame( 'daymark_microsub_import_sign_in_failed', $result->get_error_code() );
		}

		$this->assertNull( $this->request_to( self::MICROSUB . '?action=channels' ) );
	}

	public function test_complete_accepts_a_form_encoded_token_answer() {
		$this->serve_microsub_server();

		$pending                     = $this->pending();
		$this->routes[ self::TOKEN ] = $this->response( 'access_token=' . self::TOKEN_VAL . '&scope=read&me=https%3A%2F%2Fme.example%2F' );

		$follows = Daymark_Microsub_Import::complete(
			$pending,
			array(
				'code'  => 'the-code',
				'state' => $pending['state'],
			)
		);

		$this->assertCount( 3, $follows['entries'] );
	}

	// -----------------------------------------------------------------
	// fetch_follows() and parsing.
	// -----------------------------------------------------------------

	public function test_fetch_follows_fails_when_the_channel_list_cannot_be_read() {
		$this->routes[ self::MICROSUB . '?action=channels' ] = $this->json( array( 'error' => 'unauthorized' ), 401 );

		$result = Daymark_Microsub_Import::fetch_follows( self::MICROSUB, self::TOKEN_VAL );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_microsub_import_failed', $result->get_error_code() );
	}

	public function test_fetch_follows_marks_the_list_incomplete_when_a_channel_fails() {
		$this->serve_microsub_server();

		unset( $this->routes[ self::MICROSUB . '?action=follow&channel=news' ] );

		$result = Daymark_Microsub_Import::fetch_follows( self::MICROSUB, self::TOKEN_VAL );

		$this->assertTrue( $result['incomplete'] );
		$this->assertCount( 2, $result['entries'] );
	}

	public function test_fetch_follows_fails_when_no_channel_can_be_read() {
		$this->serve_microsub_server();

		unset( $this->routes[ self::MICROSUB . '?action=follow&channel=news' ], $this->routes[ self::MICROSUB . '?action=follow&channel=friends%201' ] );

		$this->assertWPError( Daymark_Microsub_Import::fetch_follows( self::MICROSUB, self::TOKEN_VAL ) );
	}

	public function test_fetch_follows_stops_at_the_channel_cap() {
		$this->serve_microsub_server();

		add_filter( 'daymark_microsub_import_max_channels', '__return_true' );

		$result = Daymark_Microsub_Import::fetch_follows( self::MICROSUB, self::TOKEN_VAL );

		$this->assertTrue( $result['incomplete'] );
		$this->assertSame( array( 'Friends', 'Friends' ), wp_list_pluck( $result['entries'], 'channel' ) );
		$this->assertNull( $this->request_to( self::MICROSUB . '?action=follow&channel=news' ) );
	}

	public function test_fetch_follows_stops_when_its_time_is_up() {
		$this->serve_microsub_server();

		add_filter( 'daymark_microsub_import_time_budget', '__return_zero' );

		$result = Daymark_Microsub_Import::fetch_follows( self::MICROSUB, self::TOKEN_VAL );

		$this->assertTrue( $result['incomplete'] );
		$this->assertSame( array( 'Friends', 'Friends' ), wp_list_pluck( $result['entries'], 'channel' ) );
	}

	public function test_fetch_follows_truncates_a_list_over_the_entry_cap() {
		$this->serve_microsub_server();

		add_filter(
			'daymark_subscription_opml_max_entries',
			static function () {
				return 2;
			}
		);

		$result = Daymark_Microsub_Import::fetch_follows( self::MICROSUB, self::TOKEN_VAL );

		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 2, $result['entries'] );
	}

	public function test_parse_channels_skips_notifications_and_malformed_items() {
		$channels = Daymark_Microsub_Import::parse_channels(
			array(
				'channels' => array(
					array(
						'uid'  => 'notifications',
						'name' => 'Notifications',
					),
					array( 'name' => 'No uid' ),
					'nope',
					array( 'uid' => array( 'x' ) ),
					array( 'uid' => 'abc' ),
					array(
						'uid'  => 42,
						'name' => "Tidy\n<script>me</script>",
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'uid'  => 'abc',
					'name' => 'abc',
				),
				array(
					'uid'  => '42',
					'name' => 'Tidy',
				),
			),
			$channels
		);

		$this->assertSame( array(), Daymark_Microsub_Import::parse_channels( array( 'channels' => 'nope' ) ) );
	}

	public function test_parse_follows_keeps_only_web_addresses() {
		$entries = Daymark_Microsub_Import::parse_follows(
			array(
				array( 'url' => ' https://ada.example/blog/ ' ),
				array( 'url' => 'ftp://files.example/' ),
				array( 'url' => 42 ),
				array( 'name' => 'No address' ),
				array(
					'url'  => 'http://plain.example',
					'name' => '<b>Plain</b>',
				),
			),
			'Friends'
		);

		$this->assertSame(
			array(
				array(
					'label'    => 'ada.example/blog',
					'xml_url'  => '',
					'html_url' => 'https://ada.example/blog/',
					'icon_url' => '',
					'channel'  => 'Friends',
				),
				array(
					'label'    => 'Plain',
					'xml_url'  => '',
					'html_url' => 'http://plain.example',
					'icon_url' => '',
					'channel'  => 'Friends',
				),
			),
			$entries
		);
	}

	// -----------------------------------------------------------------
	// Settings -> Daymark -> Import/Export.
	// -----------------------------------------------------------------

	public function test_section_offers_a_sign_in_with_the_sites_own_address() {
		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'Import from a Microsub server', $output );
		$this->assertStringContainsString( 'daymark_microsub_import_connect', $output );
		$this->assertMatchesRegularExpression( '/name="daymark_microsub_me"[^>]+value="' . preg_quote( esc_attr( home_url( '/' ) ), '/' ) . '"/', $output );
		$this->assertStringContainsString( 'Sign in and load my list', $output );
	}

	public function test_section_can_be_turned_off() {
		add_filter( 'daymark_microsub_import_enabled', '__return_false' );

		$this->assertStringNotContainsString( 'Import from a Microsub server', $this->render_import_export_tab() );
	}

	public function test_checklist_groups_sites_by_channel() {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://grace.example/',
				'feed_url' => 'https://grace.example/feed/',
			)
		);
		$this->stash();

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'reader.example follows 3 sites for you.', $output );
		$this->assertStringContainsString( 'daymark_microsub_import_confirm', $output );
		$this->assertStringContainsString( 'daymark_microsub_import_cancel', $output );
		$this->assertStringNotContainsString( 'daymark_microsub_import_connect', $output );
		$this->assertSame( 2, substr_count( $output, 'data-daymark-microsub-import-channel ' ) );
		$this->assertMatchesRegularExpression( '/<strong>Friends<\/strong>\s*<span class="description">\s*2 sites\s*<\/span>/', $output );
		$this->assertMatchesRegularExpression( '/<strong>News<\/strong>\s*<span class="description">\s*1 site\s*<\/span>/', $output );

		// A site that can be imported is checked and can be toggled.
		$this->assertMatchesRegularExpression( '/name="daymark_microsub_entry\[\]"\s+value="0"\s+checked=\'checked\'\s+data-daymark-microsub-import-entry/', $output );
		// One already followed is checked, disabled, and says so.
		$this->assertMatchesRegularExpression( '/name="daymark_microsub_entry\[\]"\s+value="1"\s+checked=\'checked\'\s+disabled=\'disabled\'\s+\/>/', $output );
		$this->assertSame( 1, substr_count( $output, 'already subscribed' ) );
	}

	public function test_checklist_escapes_what_the_server_sent() {
		$this->stash(
			array(
				'server'  => '<i>reader</i>',
				'entries' => array(
					array(
						'label'    => '<script>alert(1)</script>',
						'xml_url'  => '',
						'html_url' => 'https://ada.example/?a=1&b="2"',
						'icon_url' => '',
						'channel'  => '<b>Friends</b>',
					),
				),
			)
		);

		$output = $this->render_import_export_tab();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringNotContainsString( '<b>Friends</b>', $output );
		$this->assertStringNotContainsString( '<i>reader</i>', $output );
		$this->assertStringContainsString( '&lt;b&gt;Friends&lt;/b&gt;', $output );
	}

	public function test_checklist_after_a_part_import_checks_only_what_is_waiting() {
		$this->stash( array( 'pending' => array( 2 ) ) );

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( '1 site is still waiting, checked below.', $output );
		$this->assertMatchesRegularExpression( '/value="0"\s+data-daymark-microsub-import-entry/', $output );
		$this->assertMatchesRegularExpression( '/value="2"\s+checked=\'checked\'\s+data-daymark-microsub-import-entry/', $output );
	}

	public function test_checklist_notes_a_truncated_or_incomplete_list() {
		$this->stash(
			array(
				'total'      => 1500,
				'truncated'  => true,
				'incomplete' => true,
			)
		);

		$output = $this->render_import_export_tab();

		$this->assertStringContainsString( 'Only the first 3 of your 1500 follows are listed.', $output );
		$this->assertStringContainsString( 'Some channels could not be loaded', $output );
	}

	public function test_selected_microsub_entries_keeps_valid_indices_and_their_keys() {
		$stash    = $this->stash();
		$selected = Daymark_Admin_Subscriptions::selected_microsub_entries( $stash['entries'], array( '2', 0, 2, 99, 'abc', -1 ) );

		$this->assertSame( array( 0, 2 ), array_keys( $selected ) );
		$this->assertSame( 'https://news.example/', $selected[2]['html_url'] );
	}

	// -----------------------------------------------------------------
	// The admin-post handlers.
	// -----------------------------------------------------------------

	public function test_connect_sends_the_browser_to_the_sign_in_page() {
		$this->routes[ self::ME ] = $this->me_page();

		$location = $this->call_handler( 'handle_microsub_import_connect', 'daymark_microsub_import_connect', array( 'daymark_microsub_me' => 'me.example' ) );
		$pending  = get_transient( $this->sign_in_key() );

		$this->assertStringStartsWith( self::AUTH . '?', $location );
		$this->assertSame( $pending['state'], $this->query_of( $location )['state'] );
		$this->assertSame( self::MICROSUB, $pending['microsub_endpoint'] );
	}

	public function test_connect_shows_a_discovery_error_on_the_import_tab() {
		$location = $this->call_handler( 'handle_microsub_import_connect', 'daymark_microsub_import_connect', array( 'daymark_microsub_me' => 'me.example' ) );

		$this->assertStringContainsString( 'tab=import-export', $location );
		$this->assertStringContainsString( 'daymark_notice=error', $location );
		$this->assertStringContainsString( 'could not reach me.example', $this->stored_error() );
		$this->assertFalse( get_transient( $this->sign_in_key() ) );
	}

	public function test_handlers_refuse_someone_who_cannot_manage_the_site() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( 'handle_microsub_import_connect', 'handle_microsub_import_callback', 'handle_microsub_import_confirm', 'handle_microsub_import_cancel' ) as $method ) {
			try {
				$this->admin->$method();
				$this->fail( $method . ' should have refused.' );
			} catch ( WPDieException $e ) {
				$this->assertStringContainsString( 'not allowed', $e->getMessage() );
			}
		}

		$this->assertSame( array(), $this->requests );
	}

	public function test_callback_stashes_the_list_and_nothing_secret() {
		$this->serve_microsub_server();
		$this->call_handler( 'handle_microsub_import_connect', 'daymark_microsub_import_connect', array( 'daymark_microsub_me' => self::ME ) );

		$pending       = get_transient( $this->sign_in_key() );
		$_GET['code']  = 'the-code';
		$_GET['state'] = $pending['state'];

		$location = $this->call_handler( 'handle_microsub_import_callback' );
		$stashed  = get_transient( $this->follows_key() );

		$this->assertStringContainsString( 'tab=import-export', $location );
		$this->assertStringEndsWith( '#daymark-microsub-import', $location );
		$this->assertCount( 3, $stashed['entries'] );

		// The sign-in details are used once, and neither the token, the
		// code, nor the verifier is kept anywhere.
		$this->assertFalse( get_transient( $this->sign_in_key() ) );

		$kept = (string) wp_json_encode( $stashed );

		$this->assertStringNotContainsString( self::TOKEN_VAL, $kept );
		$this->assertStringNotContainsString( 'the-code', $kept );
		$this->assertStringNotContainsString( $pending['verifier'], $kept );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion: the token must not be in any option or transient.
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( self::TOKEN_VAL ) . '%' ) ) );
	}

	public function test_callback_with_the_wrong_state_fails_and_leaves_the_real_sign_in_alone() {
		$this->serve_microsub_server();
		$this->call_handler( 'handle_microsub_import_connect', 'daymark_microsub_import_connect', array( 'daymark_microsub_me' => self::ME ) );

		$this->requests = array();
		$_GET['code']   = 'the-code';
		$_GET['state']  = 'not-the-state';

		$location = $this->call_handler( 'handle_microsub_import_callback' );

		$this->assertStringContainsString( 'daymark_notice=error', $location );
		$this->assertStringContainsString( 'expired', $this->stored_error() );
		$this->assertFalse( get_transient( $this->follows_key() ) );
		$this->assertSame( array(), $this->requests );

		// A forged link can't cancel the sign-in: the real answer still works.
		$_GET['state'] = get_transient( $this->sign_in_key() )['state'];

		$this->call_handler( 'handle_microsub_import_callback' );

		$this->assertCount( 3, get_transient( $this->follows_key() )['entries'] );
		$this->assertFalse( get_transient( $this->sign_in_key() ) );
	}

	public function test_callback_answer_cannot_be_used_twice() {
		$this->serve_microsub_server();
		$this->call_handler( 'handle_microsub_import_connect', 'daymark_microsub_import_connect', array( 'daymark_microsub_me' => self::ME ) );

		$_GET['code']  = 'the-code';
		$_GET['state'] = get_transient( $this->sign_in_key() )['state'];

		$this->call_handler( 'handle_microsub_import_callback' );
		delete_transient( $this->follows_key() );

		$this->requests = array();
		$location       = $this->call_handler( 'handle_microsub_import_callback' );

		$this->assertStringContainsString( 'daymark_notice=error', $location );
		$this->assertFalse( get_transient( $this->follows_key() ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_callback_without_a_sign_in_in_progress_fails() {
		$_GET['code']  = 'the-code';
		$_GET['state'] = 'anything';

		$location = $this->call_handler( 'handle_microsub_import_callback' );

		$this->assertStringContainsString( 'daymark_notice=error', $location );
		$this->assertSame( array(), $this->requests );
	}

	public function test_callback_says_when_the_server_follows_nothing() {
		$this->serve_microsub_server();

		$this->routes[ self::MICROSUB . '?action=channels' ] = $this->json( array( 'channels' => array() ) );

		$this->call_handler( 'handle_microsub_import_connect', 'daymark_microsub_import_connect', array( 'daymark_microsub_me' => self::ME ) );

		$_GET['code']  = 'the-code';
		$_GET['state'] = get_transient( $this->sign_in_key() )['state'];

		$this->call_handler( 'handle_microsub_import_callback' );

		$this->assertStringContainsString( 'isn\'t following any sites yet', $this->stored_error() );
		$this->assertFalse( get_transient( $this->follows_key() ) );
	}

	public function test_confirm_follows_the_checked_sites_the_way_adding_by_hand_does() {
		$this->stash();
		$this->serve_site( 'ada.example' );
		$this->serve_site( 'news.example' );

		$location = $this->call_handler( 'handle_microsub_import_confirm', 'daymark_microsub_import_confirm', array( 'daymark_microsub_entry' => array( '0', '2' ) ) );

		$this->assertStringContainsString( 'daymark_notice=microsub_imported', $location );
		$this->assertStringNotContainsString( 'partial', $location );
		$this->assertFalse( get_transient( $this->follows_key() ) );

		$rows = $this->subscriptions->get_all();

		$this->assertEqualSets(
			array( 'https://ada.example/feed/', 'https://news.example/feed/' ),
			wp_list_pluck( $rows, 'feed_url' )
		);
		$this->assertEqualSets( array( 'ada.example', 'news.example' ), wp_list_pluck( $rows, 'site_title' ) );

		$_GET['daymark_notice'] = 'microsub_imported';

		$this->assertStringContainsString( 'Import complete: 2 subscribed, 0 already subscribed, 0 failed.', $this->render_import_export_tab() );
	}

	public function test_confirm_stops_at_its_time_budget_and_keeps_the_rest_waiting() {
		$this->stash();
		$this->serve_site( 'ada.example' );
		$this->serve_site( 'grace.example' );
		$this->serve_site( 'news.example' );

		add_filter( 'daymark_microsub_import_time_budget', '__return_zero' );

		$location = $this->call_handler( 'handle_microsub_import_confirm', 'daymark_microsub_import_confirm', array( 'daymark_microsub_entry' => array( '0', '1', '2' ) ) );
		$stashed  = get_transient( $this->follows_key() );

		$this->assertStringContainsString( 'daymark_notice=microsub_imported_partial', $location );
		$this->assertStringEndsWith( '#daymark-microsub-import', $location );
		$this->assertSame( array( 1, 2 ), $stashed['pending'] );
		$this->assertCount( 1, $this->subscriptions->get_all() );

		$_GET['daymark_notice'] = 'microsub_imported_partial';
		$output                 = $this->render_import_export_tab();

		$this->assertStringContainsString( 'Imported so far: 1 subscribed, 0 already subscribed, 0 failed.', $output );
		$this->assertStringContainsString( '2 sites are still waiting, checked below.', $output );

		// Pressing Import selected again carries on with what was waiting.
		remove_all_filters( 'daymark_microsub_import_time_budget' );

		$location = $this->call_handler( 'handle_microsub_import_confirm', 'daymark_microsub_import_confirm', array( 'daymark_microsub_entry' => array( '1', '2' ) ) );

		$this->assertStringNotContainsString( 'partial', $location );
		$this->assertCount( 3, $this->subscriptions->get_all() );
		$this->assertFalse( get_transient( $this->follows_key() ) );
	}

	public function test_confirm_only_imports_from_the_stashed_list() {
		$this->stash();

		$location = $this->call_handler( 'handle_microsub_import_confirm', 'daymark_microsub_import_confirm', array( 'daymark_microsub_entry' => array( '99', 'https://evil.example/' ) ) );

		$this->assertStringContainsString( 'daymark_notice=error', $location );
		$this->assertStringContainsString( 'Select at least one site', $this->stored_error() );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->subscriptions->get_all() );
	}

	public function test_confirm_without_a_list_asks_to_load_it_again() {
		$location = $this->call_handler( 'handle_microsub_import_confirm', 'daymark_microsub_import_confirm', array( 'daymark_microsub_entry' => array( '0' ) ) );

		$this->assertStringContainsString( 'daymark_notice=error', $location );
		$this->assertStringContainsString( 'expired', $this->stored_error() );
	}

	public function test_cancel_discards_the_list() {
		$this->stash();
		set_transient( $this->sign_in_key(), array( 'state' => 'x' ), MINUTE_IN_SECONDS );

		$location = $this->call_handler( 'handle_microsub_import_cancel', 'daymark_microsub_import_cancel' );

		$this->assertStringContainsString( 'tab=import-export', $location );
		$this->assertFalse( get_transient( $this->follows_key() ) );
		$this->assertFalse( get_transient( $this->sign_in_key() ) );
	}

	// -----------------------------------------------------------------
	// Daymark_Subscription_OPML::import_entries()'s deadline.
	// -----------------------------------------------------------------

	public function test_import_entries_without_a_deadline_imports_everything() {
		$stash = $this->stash();
		$this->serve_site( 'ada.example' );
		$this->serve_site( 'grace.example' );
		$this->serve_site( 'news.example' );

		$results = ( new Daymark_Subscription_OPML() )->import_entries( $stash['entries'] );

		$this->assertSame( array( 'subscribed', 'subscribed', 'subscribed' ), wp_list_pluck( $results, 'status' ) );
	}

	public function test_import_entries_always_imports_one_entry_past_its_deadline() {
		$stash = $this->stash();
		$this->serve_site( 'ada.example' );

		$results = ( new Daymark_Subscription_OPML() )->import_entries( $stash['entries'], microtime( true ) - 5 );

		$this->assertSame( array( 'subscribed' ), wp_list_pluck( $results, 'status' ) );
	}
}
