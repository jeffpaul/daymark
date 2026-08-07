<?php
/**
 * Daymark view renderer.
 *
 * Produces escaped HTML for the timeline, images, videos, audio, and
 * notes views. Shared by the daymark_* shortcodes and daymark/* dynamic
 * blocks so both surfaces render identical markup.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Daymark feed views. All output is escaped at build time.
 */
class Daymark_Renderer {

	/**
	 * Default number of Marks per view.
	 */
	private const DEFAULT_COUNT = 10;

	/**
	 * Hard cap on Marks per view.
	 */
	private const MAX_COUNT = 50;

	/**
	 * Map of view => _daymark_primary_type values included in that view.
	 * An empty array means no type filter (all Marks).
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
	private const STYLE_HANDLE = 'daymark-views';

	/**
	 * Outline comment-bubble icon (Feather-style, 24x24, stroke-based —
	 * matches the icon convention already used in assets/app.js).
	 */
	private const ICON_COMMENT = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-4.7 7.6 8.5 8.5 0 0 1-3.8.9H12a8.48 8.48 0 0 1-4-.9l-5 1 1-5a8.48 8.48 0 0 1-.9-4 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>';

	/**
	 * Outline heart icon, same style as ICON_COMMENT.
	 */
	private const ICON_HEART = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21.2l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"></path></svg>';

