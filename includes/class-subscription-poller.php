<?php
/**
 * Subscription polling: ingest, click-through fetch, pruning, cron scheduling,
 * and manual (rate-limited) refresh for `daymark_subscription` rows.
 *
 * This is the "what happens after a source is registered" half of the
 * Subscriptions feature (issue #78) — Daymark_Subscription_Source_Registry and
 * its concrete Daymark_Subscription_Source_Feed already know how to discover
 * and fetch/normalize a feed; this class is what turns that into cached
 * `daymark_subscription_post` entries on a recurring schedule, prunes old
 * ones, and serves a click-through "fetch the rest of this post" request.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polls, ingests, prunes, and click-through-fetches subscription content.
 */
class Daymark_Subscription_Poller {

	/**
	 * Recurring cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'daymark_subscription_poll';

	/**
	 * Name registered with WordPress's `cron_schedules` filter for the
	 * interval sourced from the `daymark_subscription_poll_interval` filter.
	 * A custom schedule (rather than a built-in name like 'daily') is
	 * required because that filter can return an arbitrary interval, not
	 * just one of WP-Cron's fixed built-in recurrences.
	 *
	 * @var string
	 */
	private const CRON_SCHEDULE_KEY = 'daymark_subscription_poll_interval';

	/**
	 * Source-agnostic post formats treated as rich media: embed/enclosure
	 * data is resolved and cached at ingest time for these, per the PRD's
	 * content ingest rules. Every other format (standard, status, quote,
	 * link, and anything else a future source might report) gets no embed
	 * data and stores only title/excerpt/author/date/permalink/format/image.
	 *
	 * @var string[]
	 */
	private const RICH_MEDIA_FORMATS = array( 'image', 'video', 'audio', 'gallery' );

	/**
	 * Consecutive failed checks after which a subscription is flagged dead
	 * (`status` => 'error'). Flagging stops the subscription from being
	 * picked up by the scheduled poll (Daymark_Subscriptions::get_active()
	 * only returns 'active' rows) until a manual refresh succeeds again.
	 *
	 * @var int
	 */
	private const DEAD_FEED_FAILURE_THRESHOLD = 7;

	/**
	 * Default maximum full-content click-through response size, in bytes.
	 * Filterable via `daymark_subscription_max_content_bytes` (issue #81).
	 *
	 * @var int
	 */
	private const MAX_CONTENT_BYTES = 4 * 1024 * 1024; // 4 MB.

	/**
	 * Hook up. Called once from Daymark_Plugin::on_init(), mirroring
	 * Daymark_Backflow_Sync::register().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_poll' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval is intentionally filterable; see daymark_subscription_poll_interval.
		// Self-heal the recurring schedule: sites where the plugin was
		// already active when this feature arrived never ran activation.
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/**
	 * Register the custom cron schedule this class polls on, sourcing its
	 * interval from the `daymark_subscription_poll_interval` filter (default
	 * DAY_IN_SECONDS) so a site can tighten or relax the polling cadence
	 * without needing a built-in WP-Cron recurrence name to exist for it.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_cron_schedule( array $schedules ): array {
		/** This filter is documented in schedule(). */
		$interval = (int) apply_filters( 'daymark_subscription_poll_interval', DAY_IN_SECONDS );

		$schedules[ self::CRON_SCHEDULE_KEY ] = array(
			'interval' => max( MINUTE_IN_SECONDS, $interval ),
			'display'  => __( 'Daymark Subscription Poll Interval', 'daymark' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring poll. Runs on plugin activation, and self-heals
	 * on `init` for already-active installs (see register()).
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		// The actual interval is resolved by register_cron_schedule() via
		// the `daymark_subscription_poll_interval` filter; this just
		// schedules the event against that custom schedule's name.
		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE_KEY, self::CRON_HOOK );
	}

