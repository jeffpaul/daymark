<?php
/**
 * OS share target, dispatched by WpApp.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Daymark_Plugin::instance()->share_target->handle();
