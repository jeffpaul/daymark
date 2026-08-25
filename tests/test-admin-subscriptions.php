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
}
