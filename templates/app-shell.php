<?php
/**
 * Moment app shell template.
 *
 * Loaded by Moment_Routes via template_include when the moment_app query
 * var is set (/moment, /moment/notifications). Renders a full standalone
 * HTML document — the active theme is intentionally not loaded and
 * wp_head()/wp_footer() are intentionally not called so no theme or admin
 * chrome leaks into the app shell.
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$moment_screen = get_query_var( Moment_Routes::QUERY_VAR );
$moment_screen = ( is_string( $moment_screen ) && '' !== $moment_screen ) ? $moment_screen : 'home';

if ( ! is_user_logged_in() ) {
	$moment_return_url = 'notifications' === $moment_screen
		? Moment_Routes::app_url( 'notifications' )
		: Moment_Routes::app_url();
	wp_safe_redirect( wp_login_url( $moment_return_url ) );
	exit;
}

if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die(
		esc_html__( 'You need permission to create posts to use Moment.', 'moment' ),
		esc_html__( 'Moment', 'moment' ),
		array( 'response' => 403 )
	);
}

$moment_user = wp_get_current_user();

/*
 * Connector list and per-type destination defaults, from the
 * Moment_Syndication_Registry (the source of truth) — so real connector
 * plugins registered via `moment_register_connectors` appear here with
 * their live connection status.
 */
$moment_registry   = Moment_Syndication_Registry::instance();
$moment_all_types  = array( 'note', 'image', 'gallery', 'video', 'audio', 'podcast', 'mixed' );
$moment_connectors = array();

// Only genuinely connected networks (a real connector plugin with
// credentials configured) are offered — a destination that cannot
// actually publish or return replies is not shown. The site itself is
// always the canonical destination either way.
foreach ( $moment_registry->get_connectors() as $moment_connector ) {
	if ( ! $moment_connector->is_connected() ) {
		continue;
	}

	$moment_connectors[] = array(
		'id'           => $moment_connector->get_id(),
		'label'        => $moment_connector->get_label(),
		'connected'    => $moment_connector->is_connected(),
		'status'       => $moment_connector->is_connected() ? 'connected' : 'mocked',
		'status_label' => $moment_connector->get_status_label(),
		'supports'     => array_values( array_filter( $moment_all_types, array( $moment_connector, 'supports_moment_type' ) ) ),
	);
}

$moment_visible_ids   = array_column( $moment_connectors, 'id' );
$moment_publisher     = Moment_Plugin::instance()->publisher;
$moment_type_defaults = array();

foreach ( $moment_all_types as $moment_type ) {
	// The user's remembered selection for the type (falling back to the
	// model defaults), limited to destinations that are actually offered.
	$moment_type_defaults[ $moment_type ] = array_values(
		array_intersect( $moment_publisher->get_effective_defaults( $moment_type ), $moment_visible_ids )
	);
}

// Site categories (the filing counterpart to destinations) and the
// remembered per-type default categories. Flat list, name-ordered; the
// app shows the picker only when there is a real choice beyond the
// site's single default category.
$moment_categories = array();
foreach ( get_categories(
	array(
		'hide_empty' => false,
		'orderby'    => 'name',
	)
) as $moment_cat ) {
	$moment_categories[] = array(
		'id'     => (int) $moment_cat->term_id,
		'name'   => $moment_cat->name,
		'parent' => (int) $moment_cat->parent,
	);
}

$moment_category_defaults = array();
foreach ( $moment_all_types as $moment_type ) {
	$moment_category_defaults[ $moment_type ] = $moment_publisher->get_effective_categories( $moment_type );
}

$moment_ai = Moment_Plugin::instance()->ai_assist;

// Controllable helpers get in-app toggles; awareness helpers are the
// remaining detected publishing plugins Moment only notes (can't drive).
$moment_controllable_helpers = Moment_Publish_Helpers::controllable();
$moment_controllable_ids     = array_column( $moment_controllable_helpers, 'id' );
$moment_awareness_helpers    = array_values(
	array_filter(
		Moment_Publish_Helpers::detect(),
		static function ( $helper ) use ( $moment_controllable_ids ) {
			return ! in_array( $helper['id'], $moment_controllable_ids, true );
		}
	)
);

