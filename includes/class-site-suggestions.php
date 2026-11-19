<?php
/**
 * Explore's "Sites you might follow" (issue #621): a short list of sites
 * this one doesn't follow yet, taken only from the people already around
 * it.
 *
 * A site is here for one or more plain reasons, each from data the site
 * already holds:
 *
 * - It follows this site (Daymark_Followers).
 * - Its owner replied to, liked, reblogged or mentioned a post here (an
 *   approved comment that carries their site address).
 *
 * Nothing is ranked by popularity, nothing is fetched, and nothing leaves
 * the site. The list is short, ordered by who was in touch most recently,
 * and "Not now" takes a site off it for good.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the sites for Explore's "Sites you might follow".
 */
class Daymark_Site_Suggestions {

	/**
	 * The most sites the list shows. It is a nudge, not a directory.
	 */
	public const MAX = 5;

	/**
	 * User meta: the keys of the sites this person said "Not now" to.
	 */
	public const META_KEY = 'daymark_site_suggestions_dismissed';

	/**
	 * How many dismissed sites one person's list remembers.
	 */
	private const MAX_DISMISSED = 300;

	/**
	 * How many of the latest responses are read. Older ones are from
	 * people who have long since gone quiet.
	 */
	private const MAX_RESPONSES = 500;

	/**
	 * The address WordPress gives its own sample comment ("A WordPress
	 * Commenter") on a new site.
	 */
	private const SAMPLE_COMMENT_EMAIL = 'wapuu@wordpress.example';

	/**
	 * Which reason each kind of response gives, in the order the reasons
	 * are shown after "Follows you".
	 */
	private const RESPONSE_REASONS = array(
		'comment' => 'replied',
		'like'    => 'liked',
		'repost'  => 'reblogged',
		'mention' => 'mentioned',
		'quote'   => 'mentioned',
	);

	/**
	 * The sites to suggest to a person, the most recently in touch first.
	 *
	 * Each has `key` (what "Not now" names it by), `name`, `url`, `host`
	 * (the address as shown, without its scheme) and `reasons`: a list of
	 * `kind` (follows_you, replied, liked, reblogged, mentioned) and
	 * `count` (how many posts, 0 for follows_you).
	 *
	 * @param int $user_id Whose list: their dismissed sites are left out.
	 * @return array<int, array{key: string, name: string, url: string, host: string, reasons: array<int, array{kind: string, count: int}>}>
	 */
	public static function for_user( int $user_id ): array {
		$sites = array();

		foreach ( Daymark_Followers::all() as $follower ) {
			$address = self::address( (string) ( $follower['url'] ?? '' ) );

			if ( null === $address ) {
				continue;
			}

			$site = $sites[ $address['id'] ] ?? self::blank( $address );

			// A follower's own page says who they are better than a
			// comment form does, so their name and address win.
			$site['name']    = (string) ( $follower['name'] ?? '' );
			$site['url']     = $address['url'];
			$site['follows'] = true;
			$site['latest']  = max( $site['latest'], (int) ( $follower['followed_at'] ?? 0 ) );

			$sites[ $address['id'] ] = $site;
		}

		foreach ( self::responses() as $comment ) {
			$address = self::address( (string) $comment->comment_author_url );
			$reason  = self::RESPONSE_REASONS[ (string) $comment->comment_type ] ?? '';

			if ( null === $address || '' === $reason ) {
				continue;
			}

			// The sample comment a new WordPress site starts with.
			if ( self::SAMPLE_COMMENT_EMAIL === (string) $comment->comment_author_email ) {
				continue;
			}

			$site = $sites[ $address['id'] ] ?? self::blank( $address );

			if ( '' === $site['name'] ) {
				$site['name'] = trim( sanitize_text_field( (string) $comment->comment_author ) );
			}

			$site['posts'][ $reason ][ (int) $comment->comment_post_ID ] = true;
			$site['latest'] = max( $site['latest'], (int) strtotime( $comment->comment_date_gmt . ' UTC' ) );

			$sites[ $address['id'] ] = $site;
		}

		$followed  = self::followed();
		$own       = self::address( home_url( '/' ) );
		$dismissed = array_flip( self::dismissed( $user_id ) );

		$sites = array_filter(
			$sites,
			static function ( array $site ) use ( $followed, $own, $dismissed ): bool {
				if ( isset( $dismissed[ $site['key'] ] ) ) {
					return false;
				}

				if ( null !== $own && $own['host'] === $site['host'] ) {
					return false;
				}

				return ! self::is_followed( $site, $followed );
			}
		);

		// Newest contact first. The address settles a tie, so the order
		// never shuffles between two visits.
		uasort(
			$sites,
			static function ( array $a, array $b ): int {
				return array( $b['latest'], $a['id'] ) <=> array( $a['latest'], $b['id'] );
			}
		);

		$list = array();

		foreach ( array_slice( $sites, 0, self::MAX ) as $site ) {
			$reasons = array();

			if ( $site['follows'] ) {
				$reasons[] = array(
					'kind'  => 'follows_you',
					'count' => 0,
				);
			}

			foreach ( array_unique( array_values( self::RESPONSE_REASONS ) ) as $reason ) {
				if ( ! empty( $site['posts'][ $reason ] ) ) {
					$reasons[] = array(
						'kind'  => $reason,
						'count' => count( $site['posts'][ $reason ] ),
					);
				}
			}

			$list[] = array(
				'key'     => $site['key'],
				'name'    => '' !== $site['name'] ? $site['name'] : $site['shown'],
				'url'     => $site['url'],
				'host'    => $site['shown'],
				'reasons' => $reasons,
			);
		}

		return $list;
	}

