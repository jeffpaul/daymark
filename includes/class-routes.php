<?php
/**
 * Front-end route handling for the Daymark app shell.
 *
 * WpApp owns all URL matching and template dispatch, including the custom
 * offline endpoints. Daymark supplies their responses.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Daymark screens and endpoints within one WpApp namespace.
 */
class Daymark_Routes {

	/** Persisted app path, chosen to avoid existing site content. */
	public const OPTION_APP_BASE = 'daymark_app_base';

	/**
	 * Allowed screen paths within the WP App.
	 *
	 * Explore/Search/Me are real server routes (not just client-side hash
	 * states) so a direct link or a browser refresh lands correctly and
	 * WordPress's own permalink handling applies — the same reason
	 * 'notifications' already worked this way. Create/Publish/Success stay
	 * pure client-side states layered on top of 'home', same as before.
	 * Search is part of the Explore screen since issue #269; 'search' stays
	 * a route so saved links keep working, and the app opens Explore for it.
	 *
	 * @var string[]
	 */
	private const SCREENS = array( 'home', 'notifications', 'explore', 'search', 'me' );

	/**
	 * Shared WP App instance, including its offline shell and extensible worker.
	 *
	 * @var \WpApp\WpApp|null
	 */
	private $app = null;

	/**
	 * Register with WP Apps before init so its router and launchers are ready.
	 *
	 * @return void
	 */
	public function register_app(): void {
		if ( null !== $this->app ) {
			return;
		}

		$this->app = new \WpApp\WpApp(
			DAYMARK_PLUGIN_DIR . 'templates',
			self::app_base(),
			array(
				'app_name'           => 'Daymark',
				'app_icon'           => DAYMARK_PLUGIN_URL . 'assets/icon-192.png',
				// The manifest is public; screens require edit_posts per route.
				'require_login'      => false,
				'admin_bar_app_link' => true,
			)
		);
		$this->app->init();
		foreach ( self::SCREENS as $screen ) {
			$this->app->route( 'home' === $screen ? '' : $screen, 'app-shell.php', array(), 'edit_posts' );
		}
		$this->app->add_menu_item( 'timeline', __( 'Timeline', 'daymark' ), self::app_url() );
		$this->app->add_menu_item( 'explore', __( 'Explore', 'daymark' ), self::app_url( 'explore' ) );
		$this->app->add_menu_item( 'notifications', __( 'Notifications', 'daymark' ), self::app_url( 'notifications' ) );
		$this->app->add_menu_item( 'me', __( 'Me', 'daymark' ), self::app_url( 'me' ) );
		$this->app->add_menu_item( 'create', __( 'New Mark', 'daymark' ), self::app_url() . '#create' );

		foreach ( array(
			'manifest'    => 'app-manifest.php',
			'config'      => 'app-config.php',
			'worker'      => 'app-worker.php',
			'offline'     => 'app-offline.php',
			'share'       => 'app-share.php',
			'bookmarklet' => 'app-bookmarklet.php',
		) as $endpoint => $template ) {
			$this->app->route( $endpoint, $template );
		}
		// Launchers advertise the app to authors; the manifest stays public.
		\WpApp\Registry::register_app_capability( self::app_base(), 'edit_posts' );
		add_filter( 'redirect_canonical', array( $this, 'skip_canonical_for_app_assets' ) );
		add_action( 'template_redirect', array( $this, 'register_offline_shell' ), 1 );
	}

