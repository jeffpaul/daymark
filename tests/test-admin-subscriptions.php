<?php
/**
 * Daymark_Admin_Subscriptions tests (issue #78): the Settings -> Daymark
 * screen's rendering, scoped to what's safe to exercise directly.
 *
 * The three admin_post handlers (handle_subscribe/handle_refresh/
 * handle_unsubscribe) all end in redirect() -> exit, so they are not
 * called here; this file covers render_page()'s output instead, which is
 * where the Refresh-availability and Last-fetched-column behavior live.
 *
 * @package Daymark
 */

/**
 * Render-output coverage for the Refresh action and the Last fetched
 * column added to the subscriptions table.
 *
 * The admin_post handlers themselves (handle_subscribe/handle_refresh/
 * handle_unsubscribe, and — new here — handle_refresh_icon/handle_export/
 * handle_import) all end in wp_die()/wp_safe_redirect()+exit, which PHPUnit
 * has no safe way to intercept without special scaffolding this codebase
 * doesn't otherwise rely on — see this class's own pre-existing docblock
 * note above test_refresh_button_shown_for_active_subscription() and
 * class-share-target.php's matching note for Daymark_Share_Target::handle().
 * Daymark_Subscriptions::refresh_icon() and Daymark_Subscription_OPML's own
 * export()/import() logic (the substantive, testable behavior each new
 * handler here only thinly wraps) are covered directly in
 * tests/test-subscriptions.php and tests/test-subscription-opml.php
 * instead; this file covers what each new form actually renders.
 */
