<?php
/**
 * REST API controller for the /wp-json/daymark/v1/ namespace.
 *
 * Every endpoint verifies the X-WP-Nonce header AND the edit_posts
 * capability before processing. No unauthenticated endpoints, ever.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles Daymark REST endpoints.
 */
class Daymark_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'daymark/v1';

	/**
	 * Maximum Marks per page for GET /marks.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 50;

	/**
	 * Hard ceiling on the number of items GET /timeline's two source
	 * queries will ever ask for in one request (`page * per_page`,
	 * before merging/slicing). Without this, an arbitrarily deep `page`
	 * value would size `posts_per_page` unbounded on every request —
	 * `per_page` is capped by MAX_PER_PAGE, but nothing otherwise caps
	 * `page` itself. 500 covers 10 pages at the max page size, generous
	 * for genuine Timeline scrolling depth while keeping a single
	 * request's worst-case query size bounded.
	 *
	 * @var int
	 */
	private const MAX_TIMELINE_QUERY_ITEMS = 500;

	/**
	 * Longest quote, in characters, a Timeline card shows for a quote
	 * Featured Content. A longer quote is cut with an ellipsis; the full
	 * quote is in the post view.
	 *
	 * @var int
	 */
	private const CARD_QUOTE_MAX_CHARS = 280;

	/**
	 * Register REST routes. Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/marks',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_mark' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'caption'              => array(
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
						'title'                => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'primary_type'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'status'               => array(
							'type'              => 'string',
							'default'           => 'publish',
							'enum'              => array( 'publish', 'draft' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'syndication_targets'  => array(
							'description' => __( 'Selected connector IDs (array or JSON string).', 'daymark' ),
						),
						'default_destinations' => array(
							'description' => __( 'Default connector IDs (array or JSON string).', 'daymark' ),
						),
						'categories'           => array(
							'description' => __( 'Category term IDs to file the Mark under (array or JSON string).', 'daymark' ),
						),
						'ai_assist_used'       => array(
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'autosave'             => array(
							'type'              => 'boolean',
							'default'           => false,
							'description'       => __( 'Marks this as a background autosave rather than a user-initiated action, for rate-limiting purposes only.', 'daymark' ),
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'captured_at'          => array(
							'type'              => 'string',
							'description'       => __( 'ISO 8601 capture timestamp from the client, used as the Mark\'s post_date when valid and not materially in the future.', 'daymark' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'location_lat'         => array(
							'type'              => 'number',
							'description'       => __( 'Captured latitude, if available and permitted.', 'daymark' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
						),
						'location_lng'         => array(
							'type'              => 'number',
							'description'       => __( 'Captured longitude, if available and permitted.', 'daymark' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
						),
						'location_accuracy'    => array(
							'type'              => 'number',
							'description'       => __( 'Captured location accuracy in meters, if available.', 'daymark' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
						),
						'place_name'           => array(
							'type'              => 'string',
							'description'       => __( 'Resolved (composer-editable) place name for a Checkin Mark.', 'daymark' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_marks' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'status'   => array(
							'type'              => 'string',
							'default'           => 'any',
							'enum'              => array( 'any', 'publish', 'draft' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'type'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						's'        => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/timeline',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_timeline' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'per_page'        => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'            => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					's'               => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'            => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'mine'            => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'subscription_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'bookmarked'      => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					// Issue #293 Search date filter — `after`/`before` are purely
					// additive to the params above (omitted means "no bound").
					'after'           => array(
						'type'   => 'string',
						'format' => 'date-time',
						// No default: omitting the param entirely skips
						// the date-time validation against '' which would
						// fail on every request.  get_timeline() treats a
						// missing/empty value as "no lower bound".
					),
					'before'          => array(
						'type'   => 'string',
						'format' => 'date-time',
						// No default: see 'after' note above.
					),
					// "On this day" (Memories, issue #294): restrictions
					// replicate `mine` (Marks only, subscription posts
					// skipped entirely) and narrow the Marks query to the
					// same calendar month and day as today in any prior
					// year — see get_timeline()'s own docblock.
					'on_this_day'     => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					// Search's result count: adds an X-WP-Total header
					// (WordPress core's own name for it) with how many
					// items match in all. Off by default, since counting
					// costs a little extra on every query.
					'count'           => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/suggestions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_suggestions' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'caption'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'type'       => array(
						'type'              => 'string',
						'default'           => 'note',
						'sanitize_callback' => 'sanitize_key',
					),
					'transcript' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/title',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_title' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'caption'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'type'       => array(
						'type'              => 'string',
						'default'           => 'note',
						'sanitize_callback' => 'sanitize_key',
					),
					'transcript' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/alt-text',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_alt_text' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/transcript',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_transcript' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/tags',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_tags' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'caption'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'type'       => array(
						'type'              => 'string',
						'default'           => 'note',
						'sanitize_callback' => 'sanitize_key',
					),
					'transcript' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/location/reverse-geocode',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'location_reverse_geocode' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'lat' => array(
						'type'              => 'number',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
					),
					'lng' => array(
						'type'              => 'number',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/location/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'location_search' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/featured-content/oembed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'featured_content_oembed' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'url'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/marks/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_daymark' ),
					'permission_callback' => array( $this, 'permissions_check_post' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_daymark' ),
					'permission_callback' => array( $this, 'permissions_check_post' ),
					'args'                => array(
						'id'                => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'caption'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
						'status'            => array(
							'type'              => 'string',
							'enum'              => array( 'publish', 'draft' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'autosave'          => array(
							'type'              => 'boolean',
							'default'           => false,
							'description'       => __( 'Marks this as a background autosave rather than a user-initiated action, for rate-limiting purposes only.', 'daymark' ),
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'captured_at'       => array(
							'type'              => 'string',
							'description'       => __( 'ISO 8601 capture timestamp from the client, used as the Mark\'s post_date when valid and not materially in the future.', 'daymark' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'location_lat'      => array(
							'type'              => 'number',
							'description'       => __( 'Captured latitude, if available and permitted.', 'daymark' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
						),
						'location_lng'      => array(
							'type'              => 'number',
							'description'       => __( 'Captured longitude, if available and permitted.', 'daymark' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
						),
						'location_accuracy' => array(
							'type'              => 'number',
							'description'       => __( 'Captured location accuracy in meters, if available.', 'daymark' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_optional_float' ),
						),
						'place_name'        => array(
							'type'              => 'string',
							'description'       => __( 'Resolved (composer-editable) place name for a Checkin Mark.', 'daymark' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_daymark' ),
					'permission_callback' => array( $this, 'permissions_check_delete' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/marks/(?P<id>\d+)/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_mark_content' ),
				// Timeline's own visibility rule, not permissions_check_post's
				// edit_post check: a Timeline card is already visible to
				// anyone who can see the Timeline at all (permissions_check
				// alone — edit_posts + nonce), regardless of who authored it —
				// see get_timeline()'s own docblock. Expanding one in place
				// discloses nothing that item's own Timeline summary
				// (title/excerpt/thumbnail) didn't already.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/marks/(?P<id>\d+)/featured-content-link',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_featured_content_link' ),
				// Same visibility rule as GET /marks/{id}/content.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/marks/(?P<id>\d+)/featured-content-image',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_featured_content_image' ),
				// Same visibility rule as GET /marks/{id}/content: the card
				// this fills in is already on the caller's Timeline.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/marks/(?P<id>\d+)/sync-responses',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_responses' ),
				'permission_callback' => array( $this, 'permissions_check_post' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_subscription' ),
					'permission_callback' => array( $this, 'permissions_check_manage' ),
					'args'                => array(
						'site_url' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_subscriptions' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Following a site from the app: find a site's feeds, then follow
		// one of them. Same capability as Settings -> Daymark, which does
		// the same job.
		register_rest_route(
			$this->namespace,
			'/subscriptions/discover',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'discover_subscription' ),
				'permission_callback' => array( $this, 'permissions_check_manage' ),
				'args'                => array(
					'site_url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/follow',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'follow_discovered_feed' ),
				'permission_callback' => array( $this, 'permissions_check_manage' ),
				'args'                => array(
					'index'      => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
					'site_title' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_subscription' ),
				'permission_callback' => array( $this, 'permissions_check_manage' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_all_subscriptions' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>\d+)/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_subscription' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_subscriptions_opml' ),
				'permission_callback' => array( $this, 'permissions_check_manage' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_subscriptions_opml' ),
				'permission_callback' => array( $this, 'permissions_check_manage' ),
			)
		);

		// Serves export_subscriptions_opml()'s response as a raw OPML/XML
		// file download rather than the REST API's usual JSON envelope —
		// scoped to this one route by request path, using the documented
		// `rest_pre_serve_request` short-circuit rather than forcing binary/
		// XML output through the JSON envelope. Never fires during
		// rest_do_request() (used by this plugin's own PHPUnit REST tests),
		// only during a real WP_REST_Server::serve_request() dispatch — see
		// maybe_serve_opml_export()'s own docblock.
		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_opml_export' ), 10, 4 );

		register_rest_route(
			$this->namespace,
			'/subscription-posts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_post_full_content' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'         => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					// Forces a live re-fetch/re-extraction even for an
					// already-'full'-cached post — see
					// get_subscription_post_full_content()'s own docblock.
					'refresh'    => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					// Set by the app's own background fetches, which spend
					// a separate rate-limit allowance from opening a post.
					'background' => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscription-posts/(?P<id>\d+)/oembed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_post_oembed' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					// `interaction`: preview the post this one replies to,
					// reblogs, likes, bookmarks, or RSVPs to (issue #168)
					// instead of its own outbound link. `reply` is its
					// earlier name.
					'target' => array(
						'type'    => 'string',
						'enum'    => array( 'link', 'interaction', 'reply' ),
						'default' => 'link',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscription-posts/(?P<id>\d+)/comment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'comment_on_subscription_post' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'text' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscription-posts/(?P<id>\d+)/comment-target',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_post_comment_target' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscription-posts/(?P<id>\d+)/like-availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_post_like_availability' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscription-posts/(?P<id>\d+)/like',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'like_subscription_post' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unlike_subscription_post' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/uploads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_upload' ),
				'permission_callback' => array( $this, 'permissions_check_upload' ),
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'size' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/uploads/(?P<id>[a-f0-9]{32})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_upload' ),
					'permission_callback' => array( $this, 'permissions_check_upload' ),
				),
				array(
					// PUT is the natural verb; POST is accepted too because
					// some hosts' firewalls refuse PUT request bodies. The
					// app sends POST.
					'methods'             => 'POST, PUT',
					'callback'            => array( $this, 'append_upload_chunk' ),
					'permission_callback' => array( $this, 'permissions_check_upload' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'cancel_upload' ),
					'permission_callback' => array( $this, 'permissions_check_upload' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bookmarks/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_bookmark' ),
					// Same reasoning as GET /marks/{id}/content: a personal
					// "save for later" action on anything already visible on
					// this user's own Timeline, not an edit_post ownership
					// check — permissions_check (edit_posts + nonce) alone.
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_bookmark' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bookmarks/(?P<id>\d+)/image',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bookmark_image' ),
				// The handler itself requires the post to be bookmarked by
				// the current user and the URL to appear in its content.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					// Validated (and matched against the post's content) by
					// Daymark_Bookmark_Images::fetch(); no 'uri' format here,
					// since sanitizing it first could stop it matching.
					'url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/timeline/last-seen',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_timeline_seen' ),
				// A pure local write (per-user meta, no outbound request),
				// debounced client-side, same posture as dismissing a
				// plugin-overlap notice — no rate-limit bucket.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/timeline/position',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_timeline_position' ),
				// Same posture as /timeline/last-seen: a debounced, local
				// per-user meta write with no outbound request.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/interaction-hints/(?P<hint>[a-z]+)/seen',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_interaction_hint_seen' ),
				// A pure local write (per-user meta, no outbound request),
				// at most once per hint per user — no rate-limit bucket.
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/plugin-overlaps/(?P<plugin>[a-z0-9-]+)/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_plugin_overlap' ),
				// A pure local write (per-user meta, no outbound request),
				// same posture as unsubscribing — no rate-limit bucket
				// needed, unlike an action that costs an external fetch.
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'plugin' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/(?P<comment_id>\d+)/reply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reply_to_comment' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'comment_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'content'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tags' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /tags — existing post_tag terms matching a search string, so the
	 * composer's tag field can offer a tap-to-pick suggestion instead of
	 * requiring the full name to be typed every time (product principle:
	 * minimal text entry).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_tags( WP_REST_Request $request ) {
		$search = (string) $request->get_param( 'search' );

		if ( '' === trim( $search ) ) {
			return new WP_REST_Response( array() );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'search'     => $search,
				'number'     => 10,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return new WP_REST_Response( array() );
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'id'   => $term->term_id,
				'name' => $term->name,
			);
		}

		return new WP_REST_Response( $results );
	}

	/**
	 * Shared permission callback: nonce + capability. Required on every route.
	 *
	 * Uses rest_authorization_required_code() so unauthenticated requests
	 * get 401 and authenticated-but-unauthorized requests get 403.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid nonce.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission check for the routes that change site-wide subscription
	 * settings: the same nonce and `edit_posts` gate as permissions_check(),
	 * plus the capability Settings -> Daymark itself requires
	 * (Daymark_Admin_Subscriptions::CAPABILITY), so a REST call can never do
	 * what that screen would refuse.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function permissions_check_manage( WP_REST_Request $request ) {
		$allowed = $this->permissions_check( $request );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( Daymark_Admin_Subscriptions::CAPABILITY ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission check for the chunked upload routes (issue #483): the
	 * shared check plus `upload_files`, the same capability a Mark save
	 * already needs before it accepts files.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function permissions_check_upload( WP_REST_Request $request ) {
		$allowed = $this->permissions_check( $request );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Apply the per-user rate limit for an expensive action.
	 *
	 * Callers return the WP_Error verbatim so a 429 carries its
	 * `retry_after` data through the REST response.
	 *
	 * @param string $action One of Daymark_Rate_Limiter::ACTION_*.
	 * @return true|WP_Error
	 */
	private function rate_limit( string $action ) {
		$attempt = Daymark_Plugin::instance()->rate_limiter->attempt( $action );

		if ( is_wp_error( $attempt ) ) {
			return new WP_Error(
				$attempt->get_error_code(),
				$attempt->get_error_message(),
				$attempt->get_error_data()
			);
		}

		return true;
	}

	/**
	 * Per-post permission callback: the shared check plus edit_post on the
	 * targeted Mark, so users cannot act on posts they cannot edit.
	 *
	 * A nonexistent post passes through to the handler, which returns its
	 * regular 404 — only a real post the user cannot edit is a 403.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function permissions_check_post( WP_REST_Request $request ) {
		$shared = $this->permissions_check( $request );

		if ( true !== $shared ) {
			return $shared;
		}

		$post_id = absint( $request->get_param( 'id' ) );

		if ( get_post( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You cannot manage responses for this Mark.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Per-post permission callback for deletion: the shared nonce + capability
	 * check plus the delete_post capability on the targeted Mark. Deleting is
	 * more privileged than editing, so it needs its own capability, not merely
	 * edit_post.
	 *
	 * A nonexistent post passes through to the handler, which returns its
	 * regular 404 — only a real post the user cannot delete is a 403.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function permissions_check_delete( WP_REST_Request $request ) {
		$shared = $this->permissions_check( $request );

		if ( true !== $shared ) {
			return $shared;
		}

		$post_id = absint( $request->get_param( 'id' ) );

		if ( get_post( $post_id ) && ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You cannot delete this Mark.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * POST /daymark/v1/uploads — start a chunked upload for one file.
	 *
	 * @param WP_REST_Request $request The request (`name`, `size`).
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_upload( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_UPLOAD );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$session = Daymark_Plugin::instance()->uploads->create_session(
			(string) $request->get_param( 'name' ),
			(int) $request->get_param( 'size' ),
			get_current_user_id()
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return new WP_REST_Response( $session, 201 );
	}

	/**
	 * GET /daymark/v1/uploads/{id} — how much of a file the site has, so
	 * the client can resume.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_upload( WP_REST_Request $request ) {
		$session = Daymark_Plugin::instance()->uploads->get_session( (string) $request['id'], get_current_user_id() );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return rest_ensure_response( $session );
	}

	/**
	 * PUT (or POST) /daymark/v1/uploads/{id} — append one part.
	 *
	 * The body is the part's raw bytes. A `Content-Range: bytes
	 * {start}-{end}/{total}` header says where it goes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function append_upload_chunk( WP_REST_Request $request ) {
		$range = (string) $request->get_header( 'content_range' );

		if ( ! preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', trim( $range ), $matches ) ) {
			return new WP_Error(
				'daymark_upload_bad_chunk',
				__( 'This part of the upload is missing its position.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$start = (int) $matches[1];
		$end   = (int) $matches[2];
		$total = (int) $matches[3];
		$bytes = (string) $request->get_body();

		if ( $end < $start || strlen( $bytes ) !== $end - $start + 1 ) {
			return new WP_Error(
				'daymark_upload_bad_chunk',
				__( 'This part of the upload is the wrong size.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$session = Daymark_Plugin::instance()->uploads->append_chunk(
			(string) $request['id'],
			get_current_user_id(),
			$start,
			$total,
			$bytes
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return rest_ensure_response( $session );
	}

	/**
	 * DELETE /daymark/v1/uploads/{id} — cancel an upload the composer no
	 * longer needs (the file was removed before the Mark was saved).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_upload( WP_REST_Request $request ) {
		$result = Daymark_Plugin::instance()->uploads->cancel_session( (string) $request['id'], get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /daymark/v1/marks — create a Mark.
	 *
	 * Accepts multipart file uploads plus caption/type/target fields and
	 * delegates to Daymark_Publisher. The composer's autosave also calls this
	 * (with `status=draft` and `autosave=1`, the latter only changing which
	 * rate-limit bucket applies) to create the first draft snapshot of an
	 * in-progress Mark before the user taps Publish or Save as Draft.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_mark( WP_REST_Request $request ) {
		$is_autosave = rest_sanitize_boolean( $request->get_param( 'autosave' ) );
		$rate        = $this->rate_limit( $is_autosave ? Daymark_Rate_Limiter::ACTION_AUTOSAVE : Daymark_Rate_Limiter::ACTION_PUBLISH );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files     = $request->get_file_params();
		$media_ids = $request->get_param( 'media_ids' );

		if ( ( ! empty( $files ) || ! empty( $media_ids ) ) && ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Canonical multipart field for destinations is `targets[]`; accept
		// the older `syndication_targets` name as a fallback.
		$targets = $request->get_param( 'targets' );
		if ( null === $targets ) {
			$targets = $request->get_param( 'syndication_targets' );
		}

		$data = array(
			'caption'              => wp_kses_post( (string) $request->get_param( 'caption' ) ),
			'title'                => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'primary_type'         => sanitize_key( (string) $request->get_param( 'primary_type' ) ),
			'status'               => sanitize_key( (string) $request->get_param( 'status' ) ),
			'syndication_targets'  => $targets,
			'default_destinations' => $request->get_param( 'default_destinations' ),
			'categories'           => $request->get_param( 'categories' ),
			'ai_assist_used'       => rest_sanitize_boolean( $request->get_param( 'ai_assist_used' ) ),
			'alt_text'             => sanitize_text_field( (string) $request->get_param( 'alt_text' ) ),
			// Per-image alt: positional array aligned to media_ids[] and
			// then files[] order.
			'alt'                  => $request->get_param( 'alt' ),
			// Files already uploaded through Daymark_Uploads (issue #483),
			// checked by Daymark_Uploads::resolve_media_ids().
			'media_ids'            => $media_ids,
			'tags'                 => array_filter( array_map( 'sanitize_text_field', (array) ( $request->get_param( 'tags' ) ?? array() ) ) ),
			'transcript'           => sanitize_textarea_field( (string) $request->get_param( 'transcript' ) ),
			// Quiet metadata capture: date/time + optional location, both
			// sent invisibly by the composer — see Daymark_Publisher::publish()
			// for how these resolve (client value vs. EXIF vs. no signal) and
			// range-validate. Never required, never blocks publishing.
			'captured_at'          => sanitize_text_field( (string) $request->get_param( 'captured_at' ) ),
			'location_lat'         => $request->get_param( 'location_lat' ),
			'location_lng'         => $request->get_param( 'location_lng' ),
			'location_accuracy'    => $request->get_param( 'location_accuracy' ),
			// A Checkin Mark's resolved (composer-editable) place name —
			// see Daymark_Publisher::resolve_place_name().
			'place_name'           => sanitize_text_field( (string) $request->get_param( 'place_name' ) ),
			// Set only when composing a reply from a subscribed post's
			// expanded card (issue #83) — see Daymark_Publisher::resolve_in_reply_to().
			'in_reply_to'          => (string) $request->get_param( 'in_reply_to' ),
			// Set only when composing a Repost/Like from a subscribed post's
			// Timeline card (issue #41 follow-up) — see
			// Daymark_Publisher::resolve_repost_of()/resolve_like_of().
			'repost_of'            => (string) $request->get_param( 'repost_of' ),
			'like_of'              => (string) $request->get_param( 'like_of' ),
			// The Reblog screen's credit for the reblogged post's author, shown
			// in the caption of Daymark_Publisher's core/embed block.
			// `quote_author` is the name an app cached before this change
			// still sends.
			'reblog_author'        => sanitize_text_field(
				(string) ( $request->get_param( 'reblog_author' ) ?? $request->get_param( 'quote_author' ) )
			),
		);

		// Only forward the helper selection when the client actually sent
		// one, so API callers that omit it keep those plugins' defaults.
		// Raw value (array or JSON string) — the publisher normalizes it.
		if ( null !== $request->get_param( 'publish_helpers' ) ) {
			$data['publish_helpers'] = $request->get_param( 'publish_helpers' );
		}

		$post_id = Daymark_Plugin::instance()->publisher->publish( $data, is_array( $files ) ? $files : array() );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$response = rest_ensure_response( $this->prepare_mark_summary( $post_id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * GET /daymark/v1/marks — recent Mark summaries.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_marks( WP_REST_Request $request ) {
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );

		// Status filter, so drafts stay reachable in the app no matter how
		// many Marks have published since (the Home Drafts row).
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$statuses = in_array( $status, array( 'publish', 'draft' ), true )
			? array( $status )
			: array( 'publish', 'draft' );

		$args = array(
			'post_type'      => 'post',
			'post_status'    => $statuses,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Personal-site-scale Mark lookup.
			'meta_key'       => '_daymark_is_mark',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Personal-site-scale Mark lookup.
			'meta_value'     => '1',
		);

		// Optional content-type filter: narrow to one _daymark_primary_type.
		// This adds a second meta condition, so switch to an explicit
		// meta_query that keeps the _daymark_is_mark gate intact.
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' !== $type ) {
			unset( $args['meta_key'], $args['meta_value'] );
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Mark lookup.
			$args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'   => '_daymark_is_mark',
					'value' => '1',
				),
				array(
					'key'   => '_daymark_primary_type',
					'value' => $type,
				),
			);
		}

		// Optional keyword search across title/content.
		$search = sanitize_text_field( (string) $request->get_param( 's' ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		$marks = array();

		foreach ( $query->posts as $post ) {
			// Published Marks are public; drafts are only listed for
			// users who can edit that specific post (authors see their
			// own, editors see all).
			if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$marks[] = $this->prepare_mark_summary( $post->ID );
		}

		return rest_ensure_response( $marks );
	}

	/**
	 * GET /daymark/v1/timeline — the merged, date-sorted Timeline: every
	 * published post on this site (a Daymark Mark or an ordinary post
	 * written directly in the block editor — see the Marks query below)
	 * interleaved with cached `daymark_subscription_post` entries from
	 * active subscriptions.
	 *
	 * `WP_Query` cannot express this in one query: `daymark_subscription_post`
	 * is a different post type entirely, so mixing the two sources into a
	 * single query isn't possible regardless of Mark-gating. So this runs
	 * two separate queries and merges the results in PHP.
	 *
	 * Pagination tradeoff (documented rather than silently assumed): each
	 * source query fetches up to `page * per_page` of its own items
	 * (sorted newest-first), the two result sets are merged and re-sorted
	 * by date, and then the exact page window is sliced out of that merged
	 * list. This re-fetches everything from page 1 through the requested
	 * page on every request, which is more repeated work than a real
	 * cursor-based cross-source pagination scheme on deep pagination — but
	 * it is simple and always correct at this codebase's personal-site
	 * scale, matching the "fetch what's needed, sort, then slice" precedent
	 * already used elsewhere in Subscriptions (e.g. pruning's retention
	 * scan). A cursor-based approach is a possible future optimization if
	 * deep Timeline pagination ever proves too slow in practice.
	 *
	 * Only published items are considered from both sources: subscription
	 * posts are always ingested as 'publish' (there is no subscription
	 * post draft state), and the Marks side is restricted to 'publish'
	 * here even though drafts are a valid Mark status elsewhere (GET
	 * /marks) — the Timeline is a published feed, not the composer's Home
	 * Drafts row. This also means the Marks side never needs a
	 * `post_status = 'draft'` clause to exclude a plain draft blog post
	 * being written in the block editor — 'publish' alone already does.
	 *
	 * The Marks side query is NOT gated on `_daymark_is_mark`: every
	 * published `post`-type post shows up, whether it was created through
	 * Daymark's own composer or written directly in the block editor —
	 * this site is the canonical, portable source of truth (Product
	 * Principle 2), and a site owner publishing some content one way and
	 * some the other shouldn't mean half of it is invisible on their own
	 * Timeline. A post with no `_daymark_primary_type` meta (never
	 * touched by Daymark) simply comes back with `type` as `''` in
	 * prepare_mark_summary() below — the app shell infers a reasonable
	 * card kind from what it actually has (a real featured image, a real
	 * excerpt) instead. The one exception is the `type` filter itself
	 * (below): narrowing to one specific `_daymark_primary_type` value
	 * only ever matches true Marks, since a plain post has no such meta to
	 * match against — Explore's "browse by type" and Search's type chips
	 * stay scoped to Daymark's own type vocabulary, not a guess at an
	 * arbitrary post's content.
	 *
	 * A Mark carrying `_daymark_like_of` or `_daymark_repost_of` (the
	 * Like/Repost toggle's own auto-published Mark — see "Subscribed-post
	 * engagement", CLAUDE.md) is unconditionally excluded from the Marks
	 * side of this query: it exists purely to carry an outbound
	 * `u-like-of`/`u-repost-of` link for a federation plugin to send, not
	 * as content meant to appear on the Timeline. This is a Timeline-only
	 * exclusion — the Mark itself is untouched everywhere else.
	 *

	 * Five optional filter params, combinable with the pagination params
	 * above: `s` (keyword search, applied identically to both source
	 * queries), `type` (content-type filter — `_daymark_primary_type` on
	 * the Marks side, `post_format` on the subscription-posts side, same
	 * enum space `GET /marks` already accepts for its own `type` param),
	 * `mine` (Marks only — the subscription-posts query is skipped
	 * entirely, not merely filtered to empty), `subscription_id`
	 * (that one subscription's posts only — the Marks query is skipped
	 * entirely), and `bookmarked` (restricts both source queries to the
	 * current user's bookmarked post IDs via `post__in` — see
	 * Daymark_Bookmarks; backs Explore's "Bookmarks" section and Search's
	 * own bookmarked filter). If both `mine` and `subscription_id` are set,
	 * `mine` wins and `subscription_id` is ignored.
	 *
	 * Two more optional filter params, the Search date filter (issue #293):
	 * `after`/`before` (an inclusive publication datetime window, REST format
	 * 'date-time' — over post_date for Marks, over the subscription post's own
	 * `published_at` meta otherwise). An omitted bound means "no bound"; core
	 * validates the datetime shape itself, via the 'date-time' format.
	 *
	 * One more optional param, "On this day" (issue #294): `on_this_day`
	 * (boolean). Marks only and subscription posts skipped entirely,
	 * exactly like `mine`; the Marks query narrows to the same calendar
	 * month and day as today in any prior year via a date_query clause
	 * over post_date (`month`/`day` with an exclusive `before` bound at
	 * local midnight today, all from wp_date() so the comparison stays in
	 * the site's own timezone). Today's own Marks are excluded by that
	 * bound; a leap-day query degrades to empty on non-leap prior years.
	 * Purely additive to every filter above — combining it with an
	 * explicit `after`/`before` window ANDs both date clauses.
	 *
	 * With `count=1`, the response carries an X-WP-Total header: how many
	 * items match in all, before paging (Search shows it as a result count).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_timeline( WP_REST_Request $request ) {
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		// Capped so an arbitrarily deep `page` can't size either source
		// query unbounded — see MAX_TIMELINE_QUERY_ITEMS.
		$limit = min( self::MAX_TIMELINE_QUERY_ITEMS, $page * $per_page );

		$search          = sanitize_text_field( (string) $request->get_param( 's' ) );
		$type            = sanitize_key( (string) $request->get_param( 'type' ) );
		$mine            = rest_sanitize_boolean( $request->get_param( 'mine' ) );
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );
		$bookmarked      = rest_sanitize_boolean( $request->get_param( 'bookmarked' ) );

		// Issue #293 Search date filter:
		// after / before — an inclusive publication datetime window
		// (REST format 'date-time', so core validates the shape
		// before this method runs). Normalized to MySQL
		// 'Y-m-d H:i:s' for both branches: the Marks side uses
		// a real WP date_query over post_date, the
		// subscription-posts side a meta range over its own
		// `published_at` (that source's real publication time,
		// per the existing sort below).
		$after  = (string) $request->get_param( 'after' );
		$before = (string) $request->get_param( 'before' );

		// "On this day" (Memories, issue #294): true restricts the whole
		// Timeline to Marks published on today's calendar date in a prior
		// year. Forces the Marks-only skip below exactly like `mine`, and
		// adds a date_query clause over post_date — see the marks-query
		// section further down.
		$on_this_day = rest_sanitize_boolean( $request->get_param( 'on_this_day' ) );

		// datetime-window bounds normalized once so both branches compare
		// the same values against their own date source. REST core has
		// already validated the date-time shape, so strtotime() can be
		// trusted to parse (a genuinely unparseable value would have been
		// rejected at the arg-validation layer).
		$after_mysql  = '' !== $after ? gmdate( 'Y-m-d H:i:s', strtotime( $after ) ) : '';
		$before_mysql = '' !== $before ? gmdate( 'Y-m-d H:i:s', strtotime( $before ) ) : '';

		// Bookmarks live in user meta, not post meta, so there's no
		// meta_query to add — resolve the current user's bookmarked IDs
		// once and restrict both source queries to them via `post__in`.
		// `post__in` combines with each query's own `post_type`, so handing
		// the same full ID list to both branches naturally scopes each to
		// only the IDs that are actually that branch's post type — no need
		// to split the list by type first. An empty bookmark list still
		// needs an explicit `array( 0 )` (a real post ID can never be 0):
		// WP_Query treats a genuinely empty `post__in` as "no restriction",
		// which would defeat the filter instead of correctly returning
		// nothing.
		$bookmarked_post_in = null;
		if ( $bookmarked ) {
			$bookmarked_ids     = Daymark_Plugin::instance()->bookmarks->get_ids( get_current_user_id() );
			$bookmarked_post_in = ! empty( $bookmarked_ids ) ? $bookmarked_ids : array( 0 );
		}

		// `mine` takes precedence over `subscription_id` when both are set:
		// Marks only, subscription posts skipped entirely either way.
		// `on_this_day` (a prior-year post has no subscription-post equivalent, so "On this day" is Marks-only by
		// construction) force the same skip — structurally, a subscription
		// post can never match it, so querying that side would only ever
		// return noise.
		$include_marks              = $mine || 0 === $subscription_id || $on_this_day;
		$include_subscription_posts = ! $mine && ! $on_this_day;

		$want_total = rest_sanitize_boolean( $request->get_param( 'count' ) );
		$total      = 0;

		$items = array();

		if ( $include_marks ) {
			// Deliberately NOT gated on _daymark_is_mark — every published
			// `post` on the site belongs on its own Timeline, whether it
			// came from Daymark's composer or the block editor directly
			// (see this method's own docblock above). The `type` filter
			// below is the one case that still requires a true Mark, since
			// a plain post has no _daymark_primary_type to filter on.
			$marks_args = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'paged'          => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => ! $want_total,
			);

			// A Like/Repost toggle's own Mark (_daymark_like_of/_daymark_repost_of
			// — see the "Subscribed-post engagement" decision, CLAUDE.md) exists
			// purely to give an outbound u-like-of/u-repost-of link for a
			// federation plugin to send; it's not content meant to be read on
			// the Timeline, so both are excluded here unconditionally. Only the
			// Timeline listing is affected — the underlying Mark is still a
			// normal published post everywhere else (Search, wp-admin, the REST
			// API directly).
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Mark lookup.
			$marks_args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'     => '_daymark_like_of',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_daymark_repost_of',
					'compare' => 'NOT EXISTS',
				),
			);

			// Optional content-type filter: narrow to one
			// _daymark_primary_type — only a true Mark carries this meta,
			// so this is the one Marks-query path that still requires
			// _daymark_is_mark (mirrors get_marks()'s own type-filter
			// handling).
			if ( '' !== $type ) {
				$marks_args['meta_query'][] = array(
					'key'   => '_daymark_is_mark',
					'value' => '1',
				);
				$marks_args['meta_query'][] = array(
					'key'   => '_daymark_primary_type',
					'value' => $type,
				);
			}

			if ( '' !== $search ) {
				$marks_args['s'] = $search;
			}

			if ( null !== $bookmarked_post_in ) {
				$marks_args['post__in'] = $bookmarked_post_in;
			}

			// Inclusive single-column window over post_date: "after This
			// Week" should keep a Mark published exactly at the window's
			// boundary, matching how the calendar reads in the app shell.
			$marks_window = array( 'inclusive' => true );
			if ( '' !== $after_mysql ) {
				$marks_window['after'] = $after_mysql;
			}
			if ( '' !== $before_mysql ) {
				$marks_window['before'] = $before_mysql;
			}

			// "On this day" (Memories, issue #294): the same calendar month
			// and day as today in any prior year. The clause uses `month`/
			// `day` with no `year` and an exclusive `before` bound at local
			// midnight today, so only strictly-earlier same-date Marks match
			// (today's own are excluded). `post_date` is stored in the
			// site's own configured timezone, so wp_date() — which applies
			// that same timezone — supplies the month/day and the bound,
			// keeping "this day" aligned with what the author sees on their
			// own calendar. A leap-day query (Feb 29) degrades naturally to
			// empty on non-leap prior years, since no row ever matches.
			if ( $on_this_day ) {
				$marks_window['month']  = (int) wp_date( 'n' );
				$marks_window['day']    = (int) wp_date( 'j' );
				$marks_window['before'] = wp_date( 'Y-m-d 00:00:00' );
			}

			// With both an explicit after/before window and `on_this_day`
			// set, both clauses AND together on the same post_date column.
			if ( count( $marks_window ) > 1 ) {
				$marks_args['date_query'] = array( $marks_window );
			}

			$marks_query = new WP_Query( $marks_args );
			$total      += (int) $marks_query->found_posts;

			foreach ( $marks_query->posts as $post ) {
				// `item_type` is added here rather than inside
				// prepare_mark_summary() itself, so that method's shape stays
				// unchanged for its other callers (GET/POST /marks, GET
				// /marks/{id}) — see prepare_subscription_post_summary()'s
				// docblock for why the discriminator isn't named `type`.
				$items[] = array(
					'date' => (string) $post->post_date_gmt,
					'item' => array( 'item_type' => 'mark' ) + $this->prepare_mark_summary( $post->ID ),
				);
			}
		}

		if ( $include_subscription_posts ) {
			$subscription_posts_args = array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'paged'          => 1,
				'no_found_rows'  => ! $want_total,
				'orderby'        => 'meta_value',
				// Sorts on the source's own published_at, not this site's
				// ingestion time — matches Daymark_Subscription_Poller's
				// pruning query, which orders the same way for the same
				// reason. Y-m-d H:i:s (always GMT) sorts correctly as a
				// plain string.
				'meta_key'       => 'published_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Personal-site-scale Timeline merge.
				'order'          => 'DESC',
			);

			// Optional scoping to one subscription and/or one content type.
			// `meta_key`/`orderby` above (for the published_at sort) stay
			// intact alongside this separate `meta_query` filter — WP_Query
			// supports both together.
			// A followed site's likes stay out of every Timeline listing
			// (issue #168), the same way the user's own Like Marks do just
			// above: a like of some other post says little on its own. The
			// posts are still stored.
			$subscription_meta_conditions = array(
				array(
					'relation' => 'OR',
					array(
						'key'     => Daymark_Subscription_Interaction::META_TYPE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Daymark_Subscription_Interaction::META_TYPE,
						'value'   => Daymark_Subscription_Interaction::HIDDEN_FROM_TIMELINE,
						'compare' => 'NOT IN',
					),
				),
			);

			if ( $subscription_id > 0 ) {
				$subscription_meta_conditions[] = array(
					'key'     => 'subscription_id',
					'value'   => $subscription_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				);
			}

			if ( '' !== $type ) {
				$subscription_meta_conditions[] = array(
					'key'   => 'post_format',
					'value' => $type,
				);
			}

			// published_at range: a single bound is a >= / <= comparison, a
			// bracket is a BETWEEN; either way the DATETIME cast makes the
			// string comparison safe. The bounds were already normalized to
			// the meta value's own 'Y-m-d H:i:s' GMT shape above.
			if ( '' !== $after_mysql && '' !== $before_mysql ) {
				$subscription_meta_conditions[] = array(
					'key'     => 'published_at',
					'value'   => array( $after_mysql, $before_mysql ),
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				);
			} elseif ( '' !== $after_mysql ) {
				$subscription_meta_conditions[] = array(
					'key'     => 'published_at',
					'value'   => $after_mysql,
					'compare' => '>=',
					'type'    => 'DATETIME',
				);
			} elseif ( '' !== $before_mysql ) {
				$subscription_meta_conditions[] = array(
					'key'     => 'published_at',
					'value'   => $before_mysql,
					'compare' => '<=',
					'type'    => 'DATETIME',
				);
			}

			if ( ! empty( $subscription_meta_conditions ) ) {
				$meta_query = 1 === count( $subscription_meta_conditions )
					? $subscription_meta_conditions
					: array_merge( array( 'relation' => 'AND' ), $subscription_meta_conditions );

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Timeline filter.
				$subscription_posts_args['meta_query'] = $meta_query;
			}

			if ( '' !== $search ) {
				$subscription_posts_args['s'] = $search;
			}

			if ( null !== $bookmarked_post_in ) {
				$subscription_posts_args['post__in'] = $bookmarked_post_in;
			}

			$subscription_posts_query = new WP_Query( $subscription_posts_args );
			$total                   += (int) $subscription_posts_query->found_posts;

			foreach ( $subscription_posts_query->posts as $post ) {
				$items[] = array(
					'date' => sanitize_text_field( (string) get_post_meta( $post->ID, 'published_at', true ) ),
					'item' => $this->prepare_subscription_post_summary( $post->ID ),
				);
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		$offset        = ( $page - 1 ) * $per_page;
		$page_of_items = array_slice( $items, $offset, $per_page );

		$response = rest_ensure_response( array_column( $page_of_items, 'item' ) );

		if ( $want_total ) {
			$response->header( 'X-WP-Total', (string) $total );
		}

		return $response;
	}

	/**
	 * POST /daymark/v1/ai/suggestions — AI Assist suggestions.
	 *
	 * Delegates to Daymark_AI_Assist, which falls back to deterministic
	 * mock suggestions when no provider is configured. Never blocks
	 * publishing.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function ai_suggestions( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_AI );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Canonical request fields are `text` and `primary_type`; accept the
		// older `caption`/`type` names as fallbacks.
		$caption    = sanitize_textarea_field( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'caption' ) ) );
		$type       = sanitize_key( (string) ( $request->get_param( 'primary_type' ) ?? $request->get_param( 'type' ) ) );
		$transcript = sanitize_textarea_field( (string) $request->get_param( 'transcript' ) );

		if ( ! in_array( $type, Daymark_Publisher::PRIMARY_TYPES, true ) ) {
			$type = 'note';
		}

		// A transcript (when the composer has one) grounds the suggestion in
		// what the recording actually says — "summarize podcast" via
		// Daymark_AI_Assist::describe_context() rather than a separate call.
		$suggestions = Daymark_Plugin::instance()->ai_assist->get_suggestions(
			array(
				'text'       => $caption,
				'transcript' => $transcript,
			),
			$type
		);

		return rest_ensure_response( $suggestions );
	}

	/**
	 * POST /daymark/v1/ai/title — a short AI-suggested title for a Mark.
	 *
	 * Mirrors /ai/suggestions: delegates to Daymark_AI_Assist, which falls back
	 * to a deterministic mock title when no provider is configured. Used to
	 * pre-fill the composer's optional Title field for audio/video Marks.
	 * Optional and non-blocking — never blocks publishing.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function ai_title( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_AI );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Canonical request fields are `text` and `primary_type`; accept the
		// older `caption`/`type` names as fallbacks (matches /ai/suggestions).
		$caption    = sanitize_textarea_field( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'caption' ) ) );
		$type       = sanitize_key( (string) ( $request->get_param( 'primary_type' ) ?? $request->get_param( 'type' ) ) );
		$transcript = sanitize_textarea_field( (string) $request->get_param( 'transcript' ) );

		if ( ! in_array( $type, Daymark_Publisher::PRIMARY_TYPES, true ) ) {
			$type = 'note';
		}

		$ai    = Daymark_Plugin::instance()->ai_assist;
		$title = $ai->suggest_title(
			array(
				'text'       => $caption,
				'type'       => $type,
				'transcript' => $transcript,
			)
		);

		return rest_ensure_response(
			array(
				'title'          => $title,
				'is_mocked'      => ! $ai->is_available(),
				'provider_label' => $ai->get_provider_label(),
			)
		);
	}

	/**
	 * POST /daymark/v1/ai/tags — an AI-suggested tag list for a Mark.
	 *
	 * Mirrors /ai/suggestions and /ai/title: delegates to
	 * Daymark_AI_Assist::suggest_tags(), which falls back to a deterministic
	 * mock tag list when no provider is configured. Serves two callers: the
	 * manual "AI Assist" sheet, and the composer's own quiet, invisible
	 * background tag suggestion (fired only when the composer has no tags
	 * yet and the author hasn't touched the tag field — see assets/app.js).
	 * Optional and non-blocking either way — never blocks publishing.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function ai_tags( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_AI );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Canonical request fields are `text` and `primary_type`; accept the
		// older `caption`/`type` names as fallbacks (matches /ai/suggestions).
		$caption    = sanitize_textarea_field( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'caption' ) ) );
		$type       = sanitize_key( (string) ( $request->get_param( 'primary_type' ) ?? $request->get_param( 'type' ) ) );
		$transcript = sanitize_textarea_field( (string) $request->get_param( 'transcript' ) );

		if ( ! in_array( $type, Daymark_Publisher::PRIMARY_TYPES, true ) ) {
			$type = 'note';
		}

		$ai   = Daymark_Plugin::instance()->ai_assist;
		$tags = $ai->suggest_tags(
			array(
				'text'       => $caption,
				'type'       => $type,
				'transcript' => $transcript,
			)
		);

		return rest_ensure_response(
			array(
				'tags'           => $tags,
				'is_mocked'      => ! $ai->is_available(),
				'provider_label' => $ai->get_provider_label(),
			)
		);
	}

	/**
	 * GET /daymark/v1/location/reverse-geocode — a short, human-readable
	 * place name for a lat/lng pair, for the Checkin composer's own Place
	 * field (issue #143). Delegates to Daymark_Geocoder::reverse(), which
	 * degrades to null on any failure — never an error response, matching
	 * this endpoint's own optional, best-effort role: a failed lookup just
	 * leaves the composer's Place field blank for the author to type by
	 * hand rather than surfacing an error for what is, either way, a field
	 * the author can always edit before publishing.
	 *
	 * @since 0.17.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function location_reverse_geocode( WP_REST_Request $request ) {
		// With location capture off, no coordinates should be sent anywhere,
		// including to the geocoder.
		if ( ! Daymark_Settings::capture_location() ) {
			return new WP_Error(
				'daymark_location_capture_off',
				__( 'Location capture is turned off on this site.', 'daymark' ),
				array( 'status' => 403 )
			);
		}

		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_LOCATION_LOOKUP );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$lat = $request->get_param( 'lat' );
		$lng = $request->get_param( 'lng' );

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180 ) {
			return rest_ensure_response( array( 'place_name' => null ) );
		}

		return rest_ensure_response(
			array(
				'place_name' => Daymark_Geocoder::reverse( (float) $lat, (float) $lng ),
			)
		);
	}

	/**
	 * GET /daymark/v1/location/search — forward place search ("search as
	 * you type") for the Checkin composer's own Place field, so an author
	 * can pick a real venue instead of only editing the quietly
	 * reverse-geocoded guess. Delegates to Daymark_Geocoder::search(),
	 * sharing the same rate-limit bucket as the reverse lookup above —
	 * one OSM dependency, one outbound-request risk class.
	 *
	 * @since 0.17.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function location_search( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_LOCATION_LOOKUP );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$query = trim( (string) $request->get_param( 'q' ) );

		if ( '' === $query ) {
			return rest_ensure_response( array( 'results' => array() ) );
		}

		return rest_ensure_response(
			array(
				'results' => Daymark_Geocoder::search( $query ),
			)
		);
	}

	/**
	 * The fields a link preview shows, from an Open Graph lookup result:
	 * title, description, and image, each '' when missing.
	 *
	 * @param array<string, mixed> $og Daymark_Subscription_Opengraph result.
	 * @return array{title: string, description: string, image: string}
	 */
	private static function link_preview_fields( array $og ): array {
		return array(
			'title'       => sanitize_text_field( (string) ( $og['title'] ?? '' ) ),
			'description' => sanitize_text_field( (string) ( $og['description'] ?? '' ) ),
			'image'       => esc_url_raw( (string) ( $og['image'] ?? '' ) ),
		);
	}

	/**
	 * GET /daymark/v1/marks/{id}/featured-content-link — the preview of a
	 * post's link Featured Content (the linked page's title, description,
	 * and image), for its Timeline card and full post view. Returns the
	 * cached Open Graph lookup when there is one; otherwise looks the page
	 * up once through Daymark_Subscription_Opengraph::resolve() (guarded,
	 * size-capped, and cached, failures included). Only that lookup costs a
	 * rate-limit slot.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_featured_content_link( WP_REST_Request $request ) {
		$post = get_post( absint( $request->get_param( 'id' ) ) );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$fc = Daymark_Featured_Content::get_featured_content( $post );

		if ( empty( $fc ) || 'link' !== $fc['type'] ) {
			return rest_ensure_response( array( 'preview' => null ) );
		}

		$saved = Daymark_Featured_Content_Social::link_preview( $post );

		if ( null !== $saved ) {
			return rest_ensure_response( array( 'preview' => $saved ) );
		}

		$url = (string) ( $fc['data']['url'] ?? '' );
		$og  = Daymark_Subscription_Opengraph::cached( $url );

		if ( null === $og ) {
			$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_FEATURED_CONTENT_OEMBED );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			$og = Daymark_Subscription_Opengraph::resolve( $url );
		}

		// Saved on the post, so its own page shows the preview from now on
		// (a Link Featured Content set before previews were saved gets one
		// the first time a signed-in user sees its card in the app).
		return rest_ensure_response( array( 'preview' => Daymark_Featured_Content_Social::store_link_preview( $post->ID, $fc, (array) $og ) ) );
	}

	/**
	 * GET /daymark/v1/marks/{id}/featured-content-image — the thumbnail a
	 * Timeline card shows for a post's video or audio Featured Content.
	 *
	 * Normally Daymark_Featured_Content_Social resolves it in the background
	 * when the Featured Content is saved, and the Timeline summary already
	 * carries it. This fills the gap when that never happened: Featured
	 * Content saved before the background resolution existed, or a site
	 * with WP-Cron turned off. It returns the stored image when there is
	 * one; otherwise it resolves it once, through the same guarded,
	 * cached path, and stores it, so the next Timeline load has it too.
	 * Only resolving costs a rate-limit slot.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_featured_content_image( WP_REST_Request $request ) {
		$post = get_post( absint( $request->get_param( 'id' ) ) );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$fc = Daymark_Featured_Content::get_featured_content( $post );

		if ( empty( $fc ) || ! in_array( $fc['type'], array( 'video', 'audio' ), true ) ) {
			return rest_ensure_response( array( 'url' => '' ) );
		}

		$image = Daymark_Featured_Content_Social::image( $post );

		if ( empty( $image['url'] ) && 'url' === ( $fc['data']['source'] ?? '' ) ) {
			$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_FEATURED_CONTENT_OEMBED );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			( new Daymark_Featured_Content_Social() )->resolve_remote_image( $post->ID );
			$image = Daymark_Featured_Content_Social::image( $post );
		}

		return rest_ensure_response( array( 'url' => empty( $image['url'] ) ? '' : esc_url_raw( $image['url'] ) ) );
	}

	/**
	 * GET /daymark/v1/featured-content/oembed — profiles a URL typed into
	 * the Featured Content editor panel (issue #401), so an author sees a
	 * live preview of what a YouTube/Vimeo/podcast-episode link will
	 * actually render as before saving. Delegates entirely to
	 * Daymark_Subscription_Oembed::resolve() — already fully generic
	 * (SSRF-guarded, size-capped, cached, never trusts a provider's raw
	 * HTML) with no subscription-specific logic in it, reused here rather
	 * than forked into a second resolver. Degrades to an empty result on
	 * any failure, matching that class's own "never throws" contract —
	 * the panel's own fallback (a plain link/file field) still works either
	 * way.
	 *
	 * @since 0.18.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function featured_content_oembed( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_FEATURED_CONTENT_OEMBED );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$url = trim( (string) $request->get_param( 'url' ) );

		if ( '' === $url ) {
			return rest_ensure_response( array( 'embed' => null ) );
		}

		// Preview under the same rule the front end will render by: discovery
		// depends on the post's author, so an Editor previewing an Author's
		// post must see what that post will really show. With no post given
		// (or one the caller cannot edit), the caller's own rights apply.
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
			$user_id = (int) $post->post_author;
		} else {
			$post    = null;
			$user_id = get_current_user_id();
		}

		$embed = Daymark_Subscription_Oembed::resolve( $url, Daymark_Featured_Content::oembed_discovery_allowed( $user_id, $post ) );

		return rest_ensure_response(
			array(
				'embed' => empty( $embed ) ? null : $embed,
			)
		);
	}

	/**
	 * Sanitize an optional REST float param: numeric input becomes a float,
	 * anything else (missing, non-numeric) becomes null so a caller can
	 * tell "no value supplied" apart from a real 0.0. Range validation
	 * happens in Daymark_Publisher::resolve_location(), which silently
	 * drops an out-of-range value rather than failing the request — this
	 * callback only normalizes type.
	 *
	 * @since 0.11.0
	 *
	 * @param mixed $value Raw REST param value.
	 * @return float|null
	 */
	public static function sanitize_optional_float( $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * POST /daymark/v1/ai/alt-text — vision alt text for one uploaded image.
	 *
	 * Reads the uploaded image from the temp upload (no attachment is
	 * created) and returns AI-generated alt text so the composer can
	 * pre-fill an editable per-image field. Falls back to mock alt when no
	 * provider is configured. Requires the upload capability since it
	 * accepts an uploaded file.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_alt_text( WP_REST_Request $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_AI );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files = $request->get_file_params();
		$image = isset( $files['image'] ) && is_array( $files['image'] ) ? $files['image'] : null;

		if ( ! $image || empty( $image['tmp_name'] ) || ! is_readable( $image['tmp_name'] ) ) {
			return new WP_Error(
				'daymark_no_image',
				__( 'No readable image was provided.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// Validate from content, not the extension — and only images.
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = (string) $finfo->file( $image['tmp_name'] );

		if ( ! str_starts_with( $mime, 'image/' ) || ! in_array( $mime, Daymark_Publisher::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'daymark_not_an_image',
				__( 'Alt text can only be generated for images.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// AI vision providers read JPEG, PNG, GIF, and WebP; an AVIF or a
		// HEIC can only fail there (#481).
		if ( ! in_array( $mime, Daymark_Publisher::VISION_MIME_TYPES, true ) ) {
			return new WP_Error(
				'daymark_alt_text_format_unsupported',
				__( "Alt text can't be suggested for this image format.", 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$context = array(
			'text'         => sanitize_textarea_field( (string) $request->get_param( 'text' ) ),
			'type'         => 'image',
			// Present only for the composer's manual "Improve with AI" tap —
			// asks the provider to refine this value rather than describe
			// the image from scratch. Absent on the automatic first-pick pass.
			'existing_alt' => sanitize_text_field( (string) $request->get_param( 'existing_alt' ) ),
		);

		$suggestion = Daymark_Plugin::instance()->ai_assist->get_image_alt_suggestion( (string) $image['tmp_name'], $context );

		return rest_ensure_response( $suggestion );
	}

	/**
	 * POST /daymark/v1/ai/transcript — transcribe one uploaded audio/video
	 * file for the composer's Transcript field.
	 *
	 * Manual and author-triggered (the composer never calls this
	 * automatically on file pick, unlike /ai/alt-text) — a recording can be
	 * large, so it is only ever sent to the provider when the author taps
	 * "Generate transcript". Reads the uploaded file from the temp upload
	 * (no attachment is created) and falls back to an empty transcript when
	 * no provider is configured or the call fails; never blocks publishing.
	 *
	 * @since 0.8.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_transcript( WP_REST_Request $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_AI );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files = $request->get_file_params();
		$media = isset( $files['media'] ) && is_array( $files['media'] ) ? $files['media'] : null;

		if ( ! $media || empty( $media['tmp_name'] ) || ! is_readable( $media['tmp_name'] ) ) {
			return new WP_Error(
				'daymark_no_media',
				__( 'No readable audio or video file was provided.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// Validate from content, not the extension — audio or video only.
		// canonical_mime() resolves content-sniffing aliases (e.g. a WAV
		// file reported as audio/x-wav) the same way Daymark_Publisher's
		// own upload validation already does, so the two never disagree.
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = Daymark_Publisher::canonical_mime( (string) $finfo->file( $media['tmp_name'] ) );

		$is_transcribable = str_starts_with( $mime, 'audio/' ) || str_starts_with( $mime, 'video/' );

		if ( ! $is_transcribable || ! in_array( $mime, Daymark_Publisher::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'daymark_not_transcribable',
				__( 'A transcript can only be generated from an audio or video file.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$suggestion = Daymark_Plugin::instance()->ai_assist->get_transcript_suggestion( (string) $media['tmp_name'] );

		return rest_ensure_response( $suggestion );
	}

	/**
	 * POST /daymark/v1/marks/{id}/sync-responses — import mocked social
	 * responses for a Mark (conversation backflow).
	 *
	 * Accepts { "networks": ["bluesky", "instagram"] }; empty or missing
	 * networks means every network in _daymark_external_posts. All imports
	 * are mocked — a real connector would plug into
	 * Daymark_Notifications::import_response().
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_responses( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SYNC );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$post_id  = absint( $request->get_param( 'id' ) );
		$networks = $request->get_param( 'networks' );

		// Accept a JSON-encoded string body field as a fallback.
		if ( is_string( $networks ) ) {
			$decoded  = json_decode( $networks, true );
			$networks = is_array( $decoded ) ? $decoded : array( $networks );
		}

		if ( ! is_array( $networks ) ) {
			$networks = array();
		}

		$networks = array_filter( array_map( 'sanitize_key', array_map( 'strval', $networks ) ) );

		// Real connector syncs honor the same per-post cooldown as the cron
		// path, so manual polling can't hammer a platform API. Mocked demo
		// syncs stay instant and repeat-safe (they dedupe instead).
		$backflow = Daymark_Plugin::instance()->backflow_sync;

		if ( $backflow->is_real_backflow_sync( $post_id, $networks ) ) {
			if ( $backflow->on_cooldown( $post_id ) ) {
				return new WP_Error(
					'daymark_sync_cooldown',
					__( 'Please wait a few minutes before syncing this Mark again.', 'daymark' ),
					array(
						'status'      => 429,
						'retry_after' => Daymark_Backflow_Sync::POST_COOLDOWN_SECONDS,
					)
				);
			}
		}

		$result = Daymark_Plugin::instance()->notifications->import_responses( $post_id, $networks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record the cooldown after a real sync so the endpoint and the
		// cron share one 10-minute window per Mark.
		if ( $backflow->is_real_backflow_sync( $post_id, $networks ) ) {
			$backflow->mark_cooldown( $post_id );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /daymark/v1/notifications — unified Daymark activity list.
	 *
	 * Returns approved comments (on-site and imported social responses)
	 * for Daymark-created posts only. Comments on non-Mark posts are
	 * excluded server-side by Daymark_Notifications.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_notifications( WP_REST_Request $request ) {
		// Viewing the feed freshens it: a stale feed schedules an async
		// background sync (never a manual control, never blocks this request).
		Daymark_Plugin::instance()->backflow_sync->maybe_freshen();

		unset( $request ); // No query args yet; Daymark-only scope is enforced server-side.

		$notifications = Daymark_Plugin::instance()->notifications;
		// Read the previous visit before marking this one, so each item
		// can say whether it arrived since then (`is_new`).
		$items = $notifications->get_notifications( Daymark_Notifications::DEFAULT_LIMIT, $notifications->get_seen() );

		// This endpoint backs the notifications screen, so serving it IS
		// the user seeing their notifications — clear the unread flag.
		$notifications->mark_seen();

		return rest_ensure_response( $items );
	}

	/**
	 * GET /daymark/v1/notifications/status — whether the current user has
	 * unread notifications, without marking anything seen. The app checks
	 * it when it comes back to the foreground and when you move between
	 * screens, so the bell's dot updates during a visit instead of only on
	 * a page load.
	 *
	 * @since 0.20.0
	 *
	 * @param WP_REST_Request $request The request (no params).
	 * @return WP_REST_Response
	 */
	public function get_notifications_status( WP_REST_Request $request ) {
		unset( $request );

		return rest_ensure_response(
			array( 'has_unread' => Daymark_Plugin::instance()->notifications->has_unread() )
		);
	}

	/**
	 * GET /daymark/v1/marks/{id} — full editable payload for the composer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_daymark( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Not a Mark post.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$caption = (string) get_post_meta( $post_id, '_daymark_caption', true );

		// Marks created before the caption meta existed: recover the
		// paragraph text from the derived block markup.
		if ( '' === $caption && preg_match_all( '#<p>(.*?)</p>#s', $post->post_content, $matches ) ) {
			$caption = implode( "\n\n", array_map( 'wp_strip_all_tags', $matches[1] ) );
		}

		$media_ids = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );
		$media_ids = is_array( $media_ids ) ? array_map( 'intval', $media_ids ) : array();
		$media     = array();

		foreach ( $media_ids as $attachment_id ) {
			if ( ! get_post( $attachment_id ) ) {
				continue;
			}

			$kind = 'file';
			if ( wp_attachment_is_image( $attachment_id ) ) {
				$kind = 'image';
			} elseif ( wp_attachment_is( 'video', $attachment_id ) ) {
				$kind = 'video';
			} elseif ( wp_attachment_is( 'audio', $attachment_id ) ) {
				$kind = 'audio';
			}

			$thumbnail = wp_get_attachment_image_url( $attachment_id, 'medium' );

			$media[] = array(
				'id'        => $attachment_id,
				'kind'      => $kind,
				'thumbnail' => $thumbnail ? esc_url_raw( $thumbnail ) : '',
				'filename'  => sanitize_file_name( basename( (string) get_attached_file( $attachment_id ) ) ),
				// Current alt text so the composer can show it editable
				// (images only; other media carry an empty string).
				'alt'       => 'image' === $kind ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '',
			);
		}

		$targets = json_decode( (string) get_post_meta( $post_id, '_daymark_syndication_targets', true ), true );
		$helpers = json_decode( (string) get_post_meta( $post_id, Daymark_Publish_Helpers::CONTROL_META, true ), true );

		$target_list = is_array( $targets ) ? array_values( array_filter( array_map( 'sanitize_key', $targets ) ) ) : array();

		$payload                = $this->prepare_mark_summary( $post_id );
		$payload['caption']     = $caption;
		$payload['transcript']  = (string) get_post_meta( $post_id, '_daymark_transcript', true );
		$payload['media']       = $media;
		$payload['targets']     = $target_list;
		$payload['helpers']     = is_array( $helpers ) ? array_values( array_filter( array_map( 'sanitize_key', $helpers ) ) ) : array();
		$payload['categories']  = array_map( 'intval', wp_get_post_categories( $post_id ) );
		$payload['in_reply_to'] = (string) get_post_meta( $post_id, '_daymark_in_reply_to', true );
		// Per-connector routing detail (issue #255 — "where did this go"),
		// server-resolved so it's accurate even for a connector that's
		// since been disconnected/deactivated, unlike the client's own
		// connectorLabel() (config.connectors is filtered to currently-
		// connected connectors only).
		$payload['external_posts'] = $this->prepare_external_posts( $post_id, $target_list );

		return rest_ensure_response( $payload );
	}

	/**
	 * Per-connector routing detail for a Mark's selected targets (issue
	 * #255 — "where did this go"). Reads the stored _daymark_external_posts
	 * reference — label/status/url captured at syndication time, including
	 * a failed/unsupported attempt since
	 * Daymark_Syndication_Registry::store_results() started recording
	 * those too — for every target that was actually attempted. A target
	 * present in _daymark_syndication_targets but absent from
	 * _daymark_external_posts (a Mark published before that fix shipped)
	 * falls back to a live-resolved label with an 'unknown' outcome rather
	 * than silently vanishing from the response.
	 *
	 * A `backflow_supported` target also carries `backflow_last_synced_at`
	 * (issue #258) — when its replies were last actually checked, from
	 * Daymark_Backflow_Sync::last_synced_at(), or '' when never synced.
	 * Empty for any non-backflow-supported target: a mock/demo reference's
	 * replies aren't "checked" from a live source, so surfacing a sync
	 * recency for one would be misleading.
	 *
	 * @param int      $post_id Mark post ID.
	 * @param string[] $targets Sanitized target connector IDs.
	 * @return array<string, array<string, mixed>> Keyed by connector ID.
	 */
	private function prepare_external_posts( int $post_id, array $targets ): array {
		$stored        = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );
		$stored        = is_array( $stored ) ? $stored : array();
		$registry      = Daymark_Syndication_Registry::instance();
		$backflow_sync = Daymark_Plugin::instance()->backflow_sync;
		$result        = array();

		foreach ( $targets as $connector_id ) {
			$entry = isset( $stored[ $connector_id ] ) && is_array( $stored[ $connector_id ] ) ? $stored[ $connector_id ] : null;

			if ( null !== $entry ) {
				$result[ $connector_id ] = array(
					'label'                   => sanitize_text_field( (string) ( $entry['label'] ?? $connector_id ) ),
					'status'                  => sanitize_key( (string) ( $entry['status'] ?? 'unknown' ) ),
					'external_url'            => ! empty( $entry['external_url'] ) ? esc_url_raw( (string) $entry['external_url'] ) : '',
					'message'                 => sanitize_text_field( (string) ( $entry['message'] ?? '' ) ),
					'backflow_supported'      => ! empty( $entry['backflow_supported'] ),
					'backflow_last_synced_at' => ! empty( $entry['backflow_supported'] )
						? $backflow_sync->last_synced_at( $post_id, $connector_id )
						: '',
				);
				continue;
			}

			$connector               = $registry->get_connector( $connector_id );
			$result[ $connector_id ] = array(
				'label'                   => $connector ? $connector->get_label() : $connector_id,
				'status'                  => 'unknown',
				'external_url'            => '',
				'message'                 => '',
				'backflow_supported'      => false,
				'backflow_last_synced_at' => '',
			);
		}

		return $result;
	}

	/**
	 * GET /daymark/v1/marks/{id}/content — the full-screen post view's own
	 * fetch: just the post's rendered content, not the page around it.
	 *
	 * Unlike get_daymark(), this is not gated on `_daymark_is_mark` — it
	 * backs the same post view for a true Mark, an ordinary post
	 * published straight through the block editor, or anything else
	 * GET /timeline itself already surfaces (see that method's own
	 * docblock on why the Marks side isn't gated on _daymark_is_mark
	 * either). Any published `post`-type post is fair game; anything else
	 * (draft, another post type, nonexistent) 404s.
	 *
	 * Mirrors WP core's own REST Posts Controller
	 * (WP_REST_Posts_Controller::prepare_item_for_response()): render via
	 * `apply_filters( 'the_content', $post->post_content )` directly,
	 * without setup_postdata() — the same filter every theme's own
	 * the_content() call ultimately runs, applied to one post's content in
	 * isolation rather than the whole page it would otherwise render
	 * inside (comments, sidebar, footer, theme chrome). wp_kses_post() on
	 * top is defense in depth, matching how a subscription post's own
	 * body_content is handled.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_mark_content( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		// apply_filters( 'the_content' ) below does not enforce a post
		// password the way a front-end template does, so without this any
		// Author or Contributor could read the full body of a protected post
		// (someone else's, or one the site owner deliberately locked).
		// Whoever can edit the post can already read it, so they are exempt.
		if ( post_password_required( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'daymark_password_protected',
				__( 'This post is password protected.', 'daymark' ),
				array( 'status' => 403 )
			);
		}

		// Core's gallery block needs a theme stylesheet the app shell doesn't
		// have, so it would show as stacked images. Each one is swapped for a
		// placeholder while the content renders, then for the same slider a
		// gallery Featured Content uses once the content is sanitized (the
		// slider's own daymark-fc-* classes would not survive the strip).
		$gallery_slots = array();
		$slot_token    = 'DAYMARKGALLERYSLOT' . wp_generate_password( 12, false );
		$swap_gallery  = static function ( $block_content, $block ) use ( &$gallery_slots, $slot_token ) {
			$captions = self::gallery_block_captions( (array) $block );
			$slider   = Daymark_Featured_Content::render_gallery_for_app( array_keys( $captions ), $captions );

			if ( '' === $slider || substr_count( $slider, 'daymark-fc-gallery__slide' ) < 2 ) {
				return $block_content;
			}

			$gallery_slots[] = $slider;

			return '<p>' . $slot_token . ( count( $gallery_slots ) - 1 ) . '</p>';
		};

		// An embed (a Reblog Mark's reblogged post, or a video pasted into
		// any post) arrives as the provider's iframe, which wp_kses_post()
		// removes. Each one is swapped for a placeholder and later for the
		// same rebuilt, allowlisted iframe or image the app's link previews
		// use. Its caption is kept. An embed WordPress couldn't resolve is
		// just a link, and is left as it is.
		$embed_slots = array();
		$embed_token = 'DAYMARKEMBEDSLOT' . wp_generate_password( 12, false );
		$swap_embed  = static function ( $block_content ) use ( &$embed_slots, $embed_token ) {
			$block_content = (string) $block_content;
			$caption_at    = stripos( $block_content, '<figcaption' );
			$body          = false === $caption_at ? $block_content : substr( $block_content, 0, $caption_at );
			$embed         = Daymark_Subscription_Oembed::safe_embed_from_html( $body );

			if ( empty( $embed['html'] ) ) {
				return $block_content;
			}

			$embed_slots[] = $embed['html'];
			$caption       = '';

			if ( false !== $caption_at && preg_match( '#<figcaption\b[^>]*>.*?</figcaption>#is', $block_content, $found ) ) {
				$caption = $found[0];
			}

			return '<figure class="wp-block-embed"><p>' . $embed_token . ( count( $embed_slots ) - 1 ) . '</p>' . $caption . '</figure>';
		};

		add_filter( 'render_block_core/gallery', $swap_gallery, 10, 2 );
		add_filter( 'render_block_core/embed', $swap_embed, 10, 1 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying WordPress core's own 'the_content' filter, not defining a new hook.
		$content = apply_filters( 'the_content', $post->post_content );
		remove_filter( 'render_block_core/gallery', $swap_gallery, 10 );
		remove_filter( 'render_block_core/embed', $swap_embed, 10 );

		// A Mark's content is written by whichever user published it, and
		// wp_kses_post() keeps `class`, so an Author could otherwise give a
		// post `class="daymark-sheet"` and have it render as a fixed,
		// full-screen layer over an Editor's app. Any `daymark-` class is
		// dropped except the two the Check In map preview legitimately emits
		// (Daymark_Publisher::build_map_preview_block()); inline styles stay,
		// since that map's pin is positioned with one and block-editor
		// content uses them for ordinary spacing and color.
		$content = Daymark_Subscription_Poller::strip_untrusted_presentation(
			wp_kses_post( $content ),
			false,
			array( 'daymark-checkin-map', 'daymark-checkin-map__pin' )
		);

		if ( ! empty( $gallery_slots ) ) {
			$content = (string) preg_replace_callback(
				'#<p>\s*' . preg_quote( $slot_token, '#' ) . '(\d+)\s*</p>#',
				static function ( $found ) use ( $gallery_slots ) {
					return $gallery_slots[ (int) $found[1] ] ?? '';
				},
				$content
			);
		}

		if ( ! empty( $embed_slots ) ) {
			$content = (string) preg_replace_callback(
				'#<p>\s*' . preg_quote( $embed_token, '#' ) . '(\d+)\s*</p>#',
				static function ( $found ) use ( $embed_slots ) {
					$html = $embed_slots[ (int) $found[1] ] ?? '';

					return '' === $html ? '' : '<div class="daymark-oembed-preview">' . $html . '</div>';
				},
				$content
			);
		}

		return rest_ensure_response(
			array(
				'content'  => $content,
				'featured' => $this->postview_featured_markup( $post, $content ),
			)
		);
	}

	/**
	 * A core/gallery block's images and their captions, in display order:
	 * attachment ID => the caption written in that image's own block (its
	 * `<figcaption>`), or '' when it has none. Reads the inner core/image
	 * blocks (WordPress 5.9 and later), or the older `ids` attribute, which
	 * has no per-image captions.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<int, string>
	 */
	private static function gallery_block_captions( array $block ): array {
		$captions = array();

		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			$id = absint( $inner['attrs']['id'] ?? 0 );

			if ( 'core/image' !== ( $inner['blockName'] ?? '' ) || $id <= 0 || isset( $captions[ $id ] ) ) {
				continue;
			}

			$caption = '';

			if ( preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#is', (string) ( $inner['innerHTML'] ?? '' ), $found ) ) {
				$caption = $found[1];
			}

			$captions[ $id ] = $caption;
		}

		if ( empty( $captions ) && ! empty( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
			foreach ( array_filter( array_map( 'absint', $block['attrs']['ids'] ) ) as $id ) {
				$captions[ $id ] = '';
			}
		}

		return $captions;
	}

	/**
	 * What the app's full post view shows above a post's content: its
	 * Featured Content when set, otherwise its featured image. The featured
	 * image is left out when the content already shows it, which is the
	 * normal case for an image Mark, since the publisher makes a Mark's
	 * first photo its featured image.
	 *
	 * Featured Content markup is built by Daymark_Featured_Content from
	 * sanitized meta, so it is not run through the class-stripping above:
	 * its own `daymark-fc-*` classes are what the gallery slider needs.
	 *
	 * @param WP_Post $post    The post.
	 * @param string  $content The post's rendered content.
	 * @return string Markup, or '' when there is nothing to show.
	 */
	private function postview_featured_markup( WP_Post $post, string $content ): string {
		$featured_content = Daymark_Featured_Content::render_for_app( $post );

		if ( '' !== $featured_content ) {
			return $featured_content;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );

		if ( $thumbnail_id <= 0 || self::content_shows_attachment( $content, $thumbnail_id ) ) {
			return '';
		}

		// A GIF at full size, so an animated one keeps its animation (#482).
		return (string) wp_get_attachment_image( $thumbnail_id, Daymark_Publisher::display_size( $thumbnail_id, 'large' ) );
	}

	/**
	 * Whether rendered content already shows an image attachment: by the
	 * `wp-image-{id}` class core's image and gallery blocks add, or by the
	 * file's path with its extension and WordPress's `-scaled` suffix
	 * removed, followed by "." or "-", which also matches any resized copy
	 * (`photo-1024x768.jpg`).
	 *
	 * @param string $content       Rendered content.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function content_shows_attachment( string $content, int $attachment_id ): bool {
		if ( '' === $content ) {
			return false;
		}

		if ( preg_match( '/\bwp-image-' . $attachment_id . '\b/', $content ) ) {
			return true;
		}

		$url = (string) wp_get_attachment_url( $attachment_id );

		if ( '' === $url ) {
			return false;
		}

		$stem = preg_replace( array( '/\.[a-z0-9]+$/i', '/-scaled$/i' ), '', (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( '' === (string) $stem ) {
			return false;
		}

		return false !== strpos( $content, $stem . '.' ) || false !== strpos( $content, $stem . '-' );
	}

	/**
	 * Validates that an ID refers to something bookmarkable: a published
	 * `post` (Mark or ordinary post — same "any published post" rule
	 * get_mark_content() already applies) or a published
	 * `daymark_subscription_post`. Anything else (draft, another post type,
	 * nonexistent) isn't a real Timeline item, so bookmarking it makes no
	 * sense.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	private function assert_bookmarkable( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $post->post_type, array( 'post', Daymark_Subscription_Post_Type::POST_TYPE ), true ) ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Validates that an ID refers to a published `daymark_subscription_post`
	 * — the only kind of item Like/Comment engagement (Jetpack-native or
	 * classic) makes sense for. Mirrors assert_bookmarkable()'s shape but is
	 * scoped narrower: a Mark has no `permalink` post meta to engage with in
	 * the first place, so it's excluded here rather than silently no-op'd.
	 *
	 * Every /subscription-posts/{id} route calls this before reading any
	 * meta or post field off the ID: without it, an Author-level caller
	 * could pass any post's ID (another author's draft, a private page) and
	 * get its title and excerpt back through a route meant only for cached
	 * subscription posts.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Error code to return when the check fails; a route
	 *                        that already had its own 404 code keeps it.
	 * @return true|WP_Error
	 */
	private function assert_subscription_post( int $post_id, string $code = 'daymark_not_found' ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || Daymark_Subscription_Post_Type::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				$code,
				__( 'Post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * POST /daymark/v1/bookmarks/{id} — bookmark a Timeline item (a Mark or
	 * a cached subscription post) for the current user, for offline
	 * viewing in Explore's Bookmarks section.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_bookmark( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$check   = $this->assert_bookmarkable( $post_id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		Daymark_Plugin::instance()->bookmarks->add( get_current_user_id(), $post_id );

		return rest_ensure_response(
			array(
				'id'         => $post_id,
				'bookmarked' => true,
			)
		);
	}

	/**
	 * GET /bookmarks/{id}/image?url= — one off-site image of a bookmarked
	 * post, fetched by this site so the app can save it for offline reading
	 * (issue #455). The app's CSP and most sites' missing CORS headers stop
	 * the app from downloading it directly. See Daymark_Bookmark_Images.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error `{ mime, data }`, data base64.
	 */
	public function get_bookmark_image( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_BOOKMARK_IMAGE );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$result = Daymark_Bookmark_Images::fetch( absint( $request->get_param( 'id' ) ), (string) $request->get_param( 'url' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * DELETE /daymark/v1/bookmarks/{id} — remove a bookmark.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_bookmark( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$check   = $this->assert_bookmarkable( $post_id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		Daymark_Plugin::instance()->bookmarks->remove( get_current_user_id(), $post_id );

		return rest_ensure_response(
			array(
				'id'         => $post_id,
				'bookmarked' => false,
			)
		);
	}

	/**
	 * POST /daymark/v1/timeline/last-seen — record that the current user has
	 * seen a Timeline item on Home. The stored marker only moves to a newer
	 * item (see Daymark_Timeline_Position::mark_seen()); the response is
	 * the marker after the update, so the app can keep its copy in step.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_timeline_seen( WP_REST_Request $request ) {
		$marker = Daymark_Timeline_Position::mark_seen(
			get_current_user_id(),
			absint( $request->get_param( 'id' ) )
		);

		if ( is_wp_error( $marker ) ) {
			return $marker;
		}

		return rest_ensure_response( array( 'last_seen' => $marker ) );
	}

	/**
	 * POST /daymark/v1/timeline/position — record the Timeline item at the
	 * top of the current user's screen on Home, so the next visit opens
	 * there (see Daymark_Timeline_Position::set_position()).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_timeline_position( WP_REST_Request $request ) {
		$position = Daymark_Timeline_Position::set_position(
			get_current_user_id(),
			absint( $request->get_param( 'id' ) )
		);

		if ( is_wp_error( $position ) ) {
			return $position;
		}

		return rest_ensure_response( array( 'position' => $position ) );
	}

	/**
	 * POST /daymark/v1/interaction-hints/{hint}/seen — record that the
	 * current user has seen an interaction-row explainer overlay, so it
	 * stays dismissed on every device they use (issue #322). The response
	 * lists every hint key seen after the update.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_interaction_hint_seen( WP_REST_Request $request ) {
		$seen = Daymark_Interaction_Hints::mark_seen(
			get_current_user_id(),
			sanitize_key( (string) $request->get_param( 'hint' ) )
		);

		if ( is_wp_error( $seen ) ) {
			return $seen;
		}

		return rest_ensure_response( array( 'seen' => $seen ) );
	}

	/**
	 * POST /daymark/v1/notifications/plugin-overlaps/{plugin}/dismiss —
	 * dismiss a plugin-overlap Notifications item (issue #346) for the
	 * current user. One-way: there's no matching "un-dismiss" route, since
	 * nothing in this feature ever needs to bring a dismissed notice back.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_plugin_overlap( WP_REST_Request $request ) {
		$plugin_key = sanitize_key( (string) $request->get_param( 'plugin' ) );
		$overlap    = Daymark_Plugin::instance()->plugin_overlap;

		if ( ! $overlap->is_known( $plugin_key ) ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Unknown plugin.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$overlap->dismiss( get_current_user_id(), $plugin_key );

		return rest_ensure_response(
			array(
				'plugin'    => $plugin_key,
				'dismissed' => true,
			)
		);
	}

	/**
	 * POST/PUT /daymark/v1/marks/{id} — update a Mark from the composer.
	 *
	 * The composer's autosave also calls this (with `autosave=1`, which only
	 * changes which rate-limit bucket applies) on every subsequent save of an
	 * in-progress Mark once autosave has created its first draft via
	 * create_mark().
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_daymark( WP_REST_Request $request ) {
		$is_autosave = rest_sanitize_boolean( $request->get_param( 'autosave' ) );
		$rate        = $this->rate_limit( $is_autosave ? Daymark_Rate_Limiter::ACTION_AUTOSAVE : Daymark_Rate_Limiter::ACTION_PUBLISH );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files     = $request->get_file_params();
		$media_ids = $request->get_param( 'media_ids' );

		if ( ( ! empty( $files ) || ! empty( $media_ids ) ) && ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'daymark' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$targets = $request->get_param( 'targets' );
		if ( null === $targets ) {
			$targets = $request->get_param( 'syndication_targets' );
		}

		$data = array(
			'caption'             => wp_kses_post( (string) $request->get_param( 'caption' ) ),
			'title'               => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'primary_type'        => sanitize_key( (string) $request->get_param( 'primary_type' ) ),
			'syndication_targets' => $targets,
			'categories'          => $request->get_param( 'categories' ),
			'alt_text'            => sanitize_text_field( (string) $request->get_param( 'alt_text' ) ),
			// Per-image alt: positional array for newly added files, plus a
			// map keyed by attachment ID for media already on the Mark.
			'alt'                 => $request->get_param( 'alt' ),
			'existing_alt'        => $request->get_param( 'existing_alt' ),
			// Author-chosen reorder of the Mark's already-attached media
			// (issue #250) — an ordered list of attachment IDs, honored only
			// when it's an exact permutation of the stored media list. See
			// Daymark_Publisher::apply_media_order().
			'media_order'         => $request->get_param( 'media_order' ),
			// Files already uploaded through Daymark_Uploads (issue #483).
			'media_ids'           => $media_ids,
			'tags'                => $request->get_param( 'tags' ),
			'transcript'          => sanitize_textarea_field( (string) $request->get_param( 'transcript' ) ),
			// Quiet metadata capture — see create_mark()'s matching comment.
			'captured_at'         => sanitize_text_field( (string) $request->get_param( 'captured_at' ) ),
			'location_lat'        => $request->get_param( 'location_lat' ),
			'location_lng'        => $request->get_param( 'location_lng' ),
			'location_accuracy'   => $request->get_param( 'location_accuracy' ),
			'place_name'          => sanitize_text_field( (string) $request->get_param( 'place_name' ) ),
			'in_reply_to'         => (string) $request->get_param( 'in_reply_to' ),
			'repost_of'           => (string) $request->get_param( 'repost_of' ),
			'like_of'             => (string) $request->get_param( 'like_of' ),
		);

		if ( null !== $request->get_param( 'publish_helpers' ) ) {
			$data['publish_helpers'] = $request->get_param( 'publish_helpers' );
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$data['status'] = $status;
		}

		$result = Daymark_Plugin::instance()->publisher->update(
			absint( $request->get_param( 'id' ) ),
			$data,
			$files
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_mark_summary( (int) $result ) );
	}

	/**
	 * DELETE /daymark/v1/marks/{id} — trash a Mark.
	 *
	 * Reversible: sends the post to the trash via wp_trash_post rather than
	 * deleting it permanently. Scoped to Mark posts, so a non-Mark id is a
	 * 404. Idempotent — an already-trashed Mark returns success.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_daymark( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return new WP_Error(
				'daymark_not_found',
				__( 'Not a Mark post.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		// Already trashed: idempotent success, no second trash needed.
		if ( 'trash' === $post->post_status ) {
			return rest_ensure_response(
				array(
					'id'      => $post_id,
					'trashed' => true,
					'status'  => 'trash',
				)
			);
		}

		$trashed = wp_trash_post( $post_id );

		if ( ! $trashed ) {
			return new WP_Error(
				'daymark_trash_failed',
				__( 'The Mark could not be trashed.', 'daymark' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'id'      => $post_id,
				'trashed' => true,
				'status'  => sanitize_key( (string) get_post_status( $post_id ) ),
			)
		);
	}

	/**
	 * POST /daymark/v1/notifications/{comment_id}/reply — reply to a comment
	 * on a Mark from the notifications screen.
	 *
	 * Validates that the comment exists and its parent post is a Mark the
	 * current user can edit, then creates a nested reply comment authored by
	 * the current user. wp_new_comment() runs in WP_Error mode so disallowed
	 * or duplicate content returns a clean JSON error instead of wp_die().
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reply_to_comment( WP_REST_Request $request ) {
		$comment_id = absint( $request->get_param( 'comment_id' ) );
		$comment    = get_comment( $comment_id );

		if ( ! $comment instanceof WP_Comment ) {
			return new WP_Error(
				'daymark_comment_not_found',
				__( 'Comment not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$post_id = (int) $comment->comment_post_ID;

		// The parent post must be a Mark the current user can edit; anything
		// else is forbidden (never leak whether the post exists).
		if ( '1' !== get_post_meta( $post_id, '_daymark_is_mark', true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You cannot reply to this comment.', 'daymark' ),
				array( 'status' => 403 )
			);
		}

		$content = sanitize_textarea_field( (string) $request->get_param( 'content' ) );

		if ( '' === $content ) {
			return new WP_Error(
				'daymark_empty_reply',
				__( 'A reply cannot be empty.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$user = wp_get_current_user();

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_parent'       => $comment_id,
			'comment_content'      => $content,
			'user_id'              => $user->ID,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,
			'comment_approved'     => 1,
		);

		// The second argument returns a WP_Error on a disallowed or duplicate
		// comment instead of calling wp_die(), so the endpoint stays JSON.
		$new_comment_id = wp_new_comment( $comment_data, true );

		if ( is_wp_error( $new_comment_id ) ) {
			$new_comment_id->add_data( array( 'status' => 400 ) );

			return $new_comment_id;
		}

		$response = rest_ensure_response(
			array(
				'comment_ID'      => (int) $new_comment_id,
				'comment_parent'  => $comment_id,
				'comment_post_ID' => $post_id,
				'content'         => $content,
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * POST /daymark/v1/subscriptions — subscribe to a site by URL.
	 *
	 * Delegates the URL validation + feed discovery + row creation + favicon
	 * resolution sequence to Daymark_Subscriptions::subscribe_to_site() (issue
	 * #78's wp-admin Settings screen shares that same method for its
	 * subscribe-by-URL form) — this method now only applies the REST-specific
	 * rate limit and shapes the REST response. Rate limited: this issues
	 * outbound requests to a site the user names, same risk class as manual
	 * sync.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_subscription( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$site_url = (string) $request->get_param( 'site_url' );
		$created  = Daymark_Plugin::instance()->subscriptions->subscribe_to_site( $site_url );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$subscription_id = (int) $created;

		// Best-effort initial fetch: without this, a freshly subscribed
		// site would sit with zero cached posts until the next scheduled
		// poll (by default once a day) — subscribe_to_site() only creates
		// the row. A failed fetch here doesn't fail the request or change
		// its shape; the next scheduled poll will keep trying.
		Daymark_Plugin::instance()->subscription_poller->manual_refresh( $subscription_id );

		$subscription = Daymark_Plugin::instance()->subscriptions->get( $subscription_id );

		$response = rest_ensure_response( $this->prepare_subscription( is_array( $subscription ) ? $subscription : array() ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Per-user transient key holding the app's last feed discovery, so
	 * POST /subscriptions/follow can only follow a feed discovery found.
	 *
	 * @return string
	 */
	private static function follow_transient_key(): string {
		return 'daymark_app_follow_candidates_' . get_current_user_id();
	}

	/**
	 * POST /daymark/v1/subscriptions/discover — find the feeds a site offers,
	 * for the app's "Follow a site" sheet. The same discovery Settings ->
	 * Daymark runs (Daymark_Subscriptions::discover_candidates()). The
	 * result is kept for this user for 15 minutes, and the follow route
	 * reads it back by index.
	 *
	 * @since 0.20.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function discover_subscription( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$resolved      = null;
		$candidates    = $subscriptions->discover_candidates( (string) $request->get_param( 'site_url' ), $resolved );

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$site_url = is_string( $resolved ) && '' !== $resolved ? $resolved : (string) $request->get_param( 'site_url' );

		set_transient(
			self::follow_transient_key(),
			array(
				'site_url'   => $site_url,
				'candidates' => $candidates,
			),
			15 * MINUTE_IN_SECONDS
		);

		$feed_source = Daymark_Plugin::instance()->subscription_source_registry->get_source( 'feed' );
		$site_title  = $feed_source instanceof Daymark_Subscription_Source_Feed ? $feed_source->get_site_title( $site_url ) : '';
		$list        = array();

		foreach ( array_values( $candidates ) as $index => $candidate ) {
			$url      = (string) ( $candidate['url'] ?? '' );
			$language = Daymark_Admin_Subscriptions::language_display_name( (string) ( $candidate['language'] ?? '' ) );
			$label    = (string) ( $candidate['source_label'] ?? '' );

			$list[] = array(
				'index'      => $index,
				'label'      => '' !== $language ? sprintf( '%1$s (%2$s)', $label, $language ) : $label,
				'title'      => sanitize_text_field( (string) ( $candidate['title'] ?? '' ) ),
				'url'        => esc_url_raw( $url ),
				'subscribed' => '' !== $url && null !== $subscriptions->get_by_feed_url( $url ),
			);
		}

		return rest_ensure_response(
			array(
				'site_url'      => esc_url_raw( $site_url ),
				'site_title'    => sanitize_text_field( $site_title ),
				'candidates'    => $list,
				'default_index' => empty( $list ) ? 0 : Daymark_Subscriptions::most_optimal_candidate_index( array_values( $candidates ) ),
			)
		);
	}

	/**
	 * POST /daymark/v1/subscriptions/follow — follow one feed from this
	 * user's last discovery, by its index. Never a URL the client sends:
	 * only a feed discovery itself found can be followed. Fetches the new
	 * subscription's posts right away, like subscribing in Settings ->
	 * Daymark, and applies an edited site name when one is sent.
	 *
	 * @since 0.20.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow_discovered_feed( WP_REST_Request $request ) {
		$stash = get_transient( self::follow_transient_key() );

		if ( ! is_array( $stash ) || empty( $stash['candidates'] ) || ! is_array( $stash['candidates'] ) ) {
			return new WP_Error(
				'daymark_follow_expired',
				__( 'That search has expired. Look up the site again.', 'daymark' ),
				array( 'status' => 410 )
			);
		}

		$candidates = array_values( $stash['candidates'] );
		$index      = (int) $request->get_param( 'index' );

		if ( ! isset( $candidates[ $index ] ) || ! is_array( $candidates[ $index ] ) ) {
			return new WP_Error(
				'daymark_follow_invalid_feed',
				__( 'Choose one of the feeds found for this site.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$created       = $subscriptions->subscribe_to_candidate( (string) $stash['site_url'], $candidates[ $index ] );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		delete_transient( self::follow_transient_key() );

		$subscription_id = (int) $created;
		$title           = $request->get_param( 'site_title' );

		if ( is_string( $title ) && '' !== trim( $title ) ) {
			$subscriptions->update( $subscription_id, array( 'site_title' => trim( $title ) ) );
		}

		// Best-effort first fetch, as create_subscription() does.
		Daymark_Plugin::instance()->subscription_poller->manual_refresh( $subscription_id );

		$subscription = $subscriptions->get( $subscription_id );
		$response     = rest_ensure_response( $this->prepare_subscription( is_array( $subscription ) ? $subscription : array() ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * GET /daymark/v1/subscriptions — active subscriptions.
	 *
	 * @param WP_REST_Request $request The request (no query args yet).
	 * @return WP_REST_Response
	 */
	public function get_subscriptions( WP_REST_Request $request ) {
		unset( $request );

		$rows = Daymark_Plugin::instance()->subscriptions->get_active();

		return rest_ensure_response( array_map( array( $this, 'prepare_subscription' ), $rows ) );
	}

	/**
	 * DELETE /daymark/v1/subscriptions/{id} — unsubscribe.
	 *
	 * Delegates to Daymark_Subscriptions::unsubscribe(), which trashes every
	 * cached `daymark_subscription_post` ingested from this subscription
	 * (relying on core's normal 7-day trash retention for eventual
	 * deletion) before deleting the subscription row itself — the same
	 * method the wp-admin Settings -> Daymark screen's Unsubscribe action
	 * uses, so a cached copy of a site's content is never orphaned no
	 * matter which surface removed the subscription.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_subscription( WP_REST_Request $request ) {
		$id            = absint( $request->get_param( 'id' ) );
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscription  = $subscriptions->get( $id );

		if ( null === $subscription ) {
			return new WP_Error(
				'daymark_subscription_not_found',
				__( 'Subscription not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$result = $subscriptions->unsubscribe( $id );

		return rest_ensure_response(
			array(
				'deleted'       => $result['deleted'],
				'trashed_posts' => $result['trashed_posts'],
			)
		);
	}

	/**
	 * POST /daymark/v1/subscriptions/{id}/refresh — manual (pull-to-refresh)
	 * poll of one subscription, independent of the cron schedule.
	 *
	 * Delegates to Daymark_Subscription_Poller::manual_refresh(), which
	 * enforces its own per-subscription 15-minute cooldown
	 * (`daymark_subscription_manual_refresh_interval`) and returns a
	 * distinguishable error when the window has not elapsed. This route
	 * additionally applies the standard per-user rate limit on top of that,
	 * since it issues an outbound request to a site the user does not
	 * control.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh_subscription( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_REFRESH );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$id     = absint( $request->get_param( 'id' ) );
		$result = Daymark_Plugin::instance()->subscription_poller->manual_refresh( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription = Daymark_Plugin::instance()->subscriptions->get( $id );

		return rest_ensure_response( $this->prepare_subscription( is_array( $subscription ) ? $subscription : array() ) );
	}

	/**
	 * POST /daymark/v1/subscriptions/refresh — manually refresh every active
	 * subscription in one request (the Timeline's pull-to-refresh and its
	 * refresh button).
	 *
	 * Spends one charge of the same per-user rate limit a single-site
	 * refresh uses, instead of one per followed site, so a long list no
	 * longer runs out of budget partway through. Each site still keeps its
	 * own 15-minute cooldown. See
	 * Daymark_Subscription_Poller::manual_refresh_all() for the counts.
	 *
	 * @since 0.20.0
	 *
	 * @param WP_REST_Request $request The request (no params).
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh_all_subscriptions( WP_REST_Request $request ) {
		unset( $request );

		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_REFRESH );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return rest_ensure_response( Daymark_Plugin::instance()->subscription_poller->manual_refresh_all() );
	}

	/**
	 * GET /daymark/v1/subscriptions/export — download every subscription as
	 * a standard OPML file (issue #80).
	 *
	 * Builds the response via Daymark_Subscription_OPML::export() — the same
	 * call the wp-admin Settings -> Daymark screen's own Export link makes —
	 * so both surfaces are guaranteed to produce byte-identical output. No
	 * extra permission beyond the standard nonce + edit_posts check: this is
	 * a read of the current user's own subscription list, same access level
	 * as GET /subscriptions itself.
	 *
	 * The response is never actually JSON-serialized to the client — see
	 * maybe_serve_opml_export(), which intercepts this one route and echoes
	 * the raw XML with file-download headers instead. Returning a normal
	 * WP_REST_Response here still matters for two reasons: PHPUnit's
	 * rest_do_request() bypasses serve_request() entirely and returns this
	 * object directly (so tests can assert on `$response->get_data()['xml']`
	 * without ever touching real output buffering), and it keeps this
	 * method's own contract (permission check -> response) identical to
	 * every other REST callback in this class.
	 *
	 * @since 0.10.0
	 *
	 * @param WP_REST_Request $request The request (no params).
	 * @return WP_REST_Response
	 */
	public function export_subscriptions_opml( WP_REST_Request $request ) {
		unset( $request );

		$xml = ( new Daymark_Subscription_OPML() )->export();

		$response = new WP_REST_Response( array( 'xml' => $xml ) );
		$response->header( 'Content-Type', 'text/x-opml+xml; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="daymark-subscriptions.opml"' );

		return $response;
	}

	/**
	 * `rest_pre_serve_request` callback, scoped to
	 * GET /daymark/v1/subscriptions/export only: echoes the raw OPML XML
	 * document with its file-download headers instead of letting
	 * WP_REST_Server wrap export_subscriptions_opml()'s response in the
	 * REST API's usual JSON envelope, which is not an appropriate shape for
	 * a file download.
	 *
	 * Only ever runs during a real HTTP dispatch through
	 * WP_REST_Server::serve_request() (i.e. rest_api_loaded()) — this filter
	 * is never consulted by rest_do_request(), which this plugin's own
	 * PHPUnit REST tests use, so those tests exercise
	 * export_subscriptions_opml()'s returned WP_REST_Response directly and
	 * never reach this method at all.
	 *
	 * @since 0.10.0
	 *
	 * @param bool            $served  Whether the request has already been served.
	 * @param mixed           $result  The response result (normally a WP_REST_Response).
	 * @param WP_REST_Request $request The request.
	 * @param WP_REST_Server  $server  The REST server instance (unused).
	 * @return bool
	 */
	public function maybe_serve_opml_export( $served, $result, $request, $server ) {
		unset( $server );

		if ( ! $request instanceof WP_REST_Request || '/' . $this->namespace . '/subscriptions/export' !== $request->get_route() ) {
			return $served;
		}

		if ( ! $result instanceof WP_REST_Response || 200 !== $result->get_status() ) {
			return $served;
		}

		$data = $result->get_data();

		if ( ! is_array( $data ) || ! isset( $data['xml'] ) ) {
			return $served;
		}

		foreach ( $result->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		// Raw XML file download, not HTML output — every value inside this
		// document was already escaped by DOMDocument when
		// Daymark_Subscription_OPML::export() built it.
		echo (string) $data['xml']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw OPML/XML file download; content already XML-escaped by DOMDocument, not HTML output.

		return true;
	}

	/**
	 * POST /daymark/v1/subscriptions/import — bulk-import subscriptions from
	 * an uploaded OPML file (issue #80).
	 *
	 * Reads the uploaded file the same way ai_alt_text()/ai_transcript() read
	 * their own uploads (WP_REST_Request::get_file_params(), no
	 * wp_handle_upload() — nothing here is stored as a permanent attachment).
	 * Rate limited once per whole import request (ACTION_SUBSCRIBE — the same
	 * bucket manual subscribe-by-URL uses, since an `htmlUrl`-only entry
	 * issues the exact same kind of outbound discovery request), not once per
	 * entry — bulk-importing many entries in a single request is exactly what
	 * that bucket already exists to allow.
	 *
	 * The upload size cap is enforced here, before the file is ever read into
	 * memory or handed to Daymark_Subscription_OPML::import() — a request
	 * validation step, not something import() itself is responsible for.
	 * Extension + parse-success together are this route's content validation:
	 * import() successfully parsing the file as OPML *is* the MIME check that
	 * matters here, since this upload is never passed through
	 * wp_handle_upload()'s own type sniffing.
	 *
	 * @since 0.10.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_subscriptions_opml( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files = $request->get_file_params();
		$file  = isset( $files['opml'] ) && is_array( $files['opml'] ) ? $files['opml'] : null;

		if ( ! $file || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error(
				'daymark_subscription_opml_missing_file',
				__( 'No OPML file was provided.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$filename  = isset( $file['name'] ) ? (string) $file['name'] : '';
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'opml', 'xml' ), true ) ) {
			return new WP_Error(
				'daymark_subscription_opml_invalid_extension',
				__( 'Please upload a .opml or .xml file.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		/** This filter is documented in Daymark_Subscription_OPML::MAX_UPLOAD_BYTES's docblock. */
		$max_bytes = (int) apply_filters( 'daymark_subscription_opml_max_upload_bytes', Daymark_Subscription_OPML::MAX_UPLOAD_BYTES );
		$size      = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size <= 0 || $size > $max_bytes ) {
			return new WP_Error(
				'daymark_subscription_opml_too_large',
				__( 'This file is too large to import.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a just-uploaded PHP temp upload file for in-memory XML parsing (not a remote fetch); matches this codebase's other direct tmp_name reads (e.g. ai_alt_text()'s finfo check reads the same kind of temp path).
		$xml = (string) file_get_contents( $file['tmp_name'] );

		$result = ( new Daymark_Subscription_OPML() )->import( $xml );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /daymark/v1/subscription-posts/{id} — click-through detail fetch
	 * for one `daymark_subscription_post` Timeline item.
	 *
	 * Returns the same summary shape as GET /timeline's item plus
	 * `body_content`, which that list response deliberately omits (see
	 * prepare_subscription_post_summary()). When the post isn't already
	 * fully cached (`content_state` !== 'full' — never fetched, or pruned),
	 * this fetches it live via
	 * Daymark_Subscription_Poller::fetch_full_content() first; an
	 * already-'full' post is returned from cache without re-hitting the
	 * source site — unless the optional `refresh` param is truthy, which
	 * forces a live re-fetch regardless of the cached content_state. This is
	 * the one way to pick up an improvement to extract_body_html() (a new
	 * stripping pass, say) for a post that was already fully cached before
	 * that improvement shipped — otherwise its stored body_content would
	 * never change again short of being pruned and re-polled, or the whole
	 * subscription being removed and re-added.
	 *
	 * Rate-limited only when it fetches from the other site; returning a
	 * cached post is free. Opening a post (the default) spends
	 * ACTION_SUBSCRIPTION_POST_OPEN. The app's background fetches (refilling
	 * a trimmed post as you scroll, caching a bookmark) pass `background=1`
	 * and spend ACTION_SUBSCRIPTION_POST_FETCH instead, so they can never
	 * use up the allowance a tap needs.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscription_post_full_content( WP_REST_Request $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$check = $this->assert_subscription_post( $id, 'daymark_subscription_post_not_found' );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$refresh = rest_sanitize_boolean( $request->get_param( 'refresh' ) );

		$content_state = get_post_meta( $id, 'content_state', true );

		if ( $refresh || 'full' !== $content_state ) {
			$rate = $this->rate_limit(
				rest_sanitize_boolean( $request->get_param( 'background' ) )
					? Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH
					: Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_OPEN
			);

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			$fetch = Daymark_Plugin::instance()->subscription_poller->fetch_full_content( $id );

			if ( is_wp_error( $fetch ) ) {
				return $fetch;
			}
		}

		$response = array_merge(
			$this->prepare_subscription_post_summary( $id ),
			array(
				// Already wp_kses_post()-sanitized by the poller; trusted raw
				// HTML meant to be rendered as-is by the app shell, same as
				// post_content elsewhere in this codebase — not re-escaped here.
				// Inline `style` attributes are stripped again on the way out
				// (not only when the poller stores it) so a body cached before
				// that stripping existed can't still reach the app shell with
				// a remote site's own CSS in it.
				'body_content' => Daymark_Subscription_Poller::strip_untrusted_presentation( (string) get_post_meta( $id, 'body_content', true ) ),
			)
		);

		return rest_ensure_response( $response );
	}

	/**
	 * GET /daymark/v1/subscription-posts/{id}/oembed — best-effort link
	 * preview of a link-format subscription post's own detected outbound
	 * link (`link_url`), for the full-screen post view. With
	 * `target=interaction` (or `reply`, its earlier name), the post this one
	 * replies to, reblogs, likes, bookmarks, or RSVPs to instead (issue
	 * #168), for the post view's interaction context.
	 *
	 * Tries Daymark_Subscription_Opengraph first (issue #349) — Open Graph/
	 * Twitter Card meta tags are the far more universal signal for an
	 * ordinary web page (a blog post, a news article) — falling back to the
	 * existing Daymark_Subscription_Oembed (issue #279) only when Open
	 * Graph finds nothing usable, since a genuine media provider (YouTube,
	 * Mastodon, etc.) still needs that resolver's real embeddable iframe/
	 * photo markup, which Open Graph tags alone can't reconstruct.
	 *
	 * Deliberately never fails/404s for "no link" or "no usable preview" —
	 * both resolve to every field empty, matching the "optional, best-
	 * effort enhancement" framing throughout: the caller has nothing
	 * special to branch on beyond "was type non-empty".
	 *
	 * Spends the background allowance (ACTION_SUBSCRIPTION_POST_FETCH):
	 * cards ask for previews as you scroll. Charged only when the preview
	 * isn't cached yet, so a preview already looked up is free.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscription_post_oembed( WP_REST_Request $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$check = $this->assert_subscription_post( $id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$is_reply = in_array( $request->get_param( 'target' ), array( 'interaction', 'reply' ), true );
		$link_url = $is_reply
			? Daymark_Subscription_Interaction::from_post( $id )['url']
			: (string) get_post_meta( $id, 'link_url', true );

		$preview = array();

		if ( '' !== $link_url ) {
			// Would resolving this make a request? Open Graph first; an
			// embed only when Open Graph found nothing (see below).
			$og_cached = Daymark_Subscription_Opengraph::cached( $link_url );
			$fetches   = null === $og_cached
				|| ( empty( $og_cached ) && ! $is_reply && null === Daymark_Subscription_Oembed::cached( $link_url ) );

			if ( $fetches ) {
				$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH );

				if ( is_wp_error( $rate ) ) {
					return $rate;
				}
			}

			$preview = Daymark_Subscription_Opengraph::resolve( $link_url );

			// Interaction context is a citation, never a playable embed.
			if ( empty( $preview ) && ! $is_reply ) {
				$preview = Daymark_Subscription_Oembed::resolve( $link_url );
			}
		}

		return rest_ensure_response(
			array(
				'type'        => sanitize_key( (string) ( $preview['type'] ?? '' ) ),
				// Already built entirely from allowlisted attributes/
				// sanitized text by Daymark_Subscription_Oembed/
				// Daymark_Subscription_Opengraph — never the provider's own
				// raw HTML — trusted the same way body_content is above.
				'html'        => (string) ( $preview['html'] ?? '' ),
				'title'       => (string) ( $preview['title'] ?? '' ),
				'description' => (string) ( $preview['description'] ?? '' ),
				'image'       => (string) ( $preview['image'] ?? '' ),
				// Set only when Parse This is active and found them.
				'author'      => (string) ( $preview['author'] ?? '' ),
				'published'   => (string) ( $preview['published'] ?? '' ),
				'url'         => esc_url_raw( $link_url ),
			)
		);
	}

	/**
	 * POST /daymark/v1/subscription-posts/{id}/comment — deliver a comment
	 * directly to a subscription post's origin (issue #317), replacing the
	 * old composer-based "Reply" action. Daymark_Comment_Delivery decides
	 * server-side (where the one outbound permalink fetch already has to
	 * happen) whether to route through Webmention (a minimal Mark on your
	 * own site) or fall back to a native comment POST to the origin's own
	 * REST API — see that class's own docblock for the full mechanism.
	 *
	 * Own rate-limit bucket (ACTION_SUBSCRIPTION_COMMENT): a different risk
	 * class from ACTION_PUBLISH's own Mark-create, since even the
	 * Webmention branch first makes an outbound fetch of a third-party
	 * host, and the native branch POSTs to one directly.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function comment_on_subscription_post( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_COMMENT );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$id    = absint( $request->get_param( 'id' ) );
		$check = $this->assert_subscription_post( $id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$text   = (string) $request->get_param( 'text' );
		$result = Daymark_Comment_Delivery::deliver( $id, $text );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$method  = sanitize_key( (string) ( $result['method'] ?? '' ) );
		$mark_id = absint( $result['mark_id'] ?? 0 );

		// Whether it actually reached the origin, where knowable: a Jetpack
		// API call that returned is delivered; a Webmention-route Mark's
		// state comes from the Webmention plugin's own meta. A native REST
		// comment's own `status` already says so, so nothing extra here.
		$delivery = '';

		if ( 'jetpack' === $method ) {
			$delivery = Daymark_Like_Delivery::STATE_SENT;
		} elseif ( 'webmention' === $method && $mark_id > 0 ) {
			$delivery = Daymark_Like_Delivery::webmention_state( $mark_id, esc_url_raw( (string) get_post_meta( $id, 'permalink', true ) ) );
		}

		return rest_ensure_response(
			array(
				'method'   => $method,
				'status'   => sanitize_key( (string) ( $result['status'] ?? '' ) ),
				'message'  => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				'mark_id'  => $mark_id,
				'delivery' => $delivery,
			)
		);
	}

	/**
	 * GET /daymark/v1/subscription-posts/{id}/comment-target — a read-only
	 * pre-check, called *before* the composer ever opens, so tapping Comment
	 * can skip straight to the origin's own comment form when Daymark can't
	 * deliver on the reader's behalf, rather than opening the composer, only
	 * to discover that after a comment has already been typed (issue #351
	 * follow-up: typing a comment, having delivery fail, then having to
	 * retype the same comment on the origin site is exactly the frustrating,
	 * comment-abandoning experience this pre-check exists to avoid).
	 * Daymark_Comment_Delivery::resolve_comment_target() reuses the same
	 * cached Webmention-endpoint discovery `deliver()` itself consults, so
	 * an actual send right after this pre-check costs no second fetch.
	 *
	 * Spends ACTION_SUBSCRIPTION_POST_OPEN, the allowance for things you
	 * tap (never ACTION_SUBSCRIPTION_COMMENT's: this never delivers
	 * anything), and only when the origin's signals aren't cached yet.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscription_post_comment_target( WP_REST_Request $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$check = $this->assert_subscription_post( $id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$permalink = esc_url_raw( (string) get_post_meta( $id, 'permalink', true ) );

		if ( null === Daymark_Comment_Delivery::cached_origin_signals( $permalink ) ) {
			$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_OPEN );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
		}

		$target = Daymark_Comment_Delivery::resolve_comment_target( $id );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		return rest_ensure_response(
			array(
				'method' => sanitize_key( (string) ( $target['method'] ?? '' ) ),
				'url'    => esc_url_raw( (string) ( $target['url'] ?? '' ) ),
			)
		);
	}

	/**
	 * GET /daymark/v1/subscription-posts/{id}/like-availability — whether a
	 * Like on this post can actually reach its origin (Jetpack-native, an
	 * ActivityPub Like through the ActivityPub plugin, or a Webmention the
	 * local Webmention plugin will send to an endpoint the origin
	 * advertises). The Timeline summary only ever reports a cached
	 * answer (`like_available`, null when unknown); the client calls this to
	 * resolve an unknown card lazily, and hides the Like icon on `false`.
	 *
	 * Rate-limited only when it would make an outbound request: a cached
	 * answer (or "no mechanism exists at all", which needs no request) is
	 * returned free. It spends the background allowance
	 * (ACTION_SUBSCRIPTION_POST_FETCH), never the one opening a post uses.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscription_post_like_availability( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$check   = $this->assert_subscription_post( $post_id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$permalink = esc_url_raw( (string) get_post_meta( $post_id, 'permalink', true ) );

		if ( null === Daymark_Like_Delivery::cached_availability( $permalink ) ) {
			$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
		}

		$result = Daymark_Like_Delivery::resolve( $post_id );

		return rest_ensure_response(
			array(
				'available' => (bool) $result['available'],
				'method'    => sanitize_key( $result['method'] ),
			)
		);
	}

	/**
	 * POST /daymark/v1/subscription-posts/{id}/like — like a subscription
	 * post (issue #391). Prefers WordPress.com's own native Like API,
	 * exactly the way the official Jetpack app does it, whenever the origin
	 * itself resolves via WordPress.com's public API and the current user
	 * has personally linked their own WordPress.com account — no local
	 * Mark, nothing published anywhere on this site. Falls back to the
	 * classic path (a minimal 'note' Mark carrying `_daymark_like_of`,
	 * exactly as before this feature shipped) for every other origin.
	 *
	 * Idempotent on the classic path the same way Daymark_Publisher's own
	 * Like/Repost guard already is (issue #389) — reuses an existing
	 * published Like Mark for this permalink rather than creating a sibling
	 * one on a duplicate tap.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function like_subscription_post( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_LIKE );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$check   = $this->assert_subscription_post( $post_id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$permalink    = esc_url_raw( (string) get_post_meta( $post_id, 'permalink', true ) );
		$availability = Daymark_Like_Delivery::resolve( $post_id );
		$jetpack      = '' !== $permalink && $availability['jetpack'] ? $this->maybe_jetpack_like( $post_id, $permalink ) : null;

		if ( null !== $jetpack ) {
			return $jetpack;
		}

		$existing = '' !== $permalink ? $this->find_own_mark_id_by_target_url( '_daymark_like_of', $permalink ) : 0;

		if ( $existing > 0 ) {
			return rest_ensure_response(
				array(
					'method'   => absint( get_post_meta( $existing, Daymark_ActivityPub_Engagement::OUTBOX_META, true ) ) > 0 ? 'activitypub' : 'classic',
					'liked'    => true,
					'mark_id'  => $existing,
					'delivery' => Daymark_Like_Delivery::like_state( false, $existing, $permalink ),
				)
			);
		}

		// ActivityPub route (issue #439): queue a real `Like` through the
		// ActivityPub plugin's outbox. The local Like Mark is still published
		// below (the liked-state UI reads it), but its Webmention is
		// suppressed so the origin receives exactly one Like. 0 when the
		// route isn't available or the queue failed — then Webmention alone.
		$outbox_id = '' !== $permalink && $availability['activitypub']
			? Daymark_ActivityPub_Engagement::like( get_current_user_id(), $permalink )
			: 0;

		// Never create a local Like Mark nothing can deliver: without an
		// ActivityPub, Webmention, or Bridgy Fed route (and with the Jetpack
		// route unavailable or just failed), the origin's author would never
		// see it. The client hides the icon on this code; the check is
		// repeated here so it never has to be trusted. A Bridgy Fed Like is
		// an ordinary Like Mark; Daymark_Bridgy_Fed marks it for Bridgy Fed
		// when it's published.
		if ( 0 === $outbox_id && ! $availability['webmention'] && ! $availability['bridgy_fed'] ) {
			return new WP_Error(
				'daymark_like_undeliverable',
				__( "This post's site can't receive a Like from Daymark.", 'daymark' ),
				array( 'status' => 422 )
			);
		}

		$title   = html_entity_decode( sanitize_text_field( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
		$caption = sprintf(
			/* translators: %s: title of the liked post */
			__( 'Liked "%s"', 'daymark' ),
			'' !== $title ? $title : $permalink
		);

		$mark_id = Daymark_Plugin::instance()->publisher->publish(
			array(
				'caption'        => $caption,
				'primary_type'   => 'note',
				'status'         => 'publish',
				'ai_assist_used' => false,
				'like_of'        => $permalink,
			)
		);

		if ( is_wp_error( $mark_id ) ) {
			// Don't leave a queued Like with no local record to undo it from.
			if ( $outbox_id > 0 ) {
				Daymark_ActivityPub_Engagement::undo_outbox_item( $outbox_id );
			}

			return $mark_id;
		}

		if ( $outbox_id > 0 ) {
			Daymark_ActivityPub_Engagement::attach_to_mark( (int) $mark_id, $outbox_id, 'Like' );
		}

		return rest_ensure_response(
			array(
				'method'   => $outbox_id > 0 ? 'activitypub' : 'classic',
				'liked'    => true,
				'mark_id'  => $mark_id,
				'delivery' => Daymark_Like_Delivery::like_state( false, (int) $mark_id, $permalink ),
			)
		);
	}

	/**
	 * DELETE /daymark/v1/subscription-posts/{id}/like — undo a like,
	 * whichever mechanism created it (Jetpack-native or a classic Mark) —
	 * resolved entirely server-side from the current user's own recorded
	 * state, so the client never needs to track or send which path was
	 * used.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlike_subscription_post( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_LIKE );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$check   = $this->assert_subscription_post( $post_id );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$user_id = get_current_user_id();

		if ( Daymark_Jetpack_Engagement::is_liked( $user_id, $post_id ) ) {
			$permalink = esc_url_raw( (string) get_post_meta( $post_id, 'permalink', true ) );
			$origin    = '' !== $permalink ? Daymark_Jetpack_Engagement::resolve_origin( $permalink ) : null;

			if ( null !== $origin ) {
				$result = Daymark_Jetpack_Engagement::unlike( $origin['site_id'], $origin['post_id'] );

				// A failed unlike call still clears our own local "liked"
				// record — the alternative (leaving it set) would strand the
				// toggle in a state the reader can never undo through this
				// UI again, worse than a WordPress.com like that outlives it.
				unset( $result );
			}

			Daymark_Jetpack_Engagement::unmark_liked( $user_id, $post_id );

			return rest_ensure_response(
				array(
					'method' => 'jetpack',
					'liked'  => false,
				)
			);
		}

		$permalink = esc_url_raw( (string) get_post_meta( $post_id, 'permalink', true ) );
		$existing  = '' !== $permalink ? $this->find_own_mark_id_by_target_url( '_daymark_like_of', $permalink ) : 0;

		// Trashing the Mark also queues an ActivityPub `Undo` when it
		// carried a queued Like (Daymark_ActivityPub_Engagement::maybe_undo()
		// on `trashed_post`), so every route is undone from this one call.
		if ( $existing > 0 ) {
			wp_trash_post( $existing );
		}

		return rest_ensure_response(
			array(
				'method' => 'classic',
				'liked'  => false,
			)
		);
	}

	/**
	 * Attempts the Jetpack-native Like fast path for like_subscription_post()
	 * above. Returns null (meaning "not eligible, fall back to the classic
	 * path") when Jetpack isn't available, the user hasn't linked their own
	 * WordPress.com account, the origin doesn't resolve via WordPress.com's
	 * API, or the actual Like call fails for any reason — this is
	 * deliberately never a hard error, since the classic path is always a
	 * safe fallback.
	 *
	 * @param int    $post_id   Subscription post ID.
	 * @param string $permalink The post's own permalink.
	 * @return WP_REST_Response|null
	 */
	private function maybe_jetpack_like( int $post_id, string $permalink ) {
		if ( ! Daymark_Jetpack_Engagement::current_user_connected() ) {
			return null;
		}

		$origin = Daymark_Jetpack_Engagement::resolve_origin( $permalink );

		if ( null === $origin ) {
			return null;
		}

		$result = Daymark_Jetpack_Engagement::like( $origin['site_id'], $origin['post_id'] );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		Daymark_Jetpack_Engagement::mark_liked( get_current_user_id(), $post_id );

		return rest_ensure_response(
			array(
				'method'   => 'jetpack',
				'liked'    => true,
				'delivery' => Daymark_Like_Delivery::STATE_SENT,
			)
		);
	}

	/**
	 * Prepare a subscription row response array: cast/escape every field per
	 * the security checklist rather than passing the raw DB row straight
	 * through.
	 *
	 * @param array<string, mixed> $row A `daymark_subscription` row.
	 * @return array<string, mixed>
	 */
	private function prepare_subscription( array $row ): array {
		return array(
			'id'                        => absint( $row['id'] ?? 0 ),
			'site_url'                  => esc_url_raw( (string) ( $row['site_url'] ?? '' ) ),
			'feed_url'                  => esc_url_raw( (string) ( $row['feed_url'] ?? '' ) ),
			'source_type'               => sanitize_key( (string) ( $row['source_type'] ?? '' ) ),
			'site_title'                => sanitize_text_field( (string) ( $row['site_title'] ?? '' ) ),
			'feed_title'                => sanitize_text_field( (string) ( $row['feed_title'] ?? '' ) ),
			'site_icon_url'             => esc_url_raw( (string) ( $row['site_icon_url'] ?? '' ) ),
			'status'                    => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'consecutive_failure_count' => absint( $row['consecutive_failure_count'] ?? 0 ),
			'last_checked_at'           => sanitize_text_field( (string) ( $row['last_checked_at'] ?? '' ) ),
			'last_manual_refresh_at'    => sanitize_text_field( (string) ( $row['last_manual_refresh_at'] ?? '' ) ),
			// Human-readable reason for the most recent failed check (issue
			// #81); '' when the subscription has never failed a check.
			'last_error'                => sanitize_text_field( (string) ( $row['last_error'] ?? '' ) ),
			'created_at'                => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	/**
	 * Prepare a `daymark_subscription_post` Timeline item response array.
	 *
	 * Deliberately omits `body_content`: sending the cached full-HTML body
	 * for every list item is wasteful when most Timeline cards only ever
	 * render the excerpt/thumbnail, and a pruned or excerpt-only post has
	 * no body to send anyway. A future per-item detail fetch (the same
	 * click-through path `Daymark_Subscription_Poller::fetch_full_content()`
	 * already serves) is the right place to return it, not this list
	 * response.
	 *
	 * `site_icon_url` comes from a per-item lookup of the post's
	 * subscription row (`Daymark_Subscriptions::get()`) rather than a
	 * batched join — fine at this codebase's personal-site scale, and
	 * matches how the rest of this class already reads a subscription's
	 * row (e.g. prepare_subscription()); batching by unique subscription_id
	 * would be a reasonable follow-up if this ever needs to scale further.
	 *
	 * @param int $post_id `daymark_subscription_post` ID.
	 * @return array<string, mixed>
	 */
	private function prepare_subscription_post_summary( int $post_id ): array {
		$subscription_id   = absint( get_post_meta( $post_id, 'subscription_id', true ) );
		$subscription      = Daymark_Plugin::instance()->subscriptions->get( $subscription_id );
		$content_state     = sanitize_key( (string) get_post_meta( $post_id, 'content_state', true ) );
		$published_at      = (string) get_post_meta( $post_id, 'published_at', true );
		$permalink         = esc_url_raw( (string) get_post_meta( $post_id, 'permalink', true ) );
		$user_id           = get_current_user_id();
		$replied_mark_id   = $this->find_own_mark_id_by_target_url( '_daymark_in_reply_to', $permalink );
		$liked_mark_id     = $this->find_own_mark_id_by_target_url( '_daymark_like_of', $permalink );
		$jetpack_liked     = Daymark_Jetpack_Engagement::is_liked( $user_id, $post_id );
		$jetpack_commented = Daymark_Jetpack_Engagement::is_commented( $user_id, $post_id );

		$summary = array(
			// Discriminator field a Timeline consumer branches on, mirroring
			// Daymark_Notifications' item `type`. Deliberately not named
			// `type` here: prepare_mark_summary()'s existing `type` key
			// already means the Mark's `_daymark_primary_type`
			// (image|video|audio|note|...), a contract several other
			// endpoints rely on (GET/POST /marks, GET /marks/{id}) — reusing
			// the same key for a different meaning on the same endpoint
			// would be confusing at best. `post_format` below is this item
			// shape's closest equivalent to a Mark's `type`.
			'item_type'          => 'subscription_post',
			'id'                 => absint( $post_id ),
			'subscription_id'    => $subscription_id,
			'title'              => html_entity_decode(
				sanitize_text_field( get_the_title( $post_id ) ),
				ENT_QUOTES,
				'UTF-8'
			),
			'excerpt'            => sanitize_text_field( (string) get_post_field( 'post_excerpt', $post_id ) ),
			'author'             => sanitize_text_field( (string) get_post_meta( $post_id, 'author', true ) ),
			// The *source* site's URL for this post — for a future in-app
			// "open this" action, not a link to render directly on this
			// site (this CPT has no permalink of its own; see
			// Daymark_Subscription_Post_Type's class docblock).
			'permalink'          => $permalink,
			// phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core helper, not the removed mysql_ extension.
			'date'               => '' !== $published_at ? mysql_to_rfc3339( $published_at ) : '',
			'post_format'        => sanitize_key( (string) get_post_meta( $post_id, 'post_format', true ) ),
			'featured_image_url' => esc_url_raw( (string) get_post_meta( $post_id, 'featured_image_url', true ) ),
			// The item's own detected outbound link, when it had no
			// confirmed media of its own — see
			// Daymark_Subscription_Content_Sniffer::sniff(). '' most of the
			// time; present, the app shell's full-screen post view offers
			// an oEmbed preview of it via GET /subscription-posts/{id}/oembed.
			'link_url'           => esc_url_raw( (string) get_post_meta( $post_id, 'link_url', true ) ),
			// What this post does to another post — reply, repost, like,
			// bookmark, or rsvp — with that post's URL and an RSVP's
			// answer (issue #168). Empty strings for most posts. The card
			// shows a context line; the post view previews `url`.
			'interaction'        => Daymark_Subscription_Interaction::from_post( $post_id ),
			// A quote post's quote and credit, for its card's quote banner
			// (issue #168). '' unless the post is quote-format and its
			// content had a blockquote.
			'quote_text'         => sanitize_text_field( (string) get_post_meta( $post_id, 'quote_text', true ) ),
			'quote_credit'       => sanitize_text_field( (string) get_post_meta( $post_id, 'quote_credit', true ) ),
			'content_state'      => in_array( $content_state, array( 'full', 'excerpt_only', 'pruned' ), true ) ? $content_state : 'excerpt_only',
			// The subscription's cached favicon, used as a pruned
			// rich-media post's Timeline placeholder in place of its
			// cleared embed (per the PRD). '' when the subscription row is
			// gone (should not normally happen while its posts still
			// exist) or never had a favicon resolved.
			'site_icon_url'      => esc_url_raw( (string) ( $subscription['site_icon_url'] ?? '' ) ),
			// The subscribed site's own URL and title, for a tap on this
			// item's avatar to offer "visit this site" and "show only this
			// site's posts" — both read from the row already fetched above,
			// no extra lookup.
			'site_url'           => esc_url_raw( (string) ( $subscription['site_url'] ?? '' ) ),
			'site_title'         => sanitize_text_field( (string) ( $subscription['site_title'] ?? '' ) ),
			'bookmarked'         => Daymark_Plugin::instance()->bookmarks->is_bookmarked( get_current_user_id(), $post_id ),
			// Whether the current user has already published a Mark engaging
			// with this exact post (issue #41 follow-up: "show whether I've
			// liked, commented on, or reblogged a subscribed post"). '' when
			// permalink is empty (never happens for a real ingested post) or
			// no such Mark exists yet. Full remote engagement counts aren't
			// obtainable in general (no built-in subscription source exposes
			// a reliable like/repost count for someone else's post), so this
			// is the buildable fallback: Daymark's own record of the user's
			// own engagement, not the origin site's real totals.
			'replied_mark_id'    => $replied_mark_id,
			'liked_mark_id'      => $liked_mark_id,
			'reposted_mark_id'   => $this->find_own_mark_id_by_target_url( '_daymark_repost_of', $permalink ),
			// Jetpack-native equivalents of the two fields above (issue #391)
			// — set only when the Like/Comment was delivered directly to
			// WordPress.com's own API rather than via a local Mark, so
			// there's no Mark ID to key off of the way the classic path's
			// own fields do.
			'jetpack_liked'      => $jetpack_liked,
			'jetpack_commented'  => $jetpack_commented,
			// Whether a Like can reach this post's origin at all (see
			// Daymark_Like_Delivery). Cache-only, never a live fetch during
			// a Timeline request: false when no mechanism exists, null when
			// the origin hasn't been looked up yet (the client resolves it
			// via GET .../like-availability), else the cached answer.
			'like_available'     => Daymark_Like_Delivery::cached_availability( $permalink ),
			// Whether this user's own Like/Comment actually reached the
			// origin: pending|sent|failed|not_sent, '' when there's nothing
			// (or, for a native REST comment, nothing recorded) to report.
			'like_delivery'      => Daymark_Like_Delivery::like_state( $jetpack_liked, $liked_mark_id, $permalink ),
			'comment_delivery'   => Daymark_Like_Delivery::comment_state( $jetpack_commented, $replied_mark_id, $permalink ),
		);

		// A gallery post's first four photos and photo count, for its card's
		// 2x2 grid — the same `gallery` shape a Mark's own summary carries.
		// Omitted when fewer than two photos are known.
		if ( 'gallery' === $summary['post_format'] ) {
			$images = Daymark_Subscription_Poller::gallery_images_for( $post_id );

			if ( count( $images ) > 1 ) {
				$summary['gallery'] = array(
					'images' => array_map( 'esc_url_raw', array_slice( $images, 0, 4 ) ),
					'count'  => count( $images ),
				);
			}
		}

		return $summary;
	}

	/**
	 * The ID of the current user's own Mark, if any, carrying the given POSSE
	 * target-URL meta value — used to detect "have I already replied to /
	 * liked / reposted this exact subscription post" (issue #41 follow-up).
	 *
	 * A plain per-call `get_posts()` lookup, not a batched join — fine at
	 * this codebase's personal-site scale, matching the precedent
	 * prepare_subscription_post_summary()'s own per-row subscription lookup
	 * already set (see that method's docblock).
	 *
	 * @param string $meta_key One of '_daymark_in_reply_to', '_daymark_like_of', '_daymark_repost_of'.
	 * @param string $url      The subscription post's own permalink to match against.
	 * @return int Mark post ID, or 0 when absent/no match.
	 */
	private function find_own_mark_id_by_target_url( string $meta_key, string $url ): int {
		if ( '' === $url ) {
			return 0;
		}

		$found = get_posts(
			array(
				// Both types: a Like Mark lives on its own post type (see
				// Daymark_Like_Visibility::POST_TYPE); a legacy one may not
				// have been migrated off 'post' yet.
				'post_type'      => array( 'post', Daymark_Like_Visibility::POST_TYPE ),
				'post_status'    => array( 'publish', 'draft' ),
				'author'         => get_current_user_id(),
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match lookup on a single-value meta key, no alternative query shape.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match is the point; see docblock above for scale reasoning.
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $found ) ? absint( $found[0] ) : 0;
	}

	/**
	 * Prepare a Mark summary response array.
	 *
	 * @param int $post_id Mark post ID.
	 * @return array<string, mixed>
	 */
	private function prepare_mark_summary( int $post_id ): array {
		$author_id = (int) get_post_field( 'post_author', $post_id );

		$summary = array(
			'id'                 => absint( $post_id ),
			// Plain text: the_title filters entity-encode (&#8217; etc.) for
			// HTML output, but API consumers escape at render time themselves.
			'title'              => html_entity_decode(
				sanitize_text_field( get_the_title( $post_id ) ),
				ENT_QUOTES,
				'UTF-8'
			),
			'permalink'          => esc_url_raw( (string) get_permalink( $post_id ) ),
			'status'             => sanitize_key( (string) get_post_status( $post_id ) ),
			'type'               => sanitize_key( (string) get_post_meta( $post_id, '_daymark_primary_type', true ) ),
			// The post's own real WordPress post format ('standard' when
			// unset). A true Mark's `type` above always takes priority
			// client-side; this exists only for an ordinary post published
			// straight through the block editor, which has no
			// _daymark_primary_type of its own — so the Timeline card can
			// tell "Standard post that happens to carry a featured image"
			// apart from a real Image/Gallery/Video format, the same
			// distinction a subscription post's own post_format already
			// draws (see resolveCardKind(), app.js).
			'post_format'        => sanitize_key( (string) ( get_post_format( $post_id ) ?: 'standard' ) ),
			// The 24-word-trimmed caption Daymark_Publisher already stores as
			// this post's own post_excerpt (see class-publisher.php) — not
			// previously exposed here, so a Timeline card had no way to show
			// a Note Mark's actual text, only its (often timestamp-fallback)
			// title.
			'excerpt'            => sanitize_text_field( (string) get_post_field( 'post_excerpt', $post_id ) ),
			// phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core helper, not the removed mysql_ extension.
			'date'               => mysql_to_rfc3339( (string) get_post_field( 'post_date', $post_id ) ),
			'thumbnail'          => $this->mark_thumbnail_url( $post_id ),
			'comment_count'      => $this->count_comments_of_type( $post_id, 'comment' ),
			// Federation-plugin likes (stored as comments) plus WordPress.com
			// likes, which Jetpack keeps off-site — see
			// Daymark_Jetpack_Engagement::sync_own_likes().
			'like_count'         => $this->count_comments_of_type( $post_id, 'like' )
				+ Daymark_Jetpack_Engagement::own_likes( $post_id )['count'],
			// Every reblog, with or without the reblogger's own words — see
			// count_reblogs(). A polling connector's own reactions aren't
			// pulled in at all (backflow only imports replies), so they
			// aren't counted; extending backflow to sync reaction counts is
			// tracked separately on issue #41.
			'repost_count'       => $this->count_reblogs( $post_id ),
			'syndication_status' => sanitize_key( (string) get_post_meta( $post_id, '_daymark_syndication_status', true ) ),
			'bookmarked'         => Daymark_Plugin::instance()->bookmarks->is_bookmarked( get_current_user_id(), $post_id ),
			// Whose post this is, so a Timeline card can label your own
			// posts "You" and another author's (on a multi-author site) by
			// name, instead of every post on this site reading the same.
			'is_mine'            => $author_id > 0 && get_current_user_id() === $author_id,
			'author_name'        => $author_id > 0
				? sanitize_text_field( (string) get_the_author_meta( 'display_name', $author_id ) )
				: '',
		);

		// Quiet metadata capture: only ever present when a value was
		// actually resolved for this Mark — omitted (not null-valued)
		// otherwise, so a Timeline card can use a plain presence check.
		$captured_at = (string) get_post_meta( $post_id, '_daymark_captured_at', true );

		if ( '' !== $captured_at ) {
			// phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core helper, not the removed mysql_ extension.
			$summary['captured_at'] = mysql_to_rfc3339( $captured_at );
		}

		$reading_time = get_post_meta( $post_id, '_daymark_reading_time_minutes', true );

		if ( is_numeric( $reading_time ) ) {
			$summary['reading_time_minutes'] = (int) $reading_time;
		}

		$location = json_decode( (string) get_post_meta( $post_id, '_daymark_location', true ), true );

		// A Mark's quietly captured coordinates go only to someone who can
		// edit it (the Privacy tab tells the site owner this location "stays
		// visible only to you"); the Timeline is shared, so without this every
		// Author saw every other user's exact position. A Check In is the
		// exception: its location is one the author chose to share, and its
		// map link is already part of its public content.
		$can_see_location = current_user_can( 'edit_post', $post_id ) || 'checkin' === (string) get_post_meta( $post_id, '_daymark_primary_type', true );

		if ( $can_see_location && is_array( $location ) && isset( $location['lat'], $location['lng'] ) && is_numeric( $location['lat'] ) && is_numeric( $location['lng'] ) ) {
			$summary['location'] = array(
				'lat' => (float) $location['lat'],
				'lng' => (float) $location['lng'],
			);
		}

		// A Checkin Mark's resolved place name (issue #143) — the composer's
		// own "Place" field re-populates from this when resuming a draft.
		$place_name = (string) get_post_meta( $post_id, '_daymark_place_name', true );

		if ( '' !== $place_name ) {
			$summary['place_name'] = $place_name;
		}

		// A Check In's own optional attached photo/video (issue #424): its
		// `type` above always stays 'checkin' (an explicit primary_type
		// override wins in Daymark_Publisher::detect_primary_type() itself —
		// a checkin's own point is the place, so media never reclassifies
		// it), so the Timeline card needs a second signal to know what real
		// media, if any, is actually attached. Omitted (not a null/empty
		// value) whenever there's nothing attached, so mediaKindForItem()
		// (assets/app.js) can use a plain presence check the same way
		// captured_at/reading_time_minutes/location/place_name above do.
		if ( 'checkin' === $summary['type'] ) {
			$raw_media_ids = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );
			$media_ids     = is_array( $raw_media_ids ) ? array_map( 'absint', $raw_media_ids ) : array();

			if ( ! empty( $media_ids ) ) {
				$summary['media_kind'] = Daymark_Plugin::instance()->publisher->detect_media_kind( $media_ids );
			}
		}

		// Featured Content (issue #401) — any post type, not Mark-specific,
		// but a Timeline card is exactly the kind of "where a Featured Image
		// would show" slot that feature already replaces by default on the
		// front end (see maybe_replace_post_thumbnail_html()), so the app
		// shell's own card follows the same rule. The type is always
		// exposed — resolveCardKind()/renderCardMedia() (assets/app.js)
		// already have a full placeholder/play-button treatment for
		// 'audio'/'video' that needs no real thumbnail to look intentional.
		// A gallery additionally carries its first four image URLs and total
		// image count, for the card's 2x2 thumbnail grid.
		$featured_content = Daymark_Featured_Content::get_featured_content( $post_id );

		if ( ! empty( $featured_content ) ) {
			$summary['featured_content'] = array(
				'type' => $featured_content['type'],
			);

			// A video or audio card shows the video's own thumbnail (or the
			// file's cover art) with a play button, like the post view's
			// preview, instead of a placeholder. This is the share image
			// Daymark_Featured_Content_Social resolves when the Featured
			// Content is saved; reading it never fetches anything, so a
			// thumbnail not resolved yet just leaves the placeholder.
			if ( in_array( $featured_content['type'], array( 'video', 'audio' ), true ) ) {
				$image = Daymark_Featured_Content_Social::image( $post_id );

				if ( ! empty( $image['url'] ) ) {
					$summary['featured_content']['image'] = esc_url_raw( $image['url'] );
				}
			}

			// A link's card shows a preview of the linked page (its image,
			// title, and site) where a featured image would go. Only an
			// already-cached Open Graph lookup is read here; a link not looked
			// up yet carries `preview: null`, and the app asks
			// GET /marks/{id}/featured-content-link for it.
			if ( 'link' === $featured_content['type'] ) {
				$link_url = (string) ( $featured_content['data']['url'] ?? '' );
				$saved    = Daymark_Featured_Content_Social::link_preview( $post_id );
				$cached   = null === $saved ? Daymark_Subscription_Opengraph::cached( $link_url ) : null;

				$summary['featured_content']['url']     = esc_url_raw( $link_url );
				$summary['featured_content']['host']    = Daymark_Featured_Content::url_host_label( $link_url );
				$summary['featured_content']['preview'] = null !== $saved ? $saved : ( null === $cached ? null : self::link_preview_fields( $cached ) );
			}

			// A quote's card shows the quote itself where a featured image
			// would go, so its text and credit travel with the summary. Plain
			// text, cut to a card-sized length; the full quote is in the post.
			if ( 'quote' === $featured_content['type'] ) {
				$text = (string) ( $featured_content['data']['text'] ?? '' );

				if ( mb_strlen( $text ) > self::CARD_QUOTE_MAX_CHARS ) {
					$text = rtrim( mb_substr( $text, 0, self::CARD_QUOTE_MAX_CHARS - 1 ) ) . '…';
				}

				$summary['featured_content']['text']   = $text;
				$summary['featured_content']['credit'] = Daymark_Featured_Content::quote_credit( (array) $featured_content['data'] );
			}

			// A gallery's card shows its first four images as a 2x2 grid
			// (issue #406), so those thumbnail URLs travel with the summary.
			if ( 'gallery' === $featured_content['type'] ) {
				$ids = array_map( 'absint', (array) ( $featured_content['data']['attachment_ids'] ?? array() ) );

				$summary['featured_content']['images'] = self::card_grid_image_urls( $ids );
				$summary['featured_content']['count']  = count( $ids );
			}
		}

		// A Mark with several photos (a gallery Mark, or a Check In with
		// more than one photo) shows them as the same 2x2 grid, so its first
		// four photos and its photo count travel with the summary too.
		if ( in_array( $summary['type'], array( 'gallery', 'checkin' ), true ) ) {
			$raw_ids   = json_decode( (string) get_post_meta( $post_id, '_daymark_media_ids', true ), true );
			$image_ids = array_values(
				array_filter(
					is_array( $raw_ids ) ? array_map( 'absint', $raw_ids ) : array(),
					'wp_attachment_is_image'
				)
			);

			if ( count( $image_ids ) > 1 ) {
				$summary['gallery'] = array(
					'images' => self::card_grid_image_urls( $image_ids ),
					'count'  => count( $image_ids ),
				);
			}
		}

		return $summary;
	}

	/**
	 * The first four images' URLs for a Timeline card's 2x2 grid, in the
	 * order given. `medium_large` (768px wide) stays sharp in a half-width
	 * cell on a high-density phone screen.
	 *
	 * @param int[] $attachment_ids Image attachment IDs, in display order.
	 * @return string[]
	 */
	private static function card_grid_image_urls( array $attachment_ids ): array {
		$images = array();

		foreach ( array_slice( $attachment_ids, 0, 4 ) as $attachment_id ) {
			// A GIF at full size, so an animated one keeps its animation (#482).
			$url = wp_get_attachment_image_url( absint( $attachment_id ), Daymark_Publisher::display_size( absint( $attachment_id ), 'medium_large' ) );

			if ( $url ) {
				$images[] = esc_url_raw( $url );
			}
		}

		return $images;
	}

	/**
	 * Count approved comments of one comment_type on a Mark, for the app
	 * shell's comment/like/repost stat row. Replies (comment_type 'comment')
	 * include on-site comments and, once backflow imports them, replies from
	 * Bluesky/the fediverse/webmention; likes ('like') and reposts ('repost')
	 * are populated the same way, written directly by the ActivityPub/
	 * ATmosphere/Webmention plugins when they receive one. All three are 0
	 * for a Mark with no connected federation plugin or no engagement yet.
	 *
	 * @param int    $post_id Mark post ID.
	 * @param string $type    Comment type ('comment', 'like', or 'repost').
	 * @return int
	 */
	private function count_comments_of_type( int $post_id, string $type ): int {
		return (int) get_comments(
			array(
				'post_id' => $post_id,
				'type'    => $type,
				'status'  => 'approve',
				'count'   => true,
			)
		);
	}

	/**
	 * How many times a Mark has been reblogged, with or without the
	 * reblogger's own words (issue #396).
	 *
	 * - `repost` comments: a plain reblog or boost, as the ActivityPub,
	 *   ATmosphere, and Webmention plugins store it (issue #41). A Webmention
	 *   reblog with commentary is still a `repost`, since the Webmention
	 *   plugin types by `u-repost-of` and ignores any text alongside it.
	 * - `quote` comments: a quote post (a reblog with commentary) from the
	 *   fediverse, which the ActivityPub plugin stores under its own `quote`
	 *   type (`Activitypub\Comment::register_comment_types()`).
	 * - Reblog Marks published on this same site (another author here
	 *   reblogging it), which no plugin turns into a comment. One that the
	 *   Webmention plugin already recorded as a comment (by its source URL)
	 *   is not counted twice.
	 *
	 * @param int $post_id Mark post ID.
	 * @return int
	 */
	private function count_reblogs( int $post_id ): int {
		$comments = get_comments(
			array(
				'post_id'  => $post_id,
				'type__in' => array( 'repost', 'quote' ),
				'status'   => 'approve',
				'fields'   => 'ids',
			)
		);

		$counted_sources = array();

		foreach ( $comments as $comment_id ) {
			$source = (string) get_comment_meta( (int) $comment_id, 'webmention_source_url', true );

			if ( '' !== $source ) {
				$counted_sources[ untrailingslashit( $source ) ] = true;
			}
		}

		$permalink = (string) get_permalink( $post_id );
		$targets   = array_values( array_unique( array_filter( array( $permalink, untrailingslashit( $permalink ), trailingslashit( $permalink ) ) ) ) );
		$local     = empty( $targets ) ? array() : get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array( $post_id ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match lookup, one per Mark card, at personal-site scale (same posture as find_own_mark_id_by_target_url()).
					array(
						'key'     => '_daymark_repost_of',
						'value'   => $targets,
						'compare' => 'IN',
					),
				),
			)
		);

		$local_uncounted = 0;

		foreach ( $local as $reblog_id ) {
			if ( ! isset( $counted_sources[ untrailingslashit( (string) get_permalink( (int) $reblog_id ) ) ] ) ) {
				++$local_uncounted;
			}
		}

		return count( $comments ) + $local_uncounted;
	}

	/**
	 * A Mark's thumbnail URL: the featured image first, then the first
	 * attachment from _daymark_media_ids if it is an image. Without this
	 * fallback, a Mark that never got a featured image set (some installs
	 * migrated from Moment have this) would show no thumbnail in the app
	 * shell's Timeline even though it has a perfectly good image attached.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string Thumbnail URL, or '' when none is available.
	 */
	private function mark_thumbnail_url( int $post_id ): string {
		$attachment_id = (int) get_post_thumbnail_id( $post_id );

		if ( 0 === $attachment_id ) {
			$raw       = get_post_meta( $post_id, '_daymark_media_ids', true );
			$media_ids = json_decode( is_string( $raw ) ? $raw : '', true );

			if ( is_array( $media_ids ) && ! empty( $media_ids ) ) {
				$attachment_id = absint( reset( $media_ids ) );
			}
		}

		if ( 0 === $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}

		// medium_large (768px wide): the thumbnail is also a card's
		// full-width banner, which a 300px `medium` image would blur on a
		// high-density phone screen. A GIF stays at full size, so an
		// animated one keeps its animation (#482).
		$url = wp_get_attachment_image_url( $attachment_id, Daymark_Publisher::display_size( $attachment_id, 'medium_large' ) );

		return $url ? esc_url_raw( $url ) : '';
	}
}
