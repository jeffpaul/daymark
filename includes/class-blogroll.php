<?php
/**
 * The blogroll: the sites you follow, shared publicly when you choose to.
 *
 * Core's Links Manager was WordPress's original blogroll. It has been hidden
 * on new sites since WordPress 3.5, has no REST API, and has nowhere to keep
 * a feed's status, so Daymark keeps its own subscriptions table and offers
 * two pieces of the blogroll idea on top of it:
 *
 * - A public OPML file of your active subscriptions (opt-in, Settings ->
 *   Daymark -> General), linked from every page with
 *   `<link rel="blogroll">` so feed readers can find it.
 * - A "Blogroll" block that lists them on any page you add it to.
 *
 * It also reads an existing Links list for the one-time "Import from
 * Links" on the Import / Export tab (links_for_import()).
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public blogroll output and Links import.
 */
class Daymark_Blogroll {

	/**
	 * Query var that serves the OPML file (`/?daymark_blogroll=opml`). A
	 * query var rather than a pretty rewrite, so it works without flushing
	 * rewrite rules when the setting is turned on.
	 */
	public const QUERY_VAR = 'daymark_blogroll';

	/**
	 * Block name.
	 */
	public const BLOCK = 'daymark/blogroll';

	/**
	 * Register hooks and the block. Called from Daymark_Plugin::on_init(),
	 * so the block is registered directly rather than on another `init`
	 * callback (one added at the running priority would never run).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_opml' ) );
		add_action( 'wp_head', array( $this, 'print_head_link' ) );
		$this->register_block();
	}

	/**
	 * Public URL of the OPML file.
	 *
	 * @return string
	 */
	public static function opml_url(): string {
		return add_query_arg( self::QUERY_VAR, 'opml', home_url( '/' ) );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * The active subscriptions to list, sorted by name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sites(): array {
		$sites = Daymark_Plugin::instance()->subscriptions->get_active();

		usort(
			$sites,
			static function ( array $a, array $b ): int {
				return strcasecmp( self::label( $a ), self::label( $b ) );
			}
		);

		return $sites;
	}

	/**
	 * A subscription's display name: its title, else its site URL's host.
	 *
	 * @param array<string, mixed> $subscription Subscription row.
	 * @return string
	 */
	public static function label( array $subscription ): string {
		$title = trim( (string) ( $subscription['site_title'] ?? '' ) );

		if ( '' !== $title ) {
			return $title;
		}

		$host = wp_parse_url( (string) ( $subscription['site_url'] ?? '' ), PHP_URL_HOST );

		return is_string( $host ) ? $host : (string) ( $subscription['site_url'] ?? '' );
	}

	/**
	 * Serve the OPML file when asked for and the blogroll is public; a 404
	 * otherwise.
	 *
	 * @return void
	 */
	public function maybe_serve_opml(): void {
		if ( 'opml' !== get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! Daymark_Settings::blogroll_public() ) {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return;
		}

		$title = sprintf(
			/* translators: %s: site name */
			__( 'Sites %s follows', 'daymark' ),
			get_bloginfo( 'name' )
		);

		status_header( 200 );
		header( 'Content-Type: text/x-opml+xml; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex' );
		echo ( new Daymark_Subscription_OPML() )->export_public( self::sites(), $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A DOMDocument-serialized XML document; every value is escaped by the serializer.
		exit;
	}

	/**
	 * Advertise the OPML file on every front-end page when public, with the
	 * `rel="blogroll"` link feed readers look for.
	 *
	 * @return void
	 */
	public function print_head_link(): void {
		if ( ! Daymark_Settings::blogroll_public() ) {
			return;
		}

		printf(
			'<link rel="blogroll" type="text/xml" href="%1$s" title="%2$s" />' . "\n",
			esc_url( self::opml_url() ),
			esc_attr__( 'Blogroll', 'daymark' )
		);
	}

	/**
	 * Register the Blogroll block: a dynamic block rendered in PHP, with a
	 * small no-build editor script that previews it through the block
	 * editor's server-side renderer.
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		wp_register_script(
			'daymark-blogroll-block',
			DAYMARK_PLUGIN_URL . 'assets/blogroll-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			DAYMARK_VERSION,
			true
		);
		wp_set_script_translations( 'daymark-blogroll-block', 'daymark' );

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Blogroll', 'daymark' ),
				'description'     => __( 'The sites you follow with Daymark.', 'daymark' ),
				'category'        => 'widgets',
				'icon'            => 'rss',
				'editor_script'   => 'daymark-blogroll-block',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'showIcons' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	/**
	 * Render the Blogroll block: a list of links to the sites you follow.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes ): string {
		$sites = self::sites();

		if ( empty( $sites ) ) {
			return '';
		}

		$show_icons = ! isset( $attributes['showIcons'] ) || (bool) $attributes['showIcons'];
		$items      = '';

		foreach ( $sites as $site ) {
			$url = esc_url( (string) ( $site['site_url'] ?? '' ) );

			if ( '' === $url ) {
				continue;
			}

			$icon = (string) ( $site['site_icon_url'] ?? '' );
			$img  = $show_icons && '' !== $icon
				? sprintf( '<img src="%s" alt="" width="20" height="20" loading="lazy" style="vertical-align:middle;margin-right:0.5em;border-radius:4px;" /> ', esc_url( $icon ) )
				: '';

			$items .= sprintf( '<li>%1$s<a href="%2$s">%3$s</a></li>', $img, $url, esc_html( self::label( $site ) ) );
		}

		if ( '' === $items ) {
			return '';
		}

		// get_block_wrapper_attributes() only works while a block is being
		// rendered; called any other way, fall back to the block's class.
		$wrapper = null !== WP_Block_Supports::$block_to_render ? get_block_wrapper_attributes() : 'class="wp-block-daymark-blogroll"';

		return sprintf( '<ul %1$s>%2$s</ul>', $wrapper, $items );
	}

	/**
	 * Existing Links Manager entries to offer for import: one entry per
	 * visible or hidden link, keyed by link ID, in the same shape
	 * Daymark_Subscription_OPML::import_entries() takes. A link's RSS
	 * address is used as its feed when it has one; otherwise its site
	 * address is discovered the same way subscribing by URL does.
	 *
	 * @return array<int, array{label: string, xml_url: string, html_url: string, icon_url: string, source_type: string}>
	 */
	public static function links_for_import(): array {
		$links = get_bookmarks(
			array(
				'hide_invisible' => 0,
				'orderby'        => 'name',
			)
		);

		$entries = array();

		foreach ( (array) $links as $link ) {
			$html_url = esc_url_raw( (string) ( $link->link_url ?? '' ) );
			$xml_url  = esc_url_raw( (string) ( $link->link_rss ?? '' ) );

			if ( '' === $html_url && '' === $xml_url ) {
				continue;
			}

			$entries[ (int) $link->link_id ] = array(
				'label'       => sanitize_text_field( (string) ( $link->link_name ?? '' ) ),
				'xml_url'     => $xml_url,
				'html_url'    => $html_url,
				'icon_url'    => '',
				'source_type' => '',
			);
		}

		return $entries;
	}
}
