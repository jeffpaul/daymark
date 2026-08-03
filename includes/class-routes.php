<?php
/**
 * Front-end route handling for the Daymark app shell.
 *
 * Route strategy (committed): rewrite rules mapping /daymark and
 * /daymark/notifications to the `daymark_app` query var, with a
 * template_include filter that loads templates/app-shell.php.
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
	 * when existing site content already owns /daymark).
	 */
	public const OPTION_APP_BASE = 'daymark_app_base';

	/**
	 * Allowed screens for the daymark_app query var.
	 *
	 * @var string[]
	 */
	private const SCREENS = array( 'home', 'notifications' );

	/**
	 * Register rewrite rules and hooks. Called on init.
	 *
	 * @return void
	 */
	public function register(): void {
		$base_was_unresolved = '' === (string) get_option( self::OPTION_APP_BASE, '' );
		$base                = self::app_base();

		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . self::QUERY_VAR . '=home', 'top' );
		add_rewrite_rule( '^' . $base . '/notifications/?$', 'index.php?' . self::QUERY_VAR . '=notifications', 'top' );
		add_rewrite_rule( '^' . $base . '/manifest\.json$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'template_include', array( $this, 'maybe_load_app_shell' ) );
		add_filter( 'redirect_canonical', array( $this, 'skip_canonical_for_manifest' ) );

		// Installs that predate the option just resolved it: persist the
		// rules registered above so the app URL works without a manual
		// permalink flush.
		if ( $base_was_unresolved ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * The app's base path. Resolves and persists on first use.
	 *
	 * @return string 'daymark', or 'daymark-app' when /daymark is owned by
	 *                existing site content.
	 */
	public static function app_base(): string {
		$base = get_option( self::OPTION_APP_BASE, '' );

		if ( is_string( $base ) && '' !== $base ) {
			return $base;
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
		$taken = get_page_by_path( 'daymark', OBJECT, array( 'page', 'post' ) ) instanceof WP_Post;
		$base  = $taken ? 'daymark-app' : 'daymark';

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
			'theme_color'      => '#7a00df',
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
