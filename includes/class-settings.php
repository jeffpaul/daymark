<?php
/**
 * Daymark's site-wide settings, read in one place.
 *
 * Each setting is a plain option that Settings -> Daymark edits, layered
 * under a developer filter: the option is the filter's default, so a
 * filter still wins. Every reader in the plugin (the publisher, the
 * notifications importer, the app config, the poller, the blogroll) goes
 * through the methods here, so the settings screen can show the value
 * actually in effect and say when code has overridden it.
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Effective values for Daymark's site-wide settings.
 */
final class Daymark_Settings {

	/**
	 * Option: ask for the device's location when starting a Check In.
	 */
	public const CAPTURE_LOCATION = 'daymark_capture_location';

	/**
	 * Option: look up the weather for a Check In's location.
	 */
	public const CAPTURE_WEATHER = 'daymark_capture_weather';

	/**
	 * Option: keep a photo's camera details (EXIF).
	 */
	public const CAPTURE_CAMERA = 'daymark_capture_camera_metadata';

	/**
	 * Option: add a Check In's coordinates as h-geo markup on its page.
	 */
	public const PUBLISH_LOCATION = 'daymark_publish_location_publicly';

	/**
	 * Option: hold replies Daymark imports for moderation.
	 */
	public const HOLD_IMPORTED_REPLIES = 'daymark_hold_imported_replies';

	/**
	 * Option: let the composer ask the AI provider for suggestions on its own.
	 */
	public const AI_AUTO_SUGGEST = 'daymark_ai_auto_suggest';

	/**
	 * Option: how often to check followed sites, in seconds.
	 */
	public const POLL_INTERVAL = 'daymark_subscription_poll_interval';

	/**
	 * Option: publish the list of followed sites as a public blogroll.
	 */
	public const BLOGROLL_PUBLIC = 'daymark_blogroll_public';

	/**
	 * Option: keep Notes (Aside-format posts) off the blog's home page and
	 * out of its main feed.
	 */
	public const HIDE_NOTES_ON_HOME = 'daymark_hide_notes_on_home';

	/**
	 * Option: show the number of unread notifications on the installed
	 * app's icon (the app badge).
	 */
	public const APP_BADGE = 'daymark_app_badge_unread';

	/**
	 * Option: the destinations a new Mark of each type starts with, as a
	 * map of Mark type to connector IDs.
	 */
	public const DEFAULT_DESTINATIONS = 'daymark_default_destinations';

	/**
	 * Option: the categories a new Mark of each type starts with, as a map
	 * of Mark type to category term IDs.
	 */
	public const DEFAULT_CATEGORIES = 'daymark_default_categories';

	/**
	 * Destinations a type starts with before anyone has set site defaults.
	 */
	private const BUILTIN_DESTINATIONS = array(
		'note'    => array( 'bluesky' ),
		'image'   => array( 'instagram' ),
		'gallery' => array( 'instagram' ),
		'video'   => array( 'youtube' ),
	);

	/**
	 * Every option this class owns, for uninstall.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return array(
			self::CAPTURE_LOCATION,
			self::CAPTURE_WEATHER,
			self::CAPTURE_CAMERA,
			self::PUBLISH_LOCATION,
			self::HOLD_IMPORTED_REPLIES,
			self::AI_AUTO_SUGGEST,
			self::POLL_INTERVAL,
			self::BLOGROLL_PUBLIC,
			self::HIDE_NOTES_ON_HOME,
			self::APP_BADGE,
			self::DEFAULT_DESTINATIONS,
			self::DEFAULT_CATEGORIES,
		);
	}

	/**
	 * A type's site default destinations as stored (Settings -> Daymark ->
	 * General), before any filter. Falls back to the built-in defaults until
	 * the site saves its own.
	 *
	 * @param string $type Mark primary type.
	 * @return string[] Connector IDs.
	 */
	public static function stored_destination_defaults( string $type ): array {
		$stored = get_option( self::DEFAULT_DESTINATIONS, null );

		if ( ! is_array( $stored ) ) {
			return self::BUILTIN_DESTINATIONS[ $type ] ?? array();
		}

		return isset( $stored[ $type ] ) && is_array( $stored[ $type ] )
			? array_values( array_map( 'sanitize_key', $stored[ $type ] ) )
			: array();
	}

	/**
	 * A type's site default destinations, as in effect: what a new Mark of
	 * that type starts with when its author has no remembered choice.
	 *
	 * @param string $type Mark primary type.
	 * @return string[] Connector IDs.
	 */
	public static function destination_defaults( string $type ): array {
		/**
		 * Filters the default destination connector IDs for a Mark type.
		 *
		 * Defaults to the site's own choice (Settings -> Daymark ->
		 * General), itself defaulting to notes -> Bluesky, images and
		 * galleries -> Instagram, and video -> YouTube.
		 *
		 * @param string[] $type_defaults Default connector IDs for this type.
		 * @param string   $type          Primary Mark type.
		 */
		$ids = apply_filters( 'daymark_default_destinations', self::stored_destination_defaults( $type ), $type );

		return is_array( $ids ) ? array_values( array_unique( array_map( 'sanitize_key', $ids ) ) ) : array();
	}

