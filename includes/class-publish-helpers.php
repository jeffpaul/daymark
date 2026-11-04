<?php
/**
 * Detection of third-party publishing plugins.
 *
 * A Mark is a standard post, so any active "publicize"-style plugin
 * (Jetpack Social, Share on Mastodon, XPoster, …) already syndicates
 * Marks on publish through its own hooks — Daymark neither drives nor
 * blocks them. This class only *detects* those plugins so the publish
 * screen can tell the user their Mark will also go out that way. It
 * does not call them, configure them, or change their behavior.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects popular social-publishing plugins for awareness-only surfacing.
 */
class Daymark_Publish_Helpers {

	/**
	 * Known publishing-helper plugins and how to detect each.
	 *
	 * A plugin is detected if any of its `slugs` (plugin folder) is active,
	 * or any of its `classes`/`functions`/`constants` exists at runtime —
	 * the latter being more precise (e.g. Jetpack's Publicize class only
	 * loads when that module is on).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PLUGINS = array(
		'jetpack'               => array(
			'label'   => 'Jetpack Social',
			'classes' => array( 'Automattic\\Jetpack\\Publicize\\Publicize' ),
			'slugs'   => array( 'jetpack-social' ),
		),
		'atmosphere'            => array(
			'label'     => 'ATmosphere',
			// `atmosphere` is the wordpress.org folder; `wordpress-atmosphere`
			// is the GitHub one. The class and constant cover any other.
			'slugs'     => array( 'atmosphere', 'wordpress-atmosphere' ),
			'classes'   => array( 'Atmosphere\\Publisher' ),
			'constants' => array( 'ATMOSPHERE_VERSION' ),
		),
		'autoblue'              => array(
			'label' => 'Autoblue',
			'slugs' => array( 'autoblue' ),
		),
		'share-on-mastodon'     => array(
			'label' => 'Share on Mastodon',
			'slugs' => array( 'share-on-mastodon' ),
		),
		'xposter'               => array(
			'label'     => 'XPoster',
			'slugs'     => array( 'wp-to-twitter' ),
			'functions' => array( 'wpt_post_to_service' ),
		),
		'autoshare-for-twitter' => array(
			'label' => 'Autopost for X',
			'slugs' => array( 'autoshare-for-twitter' ),
		),
		'blog2social'           => array(
			'label' => 'Blog2Social',
			'slugs' => array( 'blog2social' ),
		),
		'snap'                  => array(
			'label' => 'Social Networks Auto-Poster (SNAP)',
			'slugs' => array( 'social-networks-auto-poster-facebook-twitter-g' ),
		),
		'revive-old-posts'      => array(
			'label' => 'Revive Old Posts',
			'slugs' => array( 'tweet-old-post' ),
		),
	);

	/**
	 * Post meta recording which controllable helpers a Mark opted into.
	 */
	const CONTROL_META = '_daymark_publish_helpers';

