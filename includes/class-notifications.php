<?php
/**
 * Moment Notifications and Conversation Backflow
 *
 * Imports are mocked by default; real connector plugins take over via
 * the `moment_import_network_responses` filter.
 *
 * Imported social responses are stored as standard WordPress comments on
 * the original Moment post, so they render alongside on-site comments in
 * any theme with no special handling. Comment meta preserves the source
 * context (network, external ID/URL, external author, timestamps).
 *
 * Future real backflow via:
 * 1. WordPress Connector plugins — preferred for WP 7.0+ environments.
 *    A connector implements polling or webhook receipt, then calls
 *    Moment_Notifications::import_response() with verified data.
 *
 * 2. Existing WordPress social plugins — thin adapter translates
 *    incoming comment/reply events to the Moment comment meta schema.
 *
 * 3. Native Moment connector plugins — register via:
 *    add_action('moment_import_responses', [$my_connector, 'import'], 10, 2);
 *
 * Production implementation would need:
 * - Deduplication by _moment_comment_external_id
 * - Handling deleted/hidden/edited social responses
 * - Comment moderation integration
 * - Rate limiting for polling connectors
 * - Webhook signature verification
 * - Per-network opt-in settings
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects notification items for /moment/notifications and imports
 * mocked social responses as WordPress comments (conversation backflow).
 */
class Moment_Notifications {

	/**
	 * Default maximum notification items returned.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 50;

	/**
	 * Networks whose responses are threaded replies ("Reply from X").
	 * All other networks use comment phrasing ("Comment from X").
	 *
	 * @var string[]
	 */
	private const REPLY_NETWORKS = array( 'bluesky', 'mastodon', 'x' );

	/**
	 * Mocked sample response texts per network (1–2 imported per sync).
	 *
	 * @var array<string, string[]>
	 */
	private const SAMPLE_TEXTS = array(
		'bluesky'   => array( 'Love this.', 'Really nice.' ),
		'mastodon'  => array( 'Nice one!', 'Boosted this.' ),
		'instagram' => array( 'Great shot.', '❤️' ),
		'youtube'   => array( 'This looks fun.', 'Thanks for sharing.' ),
		'tiktok'    => array( 'Obsessed.', 'Love it!' ),
		'threads'   => array( 'So good.', 'Reposted.' ),
		'x'         => array( 'Nice.', 'Great post.' ),
	);

	/**
	 * Mocked external author handle per network.
	 *
	 * @var array<string, string>
	 */
	private const SAMPLE_AUTHORS = array(
		'bluesky'   => 'Demo User (@demouser.bsky.social)',
		'mastodon'  => 'Demo User (@demouser@mastodon.social)',
		'instagram' => '@demouser',
		'youtube'   => 'Demo User',
		'tiktok'    => '@demouser',
		'threads'   => '@demouser',
		'x'         => '@demouser',
	);

	/**
	 * Get notification items for the current user.
	 *
	 * Back-compat wrapper around get_notifications() (Phase 1 public API).
	 *
	 * @param array<string, mixed> $args Optional query arguments (supports 'limit').
	 * @return array<int, array<string, mixed>> Notification items.
	 */
	public function get_items( array $args = array() ): array {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : self::DEFAULT_LIMIT;

		return $this->get_notifications( $limit > 0 ? $limit : self::DEFAULT_LIMIT );
	}

	/**
	 * Build the unified notifications list: on-site comments and imported
	 * social responses, for Moment-created posts ONLY.
	 *
	 * The Moment-only scope is enforced server-side here — comments on
	 * normal posts created outside Moment never enter the result set,
	 * because the comment query is restricted to post IDs that carry
	 * _moment_is_moment = 1. This is not a client-side filter.
	 *
	 * Scoped per user: notifications are replies to Moments the current
	 * user can edit (authors get their own posts' activity; editors and
	 * admins get all of it) — never other authors' draft activity.
	 *
	 * @param int $limit Maximum items to return.
	 * @return array<int, array<string, mixed>> Notification items, newest first.
	 */
	public function get_notifications( int $limit = self::DEFAULT_LIMIT ): array {
		$moment_post_ids = $this->scoped_moment_post_ids();

		if ( empty( $moment_post_ids ) ) {
			return array();
		}

		$comments = $this->get_comments_for_posts( $moment_post_ids, $limit );
		$items    = array();

		foreach ( $comments as $comment ) {
			$post = get_post( (int) $comment->comment_post_ID );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$items[] = $this->format_comment( $comment, $post );
		}

		return $items;
	}

