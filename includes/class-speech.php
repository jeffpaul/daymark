<?php
/**
 * Read to me: an audio version of a post, made by the AI provider.
 *
 * When the site owner turns on Read to me (Settings -> Daymark -> Data &
 * privacy) and the configured AI provider can turn text into speech, the
 * app's full post view shows a Listen button. The first tap sends the
 * post's text to the provider; Daymark saves the audio file it returns
 * and reuses it for every later listen until the post's text changes.
 *
 * Works for the site's own posts (Marks, ordinary posts, read-only notes)
 * and for cached posts from followed sites. Audio files live in
 * uploads/daymark-speech/, not the Media Library: they are a cache, made
 * and deleted by Daymark, and a followed site's post isn't yours to file
 * away.
 *
 * @package Daymark
 * @since   0.21.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes, caches, and cleans up a post's audio version.
 */
final class Daymark_Speech {

	/**
	 * Post meta holding the cached audio: JSON {file, hash, mime, truncated, created}.
	 */
	public const META_KEY = '_daymark_speech';

	/**
	 * Folder under the uploads directory that holds the audio files.
	 */
	public const DIR_NAME = 'daymark-speech';

	/**
	 * Default cap on how much text is read aloud, in characters. Most
	 * text-to-speech APIs take around 4,000 characters per request.
	 */
	public const DEFAULT_MAX_CHARS = 4000;

	/**
	 * Audio MIME types Daymark saves, mapped to a file extension. Raw PCM
	 * (audio/L16, audio/pcm) is wrapped in a WAV header first, since a
	 * browser can't play it as it comes.
	 */
	private const EXTENSIONS = array(
		'audio/mpeg'  => 'mp3',
		'audio/mp3'   => 'mp3',
		'audio/wav'   => 'wav',
		'audio/x-wav' => 'wav',
		'audio/wave'  => 'wav',
		'audio/ogg'   => 'ogg',
		'audio/opus'  => 'opus',
		'audio/aac'   => 'aac',
		'audio/flac'  => 'flac',
		'audio/webm'  => 'webm',
		'audio/mp4'   => 'm4a',
		'audio/x-m4a' => 'm4a',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// before_delete_post, not deleted_post: by deleted_post the post's
		// meta is already gone, so the file name would be lost.
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_post' ) );
	}

	/**
	 * Whether the Listen button should show: the site owner turned Read to
	 * me on, and the configured provider says it can turn text into speech.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( ! Daymark_Settings::text_to_speech() ) {
			return false;
		}

		return self::provider_supports();
	}

	/**
	 * Whether the configured AI provider says it supports text to speech.
	 *
	 * @return bool
	 */
	public static function provider_supports(): bool {
		/**
		 * Whether Daymark treats the AI provider as able to turn text into
		 * speech.
		 *
		 * Defaults to the AI Client's own capability check for the first
		 * configured provider (cached for an hour).
		 *
		 * @since 0.21.0
		 *
		 * @param bool $supported Whether text to speech is supported.
		 */
		return (bool) apply_filters(
			'daymark_text_to_speech_supported',
			Daymark_Plugin::instance()->ai_assist->supports_text_to_speech()
		);
	}

	/**
	 * Whether a post may be read aloud through Daymark: a published, non
	 * password-protected post of the site's own content types, or a cached
	 * post from a followed site.
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public static function can_read( WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}

		return Daymark_External_Notes::is_own_content_type( $post->post_type )
			|| Daymark_Subscription_Post_Type::POST_TYPE === $post->post_type;
	}

	/**
	 * The text that gets read aloud: the title (unless the body already
	 * starts with it, as an untitled Note's generated title does), then the
	 * body as plain-text paragraphs, capped at the character limit.
	 *
	 * @param WP_Post $post The post.
	 * @return array{text: string, truncated: bool}
	 */
	public static function text_for_post( WP_Post $post ): array {
		if ( Daymark_Subscription_Post_Type::POST_TYPE === $post->post_type ) {
			$html = (string) get_post_meta( $post->ID, 'body_content', true );

			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				$html = '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
			}
		} else {
			$html = do_blocks( strip_shortcodes( (string) $post->post_content ) );
		}

