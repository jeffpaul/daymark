<?php
/**
 * Theme-facing template tags for Featured Content (issue #401) — plain
 * global functions, matching WordPress's own `has_post_thumbnail()`/
 * `the_post_thumbnail()` calling convention, so a theme author reaches for
 * these the same way they already reach for core's own template tags. Kept
 * in their own file (rather than alongside Daymark_Featured_Content, which
 * every one of these delegates to) per WPCS's "a file holds either function
 * declarations or an OO structure, never both" rule.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'daymark_has_featured_content' ) ) {
	/**
	 * Theme-facing template tag: does this post have Featured Content set?
	 * Mirrors core's own `has_post_thumbnail()` calling convention.
	 *
	 * @param int|WP_Post|null $post Post ID/object, or null for the current post.
	 * @return bool
	 */
	function daymark_has_featured_content( $post = null ): bool {
		return Daymark_Featured_Content::has_featured_content( $post );
	}
}

if ( ! function_exists( 'daymark_get_featured_content' ) ) {
	/**
	 * Theme-facing template tag: a post's resolved Featured Content, for a
	 * theme that wants to inspect it directly rather than rendering
	 * Daymark_Featured_Content's own default markup.
	 *
	 * @param int|WP_Post|null $post Post ID/object, or null for the current post.
	 * @return array{type: string, data: array<string, mixed>}|array{}
	 */
	function daymark_get_featured_content( $post = null ): array {
		return Daymark_Featured_Content::get_featured_content( $post );
	}
}

if ( ! function_exists( 'daymark_the_featured_content' ) ) {
	/**
	 * Theme-facing template tag: echo a post's rendered Featured Content —
	 * for a theme that wants Featured Content somewhere other than (or in
	 * addition to) its own Featured Image slot; see
	 * Daymark_Featured_Content::maybe_replace_post_thumbnail_html() for the
	 * automatic Featured-Image-slot substitution every theme already gets.
	 * Mirrors core's own `the_post_thumbnail()` calling convention.
	 *
	 * @param int|WP_Post|null     $post Post ID/object, or null for the current post.
	 * @param array<string, mixed> $args Reserved for future rendering options.
	 * @return void
	 */
	function daymark_the_featured_content( $post = null, array $args = array() ): void {
		Daymark_Featured_Content::the_featured_content( $post, $args );
	}
}
