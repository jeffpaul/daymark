<?php
/**
 * Translation readiness: every script with strings loads its translations,
 * dates use core's translated names, stored labels are translatable, and
 * the offline-fallback shell keeps the site's language.
 *
 * The string extraction itself (text domains, translator comments) is
 * checked by bin/check-i18n.sh in CI, not here.
 *
 * @package Daymark
 */

/**
 * Translation readiness tests.
 */
class Test_I18n extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'gettext_with_context' );
		remove_all_filters( 'pre_load_script_translations' );
		parent::tear_down();
	}

	/**
	 * Any bundled script that calls wp.i18n with the 'daymark' domain must be
	 * paired with wp_set_script_translations() for its handle. Without it the
	 * script's strings exist in the translation template but always show in
	 * English (the Featured Content editor had exactly this gap).
	 */
	public function test_every_script_with_strings_loads_its_translations() {
		$php_files = array_merge(
			glob( DAYMARK_PLUGIN_DIR . 'includes/*.php' ),
			glob( DAYMARK_PLUGIN_DIR . 'includes/*/*.php' ),
			glob( DAYMARK_PLUGIN_DIR . 'templates/*.php' )
		);

		// The translation call may sit in a different file from a second
		// registration of the same handle (Daymark_Routes::app_locale_data()
		// registers daymark-app again only to find its translation file), so
		// it is looked for across all of the plugin's PHP.
		$all_php = '';
		foreach ( $php_files as $php_file ) {
			$all_php .= (string) file_get_contents( $php_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source.
		}

		$checked = array();

		foreach ( $php_files as $php_file ) {
			$php = (string) file_get_contents( $php_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source.

			preg_match_all(
				"#wp_(?:enqueue|register)_script\(\s*([^,]+?)\s*,\s*DAYMARK_PLUGIN_URL\s*\.\s*'assets/([\w-]+\.js)'#",
				$php,
				$matches,
				PREG_SET_ORDER
			);

			foreach ( $matches as $match ) {
				$handle = $match[1];
				$file   = $match[2];
				$js     = (string) file_get_contents( DAYMARK_PLUGIN_DIR . 'assets/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source.

				if ( ! preg_match( "/\b(?:__|_n|_x|_nx)\([^;]*'daymark'\s*\)/s", $js ) ) {
					continue;
				}

				$this->assertMatchesRegularExpression(
					'/wp_set_script_translations\(\s*' . preg_quote( $handle, '/' ) . '\s*,\s*\'daymark\'\s*\)/',
					$all_php,
					sprintf( 'assets/%s has translatable strings (registered in %s) but nothing calls wp_set_script_translations( %s, \'daymark\' ).', $file, basename( $php_file ), $handle )
				);

				$checked[] = $file;
			}
		}

		// Guards the scan itself: if these stop being found, the regex broke.
		foreach ( array( 'app.js', 'featured-content-editor.js', 'note-length-editor.js', 'blogroll-block.js' ) as $file ) {
			$this->assertContains( $file, $checked, "Expected the scan to find assets/{$file}." );
		}
	}

	/** Month and weekday names come from core's $wp_locale, already translated by WordPress. */
	public function test_date_names_come_from_core_locale() {
		global $wp_locale;

		$names = Daymark_Routes::date_names();

		$this->assertCount( 12, $names['months'] );
		$this->assertCount( 12, $names['monthsShort'] );
		$this->assertCount( 7, $names['weekdays'] );
		$this->assertCount( 7, $names['weekdaysShort'] );
		$this->assertSame( $wp_locale->get_month( 1 ), $names['months'][0], 'Index 0 is January, matching Date#getMonth()' );
		$this->assertSame( $wp_locale->get_weekday( 0 ), $names['weekdays'][0], 'Index 0 is Sunday, matching Date#getDay()' );
		$this->assertSame( $wp_locale->get_month_abbrev( $wp_locale->get_month( 5 ) ), $names['monthsShort'][4] );

		$this->assertSame( $names, Daymark_Routes::build_app_config()['dateNames'] );
	}

	/** The weather label stored on a Mark is translated, with context, into the site's language. */
	public function test_weather_condition_label_is_translatable() {
		add_filter(
			'gettext_with_context',
			static function ( $translation, $text, $context, $domain ) {
				return ( 'daymark' === $domain && 'weather' === $context && 'Clear' === $text ) ? 'Dégagé' : $translation;
			},
			10,
			4
		);

		$method = new ReflectionMethod( 'Daymark_Publisher', 'weather_condition_label' );

		$this->assertSame( 'Dégagé', $method->invoke( null, 0 ) );
		$this->assertSame( 'Thunderstorm', $method->invoke( null, 99 ) );
		$this->assertSame( '—', $method->invoke( null, 12345 ), 'An unmapped code keeps the placeholder' );
	}

	/** With no translation file (an English site), config.json sends no locale data. */
	public function test_app_locale_data_is_null_without_translations() {
		add_filter( 'pre_load_script_translations', '__return_false' );

		$this->assertNull( Daymark_Routes::app_locale_data() );
	}

	/** With a translation file, config.json sends it in the shape wp.i18n.setLocaleData() takes, under the 'daymark' domain. */
	public function test_app_locale_data_uses_the_daymark_domain() {
		add_filter(
			'pre_load_script_translations',
			static function ( $translations, $file, $handle, $domain ) {
				if ( 'daymark-app' !== $handle || 'daymark' !== $domain ) {
					return $translations;
				}

				return wp_json_encode(
					array(
						'domain'      => 'messages',
						'locale_data' => array(
							'messages' => array(
								''         => array(
									'domain'       => 'messages',
									'lang'         => 'fr',
									'plural-forms' => 'nplurals=2; plural=(n > 1);',
								),
								'Just now' => array( "À l'instant" ),
							),
						),
					)
				);
			},
			10,
			4
		);

		$data = Daymark_Routes::app_locale_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'daymark', $data['']['domain'] );
		$this->assertSame( 'nplurals=2; plural=(n > 1);', $data['']['plural-forms'] );
		$this->assertSame( array( "À l'instant" ), $data['Just now'] );
	}

	/**
	 * The offline shell loads core's wp-hooks, then wp-i18n, before its boot
	 * script, so app.js finds wp.i18n there too, and the worker caches both
	 * so they load with no connection.
	 */
	public function test_offline_shell_loads_and_worker_caches_core_i18n() {
		$urls = Daymark_Routes::offline_core_script_urls();

		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'hooks', $urls[0] );
		$this->assertStringContainsString( 'i18n', $urls[1] );

		unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
		ob_start();
		include DAYMARK_PLUGIN_DIR . 'templates/offline-shell.php';
		$html = (string) ob_get_clean();

		$hooks = strpos( $html, esc_url( $urls[0] ) );
		$i18n  = strpos( $html, esc_url( $urls[1] ) );
		$boot  = strpos( $html, 'offline-boot.js' );

		$this->assertNotFalse( $hooks );
		$this->assertNotFalse( $i18n );
		$this->assertLessThan( $i18n, $hooks, 'wp-hooks loads before wp-i18n' );
		$this->assertLessThan( $boot, $i18n, 'wp-i18n loads before the boot script' );
		$this->assertStringNotContainsString( 'setLocaleData', $html, 'No inline translation script, which this page\'s CSP would block' );

		$script = Daymark_Routes::build_service_worker_script();

		$this->assertStringContainsString( 'const CORE_SCRIPTS = ' . wp_json_encode( $urls, JSON_UNESCAPED_SLASHES ) . ';', $script );
	}

	/** offline-boot.js hands config.json's translations to wp.i18n before app.js runs. */
	public function test_offline_boot_sets_locale_data() {
		$js = (string) file_get_contents( DAYMARK_PLUGIN_DIR . 'assets/offline-boot.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source.

		$this->assertStringContainsString( "wp.i18n.setLocaleData(configData.localeData, 'daymark')", $js );
	}
}
