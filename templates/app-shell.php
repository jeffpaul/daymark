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
	// The full-screen post view can render an oEmbed preview
	// (Daymark_Subscription_Oembed) of a link-format subscription post's
	// own detected link — necessarily a cross-origin <iframe> (the
	// provider's own embed page), same "arbitrary host, https only"
	// reasoning as img-src/media-src above for the same underlying
	// reason: Subscriptions legitimately surfaces other sites' content.
	'frame-src ' . "'self' https:",
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

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, not a state-changing action; validated against a fixed whitelist inside build_app_config().
$daymark_requested_type = isset( $_GET['daymark_type'] ) ? sanitize_key( wp_unslash( $_GET['daymark_type'] ) ) : '';
// Set only right after Daymark_Share_Target redirects here from a
// successful OS share-sheet POST — the app boots straight into that
// draft's composer instead of Home. GET /marks/{id} (which openDraft()
// uses) already enforces edit_post on this id, so a tampered value just
// fails that fetch harmlessly rather than needing a second check here.
$daymark_requested_draft_id = isset( $_GET['daymark_draft'] ) ? absint( wp_unslash( $_GET['daymark_draft'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, not a state-changing action.

// One source of truth for this array, shared with GET /daymark/config.json
// (the cold-offline-load path, issue #126) — see build_app_config()'s own
// docblock in class-routes.php.
$daymark_config = Daymark_Routes::build_app_config( $daymark_screen, $daymark_requested_type, $daymark_requested_draft_id );

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
	// 'wp-i18n' is a WordPress core script (no new dependency) — this is
	// the i18n-readiness plumbing (issue #252) wp.org's own automated
	// JS-string extraction needs. assets/app.js itself doesn't call
	// wp.i18n.__() yet (tracked separately, issue #253, to avoid
	// colliding with the in-flight gallery-reordering PR touching this
	// same file); registering the dependency and translations now means
	// nothing else has to change here once that follow-up lands.
	array( 'wp-i18n' ),
	DAYMARK_VERSION,
	array(
		'in_footer' => true,
		'strategy'  => 'defer',
	)
);
// No bundled languages/ folder or .json files: for a wordpress.org-hosted
// plugin, wp.org's own translation API serves the JSON translation
// files it generates from GlotPress automatically, keyed off this exact
// script handle + text domain pairing.
wp_set_script_translations( 'daymark-app', 'daymark' );
wp_add_inline_script(
	'daymark-app',
	'window.daymarkApp = ' . wp_json_encode( $daymark_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
	'before'
);

// Matches the script-src nonce above onto specific inline scripts only
// (id format per WP_Scripts::get_inline_script_tag(): "{handle}-js-{position}") —
// every other inline script on the page, if any, is untouched. Covers both
// the bootstrap config above and the "daymark-app-js-translations" block
// wp_set_script_translations() (see above) prints through the same
// mechanism — without this, our own strict script-src (no 'unsafe-inline')
// would silently block the translation data from ever reaching wp.i18n.
add_filter(
	'wp_inline_script_attributes',
	static function ( array $attributes ) use ( $daymark_csp_nonce ): array {
		if ( isset( $attributes['id'] ) && in_array( $attributes['id'], array( 'daymark-app-js-before', 'daymark-app-js-translations' ), true ) ) {
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
