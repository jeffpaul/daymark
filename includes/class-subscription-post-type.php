<?php
/**
 * The `daymark_subscription_post` CPT: cached copies of subscribed sites'
 * content, interleaved into the Timeline alongside the user's own Marks.
 *
 * A CPT, not a custom table (settled decision — see CLAUDE.md's
 * Subscriptions architecture notes and issue #78): it needs WordPress's
 * standard trash retention (unsubscribing relies on it) and WP_Query for
 * the Timeline merge. The `daymark_subscription` table (owned by
 * Daymark_Subscriptions, a separate concern) is what this CPT's
 * `subscription_id` meta points back to.
 *
 * This is a cached copy of someone else's content, not something this site
 * is publishing — it must never get its own public permalink here. That is
 * why `public` and `publicly_queryable` are both false below: getting
 * either wrong would republish someone else's content on this site's own
 * domain without their knowledge.
 *
 * Note on the registered slug: `daymark_subscription_post` (25 characters)
 * exceeds core's hard 20-character limit on post type names — the
 * `wp_posts.post_type` column is `varchar(20)`, and `register_post_type()`
 * refuses to register (returns a `WP_Error`, registers nothing) past that
 * length. `self::POST_TYPE` therefore holds the shortened, still-namespaced
 * `daymark_sub_post` (16 characters) as the actual value passed to
 * `register_post_type()`. Every reference elsewhere in the codebase (and
 * any future ingest/REST code) must go through `self::POST_TYPE` rather
 * than hardcoding either string.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `daymark_subscription_post` CPT and its meta fields.
 *
 * Content ingest, pruning, polling, and click-through fetch are owned
 * elsewhere (a later task) — this class only registers the post type and
 * its meta schema.
 */
class Daymark_Subscription_Post_Type {

	/**
	 * Post type slug actually passed to register_post_type().
	 *
	 * `daymark_subscription_post` is the name used everywhere in project
	 * docs/architecture notes, but it is 25 characters — past core's
	 * 20-character post type name limit (`wp_posts.post_type` is
	 * `varchar(20)`; register_post_type() rejects anything longer). This
	 * shortened, still `daymark_`-prefixed value is what is actually
	 * registered. Always reference this constant rather than either
	 * literal string.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'daymark_sub_post';

	/**
	 * Allowed `content_state` values.
	 *
	 * @var string[]
	 */
	private const CONTENT_STATES = array( 'full', 'excerpt_only', 'pruned' );

