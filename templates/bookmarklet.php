<?php
/**
 * Daymark bookmarklet popup (see Daymark_Bookmarklet).
 *
 * With no page address: the install page, with the link to drag to the
 * bookmarks bar. With one: Reblog and Like for that page, and Follow for
 * its site.
 *
 * Plain server-rendered forms. The one script (assets/bookmarklet.js) only
 * adds the Close button, so everything works with JavaScript off.
 *
 * @package Daymark
 *
 * @var array{target: array{url: string, title: string, author: string, host: string}, state: array<string, mixed>|null, follow: array{site: string, following: string, lookup: array<string, mixed>|null}|null, done: string, mark: WP_Post|null, error: string, script: string} $daymark_bookmarklet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$daymark_bm_target = $daymark_bookmarklet['target'];
$daymark_bm_state  = $daymark_bookmarklet['state'];
$daymark_bm_follow = $daymark_bookmarklet['follow'] ?? null;
$daymark_bm_done   = $daymark_bookmarklet['done'];
$daymark_bm_mark   = $daymark_bookmarklet['mark'];

// The feeds found for this page, when the reader looked them up: the
// picker shows them, and takes the page's focus from the Reblog field.
$daymark_bm_lookup     = is_array( $daymark_bm_follow ) ? $daymark_bm_follow['lookup'] : null;
$daymark_bm_candidates = is_array( $daymark_bm_lookup ) && is_array( $daymark_bm_lookup['candidates'] ?? null ) ? array_values( $daymark_bm_lookup['candidates'] ) : array();
$daymark_bm_open_feeds = array_values( array_filter( $daymark_bm_candidates, static fn( $candidate ): bool => is_array( $candidate ) && empty( $candidate['subscribed'] ) ) );
$daymark_bm_default    = (int) ( $daymark_bm_lookup['default_index'] ?? 0 );

$daymark_bm_recommended = ! empty( $daymark_bm_open_feeds ) && in_array( $daymark_bm_default, array_map( static fn( array $candidate ): int => (int) ( $candidate['index'] ?? -1 ), $daymark_bm_open_feeds ), true );

// Preselect the feed Daymark recommends, or the first one not yet followed,
// and name the recommended one only when there's a choice to make.
if ( ! $daymark_bm_recommended && ! empty( $daymark_bm_open_feeds ) ) {
	$daymark_bm_default = (int) ( $daymark_bm_open_feeds[0]['index'] ?? 0 );
}

$daymark_bm_recommended = $daymark_bm_recommended && count( $daymark_bm_candidates ) > 1;

/**
 * Print the hidden fields every popup form carries.
 *
 * @param array{url: string, title: string, author: string} $target Page details.
 * @param string                                            $action Form action.
 * @return void
 */
$daymark_bm_hidden = static function ( array $target, string $action ): void {
	wp_nonce_field( Daymark_Bookmarklet::NONCE_ACTION );
	printf( '<input type="hidden" name="daymark_action" value="%s" />', esc_attr( $action ) );
	printf( '<input type="hidden" name="u" value="%s" />', esc_attr( $target['url'] ) );
	printf( '<input type="hidden" name="t" value="%s" />', esc_attr( $target['title'] ) );
	printf( '<input type="hidden" name="a" value="%s" />', esc_attr( $target['author'] ) );
};

wp_register_style( 'daymark-app', DAYMARK_PLUGIN_URL . 'assets/app.css', array(), DAYMARK_VERSION );
wp_register_script( 'daymark-bookmarklet', DAYMARK_PLUGIN_URL . 'assets/bookmarklet.js', array(), DAYMARK_VERSION, true );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<meta name="color-scheme" content="light dark" />
	<title><?php echo '' !== $daymark_bm_target['url'] ? esc_html__( 'Reblog or Like – Daymark', 'daymark' ) : esc_html__( 'Daymark bookmarklet', 'daymark' ); ?></title>
	<link rel="icon" href="<?php echo esc_url( Daymark_Routes::daymark_icon_url( 32 ) ); ?>" sizes="32x32" />
	<?php wp_print_styles( array( 'daymark-app' ) ); ?>
