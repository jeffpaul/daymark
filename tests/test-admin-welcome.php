<?php
/**
 * The welcome notice shown once after Daymark is first activated.
 *
 * @package Daymark
 */

/**
 * Daymark_Admin_Welcome coverage.
 */
class Test_Admin_Welcome extends WP_UnitTestCase {

	/** @var Daymark_Admin_Welcome */
	private $welcome;

	public function set_up(): void {
		parent::set_up();

		$this->welcome = new Daymark_Admin_Welcome();
		delete_option( Daymark_Admin_Welcome::OPTION );
	}

	public function tear_down(): void {
		delete_option( Daymark_Admin_Welcome::OPTION );

		parent::tear_down();
	}

	/**
	 * Render the notice as the current user.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->welcome->render_notice();

		return (string) ob_get_clean();
	}

	/** Only a site's first-ever activation queues the notice. */
	public function test_queue_only_on_first_activation(): void {
		Daymark_Admin_Welcome::queue( false );
		$this->assertFalse( Daymark_Admin_Welcome::is_pending() );

		Daymark_Admin_Welcome::queue( true );
		$this->assertTrue( Daymark_Admin_Welcome::is_pending() );
	}

	/** Activating on a site Daymark was already activated on doesn't bring the notice back. */
	public function test_reactivation_does_not_queue_notice(): void {
		update_option( 'daymark_activated', time() - DAY_IN_SECONDS );

		Daymark_Plugin::activate();

		$this->assertFalse( Daymark_Admin_Welcome::is_pending() );
	}

	/** The first activation queues it. */
	public function test_first_activation_queues_notice(): void {
		delete_option( 'daymark_activated' );

		Daymark_Plugin::activate();

		$this->assertTrue( Daymark_Admin_Welcome::is_pending() );
	}

	/** An administrator sees the app address and links to Subscriptions and Connectors. */
	public function test_admin_sees_next_steps(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Daymark_Admin_Welcome::queue( true );

		$output = $this->render();

		$this->assertStringContainsString( 'Daymark is ready', $output );
		$this->assertStringContainsString( esc_url( Daymark_Routes::app_url() ), $output );
		$this->assertStringContainsString( esc_url( Daymark_Admin_Subscriptions::tab_url( 'subscriptions' ) ), $output );
		$this->assertStringContainsString( esc_url( Daymark_Admin_Subscriptions::tab_url( 'connectors' ) ), $output );
		$this->assertStringContainsString( 'daymark_dismiss_welcome', $output );
	}

	/** Someone who can't manage the site never sees it. */
	public function test_author_does_not_see_notice(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		Daymark_Admin_Welcome::queue( true );

		$this->assertSame( '', $this->render() );
	}

	/** Nothing shows when the notice isn't pending. */
	public function test_nothing_when_not_pending(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( '', $this->render() );
	}

	/** Dismissing forgets the notice for good. */
	public function test_dismiss_forgets_notice(): void {
		Daymark_Admin_Welcome::queue( true );

		Daymark_Admin_Welcome::dismiss();

		$this->assertFalse( Daymark_Admin_Welcome::is_pending() );
	}

	/** The dismiss link works through its own handler, with its nonce. */
	public function test_dismiss_link_handler(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Daymark_Admin_Welcome::queue( true );

		$_GET['daymark_dismiss_welcome'] = '1';
		$_REQUEST['_wpnonce']            = wp_create_nonce( 'daymark_dismiss_welcome' );
		$stop                            = static function ( $location ) {
			throw new RuntimeException( esc_url_raw( (string) $location ) );
		};
		add_filter( 'wp_redirect', $stop );

		try {
			$this->welcome->maybe_dismiss();
			$location = '';
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $stop );
			unset( $_GET['daymark_dismiss_welcome'], $_REQUEST['_wpnonce'] );
		}

		$this->assertFalse( Daymark_Admin_Welcome::is_pending() );
		$this->assertStringNotContainsString( 'daymark_dismiss_welcome', $location );
	}
}