	/**
	 * Controllable adapters: active publishing plugins Daymark can drive
	 * per-Mark through the plugin's OWN public per-post control: its
	 * filter, or the per-post switch its own editor panel saves
	 * (ATmosphere's `atmosphere_disabled`, Share on Mastodon's
	 * `_share_on_mastodon`), never the plugin's internal state. Only these
	 * get an in-app toggle; other detected plugins stay awareness-only.
	 *
	 * Each `bind` registers the plugin's control filter with a callback
	 * that defers to Daymark's per-post selection for Mark posts and
	 * leaves every other post's decision untouched.
	 *
	 * An adapter is detected with the same signals as its entry in
	 * PLUGINS (folder, class, function, constant), so the toggle and the
	 * awareness note can't disagree about whether the plugin is there. An
	 * adapter registered through the filter may bring its own signals.
	 *
	 * `network` names where the plugin shares to, so the toggle reads as a
	 * destination ("Bluesky via ATmosphere"). `default` is where the toggle
	 * starts for a new Mark: where the plugin would land on its own.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function adapters(): array {
		$adapters = array(
			'atmosphere'            => array(
				'label'     => 'ATmosphere',
				'network'   => 'Bluesky',
				// ATmosphere shares every post unless the post is switched
				// off (its `atmosphere_disabled` opt-out), so the toggle
				// starts on.
				'default'   => true,
				// Only a meaningful toggle when ATmosphere is connected AND
				// actually auto-publishing; in connection-only mode it posts
				// nothing, so there is nothing to gate per Mark.
				'available' => static function () {
					return function_exists( 'Atmosphere\\is_connected' )
						&& function_exists( 'Atmosphere\\is_auto_publish_enabled' )
						&& \Atmosphere\is_connected()
						&& \Atmosphere\is_auto_publish_enabled();
				},
				// ATmosphere exposes no per-post filter; its documented
				// per-post control is the `atmosphere_disabled` opt-out meta.
				// Translate the Mark's toggle into that meta just before
				// ATmosphere auto-publishes on the publish transition.
				'bind'      => static function () {
					add_action(
						'transition_post_status',
						static function ( $new_status, $old_status, $post ) {
							$selection = self::publishing_selection( $new_status, $post );

							// No Daymark selection → leave ATmosphere's own behavior alone.
							if ( null === $selection ) {
								return;
							}

							if ( in_array( 'atmosphere', $selection, true ) ) {
								delete_post_meta( $post->ID, 'atmosphere_disabled' );
							} else {
								update_post_meta( $post->ID, 'atmosphere_disabled', '1' );
							}
						},
						5,
						3
					);
				},
			),
			// Share on Mastodon and Autopost for X start off. Both put off
			// sharing a post saved over the REST API until
			// `rest_after_insert_{post_type}`, which Daymark's own Mark
			// routes fire once a save from the app is finished (see
			// Daymark_REST_Controller::fire_rest_after_insert()).
			'share-on-mastodon'     => array(
				'label'   => 'Share on Mastodon',
				'network' => 'Mastodon',
				'bind'    => static function () {
					add_filter(
						'share_on_mastodon_enabled',
						static function ( $enabled, $post_id ) {
							return self::decide( (int) $post_id, 'share-on-mastodon', (bool) $enabled );
						},
						10,
						2
					);

					// Share on Mastodon skips a post dated more than 12 hours
					// ago unless the post's own switch, `_share_on_mastodon`
					// (what its block editor panel saves), is on, and it checks
					// that before the filter above. A Mark dated by an older
					// photo, or a draft started yesterday, is that old, so the
					// Mark's choice is copied into that switch as it goes live.
					add_action(
						'transition_post_status',
						static function ( $new_status, $old_status, $post ) {
							$selection = self::publishing_selection( $new_status, $post );

							if ( null !== $selection ) {
								update_post_meta( $post->ID, '_share_on_mastodon', in_array( 'share-on-mastodon', $selection, true ) ? '1' : '0' );
							}
						},
						5,
						3
					);
				},
			),
			'autoshare-for-twitter' => array(
				'label'   => 'Autopost for X',
				'network' => 'X',
				'bind'    => static function () {
					add_filter(
						'autoshare_for_twitter_enabled_default',
						static function ( $enabled_default, $post_type, $post_id ) {
							return self::decide( (int) $post_id, 'autoshare-for-twitter', (bool) $enabled_default );
						},
						10,
						3
					);
				},
			),
		);

		/**
		 * Filter the controllable publishing-helper adapters. Each entry is
		 * keyed by id and carries `label` and `bind`, where `bind` is a
		 * callable that registers the plugin's own public per-post control
		 * filter. Optional keys: `slugs`, `classes`, `functions`, and
		 * `constants` to detect the plugin (otherwise the matching PLUGINS
		 * entry's are used), `available` (a callable gate), `network` (the
		 * service the plugin shares to), and `default` (bool, whether the
		 * toggle starts on for a new Mark; off when omitted).
		 *
		 * @param array<string, array<string, mixed>> $adapters Adapter map.
		 */
		$adapters = apply_filters( 'daymark_publish_helper_adapters', $adapters );

