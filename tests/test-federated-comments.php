<?php
/**
 * Federated comment labeling + u-syndication markup tests.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Federated_Comments detection in notifications and
 * Daymark_Syndication_Links markup.
 */
class Test_Federated_Comments extends WP_UnitTestCase {

	/**
	 * A published Mark post to hang comments on.
	 *
	 * @var int
	 */
	private int $daymark_id;

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$this->daymark_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->daymark_id, '_daymark_is_mark', '1' );
		update_post_meta( $this->daymark_id, '_daymark_primary_type', 'note' );
	}

	/**
	 * Insert an approved comment with federation-plugin meta.
	 *
	 * @param array<string, string> $meta         Comment meta to attach.
	 * @param string                $comment_type Comment type.
	 * @return int Comment ID.
	 */
	private function insert_federated_comment( array $meta, string $comment_type = 'comment' ): int {
		$comment_id = (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->daymark_id,
				'comment_content'  => 'Federated reply',
				'comment_approved' => 1,
				'comment_type'     => $comment_type,
			)
		);

		foreach ( $meta as $key => $value ) {
			add_comment_meta( $comment_id, $key, $value );
		}

		return $comment_id;
	}

	/**
	 * Find a notification item by comment ID.
	 *
	 * @param int $comment_id Comment ID.
	 * @return array<string, mixed>|null
	 */
	private function notification_item( int $comment_id ): ?array {
		$items = Daymark_Plugin::instance()->notifications->get_notifications();

		foreach ( $items as $item ) {
			if ( (int) $item['comment_ID'] === $comment_id ) {
				return $item;
			}
		}

		return null;
	}

	/** ActivityPub replies are labeled as Fediverse replies with their source link. */
	public function test_activitypub_comment_labeled() {
		$comment_id = $this->insert_federated_comment(
			array(
				'protocol'   => 'activitypub',
				'source_url' => 'https://mastodon.social/@fan/12345',
			)
		);

		$item = $this->notification_item( $comment_id );

		$this->assertNotNull( $item );
		$this->assertTrue( $item['is_imported'] );
		$this->assertSame( 'fediverse', $item['source'] );
		$this->assertSame( 'Reply from the Fediverse', $item['source_label'] );
		$this->assertSame( 'https://mastodon.social/@fan/12345', $item['source_url'] );
	}

	/** ATmosphere (atproto) replies are labeled as Bluesky replies. */
	public function test_atproto_comment_labeled() {
		$comment_id = $this->insert_federated_comment(
			array(
				'protocol'   => 'atproto',
				'source_url' => 'https://bsky.app/profile/fan.bsky.social/post/abc',
			)
		);

		$item = $this->notification_item( $comment_id );

		$this->assertNotNull( $item );
		$this->assertSame( 'bluesky', $item['source'] );
		$this->assertSame( 'Reply from Bluesky', $item['source_label'] );
	}

	/** Webmention replies are labeled with the webmention source URL. */
	public function test_webmention_comment_labeled() {
		$comment_id = $this->insert_federated_comment(
			array(
				'protocol'              => 'webmention',
				'webmention_source_url' => 'https://example.blog/reply',
			)
		);

		$item = $this->notification_item( $comment_id );

		$this->assertNotNull( $item );
		$this->assertSame( 'webmention', $item['source'] );
		$this->assertSame( 'Reply via Webmention', $item['source_label'] );
		$this->assertSame( 'https://example.blog/reply', $item['source_url'] );
	}

	/** Plain comments stay labeled as on-site. */
	public function test_plain_comment_stays_on_site() {
		$comment_id = $this->insert_federated_comment( array() );

		$item = $this->notification_item( $comment_id );

		$this->assertNotNull( $item );
		$this->assertFalse( $item['is_imported'] );
		$this->assertSame( 'site', $item['source'] );
	}

	/** Reaction comment types (likes/reposts) are not notification items. */
	public function test_reaction_comment_types_excluded() {
		$comment_id = $this->insert_federated_comment(
			array( 'protocol' => 'atproto' ),
			'like'
		);

		$this->assertNull( $this->notification_item( $comment_id ) );
	}

	/** u-syndication links render for external posts with URLs. */
	public function test_u_syndication_markup() {
		update_post_meta(
			$this->daymark_id,
			'_daymark_external_posts',
			wp_json_encode(
				array(
					'bluesky'  => array(
						'external_id'  => 'at://did:plc:x/app.bsky.feed.post/1',
						'external_url' => 'https://bsky.app/profile/demo/post/1',
						'label'        => 'Bluesky',
					),
					'mastodon' => array(
						'external_id'  => '9',
						'external_url' => '',
						'label'        => 'Mastodon',
					),
				)
			)
		);

		$markup = Daymark_Plugin::instance()->syndication_links->links_markup( $this->daymark_id );

		$this->assertStringContainsString( 'class="u-syndication"', $markup );
		$this->assertStringContainsString( 'rel="syndication nofollow"', $markup );
		$this->assertStringContainsString( 'https://bsky.app/profile/demo/post/1', $markup );
		$this->assertStringContainsString( 'Also on:', $markup );
		// The URL-less Mastodon entry has nothing to link.
		$this->assertStringNotContainsString( 'Mastodon', $markup );
	}

	/** The content filter appends links on singular Mark views only. */
	public function test_content_filter_appends_on_singular() {
		update_post_meta(
			$this->daymark_id,
			'_daymark_external_posts',
			wp_json_encode(
				array(
					'bluesky' => array(
						'external_url' => 'https://bsky.app/profile/demo/post/1',
						'label'        => 'Bluesky',
					),
				)
			)
		);

		$this->go_to( get_permalink( $this->daymark_id ) );
		$this->assertTrue( is_singular() );

		$content = '';
		while ( have_posts() ) {
			the_post();
			$content = apply_filters( 'the_content', get_the_content() );
		}

		$this->assertStringContainsString( 'u-syndication', $content );
	}
}
