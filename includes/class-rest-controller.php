<?php
/**
 * REST API controller for the /wp-json/moment/v1/ namespace.
 *
 * Every endpoint verifies the X-WP-Nonce header AND the edit_posts
 * capability before processing. No unauthenticated endpoints, ever.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles Moment REST endpoints.
 */
class Moment_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'moment/v1';

	/**
	 * Maximum Moments per page for GET /moments.
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
			'/moments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_moment' ),
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
							'description' => __( 'Selected connector IDs (array or JSON string).', 'moment' ),
						),
						'default_destinations' => array(
							'description' => __( 'Default connector IDs (array or JSON string).', 'moment' ),
						),
						'categories'           => array(
							'description' => __( 'Category term IDs to file the Moment under (array or JSON string).', 'moment' ),
						),
						'ai_assist_used'       => array(
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_moments' ),
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
			'/ai/alt-text',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_alt_text' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/moments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_moment' ),
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
					'callback'            => array( $this, 'update_moment' ),
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
					'callback'            => array( $this, 'delete_moment' ),
<<<<<<< HEAD
					'permission_callback' => array( $this, 'permissions_check_post' ),
=======
					'permission_callback' => array( $this, 'permissions_check_delete' ),
>>>>>>> upstream/main
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
			'/moments/(?P<id>\d+)/sync-responses',
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
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications' ),
				'permission_callback' => array( $this, 'permissions_check' ),
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
				__( 'Invalid nonce.', 'moment' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'moment' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Per-post permission callback: the shared check plus edit_post on the
	 * targeted Moment, so users cannot act on posts they cannot edit.
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
				__( 'You cannot manage responses for this Moment.', 'moment' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Per-post permission callback for deletion: the shared nonce + capability
	 * check plus the delete_post capability on the targeted Moment. Deleting is
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
				__( 'You cannot delete this Moment.', 'moment' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * POST /moment/v1/moments — create a Moment.
	 *
	 * Accepts multipart file uploads plus caption/type/target fields and
	 * delegates to Moment_Publisher.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_moment( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( ! empty( $files ) && ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'moment' ),
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

		$post_id = Moment_Plugin::instance()->publisher->publish( $data, is_array( $files ) ? $files : array() );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$response = rest_ensure_response( $this->prepare_moment_summary( $post_id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * GET /moment/v1/moments — recent Moment summaries.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_moments( WP_REST_Request $request ) {
<<<<<<< HEAD
		$query = new WP_Query( $this->get_moments_query_args( $request ) );
=======
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );

		// Status filter, so drafts stay reachable in the app no matter how
		// many Moments have published since (the Home Drafts row).
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Personal-site-scale Moment lookup.
			'meta_key'       => '_moment_is_moment',
			'meta_value'     => '1',
		);
>>>>>>> upstream/main

		// Optional content-type filter: narrow to one _moment_primary_type.
		// This adds a second meta condition, so switch to an explicit
		// meta_query that keeps the _moment_is_moment gate intact.
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' !== $type ) {
			unset( $args['meta_key'], $args['meta_value'] );
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Moment lookup.
			$args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'   => '_moment_is_moment',
					'value' => '1',
				),
				array(
					'key'   => '_moment_primary_type',
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

		$moments = array();

		foreach ( $query->posts as $post ) {
			if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$moments[] = $this->prepare_moment_summary( $post->ID );
		}

		return rest_ensure_response( $moments );
	}

	/**
	 * Build the WP_Query args for listing Moments, merging optional filter
	 * arguments into a consistent query shape.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string, mixed>
	 */
	private function get_moments_query_args( WP_REST_Request $request ): array {
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );

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
			'meta_key'       => '_moment_is_moment',
			'meta_value'     => '1',
		);

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' !== $type ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_moment_primary_type',
					'value' => $type,
				),
			);
		}

		$search = sanitize_text_field( (string) $request->get_param( 's' ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return $args;
	}

	/**
	 * POST /moment/v1/ai/suggestions — AI Assist suggestions.
	 *
	 * Delegates to Moment_AI_Assist, which falls back to deterministic
	 * mock suggestions when no provider is configured. Never blocks
	 * publishing.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function ai_suggestions( WP_REST_Request $request ) {
		// Canonical request fields are `text` and `primary_type`; accept the
		// older `caption`/`type` names as fallbacks.
		$caption = sanitize_textarea_field( (string) ( $request->get_param( 'text' ) ?? $request->get_param( 'caption' ) ) );
		$type    = sanitize_key( (string) ( $request->get_param( 'primary_type' ) ?? $request->get_param( 'type' ) ) );

		if ( ! in_array( $type, Moment_Publisher::PRIMARY_TYPES, true ) ) {
			$type = 'note';
		}

		$suggestions = Moment_Plugin::instance()->ai_assist->get_suggestions( $caption, $type );

		return rest_ensure_response( $suggestions );
	}

	/**
	 * POST /moment/v1/ai/alt-text — vision alt text for one uploaded image.
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
				__( 'You are not allowed to upload media.', 'moment' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$files = $request->get_file_params();
		$image = isset( $files['image'] ) && is_array( $files['image'] ) ? $files['image'] : null;

		if ( ! $image || empty( $image['tmp_name'] ) || ! is_readable( $image['tmp_name'] ) ) {
			return new WP_Error(
				'moment_no_image',
				__( 'No readable image was provided.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		// Validate from content, not the extension — and only images.
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = (string) $finfo->file( $image['tmp_name'] );

		if ( ! str_starts_with( $mime, 'image/' ) || ! in_array( $mime, Moment_Publisher::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'moment_not_an_image',
				__( 'Alt text can only be generated for images.', 'moment' ),
				array( 'status' => 400 )
			);
		}

		$context = array(
			'text' => sanitize_textarea_field( (string) $request->get_param( 'text' ) ),
			'type' => 'image',
		);

		$suggestion = Moment_Plugin::instance()->ai_assist->get_image_alt_suggestion( (string) $image['tmp_name'], $context );

		return rest_ensure_response( $suggestion );
	}

	/**
	 * POST /moment/v1/moments/{id}/sync-responses — import mocked social
	 * responses for a Moment (conversation backflow).
	 *
	 * Accepts { "networks": ["bluesky", "instagram"] }; empty or missing
	 * networks means every network in _moment_external_posts. All imports
	 * are mocked — a real connector would plug into
	 * Moment_Notifications::import_response().
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_responses( WP_REST_Request $request ) {
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

		$result = Moment_Plugin::instance()->notifications->import_responses( $post_id, $networks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /moment/v1/notifications/{comment_id}/reply — reply to a comment
	 * on a Moment from within the app.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reply_to_comment( WP_REST_Request $request ) {
		$comment_id = absint( $request->get_param( 'comment_id' ) );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error(
				'moment_comment_not_found',
				__( 'Comment not found.', 'moment' ),
				array( 'status' => 404 )
			);
		}

		// The parent post must be a Moment the user can edit.
		if ( '1' !== get_post_meta( (int) $comment->comment_post_ID, '_moment_is_moment', true )
			|| ! current_user_can( 'edit_post', (int) $comment->comment_post_ID )
		) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You cannot reply to this comment.', 'moment' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$user     = wp_get_current_user();
		$reply_id = wp_new_comment(
			array(
				'comment_post_ID'      => (int) $comment->comment_post_ID,
				'comment_parent'       => $comment_id,
				'comment_content'      => sanitize_textarea_field( (string) $request->get_param( 'content' ) ),
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => '',
				'user_id'              => $user->ID,
				'comment_approved'     => 1,
			)
		);

		if ( ! $reply_id ) {
			return new WP_Error(
				'moment_reply_failed',
				__( 'Could not post reply.', 'moment' ),
				array( 'status' => 500 )
			);
		}

		$reply = get_comment( $reply_id );

		return rest_ensure_response(
			array(
				'id'      => $reply_id,
				'author'  => $user->display_name,
				'date'    => $reply ? $reply->comment_date_gmt : '',
				'content' => sanitize_textarea_field( (string) $request->get_param( 'content' ) ),
			)
		);
	}

	/**
	 * GET /moment/v1/notifications — unified Moment activity list.
	 *
	 * Returns approved comments (on-site and imported social responses)
	 * for Moment-created posts only. Comments on non-Moment posts are
	 * excluded server-side by Moment_Notifications.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_notifications( WP_REST_Request $request ) {
		// Viewing the feed freshens it: a stale feed schedules an async
		// background sync (never a manual control, never blocks this request).
		Moment_Plugin::instance()->backflow_sync->maybe_freshen();

		unset( $request ); // No query args yet; Moment-only scope is enforced server-side.

		$notifications = Moment_Plugin::instance()->notifications;
		$items         = $notifications->get_notifications();

		// This endpoint backs the notifications screen, so serving it IS
		// the user seeing their notifications — clear the unread flag.
		$notifications->mark_seen();

		return rest_ensure_response( $items );
	}

	/**
	 * GET /moment/v1/moments/{id} — full editable payload for the composer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_moment( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_moment_is_moment', true ) ) {
			return new WP_Error(
				'moment_not_found',
				__( 'Not a Moment post.', 'moment' ),
				array( 'status' => 404 )
			);
		}

		$caption = (string) get_post_meta( $post_id, '_moment_caption', true );

		// Moments created before the caption meta existed: recover the
		// paragraph text from the derived block markup.
		if ( '' === $caption && preg_match_all( '#<p>(.*?)</p>#s', $post->post_content, $matches ) ) {
			$caption = implode( "\n\n", array_map( 'wp_strip_all_tags', $matches[1] ) );
		}

		$media_ids = json_decode( (string) get_post_meta( $post_id, '_moment_media_ids', true ), true );
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

		$targets = json_decode( (string) get_post_meta( $post_id, '_moment_syndication_targets', true ), true );
		$helpers = json_decode( (string) get_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, true ), true );

		$payload               = $this->prepare_moment_summary( $post_id );
		$payload['caption']    = $caption;
		$payload['media']      = $media;
		$payload['targets']    = is_array( $targets ) ? array_values( array_filter( array_map( 'sanitize_key', $targets ) ) ) : array();
		$payload['helpers']    = is_array( $helpers ) ? array_values( array_filter( array_map( 'sanitize_key', $helpers ) ) ) : array();
		$payload['categories'] = array_map( 'intval', wp_get_post_categories( $post_id ) );

		return rest_ensure_response( $payload );
	}

	/**
	 * POST/PUT /moment/v1/moments/{id} — update a Moment from the composer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_moment( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( ! empty( $files ) && ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'You are not allowed to upload media.', 'moment' ),
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
			// map keyed by attachment ID for media already on the Moment.
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

		$result = Moment_Plugin::instance()->publisher->update(
			absint( $request->get_param( 'id' ) ),
			$data,
			$files
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_moment_summary( (int) $result ) );
	}

	/**
	 * DELETE /moment/v1/moments/{id} — trash a Moment.
	 *
<<<<<<< HEAD
=======
	 * Reversible: sends the post to the trash via wp_trash_post rather than
	 * deleting it permanently. Scoped to Moment posts, so a non-Moment id is a
	 * 404. Idempotent — an already-trashed Moment returns success.
	 *
	 * @since 0.5.0
	 *
>>>>>>> upstream/main
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_moment( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '1' !== get_post_meta( $post_id, '_moment_is_moment', true ) ) {
			return new WP_Error(
				'moment_not_found',
				__( 'Not a Moment post.', 'moment' ),
				array( 'status' => 404 )
			);
		}

<<<<<<< HEAD
		if ( 'trash' === $post->post_status ) {
			return rest_ensure_response( array( 'deleted' => true ) );
		}

		if ( ! wp_trash_post( $post_id ) ) {
			return new WP_Error(
				'moment_delete_failed',
				__( 'Could not delete this Moment.', 'moment' ),
=======
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
				'moment_trash_failed',
				__( 'The Moment could not be trashed.', 'moment' ),
>>>>>>> upstream/main
				array( 'status' => 500 )
			);
		}

<<<<<<< HEAD
		return rest_ensure_response( array( 'deleted' => true ) );
=======
		return rest_ensure_response(
			array(
				'id'      => $post_id,
				'trashed' => true,
				'status'  => sanitize_key( (string) get_post_status( $post_id ) ),
			)
		);
	}

	/**
	 * POST /moment/v1/notifications/{comment_id}/reply — reply to a comment
	 * on a Moment from the notifications screen.
	 *
	 * Validates that the comment exists and its parent post is a Moment the
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
				'moment_comment_not_found',
				__( 'Comment not found.', 'moment' ),
				array( 'status' => 404 )
			);
		}

		$post_id = (int) $comment->comment_post_ID;

		// The parent post must be a Moment the current user can edit; anything
		// else is forbidden (never leak whether the post exists).
		if ( '1' !== get_post_meta( $post_id, '_moment_is_moment', true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You cannot reply to this comment.', 'moment' ),
				array( 'status' => 403 )
			);
		}

		$content = sanitize_textarea_field( (string) $request->get_param( 'content' ) );

		if ( '' === $content ) {
			return new WP_Error(
				'moment_empty_reply',
				__( 'A reply cannot be empty.', 'moment' ),
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
>>>>>>> upstream/main
	}

	/**
	 * Prepare a Moment summary response array.
	 *
	 * @param int $post_id Moment post ID.
	 * @return array<string, mixed>
	 */
	private function prepare_moment_summary( int $post_id ): array {
		$thumbnail = get_the_post_thumbnail_url( $post_id, 'medium' );

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
			'type'               => sanitize_key( (string) get_post_meta( $post_id, '_moment_primary_type', true ) ),
			'date'               => mysql_to_rfc3339( (string) get_post_field( 'post_date', $post_id ) ),
			'thumbnail'          => $thumbnail ? esc_url_raw( $thumbnail ) : '',
			'syndication_status' => sanitize_key( (string) get_post_meta( $post_id, '_moment_syndication_status', true ) ),
		);
	}
}
