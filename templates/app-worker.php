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
header( 'Content-Type: application/javascript; charset=utf-8' );
header( 'Cache-Control: no-store' );
echo Daymark_Routes::build_service_worker_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bundled JavaScript with plugin-owned substitutions.
