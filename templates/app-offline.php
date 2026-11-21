<?php
/**
 * Authenticated offline fallback shell, dispatched by WpApp.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Daymark_Routes::require_endpoint_access();
require __DIR__ . '/offline-shell.php';
