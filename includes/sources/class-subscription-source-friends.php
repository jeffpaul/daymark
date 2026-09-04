<?php
/**
 * The built-in Friends plugin subscription source (issue #88).
 *
 * An optional companion to the RSS/Atom, WordPress REST, and microformats2
 * sources, for a friend the site owner already follows through the
 * akirk/friends plugin (https://wordpress.org/plugins/friends/) — no hard
 * dependency: every method degrades to "nothing found" when Friends isn't
 * active, never a fatal.
 *
 * Friends already does the actual remote fetching, parsing, deduplication,
 * and post-type inference for a followed friend (including its own
 * content-based post-format-discovery fallback), caching the result as a
 * `friend_post_cache` custom post type on this same site. This source does
 * no network fetching of its own at all — `fetch()` is a local `WP_Query`
 * against Friends' own already-normalized cache, matching this codebase's
 * established pattern of reading an already-installed, already-trusted
 * plugin's own data rather than duplicating its work (the same posture
 * `Daymark_Federated_Comments` already takes toward the Webmention/
 * ActivityPub/ATmosphere plugins).
 *
 * A friend is represented by Friends as a real `WP_User` whose own site URL
 * lives in WordPress core's own `user_url` field — `discover()`/`fetch()`
 * both resolve a site URL to that `WP_User` via find_friend_user(). Because
 * Daymark never drives Friends' own "add a friend" flow, `discover()` only
 * ever succeeds for a friend the site owner has *already* added through
 * Friends' own UI; subscribing in Daymark for a URL Friends doesn't yet
 * know about falls through to the other registered sources exactly as it
 * would if this source didn't exist.
 *
 * Researched against the public akirk/friends GitHub source (CPT
 * registration, post-format assignment, and the original-post-URL-as-guid
 * convention in its feed-ingest code) — not verified against a live,
 * active Friends installation, since this sandbox has no way to install a
 * third-party plugin for testing. The Friends-specific assumptions below
 * (the CPT constant, the guid-as-permalink convention, `user_url` as a
 * friend's site) are each isolated behind their own small method so a
 * correction, if one turns out to be needed, is a one-line change.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Friends plugin source: `Daymark_Subscription_Source` built on a local
 * `WP_Query` against Friends' own `friend_post_cache` post type.
 */
class Daymark_Subscription_Source_Friends implements Daymark_Subscription_Source {

	/**
	 * How many cached posts to request per poll. Deliberately a single
	 * page, no further pagination — matches every other built-in source's
	 * own behavior, which only ever ingests whatever one fetch returns.
	 *
	 * @var int
	 */
	private const POSTS_PER_PAGE = 20;

