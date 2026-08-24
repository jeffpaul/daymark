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
	 * Block registrar.
	 *
	 * @var Daymark_Blocks
	 */
	public Daymark_Blocks $blocks;

	/**
	 * View renderer.
	 *
	 * @var Daymark_Renderer
	 */
	public Daymark_Renderer $renderer;

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
	 * Pages created on activation: slug => shortcode.
	 *
	 * @var array<string, string>
	 */
	private const ACTIVATION_PAGES = array(
		'timeline' => 'daymark/timeline',
		'images'   => 'daymark/images',
		'videos'   => 'daymark/videos',
		'audio'    => 'daymark/audio',
		'notes'    => 'daymark/notes',
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
		$this->renderer                     = new Daymark_Renderer();
		$this->blocks                       = new Daymark_Blocks( $this->renderer );
		$this->syndication_registry         = Daymark_Syndication_Registry::instance();
		$this->notifications                = new Daymark_Notifications();
		$this->syndication_links            = new Daymark_Syndication_Links();
		$this->backflow_sync                = new Daymark_Backflow_Sync();
		$this->rate_limiter                 = new Daymark_Rate_Limiter();
		$this->subscription_source_registry = Daymark_Subscription_Source_Registry::instance();
		$this->subscription_post_type       = new Daymark_Subscription_Post_Type();
		$this->subscriptions                = new Daymark_Subscriptions();

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		// Early: converts a legacy Moment (≤ 0.5.0) install before routes
		// read the app base at the default priority. No-op everywhere else.
		add_action( 'init', array( 'Daymark_Migration', 'maybe_migrate' ), 5 );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DAYMARK_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Add an "Open Daymark" action link on the Plugins list table, so the
	 * app is one click away right after activation.
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

		return array_merge( array( 'open-daymark' => $open ), $links );
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
		$this->blocks->register();
		$this->syndication_links->register();
		$this->backflow_sync->register();
		$this->publisher->register();
		$this->subscription_post_type->register();
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
	 * Registers rewrite rules, creates the Daymark view pages, flushes
	 * rewrite rules, and stores activation flags. Never deletes content.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Convert a legacy Moment (≤ 0.5.0) install first, so the carried
		// app base and section pages are in place before resolution below.
		Daymark_Migration::maybe_migrate();

		Daymark_Subscriptions::install();
		Daymark_Backflow_Sync::schedule();
		// Resolve the app base on first activation (respecting content at
		// /daymark); a base that is already persisted — including one the
		// migration carried over — is kept, because a home-screen-installed
		// app URL must never move. Then register rewrite rules so the flush
		// below picks them up.
		Daymark_Routes::app_base();
		$routes = new Daymark_Routes();
		$routes->register();

		self::create_pages();

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
		flush_rewrite_rules();
	}

	/**
	 * Create the Daymark view pages if pages with those slugs do not exist.
	 *
	 * @return void
	 */
	private static function create_pages(): void {
		$map = array();

		foreach ( self::ACTIVATION_PAGES as $slug => $block ) {
			$page_id = self::find_view_page( $slug, $block );

			if ( ! $page_id ) {
				// Preferred slug first; if the site already has an
				// unrelated page there, fall back to a daymark- prefix.
				// Existing content is never overwritten.
				foreach ( array( $slug, 'daymark-' . $slug ) as $path ) {
					if ( get_page_by_path( $path, OBJECT, 'page' ) instanceof WP_Post ) {
						continue;
					}

					// Dynamic block markup, not a shortcode: block themes
					// edit it natively, and both surfaces share
					// Daymark_Renderer anyway.
					$page_id = (int) wp_insert_post(
						array(
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_name'    => $path,
							'post_title'   => ucfirst( $slug ),
							'post_content' => '<!-- wp:' . $block . ' /-->',
						)
					);
					break;
				}
			}

			// 0 = both candidate slugs are taken by non-Mark content;
			// the app hides that view's link rather than mislink.
			$map[ $slug ] = $page_id;
		}

		update_option( 'daymark_pages', $map );
	}

	/**
	 * Find an existing page that renders the given Daymark view — one whose
	 * content carries the view's block or shortcode — at either candidate
	 * slug. Distinguishes our pages from unrelated user pages that merely
	 * occupy the slug.
	 *
	 * @param string $slug  View slug (e.g. 'timeline').
	 * @param string $block Block name (e.g. 'daymark/timeline').
	 * @return int Page ID, or 0 when no Daymark view page exists.
	 */
	private static function find_view_page( string $slug, string $block ): int {
		foreach ( array( $slug, 'daymark-' . $slug ) as $path ) {
			$page = get_page_by_path( $path, OBJECT, 'page' );

			if ( ! $page instanceof WP_Post ) {
				continue;
			}

			if (
				str_contains( $page->post_content, '<!-- wp:' . $block )
				|| str_contains( $page->post_content, '[daymark_' . $slug )
			) {
				return (int) $page->ID;
			}
		}

		return 0;
	}

	/**
	 * Map of view slug => page ID for the Daymark section pages.
	 *
	 * Self-heals for installs that predate the mapping (or whose pages
	 * changed) by adopting pages that carry the view's block or shortcode.
	 * A view maps to 0 when its slugs are occupied by non-Mark content —
	 * consumers hide that view's link.
	 *
	 * @return array<string, int>
	 */
	public static function get_daymark_pages(): array {
		$map = get_option( 'daymark_pages', array() );
		$map = is_array( $map ) ? $map : array();

		$dirty = false;

		foreach ( self::ACTIVATION_PAGES as $slug => $block ) {
			$page_id = isset( $map[ $slug ] ) ? absint( $map[ $slug ] ) : null;

			if ( null !== $page_id && ( 0 === $page_id || 'publish' === get_post_status( $page_id ) ) ) {
				continue;
			}

			$map[ $slug ] = self::find_view_page( $slug, $block );
			$dirty        = true;
		}

		if ( $dirty ) {
			update_option( 'daymark_pages', $map );
		}

		return $map;
	}
}
