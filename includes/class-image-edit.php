<?php
/**
 * Crop and rotate for a Mark's photos.
 *
 * The composer's photo sheet offers a shape (Original, Square, Tall, Wide,
 * cut from the middle) and Rotate. The app only previews the result; every
 * Mark save names the edit each photo should have, and the edit is made
 * here with WordPress's own image editor, the way the Media Library's
 * "Edit Image" does it: a new file beside the original, new sizes, and the
 * original kept (and listed in `_wp_attachment_backup_sizes`, so "Restore
 * original image" in the Media Library still works). The photo's date and
 * camera details are carried over.
 *
 * An edit always starts from the file the photo had before Daymark's first
 * edit, never from an earlier edit, so changing the shape twice doesn't
 * crop twice. See the "Crop and rotate in the photo sheet" decision row.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the photo sheet's crop and rotate on the site.
 */
class Daymark_Image_Edit {

	/**
	 * Attachment meta: the edit Daymark made, the file it made, and the
	 * attachment metadata from before it (what Original puts back).
	 *
	 * @var string
	 */
	public const META = '_daymark_image_edit';

	/**
	 * Width divided by height for each shape. Original has none.
	 *
	 * @var array<string, float>
	 */
	private const RATIOS = array(
		'square' => 1.0,
		'tall'   => 4 / 5,
		'wide'   => 16 / 9,
	);

	/**
	 * Photo types that can be edited when the server's image editor can
	 * read, turn, crop, and save them. A GIF is left out: an animated one
	 * would come back as a single frame.
	 *
	 * @var string[]
	 */
	private const CANDIDATE_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	/**
	 * Tidy one edit to a known shape and a clockwise quarter turn.
	 *
	 * @param mixed $raw An edit: `shape` and `rotate` (degrees clockwise).
	 * @return array{shape: string, rotate: int}
	 */
	public static function sanitize( $raw ): array {
		$edit = array(
			'shape'  => 'original',
			'rotate' => 0,
		);

		if ( ! is_array( $raw ) ) {
			return $edit;
		}

		$shape = sanitize_key( (string) ( $raw['shape'] ?? '' ) );

		if ( isset( self::RATIOS[ $shape ] ) ) {
			$edit['shape'] = $shape;
		}

		$rotate = $raw['rotate'] ?? 0;
		$rotate = is_numeric( $rotate ) ? (int) $rotate : 0;

		if ( 0 === $rotate % 90 ) {
			$edit['rotate'] = ( ( $rotate % 360 ) + 360 ) % 360;
		}

		return $edit;
	}

	/**
	 * The part of a photo a shape keeps: the largest box of that shape,
	 * from the middle.
	 *
	 * @param int    $width  The photo's width.
	 * @param int    $height The photo's height.
	 * @param string $shape  A shape.
	 * @return int[]|null x, y, width, height; null when nothing is cut.
	 */
	public static function crop_box( int $width, int $height, string $shape ): ?array {
		if ( ! isset( self::RATIOS[ $shape ] ) || $width <= 0 || $height <= 0 ) {
			return null;
		}

		$ratio = self::RATIOS[ $shape ];

		if ( $width / $height > $ratio ) {
			$new_width  = max( 1, (int) round( $height * $ratio ) );
			$new_height = $height;
		} else {
			$new_width  = $width;
			$new_height = max( 1, (int) round( $width / $ratio ) );
		}

		if ( $new_width >= $width && $new_height >= $height ) {
			return null;
		}

		return array(
			(int) floor( ( $width - $new_width ) / 2 ),
			(int) floor( ( $height - $new_height ) / 2 ),
			$new_width,
			$new_height,
		);
	}

	/**
	 * The photo types this site can edit.
	 *
	 * @return string[]
	 */
	public static function editable_types(): array {
		return array_values(
			array_filter(
				self::CANDIDATE_TYPES,
				static function ( string $mime ): bool {
					return wp_image_editor_supports(
						array(
							'mime_type' => $mime,
							'methods'   => array( 'rotate', 'crop', 'save' ),
						)
					);
				}
			)
		);
	}

