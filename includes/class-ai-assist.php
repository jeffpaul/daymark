<?php
/**
 * AI Assist adapter.
 *
 * Phase 4. Uses the WordPress 7.0 AI Client (the namespaced
 * `WordPress\AiClient\AiClient` SDK bundled in wp-includes/php-ai-client
 * and surfaced through `wp_ai_client_prompt()`) when at least one provider
 * is configured; otherwise returns deterministic mock suggestions.
 *
 * Contract: never throws, never blocks publishing. Any failure on the real
 * path falls back to the mock path silently (logged only under WP_DEBUG).
 *
 * @package Moment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional AI suggestion adapter with a deterministic mock fallback.
 */
class Moment_AI_Assist {

	/**
	 * Provider label reported when suggestions are mocked.
	 *
	 * @var string
	 */
	private const MOCK_PROVIDER_LABEL = 'Demo Mode';

	/**
	 * Maximum number of suggested tags returned.
	 *
	 * @var int
	 */
	private const MAX_TAGS = 5;

	/**
	 * Memoized availability result for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $available = null;

	/**
	 * First configured provider ID (e.g. 'anthropic').
	 *
	 * @var string
	 */
	private string $provider_id = '';

	/**
	 * Human-readable label for the configured provider.
	 *
	 * @var string
	 */
	private string $provider_label = '';

	/**
	 * Whether a real AI provider is available.
	 *
	 * True only when AI is not disabled for the environment AND at least one
	 * registered provider has working credentials (per the SDK's own
	 * availability check). The AI Client itself ships with core on WP 7.0+.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$this->detect_provider();

		return (bool) $this->available;
	}

	/**
	 * Human-readable label of the active suggestion source.
	 *
	 * @return string Provider name when the real path is available, 'Demo Mode' otherwise.
	 */
	public function get_provider_label(): string {
		if ( ! $this->is_available() || '' === $this->provider_label ) {
			return self::MOCK_PROVIDER_LABEL;
		}

		return $this->provider_label;
	}

	/**
	 * Suggest a caption for a Moment draft.
	 *
	 * @param array $context Context: text, media_count, media_types, filename.
	 * @return string Suggested caption (plain text).
	 */
	public function suggest_caption( array $context ): string {
		$context = $this->normalize_context( $context );

		if ( $this->is_available() ) {
			$result = $this->generate_text(
				$this->build_caption_prompt( $context ),
				'You help a personal blogger polish short, social-style posts. Respond with a single caption of at most 200 characters. Plain text only — no quotes, no hashtags, no markdown.',
				120
			);

			if ( null !== $result ) {
				return sanitize_text_field( $result );
			}
		}

		return $this->mock_caption( $context );
	}

	/**
	 * Suggest alt text for an attachment.
	 *
	 * The real path prompts from textual context only (caption, filename,
	 * attachment title) — media bytes are never uploaded to the provider.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $context       Context: text, media_count, media_types, filename.
	 * @return string Suggested alt text (plain text, may be empty for notes).
	 */
	public function suggest_alt_text( int $attachment_id, array $context ): string {
		$attachment_id = absint( $attachment_id );
		$context       = $this->normalize_context( $context );

		if ( '' === $context['filename'] && $attachment_id ) {
			$attached_file = get_attached_file( $attachment_id );

			if ( is_string( $attached_file ) && '' !== $attached_file ) {
				$context['filename'] = sanitize_file_name( wp_basename( $attached_file ) );
			}
		}

		$description = trim( $context['text'] );

		if ( $this->is_available() && '' !== $description ) {
			$result = $this->generate_text(
				$this->build_alt_text_prompt( $context ),
				'You write concise, descriptive alt text for accessibility. Respond with one plain-text phrase of at most 125 characters. No quotes, no leading "Image of".',
				80
			);

			if ( null !== $result ) {
				return sanitize_text_field( $result );
			}
		}

		return $this->mock_alt_text( $context );
	}

	/**
	 * Suggest alt text for an image from its actual pixels (vision).
	 *
	 * Unlike suggest_alt_text(), this sends the image bytes to the provider
	 * so the alt describes what is really in the frame. Used to pre-fill the
	 * composer's per-image alt field, which the author can then edit before
	 * publishing. Falls back to deterministic mock alt on any failure or
	 * when no provider is configured — never throws.
	 *
	 * @param string $image_path Absolute path to a readable image file.
	 * @param array  $context    Optional context: text, type.
	 * @return array{alt_text: string, is_mocked: bool, provider_label: string}
	 */
	public function get_image_alt_suggestion( string $image_path, array $context = array() ): array {
		$context = $this->normalize_context( $context );

		if ( $this->is_available() && '' !== $image_path && is_readable( $image_path ) ) {
			$alt = $this->generate_vision_alt( $image_path );

			if ( null !== $alt ) {
				return array(
					'alt_text'       => sanitize_text_field( $alt ),
					'is_mocked'      => false,
					'provider_label' => $this->get_provider_label(),
				);
			}
		}

		return array(
			'alt_text'       => $this->mock_alt_text( $context ),
			'is_mocked'      => true,
			'provider_label' => self::MOCK_PROVIDER_LABEL,
		);
	}

