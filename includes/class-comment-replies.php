<?php
/**
 * Answers to your comments on followed posts (issue #559).
 *
 * When you comment on a followed WordPress post from Daymark, Daymark
 * remembers that comment and checks the original site's comments API for
 * replies to it, the way you'd go back to a blog post to see whether
 * anyone answered. A new reply shows in Notifications (Comments tab) and,
 * with push turned on, as a push.
 *
 * Which comments are watched: one sent straight to the site's comments API
 * (Daymark knows its ID from the response), and one sent as a Webmention
 * (Daymark looks it up on the site by your site's address and the
 * comment's words, since a Webmention reply has no ID Daymark is told
 * about; see find_own_comment()). Only WordPress sites can be watched:
 * Daymark asks for `wp/v2/comments?parent={your comment}`.
 * A comment sent through WordPress.com (Jetpack) isn't watched yet.
 *
 * "Your site's address" is this site's home address or any page under it
 * (see own_url_kind()). On a network whose sites share one host, another
 * site's pages never count, so a reply from a neighbor isn't mistaken for
 * your own answer. A comment whose address is only that shared host can
 * still be found as yours by its words, since that's how a Webmention's
 * author is sometimes recorded.
 *
 * A reply that also reaches your own site as a Webmention to your comment's
 * Mark (the Webmention plugin, or Daymark's own receiver) is shown once:
 * the Mark's own comment wins once it's approved, keeping the reply's read
 * and archived state and, when the reply was found first, its date (see
 * superseded()), and its push isn't sent twice. That comment keeps them
 * after Daymark forgets the reply (see remember_links()).
 *
 * Checks ride the hourly backflow sync (and the freshen run when
 * Notifications opens): hourly for two days, then every six hours for the
 * first week, then daily until 30 days have passed. A site that stops
 * sharing replies, or fails five checks in a row, is no longer checked.
 * Each watch lives in one row of the commenter's own user meta, kept
 * small (25 watches per site, 10 replies each), and is removed 45 days
 * after the comment, also while watching is turned off. On a multisite
 * network, a watch belongs to the site the comment was sent from: only
 * that site checks it and lists its replies, though every site's watches
 * on the same post are matched together when finding a Webmention
 * comment, so neighbors' comments are never swapped.
 *
 * @package Daymark
 * @since   0.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watches the user's comments on followed posts for replies.
 */
class Daymark_Comment_Replies {

	/**
	 * User meta key: one row per watched comment.
	 *
	 * @var string
	 */
	public const META = 'daymark_comment_watches';

	/**
	 * Comment meta key on a Webmention comment that copies a found reply
	 * Daymark has since forgotten: which found reply it stood in for, and
	 * when Daymark found it, by user ID (see remember_links()).
	 *
	 * @var string
	 */
	public const FOUND_META = '_daymark_found_reply';

	/**
	 * Transient that keeps two check runs from overlapping.
	 *
	 * @var string
	 */
	public const LOCK = 'daymark_comment_replies_running';

	/**
	 * Most comments watched per person; the oldest goes first.
	 *
	 * @var int
	 */
	private const MAX_WATCHES = 25;

	/**
	 * Most replies remembered per comment; the newest are kept.
	 *
	 * @var int
	 */
	private const MAX_REPLIES = 10;

	/**
	 * Most reply IDs remembered per comment, so a reply that dropped off
	 * the remembered list is never reported again. More than one check
	 * reads (20), with room for replies approved late.
	 *
	 * @var int
	 */
	private const MAX_SEEN = 60;

	/**
	 * Every field a stored watch has. A row missing one is removed. A row
	 * also has `blog`, the site it was made on (see blog_of()).
	 *
	 * @var string[]
	 */
	private const KEYS = array( 'id', 'post', 'permalink', 'title', 'site', 'root', 'remote', 'comment', 'mark', 'excerpt', 'created', 'checked', 'fails', 'done', 'replies', 'seen' );

	/**
	 * Failed checks in a row before Daymark stops checking a comment.
	 *
	 * @var int
	 */
	private const MAX_FAILS = 5;

	/**
	 * How long after the watch window a watch is kept, so its replies stay
	 * in Notifications for a while.
	 *
	 * @var int
	 */
	private const KEEP_AFTER_WINDOW = 15 * DAY_IN_SECONDS;

	/**
	 * Characters of a comment kept to find it on the original site.
	 *
	 * @var int
	 */
	private const EXCERPT_CHARS = 140;

	/**
	 * Shortest text (in characters, after match_text()) that can match the
	 * start of a longer comment; shorter text must match a whole comment.
	 *
	 * @var int
	 */
	private const MIN_MATCH = 10;

	/**
	 * Approved Webmention comments per Mark, by reply key (see
	 * local_reply_keys()), until a comment or its meta changes.
	 *
	 * @var array<int, array<string, int[]>>
	 */
	private static array $local_keys = array();

	/**
	 * Links remembered on approved comments (see stored_links()), until a
	 * comment or its meta changes.
	 *
	 * @var array<int, array{post: int, own: int, links: array<int, mixed>}>|null
	 */
	private static ?array $stored_links = null;

