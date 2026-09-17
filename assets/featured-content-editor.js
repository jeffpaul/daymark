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
 * wp.media's own Backbone-based frame classes (wp.media.view.MediaFrame.Select) —
 * a part of the WP media JS API with no public reference docs, and this
 * environment has no live WordPress site to click through against before
 * shipping. Three real bugs turned up across three rounds of Jeff's own
 * click-through testing, each fixed from that feedback rather than further
 * guessing: (1) an early version added wp.media.controller.Embed as a
 * second top-level frame *state* (this.states.add()), which — since that
 * controller defaults to menu: 'default' — introduced an unwanted "Actions"
 * sidebar (the mechanism the classic, multi-state "Insert Media" modal uses
 * to list its own top-level actions); core's real single-state "Featured
 * image" frame never creates that sidebar at all. (2) Removing the state
 * (keeping the Embed controller only as a plain props model) fixed the
 * sidebar, but the "Add by URL" tab then rendered nothing when clicked: a
 * router tab (browseRouter() below) only ever switches this frame's
 * *content mode*, never which *state* is active, so the content:create
 * handler had to bind to the router tab's own key ('daymark-embed') rather
 * than the Embed controller's own default content mode ('embed'), which is
 * only reachable by activating it as a genuine state. (3) Once that bug was
 * fixed, the tab rendered *something*, but with visibly overlapping
 * elements that persisted even after removing a redundant duplicate event
 * binding suspected as the cause and hardening the CSS around it — meaning
 * the actual problem was inside wp.media.view.Embed's own undocumented
 * internal markup/layout, a view this file was reusing for the tab's
 * input+live-preview UI (the same view core's own classic "Add Media"
 * button's "Insert from URL" tab uses). Rather than keep guessing at an
 * internal view's layout with no way to inspect it directly, the tab's
 * content is no longer wp.media.view.Embed at all — it's a small, fully
 * self-built view (its own label/input/preview/button) with a live preview
 * via the existing GET /daymark/v1/featured-content/oembed route, built for
 * exactly this and left unused once an earlier draft started relying on
 * wp.media.view.Embed's own built-in scanning instead. getFeaturedContentFrameClass()
 * still feature-detects every class it touches and openMediaPicker() still
 * falls back to a plain wp.media() call (Upload files + Media Library only,
 * no URL tab) if any of them are missing or constructing the custom frame
 * throws — so a wrong assumption about this internal API costs the URL
 * tab, never the whole control. Flagged for Jeff to re-verify against a
 * real site: opening the modal, confirming no Actions sidebar, and that
 * pasting a URL under "Add by URL" now shows a live preview with no
 * overlapping elements, and "Use this URL" actually saves it.
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
 *
 * Once set, FeaturedContentControl's own "already set" state renders a
 * small preview above its summary text/Replace/Remove row — matching core's
 * own Featured Image thumbnail — via FeaturedContentPreview, using the same
 * source_url/oEmbed/native-fallback resolution order described on that
 * function's own docblock.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.data || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
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
	 * Whether a URL itself is a direct media file (matches a known
	 * audio/video extension) rather than a provider *page* URL (a Vimeo/
	 * YouTube watch page, a podcast episode page with no file extension,
	 * etc.). Only a direct file URL can ever be played by a plain native
	 * <audio>/<video src> element — a provider page URL is never itself a
	 * media file the browser can decode, so falling back to a native tag
	 * for one (as an earlier version of both this file and
	 * Daymark_Featured_Content::render_audio()/render_video() did whenever
	 * oEmbed found nothing) always renders a broken player, never a
	 * degraded-but-working one.
	 *
	 * @param {string} url
	 * @return {boolean}
	 */
	function isDirectMediaUrl( url ) {
		var clean = String( url ).split( '?' )[ 0 ].split( '#' )[ 0 ];
		var match = clean.match( /\.([a-zA-Z0-9]+)$/ );
		var ext = match ? match[ 1 ].toLowerCase() : '';

		return -1 !== AUDIO_EXTENSIONS.indexOf( ext ) || -1 !== VIDEO_EXTENSIONS.indexOf( ext );
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

		if ( ! MediaFrameSelect || ! window.Backbone || ! wp.media.view.l10n ) {
			return null;
		}

		try {
			// A plain, fully self-built URL input + live-preview view — no
			// longer wp.media.view.Embed (core's own "Insert from URL" tab
			// content). Two earlier attempts reused that view and each fixed
			// a real bug reported from a live click-through (an unwanted
			// "Actions" sidebar from wp.media.controller.Embed's own
			// menu: 'default' default; then the tab not rendering at all,
			// since a router tab switches this frame's content mode, never
			// which state is active, so content:create had to bind to the
			// router key instead of the Embed controller's own content
			// mode) — but a third click-through still showed overlapping
			// elements even after removing the state and fixing the event
			// binding, which means the remaining problem is inside
			// wp.media.view.Embed's own undocumented internal markup/CSS,
			// not anything this file controls. Rather than keep guessing at
			// an internal, unreferenced view's layout, this tab's content is
			// now a small, fully self-contained view built the same plain
			// way every other piece of markup in this file already is —
			// its own label/input/preview/button, with a live preview via
			// the existing GET /daymark/v1/featured-content/oembed route
			// (built for exactly this, then left unused once the second
			// draft started relying on wp.media.view.Embed's own built-in
			// scanning instead — see enqueue_editor_assets() for the
			// restored wp-api-fetch dependency/oembedEndpoint config this
			// needs).
			var EmbedTabView = Backbone.View.extend( {
				className: 'daymark-fc-embed-tab',

				events: {
					'input .daymark-fc-embed-url': 'handleInput',
					'click .daymark-fc-embed-submit': 'handleSubmit',
				},

				initialize: function ( options ) {
					this.frame = options.frame;
					this._previewTimer = null;
				},

				render: function () {
					this.$el.html(
						'<label class="daymark-fc-embed-label" for="daymark-fc-embed-url">' +
							__( 'Paste a URL', 'daymark' ) +
							'</label>' +
							'<input type="url" id="daymark-fc-embed-url" class="daymark-fc-embed-url" placeholder="https://" />' +
							'<div class="daymark-fc-embed-preview" hidden></div>' +
							'<button type="button" class="button button-primary daymark-fc-embed-submit">' +
							__( 'Use this URL', 'daymark' ) +
							'</button>'
					);

					return this;
				},

				handleInput: function () {
					var url = this.$( '.daymark-fc-embed-url' ).val().trim();

					clearTimeout( this._previewTimer );

					if ( ! url ) {
						this.renderPreview( null );
						return;
					}

					this._previewTimer = setTimeout( this.fetchPreview.bind( this, url ), 500 );
				},

				fetchPreview: function ( url ) {
					var endpoint = ( window.daymarkFeaturedContent || {} ).oembedEndpoint;

					if ( ! wp.apiFetch || ! endpoint ) {
						return;
					}

					var self = this;

					wp.apiFetch( { url: endpoint + '?url=' + encodeURIComponent( url ) } )
						.then( function ( response ) {
							self.renderPreview( response && response.embed ? response.embed : null );
						} )
						.catch( function () {
							self.renderPreview( null );
						} );
				},

				renderPreview: function ( embed ) {
					var $preview = this.$( '.daymark-fc-embed-preview' );

					if ( embed && embed.html ) {
						// embed.html is Daymark_Subscription_Oembed::resolve()'s own
						// rebuilt, attribute-allowlisted markup (never a provider's
						// raw response) — the same trusted shape PostScreen's own
						// oEmbed preview in assets/app.js already renders this way.
						$preview.html( embed.html ).prop( 'hidden', false );
					} else {
						$preview.empty().prop( 'hidden', true );
					}
				},

				handleSubmit: function () {
					var url = this.$( '.daymark-fc-embed-url' ).val().trim();

					if ( ! url ) {
						return;
					}

					this.frame.trigger( 'daymark:url-selected', url );
					this.frame.close();
				},
			} );

			FeaturedContentFrame = MediaFrameSelect.extend( {
				bindHandlers: function () {
					MediaFrameSelect.prototype.bindHandlers.apply( this, arguments );

					// content:create:<mode> is the single event a router tab's
					// content.mode() switch fires per activation.
					this.on( 'content:create:daymark-embed', this.daymarkEmbedContent, this );
				},

				daymarkEmbedContent: function () {
					this.content.set( new EmbedTabView( { frame: this } ).render() );
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
	 * A "All / Video / Audio" content-type filter for the "Media Library"
	 * tab, requested directly once the tab's default state — every audio and
	 * video file mixed together, with no way to narrow to just one — shipped
	 * with no way to tell them apart other than scrolling past whichever
	 * type isn't wanted.
	 *
	 * Two earlier attempts (still visible in this function's own git history)
	 * tried to hook the browse content view's own internal lifecycle —
	 * `content:create:browse`/`content:render:browse`, reading
	 * `frame.content.get().collection`/`.toolbar` — guessed by analogy with
	 * the "Add by URL" tab's own (real, working) `content:create:<mode>`
	 * binding. The second attempt (binding both events, plus a
	 * `console.warn()` on any thrown error) still shipped with no filter
	 * ever appearing and no warning logged either, which is the tell: the
	 * failure isn't an exception mid-lookup, it's this code's own early
	 * `return` guards silently no-op'ing because the assumed property names
	 * (`.content.get()`, `.collection`, `.toolbar`) are wrong, or the events
	 * fire before/after the view actually exists — exactly the same category
	 * of undocumented-internals guess that already cost three real bugs on
	 * the "Add by URL" tab (this file's own docblock above), just not caught
	 * by CI or a screenshot until now.
	 *
	 * Rewritten to depend on nothing but two things confirmed stable: (1)
	 * `frame.el`/`frame.$el` — not a wp.media guess at all, every
	 * Backbone.View (which `wp.media.view.MediaFrame` is) sets these in its
	 * own constructor, long before `render()`/`open()`; (2) `frame.state()`
	 * and `state.get( 'library' )` — wp.media's own public, commonly-used
	 * frame API for reaching the active state's attachments query
	 * (`frame.state().get( 'selection' )` for the current picks is the same
	 * pattern countless real-world plugins already rely on), rather than the
	 * browse content view's own private, undocumented `.collection`
	 * property. `library.props` is the same Backbone model
	 * `wp.media.model.Query` already watches for a `type` change — the one
	 * piece of the original design that was never actually in question.
	 *
	 * For *where* to insert the `<select>`, this no longer waits on any
	 * Backbone event at all — it watches the frame's own rendered DOM via
	 * `MutationObserver` for `.media-toolbar-secondary` (the toolbar region
	 * "Filter by date" already sits in on every "Media Library" tab, ours
	 * and core's alike, confirmed directly from Jeff's own two side-by-side
	 * screenshots) to exist, and inserts there. This sidesteps every one of
	 * the Backbone-internals questions above by relying only on rendered
	 * markup, which — unlike undocumented method/event/property names —
	 * has to match reality once the toolbar is actually on screen,
	 * regardless of which internal mechanism produced it.
	 *
	 * Deliberately Video/Audio only, no Image option: `openMediaPicker()`'s
	 * own `library: { type: [ 'audio', 'video' ] }` restriction already
	 * excludes every other mime type from the underlying query before this
	 * filter ever runs, so an Image option would only ever show zero results
	 * — this filter narrows *within* that existing restriction, it doesn't
	 * loosen it.
	 *
	 * Feature-detected and wrapped defensively, matching this file's
	 * established posture toward every other wp.media internal it touches: a
	 * missing/unexpected shape (a future core version, a customized media
	 * modal) just means no filter control appears, never a broken picker.
	 *
	 * @param {Object} frame The just-constructed wp.media frame instance.
	 */
	function bindLibraryTypeFilter( frame ) {
		var observer = null;

		function insertFilter() {
			try {
				var $jq = window.jQuery;

				if ( ! $jq || ! frame.$el || ! frame.$el.length ) {
					return;
				}

				var state = frame.state && frame.state();
				var library = state && state.get && state.get( 'library' );

				if ( ! library || ! library.props || 'function' !== typeof library.props.set ) {
					return;
				}

				var $secondary = frame.$el.find( '.media-toolbar-secondary' ).first();

				if ( ! $secondary.length || $secondary.find( '.daymark-fc-typefilter' ).length ) {
					return;
				}

				var $select = $jq(
					'<select class="daymark-fc-typefilter" aria-label="' +
						__( 'Filter by content type', 'daymark' ) +
						'">' +
						'<option value="">' +
						__( 'All media types', 'daymark' ) +
						'</option>' +
						'<option value="video">' +
						__( 'Video', 'daymark' ) +
						'</option>' +
						'<option value="audio">' +
						__( 'Audio', 'daymark' ) +
						'</option>' +
						'</select>'
				);

				$select.on( 'change', function () {
					var value = $jq( this ).val();

					library.props.set( 'type', value ? value : [ 'audio', 'video' ] );
				} );

				$secondary.prepend( $select );
			} catch ( err ) {
				// A missing/unexpected internal shape costs only this filter
				// control — the picker itself is untouched. Logged (not
				// thrown) so a real browser session can actually reveal which
				// assumption broke, since this file has no other diagnostic
				// path for a pure client-side Backbone/DOM issue like this
				// one.
				if ( window.console && window.console.warn ) {
					window.console.warn( '[Daymark Featured Content] library type filter skipped:', err );
				}
			}
		}

		frame.on( 'open', function () {
			insertFilter();

			if ( observer || ! window.MutationObserver || ! frame.el ) {
				return;
			}

			try {
				observer = new MutationObserver( insertFilter );
				observer.observe( frame.el, { childList: true, subtree: true } );
			} catch ( err ) {
				observer = null;
			}
		} );

		frame.on( 'close', function () {
			if ( observer ) {
				observer.disconnect();
				observer = null;
			}
		} );
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

		bindLibraryTypeFilter( frame );

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
	 * A small preview of the currently-set Featured Content, mirroring
	 * core's own Featured Image thumbnail. A `library` attachment renders
	 * its own native <audio>/<video> element directly, via that
	 * attachment's REST `source_url` (resolved through the `core` data
	 * store's `getMedia()` — the same lookup core's own PostFeaturedImage
	 * component already uses for its own thumbnail, so no new script
	 * dependency is needed here: `wp-core-data` is already a hard
	 * dependency of the block editor itself). A profiled `url` resolves a
	 * live oEmbed preview through the same
	 * GET /daymark/v1/featured-content/oembed route the "Add by URL" tab
	 * already uses, falling back to a plain native <audio>/<video> tag for
	 * a direct file URL an oEmbed provider can't resolve — the same
	 * fallback order Daymark_Featured_Content::render_audio()/render_video()
	 * already use server-side, so the sidebar preview and the eventual
	 * front-end rendering never disagree about what a given value shows.
	 *
	 * @param {{type: string, data: Object}} props Resolved Featured Content.
	 * @return {Object|null}
	 */
	function FeaturedContentPreview( props ) {
		var type = props.type;
		var data = props.data;
		var isUrl = 'url' === data.source;

		var attachment = useSelect(
			function ( select ) {
				return isUrl ? null : select( 'core' ).getMedia( data.attachment_id );
			},
			[ isUrl, data.attachment_id ]
		);

		var embedState = useState( null );
		var embed = embedState[ 0 ];
		var setEmbed = embedState[ 1 ];

		useEffect(
			function () {
				if ( ! isUrl ) {
					return;
				}

				var endpoint = ( window.daymarkFeaturedContent || {} ).oembedEndpoint;

				if ( ! wp.apiFetch || ! endpoint ) {
					setEmbed( false );
					return;
				}

				var cancelled = false;

				wp.apiFetch( { url: endpoint + '?url=' + encodeURIComponent( data.url ) } )
					.then( function ( response ) {
						if ( ! cancelled ) {
							setEmbed( response && response.embed ? response.embed : false );
						}
					} )
					.catch( function () {
						if ( ! cancelled ) {
							setEmbed( false );
						}
					} );

				return function () {
					cancelled = true;
				};
			},
			[ isUrl, data.url ]
		);

		if ( ! isUrl ) {
			if ( ! attachment || ! attachment.source_url ) {
				return null;
			}

			return el(
				'div',
				{ className: 'daymark-fc-preview' },
				'audio' === type
					? el( 'audio', { src: attachment.source_url, controls: true } )
					: el( 'video', { src: attachment.source_url, controls: true } )
			);
		}

		if ( embed && embed.html ) {
			// embed.html is Daymark_Subscription_Oembed::resolve()'s own
			// rebuilt, attribute-allowlisted markup (never a provider's raw
			// response) — the same trusted shape the "Add by URL" tab's own
			// preview already renders this way.
			return el( 'div', {
				className: 'daymark-fc-preview',
				dangerouslySetInnerHTML: { __html: embed.html },
			} );
		}

		// No usable oEmbed (checked and found none, or the fetch itself
		// failed). `embed` is still `null` while genuinely loading, so this
		// doesn't flash anything before the fetch above resolves either
		// way. A native <audio>/<video src> fallback only ever works for a
		// direct media file — a provider page URL (a Vimeo/YouTube watch
		// page, a private/unlisted video oEmbed declined to embed, a
		// podcast episode page with no real file extension) is never
		// itself something the browser can decode, so falling back to one
		// unconditionally here would just swap a missing preview for a
		// guaranteed-broken one.
		if ( false === embed ) {
			if ( isDirectMediaUrl( data.url ) ) {
				return el(
					'div',
					{ className: 'daymark-fc-preview' },
					'audio' === type
						? el( 'audio', { src: data.url, controls: true } )
						: el( 'video', { src: data.url, controls: true } )
				);
			}

			return el(
				'p',
				{ className: 'daymark-fc-preview-unavailable' },
				__( 'No preview available for this link.', 'daymark' )
			);
		}

		return null;
	}

	/**
	 * The control rendered right after core's own Featured Image button.
	 * Two states: unset (a single toggle button opening the media modal) or
	 * already set (a preview plus a one-line summary and Replace/Remove —
	 * Replace reopens the same modal).
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
				el( FeaturedContentPreview, { type: current.type, data: current.data } ),
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
