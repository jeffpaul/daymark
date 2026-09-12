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
	 * Query var carrying how many feeds a 'subscribed'/'subscribed_pending'
	 * notice actually subscribed to (issue #334) — always 1 for a single
	 * feed (render_subscribed_notice() then shows the original, un-pluralized
	 * copy verbatim), read only to pluralize the message when a person
	 * picked more than one feed to follow at once.
	 *
	 * @var string
	 */
	private const COUNT_QUERY_VAR = 'daymark_count';

	/**
	 * Query var carrying how many feeds a 'feeds_removed'/'feeds_updated'/
	 * 'feeds_updated_pending' notice (issue #363) unsubscribed from —
	 * COUNT_QUERY_VAR above already carries how many were *added* in the
	 * same request, so a mixed add-and-remove submission needs both.
	 *
	 * @var string
	 */
	private const REMOVED_QUERY_VAR = 'daymark_removed';

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
	 * `?orderby=` — anything else (including no `?orderby=` at all) falls
	 * back to DEFAULT_ORDERBY below rather than get_all()'s own raw
	 * `created_at DESC` order. Actions is not meaningful to sort by, so it's
	 * left out (the site icon has its own column no longer — it renders
	 * inline with the Site column's title instead — so there's nothing to
	 * exclude for it here either).
	 *
	 * @var string[]
	 */
	private const SORTABLE_COLUMNS = array( 'site', 'status', 'last_checked' );

	/**
	 * This screen's own default sort (issue #295) — A-to-Z by the Site
	 * column's own display label (subscription_label(): site title, falling
	 * back to the site URL only when there's no title), applied whenever
	 * `?orderby=` is absent or invalid, rather than surfacing get_all()'s
	 * raw subscribe-order (`created_at DESC`) unsorted. A reader scanning a
	 * list of followed sites reaches for it alphabetically far more often
	 * than by when they happened to subscribe; get_all()'s own query and
	 * every other caller (OPML export) are unaffected — this is purely this
	 * screen's own display default.
	 *
	 * @var string
	 */
	private const DEFAULT_ORDERBY = 'site';

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
		add_action( 'admin_post_daymark_subscribe_confirm', array( $this, 'handle_subscribe_confirm' ) );
		add_action( 'admin_post_daymark_subscribe_cancel', array( $this, 'handle_subscribe_cancel' ) );
		add_action( 'admin_post_daymark_subscription_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_daymark_subscription_refresh_icon', array( $this, 'handle_refresh_icon' ) );
		add_action( 'admin_post_daymark_subscription_edit_title', array( $this, 'handle_edit_title' ) );
		add_action( 'admin_post_daymark_subscription_unsubscribe', array( $this, 'handle_unsubscribe' ) );
		add_action( 'admin_post_daymark_subscription_discover_sources', array( $this, 'handle_discover_sources' ) );
		add_action( 'admin_post_daymark_subscription_discover_sources_dismiss', array( $this, 'handle_discover_sources_dismiss' ) );
		add_action( 'admin_post_daymark_subscription_update_feeds', array( $this, 'handle_subscription_update_feeds' ) );
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

		// The new-subscribe picker's own screenshot-inspired layout (issue
		// #368): a site "avatar" placeholder (no real per-site image exists
		// pre-subscribe — resolving one would cost an extra live request for
		// what's purely decorative chrome, so this is a fixed dashicon, not a
		// fetched favicon), the editable-name <details> disclosure (its own
		// marker suppressed the same way the existing per-row name editor's
		// already is, but scoped to this new, distinct class so the two
		// never share a selector by coincidence), and the discovered feed
		// URL rendered in a small bordered/monospace "code box" rather than
		// plain inline `<code>`, matching the mockup this issue was built
		// from. One rule set, attached to core's 'common' handle, matching
		// this screen's existing no-extra-stylesheet posture.
		wp_add_inline_style(
			'common',
			'.daymark-new-subscribe-edit-name::-webkit-details-marker { display: none; }'
			. '.daymark-new-subscribe-header { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }'
			. '.daymark-new-subscribe-avatar { font-size: 28px; width: 28px; height: 28px; color: #646970; }'
			. '.daymark-new-subscribe-edit-name { display: inline-block; }'
			. '.daymark-new-subscribe-edit-name summary { cursor: pointer; list-style: none; display: inline-block; }'
			. '.daymark-new-subscribe-edit-name .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }'
			. '.daymark-new-subscribe-header a { flex-basis: 100%; }'
			. '.daymark-candidate-url { display: inline-block; margin-top: 2px; padding: 2px 6px; background: #f0f0f1; border-radius: 3px; }'
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

		if ( 'subscribed' === $notice || 'subscribed_pending' === $notice ) {
			$this->render_subscribed_notice( $notice );

			return;
		}

		if ( 'feeds_added' === $notice || 'feeds_added_pending' === $notice ) {
			$this->render_feeds_added_notice( $notice );

			return;
		}

		if ( 'feeds_removed' === $notice ) {
			$this->render_feeds_removed_notice();

			return;
		}

		if ( 'feeds_updated' === $notice || 'feeds_updated_pending' === $notice ) {
			$this->render_feeds_updated_notice( $notice );

			return;
		}

		$success_messages = array(
			'unsubscribed'        => __( 'Unsubscribed.', 'daymark' ),
			'refreshed'           => __( 'Refresh requested.', 'daymark' ),
			'icon_refreshed'      => __( 'Site icon refreshed.', 'daymark' ),
			'title_updated'       => __( 'Site name updated.', 'daymark' ),
			'privacy_saved'       => __( 'Privacy settings saved.', 'daymark' ),
			'poll_interval_saved' => __( 'Check frequency saved.', 'daymark' ),
			'feeds_unchanged'     => __( 'No changes made to this site\'s feeds.', 'daymark' ),
		);

		if ( isset( $success_messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $success_messages[ $notice ] )
			);
		}
	}

	/**
	 * Read this screen's own `daymark_count` redirect-status query var
	 * (issue #334) — how many feeds a 'subscribed'/'subscribed_pending'/
	 * 'feeds_added'/'feeds_added_pending' notice actually applies to.
	 * Read-only display of a redirect status, same "not a state-changing
	 * action" reasoning render_notice()'s other query-string reads already
	 * rely on — no nonce applies here either. Always at least 1: every
	 * handler that sets this query var only ever redirects here after
	 * successfully subscribing to at least one feed.
	 *
	 * @return int
	 */
	private function resolve_notice_count(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
		return isset( $_GET[ self::COUNT_QUERY_VAR ] ) ? max( 1, absint( wp_unslash( $_GET[ self::COUNT_QUERY_VAR ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
	}

	/**
	 * Read this screen's own `daymark_removed` redirect-status query var
	 * (issue #363) — how many existing subscriptions a
	 * 'feeds_removed'/'feeds_updated'/'feeds_updated_pending' notice actually
	 * unsubscribed. Same read-only, no-nonce reasoning as resolve_notice_count()
	 * above; always at least 1, since every notice that reads this is only
	 * ever set after reconcile_selected_candidates() actually removed something.
	 *
	 * @return int
	 */
	private function resolve_notice_removed_count(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
		return isset( $_GET[ self::REMOVED_QUERY_VAR ] ) ? max( 1, absint( wp_unslash( $_GET[ self::REMOVED_QUERY_VAR ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect status; not a state-changing action.
	}

	/**
	 * Render the new-subscribe flow's own success notice (issue #334):
	 * subscribing to exactly one feed keeps the original, un-pluralized
	 * copy verbatim — `daymark_count` defaults to 1 when the query var is
	 * absent, the pre-issue-#334 shape of this redirect — and subscribing
	 * to more than one at once shows a distinct, count-carrying message
	 * instead. Two separate strings rather than `_n()` on purpose: `_n()`'s
	 * own singular form is required (by the `WordPress.WP.I18n` phpcs sniff)
	 * to carry the same `%d` placeholder as its plural, which would force
	 * "Subscribed to 1 feed..." even for the single-feed case this row's own
	 * docblock deliberately keeps unchanged.
	 *
	 * @param string $notice 'subscribed' or 'subscribed_pending'.
	 * @return void
	 */
	private function render_subscribed_notice( string $notice ): void {
		$count   = $this->resolve_notice_count();
		$pending = 'subscribed_pending' === $notice;

		if ( $count > 1 ) {
			$message = sprintf(
				$pending
					/* translators: %d: number of feeds subscribed whose first fetch didn't complete. */
					? __( 'Subscribed to %d feeds, but their first fetch didn\'t complete — their posts will appear once the next automatic check succeeds.', 'daymark' )
					/* translators: %d: number of feeds subscribed. */
					: __( 'Subscribed to %d feeds. New posts will start appearing in the Timeline.', 'daymark' ),
				$count
			);
		} elseif ( $pending ) {
			$message = __( 'Subscribed, but the first fetch didn\'t complete — its posts will appear once the next automatic check succeeds.', 'daymark' );
		} else {
			$message = __( 'Subscribed. New posts from this site will start appearing in the Timeline.', 'daymark' );
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Render an existing subscription's own "Add selected feeds" success
	 * notice (issue #334) — deliberately distinct copy from
	 * render_subscribed_notice()'s new-site wording ("Added" rather than
	 * "Subscribed"), since this is adding feed(s) alongside an
	 * already-active subscription rather than subscribing to a brand-new
	 * site. Same two-strings-not-`_n()` reasoning as render_subscribed_notice()
	 * above.
	 *
	 * @param string $notice 'feeds_added' or 'feeds_added_pending'.
	 * @return void
	 */
	private function render_feeds_added_notice( string $notice ): void {
		$count   = $this->resolve_notice_count();
		$pending = 'feeds_added_pending' === $notice;

		if ( $count > 1 ) {
			$message = sprintf(
				$pending
					/* translators: %d: number of feeds added whose first fetch didn't complete. */
					? __( 'Added %d feeds, but their first fetch didn\'t complete — their posts will appear once the next automatic check succeeds.', 'daymark' )
					/* translators: %d: number of feeds added. */
					: __( 'Added %d feeds. New posts from them will start appearing in the Timeline.', 'daymark' ),
				$count
			);
		} elseif ( $pending ) {
			$message = __( 'Added a feed, but its first fetch didn\'t complete — its posts will appear once the next automatic check succeeds.', 'daymark' );
		} else {
			$message = __( 'Added a feed. New posts from it will start appearing in the Timeline.', 'daymark' );
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Render a pure-removal "Update feeds" success notice (issue #363,
	 * 'feeds_removed') — unchecking one or more already-subscribed
	 * candidates with nothing new checked. Uses `_n()` properly (both forms
	 * carry the same `%d`) since, unlike render_subscribed_notice()'s own
	 * legacy singular copy, there's no pre-existing unpluralized string this
	 * has to preserve.
	 *
	 * @return void
	 */
	private function render_feeds_removed_notice(): void {
		$count = $this->resolve_notice_removed_count();

		$message = sprintf(
			/* translators: %d: number of feeds removed. */
			_n(
				'Removed %d feed — its previously loaded posts have been removed too.',
				'Removed %d feeds — their previously loaded posts have been removed too.',
				$count,
				'daymark'
			),
			$count
		);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Render a mixed add-and-remove "Update feeds" success notice (issue
	 * #363, 'feeds_updated'/'feeds_updated_pending') — at least one
	 * candidate was newly checked and at least one was newly unchecked in
	 * the same submission, i.e. an actual feed switch. Two independent
	 * counts in one sentence isn't a good `_n()` fit, so this always uses
	 * the plural-shaped wording regardless of either count — "added 1 feeds"
	 * reads slightly odd but stays unambiguous, and a mixed add-and-remove
	 * of exactly one each is the least common shape of this notice anyway.
	 *
	 * @param string $notice 'feeds_updated' or 'feeds_updated_pending'.
	 * @return void
	 */
	private function render_feeds_updated_notice( string $notice ): void {
		$added   = $this->resolve_notice_count();
		$removed = $this->resolve_notice_removed_count();
		$pending = 'feeds_updated_pending' === $notice;

		$message = sprintf(
			$pending
				/* translators: 1: number of feeds added, whose first fetch didn't complete. 2: number of feeds removed. */
				? __( 'Updated this site\'s feeds: added %1$d (their first fetch didn\'t complete — new posts will appear once the next automatic check succeeds), removed %2$d — the removed feeds\' previously loaded posts have been removed too.', 'daymark' )
				/* translators: 1: number of feeds added. 2: number of feeds removed. */
				: __( 'Updated this site\'s feeds: added %1$d, removed %2$d — the removed feeds\' previously loaded posts have been removed too.', 'daymark' ),
			$added,
			$removed
		);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
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
	 * Render the subscribe-by-URL form — or, once a discovery pass has been
	 * run for a just-submitted site (see handle_subscribe(), issue #334),
	 * the "which feed should Daymark follow" picker instead.
	 *
	 * Both are wrapped in one stable `#daymark-new-subscribe-root` container:
	 * `assets/admin-subscriptions.js` (issue #368) replaces this container's
	 * entire innerHTML with the picker fragment `handle_subscribe()`'s own
	 * ajax branch returns, rather than navigating away — so the JS-enhanced
	 * and plain-form-post (no-JS) paths render the exact same markup either
	 * way, just reached differently.
	 *
	 * @return void
	 */
	private function render_subscribe_form(): void {
		$stashed = get_transient( self::new_subscription_transient_key() );
		?>
		<div id="daymark-new-subscribe-root">
			<?php
			if ( is_array( $stashed ) && isset( $stashed['site_url'], $stashed['candidates'] ) && is_array( $stashed['candidates'] ) ) {
				$this->render_new_subscribe_picker(
					(string) $stashed['site_url'],
					isset( $stashed['site_title'] ) ? (string) $stashed['site_title'] : '',
					$stashed['candidates']
				);
			} else {
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
					<span class="description daymark-new-subscribe-error" role="alert" hidden></span>
					<?php
					submit_button(
						__( 'Subscribe', 'daymark' ),
						'primary',
						'daymark-subscribe-submit',
						true,
						array( 'data-daymark-loading-label' => __( 'Loading feed details…', 'daymark' ) )
					);
					?>
				</form>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the "which feed should Daymark follow" picker shown after
	 * clicking Subscribe on a new site (issue #334; reworked to this
	 * single-select, editable-name layout in issue #368) — no subscription
	 * exists yet at this point; handle_subscribe_confirm() is what actually
	 * creates one for the chosen candidate, applying $site_title as an
	 * override when it was edited (see apply_new_subscribe_title_override()).
	 *
	 * Unlike an existing subscription's own "Choose from available feeds"
	 * picker (render_source_picker(), which stays checkbox-based and can add
	 * or remove several feeds at once), this one is deliberately single-select
	 * (radio buttons, sharing render_candidate_checkboxes()'s own markup via
	 * its $multiple = false mode) — there is exactly one new subscription to
	 * create here, not an existing set to reconcile.
	 *
	 * @param string                           $site_url   The site_url
	 *                                                      discovery ran
	 *                                                      against (already
	 *                                                      normalized —
	 *                                                      see
	 *                                                      Daymark_Subscriptions::discover_candidates()'s
	 *                                                      own
	 *                                                      $resolved_site_url
	 *                                                      out-param), shown
	 *                                                      as a link.
	 * @param string                           $site_title The site's own
	 *                                                      discovered title
	 *                                                      (handle_subscribe()'s
	 *                                                      own
	 *                                                      Daymark_Subscription_Source_Feed::get_site_title()
	 *                                                      call, stashed
	 *                                                      alongside
	 *                                                      $candidates) —
	 *                                                      pre-filled into
	 *                                                      the editable name
	 *                                                      field, falling
	 *                                                      back to $site_url
	 *                                                      itself when empty.
	 * @param array<int, array<string, mixed>> $candidates Stashed
	 *                                                       discover_candidates()
	 *                                                       result.
	 * @return void
	 */
	private function render_new_subscribe_picker( string $site_url, string $site_title, array $candidates ): void {
		$display_name = '' !== $site_title ? $site_title : $site_url;
		?>
		<div class="daymark-new-subscribe-picker">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="daymark_subscribe_confirm" />
				<?php wp_nonce_field( 'daymark_subscribe_confirm', 'daymark_subscribe_confirm_nonce' ); ?>
				<div class="daymark-new-subscribe-header">
					<span class="dashicons dashicons-admin-site-alt3 daymark-new-subscribe-avatar" aria-hidden="true"></span>
					<strong data-daymark-title-text><?php echo esc_html( $display_name ); ?></strong>
					<details class="daymark-new-subscribe-edit-name" data-daymark-fallback-label="<?php echo esc_attr( $site_url ); ?>">
						<summary class="daymark-edit-title-trigger" aria-label="<?php esc_attr_e( 'Edit site name', 'daymark' ); ?>" title="<?php esc_attr_e( 'Edit site name', 'daymark' ); ?>">
							<span class="dashicons dashicons-edit" aria-hidden="true"></span>
						</summary>
						<input type="text" name="daymark_site_title" value="<?php echo esc_attr( $site_title ); ?>" placeholder="<?php echo esc_attr( $site_url ); ?>" class="daymark-new-subscribe-title-input" />
					</details>
					<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site_url ); ?></a>
				</div>
				<fieldset>
					<legend><strong><?php esc_html_e( 'Feeds found for this site:', 'daymark' ); ?></strong></legend>
					<?php $this->render_candidate_checkboxes( $candidates, '', true, false ); ?>
				</fieldset>
				<?php submit_button( __( 'Save Subscription', 'daymark' ), 'primary', 'daymark-subscribe-confirm-submit', false, array( 'style' => 'margin-right:6px;' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-top:6px;">
				<input type="hidden" name="action" value="daymark_subscribe_cancel" />
				<?php wp_nonce_field( 'daymark_subscribe_cancel', 'daymark_subscribe_cancel_nonce' ); ?>
				<?php submit_button( __( 'Cancel', 'daymark' ), 'secondary small', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render one candidate checkbox per discovered feed — shared by the
	 * new-subscribe picker above and an existing subscription's own "Choose
	 * from available feeds" picker below (issue #334), so the two flows can
	 * never render a candidate differently.
	 *
	 * Every checkbox shares the input name `daymark_candidate_index[]` and,
	 * as of issue #363, is freely toggleable — including a candidate already
	 * subscribed to (this row's own current feed_url, `$current_feed_url`,
	 * or a different existing subscription to the same URL), which renders
	 * checked but no longer `disabled`. In the existing-row "Update feeds"
	 * context (render_source_picker()), unchecking one of these and
	 * submitting is what lets reconcile_selected_candidates() unsubscribe
	 * it. In the brand-new-site context ($current_feed_url === ''), a
	 * checked-but-already-subscribed-elsewhere candidate is harmless either
	 * way — subscribe_to_selected_candidates() already silently skips a
	 * pick that turns out to be a duplicate by the time it runs, the same
	 * tolerance it already extends to a same-request race. The
	 * "(current)"/"(already subscribed)" labels below are informational
	 * only now, not a statement that the box can't be changed.
	 *
	 * @param array<int, array<string, mixed>> $candidates          Stashed
	 *                                                                discover_candidates()
	 *                                                                result.
	 * @param string                           $current_feed_url    An
	 *                                                               existing
	 *                                                               subscription's
	 *                                                               own
	 *                                                               feed_url
	 *                                                               to mark
	 *                                                               "(current)"
	 *                                                               — '' in
	 *                                                               the
	 *                                                               new-subscribe
	 *                                                               context,
	 *                                                               which
	 *                                                               has no
	 *                                                               existing
	 *                                                               row of
	 *                                                               its own
	 *                                                               yet.
	 * @param bool                             $default_check_best  Whether
	 *                                                               to
	 *                                                               additionally
	 *                                                               pre-check
	 *                                                               the
	 *                                                               single
	 *                                                               richest
	 *                                                               candidate
	 *                                                               (Daymark_Subscriptions::most_optimal_candidate_index())
	 *                                                               — the
	 *                                                               new-subscribe
	 *                                                               flow's
	 *                                                               own "at
	 *                                                               least
	 *                                                               one box
	 *                                                               checked
	 *                                                               by
	 *                                                               default"
	 *                                                               requirement;
	 *                                                               an
	 *                                                               existing
	 *                                                               row
	 *                                                               already
	 *                                                               has its
	 *                                                               own
	 *                                                               current
	 *                                                               feed
	 *                                                               checked,
	 *                                                               so it
	 *                                                               passes
	 *                                                               false.
	 * @param bool                             $multiple            Whether
	 *                                                               more than
	 *                                                               one
	 *                                                               candidate
	 *                                                               can be
	 *                                                               selected
	 *                                                               at once
	 *                                                               (checkboxes,
	 *                                                               the
	 *                                                               existing-row
	 *                                                               "Choose
	 *                                                               from
	 *                                                               available
	 *                                                               feeds"
	 *                                                               default)
	 *                                                               or
	 *                                                               exactly
	 *                                                               one
	 *                                                               (radio
	 *                                                               buttons,
	 *                                                               all
	 *                                                               sharing
	 *                                                               the same
	 *                                                               input
	 *                                                               name so
	 *                                                               the
	 *                                                               browser
	 *                                                               itself
	 *                                                               enforces
	 *                                                               mutual
	 *                                                               exclusivity
	 *                                                               —
	 *                                                               issue
	 *                                                               #368's
	 *                                                               new-subscribe
	 *                                                               picker,
	 *                                                               which
	 *                                                               only
	 *                                                               ever
	 *                                                               creates
	 *                                                               one
	 *                                                               subscription).
	 *                                                               In
	 *                                                               single-select
	 *                                                               mode
	 *                                                               only the
	 *                                                               richest
	 *                                                               candidate
	 *                                                               is
	 *                                                               pre-checked
	 *                                                               —
	 *                                                               $is_current/$already
	 *                                                               never
	 *                                                               force a
	 *                                                               second
	 *                                                               checked
	 *                                                               radio,
	 *                                                               which
	 *                                                               would be
	 *                                                               invalid;
	 *                                                               their
	 *                                                               "(current)"/"(already
	 *                                                               subscribed)"
	 *                                                               labels
	 *                                                               still
	 *                                                               render,
	 *                                                               informational
	 *                                                               only.
	 *                                                               `handle_subscribe_confirm()`
	 *                                                               needs no
	 *                                                               change
	 *                                                               either
	 *                                                               way:
	 *                                                               PHP
	 *                                                               already
	 *                                                               parses
	 *                                                               a
	 *                                                               `[]`-suffixed
	 *                                                               name
	 *                                                               into an
	 *                                                               array
	 *                                                               regardless
	 *                                                               of
	 *                                                               whether
	 *                                                               checkbox
	 *                                                               or radio
	 *                                                               inputs
	 *                                                               produced
	 *                                                               it.
	 * @return void
	 */
	private function render_candidate_checkboxes( array $candidates, string $current_feed_url, bool $default_check_best, bool $multiple = true ): void {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$best_index    = $default_check_best ? Daymark_Subscriptions::most_optimal_candidate_index( $candidates ) : -1;
		$input_type    = $multiple ? 'checkbox' : 'radio';

		foreach ( $candidates as $index => $candidate ) {
			$candidate_url   = isset( $candidate['url'] ) ? (string) $candidate['url'] : '';
			$source_label    = isset( $candidate['source_label'] ) ? (string) $candidate['source_label'] : '';
			$candidate_title = isset( $candidate['title'] ) ? (string) $candidate['title'] : '';
			$language_name   = $this->language_display_name( isset( $candidate['language'] ) ? (string) $candidate['language'] : '' );
			$is_current      = '' !== $candidate_url && $candidate_url === $current_feed_url;
			$already         = '' !== $candidate_url && null !== $subscriptions->get_by_feed_url( $candidate_url );
			$checked         = $multiple
				? ( $is_current || $already || ( $index === $best_index ) )
				: ( $index === $best_index );
			?>
			<label style="display:block;margin:4px 0;">
				<input
					type="<?php echo esc_attr( $input_type ); ?>"
					name="daymark_candidate_index[]"
					value="<?php echo esc_attr( (string) $index ); ?>"
					<?php checked( $checked ); ?>
				/>
				<strong>
					<?php echo esc_html( $source_label ); ?>
					<?php if ( '' !== $language_name ) : ?>
						(<?php echo esc_html( $language_name ); ?>)
					<?php endif; ?>
				</strong>
				<?php if ( '' !== $candidate_title ) : ?>
					— <?php echo esc_html( $candidate_title ); ?>
				<?php endif; ?>
				<?php if ( $is_current ) : ?>
					<em>(<?php esc_html_e( 'current', 'daymark' ); ?>)</em>
				<?php elseif ( $already ) : ?>
					<em>(<?php esc_html_e( 'already subscribed', 'daymark' ); ?>)</em>
				<?php endif; ?>
				<br />
				<code class="daymark-candidate-url" style="margin-left:24px;"><?php echo esc_html( $candidate_url ); ?></code>
			</label>
			<?php
		}
	}

	/**
	 * A candidate's own hreflang-derived `language` code (issue #336),
	 * mapped to a human-readable name for the checkbox picker — e.g. `pt-br`
	 * -> "Portuguese". Matched by primary language subtag only (region is
	 * ignored for display purposes, e.g. `pt`/`pt-br`/`pt-pt` all read
	 * "Portuguese") against a small, fixed lookup of common languages; a
	 * code not in that lookup falls back to displaying the raw code itself
	 * (uppercased) rather than guessing — no new locale-data dependency for
	 * an exhaustive list.
	 *
	 * @since 0.16.0
	 *
	 * @param string $language Raw `language` value from a candidate, or ''.
	 * @return string Human-readable name, or '' when $language is empty.
	 */
	private function language_display_name( string $language ): string {
		$language = strtolower( trim( $language ) );

		if ( '' === $language ) {
			return '';
		}

		$names = array(
			'en' => __( 'English', 'daymark' ),
			'es' => __( 'Spanish', 'daymark' ),
			'pt' => __( 'Portuguese', 'daymark' ),
			'fr' => __( 'French', 'daymark' ),
			'de' => __( 'German', 'daymark' ),
			'it' => __( 'Italian', 'daymark' ),
			'nl' => __( 'Dutch', 'daymark' ),
			'ru' => __( 'Russian', 'daymark' ),
			'ja' => __( 'Japanese', 'daymark' ),
			'zh' => __( 'Chinese', 'daymark' ),
			'ko' => __( 'Korean', 'daymark' ),
			'ar' => __( 'Arabic', 'daymark' ),
			'hi' => __( 'Hindi', 'daymark' ),
			'tr' => __( 'Turkish', 'daymark' ),
			'pl' => __( 'Polish', 'daymark' ),
			'sv' => __( 'Swedish', 'daymark' ),
			'da' => __( 'Danish', 'daymark' ),
			'fi' => __( 'Finnish', 'daymark' ),
			'no' => __( 'Norwegian', 'daymark' ),
			'cs' => __( 'Czech', 'daymark' ),
			'el' => __( 'Greek', 'daymark' ),
			'he' => __( 'Hebrew', 'daymark' ),
			'id' => __( 'Indonesian', 'daymark' ),
			'th' => __( 'Thai', 'daymark' ),
			'vi' => __( 'Vietnamese', 'daymark' ),
			'uk' => __( 'Ukrainian', 'daymark' ),
			'ro' => __( 'Romanian', 'daymark' ),
			'hu' => __( 'Hungarian', 'daymark' ),
		);

		$subtag = explode( '-', $language )[0];

		return $names[ $subtag ] ?? strtoupper( $language );
	}

	/**
	 * The transient key holding a stashed new-subscribe discover_candidates()
	 * result (issue #334) — per-user, matching every other stash this screen
	 * already uses (OPML import results, an existing row's own source-picker
	 * stash below), so two admins working at once never clobber each other's
	 * in-progress subscribe attempt.
	 *
	 * @return string
	 */
	private static function new_subscription_transient_key(): string {
		return 'daymark_new_subscription_candidates_' . get_current_user_id();
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
	 * @param string $orderby The active sort column — always one of
	 *                        SORTABLE_COLUMNS (see DEFAULT_ORDERBY).
	 * @param string $order   'asc' or 'desc'.
	 * @return void
	 */
	private function render_search_form( string $search, string $orderby, string $order ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="subscriptions" />
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
			<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
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
	 * @param string                           $orderby       The active sort column — always one
	 *                                                         of SORTABLE_COLUMNS (see
	 *                                                         DEFAULT_ORDERBY).
	 * @param string                           $order         'asc' or 'desc'.
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
	 * @return array{orderby: string, order: string} `orderby` is always one
	 *         of SORTABLE_COLUMNS — DEFAULT_ORDERBY when `?orderby=` is
	 *         absent or invalid, never ''; `order` is always 'asc' or
	 *         'desc'.
	 */
	private function resolve_sort_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort of this screen's own table; not a state-changing action.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort of this screen's own table; not a state-changing action.
		$order = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';

		if ( ! in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
			$orderby = self::DEFAULT_ORDERBY;
		}

		return array(
			'orderby' => $orderby,
			'order'   => 'desc' === $order ? 'desc' : 'asc',
		);
	}

	/**
	 * Sort a list of subscription rows for display (issue #178).
	 * resolve_sort_request() always hands back one of SORTABLE_COLUMNS
	 * (defaulting to DEFAULT_ORDERBY), so every call here actually sorts —
	 * there's no "leave get_all()'s raw order alone" case to special-case.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions Rows to sort.
	 * @param string                           $orderby       One of SORTABLE_COLUMNS.
	 * @param string                           $order         'asc' or 'desc'.
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_subscriptions( array $subscriptions, string $orderby, string $order ): array {
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
		$feed_url      = (string) ( $subscription['feed_url'] ?? '' );
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
		<tr data-daymark-subscription-sources-row="<?php echo esc_attr( (string) $id ); ?>">
			<td colspan="4" style="padding-top:0;">
				<?php $this->render_source_switch_control( $id, $site_url, $feed_url ); ?>
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
	 * Render one subscription's "Choose from available feeds" / candidate-
	 * picker control. Originally issue #307's single-choice "Check for other
	 * feeds" switch; reworked to an additive multi-select picker in issue
	 * #334; reworked again in issue #363 so unchecking an already-subscribed
	 * candidate unsubscribes it. Lets a person add another feed from the
	 * same site alongside this subscription, or replace this one with a
	 * differently-scoped one in the same submission (most usefully when
	 * Daymark's automatic pick turns out to be the wrong one for that site —
	 * e.g. a WordPress REST API mixing multiple languages together, where
	 * the site's own language-scoped RSS/Atom feed would have been correctly
	 * scoped).
	 *
	 * Step one is a plain trigger — clicking it runs a fresh discovery pass
	 * (Daymark_Subscriptions::discover_candidates()) and stashes the result
	 * in a short-lived, per-subscription, per-user transient (the same
	 * POST-redirect-GET-survives-via-transient convention the OPML import
	 * results already use), then redirects back. Step two only renders once
	 * that transient exists for this exact subscription: a checkbox picker
	 * listing every discovered candidate, reconciled on submit against
	 * what's actually subscribed right now — see reconcile_selected_candidates().
	 * Deliberately not run automatically on every page load — discovery is a
	 * live outbound fetch, and doing it for every row of a subscriptions
	 * table on every visit would be its own real cost for something most
	 * rows will never need.
	 *
	 * The trigger form is progressively enhanced by
	 * assets/admin-subscriptions.js to submit via `fetch()` (the same
	 * `X-Daymark-Ajax` header convention handle_subscribe() already
	 * established) so the resulting picker is injected directly into this
	 * row's own cell instead of the whole page reloading and losing scroll
	 * position back to the top of a possibly-long subscriptions table. A
	 * plain browser POST (no JS, or the header stripped) is unaffected: it
	 * still redirects exactly as before, and this method renders the
	 * identical picker markup from the same stashed transient either way.
	 *
	 * @param int    $id       Subscription ID.
	 * @param string $site_url The subscription's own site_url — discovery
	 *                         runs against this, not feed_url.
	 * @param string $feed_url The subscription's current feed_url, so the
	 *                         picker (once shown) can mark which candidate is
	 *                         already active.
	 * @return void
	 */
	private function render_source_switch_control( int $id, string $site_url, string $feed_url ): void {
		$stashed = get_transient( self::subscription_sources_transient_key( $id ) );

		if ( is_array( $stashed ) && isset( $stashed['site_url'], $stashed['candidates'] ) && (string) $stashed['site_url'] === $site_url && is_array( $stashed['candidates'] ) ) {
			$this->render_source_picker( $id, $stashed['candidates'], $feed_url );

			return;
		}
		?>
		<form class="daymark-subscription-discover-sources-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="daymark_subscription_discover_sources" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_discover_sources_' . $id, 'daymark_subscription_discover_sources_nonce' ); ?>
			<?php submit_button( __( 'Choose from available feeds', 'daymark' ), 'secondary small', 'submit', false ); ?>
		</form>
		<div class="description daymark-subscription-discover-sources-error" hidden></div>
		<?php
	}

	/**
	 * Capture render_source_switch_control()'s own echoed output as a
	 * string, for handle_discover_sources()'s ajax response — the exact
	 * same markup a full page reload would show for this row's own
	 * "Choose from available feeds" cell, just returned instead of printed
	 * directly so it can be injected in place of that cell's current content.
	 *
	 * @param int    $id       See render_source_switch_control().
	 * @param string $site_url See render_source_switch_control().
	 * @param string $feed_url See render_source_switch_control().
	 * @return string
	 */
	private function captured_render_source_switch_control( int $id, string $site_url, string $feed_url ): string {
		ob_start();
		$this->render_source_switch_control( $id, $site_url, $feed_url );

		return (string) ob_get_clean();
	}

	/**
	 * Render the candidate picker itself, once a discovery pass has stashed
	 * results for this subscription (see render_source_switch_control()).
	 *
	 * Every candidate — including this row's own current feed_url and any
	 * other already-subscribed candidate — is a plain, freely-toggleable
	 * checkbox (issue #363; previously `checked disabled`, issue #334):
	 * unchecking an already-subscribed candidate and submitting unsubscribes
	 * it (purging its cached content), while checking a not-yet-subscribed
	 * one subscribes to it — see reconcile_selected_candidates(). The
	 * "(current)"/"(already subscribed)" labels are informational only now.
	 * Submitting with nothing changed is a harmless no-op. A "Discard" action
	 * clears the stashed transient without changing anything, for a person
	 * who only wanted to look.
	 *
	 * @param int                              $id               Subscription ID.
	 * @param array<int, array<string, mixed>> $candidates       Stashed discover_candidates() result.
	 * @param string                           $current_feed_url The subscription's current feed_url.
	 * @return void
	 */
	private function render_source_picker( int $id, array $candidates, string $current_feed_url ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="daymark_subscription_update_feeds" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_update_feeds_' . $id, 'daymark_subscription_update_feeds_nonce' ); ?>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Feeds found for this site:', 'daymark' ); ?></strong></legend>
				<?php $this->render_candidate_checkboxes( $candidates, $current_feed_url, false ); ?>
			</fieldset>
			<?php submit_button( __( 'Update feeds', 'daymark' ), 'secondary small', 'submit', false, array( 'style' => 'margin-right:6px;' ) ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-top:6px;">
			<input type="hidden" name="action" value="daymark_subscription_discover_sources_dismiss" />
			<input type="hidden" name="daymark_subscription_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'daymark_subscription_discover_sources_dismiss_' . $id, 'daymark_subscription_discover_sources_dismiss_nonce' ); ?>
			<?php submit_button( __( 'Discard', 'daymark' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * The transient key holding a subscription's stashed discover_candidates()
	 * result — per-subscription and per-user, matching the OPML import
	 * results' own convention, so two admins working at once never clobber
	 * each other's in-progress source switch.
	 *
	 * @param int $id Subscription ID.
	 * @return string
	 */
	private static function subscription_sources_transient_key( int $id ): string {
		return 'daymark_subscription_sources_' . $id . '_' . get_current_user_id();
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
	 * `classes`/`constants`, where present, are an additional fallback
	 * signal for connector_status() (issue #342): a defining class/constant
	 * present at runtime can only be true for a plugin that's genuinely
	 * active (inactive plugin code is never loaded by PHP), so it catches
	 * a build whose installed folder doesn't match `folder_slug` at all —
	 * confirmed necessary for ATmosphere specifically, whose values here
	 * mirror `Daymark_Publish_Helpers::PLUGINS['atmosphere']`'s own,
	 * already-working multi-signal detection of the same plugin.
	 *
	 * @return array<string, array{label: string, type?: string, wporg_slug?: string, folder_slug?: string, classes?: string[], constants?: string[], url?: string, description: string}>
	 */
	private static function recommended_connectors(): array {
		return array(
			'webmention'  => array(
				'label'       => 'Webmention',
				'wporg_slug'  => 'webmention',
				'folder_slug' => 'webmention',
				'description' => __( "Sends and receives Webmentions automatically — a reply you compose to a subscribed post notifies its source the moment you publish, and mentions from across the IndieWeb arrive back as native comments Daymark already recognizes and labels in Notifications. It also improves the commenting experience for other Daymark users who subscribe to your site: with this active, someone reading one of your posts in their own Daymark app can comment directly from there instead of being redirected to your site's own comment form.", 'daymark' ),
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
				'classes'     => array( 'Atmosphere\\Publisher' ),
				'constants'   => array( 'ATMOSPHERE_VERSION' ),
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
		return Daymark_Plugin_Detector::find_plugin_file( $connector['folder_slug'] );
	}

	/**
	 * A recommended connector's current state: 'active', 'inactive'
	 * (installed but not active), or 'not_installed'.
	 *
	 * A folder-slug match wins first, exactly as before. When it finds
	 * nothing at all (issue #342 — a republished/renamed build installed
	 * under a folder that doesn't match `folder_slug`), falls back to
	 * `Daymark_Plugin_Detector::matches()` against the connector's own
	 * `classes`/`constants` signals: a match there can only ever mean
	 * 'active' (that code can't be loaded by an inactive plugin), never
	 * 'inactive' — there's no installed-but-not-active state this
	 * fallback can detect, since it has no folder to point Activate at.
	 *
	 * @param array<string, string|string[]> $connector One RECOMMENDED_CONNECTORS entry.
	 * @return string
	 */
	private function connector_status( array $connector ): string {
		$plugin_file = $this->connector_plugin_file( $connector );

		if ( null !== $plugin_file ) {
			return is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
		}

		$signals = array(
			'classes'   => $connector['classes'] ?? array(),
			'constants' => $connector['constants'] ?? array(),
		);

		if ( Daymark_Plugin_Detector::matches( $signals ) ) {
			return 'active';
		}

		return 'not_installed';
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
	 * Handle the subscribe-by-URL form (admin_post_daymark_subscribe,
	 * reworked in issue #334 from an immediate single-pick subscribe into
	 * this flow's own discovery step): runs Daymark_Subscriptions::discover_candidates()
	 * against the submitted URL and stashes the result — the normalized
	 * site_url discover_candidates() itself resolved, via its own
	 * $resolved_site_url out-param, not necessarily the raw text typed in,
	 * plus the site's own discovered title (pre-filling the picker's
	 * editable name field, issue #368) — in a short-lived, per-user
	 * transient, then either responds directly (see below) or redirects
	 * back so render_subscribe_form() shows the "which feed should Daymark
	 * follow" picker instead of the plain URL field. No subscription exists
	 * yet at this point; handle_subscribe_confirm() is what actually
	 * creates one for the chosen feed.
	 *
	 * `subscribe_to_site()` — REST subscribing's own single-pick, fully
	 * automatic path — is unaffected; this screen simply no longer calls it.
	 *
	 * Issue #368's JS enhancement (assets/admin-subscriptions.js) submits
	 * this same form via `fetch()` instead of letting it navigate, marked by
	 * an `X-Daymark-Ajax` request header the plain browser POST never sends —
	 * that path responds with a small `wp_send_json_*` envelope (the
	 * rendered picker fragment, or an error message) instead of redirecting,
	 * so the picker can be injected inline without a full page reload. A
	 * plain form submission (no JS, or the header stripped) is completely
	 * unaffected: it still redirects exactly as before, and
	 * render_subscribe_form() renders the identical picker markup from the
	 * same stashed transient either way.
	 *
	 * @return void
	 */
	public function handle_subscribe(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscribe', 'daymark_subscribe_nonce' );

		$is_ajax = isset( $_SERVER['HTTP_X_DAYMARK_AJAX'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DAYMARK_AJAX'] ) );

		// Same outbound-request risk class as the REST endpoint (a feed
		// discovery + favicon request to a site the user names) — rate
		// limited for parity with it, even though this admin screen's own
		// request volume is naturally far lower.
		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			$this->respond_to_subscribe_attempt( $is_ajax, $rate->get_error_message() );

			return;
		}

		$site_url = isset( $_POST['daymark_site_url'] ) ? esc_url_raw( wp_unslash( $_POST['daymark_site_url'] ) ) : '';

		$resolved_site_url = null;
		$candidates        = Daymark_Plugin::instance()->subscriptions->discover_candidates( $site_url, $resolved_site_url );

		if ( is_wp_error( $candidates ) ) {
			$this->respond_to_subscribe_attempt( $is_ajax, $candidates->get_error_message() );

			return;
		}

		$resolved_site_url = (string) $resolved_site_url;
		$site_title        = $this->discover_site_title_for_preview( $resolved_site_url );

		set_transient(
			self::new_subscription_transient_key(),
			array(
				'site_url'   => $resolved_site_url,
				'site_title' => $site_title,
				'candidates' => $candidates,
			),
			5 * MINUTE_IN_SECONDS
		);

		if ( $is_ajax ) {
			wp_send_json_success(
				array(
					'html' => $this->captured_render_new_subscribe_picker( $resolved_site_url, $site_title, $candidates ),
				)
			);

			return;
		}

		$this->redirect( array() );
	}

	/**
	 * Respond to a failed discovery attempt the same way for both the
	 * ajax and plain-form-post paths of handle_subscribe() — a JSON error
	 * envelope for the former, the existing redirect-with-error-notice
	 * behavior for the latter.
	 *
	 * @param bool   $is_ajax Whether this request carried the
	 *                        `X-Daymark-Ajax` header (see handle_subscribe()).
	 * @param string $message Human-readable error message.
	 * @return void
	 */
	private function respond_to_subscribe_attempt( bool $is_ajax, string $message ): void {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => $message ) );

			return;
		}

		$this->redirect_with_error( $message );
	}

	/**
	 * Best-effort site title to pre-fill the new-subscribe picker's editable
	 * name field with — the same value finish_subscribe() would otherwise
	 * only resolve after a subscription already exists (see
	 * Daymark_Subscription_Source_Feed::get_site_title()'s own docblock).
	 * Reading it here, ahead of time, costs no extra live request:
	 * get_site_title() (like every other discovery-time fetch) is routed
	 * through Daymark_Subscription_Html_Cache, and discover_candidates()
	 * just fetched this exact $site_url's own HTML for autodiscovery, so
	 * this call hits that same per-request cache.
	 *
	 * @param string $site_url Resolved site URL, as returned by
	 *                         discover_candidates()'s own $resolved_site_url
	 *                         out-param.
	 * @return string The discovered title, or '' when none could be found —
	 *                render_new_subscribe_picker() falls back to $site_url
	 *                itself in that case, same as an ordinary subscription's
	 *                own subscription_label().
	 */
	private function discover_site_title_for_preview( string $site_url ): string {
		$feed_source = Daymark_Plugin::instance()->subscription_source_registry->get_source( 'feed' );

		if ( ! ( $feed_source instanceof Daymark_Subscription_Source_Feed ) ) {
			return '';
		}

		return $feed_source->get_site_title( $site_url );
	}

	/**
	 * Capture render_new_subscribe_picker()'s own echoed output as a string,
	 * for handle_subscribe()'s ajax response — the exact same markup
	 * render_subscribe_form() would show on a full page reload, just
	 * returned instead of printed directly.
	 *
	 * @param string                           $site_url   See render_new_subscribe_picker().
	 * @param string                           $site_title See render_new_subscribe_picker().
	 * @param array<int, array<string, mixed>> $candidates See render_new_subscribe_picker().
	 * @return string
	 */
	private function captured_render_new_subscribe_picker( string $site_url, string $site_title, array $candidates ): string {
		ob_start();
		$this->render_new_subscribe_picker( $site_url, $site_title, $candidates );

		return (string) ob_get_clean();
	}

	/**
	 * Handle the new-subscribe picker's "Save Subscription" submit
	 * (admin_post_daymark_subscribe_confirm, issue #334; picker reworked to
	 * a single-select, editable-name layout in issue #368): reads the
	 * checked `daymark_candidate_index[]` value(s) back out of this user's
	 * own stashed discover_candidates() result (never a raw posted URL — the
	 * same "trust only what discover_candidates() itself just produced"
	 * posture subscribe_to_candidate()'s own docblock already establishes),
	 * and creates one new subscription per selected candidate via
	 * Daymark_Subscriptions::subscribe_to_candidate() — best-effort
	 * immediately polling each new subscription, same as the original
	 * single-pick subscribe flow always did. A posted `daymark_site_title`
	 * (the picker's editable name field) then overrides each newly created
	 * subscription's own discovered title — see
	 * apply_new_subscribe_title_override().
	 *
	 * @return void
	 */
	public function handle_subscribe_confirm(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscribe_confirm', 'daymark_subscribe_confirm_nonce' );

		$transient_key = self::new_subscription_transient_key();
		$stashed       = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( ! is_array( $stashed ) || ! isset( $stashed['site_url'], $stashed['candidates'] ) || ! is_array( $stashed['candidates'] ) ) {
			$this->redirect_with_error( __( 'That list of feeds has expired — please try again.', 'daymark' ) );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer().
		$indices = isset( $_POST['daymark_candidate_index'] ) && is_array( $_POST['daymark_candidate_index'] )
			? array_map( 'absint', wp_unslash( $_POST['daymark_candidate_index'] ) )
			: array();

		if ( empty( $indices ) ) {
			$this->redirect_with_error( __( 'Please choose at least one feed to subscribe to.', 'daymark' ) );

			return;
		}

		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			$this->redirect_with_error( $rate->get_error_message() );

			return;
		}

		$site_url = (string) $stashed['site_url'];
		$outcome  = $this->subscribe_to_selected_candidates( $site_url, $stashed['candidates'], $indices );

		if ( 0 === $outcome['added'] ) {
			$this->redirect_with_error( __( 'Those feeds could not be subscribed to — they may already be subscribed.', 'daymark' ) );

			return;
		}

		$title_override = isset( $_POST['daymark_site_title'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer().
			? sanitize_text_field( wp_unslash( $_POST['daymark_site_title'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer().
			: null;

		$this->apply_new_subscribe_title_override( $outcome['ids'], $title_override );

		$this->redirect(
			array(
				self::NOTICE_QUERY_VAR => $outcome['pending'] > 0 ? 'subscribed_pending' : 'subscribed',
				self::COUNT_QUERY_VAR  => (string) $outcome['added'],
			)
		);
	}

	/**
	 * Apply the new-subscribe picker's editable name field to every
	 * subscription subscribe_to_selected_candidates() just created — the
	 * picker's single radio-selected candidate in normal use, so this
	 * updates exactly one row (issue #368), but written to loop over
	 * whatever `$ids` it's given rather than assuming exactly one.
	 *
	 * A blank $title is applied too, not skipped — the same "submitting a
	 * blank value clears back to the site_url fallback" behavior
	 * render_edit_title_form()'s own docblock already documents for an
	 * existing subscription's inline editor.
	 *
	 * @param int[]       $ids   Newly created subscription IDs.
	 * @param string|null $title The posted `daymark_site_title` value, or
	 *                           null when the field wasn't present in the
	 *                           request at all (nothing to apply).
	 * @return void
	 */
	private function apply_new_subscribe_title_override( array $ids, ?string $title ): void {
		if ( null === $title || empty( $ids ) ) {
			return;
		}

		$subscriptions = Daymark_Plugin::instance()->subscriptions;

		foreach ( $ids as $id ) {
			$subscriptions->update( (int) $id, array( 'site_title' => $title ) );
		}
	}

	/**
	 * Handle the new-subscribe picker's "Cancel" submit
	 * (admin_post_daymark_subscribe_cancel, issue #334): discards the
	 * stashed discovery result without subscribing to anything, the same
	 * "Discard" behavior an existing subscription's own source picker
	 * already offers.
	 *
	 * @return void
	 */
	public function handle_subscribe_cancel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		check_admin_referer( 'daymark_subscribe_cancel', 'daymark_subscribe_cancel_nonce' );

		delete_transient( self::new_subscription_transient_key() );

		$this->redirect( array() );
	}

	/**
	 * Subscribe to every selected, not-already-subscribed candidate in a
	 * discover_candidates() result — originally the shared tail of both the
	 * brand-new-site flow and an existing subscription's own feed picker
	 * (issue #334); as of issue #363 the existing-row picker can also
	 * *remove* a feed by unchecking it, which this method has no concept of
	 * (a fresh site has no existing subscription to remove one from) — see
	 * reconcile_selected_candidates() for that flow instead. Used only by
	 * handle_subscribe_confirm() now.
	 *
	 * A candidate a caller selected that turns out to already be subscribed
	 * by the time this runs (a race between two requests, most plausibly) is
	 * silently skipped rather than surfaced as a per-candidate error — the
	 * same forgiving, best-effort tolerance Daymark_Subscription_OPML::import()
	 * already applies per entry, and correct here too: the caller only cares
	 * whether *something* new was added, not why one specific pick wasn't.
	 *
	 * @param string                           $site_url   Site URL the candidates were discovered from.
	 * @param array<int, array<string, mixed>> $candidates The full stashed
	 *                                                       discover_candidates()
	 *                                                       result — indices
	 *                                                       in `$indices` are
	 *                                                       looked up here.
	 * @param int[]                            $indices    The candidate
	 *                                                      indices a person
	 *                                                      actually checked.
	 * @return array{added: int, pending: int, ids: int[]} `added` is how many
	 *                                          new subscriptions were
	 *                                          created; `pending` is how many
	 *                                          of those had their immediate
	 *                                          best-effort poll fail (their
	 *                                          posts arrive on the next
	 *                                          scheduled check instead);
	 *                                          `ids` is every newly created
	 *                                          subscription's own ID (issue
	 *                                          #368 — so a caller can apply a
	 *                                          post-create update, such as a
	 *                                          site_title override, without
	 *                                          re-deriving which rows were
	 *                                          just added).
	 */
	private function subscribe_to_selected_candidates( string $site_url, array $candidates, array $indices ): array {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$poller        = Daymark_Plugin::instance()->subscription_poller;
		$added         = 0;
		$pending       = 0;
		$ids           = array();

		foreach ( $indices as $index ) {
			if ( ! isset( $candidates[ $index ] ) || ! is_array( $candidates[ $index ] ) ) {
				continue;
			}

			$result = $subscriptions->subscribe_to_candidate( $site_url, $candidates[ $index ] );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			++$added;
			$ids[] = (int) $result;

			// Best-effort immediate poll, matching the original single-pick
			// subscribe flow's own "don't make them wait for the next
			// scheduled check" behavior — a failure here doesn't change the
			// outcome, the next scheduled poll keeps trying.
			if ( is_wp_error( $poller->manual_refresh( (int) $result ) ) ) {
				++$pending;
			}
		}

		return array(
			'added'   => $added,
			'pending' => $pending,
			'ids'     => $ids,
		);
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
	 * Handle the "Choose from available feeds" form (issue #307, renamed
	 * from "Check for other feeds" in issue #334;
	 * admin_post_daymark_subscription_discover_sources): runs a fresh
	 * discovery pass for this subscription's own site_url across every
	 * registered subscription source, stashes the full result in a
	 * short-lived transient, then redirects back so
	 * render_source_switch_control() renders the picker for this row instead
	 * of the plain trigger. Same outbound-request risk class as subscribing
	 * itself — issues live requests to a site the user (already) named — so
	 * it's rate limited the same way.
	 *
	 * Same `X-Daymark-Ajax` progressive-enhancement convention
	 * handle_subscribe() already established: a request carrying that header
	 * (assets/admin-subscriptions.js's own fetch-based submit) gets a
	 * `wp_send_json_*` envelope back — this row's own re-rendered cell markup
	 * on success, an error message on failure — instead of a redirect, so the
	 * picker can replace the trigger button in place rather than the whole
	 * page reloading. A plain browser POST (no JS, or the header stripped)
	 * is completely unaffected: every early-return below still redirects
	 * exactly as before.
	 *
	 * @return void
	 */
	public function handle_discover_sources(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_discover_sources_' . $id, 'daymark_subscription_discover_sources_nonce' );

		$is_ajax = isset( $_SERVER['HTTP_X_DAYMARK_AJAX'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DAYMARK_AJAX'] ) );

		$subscription = Daymark_Plugin::instance()->subscriptions->get( $id );

		if ( null === $subscription ) {
			$this->respond_to_discover_sources_attempt( $is_ajax, __( 'That subscription no longer exists.', 'daymark' ) );

			return;
		}

		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			$this->respond_to_discover_sources_attempt( $is_ajax, $rate->get_error_message() );

			return;
		}

		$site_url   = (string) ( $subscription['site_url'] ?? '' );
		$candidates = Daymark_Plugin::instance()->subscriptions->discover_candidates( $site_url );

		if ( is_wp_error( $candidates ) ) {
			$this->respond_to_discover_sources_attempt( $is_ajax, $candidates->get_error_message() );

			return;
		}

		set_transient(
			self::subscription_sources_transient_key( $id ),
			array(
				'site_url'   => $site_url,
				'candidates' => $candidates,
			),
			5 * MINUTE_IN_SECONDS
		);

		if ( $is_ajax ) {
			wp_send_json_success(
				array(
					'html' => $this->captured_render_source_switch_control( $id, $site_url, (string) ( $subscription['feed_url'] ?? '' ) ),
				)
			);

			return;
		}

		$this->redirect( array() );
	}

	/**
	 * Respond to a failed handle_discover_sources() attempt the same way for
	 * both the ajax and plain-form-post paths — a JSON error envelope for
	 * the former, the existing redirect-with-error-notice behavior for the
	 * latter. Mirrors respond_to_subscribe_attempt()'s own identical shape
	 * for the new-subscribe flow.
	 *
	 * @param bool   $is_ajax Whether this request carried the
	 *                        `X-Daymark-Ajax` header.
	 * @param string $message Human-readable error message.
	 * @return void
	 */
	private function respond_to_discover_sources_attempt( bool $is_ajax, string $message ): void {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => $message ) );

			return;
		}

		$this->redirect_with_error( $message );
	}

	/**
	 * Handle the "Discard" form on the source picker
	 * (admin_post_daymark_subscription_discover_sources_dismiss): clears the
	 * stashed candidates without adding anything, so the row's plain
	 * "Choose from available feeds" trigger comes back instead of the picker
	 * lingering for its own transient's remaining lifetime.
	 *
	 * @return void
	 */
	public function handle_discover_sources_dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_discover_sources_dismiss_' . $id, 'daymark_subscription_discover_sources_dismiss_nonce' );

		delete_transient( self::subscription_sources_transient_key( $id ) );

		$this->redirect( array() );
	}

	/**
	 * Handle the source-picker form's "Update feeds" submit
	 * (admin_post_daymark_subscription_update_feeds — issue #363; renamed
	 * and reworked again from the additive-only "Add selected feeds" issue
	 * #334 introduced, itself a rework of the original single-choice
	 * "Switch"/switch_source() issue #307 shipped): reads the checked
	 * `daymark_candidate_index[]` values back out of this subscription's own
	 * stashed discover_candidates() result (never a raw posted URL, the same
	 * "trust only what discover_candidates() itself just produced" posture
	 * subscribe_to_candidate()'s own docblock establishes) and reconciles
	 * them against what's actually subscribed right now — see
	 * reconcile_selected_candidates().
	 *
	 * Rate limited the same as discovering itself: reconciling can perform
	 * real outbound requests (per-candidate favicon lookups, the best-effort
	 * immediate poll for a newly-added feed).
	 *
	 * @return void
	 */
	public function handle_subscription_update_feeds(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'daymark' ), 403 );
		}

		$id = isset( $_POST['daymark_subscription_id'] ) ? absint( wp_unslash( $_POST['daymark_subscription_id'] ) ) : 0;

		check_admin_referer( 'daymark_subscription_update_feeds_' . $id, 'daymark_subscription_update_feeds_nonce' );

		$transient_key = self::subscription_sources_transient_key( $id );
		$stashed       = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( ! is_array( $stashed ) || ! isset( $stashed['candidates'] ) || ! is_array( $stashed['candidates'] ) ) {
			$this->redirect_with_error( __( 'That list of feeds has expired — check again.', 'daymark' ) );

			return;
		}

		$subscription = Daymark_Plugin::instance()->subscriptions->get( $id );

		if ( null === $subscription ) {
			$this->redirect_with_error( __( 'That subscription no longer exists.', 'daymark' ) );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer().
		$indices = isset( $_POST['daymark_candidate_index'] ) && is_array( $_POST['daymark_candidate_index'] )
			? array_map( 'absint', wp_unslash( $_POST['daymark_candidate_index'] ) )
			: array();

		$rate = Daymark_Plugin::instance()->rate_limiter->attempt( Daymark_Rate_Limiter::ACTION_SUBSCRIBE );

		if ( is_wp_error( $rate ) ) {
			$this->redirect_with_error( $rate->get_error_message() );

			return;
		}

		$site_url = (string) ( $subscription['site_url'] ?? '' );
		$outcome  = $this->reconcile_selected_candidates( $site_url, $stashed['candidates'], $indices );

		$this->redirect(
			array(
				self::NOTICE_QUERY_VAR  => $this->resolve_update_feeds_notice( $outcome ),
				self::COUNT_QUERY_VAR   => (string) $outcome['added'],
				self::REMOVED_QUERY_VAR => (string) $outcome['removed'],
			)
		);
	}

	/**
	 * Reconcile a subscription's own "Choose from available feeds" picker
	 * submission (issue #363) against what's actually subscribed right now:
	 * a checked candidate not yet subscribed to gets subscribed (with the
	 * same best-effort immediate poll subscribe_to_selected_candidates()
	 * already gives a new pick); an unchecked candidate that *is* currently
	 * subscribed gets unsubscribed — which, via
	 * Daymark_Subscriptions::unsubscribe(), immediately trashes every
	 * `daymark_subscription_post` it already ingested, exactly the "old
	 * content goes away" half of a feed switch. Everything else (checked and
	 * already subscribed, or unchecked and never subscribed) is a no-op.
	 *
	 * Re-resolves "is this candidate currently subscribed" via
	 * Daymark_Subscriptions::get_by_feed_url() at submit time rather than
	 * trusting the picker's own stashed "(current)"/"(already subscribed)"
	 * labels, which could be several minutes stale by the time this runs.
	 * Every candidate here was itself produced by a live discover_candidates()
	 * call against this exact site_url, so a feed_url match here can only
	 * ever be a subscription that actually follows this site — there is no
	 * way for this to reach into an unrelated site's own subscription.
	 *
	 * @param string                           $site_url   Site URL the candidates were discovered from.
	 * @param array<int, array<string, mixed>> $candidates The full stashed
	 *                                                       discover_candidates()
	 *                                                       result — every
	 *                                                       one is checked
	 *                                                       against
	 *                                                       `$indices`, not
	 *                                                       just the ones a
	 *                                                       person selected,
	 *                                                       since an
	 *                                                       already-subscribed
	 *                                                       candidate absent
	 *                                                       from `$indices`
	 *                                                       is exactly what
	 *                                                       signals a removal.
	 * @param int[]                            $indices    The candidate
	 *                                                      indices a person
	 *                                                      left checked.
	 * @return array{added: int, pending: int, removed: int} `added`/`pending`
	 *                                                        match
	 *                                                        subscribe_to_selected_candidates();
	 *                                                        `removed` is how
	 *                                                        many existing
	 *                                                        subscriptions
	 *                                                        were unsubscribed.
	 */
	private function reconcile_selected_candidates( string $site_url, array $candidates, array $indices ): array {
		$subscriptions = Daymark_Plugin::instance()->subscriptions;
		$poller        = Daymark_Plugin::instance()->subscription_poller;
		$added         = 0;
		$pending       = 0;
		$removed       = 0;

		foreach ( $candidates as $index => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$feed_url = isset( $candidate['url'] ) ? (string) $candidate['url'] : '';

			if ( '' === $feed_url ) {
				continue;
			}

			$existing = $subscriptions->get_by_feed_url( $feed_url );
			$checked  = in_array( $index, $indices, true );

			if ( $checked ) {
				if ( null !== $existing ) {
					continue; // Already subscribed and still checked — no-op.
				}

				$result = $subscriptions->subscribe_to_candidate( $site_url, $candidate );

				if ( is_wp_error( $result ) ) {
					continue;
				}

				++$added;

				// Best-effort immediate poll, matching subscribe_to_selected_candidates()'s
				// own "don't make them wait for the next scheduled check"
				// behavior — a failure here doesn't change the outcome, the
				// next scheduled poll keeps trying.
				if ( is_wp_error( $poller->manual_refresh( (int) $result ) ) ) {
					++$pending;
				}

				continue;
			}

			if ( null === $existing ) {
				continue; // Never subscribed and still unchecked — no-op.
			}

			$subscriptions->unsubscribe( (int) $existing['id'] );
			++$removed;
		}

		return array(
			'added'   => $added,
			'pending' => $pending,
			'removed' => $removed,
		);
	}

	/**
	 * Map a reconcile_selected_candidates() outcome to the right
	 * render_notice() key (issue #363) — a pure add keeps the exact
	 * pre-existing 'feeds_added'/'feeds_added_pending' copy/behavior
	 * unchanged; a pure remove, a mixed add-and-remove, and a genuine no-op
	 * each get their own distinct copy instead of overloading one message
	 * for all four shapes.
	 *
	 * @param array{added: int, pending: int, removed: int} $outcome reconcile_selected_candidates()'s result.
	 * @return string A render_notice()-recognized notice key.
	 */
	private function resolve_update_feeds_notice( array $outcome ): string {
		$added   = $outcome['added'];
		$pending = $outcome['pending'];
		$removed = $outcome['removed'];

		if ( 0 === $added && 0 === $removed ) {
			return 'feeds_unchanged';
		}

		if ( 0 === $removed ) {
			return $pending > 0 ? 'feeds_added_pending' : 'feeds_added';
		}

		if ( 0 === $added ) {
			return 'feeds_removed';
		}

		return $pending > 0 ? 'feeds_updated_pending' : 'feeds_updated';
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
