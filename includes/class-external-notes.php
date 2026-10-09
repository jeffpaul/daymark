<?php
/**
 * Reads notes the site owner already wrote with Shortnotes or IndieBlocks.
 *
 * Both plugins store short notes in their own post type instead of `post`:
 *
 * - Shortnotes (https://wordpress.org/plugins/shortnotes/) registers
 *   `shortnote` (public, `show_in_rest`) on `init`. Confirmed against
 *   Shortnotes 1.7.0's `includes/post-type-note.php` (`get_slug()`).
 * - IndieBlocks (https://wordpress.org/plugins/indieblocks/) registers
 *   `indieblocks_note` (public) on `init` at priority 9, only when its
 *   Notes option (or its older "post types" option) is on. Confirmed
 *   against IndieBlocks 0.13.3's `includes/class-post-types.php`.
 *
 * Daymark shows these notes wherever it shows the site's own posts (issue
 * #492): the Timeline, Search, On this day, and Bookmarks. It only reads
 * them. It never creates, edits, or deletes one, and new Notes written in
 * Daymark are still `post` Marks (Product Principle 8).
 *
 * A note type counts only while its post type is registered, so turning
 * the plugin (or IndieBlocks' Notes option) off hides its notes again.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only access to other plugins' notes post types.
 */
class Daymark_External_Notes {

	/**
	 * Every notes post type Daymark knows how to read, keyed by post type,
	 * valued by the plugin that registers it.
	 *
	 * @var array<string, string>
	 */
	public const POST_TYPES = array(
		'shortnote'        => 'shortnotes',
		'indieblocks_note' => 'indieblocks',
	);

	/**
	 * The notes post types Daymark reads right now.
	 *
	 * @return string[] Registered post types from POST_TYPES, possibly empty.
	 */
	public static function post_types(): array {
		$registered = array_values( array_filter( array_keys( self::POST_TYPES ), 'post_type_exists' ) );

		/**
		 * Filters which other plugins' notes post types Daymark reads.
		 *
		 * Return an empty array to stop Daymark from showing Shortnotes or
		 * IndieBlocks notes. Only post types from the default list are
		 * honored, since Daymark knows nothing about any other type.
		 *
		 * @param string[] $post_types Default: each known notes post type that is registered.
		 */
		$filtered = apply_filters( 'daymark_read_external_notes', $registered );

		return array_values( array_intersect( $registered, is_array( $filtered ) ? $filtered : array() ) );
	}

	/**
	 * Whether a post type is one of the notes post types Daymark reads now.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_note_type( string $post_type ): bool {
		return in_array( $post_type, self::post_types(), true );
	}

	/**
	 * Whether a post type appears on the Timeline as the site's own content:
	 * `post`, or a notes post type Daymark reads.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_own_content_type( string $post_type ): bool {
		return 'post' === $post_type || self::is_note_type( $post_type );
	}

	/**
	 * The plugin a post's notes post type belongs to.
	 *
	 * @param int $post_id Post ID.
	 * @return string 'shortnotes', 'indieblocks', or '' when it is not a read note.
	 */
	public static function source_for( int $post_id ): string {
		$post_type = (string) get_post_type( $post_id );

		return self::is_note_type( $post_type ) ? self::POST_TYPES[ $post_type ] : '';
	}
}