class Test_Admin_Subscriptions extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var Daymark_Admin_Subscriptions */
	private $admin_subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriptions       = new Daymark_Subscriptions();
		$this->admin_subscriptions = new Daymark_Admin_Subscriptions();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		// wp_scripts() is a persistent global PHPUnit does not reset between
		// tests, so an earlier test's enqueue_assets() call would otherwise
		// leave this script enqueued for every test that follows it in the
		// same process — the same reason tests/test-subscription-opml.php's
		// set_up() resets Daymark_Subscription_Html_Cache (issue #137).
		wp_dequeue_script( 'daymark-admin-subscriptions' );
		wp_deregister_script( 'daymark-admin-subscriptions' );
	}

	/**
	 * Renders the page and returns its output as a string.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->admin_subscriptions->render_page();

		return (string) ob_get_clean();
	}

	public function test_refresh_button_shown_for_active_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.com',
				'feed_url' => 'https://example.com/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_refresh', $output );
		$this->assertStringContainsString( 'Refresh', $output );
	}

	public function test_refresh_button_shown_for_error_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.org',
				'feed_url' => 'https://example.org/feed',
				'status'   => 'error',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_refresh', $output );
	}

	public function test_icon_column_renders_image_when_site_icon_url_set(): void {
		$this->subscriptions->create(
			array(
				'site_url'      => 'https://example.com',
				'feed_url'      => 'https://example.com/feed',
				'site_icon_url' => 'https://example.com/favicon.ico',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'Icon', $output );
		$this->assertStringContainsString( '<img src="https://example.com/favicon.ico"', $output );
	}

	public function test_icon_column_renders_nothing_when_site_icon_url_empty(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.org',
				'feed_url' => 'https://example.org/feed',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'Icon', $output );
		$this->assertStringNotContainsString( '<img', $output );
	}

	public function test_last_fetched_column_shows_never_when_unchecked(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.net',
				'feed_url' => 'https://example.net/feed',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'Last fetched', $output );
		$this->assertStringContainsString( 'Never', $output );
	}

	/**
	 * Scenario (issue #81): the Status column shows an error-flagged
	 * subscription's `last_error` text — a small, natural addition alongside
	 * the existing "Error" label, not a new UI element.
	 */
	public function test_status_column_shows_last_error_for_error_subscription(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.biz',
				'feed_url' => 'https://example.biz/feed',
				'status'   => 'error',
			)
		);

		$this->subscriptions->update( $id, array( 'last_error' => 'The response exceeded the maximum allowed size.' ) );

		$output = $this->render();

		$this->assertStringContainsString( 'The response exceeded the maximum allowed size.', $output );
	}

	/** An active subscription's row never shows a `last_error`, even if one is on file from a past failure. */
	public function test_status_column_hides_last_error_for_active_subscription(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.cc',
				'feed_url' => 'https://example.cc/feed',
				'status'   => 'active',
			)
		);

		$this->subscriptions->update( $id, array( 'last_error' => 'A stale error from before this recovered.' ) );

		$output = $this->render();

		$this->assertStringNotContainsString( 'A stale error from before this recovered.', $output );
	}

	public function test_last_fetched_column_shows_relative_time_once_checked(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$this->subscriptions->update( $id, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) ) );

		$output = $this->render();

		$this->assertStringContainsString( 'ago', $output );
		$this->assertStringNotContainsString( '>Never<', $output );
	}

	/** Scenario (issue #94): every subscription row shows a "Refresh icon" action. */
	public function test_refresh_icon_button_shown_for_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://icon-example.com',
				'feed_url' => 'https://icon-example.com/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_refresh_icon', $output );
		$this->assertStringContainsString( 'Refresh icon', $output );
	}

	/** Scenario (issue #80): the settings page renders an OPML Export link. */
	public function test_export_link_rendered(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscriptions_export', $output );
		$this->assertStringContainsString( 'Export subscriptions (OPML)', $output );
	}

	/** Scenario (issue #80): the settings page renders an OPML Import form. */
	public function test_import_form_rendered(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscriptions_import', $output );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $output );
		$this->assertStringContainsString( 'daymark_opml_file', $output );
	}

	/**
	 * Scenario (issue #80): after a successful import, render_page() reads
	 * and displays the per-entry results transient handle_import() would
	 * have written, then clears it so a page refresh doesn't repeat it.
	 */
	public function test_opml_import_results_rendered_and_consumed(): void {
		$user_id = get_current_user_id();

		set_transient(
			'daymark_opml_import_result_' . $user_id,
			array(
				array(
					'label'   => 'A Subscribed Site',
					'status'  => 'subscribed',
					'message' => '',
				),
				array(
					'label'   => 'A Duplicate Site',
					'status'  => 'duplicate',
					'message' => 'A subscription for this feed already exists.',
				),
				array(
					'label'   => 'A Bad Entry <script>',
					'status'  => 'failed',
					'message' => 'This entry\'s feed URL is not valid.',
				),
			),
			MINUTE_IN_SECONDS
		);

		$_GET['daymark_notice'] = 'opml_imported';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'] );

		$this->assertStringContainsString( 'Import complete: 1 subscribed, 1 already subscribed, 1 failed.', $output );
		$this->assertStringContainsString( 'A Subscribed Site', $output );
		$this->assertStringContainsString( 'A Duplicate Site', $output );
		$this->assertStringContainsString( 'A subscription for this feed already exists.', $output );
		// Untrusted OPML-sourced labels are escaped, not rendered raw.
		$this->assertStringContainsString( 'A Bad Entry &lt;script&gt;', $output );
		$this->assertStringNotContainsString( '<script>', $output );

		// Consumed: rendering again with no notice query var shows nothing left over.
		$this->assertFalse( get_transient( 'daymark_opml_import_result_' . $user_id ) );
	}

	/**
	 * Scenario (issue #175): the Refresh form and its row carry the data
	 * attributes assets/admin-subscriptions.js needs to submit inline via
	 * the REST refresh endpoint and update that row in place.
	 */
	public function test_refresh_form_and_row_carry_js_enhancement_hooks(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://inline-refresh.example',
				'feed_url' => 'https://inline-refresh.example/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'data-daymark-subscription-row="' . $id . '"', $output );
		$this->assertStringContainsString( 'daymark-subscription-refresh-form', $output );
		$this->assertStringContainsString( 'data-daymark-subscription-id="' . $id . '"', $output );
		$this->assertStringContainsString( 'daymark-subscription-status-text', $output );
		$this->assertStringContainsString( 'daymark-subscription-last-fetched', $output );
	}

	/**
	 * Scenario (issue #175): enqueue_assets() localizes the REST refresh
	 * endpoint + a wp_rest nonce for assets/admin-subscriptions.js, but only
	 * on this screen.
	 */
	public function test_enqueue_assets_localizes_rest_config_on_settings_screen(): void {
		$this->admin_subscriptions->enqueue_assets( 'settings_page_' . Daymark_Admin_Subscriptions::PAGE_SLUG );

		$this->assertTrue( wp_script_is( 'daymark-admin-subscriptions', 'enqueued' ) );

		$localized = wp_scripts()->get_data( 'daymark-admin-subscriptions', 'data' );
		$this->assertIsString( $localized );

		$this->assertSame( 1, preg_match( '/daymarkAdminSubscriptions\s*=\s*(\{.*\});/s', $localized, $matches ) );
		$config = json_decode( $matches[1], true );

		$this->assertIsArray( $config );
		$this->assertSame( rest_url( 'daymark/v1/subscriptions/' ), $config['restUrl'] );
		$this->assertNotEmpty( $config['restNonce'] );
		$this->assertSame( 'Refresh', $config['i18n']['refreshLabel'] );
	}

	/** enqueue_assets() does nothing on any other admin screen. */
	public function test_enqueue_assets_does_nothing_off_screen(): void {
		$this->admin_subscriptions->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'daymark-admin-subscriptions', 'enqueued' ) );
	}

	// -----------------------------------------------------------------
	// Sortable columns (issue #178).
	// -----------------------------------------------------------------

	/**
	 * Create three subscriptions whose site_title values are deliberately
	 * out of alphabetical order, so a test can assert render()'s output
	 * puts them back in (or out of) order via relative strpos(). Each gets
	 * an explicit, distinct created_at (oldest: Charlie, then Alpha, then
	 * Bravo newest) set directly via $wpdb — create() always stamps
	 * created_at with "now", and three creations in one test method can
	 * easily land in the same second, which would make the *default*
	 * (created_at DESC) order's tie-break behavior undefined rather than
	 * reliably reverse-insertion; the tests here need it deterministic.
	 *
	 * @return void
	 */
	private function create_three_out_of_order_subscriptions(): void {
		global $wpdb;

		$charlie_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://charlie.example',
				'feed_url'   => 'https://charlie.example/feed',
				'site_title' => 'Charlie Site',
			)
		);
		$alpha_id   = $this->subscriptions->create(
			array(
				'site_url'   => 'https://alpha.example',
				'feed_url'   => 'https://alpha.example/feed',
				'site_title' => 'Alpha Site',
			)
		);
		$bravo_id   = $this->subscriptions->create(
			array(
				'site_url'   => 'https://bravo.example',
				'feed_url'   => 'https://bravo.example/feed',
				'site_title' => 'Bravo Site',
			)
		);

		$table = Daymark_Subscriptions::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only: forcing a deterministic created_at that Daymark_Subscriptions::update() doesn't expose (matches tests/test-subscriptions.php's own precedent for direct $wpdb use in test setup).
		$wpdb->update( $table, array( 'created_at' => '2026-01-01 00:00:01' ), array( 'id' => $charlie_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$wpdb->update( $table, array( 'created_at' => '2026-01-01 00:00:02' ), array( 'id' => $alpha_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$wpdb->update( $table, array( 'created_at' => '2026-01-01 00:00:03' ), array( 'id' => $bravo_id ) );
	}

	/** Sorting the Site column ascending orders rows by site_title A-Z. */
	public function test_site_column_sorts_ascending(): void {
		$this->create_three_out_of_order_subscriptions();

		$_GET['orderby'] = 'site';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$alpha   = strpos( $output, 'Alpha Site' );
		$bravo   = strpos( $output, 'Bravo Site' );
		$charlie = strpos( $output, 'Charlie Site' );

		$this->assertLessThan( $bravo, $alpha );
		$this->assertLessThan( $charlie, $bravo );
	}

	/** Sorting the Site column descending orders rows by site_title Z-A. */
	public function test_site_column_sorts_descending(): void {
		$this->create_three_out_of_order_subscriptions();

		$_GET['orderby'] = 'site';
		$_GET['order']   = 'desc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$alpha   = strpos( $output, 'Alpha Site' );
		$bravo   = strpos( $output, 'Bravo Site' );
		$charlie = strpos( $output, 'Charlie Site' );

		$this->assertLessThan( $bravo, $charlie );
		$this->assertLessThan( $alpha, $bravo );
	}

	/** Sorting the Status column ascending puts 'active' rows before 'error' rows. */
	public function test_status_column_sorts_ascending(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://error-site.example',
				'feed_url'   => 'https://error-site.example/feed',
				'site_title' => 'Error Site',
				'status'     => 'error',
			)
		);
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://active-site.example',
				'feed_url'   => 'https://active-site.example/feed',
				'site_title' => 'Active Site',
				'status'     => 'active',
			)
		);

		$_GET['orderby'] = 'status';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertLessThan( strpos( $output, 'Error Site' ), strpos( $output, 'Active Site' ) );
	}

	/** Sorting the Last fetched column ascending puts the least-recently-checked row first. */
	public function test_last_checked_column_sorts_ascending(): void {
		$older_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://older.example',
				'feed_url'   => 'https://older.example/feed',
				'site_title' => 'Older Check',
			)
		);
		$this->subscriptions->update( $older_id, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );

		$newer_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://newer.example',
				'feed_url'   => 'https://newer.example/feed',
				'site_title' => 'Newer Check',
			)
		);
		$this->subscriptions->update( $newer_id, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ) );

		$_GET['orderby'] = 'last_checked';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertLessThan( strpos( $output, 'Newer Check' ), strpos( $output, 'Older Check' ) );
	}

	/** With no orderby requested, the table keeps its existing default order (created_at DESC) — sorting is opt-in only. */
	public function test_no_orderby_keeps_default_order(): void {
		$this->create_three_out_of_order_subscriptions();

		$output = $this->render();

		// Default order is most-recently-created first; the third one
		// created ("Bravo Site") should lead, not the alphabetically-first one.
		$this->assertLessThan( strpos( $output, 'Alpha Site' ), strpos( $output, 'Bravo Site' ) );
	}

	/** An unrecognized orderby value is ignored, falling back to the default order rather than erroring. */
	public function test_invalid_orderby_falls_back_to_default_order(): void {
		$this->create_three_out_of_order_subscriptions();

		$_GET['orderby'] = 'not-a-real-column';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertLessThan( strpos( $output, 'Alpha Site' ), strpos( $output, 'Bravo Site' ) );
	}

	/** Column header links carry the query args that flip the active column's direction, and mark it via aria-sort. */
	public function test_sortable_header_reflects_active_column_and_toggles_direction(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$_GET['orderby'] = 'site';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		// The active (Site) header points at the opposite direction and is marked ascending.
		// The exact entity esc_url() uses to separate query args (&#038;, &amp;, or a
		// bare &) isn't the point of this assertion, so the regex accepts any of them.
		$this->assertStringContainsString( 'aria-sort="ascending"', $output );
		$this->assertMatchesRegularExpression( '/orderby=site(&amp;|&#038;|&)order=desc/', $output );

		// An inactive sortable header (Status) is marked unsorted and defaults to ascending.
		$this->assertStringContainsString( 'aria-sort="none"', $output );
		$this->assertMatchesRegularExpression( '/orderby=status(&amp;|&#038;|&)order=asc/', $output );
	}

	// -----------------------------------------------------------------
	// Editable site name (issue #180).
	// -----------------------------------------------------------------

	/** The "Edit name" form carries the subscription's current site_title, ready to submit back unchanged or edited. */
	public function test_edit_title_form_renders_with_current_site_title(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://friend-site.example',
				'feed_url'   => 'https://friend-site.example/feed',
				'site_title' => 'cryptic-friend-handle',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_edit_title', $output );
		$this->assertStringContainsString( 'name="daymark_site_title" value="cryptic-friend-handle"', $output );
		$this->assertStringContainsString( 'Edit name', $output );
	}

	/** With no site_title set, the edit form's input is empty but hints at the site URL via its placeholder. */
	public function test_edit_title_form_placeholder_falls_back_to_site_url_when_title_empty(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://untitled.example',
				'feed_url' => 'https://untitled.example/feed',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'placeholder="https://untitled.example"', $output );
	}
}
