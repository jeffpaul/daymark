<?php
/**
 * Keeps a Like Mark out of everywhere an ordinary post shows up, except
 * its own direct permalink.
 *
 * A Like Mark (`_daymark_like_of` post meta — see the "Subscribed-post
 * engagement" decision, CLAUDE.md) exists purely to give a federation
 * plugin a real, fetchable `u-like-of` source page to send an outbound
 * Webmention from; it is not content a reader is meant to find.
 *
 * It lives on its own post type, self::POST_TYPE, rather than `post`.
 * The earlier approach (issue #361) kept Like Marks as ordinary posts and
 * filtered them out of each discovery surface one by one — but that could
 * only ever reach the surfaces it knew about: a `pre_get_posts` guard
 * scoped to the main query misses a block theme's Query Loop (a secondary
 * WP_Query), widgets, related-posts plugins, and wp-admin's own Posts
 * list, which is exactly where they kept showing up. A dedicated post type
 * that is not `public`, excluded from search, not in REST, has no archive,
 * no admin UI, no categories/tags/post formats, is simply never part of
 * any query for `post` in the first place — the site's home page, every
 * archive, search, its RSS/Atom feed, `wp/v2/posts`, the XML sitemap,
 * Jetpack Social/Publicize (which only auto-shares post types declaring
 * `publicize` support), and wp-admin's Posts list all leave it out with no
 * per-surface filter needed.
 *
 * It stays `publicly_queryable` so its own permalink (`?daymark_like=slug`
 * — no rewrite rule, so nothing to flush) is still reachable by a plain,
 * unauthenticated GET: the origin site verifies a Webmention by fetching
 * that URL and finding the `u-like-of` markup. It declares `webmentions`
 * post type support so the Webmention plugin still sends for it.
 *
 * Like Marks already stored as `post` (before this change) are moved onto
 * the new post type once, on `init` (maybe_migrate_legacy_likes()), and a
 * request for one's old post permalink 301s to its new URL. The original
 * per-surface filters below are kept as defense in depth for a legacy row
 * the migration hasn't reached yet.
 *
 * Deliberately scoped to `_daymark_like_of` only — a Reblog Mark
 * (`_daymark_repost_of`) is real, user-chosen, publishable content and
 * stays an ordinary, fully visible/syndicated `post`.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excludes Like Marks from public discovery while keeping their permalink live.
 */
class Daymark_Like_Visibility {

	/**
	 * The Like Mark post type.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'daymark_like';

	/**
	 * Option set once every legacy `post`-typed Like Mark has been moved
	 * onto self::POST_TYPE.
	 *
	 * @var string
	 */
	public const MIGRATED_OPTION = 'daymark_like_post_type_migrated';

	/**
	 * Legacy Like Marks moved per request, so a site with many of them
	 * never does it all in one page load.
	 *
	 * @var int
	 */
	private const MIGRATION_BATCH = 100;

