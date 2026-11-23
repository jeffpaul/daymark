<?php
/**
 * App-scoped offline and push worker, dispatched by WpApp.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Daymark_Routes::require_endpoint_access();
\WpApp\Pwa::maybe_handle_app_request( Daymark_Routes::app_base(), 'worker' );
