<?php
/**
 * Bookmarklet popup, dispatched by WpApp.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Daymark_Plugin::instance()->bookmarklet->handle();
