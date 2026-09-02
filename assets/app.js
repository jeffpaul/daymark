/**
 * Daymark app shell — vanilla ES2020, no framework, no build step.
 *
 * Screen routing is hash-based within /daymark. The server-rendered
 * screen (home | notifications) arrives via window.daymarkApp.screen.
 *
 * Screens: #home, #create, #publish, #success, #notifications.
 * The AI Assist sheet and the subscription-post detail sheet are
 * overlays, not routed screens.
 */
(function () {
	'use strict';

	// --- Config ---
	const config = window.daymarkApp || {};
	const connectors = Array.isArray(config.connectors) ? config.connectors : [];
	const typeDefaults = config.defaults || {};
	const siteCategories = Array.isArray(config.categories) ? config.categories : [];
	const categoryDefaults = config.categoryDefaults || {};
	const root = document.getElementById('daymark-app');

	if (!root) {
		return;
	}

	// --- App state ---
	const state = {
		files: [], // { id, file, url, kind, alt, altStatus, altEdited }
		caption: '',
		title: '', // optional Title field value (audio/video by policy)
		titleStatus: 'idle', // idle | loading | done — AI prefill lifecycle
		titleEdited: false, // author typed a title: never overwrite it
		transcript: '', // optional Transcript field value (audio/video only)
		transcriptStatus: 'idle', // idle | loading | done — manual generation lifecycle
		transcriptEdited: false, // author typed/edited it: don't clobber on a later manual generation
		tags: [],
		primaryType: 'note',
		// Set by the Home launcher before navigating to #create so the
		// composer opens pre-set to the chosen type; cleared by
		// resetComposer(). Only a fallback — picking files or resuming a
		// draft still wins, exactly as effectiveType() already resolves.
		pendingType: null,
		targets: [],
		categories: [], // selected category term IDs (numbers)
		aiAssistUsed: false,
		lastPublish: null, // { response, targets, type }
		fileCounter: 0,
		editing: null, // { id, type, media: [{id, kind, thumbnail, filename}] } while editing a draft
		helpers: [], // enabled controllable third-party publishing helper ids
		offlineQueueId: null, // IndexedDB id while this composition is queued offline (see submitOrQueue())
	};

	const TYPE_LABELS = {
		note: 'Note',
		image: 'Image',
		gallery: 'Gallery',
		video: 'Video',
		audio: 'Audio',
		mixed: 'Mixed media',
	};

	// --- Helpers ---

	/**
	 * Escape a value for safe interpolation into HTML (text or attribute).
	 */
	function esc(value) {
		return String(value === null || value === undefined ? '' : value).replace(
			/[&<>"']/g,
			(ch) =>
				({
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;',
				}[ch])
		);
	}

	/**
	 * An <img> whose src isn't fully trusted to resolve (a subscription's
	 * favicon guess is the common case — see fallback_favicon_url()
	 * server-side, an unverified `/favicon.ico` URL that 404s more often
	 * than not) wired to degrade to a glyph span in the same visual slot on
	 * load failure, rather than the browser's broken-image icon. Pairs with
	 * the single delegated 'error' listener registered at boot below.
	 */
	function imgWithFallback(src, cssClass, glyph) {
		const root = cssClass.split(' ')[0];
		return `<img class="${esc(cssClass)}" src="${esc(src)}" alt="" data-img-fallback="${esc(
			glyph
		)}" data-img-fallback-class="${esc(root)} ${esc(root)}--glyph" />`;
	}

	/**
	 * Reduce an HTML string (e.g. comment content) to plain text.
	 */
	function toPlainText(html) {
		const div = document.createElement('div');
		div.innerHTML = String(html === null || html === undefined ? '' : html);
		return (div.textContent || '').trim();
	}

	/**
	 * Human relative timestamp. Accepts ISO 8601 or MySQL datetime strings.
	 */
	function relativeTime(value) {
		if (!value) {
			return '';
		}
		let date = new Date(value);
		if (Number.isNaN(date.getTime()) && typeof value === 'string') {
			date = new Date(value.replace(' ', 'T'));
		}
		if (Number.isNaN(date.getTime())) {
			return '';
		}
		const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
		if (seconds < 60) {
			return 'Just now';
		}
		const minutes = Math.floor(seconds / 60);
		if (minutes < 60) {
			return minutes + 'm ago';
		}
		const hours = Math.floor(minutes / 60);
		if (hours < 24) {
			return hours + 'h ago';
		}
		const days = Math.floor(hours / 24);
		if (days < 7) {
			return days + 'd ago';
		}
		return date.toLocaleDateString();
	}

	function connectorLabel(id) {
		const found = connectors.find((c) => c.id === id);
		return found ? found.label : id;
	}

	function siteLink(path) {
		return (config.siteUrl || '/').replace(/\/$/, '') + '/' + path.replace(/^\//, '');
	}

	// Home's merged Timeline feed page size — the infinite-scroll unit. A
	// page shorter than this means there is nothing more to load.
	const RECENT_PER_PAGE = 5;

	// Search's type-filter chips, mapped to _daymark_primary_type values
	// ('' = every type). Wired to GET /timeline?s=&type=.
	const SEARCH_FILTERS = [
		{ type: '', label: 'All' },
		{ type: 'image', label: 'Images' },
		{ type: 'video', label: 'Videos' },
		{ type: 'audio', label: 'Audio' },
		{ type: 'note', label: 'Notes' },
	];

	// Feather-style icon glyphs (inner SVG markup) for the persistent bottom
	// nav, matching the app's other inline icons. Text stays as the
	// accessible name and hover title — see NAV_TABS/navFooterMarkup().
	const TIMELINE_GLYPH =
		'<polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline>';
	const EXPLORE_GLYPH =
		'<circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>';
	const SEARCH_GLYPH =
		'<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>';
	const ME_GLYPH =
		'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>';

	// Generic 22x22 outline icon — the bottom nav's tabs, Explore's
	// "Browse by type" buttons, and Me's fallback avatar glyph all share it.
	function navIcon(glyph) {
		return `<svg class="daymark-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${glyph}</svg>`;
	}

	// The 4 Mark types the Home launcher offers — Gallery isn't its own
	// bubble since it's just "pick more than one image" in the existing
	// file picker (detectType() already upgrades image → gallery for you).
	const LAUNCHER_TYPES = ['image', 'video', 'audio', 'note'];

	// Kept as their own constant (not derived from the bottom nav's icons —
	// there is no per-type nav destination anymore) so the launcher's visual
	// vocabulary survives independently of whatever the nav tabs use.
	const TYPE_ICONS = {
		image:
			'<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
		video:
			'<polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>',
		audio:
			'<path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle>',
		note: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
	};

	const PLUS_GLYPH = '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>';

	// Outline icons for the comment/like stat row shown on Mark cards
	// throughout the app shell (Home, Search).
	const COMMENT_GLYPH =
		'<path d="M21 11.5a8.38 8.38 0 0 1-4.7 7.6 8.5 8.5 0 0 1-3.8.9H12a8.48 8.48 0 0 1-4-.9l-5 1 1-5a8.48 8.48 0 0 1-.9-4 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>';
	const HEART_GLYPH =
		'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21.2l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"></path>';

	function statIcon(glyph) {
		return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${glyph}</svg>`;
	}

	// A zero-count stat shows only its (dimmed) icon — no "0" — so the row
	// stays quiet until there's something to report.
	function renderStat(glyph, count, modifier, singular, plural) {
		const isActive = count > 0;
		const label = `${count} ${count === 1 ? singular : plural}`;
		return `<span class="daymark-stat daymark-stat--${modifier}${
			isActive ? ' daymark-stat--active' : ''
		}" aria-label="${esc(label)}">${statIcon(glyph)}${
			isActive ? `<span class="daymark-stat__count" aria-hidden="true">${count}</span>` : ''
		}</span>`;
	}

	function renderItemStats(item) {
		return `<span class="daymark-item-stats">${renderStat(
			COMMENT_GLYPH,
			item.comment_count || 0,
			'comments',
			'comment',
			'comments'
		)}${renderStat(HEART_GLYPH, item.like_count || 0, 'likes', 'like', 'likes')}</span>`;
	}

	// Pre-filters the composer's native file picker to match the launcher
	// bubble that was tapped — 'note' has no entry since it skips the
	// picker entirely (see CreateScreen.render()).
	const ACCEPT_BY_TYPE = {
		image: 'image/*',
		video: 'video/*',
		audio: 'audio/*',
	};

	// Camera-first: "assume I'm standing somewhere and want to publish," not
	// sitting at a desktop picking a file. For a typed launcher entry (image/
	// video/audio), the primary picker action opens the device's camera or
	// mic directly via the `capture` attribute — a secondary "Choose from
	// library" action clears it first, so an already-taken photo/clip stays
	// one tap away rather than disappearing. 'environment' (rear camera) is
	// ignored for audio capture direction but harmless — presence of the
	// attribute is what matters there.
	const CAPTURE_BY_TYPE = {
		image: 'environment',
		video: 'environment',
		audio: 'environment',
	};

	const CAPTURE_LABEL_BY_TYPE = {
		image: 'Take Photo',
		video: 'Record Video',
		audio: 'Record Audio',
	};

	const CAPTURE_HINT_BY_TYPE = {
		image: 'Opens your camera',
		video: 'Opens your camera',
		audio: 'Opens your microphone',
	};

	function launcherIcon(glyph) {
		return `<svg class="daymark-launcher__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${glyph}</svg>`;
	}

	/**
	 * Detect the Mark type from selected files (client-side mirror of the
	 * server-side detection used for routing defaults).
	 */
	function detectType(files) {
		if (!files.length) {
			return 'note';
		}
		const kinds = new Set(
			files.map((entry) => (entry.file.type || '').split('/')[0] || 'other')
		);
		if (kinds.size > 1) {
			return 'mixed';
		}
		const kind = kinds.values().next().value;
		if (kind === 'image') {
			return files.length > 1 ? 'gallery' : 'image';
		}
		if (kind === 'video') {
			return 'video';
		}
		if (kind === 'audio') {
			return 'audio';
		}
		return 'mixed';
	}

	function defaultTargetsFor(type) {
		const defaults = typeDefaults[type];
		return Array.isArray(defaults) ? defaults.slice() : [];
	}

	function defaultCategoriesFor(type) {
		const defaults = categoryDefaults[type];
		return Array.isArray(defaults) ? defaults.map(Number) : [];
	}

	// Bumped by resetComposer(), so an autosave response that arrives after
	// the composer has moved on to something else (a new Mark, a different
	// draft) can recognize it's stale and leave `state` alone instead of
	// clobbering whatever the composer is doing now.
	let composerGeneration = 0;

	function resetComposer() {
		composerGeneration += 1;
		clearTimeout(autosaveState.timer);
		autosaveState.timer = null;
		autosaveState.pendingRetry = false;
		state.files.forEach((entry) => {
			if (entry.url) {
				URL.revokeObjectURL(entry.url);
			}
		});
		state.files = [];
		state.caption = '';
		state.title = '';
		state.titleStatus = 'idle';
		state.titleEdited = false;
		state.transcript = '';
		state.transcriptStatus = 'idle';
		state.transcriptEdited = false;
		state.tags = [];
		state.primaryType = 'note';
		state.pendingType = null;
		state.targets = [];
		state.categories = [];
		state.aiAssistUsed = false;
		state.editing = null;
		state.helpers = [];
		state.offlineQueueId = null;
	}

	// Abandon the composer's in-progress work (starting a new Mark, or
	// opening a different draft) without losing it: fire a best-effort
	// autosave first — its payload is built synchronously from the current
	// `state` before this function returns, so the save (online or queued
	// offline) captures the final state even though resetComposer() below
	// wipes it immediately after. See runAutosave()'s composerGeneration
	// check for why the (possibly slow) response can never corrupt
	// whatever the composer does next.
	function abandonComposer() {
		runAutosave().catch(() => {});
		resetComposer();
	}

	// --- Autosave ---
	//
	// "Autosave everything. Nothing gets lost." The composer periodically
	// (and on every meaningful edit) saves in-progress work to a real
	// server-side draft, reusing the exact same create/update Mark REST
	// endpoints and publisher logic as an explicit "Save as Draft" tap —
	// autosave only ever sends status=draft, and only differs by the
	// `autosave=1` flag that routes it to its own, more generous rate-limit
	// bucket (Daymark_Rate_Limiter::ACTION_AUTOSAVE) so background autosave
	// activity can never exhaust the budget for the user's own deliberate
	// Publish/Save as Draft tap. This protects against a closed tab, a
	// backgrounded/reclaimed app, or an accidental navigation whenever
	// there's connectivity; when there isn't, submitOrQueue() below falls
	// back to the offline queue instead.
	const AUTOSAVE_DEBOUNCE_MS = 2500;

	const autosaveState = {
		timer: null,
		saving: false,
		pendingRetry: false,
		// The in-progress runAutosave() call's own completion promise —
		// waitForPendingAutosave() below awaits this so Publish/Save as
		// Draft can never race an autosave upload for the same file. Set
		// the instant `saving` flips true, resolved in runAutosave()'s
		// `finally`, so there's never a window where `saving` is true but
		// this is stale or missing.
		inFlight: null,
	};

	function setAutosaveStatus(kind) {
		const el = root.querySelector('[data-autosave-status]');
		if (!el) {
			return;
		}
		if (kind === 'saving') {
			el.textContent = 'Saving…';
		} else if (kind === 'saved') {
			el.textContent = 'Saved';
		} else if (kind === 'offline') {
			el.textContent = 'Saved offline — will sync automatically';
		} else if (kind === 'error') {
			el.textContent = 'Not saved yet — will retry';
		} else {
			el.textContent = '';
		}
	}

	// --- Offline queue (issue #121: "Creating while offline. Publish
	// later. Users shouldn't care.") ---
	//
	// Scope: this covers a composer session that's already open when
	// connectivity drops (or never had it) — the realistic "in a dead zone
	// mid-caption" case. It does NOT cover a cold load of /daymark itself
	// with zero connectivity: the service worker's scope is deliberately
	// restricted to the plugin assets directory (see daymark-sw.js) so it
	// can never cache the app-shell HTML or its per-request CSP nonce —
	// widening that is a separate, security-sensitive decision, tracked
	// on its own in issue #121, not assumed here.
	//
	// A Mark composed or edited offline is stored whole (including picked
	// media, as real Blobs — IndexedDB natively supports this) in IndexedDB
	// as a "pending" record, keyed by a local auto-incrementing id. It is
	// replayed through the exact same REST endpoints a live Publish/Save as
	// Draft/autosave already uses the moment connectivity returns, so the
	// server never sees a different code path for offline-originated work.
	const OFFLINE_DB_NAME = 'daymark-offline';
	const OFFLINE_DB_VERSION = 1;
	const OFFLINE_STORE = 'pending';

	function openOfflineDB() {
		return new Promise((resolve, reject) => {
			if (!('indexedDB' in window)) {
				reject(new Error('IndexedDB unavailable'));
				return;
			}
			const request = indexedDB.open(OFFLINE_DB_NAME, OFFLINE_DB_VERSION);
			request.onupgradeneeded = () => {
				const db = request.result;
				if (!db.objectStoreNames.contains(OFFLINE_STORE)) {
					db.createObjectStore(OFFLINE_STORE, { keyPath: 'id', autoIncrement: true });
				}
			};
			request.onsuccess = () => resolve(request.result);
			request.onerror = () => reject(request.error);
		});
	}

	function idbRequest(request) {
		return new Promise((resolve, reject) => {
			request.onsuccess = () => resolve(request.result);
			request.onerror = () => reject(request.error);
		});
	}

	// `status` distinguishes *why* a record is still local-only, so the
	// Pending section (renderPendingItem()) can show something accurate
	// instead of always saying "Offline": 'uploading' (a real request is
	// running right now — the default the moment Publish is tapped, if
	// there's connectivity at all), 'queued' (no connectivity, waiting for
	// the 'online' event), or 'error' (a real, non-connectivity failure —
	// needs the user to look at it). Defaults to 'queued' so every existing
	// caller (submitOrQueue()'s offline fallback) keeps its exact current
	// meaning without having to pass it explicitly.
	async function queuePendingMark(targetId, payload, status = 'queued') {
		const db = await openOfflineDB();
		const store = db.transaction(OFFLINE_STORE, 'readwrite').objectStore(OFFLINE_STORE);
		const now = Date.now();
		return idbRequest(store.add({ targetId, payload, status, createdAt: now, updatedAt: now }));
	}

	async function updatePendingMark(id, targetId, payload, status = 'queued') {
		const db = await openOfflineDB();
		const store = db.transaction(OFFLINE_STORE, 'readwrite').objectStore(OFFLINE_STORE);
		const existing = await idbRequest(store.get(id));
		const record = existing || { id, createdAt: Date.now() };
		record.targetId = targetId;
		record.payload = payload;
		record.status = status;
		if (status !== 'error') {
			delete record.errorMessage;
		}
		record.updatedAt = Date.now();
		await idbRequest(store.put(record));
		return id;
	}

	// Creates a new pending record, or overwrites the one this composer
	// session already queued — never both, so editing further while still
	// offline never piles up duplicate copies of the same in-progress Mark.
	async function queueOrUpdatePending(existingId, targetId, payload, status = 'queued') {
		if (existingId) {
			return updatePendingMark(existingId, targetId, payload, status);
		}
		return queuePendingMark(targetId, payload, status);
	}

	async function deletePendingMark(id) {
		const db = await openOfflineDB();
		const store = db.transaction(OFFLINE_STORE, 'readwrite').objectStore(OFFLINE_STORE);
		return idbRequest(store.delete(id));
	}

	// Flags a pending record as failed for a real (non-connectivity) reason
	// — renderPendingItem() surfaces this as "Couldn't publish" rather than
	// silently leaving it looking identical to a plain offline-queued item.
	async function markPendingError(id, message) {
		const db = await openOfflineDB();
		const store = db.transaction(OFFLINE_STORE, 'readwrite').objectStore(OFFLINE_STORE);
		const existing = await idbRequest(store.get(id));
		if (!existing) {
			return;
		}
		existing.status = 'error';
		existing.errorMessage = message;
		existing.updatedAt = Date.now();
		await idbRequest(store.put(existing));
	}

	async function getPendingMark(id) {
		const db = await openOfflineDB();
		const store = db.transaction(OFFLINE_STORE, 'readonly').objectStore(OFFLINE_STORE);
		return idbRequest(store.get(Number(id)));
	}

	async function getAllPendingMarks() {
		const db = await openOfflineDB();
		const store = db.transaction(OFFLINE_STORE, 'readonly').objectStore(OFFLINE_STORE);
		const all = await idbRequest(store.getAll());
		return Array.isArray(all) ? all : [];
	}

	// The current composer state as a plain, structured-cloneable object —
	// serializable to IndexedDB, and the single source of truth
	// payloadToFormData() turns into the multipart body either a live
	// request or a queued replay sends. Picked-but-not-yet-uploaded files
	// carry their real Blob (IndexedDB stores these natively); a file this
	// same session already uploaded (has entry.uploadedId) is folded into
	// existingAlt instead.
	function buildMarkPayload(status, opts) {
		opts = opts || {};
		const existingAlt = {};
		if (state.editing && Array.isArray(state.editing.media)) {
			state.editing.media.forEach((m) => {
				if (m.kind === 'image') {
					existingAlt[m.id] = m.alt || '';
				}
			});
		}
		const newFiles = [];
		state.files.forEach((entry) => {
			if (entry.uploadedId) {
				if (entry.kind === 'image') {
					existingAlt[entry.uploadedId] = entry.alt || '';
				}
				return;
			}
			newFiles.push({
				blob: entry.file,
				name: entry.file.name,
				kind: entry.kind,
				alt: entry.kind === 'image' ? entry.alt || '' : '',
			});
		});
		return {
			status,
			autosave: !!opts.autosave,
			caption: state.caption,
			primaryType: state.primaryType,
			title: titleFieldShown() ? state.title || '' : null,
			transcript: transcriptFieldShown() ? state.transcript || '' : null,
			aiAssistUsed: !!state.aiAssistUsed,
			targets: state.targets.slice(),
			categories: state.categories.slice(),
			tags: state.tags.slice(),
			helpers:
				Array.isArray(config.controllableHelpers) && config.controllableHelpers.length
					? state.helpers.slice()
					: null,
			newFiles,
			existingAlt,
		};
	}

	// The exact FormData a real Publish/Save as Draft, a live autosave, and
	// an offline-queue replay all send — one mapping, so none of the three
	// can drift apart.
	function payloadToFormData(payload) {
		const formData = new FormData();
		formData.append('caption', payload.caption);
		formData.append('primary_type', payload.primaryType);
		formData.append('status', payload.status);
		if (payload.title !== null) {
			formData.append('title', payload.title);
		}
		if (payload.transcript !== null) {
			formData.append('transcript', payload.transcript);
		}
		formData.append('ai_assist_used', payload.aiAssistUsed ? '1' : '0');
		payload.targets.forEach((target) => formData.append('targets[]', target));
		payload.categories.forEach((id) => formData.append('categories[]', id));
		if (payload.helpers !== null) {
			formData.append('publish_helpers', JSON.stringify(payload.helpers));
		}
		payload.newFiles.forEach((f) => {
			formData.append('files[]', f.blob, f.name);
			formData.append('alt[]', f.kind === 'image' ? f.alt : '');
		});
		if (Object.keys(payload.existingAlt).length) {
			formData.append('existing_alt', JSON.stringify(payload.existingAlt));
		}
		payload.tags.forEach((tag) => formData.append('tags[]', tag));
		if (payload.autosave) {
			formData.append('autosave', '1');
		}
		return formData;
	}

	// Tries the real request first; falls back to the offline queue only
	// when the failure looks like a connectivity problem (navigator.onLine
	// already false, or fetch itself threw — the TypeError browsers use for
	// a request that never reached a server — as opposed to a well-formed
	// HTTP error response, which always carries `.status` via readError()
	// and is rethrown so the caller's normal error handling still applies).
	async function submitOrQueue(path, targetId, payload, existingQueueId) {
		if (navigator.onLine) {
			try {
				const response = await apiUpload(path, payloadToFormData(payload));
				if (existingQueueId) {
					deletePendingMark(existingQueueId).catch(() => {});
				}
				return { queued: false, response };
			} catch (err) {
				if (!(err instanceof TypeError)) {
					throw err;
				}
				// Network-level failure despite navigator.onLine — queue below.
			}
		}
		const id = await queueOrUpdatePending(existingQueueId, targetId, payload);
		return { queued: true, id };
	}

	// "Tap Publish. Immediately appears in your timeline. Uploads continue
	// in the background." — queue-first, unlike submitOrQueue() above:
	// the deliberate Publish/Save-as-Draft tap queues the Mark locally
	// (fast — local IndexedDB, not a network round trip) and returns
	// immediately, so PublishScreen.publish() can navigate to Success
	// without ever waiting on the real request, no matter how large the
	// media or how slow the connection turns out to be. The real request
	// then runs via syncPendingMark(), fired here but deliberately not
	// awaited by the caller.
	async function publishInBackground(path, targetId, payload, existingQueueId) {
		const status = navigator.onLine ? 'uploading' : 'queued';
		const pendingId = await queueOrUpdatePending(existingQueueId, targetId, payload, status);
		syncPendingMark(pendingId, path, targetId, payload);
		return pendingId;
	}

	// The background half of publishInBackground(): attempts the real
	// request for an already-queued record. A connectivity-shaped failure
	// just downgrades it to 'queued' — the 'online' listener/
	// flushOfflineQueue() below will retry it same as any other offline
	// save. A real failure is flagged so the Pending section can surface it
	// rather than retrying forever in silence. Success removes the local
	// record and, if the user is still looking at the Success screen this
	// exact publish produced, upgrades it in place with the real server
	// data (permalink, syndication status) it couldn't have shown yet.
	async function syncPendingMark(pendingId, path, targetId, payload) {
		if (!navigator.onLine) {
			return; // Left 'queued' — nothing to attempt right now.
		}
		try {
			const response = await apiUpload(path, payloadToFormData(payload));
			await deletePendingMark(pendingId);
			SuccessScreen.upgrade(pendingId, response);
		} catch (err) {
			if (err instanceof TypeError) {
				await updatePendingMark(pendingId, targetId, payload, 'queued');
			} else {
				await markPendingError(pendingId, err.message).catch(() => {});
			}
		} finally {
			refreshPendingSection();
		}
	}

	// Replays every queued Mark through the same REST endpoints a live
	// Publish/Save as Draft/autosave uses. Triggered on the 'online' event
	// and once at boot (in case connectivity is already back from a
	// previous offline session). Stops at the first still-offline failure
	// rather than hammering every remaining item; a genuine server error on
	// one item (e.g. validation) is left queued and the rest still get a
	// chance, so one bad item can't block the others.
	let offlineFlushInFlight = false;
	async function flushOfflineQueue() {
		if (offlineFlushInFlight || !navigator.onLine) {
			return;
		}
		offlineFlushInFlight = true;
		try {
			const pending = await getAllPendingMarks();
			for (const record of pending) {
				// Actively open in the composer right now: let its own
				// autosave/publish path sync it (both already retry on the
				// next debounce/tap now that navigator.onLine is true)
				// rather than racing a background flush against in-memory
				// edits newer than what was last written to IndexedDB.
				if (record.id === state.offlineQueueId) {
					continue;
				}
				try {
					const path = record.targetId ? 'marks/' + record.targetId : 'marks';
					await apiUpload(path, payloadToFormData(record.payload));
					await deletePendingMark(record.id);
				} catch (err) {
					if (err instanceof TypeError || !navigator.onLine) {
						break; // Still offline (or just dropped) — retry next trigger.
					}
					// A real server error on this one item: flag it (so the
					// Pending section stops implying it just needs
					// connectivity) and keep going so it doesn't block the
					// rest.
					await markPendingError(record.id, err.message).catch(() => {});
				}
			}
		} finally {
			offlineFlushInFlight = false;
			refreshPendingSection();
		}
	}

	// Loads a Mark that's still only queued locally (never reached the
	// server) back into the composer — the offline counterpart to
	// openDraft(), rehydrating picked media from the stored Blobs instead
	// of fetching an already-published attachment list.
	async function openPendingMark(id) {
		const record = await getPendingMark(Number(id));
		if (!record) {
			return;
		}
		abandonComposer();
		const payload = record.payload;
		if (record.targetId) {
			state.editing = { id: record.targetId, type: payload.primaryType, media: [] };
		}
		state.offlineQueueId = record.id;
		state.caption = payload.caption || '';
		state.title = payload.title || '';
		state.titleStatus = 'done';
		state.titleEdited = false;
		state.transcript = payload.transcript || '';
		state.transcriptStatus = state.transcript ? 'done' : 'idle';
		state.transcriptEdited = false;
		state.targets = Array.isArray(payload.targets) ? payload.targets.slice() : [];
		state.categories = Array.isArray(payload.categories) ? payload.categories.slice() : [];
		state.helpers = Array.isArray(payload.helpers) ? payload.helpers.slice() : [];
		state.tags = Array.isArray(payload.tags) ? payload.tags.slice() : [];
		state.primaryType = payload.primaryType || 'note';
		state.files = (payload.newFiles || []).map((f) => {
			state.fileCounter += 1;
			return {
				id: 'f' + state.fileCounter,
				file: f.blob,
				url: f.kind === 'image' ? URL.createObjectURL(f.blob) : '',
				kind: f.kind,
				alt: f.alt || '',
				altStatus: 'idle',
				altEdited: true, // Already-typed alt: never overwrite with a fresh AI suggestion.
			};
		});
		navigate('#create');
	}

	// Save now, bypassing the debounce timer — used right after picking
	// media (protect the actual bytes as soon as possible) and right before
	// abandonComposer() wipes in-progress work.
	async function runAutosave() {
		clearTimeout(autosaveState.timer);
		autosaveState.timer = null;

		if (autosaveState.saving) {
			autosaveState.pendingRetry = true;
			return;
		}
		if (!state.caption.trim() && !state.files.length && !state.editing) {
			return; // Nothing yet worth protecting.
		}

		const generation = composerGeneration;
		const newFileCount = state.files.filter((entry) => !entry.uploadedId).length;
		const payload = buildMarkPayload('draft', { autosave: true });
		const path = state.editing ? 'marks/' + state.editing.id : 'marks';
		const targetId = state.editing ? state.editing.id : null;

		autosaveState.saving = true;
		let resolveInFlight;
		autosaveState.inFlight = new Promise((resolve) => {
			resolveInFlight = resolve;
		});
		setAutosaveStatus('saving');
		try {
			const result = await submitOrQueue(path, targetId, payload, state.offlineQueueId);
			if (generation !== composerGeneration) {
				return; // The composer has moved on; drop this stale response.
			}
			if (result.queued) {
				state.offlineQueueId = result.id;
				setAutosaveStatus('offline');
				return;
			}
			state.offlineQueueId = null;
			const response = result.response;
			if (!state.editing) {
				state.editing = { id: response.id, type: response.type || state.primaryType, media: [] };
			}
			if (newFileCount > 0) {
				// The file(s) are already attached server-side at this point
				// (the upload above succeeded) — this follow-up GET only
				// learns their attachment IDs so payloadToFormData() never
				// re-sends them. Kept in its own try/catch: if just this GET
				// fails, the save itself still succeeded and shouldn't be
				// reported as an error. A file left without an uploadedId
				// here is retried as a fresh upload next time, which is safe
				// (nothing lost) but can attach it twice in the rare case
				// this GET is what fails right after a successful upload.
				try {
					// The publisher appends newly uploaded attachments in the
					// same order files[] was sent (the same invariant
					// apply_positional_alt() relies on server-side) — so the
					// last newFileCount entries of a fresh GET are, in order,
					// the files just autosaved.
					const fresh = await apiGet('marks/' + state.editing.id);
					if (generation !== composerGeneration) {
						return;
					}
					const media = Array.isArray(fresh.media) ? fresh.media : [];
					const uploaded = media.slice(media.length - newFileCount);
					let cursor = 0;
					state.files.forEach((entry) => {
						if (!entry.uploadedId && uploaded[cursor]) {
							entry.uploadedId = uploaded[cursor].id;
							cursor += 1;
						}
					});
				} catch (err) {
					// The upload succeeded; only this ID lookup failed.
				}
			}
			setAutosaveStatus('saved');
		} catch (err) {
			if (generation === composerGeneration) {
				setAutosaveStatus('error');
			}
		} finally {
			autosaveState.saving = false;
			resolveInFlight();
			if (autosaveState.pendingRetry) {
				autosaveState.pendingRetry = false;
				runAutosave();
			}
		}
	}

	function scheduleAutosave() {
		clearTimeout(autosaveState.timer);
		autosaveState.timer = setTimeout(runAutosave, AUTOSAVE_DEBOUNCE_MS);
	}

	// Publish/Save as Draft must never race an autosave upload that's
	// already in flight for the same file: buildMarkPayload() only skips a
	// file once entry.uploadedId is set, and that ID isn't known until the
	// autosave request resolves, so tapping Publish mid-upload would re-send
	// the same blob (attaching it twice, or — if this is the first autosave
	// and the draft post doesn't exist server-side yet — creating a second,
	// separate post). This is a no-op when nothing is saving; otherwise it
	// waits out the current run and any chained retry (see runAutosave()'s
	// pendingRetry) before returning.
	async function waitForPendingAutosave() {
		while (autosaveState.saving) {
			await autosaveState.inFlight;
		}
	}

	// Effective Mark type: new files win; otherwise an edited draft's
	// stored type; otherwise the Home launcher's chosen type (if any);
	// otherwise the caption-only default. The server recomputes
	// authoritatively on save.
	function effectiveType() {
		if (state.files.length && state.editing && state.editing.media.length) {
			return 'mixed';
		}
		if (state.files.length) {
			return detectType(state.files);
		}
		if (state.editing) {
			return state.editing.type;
		}
		return state.pendingType || detectType(state.files);
	}

	// Per-type Title-field policy from the server ('optional' | 'hidden').
	// Any unknown type is treated as 'hidden' so the field never leaks onto a
	// type the server did not mark optional.
	const titlePolicy = config.titlePolicy || {};

	function titlePolicyFor(type) {
		return titlePolicy[type] === 'optional' ? 'optional' : 'hidden';
	}

	// Whether the composer should surface the optional Title field for the
	// current effective type (audio/video by default).
	function titleFieldShown() {
		return titlePolicyFor(effectiveType()) === 'optional';
	}

	// Whether the composer should surface the Transcript field. Unlike the
	// Title field there's no server-side policy to consult — a transcript
	// only ever makes sense for a Mark carrying a spoken audio track, so
	// this is a direct type check.
	function transcriptFieldShown() {
		const type = effectiveType();
		return type === 'audio' || type === 'video';
	}

	// The picked audio/video file "Generate transcript" would transcribe, or
	// null when there isn't one. entry.file (the real Blob) stays available
	// client-side for the whole composer session regardless of whether
	// autosave has already uploaded it (autosave only ever adds
	// entry.uploadedId, never clears entry.file — see runAutosave()), so
	// this works the same whether or not the file has been autosaved yet.
	// Editing an existing Mark with no newly picked file has no local bytes
	// left to send — its already-published attachment isn't a candidate.
	function transcriptSourceEntry() {
		return state.files.find((entry) => entry.kind === 'audio' || entry.kind === 'video') || null;
	}

	// Load a draft into the composer for continued editing.
	async function openDraft(id) {
		const mark = await apiGet('marks/' + id);
		abandonComposer();
		state.editing = {
			id: mark.id,
			type: mark.type || 'note',
			media: Array.isArray(mark.media) ? mark.media : [],
		};
		state.caption = mark.caption || '';
		// Preserve the draft's existing title: seed the field and mark it done
		// so the AI prefill never overwrites a title the author already has.
		state.title = mark.title || '';
		state.titleStatus = 'done';
		state.titleEdited = false;
		state.transcript = mark.transcript || '';
		state.transcriptStatus = state.transcript ? 'done' : 'idle';
		state.transcriptEdited = false;
		state.targets = Array.isArray(mark.targets) ? mark.targets.slice() : [];
		state.categories = Array.isArray(mark.categories) ? mark.categories.map(Number) : [];
		state.helpers = Array.isArray(mark.helpers) ? mark.helpers.slice() : [];
		state.primaryType = state.editing.type;
		navigate('#create');
	}

	function skeletonRows(count) {
		let out = '';
		for (let i = 0; i < count; i++) {
			out += '<div class="daymark-skeleton" aria-hidden="true"></div>';
		}
		return out;
	}

	// --- API helpers ---

	async function readError(res) {
		let message = 'Request failed (' + res.status + ')';
		let data = null;
		try {
			const body = await res.json();
			if (body && body.message) {
				message = body.message;
			}
			if (body && body.data && typeof body.data === 'object') {
				data = body.data;
			}
		} catch (err) {
			// Keep the generic message.
		}
		const error = new Error(message);
		error.status = res.status;
		// Some WP_Error responses (e.g. the subscription manual-refresh
		// cooldown) attach a retry_after (seconds) — exposed here so a
		// caller can distinguish "rate limited, try again later" from any
		// other failure without parsing the message text.
		if (data && typeof data.retry_after !== 'undefined') {
			error.retryAfter = Number(data.retry_after);
		}
		return error;
	}

	async function apiGet(path) {
		const res = await fetch(config.restUrl + path, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		if (!res.ok) {
			throw await readError(res);
		}
		return res.json();
	}

	async function apiPost(path, data) {
		const res = await fetch(config.restUrl + path, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
			body: JSON.stringify(data),
		});
		if (!res.ok) {
			throw await readError(res);
		}
		return res.json();
	}

	async function apiUpload(path, formData) {
		const res = await fetch(config.restUrl + path, {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
			body: formData,
		});
		if (!res.ok) {
			throw await readError(res);
		}
		return res.json();
	}

	async function apiDelete(path) {
		const res = await fetch(config.restUrl + path, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		if (!res.ok) {
			throw await readError(res);
		}
		return res.json();
	}

	/**
	 * Trailing debounce: coalesce rapid calls (e.g. search keystrokes) into
	 * one call after the delay.
	 */
	function debounce(fn, wait) {
		let timer = null;
		return function (...args) {
			if (timer) {
				clearTimeout(timer);
			}
			timer = setTimeout(() => {
				timer = null;
				fn.apply(this, args);
			}, wait);
		};
	}

	/**
	 * Wires one capture-phase document click+keydown pair that closes any
	 * number of open disclosures on an outside click or Escape — the
	 * convention already used by the per-item menu and the launcher, now
	 * shared with search and the reply box. Call again on re-render; a
	 * previous pair is removed first so listeners never stack across
	 * repeated renders of the same view.
	 *
	 * The current pair lives in module-level state (`dismissClickHandler` /
	 * `dismissKeyHandler`), not on `owner` — only one screen is ever showing
	 * at a time, so only one pair should ever be attached to `document` at a
	 * time. `showScreen()` clears that same state before swapping screens,
	 * which is what keeps an outgoing screen's pair from outliving its own
	 * markup; see the router section below.
	 *
	 * Each item's `close` must be idempotent (safe to call whether or not
	 * it's currently open) — an outside click calls every item's `close`
	 * unconditionally, same as Escape does. `isOpen`/`focus` are optional
	 * and Escape-only: when both are given and `isOpen()` is true, `focus()`
	 * is evaluated — and its returned element focused — right *before*
	 * `close()` runs, since a query like "which toggle is currently
	 * expanded" can no longer answer that once `close()` has already
	 * cleared it. Escape elsewhere on the page never steals focus into
	 * something that was already closed, since `focus()` is skipped
	 * entirely when `isOpen()` is false.
	 *
	 * @param {object} owner Unused by this function itself; kept in the call
	 *   signature so each call site still reads as "this view's disclosures"
	 *   at a glance.
	 * @param {Array<{selector: string, close: Function, isOpen?: Function, focus?: Function}>} items
	 *   `selector` marks the disclosure's own trigger + panel — a click inside it is
	 *   never treated as "outside".
	 */
	function bindDismissible(owner, items) {
		clearDismissible();

		dismissClickHandler = (event) => {
			items.forEach((item) => {
				if (!event.target.closest || !event.target.closest(item.selector)) {
					item.close();
				}
			});
		};

		dismissKeyHandler = (event) => {
			if ('Escape' !== event.key) {
				return;
			}
			items.forEach((item) => {
				const wasOpen = item.isOpen ? item.isOpen() : false;
				const focusTarget = wasOpen && item.focus ? item.focus() : null;
				item.close();
				if (focusTarget) {
					focusTarget.focus();
				}
			});
		};

		document.addEventListener('click', dismissClickHandler, true);
		document.addEventListener('keydown', dismissKeyHandler, true);
	}

	/**
	 * Removes the currently-attached `bindDismissible()` pair, if any. Safe
	 * to call with nothing attached — every screen's `close()` needs its own
	 * subtree to exist to do anything, so a stale pair left running against
	 * the wrong screen is a landmine (see issue #64), not just noise.
	 */
	function clearDismissible() {
		if (dismissClickHandler) {
			document.removeEventListener('click', dismissClickHandler, true);
			document.removeEventListener('keydown', dismissKeyHandler, true);
		}
		dismissClickHandler = null;
		dismissKeyHandler = null;
	}

	// --- Screen router ---

	let SCREENS = {};

	// The one `bindDismissible()` listener pair currently attached to
	// `document`, or `null`/`null` when nothing is. Shared across every
	// screen (not stashed per-controller) precisely because only one
	// screen is ever showing at a time — see `bindDismissible()` above for
	// why, and `showScreen()` below for where this gets cleared on every
	// screen swap.
	let dismissClickHandler = null;
	let dismissKeyHandler = null;

	function navigate(hash) {
		if (window.location.hash === hash) {
			showScreen(hash);
		} else {
			window.location.hash = hash;
		}
	}

	function showScreen(hash) {
		let target = SCREENS[hash] ? hash : '#home';

		// Guards: never land on screens whose state is missing.
		if (target === '#publish' && !state.files.length && !state.caption.trim()) {
			target = '#create';
		}
		if (target === '#success' && !state.lastPublish) {
			target = '#home';
		}

		AIAssistSheet.hide(false);
		SubscriptionPostSheet.hide(false);
		// The outgoing screen's `bindDismissible()` pair (if it registered
		// one at all) targets DOM that's about to be replaced wholesale
		// below — clear it unconditionally so it never keeps firing against
		// whichever screen loads next (issue #64). The next screen re-arms
		// its own pair, if it needs one, from its own `bindEvents()`/`init()`.
		clearDismissible();

		const controller = SCREENS[target];
		root.innerHTML = controller.render();
		if (controller.bindEvents) {
			controller.bindEvents();
		}
		if (controller.init) {
			controller.init();
		}

		document.body.className = 'daymark-app daymark-app--' + target.slice(1);

		if (window.location.hash !== target) {
			window.history.replaceState(null, '', target);
		}

		// Focus management: move focus to the screen heading.
		const heading = root.querySelector('[data-daymark-focus]');
		if (heading) {
			heading.focus();
		}
	}

	// --- Shared: persistent bottom nav, feed-list rendering ---
	//
	// Timeline (Home), Explore, Search, and Me all share one footer: four
	// nav tabs flanking the +New launcher. Extracted here — rather than
	// left as HomeScreen methods — so every screen that needs it (not just
	// Home) can bind the same launcher/auto-hide/dismiss behavior against
	// its own screen object.

	// A one-shot filter handed from Explore/Me to Search right before
	// navigate('#search') — e.g. "browse by type" or "your Marks" — the
	// same pattern state.pendingType already uses for the Create composer.
	// SearchScreen.init() consumes and clears it.
	let searchPreset = null;

	const NAV_TABS = [
		{ key: 'home', hash: '#home', label: 'Timeline', glyph: TIMELINE_GLYPH },
		{ key: 'explore', hash: '#explore', label: 'Explore', glyph: EXPLORE_GLYPH },
		{ key: 'search', hash: '#search', label: 'Search', glyph: SEARCH_GLYPH },
		{ key: 'me', hash: '#me', label: 'Me', glyph: ME_GLYPH },
	];

	// The persistent footer: Timeline/Explore flank one side of the +New
	// launcher, Search/Me the other — the launcher stays the visual and
	// structural center, never part of the tab list itself, so active-tab
	// styling can never land on it by accident.
	function navFooterMarkup(active) {
		const navLink = (tab) =>
			`<a class="daymark-bottomnav__link${
				tab.key === active ? ' is-active' : ''
			}" href="${esc(tab.hash)}" title="${esc(tab.label)}"${
				tab.key === active ? ' aria-current="page"' : ''
			}>${navIcon(tab.glyph)}<span class="daymark-visually-hidden">${esc(
				tab.label
			)}</span></a>`;
		const before = NAV_TABS.slice(0, 2).map(navLink).join('');
		const after = NAV_TABS.slice(2).map(navLink).join('');
		const bubbles = LAUNCHER_TYPES.map(
			(type) =>
				`<button type="button" class="daymark-launcher__bubble" data-launcher-type="${type}" tabindex="-1" aria-hidden="true" aria-label="New ${esc(
					TYPE_LABELS[type]
				)} Mark">${launcherIcon(TYPE_ICONS[type])}</button>`
		).join('');
		const launcher = `<div class="daymark-launcher" data-launcher>
			<div class="daymark-launcher__scrim" aria-hidden="true"></div>
			<div class="daymark-launcher__bubbles" data-launcher-bubbles>${bubbles}</div>
			<button type="button" class="daymark-launcher__btn" data-action="new-mark" aria-label="New Mark" aria-expanded="false">${launcherIcon(
				PLUS_GLYPH
			)}</button>
		</div>`;
		return `<footer class="daymark-homefooter"><nav class="daymark-bottomnav" aria-label="Daymark">${before}${launcher}${after}</nav></footer>`;
	}

	// The Home launcher: tapping "+ New Mark" fans out Image/Video/Audio/
	// Note bubbles (icon-only arc); tapping a bubble seeds the composer's
	// pendingType and jumps to #create. `screen` is whichever screen object
	// owns the footer currently on the page (Home/Explore/Search/Me all
	// call this from their own bindEvents()) — its openLauncher/closeLauncher
	// and _launcherOpen get attached here, same as bindFooterAutoHide()
	// below and the per-item ⋯ menu convention (aria-expanded,
	// focus-first-item on open).
	function bindLauncher(screen) {
		const launcher = root.querySelector('[data-launcher]');
		if (!launcher) {
			return;
		}
		const btn = launcher.querySelector('[data-action="new-mark"]');
		const bubbles = launcher.querySelectorAll('[data-launcher-type]');
		const scrim = launcher.querySelector('.daymark-launcher__scrim');

		screen._launcherOpen = false;

		// Bubbles become clickable only once this fires — see the CSS
		// comment on `.is-open.is-settled .daymark-launcher__bubble`
		// for why that's driven by a JS timer rather than a CSS
		// transition-delay on pointer-events. 650ms covers the worst
		// case (the last bubble's own 0.2s delay + 0.42s transition,
		// 620ms, plus a small margin) — 0 for reduced motion, since
		// CSS already skips the travel entirely and there's nothing
		// to wait out.
		const prefersReducedMotion =
			window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		const SETTLE_MS = prefersReducedMotion ? 0 : 650;

		screen.openLauncher = () => {
			screen._launcherOpen = true;
			clearTimeout(screen._launcherSettleTimer);
			launcher.classList.remove('is-settled');
			launcher.classList.add('is-open');
			screen._launcherSettleTimer = setTimeout(() => {
				launcher.classList.add('is-settled');
			}, SETTLE_MS);
			btn.setAttribute('aria-expanded', 'true');
			bubbles.forEach((bubble) => {
				bubble.removeAttribute('tabindex');
				bubble.removeAttribute('aria-hidden');
			});
			// `preventScroll` matters here: a bubble sits well within
			// view the moment it fans out, but the browser's default
			// focus-triggered scrollIntoView can still nudge the page —
			// and that nudge is itself a real scroll, which trips the
			// "a real scroll closes the launcher" listener below,
			// closing the launcher it was just supposed to focus into.
			const first = bubbles[0];
			if (first) {
				first.focus({ preventScroll: true });
			}
		};

		screen.closeLauncher = () => {
			if (!screen._launcherOpen) {
				return;
			}
			screen._launcherOpen = false;
			clearTimeout(screen._launcherSettleTimer);
			launcher.classList.remove('is-open', 'is-settled');
			btn.setAttribute('aria-expanded', 'false');
			bubbles.forEach((bubble) => {
				bubble.setAttribute('tabindex', '-1');
				bubble.setAttribute('aria-hidden', 'true');
			});
		};

		btn.addEventListener('click', () => {
			if (screen._launcherOpen) {
				screen.closeLauncher();
			} else {
				screen.openLauncher();
			}
		});

		// The scrim sits inside [data-launcher] (for stacking), so the
		// shared outside-click handler's closest() check treats a tap on
		// it as "inside" and leaves it alone — it needs its own listener.
		// `pointer-events: none` while closed keeps this scoped to only
		// when the scrim is actually showing.
		if (scrim) {
			scrim.addEventListener('click', () => screen.closeLauncher());
		}

		bubbles.forEach((bubble) => {
			bubble.addEventListener('click', () => {
				const type = bubble.getAttribute('data-launcher-type');
				screen.closeLauncher();
				resetComposer();
				state.pendingType = type;
				navigate('#create');
			});
		});
	}

	// Slide the footer (nav + launcher) out of view while scrolling down
	// through a screen's list, back in on scroll-up — reclaiming its height
	// for content without losing the controls. Removing the previous
	// listener first keeps these from stacking across repeated renders of
	// the same screen, same as the document click/keydown pair in
	// bindDismissible()/clearDismissible().
	function bindFooterAutoHide(screen) {
		const footer = root.querySelector('.daymark-homefooter');
		if (!footer) {
			return;
		}
		const launcher = footer.querySelector('[data-launcher]');

		if (screen._onScroll) {
			window.removeEventListener('scroll', screen._onScroll);
		}
		if (screen._onFooterFocusIn) {
			footer.removeEventListener('focusin', screen._onFooterFocusIn);
		}

		let lastY = window.scrollY;
		let ticking = false;

		screen._onScroll = () => {
			if (ticking) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame(() => {
				const y = window.scrollY;
				const delta = y - lastY;

				// Always show it near the top, regardless of direction.
				if (y < 80) {
					footer.classList.remove('is-footer-hidden');
					lastY = y;
					ticking = false;
					return;
				}

				// Ignore small jitters either direction so the footer
				// doesn't flicker mid-scroll.
				if (Math.abs(delta) < 8) {
					ticking = false;
					return;
				}

				// A real scroll closes the launcher too, so its bubbles
				// never end up floating over a footer that just hid —
				// but only once it has actually settled. Mid fan-out,
				// bubbles are still `pointer-events: none` (see the CSS
				// comment on `.is-settled`), so a click there makes the
				// browser/automation retry its own scrollIntoView against
				// a still-animating target; that retry's scroll would
				// otherwise land right here and close the launcher out
				// from under itself before it ever became clickable.
				const launcherOpenAndSettled =
					launcher && launcher.classList.contains('is-settled');
				if (launcherOpenAndSettled && screen.closeLauncher) {
					screen.closeLauncher();
				}

				footer.classList.toggle('is-footer-hidden', delta > 0);
				lastY = y;
				ticking = false;
			});
		};

		// A keyboard user tabbing to the CTA or a nav link must always
		// find it visible, regardless of scroll position — the footer is
		// never aria-hidden, so this just keeps sight in sync with focus.
		screen._onFooterFocusIn = () => {
			footer.classList.remove('is-footer-hidden');
		};

		window.addEventListener('scroll', screen._onScroll, { passive: true });
		footer.addEventListener('focusin', screen._onFooterFocusIn);
	}

	// The one dismissible entry every nav-footer screen needs (outside
	// click/Escape closes the launcher, focus returns to its trigger) — a
	// screen with its own additional dismissible items (Home's item ⋯
	// menus) spreads this into its own bindDismissible() array alongside
	// them, since bindDismissible() replaces the whole set on each call.
	function navFooterDismissEntry(screen) {
		return {
			selector: '[data-launcher]',
			close: () => screen.closeLauncher(),
			isOpen: () => screen._launcherOpen,
			focus: () => root.querySelector('[data-action="new-mark"]'),
		};
	}

	// Wires the launcher and the footer's scroll auto-hide for a screen
	// that has nothing else to add to bindDismissible() — Explore/Search/Me.
	// Home calls bindLauncher()/bindFooterAutoHide() directly instead, so it
	// can merge navFooterDismissEntry() into its own larger dismissible list.
	function bindNavFooter(screen) {
		bindLauncher(screen);
		bindFooterAutoHide(screen);
	}

	// The dismissible entry every feed-list screen (Home, Search) needs: an
	// outside click or Escape closes whichever ⋯ actions menu is open. A
	// Mark's or subscription post's site icon has no popover of its own to
	// close (see renderSiteIconButton() below) — a plain click fires
	// applySourceFilter() straight away — so this only ever needs to guard
	// the one [data-menu] show/hide machinery the ⋯ menu still uses.
	function itemMenusDismissEntry() {
		return { selector: '[data-actions]', close: () => closeItemMenus() };
	}

	// The site icon's one action, shared by a Mark's own icon and a
	// subscription post's site icon: filter Timeline down to just that
	// source. Hands the same source value the Source filter's <select>
	// already uses ('mine' or a subscription id) to Search via the same
	// one-shot searchPreset handoff Explore/Me use, rather than
	// reaching into a search bar that (unlike before the bottom-nav rework)
	// no longer lives on the calling screen.
	function applySourceFilter(value) {
		searchPreset = { source: value };
		navigate('#search');
	}

	// The Source filter's <option>s: "All" and "My Marks" always render
	// immediately; the per-subscription options only appear once the
	// subscriptions fetch below resolves.
	function sourceOptionsMarkup(subscriptions) {
		const list = Array.isArray(subscriptions) ? subscriptions : [];
		const subscriptionOptions = list
			.map((sub) => {
				const label = sub.site_title && sub.site_title.trim() ? sub.site_title : sub.site_url;
				return `<option value="${esc(String(sub.id))}">${esc(label)}</option>`;
			})
			.join('');
		return `<option value="">All</option><option value="mine">My Marks</option>${subscriptionOptions}`;
	}

	// Active subscriptions, for the Source filter and Explore's Following
	// section — never throws; an empty list just means those UIs show
	// nothing extra rather than failing.
	async function fetchSubscriptions() {
		try {
			const result = await apiGet('subscriptions');
			return Array.isArray(result) ? result : [];
		} catch (err) {
			return [];
		}
	}

	// Wires "tap a draft to resume editing it" for any list of drafts —
	// Home's Drafts row and Me's. Doesn't touch this/state beyond the
	// container handed in, so both screens share it as-is.
	function bindDraftTaps(container) {
		container.querySelectorAll('[data-edit-draft]').forEach((row) => {
			row.addEventListener('click', (event) => {
				event.preventDefault();
				row.setAttribute('aria-busy', 'true');
				openDraft(row.getAttribute('data-edit-draft')).catch(() => {
					row.removeAttribute('aria-busy');
				});
			});
		});
	}

	// A subscription post's own display label: its title, or — falling back —
	// the bare hostname of its site (never the full URL). Falls further back
	// to a generic phrase only when even that can't be parsed (a malformed or
	// missing site_url), so a card's avatar menu never reads with a blank
	// name in it.
	function subscriptionSiteLabel(item) {
		if (item.site_title && item.site_title.trim()) {
			return item.site_title.trim();
		}
		if (item.site_url) {
			try {
				return new URL(item.site_url).hostname;
			} catch (err) {
				// Falls through to the generic label below.
			}
		}
		return 'this site';
	}

	// The site icon that sits on every Timeline item except a Draft, as its
	// own leading-column element — not a small circular badge overlapping
	// the thumbnail's corner. A single click is the only interaction: it
	// filters Timeline down to just that source (applySourceFilter(), via
	// the data-filter-site attribute onFeedListClick() reads), no popover
	// menu and no separate "visit the site" action (a live product review
	// asked for both simplifications — the popover read as a false circular
	// tap target, and "visit" left the app for a use case that didn't earn
	// its own menu). Shared by a Mark's own icon (renderMarkItem()) and a
	// subscription post's site icon (renderSubscriptionPostCard()) so the
	// two can never drift apart. Kept as its own sibling element in the
	// card's flex layout (not nested inside the card's own link/button) so
	// a future Daymark content type can render its own card differently
	// without this icon's placement following along.
	function renderSiteIconButton({ iconSrc, iconAlt, ariaLabel, filterValue }) {
		const glyph = (iconAlt || '?').charAt(0).toUpperCase();
		const icon = iconSrc
			? imgWithFallback(iconSrc, 'daymark-recent__siteiconimg', glyph)
			: `<span class="daymark-recent__siteiconimg daymark-recent__siteiconimg--glyph" aria-hidden="true">${esc(
					glyph
			  )}</span>`;
		return `
			<div class="daymark-recent__siteicon">
				<button type="button" class="daymark-recent__siteiconbtn" data-filter-site="${esc(
					filterValue
				)}" aria-label="${esc(ariaLabel)}">
					${icon}
				</button>
			</div>`;
	}

	// One Mark's card markup — the thumbnail-or-glyph + title + meta + stats
	// core (renderMarkCore()) wrapped in its own permalink/resume-draft link
	// plus the shared ⋯ edit/delete actions menu. Used everywhere a Mark
	// appears in a list: Home's Recent/Drafts, Search's results.
	function renderMarkItem(item) {
		// Drafts look identical to published Marks otherwise — and
		// their permalinks are invisible to visitors — so tapping one
		// reopens the composer instead of the permalink. (renderMarkCore()
		// handles the visible "Draft" chip itself.)
		const title = item.title || 'Untitled Mark';
		const isDraft = item.status && 'publish' !== item.status;
		const href = isDraft ? '#create' : item.permalink || '#home';
		const editAttr = isDraft ? ` data-edit-draft="${esc(String(item.id))}"` : '';
		const id = esc(String(item.id));
		const kind = resolveCardKind(item);
		// A Draft always came through Daymark's own composer (GET /marks
		// only ever lists true Marks, unlike the Timeline's own Marks
		// query — see get_timeline()'s docblock, class-rest-controller.php)
		// — real, so it always gets the ⋯ menu. A published item's `type`
		// (_daymark_primary_type) is only ever set by that same composer,
		// so an empty one means this is an ordinary post published
		// straight through the block editor, not a Mark — GET/PUT/DELETE
		// /marks/{id} already 404 for it server-side (get_daymark(),
		// delete_daymark() both gate on _daymark_is_mark independently of
		// this), so offering Edit/Delete here would just be a dead end;
		// simplest and most honest is to not offer them at all.
		const isRealMark = isDraft || Boolean(item.type);
		// A Draft is always the author's own unpublished work — there is no
		// separate site to filter to, so it's the one item that skips the
		// site icon.
		const siteIcon = isDraft
			? ''
			: renderSiteIconButton({
					iconSrc: (config.currentUser && config.currentUser.avatarUrl) || '',
					iconAlt: (config.currentUser && config.currentUser.displayName) || 'You',
					ariaLabel: 'Filter Timeline to your Marks',
					filterValue: 'mine',
			  });
		const actions = !isRealMark
			? ''
			: `<div class="daymark-recent__actions" data-actions>
					<button type="button" class="daymark-recent__menubtn" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Actions for ${esc(
						title
					)}">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
					</button>
					<div class="daymark-menu" data-menu role="menu" aria-label="Mark actions" hidden>
						<div class="daymark-menu__actions" data-menu-actions>
							<button type="button" class="daymark-menu__item" data-menu-edit role="menuitem">Edit</button>
							<button type="button" class="daymark-menu__item daymark-menu__item--danger" data-menu-delete role="menuitem">Delete</button>
						</div>
						<div class="daymark-menu__confirm" data-menu-confirm hidden>
							<p class="daymark-menu__confirmtext">Delete this Mark? It&rsquo;ll move to Trash.</p>
							<div class="daymark-menu__confirmactions">
								<button type="button" class="daymark-btn daymark-btn--danger" data-menu-delete-confirm>Delete</button>
								<button type="button" class="daymark-btn daymark-btn--secondary" data-menu-delete-cancel>Cancel</button>
							</div>
							<p class="daymark-menu__status" data-menu-status aria-live="polite"></p>
						</div>
					</div>
				</div>`;
		return `
			<div class="daymark-recent__item-wrap" data-item="${id}">
				${siteIcon}
				${renderTypeIcon(kind)}
				<a class="daymark-recent__item daymark-recent__item--${esc(kind)}" href="${esc(href)}"${editAttr}>
					${renderMarkCore(item)}
				</a>
				${actions}
			</div>`;
	}

	// Dispatch one merged-feed item to the right card renderer: a
	// subscription post gets its own card (renderSubscriptionPostCard);
	// everything else (item_type 'mark', or a plain /marks item from a
	// Drafts list) is prepare_mark_summary()'s exact shape, so
	// renderMarkItem() — with its ⋯ edit/delete menu — works unchanged.
	function renderFeedItem(item) {
		return 'subscription_post' === item.item_type
			? renderSubscriptionPostCard(item)
			: renderMarkItem(item);
	}

	// Track subscription-post items by id so a card tap can hand the
	// detail sheet everything it already has without re-querying the DOM
	// (a Mark item needs no such tracking — its card is a plain
	// permalink/edit link, not a detail-sheet trigger). `screen` owns the
	// Map (Home and Search each keep their own).
	function rememberItem(screen, item) {
		if (item && 'subscription_post' === item.item_type) {
			screen._bySubId.set(String(item.id), item);
		}
	}

	// --- Per-item ⋯ menu (edit / delete), shared by every feed-list screen ---

	function closeItemMenus() {
		root.querySelectorAll('[data-menu]').forEach((menu) => {
			menu.hidden = true;
			const actions = menu.querySelector('[data-menu-actions]');
			const confirm = menu.querySelector('[data-menu-confirm]');
			if (actions) {
				actions.hidden = false;
			}
			if (confirm) {
				confirm.hidden = true;
			}
			const toggle = menu.parentElement
				? menu.parentElement.querySelector('[data-menu-toggle]')
				: null;
			if (toggle) {
				toggle.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function onFeedListClick(screen, event) {
		const target = event.target;

		// A subscription-post card is a <button>, not a Mark's plain
		// permalink/⋯-menu item — opening it shows the click-through
		// detail sheet in place rather than navigating.
		const subTrigger = target.closest('[data-subpost]');
		if (subTrigger) {
			const item = screen._bySubId.get(subTrigger.getAttribute('data-subpost'));
			if (item) {
				SubscriptionPostSheet.show(item, screen._detailCache, subTrigger);
			}
			return;
		}

		const toggle = target.closest('[data-menu-toggle]');
		if (toggle) {
			event.preventDefault();
			const menu = toggle.parentElement.querySelector('[data-menu]');
			const wasOpen = menu && !menu.hidden;
			closeItemMenus();
			if (menu && !wasOpen) {
				menu.hidden = false;
				toggle.setAttribute('aria-expanded', 'true');
				const first = menu.querySelector('[data-menu-edit]');
				if (first) {
					first.focus();
				}
			}
			return;
		}

		// The site icon's one action: filter Timeline down to just this
		// source, without ever leaving the app. A plain click on the icon
		// itself — no menu-open step first.
		const filterSite = target.closest('[data-filter-site]');
		if (filterSite) {
			event.preventDefault();
			closeItemMenus();
			applySourceFilter(filterSite.getAttribute('data-filter-site'));
			return;
		}

		const edit = target.closest('[data-menu-edit]');
		if (edit) {
			event.preventDefault();
			const wrap = edit.closest('[data-item]');
			closeItemMenus();
			if (wrap) {
				openDraft(wrap.getAttribute('data-item')).catch(() => {});
			}
			return;
		}

		const del = target.closest('[data-menu-delete]');
		if (del) {
			event.preventDefault();
			const menu = del.closest('[data-menu]');
			const actions = menu.querySelector('[data-menu-actions]');
			const confirm = menu.querySelector('[data-menu-confirm]');
			if (actions) {
				actions.hidden = true;
			}
			if (confirm) {
				confirm.hidden = false;
				// Focus lands on Cancel so a stray Enter is non-destructive.
				const cancel = confirm.querySelector('[data-menu-delete-cancel]');
				if (cancel) {
					cancel.focus();
				}
			}
			return;
		}

		const cancel = target.closest('[data-menu-delete-cancel]');
		if (cancel) {
			event.preventDefault();
			const menu = cancel.closest('[data-menu]');
			const actions = menu.querySelector('[data-menu-actions]');
			const confirm = menu.querySelector('[data-menu-confirm]');
			if (confirm) {
				confirm.hidden = true;
			}
			if (actions) {
				actions.hidden = false;
			}
			const del2 = menu.querySelector('[data-menu-delete]');
			if (del2) {
				del2.focus();
			}
			return;
		}

		const confirmDel = target.closest('[data-menu-delete-confirm]');
		if (confirmDel) {
			event.preventDefault();
			deleteItem(screen, confirmDel);
		}
	}

	async function deleteItem(screen, confirmBtn) {
		const wrap = confirmBtn.closest('[data-item]');
		const menu = confirmBtn.closest('[data-menu]');
		if (!wrap || !menu) {
			return;
		}
		const id = wrap.getAttribute('data-item');
		const cancelBtn = menu.querySelector('[data-menu-delete-cancel]');
		const status = menu.querySelector('[data-menu-status]');
		confirmBtn.disabled = true;
		if (cancelBtn) {
			cancelBtn.disabled = true;
		}
		confirmBtn.textContent = 'Deleting…';
		if (status) {
			status.textContent = '';
		}
		try {
			await apiDelete('marks/' + id);
			const parentList = wrap.parentElement;
			wrap.remove();
			reflectEmptied(screen, parentList);
		} catch (err) {
			confirmBtn.disabled = false;
			if (cancelBtn) {
				cancelBtn.disabled = false;
			}
			confirmBtn.textContent = 'Delete';
			if (status) {
				status.textContent = 'Could not delete. ' + err.message;
			}
		}
	}

	// After a delete, keep the emptied region honest: hide an empty Drafts
	// section, or show the right empty state for whichever list this was.
	function reflectEmptied(screen, list) {
		if (!list || list.querySelector('[data-item]')) {
			return;
		}
		if (list.hasAttribute('data-drafts-list')) {
			const section = root.querySelector('[data-drafts-section]');
			if (section) {
				section.hidden = true;
			}
			screen._hasDrafts = false;
		} else if (list.hasAttribute('data-search-results')) {
			list.innerHTML = '<p class="daymark-empty">Nothing matches. Try a different search or filter.</p>';
		} else if (list.hasAttribute('data-recent-list')) {
			screen.teardownObserver();
			const sentinel = root.querySelector('[data-recent-sentinel]');
			if (sentinel) {
				sentinel.hidden = true;
			}
			const more = root.querySelector('[data-recent-more]');
			if (more) {
				more.hidden = true;
			}
			list.innerHTML =
				'<p class="daymark-empty">Nothing here yet. <a href="#create">Publish a Mark</a> or subscribe to a site to fill your timeline.</p>';
		}
	}

	// One locally-queued (not yet synced to the server) Mark's card
	// markup — deliberately simpler than renderMarkItem()/renderMarkCore():
	// there is no server id yet, so no permalink, stats, or edit/delete
	// menu, just enough to recognize the item and (for 'queued'/'error'
	// records) resume it. A record actively 'uploading' right now has
	// nothing useful to resume — it's transient and will resolve on its
	// own within moments — so it renders as a plain, non-interactive row
	// instead of a link.
	function renderPendingItem(record) {
		const payload = record.payload || {};
		const title = (payload.caption || '').trim() || 'Untitled Mark';
		const firstFile = Array.isArray(payload.newFiles) ? payload.newFiles[0] : null;
		let thumb = '';
		if (firstFile && firstFile.kind === 'image') {
			try {
				thumb = `<img class="daymark-recent__thumb" src="${esc(
					URL.createObjectURL(firstFile.blob)
				)}" alt="" />`;
			} catch (err) {
				thumb = '';
			}
		}
		const status = record.status || 'queued';
		const meta =
			status === 'error'
				? `<span class="daymark-chip daymark-chip--danger">Couldn't publish</span> Tap to review and retry`
				: status === 'uploading'
				? `<span class="daymark-chip daymark-chip--muted">Uploading</span> Publishing now&hellip;`
				: `<span class="daymark-chip daymark-chip--draft">Offline</span> Will sync when you're back online`;
		const inner = `
					${thumb}
					<span class="daymark-recent__body">
						<span class="daymark-recent__title">${esc(title)}</span>
						<span class="daymark-recent__meta">${meta}</span>
					</span>`;
		const item =
			status === 'uploading'
				? `<span class="daymark-recent__item" aria-live="polite">${inner}</span>`
				: `<a class="daymark-recent__item" href="#create" data-resume-pending="${esc(
						String(record.id)
				  )}">${inner}</a>`;
		return `<div class="daymark-recent__item-wrap">${item}</div>`;
	}

	// Wires "tap a pending item to resume it" — the offline-queue
	// counterpart to bindDraftTaps(), reopening via openPendingMark()
	// (a local IndexedDB read) instead of openDraft()'s REST fetch.
	function bindPendingTaps(container) {
		container.querySelectorAll('[data-resume-pending]').forEach((row) => {
			row.addEventListener('click', (event) => {
				event.preventDefault();
				row.setAttribute('aria-busy', 'true');
				openPendingMark(row.getAttribute('data-resume-pending')).catch(() => {
					row.removeAttribute('aria-busy');
				});
			});
		});
	}

	// (Re)loads Home's Pending section from the local offline queue. Called
	// on Home init, and after anything that changes the queue (a fresh
	// offline save, or flushOfflineQueue() syncing items back out) — a no-op
	// if Home isn't the screen currently mounted, so callers never need to
	// know or care whether it's showing.
	async function refreshPendingSection() {
		const section = root.querySelector('[data-pending-section]');
		const list = root.querySelector('[data-pending-list]');
		if (!section || !list) {
			return;
		}
		try {
			const pending = await getAllPendingMarks();
			if (!list.isConnected) {
				return;
			}
			if (!pending.length) {
				section.hidden = true;
				list.innerHTML = '';
				return;
			}
			// Most recently touched first, so an item still being edited offline
			// stays at the top.
			pending.sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
			list.innerHTML = pending.map((record) => renderPendingItem(record)).join('');
			section.hidden = false;
			bindPendingTaps(list);
		} catch (err) {
			// IndexedDB unavailable (old browser, private-mode restrictions, …):
			// the offline queue itself already degrades to "queue attempt
			// fails, error surfaces like any other failed save" — nothing to
			// show here either.
		}
	}

	// --- Screen: Home (Timeline) ---

	const HomeScreen = {
		render() {
			const hasUnread = config.notifications && config.notifications.hasUnread;
			// Home itself is the merged Marks + subscriptions feed now, so
			// this is just a plain "go home" link — no separate screen to
			// point at.
			const wordmark = `<a class="daymark-homelink" href="#home"><svg class="daymark-homelink__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${
					TIMELINE_GLYPH
			  }</svg><span>Daymark</span></a>`;
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${wordmark}</h1>
				<a class="daymark-iconbtn" href="#notifications" aria-label="${
					hasUnread ? 'Notifications — unread replies' : 'Notifications'
				}">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
					${
						hasUnread
							? '<span class="daymark-iconbtn__dot" aria-hidden="true"></span>'
							: ''
					}
				</a>
			</header>
			<section class="daymark-screen">
				<div class="daymark-pullrefresh" data-pull-indicator aria-hidden="true">
					<span class="daymark-spinner" aria-hidden="true"></span>
				</div>
				<section class="daymark-recent" data-pending-section hidden aria-labelledby="daymark-pending-heading">
					<h2 id="daymark-pending-heading" class="daymark-section-heading">Pending</h2>
					<div class="daymark-recent__list" data-pending-list></div>
				</section>
				<section class="daymark-recent" data-drafts-section hidden aria-labelledby="daymark-drafts-heading">
					<h2 id="daymark-drafts-heading" class="daymark-section-heading">Drafts</h2>
					<div class="daymark-recent__list" data-drafts-list></div>
				</section>
				<section class="daymark-recent" aria-labelledby="daymark-recent-heading">
					<h2 id="daymark-recent-heading" class="daymark-visually-hidden">Timeline</h2>
					<p class="daymark-status" data-recent-refresh-status aria-live="polite"></p>
					<div class="daymark-recent__list" data-recent-list aria-live="polite">
						${skeletonRows(3)}
						<span class="daymark-visually-hidden">Loading your timeline</span>
					</div>
					<div class="daymark-recent__sentinel" data-recent-sentinel aria-hidden="true"></div>
					<p class="daymark-recent__more" data-recent-more hidden></p>
				</section>
			</section>
			${navFooterMarkup('home')}`;
		},

		bindEvents() {
			// --- Per-item ⋯ menu (edit / delete) via list delegation ---
			root.querySelectorAll('[data-recent-list], [data-drafts-list]').forEach((list) => {
				list.addEventListener('click', (event) => onFeedListClick(this, event));
			});
			// Pull-to-refresh is gesture-only now — Home is assumed to be
			// the Timeline, so there's no separate "Refresh" link/button.
			this.bindPullGesture();
			// Close any open item menu or the launcher on an outside click
			// or Escape (with focus returned to the launcher's own trigger —
			// the item menu never took focus in the first place, so it has
			// nothing to return).
			bindDismissible(this, [itemMenusDismissEntry(), navFooterDismissEntry(this)]);

			bindLauncher(this);
			bindFooterAutoHide(this);
		},

		async init() {
			this._searchSeq = 0;
			this._hasDrafts = false;
			this.recentPage = 1;
			this.recentDone = false;
			this.recentLoading = false;
			this._refreshing = false;
			// Keyed by subscription-post id (string) → the list item, so a
			// card tap can hand the detail sheet everything it already has
			// (title/permalink/etc.) without re-querying the DOM.
			this._bySubId = new Map();
			// Cache of fetched detail bodies, keyed by subscription-post id:
			// { state: 'loading'|'done'|'error', body_content }. Persists on
			// this singleton across re-inits (leaving and returning to Home)
			// so a card already opened once never re-fetches.
			this._detailCache = this._detailCache || new Map();

			await refreshPendingSection();

			const draftsSection = root.querySelector('[data-drafts-section]');
			const draftsList = root.querySelector('[data-drafts-list]');

			// Drafts are fetched separately so they stay reachable no matter
			// how many Marks have published since.
			try {
				const drafts = await apiGet('marks?status=draft&per_page=10');
				const draftItems = Array.isArray(drafts) ? drafts : [];
				if (draftItems.length && draftsSection && draftsList && draftsList.isConnected) {
					draftsList.innerHTML = draftItems.map((item) => renderMarkItem(item)).join('');
					draftsSection.hidden = false;
					this._hasDrafts = true;
					bindDraftTaps(draftsList);
				}
			} catch (err) {
				// A drafts failure never blocks the recent list below.
			}

			await this.loadRecent();
		},

		// (Re)load the first page of recent Marks and arm infinite scroll.
		async loadRecent() {
			const list = root.querySelector('[data-recent-list]');
			const more = root.querySelector('[data-recent-more]');
			const sentinel = root.querySelector('[data-recent-sentinel]');
			if (!list) {
				return;
			}
			const heading = root.querySelector('#daymark-recent-heading');
			if (heading) {
				heading.textContent = 'Timeline';
			}
			this.teardownObserver();
			this.recentPage = 1;
			this.recentDone = false;
			this.recentLoading = false;
			const seq = ++this._searchSeq;
			if (more) {
				more.hidden = true;
			}
			if (sentinel) {
				sentinel.hidden = false;
			}
			try {
				const items = await apiGet('timeline?per_page=' + RECENT_PER_PAGE + '&page=1');
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				const arr = Array.isArray(items) ? items : [];
				this._bySubId.clear();
				arr.forEach((item) => rememberItem(this, item));
				if (!arr.length) {
					list.innerHTML =
						'<p class="daymark-empty">Nothing here yet. <a href="#create">Publish a Mark</a> or subscribe to a site to fill your timeline.</p>';
					this.recentDone = true;
					if (sentinel) {
						sentinel.hidden = true;
					}
					return;
				}
				list.innerHTML = arr.map((item) => renderFeedItem(item)).join('');

				if (arr.length < RECENT_PER_PAGE) {
					// A short first page means there is nothing more to load.
					this.recentDone = true;
					if (sentinel) {
						sentinel.hidden = true;
					}
					return;
				}

				// A full page: more may exist. Prefer infinite scroll; only
				// fall back to an in-place "Load more" button when
				// IntersectionObserver is unavailable — there's nowhere else
				// to send someone; Home already has everything.
				if ('IntersectionObserver' in window) {
					this.setupObserver();
				} else if (more) {
					more.innerHTML =
						'<button type="button" class="daymark-btn daymark-btn--text" data-recent-loadmore>Load more</button>';
					more.hidden = false;
					const btn = more.querySelector('[data-recent-loadmore]');
					if (btn) {
						btn.addEventListener('click', () => this.loadMorePage());
					}
				}
			} catch (err) {
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				list.innerHTML =
					'<p class="daymark-error" role="alert">Could not load your timeline. ' +
					esc(err.message) +
					'</p>';
			}
		},

		// Append the next page when the sentinel scrolls into view.
		async loadMorePage() {
			if (this.recentLoading || this.recentDone) {
				return;
			}
			this.recentLoading = true;
			const list = root.querySelector('[data-recent-list]');
			const sentinel = root.querySelector('[data-recent-sentinel]');
			if (!list || !list.isConnected) {
				this.recentLoading = false;
				return;
			}
			const nextPage = this.recentPage + 1;
			try {
				const items = await apiGet('timeline?per_page=' + RECENT_PER_PAGE + '&page=' + nextPage);
				const arr = Array.isArray(items) ? items : [];
				if (arr.length && list.isConnected) {
					this.recentPage = nextPage;
					arr.forEach((item) => rememberItem(this, item));
					list.insertAdjacentHTML(
						'beforeend',
						arr.map((item) => renderFeedItem(item)).join('')
					);
				}
				if (arr.length < RECENT_PER_PAGE) {
					this.recentDone = true;
					this.teardownObserver();
					if (sentinel) {
						sentinel.hidden = true;
					}
				}
			} catch (err) {
				// Stop trying on error; keep whatever already loaded.
				this.recentDone = true;
				this.teardownObserver();
			} finally {
				this.recentLoading = false;
			}
		},

		setupObserver() {
			if (!('IntersectionObserver' in window)) {
				return; // The "Load more" button is the fallback path.
			}
			const sentinel = root.querySelector('[data-recent-sentinel]');
			if (!sentinel) {
				return;
			}
			this.teardownObserver();
			this.observer = new IntersectionObserver(
				(entries) => {
					for (const entry of entries) {
						if (entry.isIntersecting) {
							this.loadMorePage();
						}
					}
				},
				{ rootMargin: '200px' }
			);
			this.observer.observe(sentinel);
		},

		teardownObserver() {
			if (this.observer) {
				this.observer.disconnect();
				this.observer = null;
			}
		},

		// --- Pull-to-refresh: independent of the scheduled poll, and
		// rate-limited server-side per subscription (15 minutes). Refreshes
		// every active subscription, then reloads the merged feed so any
		// newly ingested posts appear — never silent, and a skipped
		// (too-recent) subscription is reported as such, not as a failure.

		async refreshOneSubscription(id) {
			try {
				await apiPost('subscriptions/' + id + '/refresh', {});
				return 'refreshed';
			} catch (err) {
				// The manual-refresh cooldown (and the endpoint's own rate
				// limit) both respond 429 — either way this subscription was
				// simply checked too recently, not a real failure.
				return err && 429 === err.status ? 'skipped' : 'failed';
			}
		},

		async pullRefresh() {
			if (this._refreshing) {
				return;
			}
			this._refreshing = true;
			const status = root.querySelector('[data-recent-refresh-status]');
			const indicator = root.querySelector('[data-pull-indicator]');
			if (indicator) {
				indicator.classList.add('is-visible', 'is-settling');
				indicator.style.transform = 'translateY(40px)';
			}
			if (status) {
				status.textContent = 'Checking your subscriptions…';
			}

			let subscriptions = [];
			try {
				const result = await apiGet('subscriptions');
				subscriptions = Array.isArray(result) ? result : [];
			} catch (err) {
				subscriptions = []; // Still reload the merged feed below.
			}

			let refreshed = 0;
			let skipped = 0;
			let failed = 0;
			if (subscriptions.length) {
				const outcomes = await Promise.all(
					subscriptions.map((s) => this.refreshOneSubscription(s.id))
				);
				outcomes.forEach((outcome) => {
					if ('refreshed' === outcome) {
						refreshed += 1;
					} else if ('skipped' === outcome) {
						skipped += 1;
					} else {
						failed += 1;
					}
				});
			}

			await this.loadRecent();

			this._refreshing = false;
			if (indicator) {
				indicator.classList.remove('is-visible', 'is-settling');
				indicator.style.transform = '';
			}
			if (status) {
				if (!subscriptions.length) {
					status.textContent = 'No subscriptions to refresh.';
				} else {
					const parts = [];
					if (refreshed) {
						parts.push(refreshed + (1 === refreshed ? ' feed' : ' feeds') + ' updated');
					}
					if (skipped) {
						parts.push(skipped + ' checked too recently, skipped');
					}
					if (failed) {
						parts.push(failed + (1 === failed ? ' feed' : ' feeds') + ' failed to refresh');
					}
					status.textContent = parts.length ? parts.join('; ') + '.' : 'Up to date.';
				}
			}
		},

		// Touch-drag pull-to-refresh: only arms while the page is already
		// scrolled to the very top (otherwise this is an ordinary scroll
		// gesture over the list, not a pull). Gesture-only, by design — no
		// separate "Refresh" link/button on Home.
		bindPullGesture() {
			const list = root.querySelector('[data-recent-list]');
			const indicator = root.querySelector('[data-pull-indicator]');
			if (!list || !indicator) {
				return;
			}
			const THRESHOLD = 64;
			let startY = 0;
			let dragging = false;
			let armed = false;

			const onStart = (event) => {
				dragging = window.scrollY <= 0 && !this._refreshing;
				armed = false;
				startY = dragging ? event.touches[0].clientY : 0;
			};
			const onMove = (event) => {
				if (!dragging) {
					return;
				}
				const delta = event.touches[0].clientY - startY;
				if (delta <= 0) {
					armed = false;
					indicator.classList.remove('is-visible', 'is-armed');
					indicator.style.transform = '';
					return;
				}
				indicator.style.transition = 'none';
				const damped = Math.min(THRESHOLD * 1.5, delta * 0.5);
				indicator.style.transform = 'translateY(' + damped + 'px)';
				indicator.classList.add('is-visible');
				armed = damped >= THRESHOLD;
				indicator.classList.toggle('is-armed', armed);
			};
			const onEnd = () => {
				if (!dragging) {
					return;
				}
				dragging = false;
				indicator.style.transition = '';
				indicator.classList.remove('is-visible', 'is-armed');
				indicator.style.transform = '';
				if (armed) {
					this.pullRefresh();
				}
				armed = false;
			};

			list.addEventListener('touchstart', onStart, { passive: true });
			list.addEventListener('touchmove', onMove, { passive: true });
			list.addEventListener('touchend', onEnd);
			list.addEventListener('touchcancel', onEnd);
		},
	};

	// --- Screen: Search ---
	//
	// Universal search across Daymark content: the same GET /timeline query
	// (keyword, type, source) Home's search bar used to run in place, now
	// its own nav destination and route instead of a collapsible header bar
	// — reachable, and refreshable, from anywhere in the app. Shares its
	// result-list rendering and item interactions (⋯ edit/delete, the
	// subscription-post detail sheet) with Home via the functions above,
	// rather than reimplementing them.

	const SearchScreen = {
		render() {
			const filterChips = SEARCH_FILTERS.map(
				(filter, index) =>
					`<button type="button" class="daymark-filterchip${
						index === 0 ? ' is-active' : ''
					}" data-filter="${esc(filter.type)}" aria-pressed="${
						index === 0 ? 'true' : 'false'
					}">${esc(filter.label)}</button>`
			).join('');
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>Search</h1>
			</header>
			<div class="daymark-searchbar daymark-searchbar--screen">
				<label class="daymark-visually-hidden" for="daymark-search-input">Search Daymark</label>
				<input type="search" id="daymark-search-input" class="daymark-input" data-search-input placeholder="Search your Marks and the sites you follow" autocomplete="off" />
				<div class="daymark-searchfilters" data-search-filters>
					<div class="daymark-filterchips" role="group" aria-label="Filter by type" data-filter-chips>${filterChips}</div>
					<label class="daymark-visually-hidden" for="daymark-source-filter">Filter by source</label>
					<select id="daymark-source-filter" class="daymark-sourcefilter" data-source-filter>${sourceOptionsMarkup(
						this._subscriptions
					)}</select>
				</div>
			</div>
			<section class="daymark-screen">
				<section class="daymark-recent" aria-labelledby="daymark-search-results-heading">
					<h2 id="daymark-search-results-heading" class="daymark-visually-hidden">Results</h2>
					<div class="daymark-recent__list" data-search-results aria-live="polite">
						${skeletonRows(3)}
						<span class="daymark-visually-hidden">Loading</span>
					</div>
				</section>
			</section>
			${navFooterMarkup('search')}`;
		},

		bindEvents() {
			root.querySelectorAll('[data-filter]').forEach((chip) => {
				chip.addEventListener('click', () => this.setFilter(chip.getAttribute('data-filter')));
			});

			const sourceFilter = root.querySelector('[data-source-filter]');
			if (sourceFilter) {
				sourceFilter.addEventListener('change', () => {
					this.searchSource = sourceFilter.value;
					this.runSearch();
				});
			}

			const input = root.querySelector('[data-search-input]');
			if (input) {
				const runDebounced = debounce(() => this.runSearch(), 250);
				input.addEventListener('input', () => {
					this.searchQuery = input.value.trim();
					runDebounced();
				});
			}

			const list = root.querySelector('[data-search-results]');
			if (list) {
				list.addEventListener('click', (event) => onFeedListClick(this, event));
			}

			bindDismissible(this, [itemMenusDismissEntry(), navFooterDismissEntry(this)]);

			bindNavFooter(this);
		},

		async init() {
			this._searchSeq = 0;
			this.searchQuery = '';
			this.searchType = '';
			this.searchSource = '';
			this._bySubId = new Map();
			this._detailCache = this._detailCache || new Map();
			this._subscriptions = [];

			// A preset handed from Explore/Me ("browse by type", "your
			// Marks", "Following") right before navigate('#search') — the
			// same one-shot pattern state.pendingType already uses for the
			// composer.
			if (searchPreset) {
				this.searchType = searchPreset.type || '';
				this.searchSource = searchPreset.source || '';
				this.searchQuery = searchPreset.query || '';
				searchPreset = null;
			}

			this.syncFilterChips();
			const input = root.querySelector('[data-search-input]');
			if (input && this.searchQuery) {
				input.value = this.searchQuery;
			}

			this.loadSubscriptionsForFilter();
			await this.runSearch();
		},

		syncFilterChips() {
			root.querySelectorAll('[data-filter]').forEach((chip) => {
				const active = (chip.getAttribute('data-filter') || '') === this.searchType;
				chip.classList.toggle('is-active', active);
				chip.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		},

		setFilter(type) {
			this.searchType = type || '';
			this.syncFilterChips();
			this.runSearch();
		},

		// Fetch active subscriptions once per visit, purely to populate the
		// Source filter's per-site options. Never blocks the search itself;
		// the dropdown just renders "All"/"My Marks" until this resolves.
		async loadSubscriptionsForFilter() {
			this._subscriptions = await fetchSubscriptions();
			const select = root.querySelector('[data-source-filter]');
			if (select && select.isConnected) {
				select.innerHTML = sourceOptionsMarkup(this._subscriptions);
				select.value = this.searchSource;
			}
		},

		async runSearch() {
			const list = root.querySelector('[data-search-results]');
			if (!list) {
				return;
			}
			const seq = ++this._searchSeq;
			list.innerHTML =
				skeletonRows(2) + '<span class="daymark-visually-hidden">Searching</span>';
			// Targets the merged Timeline endpoint (not /marks) so a search
			// covers subscription posts too by default; the Source filter
			// narrows that down to just Marks (`mine=1`) or just one
			// subscription's posts (`subscription_id`). No `status` param:
			// unlike /marks, /timeline always returns published-only from
			// both sources already. An empty query with no filters still
			// runs — it's "everything", the same default Home's own first
			// page shows.
			const params = new URLSearchParams();
			params.set('per_page', '20');
			if (this.searchQuery) {
				params.set('s', this.searchQuery);
			}
			if (this.searchType) {
				params.set('type', this.searchType);
			}
			if ('mine' === this.searchSource) {
				params.set('mine', '1');
			} else if (this.searchSource) {
				params.set('subscription_id', this.searchSource);
			}
			try {
				const items = await apiGet('timeline?' + params.toString());
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				const arr = Array.isArray(items) ? items : [];
				this._bySubId.clear();
				arr.forEach((item) => rememberItem(this, item));
				if (!arr.length) {
					list.innerHTML =
						'<p class="daymark-empty">Nothing matches. Try a different search or filter.</p>';
					return;
				}
				list.innerHTML = arr.map((item) => renderFeedItem(item)).join('');
			} catch (err) {
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				list.innerHTML =
					'<p class="daymark-error" role="alert">Search failed. ' + esc(err.message) + '</p>';
			}
		},
	};

	// --- Screen: Explore ---
	//
	// A first, deliberately non-chronological browsing destination — never
	// a second Timeline. Both sections here are real, built entirely on
	// data the plugin already exposes (Mark type filtering, active
	// subscriptions): "Browse by type" and "Following" hand a preset off to
	// Search rather than duplicating its results rendering. Memories,
	// highlights, collections, favorites, and suggested content are future
	// sections on this same screen, not implied by anything rendered here.

	const ExploreScreen = {
		render() {
			const typeButtons = LAUNCHER_TYPES.map(
				(type) =>
					`<button type="button" class="daymark-exploretype" data-explore-type="${type}">${navIcon(
						TYPE_ICONS[type]
					)}<span>${esc(TYPE_LABELS[type])}</span></button>`
			).join('');
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>Explore</h1>
			</header>
			<section class="daymark-screen">
				<section class="daymark-recent" aria-labelledby="daymark-explore-types-heading">
					<h2 id="daymark-explore-types-heading" class="daymark-section-heading">Browse by type</h2>
					<div class="daymark-exploretypes">${typeButtons}</div>
				</section>
				<section class="daymark-recent" aria-labelledby="daymark-explore-following-heading">
					<h2 id="daymark-explore-following-heading" class="daymark-section-heading">Following</h2>
					<div class="daymark-recent__list" data-explore-following>
						${skeletonRows(2)}
						<span class="daymark-visually-hidden">Loading</span>
					</div>
				</section>
			</section>
			${navFooterMarkup('explore')}`;
		},

		bindEvents() {
			root.querySelectorAll('[data-explore-type]').forEach((btn) => {
				btn.addEventListener('click', () => {
					searchPreset = { type: btn.getAttribute('data-explore-type') };
					navigate('#search');
				});
			});

			const following = root.querySelector('[data-explore-following]');
			if (following) {
				following.addEventListener('click', (event) => {
					const trigger = event.target.closest('[data-explore-subscription]');
					if (!trigger) {
						return;
					}
					searchPreset = { source: trigger.getAttribute('data-explore-subscription') };
					navigate('#search');
				});
			}

			bindDismissible(this, [navFooterDismissEntry(this)]);
			bindNavFooter(this);
		},

		async init() {
			const list = root.querySelector('[data-explore-following]');
			if (!list) {
				return;
			}
			const subscriptions = await fetchSubscriptions();
			if (!list.isConnected) {
				return;
			}
			if (!subscriptions.length) {
				list.innerHTML = `<p class="daymark-empty">You're not following any sites yet. <a href="${esc(
					config.adminSubscriptionsUrl || '#'
				)}">Subscribe to one</a> to see its posts here.</p>`;
				return;
			}
			list.innerHTML = subscriptions
				.map((sub) => {
					const label = sub.site_title && sub.site_title.trim() ? sub.site_title : sub.site_url;
					const icon = sub.site_icon_url
						? `<img class="daymark-recent__thumb" src="${esc(sub.site_icon_url)}" alt="" />`
						: `<span class="daymark-recent__thumb daymark-recent__thumb--glyph" aria-hidden="true">${esc(
								label.charAt(0).toUpperCase()
						  )}</span>`;
					return `<button type="button" class="daymark-recent__item daymark-recent__item--button" data-explore-subscription="${esc(
						String(sub.id)
					)}">${icon}<span class="daymark-recent__body"><span class="daymark-recent__title">${esc(
						label
					)}</span></span></button>`;
				})
				.join('');
		},
	};

	// --- Screen: Me ---
	//
	// A minimal foundation for the user's own Daymark identity: who they
	// are, their drafts, and the surfaces that already exist elsewhere
	// (Notifications, Subscriptions in wp-admin, their WordPress profile).
	// Deliberately doesn't duplicate WordPress's own account settings —
	// Edit profile and Log out link out to WordPress rather than
	// reimplementing them.

	const MeScreen = {
		render() {
			const user = config.currentUser || {};
			const avatar = user.avatarUrl
				? `<img class="daymark-meavatar" src="${esc(user.avatarUrl)}" alt="" />`
				: `<span class="daymark-meavatar daymark-meavatar--glyph" aria-hidden="true">${navIcon(
						ME_GLYPH
				  )}</span>`;
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>Me</h1>
			</header>
			<section class="daymark-screen">
				<div class="daymark-meprofile">
					${avatar}
					<span class="daymark-mename">${esc(user.displayName || '')}</span>
				</div>
				<nav class="daymark-melinks" aria-label="Your Daymark">
					<button type="button" class="daymark-melink" data-me-mymarks>Your Marks</button>
					<a class="daymark-melink" href="#notifications">Notifications</a>
					${
						config.adminSubscriptionsUrl
							? `<a class="daymark-melink" href="${esc(config.adminSubscriptionsUrl)}">Subscriptions</a>`
							: ''
					}
					${
						user.profileEditUrl
							? `<a class="daymark-melink" href="${esc(user.profileEditUrl)}">Edit profile</a>`
							: ''
					}
					${
						user.logoutUrl
							? `<a class="daymark-melink" href="${esc(user.logoutUrl)}">Log out</a>`
							: ''
					}
				</nav>
				<section class="daymark-recent" aria-labelledby="daymark-me-drafts-heading">
					<h2 id="daymark-me-drafts-heading" class="daymark-section-heading">Drafts</h2>
					<div class="daymark-recent__list" data-me-drafts>
						${skeletonRows(2)}
						<span class="daymark-visually-hidden">Loading</span>
					</div>
				</section>
			</section>
			${navFooterMarkup('me')}`;
		},

		bindEvents() {
			const myMarks = root.querySelector('[data-me-mymarks]');
			if (myMarks) {
				myMarks.addEventListener('click', () => {
					searchPreset = { source: 'mine' };
					navigate('#search');
				});
			}

			bindDismissible(this, [navFooterDismissEntry(this)]);
			bindNavFooter(this);
		},

		async init() {
			const list = root.querySelector('[data-me-drafts]');
			if (!list) {
				return;
			}
			try {
				const drafts = await apiGet('marks?status=draft&per_page=10');
				const draftItems = Array.isArray(drafts) ? drafts : [];
				if (!list.isConnected) {
					return;
				}
				if (!draftItems.length) {
					list.innerHTML = '<p class="daymark-empty">No drafts. <a href="#create">Start one</a>.</p>';
					return;
				}
				// View-only here (tap to resume editing) — full Edit/Delete
				// management stays on Home's own Drafts row.
				list.innerHTML = draftItems
					.map(
						(item) =>
							`<a class="daymark-recent__item daymark-recent__item--${esc(
								resolveCardKind(item)
							)}" href="#create" data-edit-draft="${esc(
								String(item.id)
							)}">${renderMarkCore(item)}</a>`
					)
					.join('');
				bindDraftTaps(list);
			} catch (err) {
				if (list.isConnected) {
					list.innerHTML =
						'<p class="daymark-error" role="alert">Could not load drafts. ' + esc(err.message) + '</p>';
				}
			}
		},
	};

	// --- Screen: Create Mark ---

	const CreateScreen = {
		render() {
			const editing = state.editing;
			const existingTiles =
				editing && editing.media.length
					? `<ul class="daymark-editmedia" aria-label="Media already attached to this draft">${editing.media
							.map(
								(m) =>
									`<li class="daymark-editmedia__item">
										${
											m.thumbnail
												? `<img class="daymark-editmedia__thumb" src="${esc(m.thumbnail)}" alt="Attached ${esc(
														m.filename || m.kind
												  )}" />`
												: `<span class="daymark-editmedia__glyph">${esc(m.kind)}</span>`
										}
										${
											m.kind === 'image'
												? `<span class="daymark-alt daymark-alt--edit">
														<label class="daymark-alt__label" for="daymark-existing-alt-${esc(m.id)}">Alt text</label>
														<input type="text" class="daymark-input daymark-alt__input" id="daymark-existing-alt-${esc(
															m.id
														)}" data-existing-alt="${esc(m.id)}" value="${esc(
														m.alt || ''
												  )}" placeholder="Describe this image" />
													</span>`
												: `<span class="daymark-editmedia__name">${esc(m.filename || m.kind)}</span>`
										}
									</li>`
							)
							.join('')}</ul>`
					: '';
			return `
			<header class="daymark-topbar">
				<a class="daymark-backlink" href="#home">&larr; Back</a>
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${
					editing ? 'Edit Draft' : 'New Mark'
				}</h1>
			</header>
			<section class="daymark-screen">
				<p class="daymark-autosave-status" data-autosave-status aria-live="polite"></p>
				${
					editing
						? '<p class="daymark-editbanner"><span class="daymark-chip daymark-chip--draft">Draft</span> Changes save to this Mark — new media is added alongside what’s attached.</p>'
						: ''
				}
				${existingTiles}
				${
					// The Home launcher's Note bubble jumps straight past the
					// picker into a focused writing flow — attaching any file
					// would flip the type away from 'note' anyway (detectType()
					// only ever returns 'note' when nothing is attached), so
					// hiding it here loses no real capability.
					'note' === state.pendingType && !state.files.length && !editing
						? ''
						: ACCEPT_BY_TYPE[state.pendingType]
						? // A typed launcher entry (Image/Video/Audio): camera-first
						  // — the primary action opens the device's camera/mic
						  // directly, a secondary, lower-emphasis action still
						  // reaches an already-taken file. Same single input both
						  // ways; bindEvents() toggles its `capture` attribute per
						  // button before opening it.
						  `<div class="daymark-picker">
					<input type="file" id="daymark-file-input" class="daymark-picker__input" accept="${esc(
						ACCEPT_BY_TYPE[state.pendingType]
					)}" multiple tabindex="-1" />
					<button type="button" class="daymark-picker__zone daymark-picker__zone--button" data-picker-capture>
						<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
						<span>${esc(CAPTURE_LABEL_BY_TYPE[state.pendingType])}</span>
						<span class="daymark-picker__hint">${esc(CAPTURE_HINT_BY_TYPE[state.pendingType])}</span>
					</button>
					<button type="button" class="daymark-btn daymark-btn--text daymark-picker__library" data-picker-library>Choose from library instead</button>
				</div>`
						: // Untyped entry (e.g. a Drafts/Explore empty-state link) —
						  // the intended capture mode isn't known yet, so this stays
						  // the original neutral, non-capture picker.
						  `<div class="daymark-picker">
					<input type="file" id="daymark-file-input" class="daymark-picker__input" accept="image/*,video/*,audio/*" multiple />
					<label for="daymark-file-input" class="daymark-picker__zone">
						<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
						<span>Tap to choose media</span>
						<span class="daymark-picker__hint">Photos, videos, or audio from your device</span>
					</label>
				</div>`
				}
				<div class="daymark-preview" data-preview></div>
				<p class="daymark-typebadge">Mark type: <span class="daymark-chip" data-type-badge>${esc(
					TYPE_LABELS[effectiveType()]
				)}</span></p>
				<div class="daymark-field">
					<label class="daymark-field__label" for="daymark-caption">Caption</label>
					<textarea id="daymark-caption" class="daymark-textarea" rows="4" placeholder="What&#39;s happening?">${esc(
						state.caption
					)}</textarea>
				</div>
				<div data-title-slot></div>
				<div data-transcript-slot></div>
				${
					config.ai && config.ai.available
						? '<button type="button" class="daymark-btn daymark-btn--secondary" data-action="ai-assist">AI Assist</button>'
						: '' /* No AI provider configured — no AI options offered. */
				}
			</section>
			<footer class="daymark-actionbar">
				<p class="daymark-status" data-create-status aria-live="polite"></p>
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="next">Next: Publish &rarr;</button>
			</footer>`;
		},

		bindEvents() {
			// Absent only when the Note bubble skipped the picker entirely.
			const input = root.querySelector('#daymark-file-input');
			const caption = root.querySelector('#daymark-caption');

			// Camera-first two-button picker (typed entries only — see
			// render()): each tap sets or clears `capture` on the one shared
			// input right before opening it, so the same change handler below
			// runs either way.
			const captureBtn = root.querySelector('[data-picker-capture]');
			if (captureBtn && input) {
				captureBtn.addEventListener('click', () => {
					input.setAttribute('capture', CAPTURE_BY_TYPE[state.pendingType] || 'environment');
					input.click();
				});
			}
			const libraryBtn = root.querySelector('[data-picker-library]');
			if (libraryBtn && input) {
				libraryBtn.addEventListener('click', () => {
					input.removeAttribute('capture');
					input.click();
				});
			}

			if (input) {
				input.addEventListener('change', () => {
					const picked = Array.from(input.files || []);
					picked.forEach((file) => {
						const duplicate = state.files.some(
							(entry) =>
								entry.file.name === file.name &&
								entry.file.size === file.size &&
								entry.file.lastModified === file.lastModified
						);
						if (duplicate) {
							return;
						}
						state.fileCounter += 1;
						const isImage = file.type.indexOf('image/') === 0;
						const entry = {
							id: 'f' + state.fileCounter,
							file,
							url: isImage ? URL.createObjectURL(file) : '',
							kind: (file.type || '').split('/')[0] || 'file',
							alt: '',
							altStatus: isImage && config.ai && config.ai.available ? 'loading' : 'idle',
							altEdited: false,
						};
						state.files.push(entry);
						// Pre-fill alt text from the AI provider (if one is
						// connected); the author can edit it before publishing.
						if (entry.altStatus === 'loading') {
							this.generateAltFor(entry);
						}
					});
					input.value = '';
					this.refreshMedia();
					// Protect the actual picked media as soon as possible —
					// don't wait for Publish/Save as Draft to upload it.
					runAutosave();
				});
			}

			caption.addEventListener('input', () => {
				state.caption = caption.value;
				scheduleAutosave();
			});

			// Alt edits on media already attached to a draft, keyed by ID.
			root.querySelectorAll('[data-existing-alt]').forEach((field) => {
				field.addEventListener('input', () => {
					const id = field.getAttribute('data-existing-alt');
					const media = (state.editing && state.editing.media) || [];
					const item = media.find((m) => String(m.id) === String(id));
					if (item) {
						item.alt = field.value;
						scheduleAutosave();
					}
				});
			});

			const aiButton = root.querySelector('[data-action="ai-assist"]');
			if (aiButton) {
				aiButton.addEventListener('click', (event) => {
					state.caption = caption.value;
					AIAssistSheet.show(event.currentTarget);
				});
			}

			root.querySelector('[data-action="next"]').addEventListener('click', () => {
				state.caption = caption.value;
				const status = root.querySelector('[data-create-status]');
				if (!state.files.length && !state.caption.trim()) {
					status.textContent = 'Add media or write a caption to continue.';
					return;
				}
				status.textContent = '';
				state.primaryType = effectiveType();
				// An edited draft keeps its stored destination and category
				// selections; fresh Marks start from the per-type defaults.
				if (!state.editing) {
					state.targets = defaultTargetsFor(state.primaryType);
					state.categories = defaultCategoriesFor(state.primaryType);
				}
				navigate('#publish');
			});

			this.refreshMedia();

			// Close the title-field hint on an outside click or Escape,
			// same convention as the item menu, launcher, search, and the
			// reply box. [data-title-slot] is the stable wrapper
			// refreshTitleField() re-renders into, so this keeps working
			// across every type change during this screen's lifetime.
			bindDismissible(this, [
				{
					selector: '[data-title-slot]',
					close: () => this.closeTitleHint(),
					isOpen: () => {
						const info = root.querySelector('[data-title-info]');
						return !!info && info.getAttribute('aria-expanded') === 'true';
					},
					focus: () => root.querySelector('[data-title-info]'),
				},
			]);
		},

		refreshMedia() {
			const preview = root.querySelector('[data-preview]');
			const badge = root.querySelector('[data-type-badge]');
			if (!preview) {
				return;
			}
			state.primaryType = effectiveType();
			if (badge) {
				badge.textContent = TYPE_LABELS[state.primaryType];
			}
			// The Title field's visibility tracks the effective type, so
			// re-render its slot whenever the media (and thus the type) shifts.
			this.refreshTitleField();
			// Same for the Transcript field (audio/video only), and its
			// "Generate transcript" button needs to reflect newly picked or
			// cleared files either way.
			this.refreshTranscriptField();
			if (!state.files.length) {
				preview.innerHTML = '';
				return;
			}

			const shown = state.files.slice(0, 4);
			const extra = state.files.length - 4;
			const tiles = shown
				.map((entry, index) => {
					const media = entry.url
						? `<img class="daymark-preview__img" src="${esc(entry.url)}" alt="Preview of ${esc(
								entry.file.name
						  )}" />`
						: `<span class="daymark-preview__glyph">${esc(entry.kind)}</span>`;
					const more =
						index === 3 && extra > 0
							? `<span class="daymark-preview__more" aria-hidden="true">+${extra}</span>`
							: '';
					return `<li class="daymark-preview__tile">${media}${more}</li>`;
				})
				.join('');

			const fileRows = state.files
				.map(
					(entry) => `
				<li class="daymark-filelist__item">
					<div class="daymark-filelist__row">
						<span class="daymark-filelist__name">${esc(entry.file.name)}</span>
						<button type="button" class="daymark-filelist__clear" data-clear-file="${esc(
							entry.id
						)}" aria-label="Clear ${esc(entry.file.name)}">Clear</button>
					</div>
					${entry.kind === 'image' ? this.altFieldMarkup(entry) : ''}
				</li>`
				)
				.join('');

			const extraLabel = extra > 0 ? `, plus ${extra} more` : '';
			preview.innerHTML = `
				<ul class="daymark-preview__grid" aria-label="Selected media previews${esc(extraLabel)}">${tiles}</ul>
				<ul class="daymark-filelist">${fileRows}</ul>`;

			preview.querySelectorAll('[data-clear-file]').forEach((button) => {
				button.addEventListener('click', () => {
					const id = button.getAttribute('data-clear-file');
					const entry = state.files.find((f) => f.id === id);
					if (entry && entry.url) {
						URL.revokeObjectURL(entry.url);
					}
					state.files = state.files.filter((f) => f.id !== id);
					this.refreshMedia();
				});
			});

			preview.querySelectorAll('[data-alt-for]').forEach((field) => {
				field.addEventListener('input', () => {
					const entry = state.files.find((f) => f.id === field.getAttribute('data-alt-for'));
					if (entry) {
						entry.alt = field.value;
						entry.altEdited = true; // Stop a late AI result from overwriting.
						scheduleAutosave();
					}
				});
			});

			// "Improve with AI"/"Suggest with AI" — a manual re-run that, unlike
			// the automatic first-pick pass, always applies its result (even
			// over a hand-typed value) since the author explicitly asked for
			// it, and sends the current text so the provider refines it rather
			// than describing the image from scratch.
			preview.querySelectorAll('[data-alt-improve]').forEach((button) => {
				button.addEventListener('click', () => {
					const entry = state.files.find((f) => f.id === button.getAttribute('data-alt-improve'));
					if (!entry || entry.altStatus === 'loading') {
						return;
					}
					const field = root.querySelector('[data-alt-for="' + entry.id + '"]');
					if (field) {
						entry.alt = field.value;
					}
					this.generateAltFor(entry, { force: true, existingAlt: entry.alt });
				});
			});
		},

		// Alt-text field for one image entry, with a hint reflecting AI state
		// and a manual "Improve with AI"/"Suggest with AI" re-run button.
		altFieldMarkup(entry) {
			const hint =
				entry.altStatus === 'loading'
					? '<span class="daymark-alt__hint">Generating alt text…</span>'
					: entry.altStatus === 'done'
					? '<span class="daymark-alt__hint">AI-suggested — edit as needed</span>'
					: '';
			const canImprove = config.ai && config.ai.available && entry.altStatus !== 'loading';
			const improveButton = canImprove
				? `<button type="button" class="daymark-btn daymark-btn--text daymark-alt__improve" data-alt-improve="${esc(
						entry.id
				  )}">${entry.alt ? 'Improve with AI' : 'Suggest with AI'}</button>`
				: '';
			return `
				<div class="daymark-alt">
					<label class="daymark-alt__label" for="daymark-alt-${esc(entry.id)}">Alt text</label>
					<input type="text" class="daymark-input daymark-alt__input" id="daymark-alt-${esc(
						entry.id
					)}" data-alt-for="${esc(entry.id)}" value="${esc(entry.alt)}" placeholder="Describe this image" ${
				entry.altStatus === 'loading' ? 'aria-busy="true"' : ''
			} />
					${hint}
					${improveButton}
				</div>`;
		},

		// Ask the provider to describe (or, with opts.existingAlt, improve) one
		// image, then drop the result into its alt field. Patches just this
		// entry's field in place so a late result never disrupts text the
		// author is typing into another image's field. On the automatic
		// first-pick pass (no opts), a result never overwrites text the author
		// already typed; opts.force (the manual "Improve with AI" button)
		// always applies its result, since that's an explicit request.
		async generateAltFor(entry, opts) {
			opts = opts || {};
			entry.altStatus = 'loading';
			// Reflect "loading" immediately for a manual re-run (the field
			// already exists in the DOM); on the automatic first-pick call
			// there's nothing to patch yet — the entry's initial altStatus
			// already renders as loading once the caller's own refreshMedia()
			// runs.
			const startField = root.querySelector('[data-alt-for="' + entry.id + '"]');
			if (startField) {
				startField.setAttribute('aria-busy', 'true');
			}
			const startButton = root.querySelector('[data-alt-improve="' + entry.id + '"]');
			if (startButton) {
				startButton.disabled = true;
			}
			try {
				const formData = new FormData();
				formData.append('image', entry.file, entry.file.name);
				formData.append('text', state.caption || '');
				if (opts.existingAlt) {
					formData.append('existing_alt', opts.existingAlt);
				}
				const result = await apiUpload('ai/alt-text', formData);
				if ((opts.force || !entry.altEdited) && result && result.alt_text) {
					entry.alt = String(result.alt_text);
					entry.altEdited = false; // An AI-refreshed value is current, not a stale hand-typed edit.
				}
				entry.altStatus = 'done';
			} catch (err) {
				entry.altStatus = 'idle';
			}

			if (!state.files.includes(entry)) {
				return; // Image was cleared before the suggestion arrived.
			}
			const field = root.querySelector('[data-alt-for="' + entry.id + '"]');
			if (!field) {
				return;
			}
			if (!entry.altEdited) {
				field.value = entry.alt;
			}
			field.removeAttribute('aria-busy');
			const hint = field.parentElement.querySelector('.daymark-alt__hint');
			if (hint) {
				hint.textContent = entry.altStatus === 'done' ? 'AI-suggested — edit as needed' : '';
			}
			const improveButton = field.parentElement.querySelector('.daymark-alt__improve');
			if (improveButton) {
				improveButton.disabled = false;
				improveButton.textContent = entry.alt ? 'Improve with AI' : 'Suggest with AI';
			}
		},

		// Markup for the optional Title field, or '' when the current type's
		// policy hides it. The ⓘ button toggles the keyboard-reachable hint.
		titleFieldMarkup() {
			if (!titleFieldShown()) {
				return '';
			}
			const busy = state.titleStatus === 'loading' ? ' aria-busy="true"' : '';
			return `
				<div class="daymark-field daymark-titlefield">
					<div class="daymark-titlefield__labelrow">
						<label class="daymark-field__label" for="daymark-title">Title (optional)</label>
						<button type="button" class="daymark-infobtn" data-title-info aria-label="About the title field" aria-expanded="false" aria-controls="daymark-title-hint">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
						</button>
					</div>
					<input type="text" class="daymark-input daymark-titlefield__input" id="daymark-title" data-title-input value="${esc(
						state.title
					)}" placeholder="Add a title"${busy} />
					<p class="daymark-titlefield__hint" id="daymark-title-hint" data-title-hint hidden>If left blank, the title is generated from your Mark&#39;s text.</p>
				</div>`;
		},

		// Render (or clear) the Title-field slot in place and, when a provider
		// is available, kick off a one-time AI prefill. Called on every media
		// change so the field appears/disappears as the effective type shifts.
		refreshTitleField() {
			const slot = root.querySelector('[data-title-slot]');
			if (!slot) {
				return;
			}
			slot.innerHTML = this.titleFieldMarkup();
			if (!titleFieldShown()) {
				return;
			}

			const input = slot.querySelector('[data-title-input]');
			if (input) {
				input.addEventListener('input', () => {
					state.title = input.value;
					state.titleEdited = true; // Stop a late AI result from overwriting.
					scheduleAutosave();
				});
			}

			const info = slot.querySelector('[data-title-info]');
			const hint = slot.querySelector('[data-title-hint]');
			if (info && hint) {
				info.addEventListener('click', () => {
					if (info.getAttribute('aria-expanded') === 'true') {
						this.closeTitleHint();
					} else {
						info.setAttribute('aria-expanded', 'true');
						hint.hidden = false;
					}
				});
			}

			// Pre-fill from the AI provider once (if one is connected and the
			// author has not already typed or seeded a title). No provider →
			// leave the field empty for manual entry.
			if (
				config.ai &&
				config.ai.available &&
				state.titleStatus === 'idle' &&
				!state.titleEdited
			) {
				this.generateTitle();
			}
		},

		// Idempotent, matching closeSearch()/closeLauncher(): safe for the
		// outside-click/Escape handler below to call unconditionally. Queries
		// fresh each time rather than closing over the info/hint elements,
		// since refreshTitleField() replaces them whenever the effective
		// type changes.
		closeTitleHint() {
			const info = root.querySelector('[data-title-info]');
			const hint = root.querySelector('[data-title-hint]');
			if (!info || info.getAttribute('aria-expanded') !== 'true') {
				return;
			}
			info.setAttribute('aria-expanded', 'false');
			if (hint) {
				hint.hidden = true;
			}
		},

		// Ask the provider for a short title and drop it into the field unless
		// the author has already typed one. Attempted at most once per compose
		// session (status advances to 'done' even on failure, so a repeated
		// media change never re-fires the request).
		async generateTitle() {
			state.titleStatus = 'loading';
			try {
				const result = await apiPost('ai/title', {
					text: state.caption || '',
					primary_type: effectiveType(),
					transcript: state.transcript || '',
				});
				if (!state.titleEdited && result && result.title) {
					state.title = String(result.title);
				}
			} catch (err) {
				// Non-blocking: leave the field for manual entry.
			}
			state.titleStatus = 'done';

			const field = root.querySelector('[data-title-input]');
			if (!field) {
				return; // Field was hidden (type changed) before the result arrived.
			}
			if (!state.titleEdited) {
				field.value = state.title;
			}
			field.removeAttribute('aria-busy');
		},

		// Markup for the optional Transcript field, or '' when the current
		// type isn't audio/video. "Generate transcript" is manual, author
		// triggered — unlike alt text this never auto-fires on file pick, so
		// a large recording is only ever sent to the provider when asked for.
		transcriptFieldMarkup() {
			if (!transcriptFieldShown()) {
				return '';
			}
			const hasCandidate = !!transcriptSourceEntry();
			const isLoading = state.transcriptStatus === 'loading';
			const showButton = config.ai && config.ai.available && (hasCandidate || isLoading);
			const buttonLabel = isLoading
				? 'Generating transcript…'
				: state.transcript
				? 'Regenerate transcript'
				: 'Generate transcript';
			return `
				<div class="daymark-field daymark-transcriptfield">
					<label class="daymark-field__label" for="daymark-transcript">Transcript (optional)</label>
					<textarea id="daymark-transcript" class="daymark-textarea" rows="4" placeholder="Add a transcript, or generate one with AI"${
						isLoading ? ' aria-busy="true"' : ''
					} data-transcript-input>${esc(state.transcript)}</textarea>
					${
						showButton
							? `<button type="button" class="daymark-btn daymark-btn--text daymark-transcriptfield__action" data-action="generate-transcript"${
									isLoading || !hasCandidate ? ' disabled' : ''
							  }>${esc(buttonLabel)}</button>`
							: ''
					}
				</div>`;
		},

		// Render (or clear) the Transcript-field slot in place, matching
		// refreshTitleField()'s pattern — called on every media change so the
		// field appears/disappears as the effective type shifts, and its
		// "Generate transcript" button updates as files are picked/cleared.
		refreshTranscriptField() {
			const slot = root.querySelector('[data-transcript-slot]');
			if (!slot) {
				return;
			}
			slot.innerHTML = this.transcriptFieldMarkup();
			if (!transcriptFieldShown()) {
				return;
			}

			const textarea = slot.querySelector('[data-transcript-input]');
			if (textarea) {
				textarea.addEventListener('input', () => {
					state.transcript = textarea.value;
					state.transcriptEdited = true;
					scheduleAutosave();
				});
			}

			const button = slot.querySelector('[data-action="generate-transcript"]');
			if (button) {
				button.addEventListener('click', () => this.generateTranscript());
			}
		},

		// Ask the provider to transcribe the picked audio/video file, then
		// drop the result into the Transcript field. Manual, author
		// triggered (see transcriptFieldMarkup()) — never blocks publishing,
		// and a failure just leaves the field for manual entry.
		async generateTranscript() {
			const entry = transcriptSourceEntry();
			if (!entry || state.transcriptStatus === 'loading') {
				return;
			}
			state.transcriptStatus = 'loading';
			this.refreshTranscriptField();
			try {
				const formData = new FormData();
				formData.append('media', entry.file, entry.file.name);
				const result = await apiUpload('ai/transcript', formData);
				if (result && typeof result.transcript === 'string' && result.transcript) {
					state.transcript = result.transcript;
					state.transcriptEdited = false;
					scheduleAutosave();
				}
			} catch (err) {
				// Non-blocking: leave the field for manual entry.
			}
			state.transcriptStatus = 'done';
			this.refreshTranscriptField();
		},
	};

	// --- Overlay: AI Assist sheet ---

	const AIAssistSheet = {
		el: null,
		opener: null,
		tags: [],

		show(opener) {
			this.opener = opener || null;
			this.tags = state.tags.slice();
			if (!this.el) {
				this.el = document.createElement('div');
				this.el.className = 'daymark-sheet';
				document.body.appendChild(this.el);
			}
			this.el.hidden = false;
			this.el.innerHTML = `
			<button type="button" class="daymark-sheet__backdrop" data-sheet-dismiss aria-label="Dismiss AI Assist"></button>
			<div class="daymark-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="daymark-sheet-title">
				<h2 class="daymark-sheet__title" id="daymark-sheet-title" tabindex="-1">AI Assist</h2>
				<div class="daymark-sheet__body" data-sheet-body aria-live="polite">
					<p class="daymark-loading"><span class="daymark-spinner" aria-hidden="true"></span> Getting suggestions&hellip;</p>
				</div>
			</div>`;

			this.el.querySelector('[data-sheet-dismiss]').addEventListener('click', () => this.hide());
			this.onKeydown = (event) => {
				if (event.key === 'Escape') {
					this.hide();
				}
			};
			document.addEventListener('keydown', this.onKeydown);
			this.el.querySelector('#daymark-sheet-title').focus();
			this.fetchSuggestions();
		},

		async fetchSuggestions() {
			const body = this.el.querySelector('[data-sheet-body]');
			try {
				// Note: files are not uploaded until publish, so no attachment
				// IDs exist yet; media_ids is empty at suggestion time.
				const suggestions = await apiPost('ai/suggestions', {
					text: state.caption,
					media_ids: [],
					primary_type: effectiveType(),
					transcript: state.transcript || '',
				});
				if (!this.el || this.el.hidden) {
					return;
				}
				this.tags = Array.isArray(suggestions.tags)
					? suggestions.tags.map((t) => String(t))
					: [];
				body.innerHTML = this.renderForm(suggestions);
				this.bindForm();
			} catch (err) {
				if (!this.el || this.el.hidden) {
					return;
				}
				body.innerHTML = `
					<p class="daymark-error" role="alert">Could not get suggestions. ${esc(err.message)}</p>
					<div class="daymark-sheet__actions">
						<button type="button" class="daymark-btn daymark-btn--primary" data-sheet-retry>Retry</button>
						<button type="button" class="daymark-btn daymark-btn--text" data-sheet-skip>Skip</button>
					</div>`;
				body.querySelector('[data-sheet-retry]').addEventListener('click', () => {
					body.innerHTML =
						'<p class="daymark-loading"><span class="daymark-spinner" aria-hidden="true"></span> Getting suggestions&hellip;</p>';
					this.fetchSuggestions();
				});
				body.querySelector('[data-sheet-skip]').addEventListener('click', () => this.hide());
			}
		},

		renderForm(suggestions) {
			const notice = suggestions.is_mocked
				? '<p class="daymark-notice">Using demo suggestions — connect an AI provider in WordPress settings for real suggestions.</p>'
				: suggestions.provider_label
				? `<p class="daymark-notice">Suggestions by ${esc(suggestions.provider_label)}.</p>`
				: '';
			return `
			${notice}
			<div class="daymark-field">
				<label class="daymark-field__label" for="daymark-ai-caption">Suggested caption</label>
				<textarea id="daymark-ai-caption" class="daymark-textarea" rows="3">${esc(
					suggestions.caption || ''
				)}</textarea>
			</div>
			<fieldset class="daymark-tags">
				<legend class="daymark-tags__legend">Suggested tags</legend>
				<ul class="daymark-tags__list" data-tag-list></ul>
				<div class="daymark-tags__addrow">
					<label class="daymark-visually-hidden" for="daymark-ai-newtag">Add a tag</label>
					<input type="text" id="daymark-ai-newtag" class="daymark-input" placeholder="Add a tag" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="daymark-tag-suggest" />
					<button type="button" class="daymark-btn daymark-btn--secondary" data-tag-add>+ Add</button>
				</div>
				<ul class="daymark-tags__suggest" id="daymark-tag-suggest" data-tag-suggest hidden></ul>
			</fieldset>
			<div class="daymark-sheet__actions">
				<button type="button" class="daymark-btn daymark-btn--primary" data-sheet-accept>Accept All</button>
				<button type="button" class="daymark-btn daymark-btn--text" data-sheet-skip>Skip</button>
			</div>`;
		},

		bindForm() {
			this.renderTags();

			const input = this.el.querySelector('#daymark-ai-newtag');
			const suggestList = this.el.querySelector('[data-tag-suggest]');

			const addTag = (value) => {
				const tag = value.trim();
				if (tag && !this.tags.includes(tag)) {
					this.tags.push(tag);
					this.renderTags();
				}
				input.value = '';
				input.focus();
				this.hideTagSuggestions();
			};

			this.el.querySelector('[data-tag-add]').addEventListener('click', () => addTag(input.value));

			// Existing-tag autocomplete: fewer characters typed when the site
			// already has the tag, per the "minimal text entry" product
			// principle — tap a suggestion instead of typing the full name.
			const runTagSearch = debounce((query) => {
				if (!query) {
					this.hideTagSuggestions();
					return;
				}
				apiGet('tags?search=' + encodeURIComponent(query))
					.then((results) => this.renderTagSuggestions(Array.isArray(results) ? results : [], addTag))
					.catch(() => this.hideTagSuggestions());
			}, 250);

			input.addEventListener('input', () => runTagSearch(input.value.trim()));
			input.addEventListener('keydown', (event) => {
				if (event.key === 'Escape') {
					this.hideTagSuggestions();
				} else if (event.key === 'Enter') {
					event.preventDefault();
					addTag(input.value);
				}
			});
			input.addEventListener('blur', () => {
				// Let a suggestion tap register before the list disappears.
				setTimeout(() => this.hideTagSuggestions(), 150);
			});
			suggestList.hidden = true;

			this.el.querySelector('[data-sheet-accept]').addEventListener('click', () => {
				state.caption = this.el.querySelector('#daymark-ai-caption').value;
				state.tags = this.tags.slice();
				state.aiAssistUsed = true;
				const captionField = document.getElementById('daymark-caption');
				if (captionField) {
					captionField.value = state.caption;
				}
				this.hide();
				runAutosave();
			});

			this.el.querySelector('[data-sheet-skip]').addEventListener('click', () => this.hide());
		},

		renderTags() {
			const list = this.el.querySelector('[data-tag-list]');
			if (!list) {
				return;
			}
			list.innerHTML = this.tags.length
				? this.tags
						.map(
							(tag, index) => `
						<li class="daymark-tags__chip">
							<span>${esc(tag)}</span>
							<button type="button" class="daymark-tags__remove" data-tag-remove="${index}" aria-label="Remove tag ${esc(
								tag
							)}">&times;</button>
						</li>`
						)
						.join('')
				: '<li class="daymark-note-card__meta">No tags suggested.</li>';
			list.querySelectorAll('[data-tag-remove]').forEach((button) => {
				button.addEventListener('click', () => {
					this.tags.splice(Number(button.getAttribute('data-tag-remove')), 1);
					this.renderTags();
				});
			});
		},

		renderTagSuggestions(results, addTag) {
			const list = this.el && this.el.querySelector('[data-tag-suggest]');
			const input = this.el && this.el.querySelector('#daymark-ai-newtag');
			if (!list || !input) {
				return;
			}
			const options = results.filter((tag) => tag && !this.tags.includes(tag.name));
			if (!options.length) {
				this.hideTagSuggestions();
				return;
			}
			list.innerHTML = options
				.map(
					(tag) =>
						`<li><button type="button" class="daymark-tags__suggestitem" data-tag-suggest-pick="${esc(
							tag.name
						)}">${esc(tag.name)}</button></li>`
				)
				.join('');
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			list.querySelectorAll('[data-tag-suggest-pick]').forEach((button) => {
				button.addEventListener('mousedown', (event) => {
					// mousedown (not click) fires before the input's blur handler.
					event.preventDefault();
					addTag(button.getAttribute('data-tag-suggest-pick'));
				});
			});
		},

		hideTagSuggestions() {
			const list = this.el && this.el.querySelector('[data-tag-suggest]');
			const input = this.el && this.el.querySelector('#daymark-ai-newtag');
			if (list) {
				list.hidden = true;
				list.innerHTML = '';
			}
			if (input) {
				input.setAttribute('aria-expanded', 'false');
			}
		},

		hide(restoreFocus = true) {
			if (!this.el || this.el.hidden) {
				return;
			}
			this.el.hidden = true;
			this.el.innerHTML = '';
			if (this.onKeydown) {
				document.removeEventListener('keydown', this.onKeydown);
				this.onKeydown = null;
			}
			if (restoreFocus && this.opener && this.opener.isConnected) {
				this.opener.focus();
			}
			this.opener = null;
		},
	};

	// --- Timeline card kinds: shared type-icon rail + per-kind bodies ---
	//
	// Home's Recent Marks list is the merged Timeline feed (GET
	// /daymark/v1/timeline): a Mark item and a subscription-post item each
	// render their own card shape, but both resolve to one shared set of
	// content "kinds" here — a Mark's own `type`
	// (note/image/gallery/video/audio/mixed) and a subscription post's
	// `post_format` are already the same vocabulary in spirit
	// (Daymark_Subscription_Source_Feed::normalize() maps a subscribed
	// feed's content onto exactly this set, not raw WordPress post
	// formats) — so one icon set, one media-slot renderer, and one meta-line
	// renderer serve every Timeline item regardless of source, while each
	// kind still gets its own visual weight (image/video/gallery/mixed get
	// a media-dominant banner, audio a compact artwork row, note pure
	// typography, and a subscription post's 'standard' format further
	// splits into 'article'/'link' below). 'article' and 'link' only ever
	// apply to a subscription post — a Mark is never "an article."

	// Icons for the rail's type-indicator column and a media-dominant
	// card's placeholder panel (see renderCardMedia()). Reuses TYPE_ICONS'
	// own image/video/audio/note glyphs so the same shape means the same
	// thing everywhere in the app; the other four kinds have no
	// composer-launcher equivalent, so they're defined here instead of
	// extending TYPE_ICONS itself.
	const CARD_KIND_ICONS = Object.assign({}, TYPE_ICONS, {
		gallery:
			'<rect x="7" y="7" width="14" height="14" rx="2"></rect><path d="M3 15V5a2 2 0 0 1 2-2h10"></path>',
		mixed:
			'<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>',
		article:
			'<line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="17" y1="14" x2="3" y2="14"></line><line x1="21" y1="18" x2="3" y2="18"></line>',
		link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>',
	});

	// Display labels for the same kind vocabulary — TYPE_LABELS covers a
	// Mark's own 6, this adds the 2 a Mark never has.
	const CARD_KIND_LABELS = Object.assign({}, TYPE_LABELS, { article: 'Article', link: 'Link' });

	// Kinds that get the media-dominant layout — a full-width band above
	// the caption, not a small thumb beside it — the ones a real
	// photo/poster frame is the point of. Audio gets its own compact
	// artwork-beside-text treatment instead (see MEDIA_DOMINANT_KINDS'
	// absence of 'audio'): Path's own audio moments show a small square,
	// not a banner, and a podcast episode's cover art is square by
	// convention anyway.
	const MEDIA_DOMINANT_KINDS = ['image', 'gallery', 'video', 'mixed'];

	// Kinds a small rich-media badge overlays on their own artwork (see
	// cardKindBadge()) — 'image' doesn't need one (the photo itself already
	// says "image"), and neither does a kind with no media slot at all
	// (note/link).
	// 'video' isn't included: its own dedicated centered play button (see
	// renderCardMedia()'s playButton) already says "tap to play" more
	// directly than a small corner badge duplicating the rail's own
	// video-camera icon would.
	const BADGED_KINDS = ['audio', 'gallery', 'mixed'];

	// Audio's own badge is a play triangle rather than a second music-note
	// icon (the rail's own type icon already identifies "audio" from a
	// distance) — this is the compact "tap to listen" affordance the brief
	// asks a podcast/audio card's artwork to carry.
	const AUDIO_BADGE_ICON =
		'<polygon points="6 4 20 12 6 20 6 4"></polygon>';

	// A subscription post's 'standard' format (no richer signal from the
	// feed itself) splits into 'article' or 'link' by a word-count
	// heuristic: the excerpt server-side is always a fixed
	// wp_trim_words(..., 40) regardless of the source post's real length
	// (see Daymark_Subscription_Source_Feed::normalize()) — there's no
	// length-preserving signal yet to tell "this genuinely was short" apart
	// from "this got truncated," so a short excerpt with no image is
	// treated as link-like; anything longer, or carrying an image, reads as
	// an article. A deliberate approximation until the feed source captures
	// real content length — see this change's summary for the follow-up
	// this implies.
	const CARD_KIND_LINK_WORD_THRESHOLD = 20;

	function resolveCardKind(item) {
		if ('subscription_post' !== item.item_type) {
			if (item.type) {
				return item.type;
			}
			// GET /timeline's Marks side isn't gated on _daymark_is_mark
			// (see get_timeline()'s own docblock, class-rest-controller.php)
			// — an ordinary post published straight through the block
			// editor shows up here too, with no _daymark_primary_type meta
			// at all to report as `type`. Infer a reasonable kind from
			// what the post actually has instead of collapsing every
			// non-Daymark post to the same bare note row: a real featured
			// image gets the same media-dominant treatment a real Image
			// Mark does, and any real excerpt otherwise reads as an
			// article — the same inference a subscription's own
			// 'standard' format gets just below.
			if (item.thumbnail) {
				return 'image';
			}
			const plainExcerpt = toPlainText(item.excerpt || '').trim();
			return plainExcerpt ? 'article' : 'note';
		}
		if (item.post_format && 'standard' !== item.post_format) {
			return item.post_format;
		}
		const words = toPlainText(item.excerpt || '')
			.trim()
			.split(/\s+/)
			.filter(Boolean).length;
		return !item.featured_image_url && words > 0 && words < CARD_KIND_LINK_WORD_THRESHOLD
			? 'link'
			: 'article';
	}

	// The rail column every card carries between its site icon and its own
	// body — a quiet, muted indicator of what kind of thing this is,
	// visually threaded to the item above and below by a thin connecting
	// line (see .daymark-recent__typeicon::before in app.css) so a scan
	// down the list reads as one continuous chronological flow, the way
	// Path's own timeline spine does. Deliberately not a tap target — it
	// carries no interaction of its own, just a glance-able signal — so it
	// stays out of the touch-target audit entirely.
	function renderTypeIcon(kind) {
		const glyph = CARD_KIND_ICONS[kind] || CARD_KIND_ICONS.note;
		const label = CARD_KIND_LABELS[kind] || 'Post';
		return `<span class="daymark-recent__typeicon" aria-hidden="true" title="${esc(
			label
		)}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${glyph}</svg></span>`;
	}

	// Small badge overlaid on a card's own artwork — video/audio/gallery/
	// mixed aren't obvious from a static image alone the way 'image'
	// already is from its own thumbnail, so a glance at the badge is
	// enough to know what a tap leads to before opening it.
	function cardKindBadge(kind) {
		if (!BADGED_KINDS.includes(kind)) {
			return '';
		}
		const glyph = 'audio' === kind ? AUDIO_BADGE_ICON : CARD_KIND_ICONS[kind];
		const fill = 'audio' === kind ? 'currentColor' : 'none';
		return `<span class="daymark-recent__thumbbadge" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${glyph}</svg></span>`;
	}

	// One card's media slot: a real image — a Mark's own thumbnail, a
	// subscription post's featured_image_url, or (only when there's no
	// post image at all) the subscription's own site icon — when there is
	// one; a type-glyph placeholder panel when there isn't (a video/audio
	// Mark very often has neither: Daymark's publisher only ever sets a
	// featured image for an image/gallery Mark — see attach_media() in
	// class-publisher.php — so the placeholder keeps the media slot's own
	// visual promise instead of collapsing to nothing); or no slot at all
	// for a kind with none (note/link). A broken image degrades to the
	// same placeholder via imgWithFallback()'s shared error handling.
	function renderCardMedia(item, kind) {
		if ('note' === kind || 'link' === kind) {
			return '';
		}
		const isMedia = MEDIA_DOMINANT_KINDS.includes(kind);
		const wrapClass = isMedia ? 'daymark-recent__thumbwrap daymark-recent__thumbwrap--media' : 'daymark-recent__thumbwrap';
		const src = item.thumbnail || item.featured_image_url || item.site_icon_url;
		const glyph = (CARD_KIND_LABELS[kind] || 'S').charAt(0);
		const playButton =
			'video' === kind
				? '<span class="daymark-recent__thumbplay" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 4 20 12 6 20 6 4"></polygon></svg></span>'
				: '';
		if (src) {
			const thumbClass =
				'daymark-recent__thumb' +
				(item.thumbnail || item.featured_image_url ? '' : ' daymark-recent__thumb--siteicon');
			return `<span class="${wrapClass}">${imgWithFallback(
				src,
				thumbClass,
				glyph
			)}${cardKindBadge(kind)}${playButton}</span>`;
		}
		const iconSize = isMedia ? 32 : 20;
		return `<span class="${wrapClass} daymark-recent__thumb--placeholder" aria-hidden="true"><svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${
			CARD_KIND_ICONS[kind] || CARD_KIND_ICONS.note
		}</svg></span>${playButton}`;
	}

	// The meta line every card kind shares: an optional leading chip
	// ('Draft' on an unpublished Mark, 'Subscribed' on a subscription
	// post), the author when there is one (a subscription post only — a
	// Mark's author is implicitly config.currentUser, shown via its own
	// site icon instead), and a relative timestamp. Deliberately doesn't
	// repeat the kind as a text label the way this line used to for a Mark
	// (TYPE_LABELS[item.type]) — the rail's own type icon (see
	// renderTypeIcon()) already says that now, so the text stays free for
	// what the icon can't show.
	function renderCardMeta(item, chipHtml) {
		const parts = [];
		if (chipHtml) {
			parts.push(chipHtml);
		}
		if (item.author) {
			parts.push(esc(item.author));
		}
		if (item.date) {
			parts.push(esc(relativeTime(item.date)));
		}
		return parts.join(' &middot; ');
	}

	// The thumbnail/media(-or-placeholder) + title + meta + stats core of
	// one Mark's card markup — used by every Mark item any feed-list screen
	// renders (Home's Recent/Drafts, Search's results), always wrapped in
	// the same ⋯ actions menu (see renderMarkItem()). Keeping this in one
	// place is what "reuse, don't reinvent Mark card markup" means.
	function renderMarkCore(item) {
		const kind = resolveCardKind(item);
		const title = item.title || 'Untitled Mark';
		// Drafts look identical to published Marks otherwise — and their
		// permalinks are invisible to visitors — so say so. (The Timeline
		// endpoint only ever returns published Marks, so this never fires
		// there; Home's Recent/Drafts lists are what actually rely on it.)
		const isDraft = item.status && 'publish' !== item.status;
		const chip = isDraft ? '<span class="daymark-chip daymark-chip--draft">Draft</span>' : '';
		// A caption longer than generate_title()'s own 8-word title trim
		// (class-publisher.php) carries real content beyond the title —
		// show it as a secondary line; a short caption's title already
		// *is* the whole caption, so repeating it as an "excerpt" would
		// just be noise.
		const excerpt = toPlainText(item.excerpt || '');
		const showExcerpt = excerpt && excerpt !== title;
		return `
					${renderCardMedia(item, kind)}
					<span class="daymark-recent__body">
						<span class="daymark-recent__title">${esc(title)}</span>
						<span class="daymark-recent__meta">${renderCardMeta(item, chip)}</span>
						${showExcerpt ? `<span class="daymark-recent__excerpt">${esc(excerpt)}</span>` : ''}
						${isDraft ? '' : renderItemStats(item)}
					</span>`;
	}

	// One subscription-post Timeline card. A <button>, not an <a>: opening it
	// shows the click-through detail sheet in place rather than navigating —
	// its permalink points at the *source* site, not anywhere in this app.
	// No comment/like stat row: those only ever exist for a Mark — Daymark
	// doesn't (and, for someone else's post, can't cheaply) track
	// engagement data of its own for a subscription post.
	function renderSubscriptionPostCard(item) {
		const kind = resolveCardKind(item);
		const title = item.title || 'Untitled post';
		const excerpt = toPlainText(item.excerpt || '');
		// Every kind but the media-dominant ones shows its excerpt — an
		// image/video/gallery/mixed card already carries the point in its
		// own banner, so a caption stays secondary the same way a Mark's
		// own excerpt does; article/link/audio/note all lean on the text.
		const showExcerpt = excerpt && !MEDIA_DOMINANT_KINDS.includes(kind);
		const id = esc(String(item.id));
		// A <button> can't contain another interactive <button> — the
		// existing subscription-post button (unchanged below) becomes a
		// sibling of the site icon and type icon inside a wrapper div
		// instead, matching the Mark item's own wrapper shape.
		const siteLabel = subscriptionSiteLabel(item);
		return `
				<div class="daymark-recent__item-wrap">
					${renderSiteIconButton({
						iconSrc: item.site_icon_url || '',
						iconAlt: siteLabel,
						ariaLabel: 'Filter Timeline to posts from ' + siteLabel,
						filterValue: String(item.subscription_id),
					})}
					${renderTypeIcon(kind)}
					<button type="button" class="daymark-recent__item daymark-recent__item--button daymark-recent__item--${esc(
						kind
					)}" data-subpost="${id}">
						${renderCardMedia(item, kind)}
						<span class="daymark-recent__body">
							<span class="daymark-recent__title">${esc(title)}</span>
							<span class="daymark-recent__meta">${renderCardMeta(
								item,
								'<span class="daymark-chip daymark-chip--draft">Subscribed</span>'
							)}</span>
							${showExcerpt ? `<span class="daymark-recent__excerpt">${esc(excerpt)}</span>` : ''}
						</span>
					</button>
				</div>`;
	}

	// --- Overlay: subscription-post detail sheet ---

	// Shown when opening a Home subscription-post card. Always issues the
	// click-through fetch (GET /subscription-posts/{id}) on first open —
	// body_content is never present in the merged Timeline feed response,
	// even for a 'full' post — then caches the result on the Map handed in
	// by HomeScreen so re-opening the same card doesn't re-fetch it.
	const SubscriptionPostSheet = {
		el: null,
		opener: null,
		cache: null,
		current: null,

		show(item, cache, opener) {
			this.opener = opener || null;
			this.cache = cache;
			this.current = item;
			if (!this.el) {
				this.el = document.createElement('div');
				this.el.className = 'daymark-sheet';
				document.body.appendChild(this.el);
			}
			this.el.hidden = false;
			this.renderShell();
			this.onKeydown = (event) => {
				if ('Escape' === event.key) {
					this.hide();
				}
			};
			document.addEventListener('keydown', this.onKeydown);
			const heading = this.el.querySelector('#daymark-subpost-title');
			if (heading) {
				heading.focus();
			}
			this.load();
		},

		renderShell() {
			const item = this.current;
			const metaParts = [];
			if (item.author) {
				metaParts.push(esc(item.author));
			}
			if (item.date) {
				metaParts.push(esc(relativeTime(item.date)));
			}
			this.el.innerHTML = `
			<button type="button" class="daymark-sheet__backdrop" data-sheet-dismiss aria-label="Close"></button>
			<div class="daymark-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="daymark-subpost-title">
				<h2 class="daymark-sheet__title" id="daymark-subpost-title" tabindex="-1">${esc(
					item.title || 'Untitled post'
				)}</h2>
				${metaParts.length ? `<p class="daymark-note-card__meta">${metaParts.join(' &middot; ')}</p>` : ''}
				<div class="daymark-sheet__body" data-subpost-body aria-live="polite">
					<p class="daymark-loading"><span class="daymark-spinner" aria-hidden="true"></span> Loading full post&hellip;</p>
				</div>
			</div>`;
			this.el.querySelector('[data-sheet-dismiss]').addEventListener('click', () => this.hide());
		},

		async load() {
			const item = this.current;
			const key = String(item.id);
			const cached = this.cache.get(key);
			if (cached && 'done' === cached.state) {
				this.renderBody(cached.body_content);
				return;
			}
			if (cached && 'error' === cached.state) {
				this.renderError();
				return;
			}
			this.cache.set(key, { state: 'loading' });
			try {
				const full = await apiGet('subscription-posts/' + item.id);
				if (!this.isShowing(key)) {
					return;
				}
				const body = full && full.body_content ? String(full.body_content) : '';
				this.cache.set(key, { state: 'done', body_content: body });
				this.renderBody(body);
			} catch (err) {
				if (!this.isShowing(key)) {
					return;
				}
				this.cache.set(key, { state: 'error' });
				this.renderError();
			}
		},

		// Whether this sheet is still open on the same card the in-flight
		// request was made for (it may have been dismissed, or reopened on a
		// different card, while the fetch was in flight).
		isShowing(key) {
			return !!this.el && !this.el.hidden && !!this.current && String(this.current.id) === key;
		},

		renderBody(html) {
			const body = this.el.querySelector('[data-subpost-body]');
			if (!body) {
				return;
			}
			// An empty body (a 'full' fetch that genuinely had nothing to
			// show) reads the same as a failure to the person looking at
			// it — never leave them staring at a silently blank sheet.
			if (!html) {
				this.renderError();
				return;
			}
			// Already wp_kses_post()-sanitized server-side and explicitly
			// documented as safe to render as-is — not re-escaped here.
			body.innerHTML =
				`<div class="daymark-subpost-content">${html}</div>` + this.viewOriginalLink();
		},

		renderError() {
			const body = this.el.querySelector('[data-subpost-body]');
			if (!body) {
				return;
			}
			body.innerHTML =
				'<p class="daymark-error" role="alert">Couldn&#39;t load full content.</p>' +
				this.viewOriginalLink();
		},

		viewOriginalLink() {
			const permalink = this.current && this.current.permalink;
			return permalink
				? `<p class="daymark-note-card__links"><a class="daymark-note-card__link" href="${esc(
						permalink
				  )}" target="_blank" rel="noopener">View original &#8599;</a></p>`
				: '';
		},

		hide(restoreFocus = true) {
			if (!this.el || this.el.hidden) {
				return;
			}
			this.el.hidden = true;
			this.el.innerHTML = '';
			if (this.onKeydown) {
				document.removeEventListener('keydown', this.onKeydown);
				this.onKeydown = null;
			}
			if (restoreFocus && this.opener && this.opener.isConnected) {
				this.opener.focus();
			}
			this.opener = null;
			this.current = null;
		},
	};

	// --- Screen: Publish ---

	// Why a connector can't take the current Mark type, phrased by what
	// it does accept ("Needs video" for YouTube/TikTok, "Needs an image"
	// for Instagram).
	function unsupportedReason(connector) {
		const supports = Array.isArray(connector.supports) ? connector.supports : [];
		if (supports.includes('video') && !supports.includes('image')) {
			return 'Needs video';
		}
		if (supports.includes('image') && !supports.includes('note')) {
			return 'Needs an image';
		}
		return 'Unavailable';
	}

	function connectorSupportsType(connector, type) {
		// No declared capabilities = assume everything (defensive default).
		return !Array.isArray(connector.supports) || connector.supports.includes(type);
	}

	const PublishScreen = {
		render() {
			// Never carry an impossible target into a publish (e.g. after
			// going back and swapping a photo for plain text).
			state.targets = state.targets.filter((id) => {
				const connector = connectors.find((c) => c.id === id);
				return !connector || connectorSupportsType(connector, state.primaryType);
			});

			const rows = connectors.length
				? '' // populated below
				: `<li class="daymark-dest daymark-dest--locked">
					<span class="daymark-dest__row"><span class="daymark-dest__info">
						<span class="daymark-recent__meta">No social networks connected yet — your site is the only destination. Connect one via a Daymark connector plugin (Settings → Connectors).</span>
					</span></span>
				</li>`;

			const connectorRows = connectors
				.map((connector) => {
					const supported = connectorSupportsType(connector, state.primaryType);
					const checked = supported && state.targets.includes(connector.id) ? ' checked' : '';
					const chip = supported
						? `<span class="daymark-chip ${connector.connected ? 'daymark-chip--success' : 'daymark-chip--muted'}">${esc(
								connector.status_label || 'Mocked · Not connected'
							)}</span>`
						: `<span class="daymark-chip daymark-chip--muted">${esc(unsupportedReason(connector))}</span>`;
					return `
				<li class="daymark-dest${supported ? '' : ' daymark-dest--unsupported'}">
					<label class="daymark-dest__row" for="daymark-dest-${esc(connector.id)}">
						<span class="daymark-dest__info">
							<span class="daymark-dest__name">${esc(connector.label)}</span>
							${chip}
						</span>
						<span class="daymark-toggle">
							<input type="checkbox" class="daymark-toggle__input" id="daymark-dest-${esc(
								connector.id
							)}" data-connector="${esc(connector.id)}"${checked}${supported ? '' : ' disabled'} aria-label="${
								supported
									? `Publish to ${esc(connector.label)}`
									: `${esc(connector.label)} does not support ${esc(TYPE_LABELS[state.primaryType] || state.primaryType)} Marks`
							}" />
							<span class="daymark-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`;
				})
				.join('');

			// Controllable third-party helpers: publish through the plugin's
			// own flow when toggled on (default off — opt-in).
			const controllable = Array.isArray(config.controllableHelpers)
				? config.controllableHelpers
				: [];
			const helperRows = controllable
				.map(
					(helper) => `
				<li class="daymark-dest">
					<label class="daymark-dest__row" for="daymark-helper-${esc(helper.id)}">
						<span class="daymark-dest__info">
							<span class="daymark-dest__name">${esc(helper.label)}</span>
							<span class="daymark-chip daymark-chip--muted">Via plugin</span>
						</span>
						<span class="daymark-toggle">
							<input type="checkbox" class="daymark-toggle__input" id="daymark-helper-${esc(
								helper.id
							)}" data-helper="${esc(helper.id)}"${
						state.helpers.includes(helper.id) ? ' checked' : ''
					} aria-label="Also publish through ${esc(helper.label)}" />
							<span class="daymark-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`
				)
				.join('');

			return `
			<header class="daymark-topbar">
				<a class="daymark-backlink" href="#create">&larr; Back</a>
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>Where should this go?</h1>
			</header>
			<section class="daymark-screen">
				<p class="daymark-typebadge">Publishing ${
					/^[aeiou]/i.test(TYPE_LABELS[state.primaryType] || '') ? 'an' : 'a'
				} <span class="daymark-chip">${esc(
					TYPE_LABELS[state.primaryType]
				)}</span> Mark</p>
				<ul class="daymark-destlist">
					<li class="daymark-dest daymark-dest--locked">
						<span class="daymark-dest__row">
							<span class="daymark-dest__info">
								<span class="daymark-dest__name">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
									Your Site
								</span>
								<span class="daymark-chip daymark-chip--success">Required</span>
							</span>
							<span class="daymark-toggle">
								<input type="checkbox" class="daymark-toggle__input" checked disabled aria-label="Your Site (always included)" />
								<span class="daymark-toggle__track" aria-hidden="true"></span>
							</span>
						</span>
					</li>
					${connectors.length ? connectorRows : rows}
					${helperRows}
				</ul>
				${(() => {
					const helpers = config.publishHelpers || [];
					if (!Array.isArray(helpers) || !helpers.length) {
						return '';
					}
					const names = helpers.map((h) => esc(h.label)).join(', ');
					return `<p class="daymark-helpers-note">Your site’s publishing tools will also share this Mark, per their own settings: <strong>${names}</strong>.</p>`;
				})()}
				${this.renderCategories()}
			</section>
			<footer class="daymark-actionbar">
				<p class="daymark-status" data-publish-status aria-live="polite"></p>
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="publish">Publish Now</button>
				<button type="button" class="daymark-btn daymark-btn--secondary" data-action="save-draft">Save as Draft</button>
			</footer>`;
		},

		// "File under" category picker — the site-filing counterpart to
		// destinations. Only shown when the site offers a real choice beyond
		// its single default category (a lone "Uncategorized" is just the
		// fallback and needs no toggle).
		renderCategories() {
			const meaningful = siteCategories.filter((c) => c.id !== config.defaultCategory);
			if (!meaningful.length) {
				return '';
			}
			const items = siteCategories
				.map(
					(cat) => `
				<li class="daymark-dest">
					<label class="daymark-dest__row" for="daymark-cat-${esc(cat.id)}">
						<span class="daymark-dest__info">
							<span class="daymark-dest__name">${esc(cat.name)}</span>
						</span>
						<span class="daymark-toggle">
							<input type="checkbox" class="daymark-toggle__input" id="daymark-cat-${esc(
								cat.id
							)}" data-category="${esc(cat.id)}"${
						state.categories.includes(cat.id) ? ' checked' : ''
					} aria-label="File under ${esc(cat.name)}" />
							<span class="daymark-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`
				)
				.join('');
			const typeLabel = esc(TYPE_LABELS[state.primaryType] || 'these');
			return `
				<h2 class="daymark-section-heading daymark-publish-subhead">File under</h2>
				<p class="daymark-publish-subnote">Saved as the default for ${typeLabel} Marks — change it any time.</p>
				<ul class="daymark-destlist">${items}</ul>`;
		},

		bindEvents() {
			root.querySelectorAll('[data-connector]').forEach((input) => {
				input.addEventListener('change', () => {
					const id = input.getAttribute('data-connector');
					if (input.checked) {
						if (!state.targets.includes(id)) {
							state.targets.push(id);
						}
					} else {
						state.targets = state.targets.filter((t) => t !== id);
					}
					scheduleAutosave();
				});
			});

			root.querySelectorAll('[data-category]').forEach((input) => {
				input.addEventListener('change', () => {
					const id = Number(input.getAttribute('data-category'));
					if (input.checked) {
						if (!state.categories.includes(id)) {
							state.categories.push(id);
						}
					} else {
						state.categories = state.categories.filter((c) => c !== id);
					}
					scheduleAutosave();
				});
			});

			root.querySelectorAll('[data-helper]').forEach((input) => {
				input.addEventListener('change', () => {
					const id = input.getAttribute('data-helper');
					if (input.checked) {
						if (!state.helpers.includes(id)) {
							state.helpers.push(id);
						}
					} else {
						state.helpers = state.helpers.filter((h) => h !== id);
					}
					scheduleAutosave();
				});
			});

			root
				.querySelector('[data-action="publish"]')
				.addEventListener('click', () => this.publish('publish'));
			root
				.querySelector('[data-action="save-draft"]')
				.addEventListener('click', () => this.publish('draft'));
		},

		async publish(postStatus) {
			const isDraft = 'draft' === postStatus;
			const button = root.querySelector(
				isDraft ? '[data-action="save-draft"]' : '[data-action="publish"]'
			);
			const otherButton = root.querySelector(
				isDraft ? '[data-action="publish"]' : '[data-action="save-draft"]'
			);
			const status = root.querySelector('[data-publish-status]');
			// Disable both actions and show the loading state on the button
			// itself — no separate "Publishing…" message (redundant). The
			// status line is reserved for errors below.
			button.disabled = true;
			if (otherButton) {
				otherButton.disabled = true;
			}
			button.textContent = isDraft ? 'Saving…' : 'Publishing…';
			status.textContent = '';

			// A real Publish/Save as Draft supersedes any pending autosave —
			// cancel it so it can't fire mid-request against the same draft.
			// If an autosave upload for a just-picked file is already in
			// flight, wait for it first (see waitForPendingAutosave()) so
			// buildMarkPayload() below sees entry.uploadedId already set
			// instead of re-sending — and re-attaching — the same file.
			clearTimeout(autosaveState.timer);
			autosaveState.timer = null;
			await waitForPendingAutosave();

			const payload = buildMarkPayload(postStatus);
			// Editing a draft updates it in place; otherwise create.
			const path = state.editing ? 'marks/' + state.editing.id : 'marks';
			const targetId = state.editing ? state.editing.id : null;

			try {
				// Queue-first: this resolves as soon as the Mark is safely on
				// this device (a fast, local IndexedDB write), not once it's
				// actually reached the server — the real request keeps going
				// in the background via syncPendingMark(). See
				// publishInBackground()'s own comment for why.
				const pendingId = await publishInBackground(path, targetId, payload, state.offlineQueueId);
				state.lastPublish = {
					pendingId,
					wasDraft: isDraft,
					targets: state.targets.slice(),
					type: state.primaryType,
					response: null,
				};
				resetComposer();
				navigate('#success');
			} catch (err) {
				// Queuing itself failed (IndexedDB unavailable — old Safari
				// private mode, storage disabled, …). Fall back to waiting on
				// the real request directly rather than risk losing the Mark.
				try {
					const response = await apiUpload(path, payloadToFormData(payload));
					state.lastPublish = {
						response,
						wasDraft: isDraft,
						targets: state.targets.slice(),
						type: state.primaryType,
					};
					resetComposer();
					navigate('#success');
				} catch (err2) {
					button.disabled = false;
					if (otherButton) {
						otherButton.disabled = false;
					}
					button.textContent = isDraft ? 'Save as Draft' : 'Publish Now';
					status.textContent = (isDraft ? 'Save failed: ' : 'Publish failed: ') + err2.message;
				}
			}
		},
	};

	// --- Screen: Success ---

	// "Tap Publish. Immediately appears in your timeline. Uploads continue
	// in the background." This screen renders from whatever's known right
	// now: state.lastPublish.response is null the moment PublishScreen
	// navigates here (the real request is still running via
	// syncPendingMark()) unless the IndexedDB fallback above had to wait
	// for it directly. If the background request resolves while the user
	// is still looking at this exact screen, upgrade() patches the detail
	// in place with the real permalink/syndication status instead of
	// making the user wait for it before ever seeing a success screen.
	const SuccessScreen = {
		render() {
			const publish = state.lastPublish || { targets: [], type: 'note', wasDraft: false, response: null };
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title daymark-visually-hidden" tabindex="-1" data-daymark-focus>${
					publish.wasDraft ? 'Draft saved' : 'Published'
				}</h1>
			</header>
			<section class="daymark-screen daymark-success">
				<span class="daymark-success__icon">
					<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
				</span>
				<div data-success-detail aria-live="polite">${this.renderDetail(publish)}</div>
				<a class="daymark-success__link" href="#home">View Timeline &rarr;</a>
			</section>
			<footer class="daymark-actionbar">
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="create-another">Create Another</button>
				<p class="daymark-status"><a class="daymark-btn--text daymark-btn" href="#home">View Timeline &rarr;</a></p>
			</footer>`;
		},

		renderDetail(publish) {
			const response = publish.response;

			if (!response) {
				return `
				<h2 class="daymark-screen__heading">${publish.wasDraft ? 'Saved as draft' : 'Published'}</h2>
				<p class="daymark-note-card__meta">${
					publish.wasDraft
						? "Saving in the background — you'll find it under Drafts on Home once it's done."
						: "Uploading in the background — it'll appear in Recent Marks as soon as it's done."
				}</p>`;
			}

			const permalink = response.permalink;
			const rows = publish.targets
				.map((id) => {
					const status = this.externalStatus(response, id);
					return `
				<li class="daymark-syndication__row">
					<span>${esc(connectorLabel(id))}</span>
					<span class="daymark-chip daymark-chip--muted">${esc(status)}</span>
				</li>`;
				})
				.join('');

			return `
			<h2 class="daymark-screen__heading">${
				publish.wasDraft ? 'Saved as draft' : 'Published to your site'
			}${
				!publish.wasDraft && permalink
					? ` <a class="daymark-success__viewlink" href="${esc(
							permalink
					  )}" target="_blank" rel="noopener">(view)</a>`
					: ''
			}</h2>
			${
				publish.wasDraft
					? '<p class="daymark-note-card__meta">Finish it any time from Recent Marks on Home.</p>'
					: ''
			}
			${
				publish.wasDraft
					? publish.targets.length
						? '<p class="daymark-note-card__meta">Selected destinations will publish when this Mark goes live.</p>'
						: ''
					: rows
					? `<ul class="daymark-syndication" aria-label="Syndication status">${rows}</ul>`
					: '<p class="daymark-note-card__meta">No social destinations selected.</p>'
			}`;
		},

		// Called by syncPendingMark() once the background request for this
		// exact publish resolves. A no-op if the user has since navigated
		// away or started another publish (pendingId no longer matches) —
		// state.lastPublish itself is still updated either way.
		upgrade(pendingId, response) {
			if (!state.lastPublish || state.lastPublish.pendingId !== pendingId) {
				return;
			}
			state.lastPublish.response = response;
			if (window.location.hash !== '#success') {
				return;
			}
			const slot = root.querySelector('[data-success-detail]');
			if (slot) {
				slot.innerHTML = this.renderDetail(state.lastPublish);
			}
		},

		externalStatus(response, connectorId) {
			const external = response && response.external_posts;
			let entry = null;
			if (Array.isArray(external)) {
				entry = external.find(
					(e) => e && (e.connector === connectorId || e.network === connectorId || e.id === connectorId)
				);
			} else if (external && typeof external === 'object') {
				entry = external[connectorId];
			}
			if (entry && entry.status) {
				const label = String(entry.status);
				const pretty = label.charAt(0).toUpperCase() + label.slice(1);
				return label === 'published' ? pretty : pretty + ' (demo mode)';
			}
			return 'Mocked (demo mode)';
		},

		bindEvents() {
			root.querySelector('[data-action="create-another"]').addEventListener('click', () => {
				resetComposer();
				navigate('#create');
			});
		},

		init() {},
	};

	// --- Screen: Notifications ---

	// Reply text a user has started typing, keyed by comment ID — kept in
	// memory (not sent anywhere) so switching between replies, closing and
	// reopening the same one, or navigating back to Notifications never
	// silently discards an unsent reply. Cleared on send or explicit Cancel.
	const replyDrafts = {};

	const NotificationsScreen = {
		render() {
			return `
			<header class="daymark-topbar">
				<a class="daymark-backlink" href="#home">&larr; Back</a>
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>Notifications</h1>
			</header>
			<section class="daymark-screen">
				<h2 class="daymark-section-heading">Recent Activity</h2>
				<div class="daymark-recent__list" data-notification-list aria-live="polite">
					${skeletonRows(3)}
					<span class="daymark-visually-hidden">Loading notifications</span>
				</div>
			</section>`;
		},

		bindEvents() {},

		async init() {
			const list = root.querySelector('[data-notification-list]');
			try {
				const items = await apiGet('notifications');
				// The endpoint marks everything seen server-side; mirror
				// that so the Home bell dot clears without a reload.
				if (config.notifications) {
					config.notifications.hasUnread = false;
				}
				if (!list || !list.isConnected) {
					return;
				}
				if (!Array.isArray(items) || !items.length) {
					list.innerHTML =
						'<p class="daymark-empty">No new activity for your Marks.</p>';
					return;
				}
				list.innerHTML = items.map((item) => this.renderItem(item)).join('');
				this.bindShowMore(list);
				// Reply interactions are delegated on the list so appended /
				// re-rendered cards stay wired.
				list.addEventListener('click', (event) => this.onReplyClick(event));
				// Track in-progress reply text so it survives switching between
				// cards, closing/reopening the same one, or navigating back here.
				list.addEventListener('input', (event) => this.onReplyInput(event));
				// Close the open reply box (there's only ever one) on an
				// outside click or Escape, returning focus to its own
				// toggle — same convention as the item menu, launcher, and
				// search on Home.
				bindDismissible(this, [
					{
						selector: '[data-reply-toggle], [data-reply-form]',
						close: () => this.closeAllReplies(list),
						isOpen: () => !!list.querySelector('[data-reply-toggle][aria-expanded="true"]'),
						focus: () => list.querySelector('[data-reply-toggle][aria-expanded="true"]'),
					},
				]);
			} catch (err) {
				if (list && list.isConnected) {
					list.innerHTML =
						'<p class="daymark-error" role="alert">Could not load notifications. ' +
						esc(err.message) +
						'</p>';
				}
			}
		},

		renderItem(item) {
			const text = toPlainText(item.comment_content);
			const long = text.length > 140;
			const author = item.comment_author || item.author || '';
			const commentId = Number(item.comment_ID || item.comment_id || 0);
			const metaParts = [];
			if (author) {
				metaParts.push(esc(author));
			}
			if (item.comment_date) {
				metaParts.push(esc(relativeTime(item.comment_date)));
			}
			if (item.post_title) {
				metaParts.push('on &ldquo;' + esc(item.post_title) + '&rdquo;');
			}
			// A reply targets a specific comment; only offer it when we have a
			// comment id to reply to.
			const replyId = 'daymark-reply-' + commentId;
			return `
			<article class="daymark-note-card"${commentId ? ` data-comment-id="${esc(String(commentId))}"` : ''}>
				<span class="daymark-chip">${esc(item.source_label || 'Comment')}</span>
				<p class="daymark-note-card__text daymark-clamp">${esc(text)}</p>
				${
					long
						? '<button type="button" class="daymark-note-card__showmore" data-showmore aria-expanded="false">Show more</button>'
						: ''
				}
				${metaParts.length ? `<p class="daymark-note-card__meta">${metaParts.join(' &middot; ')}</p>` : ''}
				<div class="daymark-note-card__links">
					${
						item.post_url
							? `<a class="daymark-note-card__link" href="${esc(
									item.post_url
							  )}">&rarr; View Mark</a>`
							: ''
					}
					${
						item.source_url
							? `<a class="daymark-note-card__link" href="${esc(
									item.source_url
							  )}" target="_blank" rel="noopener">&nearr; View on network</a>`
							: ''
					}
					${
						commentId
							? `<button type="button" class="daymark-note-card__reply" data-reply-toggle aria-expanded="false" aria-controls="${replyId}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg> Reply</button>`
							: ''
					}
				</div>
				${
					commentId
						? `<div class="daymark-reply" id="${replyId}" data-reply-form hidden>
						<label class="daymark-visually-hidden" for="${replyId}-input">Your reply</label>
						<textarea id="${replyId}-input" class="daymark-textarea daymark-reply__input" data-reply-input rows="2" placeholder="Write a reply&hellip;">${esc(replyDrafts[commentId] || '')}</textarea>
						<div class="daymark-reply__actions">
							<button type="button" class="daymark-btn daymark-btn--primary" data-reply-send>Send reply</button>
							<button type="button" class="daymark-btn daymark-btn--text" data-reply-cancel>Cancel</button>
						</div>
						<p class="daymark-reply__status" data-reply-status aria-live="polite"></p>
					</div>
					<p class="daymark-note-card__replied" data-replied hidden>Reply sent.</p>`
						: ''
				}
			</article>`;
		},

		bindShowMore(list) {
			list.querySelectorAll('[data-showmore]').forEach((button) => {
				button.addEventListener('click', () => {
					const text = button.parentElement.querySelector('.daymark-note-card__text');
					const expanded = text.classList.toggle('is-expanded');
					button.textContent = expanded ? 'Show less' : 'Show more';
					button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				});
			});
		},

		// Collapse every open reply box (one open at a time).
		closeAllReplies(list) {
			list.querySelectorAll('[data-reply-form]').forEach((form) => {
				form.hidden = true;
			});
			list.querySelectorAll('[data-reply-toggle]').forEach((toggle) => {
				toggle.setAttribute('aria-expanded', 'false');
			});
		},

		onReplyClick(event) {
			const list = event.currentTarget;
			const target = event.target;

			const toggle = target.closest('[data-reply-toggle]');
			if (toggle) {
				const card = toggle.closest('.daymark-note-card');
				const form = card.querySelector('[data-reply-form]');
				const wasOpen = form && !form.hidden;
				this.closeAllReplies(list);
				if (form && !wasOpen) {
					form.hidden = false;
					toggle.setAttribute('aria-expanded', 'true');
					const input = form.querySelector('[data-reply-input]');
					if (input) {
						input.focus();
					}
				}
				return;
			}

			const cancel = target.closest('[data-reply-cancel]');
			if (cancel) {
				const card = cancel.closest('.daymark-note-card');
				const commentId = card && card.getAttribute('data-comment-id');
				if (commentId) {
					delete replyDrafts[commentId]; // Explicit discard.
				}
				this.closeAllReplies(list);
				return;
			}

			const send = target.closest('[data-reply-send]');
			if (send) {
				this.submitReply(send);
			}
		},

		onReplyInput(event) {
			const field = event.target.closest('[data-reply-input]');
			if (!field) {
				return;
			}
			const card = field.closest('.daymark-note-card');
			const commentId = card && card.getAttribute('data-comment-id');
			if (!commentId) {
				return;
			}
			if (field.value) {
				replyDrafts[commentId] = field.value;
			} else {
				delete replyDrafts[commentId];
			}
		},

		async submitReply(sendBtn) {
			const card = sendBtn.closest('.daymark-note-card');
			const form = card.querySelector('[data-reply-form]');
			const input = form.querySelector('[data-reply-input]');
			const status = form.querySelector('[data-reply-status]');
			const cancelBtn = form.querySelector('[data-reply-cancel]');
			const commentId = card.getAttribute('data-comment-id');
			const content = (input.value || '').trim();
			if (!content) {
				status.textContent = 'Write a reply first.';
				input.focus();
				return;
			}
			sendBtn.disabled = true;
			if (cancelBtn) {
				cancelBtn.disabled = true;
			}
			sendBtn.textContent = 'Sending…';
			status.textContent = '';
			try {
				await apiPost('notifications/' + commentId + '/reply', { content });
				delete replyDrafts[commentId];
				input.value = '';
				form.hidden = true;
				const toggle = card.querySelector('[data-reply-toggle]');
				if (toggle) {
					toggle.setAttribute('aria-expanded', 'false');
				}
				const replied = card.querySelector('[data-replied]');
				if (replied) {
					replied.hidden = false;
				}
			} catch (err) {
				sendBtn.disabled = false;
				if (cancelBtn) {
					cancelBtn.disabled = false;
				}
				sendBtn.textContent = 'Send reply';
				status.textContent = 'Reply failed. ' + err.message;
			}
		},
	};

	// --- Init ---

	SCREENS = {
		'#home': HomeScreen,
		'#create': CreateScreen,
		'#publish': PublishScreen,
		'#success': SuccessScreen,
		'#notifications': NotificationsScreen,
		'#explore': ExploreScreen,
		'#search': SearchScreen,
		'#me': MeScreen,
	};

	window.addEventListener('hashchange', () => {
		showScreen(window.location.hash);
	});

	// A broken `data-img-fallback`-carrying <img> (see imgWithFallback()
	// above) degrades to a glyph span in its place instead of the browser's
	// broken-image icon. One delegated listener for the whole app rather
	// than a per-screen binding, since the failure this guards against
	// (an unverified favicon guess 404ing) can happen on any screen that
	// renders a subscription's site icon or post thumbnail. The `error`
	// event doesn't bubble, so this has to listen on the capturing phase.
	window.addEventListener(
		'error',
		(event) => {
			const img = event.target;
			if (!(img instanceof HTMLImageElement) || !img.dataset.imgFallback) {
				return;
			}
			const span = document.createElement('span');
			span.className = img.dataset.imgFallbackClass || '';
			span.setAttribute('aria-hidden', 'true');
			span.textContent = img.dataset.imgFallback;
			img.replaceWith(span);
		},
		true
	);

	// Explore/Search/Me are real server routes (Daymark_Routes), same as
	// notifications already was — a direct load or refresh at
	// /daymark/{screen} arrives here via window.daymarkApp.screen so the
	// SPA boots straight into the right tab instead of always landing on
	// Timeline first.
	const SERVER_ROUTED_SCREENS = ['notifications', 'explore', 'search', 'me'];
	const initialHash =
		window.location.hash ||
		(SERVER_ROUTED_SCREENS.includes(config.screen) ? '#' + config.screen : '#home');
	// A share-sheet draft (Daymark_Share_Target) redirects here with
	// pendingDraftId set — open straight into that draft's composer instead
	// of rendering the normal initial screen first and only then swapping
	// it out once the fetch resolves (which would flash an empty composer
	// in between). A failed fetch (tampered id, network hiccup) falls back
	// to the ordinary boot instead of leaving the shell blank.
	if (config.pendingDraftId) {
		openDraft(config.pendingDraftId).catch(() => showScreen(initialHash));
	} else {
		showScreen(initialHash);
	}

	// --- Offline queue: sync triggers ---
	//
	// Catches connectivity returning while the app is already open (the
	// composer's own submitOrQueue()/runAutosave() calls handle the moment
	// a save is attempted while offline; this is the other half — actually
	// sending what's queued once there's a network again). Also flushed
	// once at boot, in case items are still pending from a previous
	// offline session and connectivity is already back by the time this
	// load happens.
	window.addEventListener('online', () => {
		flushOfflineQueue().catch(() => {});
	});
	// A record still marked 'uploading' at boot means the page that started
	// it was closed or reloaded before the request finished — nothing is
	// actually in flight anymore, so it's downgraded to plain 'queued'
	// before the first flush, rather than showing a stale "Uploading" chip
	// for a request that isn't running.
	getAllPendingMarks()
		.then((pending) =>
			Promise.all(
				pending
					.filter((record) => record.status === 'uploading')
					.map((record) => updatePendingMark(record.id, record.targetId, record.payload, 'queued'))
			)
		)
		.catch(() => {})
		.then(() => flushOfflineQueue().catch(() => {}));

	// --- Service worker (PWA, Phase 8) ---
	//
	// The worker lives in the plugin assets directory, so its maximum
	// scope is /wp-content/plugins/daymark/assets/ — it cannot (and is not
	// meant to) control the /daymark page itself. We register with that
	// explicit narrow scope on purpose: install-time precaching still
	// stores app.css and app.js in Cache Storage, and the narrow scope
	// guarantees the worker can never intercept REST calls, nonces, or
	// the app-shell HTML. No Service-Worker-Allowed header hacks.
	// Feature-detected and failure-tolerant: if registration fails
	// (HTTP-only local sites, older browsers), the app works unchanged.
	if ('serviceWorker' in navigator && config.assetsUrl) {
		navigator.serviceWorker
			.register(config.assetsUrl + 'daymark-sw.js', { scope: config.assetsUrl })
			.catch(() => {
				/* Never let SW registration break the app. */
			});
	}
})();
