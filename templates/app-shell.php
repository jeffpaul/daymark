<?php
/**
 * Daymark app shell template.
 *
 * Loaded by Daymark_Routes via template_include when the daymark_app query
 * var is set (/daymark, /daymark/notifications). Renders a full standalone
 * HTML document — the active theme is intentionally not loaded and
 * wp_head()/wp_footer() are intentionally not called so no theme or admin
 * chrome leaks into the app shell.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$daymark_screen = get_query_var( Daymark_Routes::QUERY_VAR );
$daymark_screen = ( is_string( $daymark_screen ) && '' !== $daymark_screen ) ? $daymark_screen : 'home';

if ( ! is_user_logged_in() ) {
	$daymark_return_url = 'notifications' === $daymark_screen
		? Daymark_Routes::app_url( 'notifications' )
		: Daymark_Routes::app_url();
	wp_safe_redirect( wp_login_url( $daymark_return_url ) );
	exit;
}

if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die(
		esc_html__( 'You need permission to create posts to use Daymark.', 'daymark' ),
		esc_html__( 'Daymark', 'daymark' ),
		array( 'response' => 403 )
	);
}

// Defense-in-depth: the shell is a self-contained document with one
// same-origin script and stylesheet, so restrict what it may load. The
// inline bootstrap config (window.daymarkApp) is JSON_HEX-escaped
// server-side and nonce-scoped (see the wp_inline_script_attributes
// filter below) rather than relying on 'unsafe-inline' — which would
// let ANY injected <script> tag execute, not just this one.
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes into a CSP nonce, not obfuscating code.
$daymark_csp_nonce = base64_encode( random_bytes( 16 ) );

$daymark_csp_parts = array(
	"default-src 'self'",
	"script-src 'self' 'nonce-{$daymark_csp_nonce}'",
	"style-src 'self' 'unsafe-inline'",
	// Timeline legitimately renders images/media from arbitrary hosts, not
	// just this site's own origin: a subscribed site's own thumbnails and
	// favicons (the whole point of Subscriptions), and this site's own
	// media when served through a CDN or offload plugin (e.g. Jetpack's
	// Photon/i0.wp.com, S3, Cloudflare) rather than jeffpaul.com itself.
	// 'self'-only was fine before Subscriptions existed but silently
	// blocked every one of those images once it shipped — https: (not
	// plain http:, to avoid a mixed-content downgrade) is the least
	// restrictive fix that still limits every other directive to 'self'.
	'img-src ' . "'self' https: data: blob:",
	'media-src ' . "'self' https: blob:",
	"connect-src 'self'",
	"font-src 'self' data:",
	"object-src 'none'",
	"base-uri 'self'",
	"frame-ancestors 'none'",
	"form-action 'self'",
);

/**
 * Filters the app shell's Content-Security-Policy header.
 *
 * @param string $policy The full CSP policy string.
 */
$daymark_csp = (string) apply_filters( 'daymark_app_content_security_policy', implode( '; ', $daymark_csp_parts ) );

if ( '' !== $daymark_csp && ! headers_sent() ) {
	header( 'Content-Security-Policy: ' . $daymark_csp );
}

$daymark_user = wp_get_current_user();

/*
 * Connector list and per-type destination defaults, from the
 * Daymark_Syndication_Registry (the source of truth) — so real connector
 * plugins registered via `daymark_register_connectors` appear here with
 * their live connection status.
 */
$daymark_registry   = Daymark_Syndication_Registry::instance();
$daymark_all_types  = array( 'note', 'image', 'gallery', 'video', 'audio', 'mixed' );
$daymark_connectors = array();

// Only genuinely connected networks (a real connector plugin with
// credentials configured) are offered — a destination that cannot
// actually publish or return replies is not shown. The site itself is
// always the canonical destination either way.
foreach ( $daymark_registry->get_connectors() as $daymark_connector ) {
	if ( ! $daymark_connector->is_connected() ) {
		continue;
	}

	$daymark_connectors[] = array(
		'id'           => $daymark_connector->get_id(),
		'label'        => $daymark_connector->get_label(),
		'connected'    => $daymark_connector->is_connected(),
		'status'       => $daymark_connector->is_connected() ? 'connected' : 'mocked',
		'status_label' => $daymark_connector->get_status_label(),
		'supports'     => array_values( array_filter( $daymark_all_types, array( $daymark_connector, 'supports_daymark_type' ) ) ),
	);
}

$daymark_visible_ids   = array_column( $daymark_connectors, 'id' );
$daymark_publisher     = Daymark_Plugin::instance()->publisher;
$daymark_type_defaults = array();

