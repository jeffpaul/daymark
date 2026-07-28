/**
 * Moment app shell — vanilla ES2020, no framework, no build step.
 *
 * Screen routing is hash-based within /moment. The server-rendered
 * screen (home | notifications) arrives via window.momentApp.screen.
 *
 * Screens: #home, #create, #publish, #success, #notifications.
 * The AI Assist sheet is an overlay, not a routed screen.
 */
(function () {
	'use strict';

	// --- Config ---
	const config = window.momentApp || {};
	const connectors = Array.isArray(config.connectors) ? config.connectors : [];
	const typeDefaults = config.defaults || {};
	const siteCategories = Array.isArray(config.categories) ? config.categories : [];
	const categoryDefaults = config.categoryDefaults || {};
	const root = document.getElementById('moment-app');

	if (!root) {
		return;
	}

	// --- App state ---
	const state = {
		files: [], // { id, file, url, kind, alt, altStatus, altEdited }
		caption: '',
		tags: [],
		primaryType: 'note',
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
		podcast: 'Podcast',
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

	// Section-page URL for a view, or '' when the site has no Moment page
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
		return `<svg class="moment-bottomnav__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${glyph}</svg>`;
	}

	/**
	 * Detect the Moment type from selected files (client-side mirror of the
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
		state.tags = [];
		state.primaryType = 'note';
		state.targets = [];
		state.categories = [];
		state.aiAssistUsed = false;
		state.editing = null;
		state.helpers = [];
	}

	// Effective Moment type: new files win; otherwise an edited draft's
	// stored type; otherwise the caption-only default. The server
	// recomputes authoritatively on save.
	function effectiveType() {
		if (state.files.length && state.editing && state.editing.media.length) {
			return 'mixed';
		}
		if (state.files.length) {
			return detectType(state.files);
		}
		return state.editing ? state.editing.type : detectType(state.files);
	}

	// Load a draft into the composer for continued editing.
	async function openDraft(id) {
		const moment = await apiGet('moments/' + id);
		resetComposer();
		state.editing = {
			id: moment.id,
			type: moment.type || 'note',
			media: Array.isArray(moment.media) ? moment.media : [],
		};
		state.caption = moment.caption || '';
		state.targets = Array.isArray(moment.targets) ? moment.targets.slice() : [];
		state.categories = Array.isArray(moment.categories) ? moment.categories.map(Number) : [];
		state.helpers = Array.isArray(moment.helpers) ? moment.helpers.slice() : [];
		state.primaryType = state.editing.type;
		navigate('#create');
	}

	function skeletonRows(count) {
		let out = '';
		for (let i = 0; i < count; i++) {
			out += '<div class="moment-skeleton" aria-hidden="true"></div>';
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

		document.body.className = 'moment-app moment-app--' + target.slice(1);

		if (window.location.hash !== target) {
			window.history.replaceState(null, '', target);
		}

		// Focus management: move focus to the screen heading.
		const heading = root.querySelector('[data-moment-focus]');
		if (heading) {
			heading.focus();
		}
	}

	// --- Screen: Home ---

	const HomeScreen = {
		render() {
			return `
			<header class="moment-topbar">
				<h1 class="moment-topbar__title" tabindex="-1" data-moment-focus>Moment</h1>
				<a class="moment-iconbtn" href="#notifications" aria-label="${
					config.notifications && config.notifications.hasUnread
						? 'Notifications — unread replies'
						: 'Notifications'
				}">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
					${
						config.notifications && config.notifications.hasUnread
							? '<span class="moment-iconbtn__dot" aria-hidden="true"></span>'
							: ''
					}
				</a>
			</header>
			<section class="moment-screen">
				<section class="moment-recent" data-drafts-section hidden aria-labelledby="moment-drafts-heading">
					<h2 id="moment-drafts-heading" class="moment-section-heading">Drafts</h2>
					<div class="moment-recent__list" data-drafts-list></div>
				</section>
				<section class="moment-recent" aria-labelledby="moment-recent-heading">
					<h2 id="moment-recent-heading" class="moment-section-heading">Recent Moments</h2>
					<div class="moment-recent__list" data-recent-list aria-live="polite">
						${skeletonRows(3)}
						<span class="moment-visually-hidden">Loading recent Moments</span>
					</div>
					<p class="moment-recent__more" data-recent-more hidden></p>
				</section>
			</section>
			<footer class="moment-homefooter">
				<button type="button" class="moment-btn moment-btn--primary moment-btn--hero moment-homefooter__cta" data-action="new-moment">+ New Moment</button>
				${(() => {
					const links = Object.keys(PAGE_LABELS)
						.filter((view) => pageLink(view))
						.map(
							(view) =>
								`<a class="moment-bottomnav__link" href="${esc(pageLink(view))}" title="${esc(
									PAGE_LABELS[view]
								)}">${pageNavIcon(PAGE_ICONS[view])}<span class="moment-visually-hidden">${esc(
									PAGE_LABELS[view]
								)}</span></a>`
						)
						.join('');
					return links
						? `<nav class="moment-bottomnav" aria-label="Site views">${links}</nav>`
						: '';
				})()}
			</footer>`;
		},

		bindEvents() {
			root.querySelector('[data-action="new-moment"]').addEventListener('click', () => {
				resetComposer();
				navigate('#create');
			});
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
			const list = root.querySelector('[data-recent-list]');
			const draftsSection = root.querySelector('[data-drafts-section]');
			const draftsList = root.querySelector('[data-drafts-list]');
			try {
				// Drafts are fetched separately so they stay reachable no
				// matter how many Moments have published since.
				const [drafts, published] = await Promise.all([
					apiGet('moments?status=draft&per_page=10'),
					apiGet('moments?status=publish'),
				]);
				if (!list || !list.isConnected) {
					return;
				}

				const draftItems = Array.isArray(drafts) ? drafts : [];
				if (draftItems.length && draftsSection && draftsList) {
					draftsList.innerHTML = draftItems.map((item) => this.renderItem(item)).join('');
					draftsSection.hidden = false;
					this.bindDraftTaps(draftsList);
				}

				const publishedItems = Array.isArray(published) ? published : [];
				const items = publishedItems.slice(0, 5);
				if (!items.length) {
					list.innerHTML = draftItems.length
						? '<p class="moment-empty">Nothing published yet.</p>'
						: '<p class="moment-empty">Nothing here yet. Create your first Moment.</p>';
					return;
				}
				list.innerHTML = items.map((item) => this.renderItem(item)).join('');

				// When more published Moments exist than the five shown, offer
				// a path to the full timeline (only if that page resolved).
				const more = root.querySelector('[data-recent-more]');
				const timeline = pageLink('timeline');
				if (more && timeline && publishedItems.length > items.length) {
					more.innerHTML = `<a class="moment-recent__morelink" href="${esc(
						timeline
					)}">View more on your timeline &rarr;</a>`;
					more.hidden = false;
				}
			} catch (err) {
				if (list && list.isConnected) {
					list.innerHTML =
						'<p class="moment-error" role="alert">Could not load recent Moments. ' +
						esc(err.message) +
						'</p>';
				}
			}
		},

		renderItem(item) {
			const title = item.title || 'Untitled Moment';
			const thumb = item.thumbnail
				? `<img class="moment-recent__thumb" src="${esc(item.thumbnail)}" alt="" />`
				: `<span class="moment-recent__thumb moment-recent__thumb--glyph" aria-hidden="true">${esc(
						(TYPE_LABELS[item.type] || 'M').charAt(0)
				  )}</span>`;
			// Drafts look identical to published Moments otherwise — and
			// their permalinks are invisible to visitors — so say so, and
			// tapping one reopens the composer instead of the permalink.
			const isDraft = item.status && 'publish' !== item.status;
			const draftChip = isDraft
				? '<span class="moment-chip moment-chip--draft">Draft</span> '
				: '';
			const href = isDraft ? '#create' : item.permalink || '#home';
			const editAttr = isDraft ? ` data-edit-draft="${esc(String(item.id))}"` : '';
			return `
			<a class="moment-recent__item" href="${esc(href)}"${editAttr}>
				${thumb}
				<span class="moment-recent__body">
					<span class="moment-recent__title">${esc(title)}</span>
					<span class="moment-recent__meta">${draftChip}${esc(
						TYPE_LABELS[item.type] || item.type || ''
					)}${item.date ? ' · ' + esc(relativeTime(item.date)) : ''}</span>
				</span>
			</a>`;
		},
	};

	// --- Screen: Create Moment ---

	const CreateScreen = {
		render() {
			const editing = state.editing;
			const existingTiles =
				editing && editing.media.length
					? `<ul class="moment-editmedia" aria-label="Media already attached to this draft">${editing.media
							.map(
								(m) =>
									`<li class="moment-editmedia__item">
										${
											m.thumbnail
												? `<img class="moment-editmedia__thumb" src="${esc(m.thumbnail)}" alt="Attached ${esc(
														m.filename || m.kind
												  )}" />`
												: `<span class="moment-editmedia__glyph">${esc(m.kind)}</span>`
										}
										${
											m.kind === 'image'
												? `<span class="moment-alt moment-alt--edit">
														<label class="moment-alt__label" for="moment-existing-alt-${esc(m.id)}">Alt text</label>
														<input type="text" class="moment-input moment-alt__input" id="moment-existing-alt-${esc(
															m.id
														)}" data-existing-alt="${esc(m.id)}" value="${esc(
														m.alt || ''
												  )}" placeholder="Describe this image" />
													</span>`
												: `<span class="moment-editmedia__name">${esc(m.filename || m.kind)}</span>`
										}
									</li>`
							)
							.join('')}</ul>`
					: '';
			return `
			<header class="moment-topbar">
				<a class="moment-backlink" href="#home">&larr; Back</a>
				<h1 class="moment-topbar__title" tabindex="-1" data-moment-focus>${
					editing ? 'Edit Draft' : 'New Moment'
				}</h1>
			</header>
			<section class="moment-screen">
				${
					editing
						? '<p class="moment-editbanner"><span class="moment-chip moment-chip--draft">Draft</span> Changes save to this Moment — new media is added alongside what’s attached.</p>'
						: ''
				}
				${existingTiles}
				<div class="moment-picker">
					<input type="file" id="moment-file-input" class="moment-picker__input" accept="image/*,video/*,audio/*" multiple />
					<label for="moment-file-input" class="moment-picker__zone">
						<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
						<span>Tap to choose media</span>
						<span class="moment-picker__hint">Photos, videos, or audio from your device</span>
					</label>
				</div>
				<div class="moment-preview" data-preview></div>
				<p class="moment-typebadge">Moment type: <span class="moment-chip" data-type-badge>${esc(
					TYPE_LABELS[effectiveType()]
				)}</span></p>
				<div class="moment-field">
					<label class="moment-field__label" for="moment-caption">Caption</label>
					<textarea id="moment-caption" class="moment-textarea" rows="4" placeholder="What&#39;s happening?">${esc(
						state.caption
					)}</textarea>
				</div>
				${
					config.ai && config.ai.available
						? '<button type="button" class="moment-btn moment-btn--secondary" data-action="ai-assist">AI Assist</button>'
						: '' /* No AI provider configured — no AI options offered. */
				}
			</section>
			<footer class="moment-actionbar">
				<p class="moment-status" data-create-status aria-live="polite"></p>
				<button type="button" class="moment-btn moment-btn--primary" data-action="next">Next: Publish &rarr;</button>
			</footer>`;
		},

		bindEvents() {
			const input = root.querySelector('#moment-file-input');
			const caption = root.querySelector('#moment-caption');

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
				// selections; fresh Moments start from the per-type defaults.
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
			if (!state.files.length) {
				preview.innerHTML = '';
				return;
			}

			const shown = state.files.slice(0, 4);
			const extra = state.files.length - 4;
			const tiles = shown
				.map((entry, index) => {
					const media = entry.url
						? `<img class="moment-preview__img" src="${esc(entry.url)}" alt="Preview of ${esc(
								entry.file.name
						  )}" />`
						: `<span class="moment-preview__glyph">${esc(entry.kind)}</span>`;
					const more =
						index === 3 && extra > 0
							? `<span class="moment-preview__more" aria-hidden="true">+${extra}</span>`
							: '';
					return `<li class="moment-preview__tile">${media}${more}</li>`;
				})
				.join('');

			const fileRows = state.files
				.map(
					(entry) => `
				<li class="moment-filelist__item">
					<div class="moment-filelist__row">
						<span class="moment-filelist__name">${esc(entry.file.name)}</span>
						<button type="button" class="moment-filelist__clear" data-clear-file="${esc(
							entry.id
						)}" aria-label="Clear ${esc(entry.file.name)}">Clear</button>
					</div>
					${entry.kind === 'image' ? this.altFieldMarkup(entry) : ''}
				</li>`
				)
				.join('');

			const extraLabel = extra > 0 ? `, plus ${extra} more` : '';
			preview.innerHTML = `
				<ul class="moment-preview__grid" aria-label="Selected media previews${esc(extraLabel)}">${tiles}</ul>
				<ul class="moment-filelist">${fileRows}</ul>`;

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
					? '<span class="moment-alt__hint">Generating alt text…</span>'
					: entry.altStatus === 'done'
					? '<span class="moment-alt__hint">AI-suggested — edit as needed</span>'
					: '';
			return `
				<div class="moment-alt">
					<label class="moment-alt__label" for="moment-alt-${esc(entry.id)}">Alt text</label>
					<input type="text" class="moment-input moment-alt__input" id="moment-alt-${esc(
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
			const hint = field.parentElement.querySelector('.moment-alt__hint');
			if (hint) {
				hint.textContent = entry.altStatus === 'done' ? 'AI-suggested — edit as needed' : '';
			}
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
				this.el.className = 'moment-sheet';
				document.body.appendChild(this.el);
			}
			this.el.hidden = false;
			this.el.innerHTML = `
			<button type="button" class="moment-sheet__backdrop" data-sheet-dismiss aria-label="Dismiss AI Assist"></button>
			<div class="moment-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="moment-sheet-title">
				<h2 class="moment-sheet__title" id="moment-sheet-title" tabindex="-1">AI Assist</h2>
				<div class="moment-sheet__body" data-sheet-body aria-live="polite">
					<p class="moment-loading"><span class="moment-spinner" aria-hidden="true"></span> Getting suggestions&hellip;</p>
				</div>
			</div>`;

			this.el.querySelector('[data-sheet-dismiss]').addEventListener('click', () => this.hide());
			this.onKeydown = (event) => {
				if (event.key === 'Escape') {
					this.hide();
				}
			};
			document.addEventListener('keydown', this.onKeydown);
			this.el.querySelector('#moment-sheet-title').focus();
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
					<p class="moment-error" role="alert">Could not get suggestions. ${esc(err.message)}</p>
					<div class="moment-sheet__actions">
						<button type="button" class="moment-btn moment-btn--primary" data-sheet-retry>Retry</button>
						<button type="button" class="moment-btn moment-btn--text" data-sheet-skip>Skip</button>
					</div>`;
				body.querySelector('[data-sheet-retry]').addEventListener('click', () => {
					body.innerHTML =
						'<p class="moment-loading"><span class="moment-spinner" aria-hidden="true"></span> Getting suggestions&hellip;</p>';
					this.fetchSuggestions();
				});
				body.querySelector('[data-sheet-skip]').addEventListener('click', () => this.hide());
			}
		},

		renderForm(suggestions) {
			const notice = suggestions.is_mocked
				? '<p class="moment-notice">Using demo suggestions — connect an AI provider in WordPress settings for real suggestions.</p>'
				: suggestions.provider_label
				? `<p class="moment-notice">Suggestions by ${esc(suggestions.provider_label)}.</p>`
				: '';
			return `
			${notice}
			<div class="moment-field">
				<label class="moment-field__label" for="moment-ai-caption">Suggested caption</label>
				<textarea id="moment-ai-caption" class="moment-textarea" rows="3">${esc(
					suggestions.caption || ''
				)}</textarea>
			</div>
			<fieldset class="moment-tags">
				<legend class="moment-tags__legend">Suggested tags</legend>
				<ul class="moment-tags__list" data-tag-list></ul>
				<div class="moment-tags__addrow">
					<label class="moment-visually-hidden" for="moment-ai-newtag">Add a tag</label>
					<input type="text" id="moment-ai-newtag" class="moment-input" placeholder="Add a tag" />
					<button type="button" class="moment-btn moment-btn--secondary" data-tag-add>+ Add</button>
				</div>
			</fieldset>
			<div class="moment-sheet__actions">
				<button type="button" class="moment-btn moment-btn--primary" data-sheet-accept>Accept All</button>
				<button type="button" class="moment-btn moment-btn--text" data-sheet-skip>Skip</button>
			</div>`;
		},

		bindForm() {
			this.renderTags();

			this.el.querySelector('[data-tag-add]').addEventListener('click', () => {
				const input = this.el.querySelector('#moment-ai-newtag');
				const value = input.value.trim();
				if (value && !this.tags.includes(value)) {
					this.tags.push(value);
					this.renderTags();
				}
				input.value = '';
				input.focus();
			});

			this.el.querySelector('[data-sheet-accept]').addEventListener('click', () => {
				state.caption = this.el.querySelector('#moment-ai-caption').value;
				state.tags = this.tags.slice();
				state.aiAssistUsed = true;
				const captionField = document.getElementById('moment-caption');
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
						<li class="moment-tags__chip">
							<span>${esc(tag)}</span>
							<button type="button" class="moment-tags__remove" data-tag-remove="${index}" aria-label="Remove tag ${esc(
								tag
							)}">&times;</button>
						</li>`
						)
						.join('')
				: '<li class="moment-note-card__meta">No tags suggested.</li>';
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

	// Why a connector can't take the current Moment type, phrased by what
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
				: `<li class="moment-dest moment-dest--locked">
					<span class="moment-dest__row"><span class="moment-dest__info">
						<span class="moment-recent__meta">No social networks connected yet — your site is the only destination. Connect one via a Moment connector plugin (Settings → Connectors).</span>
					</span></span>
				</li>`;

			const connectorRows = connectors
				.map((connector) => {
					const supported = connectorSupportsType(connector, state.primaryType);
					const checked = supported && state.targets.includes(connector.id) ? ' checked' : '';
					const chip = supported
						? `<span class="moment-chip ${connector.connected ? 'moment-chip--success' : 'moment-chip--muted'}">${esc(
								connector.status_label || 'Mocked · Not connected'
							)}</span>`
						: `<span class="moment-chip moment-chip--muted">${esc(unsupportedReason(connector))}</span>`;
					return `
				<li class="moment-dest${supported ? '' : ' moment-dest--unsupported'}">
					<label class="moment-dest__row" for="moment-dest-${esc(connector.id)}">
						<span class="moment-dest__info">
							<span class="moment-dest__name">${esc(connector.label)}</span>
							${chip}
						</span>
						<span class="moment-toggle">
							<input type="checkbox" class="moment-toggle__input" id="moment-dest-${esc(
								connector.id
							)}" data-connector="${esc(connector.id)}"${checked}${supported ? '' : ' disabled'} aria-label="${
								supported
									? `Publish to ${esc(connector.label)}`
									: `${esc(connector.label)} does not support ${esc(TYPE_LABELS[state.primaryType] || state.primaryType)} Moments`
							}" />
							<span class="moment-toggle__track" aria-hidden="true"></span>
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
				<li class="moment-dest">
					<label class="moment-dest__row" for="moment-helper-${esc(helper.id)}">
						<span class="moment-dest__info">
							<span class="moment-dest__name">${esc(helper.label)}</span>
							<span class="moment-chip moment-chip--muted">Via plugin</span>
						</span>
						<span class="moment-toggle">
							<input type="checkbox" class="moment-toggle__input" id="moment-helper-${esc(
								helper.id
							)}" data-helper="${esc(helper.id)}"${
						state.helpers.includes(helper.id) ? ' checked' : ''
					} aria-label="Also publish through ${esc(helper.label)}" />
							<span class="moment-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`
				)
				.join('');

			return `
			<header class="moment-topbar">
				<a class="moment-backlink" href="#create">&larr; Back</a>
				<h1 class="moment-topbar__title" tabindex="-1" data-moment-focus>Where should this go?</h1>
			</header>
			<section class="moment-screen">
				<p class="moment-typebadge">Publishing ${
					/^[aeiou]/i.test(TYPE_LABELS[state.primaryType] || '') ? 'an' : 'a'
				} <span class="moment-chip">${esc(
					TYPE_LABELS[state.primaryType]
				)}</span> Moment</p>
				<ul class="moment-destlist">
					<li class="moment-dest moment-dest--locked">
						<span class="moment-dest__row">
							<span class="moment-dest__info">
								<span class="moment-dest__name">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
									Your Site
								</span>
								<span class="moment-chip moment-chip--success">Required</span>
							</span>
							<span class="moment-toggle">
								<input type="checkbox" class="moment-toggle__input" checked disabled aria-label="Your Site (always included)" />
								<span class="moment-toggle__track" aria-hidden="true"></span>
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
					return `<p class="moment-helpers-note">Your site’s publishing tools will also share this Moment, per their own settings: <strong>${names}</strong>.</p>`;
				})()}
				${this.renderCategories()}
			</section>
			<footer class="moment-actionbar">
				<p class="moment-status" data-publish-status aria-live="polite"></p>
				<button type="button" class="moment-btn moment-btn--primary" data-action="publish">Publish Now</button>
				<button type="button" class="moment-btn moment-btn--secondary" data-action="save-draft">Save as Draft</button>
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
				<li class="moment-dest">
					<label class="moment-dest__row" for="moment-cat-${esc(cat.id)}">
						<span class="moment-dest__info">
							<span class="moment-dest__name">${esc(cat.name)}</span>
						</span>
						<span class="moment-toggle">
							<input type="checkbox" class="moment-toggle__input" id="moment-cat-${esc(
								cat.id
							)}" data-category="${esc(cat.id)}"${
						state.categories.includes(cat.id) ? ' checked' : ''
					} aria-label="File under ${esc(cat.name)}" />
							<span class="moment-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`
				)
				.join('');
			const typeLabel = esc(TYPE_LABELS[state.primaryType] || 'these');
			return `
				<h2 class="moment-section-heading moment-publish-subhead">File under</h2>
				<p class="moment-publish-subnote">Saved as the default for ${typeLabel} Moments — change it any time.</p>
				<ul class="moment-destlist">${items}</ul>`;
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
				const path = state.editing ? 'moments/' + state.editing.id : 'moments';
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
				<li class="moment-syndication__row">
					<span>${esc(connectorLabel(id))}</span>
					<span class="moment-chip moment-chip--muted">${esc(status)}</span>
				</li>`;
				})
				.join('');

			const isDraft = publish.response && 'publish' !== publish.response.status;

			return `
			<header class="moment-topbar">
				<h1 class="moment-topbar__title moment-visually-hidden" tabindex="-1" data-moment-focus>${
					isDraft ? 'Draft saved' : 'Published'
				}</h1>
			</header>
			<section class="moment-screen moment-success">
				<span class="moment-success__icon">
					<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
				</span>
				<h2 class="moment-screen__heading">${
					isDraft ? 'Saved as draft' : 'Published to your site'
				}${
					!isDraft && permalink
						? ` <a class="moment-success__viewlink" href="${esc(
								permalink
						  )}" target="_blank" rel="noopener">(view)</a>`
						: ''
				}</h2>
				${
					isDraft
						? '<p class="moment-note-card__meta">Finish it any time from Recent Moments on Home.</p>'
						: ''
				}
				<a class="moment-success__link" href="#home">View all moments &rarr;</a>
				${
					isDraft
						? publish.targets.length
							? '<p class="moment-note-card__meta">Selected destinations will publish when this Moment goes live.</p>'
							: ''
						: rows
						? `<ul class="moment-syndication" aria-label="Syndication status">${rows}</ul>`
						: '<p class="moment-note-card__meta">No social destinations selected.</p>'
				}
			</section>
			<footer class="moment-actionbar">
				<button type="button" class="moment-btn moment-btn--primary" data-action="create-another">Create Another</button>
				${
					pageLink('timeline')
						? `<p class="moment-status"><a class="moment-btn--text moment-btn" href="${esc(
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
			<header class="moment-topbar">
				<a class="moment-backlink" href="#home">&larr; Back</a>
				<h1 class="moment-topbar__title" tabindex="-1" data-moment-focus>Notifications</h1>
			</header>
			<section class="moment-screen">
				<h2 class="moment-section-heading">Recent Activity</h2>
				<div class="moment-recent__list" data-notification-list aria-live="polite">
					${skeletonRows(3)}
					<span class="moment-visually-hidden">Loading notifications</span>
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
						'<p class="moment-empty">No new activity for your Moments.</p>';
					return;
				}
				list.innerHTML = items.map((item) => this.renderItem(item)).join('');
				this.bindShowMore(list);
			} catch (err) {
				if (list && list.isConnected) {
					list.innerHTML =
						'<p class="moment-error" role="alert">Could not load notifications. ' +
						esc(err.message) +
						'</p>';
				}
			}
		},

		renderItem(item) {
			const text = toPlainText(item.comment_content);
			const long = text.length > 140;
			const author = item.comment_author || item.author || '';
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
			return `
			<article class="moment-note-card">
				<span class="moment-chip">${esc(item.source_label || 'Comment')}</span>
				<p class="moment-note-card__text moment-clamp">${esc(text)}</p>
				${
					long
						? '<button type="button" class="moment-note-card__showmore" data-showmore aria-expanded="false">Show more</button>'
						: ''
				}
				${metaParts.length ? `<p class="moment-note-card__meta">${metaParts.join(' &middot; ')}</p>` : ''}
				<div class="moment-note-card__links">
					${
						item.post_url
							? `<a class="moment-note-card__link" href="${esc(
									item.post_url
							  )}">&rarr; View Moment</a>`
							: ''
					}
					${
						item.source_url
							? `<a class="moment-note-card__link" href="${esc(
									item.source_url
							  )}" target="_blank" rel="noopener">&nearr; View on network</a>`
							: ''
					}
				</div>
			</article>`;
		},

		bindShowMore(list) {
			list.querySelectorAll('[data-showmore]').forEach((button) => {
				button.addEventListener('click', () => {
					const text = button.parentElement.querySelector('.moment-note-card__text');
					const expanded = text.classList.toggle('is-expanded');
					button.textContent = expanded ? 'Show less' : 'Show more';
					button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				});
			});
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
	// scope is /wp-content/plugins/moment/assets/ — it cannot (and is not
	// meant to) control the /moment page itself. We register with that
	// explicit narrow scope on purpose: install-time precaching still
	// stores app.css and app.js in Cache Storage, and the narrow scope
	// guarantees the worker can never intercept REST calls, nonces, or
	// the app-shell HTML. No Service-Worker-Allowed header hacks.
	// Feature-detected and failure-tolerant: if registration fails
	// (HTTP-only local sites, older browsers), the app works unchanged.
	if ('serviceWorker' in navigator && config.assetsUrl) {
		navigator.serviceWorker
			.register(config.assetsUrl + 'moment-sw.js', { scope: config.assetsUrl })
			.catch(() => {
				/* Never let SW registration break the app. */
			});
	}
})();
