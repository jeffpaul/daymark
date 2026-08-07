/**
 * Daymark app shell — vanilla ES2020, no framework, no build step.
 *
 * Screen routing is hash-based within /daymark. The server-rendered
 * screen (home | notifications) arrives via window.daymarkApp.screen.
 *
 * Screens: #home, #create, #publish, #success, #notifications.
 * The AI Assist sheet is an overlay, not a routed screen.
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

	// Section-page URL for a view, or '' when the site has no Daymark page
	// for it (slug collision at activation) — callers hide the link.
	function pageLink(view) {
		return (config.pages && config.pages[view]) || '';
	}

	const PAGE_LABELS = {
		timeline: 'Timeline',
		images: 'Images',
		videos: 'Videos',
		audio: 'Audio',
		notes: 'Notes',
	};

	// Home "Recent Marks" page size — the infinite-scroll unit. A page
	// shorter than this means there are no more Marks to load.
	const RECENT_PER_PAGE = 5;

	// Header search: the type-filter chips, mapped to _daymark_primary_type
	// values ('' = every type). Wired to GET /marks?s=&type=.
	const SEARCH_FILTERS = [
		{ type: '', label: 'All' },
		{ type: 'image', label: 'Images' },
		{ type: 'video', label: 'Videos' },
		{ type: 'audio', label: 'Audio' },
		{ type: 'note', label: 'Notes' },
	];

	// Feather-style icon glyphs (inner SVG markup) for the site-views nav,
	// matching the app's other inline icons. Text stays as the accessible
	// name and hover title.
	const PAGE_ICONS = {
		timeline:
			'<polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline>',
		images:
			'<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
		videos:
			'<polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>',
		audio:
			'<path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle>',
		notes:
			'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
	};

	function pageNavIcon(glyph) {
		return `<svg class="daymark-bottomnav__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${glyph}</svg>`;
	}

	// The 4 Mark types the Home launcher offers — Gallery isn't its own
	// bubble since it's just "pick more than one image" in the existing
	// file picker (detectType() already upgrades image → gallery for you).
	const LAUNCHER_TYPES = ['image', 'video', 'audio', 'note'];

	// Reuses the same glyphs as the site-views nav so "Images" and "New
	// Image Mark" share one visual vocabulary.
	const TYPE_ICONS = {
		image: PAGE_ICONS.images,
		video: PAGE_ICONS.videos,
		audio: PAGE_ICONS.audio,
		note: PAGE_ICONS.notes,
	};

	const PLUS_GLYPH = '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>';

	// Pre-filters the composer's native file picker to match the launcher
	// bubble that was tapped — 'note' has no entry since it skips the
	// picker entirely (see CreateScreen.render()).
	const ACCEPT_BY_TYPE = {
		image: 'image/*',
		video: 'video/*',
		audio: 'audio/*',
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

	function resetComposer() {
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
		state.tags = [];
		state.primaryType = 'note';
		state.pendingType = null;
		state.targets = [];
		state.categories = [];
		state.aiAssistUsed = false;
		state.editing = null;
		state.helpers = [];
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

	// Load a draft into the composer for continued editing.
	async function openDraft(id) {
		const mark = await apiGet('marks/' + id);
		resetComposer();
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
		try {
			const body = await res.json();
			if (body && body.message) {
				message = body.message;
			}
		} catch (err) {
			// Keep the generic message.
		}
		return new Error(message);
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

	// --- Screen router ---

	let SCREENS = {};

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

	// --- Screen: Home ---

	const HomeScreen = {
		render() {
			const hasUnread = config.notifications && config.notifications.hasUnread;
			const filterChips = SEARCH_FILTERS.map(
				(filter, index) =>
					`<button type="button" class="daymark-filterchip${
						index === 0 ? ' is-active' : ''
					}" data-filter="${esc(filter.type)}" aria-pressed="${
						index === 0 ? 'true' : 'false'
					}">${esc(filter.label)}</button>`
			).join('');
			const timelineUrl = pageLink('timeline');
			const wordmark = timelineUrl
				? `<a class="daymark-homelink" href="${esc(timelineUrl)}"><svg class="daymark-homelink__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${
						PAGE_ICONS.timeline
				  }</svg><span>Daymark</span></a>`
				: 'Daymark';
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${wordmark}</h1>
				<button type="button" class="daymark-searchbtn" data-search-toggle aria-label="Search Marks" aria-expanded="false" aria-controls="daymark-searchbar">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
				</button>
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
			<div class="daymark-searchbar" id="daymark-searchbar" data-searchbar hidden>
				<label class="daymark-visually-hidden" for="daymark-search-input">Search Marks</label>
				<input type="search" id="daymark-search-input" class="daymark-input" data-search-input placeholder="Search your Marks" autocomplete="off" />
				<div class="daymark-filterchips" role="group" aria-label="Filter by type" data-filter-chips>${filterChips}</div>
			</div>
			<section class="daymark-screen">
				<section class="daymark-recent" data-drafts-section hidden aria-labelledby="daymark-drafts-heading">
					<h2 id="daymark-drafts-heading" class="daymark-section-heading">Drafts</h2>
					<div class="daymark-recent__list" data-drafts-list></div>
				</section>
				<section class="daymark-recent" aria-labelledby="daymark-recent-heading">
					<h2 id="daymark-recent-heading" class="daymark-section-heading">Recent Marks</h2>
					<div class="daymark-recent__list" data-recent-list aria-live="polite">
						${skeletonRows(3)}
						<span class="daymark-visually-hidden">Loading recent Marks</span>
					</div>
					<div class="daymark-recent__sentinel" data-recent-sentinel aria-hidden="true"></div>
					<p class="daymark-recent__more" data-recent-more hidden></p>
				</section>
			</section>
			<footer class="daymark-homefooter">
				${(() => {
					const navLink = (view) =>
						`<a class="daymark-bottomnav__link" href="${esc(pageLink(view))}" title="${esc(
							PAGE_LABELS[view]
						)}">${pageNavIcon(PAGE_ICONS[view])}<span class="daymark-visually-hidden">${esc(
							PAGE_LABELS[view]
						)}</span></a>`;
					// Timeline moved to the header home-link; the remaining 4
					// site views flank the launcher, 2 a side.
					const views = Object.keys(PAGE_LABELS).filter(
						(view) => 'timeline' !== view && pageLink(view)
					);
					const before = views.slice(0, 2).map(navLink).join('');
					const after = views.slice(2).map(navLink).join('');
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
					return `<nav class="daymark-bottomnav" aria-label="Site views">${before}${launcher}${after}</nav>`;
				})()}
			</footer>`;
		},

		bindEvents() {
			this.bindLauncher();

			// --- Search (collapsible header bar + type-filter chips) ---
			const searchToggle = root.querySelector('[data-search-toggle]');
			const searchInput = root.querySelector('[data-search-input]');
			if (searchToggle) {
				searchToggle.addEventListener('click', () => this.toggleSearch());
			}
			if (searchInput) {
				const runDebounced = debounce(() => this.applySearch(), 250);
				searchInput.addEventListener('input', () => {
					this.searchQuery = searchInput.value.trim();
					runDebounced();
				});
				// The native clear (✕) / an emptied field fires 'search' with an
				// empty value — collapse back to the icon, per the spec.
				searchInput.addEventListener('search', () => {
					if ('' === searchInput.value) {
						this.closeSearch();
					}
				});
				searchInput.addEventListener('keydown', (event) => {
					if ('Escape' === event.key) {
						this.closeSearch();
					}
				});
			}
			root.querySelectorAll('[data-filter]').forEach((chip) => {
				chip.addEventListener('click', () => this.setFilter(chip.getAttribute('data-filter')));
			});

			// --- Per-item ⋯ menu (edit / delete) via list delegation ---
			root.querySelectorAll('[data-recent-list], [data-drafts-list]').forEach((list) => {
				list.addEventListener('click', (event) => this.onListClick(event));
			});
			// Close any open item menu or the launcher on an outside click or
			// Escape. Removing the previous pair first keeps these from
			// stacking across the repeated Home renders within a session.
			if (this._onDocClick) {
				document.removeEventListener('click', this._onDocClick, true);
				document.removeEventListener('keydown', this._onDocKey, true);
			}
			this._onDocClick = (event) => {
				if (!event.target.closest || !event.target.closest('[data-actions]')) {
					this.closeItemMenus();
				}
				if (!event.target.closest || !event.target.closest('[data-launcher]')) {
					this.closeLauncher();
				}
			};
			this._onDocKey = (event) => {
				if ('Escape' === event.key) {
					this.closeItemMenus();
					// Escaping a disclosure returns focus to its trigger —
					// only when the launcher was actually open, so Escape
					// elsewhere on the page doesn't steal focus.
					if (this._launcherOpen) {
						this.closeLauncher();
						const btn = root.querySelector('[data-action="new-mark"]');
						if (btn) {
							btn.focus();
						}
					}
				}
			};
			document.addEventListener('click', this._onDocClick, true);
			document.addEventListener('keydown', this._onDocKey, true);

			this.bindFooterAutoHide();
		},

		// The Home launcher: tapping "+ New Mark" fans out Image/Video/Audio/
		// Note bubbles (icon-only arc); tapping a bubble seeds the composer's
		// pendingType and jumps to #create. Reuses the same open/close
		// conventions as the per-item ⋯ menu (aria-expanded, focus-first-item
		// on open) — the bubbles can't use `hidden` like that menu does,
		// since `hidden` elements can't be CSS-animated, so tabindex/
		// aria-hidden are toggled by hand instead to keep them out of the
		// tab order and AT tree while closed.
		bindLauncher() {
			const launcher = root.querySelector('[data-launcher]');
			if (!launcher) {
				return;
			}
			const btn = launcher.querySelector('[data-action="new-mark"]');
			const bubbles = launcher.querySelectorAll('[data-launcher-type]');
			const scrim = launcher.querySelector('.daymark-launcher__scrim');

			this._launcherOpen = false;

			this.openLauncher = () => {
				this._launcherOpen = true;
				launcher.classList.add('is-open');
				btn.setAttribute('aria-expanded', 'true');
				bubbles.forEach((bubble) => {
					bubble.removeAttribute('tabindex');
					bubble.removeAttribute('aria-hidden');
				});
				const first = bubbles[0];
				if (first) {
					first.focus();
				}
			};

			this.closeLauncher = () => {
				if (!this._launcherOpen) {
					return;
				}
				this._launcherOpen = false;
				launcher.classList.remove('is-open');
				btn.setAttribute('aria-expanded', 'false');
				bubbles.forEach((bubble) => {
					bubble.setAttribute('tabindex', '-1');
					bubble.setAttribute('aria-hidden', 'true');
				});
			};

			btn.addEventListener('click', () => {
				if (this._launcherOpen) {
					this.closeLauncher();
				} else {
					this.openLauncher();
				}
			});

			// The scrim sits inside [data-launcher] (for stacking), so the
			// shared outside-click handler's closest() check treats a tap on
			// it as "inside" and leaves it alone — it needs its own listener.
			// `pointer-events: none` while closed keeps this scoped to only
			// when the scrim is actually showing.
			if (scrim) {
				scrim.addEventListener('click', () => this.closeLauncher());
			}

			bubbles.forEach((bubble) => {
				bubble.addEventListener('click', () => {
					const type = bubble.getAttribute('data-launcher-type');
					this.closeLauncher();
					resetComposer();
					state.pendingType = type;
					navigate('#create');
				});
			});
		},

		// Slide the footer (CTA + site nav) out of view while scrolling down
		// through the list, back in on scroll-up — reclaiming its height for
		// content without losing the controls. Removing the previous listener
		// first keeps these from stacking across repeated Home renders, same
		// as the document click/keydown pair above.
		bindFooterAutoHide() {
			const footer = root.querySelector('.daymark-homefooter');
			if (!footer) {
				return;
			}

			if (this._onScroll) {
				window.removeEventListener('scroll', this._onScroll);
			}
			if (this._onFooterFocusIn) {
				footer.removeEventListener('focusin', this._onFooterFocusIn);
			}

			let lastY = window.scrollY;
			let ticking = false;

			this._onScroll = () => {
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
					// never end up floating over a footer that just hid.
					if (this.closeLauncher) {
						this.closeLauncher();
					}

					footer.classList.toggle('is-footer-hidden', delta > 0);
					lastY = y;
					ticking = false;
				});
			};

			// A keyboard user tabbing to the CTA or a nav link must always
			// find it visible, regardless of scroll position — the footer is
			// never aria-hidden, so this just keeps sight in sync with focus.
			this._onFooterFocusIn = () => {
				footer.classList.remove('is-footer-hidden');
			};

			window.addEventListener('scroll', this._onScroll, { passive: true });
			footer.addEventListener('focusin', this._onFooterFocusIn);
		},

		bindDraftTaps(container) {
			container.querySelectorAll('[data-edit-draft]').forEach((row) => {
				row.addEventListener('click', (event) => {
					event.preventDefault();
					row.setAttribute('aria-busy', 'true');
					openDraft(row.getAttribute('data-edit-draft')).catch(() => {
						row.removeAttribute('aria-busy');
					});
				});
			});
		},

		async init() {
			this._searchSeq = 0;
			this.searchOpen = false;
			this.searchQuery = '';
			this.searchType = '';
			this._hasDrafts = false;
			this.recentPage = 1;
			this.recentDone = false;
			this.recentLoading = false;

			const draftsSection = root.querySelector('[data-drafts-section]');
			const draftsList = root.querySelector('[data-drafts-list]');

			// Drafts are fetched separately so they stay reachable no matter
			// how many Marks have published since.
			try {
				const drafts = await apiGet('marks?status=draft&per_page=10');
				const draftItems = Array.isArray(drafts) ? drafts : [];
				if (draftItems.length && draftsSection && draftsList && draftsList.isConnected) {
					draftsList.innerHTML = draftItems.map((item) => this.renderItem(item)).join('');
					draftsSection.hidden = false;
					this._hasDrafts = true;
					this.bindDraftTaps(draftsList);
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
				heading.textContent = 'Recent Marks';
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
				const items = await apiGet(
					'marks?status=publish&per_page=' + RECENT_PER_PAGE + '&page=1'
				);
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				const arr = Array.isArray(items) ? items : [];
				if (!arr.length) {
					list.innerHTML = this._hasDrafts
						? '<p class="daymark-empty">Nothing published yet.</p>'
						: '<p class="daymark-empty">Nothing here yet. Create your first Mark.</p>';
					this.recentDone = true;
					if (sentinel) {
						sentinel.hidden = true;
					}
					return;
				}
				list.innerHTML = arr.map((item) => this.renderItem(item)).join('');

				if (arr.length < RECENT_PER_PAGE) {
					// A short first page means there is nothing more to load.
					this.recentDone = true;
					if (sentinel) {
						sentinel.hidden = true;
					}
					return;
				}

				// A full page: more may exist. Prefer infinite scroll; only fall
				// back to a timeline link when IntersectionObserver is
				// unavailable. Otherwise the link is redundant — the appended
				// pages already show everything, and the bottom-nav Timeline
				// icon still reaches the full timeline.
				if ('IntersectionObserver' in window) {
					this.setupObserver();
				} else {
					const timeline = pageLink('timeline');
					if (more && timeline) {
						more.innerHTML = `<a class="daymark-recent__morelink" href="${esc(
							timeline
						)}">View more on your timeline &rarr;</a>`;
						more.hidden = false;
					}
				}
			} catch (err) {
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				list.innerHTML =
					'<p class="daymark-error" role="alert">Could not load recent Marks. ' +
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
				const items = await apiGet(
					'marks?status=publish&per_page=' + RECENT_PER_PAGE + '&page=' + nextPage
				);
				const arr = Array.isArray(items) ? items : [];
				if (arr.length && list.isConnected) {
					this.recentPage = nextPage;
					list.insertAdjacentHTML('beforeend', arr.map((item) => this.renderItem(item)).join(''));
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
				return; // The timeline "View more" link is the fallback path.
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

		// --- Search behaviour ---

		toggleSearch() {
			if (this.searchOpen) {
				this.closeSearch();
			} else {
				this.openSearch();
			}
		},

		openSearch() {
			this.searchOpen = true;
			const bar = root.querySelector('[data-searchbar]');
			const toggle = root.querySelector('[data-search-toggle]');
			const input = root.querySelector('[data-search-input]');
			if (bar) {
				bar.hidden = false;
			}
			if (toggle) {
				toggle.setAttribute('aria-expanded', 'true');
			}
			if (input) {
				input.focus();
			}
		},

		closeSearch() {
			this.searchOpen = false;
			this.searchQuery = '';
			this.searchType = '';
			const bar = root.querySelector('[data-searchbar]');
			const toggle = root.querySelector('[data-search-toggle]');
			const input = root.querySelector('[data-search-input]');
			if (input) {
				input.value = '';
			}
			this.syncFilterChips();
			if (bar) {
				bar.hidden = true;
			}
			if (toggle) {
				toggle.setAttribute('aria-expanded', 'false');
				toggle.focus();
			}
			// Restore the ordinary paginated recent list.
			this.loadRecent();
		},

		setFilter(type) {
			this.searchType = type || '';
			this.syncFilterChips();
			this.applySearch();
		},

		syncFilterChips() {
			root.querySelectorAll('[data-filter]').forEach((chip) => {
				const active = (chip.getAttribute('data-filter') || '') === this.searchType;
				chip.classList.toggle('is-active', active);
				chip.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		},

		applySearch() {
			if ('' === this.searchQuery && '' === this.searchType) {
				this.loadRecent();
			} else {
				this.runSearch();
			}
		},

		async runSearch() {
			const list = root.querySelector('[data-recent-list]');
			const more = root.querySelector('[data-recent-more]');
			const sentinel = root.querySelector('[data-recent-sentinel]');
			if (!list) {
				return;
			}
			const heading = root.querySelector('#daymark-recent-heading');
			if (heading) {
				heading.textContent = 'Results';
			}
			// Search overrides the paginated list: stop infinite scroll and
			// hide the timeline shortcut while a query/filter is active.
			this.teardownObserver();
			if (more) {
				more.hidden = true;
			}
			if (sentinel) {
				sentinel.hidden = true;
			}
			const seq = ++this._searchSeq;
			list.innerHTML =
				skeletonRows(2) + '<span class="daymark-visually-hidden">Searching Marks</span>';
			const params = new URLSearchParams();
			params.set('status', 'publish');
			params.set('per_page', '20');
			if (this.searchQuery) {
				params.set('s', this.searchQuery);
			}
			if (this.searchType) {
				params.set('type', this.searchType);
			}
			try {
				const items = await apiGet('marks?' + params.toString());
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				const arr = Array.isArray(items) ? items : [];
				if (!arr.length) {
					list.innerHTML = '<p class="daymark-empty">No Marks match your search.</p>';
					return;
				}
				list.innerHTML = arr.map((item) => this.renderItem(item)).join('');
			} catch (err) {
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				list.innerHTML =
					'<p class="daymark-error" role="alert">Search failed. ' + esc(err.message) + '</p>';
			}
		},

		// --- Per-item ⋯ menu (edit / delete) ---

		closeItemMenus() {
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
		},

		onListClick(event) {
			const target = event.target;

			const toggle = target.closest('[data-menu-toggle]');
			if (toggle) {
				event.preventDefault();
				const menu = toggle.parentElement.querySelector('[data-menu]');
				const wasOpen = menu && !menu.hidden;
				this.closeItemMenus();
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

			const edit = target.closest('[data-menu-edit]');
			if (edit) {
				event.preventDefault();
				const wrap = edit.closest('[data-item]');
				this.closeItemMenus();
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
				this.deleteItem(confirmDel);
			}
		},

		async deleteItem(confirmBtn) {
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
				this.reflectEmptied(parentList);
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
		},

		// After a delete, keep the emptied region honest: hide an empty Drafts
		// section, or show the empty state in the recent list.
		reflectEmptied(list) {
			if (!list || list.querySelector('[data-item]')) {
				return;
			}
			if (list.hasAttribute('data-drafts-list')) {
				const section = root.querySelector('[data-drafts-section]');
				if (section) {
					section.hidden = true;
				}
				this._hasDrafts = false;
			} else if (list.hasAttribute('data-recent-list')) {
				this.teardownObserver();
				const sentinel = root.querySelector('[data-recent-sentinel]');
				if (sentinel) {
					sentinel.hidden = true;
				}
				const more = root.querySelector('[data-recent-more]');
				if (more) {
					more.hidden = true;
				}
				list.innerHTML = this._hasDrafts
					? '<p class="daymark-empty">Nothing published yet.</p>'
					: '<p class="daymark-empty">Nothing here yet. Create your first Mark.</p>';
			}
		},

		renderItem(item) {
			const title = item.title || 'Untitled Mark';
			const thumb = item.thumbnail
				? `<img class="daymark-recent__thumb" src="${esc(item.thumbnail)}" alt="" />`
				: `<span class="daymark-recent__thumb daymark-recent__thumb--glyph" aria-hidden="true">${esc(
						(TYPE_LABELS[item.type] || 'M').charAt(0)
				  )}</span>`;
			// Drafts look identical to published Marks otherwise — and
			// their permalinks are invisible to visitors — so say so, and
			// tapping one reopens the composer instead of the permalink.
			const isDraft = item.status && 'publish' !== item.status;
			const draftChip = isDraft
				? '<span class="daymark-chip daymark-chip--draft">Draft</span> '
				: '';
			const href = isDraft ? '#create' : item.permalink || '#home';
			const editAttr = isDraft ? ` data-edit-draft="${esc(String(item.id))}"` : '';
			const id = esc(String(item.id));
			return `
			<div class="daymark-recent__item-wrap" data-item="${id}">
				<a class="daymark-recent__item" href="${esc(href)}"${editAttr}>
					${thumb}
					<span class="daymark-recent__body">
						<span class="daymark-recent__title">${esc(title)}</span>
						<span class="daymark-recent__meta">${draftChip}${esc(
							TYPE_LABELS[item.type] || item.type || ''
						)}${item.date ? ' · ' + esc(relativeTime(item.date)) : ''}</span>
					</span>
				</a>
				<div class="daymark-recent__actions" data-actions>
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
				</div>
			</div>`;
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
						: `<div class="daymark-picker">
					<input type="file" id="daymark-file-input" class="daymark-picker__input" accept="${esc(
						ACCEPT_BY_TYPE[state.pendingType] || 'image/*,video/*,audio/*'
					)}" multiple />
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
				});
			}

			caption.addEventListener('input', () => {
				state.caption = caption.value;
			});

			// Alt edits on media already attached to a draft, keyed by ID.
			root.querySelectorAll('[data-existing-alt]').forEach((field) => {
				field.addEventListener('input', () => {
					const id = field.getAttribute('data-existing-alt');
					const media = (state.editing && state.editing.media) || [];
					const item = media.find((m) => String(m.id) === String(id));
					if (item) {
						item.alt = field.value;
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
					}
				});
			});
		},

		// Alt-text field for one image entry, with a hint reflecting AI state.
		altFieldMarkup(entry) {
			const hint =
				entry.altStatus === 'loading'
					? '<span class="daymark-alt__hint">Generating alt text…</span>'
					: entry.altStatus === 'done'
					? '<span class="daymark-alt__hint">AI-suggested — edit as needed</span>'
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
				</div>`;
		},

		// Ask the provider to describe one image, then drop the suggestion
		// into its alt field unless the author has already typed one. Patches
		// just this entry's field in place so a late result never disrupts
		// text the author is typing into another image's field.
		async generateAltFor(entry) {
			try {
				const formData = new FormData();
				formData.append('image', entry.file, entry.file.name);
				formData.append('text', state.caption || '');
				const result = await apiUpload('ai/alt-text', formData);
				if (!entry.altEdited && result && result.alt_text) {
					entry.alt = String(result.alt_text);
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
				});
			}

			const info = slot.querySelector('[data-title-info]');
			const hint = slot.querySelector('[data-title-hint]');
			if (info && hint) {
				info.addEventListener('click', () => {
					const open = info.getAttribute('aria-expanded') === 'true';
					info.setAttribute('aria-expanded', open ? 'false' : 'true');
					hint.hidden = open;
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
					<input type="text" id="daymark-ai-newtag" class="daymark-input" placeholder="Add a tag" />
					<button type="button" class="daymark-btn daymark-btn--secondary" data-tag-add>+ Add</button>
				</div>
			</fieldset>
			<div class="daymark-sheet__actions">
				<button type="button" class="daymark-btn daymark-btn--primary" data-sheet-accept>Accept All</button>
				<button type="button" class="daymark-btn daymark-btn--text" data-sheet-skip>Skip</button>
			</div>`;
		},

		bindForm() {
			this.renderTags();

			this.el.querySelector('[data-tag-add]').addEventListener('click', () => {
				const input = this.el.querySelector('#daymark-ai-newtag');
				const value = input.value.trim();
				if (value && !this.tags.includes(value)) {
					this.tags.push(value);
					this.renderTags();
				}
				input.value = '';
				input.focus();
			});

			this.el.querySelector('[data-sheet-accept]').addEventListener('click', () => {
				state.caption = this.el.querySelector('#daymark-ai-caption').value;
				state.tags = this.tags.slice();
				state.aiAssistUsed = true;
				const captionField = document.getElementById('daymark-caption');
				if (captionField) {
					captionField.value = state.caption;
				}
				this.hide();
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

			const formData = new FormData();
			formData.append('caption', state.caption);
			formData.append('primary_type', state.primaryType);
			formData.append('status', postStatus);
			// Include the optional title only when the field is shown for this
			// type; when hidden, omit it so the publisher keeps deriving the
			// title from the caption/timestamp.
			if (titleFieldShown()) {
				formData.append('title', state.title || '');
			}
			formData.append('ai_assist_used', state.aiAssistUsed ? '1' : '0');
			state.targets.forEach((target) => formData.append('targets[]', target));
			state.categories.forEach((id) => formData.append('categories[]', id));
			// When controllable helpers exist, send the selection (as JSON so
			// an empty choice is authoritative "none"); omit entirely when
			// there are none, leaving those plugins' own defaults untouched.
			if (Array.isArray(config.controllableHelpers) && config.controllableHelpers.length) {
				formData.append('publish_helpers', JSON.stringify(state.helpers));
			}
			// Append each file with its per-image alt in the same order, so
			// the server can map alt[] positionally onto the new attachments.
			state.files.forEach((entry) => {
				formData.append('files[]', entry.file, entry.file.name);
				formData.append('alt[]', entry.kind === 'image' ? entry.alt || '' : '');
			});
			// Editing: alt edits on already-attached images, keyed by ID.
			if (state.editing && Array.isArray(state.editing.media)) {
				const existingAlt = {};
				state.editing.media.forEach((m) => {
					if (m.kind === 'image') {
						existingAlt[m.id] = m.alt || '';
					}
				});
				if (Object.keys(existingAlt).length) {
					formData.append('existing_alt', JSON.stringify(existingAlt));
				}
			}
			state.tags.forEach((tag) => formData.append('tags[]', tag));

			try {
				// Editing a draft updates it in place; otherwise create.
				const path = state.editing ? 'marks/' + state.editing.id : 'marks';
				const response = await apiUpload(path, formData);
				state.lastPublish = {
					response,
					targets: state.targets.slice(),
					type: state.primaryType,
				};
				resetComposer();
				navigate('#success');
			} catch (err) {
				button.disabled = false;
				if (otherButton) {
					otherButton.disabled = false;
				}
				button.textContent = isDraft ? 'Save as Draft' : 'Publish Now';
				status.textContent = (isDraft ? 'Save failed: ' : 'Publish failed: ') + err.message;
			}
		},
	};

	// --- Screen: Success ---

	const SuccessScreen = {
		render() {
			const publish = state.lastPublish || { response: {}, targets: [], type: 'note' };
			const permalink = publish.response && publish.response.permalink;

			const rows = publish.targets
				.map((id) => {
					const status = this.externalStatus(publish.response, id);
					return `
				<li class="daymark-syndication__row">
					<span>${esc(connectorLabel(id))}</span>
					<span class="daymark-chip daymark-chip--muted">${esc(status)}</span>
				</li>`;
				})
				.join('');

			const isDraft = publish.response && 'publish' !== publish.response.status;

			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title daymark-visually-hidden" tabindex="-1" data-daymark-focus>${
					isDraft ? 'Draft saved' : 'Published'
				}</h1>
			</header>
			<section class="daymark-screen daymark-success">
				<span class="daymark-success__icon">
					<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
				</span>
				<h2 class="daymark-screen__heading">${
					isDraft ? 'Saved as draft' : 'Published to your site'
				}${
					!isDraft && permalink
						? ` <a class="daymark-success__viewlink" href="${esc(
								permalink
						  )}" target="_blank" rel="noopener">(view)</a>`
						: ''
				}</h2>
				${
					isDraft
						? '<p class="daymark-note-card__meta">Finish it any time from Recent Marks on Home.</p>'
						: ''
				}
				<a class="daymark-success__link" href="#home">View all marks &rarr;</a>
				${
					isDraft
						? publish.targets.length
							? '<p class="daymark-note-card__meta">Selected destinations will publish when this Mark goes live.</p>'
							: ''
						: rows
						? `<ul class="daymark-syndication" aria-label="Syndication status">${rows}</ul>`
						: '<p class="daymark-note-card__meta">No social destinations selected.</p>'
				}
			</section>
			<footer class="daymark-actionbar">
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="create-another">Create Another</button>
				${
					pageLink('timeline')
						? `<p class="daymark-status"><a class="daymark-btn--text daymark-btn" href="${esc(
								pageLink('timeline')
						  )}">View Timeline &rarr;</a></p>`
						: ''
				}
			</footer>`;
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
						<textarea id="${replyId}-input" class="daymark-textarea daymark-reply__input" data-reply-input rows="2" placeholder="Write a reply&hellip;"></textarea>
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
				this.closeAllReplies(list);
				return;
			}

			const send = target.closest('[data-reply-send]');
			if (send) {
				this.submitReply(send);
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
	};

	window.addEventListener('hashchange', () => {
		showScreen(window.location.hash);
	});

	const initialHash =
		window.location.hash ||
		(config.screen === 'notifications' ? '#notifications' : '#home');
	showScreen(initialHash);

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