	/**
	 * WordPress post_format values with a dedicated Daymark post_format
	 * bucket; everything else (including no format at all) maps to
	 * 'standard'. Matches Daymark_Subscription_Source_WordPress's own list
	 * and the "no natural equivalent" treatment issue #84's own unmapped
	 * h-entry post types get.
	 *
	 * @var string[]
	 */
	private const DAYMARK_MEDIA_FORMATS = array( 'image', 'video', 'audio', 'gallery' );

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'friends';
	}

	/**
	 * Source label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Friends Plugin', 'daymark' );
	}

	/**
	 * Discover whether the given site URL is a friend the site owner has
	 * already added through the Friends plugin's own UI.
	 *
	 * Unlike every other built-in source, this never issues a network
	 * request — it only ever succeeds for a relationship Friends already
	 * has, so there is nothing to "discover" on the friend's own site.
	 *
	 * @param string $site_url Site URL entered by the user.
	 * @return array<int, array{url: string, title: string, type: string}> A
	 *         single-element array — `url` carries the friend's own
	 *         canonical site URL (from their `WP_User::user_url`, which may
	 *         differ in scheme/trailing-slash from what was typed) and
	 *         `title` their display name — when Friends already follows
	 *         this exact site; empty otherwise (Friends inactive, or no
	 *         matching friend).
	 */
	public function discover( string $site_url ): array {
		if ( '' === trim( $site_url ) || ! $this->is_available() ) {
			return array();
		}

		$user = $this->find_friend_user( $site_url );

		if ( ! $user ) {
			return array();
		}

		return array(
			array(
				'url'   => (string) $user->user_url,
				'title' => (string) $user->display_name,
				'type'  => 'friends',
			),
		);
	}

	/**
	 * Fetch a friend's cached posts from Friends' own `friend_post_cache`
	 * post type via a local `WP_Query` — no network request.
	 *
	 * An empty array is a genuinely successful fetch of a friend with no
	 * current cached posts — distinct from a WP_Error, which means Friends
	 * itself is no longer active or the friend relationship is gone.
	 * Matches the same distinction every other built-in source documents,
	 * for the same reason: the poller's dead-feed detection must not mark a
	 * quiet-but-healthy friend dead after enough empty-but-successful polls.
	 *
	 * @param string $url The friend's own site URL, as discover() returned it.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch( string $url ): array|WP_Error {
		if ( '' === trim( $url ) ) {
			return new WP_Error( 'daymark_subscription_invalid_feed_url', __( 'Invalid URL.', 'daymark' ) );
		}

		if ( ! $this->is_available() ) {
			return new WP_Error( 'daymark_subscription_friends_unavailable', __( 'The Friends plugin is no longer active.', 'daymark' ) );
		}

		$user = $this->find_friend_user( $url );

		if ( ! $user ) {
			return new WP_Error( 'daymark_subscription_friends_not_found', __( 'This friend could not be found in Friends. It may have been removed there.', 'daymark' ) );
		}

		$query = new WP_Query(
			array(
				'post_type'      => $this->cpt(),
				'author'         => $user->ID,
				'posts_per_page' => self::POSTS_PER_PAGE,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		);

		$raw_items = array();

		foreach ( $query->posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}

			$raw_items[] = array(
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'content'      => $post->post_content,
				'excerpt'      => $post->post_excerpt,
				// Friends stores the friend's own original post URL as the
				// cached post's guid — friend_post_cache is a non-public
				// post type, so get_permalink() would return a useless
				// local admin-style URL instead.
				'permalink'    => $post->guid,
				'published_at' => $post->post_date_gmt,
				'author_name'  => (string) $user->display_name,
				'post_format'  => (string) get_post_format( $post->ID ),
			);
		}

		return $raw_items;
	}

	/**
	 * Map one raw friend_post_cache item to Daymark's source-agnostic post
	 * shape — the same eight-key shape every other built-in source
	 * produces, so ingest code never needs to know which source produced
	 * an item.
	 *
	 * `post_format` reads Friends' own already-assigned WordPress core
	 * post_format taxonomy value directly (Friends does its own
	 * content-based format discovery when a source feed doesn't declare
	 * one) — the same "trust the real value, don't re-guess" approach
	 * `Daymark_Subscription_Source_WordPress` takes toward `wp/v2/posts`'
	 * own `format` field. A `standard` result then falls back to the same
	 * shared `Daymark_Subscription_Content_Sniffer` both of those sources
	 * use, for the same reason: Friends' own format-discovery fallback
	 * doesn't run for every source feed shape, so a `standard` result here
	 * isn't necessarily a confirmed "no media" the way a genuinely assigned
	 * `image`/`video`/`audio`/`gallery` is.
	 *
	 * @param array<string, mixed> $raw_item One item from fetch()'s raw result.
	 * @return array<string, mixed> Source-agnostic normalized post data.
	 */
	public function normalize( array $raw_item ): array {
		$title = sanitize_text_field( wp_strip_all_tags( (string) ( $raw_item['title'] ?? '' ) ) );

		$excerpt_source = (string) ( $raw_item['excerpt'] ?? '' );

		if ( '' === trim( wp_strip_all_tags( $excerpt_source ) ) ) {
			$excerpt_source = (string) ( $raw_item['content'] ?? '' );
		}

		$excerpt = sanitize_text_field( wp_trim_words( wp_strip_all_tags( $excerpt_source ), 40 ) );

		$author       = sanitize_text_field( (string) ( $raw_item['author_name'] ?? '' ) );
		$permalink    = esc_url_raw( (string) ( $raw_item['permalink'] ?? '' ) );
		$published_at = $this->sanitize_datetime( (string) ( $raw_item['published_at'] ?? '' ) );

		$format = sanitize_key( (string) ( $raw_item['post_format'] ?? '' ) );

		if ( ! in_array( $format, self::DAYMARK_MEDIA_FORMATS, true ) ) {
			$format = 'standard';
		}

		$featured_image_url = '';

		if ( isset( $raw_item['post_id'] ) ) {
			$thumbnail = get_the_post_thumbnail_url( (int) $raw_item['post_id'], 'full' );

			if ( is_string( $thumbnail ) && '' !== $thumbnail ) {
				$featured_image_url = esc_url_raw( $thumbnail );
			}
		}

		// A structured thumbnail or a real Friends-assigned format is a
		// confirmed signal a content guess isn't, so only fall back to
		// sniffing the cached content's own inline media — the same
		// video/audio/gallery/image signal
		// Daymark_Subscription_Source_Feed and
		// Daymark_Subscription_Source_WordPress already look for via the
		// shared Daymark_Subscription_Content_Sniffer — and only ever
		// promote away from an unconfirmed 'standard', never override a
		// real assigned format.
		if ( 'standard' === $format ) {
			$content_html   = (string) ( $raw_item['content'] ?? '' );
			$sniffed        = Daymark_Subscription_Content_Sniffer::sniff( $content_html );
			$sniffed_format = Daymark_Subscription_Content_Sniffer::classify( $sniffed, $content_html );

			if ( '' !== $sniffed_format ) {
				$format = $sniffed_format;
			}

			if ( '' === $featured_image_url && '' !== $sniffed['image_src'] ) {
				$featured_image_url = esc_url_raw( $sniffed['image_src'] );
			}
		}

		return array(
			'title'              => $title,
			'excerpt'            => $excerpt,
			'author'             => $author,
			'published_at'       => $published_at,
			'permalink'          => $permalink,
			'post_format'        => $format,
			'featured_image_url' => $featured_image_url,
			'raw_media'          => '' !== $featured_image_url ? array( $featured_image_url ) : array(),
		);
	}

	/**
	 * Whether the Friends plugin is active and its post type is registered.
	 *
	 * @return bool
	 */
	private function is_available(): bool {
		return class_exists( 'Friends' ) && post_type_exists( $this->cpt() );
	}

	/**
	 * Friends' own custom post type slug for cached friend posts
	 * (`Friends::CPT`, currently `'friend_post_cache'`) — isolated behind
	 * its own method since this is the one Friends-internal detail every
	 * other method in this class depends on.
	 *
	 * Only ever called after is_available() (or from within it) confirms
	 * the `Friends` class exists.
	 *
	 * @return string
	 */
	private function cpt(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Friends' own constant name, not ours to rename.
		return class_exists( 'Friends' ) ? (string) \Friends::CPT : '';
	}

	/**
	 * Resolve a site URL to the `WP_User` Friends represents that friend
	 * as, by comparing against each user's own `user_url` (WordPress core's
	 * own "this user's website" field, which Friends reuses for a friend's
	 * site rather than inventing a new one).
	 *
	 * A flat `get_users()` scan rather than a targeted `WP_User_Query`
	 * `search` (which would need exact wildcard-escaping to match
	 * reliably) — deliberately simple, and perfectly adequate at the scale
	 * a personal WordPress site's user table actually reaches. Comparison
	 * ignores scheme and a trailing slash, since "http" vs "https" or a
	 * bare-vs-trailing-slash URL shouldn't be treated as a different site.
	 *
	 * @param string $url Site URL to resolve.
	 * @return WP_User|null
	 */
	private function find_friend_user( string $url ): ?WP_User {
		$target = $this->normalize_for_comparison( $url );

		if ( '' === $target ) {
			return null;
		}

		/**
		 * Filters how many users find_friend_user() scans looking for a
		 * matching `user_url`. Defaults comfortably above any realistic
		 * number of accounts (real + friend + subscription) on a personal
		 * WordPress site.
		 *
		 * @since 0.10.0
		 *
		 * @param int $number Defaults to 500.
		 */
		$number = (int) apply_filters( 'daymark_subscription_friends_user_scan_limit', 500 );

		$users = get_users(
			array(
				'number' => $number,
				'fields' => 'all',
			)
		);

		foreach ( $users as $user ) {
			$user_url = (string) $user->user_url;

			if ( '' === $user_url ) {
				continue;
			}

			if ( $this->normalize_for_comparison( $user_url ) === $target ) {
				return $user;
			}
		}

		return null;
	}

	/**
	 * Normalize a URL for comparison: lowercase host, path with any
	 * trailing slash removed, scheme dropped entirely (so http/https never
	 * counts as a different site).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_for_comparison( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url ) ) {
			$url = 'https://' . $url;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return '';
		}

		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		return $host . $path;
	}

	/**
	 * Sanitize a date string into a MySQL datetime, tolerating anything
	 * `strtotime()` can parse. Defensive: `post_date_gmt` should already be
	 * in this exact shape coming from WP core, but
	 * Daymark_Subscription_Post_Type::sanitize_datetime() (the ingest-side
	 * sanitizer normalize() output ultimately passes through) only accepts
	 * an already-MySQL-shaped string, so this guards against any
	 * unexpected shape rather than silently dropping the date.
	 *
	 * @param string $value Raw date string.
	 * @return string MySQL datetime, or '' if undeterminable.
	 */
	private function sanitize_datetime( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
