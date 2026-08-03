<?php
/**
 * Moment view renderer.
 *
 * Produces escaped HTML for the timeline, images, videos, audio, and
 * notes views. Shared by the moment_* shortcodes and moment/* dynamic
 * blocks so both surfaces render identical markup.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Moment feed views. All output is escaped at build time.
 */
class Moment_Renderer {

	/**
	 * Default number of Moments per view.
	 */
	private const DEFAULT_COUNT = 10;

	/**
	 * Hard cap on Moments per view.
	 */
	private const MAX_COUNT = 50;

	/**
	 * Map of view => _moment_primary_type values included in that view.
	 * An empty array means no type filter (all Moments).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const VIEW_TYPES = array(
		'timeline' => array(),
		'images'   => array( 'image', 'gallery', 'mixed' ),
		'videos'   => array( 'video', 'mixed' ),
		'audio'    => array( 'audio' ),
		'notes'    => array( 'note' ),
	);

	/**
	 * Style handle for the view stylesheet.
	 */
	private const STYLE_HANDLE = 'moment-views';

	/**
	 * Render a Moment view.
	 *
	 * @param string               $view One of timeline|images|videos|audio|notes.
	 * @param array<string, mixed> $args Optional view arguments. Supports 'count'.
	 * @return string Escaped HTML.
	 */
	public function render( string $view, array $args = array() ): string {
		if ( ! isset( self::VIEW_TYPES[ $view ] ) ) {
			$view = 'timeline';
		}

		$count = isset( $args['count'] ) ? absint( $args['count'] ) : self::DEFAULT_COUNT;
		$count = max( 1, min( self::MAX_COUNT, $count ) );

		$this->enqueue_styles();

		$query = new WP_Query( $this->build_query_args( $view, $count ) );

		$html = '<div class="moment-view moment-view--' . esc_attr( $view ) . '">';

		if ( ! $query->have_posts() ) {
			$html .= '<p class="moment-view-empty">' . esc_html( $this->empty_message( $view ) ) . '</p>';
			$html .= '</div>';

			return $html;
		}

		$html .= '<div class="moment-view-list">';

		while ( $query->have_posts() ) {
			$query->the_post();
			$html .= $this->render_item( get_post() );
		}

		wp_reset_postdata();

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Build the WP_Query arguments for a view.
	 *
	 * @param string $view  Validated view key.
	 * @param int    $count Sanitized, capped post count.
	 * @return array<string, mixed>
	 */
	private function build_query_args( string $view, int $count ): array {
		$meta_query = array(
			array(
				'key'   => '_moment_is_moment',
				'value' => '1',
			),
		);

		$types = self::VIEW_TYPES[ $view ];

		if ( ! empty( $types ) ) {
			$meta_query[] = array(
				'key'     => '_moment_primary_type',
				'value'   => $types,
				'compare' => 'IN',
			);
		}

		return array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $count,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Moments are identified by meta by design (standard post type, no CPT).
			'meta_query'          => $meta_query,
		);
	}

	/**
	 * Render a single Moment item.
	 *
	 * @param WP_Post $post The Moment post.
	 * @return string Escaped HTML.
	 */
	private function render_item( WP_Post $post ): string {
		$permalink = get_permalink( $post );
		$type      = get_post_meta( $post->ID, '_moment_primary_type', true );
		$type      = is_string( $type ) && '' !== $type ? sanitize_key( $type ) : 'note';
		$caption   = get_the_excerpt( $post );
		$title     = get_the_title( $post );
		$thumb     = $this->item_thumbnail( $post );

		$html = '<article class="moment-item moment-item--' . esc_attr( $type ) . '">';

		if ( '' !== $thumb ) {
			$html .= '<a class="moment-item-media" href="' . esc_url( $permalink ) . '" aria-label="' . esc_attr( $title ) . '">' . $thumb . '</a>';
		}

		$html .= '<div class="moment-item-body">';
		$html .= '<span class="moment-badge moment-badge--' . esc_attr( $type ) . '">' . esc_html( $this->type_label( $type ) ) . '</span>';
		$html .= '<a class="moment-item-date" href="' . esc_url( $permalink ) . '">';
		$html .= '<time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( $this->human_date( $post ) ) . '</time>';
		$html .= '</a>';

		if ( is_string( $caption ) && '' !== $caption ) {
			$html .= '<p class="moment-item-caption">' . esc_html( $caption ) . '</p>';
		}

		$html .= '</div></article>';

		return $html;
	}

