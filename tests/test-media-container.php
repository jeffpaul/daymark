<?php
/**
 * Tests for Daymark_Media_Container: reading an MP4 or WebM file's track
 * list to tell a sound recording from a video.
 *
 * Fixtures in tests/e2e/fixtures/:
 * - test-recording.mp4, test-recording.webm: Chromium's MediaRecorder with
 *   its fake microphone (Opus sound in a fragmented MP4, and in a WebM
 *   file the recorder finished with sizes and a seek index).
 * - test-recording-streamed.webm: the same recorder with a timeslice, so
 *   the file streams out with unknown sizes and no seek index.
 * - test-audio.m4a, test-video.mp4, test-video.webm: made with ffmpeg
 *   (AAC sound in MP4; H.264 picture and AAC sound with the track list at
 *   the end of the file; VP8 picture alone).
 *
 * @package Daymark
 */

/**
 * Exercises Daymark_Media_Container directly.
 */
class Test_Media_Container extends WP_UnitTestCase {

	/**
	 * Paths of temporary files to remove after each test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	public function tear_down(): void {
		foreach ( $this->temp_files as $path ) {
			wp_delete_file( $path );
		}

		$this->temp_files = array();

		parent::tear_down();
	}

	/**
	 * Path to a fixture.
	 *
	 * @param string $name File name in tests/e2e/fixtures/.
	 * @return string
	 */
	private function fixture( string $name ): string {
		return __DIR__ . '/e2e/fixtures/' . $name;
	}

