<?php
/**
 * Explore's "Look back" strip: a few of the site's own posts from about
 * this time in earlier years, from six months ago, and from one month ago.
 *
 * It grew out of "On this day" (issue #294), which only ever showed a post
 * from today's exact date in an earlier year, so on most days it showed
 * nothing. Look back keeps that exact match first and then widens the net
 * a little, so there is usually something to find:
 *
 * - For each earlier year, the posts from today's date ("3 years ago
 *   today"), or, when that year has none, one post from within three days
 *   of it ("This week in 2023").
 * - One post from about six months ago, and one from about one month ago.
 *
 * Everything is read from the site's own posts by their date on the site's
 * own clock. Nothing is stored, ranked by engagement, or sent anywhere.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the posts for Explore's "Look back" strip.
 */
class Daymark_Look_Back {

	/**
	 * The most tiles the strip ever holds. It is a glance, not an archive.
	 */
	public const MAX_TILES = 12;

	/**
	 * How many days either side of a date still count as "about then".
	 */
	public const NEAR_DAYS = 3;

	/**
	 * The most posts one exact day adds, so a busy day can't fill the strip.
	 */
	public const PER_DAY = 3;

	/**
	 * How many years back the strip looks.
	 */
	public const MAX_YEARS = 25;

	/**
	 * The "months ago" tiles, in the order they are shown.
	 */
	private const MONTHS_AGO = array( 6, 1 );

	/**
	 * Upper bound on the one query's rows.
	 */
	private const MAX_QUERY_ITEMS = 400;

	/**
	 * The strip's tiles for a day, in the order they are shown: earlier
	 * years first (the latest year first), then six months ago, then one
	 * month ago.
	 *
	 * Each tile says which post and why: `kind` is `years` (the post is
	 * from today's date, `amount` years ago), `week` (from within
	 * NEAR_DAYS of today's date, `amount` years ago), or `months` (from
	 * about `amount` months ago). `year` is the year the tile is about.
	 *
	 * @param string $today 'Y-m-d' on the site's clock. Today when empty or invalid.
	 * @return array<int, array{post_id: int, kind: string, amount: int, year: int}>
	 */
	public static function tiles( string $today = '' ): array {
		if ( ! Daymark_Calendar::is_day( $today ) ) {
			$today = wp_date( 'Y-m-d' );
		}

		// Dates here are plain calendar dates on the site's clock, the same
		// as a post's `post_date`, so UTC is only a neutral zone to do the
		// day arithmetic in.
		$utc      = new DateTimeZone( 'UTC' );
		$now      = new DateTimeImmutable( $today . ' 00:00:00', $utc );
		$year_now = (int) $now->format( 'Y' );
		$month    = (int) $now->format( 'n' );
		$day      = (int) $now->format( 'j' );
		$windows  = array();

		for ( $years = 1; $years <= self::MAX_YEARS; $years++ ) {
			$year = $year_now - $years;

			if ( $year < 1 ) {
				break;
			}

			// Feb 29 has no match in an ordinary year: look around Feb 28,
			// and call whatever is found "this week", never "today".
			$has_day   = checkdate( $month, $day, $year );
			$anchor    = $now->setDate( $year, $month, $has_day ? $day : 28 );
			$windows[] = array(
				'kind'   => 'years',
				'amount' => $years,
				'year'   => $year,
				'anchor' => $anchor,
				'exact'  => $has_day,
			);
		}

		foreach ( self::MONTHS_AGO as $months ) {
			$anchor    = self::months_before( $now, $months );
			$windows[] = array(
				'kind'   => 'months',
				'amount' => $months,
				'year'   => (int) $anchor->format( 'Y' ),
				'anchor' => $anchor,
				'exact'  => true,
			);
		}

		$clauses = array( 'relation' => 'OR' );

		foreach ( $windows as $window ) {
			$clauses[] = array(
				'column'    => 'post_date',
				'inclusive' => true,
				'after'     => $window['anchor']->modify( '-' . self::NEAR_DAYS . ' days' )->format( 'Y-m-d 00:00:00' ),
				'before'    => $window['anchor']->modify( '+' . self::NEAR_DAYS . ' days' )->format( 'Y-m-d 23:59:59' ),
			);
		}

		$query = new WP_Query(
			array_merge(
				Daymark_Calendar::base_args(),
				array(
					'posts_per_page' => self::MAX_QUERY_ITEMS,
					'no_found_rows'  => true,
					'orderby'        => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'date_query'     => $clauses,
				)
			)
		);

		$tiles = array();
		$used  = array();

		foreach ( $windows as $window ) {
			foreach ( self::pick( $window, $query->posts, $used, $utc ) as $tile ) {
				if ( count( $tiles ) >= self::MAX_TILES ) {
					break 2;
				}

				$used[ $tile['post_id'] ] = true;
				$tiles[]                  = $tile;
			}
		}

		return $tiles;
	}

