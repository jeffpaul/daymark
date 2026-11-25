<?php
/**
 * Metadata for the cacheable framework document.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<meta name="robots" content="noindex, nofollow" />
<meta name="color-scheme" content="light dark" />
<meta name="theme-color" content="#c93a06" media="(prefers-color-scheme: light)" />
<meta name="theme-color" content="#121212" media="(prefers-color-scheme: dark)" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<meta name="apple-mobile-web-app-title" content="Daymark" />
<meta http-equiv="Content-Security-Policy" content="<?php echo esc_attr( Daymark_Routes::offline_content_security_policy() ); ?>" />
<link rel="manifest" href="<?php echo esc_url( Daymark_Routes::app_url( 'manifest' ) ); ?>" />
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( Daymark_Routes::apple_touch_icon_url() ); ?>" />
<link rel="icon" href="<?php echo esc_url( Daymark_Routes::icon_url( 32 ) ); ?>" sizes="32x32" />