	/**
	 * User meta key holding the last-seen notifications timestamp.
	 */
	public const SEEN_META = 'moment_notifications_seen';

	/**
	 * Whether the current user has notifications newer than their last
	 * visit to the notifications screen. Boolean only — no counts.
	 *
	 * @return bool
	 */
	public function has_unread(): bool {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$moment_post_ids = $this->scoped_moment_post_ids();

		if ( empty( $moment_post_ids ) ) {
			return false;
		}

		$comments = $this->get_comments_for_posts( $moment_post_ids, 1 );

		if ( empty( $comments ) ) {
			return false;
		}

		$seen = (int) get_user_meta( $user_id, self::SEEN_META, true );

		return strtotime( $comments[0]->comment_date_gmt . ' UTC' ) > $seen;
	}

	/**
	 * Record that the current user has seen their notifications.
	 *
	 * @return void
	 */
	public function mark_seen(): void {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			update_user_meta( $user_id, self::SEEN_META, time() );
		}
	}

	/**
	 * Moment post IDs the current user may see activity for.
	 *
	 * @return int[]
	 */
	private function scoped_moment_post_ids(): array {
		return array_values(
			array_filter(
				$this->get_moment_post_ids(),
				static function ( $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				}
			)
		);
	}

	/**
	 * Get IDs of all Moment-created posts.
	 *
	 * @return int[] Post IDs where _moment_is_moment = 1.
	 */
	private function get_moment_post_ids(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Moment lookup.
				'meta_query'     => array(
					array(
						'key'   => '_moment_is_moment',
						'value' => '1',
					),
				),
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Get approved comments on the given posts, newest first.
	 *
	 * @param int[] $post_ids Moment post IDs.
	 * @param int   $limit    Maximum comments to return.
	 * @return WP_Comment[]
	 */
	private function get_comments_for_posts( array $post_ids, int $limit = self::DEFAULT_LIMIT ): array {
		$query = new WP_Comment_Query();

		$comments = $query->query(
			array(
				'post__in' => $post_ids,
				'status'   => 'approve',
				// Replies only: federation plugins (ATmosphere, Webmention)
				// also store likes/reposts as comments with their own
				// comment types — reactions are not notification items.
				'type'     => 'comment',
				'number'   => $limit,
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			)
		);

		return is_array( $comments ) ? $comments : array();
	}

	/**
	 * Build a unified notification item from a comment + its Moment post.
	 *
	 * Imported social responses (identified by _moment_comment_source
	 * meta) surface their source network, external author, and a link to
	 * the original social reply. On-site comments get source 'site' and
	 * the label 'On-site comment'.
	 *
	 * @param WP_Comment $comment The comment.
	 * @param WP_Post    $post    The Moment post it belongs to.
	 * @return array<string, mixed>
	 */
	private function format_comment( WP_Comment $comment, WP_Post $post ): array {
		$comment_id  = (int) $comment->comment_ID;
		$source      = $this->get_comment_source( $comment_id );
		$is_imported = 'site' !== $source;

		$source_label    = (string) get_comment_meta( $comment_id, '_moment_comment_source_label', true );
		$source_url      = (string) get_comment_meta( $comment_id, '_moment_comment_external_url', true );
		$external_author = (string) get_comment_meta( $comment_id, '_moment_comment_external_author', true );

		// Comments delivered by federation plugins (ActivityPub, ATmosphere,
		// Webmention) carry no _moment_comment_* meta but are social replies
		// all the same — label them from their own protocol markers.
		if ( ! $is_imported ) {
			$federated = Moment_Federated_Comments::detect( $comment );

			if ( null !== $federated ) {
				$is_imported  = true;
				$source       = $federated['source'];
				$source_label = $federated['label'];
				$source_url   = $federated['url'];
			}
		}

		$author = $is_imported && '' !== $external_author
			? $external_author
			: $comment->comment_author;

		$timestamp = strtotime( $comment->comment_date_gmt . ' UTC' );
		$relative  = $timestamp
			/* translators: %s: human-readable time difference, e.g. "2 minutes". */
			? sprintf( __( '%s ago', 'moment' ), human_time_diff( $timestamp, time() ) )
			: '';

		return array(
			// comment_ID is the canonical key the Moment frontend reads;
			// comment_id is kept as a lowercase alias.
			'comment_ID'            => $comment_id,
			'comment_id'            => $comment_id,
			'comment_content'       => wp_kses_post( $comment->comment_content ),
			'comment_date'          => $comment->comment_date,
			'comment_date_relative' => $relative,
			'comment_author'        => sanitize_text_field( $author ),
			'is_imported'           => $is_imported,
			'source'                => $source,
			'source_label'          => $is_imported && '' !== $source_label
				? sanitize_text_field( $source_label )
				: __( 'On-site comment', 'moment' ),
			'source_url'            => $source_url ? esc_url_raw( $source_url ) : '',
			'external_author'       => $is_imported && '' !== $external_author
				? sanitize_text_field( $external_author )
				: null,
			'post_id'               => (int) $post->ID,
			// Plain text: the_title filters entity-encode for HTML output,
			// but API consumers escape at render time themselves.
			'post_title'            => html_entity_decode(
				sanitize_text_field( get_the_title( $post ) ),
				ENT_QUOTES,
				'UTF-8'
			),
			'post_url'              => esc_url_raw( (string) get_permalink( $post ) ),
			'moment_type'           => sanitize_key( (string) get_post_meta( $post->ID, '_moment_primary_type', true ) ),
		);
	}

	/**
	 * Get the source network of a comment, or 'site' for on-site comments.
	 *
	 * @param int $comment_id Comment ID.
	 * @return string Network ID ('bluesky', 'instagram', …) or 'site'.
	 */
	public function get_comment_source( int $comment_id ): string {
		$source = get_comment_meta( $comment_id, '_moment_comment_source', true );

		return is_string( $source ) && '' !== $source ? sanitize_key( $source ) : 'site';
	}

	/**
	 * Import mocked social responses for a Moment (conversation backflow).
	 *
	 * For each requested network that has an entry in the Moment's
	 * _moment_external_posts reference map, inserts 1–2 sample WordPress
	 * comments with full source metadata. This is where a real connector
	 * would instead fetch replies from the platform API and hand each one
	 * to import_response().
	 *
	 * @param int      $post_id  Moment post ID.
	 * @param string[] $networks Requested network IDs; empty = all networks
	 *                           present in _moment_external_posts.
	 * @return array{imported_count: int, comments: array<int, array<string, mixed>>}|WP_Error
	 */
	public function import_responses( int $post_id, array $networks = array() ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_moment_is_moment', true ) ) {
			return new WP_Error(
				'moment_not_found',
				__( 'Moment not found.', 'moment' ),
				array( 'status' => 404 )
			);
		}

		$external_posts = json_decode( (string) get_post_meta( $post_id, '_moment_external_posts', true ), true );

		if ( ! is_array( $external_posts ) ) {
			$external_posts = array();
		}

		$networks = array_filter( array_map( 'sanitize_key', $networks ) );

		if ( empty( $networks ) ) {
			$networks = array_keys( $external_posts );
		}

		$imported = array();

		foreach ( $networks as $network ) {
			if ( ! isset( $external_posts[ $network ] ) || ! is_array( $external_posts[ $network ] ) ) {
				continue; // The Moment was never syndicated to this network.
			}

			/**
			 * Allows a real connector plugin to handle response import for
			 * a network instead of the mock importer.
			 *
			 * Return an array of imported comment IDs (may be empty) to mark
			 * the network as handled; return null to fall through to the
			 * mock importer. Handlers should call import_response() on the
			 * passed notifications instance — it deduplicates per response
			 * by `_moment_comment_external_id`, so real imports safely run
			 * on every sync (no `_moment_backflow_synced_*` flag involved).
			 *
			 * @param array<int>|null      $handled       Imported comment IDs, or null when unhandled.
			 * @param int                  $post_id       Moment post ID.
			 * @param string               $network       Network ID, e.g. 'bluesky'.
			 * @param array<string, mixed> $reference     External post reference for the network.
			 * @param Moment_Notifications $notifications This instance, for import_response() calls.
			 */
			$handled = apply_filters( 'moment_import_network_responses', null, $post_id, $network, $external_posts[ $network ], $this );

			if ( is_array( $handled ) ) {
				$imported = array_merge( $imported, array_values( array_filter( $handled, 'is_int' ) ) );

				/** This action is documented later in this method. */
				do_action( 'moment_import_responses', $post_id, $network );

				continue;
			}

			$reference = $external_posts[ $network ];

			// A real connector published this reference (it carries the
			// backflow_supported flag or a 'published' status) but no
			// connector plugin handled the import filter — e.g. it was
			// deactivated after publishing. Never fabricate demo replies
			// for a live post: skip the mock importer until a handler exists.
			if ( ! empty( $reference['backflow_supported'] ) || 'published' === ( $reference['status'] ?? '' ) ) {
				continue;
			}

			// Dedup guard: skip networks already synced for this post so
			// repeated syncs don't pile up duplicate mock comments. This
			// mirrors production deduplication, which would key on
			// _moment_comment_external_id per response instead.
			if ( '1' === get_post_meta( $post_id, '_moment_backflow_synced_' . $network, true ) ) {
				continue;
			}

			$external_url = isset( $reference['external_url'] ) ? (string) $reference['external_url'] : '';
			$texts        = self::SAMPLE_TEXTS[ $network ] ?? array( __( 'Nice post!', 'moment' ) );
			$author       = self::SAMPLE_AUTHORS[ $network ] ?? '@demouser';
			$label        = $this->get_source_label( $network, isset( $reference['label'] ) ? (string) $reference['label'] : $network );

			foreach ( array_slice( $texts, 0, 2 ) as $index => $text ) {
				$reply_number = $index + 1;

				$comment_id = $this->import_response(
					$post_id,
					$network,
					array(
						'content'      => $text,
						'author'       => $author,
						'source_label' => $label,
						'external_id'  => 'mock-reply-' . uniqid(),
						'external_url' => $external_url ? $external_url . '#reply-' . $reply_number : '',
						'created_at'   => gmdate( 'Y-m-d H:i:s', time() - ( 5 * $reply_number * MINUTE_IN_SECONDS ) ),
					)
				);

				if ( is_int( $comment_id ) && $comment_id > 0 ) {
					$imported[] = $comment_id;
				}
			}

			update_post_meta( $post_id, '_moment_backflow_synced_' . $network, '1' );

			/**
			 * Fires after responses were imported for one network.
			 *
			 * Real connector plugins hook here (or are invoked from here)
			 * to run their own platform-API import for the network.
			 *
			 * @param int    $post_id Moment post ID.
			 * @param string $network Network ID, e.g. 'bluesky'.
			 */
			do_action( 'moment_import_responses', $post_id, $network );
		}

		$comments = array();

		foreach ( $imported as $comment_id ) {
			$comment = get_comment( $comment_id );

			if ( $comment instanceof WP_Comment ) {
				$comments[] = $this->format_comment( $comment, $post );
			}
		}

		return array(
			'imported_count' => count( $imported ),
			'comments'       => $comments,
		);
	}

	/**
	 * Import a single external response as a WordPress comment.
	 *
	 * This is the plug-in point for real connectors: a WordPress Connector
	 * plugin or social-plugin adapter calls this with verified platform
	 * data and gets back a standard WordPress comment attached to the
	 * Moment post, carrying the full Moment comment meta schema.
	 *
	 * @param int                  $post_id  Moment post ID.
	 * @param string               $network  Network ID, e.g. 'bluesky'.
	 * @param array<string, mixed> $response {
	 *     Response data.
	 *
	 *     @type string $content      Response text.
	 *     @type string $author       External author display name/handle.
	 *     @type string $source_label Display label, e.g. 'Reply from Bluesky'.
	 *     @type string $external_id  Platform-unique response ID.
	 *     @type string $external_url URL of the original social response.
	 *     @type string $created_at   Source timestamp (MySQL format).
	 * }
	 * @return int|WP_Error New comment ID, or WP_Error on failure/duplicate.
	 */
	public function import_response( int $post_id, string $network, array $response ) {
		$network     = sanitize_key( $network );
		$external_id = sanitize_text_field( (string) ( $response['external_id'] ?? '' ) );

		// Deduplicate by external response ID — the same rule a real
		// polling/webhook connector must enforce.
		if ( '' !== $external_id && $this->external_response_exists( $external_id ) ) {
			return new WP_Error(
				'moment_duplicate_response',
				__( 'This external response was already imported.', 'moment' )
			);
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => wp_kses_post( (string) ( $response['content'] ?? '' ) ),
				'comment_author'       => sanitize_text_field( (string) ( $response['author'] ?? '' ) ),
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_approved'     => 1,
				'comment_type'         => 'comment',
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error(
				'moment_import_failed',
				__( 'Could not import the external response.', 'moment' )
			);
		}

		add_comment_meta( $comment_id, '_moment_comment_source', $network );
		add_comment_meta( $comment_id, '_moment_comment_source_label', sanitize_text_field( (string) ( $response['source_label'] ?? '' ) ) );
		add_comment_meta( $comment_id, '_moment_comment_external_id', $external_id );
		add_comment_meta( $comment_id, '_moment_comment_external_url', esc_url_raw( (string) ( $response['external_url'] ?? '' ) ) );
		add_comment_meta( $comment_id, '_moment_comment_external_author', sanitize_text_field( (string) ( $response['author'] ?? '' ) ) );
		add_comment_meta( $comment_id, '_moment_comment_external_created_at', sanitize_text_field( (string) ( $response['created_at'] ?? '' ) ) );
		add_comment_meta( $comment_id, '_moment_comment_imported_at', current_time( 'mysql' ) );

		return (int) $comment_id;
	}

	/**
	 * Whether a response with this external ID was already imported.
	 *
	 * @param string $external_id Platform-unique response ID.
	 * @return bool
	 */
	private function external_response_exists( string $external_id ): bool {
		$existing = get_comments(
			array(
				'count'      => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Personal-site-scale dedup lookup.
				'meta_key'   => '_moment_comment_external_id',
				'meta_value' => $external_id,
			)
		);

		return (int) $existing > 0;
	}

	/**
	 * Build the display label for an imported response.
	 *
	 * Reply-shaped networks (Bluesky, Mastodon, X) use 'Reply from X';
	 * comment-shaped networks (Instagram, YouTube, TikTok, Threads) use
	 * 'Comment from X'.
	 *
	 * @param string $network       Network ID.
	 * @param string $network_label Human-readable network name.
	 * @return string
	 */
	private function get_source_label( string $network, string $network_label ): string {
		if ( in_array( $network, self::REPLY_NETWORKS, true ) ) {
			/* translators: %s: social network name, e.g. Bluesky. */
			return sprintf( __( 'Reply from %s', 'moment' ), $network_label );
		}

		/* translators: %s: social network name, e.g. Instagram. */
		return sprintf( __( 'Comment from %s', 'moment' ), $network_label );
	}
}
