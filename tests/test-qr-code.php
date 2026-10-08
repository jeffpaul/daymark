<?php
/**
 * Daymark_QR_Code: the QR code of the app's address shown in wp-admin.
 *
 * PHP has no QR decoder to check against, so these tests check the
 * structure every QR code must have, and pin three codes by checksum. Those
 * three, and codes for every version from 1 to 15 with each of the 8 masks
 * forced, were decoded with the jsQR library when the encoder was written;
 * a checksum change means the output changed and needs decoding again.
 *
 * @package Daymark
 */

/**
 * QR code coverage.
 */
class Test_QR_Code extends WP_UnitTestCase {

	/**
	 * A grid as rows of '1' (dark) and '0' (light).
	 *
	 * @param array<int, array<int, bool>> $grid Module grid.
	 * @return string
	 */
	private function grid_text( array $grid ): string {
		return implode(
			"\n",
			array_map(
				static function ( $row ) {
					return implode(
						'',
						array_map(
							static function ( $dark ) {
								return $dark ? '1' : '0';
							},
							$row
						)
					);
				},
				$grid
			)
		);
	}

	/**
	 * Read the 15 format bits from their first copy, as the encoder lays
	 * them out.
	 *
	 * @param array<int, array<int, bool>> $grid Module grid.
	 * @return int
	 */
	private function format_bits( array $grid ): int {
		$positions = array();

		for ( $i = 0; $i <= 5; $i++ ) {
			$positions[ $i ] = array( 8, $i );
		}

		$positions[6] = array( 8, 7 );
		$positions[7] = array( 8, 8 );
		$positions[8] = array( 7, 8 );

		for ( $i = 9; $i < 15; $i++ ) {
			$positions[ $i ] = array( 14 - $i, 8 );
		}

		$bits = 0;

		foreach ( $positions as $i => $xy ) {
			if ( $grid[ $xy[1] ][ $xy[0] ] ) {
				$bits |= 1 << $i;
			}
		}

		return $bits;
	}

	/** The version grows with the data: 27 bytes needs version 3 (29 modules) at level M. */
	public function test_picks_smallest_version(): void {
		$this->assertCount( 21, Daymark_QR_Code::encode( 'https://a.b/c' ) );
		$this->assertCount( 29, Daymark_QR_Code::encode( 'https://example.com/daymark' ) );
	}

	/** Text longer than version 15 holds at level M (412 bytes) gets no code. */
	public function test_too_long_returns_null(): void {
		$this->assertNotNull( Daymark_QR_Code::encode( str_repeat( 'x', 412 ) ) );
		$this->assertNull( Daymark_QR_Code::encode( str_repeat( 'x', 413 ) ) );
		$this->assertSame( '', Daymark_QR_Code::svg( str_repeat( 'x', 413 ), 128, 'Code' ) );
	}

	/** The three finder patterns sit in their corners. */
	public function test_finder_patterns(): void {
		$grid = Daymark_QR_Code::encode( 'https://example.com/daymark' );
		$n    = count( $grid );

		foreach ( array( array( 0, 0 ), array( $n - 7, 0 ), array( 0, $n - 7 ) ) as $corner ) {
			list( $x0, $y0 ) = $corner;

			for ( $dy = 0; $dy < 7; $dy++ ) {
				for ( $dx = 0; $dx < 7; $dx++ ) {
					$ring = max( abs( $dx - 3 ), abs( $dy - 3 ) );
					$this->assertSame( 2 !== $ring, $grid[ $y0 + $dy ][ $x0 + $dx ], "Finder module at {$x0}+{$dx},{$y0}+{$dy}" );
				}
			}
		}
	}

	/** The format bits are a valid BCH codeword for level M. */
	public function test_format_bits_are_level_m(): void {
		$bits = $this->format_bits( Daymark_QR_Code::encode( 'https://example.com/daymark' ) ) ^ 0x5412;
		$data = $bits >> 10;

		$this->assertSame( 0, $data >> 3, 'Error correction level M is 00.' );

		$remainder = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 9 ) * 0x537 );
		}
		$this->assertSame( ( $data << 10 ) | $remainder, $bits );
	}

	/** Codes decoded with jsQR when the encoder was written stay byte-identical. */
	public function test_output_matches_verified_codes(): void {
		$expected = array(
			'https://example.com/daymark'    => '443a1e0968931e6d6378b01cadbfdcc2',
			'https://wp71.local/daymark-app' => '674b0ec5520486875fbf73adbb02ce05',
			str_repeat( 'https://example.com/a-long-path/', 5 ) => '10da81231bc066594180f2ffd5142fcb',
		);

		foreach ( $expected as $text => $hash ) {
			$this->assertSame( $hash, md5( $this->grid_text( Daymark_QR_Code::encode( $text ) ) ), $text );
		}
	}

	/** The SVG has the quiet zone, a white background, and an escaped accessible name. */
	public function test_svg_markup(): void {
		$svg = Daymark_QR_Code::svg( 'https://example.com/daymark', 128, 'Code "for" <app>' );

		$this->assertStringContainsString( 'viewBox="0 0 37 37"', $svg );
		$this->assertStringContainsString( 'width="128" height="128"', $svg );
		$this->assertStringContainsString( 'role="img"', $svg );
		$this->assertStringContainsString( 'aria-label="Code &quot;for&quot; &lt;app&gt;"', $svg );
		$this->assertStringContainsString( '<rect width="37" height="37" fill="#fff"/>', $svg );
	}

	/** The welcome notice and the General tab both show the app's QR code. */
	public function test_code_shown_in_notice_and_general_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Daymark_Admin_Welcome::OPTION, '1' );

		ob_start();
		( new Daymark_Admin_Welcome() )->render_notice();
		$notice = (string) ob_get_clean();

		$_GET['tab'] = 'general';
		ob_start();
		( new Daymark_Admin_Subscriptions() )->render_page();
		$general = (string) ob_get_clean();
		unset( $_GET['tab'] );
		delete_option( Daymark_Admin_Welcome::OPTION );

		$this->assertStringContainsString( '<svg', $notice );
		$this->assertStringContainsString( 'QR code for the Daymark app address', $notice );
		$this->assertStringContainsString( 'Open Daymark on your phone', $general );
		$this->assertStringContainsString( '<svg', $general );
		$this->assertStringContainsString( esc_html( Daymark_Routes::app_url() ), $general );
	}
}