foreach ( $daymark_all_types as $daymark_type ) {
	// The user's remembered selection for the type (falling back to the
	// model defaults), limited to destinations that are actually offered.
	$daymark_type_defaults[ $daymark_type ] = array_values(
		array_intersect( $daymark_publisher->get_effective_defaults( $daymark_type ), $daymark_visible_ids )
	);
}

// Site categories (the filing counterpart to destinations) and the
// remembered per-type default categories. Flat list, name-ordered; the
// app shows the picker only when there is a real choice beyond the
// site's single default category.
$daymark_categories = array();
foreach ( get_categories(
	array(
		'hide_empty' => false,
		'orderby'    => 'name',
	)
) as $daymark_cat ) {
	$daymark_categories[] = array(
		'id'     => (int) $daymark_cat->term_id,
		'name'   => $daymark_cat->name,
		'parent' => (int) $daymark_cat->parent,
	);
}

$daymark_category_defaults = array();
foreach ( $daymark_all_types as $daymark_type ) {
	$daymark_category_defaults[ $daymark_type ] = $daymark_publisher->get_effective_categories( $daymark_type );
}

// Per-type policy for the composer's optional Title field. Normalized to a
// strict 'optional' | 'hidden' map for every known type so the app can look
// up any type without a missing-key gap (a filter may return a partial map).
$daymark_title_policy_all = Daymark_Publisher::title_field_policy();
$daymark_title_policy     = array();
foreach ( $daymark_all_types as $daymark_type ) {
	$daymark_title_policy[ $daymark_type ] = ( isset( $daymark_title_policy_all[ $daymark_type ] ) && 'optional' === $daymark_title_policy_all[ $daymark_type ] )
		? 'optional'
		: 'hidden';
}

$daymark_ai = Daymark_Plugin::instance()->ai_assist;

