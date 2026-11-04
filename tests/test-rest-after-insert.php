<?php
/**
 * Tests for the save-finished action Daymark's own Mark routes fire.
 *
 * @package Daymark
 */

/**
 * A Mark saved from the app fires core's `rest_after_insert_{post_type}`
 * once Daymark has finished writing it, as the block editor's own save
 * does. Plugins that share a post saved over the REST API wait for that
 * action (Share on Mastodon, Autopost for X), so without it they never
 * share a Mark published from the app. An autosave doesn't fire it, as
 * core's autosave route doesn't.
 */
class Test_Rest_After_Insert extends WP_UnitTestCase {

	/**
	 * One entry per `rest_after_insert_post` call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $calls = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->calls = array();
		add_action(
			'rest_after_insert_post',
			function ( $post, $request, $creating ) {
				$this->calls[] = array(
					'id'       => $post instanceof WP_Post ? $post->ID : null,
					'status'   => $post instanceof WP_Post ? $post->post_status : null,
					'creating' => $creating,
					'request'  => $request instanceof WP_REST_Request ? $request->get_route() : null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Save a Mark through one of Daymark's own routes.
	 *
	 * @param string               $route  REST route.
	 * @param array<string, mixed> $params Request parameters.
	 * @return WP_REST_Response
	 */
	private function save( string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/** A Mark published in one step fires the action once, for the published Mark, as a new post. */
	public function test_publishing_a_new_mark_fires_it_once() {
		$response = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'Straight to publish',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'id'       => (int) $response->get_data()['id'],
					'status'   => 'publish',
					'creating' => true,
					'request'  => '/daymark/v1/marks',
				),
			),
			$this->calls
		);
	}

	/** Publishing a saved draft, the app's usual path, fires it once as an update. */
	public function test_publishing_a_draft_fires_it_once_as_an_update() {
		$post_id = (int) ( new Daymark_Publisher() )->publish(
			array(
				'caption' => 'Draft first',
				'status'  => 'draft',
			)
		);

		$response = $this->save(
			"/daymark/v1/marks/{$post_id}",
			array(
				'caption' => 'Ready now.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'id'       => $post_id,
					'status'   => 'publish',
					'creating' => false,
					'request'  => "/daymark/v1/marks/{$post_id}",
				),
			),
			$this->calls
		);
	}

	/** Save as Draft is a real save too, so it fires; each plugin checks the status itself, as it does for the block editor. */
	public function test_saving_a_draft_fires_it() {
		$response = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => 'For later',
				'status'  => 'draft',
			)
		);

		$this->assertSame( array( 'draft' ), array_column( $this->calls, 'status' ) );
		$this->assertSame( (int) $response->get_data()['id'], $this->calls[0]['id'] );
	}

	/** The composer's autosave, of a new Mark or a saved one, never fires it. */
	public function test_autosaves_do_not_fire_it() {
		$response = $this->save(
			'/daymark/v1/marks',
			array(
				'caption'  => 'Typing',
				'status'   => 'draft',
				'autosave' => '1',
			)
		);
		$post_id  = (int) $response->get_data()['id'];

		$this->save(
			"/daymark/v1/marks/{$post_id}",
			array(
				'caption'  => 'Still typing',
				'status'   => 'draft',
				'autosave' => '1',
			)
		);

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'Still typing', get_post_meta( $post_id, '_daymark_caption', true ) );
		$this->assertSame( array(), $this->calls );
	}

	/** A save that fails fires nothing. */
	public function test_a_failed_save_does_not_fire_it() {
		$response = $this->save(
			'/daymark/v1/marks',
			array(
				'caption' => '',
				'status'  => 'publish',
			)
		);

		$this->assertTrue( $response->is_error() );
		$this->assertSame( array(), $this->calls );
	}
}