	/**
	 * Hook up. Called from Daymark_Plugin::on_init(), which already runs on
	 * `init`, so the post type registers directly here.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_post_type();
		$this->maybe_migrate_legacy_likes();

		add_action( 'template_redirect', array( $this, 'redirect_legacy_permalink' ) );
		add_filter( 'jetpack_sync_prevent_sending_post_data', array( $this, 'prevent_jetpack_sync' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_main_query' ) );
		add_filter( 'rest_post_query', array( $this, 'exclude_from_rest_collection' ), 10, 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'block_rest_single_item' ), 10, 3 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_from_sitemap' ), 10, 2 );
		add_filter( 'oembed_response_data', array( $this, 'suppress_oembed' ), 10, 2 );
		add_filter( 'publicize_should_publicize_published_post', array( $this, 'suppress_publicize' ), 10, 2 );
	}

	/**
	 * Register self::POST_TYPE. See the class docblock for why each flag is
	 * set the way it is.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Likes', 'daymark' ),
				'labels'              => array(
					'name'          => __( 'Likes', 'daymark' ),
					'singular_name' => __( 'Like', 'daymark' ),
				),
				'public'              => false,
				'publicly_queryable'  => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => self::POST_TYPE,
				'exclude_from_search' => true,
				'can_export'          => true,
				'hierarchical'        => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'author', 'custom-fields', 'comments', 'webmentions' ),
			)
		);
	}

	/**
	 * Move a post onto self::POST_TYPE, dropping the category/tag/post
	 * format terms a `post` carried (none of which this post type has).
	 * `set_post_type()` fires no status transition, so nothing re-sends.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function convert_to_like_post_type( int $post_id ): void {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}

		wp_delete_object_term_relationships( $post_id, array( 'category', 'post_tag', 'post_format' ) );
		set_post_type( $post_id, self::POST_TYPE );
	}

	/**
	 * One-time move of every Like Mark still stored as a `post` (created
	 * before this post type existed) onto self::POST_TYPE, in batches.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_likes(): void {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'meta_key'         => '_daymark_like_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration lookup.
				'posts_per_page'   => self::MIGRATION_BATCH,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		// 'any' excludes trash; a trashed Like (the undo path) moves too so
		// a later restore can never bring it back as an ordinary post.
		$trashed = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'trash',
				'meta_key'         => '_daymark_like_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration lookup.
				'posts_per_page'   => self::MIGRATION_BATCH,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( array_merge( $ids, $trashed ) as $post_id ) {
			self::convert_to_like_post_type( (int) $post_id );
		}

		if ( count( $ids ) < self::MIGRATION_BATCH && count( $trashed ) < self::MIGRATION_BATCH ) {
			update_option( self::MIGRATED_OPTION, 1 );
		}
	}

	/**
	 * A request for a migrated Like Mark's old `post` permalink now 404s
	 * (it's no longer a `post`) — 301 it to the Like's new URL instead, so
	 * a Webmention receiver re-verifying the old source URL still finds it.
	 *
	 * @return void
	 */
	public function redirect_legacy_permalink(): void {
		if ( ! is_404() ) {
			return;
		}

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		$post_id = absint( get_query_var( 'p' ) );
		$name    = (string) get_query_var( 'name' );

		if ( $post_id > 0 ) {
			$args['p'] = $post_id;
		} elseif ( '' !== $name ) {
			$args['name'] = $name;
		} else {
			return;
		}

		$found = get_posts( $args );

		if ( empty( $found ) ) {
			return;
		}

		wp_safe_redirect( get_permalink( (int) $found[0] ), 301 );
		exit;
	}

	/**
	 * Keep a Like Mark out of Jetpack Sync, which otherwise replicates
	 * published posts to WordPress.com (where they surfaced in the Reader,
	 * issue #389). NOT independently confirmed against a live Jetpack
	 * install — the filter name/signature follows Jetpack's Sync posts
	 * module and should be verified against its current source.
	 *
	 * @param bool         $prevent Whether Jetpack would otherwise withhold the post.
	 * @param WP_Post|null $post    The post being synced.
	 * @return bool
	 */
	public function prevent_jetpack_sync( $prevent, $post ) {
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			return true;
		}

