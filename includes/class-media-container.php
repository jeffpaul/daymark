<?php
/**
 * Tells an audio-only MP4 or WebM file from a video one.
 *
 * A browser's recorder (MediaRecorder) saves sound in the same containers
 * video uses, and finfo names a file by its container (video/mp4,
 * video/webm), never by what's inside it. This reads the container's list
 * of tracks, seeking over everything else, so a recording can be stored as
 * audio. Only the few header bytes it needs are ever read.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the kinds of track in an MP4 or WebM file.
 */
class Daymark_Media_Container {

	/**
	 * Most boxes or elements visited before giving up on a file.
	 *
	 * @var int
	 */
	private const MAX_STEPS = 10000;

	/**
	 * Most bytes of a WebM Tracks element read into memory. A track list is
	 * normally a few hundred bytes.
	 *
	 * @var int
	 */
	private const MAX_TRACKS_BYTES = 1048576;

	/**
	 * EBML element IDs (WebM), with their marker bits, as written.
	 */
	private const EBML_HEADER = 0x1A45DFA3;
	private const SEGMENT     = 0x18538067;
	private const TRACKS      = 0x1654AE6B;
	private const CLUSTER     = 0x1F43B675;
	private const TRACK_ENTRY = 0xAE;
	private const TRACK_TYPE  = 0x83;

	/**
	 * Whether a file has sound and no picture.
	 *
	 * @param string $path      A readable file.
	 * @param string $container The container finfo reported: 'video/mp4' or 'video/webm'.
	 * @return bool True only when the whole track list was read and it has
	 *              an audio track and no video track.
	 */
	public static function is_audio_only( string $path, string $container ): bool {
		$kinds = self::track_kinds( $path, $container );

		return null !== $kinds && in_array( 'audio', $kinds, true ) && ! in_array( 'video', $kinds, true );
	}

