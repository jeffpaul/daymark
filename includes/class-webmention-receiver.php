<?php
/**
 * Built-in Webmention: receiving.
 *
 * The other half of Daymark_Webmention, on only while that class's
 * builtin_active() is true (no Webmention plugin). It advertises an
 * endpoint on every front-end page, accepts `source`/`target` POSTs at
 * `POST /daymark/v1/webmention`, and verifies each one later in WP-Cron,
 * as the specification recommends: fetch the source, check it really links
 * to the target, and read what it is.
 *
 * What it stores is the same as the Webmention plugin stores, so the rest
 * of Daymark reads it with no changes: a WordPress comment on the target
 * post with comment meta `protocol` = 'webmention' and
 * `webmention_source_url` (Daymark_Federated_Comments labels it, and
 * Notifications lists a reply), and comment type 'like', 'repost', or
 * 'comment' (a reply), which the Timeline's like, reblog, and comment
 * counts already read. A source that links to the target with no
 * like/reblog/reply markup is stored as a 'mention'.
 *
 * Moderation follows the site's own Settings -> Discussion rules through
 * core's check_comment() and disallowed-words list, then the
 * `pre_comment_approved` filter that anti-spam plugins use. A source that
 * stops linking to the target, or answers 404 or 410, removes its comment.
 *
 * Abuse limits: the endpoint is public, as Webmention requires, so it
 * checks everything it can without a request (the target is a real public
 * post here with comments open), caps queued verifications per source host
 * (`daymark_webmention_receive_limit`, default 30 an hour), and fetches the
 * source through the same URL guard, redirect guard, size cap, and timeout
 * as every other outbound request.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daymark's own Webmention receiver.
 */
class Daymark_Webmention_Receiver {

	/**
	 * Cron hook that verifies one received Webmention.
	 *
	 * @var string
	 */
	public const CRON_VERIFY = 'daymark_webmention_verify';

	/**
	 * Largest source page read, in bytes.
	 *
	 * @var int
	 */
	private const MAX_SOURCE_BYTES = 1048576;

	/**
	 * Longest stored reply, in characters.
	 *
	 * @var int
	 */
	private const MAX_CONTENT_CHARS = 5000;

	/**
	 * Reference classes, mapped to the comment type they make. Checked in
	 * this order, so a reply that also likes counts as a reply.
	 *
	 * @var array<string, string>
	 */
	private const REFERENCE_TYPES = array(
		'u-in-reply-to' => 'comment',
		'u-repost-of'   => 'repost',
		'u-like-of'     => 'like',
	);

	/**
	 * Hook up. Every hook checks builtin_active() itself, because the
	 * Webmention plugin can be activated after this runs.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_head', array( $this, 'print_link_tag' ), 1 );
		add_action( 'template_redirect', array( $this, 'send_link_header' ) );
		add_action( self::CRON_VERIFY, array( $this, 'verify' ), 10, 3 );
	}

	/**
	 * Register the endpoint.
	 *
	 * @return void
	 */
	public function register_route(): void {
		if ( ! Daymark_Webmention::builtin_active() ) {
			return;
		}

		register_rest_route(
			'daymark/v1',
			'/webmention',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				// Public by design: any site may send a Webmention.
				'permission_callback' => '__return_true',
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'target' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * `<link rel="webmention">` on every front-end page.
	 *
	 * @return void
	 */
	public function print_link_tag(): void {
		if ( Daymark_Webmention::builtin_active() ) {
			echo '<link rel="webmention" href="' . esc_url( Daymark_Webmention::endpoint_url() ) . '" />' . "\n";
		}
	}

	/**
	 * The same endpoint as an HTTP `Link` header, which senders check first.
	 *
	 * @return void
	 */
	public function send_link_header(): void {
		if ( Daymark_Webmention::builtin_active() && ! headers_sent() ) {
			header( 'Link: <' . esc_url_raw( Daymark_Webmention::endpoint_url() ) . '>; rel="webmention"', false );
		}
	}

	/**
	 * Accept a Webmention: check what can be checked without a request,
	 * then queue the verification. Answers 202, as the specification allows
	 * for asynchronous processing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( WP_REST_Request $request ) {
		$source = esc_url_raw( trim( (string) $request->get_param( 'source' ) ), array( 'http', 'https' ) );
		$target = esc_url_raw( trim( (string) $request->get_param( 'target' ) ), array( 'http', 'https' ) );

		if ( '' === $source || '' === $target || '' === (string) wp_parse_url( $source, PHP_URL_HOST ) ) {
			return self::error( 'daymark_webmention_invalid_url', __( 'Source and target must be http or https URLs.', 'daymark' ) );
		}

		if ( Daymark_Webmention::same_url( $source, $target ) ) {
			return self::error( 'daymark_webmention_same_url', __( 'Source and target must be different.', 'daymark' ) );
		}

		$post_id = self::target_post_id( $target );

		if ( 0 === $post_id ) {
			return self::error( 'daymark_webmention_invalid_target', __( 'The target is not a post on this site that accepts Webmentions.', 'daymark' ) );
		}

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $source ) ) ) {
			return self::error( 'daymark_webmention_invalid_source', __( 'The source could not be safely reached.', 'daymark' ) );
		}

		$args = array( $source, $target, $post_id );

		if ( false === wp_next_scheduled( self::CRON_VERIFY, $args ) ) {
			if ( ! self::within_limit( $source ) ) {
				return new WP_Error(
					'daymark_webmention_rate_limited',
					__( 'Too many Webmentions from this site. Try again later.', 'daymark' ),
					array( 'status' => 429 )
				);
			}

			wp_schedule_single_event( time(), self::CRON_VERIFY, $args );
		}

		return new WP_REST_Response( array( 'status' => 'accepted' ), 202 );
	}

	/**
	 * Verify one Webmention and create, update, or remove its comment.
	 *
	 * @param string $source  Source URL.
	 * @param string $target  Target URL.
	 * @param int    $post_id Target post ID.
	 * @return void
	 */
	public function verify( $source, $target, $post_id ): void {
		$source  = (string) $source;
		$target  = (string) $target;
		$post_id = absint( $post_id );

		if ( ! Daymark_Webmention::builtin_active() || self::target_post_id( $target ) !== $post_id ) {
			return;
		}

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $source ) ) ) {
			return;
		}

