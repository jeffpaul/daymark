<?php
/**
 * Core plugin loader.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton loader that wires up all Moment components.
 */
final class Moment_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Moment_Plugin|null
	 */
	private static ?Moment_Plugin $instance = null;

	/**
	 * Route handler.
	 *
	 * @var Moment_Routes
	 */
	public Moment_Routes $routes;

	/**
	 * REST controller.
	 *
	 * @var Moment_REST_Controller
	 */
	public Moment_REST_Controller $rest_controller;

	/**
	 * Moment publisher.
	 *
	 * @var Moment_Publisher
	 */
	public Moment_Publisher $publisher;

	/**
	 * Syndication links (u-syndication markup on Moment posts).
	 *
	 * @var Moment_Syndication_Links
	 */
	public Moment_Syndication_Links $syndication_links;

	/**
	 * Automatic backflow sync (cron + on-view freshening).
	 *
	 * @var Moment_Backflow_Sync
	 */
	public Moment_Backflow_Sync $backflow_sync;

	/**
	 * AI Assist adapter.
	 *
	 * @var Moment_AI_Assist
	 */
	public Moment_AI_Assist $ai_assist;

	/**
	 * Block registrar.
	 *
	 * @var Moment_Blocks
	 */
	public Moment_Blocks $blocks;

	/**
	 * View renderer.
	 *
	 * @var Moment_Renderer
	 */
	public Moment_Renderer $renderer;

	/**
	 * Syndication connector registry.
	 *
	 * @var Moment_Syndication_Registry
	 */
	public Moment_Syndication_Registry $syndication_registry;

	/**
	 * Notifications provider.
	 *
	 * @var Moment_Notifications
	 */
	public Moment_Notifications $notifications;

	/**
	 * Pages created on activation: slug => shortcode.
	 *
	 * @var array<string, string>
	 */
	private const ACTIVATION_PAGES = array(
		'timeline' => 'moment/timeline',
		'images'   => 'moment/images',
		'videos'   => 'moment/videos',
		'audio'    => 'moment/audio',
		'notes'    => 'moment/notes',
	);

	/**
	 * Get the singleton instance.
	 *
	 * @return Moment_Plugin
	 */
	public static function instance(): Moment_Plugin {
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
		$this->routes               = new Moment_Routes();
		$this->rest_controller      = new Moment_REST_Controller();
		$this->publisher            = new Moment_Publisher();
		$this->ai_assist            = new Moment_AI_Assist();
		$this->renderer             = new Moment_Renderer();
		$this->blocks               = new Moment_Blocks( $this->renderer );
		$this->syndication_registry = Moment_Syndication_Registry::instance();
		$this->notifications        = new Moment_Notifications();
		$this->syndication_links    = new Moment_Syndication_Links();
		$this->backflow_sync        = new Moment_Backflow_Sync();

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MOMENT_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Add an "Open Moment" action link on the Plugins list table, so the
	 * app is one click away right after activation.
	 *
	 * @param array<string, string> $links Existing action links (Deactivate, …).
	 * @return array<string, string>
	 */
	public function add_action_links( array $links ): array {
		$open = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Moment_Routes::app_url() ),
			esc_html__( 'Open Moment', 'moment' )
		);

		return array_merge( array( 'open-moment' => $open ), $links );
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
		// Bridge active third-party publishing plugins' control filters to
		// per-Moment selection (Share on Mastodon, Autoshare for Twitter).
		Moment_Publish_Helpers::register_adapters();

		/**
		 * Fires after built-in Moment connectors are registered.
		 *
		 * Third-party connector plugins, WordPress Connector plugins,
		 * or existing social publishing plugins can hook here to register
		 * their own Moment_Syndication_Connector implementations via
		 * $registry->register_connector( $connector ).
		 *
		 * @param Moment_Syndication_Registry $registry The connector registry.
		 */
		do_action( 'moment_register_connectors', $this->syndication_registry );
	}

	/**
	 * Plugin activation callback.
	 *
	 * Registers rewrite rules, creates the Moment view pages, flushes
	 * rewrite rules, and stores activation flags. Never deletes content.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Moment_Backflow_Sync::schedule();
		// Resolve the app base fresh (respecting content at /moment), then
		// register rewrite rules so the flush below picks them up.
		Moment_Routes::resolve_app_base();
		$routes = new Moment_Routes();
		$routes->register();

		self::create_pages();

		flush_rewrite_rules();

		update_option( 'moment_activated', time() );
		update_option( 'moment_version', MOMENT_VERSION );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Flushes rewrite rules only. Content, pages, and meta are preserved
	 * by design — Moments must remain standard WordPress content.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Moment_Backflow_Sync::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Create the Moment view pages if pages with those slugs do not exist.
	 *
	 * @return void
	 */
	private static function create_pages(): void {
		$map = array();

		foreach ( self::ACTIVATION_PAGES as $slug => $block ) {
			$page_id = self::find_view_page( $slug, $block );

			if ( ! $page_id ) {
				// Preferred slug first; if the site already has an
				// unrelated page there, fall back to a moment- prefix.
				// Existing content is never overwritten.
				foreach ( array( $slug, 'moment-' . $slug ) as $path ) {
					if ( get_page_by_path( $path, OBJECT, 'page' ) instanceof WP_Post ) {
						continue;
					}

					// Dynamic block markup, not a shortcode: block themes
					// edit it natively, and both surfaces share
					// Moment_Renderer anyway.
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

			// 0 = both candidate slugs are taken by non-Moment content;
			// the app hides that view's link rather than mislink.
			$map[ $slug ] = $page_id;
		}

		update_option( 'moment_pages', $map );
	}

	/**
	 * Find an existing page that renders the given Moment view — one whose
	 * content carries the view's block or shortcode — at either candidate
	 * slug. Distinguishes our pages from unrelated user pages that merely
	 * occupy the slug.
	 *
	 * @param string $slug  View slug (e.g. 'timeline').
	 * @param string $block Block name (e.g. 'moment/timeline').
	 * @return int Page ID, or 0 when no Moment view page exists.
	 */
	private static function find_view_page( string $slug, string $block ): int {
		foreach ( array( $slug, 'moment-' . $slug ) as $path ) {
			$page = get_page_by_path( $path, OBJECT, 'page' );

			if ( ! $page instanceof WP_Post ) {
				continue;
			}

			if (
				str_contains( $page->post_content, '<!-- wp:' . $block )
				|| str_contains( $page->post_content, '[moment_' . $slug )
			) {
				return (int) $page->ID;
			}
		}

		return 0;
	}

	/**
	 * Map of view slug => page ID for the Moment section pages.
	 *
	 * Self-heals for installs that predate the mapping (or whose pages
	 * changed) by adopting pages that carry the view's block or shortcode.
	 * A view maps to 0 when its slugs are occupied by non-Moment content —
	 * consumers hide that view's link.
	 *
	 * @return array<string, int>
	 */
	public static function get_moment_pages(): array {
		$map = get_option( 'moment_pages', array() );
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
			update_option( 'moment_pages', $map );
		}

		return $map;
	}
}
