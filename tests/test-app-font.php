<?php
/**
 * The app font: any font in the site's Font Library, chosen on Settings ->
 * Daymark -> General (Daymark_Settings::app_font() and friends), and the
 * move away from the old Bionic Reading setting.
 *
 * @package Daymark
 */

class Test_App_Font extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'wp_theme_json_data_theme' );
		remove_all_filters( 'daymark_app_font' );
		wp_clean_theme_json_cache();

		parent::tear_down();
	}

	/**
	 * Install a family in the Font Library, the way the Site Editor stores
	 * one: a wp_font_family post with wp_font_face children.
	 *
	 * @param string $name   Family name.
	 * @param string $slug   Family slug.
	 * @param string $family CSS font-family value.
	 * @param array  $faces  Face settings (fontStyle, fontWeight, src).
	 * @return int Family post ID.
	 */
	private function install_font( string $name, string $slug, string $family, array $faces = array() ): int {
		$family_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_font_family',
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_name'    => $slug,
				'post_content' => wp_slash( wp_json_encode( array( 'fontFamily' => $family ) ) ),
			)
		);

		foreach ( $faces as $face ) {
			self::factory()->post->create(
				array(
					'post_type'    => 'wp_font_face',
					'post_status'  => 'publish',
					'post_parent'  => $family_id,
					'post_content' => wp_slash( wp_json_encode( $face ) ),
				)
			);
		}

		return $family_id;
	}

	/**
	 * Give the active theme these font families, as its theme.json would.
	 *
	 * @param array $families theme.json fontFamilies entries.
	 * @return void
	 */
	private function theme_fonts( array $families ): void {
		add_filter(
			'wp_theme_json_data_theme',
			static function ( $theme_json ) use ( $families ) {
				return $theme_json->update_with(
					array(
						'version'  => 3,
						'settings' => array(
							'typography' => array(
								'fontFamilies' => $families,
							),
						),
					)
				);
			}
		);
		wp_clean_theme_json_cache();
	}

	/** With nothing chosen the app keeps its usual font and loads no files. */
	public function test_default_is_no_font(): void {
		$this->assertSame( '', Daymark_Settings::app_font() );
		$this->assertSame( '', Daymark_Settings::app_font_stack() );
		$this->assertSame( array(), Daymark_Settings::app_font_faces() );
		$this->assertSame( array(), Daymark_Settings::app_font_origins() );
	}

	/** Every family installed in the Font Library is offered, whatever its name. */
	public function test_lists_every_installed_family(): void {
		$this->install_font( 'Inter', 'inter', '"Inter", sans-serif' );
		$this->install_font( 'Bionic Reading', 'bionic-reading', '"Bionic Reading"' );

		$fonts = Daymark_Settings::font_library_fonts();

		$this->assertSame( 'Inter', $fonts['inter'] );
		$this->assertSame( 'Bionic Reading', $fonts['bionic-reading'] );
	}

	/** The theme's own fonts are offered too, since the Font Library shows them. */
	public function test_lists_theme_fonts(): void {
		$this->theme_fonts(
			array(
				array(
					'name'       => 'Theme Serif',
					'slug'       => 'theme-serif',
					'fontFamily' => '"Theme Serif", Georgia, serif',
				),
			)
		);

		$this->assertSame( 'Theme Serif', Daymark_Settings::font_library_fonts()['theme-serif'] ?? null );
	}

	/** The list is in name order, so a long Font Library is easy to scan. */
	public function test_list_is_sorted_by_name(): void {
		$this->install_font( 'Zilla Slab', 'zilla-slab', '"Zilla Slab", serif' );
		$this->install_font( 'Alegreya', 'alegreya', 'Alegreya, serif' );

		$names = array_values( Daymark_Settings::font_library_fonts() );
		$this->assertLessThan( array_search( 'Zilla Slab', $names, true ), array_search( 'Alegreya', $names, true ) );
	}

	/** A chosen installed family gives the app its font stack and files. */
	public function test_chosen_installed_family_supplies_stack_and_faces(): void {
		$this->install_font(
			'Inter',
			'inter',
			'"Inter", sans-serif',
			array(
				array(
					'fontFamily' => 'Inter',
					'fontStyle'  => 'normal',
					'fontWeight' => '100 900',
					'src'        => home_url( '/wp-content/uploads/fonts/inter.woff2' ),
				),
				array(
					'fontFamily' => 'Inter',
					'fontStyle'  => 'italic',
					'fontWeight' => '400',
					'src'        => array( 'https://cdn.example.com/fonts/inter-italic.ttf' ),
				),
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$this->assertSame( 'inter', Daymark_Settings::app_font() );
		$this->assertSame( '"Inter", sans-serif', Daymark_Settings::app_font_stack() );
		$this->assertSame(
			array(
				array(
					'src'    => home_url( '/wp-content/uploads/fonts/inter.woff2' ),
					'style'  => 'normal',
					'weight' => '100 900',
				),
				array(
					'src'    => 'https://cdn.example.com/fonts/inter-italic.ttf',
					'style'  => 'italic',
					'weight' => '400',
				),
			),
			Daymark_Settings::app_font_faces()
		);
	}

	/** Only a font file on another origin needs adding to the app's font-src. */
	public function test_origins_cover_only_other_hosts(): void {
		$this->install_font(
			'Inter',
			'inter',
			'Inter',
			array(
				array( 'src' => home_url( '/wp-content/uploads/fonts/inter.woff2' ) ),
				array( 'src' => 'https://cdn.example.com/fonts/inter-italic.woff2' ),
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$this->assertSame( array( 'https://cdn.example.com' ), Daymark_Settings::app_font_origins() );
	}

	/** A theme font's files, written as file:./ in theme.json, load from the theme's folder. */
	public function test_theme_font_files_resolve_to_theme_urls(): void {
		$this->theme_fonts(
			array(
				array(
					'name'       => 'Theme Serif',
					'slug'       => 'theme-serif',
					'fontFamily' => '"Theme Serif", serif',
					'fontFace'   => array(
						array(
							'fontFamily' => 'Theme Serif',
							'fontStyle'  => 'normal',
							'fontWeight' => '400',
							'src'        => array( 'file:./assets/fonts/theme-serif.woff2' ),
						),
					),
				),
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'theme-serif' );

		$this->assertSame( '"Theme Serif", serif', Daymark_Settings::app_font_stack() );
		$this->assertSame(
			array(
				array(
					'src'    => get_theme_file_uri( 'assets/fonts/theme-serif.woff2' ),
					'style'  => 'normal',
					'weight' => '400',
				),
			),
			Daymark_Settings::app_font_faces()
		);
	}

	/** A theme font that is only a list of device fonts works with no files. */
	public function test_theme_font_without_files_is_just_a_stack(): void {
		$this->theme_fonts(
			array(
				array(
					'name'       => 'System Serif',
					'slug'       => 'system-serif',
					'fontFamily' => 'Iowan Old Style, "Apple Garamond", Baskerville, serif',
				),
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'system-serif' );

		$this->assertSame( '"Iowan Old Style", "Apple Garamond", Baskerville, serif', Daymark_Settings::app_font_stack() );
		$this->assertSame( array(), Daymark_Settings::app_font_faces() );
	}

	/** An installed family wins over a theme font with the same slug. */
	public function test_installed_family_wins_over_theme_font_with_same_slug(): void {
		$this->theme_fonts(
			array(
				array(
					'name'       => 'Inter (theme)',
					'slug'       => 'inter',
					'fontFamily' => 'Inter, sans-serif',
				),
			)
		);
		$this->install_font( 'Inter', 'inter', '"Inter", system-ui' );
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$this->assertSame( 'Inter', Daymark_Settings::font_library_fonts()['inter'] );
		$this->assertSame( '"Inter", system-ui', Daymark_Settings::app_font_stack() );
	}

	/** Nothing but font names reaches the app's stylesheet. */
	public function test_stack_drops_anything_that_is_not_a_font_name(): void {
		$this->install_font( 'Odd', 'odd', 'Odd; } body { color: red, "Fine Font", url(https://evil.example/x), -apple-system' );
		update_option( Daymark_Settings::APP_FONT, 'odd' );

		$stack = Daymark_Settings::app_font_stack();

		$this->assertDoesNotMatchRegularExpression( '/[;{}()\\\\:\/]/', $stack );
		$this->assertStringContainsString( '"Fine Font"', $stack );
		$this->assertStringEndsWith( ', -apple-system', $stack );
	}

	/** A family with no font-family value falls back to its name. */
	public function test_stack_falls_back_to_family_name(): void {
		$family_id = $this->install_font( 'Plain Name', 'plain-name', '' );
		wp_update_post(
			array(
				'ID'           => $family_id,
				'post_content' => '',
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'plain-name' );

		$this->assertSame( '"Plain Name"', Daymark_Settings::app_font_stack() );
	}

	/** Files that aren't fonts, or aren't on the web, are left out. */
	public function test_faces_skip_unusable_files(): void {
		$this->install_font(
			'Inter',
			'inter',
			'Inter',
			array(
				array( 'src' => 'https://cdn.example.com/inter.eot' ),
				array( 'src' => 'javascript:alert(1)' ),
				array(
					'src'        => array( 'https://cdn.example.com/inter.svg', 'https://cdn.example.com/inter.otf' ),
					'fontStyle'  => 'sideways',
					'fontWeight' => 'heavy; x',
				),
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$this->assertSame(
			array(
				array(
					'src'    => 'https://cdn.example.com/inter.otf',
					'style'  => 'normal',
					'weight' => '400',
				),
			),
			Daymark_Settings::app_font_faces()
		);
	}

	/** A face that only covers some characters says which, so the browser picks the right file. */
	public function test_faces_carry_unicode_range(): void {
		$this->install_font(
			'Inter',
			'inter',
			'Inter',
			array(
				array(
					'src'          => 'https://cdn.example.com/inter-latin.woff2',
					'unicodeRange' => 'U+0000-00FF, U+0131',
				),
				array(
					'src'          => 'https://cdn.example.com/inter-odd.woff2',
					'unicodeRange' => 'U+0000; } body {',
				),
			)
		);
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$faces = Daymark_Settings::app_font_faces();

		$this->assertSame( 'U+0000-00FF, U+0131', $faces[0]['unicodeRange'] );
		$this->assertArrayNotHasKey( 'unicodeRange', $faces[1] );
	}

	/** A chosen font that has since been deleted leaves the usual font. */
	public function test_missing_font_falls_back_to_default(): void {
		update_option( Daymark_Settings::APP_FONT, 'gone-font' );

		$this->assertSame( '', Daymark_Settings::app_font() );
		$this->assertSame( '', Daymark_Settings::app_font_stack() );
		$this->assertSame( array(), Daymark_Settings::app_font_faces() );
	}

	/** A draft or trashed family is not in the Font Library, so it isn't used. */
	public function test_unpublished_family_is_not_used(): void {
		$family_id = $this->install_font( 'Inter', 'inter', 'Inter' );
		wp_trash_post( $family_id );
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$this->assertArrayNotHasKey( 'inter', Daymark_Settings::font_library_fonts() );
		$this->assertSame( '', Daymark_Settings::app_font() );
	}

	/** Code on the site can choose the font, by its slug. */
	public function test_filter_chooses_the_font(): void {
		$this->install_font( 'Inter', 'inter', 'Inter' );
		add_filter(
			'daymark_app_font',
			static function () {
				return 'inter';
			}
		);

		$this->assertSame( 'inter', Daymark_Settings::app_font() );
	}

	/** The app config tells the app which font to show and where its files are. */
	public function test_app_config_carries_the_font(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->install_font(
			'Inter',
			'inter',
			'"Inter", sans-serif',
			array( array( 'src' => 'https://cdn.example.com/inter.woff2' ) )
		);
		update_option( Daymark_Settings::APP_FONT, 'inter' );

		$config = Daymark_Routes::build_app_config();

		$this->assertSame( '"Inter", sans-serif', $config['appFont']['family'] );
		$this->assertSame( 'https://cdn.example.com/inter.woff2', $config['appFont']['faces'][0]['src'] );
		$this->assertArrayNotHasKey( 'readingFont', $config );
	}

	/** A site showing Bionic Reading from its Font Library keeps that font, as the chosen app font. */
	public function test_migration_keeps_a_font_library_bionic_reading_family(): void {
		$this->install_font( 'Bionic Reading', 'bionic-reading', '"Bionic Reading"' );
		update_option( 'daymark_bionic_reading_font', '1' );
		update_option( 'daymark_bionic_reading_font_url', 'https://cdn.example.com/BionicReading.woff2' );

		Daymark_Settings::migrate_bionic_reading_font();

		$this->assertSame( 'bionic-reading', get_option( Daymark_Settings::APP_FONT ) );
		$this->assertSame( 'bionic-reading', Daymark_Settings::app_font() );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font' ) );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font_url' ) );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font_family' ) );
	}

	/** The family the site had picked by hand is the one that's kept. */
	public function test_migration_keeps_the_family_that_was_picked(): void {
		$picked = $this->install_font( 'Bionic Reading Web', 'bionic-reading-web', '"Bionic Reading Web"' );
		$this->install_font( 'Bionic Reading', 'bionic-reading', '"Bionic Reading"' );
		update_option( 'daymark_bionic_reading_font', '1' );
		update_option( 'daymark_bionic_reading_font_family', $picked );

		Daymark_Settings::migrate_bionic_reading_font();

		$this->assertSame( 'bionic-reading-web', get_option( Daymark_Settings::APP_FONT ) );
	}

	/** A site that used a web font file or a copy on the device goes back to the usual font. */
	public function test_migration_without_a_font_library_family_returns_to_default(): void {
		$this->install_font( 'Inter', 'inter', 'Inter' );
		update_option( 'daymark_bionic_reading_font', '1' );
		update_option( 'daymark_bionic_reading_font_url', 'https://cdn.example.com/BionicReading.woff2' );

		Daymark_Settings::migrate_bionic_reading_font();

		$this->assertSame( '', Daymark_Settings::app_font() );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font' ) );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font_url' ) );
	}

	/** A site that had told Daymark not to use its Font Library copy goes back to the usual font. */
	public function test_migration_respects_dont_use_the_font_library(): void {
		$this->install_font( 'Bionic Reading', 'bionic-reading', '"Bionic Reading"' );
		update_option( 'daymark_bionic_reading_font', '1' );
		update_option( 'daymark_bionic_reading_font_family', -1 );

		Daymark_Settings::migrate_bionic_reading_font();

		$this->assertSame( '', Daymark_Settings::app_font() );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font_family' ) );
	}

	/** A site with the old setting off gets no font, and the old options go. */
	public function test_migration_with_the_setting_off_chooses_nothing(): void {
		$this->install_font( 'Bionic Reading', 'bionic-reading', '"Bionic Reading"' );
		update_option( 'daymark_bionic_reading_font', '' );
		update_option( 'daymark_bionic_reading_font_family', 0 );

		Daymark_Settings::migrate_bionic_reading_font();

		$this->assertSame( '', Daymark_Settings::app_font() );
		$this->assertFalse( get_option( 'daymark_bionic_reading_font' ) );
	}

	/** A font already chosen on the new setting is never replaced by the old one. */
	public function test_migration_does_not_replace_a_chosen_font(): void {
		$this->install_font( 'Bionic Reading', 'bionic-reading', '"Bionic Reading"' );
		$this->install_font( 'Inter', 'inter', 'Inter' );
		update_option( Daymark_Settings::APP_FONT, 'inter' );
		update_option( 'daymark_bionic_reading_font', '1' );

		Daymark_Settings::migrate_bionic_reading_font();

		$this->assertSame( 'inter', get_option( Daymark_Settings::APP_FONT ) );
	}

	/** The move runs by itself when WordPress loads, with nobody visiting Settings. */
	public function test_migration_is_hooked_on_init(): void {
		$this->assertSame( 5, has_action( 'init', array( 'Daymark_Settings', 'migrate_bionic_reading_font' ) ) );
	}
}
