<?php
/**
 * Sample Marks for WordPress Playground previews.
 *
 * Required by the public blueprint (`blueprint.json`) and the per-PR preview
 * workflow (`.github/workflows/pr-playground-preview.yml`) after WordPress is
 * loaded and Daymark is active, so a reviewer opens a Timeline that already
 * shows each Mark type and Featured Content kind instead of building them by
 * hand. Playground installs this repository as the plugin, so the sample
 * photos in `sample-images/` are on disk next to this file: no network fetch,
 * and nothing generated at run time (the old GD-generated placeholder image
 * is what once crashed Playground builds without that extension).
 *
 * Every Mark is created through `Daymark_Publisher::publish()`, the same path
 * the app, autosave, and the offline queue use. A failure on one sample never
 * stops the rest.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daymark_Plugin' ) ) {
	return;
}

// wp_tempnam() lives in wp-admin's file.php, which a Playground runPHP step
// (plain wp-load.php, no admin bootstrap) never loads. PHPUnit already has it
// loaded, which is why the blueprint test alone didn't catch its absence.
require_once ABSPATH . 'wp-admin/includes/file.php';

// A runPHP step has no logged-in user, and the publisher only publishes for a
// user who can publish_posts (anyone else gets a draft). Act as the site's
// first administrator so the samples land on the Timeline, not in Drafts.
if ( ! current_user_can( 'publish_posts' ) ) {
	$daymark_sample_admins = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'fields'  => 'ID',
		)
	);

	if ( ! empty( $daymark_sample_admins ) ) {
		wp_set_current_user( (int) $daymark_sample_admins[0] );
	}
}

$daymark_sample_plugin = Daymark_Plugin::instance();
$daymark_sample_dir    = __DIR__ . '/sample-images/';
$daymark_sample_start  = time();
$daymark_sample_count  = 0;

/**
 * Build the multi-file upload array the publisher expects from sample photo
 * names, copying each to a temp file first (sideloading moves the file).
 *
 * @param string[] $names Sample image base names, without extension.
 * @return array<string, array<string, array<int, mixed>>>
 */
$daymark_sample_files = static function ( array $names ) use ( $daymark_sample_dir ): array {
	$field = array(
		'name'     => array(),
		'type'     => array(),
		'tmp_name' => array(),
		'error'    => array(),
		'size'     => array(),
	);

	foreach ( $names as $name ) {
		$source = $daymark_sample_dir . $name . '.jpg';

		if ( ! is_readable( $source ) ) {
			continue;
		}

		$tmp = wp_tempnam( 'daymark-sample-' ) . '.jpg';

		if ( ! copy( $source, $tmp ) ) {
			continue;
		}

		$field['name'][]     = $name . '.jpg';
		$field['type'][]     = 'image/jpeg';
		$field['tmp_name'][] = $tmp;
		$field['error'][]    = UPLOAD_ERR_OK;
		$field['size'][]     = (int) filesize( $tmp );
	}

	return array( 'files' => $field );
};

/**
 * Publish one sample Mark, dated a little in the past so the Timeline shows
 * them in the order listed (newest first).
 *
 * @param array<string, mixed> $data  Publisher data.
 * @param string[]             $photo Sample image names to attach.
 * @return int Post ID, or 0 on failure.
 */
$daymark_sample_publish = static function ( array $data, array $photo = array() ) use ( $daymark_sample_plugin, $daymark_sample_files, $daymark_sample_start, &$daymark_sample_count ): int {
	++$daymark_sample_count;

	$data['captured_at'] = gmdate( 'c', $daymark_sample_start - ( $daymark_sample_count * 1800 ) );

	$result = $daymark_sample_plugin->publisher->publish(
		$data,
		empty( $photo ) ? array() : $daymark_sample_files( $photo )
	);

	return is_wp_error( $result ) ? 0 : (int) $result;
};

/**
 * Set a Mark's Featured Content (a general block-editor feature, not part of
 * the publisher's own contract, so it is written as the two meta keys).
 *
 * @param int                  $post_id Post ID.
 * @param string               $type    Featured Content type.
 * @param array<string, mixed> $data    Data for that type.
 * @return void
 */