	/**
	 * Get the thumbnail markup for a Moment: featured image first,
	 * then the first attachment from _moment_media_ids if it is an image.
	 *
	 * @param WP_Post $post The Moment post.
	 * @return string Escaped image HTML or empty string.
	 */
	private function item_thumbnail( WP_Post $post ): string {
		$attachment_id = (int) get_post_thumbnail_id( $post );

		if ( 0 === $attachment_id ) {
			$raw       = get_post_meta( $post->ID, '_moment_media_ids', true );
			$media_ids = json_decode( is_string( $raw ) ? $raw : '', true );

			if ( is_array( $media_ids ) && ! empty( $media_ids ) ) {
				$attachment_id = absint( reset( $media_ids ) );
			}
		}

		if ( 0 === $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}

		return wp_get_attachment_image(
			$attachment_id,
			'medium',
			false,
			array(
				'class'   => 'moment-item-thumb',
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * Human-readable date: relative within the last week, absolute after.
	 *
	 * @param WP_Post $post The Moment post.
	 * @return string
	 */
	private function human_date( WP_Post $post ): string {
		$timestamp = (int) get_post_timestamp( $post );
		$now       = time();

		if ( $timestamp > 0 && ( $now - $timestamp ) < WEEK_IN_SECONDS && $timestamp <= $now ) {
			/* translators: %s: human-readable time difference, e.g. "3 hours". */
			return sprintf( __( '%s ago', 'moment' ), human_time_diff( $timestamp, $now ) );
		}

		return (string) get_the_date( '', $post );
	}

	/**
	 * Translated badge label for a Moment type.
	 *
	 * @param string $type Sanitized _moment_primary_type value.
	 * @return string
	 */
	private function type_label( string $type ): string {
		$labels = array(
			'image'   => __( 'Image', 'moment' ),
			'video'   => __( 'Video', 'moment' ),
			'audio'   => __( 'Audio', 'moment' ),
			'note'    => __( 'Note', 'moment' ),
			'gallery' => __( 'Gallery', 'moment' ),
			'mixed'   => __( 'Mixed', 'moment' ),
		);

		return $labels[ $type ] ?? __( 'Moment', 'moment' );
	}

	/**
	 * Empty-state message for a view.
	 *
	 * @param string $view Validated view key.
	 * @return string
	 */
	private function empty_message( string $view ): string {
		$messages = array(
			'timeline' => __( 'No Moments yet. Publish your first Moment and it will appear here.', 'moment' ),
			'images'   => __( 'No image Moments yet. Share a photo and it will appear here.', 'moment' ),
			'videos'   => __( 'No video Moments yet. Share a video and it will appear here.', 'moment' ),
			'audio'    => __( 'No audio Moments yet. Share audio and it will appear here.', 'moment' ),
			'notes'    => __( 'No note Moments yet. Write a quick note and it will appear here.', 'moment' ),
		);

		return $messages[ $view ] ?? $messages['timeline'];
	}

	/**
	 * Enqueue the view stylesheet only when a view actually renders.
	 * Styles enqueued mid-page are printed via the late-styles queue.
	 *
	 * @return void
	 */
	private function enqueue_styles(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			MOMENT_PLUGIN_URL . 'assets/views.css',
			array(),
			MOMENT_VERSION
		);
	}
}
