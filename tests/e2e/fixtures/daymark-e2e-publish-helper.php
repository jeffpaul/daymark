<?php
/**
 * Plugin Name: Daymark E2E Publish Helper
 * Description: Registers a fake third-party publishing plugin so Daymark's
 *              awareness note ("also shared via …") is exercisable in E2E.
 *
 * @package Daymark
 */

define( 'DAYMARK_E2E_PUBLISH_HELPER', true );

add_filter(
	'daymark_publish_helper_plugins',
	static function ( $plugins ) {
		$plugins['e2e-helper'] = array(
			'label'     => 'Test Publicize',
			'constants' => array( 'DAYMARK_E2E_PUBLISH_HELPER' ),
		);

		return $plugins;
	}
);
