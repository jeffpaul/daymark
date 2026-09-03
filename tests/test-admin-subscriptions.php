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
}
