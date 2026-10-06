<?php
/**
 * Import the sites a user follows in the WordPress.com Reader as Daymark
 * subscriptions (issue #435).
 *
 * Someone who has followed sites in the Reader for years should not have
 * to re-add each one by URL. This reads their follow list through Jetpack's
 * own connection, as that user (the follow list belongs to the person, not
 * the site), and hands each followed site to the same per-entry import the
 * OPML import uses (Daymark_Subscription_OPML::import_entries()). An
 * imported row is therefore indistinguishable from one added by hand.
 *
 * Endpoint: `GET /read/following/mine` on the WordPress.com REST API,
 * version 1.2, paged with `page` and `number` (server cap 100 per page).
 * The response is `{subscriptions: [...], total_subscriptions, page, number}`,
 * and each subscription carries `URL` (the followed feed's URL), `blog_ID`,
 * `feed_ID`, `name`, and `site_icon`. Confirmed against WordPress.com's own
 * first-party client, Automattic/wp-calypso
 * (`packages/api-core/src/read-follows/fetchers.ts`, `adapters.ts`, `types.ts`,
 * and `packages/data-stores/src/reader/queries/use-site-subscriptions-query.ts`,
 * which documents the 100-per-page cap). The request URL shape
 * (`{base}/rest/v1.2/{path}`) was confirmed against Jetpack's own
 * `Client::validate_args_for_wpcom_json_api_request()`.
 *
 * NOT independently confirmed against a live Jetpack install. This
 * environment cannot install a third-party plugin, the same posture as
 * Daymark_Jetpack_Engagement. Every Jetpack call is wrapped so a wrong
 * assumption degrades to a readable error, never a fatal one.
 *
 * This is a one-time, user-started import. Following or unfollowing later
 * in either place does not mirror to the other.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a user's WordPress.com Reader follows through Jetpack.
 */
class Daymark_Reader_Import {

	/**
	 * Jetpack is not active, so the option is hidden entirely.
	 *
	 * @var string
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * Jetpack is active, but the current user has not linked their own
	 * WordPress.com account, so the option shows a connect link.
	 *
	 * @var string
	 */
	public const STATUS_NOT_CONNECTED = 'not_connected';

	/**
	 * Jetpack is active and the current user's account is linked.
	 *
	 * @var string
	 */
	public const STATUS_READY = 'ready';

	/**
	 * Follows requested per page — the server's own cap (see the class
	 * docblock).
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Whether the Reader import can be offered to the current user.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function status(): string {
		if ( ! Daymark_Jetpack_Engagement::is_available() ) {
			$status = self::STATUS_UNAVAILABLE;
		} elseif ( ! Daymark_Jetpack_Engagement::current_user_connected() ) {
			$status = self::STATUS_NOT_CONNECTED;
		} else {
			$status = self::STATUS_READY;
		}

		/**
		 * Filters whether the WordPress.com Reader import is offered.
		 *
		 * @since 0.20.0
		 *
		 * @param string $status 'unavailable', 'not_connected', or 'ready'.
		 */
		$status = (string) apply_filters( 'daymark_reader_import_status', $status );

