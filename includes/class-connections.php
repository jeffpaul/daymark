<?php
/**
 * The Me screen's Connections card: where this site's Marks go and the
 * sites it follows, at a glance (issue #295).
 *
 * Everything here is read from what Daymark already knows: the site's own
 * address, the sharing connectors and plugins the composer's "Where this
 * goes" sheet lists, and the followed-sites table. Nothing is written and
 * no outbound request is made.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Connections card's rows.
 */
class Daymark_Connections {

	/**
	 * Whether the ActivityPub plugin is active, so a published Mark also
	 * reaches the site's fediverse followers. The same signals the
	 * composer's "It also reaches…" note goes by.
	 *
	 * @return bool
	 */
	public static function activitypub_active(): bool {
		return Daymark_Plugin_Detector::matches(
			array(
				'slugs'     => array( 'activitypub' ),
				'constants' => array( 'ACTIVITYPUB_PLUGIN_VERSION' ),
			)
		);
	}

	/**
	 * Everything the Connections card shows the current user.
	 *
	 * `sharing_url` and `following.url` lead to Settings -> Daymark, so
	 * they are empty for anyone who can't open it. `following.url` is set
	 * only while a followed site is failing: it opens the list of those.
	 *
	 * @return array{
	 *     site: array{address: string, url: string},
	 *     sharing: array<int, array{id: string, name: string, network: string, detail: string}>,
	 *     sharing_url: string,
	 *     following: array{count: int, failing: int, url: string}
	 * }
	 */
	public static function summary(): array {
		$can_manage = current_user_can( Daymark_Admin_Subscriptions::CAPABILITY );
		$counts     = Daymark_Plugin::instance()->subscriptions->counts();

		return array(
			'site'        => array(
				'address' => self::site_address(),
				'url'     => esc_url_raw( home_url( '/' ) ),
			),
			'sharing'     => self::sharing(),
			'sharing_url' => $can_manage ? esc_url_raw( Daymark_Admin_Subscriptions::tab_url( 'connectors' ) ) : '',
			'following'   => array(
				'count'   => $counts['total'],
				'failing' => $counts['failing'],
				'url'     => $can_manage && $counts['failing'] > 0
					? esc_url_raw( add_query_arg( 'view', 'failing', Daymark_Admin_Subscriptions::tab_url( 'subscriptions' ) ) )
					: '',
			),
		);
	}

	/**
	 * The site's address as people say it: no scheme, no "www.", no
	 * trailing slash ("example.com", or "example.com/blog" for a site in a
	 * folder).
	 *
	 * @return string
	 */
	private static function site_address(): string {
		$parts = wp_parse_url( home_url( '/' ) );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return sanitize_text_field( rtrim( $host . $path, '/' ) );
	}

	/**
	 * Where a published Mark also goes, one row each, in the order the
	 * composer's "Where this goes" sheet lists them: connected
	 * destinations, sharing plugins with a per-Mark switch, the fediverse
	 * through the ActivityPub plugin, then sharing plugins that share on
	 * their own.
	 *
	 * `name` is the network when the row names one, else the connector or
	 * plugin. `network` is that network's plain name for the row's icon,
	 * or ''. `detail` is the account the plugin shares as when it says,
	 * else how the Mark gets there.
	 *
	 * @return array<int, array{id: string, name: string, network: string, detail: string}>
	 */
	public static function sharing(): array {
		$rows = array();

		foreach ( Daymark_Syndication_Registry::instance()->get_connectors() as $connector ) {
			if ( ! $connector->is_connected() ) {
				continue;
			}

			$label  = sanitize_text_field( (string) $connector->get_label() );
			$rows[] = array(
				'id'      => 'connector-' . sanitize_key( (string) $connector->get_id() ),
				'name'    => $label,
				'network' => $label,
				'detail'  => sanitize_text_field( (string) $connector->get_status_label() ),
			);
		}

		$controllable = Daymark_Publish_Helpers::controllable();

		foreach ( $controllable as $helper ) {
			$network = (string) ( $helper['network'] ?? '' );
			$plugin  = (string) ( $helper['plugin'] ?? '' );
			$account = (string) ( $helper['account'] ?? '' );

			if ( '' !== $account ) {
				$detail = $account;
			} elseif ( '' !== $network ) {
				$detail = self::through( $plugin );
			} else {
				$detail = __( 'Shares the Marks you choose', 'daymark' );
			}

			$rows[] = array(
				'id'      => 'helper-' . (string) $helper['id'],
				'name'    => '' !== $network ? $network : $plugin,
				'network' => $network,
				'detail'  => $detail,
			);
		}

		if ( self::activitypub_active() ) {
			$rows[] = array(
				'id'      => 'activitypub',
				'name'    => __( 'Fediverse', 'daymark' ),
				'network' => '',
				'detail'  => self::through( 'ActivityPub' ),
			);
		}

		$controllable_ids = array_column( $controllable, 'id' );

		foreach ( Daymark_Publish_Helpers::detect() as $helper ) {
			if ( in_array( $helper['id'], $controllable_ids, true ) ) {
				continue;
			}

			$rows[] = array(
				'id'      => 'helper-' . (string) $helper['id'],
				'name'    => (string) $helper['label'],
				'network' => '',
				'detail'  => __( 'Shares by its own settings', 'daymark' ),
			);
		}

		return $rows;
	}

	/**
	 * "Through ATmosphere": how a Mark gets to a network.
	 *
	 * @param string $plugin The plugin's name.
	 * @return string
	 */
	private static function through( string $plugin ): string {
		return sprintf(
			/* translators: %s: the plugin that shares to a network, such as ATmosphere */
			__( 'Through %s', 'daymark' ),
			$plugin
		);
	}
}
