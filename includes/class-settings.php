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
	 * Option: offer a Listen button that reads a post aloud in the app's
	 * full post view, using the AI provider's text-to-speech.
	 */
	public const TEXT_TO_SPEECH = 'daymark_text_to_speech';

	/**
	 * Option: how often to check followed sites, in seconds.
	 */
	public const POLL_INTERVAL = 'daymark_subscription_poll_interval';

	/**
	 * Option: publish the list of followed sites as a public blogroll.
	 */
	public const BLOGROLL_PUBLIC = 'daymark_blogroll_public';

	/**
	 * Option: tell a site when you start following it (a follow Webmention).
	 */
	public const ANNOUNCE_FOLLOWS = 'daymark_announce_follows';

	/**
	 * Option: keep Notes (Aside-format posts) off the blog's home page and
	 * out of its main feed.
	 */
	public const HIDE_NOTES_ON_HOME = 'daymark_hide_notes_on_home';

	/**
	 * Option: show the app's Timeline as a compact list (title and
	 * thumbnail only) instead of full cards.
	 */
	public const COMPACT_TIMELINE = 'daymark_compact_timeline';

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
	 * Option: show the app's text in the Bionic Reading font.
	 */
	public const BIONIC_READING_FONT = 'daymark_bionic_reading_font';

	/**
	 * Option: the address of the site's own licensed Bionic Reading web
	 * font file (.woff2 or .woff). Empty means "use the font if the
	 * reader's device has it installed".
	 */
	public const BIONIC_READING_FONT_URL = 'daymark_bionic_reading_font_url';

	/**
	 * Option: the Font Library family (wp_font_family post ID) to use for
	 * the Bionic Reading font. 0 means the newest matching family.
	 */
	public const BIONIC_READING_FONT_FAMILY = 'daymark_bionic_reading_font_family';

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
			self::TEXT_TO_SPEECH,
			self::POLL_INTERVAL,
			self::BLOGROLL_PUBLIC,
			self::HIDE_NOTES_ON_HOME,
			self::COMPACT_TIMELINE,
			self::APP_BADGE,
			self::DEFAULT_DESTINATIONS,
			self::DEFAULT_CATEGORIES,
			self::BIONIC_READING_FONT,
			self::BIONIC_READING_FONT_URL,
			self::BIONIC_READING_FONT_FAMILY,
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
	 * Whether the site owner turned on Read to me: a Listen button at the
	 * top of a post in the app's full post view. The button also needs a
	 * provider that can turn text into speech (Daymark_Speech::available()).
	 *
	 * @return bool
	 */
	public static function text_to_speech(): bool {
		/**
		 * Whether the app's full post view offers a Listen button.
		 *
		 * Defaults to the `daymark_text_to_speech` option (off by default,
		 * since each new recording sends the post's text to the AI provider
		 * and the provider may charge for it). The button still needs a
		 * provider that can turn text into speech.
		 *
		 * @since 0.21.0
		 *
		 * @param bool $enabled Defaults to the `daymark_text_to_speech` option.
		 */
		return (bool) apply_filters( 'daymark_text_to_speech', (bool) get_option( self::TEXT_TO_SPEECH, false ) );
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
	 * Whether following a site tells that site, with a follow Webmention
	 * from an unlisted page on this site (Daymark_Followers). On by
	 * default, so Daymark sites can show each other as followers.
	 *
	 * @return bool
	 */
	public static function announce_follows(): bool {
		/**
		 * Whether following a site sends it a follow Webmention.
		 *
		 * Defaults to the `daymark_announce_follows` option (Settings ->
		 * Daymark -> General, on by default).
		 *
		 * @param bool $announce Defaults to the `daymark_announce_follows` option.
		 */
		return (bool) apply_filters( 'daymark_announce_follows', (bool) get_option( self::ANNOUNCE_FOLLOWS, true ) );
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

	/**
	 * Whether the app shows the Timeline, Search, and Explore as a compact
	 * list: each post as its title and one small thumbnail. Off by default,
	 * so posts show as full cards.
	 *
	 * @return bool
	 */
	public static function compact_timeline(): bool {
		/**
		 * Whether the app lists posts compactly (title and thumbnail only)
		 * instead of as full cards.
		 *
		 * Defaults to the `daymark_compact_timeline` option (Settings ->
		 * Daymark -> General, off by default). Drafts and the full post
		 * view are unchanged either way.
		 *
		 * @since 0.21.0
		 *
		 * @param bool $compact Defaults to the `daymark_compact_timeline` option.
		 */
		return (bool) apply_filters( 'daymark_compact_timeline', (bool) get_option( self::COMPACT_TIMELINE, false ) );
	}

	/**
	 * Whether the app shows its text in the Bionic Reading font
	 * (https://bionic-reading.com/). Off by default.
	 *
	 * Daymark can't ship the font: it is a paid product whose license
	 * forbids passing it on. The app uses a "Bionic Reading" family from
	 * the site's Font Library when there is one, else the web font file
	 * address when one is set, else the font installed on the reader's
	 * device, else its usual system font (bionic_reading_font_faces()).
	 *
	 * @since 0.21.0
	 *
	 * @return bool
	 */
	public static function bionic_reading_font(): bool {
		/**
		 * Whether the Daymark app uses the Bionic Reading font.
		 *
		 * Defaults to the `daymark_bionic_reading_font` option (Settings ->
		 * Daymark -> General, off by default).
		 *
		 * @since 0.21.0
		 *
		 * @param bool $enabled Defaults to the `daymark_bionic_reading_font` option.
		 */
		return (bool) apply_filters( 'daymark_bionic_reading_font', (bool) get_option( self::BIONIC_READING_FONT, false ) );
	}

	/**
	 * The site's own Bionic Reading web font file, or '' for none.
	 *
	 * @since 0.21.0
	 *
	 * @return string
	 */
	public static function bionic_reading_font_url(): string {
		/**
		 * Filters the address of the Bionic Reading web font file.
		 *
		 * Defaults to the `daymark_bionic_reading_font_url` option. The
		 * value is checked again after the filter, so it must still be an
		 * http(s) address of a .woff2 or .woff file.
		 *
		 * @since 0.21.0
		 *
		 * @param string $url Defaults to the `daymark_bionic_reading_font_url` option.
		 */
		$url = apply_filters( 'daymark_bionic_reading_font_url', (string) get_option( self::BIONIC_READING_FONT_URL, '' ) );

		return self::sanitize_font_url( is_string( $url ) ? $url : '' );
	}

	/**
	 * An http(s) address of a font file, or ''.
	 *
	 * @since 0.21.0
	 *
	 * @param string $url        Address to check.
	 * @param bool   $any_format Also allow .ttf and .otf, which the Font
	 *                           Library accepts. The Bionic Reading Web
	 *                           license only covers .woff2 and .woff.
	 * @return string
	 */
	public static function sanitize_font_url( string $url, bool $any_format = false ): string {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );

		if ( '' === $url ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$pattern = $any_format ? '/\.(woff2?|ttf|otf)$/i' : '/\.woff2?$/i';

		return preg_match( $pattern, $path ) ? $url : '';
	}

	/**
	 * The Font Library families (Appearance -> Editor -> Typography) whose
	 * name contains "Bionic Reading", newest first.
	 *
	 * @since 0.21.0
	 *
	 * @return array<int, string> Family post ID => family name.
	 */
	public static function font_library_families(): array {
		$posts = get_posts(
			array(
				'post_type'   => 'wp_font_family',
				'post_status' => 'publish',
				'numberposts' => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$families = array();

		foreach ( $posts as $post ) {
			$name = (string) $post->post_title;

			if ( preg_match( '/bionic\s*-?\s*reading/i', $name . ' ' . $post->post_name ) ) {
				$families[ (int) $post->ID ] = $name;
			}
		}

		return $families;
	}

	/**
	 * The Font Library family the app uses: the one chosen on Settings ->
	 * Daymark when it still exists, else the newest "Bionic Reading"
	 * family, else 0 for none.
	 *
	 * @since 0.21.0
	 *
	 * @return int Family post ID.
	 */
	public static function bionic_reading_font_family(): int {
		$families = self::font_library_families();
		$chosen   = (int) get_option( self::BIONIC_READING_FONT_FAMILY, 0 );

		/**
		 * Filters which Font Library family Daymark uses for the Bionic
		 * Reading font. Return 0 to skip the Font Library.
		 *
		 * @since 0.21.0
		 *
		 * @param int             $family_id Chosen family post ID; 0 picks the newest match.
		 * @param array<int, string> $families Matching families, ID => name.
		 */
		$chosen = (int) apply_filters( 'daymark_bionic_reading_font_family', $chosen ? $chosen : (int) array_key_first( $families ), $families );

		return isset( $families[ $chosen ] ) ? $chosen : 0;
	}

	/**
	 * The font files the app loads for the Bionic Reading font, as
	 * FontFace sources. A Font Library family wins; otherwise the web font
	 * file address, if any. Empty when the setting is off or there's no
	 * file, in which case the app relies on a copy installed on the device.
	 *
	 * @since 0.21.0
	 *
	 * @return array<int, array{src: string, style: string, weight: string}>
	 */
	public static function bionic_reading_font_faces(): array {
		if ( ! self::bionic_reading_font() ) {
			return array();
		}

		$family = self::bionic_reading_font_family();

		if ( $family ) {
			$faces = self::font_library_faces( $family );

			if ( $faces ) {
				return $faces;
			}
		}

		$url = self::bionic_reading_font_url();

		return '' === $url ? array() : array(
			array(
				'src'    => $url,
				'style'  => 'normal',
				'weight' => '400',
			),
		);
	}

	/**
	 * A Font Library family's faces, read from its wp_font_face posts. Each
	 * face's settings are JSON in post_content (fontStyle, fontWeight, src).
	 *
	 * @param int $family_id Family post ID.
	 * @return array<int, array{src: string, style: string, weight: string}>
	 */
	private static function font_library_faces( int $family_id ): array {
		$posts = get_posts(
			array(
				'post_type'   => 'wp_font_face',
				'post_status' => 'publish',
				'post_parent' => $family_id,
				'numberposts' => 50,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		$faces = array();

		foreach ( $posts as $post ) {
			$settings = json_decode( (string) $post->post_content, true );

			if ( ! is_array( $settings ) ) {
				continue;
			}

			$sources = isset( $settings['src'] ) ? (array) $settings['src'] : array();
			$src     = '';

			foreach ( $sources as $candidate ) {
				$src = is_string( $candidate ) ? self::sanitize_font_url( $candidate, true ) : '';

				if ( '' !== $src ) {
					break;
				}
			}

			if ( '' === $src ) {
				continue;
			}

			$style  = isset( $settings['fontStyle'] ) && in_array( $settings['fontStyle'], array( 'normal', 'italic', 'oblique' ), true ) ? $settings['fontStyle'] : 'normal';
			$weight = isset( $settings['fontWeight'] ) && preg_match( '/^\d{1,4}(\s+\d{1,4})?$|^(normal|bold)$/', (string) $settings['fontWeight'] ) ? (string) $settings['fontWeight'] : '400';

			$faces[] = array(
				'src'    => $src,
				'style'  => $style,
				'weight' => $weight,
			);
		}

		return $faces;
	}

	/**
	 * The origins the app shell's font-src must allow for the Bionic
	 * Reading font files. Files on this site's own origin need none.
	 *
	 * @since 0.21.0
	 *
	 * @return string[] Origins, e.g. "https://cdn.example.com".
	 */
	public static function bionic_reading_font_origins(): array {
		$self    = self::origin_of( home_url() );
		$origins = array();

		foreach ( self::bionic_reading_font_faces() as $face ) {
			$origin = self::origin_of( $face['src'] );

			if ( '' !== $origin && $origin !== $self ) {
				$origins[] = $origin;
			}
		}

		return array_values( array_unique( $origins ) );
	}

	/**
	 * A URL's scheme://host[:port], lowercased, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