	/**
	 * The tiles one window adds: the posts from its exact day when it has
	 * any (every one for a year, up to PER_DAY; the best one for a month),
	 * otherwise the best post from the days around it.
	 *
	 * @param array<string, mixed> $window One entry of tiles()'s windows.
	 * @param WP_Post[]            $posts  Every post the query found, newest first.
	 * @param array<int, bool>     $used   Post IDs already on the strip.
	 * @param DateTimeZone         $utc    The zone the anchors are in.
	 * @return array<int, array{post_id: int, kind: string, amount: int, year: int}>
	 */
	private static function pick( array $window, array $posts, array $used, DateTimeZone $utc ): array {
		$anchor = $window['anchor']->format( 'Y-m-d' );
		$on_day = array();
		$near   = array();

		foreach ( $posts as $post ) {
			if ( isset( $used[ $post->ID ] ) ) {
				continue;
			}

			$date = substr( (string) $post->post_date, 0, 10 );
			$days = abs( (int) $window['anchor']->diff( new DateTimeImmutable( $date . ' 00:00:00', $utc ) )->format( '%r%a' ) );

			if ( $days > self::NEAR_DAYS ) {
				continue;
			}

			if ( 0 === $days && $window['exact'] ) {
				$on_day[] = $post;
			} else {
				$near[] = array(
					'post' => $post,
					'days' => $days,
				);
			}
		}

		$tile = static function ( WP_Post $post, string $kind ) use ( $window ): array {
			return array(
				'post_id' => (int) $post->ID,
				'kind'    => $kind,
				'amount'  => (int) $window['amount'],
				'year'    => 'months' === $kind ? (int) substr( (string) $post->post_date, 0, 4 ) : (int) $window['year'],
			);
		};

		if ( 'years' === $window['kind'] ) {
			if ( ! empty( $on_day ) ) {
				$picked = array();

				foreach ( array_slice( $on_day, 0, self::PER_DAY ) as $post ) {
					$picked[] = $tile( $post, 'years' );
				}

				return $picked;
			}

			$best = self::best( $near );

			return null === $best ? array() : array( $tile( $best, 'week' ) );
		}

		if ( ! empty( $on_day ) ) {
			$near = array();

			foreach ( $on_day as $post ) {
				$near[] = array(
					'post' => $post,
					'days' => 0,
				);
			}
		}

		$best = self::best( $near );

		return null === $best ? array() : array( $tile( $best, 'months' ) );
	}

	/**
	 * The one post to show out of several from around a date: one with a
	 * picture before one without, then the nearest day, then the newest
	 * (the order the candidates arrive in).
	 *
	 * @param array<int, array{post: WP_Post, days: int}> $candidates Posts and how many days off each is, newest first.
	 * @return WP_Post|null
	 */
	private static function best( array $candidates ): ?WP_Post {
		$best       = null;
		$best_score = null;

		foreach ( $candidates as $candidate ) {
			$pictured = '' !== Daymark_Calendar::thumbnail_url( $candidate['post'] );
			$score    = ( $pictured ? 0 : 100 ) + $candidate['days'];

			if ( null === $best_score || $score < $best_score ) {
				$best       = $candidate['post'];
				$best_score = $score;
			}
		}

		return $best;
	}

	/**
	 * The date a number of months before another, keeping the day of the
	 * month where that month has it and using the month's last day where
	 * it doesn't (a month before March 31 is the end of February).
	 *
	 * @param DateTimeImmutable $date   The date to count back from.
	 * @param int               $months How many months.
	 * @return DateTimeImmutable
	 */
	private static function months_before( DateTimeImmutable $date, int $months ): DateTimeImmutable {
		$first = $date->modify( 'first day of this month' )->modify( '-' . $months . ' months' );
		$day   = min( (int) $date->format( 'j' ), (int) $first->format( 't' ) );

		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day );
	}
}
