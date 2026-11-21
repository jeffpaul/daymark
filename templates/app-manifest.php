<?php
/**
 * Public install manifest, dispatched by WpApp.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

header( 'Content-Type: application/manifest+json; charset=utf-8' );
echo wp_json_encode( Daymark_Routes::build_manifest() );
