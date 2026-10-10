<?php
/**
 * Core plugin loader.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton loader that wires up all Daymark components.
 */
final class Daymark_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Daymark_Plugin|null
	 */
	private static ?Daymark_Plugin $instance = null;

	/**
	 * Route handler.
	 *
	 * @var Daymark_Routes
	 */
	public Daymark_Routes $routes;

	/**
	 * REST controller.
	 *
	 * @var Daymark_REST_Controller
	 */
	public Daymark_REST_Controller $rest_controller;

	/**
	 * Daymark publisher.
	 *
	 * @var Daymark_Publisher
	 */
	public Daymark_Publisher $publisher;

	/**
	 * Syndication links (u-syndication markup on Mark posts).
	 *
	 * @var Daymark_Syndication_Links
	 */
	public Daymark_Syndication_Links $syndication_links;

	/**
	 * Hides a Like Mark's own auto-published post from the site's own
	 * front end, feed, REST API, and sitemap — only its own permalink
	 * stays reachable, for Webmention verification.
	 *
	 * @var Daymark_Like_Visibility
	 */
	public Daymark_Like_Visibility $like_visibility;

	/**
	 * Notes (Aside-format posts) on the site: home page and main feed
	 * visibility, and titles for untitled Notes.
	 *
	 * @var Daymark_Notes
	 */
	public Daymark_Notes $notes;

	/**
	 * Block editor counter for a Note's length.
	 *
	 * @var Daymark_Note_Length
	 */
	public Daymark_Note_Length $note_length;

	/**
	 * POSSE-quality outbound microformats2 markup (h-entry, h-card, rel=me).
	 *
	 * @var Daymark_Microformats
	 */
	public Daymark_Microformats $microformats;

	/**
	 * Automatic backflow sync (cron + on-view freshening).
	 *
	 * @var Daymark_Backflow_Sync
	 */
	public Daymark_Backflow_Sync $backflow_sync;

	/**
	 * AI Assist adapter.
	 *
	 * @var Daymark_AI_Assist
	 */
	public Daymark_AI_Assist $ai_assist;

	/**
	 * Syndication connector registry.
	 *
	 * @var Daymark_Syndication_Registry
	 */
	public Daymark_Syndication_Registry $syndication_registry;

	/**
	 * Notifications provider.
	 *
	 * @var Daymark_Notifications
	 */
	public Daymark_Notifications $notifications;

	/**
	 * Per-user read and archived Notifications state.
	 *
	 * @var Daymark_Notification_State
	 */
	public Daymark_Notification_State $notification_state;

	/**
	 * Read to me: cached audio versions of posts.
	 *
	 * @var Daymark_Speech
	 */
	public Daymark_Speech $speech;

	/**
	 * Per-user bookmark set membership.
	 *
	 * @var Daymark_Bookmarks
	 */
	public Daymark_Bookmarks $bookmarks;

	/**
	 * Chunked, resumable composer uploads (issue #483).
	 *
	 * @var Daymark_Uploads
	 */
	public Daymark_Uploads $uploads;

	/**
	 * Overlapping-IndieWeb-plugin detection + per-user dismissal.
	 *
	 * @var Daymark_Plugin_Overlap
	 */
	public Daymark_Plugin_Overlap $plugin_overlap;

	/**
	 * Per-user rate limiter for REST actions.
	 *
	 * @var Daymark_Rate_Limiter
	 */
	public Daymark_Rate_Limiter $rate_limiter;

	/**
	 * Jetpack-native Like/Comment fast path for a subscribed post whose
	 * origin is itself WordPress.com-hosted or Jetpack-connected (issue
	 * #391) — detection, per-user WordPress.com connection state, origin
	 * resolution, and the actual Like/Unlike/Comment calls.
	 *
	 * @var Daymark_Jetpack_Engagement
	 */
	public Daymark_Jetpack_Engagement $jetpack_engagement;

	/**
	 * Real ActivityPub Like/Announce/Undo for a subscribed post whose origin
	 * is a fediverse object, via the ActivityPub plugin's own outbox (issue
	 * #439).
	 *
	 * @var Daymark_ActivityPub_Engagement
	 */
	public Daymark_ActivityPub_Engagement $activitypub_engagement;

	/**
	 * "Featured Content" block-editor sidebar panel (issue #401) —
	 * audio/video/gallery/quote/link as a post's featured content, in place
	 * of (or alongside) a Featured Image.
	 *
	 * @var Daymark_Featured_Content
	 */
	public Daymark_Featured_Content $featured_content;

	/**
	 * Featured Content share image/description for oEmbed and Open Graph
	 * (issue #408).
	 *
	 * @var Daymark_Featured_Content_Social
	 */
	public Daymark_Featured_Content_Social $featured_content_social;

	/**
	 * Bridgy Fed routing for Like/Reblog Marks of fediverse and Bluesky
	 * posts (issue #441).
	 *
	 * @var Daymark_Bridgy_Fed
	 */
	public Daymark_Bridgy_Fed $bridgy_fed;

	/**
	 * Public blogroll (OPML file, head link, Blogroll block) and Links
	 * import.
	 *
	 * @var Daymark_Blogroll
	 */
	public Daymark_Blogroll $blogroll;

	/**
	 * The `daymark` field on `wp/v2/posts`, for Daymark sites that follow
	 * this one.
	 *
	 * @var Daymark_Post_Export
	 */
	public Daymark_Post_Export $post_export;

	/**
	 * Built-in Webmention sender (off while the Webmention plugin is active).
	 *
	 * @var Daymark_Webmention
	 */
	public Daymark_Webmention $webmention;

	/**
	 * Built-in Webmention receiver (off while the Webmention plugin is active).
	 *
	 * @var Daymark_Webmention_Receiver
	 */
	public Daymark_Webmention_Receiver $webmention_receiver;

	/**
	 * Subscription source registry (inbound mirror of the syndication
	 * registry).
	 *
	 * @var Daymark_Subscription_Source_Registry
	 */
	public Daymark_Subscription_Source_Registry $subscription_source_registry;

	/**
	 * `daymark_subscription_post` CPT registrar.
	 *
	 * @var Daymark_Subscription_Post_Type
	 */
	public Daymark_Subscription_Post_Type $subscription_post_type;

	/**
	 * CRUD for the `daymark_subscription` custom DB table.
	 *
	 * @var Daymark_Subscriptions
	 */
	public Daymark_Subscriptions $subscriptions;

	/**
	 * Subscription polling: ingest, click-through fetch, pruning, cron
	 * scheduling, and manual refresh.
	 *
	 * @var Daymark_Subscription_Poller
	 */
	public Daymark_Subscription_Poller $subscription_poller;

	/**
	 * Settings -> Daymark wp-admin screen: subscribe-by-URL form and
	 * subscription management (issue #78's deliberate exception to this
	 * plugin's "no wp-admin chrome" non-goal — see CLAUDE.md).
	 *
	 * @var Daymark_Admin_Subscriptions
	 */
	public Daymark_Admin_Subscriptions $admin_subscriptions;

	/**
	 * Web Share Target handler — lets Daymark appear in the OS share sheet
	 * ("Share -> Daymark" on iOS/Android), creating a draft from whatever
	 * was shared. Invoked directly by Daymark_Routes::maybe_load_app_shell()
	 * on a POST to the /share route; no hooks of its own to register.
	 *
	 * @var Daymark_Share_Target
	 */
	public Daymark_Share_Target $share_target;

	/**
	 * Bookmarklet popup: Reblog or Like the page you're reading on another
	 * site. Invoked directly by Daymark_Routes::maybe_load_app_shell() on
	 * the /bookmarklet route; no hooks of its own to register.
	 *
	 * @var Daymark_Bookmarklet
	 */
	public Daymark_Bookmarklet $bookmarklet;

	/**
	 * Admin bar shortcuts: "Open Daymark" under the site-name node, and
	 * "Daymark" (pre-set to an image Mark) under the "+New" menu.
	 *
	 * @var Daymark_Admin_Bar
	 */
	public Daymark_Admin_Bar $admin_bar;

	/**
	 * Welcome notice shown once after the first activation.
	 *
	 * @var Daymark_Admin_Welcome
	 */
	public Daymark_Admin_Welcome $admin_welcome;

	/**
	 * The post-format icon indicator on wp-admin's post list screens.
	 *
	 * @var Daymark_Admin_Post_Format_Icon
	 */
	public Daymark_Admin_Post_Format_Icon $admin_post_format_icon;

	/**
	 * The Reblogs column on wp-admin's Posts list.
	 *
	 * @var Daymark_Admin_Reblog_Column
	 */
	public Daymark_Admin_Reblog_Column $admin_reblog_column;

	/**
	 * WebSub (PubSubHubbub) subscribing — sends/renews a hub subscription
	 * after a poll finds one advertised. Invoked directly by
	 * Daymark_Subscription_Poller::poll_subscription(); no hooks of its own
	 * to register.
	 *
	 * @var Daymark_Websub_Subscriber
	 */
	public Daymark_Websub_Subscriber $websub_subscriber;

	/**
	 * WebSub (PubSubHubbub) callback endpoint — verifies a hub's
	 * subscription challenge and accepts its content-distribution pushes.
	 *
	 * @var Daymark_Websub_Endpoint
	 */
	public Daymark_Websub_Endpoint $websub_endpoint;

	/**
	 * The four content-type section pages a pre-Unreleased install may still
	 * have lying around: slug => the block/shortcode markup that identifies
	 * a page as Daymark-managed (never created for a fresh install — see
	 * migrate_content_type_pages()). Kept only to recognize and safely
	 * retire an existing install's pages; nothing creates pages at these
	 * slugs anymore. Timeline's equivalent map lived here too before it was
	 * retired the same way — see remove_public_timeline_page().
	 *
	 * @var array<string, string>
	 */
	private const CONTENT_TYPE_PAGES = array(
		'images' => 'daymark/images',
		'videos' => 'daymark/videos',
		'audio'  => 'daymark/audio',
		'notes'  => 'daymark/notes',
	);

	/**
	 * Get the singleton instance.
	 *
	 * @return Daymark_Plugin
	 */
	public static function instance(): Daymark_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Private constructor. Use instance().
	 */
	private function __construct() {}

	/**
	 * Instantiate components and register hooks.
	 *
	 * @return void
	 */
	private function setup(): void {
		$this->routes                       = new Daymark_Routes();
		$this->rest_controller              = new Daymark_REST_Controller();
		$this->publisher                    = new Daymark_Publisher();
		$this->ai_assist                    = new Daymark_AI_Assist();
		$this->syndication_registry         = Daymark_Syndication_Registry::instance();
		$this->notifications                = new Daymark_Notifications();
		$this->notification_state           = new Daymark_Notification_State();
		$this->bookmarks                    = new Daymark_Bookmarks();
		$this->speech                       = new Daymark_Speech();
		$this->uploads                      = new Daymark_Uploads();
		$this->plugin_overlap               = new Daymark_Plugin_Overlap();
		$this->syndication_links            = new Daymark_Syndication_Links();
		$this->like_visibility              = new Daymark_Like_Visibility();
		$this->notes                        = new Daymark_Notes();
		$this->note_length                  = new Daymark_Note_Length();
		$this->microformats                 = new Daymark_Microformats();
		$this->backflow_sync                = new Daymark_Backflow_Sync();
		$this->rate_limiter                 = new Daymark_Rate_Limiter();
		$this->subscription_source_registry = Daymark_Subscription_Source_Registry::instance();
		$this->subscription_post_type       = new Daymark_Subscription_Post_Type();
		$this->subscriptions                = new Daymark_Subscriptions();
		$this->subscription_poller          = new Daymark_Subscription_Poller();
		$this->admin_subscriptions          = new Daymark_Admin_Subscriptions();
		$this->share_target                 = new Daymark_Share_Target();
		$this->bookmarklet                  = new Daymark_Bookmarklet();
		$this->admin_bar                    = new Daymark_Admin_Bar();
		$this->admin_welcome                = new Daymark_Admin_Welcome();
		$this->admin_post_format_icon       = new Daymark_Admin_Post_Format_Icon();
		$this->admin_reblog_column          = new Daymark_Admin_Reblog_Column();
		$this->websub_subscriber            = new Daymark_Websub_Subscriber();
		$this->websub_endpoint              = new Daymark_Websub_Endpoint();
		$this->jetpack_engagement           = new Daymark_Jetpack_Engagement();
		$this->activitypub_engagement       = new Daymark_ActivityPub_Engagement();
		$this->featured_content             = new Daymark_Featured_Content();
		$this->featured_content_social      = new Daymark_Featured_Content_Social();
		$this->bridgy_fed                   = new Daymark_Bridgy_Fed();
		$this->blogroll                     = new Daymark_Blogroll();
		$this->post_export                  = new Daymark_Post_Export();
		$this->webmention                   = new Daymark_Webmention();
		$this->webmention_receiver          = new Daymark_Webmention_Receiver();

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		// Early, at priority 5: routes read daymark_legacy_content_pages (and
		// the now-fully-retired daymark_pages option) at the default
		// priority, so both migrations must run before that.
		add_action( 'init', array( __CLASS__, 'remove_public_timeline_page' ), 5 );
		add_action( 'init', array( __CLASS__, 'migrate_content_type_pages' ), 5 );
		// Early, at priority 5: the Webmention plugin builds its list of
		// post types to send from (get_post_types_by_support( 'webmentions' ))
		// on `init` at priority 10, so the Like post type must already be
		// registered by then — rather than relying on this plugin happening
		// to load before that one alphabetically.
		add_action( 'init', array( $this->like_visibility, 'register_post_type' ), 5 );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DAYMARK_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Add "Open Daymark" and "Settings" action links on the Plugins list
	 * table, so the app and the (now tabbed — Subscriptions, Connectors,
	 * Import/Export, Privacy; issue #86) settings screen are both one click
	 * away right after activation. Renamed from "Subscriptions" now that
	 * the target page covers more than subscription management alone —
	 * the settings screen's own default tab still lands on Subscriptions.
	 *
	 * @param array<string, string> $links Existing action links (Deactivate, …).
	 * @return array<string, string>
	 */
	public function add_action_links( array $links ): array {
		$open = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Daymark_Routes::app_url() ),
			esc_html__( 'Open Daymark', 'daymark' )
		);

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Daymark_Admin_Subscriptions::page_url() ),
			esc_html__( 'Settings', 'daymark' )
		);

		return array_merge(
			array(
				'open-daymark'     => $open,
				'daymark-settings' => $settings,
			),
			$links
		);
	}

	/**
	 * Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function on_plugins_loaded(): void {
		// Reserved for load-order-sensitive wiring (translations load automatically for WP >= 4.6).
	}

	/**
	 * Runs on init. Registers routes, blocks, and connectors.
	 *
	 * @return void
	 */
	public function on_init(): void {
		// Self-heal the subscriptions table: sites where the plugin was
		// already active when this feature arrived never ran activation.
		// install() is cheap to call every request (it checks the stored
		// schema version before touching dbDelta) — matching the same
		// self-heal pattern already used by Daymark_Backflow_Sync::schedule()
		// and Daymark_Subscription_Poller::schedule().
		Daymark_Subscriptions::install();

		$this->routes->register();
		$this->syndication_links->register();
		$this->like_visibility->register();
		$this->notes->register();
		$this->note_length->register();
		$this->microformats->register();
		$this->backflow_sync->register();
		$this->publisher->register();
		$this->bookmarks->register();
		$this->speech->register();
		$this->notification_state->register();
		$this->uploads->register();
		$this->subscription_post_type->register();
		$this->subscription_poller->register();
		$this->admin_subscriptions->register();
		$this->admin_bar->register();
		$this->admin_welcome->register();
		$this->admin_post_format_icon->register();
		$this->admin_reblog_column->register();
		$this->websub_endpoint->register();
		$this->websub_subscriber->register();
		$this->jetpack_engagement->register();
		$this->activitypub_engagement->register();
		$this->featured_content->register();
		$this->featured_content_social->register();
		$this->bridgy_fed->register();
		$this->blogroll->register();
		$this->post_export->register();
		$this->webmention->register();
		$this->webmention_receiver->register();
		// Bridge active third-party publishing plugins' control filters to
		// per-Mark selection (Share on Mastodon, Autoshare for Twitter).
		Daymark_Publish_Helpers::register_adapters();

		/**
		 * Fires after built-in Daymark connectors are registered.
		 *
		 * Third-party connector plugins, WordPress Connector plugins,
		 * or existing social publishing plugins can hook here to register
		 * their own Daymark_Syndication_Connector implementations via
		 * $registry->register_connector( $connector ).
		 *
		 * @param Daymark_Syndication_Registry $registry The connector registry.
		 */
		do_action( 'daymark_register_connectors', $this->syndication_registry );

		/**
		 * Fires so inbound subscription sources can register themselves.
		 *
		 * The inbound mirror of `daymark_register_connectors`, fired at the
		 * same point in the request lifecycle. A future built-in RSS/Atom
		 * feed source, a Friends `friend_post` adapter, an ActivityPub
		 * actor-post adapter, or any other source plugin can hook here to
		 * register a Daymark_Subscription_Source implementation via
		 * $registry->register_source( $source ), without modifying core.
		 *
		 * @param Daymark_Subscription_Source_Registry $registry The subscription source registry.
		 */
		do_action( 'daymark_register_subscription_sources', $this->subscription_source_registry );
	}

	/**
	 * Plugin activation callback.
	 *
	 * Registers rewrite rules, flushes rewrite rules, and stores activation
	 * flags. A fresh install creates no pages of its own anymore — Timeline,
	 * Explore, Search, and Me all live inside the authenticated app shell.
	 * Never deletes user content: the two migrations below only ever act on
	 * a page confidently identified as carrying Daymark's own generated
	 * markup, and the content-type migration trashes (never hard-deletes)
	 * what it finds — see migrate_content_type_pages().
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Run first, so daymark_pages/daymark_legacy_content_pages are
		// settled before the app base and rewrite rules resolve below.
		self::remove_public_timeline_page();
		self::migrate_content_type_pages();

		Daymark_Subscriptions::install();
		Daymark_Backflow_Sync::schedule();
		Daymark_Subscription_Poller::schedule();
		// Resolve the app base on first activation (respecting content at
		// /daymark); a base that is already persisted — including one the
		// migration carried over — is kept, because a home-screen-installed
		// app URL must never move. Then register rewrite rules so the flush
		// below picks them up.
		Daymark_Routes::app_base();
		$routes = new Daymark_Routes();
		$routes->register();

		flush_rewrite_rules();

		// Only a site's first-ever activation queues the welcome notice;
		// reactivating later doesn't bring it back.
		Daymark_Admin_Welcome::queue( false === get_option( 'daymark_activated', false ) );

		update_option( 'daymark_activated', time() );
		update_option( 'daymark_version', DAYMARK_VERSION );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Flushes rewrite rules only. Content, pages, and meta are preserved
	 * by design — Marks must remain standard WordPress content.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Daymark_Backflow_Sync::unschedule();
		Daymark_Subscription_Poller::unschedule();
		Daymark_Uploads::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * One-time cleanup: hard-deletes an existing install's public Timeline
	 * page (issue #78). A fresh install never creates this page (or any
	 * other section page — see CONTENT_TYPE_PAGES) — this only cleans up a
	 * page an earlier version already created.
	 *
	 * Hard-deleted rather than trashed, matching the "404, no redirect"
	 * intent: a trashed page still resolves for a logged-in editor, and
	 * Daymark's own non-goal here is that Timeline as an interleaved,
	 * multi-source view only exists in the authenticated app shell now,
	 * not as a second, differently-scoped public page under the same name.
	 *
	 * Only ever touches a page carrying Daymark's own generated markup
	 * (re-verified here) — never a page a site owner repurposed at that
	 * slug in the meantime.
	 *
	 * Self-terminating: once the 'timeline' key is gone from `daymark_pages`,
	 * every later call is a single array lookup on an already-loaded
	 * option, cheap enough to run unconditionally every request — the same
	 * assumption migrate_content_type_pages() makes for its own four keys.
	 *
	 * @return void
	 */
	public static function remove_public_timeline_page(): void {
		$map = get_option( 'daymark_pages', array() );

		if ( ! is_array( $map ) || ! isset( $map['timeline'] ) ) {
			return;
		}

		$page_id = absint( $map['timeline'] );

		if ( $page_id > 0 ) {
			$content = (string) get_post_field( 'post_content', $page_id );

			if ( str_contains( $content, '<!-- wp:daymark/timeline' ) || str_contains( $content, '[daymark_timeline' ) ) {
				wp_delete_post( $page_id, true );
			}
		}

		unset( $map['timeline'] );
		update_option( 'daymark_pages', $map );
	}

	/**
	 * One-time cleanup: retires an existing install's Images/Videos/Audio/
	 * Notes section pages (Unreleased — bottom nav rework). A fresh install
	 * never creates these, so this only ever finds something on an install
	 * that ran an earlier version.
	 *
	 * Unlike remove_public_timeline_page(), these are trashed rather than
	 * hard-deleted: WordPress's own trash-and-retention lifecycle gives a
	 * site owner a way back if the removal is unwelcome, which fits a
	 * conservative migration better than an irreversible delete. The old
	 * slug is recorded in daymark_legacy_content_pages so Daymark_Routes can
	 * 301 a bookmarked/indexed URL to Explore instead of leaving a bare 404.
	 *
	 * Only ever acts on a page confidently identified as Daymark-managed
	 * (carrying the view's own block or shortcode markup) — a site owner's
	 * own page that merely happens to occupy the same slug is never touched,
	 * matching remove_public_timeline_page()'s same guarantee.
	 *
	 * Self-terminating: once daymark_pages carries none of the four legacy
	 * keys, every later call is a single array lookup on an already-loaded
	 * option, the same assumption remove_public_timeline_page() makes.
	 *
	 * @return void
	 */
	public static function migrate_content_type_pages(): void {
		$map = get_option( 'daymark_pages', array() );
		$map = is_array( $map ) ? $map : array();

		$has_legacy_key = false;
		foreach ( self::CONTENT_TYPE_PAGES as $slug => $block ) {
			if ( isset( $map[ $slug ] ) ) {
				$has_legacy_key = true;
				break;
			}
		}

		if ( ! $has_legacy_key ) {
			return;
		}

		$legacy_slugs = get_option( 'daymark_legacy_content_pages', array() );
		$legacy_slugs = is_array( $legacy_slugs ) ? $legacy_slugs : array();

		foreach ( self::CONTENT_TYPE_PAGES as $slug => $block ) {
			if ( ! isset( $map[ $slug ] ) ) {
				continue;
			}

			$page_id = absint( $map[ $slug ] );
			$page    = $page_id > 0 ? get_post( $page_id ) : null;

			if ( $page instanceof WP_Post && 'trash' !== $page->post_status ) {
				$content = (string) $page->post_content;

				if ( str_contains( $content, '<!-- wp:' . $block ) || str_contains( $content, '[daymark_' . $slug ) ) {
					wp_trash_post( $page_id );
					$legacy_slugs[ $page->post_name ] = true;
				}
			}

			unset( $map[ $slug ] );
		}

		update_option( 'daymark_legacy_content_pages', $legacy_slugs );

		if ( empty( $map ) ) {
			delete_option( 'daymark_pages' );
		} else {
			update_option( 'daymark_pages', $map );
		}
	}
}
