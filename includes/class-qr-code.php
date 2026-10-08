<?php
/**
 * A small QR code encoder, so wp-admin can show the app's address as a code
 * a phone camera can scan.
 *
 * Generated on the server, with no library and no online QR service: an
 * online service would receive the site's address, and a library would be
 * the plugin's only bundled third-party code. It covers what the app's
 * address needs: byte mode (UTF-8), error correction level M, and versions
 * 1 to 15 (up to 412 bytes). The algorithm follows ISO/IEC 18004, as laid
 * out in Project Nayuki's public QR code generator.
 *
 * @package Daymark
 * @since   0.20.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * QR code encoder with SVG output.
 */
final class Daymark_QR_Code {

	/**
	 * Error correction blocks for level M, by version: EC codewords per
	 * block, then [block count, data codewords per block] for each group.
	 */
	private const BLOCKS_M = array(
		1  => array( 10, array( array( 1, 16 ) ) ),
		2  => array( 16, array( array( 1, 28 ) ) ),
		3  => array( 26, array( array( 1, 44 ) ) ),
		4  => array( 18, array( array( 2, 32 ) ) ),
		5  => array( 24, array( array( 2, 43 ) ) ),
		6  => array( 16, array( array( 4, 27 ) ) ),
		7  => array( 18, array( array( 4, 31 ) ) ),
		8  => array( 22, array( array( 2, 38 ), array( 2, 39 ) ) ),
		9  => array( 22, array( array( 3, 36 ), array( 2, 37 ) ) ),
		10 => array( 26, array( array( 4, 43 ), array( 1, 44 ) ) ),
		11 => array( 30, array( array( 1, 50 ), array( 4, 51 ) ) ),
		12 => array( 22, array( array( 6, 36 ), array( 2, 37 ) ) ),
		13 => array( 22, array( array( 8, 37 ), array( 1, 38 ) ) ),
		14 => array( 24, array( array( 4, 40 ), array( 5, 41 ) ) ),
		15 => array( 24, array( array( 5, 41 ), array( 5, 42 ) ) ),
	);

