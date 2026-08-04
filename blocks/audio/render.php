<?php
/**
 * Server render for the daymark/audio dynamic block.
 *
 * Delegates to the shared Daymark_Renderer so the block and the
 * [daymark_audio] shortcode produce identical markup.
 *
 * @package Daymark
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$daymark_view_count = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 10;

// Carries color/typography/spacing block supports (and the alignwide/
// alignfull classes) onto the actual markup; get_block_wrapper_attributes()
// output is already escaped by core.
$daymark_wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'daymark-view daymark-view--audio' ) );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Daymark_Renderer output is fully escaped at build time; $daymark_wrapper_attributes comes from core's get_block_wrapper_attributes().
echo Daymark_Plugin::instance()->renderer->render(
	'audio',
	array(
		'count'              => $daymark_view_count,
		'wrapper_attributes' => $daymark_wrapper_attributes,
	)
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
