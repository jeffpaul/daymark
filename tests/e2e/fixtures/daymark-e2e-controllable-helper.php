<?php
/**
 * Plugin Name: Daymark E2E Controllable Helper
 * Description: Registers a fake controllable publishing helper so the E2E
 *              suite can exercise the per-Mark helper toggle end to end.
 *
 * @package Daymark
 */

// Make the fake helper's slug appear active so it is offered as controllable.
add_filter(
	'option_active_plugins',
	static function ( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		if ( ! in_array( 'daymark-e2e-helper/daymark-e2e-helper.php', $plugins, true ) ) {
			$plugins[] = 'daymark-e2e-helper/daymark-e2e-helper.php';
		}

		return $plugins;
	}
);

// Register the controllable adapter. The bind records, on the publish
// transition, whether this Mark opted the helper in — asserting the
// selection meta is in place before third-party publish hooks run.
add_filter(
	'daymark_publish_helper_adapters',
	static function ( $adapters ) {
		$adapters['daymark-e2e-helper'] = array(
			'label' => 'E2E Helper',
			'slugs' => array( 'daymark-e2e-helper' ),
			'bind'  => static function () {
				add_action(
					'transition_post_status',
					static function ( $new_status, $old_status, $post ) {
						if ( 'publish' !== $new_status || 'publish' === $old_status ) {
							return;
						}
						if ( '1' !== (string) get_post_meta( $post->ID, '_daymark_is_mark', true ) ) {
							return;
						}
						$selection = json_decode( (string) get_post_meta( $post->ID, '_daymark_publish_helpers', true ), true );
						if ( is_array( $selection ) && in_array( 'daymark-e2e-helper', $selection, true ) ) {
							update_post_meta( $post->ID, '_e2e_helper_fired', '1' );
						}
					},
					10,
					3
				);
			},
		);

		return $adapters;
	}
);
