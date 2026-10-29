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
 * The copies come from two places: Daymark's own connector results
 * (`_daymark_external_posts`) and the copies publishing plugins such as
 * Jetpack Social or Share on Mastodon recorded themselves
 * (Daymark_Plugin_Copies, issue #558). When the Syndication Links plugin
 * is active it prints a post's syndication links itself, so Daymark adds
 * its addresses to that plugin's list instead of printing a second line.
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
		// Syndication Links' own per-plugin readers run at the default
		// priority, so its list already holds their addresses by now.
		add_filter( 'get_post_syndication_links', array( $this, 'add_to_syndication_links_plugin' ), 20, 2 );
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

		// Syndication Links prints the same addresses itself (they reach
		// it through add_to_syndication_links_plugin()).
		if ( self::syndication_links_plugin_active() ) {
			return $content;
		}

		$markup = $this->links_markup( (int) $post_id );

		return '' === $markup ? $content : $content . "\n" . $markup;
	}

	/**
	 * Every address a Mark was copied to, each once: Daymark's own
	 * connector results first, then publishing plugins' copies.
	 *
	 * @param int $post_id Mark post ID.
	 * @return array<int, array{url: string, label: string}>
	 */
	public static function links( int $post_id ): array {
		$links = array();
		$seen  = array();

		$external_posts = json_decode( (string) get_post_meta( $post_id, '_daymark_external_posts', true ), true );

		foreach ( is_array( $external_posts ) ? $external_posts : array() as $reference ) {
			if ( ! is_array( $reference ) || empty( $reference['external_url'] ) ) {
				continue;
			}

			$links[] = array(
				'url'   => (string) $reference['external_url'],
				'label' => (string) ( $reference['label'] ?? __( 'View', 'daymark' ) ),
			);
		}

		foreach ( Daymark_Plugin_Copies::for_post( $post_id ) as $copy ) {
			if ( '' === $copy['url'] ) {
				continue;
			}

			$links[] = array(
				'url'   => $copy['url'],
				'label' => $copy['label'],
			);
		}

		$unique = array();

		foreach ( $links as $link ) {
			$key = Daymark_Plugin_Copies::url_key( $link['url'] );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $link;
		}

		return $unique;
	}

	/**
	 * The "Also on" line for a Mark, or '' when it has no copies.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string
	 */
	public function links_markup( int $post_id ): string {
		$links = array();

		foreach ( self::links( $post_id ) as $link ) {
			$links[] = sprintf(
				'<a class="u-syndication" rel="syndication nofollow" href="%s">%s</a>',
				esc_url( $link['url'] ),
				esc_html( $link['label'] )
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

	/**
	 * Adds a Mark's copies to the Syndication Links plugin's list
	 * (`get_post_syndication_links`, Syndication Links 4.5.3), skipping any
	 * that plugin already has under another address for the same post
	 * (it saves XPoster's tweets as `twitter.com/{account}/status/{id}`,
	 * for example, while Daymark reads `x.com/i/web/status/{id}`).
	 *
	 * @param mixed $urls    Addresses Syndication Links has so far.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public function add_to_syndication_links_plugin( $urls, $post_id = 0 ) {
		$post_id = (int) $post_id;

		if ( ! is_array( $urls ) || $post_id <= 0 || '1' !== get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			return $urls;
		}

		$seen = array();

		foreach ( $urls as $url ) {
			if ( is_string( $url ) ) {
				$seen[ Daymark_Plugin_Copies::url_key( $url ) ] = true;
			}
		}

		foreach ( self::links( $post_id ) as $link ) {
			$key = Daymark_Plugin_Copies::url_key( $link['url'] );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$urls[]       = $link['url'];
		}

		return $urls;
	}

	/**
	 * Whether the Syndication Links plugin is active. Checked by its
	 * version constant (defined as soon as it loads), not by scanning
	 * installed plugins, since this runs while a page renders.
	 *
	 * @return bool
	 */
	public static function syndication_links_plugin_active(): bool {
		/**
		 * Filters whether Daymark treats the Syndication Links plugin as
		 * active, which makes Daymark hand it a Mark's copies instead of
		 * printing its own "Also on" line.
		 *
		 * @param bool $active Whether Syndication Links is loaded.
		 */
		return (bool) apply_filters( 'daymark_syndication_links_plugin_active', defined( 'SYNDICATION_LINKS_VERSION' ) );
	}
}