	/**
	 * A type's site default categories as stored, before any filter. Empty
	 * until the site saves its own, which leaves the site's default
	 * category in place.
	 *
	 * @param string $type Mark primary type.
	 * @return int[] Category term IDs.
	 */
	public static function stored_category_defaults( string $type ): array {
		$stored = get_option( self::DEFAULT_CATEGORIES, array() );

		return is_array( $stored ) && isset( $stored[ $type ] ) && is_array( $stored[ $type ] )
			? array_values( array_filter( array_map( 'absint', $stored[ $type ] ) ) )
			: array();
	}

	/**
	 * A type's site default categories, as in effect.
	 *
	 * @param string $type Mark primary type.
	 * @return int[] Category term IDs.
	 */
	public static function category_defaults( string $type ): array {
		/**
		 * Filters the default category IDs for a Mark type.
		 *
		 * Defaults to the site's own choice (Settings -> Daymark ->
		 * General). An empty list files the Mark under the site's default
		 * category.
		 *
		 * @since 0.20.0
		 *
		 * @param int[]  $ids  Default category term IDs for this type.
		 * @param string $type Primary Mark type.
		 */
		$ids = apply_filters( 'daymark_default_categories', self::stored_category_defaults( $type ), $type );

		return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
	}

	/**
	 * Whether starting a Check In asks the browser for the device's
	 * location, to suggest its place. No other kind of Mark captures a
	 * location (Daymark_Publisher drops one sent for any other type).
	 *
	 * @return bool
	 */
	public static function capture_location(): bool {
		/**
		 * Whether a Check In's location is captured and stored.
		 *
		 * Defaults to the `daymark_capture_location` option (Settings ->
		 * Daymark -> Data & privacy, on by default). When false, the
		 * composer never asks for the device's location, the server ignores
		 * one if sent, and the reverse-geocode route refuses. Turning it off
		 * also turns off weather, which needs a location.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $capture Defaults to the `daymark_capture_location` option.
		 */
		return (bool) apply_filters( 'daymark_capture_location', (bool) get_option( self::CAPTURE_LOCATION, true ) );
	}

	/**
	 * Whether a Check In's weather is looked up (Open-Meteo).
	 *
	 * @return bool
	 */
	public static function capture_weather(): bool {
		/**
		 * Whether a Check In's current weather is looked up and stored.
		 *
		 * Defaults to the `daymark_capture_weather` option (on by default).
		 * Has no effect when location capture is off, since the lookup
		 * needs a location.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $capture Defaults to the `daymark_capture_weather` option.
		 */
		return (bool) apply_filters( 'daymark_capture_weather', (bool) get_option( self::CAPTURE_WEATHER, true ) );
	}

	/**
	 * Whether a photo's camera details (EXIF) are stored.
	 *
	 * @return bool
	 */
	public static function capture_camera_metadata(): bool {
		/**
		 * Whether camera, lens, and exposure details already in a photo's
		 * file are stored with the Mark.
		 *
		 * Defaults to the `daymark_capture_camera_metadata` option (on by
		 * default).
		 *
		 * @since 0.11.0
		 *
		 * @param bool $capture Defaults to the `daymark_capture_camera_metadata` option.
		 */
		return (bool) apply_filters( 'daymark_capture_camera_metadata', (bool) get_option( self::CAPTURE_CAMERA, true ) );
	}

	/**
	 * Whether a Check In's coordinates are added to its page as
	 * machine-readable h-geo markup. Its place name and map always show.
	 *
	 * @param int $post_id Mark post ID, or 0 when asking about the site.
	 * @return bool
	 */
	public static function publish_location_publicly( int $post_id = 0 ): bool {
		/**
		 * Whether a Check In's exact coordinates are rendered as p-geo/h-geo
		 * markup on its own page.
		 *
		 * Defaults to the `daymark_publish_location_publicly` option (off
		 * by default). A Check In's place name and map are always part of
		 * its content; this only adds the coordinates as structured data
		 * for other sites and readers.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $publish_publicly Defaults to the `daymark_publish_location_publicly` option.
		 * @param int  $post_id          Mark post ID (0 when asked about the site as a whole).
		 */
		return (bool) apply_filters( 'daymark_publish_location_publicly', (bool) get_option( self::PUBLISH_LOCATION, false ), $post_id );
	}

