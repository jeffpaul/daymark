<?php
/**
 * Tests for Read to me (Daymark_Speech and the two speech REST routes).
 *
 * The AI provider is never called: the `daymark_speech_audio` filter
 * supplies the audio, and `daymark_text_to_speech_supported` stands in for
 * the provider's capability check.
 *
 * @package Daymark
 */

/**
 * Exercises the audio version of a post.
 */
class Test_Speech extends WP_UnitTestCase {

	/** @var int */
	private $author;

	/** @var int How many times the stubbed provider was asked for audio. */
	private $calls = 0;

	/** @var array|false Audio the stubbed provider returns. */
	private $audio = array(
		'data' => 'ID3-fake-mp3-bytes',
		'mime' => 'audio/mpeg',
	);

	public function set_up(): void {
		parent::set_up();

		$this->author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->calls  = 0;
		wp_set_current_user( $this->author );

		add_filter( 'daymark_text_to_speech_supported', '__return_true' );
		add_filter(
			'daymark_speech_audio',
			function () {
				++$this->calls;
				return $this->audio;
			}
		);
		update_option( Daymark_Settings::TEXT_TO_SPEECH, '1' );
	}

	public function tear_down(): void {
		$dir = Daymark_Speech::dir( false );

		if ( '' !== $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				wp_delete_file( $file );
			}
		}

