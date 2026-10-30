<?php
/**
 * Where publishing plugins shared a post (issue #558).
 *
 * Jetpack Social, Share on Mastodon, XPoster, Autopost for X, ATmosphere,
 * Autoblue, and ActivityPub each share a post on their own when it
 * publishes, and each keeps its own record of where the copy went.
 * Daymark never drives those shares (see Daymark_Publish_Helpers), so its
 * own `_daymark_external_posts` meta never knew about them, and a Mark's
 * routing view and `u-syndication` links stayed empty for every real share.
 *
 * This class only reads each plugin's own saved post meta (and, for
 * XPoster's Mastodon link, its saved account settings). It never calls a
 * plugin, never writes its data, and never makes a request. The meta is
 * read whether or not the plugin is still active, because the copy still
 * exists on the other network either way. Every format below was checked
 * against the plugin's own source; each reader's docblock names the
 * version.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the copies publishing plugins made of a post.
 */
class Daymark_Plugin_Copies {

	/**
	 * Jetpack Social service slugs Daymark knows a name for. An unknown
	 * slug is shown title-cased.
	 */
	private const JETPACK_SERVICES = array(
		'bluesky'            => 'Bluesky',
		'facebook'           => 'Facebook',
		'instagram-business' => 'Instagram',
		'linkedin'           => 'LinkedIn',
		'mastodon'           => 'Mastodon',
		'nextdoor'           => 'Nextdoor',
		'threads'            => 'Threads',
		'tumblr'             => 'Tumblr',
		'twitter'            => 'X',
	);

	/**
	 * Copies of a post made by publishing plugins, oldest reader first.
	 *
	 * Each copy is an array with:
	 * - `service`: the network, e.g. `mastodon`, `bluesky`, `x`, `fediverse`.
	 * - `label`: the network's name, for display.
	 * - `url`: the copy's address, or '' when the plugin keeps none (an
	 *   ActivityPub post is the site's own post, and XPoster keeps only a
	 *   Bluesky content hash, which no address can be built from).
	 * - `plugin`: which plugin made it (Daymark_Publish_Helpers' keys,
	 *   plus `activitypub`).
	 * - `plugin_label`: that plugin's name.
	 *
	 * Two copies with the same address (say Jetpack and Share on Mastodon
	 * both recorded one toot) are listed once.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{service: string, label: string, url: string, plugin: string, plugin_label: string}>
	 */
	public static function for_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$copies = array_merge(
			self::jetpack( $post_id ),
			self::share_on_mastodon( $post_id ),
			self::xposter( $post_id ),
			self::autoshare( $post_id ),
			self::atmosphere( $post_id ),
			self::autoblue( $post_id ),
			self::activitypub( $post_id )
		);

		/**
		 * Filters the copies publishing plugins made of a post.
		 *
		 * Lets a site add a plugin Daymark doesn't read yet, or drop one.
		 * Each entry needs `service`, `label`, `url` ('' for none),
		 * `plugin`, and `plugin_label`; an entry missing a label or with a
		 * non-http(s) address is dropped.
		 *
		 * @param array $copies  The copies Daymark found.
		 * @param int   $post_id Post ID.
		 */
		$copies = apply_filters( 'daymark_plugin_copies', $copies, $post_id );