	/** Configure the framework fallback after authentication and connector registration. */
	public function register_offline_shell(): void {
		if ( ! $this->is_app_request() || ! current_user_can( 'edit_posts' ) || ! in_array( $this->request_path(), array_merge( array( '', 'offline', 'worker' ), self::SCREENS ), true ) ) {
			return;
		}
		$this->app->enable_pwa(
			array(
				'name'                => 'Daymark',
				'cache_policy'        => 'shell',
				'offline_url'         => self::app_url( 'offline' ),
				'head_tags'           => false,
				'service_worker_path' => 'worker',
				'cache_prefix'        => 'wp-app-' . self::app_base() . '-',
				'cache_name'          => 'wp-app-' . self::app_base() . '-' . self::service_worker_cache_version(),
				'cache_message_type'  => 'daymark-refresh-offline-shell',
				'precache'            => self::offline_asset_urls(),
				'worker_scripts'      => array( DAYMARK_PLUGIN_DIR . 'assets/push-worker.js' ),
				'worker_data'         => array( 'restUrl' => esc_url_raw( rest_url( 'daymark/v1/' ) ) ),
				'offline_shell'       => array(
					'content'       => DAYMARK_PLUGIN_DIR . 'templates/offline-content.php',
					'body_class'    => 'daymark-app daymark-app--home',
					'capability'    => 'edit_posts',
					'bootstrap_url' => self::app_url( 'config' ),
					'offline_data'  => self::build_offline_config(),
					'csp'           => self::offline_content_security_policy(),
				),
			)
		);
		// Only the fallback uses these assets; the live shell keeps its integrations.
		if ( 'offline' !== $this->request_path() ) {
			return;
		}
		$scope = self::app_base();
		foreach ( array( 'app', 'featured-content' ) as $asset ) {
			wp_app_enqueue_style( 'daymark-offline-' . $asset, DAYMARK_PLUGIN_URL . 'assets/' . $asset . '.css', array(), DAYMARK_VERSION, $scope );
		}
		foreach ( self::offline_core_script_urls() as $index => $url ) {
			wp_app_enqueue_script( 'daymark-offline-core-' . $index, $url, array(), null, true, $scope );
		}
		foreach ( array( 'featured-content', 'note-length', 'offline-boot' ) as $asset ) {
			wp_app_enqueue_script( 'daymark-offline-' . $asset, DAYMARK_PLUGIN_URL . 'assets/' . $asset . '.js', array(), DAYMARK_VERSION, true, $scope );
		}
		add_action( wp_app_get_scoped_hook_name( 'wp_app_head_meta', $scope ), array( __CLASS__, 'offline_head_meta' ) );
	}

	/** Exact URLs used by the live and fallback documents; wp-app adds its runtime. */
	public static function offline_asset_urls(): array {
		$urls = array( DAYMARK_PLUGIN_URL . 'assets/app.js' );
		foreach ( array( 'app.css', 'app.js', 'offline-boot.js', 'featured-content.css', 'featured-content.js', 'note-length.js' ) as $file ) {
			$urls[] = add_query_arg( 'ver', DAYMARK_VERSION, DAYMARK_PLUGIN_URL . 'assets/' . $file );
		}
		return array_merge( $urls, self::offline_core_script_urls() );
	}

	/** Safe, deliberately selected preferences; newly added live fields stay out. */
	public static function build_offline_config(): array {
		$live                = self::build_app_config();
		$keys                = array(
			'restUrl',
			'assetsUrl',
			'appUrl',
			'siteUrl',
			'siteTitle',
			'dateFormat',
			'dateNames',
			'siteIconUrl',
			'daymarkIconUrl',
			'screen',
			'connectors',
			'defaults',
			'siteDefaults',
			'categories',
			'categoryDefaults',
			'siteCategoryDefaults',
			'titlePolicy',
			'defaultCategory',
			'heicUploads',
			'uploadExtensions',
			'imageEdit',
			'noteLength',
			'compactTimeline',
			'capture',
			'appFont',
			'canPublish',
			'searchFirstYear',
			'timelineLastSeen',
			'timelinePosition',
			'interactionHintsSeen',
		);
		$data                = array_intersect_key( $live, array_flip( $keys ) );
		$data['userId']      = (int) get_current_user_id();
		$data['currentUser'] = array_intersect_key( $live['currentUser'], array_flip( array( 'id', 'displayName', 'avatarUrl', 'profileEditUrl' ) ) );
		$data['localeData']  = self::app_locale_data();
		return $data;
	}

	/** Policy for the cacheable framework document. */
	public static function offline_content_security_policy(): string {
		return "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data: blob:; media-src 'self' https: blob:; connect-src 'self'; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'";
	}

	/** Cached metadata shared by the framework fallback and installed app. */
	public static function offline_head_meta(): void {
		require DAYMARK_PLUGIN_DIR . 'templates/offline-head.php';
	}

	/**
	 * Whether the framework resolved this request to this Daymark instance.
	 *
	 * @return bool
	 */
	public function is_app_request(): bool {
		return null !== $this->app && $this->app->is_app_request();
	}

	/**
	 * Normalized route path, or null outside a resolved Daymark request.
	 *
	 * @return string|null
	 */
	private function request_path(): ?string {
		if ( ! $this->is_app_request() ) {
			return null;
		}
		$path = $this->app->get_route_var( 'wp_app_request' );
		return is_string( $path ) ? trim( $path, '/' ) : null;
	}