	/**
	 * Hook up: checks run with the backflow sync.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( Daymark_Backflow_Sync::CRON_HOOK, array( __CLASS__, 'check_due' ), 20 );
		add_action( Daymark_Backflow_Sync::CRON_HOOK . '_now', array( __CLASS__, 'check_due' ), 20 );

		// A copy added, edited, approved, held, or deleted takes effect
		// within the same request.
		foreach ( array( 'wp_insert_comment', 'edit_comment', 'transition_comment_status', 'deleted_comment', 'added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_local_keys' ) );
		}
	}

	/**
	 * Whether Daymark watches comments for replies.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether Daymark checks for replies to the comments you
		 * send to followed WordPress posts.
		 *
		 * @since 0.22.0
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'daymark_watch_comment_replies', true );
	}

	/**
	 * Start watching a comment the user just sent.
	 *
	 * @param int                  $user_id              Commenter.
	 * @param int                  $subscription_post_id The followed post (`daymark_sub_post`).
	 * @param array<string, mixed> $target               root (REST root, absolute), remote (post ID there),
	 *                                                   comment (comment ID there, 0 if unknown), mark (the
	 *                                                   Webmention-route Mark, 0 if none), text (what was sent).
	 * @return bool Whether a watch was added.
	 */
	public static function start_watch( int $user_id, int $subscription_post_id, array $target ): bool {
		if ( $user_id <= 0 || ! self::enabled() ) {
			return false;
		}

		$post = get_post( $subscription_post_id );

		if ( ! $post instanceof WP_Post || Daymark_Subscription_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$root      = esc_url_raw( (string) ( $target['root'] ?? '' ) );
		$remote    = absint( $target['remote'] ?? 0 );
		$comment   = absint( $target['comment'] ?? 0 );
		$mark      = absint( $target['mark'] ?? 0 );
		$permalink = esc_url_raw( (string) get_post_meta( $subscription_post_id, 'permalink', true ) );

		// With no comment ID (a Webmention, or a site that didn't say),
		// the first check looks the comment up by this site's address.
		if ( '' === $root || 0 === $remote || '' === $permalink ) {
			return false;
		}

		$watches = self::get_watches( $user_id );

		foreach ( $watches as $watch ) {
			if ( $watch['root'] === $root && (int) $watch['remote'] === $remote
				&& ( ( $comment > 0 && (int) $watch['comment'] === $comment ) || ( $mark > 0 && (int) $watch['mark'] === $mark ) ) ) {
				return false;
			}
		}

		$watch = array(
			'id'        => bin2hex( random_bytes( 6 ) ),
			'blog'      => get_current_blog_id(),
			'post'      => $subscription_post_id,
			'permalink' => $permalink,
			'title'     => Daymark_Remote_Replies::plain( (string) get_the_title( $post ), 120 ),
			'site'      => self::host_key( $permalink ),
			'root'      => $root,
			'remote'    => $remote,
			'comment'   => $comment,
			'mark'      => $mark,
			'excerpt'   => Daymark_Remote_Replies::plain( (string) ( $target['text'] ?? '' ), self::EXCERPT_CHARS ),
			'created'   => time(),
			'checked'   => 0,
			'fails'     => 0,
			'done'      => false,
			'replies'   => array(),
			'seen'      => array(),
		);

		if ( false === add_user_meta( $user_id, self::META, wp_slash( $watch ) ) ) {
			return false;
		}

		$watches[] = $watch;

		if ( count( $watches ) > self::MAX_WATCHES ) {
			usort(
				$watches,
				static function ( array $a, array $b ): int {
					return (int) $a['created'] <=> (int) $b['created'];
				}
			);

			foreach ( array_slice( $watches, 0, count( $watches ) - self::MAX_WATCHES ) as $oldest ) {
				self::remember_links( $user_id, $oldest, $oldest['replies'] );
				delete_user_meta( $user_id, self::META, wp_slash( $oldest ) );
			}
		}

		return true;
	}

	/**
	 * Check the comments that are due, oldest check first, within a small
	 * per-run limit and time budget. Runs in WP-Cron.
	 *
	 * @return int How many comments were checked.
	 */
	public static function check_due(): int {
		if ( false !== get_transient( self::LOCK ) ) {
			return 0;
		}

		set_transient( self::LOCK, time(), 5 * MINUTE_IN_SECONDS );

		try {
			$enabled = self::enabled();
			$now     = time();
			$window  = self::window();
			$due     = array();

			foreach ( self::watcher_ids() as $user_id ) {
				// The list covers the whole network, so it can include
				// someone who can no longer use Daymark on this site: their
				// watches here still expire, but aren't checked.
				$can_check = $enabled && user_can( (int) $user_id, 'edit_posts' );

				foreach ( get_user_meta( (int) $user_id, self::META, false ) as $watch ) {
					if ( ! self::is_complete( $watch ) ) {
						delete_user_meta( (int) $user_id, self::META, wp_slash( $watch ) );
						continue;
					}

					// Another site on the network checks and removes its own
					// watches, unless that site was deleted.
					if ( ! self::belongs_here( $watch ) ) {
						if ( self::site_gone( $watch ) ) {
							delete_user_meta( (int) $user_id, self::META, wp_slash( $watch ) );
						}

						continue;
					}

					// Removed on time even while watching is off, so its
					// replies leave Notifications when they should.
					if ( self::expired( $watch ) ) {
						self::remember_links( (int) $user_id, $watch, $watch['replies'] );
						delete_user_meta( (int) $user_id, self::META, wp_slash( $watch ) );
						continue;
					}

					$age = $now - (int) $watch['created'];

					if ( ! $can_check || ! empty( $watch['done'] ) || $age > $window || (int) $watch['checked'] + self::interval( $age, $watch ) > $now ) {
						continue;
					}

					$due[] = array(
						'user'  => (int) $user_id,
						'watch' => $watch,
					);
				}
			}

			usort(
				$due,
				static function ( array $a, array $b ): int {
					return (int) $a['watch']['checked'] <=> (int) $b['watch']['checked'];
				}
			);

			/**
			 * Filters how many comments one run checks for replies.
			 *
			 * @since 0.22.0
			 *
			 * @param int $limit Default 10.
			 */
			$limit = max( 1, (int) apply_filters( 'daymark_comment_reply_checks_per_run', 10 ) );

			/**
			 * Filters how long one run may spend checking comments for
			 * replies, in seconds. Whatever is left waits for the next run.
			 *
			 * @since 0.22.0
			 *
			 * @param int $seconds Default 15.
			 */
			$budget  = max( 1, (int) apply_filters( 'daymark_comment_reply_time_budget', 15 ) );
			$started = microtime( true );
			$checked = 0;

			foreach ( $due as $item ) {
				if ( $checked >= $limit || microtime( true ) - $started > $budget ) {
					break;
				}

				self::check_watch( $item['user'], $item['watch'] );
				++$checked;
			}

			self::forget_stale_links();

			return $checked;
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Notification items for the replies Daymark found, newest first.
	 * Replies that also reached a Mark as a comment are left to that
	 * comment's own item.
	 *
	 * @param int $user_id User.
	 * @return array<int, array{timestamp: int, item: array<string, mixed>}>
	 */
	public static function notification_items( int $user_id ): array {
		$items = array();

		foreach ( self::get_watches( $user_id ) as $watch ) {
			$replies = self::visible_replies( $user_id, $watch );

			if ( array() === $replies ) {
				continue;
			}

			$post_id = self::live_post_id( (int) $watch['post'] );

			foreach ( $replies as $reply ) {
				$found = absint( $reply['found'] ?? 0 );
				$date  = absint( $reply['date'] ?? 0 );

				$items[] = array(
					'timestamp' => $found,
					'item'      => array(
						'type'                 => 'comment_reply',
						'reply_key'            => self::item_key( $watch, $reply ),
						'author'               => sanitize_text_field( (string) ( $reply['author'] ?? '' ) ),
						'author_url'           => esc_url_raw( (string) ( $reply['author_url'] ?? '' ) ),
						'avatar'               => esc_url_raw( (string) ( $reply['avatar'] ?? '' ), array( 'https' ) ),
						'content'              => sanitize_textarea_field( (string) ( $reply['text'] ?? '' ) ),
						'reply_date'           => $date > 0 ? gmdate( 'c', $date ) : '',
						'reply_url'            => esc_url_raw( (string) ( $reply['url'] ?? '' ) ),
						'comment_excerpt'      => sanitize_textarea_field( (string) $watch['excerpt'] ),
						'source'               => 'followed',
						'source_label'         => __( 'Followed sites', 'daymark' ),
						'subscription_post_id' => $post_id,
						'post_title'           => (string) $watch['title'],
						'post_url'             => esc_url_raw( (string) $watch['permalink'] ),
						'site'                 => (string) $watch['site'],
					),
				);
			}
		}

		// Every item, not only the newest: Notifications prunes the read
		// and archived state of anything missing from its full list.
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		return $items;
	}

	/**
	 * The approved Webmention comments that bring a reply Daymark found for
	 * this user to the comment's Mark, when Notifications lists that Mark's
	 * comments for them (see Daymark_Notifications::lists_mark_for()), by
	 * the comment's notification ID: the found reply's notification ID, and
	 * when Daymark found it. Notifications shows such a comment in the found
	 * reply's place (see visible_replies()), with the same read and archived
	 * state (see Daymark_Notification_State::carry_over()), dated when
	 * Daymark found the reply. A reply whose comment was already approved
	 * when Daymark found it was never shown, so it isn't listed. Once
	 * Daymark forgets a reply, its comment stays listed for as long as that
	 * date still changes whether it's new or read (see remember_links() and
	 * link_matters()); after that it's dated by its own date, like any other
	 * comment.
	 *
	 * @param int $user_id User.
	 * @return array<string, array{from: string, found: int}>
	 */
	public static function superseded( int $user_id ): array {
		$superseded = array();
		$listed     = array();

		foreach ( self::get_watches( $user_id ) as $watch ) {
			if ( array() === $watch['replies'] || ! Daymark_Notifications::lists_mark_for( $user_id, (int) $watch['mark'] ) ) {
				continue;
			}

			$local = self::local_reply_keys( (int) $watch['mark'] );

			foreach ( $watch['replies'] as $reply ) {
				if ( ! is_array( $reply ) || ! empty( $reply['local'] ) ) {
					continue;
				}

				$key = self::reply_key( (string) ( $reply['url'] ?? '' ) );

				if ( '' === $key || ! isset( $local[ $key ] ) ) {
					continue;
				}

				$from = Daymark_Notifications::item_id(
					array(
						'type'      => 'comment_reply',
						'reply_key' => self::item_key( $watch, $reply ),
					)
				);

				foreach ( $local[ $key ] as $comment_id ) {
					$copy = Daymark_Notifications::item_id(
						array(
							'type'       => 'comment',
							'comment_ID' => $comment_id,
						)
					);

					if ( ! isset( $superseded[ $copy ] ) ) {
						$superseded[ $copy ] = array(
							'from'  => $from,
							'found' => absint( $reply['found'] ?? 0 ),
						);
					}
				}
			}
		}

		foreach ( self::stored_links() as $comment_id => $stored ) {
			$link = $stored['links'][ $user_id ] ?? null;
			$copy = Daymark_Notifications::item_id(
				array(
					'type'       => 'comment',
					'comment_ID' => $comment_id,
				)
			);

			if ( ! is_array( $link ) || '' === (string) ( $link['from'] ?? '' ) || isset( $superseded[ $copy ] )
				|| ! self::link_matters( $user_id, absint( $link['found'] ?? 0 ), $stored['own'] ) ) {
				continue;
			}

			if ( ! isset( $listed[ $stored['post'] ] ) ) {
				$listed[ $stored['post'] ] = Daymark_Notifications::lists_mark_for( $user_id, $stored['post'] );
			}

			if ( $listed[ $stored['post'] ] ) {
				$superseded[ $copy ] = array(
					'from'  => (string) $link['from'],
					'found' => absint( $link['found'] ?? 0 ),
				);
			}
		}

		return $superseded;
	}

	/**
	 * The notification IDs of every reply this user's watches still hold,
	 * on any site of the network, listed or not. Notifications keeps their
	 * read and archived state until the watch is removed (see
	 * Daymark_Notification_State::prune()): an expired watch's replies
	 * leave the list at once, but their state moves to a Webmention copy
	 * only when the next check removes the watch (see remember_links()),
	 * and state is stored once per person for the whole network, while
	 * each site lists only its own watches' replies.
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	public static function stored_item_ids( int $user_id ): array {
		$ids = array();

		if ( $user_id <= 0 ) {
			return $ids;
		}

		foreach ( get_user_meta( $user_id, self::META, false ) as $watch ) {
			if ( ! self::is_complete( $watch ) ) {
				continue;
			}

			foreach ( $watch['replies'] as $reply ) {
				if ( is_array( $reply ) ) {
					$ids[] = Daymark_Notifications::item_id(
						array(
							'type'      => 'comment_reply',
							'reply_key' => self::item_key( $watch, $reply ),
						)
					);
				}
			}
		}

		return $ids;
	}

	/**
	 * Whether Daymark found a reply after a given time.
	 *
	 * @param int $user_id User.
	 * @param int $since   Unix timestamp.
	 * @return bool
	 */
	public static function has_new_since( int $user_id, int $since ): bool {
		foreach ( self::get_watches( $user_id ) as $watch ) {
			foreach ( self::visible_replies( $user_id, $watch ) as $reply ) {
				if ( absint( $reply['found'] ?? 0 ) > $since ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether Daymark already told the Mark's author about this reply
	 * (found it by checking the original site), so the same reply arriving
	 * later as a Webmention comment doesn't push again. That includes a
	 * comment held when Daymark forgot the reply and approved afterward
	 * (see remember_links()).
	 *
	 * @param int    $mark_id    The comment's Mark.
	 * @param string $source_url The incoming comment's Webmention source URL.
	 * @param int    $comment_id The incoming comment, once stored (0 if not).
	 * @return bool
	 */
	public static function already_found( int $mark_id, string $source_url, int $comment_id = 0 ): bool {
		$key  = self::reply_key( $source_url );
		$post = $mark_id > 0 ? get_post( $mark_id ) : null;

		if ( '' === $key || ! $post instanceof WP_Post ) {
			return false;
		}

		if ( $comment_id > 0 ) {
			$links = get_comment_meta( $comment_id, self::FOUND_META, true );

			if ( is_array( $links ) && isset( $links[ (int) $post->post_author ] ) ) {
				return true;
			}
		}

		foreach ( self::get_watches( (int) $post->post_author ) as $watch ) {
			if ( (int) $watch['mark'] !== $mark_id ) {
				continue;
			}

			foreach ( $watch['replies'] as $reply ) {
				if ( empty( $reply['local'] ) && self::reply_key( (string) ( $reply['url'] ?? '' ) ) === $key ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The IDs, on the original site, of the comments this user sent to a
	 * followed post through Daymark.
	 *
	 * @param int    $user_id              User.
	 * @param int    $subscription_post_id The followed post (`daymark_sub_post`).
	 * @param string $permalink            Its permalink.
	 * @return string[]
	 */
	public static function own_comment_ids( int $user_id, int $subscription_post_id, string $permalink ): array {
		$permalink = esc_url_raw( $permalink );
		$ids       = array();

		foreach ( self::get_watches( $user_id ) as $watch ) {
			$same_post = ( $subscription_post_id > 0 && (int) $watch['post'] === $subscription_post_id )
				|| ( '' !== $permalink && $watch['permalink'] === $permalink );

			if ( $same_post && (int) $watch['comment'] > 0 ) {
				$ids[] = (string) (int) $watch['comment'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The user's watches made on this site: complete rows only, exactly as
	 * stored (so they match for an update or a delete).
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_watches( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		return array_values(
			array_filter(
				get_user_meta( $user_id, self::META, false ),
				static function ( $row ): bool {
					return self::is_complete( $row ) && self::belongs_here( $row );
				}
			)
		);
	}

	/**
	 * Whether a stored row is a watch with every field.
	 *
	 * @param mixed $row Stored row.
	 * @return bool
	 */
	public static function is_complete( $row ): bool {
		return is_array( $row )
			&& array() === array_diff_key( array_flip( self::KEYS ), $row )
			&& is_array( $row['replies'] )
			&& is_array( $row['seen'] );
	}

	/**
	 * The site a watch was made on. Watches from before sites were recorded
	 * count as the main site's.
	 *
	 * @param array<string, mixed> $row Watch.
	 * @return int Site ID.
	 */
	private static function blog_of( array $row ): int {
		return (int) ( $row['blog'] ?? get_main_site_id() );
	}

	/**
	 * Whether a watch was made on this site.
	 *
	 * @param array<string, mixed> $row Watch.
	 * @return bool
	 */
	private static function belongs_here( array $row ): bool {
		return self::blog_of( $row ) === get_current_blog_id();
	}

	/**
	 * Whether a watch was made on a site that no longer exists, so no site
	 * would ever check or remove it.
	 *
	 * @param array<string, mixed> $row Watch.
	 * @return bool
	 */
	private static function site_gone( array $row ): bool {
		$blog = self::blog_of( $row );

		return get_current_blog_id() !== $blog && ( $blog <= 0 || ! is_multisite() || ! get_site( $blog ) instanceof WP_Site );
	}

	/**
	 * Whether a watch is past the time it's kept: the watch window, then a
	 * while for its replies to stay in Notifications.
	 *
	 * @param array<string, mixed> $row Watch.
	 * @return bool
	 */
	private static function expired( array $row ): bool {
		return time() - (int) $row['created'] > self::window() + self::KEEP_AFTER_WINDOW;
	}

	/**
	 * A key that identifies one comment on one site, from its URL: the
	 * host plus the comment ID in `#comment-123` or `replytocom=123`. The
	 * same reply read from the site's API and received as a Webmention
	 * gives the same key. '' when the URL names no comment.
	 *
	 * @param string $url Comment URL.
	 * @return string
	 */
	public static function reply_key( string $url ): string {
		$host = self::host_key( $url );

		if ( '' === $host ) {
			return '';
		}

		if ( preg_match( '/#comment-(\d+)$/', $url, $matches ) || preg_match( '/[?&]replytocom=(\d+)/', $url, $matches ) ) {
			return $host . '#' . $matches[1];
		}

		return '';
	}

	/**
	 * Check one watched comment: find its ID if still unknown, then look
	 * for replies to it. Saves only if the watch wasn't changed meanwhile,
	 * and pushes only what this check newly found.
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $watch   Watch exactly as stored.
	 * @return void
	 */
	private static function check_watch( int $user_id, array $watch ): void {
		$stored           = $watch;
		$now              = time();
		$watch['checked'] = $now;
		$status           = Daymark_Remote_Replies::STATUS_OK;
		$new              = array();
		$dropped          = array();

		if ( 0 === (int) $watch['comment'] ) {
			$own              = self::find_own_comment( $user_id, $watch );
			$status           = $own['status'];
			$watch['comment'] = $own['id'];
		}

		if ( Daymark_Remote_Replies::STATUS_OK === $status && (int) $watch['comment'] > 0 ) {
			$fetched = Daymark_Remote_Replies::fetch_comments(
				(string) $watch['root'],
				array(
					'post'     => (int) $watch['remote'],
					'parent'   => (int) $watch['comment'],
					'per_page' => 20,
					'orderby'  => 'date_gmt',
					'order'    => 'desc',
					'_fields'  => Daymark_Remote_Replies::COMMENT_FIELDS,
				)
			);
			$status  = $fetched['status'];

			if ( Daymark_Remote_Replies::STATUS_OK === $status ) {
				$known = array_fill_keys( array_map( 'intval', $watch['seen'] ), true );
				$local = self::local_reply_keys( (int) $watch['mark'] );

				foreach ( array_reverse( $fetched['items'] ) as $item ) {
					$reply = Daymark_Remote_Replies::normalize_wordpress_comment( $item, (string) $watch['permalink'] );

					if ( null === $reply || isset( $known[ (int) $reply['id'] ] ) ) {
						continue;
					}

					$known[ (int) $reply['id'] ] = true;
					$watch['seen'][]             = (int) $reply['id'];

					// Your own answers aren't news to you. A reply signed with
					// only this site's host may be a neighbor's on a shared
					// host, so it still counts.
					if ( 'own' === self::own_url_kind( $reply['author_url'] ) ) {
						continue;
					}

					$row = array(
						'id'         => $reply['id'],
						'author'     => $reply['author_name'],
						'author_url' => $reply['author_url'],
						'avatar'     => $reply['avatar'],
						'text'       => Daymark_Remote_Replies::plain( $reply['content'], 200 ),
						'url'        => $reply['url'],
						'date'       => (int) $reply['_ts'],
						'found'      => $now,
						'local'      => isset( $local[ self::reply_key( $reply['url'] ) ] ),
					);

					$watch['replies'][] = $row;

					if ( ! $row['local'] ) {
						$new[] = $row;
					}
				}

				$dropped          = array_slice( $watch['replies'], 0, max( 0, count( $watch['replies'] ) - self::MAX_REPLIES ) );
				$watch['replies'] = array_slice( $watch['replies'], -self::MAX_REPLIES );
				$watch['seen']    = array_slice( $watch['seen'], -self::MAX_SEEN );
			}
		}

		if ( Daymark_Remote_Replies::STATUS_OK === $status ) {
			$watch['fails'] = 0;
		} elseif ( Daymark_Remote_Replies::STATUS_UNSUPPORTED === $status ) {
			$watch['done'] = true;
		} else {
			$watch['fails'] = (int) $watch['fails'] + 1;
			$watch['done']  = $watch['fails'] >= self::MAX_FAILS;
		}

		// Matching on the stored value means a run that lost a race with
		// another one saves nothing, remembers nothing, and pushes nothing.
		if ( update_user_meta( $user_id, self::META, wp_slash( $watch ), $stored ) ) {
			self::remember_links( $user_id, $watch, $dropped );

			if ( array() !== $new ) {
				self::push( $user_id, $watch, $new );
			}
		}
	}

	/**
	 * Find a Webmention comment's ID on the original site, among that
	 * post's newest comments from this site's address, or from only its
	 * host, which is how a Webmention's author is sometimes recorded.
	 *
	 * Anyone can type your site's address into a comment form, so the
	 * address alone never makes a comment yours: its words must match too.
	 * Every watch on the same post that is still looking for its comment
	 * (anyone's, on any site of the network, finished or not) is matched
	 * at the same time, best words first and then the closest time, so
	 * two people's comments are never swapped, whichever is checked
	 * first. Each watch is matched only with comments from its own site's
	 * address, or from only the host that site shares with its neighbors.
	 * A comment another watch already found is never used, nor one stored
	 * more than an hour before the comment was sent.
	 *
	 * A comment the site edited, or one with no letters, numbers, or
	 * emoji, isn't found, and its replies aren't followed.
	 *
	 * @param int                  $user_id User the watch belongs to.
	 * @param array<string, mixed> $watch   Watch.
	 * @return array{status: string, id: int}
	 */
	private static function find_own_comment( int $user_id, array $watch ): array {
		$fetched = Daymark_Remote_Replies::fetch_comments(
			(string) $watch['root'],
			array(
				'post'     => (int) $watch['remote'],
				'search'   => self::host_key( home_url( '/' ) ),
				'per_page' => 20,
				'orderby'  => 'date_gmt',
				'order'    => 'desc',
				'_fields'  => 'id,author_url,date_gmt,content',
			)
		);

		if ( Daymark_Remote_Replies::STATUS_OK !== $fetched['status'] ) {
			return array(
				'status' => $fetched['status'],
				'id'     => 0,
			);
		}

		$on_post = self::claims_on_post( $user_id, $watch );
		$pairs   = array();

		foreach ( $fetched['items'] as $item ) {
			$id = absint( $item['id'] ?? 0 );

			if ( 0 === $id || isset( $on_post['taken'][ $id ] ) ) {
				continue;
			}

			$author_url = (string) ( $item['author_url'] ?? '' );
			$date       = Daymark_Remote_Replies::timestamp( (string) ( $item['date_gmt'] ?? '' ) );
			$text       = self::match_text( is_array( $item['content'] ?? null ) ? (string) ( $item['content']['rendered'] ?? '' ) : '' );
			$kinds      = array();

			foreach ( $on_post['claims'] as $index => $claim ) {
				// Read the address as the watch's own site reads it: on a
				// shared host, a neighbor's page is never this watch's, but
				// the host alone can be anyone's on it.
				if ( ! isset( $kinds[ $claim['blog'] ] ) ) {
					$kinds[ $claim['blog'] ] = self::own_url_kind( $author_url, $claim['blog'] );
				}

				if ( '' === $kinds[ $claim['blog'] ] || ( $date > 0 && $date < $claim['created'] - HOUR_IN_SECONDS ) ) {
					continue;
				}

				$score = self::match_score( $text, $claim['variants'], $claim['cut'] );

				if ( $score > 0 ) {
					$pairs[] = array(
						'claim'   => $index,
						'comment' => $id,
						'score'   => $score,
						'gap'     => $date > 0 ? abs( $date - $claim['created'] ) : PHP_INT_MAX,
						'created' => $claim['created'],
						'user'    => $claim['user'],
						'watch'   => $claim['id'],
					);
				}
			}
		}

		usort(
			$pairs,
			static function ( array $a, array $b ): int {
				return array( $b['score'], $a['gap'], $a['created'], $a['user'], $a['watch'], $a['comment'] )
					<=> array( $a['score'], $b['gap'], $b['created'], $b['user'], $b['watch'], $b['comment'] );
			}
		);

		$used_claims   = array();
		$used_comments = array();

		foreach ( $pairs as $pair ) {
			if ( isset( $used_claims[ $pair['claim'] ] ) || isset( $used_comments[ $pair['comment'] ] ) ) {
				continue;
			}

			if ( $on_post['claims'][ $pair['claim'] ]['self'] ) {
				return array(
					'status' => Daymark_Remote_Replies::STATUS_OK,
					'id'     => $pair['comment'],
				);
			}

			$used_claims[ $pair['claim'] ]     = true;
			$used_comments[ $pair['comment'] ] = true;
		}

		return array(
			'status' => Daymark_Remote_Replies::STATUS_OK,
			'id'     => 0,
		);
	}

	/**
	 * The watches on the same post, by anyone on any site of the network:
	 * the comment IDs any of them already found, and each one still looking
	 * for its comment (this watch first). Another site's watch is weighed
	 * here but never updated: its own site records what it finds, by the
	 * same matching. A watch past its time, or from a deleted site, is no
	 * longer looking.
	 *
	 * @param int                  $user_id User the watch belongs to.
	 * @param array<string, mixed> $watch   Watch.
	 * @return array{taken: array<int, bool>, claims: array<int, array<string, mixed>>}
	 */
	private static function claims_on_post( int $user_id, array $watch ): array {
		$taken  = array();
		$claims = array( self::claim( $user_id, $watch, true ) );

		foreach ( self::watcher_ids() as $other_user ) {
			foreach ( get_user_meta( (int) $other_user, self::META, false ) as $other ) {
				if ( ! self::is_complete( $other ) ) {
					continue;
				}

				$same_watch = (int) $other_user === $user_id && $other['id'] === $watch['id'];

				if ( $same_watch || $other['root'] !== $watch['root'] || (int) $other['remote'] !== (int) $watch['remote'] ) {
					continue;
				}

				if ( (int) $other['comment'] > 0 ) {
					$taken[ (int) $other['comment'] ] = true;
				} elseif ( ! self::expired( $other ) && ! self::site_gone( $other ) ) {
					$claims[] = self::claim( (int) $other_user, $other, false );
				}
			}
		}

		return array(
			'taken'  => $taken,
			'claims' => $claims,
		);
	}

	/**
	 * What a watch still looking for its comment matches by.
	 *
	 * @param int                  $user_id User the watch belongs to.
	 * @param array<string, mixed> $watch   Watch.
	 * @param bool                 $is_self Whether it's the watch being checked.
	 * @return array{self: bool, user: int, id: string, blog: int, created: int, variants: string[], cut: bool}
	 */
	private static function claim( int $user_id, array $watch, bool $is_self ): array {
		$excerpt = (string) $watch['excerpt'];

		return array(
			'self'     => $is_self,
			'user'     => $user_id,
			'id'       => (string) $watch['id'],
			'blog'     => self::blog_of( $watch ),
			'created'  => (int) $watch['created'],
			'variants' => self::match_variants( $excerpt ),
			// The excerpt was shortened, so only its start can match.
			'cut'      => '…' === mb_substr( $excerpt, -1 ) && mb_strlen( $excerpt ) >= self::EXCERPT_CHARS - 1,
		);
	}

	/**
	 * A comment's text as sent, and as a WordPress site may show it: with
	 * WordPress's default typography (× in "2x4", ™ for "(tm)"), with
	 * WordPress's default smileys as emoji, with both, or with neither,
	 * each reduced by match_text(). The original site's settings aren't
	 * known, and needn't match this site's.
	 *
	 * @param string $excerpt Plain text of the comment as sent.
	 * @return string[]
	 */
	private static function match_variants( string $excerpt ): array {
		$escaped    = htmlspecialchars( $excerpt, ENT_NOQUOTES, 'UTF-8' );
		$texturized = self::texturize_by_default( $escaped );
		$variants   = array(
			self::match_text( $excerpt ),
			self::match_text( self::smilies_by_default( $escaped ) ),
			self::match_text( $texturized ),
			self::match_text( self::smilies_by_default( $texturized ) ),
		);

		return array_values( array_unique( array_filter( $variants, 'strlen' ) ) );
	}

	/**
	 * The parts of WordPress's default typography that still show after
	 * match_text() drops punctuation: × between numbers (where "2x4"
	 * becomes "2 4") and ™ for "(tm)". Applied as wptexturize() applies
	 * them, also when this site turned typography off. Curly quotes,
	 * dashes, and ellipses are left out, since match_text() drops them
	 * anyway.
	 *
	 * @param string $text Escaped text.
	 * @return string
	 */
	private static function texturize_by_default( string $text ): string {
		$text = str_replace( ' (tm)', ' &#8482;', $text );

		// 9x9 (times), but never 0x9999, as wptexturize() does.
		if ( preg_match( '/(?<=\d)x\d/', $text ) ) {
			$text = (string) preg_replace( '/\b(\d(?(?<=0)[\d\.,]+|[\d\.,]*))x(\d[\d\.,]*)\b/', '$1&#215;$2', $text );
		}

		return $text;
	}

	/**
	 * Smileys as a WordPress site shows them by default (most as emoji):
	 * core's own list, also when this site turned smileys off or changed
	 * its list.
	 *
	 * @param string $text Escaped text.
	 * @return string
	 */
	private static function smilies_by_default( string $text ): string {
		global $wp_smiliessearch, $wpsmiliestrans;

		$search = $wp_smiliessearch;
		$trans  = $wpsmiliestrans;
		$list   = array();
		$on     = static function (): string {
			return '1';
		};
		// smilies_init() passes core's list to the filter first; keep it,
		// whatever this site's own filters do to it.
		$keep_core = static function ( $smilies ) use ( &$list ) {
			$list = is_array( $smilies ) ? $smilies : array();
			return $smilies;
		};
		$use_core  = static function () use ( &$list ): array {
			return $list;
		};

		add_filter( 'pre_option_use_smilies', $on, PHP_INT_MAX );
		add_filter( 'smilies', $keep_core, PHP_INT_MIN );
		add_filter( 'smilies', $use_core, PHP_INT_MAX );

		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Built again from core's list, restored below.
			$wp_smiliessearch = null;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Built again from core's list, restored below.
			$wpsmiliestrans = null;

			smilies_init();

			return convert_smilies( $text );
		} finally {
			remove_filter( 'pre_option_use_smilies', $on, PHP_INT_MAX );
			remove_filter( 'smilies', $keep_core, PHP_INT_MIN );
			remove_filter( 'smilies', $use_core, PHP_INT_MAX );

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored as found.
			$wp_smiliessearch = $search;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored as found.
			$wpsmiliestrans = $trans;
		}
	}

	/**
	 * How well a comment's text matches one of a watch's variants: the
	 * whole text, or (for ten characters or more) its start followed by
	 * more words. 0 means no match; a longer match scores higher, and a
	 * whole-text match beats a start of the same length.
	 *
	 * @param string   $text     The comment on the site, reduced by match_text().
	 * @param string[] $variants The watch's variants.
	 * @param bool     $cut      Whether the watch's excerpt was shortened.
	 * @return int
	 */
	private static function match_score( string $text, array $variants, bool $cut ): int {
		$best = 0;

		foreach ( $variants as $variant ) {
			$length = mb_strlen( $variant );

			if ( $text === $variant ) {
				$best = max( $best, 2 * $length + 1 );
				continue;
			}

			if ( $length >= self::MIN_MATCH && str_starts_with( $text, $variant . ' ' ) ) {
				$best = max( $best, 2 * $length );
				continue;
			}

			// A shortened excerpt may end mid-word, so leave that word out.
			$space = mb_strrpos( $variant, ' ' );

			if ( $cut && false !== $space ) {
				$start = mb_substr( $variant, 0, $space );

				if ( mb_strlen( $start ) >= self::MIN_MATCH && str_starts_with( $text, $start . ' ' ) ) {
					$best = max( $best, 2 * mb_strlen( $start ) );
				}
			}
		}

		return $best;
	}

	/**
	 * The people with watched comments, on any site of the network, each
	 * once (the query returns a person once per watch).
	 *
	 * @return int[]
	 */
	private static function watcher_ids(): array {
		$ids = get_users(
			array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only people with watched comments; personal-site scale.
				'meta_key'     => self::META,
				'meta_compare' => 'EXISTS',
			)
		);

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Push the replies one check found.
	 *
	 * @param int                              $user_id User.
	 * @param array<string, mixed>             $watch   Watch.
	 * @param array<int, array<string, mixed>> $found   New replies.
	 * @return void
	 */
	private static function push( int $user_id, array $watch, array $found ): void {
		$count   = count( $found );
		$origin  = '' !== (string) $watch['title'] ? (string) $watch['title'] : (string) $watch['site'];
		$post_id = self::live_post_id( (int) $watch['post'] );

		if ( 1 === $count ) {
			$name = '' !== (string) $found[0]['author'] ? (string) $found[0]['author'] : __( 'Someone', 'daymark' );
			$body = sprintf(
				/* translators: 1: commenter's name, 2: the comment text. */
				__( '%1$s: %2$s', 'daymark' ),
				$name,
				(string) $found[0]['text']
			);
		} else {
			$body = sprintf(
				/* translators: %d: how many new replies. */
				_n( '%d new reply', '%d new replies', $count, 'daymark' ),
				$count
			);
		}

		Daymark_Push::notify_user(
			$user_id,
			'comments',
			array(
				'title' => sprintf(
					/* translators: %s: the original post's title, or its site when it has none. */
					__( 'New reply to your comment on %s', 'daymark' ),
					$origin
				),
				'body'  => $body,
				'url'   => $post_id > 0
					? add_query_arg(
						array(
							'daymark_post'    => $post_id,
							'daymark_replies' => 1,
						),
						Daymark_Routes::app_url()
					)
					: Daymark_Routes::app_url( 'notifications' ),
				'tag'   => 'comment-reply-' . $watch['id'],
			)
		);
	}

	/**
	 * A watch's replies that belong in Notifications: not ones that reached
	 * the Mark as an approved comment too, when Notifications lists that
	 * Mark's comments (see superseded()), and none once the watch is past
	 * the time it's kept (before the next check removes it).
	 *
	 * @param int                  $user_id User the watch belongs to.
	 * @param array<string, mixed> $watch   Watch.
	 * @return array<int, array<string, mixed>>
	 */
	private static function visible_replies( int $user_id, array $watch ): array {
		if ( array() === $watch['replies'] || self::expired( $watch ) ) {
			return array();
		}

		$local   = Daymark_Notifications::lists_mark_for( $user_id, (int) $watch['mark'] ) ? self::local_reply_keys( (int) $watch['mark'] ) : array();
		$replies = array();

		foreach ( $watch['replies'] as $reply ) {
			if ( ! is_array( $reply ) || ! empty( $reply['local'] ) ) {
				continue;
			}

			$key = self::reply_key( (string) ( $reply['url'] ?? '' ) );

			if ( '' !== $key && isset( $local[ $key ] ) ) {
				continue;
			}

			$replies[] = $reply;
		}

		return $replies;
	}

	/**
	 * The approved Webmention comments on a Mark, by reply key (see
	 * reply_key()).
	 *
	 * @param int $mark_id Mark post ID.
	 * @return array<string, int[]> Reply key => comment IDs.
	 */
	private static function local_reply_keys( int $mark_id ): array {
		if ( $mark_id <= 0 ) {
			return array();
		}

		if ( isset( self::$local_keys[ $mark_id ] ) ) {
			return self::$local_keys[ $mark_id ];
		}

		$keys = array();

		// Only types Notifications lists, so a found reply is never hidden
		// behind a comment that isn't shown there either.
		$comment_ids = get_comments(
			array(
				'post_id'  => $mark_id,
				'status'   => 'approve',
				'type'     => Daymark_Notifications::COMMENT_TYPES,
				'fields'   => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One Mark's comments.
				'meta_key' => 'webmention_source_url',
			)
		);

		foreach ( $comment_ids as $comment_id ) {
			$key = self::reply_key( (string) get_comment_meta( (int) $comment_id, 'webmention_source_url', true ) );

			if ( '' !== $key ) {
				$keys[ $key ][] = (int) $comment_id;
			}
		}

		self::$local_keys[ $mark_id ] = $keys;

		return $keys;
	}

	/**
	 * Forget the cached Webmention comments per Mark and the remembered
	 * links. Runs whenever a comment or its meta changes (see register()),
	 * and in tests.
	 *
	 * @return void
	 */
	public static function flush_local_keys(): void {
		self::$local_keys   = array();
		self::$stored_links = null;
	}

	/**
	 * Keep a forgotten reply's place in Notifications on its Webmention
	 * copy.
	 *
	 * Daymark forgets a found reply when its watch is pushed out by newer
	 * ones, when the watch is removed after the time it's kept, or when the
	 * watch keeps only its newest replies. If the reply also reached the
	 * comment's Mark as a Webmention comment, that comment took (or, once
	 * approved, takes) the reply's place in Notifications. So the place
	 * doesn't change when the reply is forgotten, this copies the reply's
	 * read and archived state to the comment (see
	 * Daymark_Notification_State::carry_over()), and stores the reply's
	 * notification ID and the time Daymark found it on the comment, for as
	 * long as that time still changes whether it's new or read (see
	 * link_matters()). A comment held for moderation always gets them,
	 * since it isn't listed yet; an approved one is also marked as pushed,
	 * so approving it again later never pushes the reply a second time.
	 *
	 * @param int                              $user_id The watch's owner.
	 * @param array<string, mixed>             $watch   The watch.
	 * @param array<int, array<string, mixed>> $replies The replies being forgotten.
	 * @return void
	 */
	private static function remember_links( int $user_id, array $watch, array $replies ): void {
		$mark_id = (int) $watch['mark'];

		if ( $mark_id <= 0 || $user_id <= 0 || array() === $replies ) {
			return;
		}

		$wanted = array();

		foreach ( $replies as $reply ) {
			if ( ! is_array( $reply ) || ! empty( $reply['local'] ) ) {
				continue;
			}

			$key = self::reply_key( (string) ( $reply['url'] ?? '' ) );

			if ( '' === $key || isset( $wanted[ $key ] ) ) {
				continue;
			}

			$wanted[ $key ] = array(
				'from'  => Daymark_Notifications::item_id(
					array(
						'type'      => 'comment_reply',
						'reply_key' => self::item_key( $watch, $reply ),
					)
				),
				'found' => absint( $reply['found'] ?? 0 ),
			);
		}

		if ( array() === $wanted ) {
			return;
		}

		// Held comments too: one approved later still takes the reply's
		// place.
		$comments = get_comments(
			array(
				'post_id'  => $mark_id,
				'status'   => 'all',
				'type'     => Daymark_Notifications::COMMENT_TYPES,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One Mark's comments.
				'meta_key' => 'webmention_source_url',
			)
		);
		$carried  = array();

		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			$comment_id = (int) $comment->comment_ID;
			$key        = self::reply_key( (string) get_comment_meta( $comment_id, 'webmention_source_url', true ) );

			if ( '' === $key || ! isset( $wanted[ $key ] ) ) {
				continue;
			}

			$link  = $wanted[ $key ];
			$links = get_comment_meta( $comment_id, self::FOUND_META, true );
			$links = is_array( $links ) ? $links : array();
			$held  = '1' !== (string) $comment->comment_approved;

			if ( ! isset( $links[ $user_id ] )
				&& ( $held || self::link_matters( $user_id, $link['found'], (int) strtotime( $comment->comment_date_gmt . ' UTC' ) ) ) ) {
				$links[ $user_id ] = $link;
				update_comment_meta( $comment_id, self::FOUND_META, wp_slash( $links ) );
			}

			if ( ! $held && ! get_comment_meta( $comment_id, Daymark_Push::COMMENT_SENT_META, true ) ) {
				update_comment_meta( $comment_id, Daymark_Push::COMMENT_SENT_META, '1' );
			}

			$carried[ Daymark_Notifications::item_id(
				array(
					'type'       => 'comment',
					'comment_ID' => $comment_id,
				)
			) ] = $link['from'];
		}

		if ( array() !== $carried ) {
			Daymark_Plugin::instance()->notification_state->carry_over( $user_id, $carried );
		}
	}

	/**
	 * The approved comments that keep a forgotten reply's place (see
	 * remember_links()): each one's Mark, its own date, and its links by
	 * user ID.
	 *
	 * @return array<int, array{post: int, own: int, links: array<int, mixed>}>
	 */
	private static function stored_links(): array {
		if ( null !== self::$stored_links ) {
			return self::$stored_links;
		}

		$stored   = array();
		$comments = get_comments(
			array(
				'status'   => 'approve',
				'type'     => Daymark_Notifications::COMMENT_TYPES,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only comments that keep a forgotten reply's place.
				'meta_key' => self::FOUND_META,
			)
		);

		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			$links = get_comment_meta( (int) $comment->comment_ID, self::FOUND_META, true );

			if ( is_array( $links ) && array() !== $links ) {
				$stored[ (int) $comment->comment_ID ] = array(
					'post'  => (int) $comment->comment_post_ID,
					'own'   => (int) strtotime( $comment->comment_date_gmt . ' UTC' ),
					'links' => $links,
				);
			}
		}

		self::$stored_links = $stored;

		return $stored;
	}

	/**
	 * Whether dating a comment by when Daymark found its reply, rather than
	 * by its own date, still changes whether Notifications shows it as new
	 * or as read for this user. Both dates are past and the cutoffs only
	 * ever move to the present, so once this is false it stays false.
	 *
	 * @param int $user_id User.
	 * @param int $found   When Daymark found the reply.
	 * @param int $own     The comment's own date.
	 * @return bool
	 */
	private static function link_matters( int $user_id, int $found, int $own ): bool {
		if ( $found <= 0 || $own <= 0 || $found === $own ) {
			return false;
		}

		$earlier = min( $found, $own );
		$later   = max( $found, $own );
		$cutoffs = array(
			(int) get_user_meta( $user_id, Daymark_Notifications::SEEN_META, true ),
			Daymark_Plugin::instance()->notification_state->read_before( $user_id ),
		);

		foreach ( $cutoffs as $cutoff ) {
			if ( $earlier <= $cutoff && $cutoff < $later ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop the links (see remember_links()) that no longer change anything:
	 * their user is gone, or both dates now show the same way (see
	 * link_matters()). Runs after each check run. A comment held for
	 * moderation keeps its links until it's approved, since only approved
	 * comments are looked at here.
	 *
	 * @return void
	 */
	private static function forget_stale_links(): void {
		foreach ( self::stored_links() as $comment_id => $stored ) {
			$keep = array();

			foreach ( $stored['links'] as $link_user => $link ) {
				if ( is_array( $link ) && get_userdata( (int) $link_user )
					&& self::link_matters( (int) $link_user, absint( $link['found'] ?? 0 ), $stored['own'] ) ) {
					$keep[ $link_user ] = $link;
				}
			}

			if ( array() === $keep ) {
				delete_comment_meta( $comment_id, self::FOUND_META );
			} elseif ( count( $keep ) < count( $stored['links'] ) ) {
				update_comment_meta( $comment_id, self::FOUND_META, wp_slash( $keep ) );
			}
		}

		self::$stored_links = null;
	}

	/**
	 * A found reply's key in its notification ID: the watch, then the
	 * reply's ID on the original site.
	 *
	 * @param array<string, mixed> $watch Watch.
	 * @param array<string, mixed> $reply Stored reply.
	 * @return string
	 */
	private static function item_key( array $watch, array $reply ): string {
		return sanitize_key( $watch['id'] . '-' . (string) ( $reply['id'] ?? '' ) );
	}

	/**
	 * The followed post's ID if it's still there to open; else 0.
	 *
	 * @param int $post_id `daymark_sub_post` ID.
	 * @return int
	 */
	private static function live_post_id( int $post_id ): int {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		return $post instanceof WP_Post && Daymark_Subscription_Post_Type::POST_TYPE === $post->post_type && 'publish' === $post->post_status
			? $post_id
			: 0;
	}

	/**
	 * How long to wait between checks of a comment this old.
	 *
	 * @param int                  $age   Seconds since the comment.
	 * @param array<string, mixed> $watch Watch.
	 * @return int Seconds.
	 */
	private static function interval( int $age, array $watch ): int {
		if ( $age < 2 * DAY_IN_SECONDS ) {
			$interval = 55 * MINUTE_IN_SECONDS;
		} elseif ( $age < WEEK_IN_SECONDS ) {
			$interval = 6 * HOUR_IN_SECONDS;
		} else {
			$interval = DAY_IN_SECONDS;
		}

		/**
		 * Filters how long Daymark waits between checks of one comment for
		 * replies.
		 *
		 * @since 0.22.0
		 *
		 * @param int                  $interval Seconds.
		 * @param int                  $age      Seconds since the comment was sent.
		 * @param array<string, mixed> $watch    The watched comment.
		 */
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'daymark_comment_reply_check_interval', $interval, $age, $watch ) );
	}

	/**
	 * How long after a comment Daymark keeps checking it.
	 *
	 * @return int Seconds.
	 */
	private static function window(): int {
		/**
		 * Filters how many days Daymark keeps checking a comment for
		 * replies.
		 *
		 * @since 0.22.0
		 *
		 * @param int $days Default 30.
		 */
		return max( 1, (int) apply_filters( 'daymark_comment_reply_watch_days', 30 ) ) * DAY_IN_SECONDS;
	}

	/**
	 * Whether a URL is this site's: 'own' for its home address or a page
	 * under it; 'root' for only the host, when this site's home has a path
	 * (a site in a subdirectory, which shares its host); '' otherwise,
	 * including another site's page on the same host. Ports and schemes
	 * aren't compared. A 'root' address can still find your comment by its
	 * words, but a reply signed with one is never skipped as your own.
	 *
	 * @param string $url  URL.
	 * @param int    $blog Read it as this site of the network does (0 for
	 *                     this site).
	 * @return string 'own', 'root', or ''.
	 */
	private static function own_url_kind( string $url, int $blog = 0 ): string {
		$blog = $blog > 0 ? $blog : get_current_blog_id();
		$home = is_multisite() && get_current_blog_id() !== $blog ? get_home_url( $blog, '/' ) : home_url( '/' );
		$host = self::host_key( $url );

		if ( '' === $host || self::host_key( $home ) !== $host ) {
			return '';
		}

		$path      = self::path_key( $url );
		$home_path = self::path_key( $home );

		if ( '' !== $home_path ) {
			if ( '' === $path ) {
				return 'root';
			}

			if ( $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
				return '';
			}
		}

		// On a network of sites in subdirectories, a page under this
		// site's address can still be a deeper site's (the main site's
		// address is the start of every other site's).
		if ( is_multisite() && ! is_subdomain_install() ) {
			$site = get_site_by_path( (string) wp_parse_url( $home, PHP_URL_HOST ), '' === $path ? '/' : $path . '/' );

			if ( $site instanceof WP_Site && $blog !== (int) $site->blog_id ) {
				return '';
			}
		}

		return 'own';
	}

	/**
	 * A URL's path, lowercased and decoded, without a trailing slash ('' for
	 * the host alone).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path_key( string $url ): string {
		return untrailingslashit( strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
	}

	/**
	 * A URL's host, lowercased, without a leading "www.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_key( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Text reduced for comparing a comment as sent with the same comment
	 * as a site shows it: lowercase letters, numbers, and emoji, one space
	 * between words, including between paragraphs.
	 *
	 * @param string $html Text or HTML.
	 * @return string
	 */
	private static function match_text( string $html ): string {
		$html = (string) preg_replace( '#<(?:br|hr|/?(?:p|div|li|ul|ol|blockquote|h[1-6]|pre|figure|figcaption|tr|td|th|table))\b[^>]*>#i', ' $0 ', $html );
		$text = (string) preg_replace( '/[^\p{L}\p{M}\p{N}\p{So}]+/u', ' ', Daymark_Remote_Replies::plain( $html, 400 ) );

		return trim( mb_strtolower( $text ) );
	}
}
