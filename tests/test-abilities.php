<?php
/**
 * Tests for the abilities Daymark registers with the WordPress Abilities
 * API, so an AI agent the site owner connects can post a Mark, read the
 * Timeline, see who responded, and follow a site.
 *
 * Each ability runs through WP_Ability::execute(), which also checks the
 * input and the result against the ability's own schemas, so a result
 * here is one a real agent would be given.
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Abilities through the Abilities API itself.
 */
class Test_Abilities extends WP_UnitTestCase {

	/** @var int */
	private $author;

	/** @var int */
	private $admin;

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/**
	 * URL => canned response, read by intercept_http_request().
	 *
	 * @var array<string, mixed>
	 */
	private array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();
		Daymark_Subscription_Html_Cache::reset();

		$this->author         = (int) self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Ada Author',
			)
		);
		$this->admin          = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriptions  = new Daymark_Subscriptions();
		$this->http_responses = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * Answers a mapped URL with its canned response and blocks the rest,
	 * so no test reaches the real network.
	 *
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL.
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		if ( array_key_exists( $url, $this->http_responses ) ) {
			return $this->http_responses[ $url ];
		}

		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * Registers a canned 200 response for a URL.
	 *
	 * @param string $url          URL to mock.
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header value.
	 * @return void
	 */
	private function mock_response( string $url, string $body, string $content_type = 'text/html; charset=UTF-8' ): void {
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
	 * Runs an ability the way an agent would.
	 *
	 * @param string     $name  Ability name.
	 * @param array|null $input Input, or null for none.
	 * @return mixed|WP_Error
	 */
	private function run_ability( string $name, ?array $input = null ) {
		$ability = wp_get_ability( $name );

		$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' is registered' );

		return $ability->execute( $input );
	}

	/**
	 * Creates a published Mark.
	 *
	 * @param string $date_gmt MySQL datetime (GMT).
	 * @param string $title    Post title.
	 * @param string $content  Post content.
	 * @param string $status   Post status.
	 * @return int
	 */
	private function create_mark( string $date_gmt, string $title = 'A Mark', string $content = 'Body copy.', string $status = 'publish' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->author,
				'post_status'   => $status,
				'post_title'    => $title,
				'post_content'  => $content,
				'post_date_gmt' => $date_gmt,
				'post_date'     => get_date_from_gmt( $date_gmt ),
			)
		);
		update_post_meta( $post_id, '_daymark_is_mark', '1' );
		update_post_meta( $post_id, '_daymark_primary_type', 'note' );

		return $post_id;
	}

	/**
	 * Creates a followed site with one cached post.
	 *
	 * @param string $published_at MySQL datetime (GMT).
	 * @param string $title        Post title.
	 * @param string $body         Full cached body, or '' for none.
	 * @return int The cached post's ID.
	 */
	private function create_followed_post( string $published_at, string $title = 'A Friend Post', string $body = '' ): int {
		$subscription_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://friend.example/',
				'feed_url'   => 'https://friend.example/feed/' . wp_generate_password( 6, false ) . '/',
				'site_title' => 'Friend Site',
			)
		);

		$this->assertIsInt( $subscription_id, 'Followed site created' );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => 'Excerpt for ' . $title,
			)
		);

		update_post_meta( $post_id, 'subscription_id', $subscription_id );
		update_post_meta( $post_id, 'published_at', $published_at );
		update_post_meta( $post_id, 'permalink', 'https://friend.example/' . sanitize_title( $title ) . '/' );
		update_post_meta( $post_id, 'author', 'Fran Friend' );
		update_post_meta( $post_id, 'post_format', 'standard' );
		update_post_meta( $post_id, 'content_state', '' !== $body ? 'full' : 'excerpt_only' );

		if ( '' !== $body ) {
			update_post_meta( $post_id, 'body_content', $body );
		}

		return $post_id;
	}

	/**
	 * Every ability Daymark offers, by name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function ability_names(): array {
		return array(
			'create-mark'       => array( 'daymark/create-mark' ),
			'get-timeline'      => array( 'daymark/get-timeline' ),
			'get-post'          => array( 'daymark/get-post' ),
			'get-notifications' => array( 'daymark/get-notifications' ),
			'follow-site'       => array( 'daymark/follow-site' ),
		);
	}

	/**
	 * The four reading and following abilities with an input each accepts.
	 *
	 * @return array<string, array{0: string, 1: array|null}>
	 */
	public function abilities_with_input(): array {
		return array(
			'create-mark'       => array( 'daymark/create-mark', array( 'text' => 'Hello.' ) ),
			'get-timeline'      => array( 'daymark/get-timeline', null ),
			'get-post'          => array( 'daymark/get-post', array( 'id' => 1 ) ),
			'get-notifications' => array( 'daymark/get-notifications', null ),
			'follow-site'       => array( 'daymark/follow-site', array( 'site_url' => 'https://example.com/' ) ),
		);
	}

	/**
	 * Each ability is registered under Daymark's own category.
	 *
	 * @dataProvider ability_names
	 *
	 * @param string $name Ability name.
	 */
	public function test_ability_is_registered_in_the_daymark_category( string $name ) {
		$ability = wp_get_ability( $name );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'daymark', $ability->get_category() );
		$this->assertNotNull( wp_get_ability_category( 'daymark' ) );
	}

	/**
	 * Each ability is offered to agents: over the Abilities REST routes, and
	 * to the MCP Adapter, which reads `public` (and `mcp.public` in its
	 * earlier releases).
	 *
	 * @dataProvider ability_names
	 *
	 * @param string $name Ability name.
	 */
	public function test_ability_is_offered_to_agents( string $name ) {
		$meta = wp_get_ability( $name )->get_meta();

		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['public'] );
		$this->assertTrue( $meta['mcp']['public'] );
	}

	/** The reading abilities say they change nothing; the others say they do. */
	public function test_abilities_say_whether_they_change_anything() {
		foreach ( array( 'daymark/get-timeline', 'daymark/get-post', 'daymark/get-notifications' ) as $name ) {
			$annotations = wp_get_ability( $name )->get_meta()['annotations'];

			$this->assertTrue( $annotations['readonly'], $name );
			$this->assertFalse( $annotations['destructive'], $name );
		}

		foreach ( array( 'daymark/create-mark', 'daymark/follow-site' ) as $name ) {
			$annotations = wp_get_ability( $name )->get_meta()['annotations'];

			$this->assertFalse( $annotations['readonly'], $name );
			$this->assertFalse( $annotations['destructive'], $name );
		}
	}

	/**
	 * Nobody signed in can run anything.
	 *
	 * @dataProvider abilities_with_input
	 *
	 * @param string     $name  Ability name.
	 * @param array|null $input Input.
	 */
	public function test_signed_out_visitor_cannot_run_an_ability( string $name, ?array $input ) {
		wp_set_current_user( 0 );

		$result = $this->run_ability( $name, $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A subscriber, who cannot use the Daymark app, cannot use its abilities.
	 *
	 * @dataProvider abilities_with_input
	 *
	 * @param string     $name  Ability name.
	 * @param array|null $input Input.
	 */
	public function test_subscriber_cannot_run_an_ability( string $name, ?array $input ) {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->run_ability( $name, $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/** Following is site-wide, so it needs the capability Settings -> Daymark needs. */
	public function test_follow_site_is_refused_for_an_author() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability( 'daymark/follow-site', array( 'site_url' => 'https://example.com/' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( array(), $this->subscriptions->get_all() );
	}

	/** With no status given, a Mark is saved as a draft and nothing is published. */
	public function test_create_mark_saves_a_draft_by_default() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability( 'daymark/create-mark', array( 'text' => 'Walked the long way home.' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );

		$post = get_post( $result['id'] );

		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( $this->author, (int) $post->post_author );
		$this->assertStringContainsString( 'Walked the long way home.', $post->post_content );
		$this->assertSame( '1', get_post_meta( $post->ID, '_daymark_is_mark', true ) );
		$this->assertSame( 'note', get_post_meta( $post->ID, '_daymark_primary_type', true ) );
		$this->assertStringContainsString( 'post=' . $post->ID, $result['edit_url'] );
	}

	/** Asking to publish publishes. */
	public function test_create_mark_publishes_when_asked() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'   => 'Coffee with Sam.',
				'status' => 'publish',
			)
		);

		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', get_post_status( $result['id'] ) );
		$this->assertSame( get_permalink( $result['id'] ), $result['url'] );
	}

	/** A time to publish at schedules the Mark, without also asking for "publish". */
	public function test_create_mark_with_a_publish_time_is_scheduled() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'       => 'Tomorrow, the lake.',
				'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( 'future', get_post_status( $result['id'] ) );
	}

	/** An explicit draft stays a draft even with a time. */
	public function test_create_mark_draft_with_a_publish_time_stays_a_draft() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'       => 'Not sure yet.',
				'status'     => 'draft',
				'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 'draft', $result['status'] );
	}

	/** A title and tags are saved with the Mark. */
	public function test_create_mark_saves_title_and_tags() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'  => 'Planted the garlic.',
				'title' => 'Garlic day',
				'tags'  => array( 'garden', 'autumn' ),
			)
		);

		$this->assertSame( 'Garlic day', $result['title'] );
		$this->assertSame( 'Garlic day', get_post( $result['id'] )->post_title );
		$this->assertEqualSets( array( 'garden', 'autumn' ), wp_get_post_tags( $result['id'], array( 'fields' => 'names' ) ) );
	}

	/** A contributor can draft, and asking to publish still leaves a draft. */
	public function test_create_mark_cannot_publish_for_a_contributor() {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'   => 'Please publish me.',
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotSame( 'publish', get_post_status( $result['id'] ) );
		$this->assertSame( get_post_status( $result['id'] ), $result['status'] );
	}

	/** Text is required. */
	public function test_create_mark_needs_text() {
		wp_set_current_user( $this->author );

		$missing = $this->run_ability( 'daymark/create-mark', array( 'title' => 'No text' ) );
		$blank   = $this->run_ability( 'daymark/create-mark', array( 'text' => '   ' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 'ability_invalid_input', $missing->get_error_code() );
		$this->assertWPError( $blank );
		$this->assertSame( 'daymark_empty', $blank->get_error_code() );
	}

	/** An input the ability doesn't know (a reply or like target, say) is refused. */
	public function test_create_mark_refuses_unknown_input() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'        => 'Nice post!',
				'in_reply_to' => 'https://friend.example/a-post/',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/** The Timeline lists the site's own posts and followed sites' posts, newest first. */
	public function test_get_timeline_lists_own_and_followed_posts_newest_first() {
		wp_set_current_user( $this->author );

		$mark_id     = $this->create_mark( '2024-01-05 00:00:00', 'My Mark', 'Body of my Mark.' );
		$followed_id = $this->create_followed_post( '2024-01-06 00:00:00', 'Friend News' );

		$result = $this->run_ability( 'daymark/get-timeline' );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( array( $followed_id, $mark_id ), array_column( $result['items'], 'id' ) );

		$followed = $result['items'][0];
		$mine     = $result['items'][1];

		$this->assertSame(
			array(
				'id'       => $followed_id,
				'from'     => 'following',
				'type'     => 'standard',
				'title'    => 'Friend News',
				'excerpt'  => 'Excerpt for Friend News',
				'url'      => 'https://friend.example/friend-news/',
				'date'     => '2024-01-06T00:00:00+00:00',
				'author'   => 'Fran Friend',
				'site'     => 'Friend Site',
				'site_url' => 'https://friend.example/',
			),
			$followed
		);

		$this->assertSame( 'me', $mine['from'] );
		$this->assertSame( 'note', $mine['type'] );
		$this->assertSame( 'My Mark', $mine['title'] );
		$this->assertSame( get_permalink( $mark_id ), $mine['url'] );
		$this->assertSame( '2024-01-05T00:00:00+00:00', $mine['date'] );
		$this->assertSame( 'Ada Author', $mine['author'] );
		$this->assertSame( get_bloginfo( 'name' ), $mine['site'] );
		$this->assertSame( home_url( '/' ), $mine['site_url'] );
	}

	/** "from" narrows the list to the site's own posts or to followed sites' posts. */
	public function test_get_timeline_can_list_only_mine_or_only_followed() {
		wp_set_current_user( $this->author );

		$mark_id     = $this->create_mark( '2024-01-05 00:00:00' );
		$followed_id = $this->create_followed_post( '2024-01-06 00:00:00' );

		$mine     = $this->run_ability( 'daymark/get-timeline', array( 'from' => 'me' ) );
		$followed = $this->run_ability( 'daymark/get-timeline', array( 'from' => 'following' ) );

		$this->assertSame( array( $mark_id ), array_column( $mine['items'], 'id' ) );
		$this->assertSame( 1, $mine['total'] );
		$this->assertSame( array( $followed_id ), array_column( $followed['items'], 'id' ) );
		$this->assertSame( 1, $followed['total'] );
	}

	/** Search words and a date window narrow the list. */
	public function test_get_timeline_searches_and_filters_by_date() {
		wp_set_current_user( $this->author );

		$trip = $this->create_mark( '2023-07-01 00:00:00', 'Lisbon trip', 'Trams and tiles.' );
		$this->create_mark( '2024-02-01 00:00:00', 'Garden notes', 'Tomatoes.' );
		$this->create_followed_post( '2024-02-02 00:00:00', 'Friend in Lisbon' );

		$searched = $this->run_ability(
			'daymark/get-timeline',
			array(
				'search' => 'Lisbon',
				'from'   => 'me',
			)
		);
		$windowed = $this->run_ability(
			'daymark/get-timeline',
			array(
				'after'  => '2023-01-01T00:00:00Z',
				'before' => '2023-12-31T23:59:59Z',
			)
		);

		$this->assertSame( array( $trip ), array_column( $searched['items'], 'id' ) );
		$this->assertSame( array( $trip ), array_column( $windowed['items'], 'id' ) );
	}

	/** The list comes a page at a time. */
	public function test_get_timeline_pages() {
		wp_set_current_user( $this->author );

		$oldest = $this->create_mark( '2024-01-01 00:00:00', 'One' );
		$this->create_mark( '2024-01-02 00:00:00', 'Two' );
		$this->create_mark( '2024-01-03 00:00:00', 'Three' );

		$result = $this->run_ability(
			'daymark/get-timeline',
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$this->assertSame( array( $oldest ), array_column( $result['items'], 'id' ) );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 2, $result['page'] );
	}

	/** A draft is not on the Timeline. */
	public function test_get_timeline_leaves_out_drafts() {
		wp_set_current_user( $this->author );

		$this->create_mark( '2024-01-01 00:00:00', 'Unfinished', 'Still writing.', 'draft' );

		$result = $this->run_ability( 'daymark/get-timeline' );

		$this->assertSame( array(), $result['items'] );
	}

	/** A Mark's full text comes back as plain text, a paragraph to a line. */
	public function test_get_post_returns_a_marks_text() {
		wp_set_current_user( $this->author );

		$mark_id = $this->create_mark(
			'2024-01-05 00:00:00',
			'My Mark',
			"<!-- wp:paragraph -->\n<p>First <strong>bold</strong> line.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second &amp; last.</p>\n<!-- /wp:paragraph -->"
		);

		$result = $this->run_ability( 'daymark/get-post', array( 'id' => $mark_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $mark_id, $result['id'] );
		$this->assertSame( 'me', $result['from'] );
		$this->assertSame( 'My Mark', $result['title'] );
		$this->assertSame( "First bold line.\n\nSecond & last.", $result['content'] );
	}

	/** A followed site's cached post comes back the same way, with its site. */
	public function test_get_post_returns_a_followed_posts_text() {
		wp_set_current_user( $this->author );

		$followed_id = $this->create_followed_post(
			'2024-01-06 00:00:00',
			'Friend News',
			'<p>We moved house.</p><script>alert(1)</script><p>New address soon.</p>'
		);

		$result = $this->run_ability( 'daymark/get-post', array( 'id' => $followed_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'following', $result['from'] );
		$this->assertSame( 'Friend Site', $result['site'] );
		$this->assertSame( 'Fran Friend', $result['author'] );
		$this->assertStringContainsString( 'We moved house.', $result['content'] );
		$this->assertStringContainsString( 'New address soon.', $result['content'] );
		$this->assertStringNotContainsString( '<', $result['content'] );
	}

	/** A draft, a page, and a missing post are all "not found". */
	public function test_get_post_does_not_serve_what_the_timeline_would_not() {
		wp_set_current_user( $this->author );

		$draft = $this->create_mark( '2024-01-01 00:00:00', 'Unfinished', 'Still writing.', 'draft' );
		$page  = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

		foreach ( array( $draft, $page, 999999 ) as $id ) {
			$result = $this->run_ability( 'daymark/get-post', array( 'id' => $id ) );

			$this->assertWPError( $result );
			$this->assertSame( 'daymark_not_found', $result->get_error_code() );
		}
	}

	/** Someone else's password-protected post stays closed. */
	public function test_get_post_keeps_a_password_protected_post_closed() {
		$locked = $this->create_mark( '2024-01-05 00:00:00', 'Private', 'Secret words.' );
		wp_update_post(
			array(
				'ID'            => $locked,
				'post_password' => 'hunter2',
			)
		);

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );

		$result = $this->run_ability( 'daymark/get-post', array( 'id' => $locked ) );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_password_protected', $result->get_error_code() );
	}

	/** A reply to a Mark is listed with who wrote it, what they said, and which Mark. */
	public function test_get_notifications_lists_a_reply_to_a_mark() {
		wp_set_current_user( $this->author );

		$mark_id    = $this->create_mark( '2024-01-05 00:00:00', 'My Mark' );
		$comment_id = (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $mark_id,
				'comment_author'   => 'Rae Reader',
				'comment_content'  => '<p>Lovely <em>photo</em>!</p>',
				'comment_date_gmt' => '2024-01-06 10:00:00',
				'comment_date'     => get_date_from_gmt( '2024-01-06 10:00:00' ),
			)
		);

		$result = $this->run_ability( 'daymark/get-notifications' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame(
			array(
				'kind'       => 'reply',
				'from'       => 'Rae Reader',
				'text'       => 'Lovely photo!',
				'date'       => '2024-01-06T10:00:00+00:00',
				'is_new'     => true,
				'source'     => 'site',
				'url'        => get_comment_link( $comment_id ),
				'post_id'    => $mark_id,
				'post_title' => 'My Mark',
				'post_url'   => get_permalink( $mark_id ),
			),
			$result['items'][0]
		);
	}

	/** A like is listed as a like. */
	public function test_get_notifications_lists_a_like_as_a_like() {
		wp_set_current_user( $this->author );

		$mark_id = $this->create_mark( '2024-01-05 00:00:00' );
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $mark_id,
				'comment_type'    => 'like',
				'comment_author'  => 'Lee Liker',
			)
		);

		$result = $this->run_ability( 'daymark/get-notifications' );

		$this->assertSame( array( 'like' ), array_column( $result['items'], 'kind' ) );
		$this->assertSame( 'Lee Liker', $result['items'][0]['from'] );
	}

	/** An agent reading notifications must not clear the app's unread mark. */
	public function test_get_notifications_does_not_mark_anything_seen() {
		wp_set_current_user( $this->author );

		$mark_id = $this->create_mark( '2024-01-05 00:00:00' );
		self::factory()->comment->create( array( 'comment_post_ID' => $mark_id ) );

		$notifications = Daymark_Plugin::instance()->notifications;
		$seen_before   = $notifications->get_seen();

		$this->assertTrue( $notifications->has_unread(), 'Unread before the agent looks' );

		$first  = $this->run_ability( 'daymark/get-notifications' );
		$second = $this->run_ability( 'daymark/get-notifications' );

		$this->assertTrue( $notifications->has_unread(), 'Still unread after the agent looked' );
		$this->assertSame( $seen_before, $notifications->get_seen() );
		$this->assertTrue( $first['items'][0]['is_new'] );
		$this->assertTrue( $second['items'][0]['is_new'], 'Still new on a second look' );
	}

	/** Feed problems are the app's business, not a response from a person. */
	public function test_get_notifications_leaves_out_feed_problems() {
		wp_set_current_user( $this->admin );

		$subscription_id = $this->subscriptions->create(
			array(
				'site_url' => 'https://broken.example/',
				'feed_url' => 'https://broken.example/feed/',
			)
		);
		$this->subscriptions->increment_failure_count( (int) $subscription_id );

		$this->assertNotEmpty( Daymark_Plugin::instance()->notifications->get_notifications(), 'The app does list the feed problem' );

		$result = $this->run_ability( 'daymark/get-notifications' );

		$this->assertSame( array(), $result['items'] );
	}

	/** "limit" caps how many come back. */
	public function test_get_notifications_respects_the_limit() {
		wp_set_current_user( $this->author );

		$mark_id = $this->create_mark( '2024-01-05 00:00:00' );
		self::factory()->comment->create_many( 3, array( 'comment_post_ID' => $mark_id ) );

		$result = $this->run_ability( 'daymark/get-notifications', array( 'limit' => 2 ) );

		$this->assertCount( 2, $result['items'] );
	}

	/** Following a site by its address finds its feed and follows it. */
	public function test_follow_site_follows_a_sites_feed() {
		wp_set_current_user( $this->admin );

		$this->mock_response(
			'https://example.com/',
			'<html><head><title>Example</title><link rel="alternate" type="application/rss+xml" href="/feed/" /></head><body></body></html>'
		);

		$result = $this->run_ability( 'daymark/follow-site', array( 'site_url' => 'https://example.com/' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/', $result['site_url'] );
		$this->assertSame( 'https://example.com/feed/', $result['feed_url'] );
		$this->assertSame( 'active', $result['status'] );

		$row = $this->subscriptions->get( $result['id'] );

		$this->assertNotNull( $row );
		$this->assertSame( 'https://example.com/feed/', $row['feed_url'] );
	}

	/** A site with no feed says so, and nothing is followed. */
	public function test_follow_site_reports_a_site_with_no_feed() {
		wp_set_current_user( $this->admin );

		$this->mock_response( 'https://example.com/', '<html><head><title>Example</title></head><body></body></html>' );

		$result = $this->run_ability( 'daymark/follow-site', array( 'site_url' => 'https://example.com/' ) );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->subscriptions->get_all() );
	}

	/**
	 * A time that can't be used is an error and saves nothing. The publisher
	 * itself reads such a time as "publish now", which an agent asked to
	 * schedule something must never do.
	 */
	public function test_create_mark_refuses_a_publish_time_that_has_passed() {
		wp_set_current_user( $this->author );

		$result = $this->run_ability(
			'daymark/create-mark',
			array(
				'text'       => 'Too late.',
				'publish_at' => '2020-01-01T09:00:00Z',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_invalid_publish_at', $result->get_error_code() );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_status' => 'any',
					'author'      => $this->author,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * The Abilities API's own REST route runs a reading ability for a
	 * signed-in user with no Daymark nonce, which Daymark's own routes
	 * would refuse: this is how an agent with an application password
	 * gets in.
	 */
	public function test_reading_ability_runs_over_the_abilities_rest_route_without_a_nonce() {
		wp_set_current_user( $this->author );

		$mark_id = $this->create_mark( '2024-01-05 00:00:00', 'My Mark' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/daymark/get-timeline/run' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $mark_id ), array_column( $response->get_data()['items'], 'id' ) );
	}

	/** Creating a Mark over that route takes a POST, and still saves a draft. */
	public function test_create_mark_runs_over_the_abilities_rest_route() {
		wp_set_current_user( $this->author );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/daymark/create-mark/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'input' => array( 'text' => 'Sent by an agent.' ) ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertSame( 'draft', get_post_status( $response->get_data()['id'] ) );
	}

	/** An ability is charged against the same allowance as the app. */
	public function test_create_mark_is_rate_limited_like_the_app() {
		wp_set_current_user( $this->author );

		$limit_to_one = static function ( array $limits ): array {
			$limits[ Daymark_Rate_Limiter::ACTION_PUBLISH ] = array(
				'limit'  => 1,
				'window' => MINUTE_IN_SECONDS,
			);

			return $limits;
		};
		add_filter( 'daymark_rate_limits', $limit_to_one );

		$first  = $this->run_ability( 'daymark/create-mark', array( 'text' => 'One.' ) );
		$second = $this->run_ability( 'daymark/create-mark', array( 'text' => 'Two.' ) );

		remove_filter( 'daymark_rate_limits', $limit_to_one );

		$this->assertIsArray( $first );
		$this->assertWPError( $second );
		$this->assertSame( 429, $second->get_error_data()['status'] );
	}
}
