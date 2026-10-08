/**
 * Note length counter for the block editor.
 *
 * While an Aside-format post (a Note), or a Standard post with no title, is
 * open in the block editor, a row in the Post sidebar's summary counts down
 * from 300 characters, the length of one Bluesky post. It says whether the
 * post will be shared as a short post (its text as an ordinary social post,
 * with no title) or a long post (a link card to it).
 *
 * The rule copies the ATmosphere plugin (checked against 2.4.1,
 * includes/transformer/class-post.php, is_short_form()). A post with a title
 * and no post format is always long form, so the counter doesn't show for
 * it. A post with a post format, or with no title, is short form when its
 * text is at most 300 characters; a longer one is long form (a link card),
 * unless it has image blocks, in which case it stays short form with its
 * images and the text is shortened. The ActivityPub plugin sends an Aside
 * post, or an untitled post, as a plain note at any length.
 *
 * The count also copies ATmosphere's: the post's text with tags removed,
 * entities decoded, and runs of whitespace collapsed to one space, counted in
 * graphemes (what a person sees as one character, so an emoji counts as one).
 * This counts the editor's saved markup in the browser, while ATmosphere
 * counts the rendered post on the server, so the two can differ by a few
 * characters, for example around embeds. ATmosphere's own pre-publish panel
 * is the exact check.
 *
 * Plain ES2020 calling wp.element.createElement: no JSX, no build step.
 *
 * @package Daymark
 */

( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editor || ! wp.element || ! wp.data || ! wp.i18n ) {
		return;
	}

	// >>> note-length
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
	 * @param {string} html Post content markup.
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
	 * @param {string} html   Post content markup.
	 * @param {Array}  blocks Blocks from the block editor store.
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
	// <<< note-length

	const { createElement: el, useEffect, useRef } = wp.element;
	const { useSelect } = wp.data;
	const { __, _n, sprintf } = wp.i18n;
	const PostStatusInfo = wp.editor.PluginPostStatusInfo;
	const limit = Math.max( 1, parseInt( ( config && config.limit ) || 300, 10 ) );
	const atmosphere = !! ( config && config.atmosphere );

	if ( ! PostStatusInfo ) {
		return;
	}

	/**
	 * The sentence under the count, saying how the Note will be shared.
	 *
	 * @param {string} form 'short', 'long', or 'long-images'.
	 * @return {string} Help text.
	 */
	function helpText( form ) {
		if ( atmosphere ) {
			if ( form === 'short' ) {
				return __( 'Posts to Bluesky as a regular post, without a title.', 'daymark' );
			}
			if ( form === 'long-images' ) {
				return __( 'Posts to Bluesky with its images, and the text is shortened to fit.', 'daymark' );
			}
			return __( 'Posts to Bluesky as a link card to this post, not its full text.', 'daymark' );
		}

		if ( form === 'short' ) {
			return __( 'Short enough to share as one social post, without a title.', 'daymark' );
		}
		return __( 'Too long for one Bluesky post. Sharing tools may share a link to it instead.', 'daymark' );
	}

	/**
	 * The label beside the count.
	 *
	 * @param {string}  kind 'note' or 'post'.
	 * @param {boolean} over Whether the text is over the limit.
	 * @return {string} Label.
	 */
	function kindLabel( kind, over ) {
		if ( kind === 'note' ) {
			return over ? __( 'Long note', 'daymark' ) : __( 'Short note', 'daymark' );
		}

		return over ? __( 'Long post', 'daymark' ) : __( 'Short post', 'daymark' );
	}

	function NoteLength() {
		const state = useSelect( ( select ) => {
			const editor = select( 'core/editor' );

			const kind = counterKind( editor.getEditedPostAttribute( 'format' ), editor.getEditedPostAttribute( 'title' ) );

			if ( ! kind ) {
				return null;
			}

			return {
				kind,
				...measureNote( editor.getEditedPostContent(), select( 'core/block-editor' ).getBlocks(), limit ),
			};
		}, [] );

		const lastForm = useRef( null );
		const form = state ? state.form : null;

		// Announce only when the Note crosses the limit, not on every keystroke.
		useEffect( () => {
			if ( form && lastForm.current && form !== lastForm.current && wp.a11y && wp.a11y.speak ) {
				wp.a11y.speak( helpText( form ), 'polite' );
			}
			lastForm.current = form;
		}, [ form ] );

		if ( ! state ) {
			return null;
		}

		const over = state.remaining < 0;
		const value = over
			? sprintf(
				/* translators: %d: number of characters over the limit. */
				_n( '%d character over', '%d characters over', -state.remaining, 'daymark' ),
				-state.remaining
			)
			: sprintf(
				/* translators: %d: number of characters left before the limit. */
				_n( '%d character left', '%d characters left', state.remaining, 'daymark' ),
				state.remaining
			);

		return el(
			PostStatusInfo,
			{ className: 'daymark-note-length' },
			el(
				'div',
				{ style: { width: '100%' } },
				el(
					'div',
					{ style: { display: 'flex', justifyContent: 'space-between', gap: '8px' } },
					el( 'span', null, kindLabel( state.kind, over ) ),
					el(
						'span',
						{ style: over ? { color: '#cc1818', fontWeight: 600 } : undefined },
						value
					)
				),
				el(
					'p',
					{ style: { margin: '4px 0 0', color: '#757575', fontSize: '12px' } },
					helpText( state.form )
				)
			)
		);
	}

	wp.plugins.registerPlugin( 'daymark-note-length', { render: NoteLength } );
} )( window.wp, window.daymarkNoteLength );
