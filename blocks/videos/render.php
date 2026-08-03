<?php
/**
 * Server render for the daymark/videos dynamic block.
 *
 * Delegates to the shared Daymark_Renderer so the block and the
 * [daymark_videos] shortcode produce identical markup.
 *
 * @package Daymark
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$daymark_view_count = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 10;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Daymark_Renderer output is fully escaped at build time.
echo Daymark_Plugin::instance()->renderer->render( 'videos', array( 'count' => $daymark_view_count ) );