		return $prevent;
	}

	/**
	 * Hide a Like Mark from the site's own home page, archives, search
	 * results, and RSS/Atom feed. Never touches a singular request (the
	 * post's own permalink) or wp-admin's own queries (its post list stays
	 * fully visible for management).
	 *
	 * @param WP_Query $query The query being filtered.
	 * @return void
	 */
	public function exclude_from_main_query( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}

		$query->set( 'meta_query', $this->add_exclusion( (array) $query->get( 'meta_query' ) ) );
	}

	/**
	 * Hide a Like Mark from the `GET /wp/v2/posts` collection listing,
	 * regardless of caller — an explicit `?include=` for its own ID is
	 * excluded too. A direct, capability-checked single-item fetch is a
	 * separate code path (block_rest_single_item()) and is unaffected.
	 *
	 * @param array<string, mixed> $args WP_Query args the REST controller built.
	 * @return array<string, mixed>
	 */
	public function exclude_from_rest_collection( array $args ): array {
		$args['meta_query'] = $this->add_exclusion( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array() );

		return $args;
	}

	/**
	 * Block an unauthenticated/non-editor `GET /wp/v2/posts/{id}` request
	 * for a Like Mark with a 404 — the standard `rest_prepare_post` filter
	 * has no reliable way to force a 404, so this short-circuits dispatch
	 * instead. Whoever can actually edit the post (wp-admin, the block
	 * editor) is let through unchanged so management keeps working.
	 *
	 * @param mixed           $result Response to replace the requested dispatch with. Null to proceed normally.
	 * @param WP_REST_Server  $server Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function block_rest_single_item( $result, $server, $request ) {
		if ( null !== $result || 'GET' !== $request->get_method() ) {
			return $result;
		}

		if ( ! preg_match( '#^/wp/v2/posts/(?P<id>\d+)$#', $request->get_route(), $matches ) ) {
			return $result;
		}

		$post_id = (int) $matches['id'];

		if ( '' === (string) get_post_meta( $post_id, '_daymark_like_of', true ) ) {
			return $result;
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return $result;
		}

		return new WP_Error(
			'rest_post_invalid_id',
			__( 'Invalid post ID.', 'daymark' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Hide a Like Mark from WordPress core's own XML sitemap.
	 *
	 * @param array<string, mixed> $args      Query args for the sitemap's posts query.
	 * @param string               $post_type The post type being queried.
	 * @return array<string, mixed>
	 */
	public function exclude_from_sitemap( array $args, string $post_type ): array {
		if ( 'post' !== $post_type ) {
			return $args;
		}

		$args['meta_query'] = $this->add_exclusion( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array() );

		return $args;
	}

	/**
	 * Defense in depth: suppress oEmbed discovery/response for a Like Mark
	 * too, on top of every channel above — returning false is core's own
	 * documented way for a filter to disable oEmbed for a given post.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @param WP_Post              $post The post.
	 * @return array<string, mixed>|false
	 */
	public function suppress_oembed( $data, $post ) {
		if ( $post instanceof WP_Post && '' !== (string) get_post_meta( $post->ID, '_daymark_like_of', true ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Defense in depth against Jetpack Social/Publicize auto-sharing a Like
	 * Mark to a site's connected social networks (a real, reported gap:
	 * Daymark's own "never syndicates" guarantee — `Daymark_Publisher::publish()`
	 * forcing `$targets`/`$defaults` to `array()` — only stops Daymark's own
	 * outbound connector pipeline; it has no effect on an independent plugin
	 * like Jetpack, which auto-shares any newly published public post to
	 * every connection enabled by default, with no awareness of Daymark's
	 * own post meta). `Daymark_Publisher::publish()` also sets a
	 * `_wpas_done_all` post meta flag on a Like Mark before its publish
	 * transition (Jetpack Publicize's own historical "already handled, don't
	 * auto-share" convention) as the first, more broadly-applicable layer;
	 * this filter is the second, in case that meta convention has since
	 * changed underneath it.
	 *
	 * NOT independently confirmed against a live Jetpack install — this
	 * environment cannot install a third-party plugin for testing, the same
	 * posture already established elsewhere in this codebase for a plugin
	 * this sandbox can't reach (see the ATmosphere/Friends-plugin/Bridgy Fed
	 * rows, CLAUDE.md) — flagged for Jeff to verify the filter name and its
	 * `(bool $should_publicize, WP_Post $post)` signature against Jetpack's
	 * own current source before relying on either layer alone.
	 *
	 * @param bool         $should_publicize Whether Jetpack would otherwise auto-share this post.
	 * @param WP_Post|null $post             The post being published.
	 * @return bool
	 */
	public function suppress_publicize( $should_publicize, $post ) {
		if ( $post instanceof WP_Post && '' !== (string) get_post_meta( $post->ID, '_daymark_like_of', true ) ) {
			return false;
		}

		return $should_publicize;
	}

	/**
	 * The first, more broadly-applicable Jetpack Social/Publicize
	 * suppression layer — see suppress_publicize() above for the full
	 * rationale and its own confidence caveat. Jetpack Publicize's own
	 * historical "this post is already handled, don't auto-share it" post
	 * meta flag. Must be set BEFORE the post's publish transition fires
	 * (Publicize hooks that same transition): `Daymark_Publisher::publish()`
	 * calls this immediately before transitioning a Like Mark from draft to
	 * publish — the same "insert as draft, apply meta, then go live"
	 * sequence that method already uses so `Daymark_Publish_Helpers`'
	 * own adapters can see their control meta before a third-party plugin's
	 * publish-time hook fires.
	 *
	 * @param int $post_id The Like Mark's post ID.
	 * @return void
	 */
	public static function suppress_publicize_on_insert( int $post_id ): void {
		update_post_meta( $post_id, '_wpas_done_all', 1 );
	}

	/**
	 * Merge the `_daymark_like_of` NOT EXISTS clause into an existing
	 * meta_query without discarding whatever's already there.
	 *
	 * @param array<int|string, mixed> $meta_query Existing meta_query, if any.
	 * @return array<int|string, mixed>
	 */
	private function add_exclusion( array $meta_query ): array {
		$exclusion = array(
			'key'     => '_daymark_like_of',
			'compare' => 'NOT EXISTS',
		);

		if ( empty( $meta_query ) ) {
			return array( $exclusion );
		}

		return array(
			'relation' => 'AND',
			$meta_query,
			$exclusion,
		);
	}
}