$daymark_sample_featured = static function ( int $post_id, string $type, array $data ): void {
	if ( $post_id <= 0 ) {
		return;
	}

	update_post_meta( $post_id, '_daymark_featured_content_type', $type );
	update_post_meta( $post_id, '_daymark_featured_content', wp_json_encode( array( $type => $data ) ) );
};

// A single photo.
$daymark_sample_publish(
	array(
		'caption'      => 'Sunrise over the harbor. A single-photo Mark.',
		'primary_type' => 'image',
	),
	array( 'sunrise-harbor' )
);

// A regular multi-photo gallery Mark.
$daymark_sample_gallery_id = $daymark_sample_publish(
	array(
		'caption'      => 'Four stops, one Mark. A regular gallery Mark.',
		'primary_type' => 'gallery',
	),
	array( 'pine-forest', 'city-dusk', 'desert-dunes', 'alpine-lake' )
);

// A Check In with an optional photo.
$daymark_sample_publish(
	array(
		'caption'      => "See, it's me holding it up!",
		'primary_type' => 'checkin',
		'place_name'   => 'Leaning Tower of Pisa',
		'location_lat' => 43.7230,
		'location_lng' => 10.3966,
	),
	array( 'alpine-lake' )
);

// A Check In with no photo: just the place and its map preview.
$daymark_sample_publish(
	array(
		'primary_type' => 'checkin',
		'place_name'   => 'Golden Gate Bridge',
		'location_lat' => 37.8199,
		'location_lng' => -122.4783,
	)
);

// Featured Content: a gallery, reusing the gallery Mark's photos. Shows as a
// carousel on the post and as a 2x2 grid on the Timeline card.
$daymark_sample_featured_gallery_id = $daymark_sample_publish(
	array(
		'title'        => 'Featured Content: gallery',
		'caption'      => 'This Mark sets a photo gallery as its Featured Content instead of an image.',
		'primary_type' => 'note',
	)
);

$daymark_sample_gallery_media = $daymark_sample_gallery_id > 0
	? json_decode( (string) get_post_meta( $daymark_sample_gallery_id, '_daymark_media_ids', true ), true )
	: array();

if ( is_array( $daymark_sample_gallery_media ) && ! empty( $daymark_sample_gallery_media ) ) {
	$daymark_sample_featured(
		$daymark_sample_featured_gallery_id,
		'gallery',
		array( 'attachment_ids' => array_map( 'absint', $daymark_sample_gallery_media ) )
	);
}

// Featured Content: a quote with an author and a source.
$daymark_sample_featured(
	$daymark_sample_publish(
		array(
			'title'        => 'Featured Content: quote',
			'caption'      => 'This Mark sets a quote as its Featured Content.',
			'primary_type' => 'note',
		)
	),
	'quote',
	array(
		'text'         => 'The best way to predict the future is to invent it.',
		'author'       => 'Alan Kay',
		'citation_url' => 'https://en.wikipedia.org/wiki/Alan_Kay',
	)
);

// Featured Content: a link. Only offered for the Link post format.
$daymark_sample_link_id = $daymark_sample_publish(
	array(
		'title'        => 'Featured Content: link',
		'caption'      => 'This Mark uses the Link post format, so it can set a link as its Featured Content.',
		'primary_type' => 'note',
	)
);

if ( $daymark_sample_link_id > 0 ) {
	set_post_format( $daymark_sample_link_id, 'link' );
	$daymark_sample_featured( $daymark_sample_link_id, 'link', array( 'url' => 'https://wordpress.org/news/' ) );
}

// Featured Content: a YouTube video.
$daymark_sample_featured(
	$daymark_sample_publish(
		array(
			'title'        => 'Featured Content demo',
			'caption'      => 'Demo of Featured Content: this Mark uses a YouTube video as its featured video instead of an image.',
			'primary_type' => 'note',
		)
	),
	'video',
	array(
		'source' => 'url',
		'url'    => 'https://www.youtube.com/watch?v=BZtL1NVlxgQ',
	)
);
