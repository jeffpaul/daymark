<?php
/**
 * Syndication links markup (IndieWeb u-syndication).
 *
 * Appends an "Also on" line to singular Mark posts linking each
 * syndicated copy with `class="u-syndication" rel="syndication"`. That
 * markup is what IndieWeb tooling discovers — most notably Bridgy
 * (brid.gy), which watches the linked social copies and sends replies,
 * likes, and reposts back to this site as webmentions. Combined with the
 * Webmention plugin (and Daymark's federated-comment labeling), that is
 * backfeed for networks Daymark has no API connector for — free.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders u-syndication links on Mark posts.
 */
class Daymark_Syndication_Links {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'append_links' ), 20 );
	}

	/**
	 * Append syndication links to singular Mark content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_links( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || '1' !== get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return $content;
		}

		$markup = $this->links_markup( (int) $post_id );

		return '' === $markup ? $content : $content . "\n" . $markup;
	}

	/**
	 * Build the "Also on" paragraph for a Mark's external posts.
	 *
	 * Only references with a real URL are rendered; failed syndications
	 * have no entry to link.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string Escaped HTML, or '' when there is nothing to link.
	 */
	public function links_markup( int $post_id ): string {
		$external_posts = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );

		if ( ! is_array( $external_posts ) ) {
			return '';
		}

		$links = array();

		foreach ( $external_posts as $reference ) {
			if ( ! is_array( $reference ) || empty( $reference['external_url'] ) ) {
				continue;
			}

			$links[] = sprintf(
				'<a class="u-syndication" rel="syndication nofollow" href="%s">%s</a>',
				esc_url( (string) $reference['external_url'] ),
				esc_html( (string) ( $reference['label'] ?? __( 'View', 'daymark' ) ) )
			);
		}

		if ( array() === $links ) {
			return '';
		}

		return sprintf(
			'<p class="daymark-syndication-links">%s %s</p>',
			esc_html__( 'Also on:', 'daymark' ),
			implode( ' · ', $links )
		);
	}
}