		return is_array( $adapters ) ? $adapters : array();
	}

	/**
	 * Active controllable helpers, for the publish-screen toggles. Each is
	 * [{id, label, plugin, network, default}]: `label` is the toggle's text
	 * ("Bluesky via ATmosphere", or just the plugin's name when it names no
	 * network), `plugin` the plugin's name, and `default` whether the toggle
	 * starts on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function controllable(): array {
		$active = self::active_plugin_slugs();
		$found  = array();

		foreach ( self::adapters() as $id => $def ) {
			if ( ! self::adapter_active( (string) $id, $def, $active ) ) {
				continue;
			}

			$plugin  = sanitize_text_field( (string) ( $def['label'] ?? $id ) );
			$network = sanitize_text_field( (string) ( $def['network'] ?? '' ) );

			$found[] = array(
				'id'      => sanitize_key( (string) $id ),
				'label'   => '' !== $network
					? sprintf(
						/* translators: 1: a social network, such as Bluesky, 2: the plugin that shares to it, such as ATmosphere */
						__( '%1$s via %2$s', 'daymark' ),
						$network,
						$plugin
					)
					: $plugin,
				'plugin'  => $plugin,
				'network' => $network,
				'default' => ! empty( $def['default'] ),
			);
		}

		return $found;
	}

	/**
	 * Whether an adapter's plugin is active and its optional `available`
	 * gate passes.
	 *
	 * @param string   $id     Adapter id.
	 * @param mixed    $def    Adapter definition.
	 * @param string[] $active Active plugin folder slugs.
	 * @return bool
	 */
	private static function adapter_active( string $id, $def, array $active ): bool {
		return is_array( $def )
			&& self::matches( self::adapter_signals( $id, $def ), $active )
			&& self::adapter_available( $def );
	}

	/**
	 * The detection signals for an adapter: its own, when it declares any,
	 * otherwise its PLUGINS entry's.
	 *
	 * @param string               $id  Adapter id.
	 * @param array<string, mixed> $def Adapter definition.
	 * @return array<string, mixed>
	 */
	private static function adapter_signals( string $id, array $def ): array {
		foreach ( array( 'slugs', 'classes', 'functions', 'constants' ) as $key ) {
			if ( ! empty( $def[ $key ] ) ) {
				return $def;
			}
		}

		$defs = self::definitions();

		return isset( $defs[ $id ] ) && is_array( $defs[ $id ] ) ? $defs[ $id ] : array();
	}

	/**
	 * Whether an adapter's optional `available` gate passes (adapters with
	 * no gate are always available when their plugin is active).
	 *
	 * @param array<string, mixed> $def Adapter definition.
	 * @return bool
	 */
	private static function adapter_available( array $def ): bool {
		if ( isset( $def['available'] ) && is_callable( $def['available'] ) ) {
			return (bool) ( $def['available'] )();
		}

		return true;
	}

	/**
	 * Ids of active controllable helpers.
	 *
	 * @return string[]
	 */
	public static function controllable_ids(): array {
		return array_column( self::controllable(), 'id' );
	}

	/**
	 * Register the control filters for every active controllable helper.
	 * Called on init; the filters gate on per-post Daymark selection, so
	 * they never affect non-Mark posts.
	 *
	 * @return void
	 */
	public static function register_adapters(): void {
		$active = self::active_plugin_slugs();

		foreach ( self::adapters() as $id => $def ) {
			if ( self::adapter_active( (string) $id, $def, $active ) && isset( $def['bind'] ) && is_callable( $def['bind'] ) ) {
				( $def['bind'] )();
			}
		}
	}

	/**
	 * The share decision for one helper on one post: governs only Daymark
	 * posts that recorded a selection, and returns the plugin's own
	 * fallback for everything else.
	 *
	 * @param int    $post_id  Post being evaluated.
	 * @param string $id       Controllable helper id.
	 * @param bool   $fallback The plugin's own default decision.
	 * @return bool
	 */
	private static function decide( int $post_id, string $id, bool $fallback ): bool {
		$selection = self::selection( $post_id );

		// No recorded selection → don't interfere with the plugin's default.
		if ( null === $selection ) {
			return $fallback;
		}

		return in_array( $id, $selection, true );
	}

	/**
	 * The helper ids a Mark recorded, or null when the post isn't a Mark or
	 * recorded no choice (an API caller that left `publish_helpers` out).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]|null
	 */
	private static function selection( int $post_id ): ?array {
		if ( '1' !== (string) get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return null;
		}

		$selection = json_decode( (string) get_post_meta( $post_id, self::CONTROL_META, true ), true );

		return is_array( $selection ) ? $selection : null;
	}

	/**
	 * For a `transition_post_status` callback: the recorded helper ids of a
	 * Mark that is going live, or null for anything else.
	 *
	 * @param string $new_status The post's new status.
	 * @param mixed  $post       The post.
	 * @return string[]|null
	 */
	private static function publishing_selection( string $new_status, $post ): ?array {
		if ( 'publish' !== $new_status || ! $post instanceof WP_Post ) {
			return null;
		}

		return self::selection( $post->ID );
	}

	/**
	 * The detectable plugin definitions, filterable so other publishing
	 * plugins can make themselves known to Daymark's awareness note.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		/**
		 * Filter the map of detectable publishing-helper plugins.
		 *
		 * @param array<string, array<string, mixed>> $plugins id => definition,
		 *        where a definition may carry `label`, `slugs`, `classes`,
		 *        `functions`, and `constants`.
		 */
		$defs = apply_filters( 'daymark_publish_helper_plugins', self::PLUGINS );

		return is_array( $defs ) ? $defs : array();
	}

	/**
	 * Active publishing-helper plugins as [{id, label}], for surfacing in
	 * the composer's "Where this goes" sheet. Awareness only.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function detect(): array {
		$active = self::active_plugin_slugs();
		$found  = array();

		foreach ( self::definitions() as $id => $def ) {
			if ( is_array( $def ) && self::matches( $def, $active ) ) {
				$found[] = array(
					'id'    => sanitize_key( (string) $id ),
					'label' => sanitize_text_field( (string) ( $def['label'] ?? $id ) ),
				);
			}
		}

		return $found;
	}

	/**
	 * Whether a definition matches the current site.
	 *
	 * @param array<string, mixed> $def          Plugin definition.
	 * @param string[]             $active_slugs Active plugin folder slugs.
	 * @return bool
	 */
	private static function matches( array $def, array $active_slugs ): bool {
		foreach ( (array) ( $def['slugs'] ?? array() ) as $slug ) {
			if ( in_array( $slug, $active_slugs, true ) ) {
				return true;
			}
		}
		foreach ( (array) ( $def['classes'] ?? array() ) as $class ) {
			if ( class_exists( (string) $class ) ) {
				return true;
			}
		}
		foreach ( (array) ( $def['functions'] ?? array() ) as $fn ) {
			if ( function_exists( (string) $fn ) ) {
				return true;
			}
		}
		foreach ( (array) ( $def['constants'] ?? array() ) as $const ) {
			if ( defined( (string) $const ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Folder slugs of all active plugins (site + network).
	 *
	 * @return string[]
	 */
	private static function active_plugin_slugs(): array {
		$paths = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$paths = array_merge( $paths, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_map(
			static function ( $path ) {
				return strtok( (string) $path, '/' );
			},
			$paths
		);
	}
}