		$response = Daymark_Outbound_Guard::get(
			$source,
			array(
				'timeout'             => (int) apply_filters( 'daymark_webmention_verify_timeout', 10 ),
				'redirection'         => 5,
				'limit_response_size' => (int) apply_filters( 'daymark_webmention_max_source_bytes', self::MAX_SOURCE_BYTES ),
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// The source is gone: its mention goes too.
		if ( 404 === $code || 410 === $code ) {
			self::remove( $post_id, $source );
			return;
		}

		if ( $code < 200 || $code >= 300 ) {
			return;
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( ! self::links_to( $html, $source, $target ) ) {
			self::remove( $post_id, $source );
			return;
		}

		self::store( $post_id, $source, $target, self::read_source( $html, $source, $target ) );
	}

	/**
	 * The local post a target URL names, when it's a published, public post
	 * with comments open; else 0.
	 *
	 * @param string $target Target URL.
	 * @return int
	 */
	public static function target_post_id( string $target ): int {
		$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );

		if ( '' === $host || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== $host ) {
			return 0;
		}

		$post_id = url_to_postid( $target );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if (
			! $post instanceof WP_Post
			|| 'publish' !== $post->post_status
			|| '' !== (string) $post->post_password
			|| Daymark_Subscription_Post_Type::POST_TYPE === $post->post_type
			|| ! is_post_type_viewable( $post->post_type )
			|| ! comments_open( $post )
		) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * Whether the source page links to the target (any `href` or `src`).
	 *
	 * @param string $html   Source HTML.
	 * @param string $source Source URL, to resolve relative links.
	 * @param string $target Target URL.
	 * @return bool
	 */
	private static function links_to( string $html, string $source, string $target ): bool {
		foreach ( self::linked_urls( $html, $source ) as $url ) {
			if ( Daymark_Webmention::same_url( $url['url'], $target ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every link in some HTML, absolute, with the element's class.
	 *
	 * @param string $html   HTML.
	 * @param string $source Base URL.
	 * @return array<int, array{url: string, class: string}>
	 */
	private static function linked_urls( string $html, string $source ): array {
		$links = array();

		if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $links;
		}

		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			foreach ( array( 'href', 'src' ) as $attribute ) {
				$value = trim( (string) ( $processor->get_attribute( $attribute ) ?? '' ) );

				if ( '' !== $value && '#' !== $value[0] ) {
					$links[] = array(
						'url'   => WP_Http::make_absolute_url( $value, $source ),
						'class' => (string) ( $processor->get_attribute( 'class' ) ?? '' ),
					);
				}
			}
		}

		return $links;
	}

	/**
	 * What the source says about the target: the comment type, its text,
	 * and its author.
	 *
	 * @param string $html   Source HTML.
	 * @param string $source Source URL.
	 * @param string $target Target URL.
	 * @return array{type: string, content: string, author: string, author_url: string}
	 */
	public static function read_source( string $html, string $source, string $target ): array {
		$type = 'mention';

		foreach ( self::REFERENCE_TYPES as $class_token => $comment_type ) {
			foreach ( self::linked_urls( $html, $source ) as $link ) {
				if ( preg_match( '/(^|\s)' . preg_quote( $class_token, '/' ) . '(\s|$)/', $link['class'] ) && Daymark_Webmention::same_url( $link['url'], $target ) ) {
					$type = $comment_type;
					break 2;
				}
			}
		}

		$parser  = new Daymark_Subscription_Source_Microformats();
		$content = '';

		if ( 'comment' === $type || 'mention' === $type ) {
			$content = self::plain_text( $parser->first_class_inner_html( $html, 'e-content' ) );
		}

		$author_html = $parser->first_class_inner_html( $html, 'p-author' );
		$author      = self::plain_text( $parser->first_class_inner_html( $author_html, 'p-name' ) );

		if ( '' === $author ) {
			$author = self::plain_text( $author_html );
		}

		$host = (string) wp_parse_url( $source, PHP_URL_HOST );

		return array(
			'type'       => $type,
			'content'    => mb_substr( $content, 0, self::MAX_CONTENT_CHARS ),
			'author'     => '' !== $author ? mb_substr( $author, 0, 245 ) : $host,
			'author_url' => esc_url_raw( (string) wp_parse_url( $source, PHP_URL_SCHEME ) . '://' . $host . '/' ),
		);
	}

	/**
	 * Store (or update) the comment for a verified Webmention.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param string               $source  Source URL.
	 * @param string               $target  Target URL.
	 * @param array<string, mixed> $read    read_source() result.
	 * @return int Comment ID, or 0.
	 */
	public static function store( int $post_id, string $source, string $target, array $read ): int {
		$type    = (string) $read['type'];
		$content = (string) $read['content'];

		if ( '' === $content ) {
			$content = self::placeholder_text( $type, (string) $read['author'] );
		}

		$data = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => (string) $read['author'],
			'comment_author_email' => '',
			'comment_author_url'   => (string) $read['author_url'],
			'comment_content'      => $content,
			'comment_type'         => $type,
			'comment_parent'       => 0,
			'user_id'              => 0,
			'comment_agent'        => 'Daymark Webmention',
		);

		$existing = self::existing_comment_id( $post_id, $source );

		if ( $existing > 0 ) {
			$data['comment_ID'] = $existing;
			wp_update_comment( wp_slash( $data ) );

			return $existing;
		}

		$approved = check_comment( $data['comment_author'], '', $data['comment_author_url'], $content, '', '', $type ) ? 1 : 0;

		if ( wp_check_comment_disallowed_list( $data['comment_author'], '', $data['comment_author_url'], $content, '', '' ) ) {
			$approved = 'spam';
		}

		// Core's own filter, so anti-spam plugins judge it like any comment.
		$approved = apply_filters( 'pre_comment_approved', $approved, $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.

		if ( is_wp_error( $approved ) ) {
			return 0;
		}

		$data['comment_approved'] = $approved;
		$data['comment_date']     = current_time( 'mysql' );
		$data['comment_date_gmt'] = current_time( 'mysql', true );

		$comment_id = (int) wp_insert_comment( wp_slash( $data ) );

		if ( $comment_id <= 0 ) {
			return 0;
		}

		add_comment_meta( $comment_id, 'protocol', 'webmention', true );
		add_comment_meta( $comment_id, 'webmention_source_url', esc_url_raw( $source ), true );
		add_comment_meta( $comment_id, 'webmention_target_url', esc_url_raw( $target ), true );

		// A reply or mention notifies the post author or moderator the way a
		// typed comment does; a like or reblog only shows in the counts.
		if ( in_array( $type, array( 'comment', 'mention' ), true ) ) {
			// Core's own action, which sends the usual comment emails.
			do_action( 'comment_post', $comment_id, $approved, $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		}

		return $comment_id;
	}

	/**
	 * Remove the comment a source made on a post, if any.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $source  Source URL.
	 * @return void
	 */
	private static function remove( int $post_id, string $source ): void {
		$comment_id = self::existing_comment_id( $post_id, $source );

		if ( $comment_id > 0 ) {
			wp_delete_comment( $comment_id );
		}
	}

	/**
	 * The comment an earlier Webmention from this source made on this post.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $source  Source URL.
	 * @return int
	 */
	private static function existing_comment_id( int $post_id, string $source ): int {
		$ids = get_comments(
			array(
				'post_id'    => $post_id,
				'status'     => 'all',
				'type'       => 'all',
				'fields'     => 'ids',
				'number'     => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One post's comments.
				'meta_query' => array(
					array(
						'key'   => 'webmention_source_url',
						'value' => esc_url_raw( $source ),
					),
				),
			)
		);

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Text for a like, reblog, or mention with no words of its own.
	 *
	 * @param string $type   Comment type.
	 * @param string $author Author name.
	 * @return string
	 */
	private static function placeholder_text( string $type, string $author ): string {
		switch ( $type ) {
			case 'like':
				/* translators: %s: name of the person or site. */
				return sprintf( __( '%s liked this.', 'daymark' ), $author );
			case 'repost':
				/* translators: %s: name of the person or site. */
				return sprintf( __( '%s reblogged this.', 'daymark' ), $author );
			default:
				/* translators: %s: name of the person or site. */
				return sprintf( __( '%s mentioned this.', 'daymark' ), $author );
		}
	}

	/**
	 * Count one queued verification against the source host's hourly limit.
	 *
	 * @param string $source Source URL.
	 * @return bool False when over the limit.
	 */
	private static function within_limit( string $source ): bool {
		$key   = 'daymark_wm_rx_' . md5( strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) ) );
		$count = (int) get_transient( $key );

		/**
		 * Webmentions one source host may queue per hour.
		 *
		 * @param int $limit Default 30.
		 */
		if ( $count >= (int) apply_filters( 'daymark_webmention_receive_limit', 30 ) ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Plain, single-spaced text from HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * A 400 error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
