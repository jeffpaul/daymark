<?php
/**
 * Static mount points for the framework-owned offline document.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="daymark-app" class="daymark-shell">
	<p class="daymark-boot"><?php esc_html_e( 'Loading Daymark…', 'daymark' ); ?></p>
</div>
<noscript><p class="daymark-noscript"><?php esc_html_e( 'Daymark needs JavaScript. Please enable it and reload.', 'daymark' ); ?></p></noscript>