		delete_option( Daymark_Settings::TEXT_TO_SPEECH );
		parent::tear_down();
	}

	private function speech_request( string $base, int $post_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/daymark/v1/{$base}/{$post_id}/speech" );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_get_server()->dispatch( $request );
	}

	private function post( array $args = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_author'  => $this->author,
					'post_status'  => 'publish',
					'post_title'   => 'Sunset walk',
					'post_content' => '<!-- wp:paragraph --><p>The sky turned orange &amp; gold.</p><!-- /wp:paragraph -->',
				),
				$args
			)
		);
	}

	private function file_for( string $url ): string {
		return Daymark_Speech::dir( false ) . '/' . rawurldecode( basename( $url ) );
	}

	public function test_off_by_default(): void {
		delete_option( Daymark_Settings::TEXT_TO_SPEECH );

		$this->assertFalse( Daymark_Settings::text_to_speech() );
		$this->assertFalse( Daymark_Speech::available() );
	}

	public function test_needs_a_provider_that_supports_speech(): void {
		remove_all_filters( 'daymark_text_to_speech_supported' );
		add_filter( 'daymark_text_to_speech_supported', '__return_false' );

		$this->assertFalse( Daymark_Speech::available() );
	}

	public function test_text_starts_with_the_title_then_the_body(): void {
		$text = Daymark_Speech::text_for_post( get_post( $this->post() ) );

		$this->assertSame( "Sunset walk.\n\nThe sky turned orange & gold.", $text['text'] );
		$this->assertFalse( $text['truncated'] );
	}

	public function test_title_is_not_repeated_when_the_body_starts_with_it(): void {
		$id   = $this->post(
			array(
				'post_title'   => 'Coffee with friends at the corner…',
				'post_content' => '<p>Coffee with friends at the corner shop this morning.</p>',
			)
		);
		$text = Daymark_Speech::text_for_post( get_post( $id ) );

		$this->assertSame( 'Coffee with friends at the corner shop this morning.', $text['text'] );
	}

	public function test_long_text_is_cut_at_a_sentence(): void {
		add_filter(
			'daymark_speech_max_chars',
			static function () {
				return 200;
			}
		);
		$body = str_repeat( 'This is one sentence of the post. ', 20 );
		$text = Daymark_Speech::text_for_post( get_post( $this->post( array( 'post_content' => "<p>{$body}</p>" ) ) ) );

		$this->assertTrue( $text['truncated'] );
		$this->assertLessThanOrEqual( 200, mb_strlen( $text['text'] ) );
		$this->assertStringEndsWith( 'post.', $text['text'] );
	}

	public function test_raw_pcm_gets_a_wav_header(): void {
		$audio = Daymark_Speech::playable( str_repeat( "\x00", 100 ), 'audio/L16;codec=pcm;rate=24000' );

		$this->assertSame( 'audio/wav', $audio['mime'] );
		$this->assertSame( 'wav', $audio['ext'] );
		$this->assertSame( 'RIFF', substr( $audio['data'], 0, 4 ) );
		$this->assertSame( 144, strlen( $audio['data'] ) );
		$this->assertSame( 24000, unpack( 'V', substr( $audio['data'], 24, 4 ) )[1] );
	}

	public function test_unknown_audio_type_is_refused(): void {
		$this->assertNull( Daymark_Speech::playable( 'x', 'text/html' ) );
		$this->assertSame( 'mp3', Daymark_Speech::playable( 'x', 'audio/mpeg' )['ext'] );
	}

	public function test_route_makes_audio_once_then_reuses_it(): void {
		$id = $this->post();

		$first = $this->speech_request( 'marks', $id );
		$this->assertSame( 200, $first->get_status() );
		$this->assertFalse( $first->get_data()['cached'] );
		$this->assertStringContainsString( '/daymark-speech/' . $id . '-', $first->get_data()['url'] );
		$this->assertFileExists( $this->file_for( $first->get_data()['url'] ) );

		$second = $this->speech_request( 'marks', $id );
		$this->assertTrue( $second->get_data()['cached'] );
		$this->assertSame( $first->get_data()['url'], $second->get_data()['url'] );
		$this->assertSame( 1, $this->calls );
	}

	public function test_only_a_new_recording_is_rate_limited(): void {
		add_filter(
			'daymark_rate_limits',
			static function ( $limits ) {
				$limits[ Daymark_Rate_Limiter::ACTION_SPEECH ] = array(
					'limit'  => 1,
					'window' => 900,
				);
				return $limits;
			}
		);

		$id = $this->post();
		$this->assertSame( 200, $this->speech_request( 'marks', $id )->get_status() );
		$this->assertSame( 200, $this->speech_request( 'marks', $id )->get_status() );
		$this->assertSame( 429, $this->speech_request( 'marks', $this->post() )->get_status() );
	}

	public function test_changed_text_makes_a_new_recording_and_deletes_the_old(): void {
		$id    = $this->post();
		$first = $this->speech_request( 'marks', $id )->get_data()['url'];

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<p>A different body now.</p>',
			)
		);
		$second = $this->speech_request( 'marks', $id )->get_data()['url'];

		$this->assertNotSame( $first, $second );
		$this->assertFileDoesNotExist( $this->file_for( $first ) );
		$this->assertFileExists( $this->file_for( $second ) );
	}

	public function test_deleting_the_post_deletes_its_audio(): void {
		$id  = $this->post();
		$url = $this->speech_request( 'marks', $id )->get_data()['url'];

		wp_delete_post( $id, true );

		$this->assertFileDoesNotExist( $this->file_for( $url ) );
	}

	public function test_route_refuses_when_turned_off(): void {
		delete_option( Daymark_Settings::TEXT_TO_SPEECH );

		$response = $this->speech_request( 'marks', $this->post() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->calls );
	}

	public function test_password_protected_and_draft_posts_are_refused(): void {
		$this->assertSame( 404, $this->speech_request( 'marks', $this->post( array( 'post_password' => 'secret' ) ) )->get_status() );
		$this->assertSame( 404, $this->speech_request( 'marks', $this->post( array( 'post_status' => 'draft' ) ) )->get_status() );
		$this->assertSame( 0, $this->calls );
	}

	public function test_post_with_no_text_is_refused(): void {
		$id = $this->post(
			array(
				'post_title'   => '',
				'post_content' => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="" /></figure><!-- /wp:image -->',
			)
		);

		$this->assertSame( 422, $this->speech_request( 'marks', $id )->get_status() );
	}

	public function test_provider_failure_is_reported(): void {
		$this->audio = false;

		$response = $this->speech_request( 'marks', $this->post() );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'daymark_speech_failed', $response->get_data()['code'] );
	}

	public function test_followed_site_post_reads_its_cached_body(): void {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Their post',
			)
		);
		update_post_meta( $id, 'body_content', '<p>Words from a site I follow.</p>' );

		$this->assertSame( "Their post.\n\nWords from a site I follow.", Daymark_Speech::text_for_post( get_post( $id ) )['text'] );
		$this->assertSame( 200, $this->speech_request( 'subscription-posts', $id )->get_status() );
	}

	public function test_each_route_only_takes_its_own_post_type(): void {
		$this->assertSame( 404, $this->speech_request( 'subscription-posts', $this->post() )->get_status() );

		$sub = (int) self::factory()->post->create(
			array(
				'post_type'   => Daymark_Subscription_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 404, $this->speech_request( 'marks', $sub )->get_status() );
	}

	public function test_app_config_reports_availability(): void {
		$config = Daymark_Routes::build_app_config();
		$this->assertTrue( $config['speech']['available'] );

		delete_option( Daymark_Settings::TEXT_TO_SPEECH );
		$this->assertFalse( Daymark_Routes::build_app_config()['speech']['available'] );
	}
}
