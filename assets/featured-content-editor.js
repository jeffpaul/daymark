/**
 * "Featured Content" (issue #401) — a single "Set featured content" control
 * that renders immediately after core's own "Set featured image" button,
 * inside the same Featured Image panel, letting an author set an audio or
 * video file (from the media library, or a profiled URL — YouTube/Vimeo/a
 * podcast episode link) as a post's Featured Content instead of a static
 * image.
 *
 * There is no public way to inject a sibling control directly into core's
 * PostFeaturedImage component's own rendered output other than the
 * documented `editor.PostFeaturedImage` wp.hooks filter — the same
 * mechanism the Block Editor Handbook's own "extend a component" example
 * uses to wrap it and render additional content after it. That filter wraps
 * whatever `@wordpress/editor` renders inside the sidebar's existing
 * "Featured image" panel, so this needed no new PluginDocumentSettingPanel
 * (and no new panel heading) at all — a real change from this feature's
 * first draft, which did register its own separate "Featured Content"
 * panel; that approach worked, but visually and structurally didn't match
 * what was actually asked for (a second button in the *same* panel), so it
 * was replaced with this filter-based approach once that mismatch was
 * pointed out against the real editor UI.
 *
 * Plain ES2020 calling wp.element.createElement — no JSX, no build step,
 * enqueued only against WordPress core's own bundled scripts. This matches
 * the "vanilla, no build" posture CLAUDE.md's "Block vs shortcode" decision
 * already established for assets/app.js, the one other place this codebase
 * writes browser JS. See Daymark_Featured_Content::enqueue_editor_assets()
 * for the dependency list and localized daymarkFeaturedContent config this
 * file reads.
 *
 * Type (audio vs video) is no longer chosen by the author up front: picking
 * a file from the media library reads its own MIME type directly
 * (attachment.mime), and a pasted URL is guessed from its file extension or
 * known audio-hosting domains, defaulting to video otherwise (most oEmbed
 * providers — YouTube, Vimeo, etc. — are video). The guess only decides
 * which meta type gets stored and which shortcode function backs the
 * fallback render path when oEmbed itself doesn't resolve — Daymark_Featured_Content::render()
 * still renders a resolved oEmbed's own markup unchanged either way.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.data || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var RawHTML = wp.element.RawHTML;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;

	var config = window.daymarkFeaturedContent || {};
	var META_TYPE = config.metaType || '_daymark_featured_content_type';
	var META_DATA = config.metaData || '_daymark_featured_content';
	var OEMBED_ENDPOINT = config.oembedEndpoint || '';

	// File extensions this guesses as audio vs video when a pasted URL is a
	// direct media file rather than a page a provider's oEmbed would resolve.
	var AUDIO_EXTENSIONS = [ 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'wma' ];
	var VIDEO_EXTENSIONS = [ 'mp4', 'm4v', 'mov', 'webm', 'ogv', 'avi', 'wmv' ];

	// Hosts that are audio-first even though their pages aren't a direct
	// media-file URL — a podcast episode page's own URL rarely ends in
	// ".mp3". Everything else (YouTube, Vimeo, and most other oEmbed
	// providers) is treated as video, the more common case for an arbitrary
	// profiled URL.
	var AUDIO_HOST_PATTERN = /soundcloud\.com|anchor\.fm|buzzsprout\.com|podbean\.com|transistor\.fm|libsyn\.com|spotify\.com\/episode|open\.spotify\.com\/show|podcasts\.apple\.com/i;

	/**
	 * Guess whether a pasted URL is audio or video content, since the
	 * author no longer picks a type up front. This only decides which meta
	 * type gets stored (and which native <audio>/<video> fallback shortcode
	 * function backs it if oEmbed resolution itself comes up empty) — a
	 * resolved oEmbed's own markup renders exactly the same either way.
	 *
	 * @param {string} url Pasted URL.
	 * @return {string} 'audio' or 'video'.
	 */
	function guessUrlKind( url ) {
		var clean = String( url ).split( '?' )[ 0 ].split( '#' )[ 0 ];
		var match = clean.match( /\.([a-zA-Z0-9]+)$/ );
		var ext = match ? match[ 1 ].toLowerCase() : '';

		if ( -1 !== AUDIO_EXTENSIONS.indexOf( ext ) ) {
			return 'audio';
		}

		if ( -1 !== VIDEO_EXTENSIONS.indexOf( ext ) ) {
			return 'video';
		}

		return AUDIO_HOST_PATTERN.test( url ) ? 'audio' : 'video';
	}

	/**
	 * Read the currently-saved Featured Content type + that type's own data
	 * sub-object out of post meta — mirrors
	 * Daymark_Featured_Content::get_featured_content()'s own tolerance for
	 * an empty/malformed blob.
	 *
	 * @param {Object} meta Current post meta object.
	 * @return {{type: string, data: Object}}
	 */
	function readFeaturedContent( meta ) {
		var type = meta[ META_TYPE ] || '';
		var raw = meta[ META_DATA ] || '';
		var data = {};

		if ( raw ) {
			try {
				var decoded = JSON.parse( raw );

				if ( decoded && typeof decoded === 'object' && decoded[ type ] && typeof decoded[ type ] === 'object' ) {
					data = decoded[ type ];
				}
			} catch ( err ) {
				data = {};
			}
		}

		return { type: type, data: data };
	}

	/**
	 * Save a content kind's value as the post's Featured Content.
	 *
	 * @param {Function} editPost `core/editor`'s editPost dispatcher.
	 * @param {string}   type     'audio' or 'video'.
	 * @param {Object}   value    That kind's data sub-object.
	 */
	function saveFeaturedContent( editPost, type, value ) {
		var payload = {};
		payload[ type ] = value;

		var meta = {};
		meta[ META_TYPE ] = type;
		meta[ META_DATA ] = JSON.stringify( payload );
		editPost( { meta: meta } );
	}

	/**
	 * Clear the post's Featured Content.
	 *
	 * @param {Function} editPost `core/editor`'s editPost dispatcher.
	 */
	function clearFeaturedContent( editPost ) {
		var meta = {};
		meta[ META_TYPE ] = '';
		meta[ META_DATA ] = '';
		editPost( { meta: meta } );
	}

	/**
	 * Open WordPress's own classic media modal, scoped to audio+video, and
	 * hand the picked attachment back to the caller. Requires
	 * Daymark_Featured_Content::enqueue_editor_assets() to have called
	 * wp_enqueue_media() — the block editor's own Featured Image panel
	 * doesn't use wp.media at all (it fetches media via REST directly), so
	 * it can't be assumed available on every post-edit screen without
	 * asking for it explicitly.
	 *
	 * @param {Function} onSelect Called with the picked attachment's REST-shaped object.
	 */
	function openMediaPicker( onSelect ) {
		if ( ! wp.media ) {
			return;
		}

		var frame = wp.media( {
			title: __( 'Select audio or video', 'daymark' ),
			library: { type: [ 'audio', 'video' ] },
			multiple: false,
			button: { text: __( 'Use this file', 'daymark' ) },
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' ).first();

			if ( selection ) {
				onSelect( selection.toJSON() );
			}
		} );

		frame.open();
	}

	/**
	 * One-line summary of the currently-saved Featured Content, for the
	 * already-set state.
	 *
	 * @param {string} type Saved type ('audio'/'video').
	 * @param {Object} data That type's saved data sub-object.
	 * @return {string}
	 */
	function summaryLabel( type, data ) {
		var kindLabel = 'audio' === type ? __( 'Audio', 'daymark' ) : __( 'Video', 'daymark' );

		if ( 'url' === data.source ) {
			return sprintf(
				/* translators: %s: Audio or Video. */
				__( 'Featured content: %s (URL)', 'daymark' ),
				kindLabel
			);
		}

		return sprintf(
			/* translators: %s: Audio or Video. */
			__( 'Featured content: %s file', 'daymark' ),
			kindLabel
		);
	}

	/**
	 * The control rendered right after core's own Featured Image button.
	 * Three states: unset (a single toggle button), the picker open (media
	 * library button + URL field, with a live preview), or already set (a
	 * one-line summary plus Replace/Remove).
	 */
	function FeaturedContentControl() {
		var meta = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor ? editor.getEditedPostAttribute( 'meta' ) || {} : {};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;
		var current = readFeaturedContent( meta );

		var openState = useState( false );
		var isOpen = openState[ 0 ];
		var setOpen = openState[ 1 ];

		var urlState = useState( '' );
		var url = urlState[ 0 ];
		var setUrl = urlState[ 1 ];

		var previewState = useState( null );
		var preview = previewState[ 0 ];
		var setPreview = previewState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		function resetPicker() {
			setOpen( false );
			setUrl( '' );
			setPreview( null );
			setBusy( false );
		}

		function handleLibrarySelect( attachment ) {
			var mime = attachment.mime || '';
			var kind = 0 === mime.indexOf( 'audio/' ) ? 'audio' : ( 0 === mime.indexOf( 'video/' ) ? 'video' : '' );

			if ( ! kind ) {
				return;
			}

			saveFeaturedContent( editPost, kind, { source: 'library', attachment_id: attachment.id } );
			resetPicker();
		}

		function handleUseUrl() {
			if ( ! url ) {
				return;
			}

			saveFeaturedContent( editPost, guessUrlKind( url ), { source: 'url', url: url } );
			resetPicker();
		}

		function handlePreview() {
			if ( ! url || ! OEMBED_ENDPOINT || ! wp.apiFetch ) {
				return;
			}

			setBusy( true );

			wp.apiFetch( { url: OEMBED_ENDPOINT + '?url=' + encodeURIComponent( url ) } ).then(
				function ( response ) {
					setBusy( false );
					setPreview( response && response.embed ? response.embed : '' );
				},
				function () {
					setBusy( false );
					setPreview( '' );
				}
			);
		}

		if ( current.type ) {
			return el(
				'div',
				{ className: 'daymark-fc-summary' },
				el( 'span', {}, summaryLabel( current.type, current.data ) ),
				el(
					'div',
					{ className: 'daymark-fc-summary-actions' },
					el(
						'button',
						{ type: 'button', className: 'daymark-fc-link', onClick: function () { setOpen( true ); } },
						__( 'Replace', 'daymark' )
					),
					el(
						'button',
						{
							type: 'button',
							className: 'daymark-fc-link daymark-fc-link--danger',
							onClick: function () {
								clearFeaturedContent( editPost );
								resetPicker();
							},
						},
						__( 'Remove', 'daymark' )
					)
				),
				isOpen ? renderPicker() : null
			);
		}

		if ( ! isOpen ) {
			return el(
				'button',
				{ type: 'button', className: 'daymark-fc-toggle', onClick: function () { setOpen( true ); } },
				__( 'Set featured content', 'daymark' )
			);
		}

		return renderPicker();

		/**
		 * @return {Object} The open picker's own element tree.
		 */
		function renderPicker() {
			return el(
				'div',
				{ className: 'daymark-fc-picker' },
				el(
					'button',
					{ type: 'button', className: 'daymark-fc-toggle', onClick: function () { openMediaPicker( handleLibrarySelect ); } },
					__( 'Choose from Media Library', 'daymark' )
				),
				el( 'p', { className: 'daymark-fc-or' }, __( 'Or paste a URL (YouTube, Vimeo, a podcast episode link, etc.):', 'daymark' ) ),
				el( 'input', {
					type: 'url',
					className: 'daymark-fc-url-input',
					placeholder: __( 'https://…', 'daymark' ),
					value: url,
					onChange: function ( event ) {
						setUrl( event.target.value );
						setPreview( null );
					},
				} ),
				el(
					'div',
					{ className: 'daymark-fc-url-actions' },
					el(
						'button',
						{ type: 'button', className: 'daymark-fc-link', disabled: ! url || busy, onClick: handlePreview },
						busy ? __( 'Loading preview…', 'daymark' ) : __( 'Preview', 'daymark' )
					),
					el(
						'button',
						{ type: 'button', className: 'daymark-fc-link', disabled: ! url, onClick: handleUseUrl },
						__( 'Use this URL', 'daymark' )
					),
					el(
						'button',
						{ type: 'button', className: 'daymark-fc-link', onClick: resetPicker },
						__( 'Cancel', 'daymark' )
					)
				),
				preview ? el( RawHTML, { className: 'daymark-fc-preview' }, preview ) : null,
				'' === preview ? el( 'p', { className: 'daymark-fc-preview-empty' }, __( "Couldn't generate a preview for this URL — it will still be saved and rendered on the front end.", 'daymark' ) ) : null
			);
		}
	}

	/**
	 * Wraps core's own PostFeaturedImage component via the documented
	 * `editor.PostFeaturedImage` filter — the Block Editor Handbook's own
	 * "extend a component" example wraps a filtered component the same way:
	 * render the original unchanged, then append additional content after
	 * it. This is what actually places "Set featured content" directly
	 * below "Set featured image" inside the same, single "Featured image"
	 * sidebar panel, with no second panel/heading of our own.
	 *
	 * @param {Function} OriginalPostFeaturedImage Core's own component.
	 * @return {Function} Wrapped component.
	 */
	function withFeaturedContentControl( OriginalPostFeaturedImage ) {
		return function ( props ) {
			return el(
				Fragment,
				{},
				el( OriginalPostFeaturedImage, props ),
				el( FeaturedContentControl, {} )
			);
		};
	}

	wp.hooks.addFilter(
		'editor.PostFeaturedImage',
		'daymark/featured-content-control',
		withFeaturedContentControl
	);
} )( window.wp );
