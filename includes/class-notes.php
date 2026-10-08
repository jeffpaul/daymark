<?php
/**
 * Notes on the site itself: where they show, and their titles.
 *
 * A Note is an ordinary `post` with the Aside post format. Daymark's own
 * Note Marks use that format (Daymark_Publisher::TYPE_POST_FORMATS), and so
 * can a post written in the block editor. Notes deliberately stay `post`
 * rather than a custom post type, so they keep working if Daymark is ever
 * removed (Product Principle 8 in CLAUDE.md).
 *
 * This class does two things for every Aside post, wherever it was
 * written:
 *
 * - When Settings -> Daymark -> General turns on "Keep Notes off your
 *   blog's home page and main feed" (Daymark_Settings::hide_notes_on_home(),
 *   off by default), the main query for the home page and the main RSS/Atom feed leaves
 *   Aside posts out. Nothing else changes: each Note's own page, every
 *   archive (including the Aside format archive), search, the REST API,
 *   the sitemap, and Daymark's Timeline still include them.
 * - A Note published with no title gets one, built the same way the app
 *   titles a Note Mark (Daymark_Publisher::generate_title()). Without it,
 *   an untitled Note shows as "(no title)" in wp-admin, has its post ID as
 *   its slug, and has an empty title in feeds. A title someone typed is
 *   never replaced, and drafts are left alone so the title reflects the
 *   Note as published.
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Home page/feed visibility and automatic titles for Aside-format posts.
 */
class Daymark_Notes {

	/**
	 * The post format that marks a post as a Note.
	 */
	public const POST_FORMAT = 'aside';

	/**
	 * Statuses at which an untitled Note gets a title.
	 */
	private const TITLED_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * ID of the post a REST request is about to save, so fill_title()
	 * leaves that save to fill_rest_title(). Null when none is pending.
	 *
	 * @var int|null
	 */
	private ?int $rest_post_id = null;

	/**
	 * Hook up. Called from Daymark_Plugin::on_init().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'exclude_from_home_and_feed' ) );
		add_filter( 'rest_pre_insert_post', array( $this, 'fill_rest_title' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'fill_title' ), 10, 2 );
	}

	/**
	 * Leave Notes out of the home page and the main feed when the site
	 * owner has chosen to keep them off there.
	 *
	 * Only the main query is changed. A block theme's home template uses
	 * the main query (a Query Loop block set to inherit it), so it follows
	 * this setting; a Query Loop with its own query does not.
	 *
	 * @param WP_Query $query The query about to run.
	 * @return void
	 */
	public function exclude_from_home_and_feed( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! Daymark_Settings::hide_notes_on_home() ) {
			return;
		}

		if ( ! $query->is_home() && ! self::is_main_feed( $query ) ) {
			return;
		}

		$exclusion = array(
			'taxonomy' => 'post_format',
			'field'    => 'slug',
			'terms'    => array( 'post-format-' . self::POST_FORMAT ),
			'operator' => 'NOT IN',
		);

		$existing = $query->get( 'tax_query' );

