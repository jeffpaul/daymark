<?php
/**
 * Hides a Like Mark's own auto-published post from every public discovery
 * surface except its own direct permalink.
 *
 * A Like Mark (`_daymark_like_of` post meta — see the "Subscribed-post
 * engagement" decision, CLAUDE.md) exists purely to give a federation
 * plugin a real, fetchable `u-like-of` source page to send an outbound
 * Webmention/ActivityPub Like from; it is not content a reader is meant to
 * find. It was already excluded from Daymark's own Timeline (issue #267),
 * but nothing kept it out of the site's own front end, feed, REST API, or
 * XML sitemap — so it could still show up as an ordinary post on the
 * theme's home page, in an "Asides" archive, in the site's RSS feed, or via
 * `wp/v2/posts`, exactly as reported.
 *
 * The one deliberate exception is the post's own singular permalink: the
 * Webmention verification a Like toggle relies on requires the origin site
 * to be able to fetch that URL directly and find the `u-like-of` markup, so
 * it must stay reachable by a plain, unauthenticated GET. Every hook below
 * is scoped to leave `is_singular()` (and a direct, capability-checked
 * single-item REST fetch) completely untouched.
 *
 * Deliberately scoped to `_daymark_like_of` only — a Reblog Mark
 * (`_daymark_repost_of`) is real, user-chosen, publishable content and
 * stays exactly as visible/syndicated as any other Mark.
 *
 * wp-admin's own post list is intentionally left alone: a site owner can
 * still see and manage a Like Mark there (or in the block editor, which
 * this class's REST guard also leaves working for whoever can edit the
 * post) — only the *public* discovery surfaces are hidden.
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
	 * Hook up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'exclude_from_main_query' ) );
		add_filter( 'rest_post_query', array( $this, 'exclude_from_rest_collection' ), 10, 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'block_rest_single_item' ), 10, 3 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_from_sitemap' ), 10, 2 );
		add_filter( 'oembed_response_data', array( $this, 'suppress_oembed' ), 10, 2 );
		add_filter( 'publicize_should_publicize_published_post', array( $this, 'suppress_publicize' ), 10, 2 );
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
