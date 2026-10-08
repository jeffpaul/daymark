<?php
/**
 * Likes and Reblogs of fediverse and Bluesky posts for a site bridged with
 * Bridgy Fed (issue #441, split from #439).
 *
 * Bridgy Fed (https://fed.brid.gy/) gives a plain WordPress site a
 * fediverse and Bluesky presence with no ActivityPub plugin. Its own docs
 * (`templates/docs.html` in snarfed/bridgy-fed, "How do I like or repost a
 * fediverse… post?") say a like or repost must be a microformats2 post with
 * `u-like-of` / `u-repost-of`, sent to Bridgy Fed by Webmention, and that
 * the post should carry a hidden link to Bridgy Fed so the site's
 * Webmention sender pings it:
 *
 *     <a class="u-bridgy-fed" href="https://fed.brid.gy/" hidden="from-humans"></a>
 *
 * Daymark already renders `u-like-of` / `u-repost-of` and adds those
 * targets to the Webmention plugin's list (Daymark_Microformats). This class
 * adds the rest, for one Mark at a time:
 *
 *  - Whether the site is bridged at all. Bridgy Fed offers no stable API
 *    to ask, so it is an explicit setting (the Bridgy Fed card on Settings ->
 *    Daymark -> Connectors), off by default, filterable. A site that isn't
 *    bridged never pings Bridgy Fed.
 *  - Whether this Mark's target needs Bridgy Fed: a fediverse post (its page
 *    advertises an ActivityStreams alternate) or a Bluesky post, that does
 *    not accept Webmentions itself. A target that takes Webmentions already
 *    gets the Like directly; sending it through Bridgy Fed too would deliver
 *    it twice. The decision is made once, when the Mark is published, and
 *    stored in META, so the markup and the Webmention list agree for the
 *    life of the Mark.
 *  - A Mark whose Like/Announce the ActivityPub plugin already queued
 *    (issue #439) is left alone for the same reason.
 *
 * Nothing here talks to Bridgy Fed directly. The Webmention plugin sends the
 * Webmention; Bridgy Fed fetches the Mark and delivers it.
 *
 * Not verified against the live service: fed.brid.gy was unreachable from
 * the environment this was built in, so the markup and flow follow Bridgy
 * Fed's own docs in its public repository.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridgy Fed routing for Like and Reblog Marks.
 */
class Daymark_Bridgy_Fed {

	/**
	 * Bridgy Fed's Webmention target, and the href of the hidden link.
	 *
	 * @var string
	 */
	public const TARGET = 'https://fed.brid.gy/';

	/**
	 * Site option: '1' when the site owner says this site is bridged.
	 *
	 * @var string
	 */
	public const OPTION = 'daymark_bridgy_fed_bridged';

	/**
	 * Post meta: '1' on a Like/Reblog Mark that should go through Bridgy Fed.
	 *
	 * @var string
	 */
	public const META = '_daymark_bridgy_fed';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'daymark_published', array( $this, 'maybe_route_mark' ), 10, 1 );
	}

	/**
	 * Whether this site is bridged with Bridgy Fed.
	 *
	 * @return bool
	 */
	public static function is_bridged(): bool {
		/**
		 * Filters whether this site is bridged with Bridgy Fed, so Like and
		 * Reblog Marks of fediverse and Bluesky posts are sent through it.
		 * Defaults to the checkbox on Settings -> Daymark -> Connectors.
		 *
		 * @since 0.19.0
		 *
		 * @param bool $bridged Defaults to the `daymark_bridgy_fed_bridged` option.
		 */
		return (bool) apply_filters( 'daymark_bridgy_fed_bridged', '1' === (string) get_option( self::OPTION, '' ) );
	}

	/**
	 * Whether the route can work at all here: bridged, and a Webmention
	 * sender to reach Bridgy Fed with.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return self::is_bridged() && Daymark_Webmention::can_send();
	}

	/**
	 * Whether a target, described by its origin signals, needs Bridgy Fed: a
	 * fediverse or Bluesky post that doesn't take Webmentions itself.
	 *
	 * @param array<string, mixed> $signals Daymark_Comment_Delivery origin signals.
	 * @return bool
	 */
	public static function target_needs_bridge( array $signals ): bool {
		if ( '' !== (string) ( $signals['webmention_endpoint'] ?? '' ) ) {
			return false;
		}

		return ! empty( $signals['activitypub_object'] ) || ! empty( $signals['bluesky'] );
	}

	/**
	 * After a Mark is published, record whether its Like/Reblog goes
	 * through Bridgy Fed.
	 *
	 * @param int $post_id Mark post ID.
	 * @return void
	 */
	public function maybe_route_mark( $post_id ): void {
		$post_id = absint( $post_id );
		$target  = self::engagement_target( $post_id );

		if ( '' === $target || ! self::available() ) {
			return;
		}

		$signals = Daymark_Comment_Delivery::origin_signals_for_url( $target );

		if ( is_wp_error( $signals ) || ! self::target_needs_bridge( $signals ) ) {
			return;
		}

		update_post_meta( $post_id, self::META, '1' );
	}

	/**
	 * The like-of or repost-of URL of a Mark, or ''.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string
	 */
	private static function engagement_target( int $post_id ): string {
		foreach ( array( '_daymark_like_of', '_daymark_repost_of' ) as $key ) {
			$url = esc_url_raw( (string) get_post_meta( $post_id, $key, true ) );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Whether a Mark should carry the Bridgy Fed link and ping Bridgy Fed
	 * now: routed at publish time, the site still bridged, and no
	 * ActivityPub Like/Announce already queued for it.
	 *
	 * @param int $post_id Mark post ID.
	 * @return bool
	 */
	public static function routes_mark( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META, true )
			&& self::is_bridged()
			&& '' === Daymark_ActivityPub_Engagement::suppressed_webmention_target( $post_id );
	}

	/**
	 * The hidden link Bridgy Fed's docs ask for.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string Escaped HTML, or ''.
	 */
	public static function markup( int $post_id ): string {
		if ( ! self::routes_mark( $post_id ) ) {
			return '';
		}

		return '<a class="u-bridgy-fed" href="' . esc_url( self::TARGET ) . '" hidden="from-humans"></a>';
	}
}
