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
		add_rewrite_rule( '^' . $base . '/share/?$', 'index.php?' . self::QUERY_VAR . '=share', 'top' );
		// Cold-offline-load support (issue #126) — see maybe_load_app_shell()
		// for what each of these three actually serves.
		add_rewrite_rule( '^' . $base . '/config\.json$', 'index.php?' . self::QUERY_VAR . '=config', 'top' );
		add_rewrite_rule( '^' . $base . '/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^' . $base . '/offline\.html$', 'index.php?' . self::QUERY_VAR . '=offline', 'top' );

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
		add_filter( 'redirect_canonical', array( $this, 'skip_canonical_for_app_assets' ) );

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
			// Camera-first: a long-press on the installed home-screen icon
			// jumps straight to the composer (CreateScreen renders fine cold
			// — no prior Home visit or app state required), skipping Home and
			// the +New launcher tap entirely for the "standing somewhere,
			// want to publish right now" case.
			'shortcuts'        => array(
				array(
					'name'        => __( 'New Mark', 'daymark' ),
					'short_name'  => __( 'New Mark', 'daymark' ),
					'description' => __( 'Jump straight to the composer.', 'daymark' ),
					'url'         => self::app_url() . '#create',
					'icons'       => array( self::icon_descriptor( 192 ) ),
				),
			),
			// "Share -> Daymark" from the OS share sheet — the installed
			// app's own /share route (Daymark_Share_Target) receives the
			// POST and creates a draft. `media[]` (not `media`) so PHP
			// transposes more than one shared file into an array the same
			// way the composer's own files[] upload field already does.
			'share_target'     => array(
				'action'  => self::app_url( 'share' ),
				'method'  => 'POST',
				'enctype' => 'multipart/form-data',
				'params'  => array(
					'title' => 'title',
					'text'  => 'text',
					'url'   => 'url',
					'files' => array(
						array(
							'name'   => 'media[]',
							'accept' => array( 'image/*', 'video/*', 'audio/*' ),
						),
					),
				),
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

		return self::daymark_icon_url( $size );
	}

	/**
	 * Daymark's own bundled icon URL at (approximately) the given size —
	 * never the site's own Site Icon, even when one is configured. Used for
	 * the app shell's own header/nav chrome, which is Daymark's brand
	 * identity, not the site's — see icon_url() for the Site-Icon-first
	 * resolution used everywhere else (Timeline card site icons, browser
	 * favicon, PWA manifest icons).
	 *
	 * @param int $size Desired square size in px.
	 * @return string
	 */
	public static function daymark_icon_url( int $size ): string {
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
	 * Builds the app shell's bootstrap config array — the exact same shape
	 * templates/app-shell.php inlines as `window.daymarkApp` for a normal
	 * online load, and GET /daymark/config.json (see maybe_load_app_shell())
	 * returns for the cold-offline-load path (issue #126). One source of
	 * truth for both so they can never drift apart.
	 *
	 * Always includes a fresh REST nonce — this method is only ever called
	 * for a live, per-request, already-authenticated response (the inline
	 * script, or a live config.json fetch); nothing here writes to durable
	 * storage. It's the service worker's own job (assets/daymark-sw.js) to
	 * strip the nonce back out before persisting a copy to Cache Storage for
	 * offline reuse — see that file's own docblock.
	 *
	 * @param string $screen           Current app screen ('home', 'notifications', etc.).
	 * @param string $pending_type     One-shot composer type hint, or ''.
	 * @param int    $pending_draft_id One-shot share-sheet draft ID, or 0.
	 * @return array<string, mixed>
	 */
	public static function build_app_config( string $screen = 'home', string $pending_type = '', int $pending_draft_id = 0 ): array {
		$user = wp_get_current_user();

		/*
		 * Connector list and per-type destination defaults, from the
		 * Daymark_Syndication_Registry (the source of truth) — so real
		 * connector plugins registered via `daymark_register_connectors`
		 * appear here with their live connection status.
		 */
		$registry   = Daymark_Syndication_Registry::instance();
		$all_types  = array( 'note', 'image', 'gallery', 'video', 'audio', 'mixed' );
		$connectors = array();

		// Only genuinely connected networks (a real connector plugin with
		// credentials configured) are offered — a destination that cannot
		// actually publish or return replies is not shown. The site itself
		// is always the canonical destination either way.
		foreach ( $registry->get_connectors() as $connector ) {
			if ( ! $connector->is_connected() ) {
				continue;
			}

			$connectors[] = array(
				'id'           => $connector->get_id(),
				'label'        => $connector->get_label(),
				'connected'    => $connector->is_connected(),
				'status'       => $connector->is_connected() ? 'connected' : 'mocked',
				'status_label' => $connector->get_status_label(),
				'supports'     => array_values( array_filter( $all_types, array( $connector, 'supports_daymark_type' ) ) ),
			);
		}

		$visible_ids   = array_column( $connectors, 'id' );
		$publisher     = Daymark_Plugin::instance()->publisher;
		$type_defaults = array();

		foreach ( $all_types as $type ) {
			// The user's remembered selection for the type (falling back to
			// the model defaults), limited to destinations actually offered.
			$type_defaults[ $type ] = array_values(
				array_intersect( $publisher->get_effective_defaults( $type ), $visible_ids )
			);
		}

		// Site categories (the filing counterpart to destinations) and the
		// remembered per-type default categories. Flat list, name-ordered;
		// the app shows the picker only when there is a real choice beyond
		// the site's single default category.
		$categories = array();
		foreach ( get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		) as $cat ) {
			$categories[] = array(
				'id'     => (int) $cat->term_id,
				'name'   => $cat->name,
				'parent' => (int) $cat->parent,
			);
		}

		$category_defaults = array();
		foreach ( $all_types as $type ) {
			$category_defaults[ $type ] = $publisher->get_effective_categories( $type );
		}

		// Per-type policy for the composer's optional Title field.
		// Normalized to a strict 'optional' | 'hidden' map for every known
		// type so the app can look up any type without a missing-key gap (a
		// filter may return a partial map).
		$title_policy_all = Daymark_Publisher::title_field_policy();
		$title_policy     = array();
		foreach ( $all_types as $type ) {
			$title_policy[ $type ] = ( isset( $title_policy_all[ $type ] ) && 'optional' === $title_policy_all[ $type ] )
				? 'optional'
				: 'hidden';
		}

		$ai = Daymark_Plugin::instance()->ai_assist;

		// Controllable third-party helpers get a per-Mark toggle; the rest
		// of the detected publishing plugins stay awareness-only (Daymark
		// does not drive those).
		$controllable_helpers = Daymark_Publish_Helpers::controllable();
		$controllable_ids     = array_column( $controllable_helpers, 'id' );
		$awareness_helpers    = array_values(
			array_filter(
				Daymark_Publish_Helpers::detect(),
				static function ( $helper ) use ( $controllable_ids ) {
					return ! in_array( $helper['id'], $controllable_ids, true );
				}
			)
		);

		return array(
			'restUrl'               => esc_url_raw( rest_url( 'daymark/v1/' ) ),
			'assetsUrl'             => esc_url_raw( DAYMARK_PLUGIN_URL . 'assets/' ),
			// Trailing-slash directory URL for the app's own base
			// (/daymark/, or /daymark-app/) — the service worker
			// registration scope (issue #126) needs a directory-shaped
			// URL, not app_url()'s own bare (no trailing slash) form.
			'appUrl'                => esc_url_raw( self::app_url() . '/' ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'siteUrl'               => esc_url_raw( home_url( '/' ) ),
			'siteTitle'             => sanitize_text_field( get_bloginfo( 'name' ) ),
			// A raw PHP date() format string (Settings -> General -> Date
			// Format) — assets/app.js's formatDateWithPhpFormat() maps it
			// token-by-token onto a Timeline card's own absolute-date
			// display, so a card reads dates the same way the rest of
			// wp-admin already does rather than the browser's own locale
			// default.
			'dateFormat'            => sanitize_text_field( get_option( 'date_format' ) ),
			// Site Icon first, Daymark's own bundled icon otherwise — same
			// resolution icon_url() already uses for the browser favicon
			// and PWA manifest icons.
			'siteIconUrl'           => esc_url_raw( self::icon_url( 96 ) ),
			// Always Daymark's own bundled icon, never the site's Site Icon
			// — used for the app shell's own header/nav chrome.
			'daymarkIconUrl'        => esc_url_raw( self::daymark_icon_url( 96 ) ),
			'screen'                => $screen,
			'connectors'            => $connectors,
			'defaults'              => $type_defaults,
			'categories'            => $categories,
			'categoryDefaults'      => $category_defaults,
			'titlePolicy'           => $title_policy,
			'defaultCategory'       => (int) get_option( 'default_category' ),
			'ai'                    => array(
				'available'     => $ai->is_available(),
				'providerLabel' => $ai->get_provider_label(),
			),
			'notifications'         => array(
				'hasUnread' => Daymark_Plugin::instance()->notifications->has_unread(),
			),
			'controllableHelpers'   => $controllable_helpers,
			'publishHelpers'        => $awareness_helpers,
			'currentUser'           => array(
				'id'             => (int) $user->ID,
				'displayName'    => $user->display_name,
				'avatarUrl'      => esc_url_raw( (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ) ),
				'profileEditUrl' => esc_url_raw( get_edit_profile_url( $user->ID ) ),
				'logoutUrl'      => esc_url_raw( wp_logout_url( self::app_url( 'me' ) ) ),
			),
			'adminSubscriptionsUrl' => esc_url_raw( Daymark_Admin_Subscriptions::page_url() ),
			'pluginsUrl'            => esc_url_raw( admin_url( 'plugins.php' ) ),
			'pendingDraftId'        => $pending_draft_id,
			'pendingType'           => in_array( $pending_type, array( 'image', 'video', 'audio', 'note' ), true ) ? $pending_type : '',
		);
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
	 * Serve the manifest, config.json, sw.js, and offline.html without the
	 * canonical trailing-slash 301.
	 *
	 * WordPress's redirect_canonical would bounce a dotted {base}/foo.ext
	 * URL to a slash-suffixed one before the real content is served — one
	 * wasted hop on every fetch of any of these.
	 *
	 * @param string|false $redirect_url The canonical redirect target.
	 * @return string|false False cancels the redirect for these requests.
	 */
	public function skip_canonical_for_app_assets( $redirect_url ) {
		if ( in_array( get_query_var( self::QUERY_VAR ), array( 'manifest', 'config', 'sw', 'offline' ), true ) ) {
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

		// Cold-offline-load support (issue #126): config/sw/offline are the
		// three pieces the service worker (assets/daymark-sw.js) needs to
		// make a zero-connectivity /daymark load usable — none of them are
		// app screens either. Each requires the same edit_posts capability
		// every other Daymark surface does; an unauthenticated or
		// under-privileged request gets a plain 401 (never a login-page
		// redirect — these are fetched by JS/the browser's own SW machinery,
		// never navigated to directly by a person).
		if ( in_array( $screen, array( 'config', 'sw', 'offline' ), true ) ) {
			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				status_header( 401 );
				header( 'Content-Type: application/json; charset=utf-8' );
				echo wp_json_encode( array( 'error' => 'not_logged_in' ) );
				exit;
			}

			if ( 'config' === $screen ) {
				// GET /daymark/config.json — the offline-fallback shell's own
				// bootstrap fetch (assets/offline-boot.js). Always includes a
				// fresh, live nonce (see build_app_config()'s own docblock);
				// never itself cached at the HTTP layer — the service worker
				// is the one place a redacted (nonce-stripped) copy is
				// deliberately persisted, for offline reuse only.
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Cache-Control: no-store' );
				echo wp_json_encode( self::build_app_config() );
				exit;
			}

			if ( 'sw' === $screen ) {
				// GET /daymark/sw.js — the exact same worker script as
				// assets/daymark-sw.js, served at a URL under the app's own
				// base so its default registration scope (the directory of
				// its own script URL, per the Service Worker spec) already
				// covers every /daymark* route with no Service-Worker-Allowed
				// header needed. The one placeholder token gets the real
				// plugin assets URL substituted in, since app.css/app.js live
				// under a different directory than this URL does.
				$sw_path = DAYMARK_PLUGIN_DIR . 'assets/daymark-sw.js';
				$sw_js   = is_readable( $sw_path ) ? (string) file_get_contents( $sw_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading this plugin's own bundled static JS file off disk, not a remote fetch.
				$sw_js   = str_replace( '__DAYMARK_ASSETS_URL__', DAYMARK_PLUGIN_URL . 'assets/', $sw_js );
				header( 'Content-Type: application/javascript; charset=utf-8' );
				header( 'Cache-Control: no-store' );
				echo $sw_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static plugin-owned JS with one URL substitution, not user input.
				exit;
			}

			// 'offline' — templates/offline-shell.php, the static-shaped,
			// script-nonce-free fallback page the service worker's own
			// navigation handler serves when a real /daymark* fetch fails.
			$offline_shell = DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';

			if ( is_readable( $offline_shell ) ) {
				return $offline_shell;
			}

			return $template;
		}

		// The OS share sheet's POST target (see build_manifest()'s
		// share_target) — not an app screen either. Daymark_Share_Target
		// always exits (a redirect into the app, or a wp_die() on a real
		// failure), so nothing after this point ever runs for this screen.
		if ( 'share' === $screen ) {
			Daymark_Plugin::instance()->share_target->handle();
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
