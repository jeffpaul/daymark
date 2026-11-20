<?php
/**
 * E2E fixture: one font in the Font Library.
 *
 * Gives the active theme a font family, "Daymark E2E Font", the way a
 * theme.json would, so the font setting spec has a font to pick whatever
 * theme the E2E site runs. Its one file is a font WordPress itself ships,
 * so the browser has something real to load.
 *
 * Copied into mu-plugins by the Playwright CI job's Seed step — never part
 * of the distributed plugin.
 */

add_filter(
	'wp_theme_json_data_theme',
	static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'typography' => array(
						'fontFamilies' => array(
							array(
								'name'       => 'Daymark E2E Font',
								'slug'       => 'daymark-e2e-font',
								'fontFamily' => '"Daymark E2E Font", serif',
								'fontFace'   => array(
									array(
										'fontFamily' => 'Daymark E2E Font',
										'fontStyle'  => 'normal',
										'fontWeight' => '400',
										'src'        => array( includes_url( 'fonts/dashicons.woff2' ) ),
									),
								),
							),
						),
					),
				),
			)
		);
	}
);
