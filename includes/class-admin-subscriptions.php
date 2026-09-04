<?php
/**
 * Settings -> Daymark: a wp-admin screen for managing subscriptions
 * (issue #78).
 *
 * A deliberate, confirmed exception to this plugin's "no wp-admin chrome"
 * non-goal (see CLAUDE.md's non-goals list): subscribing/unsubscribing is
 * an infrequently accessed screen that does not need to live in the
 * mobile-first app shell. This is the first admin-facing class in this
 * codebase — plain wp-admin form posts (POST-redirect-GET via the standard
 * `admin_post_{action}` hook pattern, with query-string status notices on
 * redirect back). No REST, no AJAX — a small enqueued script only gives the
 * Subscribe button a loading state on submit; it doesn't change the request
 * model.
 *
 * Gated on `edit_posts`, not the wp-admin-conventional `manage_options`:
 * every existing Daymark permission check in this codebase
 * (Daymark_REST_Controller::permissions_check(), and
 * Daymark_Subscription_Post_Type's meta `auth_callback`, which explicitly
 * mirrors that same gate) already uses `edit_posts`, and
 * add_options_page()'s capability parameter accepts any capability string,
 * not only `manage_options`. Matching that existing authorization model
 * keeps one consistent gate across the whole plugin instead of introducing
 * a second one just for this screen.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Settings -> Daymark page and its admin-post form handlers:
 * subscribe, refresh, refresh icon, unsubscribe, and OPML export/import.
 */
class Daymark_Admin_Subscriptions {

	/**
	 * Capability required to view this screen and act on its forms.
	 *
	 * Deliberately `edit_posts` rather than the wp-admin-conventional
	 * `manage_options` — see the class docblock.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'edit_posts';

	/**
	 * Settings page slug (Settings -> Daymark).
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'daymark-subscriptions';

	/**
	 * Query var carrying the post-redirect status notice.
	 *
	 * @var string
	 */
	private const NOTICE_QUERY_VAR = 'daymark_notice';

	/**
	 * Query var carrying an error notice's message text.
	 *
	 * @var string
	 */
	private const MESSAGE_QUERY_VAR = 'daymark_message';

