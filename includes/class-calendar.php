<?php
/**
 * The Me screen's "Your days" calendar: which days of a month this site
 * published on, and what it published on one day.
 *
 * It counts the same posts the Timeline shows as your own (GET /timeline
 * with `mine`): every published `post`, a Daymark Mark or one written in
 * the block editor, plus notes from Shortnotes or IndieBlocks, and never a
 * Like Mark. Days are the site's own calendar days (post_date, in the
 * site's timezone), so a Mark sits on the day its author saw when
 * publishing it.
 *
 * Read-only and local: two or three WP_Query calls, no outbound request.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Month and day lookups for the Me screen's calendar.
 */
class Daymark_Calendar {

	/**
	 * The most posts read for one month. A month with more still reports
	 * its real total, but days past this many posts carry no count.
	 *
	 * @var int
	 */
	public const MAX_MONTH_ITEMS = 500;

	/**
	 * The most posts listed for one day.
	 *
	 * @var int
	 */
	public const MAX_DAY_ITEMS = 50;

	/**
	 * Whether a value is a month as this class takes it: YYYY-MM.
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	public static function is_month( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9]{4}-(0[1-9]|1[0-2])$/', $value );
	}

	/**
	 * Whether a value is a real calendar day: YYYY-MM-DD.
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	public static function is_day( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * One month of the calendar.
	 *
	 * @param string $month The month as YYYY-MM. Anything else means the
	 *                      current month on the site's clock.
	 * @return array{
	 *     month: string,
	 *     today: string,
	 *     week_starts_on: int,
	 *     days: array<int, array{date: string, count: int, thumbnail: string}>,
	 *     total: int,
	 *     all_time: int,
	 *     first_month: string
	 * }
	 */
	public static function month( string $month = '' ): array {
		if ( ! self::is_month( $month ) ) {
			$month = wp_date( 'Y-m' );
		}

		$query = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'posts_per_page' => self::MAX_MONTH_ITEMS,
					'orderby'        => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'date_query'     => array(
						array(
							'year'  => (int) substr( $month, 0, 4 ),
							'month' => (int) substr( $month, 5, 2 ),
						),
					),
				)
			)
		);

		$days = array();

		foreach ( $query->posts as $post ) {
			$date = substr( (string) $post->post_date, 0, 10 );

			if ( ! isset( $days[ $date ] ) ) {
				$days[ $date ] = array(
					'date'      => $date,
					'count'     => 0,
					'thumbnail' => '',
				);
			}

			++$days[ $date ]['count'];

			if ( '' === $days[ $date ]['thumbnail'] ) {
				$days[ $date ]['thumbnail'] = self::thumbnail_url( $post );
			}
		}

		// The very first post, for "since" on the Me screen and for knowing
		// when there is no earlier month to step back to. Its found_posts
		// is the count of everything.
		$first = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'posts_per_page' => 1,
					'orderby'        => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
				)
			)
		);

		return array(
			'month'          => $month,
			'today'          => wp_date( 'Y-m-d' ),
			'week_starts_on' => max( 0, min( 6, (int) get_option( 'start_of_week', 0 ) ) ),
			'days'           => array_values( $days ),
			'total'          => (int) $query->found_posts,
			'all_time'       => (int) $first->found_posts,
			'first_month'    => empty( $first->posts ) ? '' : substr( (string) $first->posts[0]->post_date, 0, 7 ),
		);
	}

	/**
	 * The posts published on one day, newest first.
	 *
	 * @param string $day The day as YYYY-MM-DD, on the site's clock.
	 * @return int[] Post IDs. Empty for a value that isn't a real day.
	 */
	public static function day_post_ids( string $day ): array {
		if ( ! self::is_day( $day ) ) {
			return array();
		}

		$query = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'posts_per_page' => self::MAX_DAY_ITEMS,
					'no_found_rows'  => true,
					'fields'         => 'ids',
					'orderby'        => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'date_query'     => array(
						array(
							'year'  => (int) substr( $day, 0, 4 ),
							'month' => (int) substr( $day, 5, 2 ),
							'day'   => (int) substr( $day, 8, 2 ),
						),
					),
				)
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The query every lookup here shares: the site's own published posts,
	 * as GET /timeline's `mine` filter lists them. Explore's "Look back"
	 * strip (Daymark_Look_Back) reads the same posts.
	 *
	 * @return array<string, mixed> WP_Query arguments.
	 */
	public static function base_args(): array {
		return array(
			'post_type'              => array_values( array_unique( array_merge( array( 'post' ), Daymark_External_Notes::post_types() ) ) ),
			'post_status'            => 'publish',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			// A Like Mark carries only an outbound link, so it isn't a day's
			// content. Like Marks have their own post type now; this drops
			// any older one still stored as a `post`.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale Mark lookup.
			'meta_query'             => array(
				array(
					'key'     => '_daymark_like_of',
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * A small picture for a day's square: the post's featured image, else
	 * the first image it was published with. Nothing for a
	 * password-protected post. Daymark_Look_Back uses it to prefer a post
	 * with a picture.
	 *
	 * @param WP_Post $post The post.
	 * @return string Image URL, or '' for none.
	 */
	public static function thumbnail_url( WP_Post $post ): string {
		if ( '' !== (string) $post->post_password ) {
			return '';
		}

		$attachment_id = (int) get_post_thumbnail_id( $post->ID );

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

		$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		return $url ? esc_url_raw( $url ) : '';
	}
}