	/**
	 * A temporary file holding these bytes.
	 *
	 * @param string $bytes Contents.
	 * @return string
	 */
	private function temp_file( string $bytes ): string {
		$path = wp_tempnam( 'daymark-container-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $path, $bytes );
		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * One MP4 box.
	 *
	 * @param string $type    Four-character type.
	 * @param string $payload Payload.
	 * @return string
	 */
	private function box( string $type, string $payload ): string {
		return pack( 'N', 8 + strlen( $payload ) ) . $type . $payload;
	}

	/**
	 * An MP4 track box with this handler type ('soun', 'vide', 'text').
	 *
	 * @param string $handler Handler type.
	 * @return string
	 */
	private function trak( string $handler ): string {
		// Version and flags, then pre_defined, then the handler type and a name.
		$hdlr = $this->box( 'hdlr', str_repeat( "\0", 8 ) . $handler . str_repeat( "\0", 13 ) );

		return $this->box( 'trak', $this->box( 'tkhd', str_repeat( "\0", 84 ) ) . $this->box( 'mdia', $this->box( 'mdhd', str_repeat( "\0", 24 ) ) . $hdlr ) );
	}

	/**
	 * Fixture, container, and the track kinds it holds.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}>
	 */
	public function data_fixtures(): array {
		return array(
			'Chromium MP4 recording'       => array( 'test-recording.mp4', 'video/mp4', array( 'audio' ) ),
			'Chromium WebM recording'      => array( 'test-recording.webm', 'video/webm', array( 'audio' ) ),
			'streamed WebM recording'      => array( 'test-recording-streamed.webm', 'video/webm', array( 'audio' ) ),
			'AAC in MP4 (M4A)'             => array( 'test-audio.m4a', 'video/mp4', array( 'audio' ) ),
			'MP4 video with sound'         => array( 'test-video.mp4', 'video/mp4', array( 'video', 'audio' ) ),
			'WebM video without any sound' => array( 'test-video.webm', 'video/webm', array( 'video' ) ),
		);
	}

	/**
	 * @dataProvider data_fixtures
	 *
	 * @param string   $name      Fixture.
	 * @param string   $container Container.
	 * @param string[] $kinds     Expected track kinds.
	 */
	public function test_reads_each_track_kind( string $name, string $container, array $kinds ): void {
		$this->assertSame( $kinds, Daymark_Media_Container::track_kinds( $this->fixture( $name ), $container ) );
		$this->assertSame( array( 'audio' ) === $kinds, Daymark_Media_Container::is_audio_only( $this->fixture( $name ), $container ) );
	}

	/**
	 * Cutting a file short (the first part of an upload) gives either the
	 * whole file's answer or no answer, never a different one.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string   $name      Fixture.
	 * @param string   $container Container.
	 * @param string[] $kinds     Expected track kinds.
	 */
	public function test_a_file_cut_short_never_reads_differently( string $name, string $container, array $kinds ): void {
		$bytes = (string) file_get_contents( $this->fixture( $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$total = strlen( $bytes );
		$path  = $this->temp_file( '' );

		for ( $length = 0; $length < $total; $length += 5 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
			file_put_contents( $path, substr( $bytes, 0, $length ) );
			clearstatcache( true, $path );

			$result = Daymark_Media_Container::track_kinds( $path, $container );

			$this->assertTrue( null === $result || $kinds === $result, "Cut to $length bytes." );
		}
	}

	/** A recording's track list comes first, so its first part is enough. */
	public function test_the_start_of_a_recording_is_enough(): void {
		$recordings = array(
			'test-recording.mp4'           => 'video/mp4',
			'test-recording-streamed.webm' => 'video/webm',
		);

		foreach ( $recordings as $name => $container ) {
			$bytes = (string) file_get_contents( $this->fixture( $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
			$half  = $this->temp_file( substr( $bytes, 0, (int) ( strlen( $bytes ) * 0.6 ) ) );

			$this->assertSame( array( 'audio' ), Daymark_Media_Container::track_kinds( $half, $container ), $name );
		}
	}

	/** A video whose track list is at the end can't be read from its start. */
	public function test_a_video_with_its_track_list_at_the_end_needs_the_whole_file(): void {
		$bytes = (string) file_get_contents( $this->fixture( 'test-video.mp4' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertNull( Daymark_Media_Container::track_kinds( $this->temp_file( substr( $bytes, 0, 2000 ) ), 'video/mp4' ) );
		$this->assertFalse( Daymark_Media_Container::is_audio_only( $this->temp_file( substr( $bytes, 0, 2000 ) ), 'video/mp4' ) );
	}

	/** Only MP4 and WebM are read, and a file of the other kind reads as nothing. */
	public function test_only_mp4_and_webm_are_read(): void {
		$this->assertNull( Daymark_Media_Container::track_kinds( $this->fixture( 'test-audio.ogg' ), 'audio/ogg' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( $this->fixture( 'test-recording.webm' ), 'video/mp4' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( $this->fixture( 'test-recording.mp4' ), 'video/webm' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( '/no/such/file.mp4', 'video/mp4' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( $this->temp_file( 'tiny' ), 'video/mp4' ) );
	}

	/** A stream wrapper path is never opened, even one PHP could read. */
	public function test_a_stream_path_is_refused(): void {
		$this->assertSame( array( 'audio' ), Daymark_Media_Container::track_kinds( $this->fixture( 'test-recording.mp4' ), 'video/mp4' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( 'file://' . $this->fixture( 'test-recording.mp4' ), 'video/mp4' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( 'https://example.com/recording.mp4', 'video/mp4' ) );
	}

	/** Random bytes read as nothing. */
	public function test_noise_reads_as_nothing(): void {
		$noise = $this->temp_file( random_bytes( 4096 ) );

		$this->assertFalse( Daymark_Media_Container::is_audio_only( $noise, 'video/mp4' ) );
		$this->assertFalse( Daymark_Media_Container::is_audio_only( $noise, 'video/webm' ) );
	}

	/** A box with a 64-bit size, and one that runs to the end of the file, are read. */
	public function test_mp4_large_and_open_ended_boxes(): void {
		$tracks = $this->trak( 'soun' );
		$large  = pack( 'N', 1 ) . 'moov' . pack( 'J', 16 + strlen( $tracks ) ) . $tracks;

		$this->assertSame( array( 'audio' ), Daymark_Media_Container::track_kinds( $this->temp_file( $this->box( 'ftyp', 'isom' ) . $large ), 'video/mp4' ) );

		$open = pack( 'N', 0 ) . 'moov' . $this->trak( 'soun' ) . $this->trak( 'vide' );

		$this->assertSame( array( 'audio', 'video' ), Daymark_Media_Container::track_kinds( $this->temp_file( $this->box( 'ftyp', 'isom' ) . $open ), 'video/mp4' ) );
	}

	/** A track that is neither sound nor picture (subtitles) doesn't make a file video. */
	public function test_mp4_other_tracks(): void {
		$path = $this->temp_file( $this->box( 'ftyp', 'isom' ) . $this->box( 'moov', $this->trak( 'soun' ) . $this->trak( 'text' ) ) );

		$this->assertSame( array( 'audio', 'other' ), Daymark_Media_Container::track_kinds( $path, 'video/mp4' ) );
		$this->assertTrue( Daymark_Media_Container::is_audio_only( $path, 'video/mp4' ) );
	}

	/** Broken box sizes read as nothing. */
	public function test_mp4_broken_sizes(): void {
		$too_small = pack( 'N', 4 ) . 'moov' . $this->trak( 'soun' );
		$too_big   = pack( 'N', 9999 ) . 'moov' . $this->trak( 'soun' );

		$this->assertNull( Daymark_Media_Container::track_kinds( $this->temp_file( $this->box( 'ftyp', 'isom' ) . $too_small ), 'video/mp4' ) );
		$this->assertNull( Daymark_Media_Container::track_kinds( $this->temp_file( $this->box( 'ftyp', 'isom' ) . $too_big ), 'video/mp4' ) );
	}

	/** A file made of endless empty boxes is given up on. */
	public function test_mp4_gives_up_after_too_many_boxes(): void {
		$path = $this->temp_file( str_repeat( $this->box( 'free', '' ), 12000 ) . $this->box( 'moov', $this->trak( 'soun' ) ) );

		$this->assertNull( Daymark_Media_Container::track_kinds( $path, 'video/mp4' ) );
	}

	/** A WebM file whose media starts before its track list reads as nothing. */
	public function test_webm_cluster_before_tracks(): void {
		$bytes  = (string) file_get_contents( $this->fixture( 'test-recording-streamed.webm' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$tracks = strpos( $bytes, "\x16\x54\xAE\x6B" );

		$this->assertIsInt( $tracks );

		// An empty Cluster (known size 0) just before the Tracks element.
		$moved = substr( $bytes, 0, $tracks ) . "\x1F\x43\xB6\x75\x80" . substr( $bytes, $tracks );

		$this->assertNull( Daymark_Media_Container::track_kinds( $this->temp_file( $moved ), 'video/webm' ) );
	}
}
