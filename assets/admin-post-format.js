/**
 * Prefills Quick Edit's Format field with a row's current post format.
 *
 * The field itself is rendered server-side (Daymark_Admin_Post_Format_Icon::
 * render_quick_edit_field()) into WordPress core's own *shared* hidden Quick
 * Edit template row, so it can't know which post is being edited — this
 * overrides inlineEditPost.edit() the same way the WordPress Developer
 * Handbook documents for prefilling a custom Quick Edit field, reading the
 * value from a data-format attribute Daymark_Admin_Post_Format_Icon::
 * render_format_icon_column() already stamps on every row's own icon column.
 *
 * @package Daymark
 */

( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof inlineEditPost ) {
		return;
	}

	var daymarkOriginalEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		var result = daymarkOriginalEdit.apply( this, arguments );
		var postId = 'object' === typeof id ? this.getId( id ) : parseInt( id, 10 );

		if ( ! postId ) {
			return result;
		}

		var format = $( '#post-' + postId )
			.find( '.daymark-format-icon-cell' )
			.data( 'format' );

		$( '#edit-' + postId )
			.find( 'select[name="daymark_post_format"]' )
			.val( format || '' );

		return result;
	};
} )( jQuery );