	/**
	 * Clear the schedule. Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The scheduled cron callback: polls, then prunes, every active
	 * subscription. Pruning runs in the same pass right after each
	 * subscription's poll, per the PRD ("not a separate scheduled job").
	 *
	 * @return void
	 */
	public function run_scheduled_poll(): void {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;

		foreach ( $subscriptions->get_active() as $subscription ) {
			$id = absint( $subscription['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$this->poll_subscription( $id );
			$this->prune_subscription( $id );
		}
	}

	/**
	 * Poll one subscription: fetch its feed, and ingest any new items.
	 *
	 * A failed fetch (the registered source's fetch() returns a WP_Error —
	 * the feed itself could not be reached or parsed) increments the
	 * consecutive failure count and leaves all existing cached posts
	 * untouched; it never resets or ingests anything. A feed that fetches
	 * fine but currently has zero items returns a plain (empty) array, not
	 * a WP_Error, and is treated as a success: the failure count still
	 * resets, nothing is ingested. Seven consecutive real failures flags
	 * the subscription dead (`status` => 'error'), which stops it from
	 * being returned by
	 * Daymark_Subscriptions::get_active() — and therefore from being
	 * polled by run_scheduled_poll() — until a manual refresh (which looks
	 * the row up by ID directly, bypassing the active-only filter) succeeds
	 * again and flips it back to 'active'.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return true|WP_Error True on a successful poll (even with zero new
	 *                       items), WP_Error on a hard failure to reach or
	 *                       parse the feed, or if the subscription/source no
	 *                       longer exists.
	 */
	public function poll_subscription( int $subscription_id ) {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscription  = $subscriptions->get( $subscription_id );

		if ( null === $subscription ) {
			return new WP_Error(
				'daymark_subscription_not_found',
				__( 'Subscription not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$registry = Daymark_Plugin::instance()->subscription_source_registry;
		$source   = $registry->get_source( sanitize_key( (string) ( $subscription['source_type'] ?? '' ) ) );

		if ( ! $source instanceof Daymark_Subscription_Source ) {
			$error_message = __( 'This subscription\'s source type is no longer available.', 'daymark' );

			$this->record_failed_check( $subscription_id, $error_message );

			return new WP_Error(
				'daymark_subscription_source_missing',
				$error_message,
				array( 'status' => 500 )
			);
		}

		$raw_items = $source->fetch( (string) $subscription['feed_url'] );

		// A WP_Error means the feed itself couldn't be reached or parsed —
		// a real failure. An empty (but non-error) array means the feed
		// fetched fine and simply has no items right now, a legitimate
		// state for a quiet blog that must NOT count against it, or a
		// subscription would get wrongly flagged dead after enough silent
		// poll cycles.
		if ( is_wp_error( $raw_items ) ) {
			// Reuses the source's own WP_Error message (e.g. a SimplePie
			// parse error, an SSRF-guard rejection, or a size-cap failure)
			// for `last_error` — the most specific reason actually available,
			// rather than the generic message the returned WP_Error carries.
			$this->record_failed_check( $subscription_id, $raw_items->get_error_message() );

			return new WP_Error(
				'daymark_subscription_fetch_failed',
				__( 'This subscription\'s feed could not be reached or parsed.', 'daymark' ),
				array( 'status' => 502 )
			);
		}

		$this->record_successful_check( $subscription_id );

		foreach ( $raw_items as $raw_item ) {
			$this->maybe_ingest_item( $subscription_id, $source->normalize( $raw_item ) );
		}

		// WebSub (issue #82): purely additive to the polling this method
		// already does — never a reason to fail or skip anything above.
		// Only the built-in feed source can advertise a hub today.
		if ( $source instanceof Daymark_Subscription_Source_Feed ) {
			Daymark_Plugin::instance()->websub_subscriber->maybe_subscribe(
				$subscription_id,
				(string) $subscription['feed_url'],
				$source->get_last_hub_url()
			);
		}

		return true;
	}

	/**
	 * Record a successful check: resets the failure count and refreshes
	 * `status`/`last_checked_at`/`last_error` — shared by poll_subscription()
	 * (a successful poll) and Daymark_Websub_Endpoint (a verified WebSub
	 * content delivery, which is just as much evidence the subscription is
	 * alive as a successful poll is).
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function record_successful_check( int $subscription_id ): void {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;

		$subscriptions->reset_failure_count( $subscription_id );
		$subscriptions->update(
			$subscription_id,
			array(
				'status'          => 'active',
				'last_checked_at' => current_time( 'mysql', true ),
				'last_error'      => '',
			)
		);
	}

	/**
	 * Record one failed check: increments the consecutive failure count,
	 * updates `last_checked_at`/`last_error`, and flags the subscription dead
	 * once the threshold is reached.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $error_message   Human-readable reason for this failure
	 *                                (issue #81), stored as `last_error`.
	 * @return void
	 */
	private function record_failed_check( int $subscription_id, string $error_message = '' ): void {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$new_count     = $subscriptions->increment_failure_count( $subscription_id );

		$fields = array(
			'last_checked_at' => current_time( 'mysql', true ),
			'last_error'      => $error_message,
		);

		if ( is_int( $new_count ) && $new_count >= self::DEAD_FEED_FAILURE_THRESHOLD ) {
			$fields['status'] = 'error';
		}

		$subscriptions->update( $subscription_id, $fields );
	}

	/**
	 * Ingest one normalized item, unless a `daymark_subscription_post` for
	 * this permalink + subscription already exists.
	 *
	 * Public: also called directly by Daymark_Websub_Endpoint for a verified
	 * WebSub content delivery, which normalizes items the exact same way a
	 * poll does but never goes through poll_subscription() itself (there is
	 * no fetch to perform — the hub already pushed the content).
	 *
	 * @param int                  $subscription_id Subscription ID.
	 * @param array<string, mixed> $normalized      Daymark_Subscription_Source::normalize() output.
	 * @return int New post ID, or 0 when skipped (duplicate, or no usable permalink) or on insert failure.
	 */
	public function maybe_ingest_item( int $subscription_id, array $normalized ): int {
		$permalink = esc_url_raw( (string) ( $normalized['permalink'] ?? '' ) );

		// Nothing to dedupe on or link back to the source with.
		if ( '' === $permalink ) {
			return 0;
		}

		if ( $this->post_exists_for_permalink( $subscription_id, $permalink ) ) {
			return 0;
		}

		$title    = sanitize_text_field( (string) ( $normalized['title'] ?? '' ) );
		$excerpt  = sanitize_text_field( (string) ( $normalized['excerpt'] ?? '' ) );
		$author   = sanitize_text_field( (string) ( $normalized['author'] ?? '' ) );
		$image    = esc_url_raw( (string) ( $normalized['featured_image_url'] ?? '' ) );
		$format   = sanitize_key( (string) ( $normalized['post_format'] ?? 'standard' ) );
		$date     = Daymark_Subscription_Post_Type::sanitize_datetime( (string) ( $normalized['published_at'] ?? '' ) );
		$is_media = in_array( $format, self::RICH_MEDIA_FORMATS, true );

		// Rich-media formats (image/video/audio/gallery): resolve and cache
		// embed data now, so Timeline render time never resolves oEmbed.
		// This phase's "resolution" is caching the raw enclosure/media URLs
		// normalize() already extracted (raw_media) — no oEmbed HTTP call is
		// made here. See class docblock and CLAUDE.md for this interpretation.
		$embed_data = '';

		if ( $is_media ) {
			$raw_media  = is_array( $normalized['raw_media'] ?? null ) ? $normalized['raw_media'] : array();
			$raw_media  = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $raw_media ) ) ) );
			$embed_data = Daymark_Subscription_Post_Type::sanitize_embed_data( array( 'raw_media' => $raw_media ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, 'subscription_id', absint( $subscription_id ) );
		update_post_meta( $post_id, 'permalink', $permalink );
		update_post_meta( $post_id, 'author', $author );
		update_post_meta( $post_id, 'published_at', $date );
		update_post_meta( $post_id, 'post_format', $format );
		update_post_meta( $post_id, 'featured_image_url', $image );
		update_post_meta( $post_id, 'embed_data', $embed_data );
		// Every format starts excerpt_only: rich-media formats get their
		// embed data pre-resolved above, but none of them (nor standard/
		// status/quote/link) fetch a full body at ingest time.
		update_post_meta( $post_id, 'content_state', 'excerpt_only' );
		update_post_meta( $post_id, 'body_content', '' );
		update_post_meta( $post_id, 'fetched_full_at', '' );

		return (int) $post_id;
	}

	/**
	 * Whether a `daymark_subscription_post` already exists for this
	 * subscription + permalink (dedupe check).
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $permalink       Source permalink (already sanitized).
	 * @return bool
	 */
	private function post_exists_for_permalink( int $subscription_id, string $permalink ): bool {
		$query = new WP_Query(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale subscription ingest dedupe.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'subscription_id',
						'value'   => $subscription_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'   => 'permalink',
						'value' => $permalink,
					),
				),
			)
		);

		return ! empty( $query->posts );
	}