	/**
	 * The `comment_approved` value for a reply Daymark imports from a
	 * connected network: 1 to show it right away, 0 to hold it.
	 *
	 * @param int    $post_id  Mark the reply is on (0 when asking about the site).
	 * @param string $network  Network the reply came from.
	 * @param array  $response The imported response.
	 * @return int
	 */
	public static function imported_reply_approved( int $post_id = 0, string $network = '', array $response = array() ): int {
		$default = get_option( self::HOLD_IMPORTED_REPLIES, '' ) ? 0 : 1;

		/**
		 * The `comment_approved` value for a reply Daymark's own importer
		 * stores (replies the ActivityPub, ATmosphere, and Webmention
		 * plugins deliver follow those plugins' own settings).
		 *
		 * Defaults to 0 when "Hold imported replies for moderation" is on
		 * (Settings -> Daymark -> Data & privacy), else 1.
		 *
		 * @since 0.7.0
		 *
		 * @param int    $approved 1 to approve, 0 to hold for moderation.
		 * @param int    $post_id  Mark post ID.
		 * @param string $network  Network ID.
		 * @param array  $response Imported response data.
		 */
		return (int) apply_filters( 'daymark_comment_import_approved', $default, $post_id, $network, $response );
	}

	/**
	 * Whether the composer may ask the AI provider for suggestions on its
	 * own (tags while you type, alt text when you pick a photo). When false,
	 * AI only runs when you tap an AI button.
	 *
	 * @return bool
	 */
	public static function ai_auto_suggest(): bool {
		/**
		 * Whether the composer sends drafts to the AI provider without a tap.
		 *
		 * Defaults to the `daymark_ai_auto_suggest` option (on by default).
		 *
		 * @since 0.20.0
		 *
		 * @param bool $auto Defaults to the `daymark_ai_auto_suggest` option.
		 */
		return (bool) apply_filters( 'daymark_ai_auto_suggest', (bool) get_option( self::AI_AUTO_SUGGEST, true ) );
	}

	/**
	 * How often followed sites are checked, in seconds.
	 *
	 * @return int
	 */
	public static function poll_interval(): int {
		/**
		 * Filters how often the recurring poll checks every subscription, in
		 * seconds.
		 *
		 * Defaults to the `daymark_subscription_poll_interval` option
		 * (Settings -> Daymark -> General), itself defaulting to a day.
		 *
		 * @since 0.10.0
		 *
		 * @param int $seconds Poll interval. Default DAY_IN_SECONDS.
		 */
		return (int) apply_filters( 'daymark_subscription_poll_interval', (int) get_option( self::POLL_INTERVAL, DAY_IN_SECONDS ) );
	}

	/**
	 * Whether the list of followed sites is published as a public blogroll
	 * (an OPML file, advertised in the site's head).
	 *
	 * @return bool
	 */
	public static function blogroll_public(): bool {
		/**
		 * Whether the sites you follow are published as a public OPML
		 * blogroll.
		 *
		 * Defaults to the `daymark_blogroll_public` option (Settings ->
		 * Daymark -> General, off by default).
		 *
		 * @since 0.20.0
		 *
		 * @param bool $public Defaults to the `daymark_blogroll_public` option.
		 */
		return (bool) apply_filters( 'daymark_blogroll_public', (bool) get_option( self::BLOGROLL_PUBLIC, false ) );
	}

	/**
	 * Whether Notes are kept off the blog's home page and out of its main
	 * feed. A Note is any post with the Aside post format, whether it came
	 * from Daymark or the block editor (Daymark_Notes). Off by default, so
	 * Notes appear there like any other post.
	 *
	 * @return bool
	 */
	public static function hide_notes_on_home(): bool {
		/**
		 * Whether Notes (Aside-format posts) are left off the blog's home
		 * page and out of its main RSS/Atom feed.
		 *
		 * Defaults to the `daymark_hide_notes_on_home` option (Settings ->
		 * Daymark -> General, off by default). When true, Notes are left out
		 * of those two places only: each Note's own page, archives, search,
		 * the REST API, and Daymark's Timeline still include them.
		 *
		 * @since 0.20.0
		 *
		 * @param bool $hide Defaults to the `daymark_hide_notes_on_home` option.
		 */
		return (bool) apply_filters( 'daymark_hide_notes_on_home', (bool) get_option( self::HIDE_NOTES_ON_HOME, false ) );
	}

	/**
	 * Whether the installed app shows the number of unread notifications
	 * on its icon. Off by default.
	 *
	 * @return bool
	 */
	public static function app_badge_unread(): bool {
		/**
		 * Whether the installed Daymark app shows the number of unread
		 * notifications on its icon (the app badge).
		 *
		 * Defaults to the `daymark_app_badge_unread` option (Settings ->
		 * Daymark -> General, off by default). The badge needs a browser
		 * with the Badging API and an installed app.
		 *
		 * @since 0.21.0
		 *
		 * @param bool $show Defaults to the `daymark_app_badge_unread` option.
		 */
		return (bool) apply_filters( 'daymark_app_badge_unread', (bool) get_option( self::APP_BADGE, false ) );
	}
}