		$body  = self::plain_text( (string) $html );
		$title = self::plain_text( get_the_title( $post ) );
		$title = trim( preg_replace( '/(\x{2026}|\.\.\.)$/u', '', $title ) );

		if ( '' !== $title && 0 !== mb_stripos( $body, $title ) ) {
			$body = $title . ".\n\n" . $body;
		}

		return self::cap( trim( $body ), self::max_chars() );
	}

	/**
	 * The cached audio for a post, when it still matches the post's text.
	 *
	 * @param WP_Post $post The post.
	 * @return array{url: string, truncated: bool}|null
	 */
	public static function cached( WP_Post $post ): ?array {
		$text = self::text_for_post( $post );

		if ( '' === $text['text'] ) {
			return null;
		}

		return self::cached_for_hash( $post->ID, self::hash( $text['text'] ) );
	}

	/**
	 * Return the post's audio, making it first when there's none for the
	 * current text.
	 *
	 * @param WP_Post $post      The post.
	 * @param bool    $may_make  False to only return a cached recording
	 *                           (the caller has run out of its allowance).
	 * @return array{url: string, truncated: bool, cached: bool}|WP_Error
	 */
	public static function get_or_make( WP_Post $post, bool $may_make = true ) {
		$text = self::text_for_post( $post );

		if ( '' === $text['text'] ) {
			return new WP_Error(
				'daymark_speech_no_text',
				__( 'This post has no text to read aloud.', 'daymark' ),
				array( 'status' => 422 )
			);
		}

		$hash   = self::hash( $text['text'] );
		$cached = self::cached_for_hash( $post->ID, $hash );

		if ( null !== $cached ) {
			return $cached + array( 'cached' => true );
		}

		if ( ! $may_make ) {
			return new WP_Error( 'daymark_speech_not_cached', '', array( 'status' => 404 ) );
		}

		/**
		 * Supplies a post's audio instead of asking the AI provider.
		 *
		 * Return an array {data: string (audio bytes), mime: string} to use
		 * it, false to treat the recording as failed, or null (the default)
		 * to ask the configured AI provider.
		 *
		 * @since 0.21.0
		 *
		 * @param array|false|null $audio The audio, or null.
		 * @param string           $text  The text to read aloud.
		 * @param WP_Post          $post  The post.
		 */
		$audio = apply_filters( 'daymark_speech_audio', null, $text['text'], $post );

		if ( null === $audio ) {
			$audio = Daymark_Plugin::instance()->ai_assist->convert_text_to_speech( $text['text'], self::voice() );
		}

		$audio = is_array( $audio ) && isset( $audio['data'], $audio['mime'] ) && '' !== (string) $audio['data']
			? self::playable( (string) $audio['data'], (string) $audio['mime'] )
			: null;

		if ( null === $audio ) {
			return new WP_Error(
				'daymark_speech_failed',
				__( 'Couldn\'t make an audio version. Your AI provider may not support reading posts aloud yet.', 'daymark' ),
				array( 'status' => 502 )
			);
		}

		$saved = self::save( $post->ID, $audio['data'], $audio['ext'] );

		if ( '' === $saved ) {
			return new WP_Error(
				'daymark_speech_save_failed',
				__( 'Couldn\'t save the audio version.', 'daymark' ),
				array( 'status' => 500 )
			);
		}

		self::delete_for_post( $post->ID );

		update_post_meta(
			$post->ID,
			self::META_KEY,
			wp_slash(
				wp_json_encode(
					array(
						'file'      => $saved,
						'hash'      => $hash,
						'mime'      => $audio['mime'],
						'truncated' => $text['truncated'],
						'created'   => time(),
					)
				)
			)
		);

		return array(
			'url'       => self::file_url( $saved ),
			'truncated' => $text['truncated'],
			'cached'    => false,
		);
	}

	/**
	 * Delete a post's cached audio file and its meta.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_for_post( $post_id ): void {
		$post_id = absint( $post_id );
		$stored  = self::stored( $post_id );

		if ( null !== $stored ) {
			$path = self::file_path( $stored['file'] );

			if ( '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Make raw provider audio playable in a browser: keep a known audio
	 * type, wrap raw PCM in a WAV header, refuse anything else.
	 *
	 * @param string $data Audio bytes.
	 * @param string $mime MIME type the provider reported (may carry parameters).
	 * @return array{data: string, mime: string, ext: string}|null
	 */
	public static function playable( string $data, string $mime ): ?array {
		$mime = strtolower( trim( $mime ) );
		$base = trim( explode( ';', $mime )[0] );

		if ( isset( self::EXTENSIONS[ $base ] ) ) {
			return array(
				'data' => $data,
				'mime' => $base,
				'ext'  => self::EXTENSIONS[ $base ],
			);
		}

		if ( in_array( $base, array( 'audio/l16', 'audio/pcm' ), true ) ) {
			$rate = preg_match( '/rate=(\d+)/', $mime, $found ) ? (int) $found[1] : 24000;

			return array(
				'data' => self::wav_header( strlen( $data ), max( 8000, min( 96000, $rate ) ) ) . $data,
				'mime' => 'audio/wav',
				'ext'  => 'wav',
			);
		}

		return null;
	}

	/**
	 * A 44-byte WAV header for 16-bit mono little-endian PCM.
	 *
	 * Gemini's speech output, for one, is raw 16-bit PCM despite its
	 * audio/L16 label; this treats it as little-endian, which is what those
	 * providers send.
	 *
	 * @param int $data_bytes Length of the PCM data.
	 * @param int $rate       Sample rate in Hz.
	 * @return string
	 */
	private static function wav_header( int $data_bytes, int $rate ): string {
		return 'RIFF' . pack( 'V', 36 + $data_bytes ) . 'WAVE'
			. 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 )
			. 'data' . pack( 'V', $data_bytes );
	}

	/**
	 * Reduce HTML to plain text with paragraph breaks.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain_text( string $html ): string {
		// Block-level ends become paragraph breaks before the tags go.
		$html = (string) preg_replace( '#</(p|div|li|h[1-6]|blockquote|figcaption|pre|tr)>|<br\s*/?>#i', "$0\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out  = array();

		foreach ( preg_split( '/\n\s*\n/u', $text ) as $paragraph ) {
			$paragraph = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $paragraph ) );

			if ( '' !== $paragraph ) {
				$out[] = $paragraph;
			}
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Cap text at a character limit, ending on a sentence (or else a word)
	 * boundary.
	 *
	 * @param string $text      Text.
	 * @param int    $max_chars Limit.
	 * @return array{text: string, truncated: bool}
	 */
	private static function cap( string $text, int $max_chars ): array {
		if ( mb_strlen( $text ) <= $max_chars ) {
			return array(
				'text'      => $text,
				'truncated' => false,
			);
		}

		$cut  = mb_substr( $text, 0, $max_chars );
		$stop = max( (int) mb_strrpos( $cut, '. ' ), (int) mb_strrpos( $cut, '! ' ), (int) mb_strrpos( $cut, '? ' ), (int) mb_strrpos( $cut, "\n" ) );

		if ( $stop > $max_chars / 2 ) {
			$cut = mb_substr( $cut, 0, $stop + 1 );
		} else {
			$space = (int) mb_strrpos( $cut, ' ' );
			$cut   = $space > 0 ? mb_substr( $cut, 0, $space ) : $cut;
		}

		return array(
			'text'      => trim( $cut ),
			'truncated' => true,
		);
	}

	/**
	 * The character limit, filterable.
	 *
	 * @return int
	 */
	private static function max_chars(): int {
		/**
		 * How many characters of a post are read aloud.
		 *
		 * @since 0.21.0
		 *
		 * @param int $max_chars Defaults to 4000.
		 */
		return max( 200, (int) apply_filters( 'daymark_speech_max_chars', self::DEFAULT_MAX_CHARS ) );
	}

	/**
	 * The provider voice to ask for, or '' for the provider's default.
	 *
	 * @return string
	 */
	private static function voice(): string {
		/**
		 * The voice name to ask the AI provider for when reading a post
		 * aloud. Voice names are provider-specific (for example `alloy` for
		 * OpenAI). Empty uses the provider's default.
		 *
		 * @since 0.21.0
		 *
		 * @param string $voice Defaults to ''.
		 */
		return sanitize_text_field( (string) apply_filters( 'daymark_speech_voice', '' ) );
	}

	/**
	 * Identity of a recording: the text plus the voice it was read in.
	 *
	 * @param string $text Text read aloud.
	 * @return string
	 */
	private static function hash( string $text ): string {
		return md5( self::voice() . "\n" . $text );
	}

	/**
	 * The stored recording when its hash matches and its file still exists.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash    Expected hash.
	 * @return array{url: string, truncated: bool}|null
	 */
	private static function cached_for_hash( int $post_id, string $hash ): ?array {
		$stored = self::stored( $post_id );

		if ( null === $stored || ! hash_equals( $stored['hash'], $hash ) ) {
			return null;
		}

		$path = self::file_path( $stored['file'] );

		if ( '' === $path || ! file_exists( $path ) ) {
			return null;
		}

		return array(
			'url'       => self::file_url( $stored['file'] ),
			'truncated' => $stored['truncated'],
		);
	}

	/**
	 * The stored recording meta, validated.
	 *
	 * @param int $post_id Post ID.
	 * @return array{file: string, hash: string, truncated: bool}|null
	 */
	private static function stored( int $post_id ): ?array {
		$raw  = get_post_meta( $post_id, self::META_KEY, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( ! is_array( $data ) || empty( $data['file'] ) || empty( $data['hash'] ) ) {
			return null;
		}

		$file = sanitize_file_name( (string) $data['file'] );

		if ( $file !== $data['file'] ) {
			return null;
		}

		return array(
			'file'      => $file,
			'hash'      => (string) $data['hash'],
			'truncated' => ! empty( $data['truncated'] ),
		);
	}

	/**
	 * Write an audio file and return its file name, or '' on failure.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $data    Audio bytes.
	 * @param string $ext     File extension.
	 * @return string
	 */
	private static function save( int $post_id, string $data, string $ext ): string {
		$dir = self::dir();

		if ( '' === $dir ) {
			return '';
		}

		// A random part, so a recording's address can't be guessed from the
		// post ID alone.
		$file = $post_id . '-' . strtolower( wp_generate_password( 16, false ) ) . '.' . $ext;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing Daymark's own cache file.
		if ( false === file_put_contents( $dir . '/' . $file, $data ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * The audio folder, created on first use.
	 *
	 * Unlike the chunked-upload folder, files here must be readable from
	 * the web: the browser plays them straight from this address. Only an
	 * index.php is added, so the folder can't be listed.
	 *
	 * @param bool $create Create the folder when missing.
	 * @return string Absolute path, or '' when unavailable.
	 */
	public static function dir( bool $create = true ): string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;

		if ( is_dir( $dir ) ) {
			return $dir;
		}

		if ( ! $create || ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time guard file.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );

		return $dir;
	}

	/**
	 * Absolute path of a stored audio file.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	private static function file_path( string $file ): string {
		$dir = self::dir( false );

		return '' === $dir ? '' : $dir . '/' . $file;
	}

	/**
	 * Public URL of a stored audio file.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	private static function file_url( string $file ): string {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( (string) $uploads['baseurl'] ) . self::DIR_NAME . '/' . rawurlencode( $file );
	}
}