	/**
	 * Prune a subscription's cached posts: eligible once they exceed the
	 * later of the 10 most recent posts, or all posts published within the
	 * last year. Runs right after poll_subscription() in the same pass.
	 *
	 * A post is retained (never pruned) when it is among the 10 most recent
	 * by `published_at`, OR its `published_at` falls within the last year —
	 * everything else gets pruned. Concretely: fewer than 10 total posts
	 * means nothing is pruned regardless of age (all of them are within the
	 * top 10); 100 posts all published within the last year means nothing is
	 * pruned either (all of them satisfy the last-year clause).
	 *
	 * Pruning clears `body_content` and `embed_data` and sets `content_state`
	 * to 'pruned'. Title, excerpt, `published_at`, and `featured_image_url`
	 * are left untouched (per the PRD, these are what a pruned card renders
	 * from). `permalink`, `subscription_id`, `author`, and `post_format` are
	 * also left untouched deliberately, even though the PRD's "keep only
	 * title/excerpt/date/image" phrasing might suggest clearing them too —
	 * they are structural bookkeeping fields, not rendered content, and
	 * `permalink` in particular is required for the click-through re-fetch a
	 * pruned post still supports. See the "before you finish" note in this
	 * task's write-up: flagged for review.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return int Count of posts pruned.
	 */
	public function prune_subscription( int $subscription_id ): int {
		$post_ids = get_posts(
			array(
				'post_type'      => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'orderby'        => 'meta_value',
				'meta_key'       => 'published_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Personal-site-scale pruning pass; sorts lexically, which matches chronologically for the Y-m-d H:i:s meta format.
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal-site-scale pruning pass.
				'meta_query'     => array(
					array(
						'key'     => 'subscription_id',
						'value'   => $subscription_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$one_year_ago = time() - YEAR_IN_SECONDS;
		$pruned_count = 0;

		foreach ( $post_ids as $index => $post_id ) {
			$post_id = (int) $post_id;

			// Retained: among the 10 most recent, regardless of age.
			if ( $index < 10 ) {
				continue;
			}

			$published_at = (string) get_post_meta( $post_id, 'published_at', true );
			$published_ts = '' !== $published_at ? strtotime( $published_at . ' +00:00' ) : false;

			// Retained: published within the last year (an undeterminable
			// date is treated as NOT within the last year, so it falls
			// through to pruning like any other stale, unranked post).
			if ( false !== $published_ts && $published_ts >= $one_year_ago ) {
				continue;
			}

			if ( 'pruned' === get_post_meta( $post_id, 'content_state', true ) ) {
				continue; // Already pruned; nothing left to do.
			}

			update_post_meta( $post_id, 'body_content', '' );
			update_post_meta( $post_id, 'embed_data', '' );
			update_post_meta( $post_id, 'content_state', 'pruned' );

			++$pruned_count;
		}

		return $pruned_count;
	}

	/**
	 * Click-through fetch: fetch a `daymark_subscription_post`'s full content
	 * live from its source permalink, sanitize it, and cache it. Also the
	 * re-fetch path for a pruned post — nothing here distinguishes a pruned
	 * post from one that was simply never fetched in full; both take the
	 * identical path.
	 *
	 * The response body is narrowed with extract_body_html() (semantic
	 * chrome and a comments/reply section dropped, the page's own
	 * <article> preferred over its whole <body> when present) before
	 * wp_kses_post() sanitizes what's left — a bounded, regex-based
	 * approximation of article-body extraction, not full Readability
	 * parity; see that method's own docblock for what it does and doesn't
	 * catch.
	 *
	 * @param int $post_id `daymark_subscription_post` ID.
	 * @return true|WP_Error True once body_content/content_state/fetched_full_at
	 *                       are cached, WP_Error on a missing post or fetch failure.
	 */
	public function fetch_full_content( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Daymark_Subscription_Post_Type::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'daymark_subscription_post_not_found',
				__( 'Subscription post not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		$permalink = esc_url_raw( (string) get_post_meta( $post_id, 'permalink', true ) );
		$scheme    = strtolower( (string) wp_parse_url( $permalink, PHP_URL_SCHEME ) );

		if ( '' === $permalink || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'daymark_subscription_invalid_permalink',
				__( 'This post has no valid source URL to fetch.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		// SSRF hardening (issue #81): a rejected URL fails the exact same way
		// an invalid one already does above.
		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $permalink ) ) ) {
			return new WP_Error(
				'daymark_subscription_invalid_permalink',
				__( 'This post has no valid source URL to fetch.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		/**
		 * Filters the maximum full-content click-through response size, in
		 * bytes, fetch_full_content() will download.
		 *
		 * @since 0.10.0
		 *
		 * @param int $max_bytes Defaults to 4 MB.
		 */
		$max_bytes = (int) apply_filters( 'daymark_subscription_max_content_bytes', self::MAX_CONTENT_BYTES );

		// wp_safe_remote_get(), not wp_remote_get(): this is a stored,
		// user-subscribed-to external URL fetched on a live user action, same
		// SSRF-hardening reasoning as the feed source's own site-HTML fetch.
		$response = wp_safe_remote_get(
			$permalink,
			array(
				/**
				 * Filters the HTTP timeout, in seconds, used for the
				 * click-through full-content fetch.
				 *
				 * @since 0.10.0
				 *
				 * @param int $seconds Defaults to 15.
				 */
				'timeout'             => (int) apply_filters( 'daymark_subscription_content_fetch_timeout', 15 ),
				'redirection'         => 5,
				'limit_response_size' => $max_bytes,
				'user-agent'          => 'Daymark/' . ( defined( 'DAYMARK_VERSION' ) ? DAYMARK_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'daymark_subscription_fetch_failed',
				__( 'The full content could not be fetched from the source site.', 'daymark' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'daymark_subscription_fetch_failed',
				__( 'The full content could not be fetched from the source site.', 'daymark' ),
				array( 'status' => 502 )
			);
		}

		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( '' === $body ) {
			return new WP_Error(
				'daymark_subscription_fetch_failed',
				__( 'The source site returned an empty response.', 'daymark' ),
				array( 'status' => 502 )
			);
		}

		// Reject rather than silently accept a truncated body: a body at or
		// beyond the cap means the download was cut off, not that the page
		// genuinely ended there.
		if ( strlen( $body ) >= $max_bytes ) {
			return new WP_Error(
				'daymark_subscription_fetch_failed',
				__( 'The full content could not be fetched from the source site.', 'daymark' ),
				array( 'status' => 502 )
			);
		}

		$sanitized = wp_kses_post( self::extract_body_html( $body ) );

		update_post_meta( $post_id, 'body_content', $sanitized );
		update_post_meta( $post_id, 'content_state', 'full' );
		update_post_meta( $post_id, 'fetched_full_at', current_time( 'mysql', true ) );

		return true;
	}

	/**
	 * Narrow a fetched page down to just its likely article markup before
	 * wp_kses_post() sanitizes it — the click-through sheet's whole point is
	 * showing the post someone tapped into, not the rest of its page's
	 * chrome (nav, header, footer, sidebar, comments) around it.
	 *
	 * By design, wp_kses_post() strips disallowed tags but leaves their
	 * enclosed text behind, so a raw fetched HTML page (which routinely
	 * carries a `<head>` full of `<title>`/`<meta>`, `<script>`/`<style>`
	 * blocks anywhere in the document, and a theme's own nav/header/footer/
	 * comments markup around the actual post) would otherwise surface all
	 * of that as visible content. This does four bounded, regex-based
	 * passes (matching this codebase's existing choice, in
	 * Daymark_Subscription_Source_Feed, to avoid a hard DOMDocument
	 * dependency rather than reach for full Readability-style article
	 * extraction, which is real, separate work — see the mf2 h-entry
	 * connector tracked in issue #84):
	 *
	 * 1. Drop `<script>`/`<style>`/`<noscript>` elements entirely (tag
	 *    *and* content, since kses would only remove the tags).
	 * 2. Drop `<nav>`/`<header>`/`<footer>`/`<aside>` elements entirely,
	 *    wherever they appear — a reliable signal on any HTML5-structured
	 *    page (WordPress or not), not a WordPress-specific guess.
	 * 3. Prefer the page's own `<article>` element over the whole `<body>`
	 *    when present (first non-greedy match, so a later "related posts"
	 *    section built from its own `<article>` cards is never reached).
	 *    `<article>` is the near-universal single-post convention —
	 *    WordPress's own `post_class()` included — and, unlike `<body>`,
	 *    it's also what actually excludes a theme's comments section for
	 *    the common case: comments are almost always a sibling rendered
	 *    *after* `</article>`, not nested inside it.
	 * 4. As a second, defensive pass over whatever step 3 left: some
	 *    themes do nest their comments list or reply form inside the same
	 *    `<article>` as the post content, each conventionally carrying a
	 *    recognizable `id="comments"`/`id="respond"` — drop everything
	 *    from that point onward rather than trying to balance the
	 *    container's own closing tag (which a plain regex can't do
	 *    reliably against arbitrary nested markup).
	 *
	 * None of this is exact against arbitrary, unknown page markup — an
	 * unusual theme that skips `<article>` and/or gives its comments
	 * section no recognizable id can still leak some chrome through, same
	 * as the pre-existing script/style stripping already accepted that
	 * risk for its own narrower case.
	 *
	 * @param string $html Raw fetched HTML.
	 * @return string Narrowed HTML, still to be passed through wp_kses_post().
	 */
	private static function extract_body_html( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<(nav|header|footer|aside)\b[^>]*>.*?</\1>#is', '', $html );

		if ( preg_match( '#<article\b[^>]*>(.*?)</article>#is', $html, $matches ) ) {
			$html = $matches[1];
		} elseif ( preg_match( '#<body\b[^>]*>(.*)</body>#is', $html, $matches ) ) {
			$html = $matches[1];
		}

		$html = (string) preg_replace( '#<[^>]+\bid=["\'](?:comments|respond)["\'][^>]*>.*$#is', '', $html );

		return $html;
	}

	/**
	 * Manual (pull-to-refresh) poll: independent of the cron schedule.
	 * Rate-limited to once per subscription per
	 * `daymark_subscription_manual_refresh_interval` (default 15 minutes) —
	 * within the window this makes no request at all and returns a
	 * distinguishable WP_Error rather than silently no-op'ing or polling
	 * anyway.
	 *
	 * Looks the subscription up by ID directly (not via get_active()), so a
	 * subscription already flagged dead (`status` => 'error') can still be
	 * manually retried; a successful poll flips it back to 'active'.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return true|WP_Error True once polled (and pruned) and
	 *                       `last_manual_refresh_at` is updated; WP_Error
	 *                       when the subscription does not exist, the window
	 *                       has not elapsed yet (code
	 *                       `daymark_subscription_refresh_too_recent`), or
	 *                       the underlying poll itself failed (the poll's own
	 *                       WP_Error, in which case `last_manual_refresh_at`
	 *                       is still updated — the attempt was made).
	 */
	public function manual_refresh( int $subscription_id ) {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$subscription  = $subscriptions->get( $subscription_id );

		if ( null === $subscription ) {
			return new WP_Error(
				'daymark_subscription_not_found',
				__( 'Subscription not found.', 'daymark' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * Filters how long a manual refresh treats a subscription as too
		 * recently refreshed to poll again, in seconds.
		 *
		 * @param int $seconds Manual refresh cooldown. Default 15 minutes.
		 */
		$interval = max( 1, (int) apply_filters( 'daymark_subscription_manual_refresh_interval', 15 * MINUTE_IN_SECONDS ) );

		$last_refresh = (string) ( $subscription['last_manual_refresh_at'] ?? '' );

		if ( '' !== $last_refresh ) {
			$last_ts = strtotime( $last_refresh . ' +00:00' );
			$elapsed = false !== $last_ts ? ( time() - $last_ts ) : $interval;

			if ( $elapsed < $interval ) {
				return new WP_Error(
					'daymark_subscription_refresh_too_recent',
					__( 'This subscription was refreshed recently. Please try again later.', 'daymark' ),
					array(
						'status'      => 429,
						'retry_after' => $interval - $elapsed,
					)
				);
			}
		}

		$result = $this->poll_subscription( $subscription_id );
		$this->prune_subscription( $subscription_id );

		// Recorded regardless of poll success/failure: a manual refresh
		// "used up" its window the moment the request was made, independent
		// of the cron schedule and never resetting or interacting with it.
		$subscriptions->update( $subscription_id, array( 'last_manual_refresh_at' => current_time( 'mysql', true ) ) );

		return $result;
	}
}
