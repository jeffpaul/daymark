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
 * redirect back) for every action except the per-row Refresh form (issue
 * #175): that one form is progressively enhanced by
 * `assets/admin-subscriptions.js` to submit via the existing REST refresh
 * endpoint instead, updating only its own row in place rather than
 * reloading the whole page — the admin-post handler stays as that one
 * form's no-JS fallback, unchanged.
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
 * subscribe, refresh, refresh icon, edit name, unsubscribe, and OPML
 * export/import.
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
	 * Settings page slug. Shortened from the original `daymark-subscriptions`
	 * (which only ever covered one of what's now four tabs — see
	 * resolve_active_tab()) to plain `daymark`, giving the page a short,
	 * stable `options-general.php?page=daymark` URL ahead of a 1.0.0 release
	 * rather than after, when it would need a redirect forever. Stays a
	 * Settings submenu item (`add_options_page()`), not a top-level admin
	 * menu item — this screen is infrequently touched once a site is set up,
	 * so it doesn't earn a permanent slot in the main admin menu.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'daymark';

	/**
	 * The pre-0.14.0 page slug (`options-general.php?page=daymark-subscriptions`,
	 * a Settings submenu item covering only what's now the Subscriptions
	 * tab) — kept only so maybe_redirect_legacy_url() can send an old
	 * bookmark/link somewhere real instead of a 404, the same "redirect a
	 * legacy URL rather than leave it dead" precedent `Daymark_Routes`
	 * already established for the app's own legacy base and for a trashed
	 * content-type page's old URL.
	 *
	 * @var string
	 */
	private const LEGACY_PAGE_SLUG = 'daymark-subscriptions';

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
	 * Query var carrying the subscriptions table's own search term (issue
	 * #281) — `s`, matching the same name WP core's own list-table search
	 * boxes already use, rather than a `daymark_`-prefixed one, since this
	 * has no chance of colliding with anything else this GET request reads.
	 *
	 * @var string
	 */
	private const SEARCH_QUERY_VAR = 's';

	/**
	 * Subscriptions table columns a visitor can sort by (issue #178), via
	 * `?orderby=` — anything else falls back to the table's default order
	 * (get_all()'s own `created_at DESC`). Actions is not meaningful to sort
	 * by, so it's left out (the site icon has its own column no longer —
	 * it renders inline with the Site column's title instead — so there's
	 * nothing to exclude for it here either).
	 *
	 * @var string[]
	 */
	private const SORTABLE_COLUMNS = array( 'site', 'status', 'last_checked' );

	/**
	 * Register the settings page and admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_url' ) );
		add_action( 'admin_post_daymark_subscribe', array( $this, 'handle_subscribe' ) );
		add_action( 'admin_post_daymark_subscription_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_daymark_subscription_refresh_icon', array( $this, 'handle_refresh_icon' ) );
		add_action( 'admin_post_daymark_subscription_edit_title', array( $this, 'handle_edit_title' ) );
		add_action( 'admin_post_daymark_subscription_unsubscribe', array( $this, 'handle_unsubscribe' ) );
		add_action( 'admin_post_daymark_subscriptions_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_daymark_subscriptions_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_daymark_privacy_save', array( $this, 'handle_privacy_save' ) );
		add_action( 'admin_post_daymark_subscription_poll_interval_save', array( $this, 'handle_poll_interval_save' ) );
	}

	/**
	 * Register Settings -> Daymark (issue #86's own restructuring gave it
	 * four tabs — Subscriptions, Connectors, Import/Export, Privacy, see
	 * resolve_active_tab() — instead of one long page, and shortened its
	 * slug/URL — see PAGE_SLUG's own docblock — but it stays a Settings
	 * submenu item: this screen sees day-to-day use only rarely, once a
	 * site's subscriptions/connectors/privacy choices are set up, so it
	 * doesn't warrant a permanent top-level admin menu slot the way a
	 * screen someone opens routinely would).
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
	 * Redirect the pre-0.14.0 Settings submenu URL
	 * (`options-general.php?page=daymark-subscriptions`) to this page's new,
	 * shorter slug (`options-general.php?page=daymark`), preserving every
	 * other query arg (a notice, a search term, a sort column) so an old
	 * bookmark or a home-screen shortcut still lands somewhere useful
	 * instead of wp-admin's own "Sorry, you are not allowed..." page a
	 * since-renamed submenu slug would otherwise produce.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_url(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect decision, not a state-changing action.
		if ( 'options-general.php' !== $pagenow || ! isset( $_GET['page'] ) || self::LEGACY_PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect decision, not a state-changing action.
		$args = wp_unslash( $_GET );
		unset( $args['page'] );

		wp_safe_redirect( add_query_arg( $args, self::page_url() ) );
		exit;
	}

	/**
	 * Enqueue this screen's own script, and only on this screen.
	 *
	 * Localizes the REST refresh endpoint + a `wp_rest` nonce (issue #175):
	 * the per-row Refresh form (see render_refresh_form()) now submits via
	 * this endpoint instead of a full admin-post.php POST-redirect-GET, so
	 * the row updates in place instead of reloading the page. The `wp_rest`
	 * nonce is distinct from the admin-post action nonce
	 * check_admin_referer() still verifies in handle_refresh() — that nonce
	 * only guards the no-JS fallback form submission, not the REST call.
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

		// Suppresses the <details> disclosure triangle Chrome/Safari render
		// via ::-webkit-details-marker (Firefox already respects list-style
		// alone) so the "Edit site name" trigger reads as a bare pencil
		// icon — the one thing an inline style="" attribute on the element
		// itself can't reach, since pseudo-elements aren't stylable inline.
		// Attached to core's own always-loaded 'common' handle rather than
		// registering a new stylesheet for two rules, matching this
		// screen's existing no-extra-asset posture for small decorative
		// touches (e.g. the sortable-column arrow).
		wp_add_inline_style(
			'common',
			'.daymark-edit-title-trigger::-webkit-details-marker { display: none; }'
		);

		// The Refresh control's "processing" state (this method's own
		// docblock on render_refresh_form()): admin-subscriptions.js toggles
		// a `daymark-is-refreshing` class on the button while its request is
		// in flight, and this is what actually spins the dashicon while
		// that class is present — a plain `button:disabled` state alone
		// wouldn't communicate "this is working," only "you can't click it
		// again yet." Attached to core's 'common' handle rather than a new
		// stylesheet, matching the rule immediately above.
		wp_add_inline_style(
			'common',
			'@keyframes daymark-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .daymark-subscription-refresh-trigger.daymark-is-refreshing .dashicons { animation: daymark-spin 1s linear infinite; }'
		);

		wp_localize_script(
			'daymark-admin-subscriptions',
			'daymarkAdminSubscriptions',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'daymark/v1/subscriptions/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'refreshLabel'     => __( 'Refresh', 'daymark' ),
					'refreshingLabel'  => __( 'Refreshing…', 'daymark' ),
					'statusActive'     => __( 'Active', 'daymark' ),
					'statusError'      => __( 'Error', 'daymark' ),
					'justNow'          => __( 'Just now', 'daymark' ),
					'genericError'     => __( 'Something went wrong. Please try again.', 'daymark' ),
					// %s is replaced with the failure's own reason text
					// client-side (see applyRefreshedRow() in
					// admin-subscriptions.js) — kept as one translatable
					// string rather than concatenating a fixed prefix, so a
					// translation can reorder around the inserted reason.
					/* translators: %s: the most recent fetch failure's reason. */
					'recentFetchIssue' => __( 'Recent fetch issue: %s', 'daymark' ),
				),
			)
		);
	}

	/**
	 * This screen's admin URL, e.g. for the plugin action link. Points at
	 * the default (first) tab — see resolve_active_tab().
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * This screen's admin URL for a specific tab.
	 *
	 * @param string $tab One of TABS' own keys.
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return add_query_arg( 'tab', $tab, self::page_url() );
	}

	/**
	 * Tab key => nav label. Order is display order; the first entry is the
	 * default tab (resolve_active_tab()'s own fallback). Introduced in
	 * issue #86's own restructuring once a fourth section (Connectors)
	 * would otherwise have made a single, unbroken page read as one
	 * overloaded settings screen — see CLAUDE.md's own architectural
	 * decision row for the full rationale.
	 *
	 * @var array<string, string>
	 */
	private const TABS = array(
		'subscriptions' => 'Subscriptions',
		'connectors'    => 'Connectors',
		'import-export' => 'Import / Export',
		'privacy'       => 'Privacy',
	);

	/**
	 * Resolve which tab to render from `?tab=`, falling back to the first
	 * TABS entry for a missing or unrecognized value — the same
	 * whitelist-or-default posture resolve_sort_request() already uses for
	 * `?orderby=`.
	 *
	 * @return string One of TABS' own keys.
	 */
	private function resolve_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection, not a state-changing action.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = array_key_first( self::TABS );
		}

		return $tab;
	}

	/**
	 * Render the tab nav using WP core's own bundled `nav-tab-wrapper`
	 * markup/CSS (already loaded on every wp-admin screen) rather than a
	 * new stylesheet, matching this screen's established "no new asset for
	 * a small, standard piece of chrome" posture (e.g. the sortable-column
	 * arrow, the pencil-icon disclosure).
	 *
	 * @param string $active One of TABS' own keys.
	 * @return void
	 */
	private function render_tab_nav( string $active ): void {
		?>
		<h2 class="nav-tab-wrapper">
			<?php foreach ( self::TABS as $tab => $label ) : ?>
				<a
					href="<?php echo esc_url( self::tab_url( $tab ) ); ?>"
					class="nav-tab<?php echo $tab === $active ? ' nav-tab-active' : ''; ?>"
					<?php echo $tab === $active ? ' aria-current="page"' : ''; ?>
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	/**
	 * Render the settings page: a status notice (if any), the tab nav, and
	 * the active tab's own content.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'daymark' ), 403 );
		}

		$active_tab = $this->resolve_active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Daymark', 'daymark' ); ?></h1>

			<?php $this->render_notice(); ?>
			<?php $this->render_tab_nav( $active_tab ); ?>

			<?php if ( 'subscriptions' === $active_tab ) : ?>
				<?php $this->render_subscriptions_tab(); ?>
			<?php elseif ( 'connectors' === $active_tab ) : ?>
				<?php $this->render_connectors_tab(); ?>
			<?php elseif ( 'import-export' === $active_tab ) : ?>
				<?php $this->render_import_export_tab(); ?>
			<?php elseif ( 'privacy' === $active_tab ) : ?>
				<?php $this->render_privacy_section(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Subscriptions tab's own content — unchanged from what render_page()
	 * rendered unconditionally before this screen had tabs, minus its own
	 * now-redundant `<h2>Subscriptions</h2>` (the active tab label already
	 * says this).
	 *
	 * @return void
	 */
	private function render_subscriptions_tab(): void {
		$subscriptions = Daymark_Plugin::instance()->subscriptions->get_all();
		$total_count   = count( $subscriptions );
		$search        = $this->resolve_search_request();
		$subscriptions = $this->filter_subscriptions( $subscriptions, $search );
		$sort          = $this->resolve_sort_request();
		$subscriptions = $this->sort_subscriptions( $subscriptions, $sort['orderby'], $sort['order'] );
		?>
		<p><?php esc_html_e( 'Subscribe to another site\'s feed to see its posts alongside your own Marks in the Timeline.', 'daymark' ); ?></p>

		<?php $this->render_subscribe_form(); ?>
		<?php if ( $total_count > 0 ) : ?>
			<?php $this->render_search_form( $search, $sort['orderby'], $sort['order'] ); ?>
		<?php endif; ?>
		<?php $this->render_subscriptions_table( $subscriptions, $sort['orderby'], $sort['order'], $search, $total_count ); ?>
		<?php $this->render_poll_interval_form(); ?>
		<?php
	}

	/**
	 * The Import/Export tab's own content — unchanged from what render_page()
	 * rendered unconditionally before this screen had tabs, minus its own
	 * now-redundant `<h2>Import / export</h2>`.
	 *
	 * @return void
	 */
	private function render_import_export_tab(): void {
		?>
		<p><?php esc_html_e( 'Back up your subscription list, or bulk-import one from another feed reader, using the standard OPML format.', 'daymark' ); ?></p>
		<?php
		$this->render_export_link();
		$this->render_import_form();
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
			'subscribed'          => __( 'Subscribed. New posts from this site will start appearing in the Timeline.', 'daymark' ),
			'subscribed_pending'  => __( 'Subscribed, but the first fetch didn\'t complete — its posts will appear once the next automatic check succeeds.', 'daymark' ),
			'unsubscribed'        => __( 'Unsubscribed.', 'daymark' ),
			'refreshed'           => __( 'Refresh requested.', 'daymark' ),
			'icon_refreshed'      => __( 'Site icon refreshed.', 'daymark' ),
			'title_updated'       => __( 'Site name updated.', 'daymark' ),
			'privacy_saved'       => __( 'Privacy settings saved.', 'daymark' ),
			'poll_interval_saved' => __( 'Check frequency saved.', 'daymark' ),
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
						<p class="description"><?php esc_html_e( 'Daymark will look for a feed at this address — a specific section (e.g. a Notes archive) or a feed URL itself both work, so you can subscribe to more than one feed on the same site. The scheme (https://) is optional — assumed when left off.', 'daymark' ); ?></p>
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
	 * Render the subscriptions table's own search box — a plain GET form,
	 * matching this screen's established "read-only query-string round trip,
	 * no JS, no nonce" posture already set by the sortable column headers
	 * (issue #178) rather than a new REST-backed live-filter (the only
	 * JS-enhanced form on this screen remains the Refresh action, issue
	 * #175/#176, which exists specifically because *that* action needs a
	 * live external fetch's result — a search has nothing to fetch, the
	 * result is already in `$search` by the time this renders). Carries the
	 * current sort as hidden fields so submitting a new search doesn't reset
	 * an active column sort back to the table's default order.
	 *
	 * @param string $search  The current search term, if any (for the
	 *                        input's own value).
	 * @param string $orderby The active sort column, or '' — see
	 *                        SORTABLE_COLUMNS.
	 * @param string $order   'asc' or 'desc'.
	 * @return void
	 */
	private function render_search_form( string $search, string $orderby, string $order ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="subscriptions" />
			<?php if ( '' !== $orderby ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
			<?php endif; ?>
			<p class="search-box">
				<label class="screen-reader-text" for="daymark-subscription-search-input"><?php esc_html_e( 'Search Subscriptions', 'daymark' ); ?></label>
				<input
					type="search"
					id="daymark-subscription-search-input"
					name="<?php echo esc_attr( self::SEARCH_QUERY_VAR ); ?>"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search by site name or URL…', 'daymark' ); ?>"
				/>
				<?php submit_button( __( 'Search Subscriptions', 'daymark' ), '', '', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the table of existing subscriptions.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions Rows from
	 *                                                          Daymark_Subscriptions::get_all(),
	 *                                                          already filtered by filter_subscriptions()
	 *                                                          and sorted by sort_subscriptions().
	 * @param string                           $orderby       The active sort column (one of
	 *                                                         SORTABLE_COLUMNS, or '' for the
	 *                                                         table's default order).
	 * @param string                           $order         'asc' or 'desc' — meaningless when
	 *                                                         $orderby is ''.
	 * @param string                           $search        The active search term, or '' — only
	 *                                                         used here to tell "no subscriptions at
	 *                                                         all" apart from "none match this
	 *                                                         search" in the empty state.
	 * @param int                              $total_count   Total subscription count before
	 *                                                         filtering, for the same distinction.
	 * @return void
	 */
	private function render_subscriptions_table( array $subscriptions, string $orderby, string $order, string $search, int $total_count ): void {
		if ( empty( $subscriptions ) ) {
			if ( '' !== $search && $total_count > 0 ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: the search term that matched nothing. */
							__( 'No subscriptions match "%s".', 'daymark' ),
							$search
						)
					)
				);
			} else {
				echo '<p>' . esc_html__( 'No subscriptions yet.', 'daymark' ) . '</p>';
			}

			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<?php $this->render_sortable_column_header( __( 'Site', 'daymark' ), 'site', $orderby, $order, $search ); ?>
					<?php $this->render_sortable_column_header( __( 'Status', 'daymark' ), 'status', $orderby, $order, $search ); ?>
					<?php $this->render_sortable_column_header( __( 'Last fetched', 'daymark' ), 'last_checked', $orderby, $order, $search ); ?>
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
	 * Render one sortable column header: a link that sorts ascending, or —
	 * when this column is already the active sort — flips to the opposite
	 * direction, matching the standard wp-admin list-table sortable-column
	 * convention (issue #178). A plain Unicode arrow marks the active
	 * column's current direction rather than relying on core's list-table
	 * CSS/icon font, matching this screen's existing "no extra CSS/JS asset
	 * for a decorative indicator" posture (see render_subscription_row()'s
	 * own icon-cell docblock for the same reasoning applied to the site
	 * icon's fallback glyph).
	 *
	 * @param string $label         Visible column label.
	 * @param string $column        This column's sort key (one of SORTABLE_COLUMNS).
	 * @param string $orderby       The currently active sort column, or ''.
	 * @param string $order         The currently active sort direction ('asc'/'desc').
	 * @param string $search        The active search term, or '' — carried through so
	 *                              re-sorting a search's own results doesn't drop the filter.
	 * @return void
	 */
	private function render_sortable_column_header( string $label, string $column, string $orderby, string $order, string $search = '' ): void {
		$is_active  = ( $column === $orderby );
		$next_order = ( $is_active && 'asc' === $order ) ? 'desc' : 'asc';
		$args       = array(
			'orderby' => $column,
			'order'   => $next_order,
		);

		if ( '' !== $search ) {
			$args[ self::SEARCH_QUERY_VAR ] = $search;
		}

		$url       = add_query_arg( $args, self::page_url() );
		$aria_sort = ! $is_active ? 'none' : ( 'desc' === $order ? 'descending' : 'ascending' );
		?>
		<th scope="col" aria-sort="<?php echo esc_attr( $aria_sort ); ?>">
			<a href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $is_active ) : ?>
					<span aria-hidden="true"><?php echo 'desc' === $order ? '&#9660;' : '&#9650;'; ?></span>
				<?php endif; ?>
			</a>
		</th>
		<?php
	}

	/**
	 * Read and sanitize this screen's own `?s=` search term (issue #281).
	 * Same "read-only query-string round trip, not a state-changing action"
	 * reasoning as resolve_sort_request() below — no nonce applies here
	 * either.
	 *
	 * @return string The trimmed, sanitized search term, or '' when absent.
	 */
	private function resolve_search_request(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter of this screen's own table; not a state-changing action.
		$search = isset( $_GET[ self::SEARCH_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter of this screen's own table; not a state-changing action.

		return trim( $search );
	}

	/**
	 * Filter subscription rows to those matching `$search` (issue #281): a
	 * case-insensitive substring match against the site's title, URL, and
	 * the underlying feed's own URL/title — not just the visible Site
	 * column text — so a search also finds a specific feed among several
	 * subscriptions to the same site (see "Subscribing to a second,
	 * differently-scoped feed on an already-subscribed site" in CLAUDE.md),
	 * which searching only `subscription_label()`'s own display text
	 * couldn't distinguish.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions Rows to filter.
	 * @param string                           $search        Already-trimmed search term (from
	 *                                                          resolve_search_request()); '' matches
	 *                                                          everything.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_subscriptions( array $subscriptions, string $search ): array {
		if ( '' === $search ) {
			return $subscriptions;
		}

		return array_values(
			array_filter(
				$subscriptions,
				static function ( array $subscription ) use ( $search ) {
					$haystack = implode(
						' ',
						array(
							(string) ( $subscription['site_title'] ?? '' ),
							(string) ( $subscription['site_url'] ?? '' ),
							(string) ( $subscription['feed_title'] ?? '' ),
							(string) ( $subscription['feed_url'] ?? '' ),
						)
					);

					return false !== stripos( $haystack, $search );
				}
			)
		);
	}

	/**
	 * Read and validate this screen's own `?orderby=`/`?order=` query args
	 * (issue #178). A read-only sort of this screen's own table — not a
	 * state-changing action — so, like render_notice()'s query-string read
	 * above, no nonce applies here.
	 *
	 * @return array{orderby: string, order: string} `orderby` is one of
	 *         SORTABLE_COLUMNS, or '' to keep the table's default order;
	 *         `order` is always 'asc' or 'desc' (meaningless when `orderby`
	 *         is '').
	 */
	private function resolve_sort_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort of this screen's own table; not a state-changing action.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort of this screen's own table; not a state-changing action.
		$order = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';

		if ( ! in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
			$orderby = '';
		}

		return array(
			'orderby' => $orderby,
			'order'   => 'desc' === $order ? 'desc' : 'asc',
		);
	}

	/**
	 * Sort a list of subscription rows for display (issue #178). Leaves the
	 * list untouched (get_all()'s own `created_at DESC`) when `$orderby` is
	 * '' — the whitelist resolve_sort_request() already applies means that's
	 * only ever the "no sort requested, or an invalid one" case, never a
	 * real column with nothing to compare.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions Rows to sort.
	 * @param string                           $orderby       One of SORTABLE_COLUMNS, or ''.
	 * @param string                           $order         'asc' or 'desc'.
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_subscriptions( array $subscriptions, string $orderby, string $order ): array {
		if ( '' === $orderby ) {
			return $subscriptions;
		}

		usort(
			$subscriptions,
			static function ( array $a, array $b ) use ( $orderby ) {
				if ( 'status' === $orderby ) {
					return strcasecmp( (string) ( $a['status'] ?? '' ), (string) ( $b['status'] ?? '' ) );
				}

				if ( 'last_checked' === $orderby ) {
					$a_time = strtotime( (string) ( $a['last_checked_at'] ?? '' ) . ' +00:00' );
					$b_time = strtotime( (string) ( $b['last_checked_at'] ?? '' ) . ' +00:00' );

					return ( false !== $a_time ? $a_time : 0 ) <=> ( false !== $b_time ? $b_time : 0 );
				}

				return strcasecmp( self::subscription_label( $a ), self::subscription_label( $b ) );
			}
		);

		if ( 'desc' === $order ) {
			$subscriptions = array_reverse( $subscriptions );
		}

		return $subscriptions;
	}

	/**
	 * A subscription's display label: its site title when known, else its
	 * site URL — the same resolution render_subscription_row() and
	 * render_unsubscribe_form() already apply for the row's own strong text
	 * and confirm() prompt, shared here so the Site column always sorts by
	 * exactly what it displays.
	 *
	 * @param array<string, mixed> $subscription A `daymark_subscription` row.
	 * @return string
	 */
	private static function subscription_label( array $subscription ): string {
		$title    = sanitize_text_field( (string) ( $subscription['site_title'] ?? '' ) );
		$site_url = (string) ( $subscription['site_url'] ?? '' );

		return '' !== $title ? $title : $site_url;
	}

	/**
	 * Render one subscription's row: site icon/title/URL, status, when it
	 * was last fetched, and its Refresh / Unsubscribe actions.
	 *
	 * The icon renders nothing at all (not a placeholder glyph) when
	 * `site_icon_url` is empty or fails to load — a bare `/favicon.ico`
	 * fallback guess (see `Daymark_Subscription_Source_Feed::get_favicon_url()`)
	 * is not verified to resolve to a real image, and this screen has no
	 * enqueued JS/CSS asset of its own worth adding just for a fallback
	 * glyph the app shell already provides its own version of
	 * (`imgWithFallback()`, `assets/app.js`) for the exact same "a
	 * subscription's icon might 404" case. Rendered inline immediately to
	 * the left of the site title/URL, in the Site column itself, rather
	 * than in its own column (its original shape when this shipped —
	 * issue #171).
	 *
	 * @param array<string, mixed> $subscription A `daymark_subscription` row.
	 * @return void
	 */
	private function render_subscription_row( array $subscription ): void {
		$id            = absint( $subscription['id'] ?? 0 );
		$site_url      = (string) ( $subscription['site_url'] ?? '' );
		$site_title    = sanitize_text_field( (string) ( $subscription['site_title'] ?? '' ) );
		$icon_url      = (string) ( $subscription['site_icon_url'] ?? '' );
		$status        = sanitize_key( (string) ( $subscription['status'] ?? '' ) );
		$is_error      = 'error' === $status;
		$label         = self::subscription_label( $subscription );
		$row_label     = '' !== $label ? $label : __( '(untitled)', 'daymark' );
		$last_error    = sanitize_text_field( (string) ( $subscription['last_error'] ?? '' ) );
		$failure_count = absint( $subscription['consecutive_failure_count'] ?? 0 );
		// Issue #182: a subscription can be failing (and have a real
		// last_error) well before it's flagged fully dead — the dead
		// threshold is 7 consecutive failures, but the very first one
		// already has something worth surfacing here. A subscription that
		// recovers has its failure count reset to 0 on the next successful
		// check, so a stale last_error left over from a past recovery
		// (failure count back at 0) still correctly shows nothing.
		$has_error_message = '' !== $last_error && ( $is_error || $failure_count > 0 );
		?>
		<tr data-daymark-subscription-row="<?php echo esc_attr( (string) $id ); ?>">
			<td>
				<?php if ( '' !== $icon_url ) : ?>
					<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="20" height="20" style="width:20px;height:20px;border-radius:2px;vertical-align:middle;margin-right:6px;" onerror="this.remove()" />
				<?php endif; ?>
				<strong data-daymark-title-text><?php echo esc_html( $row_label ); ?></strong>
				<?php $this->render_edit_title_form( $id, $site_title, $site_url ); ?>
				<?php if ( '' !== $site_url ) : ?>
					<br />
					<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site_url ); ?></a>
				<?php endif; ?>
			</td>
			<td>
				<span class="daymark-subscription-status-text"><?php echo $is_error ? esc_html__( 'Error', 'daymark' ) : esc_html__( 'Active', 'daymark' ); ?></span>
				<?php
				$error_message = '';

				if ( $has_error_message ) {
					// The dead ('Error') case shows the reason alone, unchanged
					// from before this row could also show a non-dead issue.
					// The still-'Active'-but-failing case gets a prefix so it
					// doesn't read as identical to a fully dead feed.
					$error_message = $is_error ? $last_error : sprintf(
						/* translators: %s: the most recent fetch failure's reason. */
						__( 'Recent fetch issue: %s', 'daymark' ),
						$last_error
					);
				}
				?>
				<div class="description daymark-subscription-status-error" <?php echo $has_error_message ? '' : 'hidden'; ?>><?php echo esc_html( $error_message ); ?></div>
			</td>
			<td>
				<span class="daymark-subscription-last-fetched"><?php echo esc_html( $this->format_last_checked( (string) ( $subscription['last_checked_at'] ?? '' ) ) ); ?></span>
				<?php $this->render_refresh_form( $id ); ?>
			</td>
			<td>
				<?php
				$this->render_refresh_icon_form( $id );

				$this->render_unsubscribe_form( $id, $row_label );
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one subscription's Refresh control: a small circular-arrows
	 * icon (dashicons-update — WordPress core's own bundled refresh glyph,
	 * so no new icon asset) sitting immediately after the "Last fetched"
	 * text, rather than a labeled button in the Actions column (issue
	 * #240's own "move it next to what it refreshes" precedent for the
	 * site-icon placement). Shown on every row, not only a failing one — it
	 * fetches on demand instead of waiting for the next scheduled poll, and
	 * doubles as the retry action when status is 'error'. Delegates to the
	 * same Daymark_Subscription_Poller::manual_refresh() as the REST
	 * refresh endpoint, including its per-subscription cooldown.
	 *
	 * When JS is available (issue #175), `assets/admin-subscriptions.js`
	 * intercepts this form's submit and calls the REST refresh endpoint
	 * directly instead, updating this row's Status/Last fetched cells in
	 * place — see enqueue_assets()'s localized config. It also toggles a
	 * `daymark-is-refreshing` class on this button (spinning the icon via
	 * the inline animation enqueue_assets() attaches — see its own
	 * docblock) instead of swapping button text, since an icon-only control
	 * has no label to swap. The form itself still posts to
	 * admin_post_daymark_subscription_refresh as a no-JS fallback,
	 * identical to this control's previous (page-reloading) behavior.
	 *
	 * @param int $id Subscription ID.
	 * @return void
	 */
	private function render_refresh_form( int $id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="daymark-subscription-refresh-form" data-daymark-subscription-id="<?php echo esc_attr( (string) $id ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="daymark_subscription_refresh" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_refresh_' . $id, 'daymark_subscription_refresh_nonce' ); ?>
			<button type="submit" class="button-link daymark-subscription-refresh-trigger" aria-label="<?php esc_attr_e( 'Refresh', 'daymark' ); ?>" title="<?php esc_attr_e( 'Refresh', 'daymark' ); ?>">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
			</button>
			<span class="description daymark-subscription-refresh-error" role="alert" hidden></span>
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
	 * Render one subscription's inline name editor: a small pencil-icon
	 * trigger — WordPress's own bundled `dashicons-edit`, so no new asset —
	 * sitting immediately to the right of the site name. Built on a
	 * `<details>`/`<summary>` disclosure (the pencil icon is the summary)
	 * so it works with no JS at all as its own foundation: clicking it
	 * reveals a text input pre-filled with the subscription's own
	 * `site_title`, and the kept Save button submits normally.
	 * `assets/admin-subscriptions.js` progressively enhances this into the
	 * requested inline-edit interaction — focusing/selecting the input the
	 * moment it opens, and saving on Enter, Tab, or clicking away (all of
	 * which end in the input's own `blur` event) via a background request
	 * to the same admin-post handler, instead of a full page reload.
	 * Useful in particular for a Friends-plugin-sourced subscription (issue
	 * #88), whose `site_title` is whatever that friend's own site calls
	 * itself — not necessarily the name the site owner would recognize
	 * them by.
	 *
	 * Submitting a blank value is allowed on purpose: it clears a bad
	 * override back to '', which subscription_label() already falls back
	 * from to the site URL, exactly the same fallback a never-titled
	 * subscription already shows.
	 *
	 * @since 0.11.0
	 *
	 * @param int    $id          Subscription ID.
	 * @param string $site_title  Current raw site_title (may be '').
	 * @param string $site_url    Current site_url, shown as the input's
	 *                            placeholder when site_title is '', and as
	 *                            the fallback display name JS restores if
	 *                            the saved value ends up blank.
	 * @return void
	 */
	private function render_edit_title_form( int $id, string $site_title, string $site_url ): void {
		?>
		<details class="daymark-subscription-edit-title" data-daymark-subscription-id="<?php echo esc_attr( (string) $id ); ?>" data-daymark-fallback-label="<?php echo esc_attr( $site_url ); ?>" style="display:inline-block;vertical-align:middle;margin-left:4px;">
			<summary class="daymark-edit-title-trigger" aria-label="<?php esc_attr_e( 'Edit site name', 'daymark' ); ?>" title="<?php esc_attr_e( 'Edit site name', 'daymark' ); ?>" style="cursor:pointer;list-style:none;display:inline-block;">
				<span class="dashicons dashicons-edit" aria-hidden="true" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
			</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="daymark-subscription-edit-title-form" style="margin-top:6px;">
				<input type="hidden" name="action" value="daymark_subscription_edit_title" />
				<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'daymark_subscription_edit_title_' . $id, 'daymark_subscription_edit_title_nonce' ); ?>
				<input type="text" name="daymark_site_title" value="<?php echo esc_attr( $site_title ); ?>" placeholder="<?php echo esc_attr( $site_url ); ?>" class="daymark-subscription-title-input" style="width:100%;max-width:220px;" />
				<?php submit_button( __( 'Save', 'daymark' ), 'secondary small', 'submit', false ); ?>
				<span class="description daymark-subscription-title-error" role="alert" hidden></span>
			</form>
		</details>
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
	 * Recommended companion IndieWeb plugins (issue #86) — every one of
	 * these solves its problem at the data/protocol layer rather than
	 * through theme template rendering, which is exactly what already lets
	 * `Daymark_Federated_Comments` extend it (reading the `protocol`
	 * comment meta each one already writes) instead of Daymark needing to
	 * reimplement Webmention/ActivityPub/AT Protocol itself — see
	 * CLAUDE.md's "Companion connectors removed" and "Webmention: rescoped
	 * to lean on ecosystem plugins" rows for the standing precedent this
	 * tab surfaces rather than replaces.
	 *
	 * `wporg_slug` is the wordpress.org plugin repository slug — used both
	 * for the WPORG link and as the native installer's own `plugin=`
	 * parameter (`wp-admin/update.php?action=install-plugin` resolves this
	 * against the plugins API to build the download package). `folder_slug`
	 * is the plugin's own installed-directory name, used only to detect
	 * install/active state via `get_plugins()` — usually identical to
	 * `wporg_slug`, but ATmosphere's own installed folder
	 * (`wordpress-atmosphere`) differs from its shorter wp.org repo slug
	 * (`atmosphere`), confirmed against `Daymark_Publish_Helpers`' own
	 * functionally-verified detection of the same plugin.
	 *
	 * A method rather than a class const — like `Daymark_AI_Assist`'s own
	 * `mock_provider_label()`, a const can't hold a `__()` call, and every
	 * description here is genuinely user-facing (issue #252's own i18n
	 * audit already established this exact distinction for this codebase).
	 *
	 * An entry's `type` is either 'plugin' (the default when omitted — a
	 * wordpress.org plugin with `wporg_slug`/`folder_slug` driving a real
	 * install/active state and Install/Activate actions, per the three
	 * entries below) or 'service' (an external, non-WordPress service with
	 * a plain `url` instead — see 'bridgy_fed' below and issue #91's own
	 * framing: "this is not a WordPress plugin... so this issue is scoped
	 * differently from Issue 14's [now #86's] plugin-recommendation
	 * pattern"). render_connectors_tab() branches on this to skip
	 * connector_status()/Install/Activate entirely for a 'service' entry.
	 *
	 * @return array<string, array{label: string, type?: string, wporg_slug?: string, folder_slug?: string, url?: string, description: string}>
	 */
	private static function recommended_connectors(): array {
		return array(
			'webmention'  => array(
				'label'       => 'Webmention',
				'wporg_slug'  => 'webmention',
				'folder_slug' => 'webmention',
				'description' => __( 'Sends and receives Webmentions automatically — a reply you compose to a subscribed post notifies its source the moment you publish, and mentions from across the IndieWeb arrive back as native comments Daymark already recognizes and labels in Notifications.', 'daymark' ),
			),
			'activitypub' => array(
				'label'       => 'ActivityPub',
				'wporg_slug'  => 'activitypub',
				'folder_slug' => 'activitypub',
				/* translators: "Reply from the Fediverse" matches the exact label Daymark itself shows in Notifications for this source — see readme.txt's own backflow FAQ. */
				'description' => __( 'Makes your site followable from Mastodon, Threads, Pixelfed, and the rest of the fediverse — a published Mark reaches those followers automatically, and their replies come back into Daymark Notifications labeled "Reply from the Fediverse."', 'daymark' ),
			),
			'atmosphere'  => array(
				'label'       => 'ATmosphere',
				'wporg_slug'  => 'atmosphere',
				'folder_slug' => 'wordpress-atmosphere',
				/* translators: "Reply from Bluesky" matches the exact label Daymark itself shows in Notifications for this source — see readme.txt's own backflow FAQ. */
				'description' => __( 'Connects your site to Bluesky / the AT Protocol — the publish screen gets a per-Mark Bluesky toggle, and replies delivered back are recognized and labeled in Notifications ("Reply from Bluesky").', 'daymark' ),
			),
			'bridgy_fed'  => array(
				'label'       => 'Bridgy Fed',
				'type'        => 'service',
				'url'         => 'https://fed.brid.gy/',
				'description' => __( 'A free, hosted bridge — not a plugin to install — that gives your site a fediverse and Bluesky presence through the Webmention support above, with no ActivityPub or AT Protocol plugin of its own required. An alternative to the ActivityPub plugin above rather than an addition to it: Bridgy Fed bridges you in under an auto-generated handle tied to its own domain, where the ActivityPub plugin gives your site its own native handle on your own domain. See CLAUDE.md for the full comparison.', 'daymark' ),
			),
		);
	}

	/**
	 * Find a recommended connector's own installed plugin file (the
	 * `folder/main-file.php` key `get_plugins()` returns it under), by
	 * matching just the folder segment against `folder_slug` — never
	 * assuming a guessed main-file name, since that isn't always identical
	 * to the plugin's slug.
	 *
	 * @param array<string, string> $connector One RECOMMENDED_CONNECTORS entry.
	 * @return string|null The plugin file (e.g. `webmention/webmention.php`), or null if not installed.
	 */
	private function connector_plugin_file( array $connector ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( strtok( $plugin_file, '/' ) === $connector['folder_slug'] ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * A recommended connector's current state: 'active', 'inactive'
	 * (installed but not active), or 'not_installed'.
	 *
	 * @param array<string, string> $connector One RECOMMENDED_CONNECTORS entry.
	 * @return string
	 */
	private function connector_status( array $connector ): string {
		$plugin_file = $this->connector_plugin_file( $connector );

		if ( null === $plugin_file ) {
			return 'not_installed';
		}

		return is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
	}

	/**
	 * Build the native, nonced "Install Now" URL WordPress's own Plugins ->
	 * Add New screen uses for any wp.org plugin — reused as-is rather than
	 * writing a custom install handler, so this tab inherits core's own
	 * battle-tested download/unzip/verify flow (and its own results page)
	 * for free.
	 *
	 * @param string $wporg_slug The plugin's wordpress.org repository slug.
	 * @return string
	 */
	private function connector_install_url( string $wporg_slug ): string {
		// add_query_arg() does not urlencode its own values (by design —
		// see its own docs), so the plugin slug is pre-encoded here, the
		// same way wp-admin's own Plugins -> Add New screen builds this
		// exact URL.
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'install-plugin',
					'plugin' => rawurlencode( $wporg_slug ),
				),
				admin_url( 'update.php' )
			),
			'install-plugin_' . $wporg_slug
		);
	}

	/**
	 * Build the native, nonced "Activate" URL `wp-admin/plugins.php` itself
	 * uses — reused as-is, same reasoning as connector_install_url().
	 * Carries a `_wp_http_referer` back to this tab so activating a
	 * connector returns here (with its own "Plugin activated" notice)
	 * instead of landing on the full Plugins list screen.
	 *
	 * @param string $plugin_file The plugin file returned by connector_plugin_file().
	 * @return string
	 */
	private function connector_activate_url( string $plugin_file ): string {
		// Same pre-encoding reasoning as connector_install_url() — the
		// plugin file contains a '/', which add_query_arg() would
		// otherwise leave completely literal in the query string.
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'           => 'activate',
					'plugin'           => rawurlencode( $plugin_file ),
					'_wp_http_referer' => rawurlencode( self::tab_url( 'connectors' ) ),
				),
				admin_url( 'plugins.php' )
			),
			'activate-plugin_' . $plugin_file
		);
	}

	/**
	 * Render the Connectors tab (issue #86, extended for a 'service' entry
	 * by issue #91): one card per RECOMMENDED_CONNECTORS entry with a
	 * Daymark-specific benefit description. A 'plugin' entry (the default)
	 * gets a WPORG link and an inline Install/Activate action reflecting
	 * the plugin's real current state; a 'service' entry gets a plain
	 * external link instead — there is no plugin file to detect a state
	 * for, and nothing to install. Never a hard dependency either way, per
	 * issue #86's own framing.
	 *
	 * @return void
	 */
	private function render_connectors_tab(): void {
		?>
		<p><?php esc_html_e( 'Daymark works best when paired with IndieWeb plugins and services such as these — each one extends what Daymark already does, at the protocol level, without Daymark needing to reimplement it.', 'daymark' ); ?></p>
		<div style="display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:1em;max-width:900px;">
			<?php foreach ( self::recommended_connectors() as $connector ) : ?>
				<?php $is_service = 'service' === ( $connector['type'] ?? 'plugin' ); ?>
				<div class="card" style="max-width:none;margin:0;">
					<?php if ( $is_service ) : ?>
						<h3>
							<a href="<?php echo esc_url( $connector['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $connector['label'] ); ?></a>
						</h3>
						<p><?php echo esc_html( $connector['description'] ); ?></p>
						<p>
							<a href="<?php echo esc_url( $connector['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Get started', 'daymark' ); ?></a>
						</p>
					<?php else : ?>
						<?php
						$status  = $this->connector_status( $connector );
						$wp_link = 'https://wordpress.org/plugins/' . $connector['wporg_slug'] . '/';
						?>
						<h3>
							<a href="<?php echo esc_url( $wp_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $connector['label'] ); ?></a>
						</h3>
						<p><?php echo esc_html( $connector['description'] ); ?></p>
						<p>
							<?php if ( 'active' === $status ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
								<?php esc_html_e( 'Active', 'daymark' ); ?>
							<?php elseif ( 'inactive' === $status ) : ?>
								<?php $plugin_file = $this->connector_plugin_file( $connector ); ?>
								<?php esc_html_e( 'Installed, not active.', 'daymark' ); ?>
								<?php if ( null !== $plugin_file && current_user_can( 'activate_plugins' ) ) : ?>
									<a href="<?php echo esc_url( $this->connector_activate_url( $plugin_file ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Activate', 'daymark' ); ?></a>
								<?php endif; ?>
							<?php elseif ( current_user_can( 'install_plugins' ) ) : ?>
								<a href="<?php echo esc_url( $this->connector_install_url( $connector['wporg_slug'] ) ); ?>" class="button button-primary"><?php esc_html_e( 'Install Now', 'daymark' ); ?></a>
							<?php else : ?>
								<a href="<?php echo esc_url( $wp_link ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Get it from WordPress.org', 'daymark' ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Allowed values for the "Check for new posts" dropdown (issue #291):
	 * seconds => label. A fixed, small set rather than a free-typed number —
	 * validated against on save so a POSTed value can't set an arbitrary
	 * interval (e.g. hammering every subscribed site once a second).
	 * DAY_IN_SECONDS is the pre-existing filter's own hardcoded default, so
	 * it stays the option's default too.
	 *
	 * @since 0.13.0
	 *
	 * @return array<int, string>
	 */
	private static function poll_interval_options(): array {
		return array(
			HOUR_IN_SECONDS      => __( 'Hourly', 'daymark' ),
			6 * HOUR_IN_SECONDS  => __( 'Every 6 hours', 'daymark' ),
			12 * HOUR_IN_SECONDS => __( 'Every 12 hours', 'daymark' ),
			DAY_IN_SECONDS       => __( 'Daily', 'daymark' ),
		);
	}

	/**
	 * Render the "Check for new posts" dropdown (issue #291) at the end of
	 * the Subscriptions section: how often the recurring poll cron
	 * (Daymark_Subscription_Poller::CRON_HOOK) checks every active
	 * subscription for new content. Backed by the daymark_subscription_poll_interval
	 * option that class's own register_cron_schedule() now reads — see that
	 * method's docblock for the same filter-still-wins layering the Privacy
	 * section's toggles use.
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	private function render_poll_interval_form(): void {
		$current = (int) get_option( 'daymark_subscription_poll_interval', DAY_IN_SECONDS );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
			<input type="hidden" name="action" value="daymark_subscription_poll_interval_save" />
			<?php wp_nonce_field( 'daymark_subscription_poll_interval_save', 'daymark_subscription_poll_interval_save_nonce' ); ?>
			<label for="daymark_subscription_poll_interval">
				<?php esc_html_e( 'Check for new posts:', 'daymark' ); ?>
			</label>
			<select name="daymark_subscription_poll_interval" id="daymark_subscription_poll_interval">
				<?php foreach ( self::poll_interval_options() as $seconds => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $seconds ); ?>" <?php selected( $current, $seconds ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Save', 'daymark' ), 'secondary', 'daymark-poll-interval-submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the "Check for new posts" form
	 * (admin_post_daymark_subscription_poll_interval_save, issue #291). The
	 * posted value is validated against poll_interval_options()'s own fixed
	 * set before being stored — never trusted as an arbitrary integer.
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	public function handle_poll_interval_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscription_poll_interval_save', 'daymark_subscription_poll_interval_save_nonce' );

		$posted  = isset( $_POST['daymark_subscription_poll_interval'] ) ? absint( wp_unslash( $_POST['daymark_subscription_poll_interval'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer().
		$allowed = self::poll_interval_options();

		if ( ! isset( $allowed[ $posted ] ) ) {
			$this->redirect_with_error( __( 'That check frequency is not a valid choice.', 'daymark' ) );

			return;
		}

		update_option( 'daymark_subscription_poll_interval', $posted );

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'poll_interval_saved' ) );
	}

	/**
	 * Definitions for the Privacy section's checkboxes (issue #289): option
	 * name => label, description, and default. The default matches each
	 * option's matching filter's own pre-existing hardcoded default (see
	 * Daymark_Publisher::extract_camera_info()/resolve_location()/
	 * fetch_weather() and Daymark_Microformats::hentry_markup()), so an
	 * upgrading site's behavior is unchanged until a site owner actively
	 * unchecks one — the option is a new way to set what the filter already
	 * defaulted to, not a new default.
	 *
	 * @since 0.13.0
	 *
	 * @return array<string, array{label: string, description: string, default: bool}>
	 */
	private static function privacy_option_definitions(): array {
		return array(
			'daymark_capture_location'          => array(
				'label'       => __( 'Location', 'daymark' ),
				'description' => __( 'Quietly capture a Mark\'s location (from the browser) for use inside the app — Timeline, notifications. Turning this off also disables weather capture below.', 'daymark' ),
				'default'     => true,
			),
			'daymark_capture_weather'           => array(
				'label'       => __( 'Weather', 'daymark' ),
				'description' => __( 'Look up the current weather for a Mark\'s captured location. Has no effect if location capture above is off.', 'daymark' ),
				'default'     => true,
			),
			'daymark_capture_camera_metadata'   => array(
				'label'       => __( 'Camera metadata', 'daymark' ),
				'description' => __( 'Store camera, lens, and exposure details (EXIF) already present in a photo\'s own file.', 'daymark' ),
				'default'     => true,
			),
			'daymark_publish_location_publicly' => array(
				'label'       => __( 'Publish location publicly', 'daymark' ),
				'description' => __( 'Show a Mark\'s captured location in its public, search-indexable page markup. Off by default — location otherwise stays visible only to you, inside the app.', 'daymark' ),
				'default'     => false,
			),
		);
	}

	/**
	 * Render the "Privacy" section (issue #289): a checkbox per
	 * quietly-captured-metadata opt-out that already existed as a
	 * developer-only filter (Daymark_Publisher, Daymark_Microformats) but,
	 * until now, had no UI a non-technical site owner could reach. Each
	 * checkbox is backed by a same-named wp_option that filter's own
	 * apply_filters() default argument now reads — a developer filter still
	 * wins over this option (layered, not replaced), so nothing already
	 * relying on the filter changes behavior.
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	private function render_privacy_section(): void {
		?>
		<p><?php esc_html_e( 'Control what quietly-captured metadata Daymark stores or publishes for new Marks. A developer can still override any of these from code — see the readme FAQ.', 'daymark' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="daymark_privacy_save" />
			<?php wp_nonce_field( 'daymark_privacy_save', 'daymark_privacy_save_nonce' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( self::privacy_option_definitions() as $option => $definition ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $definition['label'] ); ?></th>
						<td>
							<label for="<?php echo esc_attr( $option ); ?>">
								<input
									type="checkbox"
									name="<?php echo esc_attr( $option ); ?>"
									id="<?php echo esc_attr( $option ); ?>"
									value="1"
									<?php checked( (bool) get_option( $option, $definition['default'] ) ); ?>
								/>
								<?php echo esc_html( $definition['description'] ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( __( 'Save privacy settings', 'daymark' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the Privacy section's form (admin_post_daymark_privacy_save,
	 * issue #289). One update_option() per checkbox, present or absent in
	 * $_POST per HTML's own unchecked-checkbox convention — no schema, no
	 * shared table row, since each is a plain scalar wp_option the matching
	 * filter's default argument reads directly (see
	 * privacy_option_definitions()'s own docblock).
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	public function handle_privacy_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_privacy_save', 'daymark_privacy_save_nonce' );

		foreach ( array_keys( self::privacy_option_definitions() ) as $option ) {
			update_option( $option, isset( $_POST[ $option ] ) ? '1' : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer().
		}

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'privacy_saved' ) );
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
	 * Handle the "Edit name" form (admin_post_daymark_subscription_edit_title,
	 * issue #180). A pure local DB write (Daymark_Subscriptions::update()'s
	 * own `site_title` field) — no outbound request, so unlike Refresh/
	 * Refresh icon this needs no rate limit, matching handle_unsubscribe()'s
	 * own posture for the same reason.
	 *
	 * A blank submission is stored as-is: subscription_label()'s existing
	 * "site_title, else site_url" fallback already handles a cleared value
	 * the same way it handles a never-titled subscription.
	 *
	 * @since 0.11.0
	 *
	 * @return void
	 */
	public function handle_edit_title(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_edit_title_' . $id, 'daymark_subscription_edit_title_nonce' );

		$site_title = isset( $_POST['daymark_site_title'] ) ? sanitize_text_field( wp_unslash( $_POST['daymark_site_title'] ) ) : '';

		Daymark_Plugin::instance()->subscriptions->update( $id, array( 'site_title' => $site_title ) );

		$this->redirect( array( self::NOTICE_QUERY_VAR => 'title_updated' ) );
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
