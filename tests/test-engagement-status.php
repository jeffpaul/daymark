<?php
/**
 * Like and Comment explanations in the full post view (issue #561).
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Engagement_Status and GET /subscription-posts/{id}/engagement.
 */
class Test_Engagement_Status extends WP_UnitTestCase {

	/**
	 * Permalink of the subscription post under test.
	 *
	 * @var string
	 */
	private $permalink = 'https://example.com/hello/';

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		// Nothing in these tests may reach the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		remove_all_filters( 'daymark_rate_limits' );

		parent::tear_down();
	}

	/**
	 * Refuse every outbound request.
	 *
	 * @return WP_Error
	 */
	public function block_http(): WP_Error {
		return new WP_Error( 'http_blocked', 'No network in tests.' );
	}

	/**
	 * Everything an administrator may open.
	 *
	 * @return array<string, bool>
	 */
	private static function admin_can(): array {
		return array(
			'connectors'     => true,
			'plugins'        => true,
			'update_plugins' => true,
			'settings'       => true,
			'users'          => true,
			'jetpack'        => true,
		);
	}

	/**
	 * Facts for a post whose Like can't be delivered and whose Comment
	 * opens the post on its own site.
	 *
	 * @param array<string, mixed> $facts Facts to change.
	 * @return array<string, mixed>
	 */
	private static function facts( array $facts = array() ): array {
		return array_merge(
			array(
				'like_available' => false,
				'comment_method' => 'redirect',
				'comment_url'    => 'https://example.com/hello/#respond',
				'reached'        => true,
				'activitypub'    => 'active',
				'can'            => self::admin_can(),
			),
			$facts
		);
	}

	/**
	 * Why a Like can't be delivered, for each kind of post and site.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}>
	 */
	public static function like_reasons(): array {
		return array(
			'site takes no Likes'               => array( array(), 'origin', false ),
			'page could not be loaded'          => array( array( 'reached' => false ), 'unreachable', false ),
			'cannot send Webmentions'           => array( array( 'webmention_endpoint' => true ), 'webmention', true ),
			'Bluesky, not bridged'              => array( array( 'bluesky' => true ), 'bridgy_fed', true ),
			'Bluesky, bridged, cannot send'     => array( array( 'bluesky' => true, 'bridged' => true ), 'bridgy_fed_webmention', true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'fediverse, no ActivityPub plugin'  => array( array( 'fediverse' => true, 'activitypub' => 'missing' ), 'activitypub_missing', true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'fediverse, old ActivityPub plugin' => array( array( 'fediverse' => true, 'activitypub' => 'outdated' ), 'activitypub_outdated', true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'fediverse, unusable plugin'        => array( array( 'fediverse' => true, 'activitypub' => 'incompatible' ), 'activitypub_incompatible', false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'fediverse, blog profile only'      => array( array( 'fediverse' => true, 'activitypub_profiles_off' => true ), 'activitypub_profiles', true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'fediverse, account not turned on'  => array( array( 'fediverse' => true ), 'activitypub_user', true ),
			'fediverse, lookup failed'          => array( array( 'fediverse' => true, 'activitypub_user' => true ), 'activitypub_unreachable', false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'fediverse, bridged, cannot send'   => array( array( 'fediverse' => true, 'bridged' => true ), 'bridgy_fed_webmention', true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'WordPress.com, account not linked' => array( array( 'jetpack_origin' => true ), 'jetpack_account', true ),
		);
	}

	/**
	 * @dataProvider like_reasons
	 *
	 * @param array<string, mixed> $facts    Facts to change.
	 * @param string               $reason   Expected reason.
	 * @param bool                 $has_link Whether an administrator gets a link.
	 */
	public function test_explains_why_a_like_cannot_be_delivered( array $facts, string $reason, bool $has_link ) {
		$note = Daymark_Engagement_Status::explain( self::facts( $facts ) );

		$this->assertSame( $reason, $note['reason'] );
		$this->assertSame( $has_link, null !== $note['link'] );

		// Someone who can't make the fix gets the same words, no link.
		$facts['can'] = array();
		$plain        = Daymark_Engagement_Status::explain( self::facts( $facts ) );
		$this->assertSame( $note['sentences'], $plain['sentences'] );
		$this->assertNull( $plain['link'] );
	}

	public function test_says_comment_opens_the_post_when_like_is_unavailable() {
		$note = Daymark_Engagement_Status::explain( self::facts() );

		$this->assertCount( 2, $note['sentences'] );
		$this->assertStringContainsString( 'To comment', $note['sentences'][1] );

		// Linking a WordPress.com account fixes both, so one sentence.
		$note = Daymark_Engagement_Status::explain( self::facts( array( 'jetpack_origin' => true ) ) );
		$this->assertCount( 1, $note['sentences'] );
	}

	public function test_bridged_sites_are_not_told_to_install_activitypub() {
		$note = Daymark_Engagement_Status::explain(
			self::facts(
				array(
					'fediverse'   => true,
					'bridged'     => true,
					'can_send'    => true,
					'activitypub' => 'missing',
				)
			)
		);

		$this->assertSame( 'origin', $note['reason'] );
	}

	public function test_links_point_at_the_connector_card() {
		$note = Daymark_Engagement_Status::explain( self::facts( array( 'bluesky' => true ) ) );

		$this->assertStringContainsString( 'tab=connectors', $note['link']['url'] );
		$this->assertStringEndsWith( '#daymark-connector-bridgy_fed', $note['link']['url'] );
	}

	public function test_no_note_when_like_and_comment_both_reach_the_site() {
		$this->assertNull(
			Daymark_Engagement_Status::explain(
				self::facts(
					array(
						'like_available' => true,
						'comment_method' => 'webmention',
						'comment_url'    => '',
					)
				)
			)
		);
	}

	public function test_already_liked_post_only_explains_comment() {
		$note = Daymark_Engagement_Status::explain( self::facts( array( 'liked' => true ) ) );

		$this->assertSame( 'comment_only', $note['reason'] );
		$this->assertCount( 1, $note['sentences'] );

		$note = Daymark_Engagement_Status::explain(
			self::facts(
				array(
					'like_available' => true,
					'jetpack_origin' => true,
				)
			)
		);
		$this->assertSame( 'jetpack_account', $note['reason'] );
		$this->assertStringNotContainsString( 'like and', $note['sentences'][0] );
	}

	public function test_route_explains_a_site_that_takes_no_likes() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->seed_signals();

		$data = $this->get( $this->create_subscription_post() )->get_data();

		$this->assertFalse( $data['like']['available'] );
		$this->assertSame( 'redirect', $data['comment']['method'] );
		$this->assertSame( $this->permalink . '#respond', $data['comment']['url'] );
		$this->assertSame( 'origin', $data['note']['reason'] );
		$this->assertNull( $data['note']['link'] );
	}

	public function test_route_explains_a_page_it_could_not_load() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->seed_signals( array( 'reached' => false ) );

		$data = $this->get( $this->create_subscription_post() )->get_data();

		$this->assertSame( 'unreachable', $data['note']['reason'] );
	}

	public function test_route_links_an_administrator_to_bridgy_fed_for_a_bluesky_post() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->seed_signals( array( 'bluesky' => true ) );

		$data = $this->get( $this->create_subscription_post() )->get_data();

		$this->assertSame( 'bridgy_fed', $data['note']['reason'] );
		$this->assertStringEndsWith( '#daymark-connector-bridgy_fed', $data['note']['link']['url'] );
	}

	public function test_route_spends_the_background_allowance_only_on_a_lookup() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter(
			'daymark_rate_limits',
			static function ( $limits ) {
				$limits[ Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH ]['limit'] = 1;
				return $limits;
			}
		);
		$post_id = $this->create_subscription_post();

		// Cached: free, however often.
		$this->seed_signals();
		$this->assertSame( 200, $this->get( $post_id )->get_status() );
		$this->assertSame( 200, $this->get( $post_id )->get_status() );

		// Not cached: the first lookup spends the one allowed, the next is refused.
		delete_transient( 'daymark_comment_sig_' . md5( $this->permalink ) );
		$this->assertSame( 200, $this->get( $post_id )->get_status() );
		delete_transient( 'daymark_comment_sig_' . md5( $this->permalink ) );
		$this->assertSame( 429, $this->get( $post_id )->get_status() );
	}

	public function test_route_spends_nothing_on_an_address_it_will_not_look_up() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter(
			'daymark_rate_limits',
			static function ( $limits ) {
				$limits[ Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH ]['limit'] = 1;
				return $limits;
			}
		);

		// The URL guard refuses this port, so nothing is fetched or cached.
		$this->permalink = 'https://example.com:8443/x';
		$post_id         = $this->create_subscription_post();
		$limiter         = Daymark_Plugin::instance()->rate_limiter;

		$this->assertFalse( Daymark_Engagement_Status::needs_lookup( $post_id ) );

		foreach ( array( 1, 2 ) as $attempt ) {
			$response = $this->get( $post_id );

			$this->assertSame( 200, $response->get_status(), 'Request ' . $attempt );
			$this->assertSame( 'unreachable', $response->get_data()['note']['reason'] );
		}

		$this->assertSame( 1, $limiter->remaining( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH ) );
	}

	public function test_route_refuses_a_post_that_is_not_a_followed_post() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( 404, $this->get( (int) self::factory()->post->create() )->get_status() );
	}

	/**
	 * Cache the post's origin signals, as a looked-up page would leave them.
	 *
	 * @param array<string, mixed> $signals Signals to change.
	 * @return void
	 */
	private function seed_signals( array $signals = array() ): void {
		set_transient(
			'daymark_comment_sig_' . md5( $this->permalink ),
			array_merge(
				array(
					'webmention_endpoint' => '',
					'rest_root'           => '',
					'post_id'             => 0,
					'jetpack_site_id'     => 0,
					'jetpack_post_id'     => 0,
					'activitypub_object'  => false,
					'bluesky'             => false,
					'reached'             => true,
				),
				$signals
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * GET the engagement route for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Response
	 */
	private function get( int $post_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/daymark/v1/subscription-posts/' . $post_id . '/engagement' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Create a followed post at $this->permalink.
	 *
	 * @return int
	 */
	private function create_subscription_post(): int {
		$subscription_id = ( new Daymark_Subscriptions() )->create(
			array(
				'site_url' => 'https://example.com/',
				'feed_url' => 'https://example.com/feed/' . wp_generate_password( 8, false ),
			)
		);

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Hello',
			)
		);
		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, 'permalink', $this->permalink );

		return $post_id;
	}
}
