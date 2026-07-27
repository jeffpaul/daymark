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
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications' ),
				'permission_callback' => array( $this, 'permissions_check' ),
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
			'ai_assist_used'       => rest_sanitize_boolean( $request->get_param( 'ai_assist_used' ) ),
			'alt_text'             => sanitize_text_field( (string) $request->get_param( 'alt_text' ) ),
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
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );

		// Status filter, so drafts stay reachable in the app no matter how
		// many Moments have published since (the Home Drafts row).
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$statuses = in_array( $status, array( 'publish', 'draft' ), true )
			? array( $status )
			: array( 'publish', 'draft' );

		$query = new WP_Query(
			array(
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
			)
		);

		$moments = array();

		foreach ( $query->posts as $post ) {
			// Published Moments are public; drafts are only listed for
			// users who can edit that specific post (authors see their
			// own, editors see all).
			if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$moments[] = $this->prepare_moment_summary( $post->ID );
		}

		return rest_ensure_response( $moments );
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
			);
		}

		$targets = json_decode( (string) get_post_meta( $post_id, '_moment_syndication_targets', true ), true );
		$helpers = json_decode( (string) get_post_meta( $post_id, Moment_Publish_Helpers::CONTROL_META, true ), true );

		$payload            = $this->prepare_moment_summary( $post_id );
		$payload['caption'] = $caption;
		$payload['media']   = $media;
		$payload['targets'] = is_array( $targets ) ? array_values( array_filter( array_map( 'sanitize_key', $targets ) ) ) : array();
		$payload['helpers'] = is_array( $helpers ) ? array_values( array_filter( array_map( 'sanitize_key', $helpers ) ) ) : array();

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
			'alt_text'            => sanitize_text_field( (string) $request->get_param( 'alt_text' ) ),
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
