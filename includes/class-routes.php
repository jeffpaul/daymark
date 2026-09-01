<?php
/**
 * Front-end route handling for the Daymark app shell.
 *
 * Route strategy (committed): rewrite rules mapping /daymark and its
 * /notifications, /explore, /search, and /me sub-routes to the
 * `daymark_app` query var, with a template_include filter that loads
 * templates/app-shell.php.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the /daymark rewrite rules and routes to the app shell template.
 */
class Daymark_Routes {

	/**
	 * Query var carrying the requested Daymark app screen.
	 */
	public const QUERY_VAR = 'daymark_app';

	/**
	 * Option storing the resolved app base path ('daymark', or 'daymark-app'
	 * when existing site content already owns /daymark). Always one of
	 * those two values — a migrated install's old Moment-era base is never
	 * carried in here; see OPTION_LEGACY_APP_BASE.
	 */
	public const OPTION_APP_BASE = 'daymark_app_base';

	/**
	 * Option storing a migrated install's pre-rename base (e.g. 'moment'),
	 * if any, purely so that old URL can 301 to wherever the app lives now.
	 * Set once, by a one-time migration that has since been removed; never
	 * treated as a valid app base itself.
	 */
	public const OPTION_LEGACY_APP_BASE = 'daymark_legacy_app_base';

	/**
	 * Allowed screens for the daymark_app query var.
	 *
	 * Explore/Search/Me are real server routes (not just client-side hash
	 * states) so a direct link or a browser refresh lands correctly and
	 * WordPress's own permalink handling applies — the same reason
	 * 'notifications' already worked this way. Create/Publish/Success stay
	 * pure client-side states layered on top of 'home', same as before.
	 *
	 * @var string[]
	 */
	private const SCREENS = array( 'home', 'notifications', 'explore', 'search', 'me' );

	/**
	 * Register rewrite rules and hooks. Called on init.
	 *
	 * @return void
	 */
	public function register(): void {
		$stored_base          = (string) get_option( self::OPTION_APP_BASE, '' );
		$base_was_unresolved  = '' === $stored_base;
		$base_was_self_healed = '' !== $stored_base && ! self::is_valid_base( $stored_base );
		$base                 = self::app_base();

		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . self::QUERY_VAR . '=home', 'top' );
		add_rewrite_rule( '^' . $base . '/notifications/?$', 'index.php?' . self::QUERY_VAR . '=notifications', 'top' );
		add_rewrite_rule( '^' . $base . '/explore/?$', 'index.php?' . self::QUERY_VAR . '=explore', 'top' );
		add_rewrite_rule( '^' . $base . '/search/?$', 'index.php?' . self::QUERY_VAR . '=search', 'top' );
		add_rewrite_rule( '^' . $base . '/me/?$', 'index.php?' . self::QUERY_VAR . '=me', 'top' );
		add_rewrite_rule( '^' . $base . '/manifest\.json$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );

		// A bookmarked/indexed URL for a retired Images/Videos/Audio/Notes
		// section page (see Daymark_Plugin::migrate_content_type_pages())
		// redirects to Explore — the closest living equivalent to "browse by
		// media type" — rather than a bare 404. Skipped, like the legacy app
		// base redirect below, when the old slug is now owned by real site
		// content the migration correctly left untouched.
		foreach ( self::legacy_content_redirect_slugs() as $legacy_content_slug ) {
			add_rewrite_rule( '^' . $legacy_content_slug . '/?$', 'index.php?' . self::QUERY_VAR . '=redirect-explore', 'top' );
		}

		// A migrated install's old Moment-era URL (e.g. /moment) is not a
		// second home for the app — it 301s to wherever the app actually
		// lives now, so a stale bookmark or home-screen icon still lands
		// somewhere real instead of a 404. Skipped when that old slug is
		// now owned by real site content, so it's never rewritten out from
		// under it.
		$legacy_base    = self::legacy_app_base();
		$needs_redirect = '' !== $legacy_base && $legacy_base !== $base && ! self::slug_is_taken( $legacy_base );

		if ( $needs_redirect ) {
			add_rewrite_rule( '^' . $legacy_base . '/?$', 'index.php?' . self::QUERY_VAR . '=redirect-home', 'top' );
			add_rewrite_rule( '^' . $legacy_base . '/notifications/?$', 'index.php?' . self::QUERY_VAR . '=redirect-notifications', 'top' );
		}

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'template_include', array( $this, 'maybe_load_app_shell' ) );
		add_filter( 'redirect_canonical', array( $this, 'skip_canonical_for_manifest' ) );