	/**
	 * What the app needs to know: which picked photos get the controls.
	 *
	 * @return array{types: string[]}
	 */
	public static function app_config(): array {
		return array( 'types' => self::editable_types() );
	}

	/**
	 * Whether an attachment is a photo this site can edit.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function can_edit( int $attachment_id ): bool {
		return wp_attachment_is_image( $attachment_id )
			&& in_array( (string) get_post_mime_type( $attachment_id ), self::editable_types(), true );
	}

	/**
	 * A photo's edit, for the composer: whether it can be edited, and when
	 * Daymark has edited it, that edit and the address of the picture from
	 * before it (which the app previews other shapes from).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{editable: bool, edit?: array{shape: string, rotate: int}, original?: string}
	 */
	public static function describe( int $attachment_id ): array {
		$described = array( 'editable' => self::can_edit( $attachment_id ) );
		$applied   = self::applied( $attachment_id );

		if ( null === $applied ) {
			return $described;
		}

		$described['edit'] = array(
			'shape'  => $applied['shape'],
			'rotate' => $applied['rotate'],
		);

		// A size big enough for the sheet's preview, else the file itself.
		$before = $applied['before'];
		$file   = wp_basename( (string) $before['file'] );

		foreach ( array( 'large', 'medium_large' ) as $size ) {
			if ( ! empty( $before['sizes'][ $size ]['file'] ) ) {
				$file = (string) $before['sizes'][ $size ]['file'];
				break;
			}
		}

		$folder = dirname( (string) $before['file'] );
		$folder = '.' === $folder ? '' : trailingslashit( $folder );

		$described['original'] = esc_url_raw( trailingslashit( wp_get_upload_dir()['baseurl'] ) . $folder . $file );

		return $described;
	}

