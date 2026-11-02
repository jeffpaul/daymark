<?php
/**
 * Daymark_Note_Length tests: the block editor's Note length counter loads
 * where post formats exist, with its limit and Bluesky context, and shares its
 * counting script and settings with the app's composer.
 *
 * @package Daymark
 */

/**
 * Daymark_Note_Length coverage.
 */
class Test_Note_Length extends WP_UnitTestCase {

	public function tear_down(): void {
		wp_dequeue_script( Daymark_Note_Length::HANDLE );
		wp_deregister_script( Daymark_Note_Length::HANDLE );
		wp_deregister_script( Daymark_Note_Length::SHARED_HANDLE );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Run the block editor enqueue for a screen.
	 *
	 * @param string $post_type Post type of the edit screen.
	 * @return void
	 */
	private function enqueue_for( string $post_type ): void {
		set_current_screen( 'post' );
		get_current_screen()->post_type = $post_type;

		( new Daymark_Note_Length() )->enqueue_editor_assets();
	}

	/** The counter loads when editing a post, a post type with formats. */
	public function test_enqueued_for_posts(): void {
		$this->enqueue_for( 'post' );

		$this->assertTrue( wp_script_is( Daymark_Note_Length::HANDLE, 'enqueued' ) );

		$data = (string) wp_scripts()->get_data( Daymark_Note_Length::HANDLE, 'data' );
		$this->assertStringContainsString( '"limit":"300"', $data );
		// Other tests define ATmosphere's functions, so only the key is fixed here.
		$this->assertMatchesRegularExpression( '/"atmosphere":"1?"/', $data );
	}

	/** The counter counts with the shared script, which the app's composer loads too. */
	public function test_counter_loads_the_shared_counting_script(): void {
		$this->enqueue_for( 'post' );

		$scripts = wp_scripts();
		$this->assertContains( Daymark_Note_Length::SHARED_HANDLE, $scripts->registered[ Daymark_Note_Length::HANDLE ]->deps );

		$shared = $scripts->registered[ Daymark_Note_Length::SHARED_HANDLE ];
		$this->assertStringEndsWith( 'assets/note-length.js', $shared->src );
		// No dependencies, so the offline shell can print it as one plain script.
		$this->assertSame( array(), $shared->deps );
		$this->assertFileExists( DAYMARK_PLUGIN_DIR . 'assets/note-length.js' );
	}

	/** The app gets the same limit and Bluesky setting as the block editor. */
	public function test_app_config_matches_the_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$config = Daymark_Note_Length::app_config();
		$this->assertSame( 300, $config['limit'] );
		$this->assertIsBool( $config['atmosphere'] );
		$this->assertSame( $config, Daymark_Routes::build_app_config( 'home' )['noteLength'] );

		$set = static function () {
			return 500;
		};
		add_filter( 'daymark_note_short_form_limit', $set );
		$this->assertSame( 500, Daymark_Note_Length::app_config()['limit'] );
		remove_filter( 'daymark_note_short_form_limit', $set );
	}

	/** It doesn't load for a post type without post formats. */
	public function test_not_enqueued_for_pages(): void {
		$this->enqueue_for( 'page' );

		$this->assertFalse( wp_script_is( Daymark_Note_Length::HANDLE, 'enqueued' ) );
	}

	/** The limit can be changed with a filter, and is never below 1. */
	public function test_limit_filter(): void {
		$set = static function () {
			return 500;
		};
		add_filter( 'daymark_note_short_form_limit', $set );
		$this->assertSame( 500, Daymark_Note_Length::limit() );
		remove_filter( 'daymark_note_short_form_limit', $set );

		add_filter( 'daymark_note_short_form_limit', '__return_zero' );
		$this->assertSame( 1, Daymark_Note_Length::limit() );
		remove_filter( 'daymark_note_short_form_limit', '__return_zero' );
	}
}