// Controllable helpers get in-app toggles; awareness helpers are the
// remaining detected publishing plugins Daymark only notes (can't drive).
$daymark_controllable_helpers = Daymark_Publish_Helpers::controllable();
$daymark_controllable_ids     = array_column( $daymark_controllable_helpers, 'id' );
$daymark_awareness_helpers    = array_values(
	array_filter(
		Daymark_Publish_Helpers::detect(),
		static function ( $helper ) use ( $daymark_controllable_ids ) {
			return ! in_array( $helper['id'], $daymark_controllable_ids, true );
		}
	)
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, not a state-changing action; validated against a fixed whitelist below.
$daymark_requested_type = isset( $_GET['daymark_type'] ) ? sanitize_key( wp_unslash( $_GET['daymark_type'] ) ) : '';

$daymark_config = array(
	'restUrl'               => esc_url_raw( rest_url( 'daymark/v1/' ) ),
	'assetsUrl'             => esc_url_raw( DAYMARK_PLUGIN_URL . 'assets/' ),
	'nonce'                 => wp_create_nonce( 'wp_rest' ),
	'siteUrl'               => esc_url_raw( home_url( '/' ) ),
	'siteTitle'             => sanitize_text_field( get_bloginfo( 'name' ) ),
	// Site Icon first, Daymark's own bundled icon otherwise — same
	// resolution Daymark_Routes::icon_url() already uses for the browser
	// favicon and PWA manifest icons. Timeline's own-Mark leading icon
	// reuses it too, so "your Marks" and "a subscribed site's posts" both
	// identify their source the same way (a site's icon), not one by site
	// and the other by the logged-in user's personal Gravatar.
	'siteIconUrl'           => esc_url_raw( Daymark_Routes::icon_url( 96 ) ),
	'screen'                => $daymark_screen,
	'connectors'            => $daymark_connectors,
	'defaults'              => $daymark_type_defaults,
	'categories'            => $daymark_categories,
	'categoryDefaults'      => $daymark_category_defaults,
	'titlePolicy'           => $daymark_title_policy,
	'defaultCategory'       => (int) get_option( 'default_category' ),
	'ai'                    => array(
		'available'     => $daymark_ai->is_available(),
		'providerLabel' => $daymark_ai->get_provider_label(),
	),
	'notifications'         => array(
		'hasUnread' => Daymark_Plugin::instance()->notifications->has_unread(),
	),
	// Controllable third-party helpers get a per-Mark toggle; the rest
	// of the detected publishing plugins stay awareness-only (Daymark does
	// not drive those).
	'controllableHelpers'   => $daymark_controllable_helpers,
	'publishHelpers'        => $daymark_awareness_helpers,
	'currentUser'           => array(
		'id'             => (int) $daymark_user->ID,
		'displayName'    => $daymark_user->display_name,
		'avatarUrl'      => esc_url_raw( (string) get_avatar_url( $daymark_user->ID, array( 'size' => 96 ) ) ),
		// The Me screen links out to WordPress's own profile/logout rather
		// than duplicating account settings — see CLAUDE.md's non-goals.
		'profileEditUrl' => esc_url_raw( get_edit_profile_url( $daymark_user->ID ) ),
		'logoutUrl'      => esc_url_raw( wp_logout_url( Daymark_Routes::app_url( 'me' ) ) ),
	),
	// Subscription management lives in wp-admin (Settings -> Daymark), not
	// the app shell — see CLAUDE.md's "Subscribe-by-URL + subscription
	// management" decision. Explore's Following section and Me both link
	// out to it rather than duplicating it in-app.
	'adminSubscriptionsUrl' => esc_url_raw( Daymark_Admin_Subscriptions::page_url() ),
	// Set only right after Daymark_Share_Target redirects here from a
	// successful OS share-sheet POST — the app boots straight into that
	// draft's composer instead of Home. GET /marks/{id} (which openDraft()
	// uses) already enforces edit_post on this id, so a tampered value just
	// fails that fetch harmlessly rather than needing a second check here.
	'pendingDraftId'        => isset( $_GET['daymark_draft'] ) ? absint( wp_unslash( $_GET['daymark_draft'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, not a state-changing action.
	// Set only when arriving from an external launcher (e.g. wp-admin's
	// "+New -> Daymark" item, Daymark_Admin_Bar) that wants the composer
	// to open pre-set to a specific type — the same one-shot mechanism
	// state.pendingType already uses when the in-app Home launcher sets
	// it, just seeded from a query var instead of a tap. Restricted to
	// the four types the composer's picker actually understands
	// (assets/app.js's LAUNCHER_TYPES); anything else is dropped rather
	// than reaching a picker lookup that doesn't have a matching key.
	'pendingType'           => in_array( $daymark_requested_type, array( 'image', 'video', 'audio', 'note' ), true ) ? $daymark_requested_type : '',
);

/*
 * The app's assets go through the script/style API (registration,
 * versioning, dedupe, defer strategy, inline config) but are printed
 * per-handle below instead of via wp_head()/wp_footer(), keeping the
 * shell free of theme and admin chrome.
 */
wp_register_style( 'daymark-app', DAYMARK_PLUGIN_URL . 'assets/app.css', array(), DAYMARK_VERSION );
wp_register_script(
	'daymark-app',
	DAYMARK_PLUGIN_URL . 'assets/app.js',
	array(),
	DAYMARK_VERSION,
	array(
		'in_footer' => true,
		'strategy'  => 'defer',
	)
);
wp_add_inline_script(
	'daymark-app',
	'window.daymarkApp = ' . wp_json_encode( $daymark_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
	'before'
);

// Matches the script-src nonce above onto this specific inline script only
// (id format per WP_Scripts::get_inline_script_tag(): "{handle}-js-{position}") —
// every other inline script on the page, if any, is untouched.
add_filter(
	'wp_inline_script_attributes',
	static function ( array $attributes ) use ( $daymark_csp_nonce ): array {
		if ( isset( $attributes['id'] ) && 'daymark-app-js-before' === $attributes['id'] ) {
			$attributes['nonce'] = $daymark_csp_nonce;
		}

		return $attributes;
	}
);

wp_enqueue_style( 'daymark-app' );
wp_enqueue_script( 'daymark-app' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<meta name="robots" content="noindex, nofollow" />
	<meta name="theme-color" content="#c93a06" />
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="apple-mobile-web-app-status-bar-style" content="default" />
	<meta name="apple-mobile-web-app-title" content="Daymark" />
	<title><?php esc_html_e( 'Daymark', 'daymark' ); ?></title>
	<?php /* Dynamic manifest: start_url/scope track the resolved app base. */ ?>
	<link rel="manifest" href="<?php echo esc_url( Daymark_Routes::app_url( 'manifest.json' ) ); ?>" />
	<?php /* Home-screen icon: the site's Site Icon when set, else Daymark's (opaque PNG; iOS ignores SVG here). */ ?>
	<link rel="apple-touch-icon" href="<?php echo esc_url( Daymark_Routes::icon_url( 180 ) ); ?>" />
	<link rel="icon" href="<?php echo esc_url( Daymark_Routes::icon_url( 32 ) ); ?>" sizes="32x32" />
	<?php wp_print_styles( array( 'daymark-app' ) ); ?>
</head>
<body class="daymark-app daymark-app--<?php echo esc_attr( $daymark_screen ); ?>">
	<div id="daymark-app" class="daymark-shell">
		<p class="daymark-boot"><?php esc_html_e( 'Loading Daymark…', 'daymark' ); ?></p>
	</div>
	<noscript>
		<p class="daymark-noscript"><?php esc_html_e( 'Daymark needs JavaScript. Please enable it and reload.', 'daymark' ); ?></p>
	</noscript>
	<?php wp_print_scripts( array( 'daymark-app' ) ); ?>
</body>
</html>