		return in_array( $status, array( self::STATUS_UNAVAILABLE, self::STATUS_NOT_CONNECTED, self::STATUS_READY ), true )
			? $status
			: self::STATUS_UNAVAILABLE;
	}

	/**
	 * The most follows one import will list — the same cap the OPML import
	 * applies to one file (`daymark_subscription_opml_max_entries`).
	 *
	 * @return int
	 */
	public static function max_entries(): int {
		/** This filter is documented in includes/class-subscription-opml.php. */
		return max( 0, (int) apply_filters( 'daymark_subscription_opml_max_entries', 1000 ) );
	}

	/**
	 * Fetch the current user's Reader follows, page by page, up to
	 * max_entries(). Unlike an OPML file, a follow list can't be split by
	 * the user, so a list over the cap is truncated (and says so) rather
	 * than rejected.
	 *
	 * @return array{entries: array<int, array{label: string, xml_url: string, html_url: string, icon_url: string}>, total: int, truncated: bool}|WP_Error
	 */
	public static function fetch_follows() {
		if ( self::STATUS_READY !== self::status() ) {
			return new WP_Error(
				'daymark_reader_import_unavailable',
				__( 'Link your WordPress.com account through Jetpack to import the sites you follow in the Reader.', 'daymark' )
			);
		}

		$max       = self::max_entries();
		$entries   = array();
		$seen      = array();
		$total     = 0;
		$truncated = false;
		$max_pages = (int) ceil( max( 1, $max ) / self::PAGE_SIZE ) + 1;

		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$body = self::fetch_page( $page );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$total = max( $total, absint( $body['total_subscriptions'] ?? 0 ) );
			$items = is_array( $body['subscriptions'] ?? null ) ? $body['subscriptions'] : array();

			foreach ( self::parse_follows( $items ) as $entry ) {
				$key = strtolower( untrailingslashit( $entry['xml_url'] ) );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				if ( count( $entries ) >= $max ) {
					$truncated = true;
					break 2;
				}

				$seen[ $key ] = true;
				$entries[]    = $entry;
			}

			if ( count( $items ) < self::PAGE_SIZE ) {
				break;
			}
		}

		if ( $total > count( $entries ) && count( $entries ) >= $max ) {
			$truncated = true;
		}

		return array(
			'entries'   => $entries,
			'total'     => max( $total, count( $entries ) ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Fetch and decode one page of `read/following/mine`.
	 *
	 * @param int $page 1-based page number.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function fetch_page( int $page ) {
		/**
		 * Short-circuits one page of the Reader follows request. Return a
		 * decoded response array (or a WP_Error) to skip the real
		 * WordPress.com call. Used by the test suite, since the real
		 * Jetpack classes can't be loaded there.
		 *
		 * @since 0.20.0
		 *
		 * @param array|WP_Error|null $pre  Null to make the real request.
		 * @param int                 $page 1-based page number.
		 * @param int                 $per  Follows per page.
		 */
		$pre = apply_filters( 'daymark_reader_import_pre_fetch_page', null, $page, self::PAGE_SIZE );

		if ( null !== $pre ) {
			return is_array( $pre ) || is_wp_error( $pre ) ? $pre : self::request_failed_error();
		}

		try {
			$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_user(
				add_query_arg(
					array(
						'page'   => $page,
						'number' => self::PAGE_SIZE,
						'meta'   => '',
					),
					'read/following/mine'
				),
				'1.2',
				array(
					'method'  => 'GET',
					'timeout' => 20,
				),
				null,
				// v1.x endpoints live under `rest`, not the wpcom/v2 base;
				// see Daymark_Jetpack_Engagement::write_action().
				'rest'
			);
		} catch ( \Throwable $e ) {
			return self::request_failed_error();
		}

		if ( is_wp_error( $response ) ) {
			return self::request_failed_error();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return self::request_failed_error();
		}

		return $body;
	}

	/**
	 * The error shown when WordPress.com could not be reached or answered
	 * with something unexpected.
	 *
	 * @return WP_Error
	 */
	private static function request_failed_error(): WP_Error {
		return new WP_Error(
			'daymark_reader_import_failed',
			__( 'Daymark could not load your WordPress.com Reader follows. Please try again in a few minutes.', 'daymark' )
		);
	}

	/**
	 * Turn raw `subscriptions` items into Daymark_Subscription_OPML
	 * import_entries() entries. An item without a usable http(s) feed URL
	 * is skipped. The site URL is the feed URL's scheme and host, the same
	 * fallback the OPML import uses when a file has no `htmlUrl`, since the
	 * follow list carries no separate site address (`meta.links.site` is an
	 * API link, not the site). The label is the site's own name, else its
	 * host. Nothing here is trusted yet: import_entries() runs every URL
	 * through the same validation and SSRF guard as an OPML entry.
	 *
	 * @param array<int, mixed> $items Raw `subscriptions` items.
	 * @return array<int, array{label: string, xml_url: string, html_url: string, icon_url: string}>
	 */
	public static function parse_follows( array $items ): array {
		$entries = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$feed_url = esc_url_raw( trim( (string) ( $item['URL'] ?? '' ) ), array( 'http', 'https' ) );
			$host     = (string) wp_parse_url( $feed_url, PHP_URL_HOST );

			if ( '' === $feed_url || '' === $host ) {
				continue;
			}

			$scheme = strtolower( (string) wp_parse_url( $feed_url, PHP_URL_SCHEME ) );
			$name   = sanitize_text_field( html_entity_decode( (string) ( $item['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
			$icon   = is_string( $item['site_icon'] ?? null ) ? $item['site_icon'] : '';

			$entries[] = array(
				'label'    => '' !== $name ? $name : $host,
				'xml_url'  => $feed_url,
				'html_url' => $scheme . '://' . $host,
				'icon_url' => esc_url_raw( trim( $icon ), array( 'http', 'https' ) ),
			);
		}

		return $entries;
	}
}