	/**
	 * Alignment pattern centre coordinates, by version.
	 */
	private const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
		11 => array( 6, 30, 54 ),
		12 => array( 6, 32, 58 ),
		13 => array( 6, 34, 62 ),
		14 => array( 6, 26, 46, 66 ),
		15 => array( 6, 26, 48, 70 ),
	);

	/**
	 * Module grid, [row][column], true for dark.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private array $modules = array();

	/**
	 * Which modules belong to fixed patterns, so data and masks skip them.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private array $is_function = array();

	/**
	 * Width and height in modules.
	 *
	 * @var int
	 */
	private int $size = 0;

	/**
	 * Encode text as a QR code.
	 *
	 * @param string $text Text to encode, as UTF-8.
	 * @return array<int, array<int, bool>>|null Module grid, [row][column] with true for dark, or null when the text is too long.
	 */
	public static function encode( string $text ): ?array {
		$bytes   = array_values( unpack( 'C*', $text ) ? unpack( 'C*', $text ) : array() );
		$version = self::pick_version( count( $bytes ) );

		if ( null === $version ) {
			return null;
		}

		$qr = new self();

		return $qr->build( $version, $bytes );
	}

	/**
	 * Render text as an SVG QR code: black modules on white, with the
	 * standard four-module quiet zone.
	 *
	 * @param string $text  Text to encode.
	 * @param int    $size  Width and height in pixels.
	 * @param string $label Accessible name for the image.
	 * @return string SVG markup, or '' when the text is too long.
	 */
	public static function svg( string $text, int $size, string $label ): string {
		$grid = self::encode( $text );

		if ( null === $grid ) {
			return '';
		}

		$count = count( $grid );
		$view  = $count + 8;
		$path  = '';

		foreach ( $grid as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z';
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" role="img" aria-label="%3$s" shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/><path fill="#000" d="%4$s"/></svg>',
			$view,
			$size,
			esc_attr( $label ),
			$path
		);
	}

	/**
	 * The smallest version whose level-M capacity fits the data.
	 *
	 * @param int $length Data length in bytes.
	 * @return int|null
	 */
	private static function pick_version( int $length ): ?int {
		foreach ( self::BLOCKS_M as $version => $spec ) {
			$count_bits = $version < 10 ? 8 : 16;

			if ( 4 + $count_bits + 8 * $length <= 8 * self::data_codewords( $version ) ) {
				return $version;
			}
		}

		return null;
	}

	/**
	 * Data codewords a version holds at level M.
	 *
	 * @param int $version Version.
	 * @return int
	 */
	private static function data_codewords( int $version ): int {
		$total = 0;

		foreach ( self::BLOCKS_M[ $version ][1] as $group ) {
			$total += $group[0] * $group[1];
		}

		return $total;
	}

	/**
	 * Build the module grid.
	 *
	 * @param int   $version Version.
	 * @param int[] $bytes   Data bytes.
	 * @return array<int, array<int, bool>>
	 */
	private function build( int $version, array $bytes ): array {
		$this->size        = 4 * $version + 17;
		$this->modules     = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->is_function = $this->modules;

		$this->draw_function_patterns( $version );
		$this->draw_codewords( $this->codewords( $version, $bytes ) );

		$best_mask    = 0;
		$best_penalty = PHP_INT_MAX;

		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $mask );
			$penalty = $this->penalty();

			if ( $penalty < $best_penalty ) {
				$best_mask    = $mask;
				$best_penalty = $penalty;
			}

			$this->apply_mask( $mask ); // Masking twice undoes it.
		}

		$this->apply_mask( $best_mask );
		$this->draw_format_bits( $best_mask );

		return $this->modules;
	}

	/**
	 * Data plus error correction codewords, interleaved by block.
	 *
	 * @param int   $version Version.
	 * @param int[] $bytes   Data bytes.
	 * @return int[]
	 */
	private function codewords( int $version, array $bytes ): array {
		$capacity = self::data_codewords( $version );
		$bits     = array();

		$this->append_bits( $bits, 0x4, 4 );
		$this->append_bits( $bits, count( $bytes ), $version < 10 ? 8 : 16 );

		foreach ( $bytes as $byte ) {
			$this->append_bits( $bits, $byte, 8 );
		}

		// Terminator, then pad to a whole byte.
		$this->append_bits( $bits, 0, min( 4, 8 * $capacity - count( $bits ) ) );
		$this->append_bits( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );

		$data = array();

		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$data[] = (int) bindec( implode( '', $chunk ) );
		}

		// Fill the rest with the alternating pad bytes 0xEC and 0x11.
		$pad_count = $capacity - count( $data );

		for ( $i = 0; $i < $pad_count; $i++ ) {
			$data[] = 0 === $i % 2 ? 0xEC : 0x11;
		}

		$ec_length = self::BLOCKS_M[ $version ][0];
		$divisor   = $this->rs_divisor( $ec_length );
		$blocks    = array();
		$offset    = 0;

		foreach ( self::BLOCKS_M[ $version ][1] as $group ) {
			for ( $b = 0; $b < $group[0]; $b++ ) {
				$block    = array_slice( $data, $offset, $group[1] );
				$offset  += $group[1];
				$blocks[] = array( $block, $this->rs_remainder( $block, $divisor ) );
			}
		}

		$result   = array();
		$max_data = max(
			array_map(
				static function ( $block ) {
					return count( $block[0] );
				},
				$blocks
			)
		);

		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( $i < count( $block[0] ) ) {
					$result[] = $block[0][ $i ];
				}
			}
		}

		for ( $i = 0; $i < $ec_length; $i++ ) {
			foreach ( $blocks as $block ) {
				$result[] = $block[1][ $i ];
			}
		}

		return $result;
	}

	/**
	 * Append the low `$length` bits of `$value`, most significant first.
	 *
	 * @param int[] $bits   Bit list, modified in place.
	 * @param int   $value  Value.
	 * @param int   $length Bit count.
	 * @return void
	 */
	private function append_bits( array &$bits, int $value, int $length ): void {
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	/**
	 * Multiply in GF(256) with the QR code polynomial 0x11D.
	 *
	 * @param int $x Factor.
	 * @param int $y Factor.
	 * @return int
	 */
	private function gf_multiply( int $x, int $y ): int {
		$z = 0;

		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}

		return $z;
	}

	/**
	 * Reed-Solomon generator polynomial coefficients for a degree.
	 *
	 * @param int $degree Number of error correction codewords.
	 * @return int[]
	 */
	private function rs_divisor( int $degree ): array {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;

		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = $this->gf_multiply( $result[ $j ], $root );

				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}

			$root = $this->gf_multiply( $root, 0x02 );
		}

		return $result;
	}

	/**
	 * Reed-Solomon error correction codewords for a block.
	 *
	 * @param int[] $data    Data codewords.
	 * @param int[] $divisor Generator from rs_divisor().
	 * @return int[]
	 */
	private function rs_remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );

		foreach ( $data as $byte ) {
			$factor   = $byte ^ array_shift( $result );
			$result[] = 0;

			foreach ( $divisor as $i => $coefficient ) {
				$result[ $i ] ^= $this->gf_multiply( $coefficient, $factor );
			}
		}

		return $result;
	}

	/**
	 * Set a module and mark it as part of a fixed pattern.
	 *
	 * @param int  $x    Column.
	 * @param int  $y    Row.
	 * @param bool $dark Whether the module is dark.
	 * @return void
	 */
	private function set_function( int $x, int $y, bool $dark ): void {
		$this->modules[ $y ][ $x ]     = $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	/**
	 * Draw finder, timing, and alignment patterns, the dark module, and
	 * (from version 7) the version information, and reserve the format
	 * information areas.
	 *
	 * @param int $version Version.
	 * @return void
	 */
	private function draw_function_patterns( int $version ): void {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}

		$this->draw_finder( 3, 3 );
		$this->draw_finder( $this->size - 4, 3 );
		$this->draw_finder( 3, $this->size - 4 );

		$positions = self::ALIGNMENT[ $version ];
		$count     = count( $positions );

		foreach ( $positions as $i => $x ) {
			foreach ( $positions as $j => $y ) {
				$on_finder = ( 0 === $i && 0 === $j ) || ( 0 === $i && $count - 1 === $j ) || ( $count - 1 === $i && 0 === $j );

				if ( ! $on_finder ) {
					$this->draw_alignment( $x, $y );
				}
			}
		}

		// Reserve the format areas; draw_format_bits() fills them later.
		$this->draw_format_bits( 0 );

		if ( $version >= 7 ) {
			$remainder = $version;

			for ( $i = 0; $i < 12; $i++ ) {
				$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 11 ) * 0x1F25 );
			}

			$bits = ( $version << 12 ) | $remainder;

			for ( $i = 0; $i < 18; $i++ ) {
				$dark = 1 === ( ( $bits >> $i ) & 1 );
				$a    = $this->size - 11 + $i % 3;
				$b    = intdiv( $i, 3 );
				$this->set_function( $a, $b, $dark );
				$this->set_function( $b, $a, $dark );
			}
		}
	}

	/**
	 * Draw a finder pattern and its separator, centred on a module.
	 *
	 * @param int $x Centre column.
	 * @param int $y Centre row.
	 * @return void
	 */
	private function draw_finder( int $x, int $y ): void {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$xx = $x + $dx;
				$yy = $y + $dy;

				if ( $xx < 0 || $xx >= $this->size || $yy < 0 || $yy >= $this->size ) {
					continue;
				}

				$distance = max( abs( $dx ), abs( $dy ) );
				$this->set_function( $xx, $yy, 2 !== $distance && 4 !== $distance );
			}
		}
	}

	/**
	 * Draw an alignment pattern centred on a module.
	 *
	 * @param int $x Centre column.
	 * @param int $y Centre row.
	 * @return void
	 */
	private function draw_alignment( int $x, int $y ): void {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				$distance = max( abs( $dx ), abs( $dy ) );
				$this->set_function( $x + $dx, $y + $dy, 1 !== $distance );
			}
		}
	}

	/**
	 * Draw both copies of the format information for level M and a mask.
	 *
	 * @param int $mask Mask pattern, 0 to 7.
	 * @return void
	 */
	private function draw_format_bits( int $mask ): void {
		$data      = ( 0 << 3 ) | $mask; // Level M is 00.
		$remainder = $data;

		for ( $i = 0; $i < 10; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 9 ) * 0x537 );
		}

		$bits = ( ( $data << 10 ) | $remainder ) ^ 0x5412;
		$bit  = static function ( int $i ) use ( $bits ): bool {
			return 1 === ( ( $bits >> $i ) & 1 );
		};

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}

		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );

		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $this->size - 1 - $i, 8, $bit( $i ) );
		}

		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $this->size - 15 + $i, $bit( $i ) );
		}

		$this->set_function( 8, $this->size - 8, true ); // The dark module.
	}

	/**
	 * Place codewords in the zigzag order, skipping fixed patterns.
	 *
	 * @param int[] $codewords Interleaved codewords.
	 * @return void
	 */
	private function draw_codewords( array $codewords ): void {
		$total = 8 * count( $codewords );
		$i     = 0;

		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}

			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $this->size - 1 - $vert : $vert;

					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	/**
	 * Invert the data modules a mask pattern selects. Applying the same
	 * mask twice restores the original.
	 *
	 * @param int $mask Mask pattern, 0 to 7.
	 * @return void
	 */
	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}

				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
						break;
					case 6:
						$invert = 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
					default:
						$invert = 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
				}

				if ( $invert ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * Penalty score for the current grid (the four rules of the standard).
	 * Lower reads more reliably; it only chooses between masks.
	 *
	 * @return int
	 */
	private function penalty(): int {
		$score = 0;
		$n     = $this->size;
		$lines = array();

		for ( $y = 0; $y < $n; $y++ ) {
			$lines[] = $this->modules[ $y ];
		}

		for ( $x = 0; $x < $n; $x++ ) {
			$lines[] = array_column( $this->modules, $x );
		}

		foreach ( $lines as $line ) {
			// Rule 1: runs of five or more modules of one colour.
			$run = 1;

			for ( $i = 1; $i <= $n; $i++ ) {
				if ( $i < $n && $line[ $i ] === $line[ $i - 1 ] ) {
					++$run;
					continue;
				}

				if ( $run >= 5 ) {
					$score += 3 + ( $run - 5 );
				}

				$run = 1;
			}

			// Rule 3: a finder-like 1:1:3:1:1 pattern with four light modules beside it.
			$text   = implode(
				'',
				array_map(
					static function ( $dark ) {
						return $dark ? '1' : '0';
					},
					$line
				)
			);
			$padded = '0000' . $text . '0000';
			$score += 40 * ( substr_count( $padded, '00001011101' ) + substr_count( $padded, '10111010000' ) );
		}

		// Rule 2: 2x2 blocks of one colour.
		for ( $y = 0; $y < $n - 1; $y++ ) {
			for ( $x = 0; $x < $n - 1; $x++ ) {
				$c = $this->modules[ $y ][ $x ];

				if ( $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Rule 4: how far the share of dark modules is from half.
		$dark = 0;

		foreach ( $this->modules as $row ) {
			$dark += count( array_filter( $row ) );
		}

		$score += 10 * intdiv( (int) abs( $dark * 100 / ( $n * $n ) - 50 ), 5 );

		return $score;
	}
}