		return self::clean( is_array( $copies ) ? $copies : array() );
	}

	/**
	 * A key two addresses for the same copy share: X/Twitter status links
	 * match by status ID (`twitter.com/{user}/status/1` and
	 * `x.com/i/web/status/1` are one post), Bluesky post links by record
	 * key (a handle and a DID name the same profile), anything else by
	 * host and path with `www.` and a trailing slash ignored.
	 *
	 * @param string $url Address.
	 * @return string Key, or '' when the address can't be read.
	 */
	public static function url_key( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$host = (string) preg_replace( '/^(?:www|mobile)\./', '', $host );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( in_array( $host, array( 'twitter.com', 'x.com' ), true )
			&& preg_match( '#/status(?:es)?/(\d+)#', $path, $match ) ) {
			return 'x:' . $match[1];
		}

		if ( 'bsky.app' === $host && preg_match( '#^/profile/[^/]+/post/([^/]+)#', $path, $match ) ) {
			return 'bsky:' . $match[1];
		}

		$key = $host . untrailingslashit( $path );

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$key .= '?' . $parts['query'];
		}

		return $key;
	}

	/**
	 * Jetpack Social (`publicize` package 0.88, `Share_Status`).
	 *
	 * `_publicize_shares` is one meta row holding a list of shares; the
	 * older WordPress.com format keeps one share per row instead, which
	 * Jetpack itself detects by the first row not being a list. A share is
	 * `{status, message, service, …}`; on `success`, `message` is the
	 * copy's address.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function jetpack( int $post_id ): array {
		$shares = get_post_meta( $post_id, '_publicize_shares', true );

		if ( is_array( $shares ) && array() !== $shares && ! array_is_list( $shares ) ) {
			$shares = get_post_meta( $post_id, '_publicize_shares', false );
		}

		if ( ! is_array( $shares ) ) {
			return array();
		}

		$copies = array();

		foreach ( $shares as $share ) {
			if ( ! is_array( $share ) || 'success' !== ( $share['status'] ?? '' ) ) {
				continue;
			}

			$service = sanitize_key( (string) ( $share['service'] ?? '' ) );

			$copies[] = self::copy(
				'' === $service ? 'social' : $service,
				self::JETPACK_SERVICES[ $service ] ?? ucwords( str_replace( '-', ' ', $service ) ),
				(string) ( $share['message'] ?? '' ),
				'jetpack',
				'Jetpack Social'
			);
		}

		return $copies;
	}

	/**
	 * Share on Mastodon (0.20): `_share_on_mastodon_url` holds the toot's
	 * address once shared, and is deleted when the toot is.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function share_on_mastodon( int $post_id ): array {
		$url = (string) get_post_meta( $post_id, '_share_on_mastodon_url', true );

		if ( '' === $url ) {
			return array();
		}

		return array( self::copy( 'mastodon', __( 'Mastodon', 'daymark' ), $url, 'share-on-mastodon', 'Share on Mastodon' ) );
	}

	/**
	 * XPoster (5.1.1): the first status ID per service, in `_wpt_tweet_id`
	 * (X), `_wpt_status_id` (Mastodon), and `_wpt_bluesky_id` (Bluesky).
	 *
	 * An X status opens at `x.com/i/web/status/{id}` with no account name.
	 * A Mastodon status needs the account's server and name: they come
	 * from the site-wide settings, and only when the post's author has no
	 * Mastodon account of their own in XPoster, since then the saved ID
	 * could be from either account. The Bluesky value is the record's
	 * content hash, which no address can be built from, so that copy is
	 * listed without a link.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function xposter( int $post_id ): array {
		$copies = array();

		$tweet_id = (string) get_post_meta( $post_id, '_wpt_tweet_id', true );
		if ( preg_match( '/^\d+$/', $tweet_id ) ) {
			$copies[] = self::copy( 'x', 'X', 'https://x.com/i/web/status/' . $tweet_id, 'xposter', 'XPoster' );
		}

		$status_id = (string) get_post_meta( $post_id, '_wpt_status_id', true );
		if ( preg_match( '/^[A-Za-z0-9]+$/', $status_id ) ) {
			$copies[] = self::copy( 'mastodon', __( 'Mastodon', 'daymark' ), self::xposter_mastodon_url( $post_id, $status_id ), 'xposter', 'XPoster' );
		}

		if ( '' !== (string) get_post_meta( $post_id, '_wpt_bluesky_id', true ) ) {
			$copies[] = self::copy( 'bluesky', __( 'Bluesky', 'daymark' ), '', 'xposter', 'XPoster' );
		}

		return $copies;
	}

	/**
	 * Address of an XPoster Mastodon status, or '' when it can't be known.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $status_id Mastodon status ID.
	 * @return string
	 */
	private static function xposter_mastodon_url( int $post_id, string $status_id ): string {
		$author = (int) get_post_field( 'post_author', $post_id );

		if ( $author > 0 && '' !== (string) get_user_meta( $author, 'wpt_mastodon_instance', true ) ) {
			return '';
		}

		$instance = untrailingslashit( (string) get_option( 'wpt_mastodon_instance', '' ) );
		$username = ltrim( (string) get_option( 'wpt_mastodon_username', '' ), '@' );

		if ( '' === $instance || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $username ) ) {
			return '';
		}

		return $instance . '/@' . $username . '/' . $status_id;
	}

	/**
	 * Autopost for X (Autoshare for Twitter 2.3): `autoshare_status` is one
	 * status, or (once a post was shared more than once) a list of them. A
	 * published status carries `twitter_id` and the account `handle`.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function autoshare( int $post_id ): array {
		$statuses = get_post_meta( $post_id, 'autoshare_status', true );

		if ( ! is_array( $statuses ) ) {
			return array();
		}

		if ( isset( $statuses['status'] ) || isset( $statuses['twitter_id'] ) ) {
			$statuses = array( $statuses );
		}

		$copies = array();

		foreach ( $statuses as $status ) {
			if ( ! is_array( $status ) || 'published' !== ( $status['status'] ?? '' ) ) {
				continue;
			}

			$tweet_id = (string) ( $status['twitter_id'] ?? '' );
			if ( ! preg_match( '/^\d+$/', $tweet_id ) ) {
				continue;
			}

			$handle = (string) ( $status['handle'] ?? '' );
			$handle = preg_match( '/^[A-Za-z0-9_]{1,15}$/', $handle ) ? $handle : 'i/web';

			$copies[] = self::copy( 'x', 'X', 'https://x.com/' . $handle . '/status/' . $tweet_id, 'autoshare-for-twitter', 'Autopost for X' );
		}

		return $copies;
	}

	/**
	 * ATmosphere (2.4.2): `_atmosphere_bsky_uri` is the Bluesky record's
	 * `at://` address. ATmosphere's own `post_share_url()` turns it into a
	 * web link that honors its appview setting; when ATmosphere isn't
	 * loaded, Daymark builds the bsky.app link itself.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function atmosphere( int $post_id ): array {
		$uri = (string) get_post_meta( $post_id, '_atmosphere_bsky_uri', true );

		if ( '' === $uri ) {
			return array();
		}

		$url = '';

		if ( function_exists( 'Atmosphere\\post_share_url' ) ) {
			try {
				$url = (string) \Atmosphere\post_share_url( $post_id );
			} catch ( Throwable $e ) {
				$url = '';
			}
		}

		if ( '' === $url && preg_match( '#^at://(did:[a-z]+:[A-Za-z0-9._:%-]+)/app\.bsky\.feed\.post/([A-Za-z0-9._:~-]+)$#', $uri, $match ) ) {
			$url = 'https://bsky.app/profile/' . $match[1] . '/post/' . $match[2];
		}

		return array( self::copy( 'bluesky', __( 'Bluesky', 'daymark' ), $url, 'atmosphere', 'ATmosphere' ) );
	}

	/**
	 * Autoblue (2.0): `autoblue_shares` is a list of `{did, uri, …}`, one
	 * per share. (Its separate `autoblue_post_url` is a Bluesky post the
	 * author picked to show replies from, not necessarily a copy, so it is
	 * not read.)
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function autoblue( int $post_id ): array {
		$shares = get_post_meta( $post_id, 'autoblue_shares', true );

		if ( ! is_array( $shares ) ) {
			return array();
		}

		$copies = array();

		foreach ( $shares as $share ) {
			if ( ! is_array( $share ) ) {
				continue;
			}

			$did  = (string) ( $share['did'] ?? '' );
			$uri  = (string) ( $share['uri'] ?? '' );
			$rkey = (string) substr( (string) strrchr( $uri, '/' ), 1 );

			if ( ! preg_match( '/^did:[a-z]+:[A-Za-z0-9._:%-]+$/', $did ) || ! preg_match( '/^[A-Za-z0-9._:~-]+$/', $rkey ) ) {
				continue;
			}

			$copies[] = self::copy( 'bluesky', __( 'Bluesky', 'daymark' ), 'https://bsky.app/profile/' . $did . '/post/' . $rkey, 'autoblue', 'Autoblue' );
		}

		return $copies;
	}

	/**
	 * ActivityPub (9.3): `activitypub_status` is `federated` once the post
	 * went out to followers. The fediverse copy is the site's own post, so
	 * there is no separate address to link.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function activitypub( int $post_id ): array {
		if ( 'federated' !== (string) get_post_meta( $post_id, 'activitypub_status', true ) ) {
			return array();
		}

		return array( self::copy( 'fediverse', __( 'Fediverse', 'daymark' ), '', 'activitypub', 'ActivityPub' ) );
	}

	/**
	 * One copy entry.
	 *
	 * @param string $service      Network.
	 * @param string $label        Network name.
	 * @param string $url          Address, or ''.
	 * @param string $plugin       Plugin key.
	 * @param string $plugin_label Plugin name.
	 * @return array<string, string>
	 */
	private static function copy( string $service, string $label, string $url, string $plugin, string $plugin_label ): array {
		return array(
			'service'      => $service,
			'label'        => $label,
			'url'          => $url,
			'plugin'       => $plugin,
			'plugin_label' => $plugin_label,
		);
	}

	/**
	 * Drops malformed entries and repeats.
	 *
	 * An address must be http(s) with a host (checked without a DNS
	 * lookup, since this runs while a page renders). Copies sharing an
	 * address are listed once; address-less copies are listed once per
	 * plugin and network.
	 *
	 * @param array $copies Raw copies.
	 * @return array<int, array{service: string, label: string, url: string, plugin: string, plugin_label: string}>
	 */
	private static function clean( array $copies ): array {
		$clean = array();
		$seen  = array();

		foreach ( $copies as $copy ) {
			if ( ! is_array( $copy ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $copy['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$url = trim( (string) ( $copy['url'] ?? '' ) );
			if ( '' !== $url ) {
				$url  = esc_url_raw( $url, array( 'http', 'https' ) );
				$host = '' === $url ? '' : (string) wp_parse_url( $url, PHP_URL_HOST );
				if ( '' === $host ) {
					continue;
				}
			}

			$service = sanitize_key( (string) ( $copy['service'] ?? '' ) );
			$plugin  = sanitize_key( (string) ( $copy['plugin'] ?? '' ) );
			$key     = '' === $url ? 'none:' . $plugin . ':' . $service : self::url_key( $url );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$clean[] = array(
				'service'      => $service,
				'label'        => $label,
				'url'          => $url,
				'plugin'       => $plugin,
				'plugin_label' => sanitize_text_field( (string) ( $copy['plugin_label'] ?? '' ) ),
			);
		}

		return $clean;
	}
}
