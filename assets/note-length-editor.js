/**
 * Note length counter for the block editor.
 *
 * While an Aside-format post (a Note), or a Standard post with no title, is
 * open in the block editor, a row in the Post sidebar's summary counts down
 * from 300 characters, the length of one Bluesky post. It says whether the
 * post will be shared as a short post (its text as an ordinary social post,
 * with no title) or a long post (a link card to it). A post with a title and
 * no post format is always long form, so the counter doesn't show for it. The
 * ActivityPub plugin sends an Aside post, or an untitled post, as a plain note
 * at any length.
 *
 * The counting rule and the count itself live in assets/note-length.js
 * (window.daymarkNoteMeasure), which the app's composer uses too, so a Note
 * counts the same wherever it's written. That file says how both copy the
 * ATmosphere plugin.
 *
 * Plain ES2020 calling wp.element.createElement: no JSX, no build step.
 *
 * @package Daymark
 */

( function ( wp, config, measure ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editor || ! wp.element || ! wp.data || ! wp.i18n || ! measure ) {
		return;
	}

	const { counterKind, measureNote } = measure;

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
} )( window.wp, window.daymarkNoteLength, window.daymarkNoteMeasure );
