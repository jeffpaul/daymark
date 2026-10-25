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
 * Endpoint: `GET /me/following` on WordPress.com's wpcom/v2 API, called
 * with `Client::wpcom_json_api_request_as_user( '/me/following', '2' )`.
 * This is what a Jetpack maintainer pointed to on Automattic/jetpack#8347:
 * the v1.x Reader endpoints (`read/following/mine`) are not opened to
 * Jetpack connections, and called that way answer with an empty list. The
 * request and the response were confirmed against Jetpack's own Blogroll
 * block, which calls the same endpoint
 * (`_inc/lib/core-api/wpcom-endpoints/class-wpcom-rest-api-v2-endpoint-following.php`
 * and `extensions/blocks/blogroll/utils.js`): the response is a plain JSON
 * array, one object per followed site, carrying `blog_id`, `URL` (the
 * site's own address, not its feed), `name`, `site_icon`, `description`,
 * and `is_non_wpcom_site`. Jetpack sends no paging arguments, so this reads
 * the list in one request.
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
	 * Fetch the current user's Reader follows, up to max_entries(). Unlike
	 * an OPML file, a follow list can't be split by the user, so a list over
	 * the cap is truncated (and says so) rather than rejected.
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

		$items = self::fetch_list();

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$max     = self::max_entries();
		$entries = array();
		$seen    = array();

		foreach ( self::parse_follows( $items ) as $entry ) {
			$key = strtolower( untrailingslashit( $entry['html_url'] ) );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$entries[]    = $entry;
		}

		$total = count( $entries );

		return array(
			'entries'   => array_slice( $entries, 0, $max ),
			'total'     => $total,
			'truncated' => $total > $max,
		);
	}

	/**
	 * Fetch and decode the `/me/following` list.
	 *
	 * @return array<int, mixed>|WP_Error
	 */
	private static function fetch_list() {
		/**
		 * Short-circuits the Reader follows request. Return the decoded
		 * response (a list of followed sites) or a WP_Error to skip the real
		 * WordPress.com call. Used by the test suite, since the real
		 * Jetpack classes can't be loaded there.
		 *
		 * @since 0.22.0
		 *
		 * @param array|WP_Error|null $pre Null to make the real request.
		 */
		$pre = apply_filters( 'daymark_reader_import_pre_fetch', null );

		if ( null !== $pre ) {
			return self::as_follow_list( $pre );
		}

		try {
			$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_user(
				'/me/following',
				'2',
				array(
					'method'  => 'GET',
					'timeout' => 20,
				)
			);
		} catch ( \Throwable $e ) {
			return self::request_failed_error();
		}

		if ( is_wp_error( $response ) ) {
			return self::request_failed_error();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return self::request_failed_error();
		}

		return self::as_follow_list( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Accept a decoded response only when it is the plain list the endpoint
	 * returns. An object (for example a `{code, message}` error body) or
	 * anything else counts as a failure.
	 *
	 * @param mixed $body Decoded response, or a WP_Error.
	 * @return array<int, mixed>|WP_Error
	 */
	private static function as_follow_list( $body ) {
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ! is_array( $body ) || ! array_is_list( $body ) ) {
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
	 * Turn raw `/me/following` items into Daymark_Subscription_OPML
	 * import_entries() entries. Each item's `URL` is the followed site's own
	 * address, so it becomes the entry's site URL with no feed URL: the
	 * import then finds the site's feed the same way adding a site by URL
	 * does, rather than trusting a guessed feed address. An item without a
	 * usable http(s) URL is skipped. The label is the site's own name, else
	 * its host. Nothing here is trusted yet: import_entries() runs every URL
	 * through the same validation and SSRF guard as an OPML entry.
	 *
	 * @param array<int, mixed> $items Raw `/me/following` items.
	 * @return array<int, array{label: string, xml_url: string, html_url: string, icon_url: string}>
	 */
	public static function parse_follows( array $items ): array {
		$entries = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$site_url = esc_url_raw( trim( (string) ( $item['URL'] ?? '' ) ), array( 'http', 'https' ) );
			$host     = (string) wp_parse_url( $site_url, PHP_URL_HOST );

			if ( '' === $site_url || '' === $host ) {
				continue;
			}

			$name = sanitize_text_field( html_entity_decode( (string) ( $item['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
			$icon = is_string( $item['site_icon'] ?? null ) ? $item['site_icon'] : '';

			$entries[] = array(
				'label'    => '' !== $name ? $name : $host,
				'xml_url'  => '',
				'html_url' => $site_url,
				'icon_url' => esc_url_raw( trim( $icon ), array( 'http', 'https' ) ),
			);
		}

		return $entries;
	}
}