</head>
<body class="daymark-app daymark-bookmarklet">
	<main class="daymark-shell daymark-bookmarklet__shell">
		<header class="daymark-topbar">
			<?php if ( '' === $daymark_bm_target['url'] ) : ?>
				<?php // The install page is reached from Me in the app; this is the same back link the app's own screens use. ?>
				<a class="daymark-backlink daymark-backlink--icon" href="<?php echo esc_url( Daymark_Routes::app_url( 'me' ) ); ?>" aria-label="<?php esc_attr_e( 'Back to Me', 'daymark' ); ?>"><span aria-hidden="true">&larr;</span><img src="<?php echo esc_url( Daymark_Routes::daymark_icon_url( 192 ) ); ?>" alt="" width="22" height="22" /></a>
			<?php else : ?>
				<?php // The Reblog/Like popup opens from another site, so it has no app screen to go back to; it gets a Close button instead. ?>
				<img class="daymark-bookmarklet__icon" src="<?php echo esc_url( Daymark_Routes::daymark_icon_url( 192 ) ); ?>" alt="" width="26" height="26" />
			<?php endif; ?>
			<h1 class="daymark-topbar__title">
				<?php echo '' !== $daymark_bm_target['url'] ? esc_html__( 'Share to Daymark', 'daymark' ) : esc_html__( 'Daymark bookmarklet', 'daymark' ); ?>
			</h1>
			<button type="button" class="daymark-btn daymark-btn--text daymark-bookmarklet__close" data-bookmarklet-close hidden><?php esc_html_e( 'Close', 'daymark' ); ?></button>
		</header>

		<section class="daymark-screen daymark-bookmarklet__screen">
		<?php if ( '' === $daymark_bm_target['url'] ) : ?>
			<p><?php esc_html_e( 'Reblog or Like a post while you read it anywhere on the web. A Reblog is published here on your site; a Like reaches the post’s own site when it can receive one.', 'daymark' ); ?></p>
			<?php if ( Daymark_Bookmarklet::can_follow() ) : ?>
				<p><?php esc_html_e( 'You can also follow the site you’re reading, so its new posts show up in your Timeline.', 'daymark' ); ?></p>
			<?php endif; ?>
			<h2 class="daymark-section-heading"><?php esc_html_e( 'Install it', 'daymark' ); ?></h2>
			<ol class="daymark-bookmarklet__steps">
				<li><?php esc_html_e( 'Show your browser’s bookmarks bar.', 'daymark' ); ?></li>
				<li><?php esc_html_e( 'Drag this button to the bookmarks bar:', 'daymark' ); ?></li>
			</ol>
			<p>
				<?php // A javascript: URL, so esc_url() would drop it; script() rawurlencodes everything, and esc_attr() keeps it attribute-safe. ?>
				<a class="daymark-btn daymark-btn--primary daymark-bookmarklet__link" href="<?php echo esc_attr( $daymark_bookmarklet['script'] ); ?>"><?php esc_html_e( 'Daymark', 'daymark' ); ?></a>
			</p>
			<p class="daymark-field__help"><?php esc_html_e( 'Then, on any post you want to share, click Daymark in your bookmarks bar.', 'daymark' ); ?></p>
			<p class="daymark-field__help"><?php esc_html_e( 'On a phone, install Daymark to your home screen instead. On Android you can then share a page to Daymark from the browser’s Share menu.', 'daymark' ); ?></p>
			<p><a href="<?php echo esc_url( Daymark_Routes::app_url() ); ?>"><?php esc_html_e( 'Open Daymark', 'daymark' ); ?></a></p>
		<?php else : ?>
			<?php if ( '' !== $daymark_bookmarklet['error'] ) : ?>
				<p class="daymark-bookmarklet__notice daymark-bookmarklet__notice--error" role="alert"><?php echo esc_html( $daymark_bookmarklet['error'] ); ?></p>
			<?php elseif ( 'reblogged' === $daymark_bm_done && $daymark_bm_mark instanceof WP_Post ) : ?>
				<p class="daymark-bookmarklet__notice" role="status">
					<?php
					echo 'publish' === $daymark_bm_mark->post_status
						? esc_html__( 'Reblogged to your site.', 'daymark' )
						: esc_html__( 'Saved as a draft. An editor needs to publish it.', 'daymark' );
					?>
				</p>
			<?php elseif ( 'liked' === $daymark_bm_done ) : ?>
				<p class="daymark-bookmarklet__notice" role="status"><?php esc_html_e( 'Liked.', 'daymark' ); ?></p>
			<?php elseif ( 'unliked' === $daymark_bm_done ) : ?>
				<p class="daymark-bookmarklet__notice" role="status"><?php esc_html_e( 'Like removed.', 'daymark' ); ?></p>
			<?php elseif ( 'followed' === $daymark_bm_done ) : ?>
				<p class="daymark-bookmarklet__notice" role="status"><?php esc_html_e( 'Followed. New posts from this site will show up in your Timeline.', 'daymark' ); ?></p>
			<?php endif; ?>

			<div class="daymark-field">
				<div class="daymark-field__label"><?php esc_html_e( 'Post', 'daymark' ); ?></div>
				<?php if ( ! empty( $daymark_bm_state['embed']['html'] ) ) : ?>
					<div class="daymark-oembed-preview daymark-bookmarklet__embed">
						<?php echo $daymark_bm_state['embed']['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rebuilt by Daymark_Subscription_Oembed from allowlisted, escaped attributes only. ?>
					</div>
				<?php else : ?>
					<blockquote class="daymark-reblog-quote">
						<p><a href="<?php echo esc_url( $daymark_bm_target['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $daymark_bm_target['title'] ); ?></a></p>
						<cite><?php echo esc_html( implode( ', ', array_filter( array( $daymark_bm_target['author'], $daymark_bm_target['host'] ) ) ) ); ?></cite>
					</blockquote>
				<?php endif; ?>
			</div>

			<div class="daymark-bookmarklet__like">
				<?php if ( ! empty( $daymark_bm_state['like_available'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( Daymark_Bookmarklet::popup_url() ); ?>">
						<?php $daymark_bm_hidden( $daymark_bm_target, ! empty( $daymark_bm_state['liked'] ) ? 'unlike' : 'like' ); ?>
						<button type="submit" class="daymark-btn daymark-btn--secondary" aria-pressed="<?php echo ! empty( $daymark_bm_state['liked'] ) ? 'true' : 'false'; ?>">
							<?php echo ! empty( $daymark_bm_state['liked'] ) ? esc_html__( '♥ Liked · Undo', 'daymark' ) : esc_html__( '♡ Like', 'daymark' ); ?>
						</button>
					</form>
				<?php else : ?>
					<p class="daymark-field__help"><?php esc_html_e( 'This post’s site can’t receive a Like from Daymark.', 'daymark' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( null !== $daymark_bm_follow ) : ?>
				<div class="daymark-bookmarklet__follow">
					<?php if ( '' !== $daymark_bm_follow['following'] ) : ?>
						<p class="daymark-field__help">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: name of a site the reader already follows. */
									__( 'You follow %s.', 'daymark' ),
									$daymark_bm_follow['following']
								)
							);
							?>
						</p>
					<?php elseif ( is_array( $daymark_bm_lookup ) && empty( $daymark_bm_open_feeds ) ) : ?>
						<p class="daymark-field__help"><?php esc_html_e( 'You already follow every feed this site offers.', 'daymark' ); ?></p>
					<?php elseif ( is_array( $daymark_bm_lookup ) ) : ?>
						<?php
						$daymark_bm_site_name = sanitize_text_field( (string) ( $daymark_bm_lookup['site_title'] ?? '' ) );
						$daymark_bm_site_url  = esc_url_raw( (string) ( $daymark_bm_lookup['site_url'] ?? '' ) );
						?>
						<h2 class="daymark-section-heading">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: name or address of a site, such as example.com. */
									__( 'Follow %s', 'daymark' ),
									'' !== $daymark_bm_site_name ? $daymark_bm_site_name : $daymark_bm_follow['site']
								)
							);
							?>
						</h2>
						<form method="post" action="<?php echo esc_url( Daymark_Bookmarklet::popup_url() ); ?>" class="daymark-bookmarklet__followform">
							<?php $daymark_bm_hidden( $daymark_bm_target, 'follow' ); ?>
							<fieldset class="daymark-follow__feeds">
								<legend class="daymark-field__label"><?php echo count( $daymark_bm_candidates ) > 1 ? esc_html__( 'Choose a feed', 'daymark' ) : esc_html__( 'Feed', 'daymark' ); ?></legend>
								<?php
								foreach ( $daymark_bm_candidates as $daymark_bm_candidate ) :
									if ( ! is_array( $daymark_bm_candidate ) ) {
										continue;
									}

									$daymark_bm_index      = (int) ( $daymark_bm_candidate['index'] ?? -1 );
									$daymark_bm_subscribed = ! empty( $daymark_bm_candidate['subscribed'] );
									$daymark_bm_label      = (string) ( $daymark_bm_candidate['label'] ?? '' );
									$daymark_bm_picked     = $daymark_bm_index === $daymark_bm_default && ! $daymark_bm_subscribed;
									?>
									<label class="daymark-follow__option">
										<input type="radio" name="daymark_feed_index" value="<?php echo esc_attr( (string) $daymark_bm_index ); ?>"<?php checked( $daymark_bm_picked ); ?><?php disabled( $daymark_bm_subscribed ); ?><?php echo $daymark_bm_picked ? ' autofocus' : ''; ?> />
										<span class="daymark-follow__optiontext">
											<span class="daymark-follow__name">
												<strong><?php echo esc_html( '' !== $daymark_bm_label ? $daymark_bm_label : __( 'Feed', 'daymark' ) ); ?></strong>
												<?php if ( $daymark_bm_recommended && $daymark_bm_index === $daymark_bm_default ) : ?>
													<span class="daymark-follow__recommended"><?php esc_html_e( '(recommended)', 'daymark' ); ?></span>
												<?php endif; ?>
												<?php if ( $daymark_bm_subscribed ) : ?>
													<em><?php esc_html_e( '(already following)', 'daymark' ); ?></em>
												<?php endif; ?>
											</span>
											<span class="daymark-follow__url"><?php echo esc_html( (string) ( $daymark_bm_candidate['url'] ?? '' ) ); ?></span>
										</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<div class="daymark-field">
								<label class="daymark-field__label" for="daymark-bm-site-title"><?php esc_html_e( 'Name', 'daymark' ); ?></label>
								<input type="text" id="daymark-bm-site-title" name="daymark_site_title" class="daymark-input" value="<?php echo esc_attr( $daymark_bm_site_name ); ?>" placeholder="<?php echo esc_attr( $daymark_bm_site_url ); ?>" />
							</div>
							<div class="daymark-sheet__actions">
								<button type="submit" class="daymark-btn daymark-btn--primary"><?php esc_html_e( 'Follow', 'daymark' ); ?></button>
								<?php // In the same form so it carries the nonce; handle_post() turns it into a cancel. Follow comes first, so Enter still follows. ?>
								<button type="submit" name="daymark_follow_cancel" value="1" class="daymark-btn daymark-btn--text"><?php esc_html_e( 'Cancel', 'daymark' ); ?></button>
							</div>
							<p class="daymark-field__help">
								<a href="<?php echo esc_url( Daymark_Follow_Link::app_url( $daymark_bm_target['url'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Not the site you meant? Enter its address in Daymark.', 'daymark' ); ?></a>
							</p>
						</form>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( Daymark_Bookmarklet::popup_url() ); ?>">
							<?php $daymark_bm_hidden( $daymark_bm_target, 'follow_find' ); ?>
							<button type="submit" class="daymark-btn daymark-btn--secondary">
								<?php
								echo '' !== $daymark_bm_follow['site']
									? esc_html(
										sprintf(
											/* translators: %s: name or address of a site, such as example.com. */
											__( 'Follow %s', 'daymark' ),
											$daymark_bm_follow['site']
										)
									)
									: esc_html__( 'Follow this site', 'daymark' );
								?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $daymark_bm_state['reblog_id'] ) ) : ?>
				<p>
					<?php esc_html_e( 'You reblogged this post.', 'daymark' ); ?>
					<a href="<?php echo esc_url( (string) get_permalink( (int) $daymark_bm_state['reblog_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View your Reblog', 'daymark' ); ?></a>
					<?php
					$daymark_bm_edit = get_edit_post_link( (int) $daymark_bm_state['reblog_id'], 'raw' );
					if ( $daymark_bm_edit ) :
						?>
						· <a href="<?php echo esc_url( $daymark_bm_edit ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit', 'daymark' ); ?></a>
					<?php endif; ?>
				</p>
			<?php elseif ( $daymark_bm_mark instanceof WP_Post && 'reblogged' === $daymark_bm_done ) : ?>
				<p>
					<a href="<?php echo esc_url( (string) get_permalink( $daymark_bm_mark ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View your Reblog', 'daymark' ); ?></a>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( Daymark_Bookmarklet::popup_url() ); ?>" class="daymark-bookmarklet__reblog">
					<?php $daymark_bm_hidden( $daymark_bm_target, 'reblog' ); ?>
					<div class="daymark-field">
						<label class="daymark-field__label" for="daymark-bm-comment"><?php esc_html_e( 'Your thoughts', 'daymark' ); ?></label>
						<p class="daymark-field__help" id="daymark-bm-comment-help"><?php esc_html_e( 'This goes after the post in your Reblog. Say why you’re sharing it.', 'daymark' ); ?></p>
						<textarea id="daymark-bm-comment" name="daymark_comment" class="daymark-textarea" rows="4" aria-describedby="daymark-bm-comment-help" placeholder="<?php esc_attr_e( 'What do you think of it?', 'daymark' ); ?>"<?php echo empty( $daymark_bm_open_feeds ) ? ' autofocus' : ''; ?>></textarea>
					</div>
					<div class="daymark-field">
						<label class="daymark-field__label" for="daymark-bm-title"><?php esc_html_e( 'Title', 'daymark' ); ?></label>
						<input type="text" id="daymark-bm-title" name="daymark_title" class="daymark-input" value="<?php echo esc_attr( Daymark_Bookmarklet::default_title( $daymark_bm_target ) ); ?>" />
					</div>
					<button type="submit" class="daymark-btn daymark-btn--primary"><?php esc_html_e( 'Reblog', 'daymark' ); ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>
		</section>
	</main>
	<?php wp_print_scripts( array( 'daymark-bookmarklet' ) ); ?>
</body>
</html>
