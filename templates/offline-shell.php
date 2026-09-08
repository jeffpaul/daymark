<?php
/**
 * Daymark offline-fallback app shell (issue #126).
 *
 * Served by Daymark_Routes at GET /daymark/offline.html, and only ever
 * reached in two ways: precached at service-worker install time
 * (assets/daymark-sw.js), and replayed by that same worker's navigation
 * handler when a real fetch for /daymark* fails (no connectivity).
 *
 * Deliberately carries no per-request CSP nonce and no inline scripts at
 * all — unlike templates/app-shell.php, this page's own markup is cached
 * verbatim in Cache Storage and replayed as-is for however long the cache
 * entry lives, so a dynamic, single-use nonce baked into it would become a
 * stale, durably-inspectable, effectively static "secret" the moment it's
 * persisted — exactly the property a nonce exists to avoid. Sidestepping
 * that tension is the point: `script-src 'self'` (no 'unsafe-inline', no
 * nonce) is sufficient because every script here is external
 * (assets/offline-boot.js, then assets/app.js, both loaded via ordinary
 * <script src>, not inline), and CSP's script-src never governs a plain
 * `data-*` attribute either way.
 *
 * Bootstrap config normally arrives inline (see app-shell.php); here it's
 * fetched by assets/offline-boot.js from GET /daymark/config.json instead —
 * network-first when genuinely online, falling back to the service worker's
 * own redacted (nonce-stripped) cached copy when not. See that file and
 * assets/daymark-sw.js for the rest of the mechanism.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$daymark_offline_csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data: blob:; media-src 'self' https: blob:; connect-src 'self'; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'";

if ( ! headers_sent() ) {
	header( 'Content-Security-Policy: ' . $daymark_offline_csp );
}

wp_register_style( 'daymark-app', DAYMARK_PLUGIN_URL . 'assets/app.css', array(), DAYMARK_VERSION );
wp_enqueue_style( 'daymark-app' );
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
	<meta http-equiv="Content-Security-Policy" content="<?php echo esc_attr( $daymark_offline_csp ); ?>" />
	<title><?php esc_html_e( 'Daymark', 'daymark' ); ?></title>
	<link rel="manifest" href="<?php echo esc_url( Daymark_Routes::app_url( 'manifest.json' ) ); ?>" />
	<link rel="apple-touch-icon" href="<?php echo esc_url( Daymark_Routes::icon_url( 180 ) ); ?>" />
	<link rel="icon" href="<?php echo esc_url( Daymark_Routes::icon_url( 32 ) ); ?>" sizes="32x32" />
	<?php wp_print_styles( array( 'daymark-app' ) ); ?>
</head>
<body class="daymark-app daymark-app--home">
	<div id="daymark-app" class="daymark-shell">
		<p class="daymark-boot"><?php esc_html_e( 'Loading Daymark…', 'daymark' ); ?></p>
	</div>
	<noscript>
		<p class="daymark-noscript"><?php esc_html_e( 'Daymark needs JavaScript. Please enable it and reload.', 'daymark' ); ?></p>
	</noscript>
	<?php
	/*
	 * No window.daymarkApp inline script (see this file's own docblock) —
	 * offline-boot.js fetches it instead, then injects app.js's own <script>
	 * once that config is ready. data-config-url/data-app-js-url are read via
	 * document.currentScript, not window globals, so this stays correct even
	 * though this exact markup may be replayed from Cache Storage well after
	 * it was first generated.
	 */
	?>
	<script
		src="<?php echo esc_url( DAYMARK_PLUGIN_URL . 'assets/offline-boot.js' ); ?>"
		data-config-url="<?php echo esc_url( Daymark_Routes::app_url( 'config.json' ) ); ?>"
		data-app-js-url="<?php echo esc_url( DAYMARK_PLUGIN_URL . 'assets/app.js' ); ?>"
	></script>
</body>
</html>