	/**
	 * Render a Mark view.
	 *
	 * @param string               $view Timeline|images|videos|audio|notes.
	 * @param array<string, mixed> $args Optional view arguments. Supports
	 *                                   'count' and 'wrapper_attributes' — the
	 *                                   latter is a pre-built HTML attribute
	 *                                   string (from get_block_wrapper_attributes())
	 *                                   that the daymark/* blocks pass in so
	 *                                   color/typography/spacing block supports
	 *                                   reach the actual markup. The shortcode
	 *                                   path leaves it unset and gets the plain
	 *                                   class attribute instead.
	 * @return string Escaped HTML.
	 */
	public function render( string $view, array $args = array() ): string {
		if ( ! isset( self::VIEW_TYPES[ $view ] ) ) {
			$view = 'timeline';
		}

		$count = absint( $args['count'] ?? self::DEFAULT_COUNT );
		$count = max( 1, min( self::MAX_COUNT, $count ) );

		$this->enqueue_styles();

		$query = new WP_Query( $this->build_query_args( $view, $count ) );

		$wrapper_attributes = isset( $args['wrapper_attributes'] ) && is_string( $args['wrapper_attributes'] ) && '' !== $args['wrapper_attributes']
			? $args['wrapper_attributes']
			: 'class="daymark-view daymark-view--' . esc_attr( $view ) . '"';

		$html = '<div ' . $wrapper_attributes . '>';

		if ( ! $query->have_posts() ) {
			$html .= '<p class="daymark-view-empty">' . esc_html( $this->empty_message( $view ) ) . '</p>';
			$html .= '</div>';

			return $html;
		}

		$html .= '<div class="daymark-view-list">';

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
				'key'   => '_daymark_is_mark',
				'value' => '1',
			),
		);

		$types = self::VIEW_TYPES[ $view ];

		if ( ! empty( $types ) ) {
			$meta_query[] = array(
				'key'     => '_daymark_primary_type',
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Marks are identified by meta by design (standard post type, no CPT).
			'meta_query'          => $meta_query,
		);
	}

	/**
	 * Render a single Mark item.
	 *
	 * @param WP_Post $post The Mark post.
	 * @return string Escaped HTML.
	 */
	private function render_item( WP_Post $post ): string {
		$permalink = get_permalink( $post );
		$type      = get_post_meta( $post->ID, '_daymark_primary_type', true );
		$type      = is_string( $type ) && '' !== $type ? sanitize_key( $type ) : 'note';
		$caption   = get_the_excerpt( $post );
		$title     = get_the_title( $post );

		$html = '<article class="daymark-item daymark-item--' . esc_attr( $type ) . '">';

		$has_caption   = is_string( $caption ) && '' !== $caption;
		$note_led_here = false;

		// Every type leads with its most prominent content, ahead of the
		// badge/date row — a thumbnail or player for the types that have
		// one; for a note, which has nothing else, that's its caption
		// (pull-quote styled), since the caption *is* the whole Mark.
		if ( in_array( $type, array( 'audio', 'video' ), true ) ) {
			// Pure audio/video Marks (detect_primary_type() only returns
			// these when no image is attached) get an inline player instead
			// of a thumbnail — there is usually nothing to thumbnail in the
			// first place. It isn't wrapped in the permalink link like the
			// image thumbnail is: native controls need direct interaction.
			$html .= $this->item_player( $post, $type );
		} elseif ( 'note' === $type ) {
			if ( $has_caption ) {
				$html         .= '<p class="daymark-item-caption daymark-item-caption--note">' . esc_html( $caption ) . '</p>';
				$note_led_here = true;
			}
		} else {
			$thumb = $this->item_thumbnail( $post );

			if ( '' !== $thumb ) {
				$html .= '<a class="daymark-item-media" href="' . esc_url( $permalink ) . '" aria-label="' . esc_attr( $title ) . '">' . $thumb . '</a>';
			}
		}

		$html .= '<div class="daymark-item-body">';
		$html .= '<span class="daymark-badge daymark-badge--' . esc_attr( $type ) . '">' . esc_html( $this->type_label( $type ) ) . '</span>';
		$html .= '<a class="daymark-item-date" href="' . esc_url( $permalink ) . '">';
		$html .= '<time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( $this->human_date( $post ) ) . '</time>';
		$html .= '</a>';

		if ( $has_caption && ! $note_led_here ) {
			$html .= '<p class="daymark-item-caption">' . esc_html( $caption ) . '</p>';
		}

		$html .= $this->render_stats( $post->ID );
		$html .= '</div></article>';

		return $html;
	}

	/**
	 * Render the comment/like stat row for a Mark.
	 *
	 * A stat with a zero count shows only its (dimmed) icon — no "0" — so
	 * the row stays quiet until there's something to report; a stat with
	 * one or more shows its count next to a bolder icon.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string Escaped HTML.
	 */
	private function render_stats( int $post_id ): string {
		$counts = $this->reaction_counts( $post_id );

		/* translators: %d: Number of comments on this Mark. */
		$comments_format = _n( '%d comment', '%d comments', $counts['comments'], 'daymark' );
		/* translators: %d: Number of likes on this Mark. */
		$likes_format = _n( '%d like', '%d likes', $counts['likes'], 'daymark' );

		$html  = '<p class="daymark-item-stats">';
		$html .= $this->render_stat( self::ICON_COMMENT, $counts['comments'], 'comments', $comments_format );
		$html .= $this->render_stat( self::ICON_HEART, $counts['likes'], 'likes', $likes_format );
		$html .= '</p>';

		return $html;
	}

	/**
	 * Render one stat pill.
	 *
	 * @param string $icon        Trusted inline SVG markup (a class constant).
	 * @param int    $count       Stat count.
	 * @param string $modifier    BEM modifier for this stat (e.g. 'comments').
	 * @param string $label_format Translated `%d` label used for the accessible name.
	 * @return string Escaped HTML.
	 */
	private function render_stat( string $icon, int $count, string $modifier, string $label_format ): string {
		$is_active = $count > 0;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $icon is a trusted class constant (inline SVG), not user input.
		$html = '<span class="daymark-stat daymark-stat--' . esc_attr( $modifier ) . ( $is_active ? ' daymark-stat--active' : '' ) . '" aria-label="' . esc_attr( sprintf( $label_format, $count ) ) . '">' . $icon;

		if ( $is_active ) {
			$html .= '<span class="daymark-stat__count" aria-hidden="true">' . (int) $count . '</span>';
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * Count a Mark's replies and reactions.
	 *
	 * Replies (comment_type 'comment') include on-site comments and, once
	 * backflow imports them, replies from Bluesky/the fediverse/webmention.
	 * Likes (comment_type 'like') are populated the same way, by
	 * ActivityPub, ATmosphere, and Webmention — all three plugins land
	 * likes under that exact comment type. Both are 0 for Marks with no
	 * connected federation plugin or no engagement yet.
	 *
	 * @param int $post_id Mark post ID.
	 * @return array{comments: int, likes: int}
	 */
	private function reaction_counts( int $post_id ): array {
		return array(
			'comments' => $this->count_comments_of_type( $post_id, 'comment' ),
			'likes'    => $this->count_comments_of_type( $post_id, 'like' ),
		);
	}

	/**
	 * Count approved comments of one comment_type on a post.
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
	 * Get the thumbnail markup for a Mark: featured image first,
	 * then the first attachment from _daymark_media_ids if it is an image.
	 *
	 * @param WP_Post $post The Mark post.
	 * @return string Escaped image HTML or empty string.
	 */
	private function item_thumbnail( WP_Post $post ): string {
		$attachment_id = (int) get_post_thumbnail_id( $post );

		if ( 0 === $attachment_id ) {
			$raw       = get_post_meta( $post->ID, '_daymark_media_ids', true );
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
				'class'   => 'daymark-item-thumb',
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * Inline `<audio>`/`<video>` player for a pure audio or video Mark, using
	 * its first attached file. Empty when the expected attachment is
	 * missing or doesn't actually match the requested media kind — callers
	 * treat an empty return as "nothing to show", same as item_thumbnail().
	 *
	 * @param WP_Post $post The Mark post.
	 * @param string  $type 'audio' or 'video'.
	 * @return string Escaped HTML, or ''.
	 */
	private function item_player( WP_Post $post, string $type ): string {
		$raw       = get_post_meta( $post->ID, '_daymark_media_ids', true );
		$media_ids = json_decode( is_string( $raw ) ? $raw : '', true );

		if ( ! is_array( $media_ids ) || empty( $media_ids ) ) {
			return '';
		}

		$attachment_id = absint( reset( $media_ids ) );
		$expected_mime = 'audio' === $type ? 'audio/' : 'video/';
		$mime          = (string) get_post_mime_type( $attachment_id );

		if ( 0 === $attachment_id || ! str_starts_with( $mime, $expected_mime ) ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$tag = 'audio' === $type ? 'audio' : 'video';

		return '<' . $tag . ' class="daymark-item-player daymark-item-player--' . esc_attr( $type ) . '" controls preload="metadata" src="' . esc_url( $url ) . '"></' . $tag . '>';
	}

	/**
	 * Human-readable date: relative within the last week, absolute after.
	 *
	 * @param WP_Post $post The Mark post.
	 * @return string
	 */
	private function human_date( WP_Post $post ): string {
		$timestamp = (int) get_post_timestamp( $post );
		$now       = time();

		if ( $timestamp > 0 && ( $now - $timestamp ) < WEEK_IN_SECONDS && $timestamp <= $now ) {
			/* translators: %s: human-readable time difference, e.g. "3 hours". */
			return sprintf( __( '%s ago', 'daymark' ), human_time_diff( $timestamp, $now ) );
		}

		return (string) get_the_date( '', $post );
	}

	/**
	 * Translated badge label for a Mark type.
	 *
	 * @param string $type Sanitized _daymark_primary_type value.
	 * @return string
	 */
	private function type_label( string $type ): string {
		$labels = array(
			'image'   => __( 'Image', 'daymark' ),
			'video'   => __( 'Video', 'daymark' ),
			'audio'   => __( 'Audio', 'daymark' ),
			'note'    => __( 'Note', 'daymark' ),
			'gallery' => __( 'Gallery', 'daymark' ),
			'mixed'   => __( 'Mixed', 'daymark' ),
		);

		return $labels[ $type ] ?? __( 'Mark', 'daymark' );
	}

	/**
	 * Empty-state message for a view.
	 *
	 * @param string $view Validated view key.
	 * @return string
	 */
	private function empty_message( string $view ): string {
		$messages = array(
			'timeline' => __( 'No Marks yet. Publish your first Mark and it will appear here.', 'daymark' ),
			'images'   => __( 'No image Marks yet. Share a photo and it will appear here.', 'daymark' ),
			'videos'   => __( 'No video Marks yet. Share a video and it will appear here.', 'daymark' ),
			'audio'    => __( 'No audio Marks yet. Share audio and it will appear here.', 'daymark' ),
			'notes'    => __( 'No note Marks yet. Write a quick note and it will appear here.', 'daymark' ),
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
			DAYMARK_PLUGIN_URL . 'assets/views.css',
			array(),
			DAYMARK_VERSION
		);
	}
}