		$query->set(
			'tax_query',
			is_array( $existing ) && ! empty( $existing )
				? array(
					'relation' => 'AND',
					$existing,
					$exclusion,
				)
				: array( $exclusion )
		);
	}

	/**
	 * Whether a query is the site's main posts feed (`/feed/`), not a
	 * category, tag, author, search, comment, or single-post feed.
	 *
	 * @param WP_Query $query Query to check.
	 * @return bool
	 */
	private static function is_main_feed( WP_Query $query ): bool {
		return $query->is_feed()
			&& ! $query->is_comment_feed()
			&& ! $query->is_singular()
			&& ! $query->is_archive()
			&& ! $query->is_search();
	}

	/**
	 * Give an untitled Note a title when the block editor publishes it.
	 *
	 * The REST API sets a post's format only after inserting the post, so
	 * the format comes from the request here, falling back to the post's
	 * saved format when the request doesn't change it.
	 *
	 * @param stdClass|WP_Error $prepared_post Post about to be inserted or updated.
	 * @param WP_REST_Request   $request       The REST request.
	 * @return stdClass|WP_Error
	 */
	public function fill_rest_title( $prepared_post, $request ) {
		if ( ! $prepared_post instanceof stdClass || ! $request instanceof WP_REST_Request ) {
			return $prepared_post;
		}

		$this->rest_post_id = (int) ( $prepared_post->ID ?? 0 );

		$existing = ! empty( $prepared_post->ID ) ? get_post( (int) $prepared_post->ID ) : null;
		$format   = ! empty( $request['format'] )
			? (string) $request['format']
			: ( $existing ? (string) get_post_format( $existing ) : '' );
		$title    = isset( $prepared_post->post_title )
			? (string) $prepared_post->post_title
			: ( $existing ? $existing->post_title : '' );
		$status   = isset( $prepared_post->post_status )
			? (string) $prepared_post->post_status
			: ( $existing ? $existing->post_status : 'draft' );
		$content  = isset( $prepared_post->post_content )
			? (string) $prepared_post->post_content
			: ( $existing ? $existing->post_content : '' );

		if ( self::needs_title( $format, $title, $status, $existing ? $existing->ID : 0 ) ) {
			$prepared_post->post_title = self::title_for( $content );
		}

		return $prepared_post;
	}

	/**
	 * Give an untitled Note a title when it's published any other way: the
	 * classic editor, Quick Edit, or code calling wp_insert_post().
	 *
	 * A save made by a REST request is left to fill_rest_title(), because
	 * during a REST update the saved format can be the one the request is
	 * replacing.
	 *
	 * @param array<string, mixed> $data    Slashed post data about to be saved.
	 * @param array<string, mixed> $postarr Slashed, sanitized data passed to wp_insert_post().
	 * @return array<string, mixed>
	 */
	public function fill_title( array $data, array $postarr ): array {
		$post_id = (int) ( $postarr['ID'] ?? 0 );

		if ( null !== $this->rest_post_id && $this->rest_post_id === $post_id ) {
			$this->rest_post_id = null;
			return $data;
		}

		if ( 'post' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$format = isset( $postarr['post_format'] )
			? (string) $postarr['post_format']
			: ( $post_id ? (string) get_post_format( $post_id ) : '' );
		$title  = wp_unslash( (string) ( $data['post_title'] ?? '' ) );

		if ( self::needs_title( $format, $title, (string) ( $data['post_status'] ?? '' ), $post_id ) ) {
			$data['post_title'] = wp_slash( self::title_for( wp_unslash( (string) ( $data['post_content'] ?? '' ) ) ) );
		}

		return $data;
	}

	/**
	 * Whether a post being saved is an untitled Note that should get a title.
	 *
	 * @param string $format  The post's format.
	 * @param string $title   The title it will be saved with.
	 * @param string $status  The status it will be saved with.
	 * @param int    $post_id Post ID, or 0 for a new post.
	 * @return bool
	 */
	private static function needs_title( string $format, string $title, string $status, int $post_id ): bool {
		if ( self::POST_FORMAT !== $format || '' !== trim( $title ) || ! in_array( $status, self::TITLED_STATUSES, true ) ) {
			return false;
		}

		/**
		 * Whether to give an untitled Note (an Aside-format post) a title
		 * when it's published.
		 *
		 * @since 0.20.0
		 *
		 * @param bool $fill    Default true.
		 * @param int  $post_id Post ID, or 0 for a post not yet created.
		 */
		return (bool) apply_filters( 'daymark_fill_note_title', true, $post_id );
	}

	/**
	 * A title for a Note from its content: its first words, or the date and
	 * time when it has no text (Daymark_Publisher::generate_title()).
	 *
	 * Tags become spaces first, so words in neighboring blocks or paragraphs
	 * don't run together.
	 *
	 * @param string $content Post content, usually block markup.
	 * @return string
	 */
	public static function title_for( string $content ): string {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $content );
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );

		return Daymark_Publisher::generate_title( $text );
	}
}