	/**
	 * Whether existing content owns the proposed app path.
	 *
	 * @param string $slug Slug to check.
	 * @return bool
	 */
	private static function slug_is_taken( string $slug ): bool {
		return get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) ) instanceof WP_Post;
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
	 * Stable app path, resolved on first use without legacy URL aliases.
	 *
	 * @return string App base path.
	 */
	public static function app_base(): string {
		$base = get_option( self::OPTION_APP_BASE, '' );
		return is_string( $base ) && self::is_valid_base( $base ) ? $base : self::resolve_app_base();
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
			// shows no home-screen icon at all. Always Daymark's own icon,
			// never the site's Site Icon (issue #414) — a home-screen
			// install is an install of the Daymark app, not of the site
			// itself, matching the same "our own chrome, not the site's"
			// reasoning icon_url()'s own header-chrome carve-out already
			// established (see daymark_icon_url()'s docblock).
			// The "any" icons are the rounded tile on a transparent canvas,
			// right for a desktop install. The "maskable" ones are opaque and
			// full bleed, with the glyph inside the safe zone, so Android can
			// shape them like any other app icon instead of shrinking the
			// tile onto a white plate.
			'icons'            => array(
				self::icon_descriptor( 192 ),
				self::icon_descriptor( 512 ),
				self::icon_descriptor( 192, 'maskable' ),
				self::icon_descriptor( 512, 'maskable' ),
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
	 * An icon URL at (approximately) the given size: the site's own Site
	 * Icon when one is set, else Daymark's bundled icon. Used by the
	 * browser-tab favicon and a Timeline card's own-Mark leading icon — both
	 * represent this site's own identity. NOT used for the home-screen/PWA
	 * icon (apple-touch-icon, manifest icons) — see daymark_icon_url()'s own
	 * docblock for why those are scoped differently (issue #414).
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
	 * the app shell's own header/nav chrome (Daymark's brand identity, not
	 * the site's) and, since issue #414, for every home-screen/PWA icon
	 * (apple-touch-icon, the manifest's own icons/shortcuts) — a home-screen
	 * install is an install of the Daymark app, not of the site itself, so
	 * it should always look like Daymark regardless of the site's own Site
	 * Icon. See icon_url() for the Site-Icon-first resolution still used for
	 * the browser-tab favicon and a Timeline card's own-Mark leading icon.
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
	 * The iPhone/iPad home-screen icon (apple-touch-icon): Daymark's own
	 * icon, 180x180, square and fully opaque.
	 *
	 * Apple's iOS rounds the corners itself and wants an opaque image. The
	 * bundled icon-192.png is a rounded tile with transparent corners and
	 * margins, which iOS fills with black or, on newer versions, can drop
	 * entirely and show a blank tile instead. apple-touch-icon.png is the same artwork
	 * redrawn edge to edge by bin/build-app-icons.py.
	 *
	 * @return string
	 */
	public static function apple_touch_icon_url(): string {
		return self::versioned_asset_url( 'apple-touch-icon.png' );
	}

	/**
	 * Whether a request is for one of the iPhone's fallback home-screen icon
	 * addresses at the top of the site: /apple-touch-icon.png, with an
	 * optional size (-180x180) and an optional -precomposed.
	 *
	 * An iPhone reads a home-screen icon from the page's own apple-touch-icon
	 * link tag. With no page to read, it asks the top of the site for these
	 * file names. Firefox for iOS always leaves iOS with no page: its
	 * "Add to Home Screen" hands over an empty stand-in web view that holds
	 * only the address and the title (mozilla-mobile/firefox-ios issue
	 * 35900). So for Firefox these addresses are the only icon there is,
	 * whatever the app shell declares.
	 *
	 * Anchored at the top of the host because that is the only place iOS
	 * looks; a WordPress living in a sub-folder never receives the request.
	 *
	 * @param string $request_uri The request's path, with or without a query string.
	 * @return bool
	 */
	public static function is_root_touch_icon_request( string $request_uri ): bool {
		$path = explode( '?', $request_uri, 2 )[0];

		return 1 === preg_match( '#^/apple-touch-icon(?:-\d{1,4}x\d{1,4})?(?:-precomposed)?\.png$#D', $path );
	}

	/**
	 * The PNG file that answers the iPhone's fallback icon addresses, or ''
	 * to leave those addresses alone.
	 *
	 * Daymark's own opaque home-screen icon by default, never the Site Icon,
	 * for the same reason as the app shell's own icon (issue #414). These
	 * addresses stand for the whole site, though, so a site can keep them
	 * for itself.
	 *
	 * @return string Absolute path to a readable PNG, or ''.
	 */
	public static function root_touch_icon_file(): string {
		/**
		 * Filters the PNG file served at /apple-touch-icon.png (and its sized
		 * and -precomposed forms) at the top of the site.
		 *
		 * Return an empty string to have Daymark leave these addresses
		 * alone, or the absolute path of another square, opaque PNG to serve
		 * that instead. A real file at that address on the server always
		 * wins, since the request then never reaches WordPress.
		 *
		 * @param string $file Absolute path to Daymark's 180x180 home-screen icon.
		 */
		$file = apply_filters( 'daymark_root_touch_icon_file', DAYMARK_PLUGIN_DIR . 'assets/apple-touch-icon.png' );

		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		$type = wp_check_filetype( $file, array( 'png' => 'image/png' ) );

		return 'image/png' === $type['type'] ? $file : '';
	}

	/**
	 * What to answer a request with, when it is for one of the iPhone's
	 * fallback icon addresses: the file and its headers. Null for any other
	 * request, which WordPress then handles as usual.
	 *
	 * The file itself rather than a redirect to it, so nothing depends on
	 * the fetcher following one, and cached for a day rather than for good,
	 * because this address can't carry a version the way the app shell's own
	 * icon link does.
	 *
	 * @param string $method      The request method.
	 * @param string $request_uri The request's path, with or without a query string.
	 * @return array{file: string, headers: array<string, string>, send_body: bool}|null
	 */
	public static function root_touch_icon_response( string $method, string $request_uri ): ?array {
		$method = strtoupper( $method );

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! self::is_root_touch_icon_request( $request_uri ) ) {
			return null;
		}

		$file = self::root_touch_icon_file();

		if ( '' === $file ) {
			return null;
		}

		return array(
			'file'      => $file,
			'headers'   => array(
				'Content-Type'           => 'image/png',
				'Content-Length'         => (string) filesize( $file ),
				'Cache-Control'          => 'public, max-age=' . DAY_IN_SECONDS,
				'X-Content-Type-Options' => 'nosniff',
			),
			'send_body' => 'GET' === $method,
		);
	}

	/**
	 * Answer the iPhone's fallback icon addresses with Daymark's icon.
	 *
	 * Hooked at the very start of init (Daymark_Plugin::setup()), so it
	 * needs no rewrite rule or permalink flush, works with plain permalinks,
	 * and answers before WordPress builds a "not found" page for what looks
	 * like a missing file.
	 *
	 * @return void
	 */
	public function maybe_serve_root_touch_icon(): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared against a fixed list and a fixed pattern, never stored or printed.
		$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$response = self::root_touch_icon_response( $method, $request_uri );

		if ( null === $response ) {
			return;
		}

		status_header( 200 );

		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		if ( $response['send_body'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams one small, plugin-chosen local PNG; WP_Filesystem has no streaming equivalent.
			readfile( $response['file'] );
		}

		exit;
	}

	/**
	 * A manifest icon descriptor at the given size — always Daymark's own
	 * bundled icon (see build_manifest()'s own comment for why).
	 *
	 * @param int    $size    Square size in px.
	 * @param string $purpose 'any' or 'maskable'.
	 * @return array<string, string>
	 */
	private static function icon_descriptor( int $size, string $purpose = 'any' ): array {
		if ( 'maskable' === $purpose ) {
			$file = $size > 256 ? 'icon-maskable-512.png' : 'icon-maskable-192.png';
		} else {
			$file = basename( self::daymark_icon_url( $size ) );
		}

		return array(
			'src'     => self::versioned_asset_url( $file ),
			'sizes'   => $size . 'x' . $size,
			'type'    => 'image/png',
			'purpose' => $purpose,
		);
	}

	/**
	 * A bundled asset's URL with a short hash of its contents as `?ver=`.
	 *
	 * Phones keep a home-screen icon by its URL, so changing the artwork
	 * would otherwise never reach anyone who already added Daymark, and a
	 * phone that once got a bad response for an icon URL keeps showing it.
	 * Hashing the file (rather than using the plugin version) changes the
	 * URL only when the image itself changes, so Android doesn't re-check
	 * the installed app's icon on every release.
	 *
	 * @param string $file File name under assets/.
	 * @return string
	 */
	private static function versioned_asset_url( string $file ): string {
		static $versions = array();

		if ( ! isset( $versions[ $file ] ) ) {
			$hash              = md5_file( DAYMARK_PLUGIN_DIR . 'assets/' . $file );
			$versions[ $file ] = false === $hash ? DAYMARK_VERSION : substr( $hash, 0, 8 );
		}

		return add_query_arg( 'ver', $versions[ $file ], DAYMARK_PLUGIN_URL . 'assets/' . $file );
	}

	/**
	 * WordPress core scripts the offline-fallback shell loads so the app
	 * still has wp.i18n, and so still shows the site's language, offline.
	 * Plain external files with no inline code, which the offline shell's
	 * nonce-free `script-src 'self'` allows. Order matters: wp-i18n needs
	 * wp-hooks loaded first.
	 *
	 * @return string[] Absolute URLs, in load order.
	 */
	public static function offline_core_script_urls(): array {
		$scripts = wp_scripts();
		$urls    = array();

		foreach ( array( 'wp-hooks', 'wp-i18n' ) as $handle ) {
			$script = $scripts->registered[ $handle ] ?? null;

			if ( ! $script || ! is_string( $script->src ) || '' === $script->src ) {
				continue;
			}

			$src = $script->src;

			if ( ! preg_match( '#^(https?:)?//#', $src ) ) {
				$src = $scripts->base_url . $src;
			}

			$urls[] = $script->ver ? add_query_arg( 'ver', $script->ver, $src ) : $src;
		}

		return $urls;
	}

	/**
	 * The app's translations for the current user's language, in the shape
	 * wp.i18n.setLocaleData() takes. The online app shell gets the same data
	 * from wp_set_script_translations(), as an inline script. The offline
	 * shell can't run inline scripts, so config carries this instead
	 * and assets/offline-boot.js hands it to wp.i18n.
	 *
	 * @return array<string, mixed>|null Null when no translation file exists
	 *                                   (an English site, for example).
	 */
	public static function app_locale_data(): ?array {
		$was_registered = wp_script_is( 'daymark-app', 'registered' );
		if ( ! $was_registered ) {
			wp_register_script( 'daymark-app', DAYMARK_PLUGIN_URL . 'assets/app.js', array( 'wp-i18n' ), DAYMARK_VERSION, true );
		}

		$json = load_script_textdomain( 'daymark-app', 'daymark' );
		if ( ! $was_registered ) {
			// Preparing fallback data must not replace the live shell's dependencies.
			wp_deregister_script( 'daymark-app' );
		}

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$translations = json_decode( $json, true );
		$locale_data  = $translations['locale_data']['daymark'] ?? $translations['locale_data']['messages'] ?? null;

		if ( ! is_array( $locale_data ) || count( $locale_data ) < 2 ) {
			return null;
		}

		$locale_data['']['domain'] = 'daymark';

		return $locale_data;
	}

	/**
	 * A cache version for the service worker that changes whenever the
	 * plugin's cached assets do.
	 *
	 * The worker only re-installs (and so only re-downloads app.js, app.css,
	 * offline-boot.js, the Featured Content slider's two files, and the Note
	 * length count's note-length.js into a fresh cache) when the bytes of
	 * /daymark/worker change. wp-app serves exact URLs network-first and uses
	 * the precached copy offline. The plugin version covers a
	 * normal release; the assets' modification times also cover a development
	 * checkout, where the version constant does not move.
	 *
	 * @return string For example `0.17.0-1a2b3c4d`.
	 */
	public static function service_worker_cache_version(): string {
		$signature = '';

		foreach ( array( 'app.js', 'app.css', 'offline-boot.js', 'featured-content.js', 'featured-content.css', 'note-length.js' ) as $file ) {
			$path       = DAYMARK_PLUGIN_DIR . 'assets/' . $file;
			$signature .= '|' . ( is_readable( $path ) ? (int) filemtime( $path ) : 0 );
		}

		// The worker also caches core's wp-hooks and wp-i18n, so a core
		// update has to give it a fresh cache as well.
		$signature .= '|' . get_bloginfo( 'version' );
		foreach ( array( 'offline-content.php', 'offline-head.php' ) as $file ) {
			$signature .= '|' . filemtime( DAYMARK_PLUGIN_DIR . 'templates/' . $file );
		}

		return DAYMARK_VERSION . '-' . substr( md5( $signature ), 0, 8 );
	}

	/**
	 * The year of the site's oldest published post, for the years Search's
	 * "Pick a month" offers. This year when the site has no posts yet.
	 *
	 * @return int
	 */
	public static function first_post_year(): int {
		$oldest = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'orderby'          => 'date',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$year = empty( $oldest ) ? 0 : (int) get_the_date( 'Y', $oldest[0] );

		return $year > 0 ? $year : (int) wp_date( 'Y' );
	}

	/**
	 * Month and weekday names in the site's language, for the app's own
	 * Date Format rendering. Read from core's $wp_locale, which WordPress
	 * already translates, so Daymark's translators never repeat that work.
	 * Index 0 is January / Sunday, matching JavaScript's Date methods.
	 *
	 * @return array{months: string[], monthsShort: string[], weekdays: string[], weekdaysShort: string[]}
	 */
	public static function date_names(): array {
		global $wp_locale;

		$names = array(
			'months'        => array(),
			'monthsShort'   => array(),
			'weekdays'      => array(),
			'weekdaysShort' => array(),
		);

		if ( ! $wp_locale instanceof WP_Locale ) {
			return $names;
		}

		for ( $month = 1; $month <= 12; $month++ ) {
			$full                   = $wp_locale->get_month( $month );
			$names['months'][]      = $full;
			$names['monthsShort'][] = $wp_locale->get_month_abbrev( $full );
		}

		for ( $day = 0; $day <= 6; $day++ ) {
			$full                     = $wp_locale->get_weekday( $day );
			$names['weekdays'][]      = $full;
			$names['weekdaysShort'][] = $wp_locale->get_weekday_abbrev( $full );
		}

		return $names;
	}

	/**
	 * Builds the app shell's bootstrap config array — the exact same shape
	 * templates/app-shell.php inlines as `window.daymarkApp` for a normal
	 * online load, and GET /daymark/config (see the app-config.php endpoint)
	 * returns for the cold-offline-load path (issue #126). One source of
	 * truth for both so they can never drift apart.
	 *
	 * Always includes a fresh REST nonce — this method is only ever called
	 * for a live, per-request, already-authenticated response (the inline
	 * script, or a live config fetch); nothing here writes to durable
	 * storage. The framework fallback embeds only build_offline_config()'s
	 * explicitly selected safe fields. Live config is never cached.
	 *
	 * @param string $screen             Current app screen ('home', 'notifications', etc.).
	 * @param string $pending_type       One-shot composer type hint, or ''.
	 * @param int    $pending_draft_id   One-shot share-sheet draft ID, or 0.
	 * @param int    $pending_post_id    A followed site's post to open (a push from a favorite site), or 0.
	 * @param string $pending_follow_url A site to follow, from a follow link (Daymark_Follow_Link), or ''.
	 * @return array<string, mixed>
	 */
	public static function build_app_config( string $screen = 'home', string $pending_type = '', int $pending_draft_id = 0, int $pending_post_id = 0, string $pending_follow_url = '' ): array {
		$user                     = wp_get_current_user();
		$can_manage_subscriptions = current_user_can( Daymark_Admin_Subscriptions::CAPABILITY );
		$app_badge                = Daymark_Settings::app_badge_unread();

		/*
		 * Connector list and per-type destination defaults, from the
		 * Daymark_Syndication_Registry (the source of truth) — so real
		 * connector plugins registered via `daymark_register_connectors`
		 * appear here with their live connection status.
		 */
		$registry   = Daymark_Syndication_Registry::instance();
		$all_types  = array( 'note', 'image', 'gallery', 'video', 'audio', 'mixed', 'checkin' );
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
		$site_defaults = array();

		foreach ( $all_types as $type ) {
			// The user's remembered selection for the type (falling back to
			// the site defaults), limited to destinations actually offered.
			$type_defaults[ $type ] = array_values(
				array_intersect( $publisher->get_effective_defaults( $type ), $visible_ids )
			);
			// The site defaults alone, so the composer can tell when a
			// remembered choice differs from them and offer to switch back.
			$site_defaults[ $type ] = array_values(
				array_intersect( Daymark_Settings::destination_defaults( $type ), $visible_ids )
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
				// Plain text, like siteTitle below: WordPress saves an & in
				// a name as "&amp;", and the app escapes what it shows.
				'name'   => html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ),
				'parent' => (int) $cat->parent,
			);
		}

		$category_defaults      = array();
		$site_category_defaults = array();
		$category_ids           = array_column( $categories, 'id' );
		foreach ( $all_types as $type ) {
			$category_defaults[ $type ]      = $publisher->get_effective_categories( $type );
			$site_category_defaults[ $type ] = array_values(
				array_intersect( Daymark_Settings::category_defaults( $type ), $category_ids )
			);
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
			'userId'                 => (int) $user->ID,
			'restUrl'                => esc_url_raw( rest_url( 'daymark/v1/' ) ),
			'assetsUrl'              => esc_url_raw( DAYMARK_PLUGIN_URL . 'assets/' ),
			// Trailing-slash directory URL for the app's own base
			// (/daymark/, or /daymark-app/) — the service worker
			// registration scope (issue #126) needs a directory-shaped
			// URL, not app_url()'s own bare (no trailing slash) form.
			'appUrl'                 => esc_url_raw( self::app_url() . '/' ),
			'bookmarkletUrl'         => esc_url_raw( Daymark_Bookmarklet::popup_url() ),
			'nonce'                  => wp_create_nonce( 'wp_rest' ),
			'siteUrl'                => esc_url_raw( home_url( '/' ) ),
			// Plain text, as Mark titles are sent: WordPress saves the site
			// title HTML-encoded ("JP&#039;s Daymark"), and the app escapes
			// it wherever it shows it.
			'siteTitle'              => html_entity_decode( sanitize_text_field( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' ),
			// A raw PHP date() format string (Settings -> General -> Date
			// Format) — assets/app.js's formatDateWithPhpFormat() maps it
			// token-by-token onto a Timeline card's own absolute-date
			// display, so a card reads dates the same way the rest of
			// wp-admin already does rather than the browser's own locale
			// default.
			'dateFormat'             => sanitize_text_field( get_option( 'date_format' ) ),
			// Month and weekday names for that Date Format, from WordPress
			// core's own translations ($wp_locale), so they read exactly as
			// wp-admin's dates do in the site's language.
			'dateNames'              => self::date_names(),
			// Site Icon first, Daymark's own bundled icon otherwise — same
			// resolution icon_url() already uses for the browser favicon
			// and PWA manifest icons.
			'siteIconUrl'            => esc_url_raw( self::icon_url( 96 ) ),
			// Always Daymark's own bundled icon, never the site's Site Icon
			// — used for the app shell's own header/nav chrome.
			'daymarkIconUrl'         => esc_url_raw( self::daymark_icon_url( 96 ) ),
			'screen'                 => $screen,
			'connectors'             => $connectors,
			'defaults'               => $type_defaults,
			'siteDefaults'           => $site_defaults,
			'categories'             => $categories,
			'categoryDefaults'       => $category_defaults,
			'siteCategoryDefaults'   => $site_category_defaults,
			'titlePolicy'            => $title_policy,
			'defaultCategory'        => (int) get_option( 'default_category' ),
			// Whether the server can convert a HEIC/HEIF photo to JPEG
			// (#481); the composer refuses one at pick time when it can't.
			'heicUploads'            => Daymark_Publisher::accepts_heic(),
			// The file name extensions an upload can start with; the
			// composer refuses any other file when it's picked.
			'uploadExtensions'       => Daymark_Uploads::accepted_extensions(),
			// The photo types the photo sheet's crop and rotate work on
			// (those the server's image editor can change).
			'imageEdit'              => Daymark_Image_Edit::app_config(),
			// The Note length count under a Note's caption: the same limit
			// and Bluesky wording as the block editor's counter.
			'noteLength'             => Daymark_Note_Length::app_config(),
			'ai'                     => array(
				'available'     => $ai->is_available(),
				'providerLabel' => $ai->get_provider_label(),
				// Whether the composer may ask for tags and alt text on its
				// own; when false, AI only runs when an AI button is tapped.
				'autoSuggest'   => Daymark_Settings::ai_auto_suggest(),
			),
			// Read to me: whether the full post view offers a Listen button
			// (turned on in Settings and the AI provider can make speech).
			'speech'                 => array(
				'available' => Daymark_Speech::available(),
			),
			// Where a published Mark also reaches through an active
			// federation plugin, for the "Where this goes" sheet's "Your site is
			// the destination" note.
			'reach'                  => self::federation_reach(),
			// Whether lists show each post as just its title and thumbnail.
			'compactTimeline'        => Daymark_Settings::compact_timeline(),
			// Whether starting a Check In may ask for the device's location.
			'capture'                => array(
				'location' => Daymark_Settings::capture_location(),
			),
			// The font chosen from the site's Font Library (Settings ->
			// Daymark -> General). `family` is its CSS font-family list and
			// `faces` its font files; both are empty for Daymark's usual
			// font. A font with no files is a list of device fonts.
			'appFont'                => array(
				'family' => Daymark_Settings::app_font_stack(),
				'faces'  => Daymark_Settings::app_font_faces(),
			),
			'notifications'          => array(
				'hasUnread'   => Daymark_Plugin::instance()->notifications->has_unread(),
				// Whether to show the unread count on the installed app's
				// icon, and that count (only worked out when it's on).
				'badge'       => $app_badge,
				'unreadCount' => $app_badge ? Daymark_Plugin::instance()->notifications->unread_count() : 0,
			),
			// Web push: the site's public signing key (empty when the
			// bundled library can't load, which hides the switch), and
			// which kinds of push this person wants.
			'push'                   => array(
				'publicKey' => Daymark_Push::public_key(),
				'prefs'     => Daymark_Push::get_prefs( (int) $user->ID ),
			),
			'controllableHelpers'    => $controllable_helpers,
			'publishHelpers'         => $awareness_helpers,
			'currentUser'            => array(
				'id'             => (int) $user->ID,
				'displayName'    => html_entity_decode( $user->display_name, ENT_QUOTES, 'UTF-8' ),
				'avatarUrl'      => esc_url_raw( (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ) ),
				'profileEditUrl' => esc_url_raw( get_edit_profile_url( $user->ID ) ),
				'logoutUrl'      => esc_url_raw( wp_logout_url( self::app_url( 'me' ) ) ),
			),
			// Settings -> Daymark needs Daymark_Admin_Subscriptions::CAPABILITY, so
			// anyone without it gets no link (an empty string) rather than one
			// that lands on a permission error, and no in-app Unsubscribe.
			'adminSubscriptionsUrl'  => $can_manage_subscriptions ? esc_url_raw( Daymark_Admin_Subscriptions::tab_url( 'subscriptions' ) ) : '',
			'adminConnectorsUrl'     => $can_manage_subscriptions ? esc_url_raw( Daymark_Admin_Subscriptions::tab_url( 'connectors' ) ) : '',
			'canManageSubscriptions' => $can_manage_subscriptions,
			// False for someone who can't publish (a Contributor): the server
			// saves their Mark as a draft, and the app says so.
			'canPublish'             => current_user_can( 'publish_posts' ),
			'pluginsUrl'             => esc_url_raw( admin_url( 'plugins.php' ) ),
			// The year of the site's first post, so Search's "Pick a
			// month" offers every year there is something from.
			'searchFirstYear'        => self::first_post_year(),
			// The newest Timeline item this user has seen (or null), so
			// Home can open anchored on it with newer posts above — see
			// Daymark_Timeline_Position.
			'timelineLastSeen'       => Daymark_Timeline_Position::get( (int) $user->ID ),
			// The item at the top of this user's screen when they last
			// looked at Home (or null): where Home opens next time.
			'timelinePosition'       => Daymark_Timeline_Position::get_position( (int) $user->ID ),
			// Interaction-row hint keys this user has already seen on any
			// device — see Daymark_Interaction_Hints.
			'interactionHintsSeen'   => Daymark_Interaction_Hints::get_seen( (int) $user->ID ),
			'pendingDraftId'         => $pending_draft_id,
			// A site to open Follow a site with, from a follow link. Only
			// fills in the address: nothing is looked up or followed until
			// the person confirms.
			'pendingFollowUrl'       => Daymark_Follow_Link::read_url( $pending_follow_url ),
			'pendingPostId'          => $pending_post_id,
			'pendingType'            => in_array( $pending_type, array( 'image', 'video', 'audio', 'note' ), true ) ? $pending_type : '',
		);
	}

	/**
	 * Where a published Mark also goes through an active federation plugin
	 * (ActivityPub, ATmosphere), in display order, for the "Where this goes"
	 * sheet's "Your site is the destination" note. Each entry is [label, helper]:
	 * `helper` is the id of the per-Mark toggle the entry depends on, or ''
	 * when nothing in the app turns it off, so the note can follow the
	 * toggle.
	 *
	 * ATmosphere counts only while it would actually share: connected and
	 * auto-publishing, the same check that offers its toggle.
	 *
	 * @return array<int, array{label: string, helper: string}>
	 */
	private static function federation_reach(): array {
		$reach = array();

		if ( Daymark_Connections::activitypub_active() ) {
			$reach[] = array(
				'label'  => __( 'your fediverse followers', 'daymark' ),
				'helper' => '',
			);
		}

		if ( in_array( 'atmosphere', Daymark_Publish_Helpers::controllable_ids(), true ) ) {
			$reach[] = array(
				'label'  => __( 'Bluesky', 'daymark' ),
				'helper' => 'atmosphere',
			);
		}

		return $reach;
	}

	/**
	 * Serve machine-fetched endpoints directly, especially the worker script.
	 *
	 * @param string|false $redirect_url Proposed canonical redirect.
	 * @return string|false
	 */
	public function skip_canonical_for_app_assets( $redirect_url ) {
		if ( in_array( $this->request_path(), array( 'manifest', 'config', 'worker', 'offline' ), true ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Require authentication without redirecting machine-fetched endpoints.
	 *
	 * @return void
	 */
	public static function require_endpoint_access(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			status_header( 401 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'not_logged_in' ) );
			exit;
		}
	}

	/**
	 * Get the current Daymark app screen, if any.
	 *
	 * @return string A registered screen name, or '' when not on a Daymark screen.
	 */
	public function current_screen(): string {
		$screen = $this->request_path();
		if ( null === $screen ) {
			return '';
		}
		$screen = '' === $screen ? 'home' : $screen;

		if ( in_array( $screen, self::SCREENS, true ) ) {
			return $screen;
		}

		return '';
	}
}
