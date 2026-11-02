<?php
/**
 * Follow a site from anywhere: a link that opens your own Daymark ready to
 * follow a site, and the "Follow me with Daymark" block that builds that
 * link on someone else's site.
 *
 * @package Daymark
 * @since   0.22.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Follow links (issue #560).
 *
 * Any page on a Daymark site, given `?daymark_follow_site=<address>`,
 * redirects to the app, which opens its Follow a site sheet with that
 * address filled in. Nothing is looked up or followed until the person
 * taps Find feeds and then Follow, so a link someone else made can't follow
 * anything on its own. Following stays limited to people who can manage
 * subscriptions (Daymark_Admin_Subscriptions::CAPABILITY); the app tells
 * anyone else that only an administrator can follow sites.
 *
 * The link works from any address on the site, not only the app's own,
 * because the block runs on someone else's site and doesn't know where the
 * visitor's app lives (`/daymark`, or `/daymark-app` when that slug is
 * already taken). The redirect only ever goes to this site's own app, so
 * it can't be used to send people anywhere else.
 */
class Daymark_Follow_Link {

	/**
	 * Query arg carrying the address of the site to follow.
	 */
	public const QUERY_VAR = 'daymark_follow_site';

	/**
	 * Block name.
	 */
	public const BLOCK = 'daymark/follow-me';

	/**
	 * Longest address accepted.
	 */
	private const MAX_LENGTH = 2000;

	/**
	 * Register the redirect and the block. Called from
	 * Daymark_Plugin::on_init(), so the block is registered directly rather
	 * than on another `init` callback (one added at the running priority
	 * would never run).
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 1: before redirect_canonical() (priority 10) can rewrite
		// the address.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
		$this->register_block();
	}

	/**
	 * Read and check the address of the site to follow.
	 *
	 * An address without a scheme gets `https://`, like the app's own Follow
	 * a site field.
	 *
	 * @param mixed $raw The query arg's value (unslashed).
	 * @return string An http(s) URL with a host, or '' when unusable.
	 */
	public static function read_url( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );

		if ( '' === $raw || strlen( $raw ) > self::MAX_LENGTH ) {
			return '';
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}

		$url  = esc_url_raw( $raw, array( 'http', 'https' ) );
		$host = '' !== $url ? wp_parse_url( $url, PHP_URL_HOST ) : null;