		// Installs that predate the option just resolved it: persist the
		// rules registered above so the app URL works without a manual
		// permalink flush. Installs that migrated before this redirect
		// existed, or before app_base() started self-healing a stale
		// pre-rename value (see app_base()), need the same one-time flush,
		// so the new /daymark rule actually takes effect without a manual
		// permalink resave. An install upgrading into the Explore/Search/Me
		// routes (and any legacy content-page redirect) needs the same
		// one-time flush for the same reason — daymark_nav_routes_added
		// covers both, added together in the same release.
		if (
			$base_was_unresolved
			|| $base_was_self_healed
			|| ( $needs_redirect && ! get_option( 'daymark_redirect_rule_added' ) )
			|| ! get_option( 'daymark_nav_routes_added' )
		) {
			update_option( 'daymark_redirect_rule_added', 1 );
			update_option( 'daymark_nav_routes_added', 1 );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Slugs of retired content-type pages that still need a redirect to
	 * Explore — every daymark_legacy_content_pages entry not currently
	 * shadowing real site content.
	 *
	 * @return string[]
	 */
	private static function legacy_content_redirect_slugs(): array {
		$slugs = get_option( 'daymark_legacy_content_pages', array() );
		$slugs = is_array( $slugs ) ? $slugs : array();

		return array_values(
			array_filter(
				array_map( 'strval', array_keys( $slugs ) ),
				static function ( string $slug ): bool {
					return '' !== $slug && ! self::slug_is_taken( $slug );
				}
			)
		);
	}

	/**
	 * Whether the given slug is already owned by real site content (a page
	 * or post at that path) — used both to decide whether the app itself
	 * must step aside from /daymark, and whether a legacy-base redirect
	 * would shadow real content at the old slug.
	 *
	 * @param string $slug Slug to check.
	 * @return bool
	 */
	private static function slug_is_taken( string $slug ): bool {
		return get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) ) instanceof WP_Post;
	}

	/**
	 * A migrated install's pre-rename base (e.g. 'moment'), if any.
	 *
	 * @return string The legacy base, or '' when this install never had one.
	 */
	private static function legacy_app_base(): string {
		$legacy = get_option( self::OPTION_LEGACY_APP_BASE, '' );

		return is_string( $legacy ) ? $legacy : '';
	}

	/**
	 * Whether a base value is one this code would actually persist itself.
	 *
	 * @param string $base Base value to check.
	 * @return bool
	 */
	private static function is_valid_base( string $base ): bool {
		return 'daymark' === $base || 'daymark-app' === $base;
	}

	/**
	 * The app's base path. Resolves and persists on first use.
	 *
	 * Self-heals a stale pre-rename value: an install that migrated before
	 * an earlier version of the one-time Moment-to-Daymark migration stopped
	 * carrying the old Moment-era base into this option has it permanently
	 * stuck at e.g. 'moment' otherwise — this option is deliberately never
	 * re-resolved once set, so nothing else would ever correct it. The old
	 * value is captured into OPTION_LEGACY_APP_BASE first, same as that
	 * migration did, so register()'s redirect still 301s it to wherever the
	 * app actually resolves to now.
	 *
	 * @return string 'daymark', or 'daymark-app' when /daymark is owned by
	 *                existing site content.
	 */
	public static function app_base(): string {
		$base = get_option( self::OPTION_APP_BASE, '' );

		if ( is_string( $base ) && '' !== $base ) {
			if ( self::is_valid_base( $base ) ) {
				return $base;
			}

			if ( false === get_option( self::OPTION_LEGACY_APP_BASE, false ) ) {
				update_option( self::OPTION_LEGACY_APP_BASE, $base );
			}
		}

		return self::resolve_app_base();
	}

	/**
	 * Resolve which base path the app may claim, and persist it.
	 *
	 * The route is a top rewrite rule, which would silently shadow a page
	 * or post already living at /daymark — so when such content exists the
	 * app steps aside to /daymark-app. Resolved at activation (and lazily
	 * for older installs), then kept stable: a home-screen-installed app
	 * URL should not move underneath its users.
	 *
	 * @return string The resolved base.
	 */
	public static function resolve_app_base(): string {
		$base = self::slug_is_taken( 'daymark' ) ? 'daymark-app' : 'daymark';

		update_option( self::OPTION_APP_BASE, $base );

		return $base;
	}

	/**
	 * Absolute URL into the Mark app.
	 *
	 * @param string $path Optional path within the app (e.g. 'notifications').
	 * @return string
	 */
	public static function app_url( string $path = '' ): string {
		$url = '/' . self::app_base();

		if ( '' !== $path ) {
			$url .= '/' . ltrim( $path, '/' );
		}

		return home_url( $url );
	}

	/**
	 * The PWA manifest, built against the resolved app base so
	 * home-screen installs open the right URL wherever the app lives.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_manifest(): array {
		return array(
			'name'             => 'Daymark',
			'short_name'       => 'Daymark',
			'start_url'        => self::app_url(),
			'scope'            => self::app_url(),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#c93a06',
			// PNG icons only — iOS chokes on an SVG "any" entry and then
			// shows no home-screen icon at all. The site's own Site Icon is
			// preferred when set, so the installed app matches the site.
			'icons'            => array(
				self::icon_descriptor( 192 ),
				self::icon_descriptor( 512 ),
			),
		);
	}

	/**
	 * A home-screen/app icon URL at (approximately) the given size: the
	 * site's own Site Icon when one is set, else Daymark's bundled icon.
	 *
	 * @param int $size Desired square size in px.
	 * @return string
	 */
	public static function icon_url( int $size ): string {
		if ( has_site_icon() ) {
			$url = get_site_icon_url( $size );
			if ( $url ) {
				return $url;
			}
		}

		$file = 'icon-192.png';
		if ( $size > 256 ) {
			$file = 'icon-512.png';
		} elseif ( $size <= 32 ) {
			$file = 'icon-32.png';
		}

		return DAYMARK_PLUGIN_URL . 'assets/' . $file;
	}

	/**
	 * A manifest icon descriptor at the given size.
	 *
	 * @param int $size Square size in px.
	 * @return array<string, string>
	 */
	private static function icon_descriptor( int $size ): array {
		$url        = self::icon_url( $size );
		$descriptor = array(
			'src'   => $url,
			'sizes' => $size . 'x' . $size,
		);

		// Only claim a type we're sure of (bundled PNGs, or a .png Site Icon).
		if ( str_ends_with( strtok( $url, '?' ), '.png' ) ) {
			$descriptor['type'] = 'image/png';
		}

		return $descriptor;
	}

	/**
	 * Register the daymark_app query var.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Serve the manifest without the canonical trailing-slash 301.
	 *
	 * WordPress's redirect_canonical would bounce {base}/manifest.json to
	 * a slash-suffixed URL before the JSON is served — one wasted hop on
	 * every PWA manifest fetch.
	 *
	 * @param string|false $redirect_url The canonical redirect target.
	 * @return string|false False cancels the redirect for manifest requests.
	 */
	public function skip_canonical_for_manifest( $redirect_url ) {
		if ( 'manifest' === get_query_var( self::QUERY_VAR ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Load the Daymark app shell template when daymark_app is set.
	 *
	 * @param string $template The template WordPress resolved.
	 * @return string
	 */
	public function maybe_load_app_shell( string $template ): string {
		$screen = get_query_var( self::QUERY_VAR );

		if ( ! is_string( $screen ) || '' === $screen ) {
			return $template;
		}

		// The manifest is plain JSON served on the app base (its start_url
		// must track wherever the base resolved to), not an app screen.
		if ( 'manifest' === $screen ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( self::build_manifest() );
			exit;
		}

		// /daymark on a migrated install (base still the legacy value):
		// send visitors to wherever the app actually lives, rather than a
		// hard 404 on the new brand's own URL. A retired Images/Videos/
		// Audio/Notes section page's old slug lands on Explore instead.
		if ( 'redirect-home' === $screen || 'redirect-notifications' === $screen || 'redirect-explore' === $screen ) {
			if ( 'redirect-notifications' === $screen ) {
				$target = self::app_url( 'notifications' );
			} elseif ( 'redirect-explore' === $screen ) {
				$target = self::app_url( 'explore' );
			} else {
				$target = self::app_url();
			}
			wp_safe_redirect( $target, 301 );
			exit;
		}

		if ( ! in_array( $screen, self::SCREENS, true ) ) {
			return $template;
		}

		$app_shell = DAYMARK_PLUGIN_DIR . 'templates/app-shell.php';

		if ( is_readable( $app_shell ) ) {
			return $app_shell;
		}

		return $template;
	}

	/**
	 * Get the current Daymark app screen, if any.
	 *
	 * @return string One of 'home', 'notifications', or '' when not in the app.
	 */
	public function current_screen(): string {
		$screen = get_query_var( self::QUERY_VAR );

		if ( is_string( $screen ) && in_array( $screen, self::SCREENS, true ) ) {
			return $screen;
		}

		return '';
	}
}