	/**
	 * Register the post type, its meta fields, and the front-end 404 guard.
	 *
	 * Called from Daymark_Plugin::on_init(), which already runs on the
	 * `init` hook — no need to hook the post type/meta registration
	 * separately. `template_redirect` is hooked here since it fires on a
	 * later, per-request hook.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_post_type();
		$this->register_meta();

		add_action( 'template_redirect', array( $this, 'force_404_on_front_end' ) );
	}

	/**
	 * Register the `daymark_subscription_post` post type.
	 *
	 * Deliberately locked down: this is a cached copy of someone else's
	 * content, not something Daymark publishes on this site's behalf.
	 *
	 * - `public` => false and `publicly_queryable` => false: no front-end
	 *   permalink, ever — not on this site's domain.
	 * - `show_ui` => false: no wp-admin list table; management happens in
	 *   the Daymark app shell only.
	 * - `show_in_rest` => false: this is a cached, read-only copy of
	 *   someone else's content that only the poller (ingest/pruning/
	 *   click-through fetch) ever writes — a user must never be able to
	 *   edit or delete it. The authenticated app shell reads it through
	 *   Daymark's own custom routes (GET /daymark/v1/timeline and GET
	 *   /daymark/v1/subscription-posts/{id} in Daymark_REST_Controller),
	 *   which query post meta directly and don't depend on this post type
	 *   having a REST controller at all. Leaving `show_in_rest` true would
	 *   auto-register a generic wp/v2/subscription-posts controller with
	 *   full CRUD (gated only by ordinary edit_post/delete_post
	 *   capabilities via `map_meta_cap` below) — nothing in this app uses
	 *   it, but it would still be a real, reachable way to edit or delete
	 *   subscription content that this post type is otherwise deliberately
	 *   locked down against.
	 * - `has_archive` => false, `rewrite` => false, `query_var` => false,
	 *   `exclude_from_search` => true: belt-and-suspenders alongside
	 *   `public`/`publicly_queryable` so nothing about this post type ever
	 *   surfaces in a normal front-end query, sitemap, or search result.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Subscription Posts', 'daymark' ),
				'labels'              => array(
					'name'          => __( 'Subscription Posts', 'daymark' ),
					'singular_name' => __( 'Subscription Post', 'daymark' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'exclude_from_search' => true,
				'can_export'          => true,
				'hierarchical'        => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'excerpt', 'thumbnail' ),
			)
		);
	}

	/**
	 * Register post meta fields on `daymark_subscription_post`.
	 *
	 * Each field's own `show_in_rest` is inert now that the post type
	 * itself is `show_in_rest => false` (see register_post_type() above) —
	 * there is no wp/v2 controller left for it to expose these on. Left
	 * true (rather than also flipped to match) purely for schema
	 * documentation and in case a future, narrowly-scoped read-only
	 * exposure is ever added; it grants no REST access on its own. Each
	 * field still gets a `sanitize_callback` per the security checklist,
	 * and an `auth_callback` requiring `edit_posts`, so even a future
	 * generic controller would need an explicit, deliberate opt-in rather
	 * than silently inheriting an open one.
	 *
	 * Sanitization here is a schema-level safety net; `body_content` in
	 * particular is documented to be fully `wp_kses_post()`-sanitized by
	 * whatever REST/ingest code writes it later — this registration just
	 * ensures nothing malformed lands in the meta table even before that
	 * code exists.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth_callback = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		// FK to the `daymark_subscription` table by ID (no DB-level FK
		// constraint — that table is a plain custom table, not relational
		// in the schema sense).
		register_post_meta(
			self::POST_TYPE,
			'subscription_id',
			array(
				'type'              => 'integer',
				'description'       => __( 'ID of the daymark_subscription row this post was ingested from.', 'daymark' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth_callback,
			)
		);

		// Nullable text/HTML. Eventually wp_kses_post()-sanitized at write
		// time by ingest/click-through-fetch code; sanitized here too as a
		// schema-level safety net.
		register_post_meta(
			self::POST_TYPE,
			'body_content',
			array(
				'type'              => 'string',
				'description'       => __( 'Cached full HTML body content, when fetched.', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => $auth_callback,
			)
		);

		// Nullable, JSON-encoded string (e.g. resolved embed/enclosure/
		// oEmbed data for video/image/gallery/audio formats).
		register_post_meta(
			self::POST_TYPE,
			'embed_data',
			array(
				'type'              => 'string',
				'description'       => __( 'JSON-encoded cached embed/enclosure/oEmbed data.', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_embed_data' ),
				'auth_callback'     => $auth_callback,
			)
		);

		// Enum: full | excerpt_only | pruned.
		register_post_meta(
			self::POST_TYPE,
			'content_state',
			array(
				'type'              => 'string',
				'description'       => __( 'Cache depth: full, excerpt_only, or pruned.', 'daymark' ),
				'single'            => true,
				'default'           => 'excerpt_only',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_content_state' ),
				'auth_callback'     => $auth_callback,
			)
		);

		// Nullable MySQL datetime string.
		register_post_meta(
			self::POST_TYPE,
			'fetched_full_at',
			array(
				'type'              => 'string',
				'description'       => __( 'When body_content was last fetched in full (MySQL datetime), if ever.', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_datetime' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'post_format',
			array(
				'type'              => 'string',
				'description'       => __( 'The Daymark post_format a source connector normalized this item to (standard, image, video, audio, gallery, or note).', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'featured_image_url',
			array(
				'type'              => 'string',
				'description'       => __( 'Featured image URL as cached from the source.', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth_callback,
			)
		);

		// The *source* site's permalink for this specific post — never a
		// URL on this site, since this post type has no permalink here.
		register_post_meta(
			self::POST_TYPE,
			'permalink',
			array(
				'type'              => 'string',
				'description'       => __( "The source site's permalink for this post (not a URL on this site).", 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'author',
			array(
				'type'              => 'string',
				'description'       => __( 'Author name as cached from the source.', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'published_at',
			array(
				'type'              => 'string',
				'description'       => __( 'Original publish date/time from the source (MySQL datetime).', 'daymark' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_datetime' ),
				'auth_callback'     => $auth_callback,
			)
		);
	}

	/**
	 * Force a 404 for any front-end request that resolves to a singular
	 * `daymark_subscription_post`.
	 *
	 * `public => false` and `publicly_queryable => false` stop this post
	 * type from getting pretty-permalink rewrite rules, an archive, or
	 * REST auto-discovery — but they do not stop WordPress's main query
	 * from resolving a directly constructed `?post_type=<slug>&p=<id>`
	 * request on the front end (`post_type` is parsed as a public query
	 * var regardless of the post type's own visibility flags). Without
	 * this guard, that URL shape would render the cached third-party
	 * content as a normal front-end page — exactly the harm this CPT's
	 * lockdown exists to prevent. This is intentionally a hard 404, not a
	 * redirect: there is no canonical front-end URL for this content to
	 * redirect to.
	 *
	 * Only affects the front end; the authenticated REST API path
	 * (`show_in_rest => true`) never runs `template_redirect` and is
	 * unaffected.
	 *
	 * @return void
	 */
	public function force_404_on_front_end(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Sanitize `embed_data`: accept an array/object by JSON-encoding it, or
	 * a string only when it is valid JSON. Anything else becomes ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_embed_data( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );

			return false !== $encoded ? $encoded : '';
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		json_decode( $value );

		return ( JSON_ERROR_NONE === json_last_error() ) ? $value : '';
	}

	/**
	 * Sanitize `content_state` to one of the allowed enum values, falling
	 * back to 'excerpt_only' for anything unrecognized.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_content_state( $value ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, self::CONTENT_STATES, true ) ? $value : 'excerpt_only';
	}

	/**
	 * Sanitize a nullable MySQL datetime string, rejecting anything that
	 * isn't a plausible `Y-m-d H:i:s` value rather than storing malformed
	 * data.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_datetime( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$value = sanitize_text_field( $value );

		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
