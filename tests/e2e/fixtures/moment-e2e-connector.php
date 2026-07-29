<?php
/**
 * Plugin Name: Moment E2E Connector
 * Description: Registers a fake CONNECTED syndication connector so the E2E
 *              suite can exercise the connected-destination publish path —
 *              the toggle rendering, per-type memory, and syndication result
 *              that ship in the plugin but no default install exercises.
 *              Test-only; never shipped in the plugin zip.
 *
 * @package Moment
 */

add_action(
	'moment_register_connectors',
	static function ( $registry ) {
		// Opt-in per request via a cookie the connector spec sets, so every
		// other E2E test keeps the "nothing connected → Your Site only"
		// baseline and only this one test sees a connected destination.
		if ( empty( $_COOKIE['moment_e2e_connector'] ) ) {
			return;
		}

		// The interface only exists once Moment (a regular plugin) has loaded;
		// this action fires on init, after mu-plugins, so it is safe here.
		if ( ! interface_exists( 'Moment_Syndication_Connector' ) ) {
			return;
		}

		$registry->register_connector(
			new class() implements Moment_Syndication_Connector {

				public function get_id(): string {
					return 'e2e-net';
				}

				public function get_label(): string {
					return 'E2E Network';
				}

				public function supports_moment_type( string $type ): bool {
					unset( $type ); // Accepts every Moment type.
					return true;
				}

				public function is_connected(): bool {
					return true;
				}

				public function get_status_label(): string {
					return 'Connected · E2E';
				}

				public function publish( int $post_id, array $payload ): array {
					unset( $payload );
					$external_id = 'e2e-' . $post_id;

					return array(
						'success'            => true,
						'external_id'        => $external_id,
						'external_url'       => 'https://e2e.test/post/' . $external_id,
						'status'             => 'published',
						'message'            => 'Published to E2E Network.',
						// Marks the reference eligible for automatic backflow.
						'backflow_supported' => true,
					);
				}
			}
		);
	}
);
