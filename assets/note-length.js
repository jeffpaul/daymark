/**
 * Note length counting, shared by the block editor's Note length counter
 * (assets/note-length-editor.js) and the app's composer (assets/app.js), so
 * a Note counts the same wherever it's written.
 *
 * A Note fits in one Bluesky post at up to 300 characters. The rule copies
 * the ATmosphere plugin (checked against 2.4.1,
 * includes/transformer/class-post.php, is_short_form()). A post with a title
 * and no post format is always long form. A post with a post format, or with
 * no title, is short form when its text is at most 300 characters; a longer
 * one is long form (a link card), unless it has image blocks, in which case
 * it stays short form with its images and the text is shortened.
 *
 * The count also copies ATmosphere's: the post's text with tags removed,
 * entities decoded, and runs of whitespace collapsed to one space, counted in
 * graphemes (what a person sees as one character, so an emoji counts as one).
 * This counts in the browser, while ATmosphere counts the rendered post on
 * the server, so the two can differ by a few characters, for example around
 * embeds. ATmosphere's own pre-publish panel is the exact check.
 *
 * Sets window.daymarkNoteMeasure. No strings to translate and no
 * dependencies; tests/e2e/note-length.spec.js loads this file on its own.
 *
 * @package Daymark
 */

( function ( global ) {
	'use strict';

	/**
	 * Which kind of post the counter applies to: an Aside post is a 'note',
	 * a Standard post with no title is a 'post', and anything else gets no
	 * counter (null).
	 *
	 * @param {string} format The post's format ('' or 'standard' for Standard).
	 * @param {string} title  The post's title as edited.
	 * @return {string|null} 'note', 'post', or null.
	 */
	function counterKind( format, title ) {
		if ( format === 'aside' ) {
			return 'note';
		}

		if ( ( ! format || format === 'standard' ) && ! String( title || '' ).trim() ) {
			return 'post';
		}

		return null;
	}

	/**
	 * The text of a post as ATmosphere measures it: tags and block comments
	 * removed, entities decoded, script and style contents dropped, whitespace
	 * collapsed to single spaces, and trimmed.
	 *
	 * @param {string} html Post content markup, or a caption as typed.
	 * @return {string} Plain text.
	 */
	function notePlainText( html ) {
		const template = document.createElement( 'template' );
		template.innerHTML = String( html || '' );
		template.content.querySelectorAll( 'script, style' ).forEach( ( node ) => node.remove() );

		return ( template.content.textContent || '' ).replace( /\s+/gu, ' ' ).trim();
	}

	/**
	 * Number of graphemes in a string, so an emoji or an accented letter made
	 * of several code points counts as one character.
	 *
	 * @param {string} text Text to count.
	 * @return {number} Grapheme count.
	 */
	function graphemeCount( text ) {
		if ( typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function' ) {
			return [ ...new Intl.Segmenter( undefined, { granularity: 'grapheme' } ).segment( text ) ].length;
		}

		return Array.from( text ).length;
	}

	/**
	 * Whether a block tree holds an image block with a media library image,
	 * the images ATmosphere posts to Bluesky.
	 *
	 * @param {Array} blocks Blocks from the block editor store.
	 * @return {boolean} True when at least one such image exists.
	 */
	function hasLibraryImage( blocks ) {
		return ( blocks || [] ).some(
			( block ) =>
				( block.name === 'core/image' && block.attributes && Number( block.attributes.id ) > 0 ) ||
				hasLibraryImage( block.innerBlocks )
		);
	}

	/**
	 * How a Note of this content measures against the limit.
	 *
	 * @param {string} html   Post content markup, or a caption as typed.
	 * @param {Array}  blocks Blocks from the block editor store ([] for none).
	 * @param {number} limit  Character limit, normally 300.
	 * @return {{count: number, remaining: number, form: string}} `form` is
	 *     'short', 'long', or 'long-images' (over the limit, but with images).
	 */
	function measureNote( html, blocks, limit ) {
		const count = graphemeCount( notePlainText( html ) );
		const remaining = limit - count;
		let form = 'short';

		if ( remaining < 0 ) {
			form = hasLibraryImage( blocks ) ? 'long-images' : 'long';
		}

		return { count, remaining, form };
	}

	global.daymarkNoteMeasure = Object.freeze( {
		counterKind,
		notePlainText,
		graphemeCount,
		hasLibraryImage,
		measureNote,
	} );
} )( window );