	/**
	 * Register the settings page and admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_daymark_subscribe', array( $this, 'handle_subscribe' ) );
		add_action( 'admin_post_daymark_subscription_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_daymark_subscription_refresh_icon', array( $this, 'handle_refresh_icon' ) );
		add_action( 'admin_post_daymark_subscription_unsubscribe', array( $this, 'handle_unsubscribe' ) );
		add_action( 'admin_post_daymark_subscriptions_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_daymark_subscriptions_import', array( $this, 'handle_import' ) );
	}

	/**
	 * Register Settings -> Daymark.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Daymark', 'daymark' ),
			__( 'Daymark', 'daymark' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue this screen's own script, and only on this screen.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'daymark-admin-subscriptions',
			DAYMARK_PLUGIN_URL . 'assets/admin-subscriptions.js',
			array(),
			DAYMARK_VERSION,
			true
		);
	}

	/**
	 * This screen's admin URL, e.g. for the plugin action link.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Render the settings page: a status notice (if any), the subscribe-by-URL
	 * form, and the list of existing subscriptions.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'daymark' ), 403 );
		}

		$subscriptions = Daymark_Plugin::instance()->subscriptions->get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Daymark', 'daymark' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Subscriptions', 'daymark' ); ?></h2>
			<p><?php esc_html_e( 'Subscribe to another site\'s feed to see its posts alongside your own Marks in the Timeline.', 'daymark' ); ?></p>

			<?php $this->render_subscribe_form(); ?>
			<?php $this->render_subscriptions_table( $subscriptions ); ?>

			<h2><?php esc_html_e( 'Import / export', 'daymark' ); ?></h2>
			<p><?php esc_html_e( 'Back up your subscription list, or bulk-import one from another feed reader, using the standard OPML format.', 'daymark' ); ?></p>
			<?php
			$this->render_export_link();
			$this->render_import_form();
			?>
		</div>
		<?php
	}

	/**
	 * Render the dismissible admin notice for the redirect-back status query
	 * var, if one is present.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
		$notice = isset( $_GET[ self::NOTICE_QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_VAR ] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		if ( 'error' === $notice ) {
			$message = isset( $_GET[ self::MESSAGE_QUERY_VAR ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
				? sanitize_text_field( wp_unslash( $_GET[ self::MESSAGE_QUERY_VAR ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
				: __( 'Something went wrong.', 'daymark' );

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);

			return;
		}

		if ( 'opml_imported' === $notice ) {
			$this->render_opml_import_results();

			return;
		}

		$success_messages = array(
			'subscribed'         => __( 'Subscribed. New posts from this site will start appearing in the Timeline.', 'daymark' ),
			'subscribed_pending' => __( 'Subscribed, but the first fetch didn\'t complete — its posts will appear once the next automatic check succeeds.', 'daymark' ),
			'unsubscribed'       => __( 'Unsubscribed.', 'daymark' ),
			'refreshed'          => __( 'Refresh requested.', 'daymark' ),
			'icon_refreshed'     => __( 'Site icon refreshed.', 'daymark' ),
		);

		if ( isset( $success_messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $success_messages[ $notice ] )
			);
		}
	}

	/**
	 * Render the per-entry OPML import results summary, read once from the
	 * short-lived, current-user-scoped transient handle_import() writes
	 * (POST-redirect-GET can't otherwise carry an array through the
	 * redirect's query string) — consumed (deleted) here so a page refresh
	 * doesn't show a stale result again.
	 *
	 * @return void
	 */
	private function render_opml_import_results(): void {
		$transient_key = 'daymark_opml_import_result_' . get_current_user_id();
		$results       = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( ! is_array( $results ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Import complete.', 'daymark' )
			);

			return;
		}

		$counts = array(
			'subscribed' => 0,
			'duplicate'  => 0,
			'failed'     => 0,
		);

		foreach ( $results as $result ) {
			$status = isset( $result['status'] ) ? (string) $result['status'] : '';

			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number subscribed, 2: number already subscribed, 3: number failed. */
					__( 'Import complete: %1$d subscribed, %2$d already subscribed, %3$d failed.', 'daymark' ),
					$counts['subscribed'],
					$counts['duplicate'],
					$counts['failed']
				)
			)
		);

		if ( empty( $results ) ) {
			return;
		}
		$status_labels = array(
			'subscribed' => __( 'Subscribed', 'daymark' ),
			'duplicate'  => __( 'Already subscribed', 'daymark' ),
			'failed'     => __( 'Failed', 'daymark' ),
		);
		?>
		<table class="wp-list-table widefat fixed striped" style="max-width:600px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Entry', 'daymark' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'daymark' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $result ) : ?>
					<?php
					$label        = isset( $result['label'] ) ? (string) $result['label'] : '';
					$status       = isset( $result['status'] ) ? (string) $result['status'] : '';
					$message      = isset( $result['message'] ) ? (string) $result['message'] : '';
					$status_label = $status_labels[ $status ] ?? $status;
					?>
					<tr>
						<td><?php echo esc_html( '' !== $label ? $label : __( '(untitled)', 'daymark' ) ); ?></td>
						<td>
							<?php echo esc_html( $status_label ); ?>
							<?php if ( '' !== $message && 'subscribed' !== $status ) : ?>
								<br />
								<span class="description"><?php echo esc_html( $message ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the subscribe-by-URL form.
	 *
	 * @return void
	 */
	private function render_subscribe_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="daymark_subscribe" />
			<?php wp_nonce_field( 'daymark_subscribe', 'daymark_subscribe_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="daymark_site_url"><?php esc_html_e( 'Site URL', 'daymark' ); ?></label>
					</th>
					<td>
						<input
							name="daymark_site_url"
							type="text"
							id="daymark_site_url"
							class="regular-text"
							placeholder="example.com"
							required="required"
						/>
						<p class="description"><?php esc_html_e( 'Daymark will look for a feed at this address. The scheme (https://) is optional — assumed when left off.', 'daymark' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
			submit_button(
				__( 'Subscribe', 'daymark' ),
				'primary',
				'daymark-subscribe-submit',
				true,
				array( 'data-daymark-loading-label' => __( 'Subscribing…', 'daymark' ) )
			);
			?>
		</form>
		<?php
	}

	/**
	 * Render the table of existing subscriptions.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions Rows from
	 *                                                          Daymark_Subscriptions::get_all().
	 * @return void
	 */
	private function render_subscriptions_table( array $subscriptions ): void {
		if ( empty( $subscriptions ) ) {
			echo '<p>' . esc_html__( 'No subscriptions yet.', 'daymark' ) . '</p>';

			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'daymark' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Icon', 'daymark' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'daymark' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last fetched', 'daymark' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'daymark' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
					<?php $this->render_subscription_row( $subscription ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one subscription's row: site title/URL, its cached icon, status,
	 * when it was last fetched, and its Refresh / Unsubscribe actions.
	 *
	 * The icon cell renders nothing at all (not a placeholder glyph) when
	 * `site_icon_url` is empty or fails to load — a bare `/favicon.ico`
	 * fallback guess (see `Daymark_Subscription_Source_Feed::get_favicon_url()`)
	 * is not verified to resolve to a real image, and this screen has no
	 * enqueued JS/CSS asset of its own worth adding just for a fallback
	 * glyph the app shell already provides its own version of
	 * (`imgWithFallback()`, `assets/app.js`) for the exact same "a
	 * subscription's icon might 404" case.
	 *
	 * @param array<string, mixed> $subscription A `daymark_subscription` row.
	 * @return void
	 */
	private function render_subscription_row( array $subscription ): void {
		$id         = absint( $subscription['id'] ?? 0 );
		$site_url   = (string) ( $subscription['site_url'] ?? '' );
		$title      = sanitize_text_field( (string) ( $subscription['site_title'] ?? '' ) );
		$icon_url   = (string) ( $subscription['site_icon_url'] ?? '' );
		$status     = sanitize_key( (string) ( $subscription['status'] ?? '' ) );
		$is_error   = 'error' === $status;
		$label      = '' !== $title ? $title : $site_url;
		$row_label  = '' !== $label ? $label : __( '(untitled)', 'daymark' );
		$last_error = sanitize_text_field( (string) ( $subscription['last_error'] ?? '' ) );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $row_label ); ?></strong>
				<?php if ( '' !== $site_url ) : ?>
					<br />
					<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site_url ); ?></a>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( '' !== $icon_url ) : ?>
					<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="20" height="20" style="width:20px;height:20px;border-radius:2px;vertical-align:middle;" onerror="this.remove()" />
				<?php endif; ?>
			</td>
			<td>
				<?php echo $is_error ? esc_html__( 'Error', 'daymark' ) : esc_html__( 'Active', 'daymark' ); ?>
				<?php if ( $is_error && '' !== $last_error ) : ?>
					<br />
					<span class="description"><?php echo esc_html( $last_error ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php echo esc_html( $this->format_last_checked( (string) ( $subscription['last_checked_at'] ?? '' ) ) ); ?>
			</td>
			<td>
				<?php
				$this->render_refresh_form( $id );

				$this->render_refresh_icon_form( $id );

				$this->render_unsubscribe_form( $id, $row_label );
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one subscription's Refresh form. Shown on every row, not only
	 * a failing one — it fetches on demand instead of waiting for the next
	 * scheduled poll, and doubles as the retry action when status is
	 * 'error'. Delegates to the same Daymark_Subscription_Poller::manual_refresh()
	 * as the REST refresh endpoint, including its per-subscription cooldown.
	 *
	 * @param int $id Subscription ID.
	 * @return void
	 */
	private function render_refresh_form( int $id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
			<input type="hidden" name="action" value="daymark_subscription_refresh" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_refresh_' . $id, 'daymark_subscription_refresh_nonce' ); ?>
			<?php submit_button( __( 'Refresh', 'daymark' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render one subscription's "Refresh icon" form (issue #94): re-runs
	 * site-icon discovery on demand and updates `site_icon_url`. Shown on
	 * every row, not only one with no icon yet — a site's favicon can also
	 * change after the original subscribe. Manual only, on purpose: no
	 * scheduled/automatic icon refresh exists, and this action has no
	 * per-subscription cooldown of its own (unlike Refresh's 15-minute
	 * window) — the shared per-user rate limit
	 * (Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_REFRESH) is the only abuse
	 * guard this lightweight, infrequent action needs.
	 *
	 * @since 0.10.0
	 *
	 * @param int $id Subscription ID.
	 * @return void
	 */
	private function render_refresh_icon_form( int $id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
			<input type="hidden" name="action" value="daymark_subscription_refresh_icon" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_refresh_icon_' . $id, 'daymark_subscription_refresh_icon_nonce' ); ?>
			<?php submit_button( __( 'Refresh icon', 'daymark' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Format a `last_checked_at` value (UTC MySQL datetime, or empty when
	 * never checked) for display, e.g. "5 minutes ago".
	 *
	 * @param string $last_checked_at UTC MySQL datetime string, or ''.
	 * @return string
	 */
	private function format_last_checked( string $last_checked_at ): string {
		if ( '' === $last_checked_at ) {
			return __( 'Never', 'daymark' );
		}

		$timestamp = strtotime( $last_checked_at . ' +00:00' );

		if ( false === $timestamp ) {
			return __( 'Never', 'daymark' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 minutes". */
			__( '%s ago', 'daymark' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Render one subscription's Unsubscribe form. Unsubscribing deletes the
	 * `daymark_subscription` row outright (no soft-delete state exists) —
	 * a plain confirm() dialog guards against an accidental click, matching
	 * standard wp-admin practice for an irreversible action.
	 *
	 * @param int    $id    Subscription ID.
	 * @param string $label Human-readable label for the confirmation prompt.
	 * @return void
	 */
	private function render_unsubscribe_form( int $id, string $label ): void {
		$confirm_message = sprintf(
			/* translators: %s: subscription site title or URL. */
			__( 'Unsubscribe from %s? This cannot be undone.', 'daymark' ),
			$label
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="daymark_subscription_unsubscribe" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_unsubscribe_' . $id, 'daymark_subscription_unsubscribe_nonce' ); ?>
			<?php
			submit_button(
				__( 'Unsubscribe', 'daymark' ),
				'delete small',
				'submit',
				false,
				array( 'onclick' => 'return confirm(\'' . esc_js( $confirm_message ) . '\');' )
			);
			?>
		</form>
		<?php
	}

	/**
	 * Render the "Export" link (issue #80): a plain, nonce-carrying GET link
	 * to admin_post_daymark_subscriptions_export, which streams the same
	 * Daymark_Subscription_OPML::export() output the REST
	 * `GET /daymark/v1/subscriptions/export` route serves — one export
	 * implementation shared by both surfaces, matching this screen's
	 * existing subscribe/refresh/unsubscribe convention of delegating to a
	 * single shared method rather than duplicating logic per surface.
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	private function render_export_link(): void {
		$url = wp_nonce_url(
			add_query_arg( 'action', 'daymark_subscriptions_export', admin_url( 'admin-post.php' ) ),
			'daymark_subscriptions_export',
			'daymark_subscriptions_export_nonce'
		);
		?>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-secondary"><?php esc_html_e( 'Export subscriptions (OPML)', 'daymark' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Render the OPML file-upload Import form (issue #80).
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	private function render_import_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="daymark_subscriptions_import" />
			<?php wp_nonce_field( 'daymark_subscriptions_import', 'daymark_subscriptions_import_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="daymark_opml_file"><?php esc_html_e( 'Import OPML file', 'daymark' ); ?></label>
					</th>
					<td>
						<input type="file" name="daymark_opml_file" id="daymark_opml_file" accept=".opml,.xml" required="required" />
						<p class="description"><?php esc_html_e( 'Each entry is imported individually — one bad entry will not stop the rest from importing.', 'daymark' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Import', 'daymark' ), 'secondary', 'daymark-import-submit', true ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the subscribe-by-URL form (admin_post_daymark_subscribe).
	 *
	 * Delegates to Daymark_Subscriptions::subscribe_to_site() — the same
	 * method POST /daymark/v1/subscriptions uses — after this screen's own
	 * capability, nonce, and rate-limit checks.
	 *
	 * @return void
	 */
	public function handle_subscribe(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscribe', 'daymark_subscribe_nonce' );

		// Same outbound-request risk class as the REST endpoint (a feed
		// discovery + favicon request to a site the user names) — rate
		// limited for parity with it, even though this admin screen's own
		// request volume is naturally far lower.
		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			$this->redirect_with_error( $rate->get_error_message() );

			return;
		}

		$site_url = isset( $_POST['daymark_site_url'] ) ? esc_url_raw( wp_unslash( $_POST['daymark_site_url'] ) ) : '';

		$result = Daymark_Plugin::instance()->subscriptions->subscribe_to_site( $site_url );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );

			return;
		}

		// Without this, a freshly subscribed site would sit with zero
		// cached posts until the next scheduled poll — by default once a
		// day (`daymark_subscription_poll_interval`) — since a subscribe
		// only creates the row. Fetching once immediately is what makes
		// "subscribe and see its posts in the Timeline" actually work
		// right away rather than requiring a silent wait; best-effort, so
		// a failed first fetch (the notice below distinguishes it) still
		// leaves the subscription itself created — the next scheduled
		// poll will keep trying.
		$poll_result = Daymark_Plugin::instance()->subscription_poller->manual_refresh( (int) $result );

		if ( is_wp_error( $poll_result ) ) {
			$this->redirect( array( self::NOTICE_QUERY_VAR => 'subscribed_pending' ) );

			return;
		}

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'subscribed' ) );
	}

	/**
	 * Handle the Refresh form (admin_post_daymark_subscription_refresh).
	 *
	 * Delegates to Daymark_Subscription_Poller::manual_refresh(), which
	 * enforces its own per-subscription 15-minute cooldown independent of
	 * the rate limit applied here. Applies the same per-user rate limit as
	 * the REST refresh endpoint, for parity.
	 *
	 * @return void
	 */
	public function handle_refresh(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_refresh_' . $id, 'daymark_subscription_refresh_nonce' );

		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_REFRESH );

		if ( is_wp_error( $rate ) ) {
			$this->redirect_with_error( $rate->get_error_message() );

			return;
		}

		$result = Daymark_Plugin::instance()->subscription_poller->manual_refresh( $id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );

			return;
		}

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'refreshed' ) );
	}

	/**
	 * Handle the Refresh icon form
	 * (admin_post_daymark_subscription_refresh_icon, issue #94).
	 *
	 * Delegates to Daymark_Subscriptions::refresh_icon(). Applies the same
	 * per-user rate limit (Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_REFRESH
	 * — same outbound-request risk class as the content Refresh action
	 * above) as its only abuse guard; unlike handle_refresh(), there is no
	 * separate per-subscription cooldown here — see
	 * Daymark_Subscriptions::refresh_icon()'s own docblock for why one
	 * isn't needed.
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	public function handle_refresh_icon(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_refresh_icon_' . $id, 'daymark_subscription_refresh_icon_nonce' );

		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_REFRESH );

		if ( is_wp_error( $rate ) ) {
			$this->redirect_with_error( $rate->get_error_message() );

			return;
		}

		$result = Daymark_Plugin::instance()->subscriptions->refresh_icon( $id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );

			return;
		}

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'icon_refreshed' ) );
	}

	/**
	 * Handle the Unsubscribe form (admin_post_daymark_subscription_unsubscribe).
	 *
	 * Delegates to Daymark_Subscriptions::unsubscribe() — the same method
	 * DELETE /daymark/v1/subscriptions/{id} uses — which trashes every
	 * cached `daymark_subscription_post` ingested from this subscription
	 * before deleting the subscription row itself, so a cached copy of a
	 * site's content is never orphaned no matter which surface removed the
	 * subscription.
	 *
	 * @return void
	 */
	public function handle_unsubscribe(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_unsubscribe_' . $id, 'daymark_subscription_unsubscribe_nonce' );

		Daymark_Plugin::instance()->subscriptions->unsubscribe( $id );

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'unsubscribed' ) );
	}

	/**
	 * Handle the Export link (admin_post_daymark_subscriptions_export,
	 * issue #80): streams Daymark_Subscription_OPML::export()'s output as a
	 * file download — the same shared export implementation
	 * GET /daymark/v1/subscriptions/export serves, so there is exactly one
	 * thing to keep in sync between the two surfaces.
	 *
	 * A GET request (this is a read, not a state change), so its nonce is
	 * verified from `$_GET` rather than `$_POST`, matching
	 * wp_nonce_url()'s own convention.
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscriptions_export', 'daymark_subscriptions_export_nonce' );

		$xml = ( new Daymark_Subscription_OPML() )->export();

		nocache_headers();
		header( 'Content-Type: text/x-opml+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="daymark-subscriptions.opml"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw OPML/XML file download, not HTML output; content already XML-escaped by DOMDocument in Daymark_Subscription_OPML::export().
		echo $xml;
		exit;
	}

	/**
	 * Handle the Import form (admin_post_daymark_subscriptions_import,
	 * issue #80).
	 *
	 * Capability + nonce + the same per-request rate limit
	 * (Daymark_Rate_Limiter::ACTION_SUBSCRIBE) the REST import route
	 * applies, then the same upload-size cap
	 * (Daymark_Subscription_OPML::MAX_UPLOAD_BYTES, filterable via
	 * `daymark_subscription_opml_max_upload_bytes` — one shared constant so
	 * this handler and the REST route can never enforce a different cap),
	 * enforced before the file is read into memory. The uploaded file is
	 * read directly from `$_FILES` (standard PHP upload handling) rather
	 * than through `wp_handle_upload()` — nothing here is stored as a
	 * permanent attachment.
	 *
	 * The per-entry results array (not a request-level failure) is stashed
	 * in a short-lived, current-user-scoped transient rather than passed
	 * through the redirect's query string (POST-redirect-GET can't carry an
	 * array that way) — render_opml_import_results() reads and consumes it
	 * on the next page load.
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	public function handle_import(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscriptions_import', 'daymark_subscriptions_import_nonce' );

		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			$this->redirect_with_error( $rate->get_error_message() );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce already verified above via check_admin_referer(); the raw upload array itself is not user-suppliable text to sanitize (each field is validated/read individually below — extension, size, tmp_name via is_uploaded_file()), same as Daymark_Share_Target::handle()'s own $_FILES read.
		$file = isset( $_FILES['daymark_opml_file'] ) && is_array( $_FILES['daymark_opml_file'] ) ? $_FILES['daymark_opml_file'] : null;

		if ( null === $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->redirect_with_error( __( 'No OPML file was provided.', 'daymark' ) );

			return;
		}

		$filename  = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'opml', 'xml' ), true ) ) {
			$this->redirect_with_error( __( 'Please upload a .opml or .xml file.', 'daymark' ) );

			return;
		}

		/** This filter is documented in Daymark_Subscription_OPML::MAX_UPLOAD_BYTES's docblock. */
		$max_bytes = (int) apply_filters( 'daymark_subscription_opml_max_upload_bytes', Daymark_Subscription_OPML::MAX_UPLOAD_BYTES );
		$size      = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size <= 0 || $size > $max_bytes ) {
			$this->redirect_with_error( __( 'This file is too large to import.', 'daymark' ) );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a just-uploaded PHP temp upload file for in-memory XML parsing (not a remote fetch); this form intentionally doesn't go through wp_handle_upload() since nothing is stored as a permanent attachment.
		$xml = (string) file_get_contents( $file['tmp_name'] );

		$results = ( new Daymark_Subscription_OPML() )->import( $xml );

		if ( is_wp_error( $results ) ) {
			$this->redirect_with_error( $results->get_error_message() );

			return;
		}

		set_transient( 'daymark_opml_import_result_' . get_current_user_id(), $results, MINUTE_IN_SECONDS );

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'opml_imported' ) );
	}

	/**
	 * Redirect back to the settings page with the given query args merged
	 * in, then stop execution (standard POST-redirect-GET).
	 *
	 * @param array<string, string> $args Extra query args (e.g. the notice).
	 * @return void
	 */
	private function redirect( array $args ): void {
		$url = add_query_arg( $args, self::page_url() );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Redirect back to the settings page with an error notice carrying the
	 * given message.
	 *
	 * @param string $message Error message to display.
	 * @return void
	 */
	private function redirect_with_error( string $message ): void {
		$this->redirect(
			array(
				self::NOTICE_QUERY_VAR  => 'error',
				self::MESSAGE_QUERY_VAR => $message,
			)
		);
	}
}
