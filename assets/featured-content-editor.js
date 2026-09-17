/**
 * "Featured Content" block-editor sidebar panel (issue #401) — a
 * PluginDocumentSettingPanel sibling to core's own Featured Image panel,
 * letting an author set an audio or video file (from the media library, or
 * a profiled URL — YouTube/Vimeo/a podcast episode link) as a post's
 * Featured Content instead of a static image.
 *
 * Plain ES2020 calling wp.element.createElement — no JSX, no build step,
 * enqueued only against WordPress core's own bundled scripts. This matches
 * the "vanilla, no build" posture CLAUDE.md's "Block vs shortcode" decision
 * already established for assets/app.js, the one other place this codebase
 * writes browser JS. See Daymark_Featured_Content::enqueue_editor_assets()
 * for the dependency list and localized daymarkFeaturedContent config this
 * file reads.
 *
 * Content-kind coverage here matches Daymark_Featured_Content::SUPPORTED_TYPES
 * (audio/video only, in this phase) — the panel only ever offers what
 * daymarkFeaturedContent.allowedTypes lists, so a future phase's own
 * gallery/quote/link buttons appear automatically once that phase widens
 * the server-side allow-list, with no changes needed here.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.i18n || ! wp.components ) {
		return;
	}

	var PluginDocumentSettingPanel = ( wp.editPost && wp.editPost.PluginDocumentSettingPanel ) || ( wp.editor && wp.editor.PluginDocumentSettingPanel );

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	var el = wp.element.createElement;
	var RawHTML = wp.element.RawHTML;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var Button = wp.components.Button;
	var TextControl = wp.components.TextControl;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;

	var config = window.daymarkFeaturedContent || {};
	var META_TYPE = config.metaType || '_daymark_featured_content_type';
	var META_DATA = config.metaData || '_daymark_featured_content';
	var ALLOWED_TYPES = config.allowedTypes || [ 'audio', 'video' ];
	var OEMBED_ENDPOINT = config.oembedEndpoint || '';

	var TYPE_LABELS = {
		audio: __( 'Audio', 'daymark' ),
		video: __( 'Video', 'daymark' ),
	};

	var LIBRARY_LABELS = {
		audio: __( 'Select Audio', 'daymark' ),
		video: __( 'Select Video', 'daymark' ),
	};

	/**
	 * Read one content kind's sub-object out of the JSON-encoded meta
	 * data blob — mirrors Daymark_Featured_Content::get_featured_content()'s
	 * own "empty/malformed decodes to {}" tolerance.
	 *
	 * @param {string} raw  JSON-encoded _daymark_featured_content value.
	 * @param {string} type Content kind to read.
	 * @return {Object} That kind's own sub-object, or {}.
	 */
	function readTypeData( raw, type ) {
		if ( ! raw ) {
			return {};
		}

		try {
			var decoded = JSON.parse( raw );

			return decoded && typeof decoded === 'object' && decoded[ type ] && typeof decoded[ type ] === 'object' ? decoded[ type ] : {};
		} catch ( err ) {
			return {};
		}
	}

	/**
	 * Merge one content kind's new value into the existing JSON blob,
	 * leaving every other kind's own already-saved sub-object untouched —
	 * e.g. switching from a saved `video` back to `audio` doesn't discard
	 * whatever video data was already there, since only the type meta
	 * actually decides which sub-key renders.
	 *
	 * @param {string} raw   Existing JSON-encoded value.
	 * @param {string} type  Content kind being written.
	 * @param {Object} value That kind's new sub-object.
	 * @return {string} Re-encoded JSON.
	 */
	function writeTypeData( raw, type, value ) {
		var decoded = {};

		if ( raw ) {
			try {
				var parsed = JSON.parse( raw );

				if ( parsed && typeof parsed === 'object' ) {
					decoded = parsed;
				}
			} catch ( err ) {
				decoded = {};
			}
		}

		decoded[ type ] = value;

		return JSON.stringify( decoded );
	}

	/**
	 * Open WordPress's own classic media modal, scoped to one MIME family,
	 * and hand the picked attachment back to the caller. Requires
	 * Daymark_Featured_Content::enqueue_editor_assets() to have called
	 * wp_enqueue_media() and enqueued media-editor/media-models — the block
	 * editor's own Featured Image panel doesn't use wp.media at all (it
	 * fetches media via REST directly), so it can't be assumed available on
	 * every post-edit screen without asking for it explicitly.
	 *
	 * @param {string}   type     'audio' or 'video'.
	 * @param {Function} onSelect Called with the picked attachment's REST-shaped object.
	 */
	function openMediaPicker( type, onSelect ) {
		if ( ! wp.media ) {
			return;
		}

		var frame = wp.media( {
			title: LIBRARY_LABELS[ type ] || type,
			library: { type: type },
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
	 * The panel itself. Three states: nothing set yet (pick a type), a type
	 * chosen but not yet saved (pick a source — library or URL), or already
	 * set (a summary + Remove action) — mirroring core's own Featured Image
	 * panel's "Set" -> preview-and-remove flow.
	 */
	function FeaturedContentPanel() {
		var meta = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor ? editor.getEditedPostAttribute( 'meta' ) || {} : {};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		var currentType = meta[ META_TYPE ] || '';
		var currentRawData = meta[ META_DATA ] || '';
		var isSet = '' !== currentType && -1 !== ALLOWED_TYPES.indexOf( currentType );

		var choosingTypeState = useState( '' );
		var choosingType = choosingTypeState[ 0 ];
		var setChoosingType = choosingTypeState[ 1 ];

		var urlState = useState( '' );
		var urlValue = urlState[ 0 ];
		var setUrlValue = urlState[ 1 ];

		var previewState = useState( null );
		var preview = previewState[ 0 ];
		var setPreview = previewState[ 1 ];

		function resetChooser() {
			setChoosingType( '' );
			setUrlValue( '' );
			setPreview( null );
		}

		function save( type, value ) {
			var next = {};

			next[ META_TYPE ] = type;
			next[ META_DATA ] = writeTypeData( currentRawData, type, value );
			editPost( { meta: next } );
			resetChooser();
		}

		function remove() {
			var next = {};

			next[ META_TYPE ] = '';
			editPost( { meta: next } );
			resetChooser();
		}

		function pickFromLibrary( type ) {
			openMediaPicker( type, function ( attachment ) {
				save( type, { source: 'library', attachment_id: attachment.id } );
			} );
		}

		function previewUrl( type ) {
			if ( ! urlValue || ! OEMBED_ENDPOINT || ! wp.apiFetch ) {
				return;
			}

			setPreview( { loading: true, embed: null } );

			wp.apiFetch( { url: OEMBED_ENDPOINT + '?url=' + encodeURIComponent( urlValue ) } ).then(
				function ( response ) {
					setPreview( { loading: false, embed: response && response.embed ? response.embed : null } );
				},
				function () {
					setPreview( { loading: false, embed: null } );
				}
			);
		}

		function useUrl( type ) {
			save( type, { source: 'url', url: urlValue } );
		}

		if ( isSet ) {
			var data = readTypeData( currentRawData, currentType );
			var summary = 'url' === data.source ? data.url : __( 'From your media library', 'daymark' );

			return el(
				PluginDocumentSettingPanel,
				{ name: 'daymark-featured-content', title: __( 'Featured Content', 'daymark' ) },
				el( 'p', { className: 'daymark-fc-summary' }, ( TYPE_LABELS[ currentType ] || currentType ) + ': ' + summary ),
				el( Button, { variant: 'link', isDestructive: true, onClick: remove }, __( 'Remove Featured Content', 'daymark' ) )
			);
		}

		if ( ! choosingType ) {
			return el(
				PluginDocumentSettingPanel,
				{ name: 'daymark-featured-content', title: __( 'Featured Content', 'daymark' ) },
				el(
					'div',
					{ className: 'daymark-fc-type-buttons' },
					ALLOWED_TYPES.map( function ( type ) {
						return el(
							Button,
							{ key: type, variant: 'secondary', onClick: function () { setChoosingType( type ); } },
							TYPE_LABELS[ type ] || type
						);
					} )
				)
			);
		}

		return el(
			PluginDocumentSettingPanel,
			{ name: 'daymark-featured-content', title: __( 'Featured Content', 'daymark' ) },
			el( 'p', {}, TYPE_LABELS[ choosingType ] || choosingType ),
			el( Button, { variant: 'secondary', onClick: function () { pickFromLibrary( choosingType ); } }, __( 'Choose from Media Library', 'daymark' ) ),
			el( 'p', { className: 'daymark-fc-or' }, __( 'Or paste a URL (YouTube, Vimeo, a podcast episode link, etc.):', 'daymark' ) ),
			el( TextControl, {
				value: urlValue,
				onChange: setUrlValue,
				placeholder: __( 'https://…', 'daymark' ),
			} ),
			el(
				'div',
				{ className: 'daymark-fc-url-actions' },
				el( Button, { variant: 'tertiary', disabled: ! urlValue, onClick: function () { previewUrl( choosingType ); } }, __( 'Preview', 'daymark' ) ),
				el( Button, { variant: 'primary', disabled: ! urlValue, onClick: function () { useUrl( choosingType ); } }, __( 'Use this URL', 'daymark' ) )
			),
			preview && preview.loading ? el( Spinner, {} ) : null,
			preview && ! preview.loading && preview.embed && preview.embed.html ? el( RawHTML, { className: 'daymark-fc-preview' }, preview.embed.html ) : null,
			preview && ! preview.loading && ! preview.embed ? el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( "Couldn't generate a preview for this URL — it will still be saved and rendered on the front end.", 'daymark' )
			) : null,
			el( Button, { variant: 'link', onClick: resetChooser }, __( 'Cancel', 'daymark' ) )
		);
	}

	wp.plugins.registerPlugin( 'daymark-featured-content', { render: FeaturedContentPanel } );
} )( window.wp );
