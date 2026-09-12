<?php
/**
 * Detects an active IndieWeb plugin whose own behavior overlaps something
 * Daymark already implements natively, and surfaces it — informationally,
 * never by suppressing the other plugin's behavior — as a dismissible
 * Daymark Notifications item (issue #346).
 *
 * Detection only, never control: this class only ever reads whether a
 * plugin is active via the shared Daymark_Plugin_Detector, the same
 * posture every other cross-plugin awareness in this codebase already
 * takes (Daymark_Federated_Comments, Daymark_Publish_Helpers). It never
 * hooks into or suppresses another plugin's own save_post/the_content
 * filters — that was explicitly ruled out in issue #346 as a fragile,
 * invasive integration with no precedent anywhere in this codebase.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects overlapping IndieWeb plugins and tracks per-user dismissal.
 */
class Daymark_Plugin_Overlap {

	/**
	 * User meta key. Multi-value: one row per dismissed plugin key —
	 * same "per-user-per-thing" membership shape Daymark_Bookmarks
	 * already established (add_user_meta()/delete_user_meta(), not a
	 * single serialized array), just keyed by a fixed plugin identifier
	 * instead of an arbitrary post ID.
	 *
	 * @var string
	 */
	public const META_KEY = 'daymark_plugin_overlap_dismissed';

	/**
	 * The four plugins named in issue #346, and what each one overlaps.
	 *
	 * Detection signals (`slugs`/`classes`/`constants`) were confirmed
	 * directly against each plugin's own public GitHub source (this
	 * environment cannot fetch wordpress.org directly) rather than
	 * guessed — folder slug matches the plugin's own text domain/wp.org
	 * slug in every case:
	 *
	 * - Post Kinds (dshanske/indieweb-post-kinds): main file
	 *   `indieweb-post-kinds.php`, text domain `indieweb-post-kinds`,
	 *   main class `Post_Kinds_Plugin`.
	 * - Microformats 2 (indieweb/wordpress-uf2): main file `wp-uf2.php`,
	 *   text domain / wp.org slug `wp-uf2`, main class `UF2_Plugin`.
	 * - Syndication Links (dshanske/syndication-links): text domain /
	 *   slug `syndication-links`, version constant
	 *   `SYNDICATION_LINKS_VERSION`.
	 * - IndieBlocks (janboddez/indieblocks): text domain / slug
	 *   `indieblocks`, namespaced `IndieBlocks\Plugin` singleton.
	 *
	 * @var array<string, array{label: string, overlaps: string, slugs?: string[], classes?: string[], constants?: string[]}>
	 */
	private const OVERLAPS = array(
		'post-kinds'        => array(
			'label'    => 'Post Kinds',
			'overlaps' => 'may duplicate the Like, Repost, and Comment markup Daymark already renders on your Marks',
			'slugs'    => array( 'indieweb-post-kinds' ),
			'classes'  => array( 'Post_Kinds_Plugin' ),
		),
		'microformats2'     => array(
			'label'    => 'Microformats 2',
			'overlaps' => 'may duplicate the h-entry/h-card markup Daymark already renders on your Marks',
			'slugs'    => array( 'wp-uf2' ),
			'classes'  => array( 'UF2_Plugin' ),
		),
		'syndication-links' => array(
			'label'     => 'Syndication Links',
			'overlaps'  => 'may duplicate the syndication markup Daymark already renders for Bridgy backfeed',
			'slugs'     => array( 'syndication-links' ),
			'constants' => array( 'SYNDICATION_LINKS_VERSION' ),
		),
		'indieblocks'       => array(
			'label'    => 'IndieBlocks',
			'overlaps' => 'may overlap several of the above at once (its own kinds, webmention, microformats, and syndication features)',
			'slugs'    => array( 'indieblocks' ),
			'classes'  => array( 'IndieBlocks\\Plugin' ),
		),
	);

	/**
	 * Every currently-active overlapping plugin, keyed by plugin key —
	 * regardless of dismissal state (callers filter that separately).
	 *
	 * @return array<string, array{label: string, overlaps: string}>
	 */
	public function get_active_overlaps(): array {
		$active = array();

		foreach ( self::OVERLAPS as $key => $overlap ) {
			$signals = array(
				'slugs'     => $overlap['slugs'] ?? array(),
				'classes'   => $overlap['classes'] ?? array(),
				'constants' => $overlap['constants'] ?? array(),
			);

			if ( Daymark_Plugin_Detector::matches( $signals ) ) {
				$active[ $key ] = array(
					'label'    => $overlap['label'],
					'overlaps' => $overlap['overlaps'],
				);
			}
		}

		return $active;
	}

	/**
	 * Whether a given plugin key is a recognized overlap entry.
	 *
	 * @param string $plugin_key One of the OVERLAPS keys.
	 * @return bool
	 */
	public function is_known( string $plugin_key ): bool {
		return isset( self::OVERLAPS[ $plugin_key ] );
	}

	/**
	 * Every plugin key this user has dismissed.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public function get_dismissed( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		return array_map( 'strval', get_user_meta( $user_id, self::META_KEY, false ) );
	}

	/**
	 * Whether a user has already dismissed a given plugin's overlap notice.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $plugin_key One of the OVERLAPS keys.
	 * @return bool
	 */
	public function is_dismissed( int $user_id, string $plugin_key ): bool {
		return in_array( $plugin_key, $this->get_dismissed( $user_id ), true );
	}

	/**
	 * Dismisses a plugin's overlap notice for a user. A no-op if already
	 * dismissed — mirrors Daymark_Bookmarks::add()'s own idempotence.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $plugin_key One of the OVERLAPS keys.
	 * @return void
	 */
	public function dismiss( int $user_id, string $plugin_key ): void {
		if ( $user_id <= 0 || '' === $plugin_key || $this->is_dismissed( $user_id, $plugin_key ) ) {
			return;
		}

		add_user_meta( $user_id, self::META_KEY, $plugin_key );
	}
}
