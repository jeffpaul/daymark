<?php
/**
 * POSSE-quality outbound microformats2 markup (issue #78).
 *
 * WordPress's active theme renders a Mark's own permalink page — the app
 * shell covers only Timeline/Explore/Search/Me, so any plugin adding mf2
 * markup through theme template hooks never reaches those app-shell views,
 * but a theme-rendered singular page is exactly where IndieWeb tooling
 * (readers, Bridgy, Webmention senders) looks for h-entry markup. This
 * class adds that markup the same way Daymark_Syndication_Links
 * already does: filters on post_class, the_title, and the_content, guarded
 * so they only touch a Mark's own singular main-query render.
 *
 * u-in-reply-to is not implemented. Nothing in the Mark data model
 * (_daymark_* meta, class-publisher.php) records a parent post — a Mark is
 * always an original post today, so there is no reply relationship to mark
 * up. Add it if that changes.
 *
 * u-email is deliberately left off the h-card. WordPress account emails are
 * not meant to be public and there is no separate public-contact-address
 * field to source one from instead. u-email is optional in the h-card spec,
 * so leaving it off does not break validation.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders h-entry, h-card, and rel=me markup on Mark posts.
 */
class Daymark_Microformats {

	/**
	 * User meta key storing a user's rel=me URL, set on their own
	 * Users -> Your Profile screen.
	 *
	 * @var string
	 */
	public const REL_ME_META_KEY = 'daymark_rel_me_url';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'post_class', array( $this, 'h_entry_class' ), 10, 3 );
		add_filter( 'the_title', array( $this, 'wrap_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'append_entry_markup' ), 8 );

		add_action( 'show_user_profile', array( $this, 'render_rel_me_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_rel_me_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_rel_me_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_rel_me_field' ) );
	}

	/**
	 * Add the h-entry class to a Mark's post_class output.
	 *
	 * No is_singular() guard: h-entry is valid anywhere a Mark's post_class
	 * renders, including an archive or other h-feed context, and every
	 * other h-entry-class check in this codebase gates on the
	 * _daymark_is_mark meta alone.
	 *
	 * post_class's real filter signature is ($classes, $class, $post_id) —
	 * three arguments. The unused middle $class parameter (extra classes a
	 * caller passed to post_class(), e.g. from a Query Loop block) still
	 * has to be declared, or WP only ever hands this callback the first two
	 * arguments and $post_id is never received.
	 *
	 * @param string[]        $classes     Existing post classes.
	 * @param string[]|string $extra_class Extra classes passed to post_class() by the caller. Unused.
	 * @param int             $post_id     Post ID.
	 * @return string[]
	 */
	public function h_entry_class( array $classes, $extra_class, int $post_id ): array {
		if ( '1' === get_post_meta( $post_id, '_daymark_is_mark', true ) ) {
			$classes[] = 'h-entry';
		}

		return $classes;
	}

	/**
	 * Wrap a Mark's title in its mf2 property span.
	 *
	 * A note's title is auto-generated from its caption, so it stands in
	 * for a summary rather than a distinct name — p-summary is the correct
	 * property there, not a second p-name for the same text.
	 *
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function wrap_title( string $title, int $post_id ): string {
		if ( ! $this->is_mark_singular_main_query( $post_id ) ) {
			return $title;
		}

		$property = 'note' === get_post_meta( $post_id, '_daymark_primary_type', true ) ? 'p-summary' : 'p-name';

		return '<span class="' . esc_attr( $property ) . '">' . $title . '</span>';
	}

	/**
	 * Wrap a Mark's content in e-content and append its mf2 metadata block.
	 *
	 * Runs at priority 8, ahead of Daymark_Syndication_Links' priority 20,
	 * so u-syndication links land outside e-content — matching IndieWeb
	 * convention that e-content is the entry's own content, not links to
	 * copies of it.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_entry_markup( string $content ): string {
		$post_id = get_the_ID();

		if ( ! $post_id || ! $this->is_mark_singular_main_query( (int) $post_id ) ) {
			return $content;
		}

		$post_id = (int) $post_id;

		return '<div class="e-content">' . $content . '</div>' . "\n" . $this->entry_metadata_markup( $post_id );
	}

	/**
	 * Whether the current post is a Mark being rendered in a singular main
	 * query loop — the same guard Daymark_Syndication_Links uses, so this
	 * markup never reaches wp-admin lists, feeds, or REST responses.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_mark_singular_main_query( int $post_id ): bool {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return false;
		}

		return 0 !== $post_id && '1' === get_post_meta( $post_id, '_daymark_is_mark', true );
	}

	/**
	 * Build the u-url/dt-published/rich-media/author metadata block for a
	 * Mark's entry markup.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string Escaped HTML.
	 */
	public function entry_metadata_markup( int $post_id ): string {
		$permalink = get_permalink( $post_id );
		$published = get_the_date( 'c', $post_id );

		$html  = '<div class="daymark-h-entry-meta">';
		$html .= '<a class="u-url" href="' . esc_url( (string) $permalink ) . '">' . esc_html( (string) $permalink ) . '</a>';
		$html .= '<time class="dt-published" datetime="' . esc_attr( $published ) . '">' . esc_html( (string) get_the_date( '', $post_id ) ) . '</time>';
		$html .= $this->rich_media_markup( $post_id );

		$post = get_post( $post_id );

		if ( $post instanceof WP_Post ) {
			$html .= $this->render_author_hcard( (int) $post->post_author );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build u-photo/u-video/u-audio markup from a Mark's attached media.
	 *
	 * Reads _daymark_media_ids — the same meta key Daymark_Publisher already
	 * uses — rather than parsing the rendered block HTML, which would be
	 * theme- and block-markup-dependent.
	 *
	 * @param int $post_id Mark post ID.
	 * @return string Escaped HTML, or '' when there is no attached media.
	 */
	public function rich_media_markup( int $post_id ): string {
		$raw       = get_post_meta( $post_id, '_daymark_media_ids', true );
		$media_ids = json_decode( is_string( $raw ) ? $raw : '', true );

		if ( ! is_array( $media_ids ) || array() === $media_ids ) {
			return '';
		}

		$html = '';

		foreach ( $media_ids as $media_id ) {
			$attachment_id = absint( $media_id );

			if ( 0 === $attachment_id ) {
				continue;
			}

			$url = wp_get_attachment_url( $attachment_id );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$mime = (string) get_post_mime_type( $attachment_id );

			if ( str_starts_with( $mime, 'image/' ) ) {
				$html .= '<img class="u-photo" src="' . esc_url( $url ) . '" alt="" />';
			} elseif ( str_starts_with( $mime, 'video/' ) ) {
				$html .= '<a class="u-video" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
			} elseif ( str_starts_with( $mime, 'audio/' ) ) {
				$html .= '<a class="u-audio" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
			}
		}

		return $html;
	}

	/**
	 * Render a Mark author's h-card, plus a rel=me link when the author has
	 * set one on their profile.
	 *
	 * Pure and public so tests can call it directly without simulating a
	 * full theme render.
	 *
	 * @param int $author_id Author user ID.
	 * @return string Escaped HTML.
	 */
	public function render_author_hcard( int $author_id ): string {
		$author = get_userdata( $author_id );

		if ( ! $author instanceof WP_User ) {
			return '';
		}

		$avatar_url = get_avatar_url( $author_id );

		$html  = '<a class="p-author h-card" href="' . esc_url( (string) get_author_posts_url( $author_id ) ) . '">';
		$html .= is_string( $avatar_url ) && '' !== $avatar_url
			? '<img class="u-photo" src="' . esc_url( $avatar_url ) . '" alt="" />'
			: '';
		$html .= '<span class="p-name">' . esc_html( $author->display_name ) . '</span>';
		$html .= '</a>';

		$rel_me_url = get_user_meta( $author_id, self::REL_ME_META_KEY, true );

		if ( is_string( $rel_me_url ) && '' !== $rel_me_url ) {
			$html .= ' <a rel="me" href="' . esc_url( $rel_me_url ) . '">' . esc_html( $rel_me_url ) . '</a>';
		}

		return $html;
	}

	/**
	 * Render the rel=me URL field on a user's profile screen.
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public function render_rel_me_field( WP_User $user ): void {
		wp_nonce_field( 'daymark_save_rel_me', 'daymark_rel_me_nonce' );

		$value = get_user_meta( $user->ID, self::REL_ME_META_KEY, true );
		?>
		<h2><?php esc_html_e( 'Daymark', 'daymark' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="daymark_rel_me_url"><?php esc_html_e( 'rel=me link', 'daymark' ); ?></label></th>
				<td>
					<input type="url" name="daymark_rel_me_url" id="daymark_rel_me_url" class="regular-text" value="<?php echo esc_attr( is_string( $value ) ? $value : '' ); ?>" />
					<p class="description"><?php esc_html_e( 'A profile URL to publish as rel="me" next to your Marks — for example a Mastodon or GitHub profile, so identity-verification tools can confirm this site is yours.', 'daymark' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the rel=me URL field.
	 *
	 * Gated on edit_user, the correct capability for this pair of core
	 * profile-save hooks — not the edit_posts convention used elsewhere in
	 * this plugin, which covers Daymark's own REST and admin surfaces, not
	 * core profile editing.
	 *
	 * @param int $user_id The user being saved.
	 * @return void
	 */
	public function save_rel_me_field( int $user_id ): void {
		if ( ! isset( $_POST['daymark_rel_me_nonce'] ) || ! check_admin_referer( 'daymark_save_rel_me', 'daymark_rel_me_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$url = isset( $_POST['daymark_rel_me_url'] ) ? esc_url_raw( wp_unslash( $_POST['daymark_rel_me_url'] ) ) : '';

		if ( '' === $url ) {
			delete_user_meta( $user_id, self::REL_ME_META_KEY );

			return;
		}

		update_user_meta( $user_id, self::REL_ME_META_KEY, $url );
	}
}