	/**
	 * Run a single vision generation to describe an image as alt text.
	 *
	 * Builds a mixed text+image prompt for the WP 7.0 AI Client. Returns
	 * null on any failure so the caller falls back to mock alt.
	 *
	 * @param string $image_path Absolute path to a readable image file.
	 * @return string|null Trimmed alt text, or null on any failure.
	 */
	private function generate_vision_alt( string $image_path ): ?string {
		$file_class = '\WordPress\AiClient\Files\DTO\File';
		$part_class = '\WordPress\AiClient\Messages\DTO\MessagePart';

		if ( ! class_exists( $file_class ) || ! class_exists( $part_class ) ) {
			return null;
		}

		try {
			$file = new $file_class( $image_path );
			$part = new $part_class( $file );

			$instruction = 'Write concise, descriptive alt text for the attached image, for a personal blog. '
				. 'Describe what is actually visible. One plain-text phrase, at most 125 characters. '
				. 'No quotes, no leading "Image of" or "Photo of".';

			$result = wp_ai_client_prompt( array( $instruction, $part ) )
				->using_system_instruction( 'You write concise, accurate alt text for accessibility.' )
				->using_max_tokens( 80 )
				->generate_text();

			if ( is_wp_error( $result ) ) {
				$this->log_debug( 'Vision alt failed: ' . $result->get_error_message() );
				return null;
			}

			if ( ! is_string( $result ) || '' === trim( $result ) ) {
				return null;
			}

			return trim( $result );
		} catch ( Throwable $e ) {
			$this->log_debug( 'Vision alt threw: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Suggest tags for a Moment draft.
	 *
	 * @param array $context Context: text, media_count, media_types, filename.
	 * @return string[] Suggested tag slugs/phrases (max 5).
	 */
	public function suggest_tags( array $context ): array {
		$context = $this->normalize_context( $context );

		if ( $this->is_available() ) {
			$result = $this->generate_text(
				$this->build_tags_prompt( $context ),
				'You suggest short lowercase topic tags for a personal blog post. Respond ONLY with 3 to 5 comma-separated tags. No hashes, no numbering, no extra words.',
				60
			);

			if ( null !== $result ) {
				$tags = $this->sanitize_tags( explode( ',', $result ) );

				if ( ! empty( $tags ) ) {
					return $tags;
				}
			}
		}

		return $this->mock_tags( $context );
	}

	/**
	 * Get the full suggestion bundle for a Moment draft.
	 *
	 * Backward compatible with the Phase 2 call shape used by the REST
	 * controller: get_suggestions( $caption_string, $type ). A string first
	 * argument is normalized into the context array internally.
	 *
	 * The real path issues a single structured (JSON) generation call; any
	 * failure at any step falls back to the deterministic mock bundle.
	 *
	 * @param array|string $context Context array (text, media_count, media_types,
	 *                              filename) or a plain caption string.
	 * @param string       $type    Primary Moment type (image|video|audio|podcast|note|gallery|mixed).
	 * @return array{caption: string, alt_text: string, tags: string[], is_mocked: bool, provider_label: string}
	 */
	public function get_suggestions( $context, string $type = '' ): array {
		$context = $this->normalize_context( $context, $type );

		if ( $this->is_available() ) {
			$suggestions = $this->generate_suggestion_bundle( $context );

			if ( null !== $suggestions ) {
				return $suggestions;
			}
		}

		// TODO: Replace with real WordPress\AiClient\AiClient call when a provider is configured.
		// WP 7.0 AI Client: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
		// Real path: wp_ai_client_prompt( $prompt )->as_json_response( $schema )->generate_text()
		// (see generate_suggestion_bundle()). This mock is deterministic: same input, same output.
		return array(
			'caption'        => $this->mock_caption( $context ),
			'alt_text'       => $this->mock_alt_text( $context ),
			'tags'           => $this->mock_tags( $context ),
			'is_mocked'      => true,
			'provider_label' => self::MOCK_PROVIDER_LABEL,
		);
	}

	/**
	 * Detect the first configured AI Client provider.
	 *
	 * Memoized per request. Never throws — any SDK error means "not available".
	 *
	 * @return void
	 */
	private function detect_provider(): void {
		if ( null !== $this->available ) {
			return;
		}

		$this->available = false;

		try {
			// Respect the core kill switch / filter (WP_AI_SUPPORT, wp_supports_ai).
			if ( ! wp_supports_ai() ) {
				return;
			}

			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
				$provider_id = (string) $provider_id;

				if ( ! $registry->isProviderConfigured( $provider_id ) ) {
					continue;
				}

				$this->provider_id    = $provider_id;
				$this->provider_label = $this->resolve_provider_label( $registry, $provider_id );
				$this->available      = true;

				return;
			}
		} catch ( Throwable $e ) {
			$this->available = false;
			$this->log_debug( 'Provider detection failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Resolve a human-readable provider label from SDK metadata.
	 *
	 * @param object $registry    The SDK ProviderRegistry.
	 * @param string $provider_id Provider ID (e.g. 'anthropic').
	 * @return string
	 */
	private function resolve_provider_label( $registry, string $provider_id ): string {
		try {
			$class_name = $registry->getProviderClassName( $provider_id );
			$name       = $class_name::metadata()->getName();

			if ( is_string( $name ) && '' !== $name ) {
				return sanitize_text_field( $name );
			}
		} catch ( Throwable $e ) {
			$this->log_debug( 'Provider label lookup failed: ' . $e->getMessage() );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $provider_id ) ) );
	}

	/**
	 * Run a single text generation call through the WP 7.0 AI Client.
	 *
	 * @param string     $prompt      User prompt.
	 * @param string     $system      System instruction.
	 * @param int        $max_tokens  Token cap for the response.
	 * @param array|null $json_schema Optional JSON schema for structured output.
	 * @return string|null Trimmed response text, or null on any failure.
	 */
	private function generate_text( string $prompt, string $system, int $max_tokens, ?array $json_schema = null ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		try {
			// Note: no using_temperature() — current Anthropic models reject
			// the temperature parameter with a 400. Provider defaults are fine.
			$builder = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->using_max_tokens( $max_tokens );

			if ( null !== $json_schema ) {
				$builder = $builder->as_json_response( $json_schema );
			}

			$result = $builder->generate_text();

			if ( is_wp_error( $result ) ) {
				$this->log_debug( 'Generation failed: ' . $result->get_error_message() );
				return null;
			}

			if ( ! is_string( $result ) || '' === trim( $result ) ) {
				return null;
			}

			return trim( $result );
		} catch ( Throwable $e ) {
			$this->log_debug( 'Generation threw: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Real path for get_suggestions(): one structured JSON generation call.
	 *
	 * @param array $context Normalized context.
	 * @return array|null Full suggestion bundle, or null so the caller falls back to mock.
	 */
	private function generate_suggestion_bundle( array $context ): ?array {
		$schema = array(
			'type'                 => 'object',
			// Anthropic structured output rejects object schemas unless
			// additionalProperties is explicitly false.
			'additionalProperties' => false,
			'properties'           => array(
				'caption'  => array( 'type' => 'string' ),
				'alt_text' => array( 'type' => 'string' ),
				'tags'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'caption', 'alt_text', 'tags' ),
		);

		$prompt = 'Suggest a caption, alt text, and 3-5 topic tags for this personal blog moment. '
			. $this->describe_context( $context )
			. ' Respond as JSON with keys "caption" (max 200 chars), "alt_text" (max 125 chars, empty string if no media), and "tags" (array of lowercase strings).';

		$raw = $this->generate_text(
			$prompt,
			'You help a personal blogger polish short, social-style posts. Plain language, no hashtags, no markdown.',
			400,
			$schema
		);

		if ( null === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['caption'], $data['alt_text'], $data['tags'] ) ) {
			$this->log_debug( 'Structured suggestion response was not valid JSON.' );
			return null;
		}

		$tags = $this->sanitize_tags( (array) $data['tags'] );

		return array(
			'caption'        => sanitize_text_field( (string) $data['caption'] ),
			'alt_text'       => sanitize_text_field( (string) $data['alt_text'] ),
			'tags'           => ! empty( $tags ) ? $tags : $this->mock_tags( $context ),
			'is_mocked'      => false,
			'provider_label' => $this->get_provider_label(),
		);
	}

	/**
	 * Normalize a context array (or legacy caption string) into a known shape.
	 *
	 * @param array|string $context Context array or plain caption string.
	 * @param string       $type    Primary Moment type hint.
	 * @return array{text: string, media_count: int, media_types: string[], filename: string, type: string}
	 */
	private function normalize_context( $context, string $type = '' ): array {
		if ( is_string( $context ) ) {
			$context = array( 'text' => $context );
		}

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$media_types = array();

		foreach ( (array) ( $context['media_types'] ?? array() ) as $media_type ) {
			$media_type = sanitize_key( (string) $media_type );

			if ( '' !== $media_type ) {
				$media_types[] = $media_type;
			}
		}

		$type = sanitize_key( '' !== $type ? $type : (string) ( $context['type'] ?? '' ) );

		if ( '' === $type ) {
			$type = ! empty( $media_types ) ? $media_types[0] : 'note';
		}

		return array(
			'text'        => sanitize_textarea_field( (string) ( $context['text'] ?? '' ) ),
			'media_count' => absint( $context['media_count'] ?? 0 ),
			'media_types' => $media_types,
			'filename'    => sanitize_file_name( (string) ( $context['filename'] ?? '' ) ),
			'type'        => $type,
		);
	}

	/**
	 * Describe the normalized context as a prompt fragment.
	 *
	 * @param array $context Normalized context.
	 * @return string
	 */
	private function describe_context( array $context ): string {
		$parts = array( 'Moment type: ' . $context['type'] . '.' );

		if ( '' !== $context['text'] ) {
			$parts[] = 'Draft text: "' . $context['text'] . '".';
		}

		if ( $context['media_count'] > 0 ) {
			$parts[] = sprintf(
				'Attached media: %d file(s)%s.',
				$context['media_count'],
				! empty( $context['media_types'] ) ? ' (' . implode( ', ', $context['media_types'] ) . ')' : ''
			);
		}

		if ( '' !== $context['filename'] ) {
			$parts[] = 'Filename: ' . $context['filename'] . '.';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Build the caption prompt for the real path.
	 *
	 * @param array $context Normalized context.
	 * @return string
	 */
	private function build_caption_prompt( array $context ): string {
		return 'Suggest one short caption for this personal blog moment. ' . $this->describe_context( $context );
	}

	/**
	 * Build the alt text prompt for the real path.
	 *
	 * @param array $context Normalized context.
	 * @return string
	 */
	private function build_alt_text_prompt( array $context ): string {
		return 'Suggest alt text for the attached media, based only on this description. ' . $this->describe_context( $context );
	}

	/**
	 * Build the tags prompt for the real path.
	 *
	 * @param array $context Normalized context.
	 * @return string
	 */
	private function build_tags_prompt( array $context ): string {
		return 'Suggest 3 to 5 topic tags for this personal blog moment. ' . $this->describe_context( $context );
	}

	/**
	 * Deterministic mock caption. Same input, same output.
	 *
	 * @param array $context Normalized context.
	 * @return string
	 */
	private function mock_caption( array $context ): string {
		// Fallback when no AI provider is configured. The real path is
		// wp_ai_client_prompt( $this->build_caption_prompt( $context ) )->generate_text()
		// in suggest_caption().
		if ( '' !== $context['text'] ) {
			return wp_html_excerpt( $context['text'], 100, '…' );
		}

		$captions = array(
			'image'   => 'A quiet moment, captured.',
			'gallery' => 'A few moments, gathered together.',
			'video'   => 'A moment in motion.',
			'audio'   => 'A moment in sound.',
			'podcast' => 'A new episode, in the moment.',
			'note'    => 'A small note from today.',
			'mixed'   => 'A mixed-media moment.',
		);

		return $captions[ $context['type'] ] ?? $captions['note'];
	}

	/**
	 * Deterministic mock alt text. Same input, same output.
	 *
	 * @param array $context Normalized context.
	 * @return string
	 */
	private function mock_alt_text( array $context ): string {
		// Fallback when no AI provider is configured. The real path is
		// wp_ai_client_prompt( $this->build_alt_text_prompt( $context ) )->generate_text()
		// in suggest_alt_text().
		$labels = array(
			'image'   => 'Photo',
			'gallery' => 'Photo gallery',
			'video'   => 'Video',
			'audio'   => 'Audio file',
			'podcast' => 'Audio file',
			'mixed'   => 'Media',
		);

		return $labels[ $context['type'] ] ?? '';
	}

	/**
	 * Deterministic mock tags. Same input, same output.
	 *
	 * @param array $context Normalized context.
	 * @return string[]
	 */
	private function mock_tags( array $context ): array {
		// Fallback when no AI provider is configured. The real path is
		// wp_ai_client_prompt( $this->build_tags_prompt( $context ) )->generate_text()
		// in suggest_tags().
		return $this->sanitize_tags( array( 'moment', $context['type'], 'personal' ) );
	}

	/**
	 * Sanitize, lowercase, dedupe, and cap a list of tags.
	 *
	 * @param array $tags Raw tag candidates.
	 * @return string[]
	 */
	private function sanitize_tags( array $tags ): array {
		$clean = array();

		foreach ( $tags as $tag ) {
			$tag = strtolower( sanitize_text_field( (string) $tag ) );
			$tag = trim( $tag, " \t\n\r\0\x0B#," );

			if ( '' !== $tag && ! in_array( $tag, $clean, true ) ) {
				$clean[] = $tag;
			}

			if ( count( $clean ) >= self::MAX_TAGS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Log a debug message when WP_DEBUG is enabled. Never throws.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only fallback logging.
			error_log( '[Moment AI Assist] ' . $message );
		}
	}
}
