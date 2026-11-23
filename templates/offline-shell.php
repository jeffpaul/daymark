<?php
/**
 * Render the framework-owned offline document.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
\WpApp\Pwa::render_shell( Daymark_Routes::app_base(), true );