		return is_string( $host ) && '' !== $host ? $url : '';
	}

	/**
	 * The app's address with a site to follow filled in.
	 *
	 * @param string $site_url Site to follow.
	 * @return string
	 */
	public static function app_url( string $site_url ): string {
		return add_query_arg( self::QUERY_VAR, rawurlencode( $site_url ), Daymark_Routes::app_url() );
	}

	/**
	 * Where a follow link on this request should go: the app with the site
	 * filled in, or '' when this request isn't a usable follow link.
	 *
	 * @return string
	 */
	public static function redirect_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect to this site's own app; nothing changes until the person confirms there.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return '';
		}

		// The app shell reads the address itself (templates/app-shell.php).
		if ( '' !== (string) get_query_var( Daymark_Routes::QUERY_VAR ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by read_url().
		$url = self::read_url( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

		return '' !== $url ? self::app_url( $url ) : '';
	}

	/**
	 * Send a follow link that arrived anywhere on the site to the app.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		$url = self::redirect_url();

		if ( '' === $url ) {
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Register the "Follow me with Daymark" block: a dynamic block rendered
	 * in PHP, a no-build editor script that previews it through the block
	 * editor's server-side renderer, and a small front-end script that
	 * sends the visitor to their own site.
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		wp_register_script(
			'daymark-follow-me-block',
			DAYMARK_PLUGIN_URL . 'assets/follow-me-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			DAYMARK_VERSION,
			true
		);
		wp_set_script_translations( 'daymark-follow-me-block', 'daymark' );

		// No strings of its own: its messages come from the block's markup.
		wp_register_script(
			'daymark-follow-me',
			DAYMARK_PLUGIN_URL . 'assets/follow-me.js',
			array(),
			DAYMARK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_style( 'daymark-follow-me', DAYMARK_PLUGIN_URL . 'assets/follow-me.css', array(), DAYMARK_VERSION );

		register_block_type(
			self::BLOCK,
			array(
				'api_version'           => 3,
				'title'                 => __( 'Follow me with Daymark', 'daymark' ),
				'description'           => __( 'Lets visitors follow this site from their own site with Daymark, or with any feed reader.', 'daymark' ),
				'category'              => 'widgets',
				'icon'                  => 'groups',
				'keywords'              => array( __( 'follow', 'daymark' ), __( 'subscribe', 'daymark' ), __( 'feed', 'daymark' ) ),
				'editor_script_handles' => array( 'daymark-follow-me-block' ),
				'view_script_handles'   => array( 'daymark-follow-me' ),
				'style_handles'         => array( 'daymark-follow-me' ),
				'render_callback'       => array( $this, 'render_block' ),
				'attributes'            => array(
					// Set only by the editor's preview, which shows the form
					// that the front-end script would otherwise reveal.
					'preview' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'supports'              => array(
					'html'    => false,
					'align'   => array( 'wide', 'full' ),
					'spacing' => array(
						'margin'  => true,
						'padding' => true,
					),
				),
			)
		);
	}

	/**
	 * Render the block: a form that sends a visitor to their own Daymark
	 * with this site filled in, and this site's feed address for any feed
	 * reader.
	 *
	 * The form starts hidden and the front-end script reveals it, so with
	 * scripts off only the feed address shows. Where no script runs at all
	 * (feeds, and REST responses such as a post's `content.rendered`, which
	 * other Daymark sites read), only the feed address is rendered.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes ): string {
		$preview  = ! empty( $attributes['preview'] );
		$feed_url = get_feed_link();
		$feed     = sprintf(
			'<p class="daymark-follow-me__feed">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Follow with any feed reader:', 'daymark' ),
			esc_url( $feed_url ),
			esc_html( $feed_url )
		);

		if ( ! $preview && ( is_feed() || wp_is_serving_rest_request() ) ) {
			return sprintf( '<div %1$s>%2$s</div>', self::wrapper_attributes( array() ), $feed );
		}

		$id   = wp_unique_id( 'daymark-follow-me-' );
		$form = sprintf(
			'<form class="daymark-follow-me__form" novalidate%1$s>'
			. '<p class="daymark-follow-me__lead">%2$s</p>'
			. '<label class="daymark-follow-me__label" for="%3$s">%4$s</label>'
			. '<div class="daymark-follow-me__row">'
			. '<input class="daymark-follow-me__input" id="%3$s" name="url" type="text" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" placeholder="%5$s" aria-describedby="%3$s-error" />'
			. '<button class="daymark-follow-me__button wp-element-button" type="submit">%6$s</button>'
			. '</div>'
			. '<p class="daymark-follow-me__error" id="%3$s-error" role="alert" hidden></p>'
			. '</form>',
			$preview ? '' : ' hidden',
			esc_html__( 'Have a site with Daymark? Follow this site from it.', 'daymark' ),
			esc_attr( $id ),
			esc_html__( 'Your site’s address', 'daymark' ),
			esc_attr__( 'yoursite.com', 'daymark' ),
			esc_html__( 'Follow', 'daymark' )
		);

		return sprintf(
			'<div %1$s>%2$s%3$s</div>',
			self::wrapper_attributes(
				array(
					'data-daymark-follow-me' => '',
					'data-site'              => home_url( '/' ),
					'data-invalid'           => __( 'Enter your site’s address, like yoursite.com.', 'daymark' ),
				)
			),
			$form,
			$feed
		);
	}

	/**
	 * The block wrapper's attributes. get_block_wrapper_attributes() only
	 * works while a block is being rendered; called any other way, this
	 * falls back to the block's class.
	 *
	 * @param array<string, string> $extra Extra attributes.
	 * @return string
	 */
	private static function wrapper_attributes( array $extra ): string {
		if ( null !== WP_Block_Supports::$block_to_render ) {
			return get_block_wrapper_attributes( $extra );
		}

		$html = 'class="wp-block-daymark-follow-me"';

		foreach ( $extra as $name => $value ) {
			$html .= sprintf( ' %1$s="%2$s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return $html;
	}
}
