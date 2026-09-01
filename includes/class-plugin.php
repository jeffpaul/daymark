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
	 * Per-user rate limiter for REST actions.
	 *
	 * @var Daymark_Rate_Limiter
	 */
	public Daymark_Rate_Limiter $rate_limiter;

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
		$this->syndication_links            = new Daymark_Syndication_Links();
		$this->microformats                 = new Daymark_Microformats();
		$this->backflow_sync                = new Daymark_Backflow_Sync();
		$this->rate_limiter                 = new Daymark_Rate_Limiter();
		$this->subscription_source_registry = Daymark_Subscription_Source_Registry::instance();
		$this->subscription_post_type       = new Daymark_Subscription_Post_Type();
		$this->subscriptions                = new Daymark_Subscriptions();
		$this->subscription_poller          = new Daymark_Subscription_Poller();
		$this->admin_subscriptions          = new Daymark_Admin_Subscriptions();

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		// Early, at priority 5: routes read daymark_legacy_content_pages (and
		// the now-fully-retired daymark_pages option) at the default
		// priority, so both migrations must run before that.
		add_action( 'init', array( __CLASS__, 'remove_public_timeline_page' ), 5 );
		add_action( 'init', array( __CLASS__, 'migrate_content_type_pages' ), 5 );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DAYMARK_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Add "Open Daymark" and "Subscriptions" action links on the Plugins
	 * list table, so the app and the subscribe-by-URL settings screen are
	 * both one click away right after activation.
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

		$subscriptions = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Daymark_Admin_Subscriptions::page_url() ),
			esc_html__( 'Subscriptions', 'daymark' )
		);

		return array_merge(
			array(
				'open-daymark'          => $open,
				'daymark-subscriptions' => $subscriptions,
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
		$this->routes->register();
		$this->syndication_links->register();
		$this->microformats->register();
		$this->backflow_sync->register();
		$this->publisher->register();
		$this->subscription_post_type->register();
		$this->subscription_poller->register();
		$this->admin_subscriptions->register();
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