// Section-page links resolve to the real pages (collision-aware slugs);
// a view without a live Moment page gets '' and the app hides its link.
$moment_pages = array();
foreach ( Moment_Plugin::get_moment_pages() as $moment_view => $moment_page_id ) {
	$moment_pages[ $moment_view ] = $moment_page_id && 'publish' === get_post_status( $moment_page_id )
		? esc_url_raw( (string) get_permalink( $moment_page_id ) )
		: '';
}

$moment_config = array(
	'restUrl'             => esc_url_raw( rest_url( 'moment/v1/' ) ),
	'assetsUrl'           => esc_url_raw( MOMENT_PLUGIN_URL . 'assets/' ),
	'nonce'               => wp_create_nonce( 'wp_rest' ),
	'siteUrl'             => esc_url_raw( home_url( '/' ) ),
	'screen'              => $moment_screen,
	'connectors'          => $moment_connectors,
	'defaults'            => $moment_type_defaults,
	'categories'          => $moment_categories,
	'categoryDefaults'    => $moment_category_defaults,
	'defaultCategory'     => (int) get_option( 'default_category' ),
	'pages'               => $moment_pages,
	'ai'                  => array(
		'available'     => $moment_ai->is_available(),
		'providerLabel' => $moment_ai->get_provider_label(),
	),
	'notifications'       => array(
		'hasUnread' => Moment_Plugin::instance()->notifications->has_unread(),
	),
	// Controllable third-party helpers get a per-Moment toggle; the rest
	// of the detected publishing plugins stay awareness-only (Moment does
	// not drive those).
	'controllableHelpers' => $moment_controllable_helpers,
	'publishHelpers'      => $moment_awareness_helpers,
	'currentUser'         => array(
		'id'          => (int) $moment_user->ID,
		'displayName' => $moment_user->display_name,
	),
);

/*
 * The app's assets go through the script/style API (registration,
 * versioning, dedupe, defer strategy, inline config) but are printed
 * per-handle below instead of via wp_head()/wp_footer(), keeping the
 * shell free of theme and admin chrome.
 */
wp_register_style( 'moment-app', MOMENT_PLUGIN_URL . 'assets/app.css', array(), MOMENT_VERSION );
wp_register_script(
	'moment-app',
	MOMENT_PLUGIN_URL . 'assets/app.js',
	array(),
	MOMENT_VERSION,
	array(
		'in_footer' => true,
		'strategy'  => 'defer',
	)
);
wp_add_inline_script(
	'moment-app',
	'window.momentApp = ' . wp_json_encode( $moment_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
	'before'
);
wp_enqueue_style( 'moment-app' );
wp_enqueue_script( 'moment-app' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<meta name="robots" content="noindex, nofollow" />
	<meta name="theme-color" content="#7a00df" />
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="apple-mobile-web-app-status-bar-style" content="default" />
	<meta name="apple-mobile-web-app-title" content="Moment" />
	<title><?php esc_html_e( 'Moment', 'moment' ); ?></title>
	<?php /* Dynamic manifest: start_url/scope track the resolved app base. */ ?>
	<link rel="manifest" href="<?php echo esc_url( Moment_Routes::app_url( 'manifest.json' ) ); ?>" />
	<?php /* Home-screen icon: the site's Site Icon when set, else Moment's (opaque PNG; iOS ignores SVG here). */ ?>
	<link rel="apple-touch-icon" href="<?php echo esc_url( Moment_Routes::icon_url( 180 ) ); ?>" />
	<link rel="icon" href="<?php echo esc_url( MOMENT_PLUGIN_URL . 'assets/icon.svg' ); ?>" type="image/svg+xml" />
	<?php wp_print_styles( array( 'moment-app' ) ); ?>
</head>
<body class="moment-app moment-app--<?php echo esc_attr( $moment_screen ); ?>">
	<div id="moment-app" class="moment-shell">
		<p class="moment-boot"><?php esc_html_e( 'Loading Moment…', 'moment' ); ?></p>
	</div>
	<noscript>
		<p class="moment-noscript"><?php esc_html_e( 'Moment needs JavaScript. Please enable it and reload.', 'moment' ); ?></p>
	</noscript>
	<?php wp_print_scripts( array( 'moment-app' ) ); ?>
</body>
</html>
