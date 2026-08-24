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
			'/ai/suggestions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_suggestions' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'caption' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'type'    => array(
						'type'              => 'string',
						'default'           => 'note',
						'sanitize_callback' => 'sanitize_key',
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
					'caption' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'type'    => array(
						'type'              => 'string',
						'default'           => 'note',
						'sanitize_callback' => 'sanitize_key',
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
						'id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'caption' => array(
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
						'status'  => array(
							'type'              => 'string',
							'enum'              => array( 'publish', 'draft' ),
							'sanitize_callback' => 'sanitize_key',
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
			'/subscriptions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_subscription' ),
					'permission_callback' => array( $this, 'permissions_check' ),
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

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_subscription' ),
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
	 * POST /daymark/v1/marks — create a Mark.
	 *
	 * Accepts multipart file uploads plus caption/type/target fields and
	 * delegates to Daymark_Publisher.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_mark( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_PUBLISH );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files = $request->get_file_params();

		if ( ! empty( $files ) && ! current_user_can( 'upload_files' ) ) {
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
			// Per-image alt: positional array aligned to files[] order.
			'alt'                  => $request->get_param( 'alt' ),
			'tags'                 => array_filter( array_map( 'sanitize_text_field', (array) ( $request->get_param( 'tags' ) ?? array() ) ) ),
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
		$caption = sanitize_textarea_field( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'caption' ) ) );
		$type    = sanitize_key( (string) ( $request->get_param( 'primary_type' ) ?? $request->get_param( 'type' ) ) );

		if ( ! in_array( $type, Daymark_Publisher::PRIMARY_TYPES, true ) ) {
			$type = 'note';
		}

		$suggestions = Daymark_Plugin::instance()->ai_assist->get_suggestions( $caption, $type );

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
		$caption = sanitize_textarea_field( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'caption' ) ) );
		$type    = sanitize_key( (string) ( $request->get_param( 'primary_type' ) ?? $request->get_param( 'type' ) ) );

		if ( ! in_array( $type, Daymark_Publisher::PRIMARY_TYPES, true ) ) {
			$type = 'note';
		}

		$ai    = Daymark_Plugin::instance()->ai_assist;
		$title = $ai->suggest_title(
			array(
				'text' => $caption,
				'type' => $type,
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

		$context = array(
			'text' => sanitize_textarea_field( (string) $request->get_param( 'text' ) ),
			'type' => 'image',
		);

		$suggestion = Daymark_Plugin::instance()->ai_assist->get_image_alt_suggestion( (string) $image['tmp_name'], $context );

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
		$items         = $notifications->get_notifications();

		// This endpoint backs the notifications screen, so serving it IS
		// the user seeing their notifications — clear the unread flag.
		$notifications->mark_seen();

		return rest_ensure_response( $items );
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

		$payload               = $this->prepare_mark_summary( $post_id );
		$payload['caption']    = $caption;
		$payload['media']      = $media;
		$payload['targets']    = is_array( $targets ) ? array_values( array_filter( array_map( 'sanitize_key', $targets ) ) ) : array();
		$payload['helpers']    = is_array( $helpers ) ? array_values( array_filter( array_map( 'sanitize_key', $helpers ) ) ) : array();
		$payload['categories'] = array_map( 'intval', wp_get_post_categories( $post_id ) );

		return rest_ensure_response( $payload );
	}

	/**
	 * POST/PUT /daymark/v1/marks/{id} — update a Mark from the composer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_daymark( WP_REST_Request $request ) {
		$rate = $this->rate_limit( Daymark_Rate_Limiter::ACTION_PUBLISH );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$files = $request->get_file_params();

		if ( ! empty( $files ) && ! current_user_can( 'upload_files' ) ) {
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
			'tags'                => $request->get_param( 'tags' ),
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
	 * Discovers the site's feed via the subscription source registry (source
	 * agnostic, even though only the built-in RSS/Atom feed source ships
	 * today), creates the `daymark_subscription` row, then best-effort
	 * resolves the site's favicon. Rate limited: this issues outbound
	 * requests to a site the user names, same risk class as manual sync.
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
		$scheme   = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );

		if ( '' === $site_url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'daymark_subscription_invalid_url',
				__( 'Please enter a valid site URL.', 'daymark' ),
				array( 'status' => 400 )
			);
		}

		$registry   = Daymark_Plugin::instance()->subscription_source_registry;
		$discovered = $registry->discover_feeds( $site_url );
		$feed       = isset( $discovered[0] ) && is_array( $discovered[0] ) ? $discovered[0] : array();
		$feed_url   = isset( $feed['url'] ) ? (string) $feed['url'] : '';

		if ( '' === $feed_url ) {
			return new WP_Error(
				'daymark_subscription_no_feed_found',
				__( 'No feed could be found at this URL.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		$created = Daymark_Plugin::instance()->subscriptions->create(
			array(
				'site_url'    => $site_url,
				'feed_url'    => $feed_url,
				'source_type' => 'feed',
				'site_title'  => isset( $feed['title'] ) ? sanitize_text_field( (string) $feed['title'] ) : '',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$subscription_id = (int) $created;

		// Best-effort favicon lookup: a one-time enhancement, never a reason
		// to fail the subscribe request itself.
		$feed_source = $registry->get_source( 'feed' );

		if ( $feed_source instanceof Daymark_Subscription_Source_Feed ) {
			$favicon_url = $feed_source->get_favicon_url( $site_url );

			if ( '' !== $favicon_url ) {
				Daymark_Plugin::instance()->subscriptions->update( $subscription_id, array( 'site_icon_url' => $favicon_url ) );
			}
		}

		$subscription = Daymark_Plugin::instance()->subscriptions->get( $subscription_id );

		$response = rest_ensure_response( $this->prepare_subscription( is_array( $subscription ) ? $subscription : array() ) );
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
	 * Trashes every cached `daymark_subscription_post` ingested from this
	 * subscription (relying on core's normal 7-day trash retention for
	 * eventual deletion — no custom deletion logic), then deletes the
	 * subscription row itself.
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

		$query = new WP_Query(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale unsubscribe cleanup.
				'meta_query'     => array(
					array(
						'key'     => 'subscription_id',
						'value'   => $id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$trashed_count = 0;

		foreach ( $query->posts as $post_id ) {
			if ( wp_trash_post( (int) $post_id ) ) {
				++$trashed_count;
			}
		}

		$subscriptions->delete( $id );

		return rest_ensure_response(
			array(
				'deleted'       => true,
				'trashed_posts' => $trashed_count,
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
			'site_icon_url'             => esc_url_raw( (string) ( $row['site_icon_url'] ?? '' ) ),
			'status'                    => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'consecutive_failure_count' => absint( $row['consecutive_failure_count'] ?? 0 ),
			'last_checked_at'           => sanitize_text_field( (string) ( $row['last_checked_at'] ?? '' ) ),
			'last_manual_refresh_at'    => sanitize_text_field( (string) ( $row['last_manual_refresh_at'] ?? '' ) ),
			'created_at'                => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	/**
	 * Prepare a Mark summary response array.
	 *
	 * @param int $post_id Mark post ID.
	 * @return array<string, mixed>
	 */
	private function prepare_mark_summary( int $post_id ): array {
		return array(
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
			// phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core helper, not the removed mysql_ extension.
			'date'               => mysql_to_rfc3339( (string) get_post_field( 'post_date', $post_id ) ),
			'thumbnail'          => $this->mark_thumbnail_url( $post_id ),
			'comment_count'      => $this->count_comments_of_type( $post_id, 'comment' ),
			'like_count'         => $this->count_comments_of_type( $post_id, 'like' ),
			'syndication_status' => sanitize_key( (string) get_post_meta( $post_id, '_daymark_syndication_status', true ) ),
		);
	}

	/**
	 * Count approved comments of one comment_type on a Mark — mirrors
	 * Daymark_Renderer::count_comments_of_type(), which the public views
	 * use for the same stat. Replies (comment_type 'comment') include
	 * on-site comments and, once backflow imports them, replies from
	 * Bluesky/the fediverse/webmention; likes (comment_type 'like') are
	 * populated the same way. Both are 0 for a Mark with no connected
	 * federation plugin or no engagement yet.
	 *
	 * @param int    $post_id Mark post ID.
	 * @param string $type    Comment type ('comment' or 'like').
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
	 * A Mark's thumbnail URL: the featured image first, then the first
	 * attachment from _daymark_media_ids if it is an image — the same
	 * fallback Daymark_Renderer::item_thumbnail() uses for the public
	 * views. Without it, a Mark that never got a featured image set
	 * (some installs migrated from Moment have this) shows no thumbnail
	 * here even though that same fallback already finds one for the
	 * public Timeline.
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

		$url = wp_get_attachment_image_url( $attachment_id, 'medium' );

		return $url ? esc_url_raw( $url ) : '';
	}
}
