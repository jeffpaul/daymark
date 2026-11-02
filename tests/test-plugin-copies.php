<?php
/**
 * Tests for Daymark_Plugin_Copies and the syndication links built from it
 * (issue #558): where publishing plugins shared a Mark.
 *
 * @package Daymark
 */

/**
 * Reads each publishing plugin's own saved data.
 */
class Test_Plugin_Copies extends WP_UnitTestCase {

	/**
	 * A published Mark.
	 *
	 * @var int
	 */
	private $mark_id;

	public function set_up(): void {
		parent::set_up();

		$this->mark_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Copies test',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		update_post_meta( $this->mark_id, '_daymark_is_mark', '1' );

		// The Mark was saved in an earlier request, not the one each test runs.
		Daymark_Syndication_Links::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'daymark_plugin_copies' );
		remove_all_filters( 'daymark_syndication_links_plugin_active' );
		delete_option( 'wpt_mastodon_instance' );
		delete_option( 'wpt_mastodon_username' );
		parent::tear_down();
	}

	/** A post no plugin shared has no copies. */
	public function test_no_copies_without_plugin_data() {
		$this->assertSame( array(), Daymark_Plugin_Copies::for_post( $this->mark_id ) );
		$this->assertSame( array(), Daymark_Plugin_Copies::for_post( 0 ) );
	}

	/** Jetpack Social: only successful shares count; the message is the address. */
	public function test_jetpack_reads_successful_shares() {
		add_post_meta(
			$this->mark_id,
			'_publicize_shares',
			array(
				array(
					'status'  => 'success',
					'message' => 'https://mastodon.social/@demo/1',
					'service' => 'mastodon',
				),
				array(
					'status'  => 'failure',
					'message' => 'Token expired',
					'service' => 'linkedin',
				),
				array(
					'status'  => 'success',
					'message' => 'https://www.threads.net/@demo/post/abc',
					'service' => 'threads',
				),
			)
		);

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertCount( 2, $copies );
		$this->assertSame( 'mastodon', $copies[0]['service'] );
		$this->assertSame( 'Mastodon', $copies[0]['label'] );
		$this->assertSame( 'https://mastodon.social/@demo/1', $copies[0]['url'] );
		$this->assertSame( 'jetpack', $copies[0]['plugin'] );
		$this->assertSame( 'Jetpack Social', $copies[0]['plugin_label'] );
		$this->assertSame( 'Threads', $copies[1]['label'] );
	}

	/** Jetpack's older format keeps one share per meta row. */
	public function test_jetpack_reads_one_share_per_row() {
		add_post_meta(
			$this->mark_id,
			'_publicize_shares',
			array(
				'status'  => 'success',
				'message' => 'https://bsky.app/profile/demo.bsky.social/post/3abc',
				'service' => 'bluesky',
			)
		);
		add_post_meta(
			$this->mark_id,
			'_publicize_shares',
			array(
				'status'  => 'success',
				'message' => 'https://www.tumblr.com/demo/123',
				'service' => 'tumblr',
			)
		);

		$labels = wp_list_pluck( Daymark_Plugin_Copies::for_post( $this->mark_id ), 'label' );

		$this->assertSame( array( 'Bluesky', 'Tumblr' ), $labels );
	}

	/** Share on Mastodon keeps the toot's address. */
	public function test_share_on_mastodon() {
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://indieweb.social/@demo/112233' );

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertCount( 1, $copies );
		$this->assertSame( 'https://indieweb.social/@demo/112233', $copies[0]['url'] );
		$this->assertSame( 'share-on-mastodon', $copies[0]['plugin'] );
		$this->assertSame( 'Share on Mastodon', $copies[0]['plugin_label'] );
	}

	/** XPoster: an X link from the tweet ID, a Bluesky copy without a link. */
	public function test_xposter_x_and_bluesky() {
		update_post_meta( $this->mark_id, '_wpt_tweet_id', '1790000000000000000' );
		update_post_meta( $this->mark_id, '_wpt_bluesky_id', 'bafyreihash' );

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertCount( 2, $copies );
		$this->assertSame( 'https://x.com/i/web/status/1790000000000000000', $copies[0]['url'] );
		$this->assertSame( 'XPoster', $copies[0]['plugin_label'] );
		$this->assertSame( 'bluesky', $copies[1]['service'] );
		$this->assertSame( '', $copies[1]['url'] );
	}

	/** XPoster: a Mastodon link needs the site-wide account and no per-author one. */
	public function test_xposter_mastodon_link_uses_site_account() {
		update_post_meta( $this->mark_id, '_wpt_status_id', '113000000000000001' );

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );
		$this->assertSame( '', $copies[0]['url'], 'No saved account, no address' );

		update_option( 'wpt_mastodon_instance', 'https://mastodon.example/' );
		update_option( 'wpt_mastodon_username', '@demo' );

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );
		$this->assertSame( 'https://mastodon.example/@demo/113000000000000001', $copies[0]['url'] );

		$author = (int) get_post_field( 'post_author', $this->mark_id );
		update_user_meta( $author, 'wpt_mastodon_instance', 'https://other.example' );

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );
		$this->assertSame( '', $copies[0]['url'], 'The author has their own account, so the ID could be from either' );
	}

	/** Autopost for X: one status, or a list once shared again. */
	public function test_autoshare_single_and_list() {
		update_post_meta(
			$this->mark_id,
			'autoshare_status',
			array(
				'status'     => 'published',
				'twitter_id' => '42',
				'handle'     => 'demo_user',
			)
		);

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );
		$this->assertSame( 'https://x.com/demo_user/status/42', $copies[0]['url'] );
		$this->assertSame( 'Autopost for X', $copies[0]['plugin_label'] );

		update_post_meta(
			$this->mark_id,
			'autoshare_status',
			array(
				array(
					'status'     => 'published',
					'twitter_id' => '43',
					'handle'     => 'not a handle!',
				),
				array(
					'status' => 'error',
				),
			)
		);

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );
		$this->assertCount( 1, $copies );
		$this->assertSame( 'https://x.com/i/web/status/43', $copies[0]['url'] );
	}

	/** ATmosphere: the at:// record becomes a bsky.app link. */
	public function test_atmosphere_builds_bsky_link() {
		update_post_meta( $this->mark_id, '_atmosphere_bsky_uri', 'at://did:plc:abc123/app.bsky.feed.post/3kxyz' );

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertSame( 'https://bsky.app/profile/did:plc:abc123/post/3kxyz', $copies[0]['url'] );
		$this->assertSame( 'ATmosphere', $copies[0]['plugin_label'] );
	}

	/** Autoblue: one copy per share. */
	public function test_autoblue_reads_shares() {
		update_post_meta(
			$this->mark_id,
			'autoblue_shares',
			array(
				array(
					'did' => 'did:plc:abc123',
					'uri' => 'at://did:plc:abc123/app.bsky.feed.post/3kaaa',
				),
				array(
					'did' => 'not a did',
					'uri' => 'at://x/app.bsky.feed.post/3kbbb',
				),
			)
		);

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertCount( 1, $copies );
		$this->assertSame( 'https://bsky.app/profile/did:plc:abc123/post/3kaaa', $copies[0]['url'] );
	}

	/** ActivityPub: listed once federated, with no separate address. */
	public function test_activitypub_listed_without_link() {
		update_post_meta( $this->mark_id, 'activitypub_status', 'pending' );
		$this->assertSame( array(), Daymark_Plugin_Copies::for_post( $this->mark_id ) );

		update_post_meta( $this->mark_id, 'activitypub_status', 'federated' );
		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertSame( 'fediverse', $copies[0]['service'] );
		$this->assertSame( '', $copies[0]['url'] );
		$this->assertSame( 'ActivityPub', $copies[0]['plugin_label'] );
	}

	/** Two plugins that recorded the same toot list it once. */
	public function test_same_copy_from_two_plugins_is_listed_once() {
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );
		add_post_meta(
			$this->mark_id,
			'_publicize_shares',
			array(
				array(
					'status'  => 'success',
					'message' => 'https://mastodon.social/@demo/1/',
					'service' => 'mastodon',
				),
			)
		);

		$this->assertCount( 1, Daymark_Plugin_Copies::for_post( $this->mark_id ) );
	}

	/** The filter can add a copy; a bad address or a missing label is dropped. */
	public function test_filter_adds_and_drops_entries() {
		add_filter(
			'daymark_plugin_copies',
			static function ( $copies ) {
				$copies[] = array(
					'service'      => 'pixelfed',
					'label'        => 'Pixelfed',
					'url'          => 'https://pixelfed.example/p/demo/1',
					'plugin'       => 'my-plugin',
					'plugin_label' => 'My Plugin',
				);
				$copies[] = array(
					'service' => 'evil',
					'label'   => 'Evil',
					'url'     => 'javascript:alert(1)',
				);
				$copies[] = array(
					'service' => 'nolabel',
					'url'     => 'https://example.com/1',
				);

				return $copies;
			}
		);

		$copies = Daymark_Plugin_Copies::for_post( $this->mark_id );

		$this->assertCount( 1, $copies );
		$this->assertSame( 'Pixelfed', $copies[0]['label'] );
	}

	/** Addresses for the same post share a key. */
	public function test_url_key_matches_aliases() {
		$this->assertSame(
			Daymark_Plugin_Copies::url_key( 'https://twitter.com/demo/status/42' ),
			Daymark_Plugin_Copies::url_key( 'https://x.com/i/web/status/42' )
		);
		$this->assertSame(
			Daymark_Plugin_Copies::url_key( 'https://bsky.app/profile/demo.bsky.social/post/3kxyz' ),
			Daymark_Plugin_Copies::url_key( 'https://bsky.app/profile/did:plc:abc/post/3kxyz' )
		);
		$this->assertSame(
			Daymark_Plugin_Copies::url_key( 'https://www.example.com/a/' ),
			Daymark_Plugin_Copies::url_key( 'https://example.com/a' )
		);
		$this->assertNotSame(
			Daymark_Plugin_Copies::url_key( 'https://example.com/a?p=1' ),
			Daymark_Plugin_Copies::url_key( 'https://example.com/a?p=2' )
		);
		$this->assertSame( '', Daymark_Plugin_Copies::url_key( 'not a url' ) );
	}

	/** The "Also on" line lists plugin copies with links, after Daymark's own. */
	public function test_links_markup_includes_plugin_copies() {
		update_post_meta(
			$this->mark_id,
			'_daymark_external_posts',
			wp_json_encode(
				array(
					'bluesky' => array(
						'external_url' => 'https://bsky.app/profile/demo/post/3kxyz',
						'label'        => 'Bluesky',
					),
				)
			)
		);
		// The same Bluesky post, recorded by ATmosphere under the DID.
		update_post_meta( $this->mark_id, '_atmosphere_bsky_uri', 'at://did:plc:abc/app.bsky.feed.post/3kxyz' );
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );
		update_post_meta( $this->mark_id, 'activitypub_status', 'federated' );

		$links = Daymark_Syndication_Links::links( $this->mark_id );

		$this->assertSame(
			array( 'https://bsky.app/profile/demo/post/3kxyz', 'https://mastodon.social/@demo/1' ),
			wp_list_pluck( $links, 'url' )
		);

		$markup = Daymark_Plugin::instance()->syndication_links->links_markup( $this->mark_id );

		$this->assertStringContainsString( 'href="https://mastodon.social/@demo/1"', $markup );
		$this->assertStringContainsString( 'class="u-syndication"', $markup );
		// An ActivityPub copy has no address of its own to link.
		$this->assertStringNotContainsString( 'Fediverse', $markup );
	}

	/**
	 * A plugin's copy is linked on the Mark's page, unless the Syndication
	 * Links plugin is active, which prints the links itself.
	 */
	public function test_page_links_copies_unless_syndication_links_plugin_active() {
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );

		$this->assertStringContainsString( 'https://mastodon.social/@demo/1', $this->rendered_content() );

		add_filter( 'daymark_syndication_links_plugin_active', '__return_true' );

		$this->assertStringNotContainsString( 'daymark-syndication-links', $this->rendered_content() );
	}

	/**
	 * The Mark's content as its own page renders it.
	 *
	 * @return string
	 */
	private function rendered_content(): string {
		$this->go_to( get_permalink( $this->mark_id ) );

		$content = '';
		while ( have_posts() ) {
			the_post();
			$content = apply_filters( 'the_content', get_the_content() );
		}

		return $content;
	}

	/** Daymark hands its copies to Syndication Links, skipping ones it already has. */
	public function test_adds_copies_to_syndication_links_plugin_list() {
		add_filter( 'daymark_syndication_links_plugin_active', '__return_true' );
		$this->showing_page();
		update_post_meta( $this->mark_id, '_wpt_tweet_id', '42' );
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );

		$urls = Daymark_Plugin::instance()->syndication_links->add_to_syndication_links_plugin(
			array( 'https://twitter.com/demo/status/42' ),
			$this->mark_id
		);

		$this->assertSame(
			array( 'https://twitter.com/demo/status/42', 'https://mastodon.social/@demo/1' ),
			$urls
		);

		$other = self::factory()->post->create();
		update_post_meta( $other, '_share_on_mastodon_url', 'https://mastodon.social/@demo/2' );
		// Saved in an earlier request, so only the Mark check can say no.
		$this->new_request();

		$this->assertSame(
			array(),
			Daymark_Plugin::instance()->syndication_links->add_to_syndication_links_plugin( array(), $other ),
			'Only Marks get Daymark\'s additions'
		);
	}

	/**
	 * Syndication Links saves what it reads from its list when it fills its
	 * post-editor box or adds a link, so Daymark adds nothing outside a
	 * page being shown, or while a post is saved during one.
	 */
	public function test_adds_nothing_to_syndication_links_plugin_list_outside_a_page() {
		add_filter( 'daymark_syndication_links_plugin_active', '__return_true' );
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );
		$links = Daymark_Plugin::instance()->syndication_links;
		$saved = array( 'https://twitter.com/demo/status/42' );

		// wp-admin, the REST API, WP-Cron: template_redirect never fired.
		unset( $GLOBALS['wp_actions']['template_redirect'] );
		$this->assertSame( $saved, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ) );

		$this->showing_page();

		// A post saved while a page is shown (another plugin posting it).
		foreach ( array( 'save_post', 'wp_after_insert_post', 'template_include' ) as $hook ) {
			$GLOBALS['wp_current_filter'][] = $hook;
			$this->assertSame( $saved, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ), $hook );
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		$this->assertCount( 2, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ) );
	}

	/**
	 * A post saved while a page is shown (a scheduled Mark published late,
	 * a front-end form) gets nothing added to Syndication Links' list at
	 * any point of its save, or for the rest of the request: a publishing
	 * plugin may post it, and Syndication Links save its link, from any of
	 * those hooks or after them. Another Mark shown on the same page still
	 * gets its copies.
	 */
	public function test_adds_nothing_to_a_post_saved_while_a_page_is_shown() {
		add_filter( 'daymark_syndication_links_plugin_active', '__return_true' );
		$links = Daymark_Plugin::instance()->syndication_links;
		$saved = array( 'https://twitter.com/demo/status/42' );

		$scheduled = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_title'  => 'Scheduled Mark',
			)
		);
		update_post_meta( $scheduled, '_daymark_is_mark', '1' );
		update_post_meta( $scheduled, '_share_on_mastodon_url', 'https://mastodon.social/@demo/3' );
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );

		// Its time has come, so saving it later keeps it published.
		global $wpdb;
		$past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup: wp_update_post() would publish it now.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $past,
				'post_date_gmt' => $past,
			),
			array( 'ID' => $scheduled )
		);
		clean_post_cache( $scheduled );

		// A new request starts, and shows a page.
		$this->new_request();
		$this->showing_page();

		$hooks = array(
			'transition_post_status',
			'future_to_publish',
			'publish_post',
			'publish_to_publish',
			'edit_post',
			'post_updated',
			'save_post_post',
			'save_post',
			'wp_insert_post',
			'wp_after_insert_post',
		);
		$read  = array();

		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook, $links, $saved, $scheduled, &$read ) {
					$read[ $hook ][] = $links->add_to_syndication_links_plugin( $saved, $scheduled );
				}
			);
		}

		// Published late, then edited, during the same request.
		wp_publish_post( $scheduled );
		wp_update_post(
			array(
				'ID'         => $scheduled,
				'post_title' => 'Scheduled Mark, edited',
			)
		);

		foreach ( $hooks as $hook ) {
			$this->assertNotEmpty( $read[ $hook ] ?? array(), "{$hook} fired" );

			foreach ( $read[ $hook ] as $urls ) {
				$this->assertSame( $saved, $urls, $hook );
			}
		}

		// Later in the same request, such as a plugin posting it on shutdown.
		$this->assertSame( $saved, $links->add_to_syndication_links_plugin( $saved, $scheduled ) );

		// A Mark this request didn't save is still shown with its copies.
		$this->assertSame(
			array( 'https://twitter.com/demo/status/42', 'https://mastodon.social/@demo/1' ),
			$links->add_to_syndication_links_plugin( $saved, $this->mark_id )
		);
	}

	/**
	 * Nothing is shown once the response is finished, so a plugin reading
	 * the list on shutdown (to save a link it posted) gets nothing added.
	 */
	public function test_adds_nothing_on_shutdown() {
		add_filter( 'daymark_syndication_links_plugin_active', '__return_true' );
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );
		$this->new_request();
		$this->showing_page();
		$links = Daymark_Plugin::instance()->syndication_links;
		$saved = array( 'https://twitter.com/demo/status/42' );

		$GLOBALS['wp_current_filter'][] = 'shutdown';
		$this->assertSame( $saved, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ) );
		array_pop( $GLOBALS['wp_current_filter'] );

		$this->assertCount( 2, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ) );
	}

	/**
	 * A site with themes turned off never fires template_redirect, but
	 * still serves its feeds, and the copies belong in them.
	 */
	public function test_adds_copies_to_a_feed_served_with_themes_off() {
		add_filter( 'daymark_syndication_links_plugin_active', '__return_true' );
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );
		$this->new_request();
		unset( $GLOBALS['wp_actions']['template_redirect'] );
		$links = Daymark_Plugin::instance()->syndication_links;
		$saved = array( 'https://twitter.com/demo/status/42' );

		$this->assertSame( $saved, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ), 'Not a feed' );

		$GLOBALS['wp_current_filter'][] = 'do_feed_rss2';
		$this->assertSame(
			array( 'https://twitter.com/demo/status/42', 'https://mastodon.social/@demo/1' ),
			$links->add_to_syndication_links_plugin( $saved, $this->mark_id )
		);

		// A post saved while the feed is served still gets nothing.
		$GLOBALS['wp_current_filter'][] = 'save_post';
		$this->assertSame( $saved, $links->add_to_syndication_links_plugin( $saved, $this->mark_id ) );
		array_pop( $GLOBALS['wp_current_filter'] );
		array_pop( $GLOBALS['wp_current_filter'] );
	}

	/** While Daymark prints its own "Also on" line, Syndication Links' list is left alone. */
	public function test_adds_nothing_while_syndication_links_plugin_is_treated_as_inactive() {
		add_filter( 'daymark_syndication_links_plugin_active', '__return_false' );
		$this->showing_page();
		update_post_meta( $this->mark_id, '_share_on_mastodon_url', 'https://mastodon.social/@demo/1' );

		$this->assertSame(
			array( 'https://twitter.com/demo/status/42' ),
			Daymark_Plugin::instance()->syndication_links->add_to_syndication_links_plugin(
				array( 'https://twitter.com/demo/status/42' ),
				$this->mark_id
			)
		);
	}

	/**
	 * Act as though a front-end page is being shown. The test case puts
	 * the action counts back after each test.
	 *
	 * @return void
	 */
	private function showing_page(): void {
		$GLOBALS['wp_actions']['template_redirect'] = 1;
	}

	/**
	 * Start what Daymark treats as a new request: the posts this test
	 * created were saved in an earlier one.
	 *
	 * @return void
	 */
	private function new_request(): void {
		Daymark_Syndication_Links::reset();
	}
}
