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
 * Clicking "Set featured content" opens the same classic media modal
 * overlay "Set featured image" does (via wp.media()), titled "Featured
 * content" instead of "Featured image", with a third "Add by URL" tab
 * alongside core's own "Upload files"/"Media Library" tabs — a real change
 * from this feature's second draft, which expanded an inline sidebar picker
 * (a "Choose from Media Library" button plus a plain URL field) instead of
 * reusing the modal overlay. Reaching that third tab means extending
 * wp.media's own Backbone-based frame classes (wp.media.view.MediaFrame.Select),
 * reusing wp.media.view.Embed for the URL tab's own input+live-preview UI —
 * the same view core's own classic "Add Media" button's "Insert from URL"
 * tab uses — rather than the documented, stable `editor.PostFeaturedImage`
 * filter above. This part of the WP media JS API has no public reference
 * docs and could not be exercised against a live site in this environment
 * before shipping; two real bugs turned up on Jeff's own click-through and
 * were fixed from that feedback rather than further guessing: (1) adding
 * wp.media.controller.Embed as a second top-level frame *state* (an early
 * version of this file did, via this.states.add()) introduced an unwanted
 * "Actions" sidebar, because that controller defaults to menu: 'default' —
 * the mechanism the classic, multi-state "Insert Media" modal uses to list
 * Insert Media/Create Gallery/Insert from URL as top-level sidebar actions;
 * core's real single-state "Featured image" frame never creates that
 * sidebar at all. (2) Once that state was removed and the Embed controller
 * kept only as a plain, un-registered props model instead (never added via
 * this.states.add()), the "Add by URL" router tab (browseRouter() below)
 * still didn't render anything when clicked, because a router tab only
 * ever switches this frame's *content mode*, never which *state* is
 * active — a content:create handler has to be bound to the router tab's
 * own key ('daymark-embed'), not to the Embed controller's own default
 * content mode ('embed'), which is only reachable by activating it as a
 * genuine state (i.e. via a menu, never a router tab). getFeaturedContentFrameClass()
 * still feature-detects every class it touches and openMediaPicker() still
 * falls back to a plain wp.media() call (Upload files + Media Library only,
 * no URL tab) if any of them are missing or constructing the custom frame
 * throws — so a wrong assumption about this internal API costs the URL
 * tab, never the whole control. Flagged for Jeff to re-verify against a
 * real site: opening the modal, confirming no Actions sidebar, and that
 * pasting a URL under "Add by URL" now shows a live preview and "Use this
 * URL" actually saves it.
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
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;

	var config = window.daymarkFeaturedContent || {};
	var META_TYPE = config.metaType || '_daymark_featured_content_type';
	var META_DATA = config.metaData || '_daymark_featured_content';

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

	// Lazily built, memoized once the required wp.media classes are confirmed
	// present — see getFeaturedContentFrameClass().
	var FeaturedContentFrame;
	var frameBuildAttempted = false;

	/**
	 * Builds (once) a wp.media frame class that behaves exactly like core's
	 * own Featured Image frame (wp.media.view.MediaFrame.Select — "Upload
	 * files"/"Media Library" tabs, a working "Use this file" button) plus one
	 * extra "Add by URL" tab, reusing wp.media.controller.Embed and
	 * wp.media.view.Embed for that tab's own input+live-preview UI — the
	 * same classes WordPress core's own classic "Add Media" button's own
	 * "Insert from URL" tab is built from.
	 *
	 * Every class this touches is feature-detected first; if any is missing
	 * (a WordPress version this wasn't built against, a customized media
	 * modal), this returns null and the caller falls back to a plain
	 * wp.media() call with no URL tab, rather than risk a half-working frame.
	 *
	 * @return {Function|null} The frame constructor, or null if unsupported.
	 */
	function getFeaturedContentFrameClass() {
		if ( frameBuildAttempted ) {
			return FeaturedContentFrame;
		}

		frameBuildAttempted = true;

		var MediaFrameSelect = wp.media && wp.media.view && wp.media.view.MediaFrame && wp.media.view.MediaFrame.Select;

		if (
			! MediaFrameSelect ||
			! window.Backbone ||
			! wp.media.controller || ! wp.media.controller.Embed ||
			! wp.media.view.Embed ||
			! wp.media.view.l10n
		) {
			return null;
		}

		try {
			// A plain URL input + live-preview view (reusing wp.media.view.Embed,
			// the same class core's own classic "Insert from URL" tab uses) plus
			// our own submit button rendered directly alongside it. This is
			// deliberately its own small Backbone view, not a second top-level
			// wp.media state: an earlier version added the Embed controller via
			// this.states.add(), which — because that controller defaults to
			// menu: 'default' — introduced an unwanted "Actions" sidebar (the
			// mechanism the classic, multi-state "Insert Media" modal uses to
			// list its top-level actions). A follow-up fix suppressed that with
			// menu: false, but a live click-through then showed the "Add by
			// URL" router tab highighting itself without ever rendering the
			// embed content at all: a router tab only ever switches this
			// frame's *content mode* (browseRouter below), it does not switch
			// which *state* is active — so a content:create handler has to be
			// bound to the router tab's own key ('daymark-embed'), not to the
			// Embed controller's own default content mode ('embed'), which is
			// only ever reached by activating it as a genuine top-level state
			// (i.e. via a menu, not a router tab). Not adding it as a state at
			// all — just keeping one persistent Embed *controller* instance
			// (below) purely as the props model this view reads/writes — sidesteps
			// both problems together: no second state, so no menu sidebar, and
			// no reliance on state-switching for the router tab to work.
			var EmbedTabView = Backbone.View.extend( {
				className: 'daymark-fc-embed-tab',

				initialize: function ( options ) {
					this.frame = options.frame;
					this.embedModel = options.embedModel;
				},

				render: function () {
					this.embedView = new wp.media.view.Embed( {
						controller: this.frame,
						model: this.embedModel,
					} );
					this.embedView.render();

					var button = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button button-primary daymark-fc-embed-submit';
					button.textContent = __( 'Use this URL', 'daymark' );
					button.addEventListener( 'click', this.handleSubmit.bind( this ) );

					this.$el.empty().append( this.embedView.el, button );

					return this;
				},

				handleSubmit: function () {
					var url = this.embedModel.props ? this.embedModel.props.get( 'url' ) : '';

					if ( ! url ) {
						return;
					}

					this.frame.trigger( 'daymark:url-selected', url );
					this.frame.close();
				},
			} );

			FeaturedContentFrame = MediaFrameSelect.extend( {
				initialize: function () {
					MediaFrameSelect.prototype.initialize.apply( this, arguments );

					// Never registered via this.states.add() — see the note
					// above. Just a plain props model for the URL input/preview
					// to read and write, independent of whatever state (upload
					// or browse) is actually active while this tab is open.
					this.daymarkEmbedModel = new wp.media.controller.Embed( { metadata: {} } );
				},

				bindHandlers: function () {
					MediaFrameSelect.prototype.bindHandlers.apply( this, arguments );

					this.on( 'content:create:daymark-embed content:render:daymark-embed', this.daymarkEmbedContent, this );
				},

				daymarkEmbedContent: function () {
					var view = new EmbedTabView( {
						frame: this,
						embedModel: this.daymarkEmbedModel,
					} ).render();

					this.content.set( view );
				},

				browseRouter: function ( routerView ) {
					routerView.set( {
						upload: {
							text: wp.media.view.l10n.uploadFilesTitle,
							priority: 20,
						},
						browse: {
							text: wp.media.view.l10n.mediaLibraryTitle,
							priority: 40,
						},
						'daymark-embed': {
							text: __( 'Add by URL', 'daymark' ),
							priority: 60,
						},
					} );
				},
			} );
		} catch ( err ) {
			FeaturedContentFrame = null;
		}

		return FeaturedContentFrame;
	}

	/**
	 * Open the media picker for Featured Content — the same modal overlay
	 * "Set featured image" opens, titled "Featured content", scoped to
	 * audio/video. Uses the custom frame above when available (adding the
	 * "Add by URL" tab); otherwise falls back to a plain wp.media() call
	 * with just the two default tabs. Requires
	 * Daymark_Featured_Content::enqueue_editor_assets() to have called
	 * wp_enqueue_media() — the block editor's own Featured Image panel
	 * doesn't use wp.media at all (it fetches media via REST directly), so
	 * it can't be assumed available on every post-edit screen without
	 * asking for it explicitly.
	 *
	 * @param {Function} onLibrarySelect Called with the picked attachment's REST-shaped object.
	 * @param {Function} onUrlSelect     Called with a pasted URL string.
	 */
	function openMediaPicker( onLibrarySelect, onUrlSelect ) {
		if ( ! wp.media ) {
			return;
		}

		var options = {
			title: __( 'Featured content', 'daymark' ),
			library: { type: [ 'audio', 'video' ] },
			multiple: false,
			button: { text: __( 'Use this file', 'daymark' ) },
		};

		var FrameClass = getFeaturedContentFrameClass();
		var frame;

		if ( FrameClass ) {
			try {
				frame = new FrameClass( options );
			} catch ( err ) {
				frame = null;
			}
		}

		if ( ! frame ) {
			frame = wp.media( options );
		}

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' ).first();

			if ( selection ) {
				onLibrarySelect( selection.toJSON() );
			}
		} );

		frame.on( 'daymark:url-selected', function ( url ) {
			onUrlSelect( url );
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
	 * Two states: unset (a single toggle button opening the media modal) or
	 * already set (a one-line summary plus Replace/Remove — Replace reopens
	 * the same modal).
	 */
	function FeaturedContentControl() {
		var meta = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor ? editor.getEditedPostAttribute( 'meta' ) || {} : {};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;
		var current = readFeaturedContent( meta );

		function handleLibrarySelect( attachment ) {
			var mime = attachment.mime || '';
			var kind = 0 === mime.indexOf( 'audio/' ) ? 'audio' : ( 0 === mime.indexOf( 'video/' ) ? 'video' : '' );

			if ( ! kind ) {
				return;
			}

			saveFeaturedContent( editPost, kind, { source: 'library', attachment_id: attachment.id } );
		}

		function handleUrlSelect( url ) {
			if ( ! url ) {
				return;
			}

			saveFeaturedContent( editPost, guessUrlKind( url ), { source: 'url', url: url } );
		}

		function openPicker() {
			openMediaPicker( handleLibrarySelect, handleUrlSelect );
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
						{ type: 'button', className: 'daymark-fc-link', onClick: openPicker },
						__( 'Replace', 'daymark' )
					),
					el(
						'button',
						{
							type: 'button',
							className: 'daymark-fc-link daymark-fc-link--danger',
							onClick: function () {
								clearFeaturedContent( editPost );
							},
						},
						__( 'Remove', 'daymark' )
					)
				)
			);
		}

		return el(
			'button',
			{ type: 'button', className: 'daymark-fc-toggle', onClick: openPicker },
			__( 'Set featured content', 'daymark' )
		);
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