	/**
	 * Make the edits a Mark save names, for that Mark's own photos only.
	 *
	 * A photo the map doesn't name is left as it is. Nothing here ever
	 * stops a save: an edit that can't be made leaves the photo unchanged.
	 *
	 * @param mixed $raw         Edits keyed by attachment ID (array or JSON string).
	 * @param int[] $allowed_ids The Mark's media: the only attachments an edit may touch.
	 * @return void
	 */
	public static function apply_map( $raw, array $allowed_ids ): void {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $raw ) ) {
			return;
		}

		$allowed_ids = array_map( 'intval', $allowed_ids );

		foreach ( $raw as $id => $edit ) {
			$id = absint( $id );

			if ( $id && in_array( $id, $allowed_ids, true ) && current_user_can( 'edit_post', $id ) ) {
				self::apply( $id, self::sanitize( $edit ) );
			}
		}
	}

	/**
	 * Give a photo an edit. Returns whether its file changed.
	 *
	 * @param int                               $attachment_id Attachment ID.
	 * @param array{shape: string, rotate: int} $edit          A tidied edit (see sanitize()).
	 * @return bool
	 */
	public static function apply( int $attachment_id, array $edit ): bool {
		$edit     = self::sanitize( $edit );
		$applied  = self::applied( $attachment_id );
		$no_edit  = 'original' === $edit['shape'] && 0 === $edit['rotate'];
		$same_now = null !== $applied && $applied['shape'] === $edit['shape'] && $applied['rotate'] === $edit['rotate'];

		// Nothing asked of a photo Daymark never edited, or the edit it
		// already has.
		if ( ( null === $applied && $no_edit ) || $same_now ) {
			return false;
		}

		if ( $no_edit ) {
			return self::restore( $attachment_id, $applied );
		}

		if ( ! self::can_edit( $attachment_id ) ) {
			return false;
		}

		$current = (string) get_attached_file( $attachment_id );
		$meta    = wp_get_attachment_metadata( $attachment_id );

		if ( '' === $current || ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return false;
		}

		// Always from the photo as it was before Daymark's first edit.
		$before = null !== $applied ? $applied['before'] : $meta;
		$source = null !== $applied ? self::path_of( (string) $before['file'] ) : $current;

		if ( ! is_readable( $source ) ) {
			return false;
		}

		$saved = self::make_edit( $source, $edit, (string) get_post_mime_type( $attachment_id ) );

		if ( null === $saved ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		update_attached_file( $attachment_id, $saved['path'] );

		// The edit is already no bigger than the photo WordPress kept, so it
		// needs no "-scaled" copy of its own.
		add_filter( 'big_image_size_threshold', '__return_false', 999 );
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $saved['path'] );
		remove_filter( 'big_image_size_threshold', '__return_false', 999 );

		if ( ! is_array( $new_meta ) || empty( $new_meta['width'] ) || empty( $new_meta['height'] ) ) {
			// Put things back as they were.
			update_attached_file( $attachment_id, $current );
			wp_update_attachment_metadata( $attachment_id, $meta );
			wp_delete_file( $saved['path'] );

			return false;
		}

		// The date taken and camera details belong to the photo, not the file.
		if ( isset( $before['image_meta'] ) ) {
			$new_meta['image_meta'] = $before['image_meta'];
		}

		if ( isset( $before['original_image'] ) ) {
			$new_meta['original_image'] = $before['original_image'];
		}

		wp_update_attachment_metadata( $attachment_id, $new_meta );

		if ( (string) get_post_mime_type( $attachment_id ) !== $saved['mime-type'] ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $saved['mime-type'],
				)
			);
		}

		if ( null === $applied ) {
			self::remember_backup( $attachment_id, $meta );
		} else {
			self::delete_edit_files( dirname( $current ), $applied['file'], $meta );
		}

		update_post_meta(
			$attachment_id,
			self::META,
			wp_slash(
				array(
					'shape'  => $edit['shape'],
					'rotate' => $edit['rotate'],
					'file'   => wp_basename( $saved['path'] ),
					'mime'   => null !== $applied ? $applied['mime'] : (string) get_post_mime_type( $attachment_id ),
					'before' => $before,
				)
			)
		);

		return true;
	}

	/**
	 * The edit Daymark made to a photo, or null when it made none.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{shape: string, rotate: int, file: string, mime: string, before: array<string, mixed>}|null
	 */
	private static function applied( int $attachment_id ): ?array {
		$stored = get_post_meta( $attachment_id, self::META, true );

		if ( ! is_array( $stored ) || empty( $stored['file'] ) || empty( $stored['before'] ) || ! is_array( $stored['before'] ) || empty( $stored['before']['file'] ) ) {
			return null;
		}

		$edit = self::sanitize( $stored );

		return array(
			'shape'  => $edit['shape'],
			'rotate' => $edit['rotate'],
			'file'   => (string) $stored['file'],
			'mime'   => (string) ( $stored['mime'] ?? '' ),
			'before' => $stored['before'],
		);
	}

	/**
	 * Turn and crop a file into a new one beside it.
	 *
	 * @param string                            $source The file to start from.
	 * @param array{shape: string, rotate: int} $edit   The edit.
	 * @param string                            $mime   The photo's type.
	 * @return array{path: string, mime-type: string}|null Null when the edit couldn't be made.
	 */
	private static function make_edit( string $source, array $edit, string $mime ): ?array {
		$editor = wp_get_image_editor( $source );

		if ( is_wp_error( $editor ) ) {
			return null;
		}

		// WordPress turns counter-clockwise; the sheet's Rotate turns clockwise.
		if ( 0 !== $edit['rotate'] && is_wp_error( $editor->rotate( 360 - $edit['rotate'] ) ) ) {
			return null;
		}

		$size = $editor->get_size();
		$box  = self::crop_box( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ), $edit['shape'] );

		if ( null !== $box && is_wp_error( $editor->crop( $box[0], $box[1], $box[2], $box[3] ) ) ) {
			return null;
		}

		// Named the way WordPress names its own edited images.
		$parts = pathinfo( $source );
		$stem  = (string) preg_replace( '/-e[0-9]{13}$/', '', (string) $parts['filename'] );
		$ext   = isset( $parts['extension'] ) ? '.' . $parts['extension'] : '';

		do {
			$target = trailingslashit( (string) $parts['dirname'] ) . $stem . '-e' . time() . wp_rand( 100, 999 ) . $ext;
		} while ( file_exists( $target ) );

		$saved = $editor->save( $target, $mime );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}

		return array(
			'path'      => (string) $saved['path'],
			'mime-type' => (string) ( $saved['mime-type'] ?? $mime ),
		);
	}

	/**
	 * Original, with no turn: put back the file and sizes from before
	 * Daymark's edit, and remove the edit's own files.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $applied       The edit being undone (see applied()).
	 * @return bool
	 */
	private static function restore( int $attachment_id, array $applied ): bool {
		$before      = $applied['before'];
		$before_path = self::path_of( (string) $before['file'] );

		if ( ! file_exists( $before_path ) ) {
			return false;
		}

		$current = (string) get_attached_file( $attachment_id );
		$meta    = wp_get_attachment_metadata( $attachment_id );

		update_attached_file( $attachment_id, $before_path );
		wp_update_attachment_metadata( $attachment_id, $before );

		if ( '' !== $applied['mime'] && (string) get_post_mime_type( $attachment_id ) !== $applied['mime'] ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $applied['mime'],
				)
			);
		}

		if ( '' !== $current && is_array( $meta ) ) {
			self::delete_edit_files( dirname( $current ), $applied['file'], $meta );
		}

		delete_post_meta( $attachment_id, self::META );

		return true;
	}

	/**
	 * List the pre-edit file and its sizes where WordPress keeps an edited
	 * image's earlier files, so the Media Library can restore the original
	 * and deleting the photo deletes them too.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $meta          The attachment metadata before the edit.
	 * @return void
	 */
	private static function remember_backup( int $attachment_id, array $meta ): void {
		$backup = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$backup = is_array( $backup ) ? $backup : array();
		$file   = wp_basename( (string) $meta['file'] );

		foreach ( $backup as $key => $entry ) {
			if ( str_starts_with( (string) $key, 'full-' ) && is_array( $entry ) && ( $entry['file'] ?? '' ) === $file ) {
				// Already listed (the photo was edited and restored before).
				return;
			}
		}

		// "orig" for a photo never edited before; a photo already edited in
		// the Media Library keeps its own original listed, and this file
		// goes in beside it, as WordPress does for an edit of an edit.
		$tag = isset( $backup['full-orig'] ) ? time() . wp_rand( 100, 999 ) : 'orig';

		$backup[ 'full-' . $tag ] = array(
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'file'   => $file,
		);

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
			if ( is_array( $data ) && ! empty( $data['file'] ) ) {
				$backup[ $size . '-' . $tag ] = $data;
			}
		}

		update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', wp_slash( $backup ) );
	}

	/**
	 * Delete an edit's file and the sizes made from it. Only files named
	 * as an edit of this photo are ever deleted.
	 *
	 * @param string               $dir  The folder they are in.
	 * @param string               $file The edit's file name.
	 * @param array<string, mixed> $meta The attachment metadata while that edit was current.
	 * @return void
	 */
	private static function delete_edit_files( string $dir, string $file, array $meta ): void {
		if ( ! preg_match( '/-e[0-9]{13}\.[A-Za-z0-9]+$/', $file ) ) {
			return;
		}

		$stem  = (string) pathinfo( $file, PATHINFO_FILENAME );
		$files = array( $file );

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $data ) {
			if ( is_array( $data ) && ! empty( $data['file'] ) && str_starts_with( (string) $data['file'], $stem . '-' ) ) {
				$files[] = (string) $data['file'];
			}
		}

		foreach ( array_unique( $files ) as $name ) {
			wp_delete_file( trailingslashit( $dir ) . wp_basename( $name ) );
		}
	}

	/**
	 * The full path of a file named relative to the uploads folder, as
	 * attachment metadata names it.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string
	 */
	private static function path_of( string $relative ): string {
		return path_join( wp_get_upload_dir()['basedir'], $relative );
	}
}