	/**
	 * The kind of each track in an MP4 or WebM file.
	 *
	 * @param string $path      A readable local file (a stream wrapper path,
	 *                          such as a URL, is refused).
	 * @param string $container The container finfo reported: 'video/mp4' or 'video/webm'.
	 * @return string[]|null 'audio', 'video', or 'other' for each track, or
	 *                       null when the track list couldn't be read in full.
	 */
	public static function track_kinds( string $path, string $container ): ?array {
		if ( ! in_array( $container, array( 'video/mp4', 'video/webm' ), true ) || '' === $path || wp_is_stream( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$size = (int) filesize( $path );

		if ( $size < 16 ) {
			return null;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Seeks over media data; only header bytes are read.

		if ( false === $handle ) {
			return null;
		}

		try {
			return 'video/mp4' === $container ? self::mp4_track_kinds( $handle, $size ) : self::webm_track_kinds( $handle, $size );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() above.
		}
	}

	/**
	 * Track kinds of an MP4 (ISO base media) file, from each track's
	 * handler: moov > trak > mdia > hdlr.
	 *
	 * @param resource $handle Open file.
	 * @param int      $size   File size in bytes.
	 * @return string[]|null
	 */
	private static function mp4_track_kinds( $handle, int $size ): ?array {
		$steps = 0;
		$moov  = self::mp4_find( $handle, 0, $size, 'moov', $steps );

		if ( null === $moov ) {
			return null;
		}

		$kinds  = array();
		$offset = $moov[0];

		while ( $offset < $moov[1] ) {
			$box = self::mp4_box( $handle, $offset, $moov[1], $steps );

			if ( null === $box ) {
				return null;
			}

			if ( 'trak' === $box['type'] ) {
				$mdia = self::mp4_find( $handle, $box['start'], $box['end'], 'mdia', $steps );
				$hdlr = null === $mdia ? null : self::mp4_find( $handle, $mdia[0], $mdia[1], 'hdlr', $steps );

				// Version and flags (4 bytes), pre_defined (4), handler type (4).
				$handler = null === $hdlr || $hdlr[1] - $hdlr[0] < 12 ? '' : substr( self::read( $handle, $hdlr[0], 12 ), 8, 4 );

				if ( 'soun' === $handler ) {
					$kinds[] = 'audio';
				} elseif ( 'vide' === $handler ) {
					$kinds[] = 'video';
				} else {
					$kinds[] = 'other';
				}
			}

			$offset = $box['end'];
		}

		return empty( $kinds ) ? null : $kinds;
	}

	/**
	 * The first box of a type in a range, by its payload's start and end.
	 *
	 * Stops at the first box that runs past the range, so a file cut short
	 * (the first part of an upload) finds a box only if it's complete.
	 *
	 * @param resource $handle Open file.
	 * @param int      $start  Range start offset.
	 * @param int      $end    Range end offset.
	 * @param string   $type   Four-character box type.
	 * @param int      $steps  Boxes visited so far, shared across calls.
	 * @return array{0: int, 1: int}|null
	 */
	private static function mp4_find( $handle, int $start, int $end, string $type, int &$steps ): ?array {
		$offset = $start;

		while ( $offset < $end ) {
			$box = self::mp4_box( $handle, $offset, $end, $steps );

			if ( null === $box ) {
				return null;
			}

			if ( $type === $box['type'] ) {
				return array( $box['start'], $box['end'] );
			}

			$offset = $box['end'];
		}

		return null;
	}

	/**
	 * One MP4 box header at an offset.
	 *
	 * @param resource $handle Open file.
	 * @param int      $offset Box start.
	 * @param int      $end    End of the range the box must fit in.
	 * @param int      $steps  Boxes visited so far.
	 * @return array{type: string, start: int, end: int}|null Null when the
	 *         box is broken, runs past the range, or too many were visited.
	 */
	private static function mp4_box( $handle, int $offset, int $end, int &$steps ): ?array {
		++$steps;

		if ( $steps > self::MAX_STEPS || $offset + 8 > $end ) {
			return null;
		}

		$header = self::read( $handle, $offset, 8 );

		if ( 8 !== strlen( $header ) ) {
			return null;
		}

		$box_size    = (int) unpack( 'N', substr( $header, 0, 4 ) )[1];
		$header_size = 8;

		if ( 1 === $box_size ) {
			// A 64-bit size follows the type.
			$large = self::read( $handle, $offset + 8, 8 );

			if ( 8 !== strlen( $large ) ) {
				return null;
			}

			$box_size    = (int) unpack( 'J', $large )[1];
			$header_size = 16;
		} elseif ( 0 === $box_size ) {
			// The box runs to the end of the file.
			$box_size = $end - $offset;
		}

		if ( $box_size < $header_size || $box_size > $end - $offset ) {
			return null;
		}

		return array(
			'type'  => substr( $header, 4, 4 ),
			'start' => $offset + $header_size,
			'end'   => $offset + $box_size,
		);
	}

	/**
	 * Track kinds of a WebM (Matroska) file, from each TrackEntry's
	 * TrackType: EBML header, then Segment > Tracks > TrackEntry > TrackType.
	 *
	 * The track list comes before the first Cluster of media; a recorder
	 * writes the Segment and its Clusters with an unknown size, so the walk
	 * stops at the first Cluster or at any other element it can't skip.
	 *
	 * @param resource $handle Open file.
	 * @param int      $size   File size in bytes.
	 * @return string[]|null
	 */
	private static function webm_track_kinds( $handle, int $size ): ?array {
		$header = self::ebml_element_at( $handle, 0, $size );

		if ( null === $header || self::EBML_HEADER !== $header['id'] || null === $header['size'] ) {
			return null;
		}

		$segment = self::ebml_element_at( $handle, $header['data'] + $header['size'], $size );

		if ( null === $segment || self::SEGMENT !== $segment['id'] ) {
			return null;
		}

		$end    = null === $segment['size'] ? $size : min( $size, $segment['data'] + $segment['size'] );
		$offset = $segment['data'];

		for ( $steps = 0; $offset < $end && $steps < self::MAX_STEPS; $steps++ ) {
			$element = self::ebml_element_at( $handle, $offset, $end );

			if ( null === $element || self::CLUSTER === $element['id'] || null === $element['size'] ) {
				return null;
			}

			if ( self::TRACKS === $element['id'] ) {
				if ( $element['size'] > self::MAX_TRACKS_BYTES || $element['data'] + $element['size'] > $end ) {
					return null;
				}

				$tracks = self::read( $handle, $element['data'], $element['size'] );

				return strlen( $tracks ) === $element['size'] ? self::webm_tracks( $tracks ) : null;
			}

			$offset = $element['data'] + $element['size'];
		}

		return null;
	}

	/**
	 * Track kinds from the payload of a WebM Tracks element.
	 *
	 * @param string $bytes The Tracks element's payload.
	 * @return string[]|null
	 */
	private static function webm_tracks( string $bytes ): ?array {
		$kinds  = array();
		$end    = strlen( $bytes );
		$offset = 0;

		for ( $steps = 0; $offset < $end && $steps < self::MAX_STEPS; $steps++ ) {
			$entry = self::ebml_element( $bytes, $offset );

			if ( null === $entry || null === $entry['size'] || $entry['data'] + $entry['size'] > $end ) {
				return null;
			}

			if ( self::TRACK_ENTRY === $entry['id'] ) {
				$kinds[] = self::webm_track_kind( substr( $bytes, $entry['data'], $entry['size'] ) );
			}

			$offset = $entry['data'] + $entry['size'];
		}

		return empty( $kinds ) || $offset < $end ? null : $kinds;
	}

	/**
	 * The kind of one WebM track, from its TrackType (1 video, 2 audio).
	 *
	 * @param string $bytes A TrackEntry element's payload.
	 * @return string 'audio', 'video', or 'other'.
	 */
	private static function webm_track_kind( string $bytes ): string {
		$end    = strlen( $bytes );
		$offset = 0;

		for ( $steps = 0; $offset < $end && $steps < self::MAX_STEPS; $steps++ ) {
			$element = self::ebml_element( $bytes, $offset );

			if ( null === $element || null === $element['size'] || $element['data'] + $element['size'] > $end ) {
				return 'other';
			}

			if ( self::TRACK_TYPE === $element['id'] && $element['size'] >= 1 && $element['size'] <= 8 ) {
				$type = 0;

				for ( $i = 0; $i < $element['size']; $i++ ) {
					$type = ( $type << 8 ) | ord( $bytes[ $element['data'] + $i ] );
				}

				if ( 1 === $type ) {
					return 'video';
				}

				return 2 === $type ? 'audio' : 'other';
			}

			$offset = $element['data'] + $element['size'];
		}

		return 'other';
	}

	/**
	 * One EBML element header in a file.
	 *
	 * @param resource $handle Open file.
	 * @param int      $offset Element start.
	 * @param int      $end    End of the range the header must fit in.
	 * @return array{id: int, size: int|null, data: int}|null
	 */
	private static function ebml_element_at( $handle, int $offset, int $end ): ?array {
		$bytes = self::read( $handle, $offset, min( 12, $end - $offset ) );
		$found = self::ebml_element( $bytes, 0 );

		if ( null === $found ) {
			return null;
		}

		$found['data'] += $offset;

		return $found;
	}

	/**
	 * One EBML element header in a string: an ID (1 to 4 bytes, marker
	 * bits kept) and a size (1 to 8 bytes, marker bit dropped; all ones
	 * means the size is unknown).
	 *
	 * @param string $bytes  Bytes holding the header.
	 * @param int    $offset Header start in $bytes.
	 * @return array{id: int, size: int|null, data: int}|null 'data' is where
	 *         the payload starts in $bytes; 'size' is null when unknown.
	 */
	private static function ebml_element( string $bytes, int $offset ): ?array {
		$available = strlen( $bytes ) - $offset;

		if ( $available < 2 ) {
			return null;
		}

		$id_length = self::vint_length( ord( $bytes[ $offset ] ) );

		if ( $id_length < 1 || $id_length > 4 || $available < $id_length + 1 ) {
			return null;
		}

		$id = 0;

		for ( $i = 0; $i < $id_length; $i++ ) {
			$id = ( $id << 8 ) | ord( $bytes[ $offset + $i ] );
		}

		$first       = ord( $bytes[ $offset + $id_length ] );
		$size_length = self::vint_length( $first );

		if ( $size_length < 1 || $available < $id_length + $size_length ) {
			return null;
		}

		$mask     = 0xFF >> $size_length;
		$size     = $first & $mask;
		$all_ones = $size === $mask;

		for ( $i = 1; $i < $size_length; $i++ ) {
			$byte     = ord( $bytes[ $offset + $id_length + $i ] );
			$all_ones = $all_ones && 0xFF === $byte;
			$size     = ( $size << 8 ) | $byte;
		}

		return array(
			'id'   => $id,
			'size' => $all_ones ? null : $size,
			'data' => $offset + $id_length + $size_length,
		);
	}

	/**
	 * The length of an EBML variable-size integer, from its first byte: one
	 * plus the number of leading zero bits.
	 *
	 * @param int $first_byte The integer's first byte.
	 * @return int 1 to 8, or 0 when the byte is zero (not a valid start).
	 */
	private static function vint_length( int $first_byte ): int {
		for ( $length = 1; $length <= 8; $length++ ) {
			if ( $first_byte & ( 0x80 >> ( $length - 1 ) ) ) {
				return $length;
			}
		}

		return 0;
	}

	/**
	 * Bytes from a file at an offset.
	 *
	 * @param resource $handle Open file.
	 * @param int      $offset Where to start.
	 * @param int      $length How many bytes.
	 * @return string Fewer bytes than asked at the end of the file; '' on error.
	 */
	private static function read( $handle, int $offset, int $length ): string {
		if ( $length <= 0 || $offset < 0 || 0 !== fseek( $handle, $offset ) ) {
			return '';
		}

		$bytes = fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads a few header bytes at a time.

		return false === $bytes ? '' : $bytes;
	}
}
