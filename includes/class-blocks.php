<?php
/**
 * Block and shortcode registration.
 *
 * Registers the daymark/* dynamic blocks from the blocks/ directory and
 * the matching daymark_* shortcodes. Both delegate to Daymark_Renderer so
 * blocks and shortcodes produce identical markup.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Daymark blocks and shortcode fallbacks.
 */
class Daymark_Blocks {

	/**
	 * View keys shared by blocks and shortcodes.
	 *
	 * Blocks: daymark/{view}. Shortcodes: [daymark_{view}].
	 *
	 * @var array<int, string>
	 */
	private const VIEWS = array( 'timeline', 'images', 'videos', 'audio', 'notes' );

	/**
	 * Script handle for the single shared block editor bundle.
	 *
	 * @var string
	 */
	private const EDITOR_SCRIPT_HANDLE = 'daymark-editor-script';

	/**
	 * Shared view renderer.
	 *
	 * @var Daymark_Renderer
	 */
	private Daymark_Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Daymark_Renderer|null $renderer Shared renderer instance.
	 */
	public function __construct( ?Daymark_Renderer $renderer = null ) {
		$this->renderer = $renderer ?? new Daymark_Renderer();
	}

	/**
	 * Register blocks and shortcodes. Called on init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'block_categories_all', array( $this, 'register_category' ) );

		// Register the shared editor bundle once. Every block.json references
		// this handle rather than a file: path so core enqueues a single
		// script instead of one per block (each would otherwise re-run
		// registerBlockType() and throw "already registered" errors).
		$asset = include DAYMARK_PLUGIN_DIR . 'build/index.asset.php';
		$asset = is_array( $asset ) ? $asset : array();

		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			DAYMARK_PLUGIN_URL . 'build/index.js',
			(array) ( $asset['dependencies'] ?? array() ),
			$asset['version'] ?? DAYMARK_VERSION,
			true
		);

		foreach ( self::VIEWS as $view ) {
			add_shortcode(
				'daymark_' . $view,
				function ( $atts ) use ( $view ): string {
					return $this->render_shortcode( $view, $atts );
				}
			);

			$block_json = DAYMARK_PLUGIN_DIR . 'blocks/' . $view . '/block.json';

			if ( file_exists( $block_json ) ) {
				register_block_type( $block_json );
			}
		}
	}

	/**
	 * Add a dedicated "Daymark" inserter category so the five view blocks
	 * are grouped together instead of scattered under the generic "Widgets"
	 * category.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_category( array $categories ): array {
		return array_merge(
			array(
				array(
					'slug'  => 'daymark',
					'title' => __( 'Daymark', 'daymark' ),
					'icon'  => 'location-alt',
				),
			),
			$categories
		);
	}

	/**
	 * Shortcode callback for a Mark view.
	 *
	 * @param string       $view Validated view key from self::VIEWS.
	 * @param array|string $atts Raw shortcode attributes.
	 * @return string Escaped HTML.
	 */
	private function render_shortcode( string $view, $atts ): string {
		$atts = shortcode_atts(
			array(
				'count' => 10,
			),
			(array) $atts,
			'daymark_' . $view
		);

		return $this->renderer->render(
			$view,
			array(
				'count' => absint( $atts['count'] ),
			)
		);
	}
}
