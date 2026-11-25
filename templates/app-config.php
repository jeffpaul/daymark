<?php
/**
 * Fresh authenticated offline bootstrap configuration, dispatched by WpApp.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Daymark_Routes::require_endpoint_access();
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
$daymark_config               = Daymark_Routes::build_app_config();
$daymark_config['localeData'] = Daymark_Routes::app_locale_data();
echo wp_json_encode( $daymark_config );
