<?php
/**
 * Subscription source interface.
 *
 * Contract for all Daymark inbound subscription sources — the built-in
 * RSS/Atom feed reader and any future source alike. Real sources implement
 * this interface and register via the `daymark_register_subscription_sources`
 * action; core Daymark code never changes.
 *
 * This is the inbound mirror of Daymark_Syndication_Connector
 * (interface-syndication-connector.php): that interface pulls a Mark out to
 * an external destination, this one pulls external content in as cached
 * `daymark_subscription_post` entries for the Timeline.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for an inbound subscription source (e.g. RSS/Atom feeds, and —
 * later — Friends `friend_post` or an ActivityPub actor's outbox).
 */
interface Daymark_Subscription_Source {

	/**
	 * Unique machine identifier, e.g. 'feed'.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human label for UI, e.g. 'RSS/Atom Feed'.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Discover the feed(s) (or other source-specific locator(s)) a user can
	 * subscribe to for a given site.
	 *
	 * Takes a site URL (e.g. `https://example.com/`), not a feed URL — the
	 * user subscribes to a site, and the concrete source is responsible for
	 * locating what it needs from there (for the built-in feed source, this
	 * is `<link rel="alternate">` autodiscovery against the site's `<head>`;
	 * a future source implementation may resolve something else entirely,
	 * e.g. an ActivityPub actor document). The return shape is
	 * implementation-specific and documented by the concrete source that
	 * defines it — this interface does not prescribe it, since the one
	 * shipping concrete implementation (`Daymark_Subscription_Source_Feed`,
	 * which performs the real autodiscovery heuristic) is built in a later
	 * task.
	 *
	 * @param string $site_url Site URL entered by the user (not a feed URL).
	 * @return array<int|string, mixed> Discovered candidate(s), or an empty
	 *                                  array when nothing is discoverable.
	 */
	public function discover( string $site_url ): array;

	/**
	 * Fetch the raw content at a given feed URL (or other source-specific
	 * locator returned by discover()).
	 *
	 * Return shape is implementation-specific (e.g. a parsed SimplePie feed
	 * object/array for the built-in feed source) — normalize() is what
	 * turns it into Daymark's source-agnostic post shape.
	 *
	 * An empty array is a *successful* fetch of a feed that currently has
	 * no items — a real, valid state (a quiet blog between posts), not a
	 * failure. A `WP_Error` is reserved for when the feed itself could not
	 * be reached or parsed. Callers (the poller's dead-feed detection in
	 * particular) rely on this distinction: conflating "empty" with
	 * "broken" would mark a quiet-but-healthy subscription dead after
	 * enough poll cycles, which is exactly the false positive dead-feed
	 * detection exists to avoid.
	 *
	 * @param string $feed_url Feed URL (or other locator) to fetch.
	 * @return array<int|string, mixed>|WP_Error Raw fetched items (possibly
	 *                                           empty), or a WP_Error when
	 *                                           the feed could not be
	 *                                           reached or parsed.
	 */
	public function fetch( string $feed_url ): array|WP_Error;

	/**
	 * Map one raw item from fetch() to Daymark's source-agnostic post
	 * shape.
	 *
	 * The output must not carry source-specific field names or structure —
	 * it has to be a shape any current or future source can populate (RSS/
	 * Atom today; a future Friends `friend_post` or ActivityPub actor-post
	 * source tomorrow) so that ingest, caching, and Timeline rendering code
	 * never need to know which source produced an item. The exact set of
	 * normalized fields is intentionally not fixed by this interface; it is
	 * defined where ingest is implemented.
	 *
	 * @param array<string, mixed> $raw_item One item from fetch()'s raw result.
	 * @return array<string, mixed> Source-agnostic normalized post data.
	 */
	public function normalize( array $raw_item ): array;
}