	/**
	 * Take a site off a person's list for good ("Not now").
	 *
	 * @param int    $user_id The person.
	 * @param string $key     The site's `key` from for_user().
	 * @return void
	 */
	public static function dismiss( int $user_id, string $key ): void {
		if ( $user_id <= 0 || 1 !== preg_match( '/^[a-f0-9]{16}$/', $key ) ) {
			return;
		}

		$keys = array_values( array_unique( array_merge( array( $key ), self::dismissed( $user_id ) ) ) );

		update_user_meta( $user_id, self::META_KEY, array_slice( $keys, 0, self::MAX_DISMISSED ) );
	}

	/**
	 * The keys of the sites a person said "Not now" to, newest first.
	 *
	 * @param int $user_id The person.
	 * @return string[]
	 */
	private static function dismissed( int $user_id ): array {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::META_KEY, true ) : array();

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * The latest approved responses to this site's published posts from
	 * people without an account here, newest first.
	 *
	 * @return WP_Comment[]
	 */
	private static function responses(): array {
		$comments = get_comments(
			array(
				'status'                    => 'approve',
				'type__in'                  => array_keys( self::RESPONSE_REASONS ),
				'user_id'                   => 0,
				'post_status'               => 'publish',
				'number'                    => self::MAX_RESPONSES,
				'orderby'                   => 'comment_date_gmt',
				'order'                     => 'DESC',
				'update_comment_meta_cache' => false,
				'update_comment_post_cache' => false,
			)
		);

		return is_array( $comments ) ? $comments : array();
	}

	/**
	 * A suggestion with nothing known about it yet.
	 *
	 * @param array{id: string, host: string, path: string, url: string, shown: string} $address address() result.
	 * @return array<string, mixed>
	 */
	private static function blank( array $address ): array {
		return $address + array(
			'key'     => substr( md5( $address['id'] ), 0, 16 ),
			'name'    => '',
			'follows' => false,
			'posts'   => array(),
			'latest'  => 0,
		);
	}

	/**
	 * A web address taken apart so two spellings of one site compare
	 * equal: `host` is lower case without "www.", `path` has no trailing
	 * slash, and `id` is the two together. The path is kept because many
	 * people share one host (a profile on a social server is not the
	 * server).
	 *
	 * @param string $url An address someone gave.
	 * @return array{id: string, host: string, path: string, url: string, shown: string}|null Null when it isn't a web address.
	 */
	private static function address( string $url ): ?array {
		$url   = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$parts = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );
		$host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );

		if ( '' === $host ) {
			return null;
		}

		return array(
			'id'    => $host . $path,
			'host'  => $host,
			'path'  => $path,
			'url'   => '' === $path && empty( $parts['query'] ) && '/' !== substr( $url, -1 ) ? $url . '/' : $url,
			'shown' => $host . $path,
		);
	}

	/**
	 * The addresses of every site this one follows, active or not.
	 *
	 * @return array<int, array{host: string, path: string}>
	 */
	private static function followed(): array {
		$followed = array();

		foreach ( Daymark_Plugin::instance()->subscriptions->get_all() as $subscription ) {
			$address = self::address( (string) ( $subscription['site_url'] ?? '' ) );

			if ( null !== $address ) {
				$followed[] = array(
					'host' => $address['host'],
					'path' => $address['path'],
				);
			}
		}

		return $followed;
	}

	/**
	 * Whether a site is already followed. A whole host counts as followed
	 * when its front page is, and a front page counts when anything on its
	 * host is; otherwise the followed address has to be the same one or
	 * sit above it, so following one person on a shared host doesn't hide
	 * another.
	 *
	 * @param array<string, mixed>                          $site     The suggestion.
	 * @param array<int, array{host: string, path: string}> $followed followed() result.
	 * @return bool
	 */
	private static function is_followed( array $site, array $followed ): bool {
		foreach ( $followed as $address ) {
			if ( $address['host'] !== $site['host'] ) {
				continue;
			}

			if ( '' === $address['path'] || '' === $site['path'] || $address['path'] === $site['path'] || 0 === strpos( $site['path'], $address['path'] . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
