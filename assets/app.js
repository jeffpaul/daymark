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

	const { __, _n, _x, sprintf } = wp.i18n;

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
		tagsEdited: false, // author added/removed/accepted a tag themselves: never overwrite with a later quiet suggestion
		// Quiet metadata capture (never user-visible, never blocks anything):
		// set once per fresh composing session by maybeBeginQuietCapture().
		capturedAt: null, // ISO 8601 string — "when this Mark was actually created"
		location: null, // { lat, lng, accuracy } | null — best-effort, silent geolocation
		locationRequested: false, // guards against asking more than once per session
		primaryType: 'note',
		// Set by the Home launcher before navigating to #create so the
		// composer opens pre-set to the chosen type; cleared by
		// resetComposer(). Only a fallback — picking files or resuming a
		// draft still wins, exactly as effectiveType() already resolves.
		pendingType: null,
		// { url, title } | null — set by startReplyToSubscriptionPost() when
		// composing a reply from a subscribed post's full-screen view, or
		// restored from an existing draft's own in_reply_to (openDraft()).
		// Unlike pendingType this isn't a one-shot UI hint: url travels with
		// every buildMarkPayload() call for the rest of the session (autosave,
		// publish) exactly like capturedAt/location do, so the relationship
		// is recorded on the Mark itself, not just the composer's first render.
		replyTo: null,
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
		note: __('Note', 'daymark'),
		image: __('Image', 'daymark'),
		gallery: __('Gallery', 'daymark'),
		video: __('Video', 'daymark'),
		audio: __('Audio', 'daymark'),
		mixed: __('Mixed media', 'daymark'),
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
	/**
	 * Parse an ISO 8601 or MySQL datetime string into a Date, or null if it
	 * doesn't parse as either. Shared by relativeTime() and
	 * renderCardTimestamp() so they can never drift on what counts as a
	 * valid date.
	 */
	function parseDate(value) {
		if (!value) {
			return null;
		}
		let date = new Date(value);
		if (Number.isNaN(date.getTime()) && typeof value === 'string') {
			date = new Date(value.replace(' ', 'T'));
		}
		return Number.isNaN(date.getTime()) ? null : date;
	}

	const PHP_MONTH_NAMES = [
		__('January', 'daymark'),
		__('February', 'daymark'),
		__('March', 'daymark'),
		__('April', 'daymark'),
		__('May', 'daymark'),
		__('June', 'daymark'),
		__('July', 'daymark'),
		__('August', 'daymark'),
		__('September', 'daymark'),
		__('October', 'daymark'),
		__('November', 'daymark'),
		__('December', 'daymark'),
	];
	const PHP_MONTH_ABBR = [
		__('Jan', 'daymark'),
		__('Feb', 'daymark'),
		__('Mar', 'daymark'),
		__('Apr', 'daymark'),
		__('May', 'daymark'),
		__('Jun', 'daymark'),
		__('Jul', 'daymark'),
		__('Aug', 'daymark'),
		__('Sep', 'daymark'),
		__('Oct', 'daymark'),
		__('Nov', 'daymark'),
		__('Dec', 'daymark'),
	];
	const PHP_DAY_NAMES = [
		__('Sunday', 'daymark'),
		__('Monday', 'daymark'),
		__('Tuesday', 'daymark'),
		__('Wednesday', 'daymark'),
		__('Thursday', 'daymark'),
		__('Friday', 'daymark'),
		__('Saturday', 'daymark'),
	];
	const PHP_DAY_ABBR = [
		__('Sun', 'daymark'),
		__('Mon', 'daymark'),
		__('Tue', 'daymark'),
		__('Wed', 'daymark'),
		__('Thu', 'daymark'),
		__('Fri', 'daymark'),
		__('Sat', 'daymark'),
	];

	function ordinalSuffix(day) {
		if (day % 10 === 1 && day !== 11) {
			return 'st';
		}
		if (day % 10 === 2 && day !== 12) {
			return 'nd';
		}
		if (day % 10 === 3 && day !== 13) {
			return 'rd';
		}
		return 'th';
	}

	// Formats a Date using the subset of PHP date() format tokens WordPress's
	// own Date Format setting (Settings -> General; config.dateFormat below)
	// can produce — that option is a raw PHP date() format string, and JS has
	// no built-in equivalent, so each token is mapped by hand. Scoped to the
	// tokens a *date* format realistically uses (day/month/year/weekday);
	// time tokens aren't needed since this is specifically WP's Date Format
	// option, not Time Format. A backslash escapes the following character
	// as a literal, matching PHP's own date() syntax.
	function formatDateWithPhpFormat(date, format) {
		const day = date.getDate();
		const month = date.getMonth();
		const year = date.getFullYear();
		const weekday = date.getDay();
		let result = '';
		for (let i = 0; i < format.length; i += 1) {
			const char = format[i];
			if ('\\' === char && i + 1 < format.length) {
				result += format[i + 1];
				i += 1;
				continue;
			}
			switch (char) {
				case 'd':
					result += String(day).padStart(2, '0');
					break;
				case 'j':
					result += String(day);
					break;
				case 'D':
					result += PHP_DAY_ABBR[weekday];
					break;
				case 'l':
					result += PHP_DAY_NAMES[weekday];
					break;
				case 'N':
					result += String(0 === weekday ? 7 : weekday);
					break;
				case 'w':
					result += String(weekday);
					break;
				case 'S':
					result += ordinalSuffix(day);
					break;
				case 'F':
					result += PHP_MONTH_NAMES[month];
					break;
				case 'M':
					result += PHP_MONTH_ABBR[month];
					break;
				case 'm':
					result += String(month + 1).padStart(2, '0');
					break;
				case 'n':
					result += String(month + 1);
					break;
				case 'Y':
					result += String(year);
					break;
				case 'y':
					result += String(year).slice(-2);
					break;
				default:
					result += char;
			}
		}
		return result;
	}

	// The absolute-date display a card's timestamp falls back to once it's
	// too old for a relative "Xd ago" reading — uses the site's own
	// configured Date Format (Settings -> General -> Date Format,
	// config.dateFormat) rather than the browser's locale default, so it
	// reads the same way a site owner already sees dates everywhere else in
	// wp-admin. Falls back to the browser's own locale formatting only if
	// the server never sent a format (shouldn't happen — every WP install
	// has this option set).
	function formatAbsoluteDate(date) {
		return config.dateFormat ? formatDateWithPhpFormat(date, config.dateFormat) : date.toLocaleDateString();
	}

	function relativeTime(value) {
		const date = parseDate(value);
		if (!date) {
			return '';
		}
		const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
		if (seconds < 60) {
			return __('Just now', 'daymark');
		}
		const minutes = Math.floor(seconds / 60);
		if (minutes < 60) {
			return sprintf(__('%dm ago', 'daymark'), minutes);
		}
		const hours = Math.floor(minutes / 60);
		if (hours < 24) {
			return sprintf(__('%dh ago', 'daymark'), hours);
		}
		const days = Math.floor(hours / 24);
		if (days < 7) {
			return sprintf(__('%dd ago', 'daymark'), days);
		}
		return formatAbsoluteDate(date);
	}

	/**
	 * A Timeline card's own timestamp: relativeTime()'s display (relative
	 * for the first week, then a plain date), wrapped in a real <time
	 * datetime> element whose title is the full date and time — so the
	 * exact moment is always one hover/screen-reader-announcement away,
	 * not just available after the 7-day cutover to an absolute date.
	 */
	function renderCardTimestamp(value) {
		const date = parseDate(value);
		if (!date) {
			return '';
		}
		const full = sprintf(
			/* translators: 1: date, 2: time */
			__('%1$s at %2$s', 'daymark'),
			formatAbsoluteDate(date),
			date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
		);
		return `<time datetime="${esc(date.toISOString())}" title="${esc(full)}">${esc(
			relativeTime(value)
		)}</time>`;
	}

	/**
	 * Buckets a date into a relative Timeline period (issue #145) — Today,
	 * This Week, Last Week, This Month, Last Month, This Year, or a bare
	 * year for anything older. Purely additive to a card's own
	 * renderCardTimestamp(): this only decides where a section break falls,
	 * never what an individual card's own timestamp shows. Deliberately no
	 * separate "Now" bucket on top of Today — a card's own per-item
	 * timestamp (relativeTime()) already reads "Just now"/"Xm ago" for the
	 * freshest items, so a second, coarser "Now" header above Today would
	 * only say the same thing twice.
	 *
	 * `key` is a plain equality-comparable string so callers can detect a
	 * bucket change by walking date-descending items in order — this
	 * codebase's `GET /timeline` already sorts that way, so a bucket, once
	 * left, is never revisited within one render pass.
	 *
	 * Week/month/year boundaries are calendar-based (not a rolling N-day
	 * window), matching how "This Month"/"Last Month" read in everyday use;
	 * weeks start on Sunday (Date#getDay()'s own 0-based convention) rather
	 * than assuming a locale's first day of the week.
	 */
	function timelinePeriod(date) {
		const now = new Date();
		const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
		const today = startOfDay(now);
		const day = startOfDay(date);

		if (day.getTime() === today.getTime()) {
			return { key: 'today', label: __('Today', 'daymark') };
		}

		const startOfWeek = (d) => {
			const s = startOfDay(d);
			s.setDate(s.getDate() - s.getDay());
			return s;
		};
		const thisWeekStart = startOfWeek(now);
		if (day.getTime() >= thisWeekStart.getTime()) {
			return { key: 'this_week', label: __('This Week', 'daymark') };
		}
		const lastWeekStart = new Date(thisWeekStart);
		lastWeekStart.setDate(lastWeekStart.getDate() - 7);
		if (day.getTime() >= lastWeekStart.getTime()) {
			return { key: 'last_week', label: __('Last Week', 'daymark') };
		}

		if (date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth()) {
			return { key: 'this_month', label: __('This Month', 'daymark') };
		}
		const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
		if (date.getFullYear() === lastMonth.getFullYear() && date.getMonth() === lastMonth.getMonth()) {
			return { key: 'last_month', label: __('Last Month', 'daymark') };
		}

		if (date.getFullYear() === now.getFullYear()) {
			return { key: 'this_year', label: __('This Year', 'daymark') };
		}
		return { key: 'year:' + date.getFullYear(), label: String(date.getFullYear()) };
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
		{ type: '', label: __('All', 'daymark') },
		{ type: 'image', label: __('Images', 'daymark') },
		{ type: 'video', label: __('Videos', 'daymark') },
		{ type: 'audio', label: __('Audio', 'daymark') },
		{ type: 'note', label: __('Notes', 'daymark') },
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

	// Outline icons for the like/comment/reblog stat row shown on Mark cards
	// throughout the app shell (Home, Search).
	const COMMENT_GLYPH =
		'<path d="M21 11.5a8.38 8.38 0 0 1-4.7 7.6 8.5 8.5 0 0 1-3.8.9H12a8.48 8.48 0 0 1-4-.9l-5 1 1-5a8.48 8.48 0 0 1-.9-4 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>';
	const HEART_GLYPH =
		'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21.2l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"></path>';
	const REPOST_GLYPH =
		'<polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path>';
	const BOOKMARK_GLYPH = '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>';
	const SHARE_GLYPH =
		'<path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line>';
	const EXTERNAL_LINK_GLYPH =
		'<line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline>';
	const ROUTING_GLYPH =
		'<circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>';

	function statIcon(glyph) {
		return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${glyph}</svg>`;
	}

	// A zero-count stat shows only its (dimmed) icon — no "0" — so the row
	// stays quiet until there's something to report. `title` mirrors
	// `aria-label` as a native hover tooltip, matching the precedent
	// renderSiteIconButton() already set: a screen reader needs the
	// label; a sighted, non-touch hover wants to see it too.
	function renderStat(glyph, count, modifier, label) {
		const isActive = count > 0;
		return `<span class="daymark-stat daymark-stat--${modifier}${
			isActive ? ' daymark-stat--active' : ''
		}" aria-label="${esc(label)}" title="${esc(label)}">${statIcon(glyph)}${
			isActive ? `<span class="daymark-stat__count" aria-hidden="true">${count}</span>` : ''
		}</span>`;
	}

	// A Bookmark toggle, not a passive stat: it always shows (filled when
	// bookmarked, outline otherwise — never quiet-hidden the way a
	// zero-count stat is) and is the one *interactive* entry in this row,
	// so it's a `span[role="button"]` rather than a real `<button>` — this
	// row lives nested inside the card's own expand-trigger `<button>`
	// (renderMarkCore()) or, for a subscription post, inside its own
	// click-through `<button>` (renderSubscriptionPostCard()) — either
	// way, a real nested `<button>` would trip the HTML parser's own
	// "no <button> inside <button>" auto-close rule and silently break the
	// surrounding markup. onFeedListClick()/onFeedListKeydown() handle
	// activation the same as a real button would (click, or Enter/Space
	// while focused), and stop before reaching that button's own
	// expand-post/subpost handling.
	function renderBookmarkToggle(item, kind) {
		const bookmarked = !!item.bookmarked;
		const id = esc(String(item.id));
		const label = bookmarked ? __('Remove bookmark', 'daymark') : __('Bookmark for offline viewing', 'daymark');
		return `<span class="daymark-stat daymark-stat--bookmark${
			bookmarked ? ' daymark-stat--active daymark-stat--bookmarked' : ''
		}" role="button" tabindex="0" aria-pressed="${bookmarked ? 'true' : 'false'}" aria-label="${esc(
			label
		)}" title="${esc(label)}" data-bookmark-toggle="${id}" data-bookmark-kind="${esc(
			kind
		)}">${statIcon(BOOKMARK_GLYPH)}</span>`;
	}

	// A permanent "open the original" entry in the row — replaces the old
	// "View full post"/"View original" link that used to sit in the
	// expanded content's own footer (only visible once expanded). Same
	// span[role="button"] reasoning as Bookmark/Share; the URL travels
	// directly on the element itself (data-external-link) rather than
	// through a screen._byMarkId/_bySubId lookup, since — unlike Share —
	// there's no title/other field this needs, just the one URL. Omitted
	// entirely when an item has no permalink (should not normally happen
	// for anything actually published).
	function renderExternalLinkToggle(item) {
		if (!item.permalink) {
			return '';
		}
		const url = esc(item.permalink);
		const label = esc(__('Open original', 'daymark'));
		return `<span class="daymark-stat daymark-stat--external" role="button" tabindex="0" aria-label="${label}" title="${label}" data-external-link="${url}">${statIcon(
			EXTERNAL_LINK_GLYPH
		)}</span>`;
	}

	// "Where did this go" (issue #255) — a Mark-only affordance, shown once
	// the Mark has actually been routed somewhere (syndication_status isn't
	// 'not_attempted'; a Mark with no selected destinations has nothing to
	// report here, and a subscription post has no syndication targets of
	// its own at all). Tapping it opens a small popover — a sibling panel
	// in the item-wrap, not nested content, since a target's own link
	// can't validly live inside the card's own expand-trigger <button> —
	// populated lazily via toggleRoutingPanel().
	function renderRoutingToggle(item) {
		if (!item.syndication_status || 'not_attempted' === item.syndication_status) {
			return '';
		}
		const id = esc(String(item.id));
		const label = esc(__('Where this went', 'daymark'));
		return `<span class="daymark-stat daymark-stat--routing" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="${label}" title="${label}" data-routing-toggle="${id}">${statIcon(
			ROUTING_GLYPH
		)}</span>`;
	}

	// The row's other interactive entry, same span[role="button"] reasoning
	// as renderBookmarkToggle() above (nested inside the card's own
	// expand-trigger button either way). Shares a Mark's or a subscription
	// post's real permalink — identical behavior for either, so unlike the
	// Bookmark toggle this needs no `kind` distinction. Omitted entirely
	// when an item has no permalink at all (should not normally happen for
	// anything actually published), since there'd be nothing to share.
	function renderShareToggle(item) {
		if (!item.permalink) {
			return '';
		}
		const id = esc(String(item.id));
		const label = esc(__('Share', 'daymark'));
		return `<span class="daymark-stat daymark-stat--share" role="button" tabindex="0" aria-label="${label}" title="${label}" data-share-toggle="${id}">${statIcon(
			SHARE_GLYPH
		)}</span>`;
	}

	// A read-only indicator — not a toggle, no click handler — showing
	// whether the current user has already published a Mark engaging with
	// this exact subscription post (a reply, via the existing "Reply"
	// action). Same reasoning renderStat() already gives for the row's
	// numeric stats (always visible, never a real `<button>`, since it lives
	// nested inside the card's own expand-trigger button), but binary rather
	// than counted: Daymark only ever knows its own record of "did I engage
	// with this," never the origin site's real comment count.
	function renderEngagementIndicator(glyph, active, modifier, label) {
		return `<span class="daymark-stat daymark-stat--${modifier}${
			active ? ' daymark-stat--active' : ''
		}" aria-label="${esc(label)}" title="${esc(label)}">${statIcon(glyph)}</span>`;
	}

	// The Like toggle for a subscription post — the row's other *interactive*
	// entry besides Bookmark/Repost, same span[role="button"] reasoning as
	// renderBookmarkToggle() (nested inside the card's own expand-trigger
	// button). Unlike Bookmark, activating this publishes (or, to undo,
	// trashes) a small Mark of the site owner's own — see toggleLike() — so
	// `data-like-mark-id` carries that Mark's ID once one exists, letting the
	// toggle undo itself without a second lookup.
	function renderLikeToggle(item) {
		const liked = !!item.liked_mark_id;
		const id = esc(String(item.id));
		const markId = esc(String(item.liked_mark_id || 0));
		const label = liked ? __('Unlike', 'daymark') : __('Like', 'daymark');
		return `<span class="daymark-stat daymark-stat--like${
			liked ? ' daymark-stat--active daymark-stat--liked' : ''
		}" role="button" tabindex="0" aria-pressed="${liked ? 'true' : 'false'}" aria-label="${esc(
			label
		)}" title="${esc(label)}" data-like-toggle="${id}" data-like-mark-id="${markId}">${statIcon(
			HEART_GLYPH
		)}</span>`;
	}

	// The Repost toggle — same shape/reasoning as renderLikeToggle() above.
	function renderRepostToggle(item) {
		const reposted = !!item.reposted_mark_id;
		const id = esc(String(item.id));
		const markId = esc(String(item.reposted_mark_id || 0));
		const label = reposted ? __('Undo repost', 'daymark') : __('Repost', 'daymark');
		return `<span class="daymark-stat daymark-stat--repost${
			reposted ? ' daymark-stat--active daymark-stat--reposted' : ''
		}" role="button" tabindex="0" aria-pressed="${reposted ? 'true' : 'false'}" aria-label="${esc(
			label
		)}" title="${esc(label)}" data-repost-toggle="${id}" data-repost-mark-id="${markId}">${statIcon(
			REPOST_GLYPH
		)}</span>`;
	}

	function renderItemStats(item) {
		const likeCount = item.like_count || 0;
		const commentCount = item.comment_count || 0;
		const repostCount = item.repost_count || 0;
		return `<span class="daymark-item-stats">${renderStat(
			HEART_GLYPH,
			likeCount,
			'likes',
			sprintf(
				/* translators: %d: number of likes */
				_n('%d like', '%d likes', likeCount, 'daymark'),
				likeCount
			)
		)}${renderStat(
			COMMENT_GLYPH,
			commentCount,
			'comments',
			sprintf(
				/* translators: %d: number of comments */
				_n('%d comment', '%d comments', commentCount, 'daymark'),
				commentCount
			)
		)}${renderStat(
			REPOST_GLYPH,
			repostCount,
			'reposts',
			sprintf(
				/* translators: %d: number of reposts */
				_n('%d repost', '%d reposts', repostCount, 'daymark'),
				repostCount
			)
		)}${renderBookmarkToggle(item, 'mark')}${renderExternalLinkToggle(item)}${renderRoutingToggle(
			item
		)}${renderShareToggle(item)}</span>`;
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
		image: __('Take Photo', 'daymark'),
		video: __('Record Video', 'daymark'),
		audio: __('Record Audio', 'daymark'),
	};

	const CAPTURE_HINT_BY_TYPE = {
		image: __('Opens your camera', 'daymark'),
		video: __('Opens your camera', 'daymark'),
		audio: __('Opens your microphone', 'daymark'),
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

	// Manual gallery reordering (issue #250): moves an image entry one slot
	// up/down among the *other images* in the same list, ignoring any
	// non-image entries interleaved with them (a mixed-type Mark can carry
	// video/audio alongside a gallery's worth of images) — mutates the list
	// in place, since both state.files and state.editing.media are Object
	// references the composer already holds. Returns whether anything moved.
	function moveImageInList(list, id, direction) {
		const imageIndices = [];
		list.forEach((item, index) => {
			if (item.kind === 'image') {
				imageIndices.push(index);
			}
		});
		const currentPos = imageIndices.findIndex((index) => String(list[index].id) === String(id));
		const targetPos = currentPos + direction;
		if (currentPos === -1 || targetPos < 0 || targetPos >= imageIndices.length) {
			return false;
		}
		const i = imageIndices[currentPos];
		const j = imageIndices[targetPos];
		const swap = list[i];
		list[i] = list[j];
		list[j] = swap;
		return true;
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
		state.tagsEdited = false;
		clearTimeout(quietTagsTimer);
		quietTagsTimer = null;
		state.capturedAt = null;
		state.location = null;
		state.locationRequested = false;
		state.primaryType = 'note';
		state.pendingType = null;
		state.replyTo = null;
		state.targets = [];
		state.categories = [];
		state.aiAssistUsed = false;
		state.editing = null;
		state.helpers = [];
		state.offlineQueueId = null;
	}

	// Quietly capture "when/where this Mark was actually created" the
	// moment a fresh composing session begins — never for a resumed draft:
	// openDraft()/openPendingMark() both set state.editing BEFORE
	// navigate('#create') runs, so the guard below skips both, and
	// resetComposer() (called by both the launcher's typed-entry bubbles and
	// "Create Another") always clears state.editing first, so a genuinely
	// fresh session reliably has it unset. Idempotent per session — once
	// state.capturedAt is set, this is a no-op until the next
	// resetComposer(). Called from CreateScreen.bindEvents() so it covers
	// every path into the composer for a new Mark (the launcher, "Create
	// Another", a bookmarked/refreshed #create URL, the PWA manifest's
	// #create shortcut, an empty-state "Start one" link) with one guard
	// rather than needing a call at each entry point.
	//
	// "Don't make users fill those in": no loading state, no error, no
	// retry prompt — geolocation denial/timeout/absence just leaves
	// state.location unset, exactly like every other quiet-capture signal
	// in this feature.
	function maybeBeginQuietCapture() {
		if (state.editing) {
			return;
		}
		if (!state.capturedAt) {
			state.capturedAt = new Date().toISOString();
		}
		if (state.locationRequested || !('geolocation' in navigator)) {
			return;
		}
		state.locationRequested = true;
		navigator.geolocation.getCurrentPosition(
			(position) => {
				state.location = {
					lat: position.coords.latitude,
					lng: position.coords.longitude,
					accuracy: position.coords.accuracy,
				};
			},
			() => {
				// Denied, unsupported, or timed out — nothing to surface.
			},
			{ enableHighAccuracy: false, timeout: 5000 }
		);
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
			el.textContent = __('Saving…', 'daymark');
		} else if (kind === 'saved') {
			el.textContent = __('Saved', 'daymark');
		} else if (kind === 'offline') {
			el.textContent = __('Saved offline — will sync automatically', 'daymark');
		} else if (kind === 'error') {
			el.textContent = __('Not saved yet — will retry', 'daymark');
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
	// v2 adds BOOKMARK_STORE (see "Bookmarks" below) — the existing
	// OFFLINE_STORE is untouched, so onupgradeneeded's own existence check
	// (unchanged) still leaves an existing 'pending' store alone on
	// upgrade; only the new store is created.
	const OFFLINE_DB_VERSION = 2;
	const OFFLINE_STORE = 'pending';
	const BOOKMARK_STORE = 'bookmarks';

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
				if (!db.objectStoreNames.contains(BOOKMARK_STORE)) {
					// Keyed by the real Mark/subscription-post ID (not
					// auto-incrementing, unlike OFFLINE_STORE) — a bookmark's
					// cached content has exactly one natural identity, and
					// keying on it directly is what makes put()-to-update and
					// delete()-on-unbookmark idempotent.
					db.createObjectStore(BOOKMARK_STORE, { keyPath: 'id' });
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

	// --- Bookmarks: cached full content for offline viewing ---
	//
	// A bookmarked Mark or subscription post's full content is cached in
	// BOOKMARK_STORE (see openOfflineDB() above) so Explore's Bookmarks
	// section (a Search view filtered to `bookmarked=1`) still has
	// something to show with no connectivity — the same
	// content-fetch endpoints the full-screen post view uses (GET
	// /marks/{id}/content, GET /subscription-posts/{id}), just
	// persisted locally instead of fetched fresh every time. Every image
	// the cached content markup references is cached alongside it, as a
	// Blob (see cacheContentImages()) — the markup alone still pointed at
	// live, offline-broken image URLs otherwise (issue #236). Every
	// function here is best-effort: a failed cache write or read never
	// blocks the bookmark action itself (the server-side bookmark already
	// succeeded/failed independently) — it just means this device won't
	// have that one item (or, for a single failed image, just that one
	// image) available offline until the next successful cache attempt
	// (a retry on toggling it again, or syncBookmarkCache() on the next
	// app boot).
	async function putCachedBookmark(record) {
		const db = await openOfflineDB();
		const store = db.transaction(BOOKMARK_STORE, 'readwrite').objectStore(BOOKMARK_STORE);
		return idbRequest(store.put(record));
	}

	async function getCachedBookmark(id) {
		try {
			const db = await openOfflineDB();
			const store = db.transaction(BOOKMARK_STORE, 'readonly').objectStore(BOOKMARK_STORE);
			return await idbRequest(store.get(Number(id)));
		} catch (err) {
			return undefined;
		}
	}

	async function getAllCachedBookmarks() {
		try {
			const db = await openOfflineDB();
			const store = db.transaction(BOOKMARK_STORE, 'readonly').objectStore(BOOKMARK_STORE);
			const all = await idbRequest(store.getAll());
			return Array.isArray(all) ? all : [];
		} catch (err) {
			return [];
		}
	}

	async function removeCachedBookmarkOffline(id) {
		try {
			const db = await openOfflineDB();
			const store = db.transaction(BOOKMARK_STORE, 'readwrite').objectStore(BOOKMARK_STORE);
			await idbRequest(store.delete(Number(id)));
		} catch (err) {
			// IndexedDB unavailable, or this item was never cached in the
			// first place — either way, nothing left to remove.
		}
	}

	// Every <img src> an item's cached content markup references — walked
	// via a <template> (its .content is an inert DocumentFragment, so
	// parsing arbitrary bookmarked HTML here never runs any script/loads
	// any resource by itself) rather than a regex, since this runs in the
	// browser with a real DOM available, unlike the PHP-side content
	// extraction this codebase otherwise favors regex for.
	function extractImageUrls(html) {
		if (!html) {
			return [];
		}
		const template = document.createElement('template');
		template.innerHTML = html;
		const urls = new Set();
		template.content.querySelectorAll('img[src]').forEach((img) => {
			const src = img.getAttribute('src');
			if (src) {
				urls.add(src);
			}
		});
		return Array.from(urls);
	}

	// Fetches and caches, as Blobs, every image a bookmarked item's content
	// references — the markup itself is cached verbatim (see
	// cacheBookmarkOffline() below), but its <img src> attributes still
	// point at the live origin site; with no connectivity those rendered
	// as broken images even though the surrounding text displayed fine
	// from cache (issue #236). IndexedDB natively stores Blobs, the same
	// mechanism the offline-creation feature already relies on for picked
	// media. Best-effort per image: a failed fetch just leaves that one
	// image pointing at its original, still-offline-broken URL — never
	// blocks caching the rest of the item.
	async function cacheContentImages(html) {
		const urls = extractImageUrls(html);
		const images = {};
		await Promise.all(
			urls.map(async (url) => {
				try {
					const response = await fetch(url);
					if (response.ok) {
						images[url] = await response.blob();
					}
				} catch (err) {
					// Best-effort — see this function's own docblock.
				}
			})
		);
		return images;
	}

	// Fetches one bookmarked item's full content and caches it. `screen`
	// (when given) supplies the item's own already-fetched Timeline
	// summary via its `_byMarkId`/`_bySubId` map (see rememberItem()) — no
	// extra request for that half; a `null` summary (screen not given, or
	// the item wasn't in either map — e.g. the boot-time sync below,
	// working from GET /timeline?bookmarked=1 directly) is fine too,
	// since renderFeedItem() only needs prepare_mark_summary()'s/
	// prepare_subscription_post_summary()'s own shape, which the boot sync
	// already has from that same request.
	async function cacheBookmarkOffline(screen, kind, id, summary) {
		try {
			const item =
				summary ||
				(screen && screen._byMarkId && screen._byMarkId.get(String(id))) ||
				(screen && screen._bySubId && screen._bySubId.get(String(id))) ||
				null;
			const isSubscriptionPost = 'subscription_post' === kind;
			const response = await apiGet(
				isSubscriptionPost ? 'subscription-posts/' + id : 'marks/' + id + '/content'
			);
			const content = isSubscriptionPost ? response.body_content || '' : response.content || '';
			const images = await cacheContentImages(content);
			await putCachedBookmark({
				id: Number(id),
				kind,
				item,
				content,
				images,
				cachedAt: Date.now(),
			});
		} catch (err) {
			// Best-effort — see this section's own docblock above.
		}
	}

	// Boot-time sync (issue #193's own explicit requirement: "whenever
	// someone logs into their site and opens Daymark on a new device, we
	// should ensure we cache these Bookmarked posts' content"). Fetches
	// the user's current bookmark list from the server (the source of
	// truth — bookmarks are per-user server state, not local) and caches
	// full content for anything not already cached; also prunes any
	// locally cached item that's no longer bookmarked (e.g. unbookmarked
	// from a different device since this one's last sync). Never awaited
	// by boot() itself — see its own call site — so a slow or offline
	// sync never delays the app shell's first render.
	async function syncBookmarkCache() {
		// GET /timeline caps per_page at 50 server-side (MAX_PER_PAGE), so a
		// user with more than 50 bookmarks needs more than one page — loop
		// until a short page confirms there's nothing left, capped at 10
		// pages (500 items, matching the server's own MAX_TIMELINE_QUERY_ITEMS)
		// as a sanity ceiling against ever looping unbounded.
		const perPage = 50;
		const items = [];
		try {
			for (let page = 1; page <= 10; page += 1) {
				const batch = await apiGet(
					'timeline?per_page=' + perPage + '&page=' + page + '&bookmarked=1'
				);
				const arr = Array.isArray(batch) ? batch : [];
				items.push(...arr);
				if (arr.length < perPage) {
					break;
				}
			}
		} catch (err) {
			return;
		}
		const stillBookmarkedIds = new Set(items.map((item) => Number(item.id)));

		const cached = await getAllCachedBookmarks();
		const alreadyCachedIds = new Set(cached.map((record) => record.id));

		await Promise.all(
			cached
				.filter((record) => !stillBookmarkedIds.has(record.id))
				.map((record) => removeCachedBookmarkOffline(record.id))
		);

		await Promise.all(
			items
				.filter((item) => !alreadyCachedIds.has(Number(item.id)))
				.map((item) =>
					cacheBookmarkOffline(
						null,
						'subscription_post' === item.item_type ? 'subscription_post' : 'mark',
						item.id,
						item
					)
				)
		);
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
		// The author's chosen order for already-attached media (issue #250)
		// — state.editing.media's own array order, which moveExistingMedia()
		// mutates in place. Empty when there's nothing existing to reorder.
		const mediaOrder = [];
		if (state.editing && Array.isArray(state.editing.media)) {
			state.editing.media.forEach((m) => {
				if (m.kind === 'image') {
					existingAlt[m.id] = m.alt || '';
				}
				mediaOrder.push(m.id);
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
			// Quiet metadata capture: carried along unchanged by every payload
			// consumer (a live request, autosave, and an offline-queue replay
			// all share this one builder), so it survives exactly like every
			// other composer field does.
			capturedAt: state.capturedAt || null,
			location: state.location ? Object.assign({}, state.location) : null,
			inReplyTo: state.replyTo ? state.replyTo.url : '',
			newFiles,
			existingAlt,
			mediaOrder,
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
		if (payload.mediaOrder && payload.mediaOrder.length) {
			formData.append('media_order', JSON.stringify(payload.mediaOrder));
		}
		payload.tags.forEach((tag) => formData.append('tags[]', tag));
		if (payload.capturedAt) {
			formData.append('captured_at', payload.capturedAt);
		}
		if (payload.location) {
			formData.append('location_lat', String(payload.location.lat));
			formData.append('location_lng', String(payload.location.lng));
			if (payload.location.accuracy !== undefined && payload.location.accuracy !== null) {
				formData.append('location_accuracy', String(payload.location.accuracy));
			}
		}
		if (payload.inReplyTo) {
			formData.append('in_reply_to', payload.inReplyTo);
		}
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

	// Shared-device safeguard (issue #126): asks the active service worker
	// to clear its cached offline-fallback shell/config before a deliberate
	// Log out proceeds to WordPress's own logout URL, so a stale cached
	// config (branding, connector/category lists) never lingers into
	// whoever uses this browser next. Best-effort with a short timeout —
	// no controller yet, no response in time, or the feature simply
	// unsupported all resolve the same way: proceed with the real logout
	// regardless, never block it on this.
	function clearOfflineShellCache() {
		return new Promise((resolve) => {
			const controller = navigator.serviceWorker && navigator.serviceWorker.controller;
			if (!controller) {
				resolve();
				return;
			}
			const channel = new MessageChannel();
			const timer = setTimeout(resolve, 800);
			channel.port1.onmessage = () => {
				clearTimeout(timer);
				resolve();
			};
			controller.postMessage({ type: 'daymark-clear-offline-cache' }, [channel.port2]);
		});
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
		// Quiet metadata capture: this session's original signals travel
		// with the queued payload — restore them rather than re-resolving
		// (a fresh "now" or a fresh geolocation prompt would misrepresent
		// when/where this Mark was actually created).
		state.capturedAt = payload.capturedAt || null;
		state.location = payload.location || null;
		state.locationRequested = true;
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

	// --- Quiet AI tag suggestion ---
	//
	// "Leverage AI to generate tags. Don't make users fill those in." A
	// background, invisible counterpart to the manual "AI Assist" sheet's
	// own tag suggestions: no spinner, no toast, never interrupts, never
	// blocks Publish (it's simply not awaited by anything in the publish
	// path). Fires at most once per composing session — the moment it
	// successfully applies a result, state.tags is no longer empty, which
	// is itself the guard against firing again.
	const QUIET_TAGS_DEBOUNCE_MS = 2500; // Mirrors AUTOSAVE_DEBOUNCE_MS.
	let quietTagsTimer = null;
	let quietTagsInFlight = false;

	function scheduleQuietTagSuggestion() {
		if (state.tagsEdited || state.tags.length) {
			return; // Author already has tags — nothing quiet to add.
		}
		clearTimeout(quietTagsTimer);
		quietTagsTimer = setTimeout(runQuietTagSuggestion, QUIET_TAGS_DEBOUNCE_MS);
	}

	async function runQuietTagSuggestion() {
		if (quietTagsInFlight || state.tagsEdited || state.tags.length) {
			return;
		}
		// Same grounding bar as the manual AI Assist sheet/describe_context():
		// a caption, or at least one picked media file.
		if (!state.caption.trim() && !state.files.length) {
			return;
		}
		const generation = composerGeneration;
		quietTagsInFlight = true;
		try {
			const result = await apiPost('ai/tags', {
				text: state.caption,
				primary_type: effectiveType(),
				transcript: state.transcript || '',
			});
			// The composer may have moved on to a different Mark entirely, or
			// the author may have added/accepted tags themselves while this
			// was in flight — either way, never stomp on the current state.
			if (generation !== composerGeneration || state.tagsEdited || state.tags.length) {
				return;
			}
			const tags = Array.isArray(result.tags) ? result.tags.map((t) => String(t)) : [];
			if (!tags.length) {
				return;
			}
			state.tags = tags;
			// Keep the AI Assist sheet in sync if it happens to already be
			// open, reusing its own render call rather than duplicating it.
			if (AIAssistSheet.el && !AIAssistSheet.el.hidden) {
				AIAssistSheet.tags = tags.slice();
				AIAssistSheet.renderTags();
			}
		} catch (err) {
			// Best-effort and invisible — never surface an error here.
		} finally {
			quietTagsInFlight = false;
		}
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

	// Fetches a draft and populates the composer's state for it — shared by
	// openDraft() (continues into #create) and openDraftAndPublish() (issue
	// #265, skips straight to #publish), so the two entry points can never
	// populate state differently.
	async function loadDraftIntoState(id) {
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
		// No cached title to show for a resumed draft — the chip falls back
		// to the URL itself (see CreateScreen.render()).
		state.replyTo = mark.in_reply_to ? { url: mark.in_reply_to, title: '' } : null;
		return mark;
	}

	// Load a draft into the composer for continued editing.
	async function openDraft(id) {
		await loadDraftIntoState(id);
		navigate('#create');
	}

	// One-tap "Publish" from the Drafts list (issue #265): populates the
	// composer's state exactly like openDraft() does, but skips #create
	// entirely for a draft that's already ready — the same readiness bar
	// showScreen()'s own #publish guard already enforces (hasComposerContent()).
	// A draft with neither a caption nor any media (existing or newly picked)
	// falls back to #create instead, same as tapping the row/Edit would.
	async function openDraftAndPublish(id) {
		await loadDraftIntoState(id);
		navigate(hasComposerContent() ? '#publish' : '#create');
	}

	// Whether the composer currently has enough to publish: a caption, a
	// newly picked file, or (a draft resumed via loadDraftIntoState())
	// already-attached media — the last of these was missing from this
	// check for a long time (issue #265), so a media-only draft with no
	// caption could silently bounce from #publish back to #create.
	function hasComposerContent() {
		return (
			Boolean(state.caption.trim()) ||
			state.files.length > 0 ||
			Boolean(state.editing && state.editing.media && state.editing.media.length)
		);
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
		let message = sprintf(
			/* translators: %d: HTTP status code */
			__('Request failed (%d)', 'daymark'),
			res.status
		);
		let code = '';
		let data = null;
		try {
			const body = await res.json();
			if (body && body.message) {
				message = body.message;
			}
			if (body && body.code) {
				code = String(body.code);
			}
			if (body && body.data && typeof body.data === 'object') {
				data = body.data;
			}
		} catch (err) {
			// Keep the generic message.
		}
		const error = new Error(message);
		error.status = res.status;
		// WP core's own machine-readable error code (e.g.
		// 'rest_cookie_invalid_nonce') — exposed so a caller can recognize a
		// specific failure without matching on the human-readable message
		// text, which isn't meant to be parsed (see isAuthExpiredError()).
		error.code = code;
		// Some WP_Error responses (e.g. the subscription manual-refresh
		// cooldown) attach a retry_after (seconds) — exposed here so a
		// caller can distinguish "rate limited, try again later" from any
		// other failure without parsing the message text.
		if (data && typeof data.retry_after !== 'undefined') {
			error.retryAfter = Number(data.retry_after);
		}
		return error;
	}

	// A stale nonce baked into the app-shell page at load time (e.g. a
	// home-screen-installed PWA resumed from a long background suspension
	// instead of a fresh navigation) fails every REST call with WP core's
	// own 'rest_cookie_invalid_nonce' error — a real, actionable "your
	// session expired, reload" case, not a generic request failure. Callers
	// that surface API errors to the user check this first so they can show
	// something a reader can actually act on instead of a raw WP error
	// string like "Cookie check failed."
	function isAuthExpiredError(err) {
		return Boolean(err) && 'rest_cookie_invalid_nonce' === err.code;
	}

	function authExpiredErrorHtml() {
		return (
			'<p class="daymark-error" role="alert">' +
			esc(__('Your session has expired.', 'daymark')) +
			' <button type="button" class="daymark-btn--text daymark-btn" data-reload-app>' +
			esc(__('Reload', 'daymark')) +
			'</button></p>'
		);
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
		if (target === '#publish' && !hasComposerContent()) {
			target = '#create';
		}
		if (target === '#success' && !state.lastPublish) {
			target = '#home';
		}
		if (target === '#post' && !pendingPostView) {
			target = '#home';
		}

		AIAssistSheet.hide(false);
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
		{ key: 'home', hash: '#home', label: __('Timeline', 'daymark'), glyph: TIMELINE_GLYPH },
		{ key: 'explore', hash: '#explore', label: __('Explore', 'daymark'), glyph: EXPLORE_GLYPH },
		{ key: 'search', hash: '#search', label: __('Search', 'daymark'), glyph: SEARCH_GLYPH },
		{ key: 'me', hash: '#me', label: __('Me', 'daymark'), glyph: ME_GLYPH },
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
				`<button type="button" class="daymark-launcher__bubble" data-launcher-type="${type}" tabindex="-1" aria-hidden="true" aria-label="${esc(
					sprintf(
						/* translators: %s: Mark type label (e.g. "Image") */
						__('New %s Mark', 'daymark'),
						TYPE_LABELS[type]
					)
				)}">${launcherIcon(TYPE_ICONS[type])}</button>`
		).join('');
		const launcher = `<div class="daymark-launcher" data-launcher>
			<div class="daymark-launcher__scrim" aria-hidden="true"></div>
			<div class="daymark-launcher__bubbles" data-launcher-bubbles>${bubbles}</div>
			<button type="button" class="daymark-launcher__btn" data-action="new-mark" aria-label="${esc(
				__('New Mark', 'daymark')
			)}" aria-expanded="false">${launcherIcon(PLUS_GLYPH)}</button>
		</div>`;
		return `<footer class="daymark-homefooter"><nav class="daymark-bottomnav" aria-label="Daymark">${before}${launcher}${after}</nav></footer>`;
	}

	// The Home launcher: tapping "+ New Mark" fans out Image/Video/Audio/
	// Note bubbles (icon-only arc); tapping a bubble seeds the composer's
	// pendingType and jumps to #create. `screen` is whichever screen object
	// owns the footer currently on the page (Home/Explore/Search/Me all
	// call this from their own bindEvents()) — its openLauncher/closeLauncher
	// and _launcherOpen get attached here, same as bindChromeAutoHide()
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
	// for content without losing the controls. The header does the
	// opposite: it hides while scrolling up and reappears while scrolling
	// down, so the two chrome bars are never both reclaiming space at the
	// same time — scrolling down (reading further into the list) only
	// ever costs the footer's height, scrolling back up toward the top
	// only ever costs the header's. Removing the previous listener first
	// keeps these from stacking across repeated renders of the same
	// screen, same as the document click/keydown pair in
	// bindDismissible()/clearDismissible().
	function bindChromeAutoHide(screen) {
		const footer = root.querySelector('.daymark-homefooter');
		const header = root.querySelector('.daymark-topbar');
		if (!footer && !header) {
			return;
		}
		const launcher = footer && footer.querySelector('[data-launcher]');

		if (screen._onScroll) {
			window.removeEventListener('scroll', screen._onScroll);
		}
		if (screen._onChromeFocusIn) {
			if (footer) {
				footer.removeEventListener('focusin', screen._onChromeFocusIn);
			}
			if (header) {
				header.removeEventListener('focusin', screen._onChromeFocusIn);
			}
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

				// Always show both near the top, regardless of direction.
				if (y < 80) {
					if (footer) {
						footer.classList.remove('is-footer-hidden');
					}
					if (header) {
						header.classList.remove('is-header-hidden');
					}
					lastY = y;
					ticking = false;
					return;
				}

				// Ignore small jitters either direction so the chrome
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

				if (footer) {
					footer.classList.toggle('is-footer-hidden', delta > 0);
				}
				if (header) {
					header.classList.toggle('is-header-hidden', delta < 0);
				}
				lastY = y;
				ticking = false;
			});
		};

		// A keyboard user tabbing to a control in either bar must always
		// find it visible, regardless of scroll position — neither is ever
		// aria-hidden, so this just keeps sight in sync with focus.
		screen._onChromeFocusIn = () => {
			if (footer) {
				footer.classList.remove('is-footer-hidden');
			}
			if (header) {
				header.classList.remove('is-header-hidden');
			}
		};

		window.addEventListener('scroll', screen._onScroll, { passive: true });
		if (footer) {
			footer.addEventListener('focusin', screen._onChromeFocusIn);
		}
		if (header) {
			header.addEventListener('focusin', screen._onChromeFocusIn);
		}
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

	// Wires the launcher and the header/footer scroll auto-hide for a
	// screen that has nothing else to add to bindDismissible() —
	// Explore/Search/Me. Home calls bindLauncher()/bindChromeAutoHide()
	// directly instead, so it can merge navFooterDismissEntry() into its
	// own larger dismissible list.
	function bindNavFooter(screen) {
		bindLauncher(screen);
		bindChromeAutoHide(screen);
	}

	// The dismissible entry every feed-list screen (Home, Search) needs: an
	// outside click or Escape closes whichever ⋯ actions menu is open. A
	// Mark's or subscription post's site icon has no popover of its own to
	// close (see renderSiteIconButton() below) — a plain click fires
	// applySourceFilter() straight away — so this only ever needs to guard
	// the ⋯ menu's [data-menu] show/hide machinery and the routing
	// popover's [data-routing-panel] (issue #255), both closed together by
	// closeItemMenus(). The routing toggle and its panel live in different
	// parts of the item-wrap (see renderMarkItem()'s own comment on why),
	// so both are named directly here rather than a single shared wrapper.
	function itemMenusDismissEntry() {
		return {
			selector: '[data-actions], [data-routing-toggle], [data-routing-panel]',
			close: () => closeItemMenus(),
		};
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
		return `<option value="">${esc(__('All', 'daymark'))}</option><option value="mine">${esc(
			__('My Marks', 'daymark')
		)}</option>${subscriptionOptions}`;
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
		return __('this site', 'daymark');
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
	//
	// `title` carries the site's name and URL as a native on-hover tooltip
	// (issue #181) — deliberately separate from `aria-label`, which
	// describes the button's *action* ("Filter Timeline to..."), not the
	// site itself; a screen reader announces the action, a sighted hover
	// sees what site this actually is. `\n` renders as a real line break in
	// every browser's native title tooltip, so the two read as separate
	// lines rather than one run-on sentence.
	function renderSiteIconButton({ iconSrc, iconAlt, ariaLabel, filterValue, siteUrl }) {
		const glyph = (iconAlt || '?').charAt(0).toUpperCase();
		const icon = iconSrc
			? imgWithFallback(iconSrc, 'daymark-recent__siteiconimg', glyph)
			: `<span class="daymark-recent__siteiconimg daymark-recent__siteiconimg--glyph" aria-hidden="true">${esc(
					glyph
			  )}</span>`;
		const tooltip = siteUrl ? `${iconAlt || ''}\n${siteUrl}` : iconAlt || '';
		return `
			<div class="daymark-recent__siteicon">
				<button type="button" class="daymark-recent__siteiconbtn" data-filter-site="${esc(
					filterValue
				)}" aria-label="${esc(ariaLabel)}" title="${esc(tooltip)}">
					${icon}
				</button>
			</div>`;
	}

	// One Mark's card markup — the thumbnail-or-glyph + title + meta + stats
	// core (renderMarkCore()) wrapped in its own tap target plus, for a
	// Draft only, the shared ⋯ edit/delete actions menu. Used everywhere a
	// Mark appears in a list: Home's Recent/Drafts, Search's results.
	function renderMarkItem(item) {
		// Drafts look identical to published Marks otherwise — and their
		// permalinks are invisible to visitors — so tapping one reopens the
		// composer instead of opening it on the post-view screen.
		// (renderMarkCore() handles the visible "Draft" chip itself.) A
		// published item — a true Mark or an ordinary block-editor post
		// alike — opens its own content on the full-screen post view
		// instead (see openPostView()/onFeedListClick()), the same as a
		// subscription post's card; it never navigates away to the real
		// permalink.
		const title = item.title || __('Untitled Mark', 'daymark');
		const isDraft = item.status && 'publish' !== item.status;
		const editAttr = isDraft ? ` data-edit-draft="${esc(String(item.id))}"` : '';
		const id = esc(String(item.id));
		const kind = resolveCardKind(item);
		// The ⋯ Edit/Delete menu is a Draft-only affordance: once a Mark is
		// published, in-app editing/deleting isn't offered at all — wp-admin
		// is the place to adjust or remove a published Mark (see the
		// "Published Marks are read-only in-app" decision, CLAUDE.md).
		// A Draft is always the author's own unpublished work — there is no
		// separate site to filter to, so it's the one item that skips the
		// site icon.
		// The site's own icon (config.siteIconUrl, resolved server-side by
		// Daymark_Routes::icon_url() — Site Icon, else Daymark's bundled
		// icon), not the logged-in user's personal Gravatar: a subscription
		// post's leading icon is the *source site's* icon, so a Mark's own
		// leading icon identifies the same way, by site rather than by author.
		const siteIcon = isDraft
			? ''
			: renderSiteIconButton({
					iconSrc: config.siteIconUrl || '',
					iconAlt: config.siteTitle || __('Site', 'daymark'),
					ariaLabel: __('Filter Timeline to your Marks', 'daymark'),
					filterValue: 'mine',
					siteUrl: config.siteUrl || '',
			  });
		const actions = !isDraft
			? ''
			: `<div class="daymark-recent__actions" data-actions>
					<button type="button" class="daymark-recent__menubtn" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="${esc(
						sprintf(
							/* translators: %s: Mark title */
							__('Actions for %s', 'daymark'),
							title
						)
					)}">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
					</button>
					<div class="daymark-menu" data-menu role="menu" aria-label="${esc(__('Mark actions', 'daymark'))}" hidden>
						<div class="daymark-menu__actions" data-menu-actions>
							<button type="button" class="daymark-menu__item" data-menu-edit role="menuitem">${esc(
								__('Edit', 'daymark')
							)}</button>
							<button type="button" class="daymark-menu__item" data-menu-publish role="menuitem">${esc(
								__('Publish', 'daymark')
							)}</button>
							<button type="button" class="daymark-menu__item daymark-menu__item--danger" data-menu-delete role="menuitem">${esc(
								__('Delete', 'daymark')
							)}</button>
						</div>
						<div class="daymark-menu__confirm" data-menu-confirm hidden>
							<p class="daymark-menu__confirmtext">${esc(__('Delete this Mark? It’ll move to Trash.', 'daymark'))}</p>
							<div class="daymark-menu__confirmactions">
								<button type="button" class="daymark-btn daymark-btn--danger" data-menu-delete-confirm>${esc(
									__('Delete', 'daymark')
								)}</button>
								<button type="button" class="daymark-btn daymark-btn--secondary" data-menu-delete-cancel>${esc(
									__('Cancel', 'daymark')
								)}</button>
							</div>
							<p class="daymark-menu__status" data-menu-status aria-live="polite"></p>
						</div>
					</div>
				</div>`;
		const card = isDraft
			? `<a class="daymark-recent__item daymark-recent__item--${esc(
					kind
			  )}" href="#create"${editAttr}>${renderMarkCore(item)}</a>`
			: `<button type="button" class="daymark-recent__item daymark-recent__item--button daymark-recent__item--${esc(
					kind
			  )}" data-expand-post="${id}">${renderMarkCore(item)}</button>`;
		// The routing popover's own panel — a sibling of the card button, not
		// nested inside it: a target's own link can't validly live inside
		// another <button>. Gated on the same condition as
		// renderRoutingToggle() itself, so there's no unused empty panel for
		// a Mark with nothing to route.
		const hasRouting = !isDraft && item.syndication_status && 'not_attempted' !== item.syndication_status;
		return `
			<div class="daymark-recent__item-wrap" data-item="${id}">
				${siteIcon}
				${renderTypeIcon(kind)}
				${card}
				${actions}
				${hasRouting ? `<div class="daymark-recent__routing" data-routing-panel="${id}" hidden></div>` : ''}
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

	// Renders a page of feed items with relative-period group headers
	// (timelinePeriod(), issue #145) interleaved ahead of the first item in
	// each new bucket — Home's Timeline only (renderMarkItem()/
	// renderSubscriptionPostCard() elsewhere, e.g. Search's results, are
	// unaffected). `screen._lastGroupKey` carries the most recent bucket
	// across calls so loadMorePage()'s own appended page continues the same
	// run of headers loadRecent() started, rather than repeating one for a
	// bucket the list is already mid-way through — loadRecent() resets it
	// to null before rendering page 1, loadMorePage() deliberately doesn't.
	// An item with no parseable date is rendered with no header of its own
	// and leaves the current bucket untouched, rather than guessing one.
	function renderFeedItemsWithGroups(screen, items) {
		let html = '';
		items.forEach((item) => {
			const date = item.date ? parseDate(item.date) : null;
			if (date) {
				const period = timelinePeriod(date);
				if (period.key !== screen._lastGroupKey) {
					html += `<h3 class="daymark-section-heading daymark-recent__groupheader">${esc(
						period.label
					)}</h3>`;
					screen._lastGroupKey = period.key;
				}
			}
			html += renderFeedItem(item);
		});
		return html;
	}

	// Track every feed item by id so a card tap can hand openPostView()
	// everything it already has without re-querying the DOM. A subscription
	// post and a Mark/ordinary post keep separate Maps (their ids come from
	// different post types and could otherwise collide) — `screen` owns
	// both (Home and Search each keep their own pair).
	function rememberItem(screen, item) {
		if (!item) {
			return;
		}
		if ('subscription_post' === item.item_type) {
			screen._bySubId.set(String(item.id), item);
		} else {
			screen._byMarkId.set(String(item.id), item);
		}
	}

	// --- Scroll-triggered rehydration of pruned subscription-post content
	// (issue #93), Home's own Timeline only ---
	//
	// A subscription post's own content is only ever fetched on demand
	// (content_state !== 'full' — never fetched, or pruned by
	// Daymark_Subscription_Poller::prune_subscription() after aging out) via
	// GET /subscription-posts/{id}, previously only reached by an explicit
	// click-through (openPostView()). This proactively fires that exact same
	// fetch, unmodified, just before a still-pruned card scrolls into view,
	// so opening it moments later renders instantly from the now-'full'
	// server-side cache instead of waiting on a live external fetch.
	//
	// Deliberately no `refresh` param and no new server-side code at all:
	// GET /subscription-posts/{id} already only fetches live when
	// content_state !== 'full' (see get_subscription_post_full_content()'s
	// own docblock), so a scroll-triggered call is byte-for-byte the same
	// request a click-through already makes — it inherits that endpoint's
	// real, already-enforced throttle
	// (Daymark_Rate_Limiter::ACTION_SUBSCRIPTION_POST_FETCH, a per-user
	// 20-per-5-minute budget shared with explicit click-throughs) rather
	// than a new one. Note this is *not* the same thing as the
	// per-subscription 15-minute cooldown that guards a whole-feed manual
	// refresh (Daymark_Subscription_Poller::manual_refresh(), a different
	// action reached via POST /subscriptions/{id}/refresh) — no such
	// per-subscription cooldown exists on this fetch today, so the shared
	// per-user limiter above is the one real throttle there is to reuse.
	//
	// A single in-flight request at a time (queued, never parallel) keeps a
	// fast scroll past many pruned cards from bursting through that shared
	// per-user budget in one go and starving a real click-through right
	// after; hitting the limiter's own 429 pauses the queue for its
	// reported retry_after rather than adding a second, separate cooldown.
	// A failed attempt (rate-limited or a genuine fetch failure) never
	// retries in a loop and never surfaces an error — the card is simply
	// left exactly as its existing pruned/placeholder rendering already
	// looks (renderSubscriptionPostCard() doesn't vary by content_state at
	// all), same graceful-failure contract a failed click-through already has.

	const REHYDRATE_LOOKAHEAD = '600px';

	// (Re)arms the shared observer with every not-yet-'full', not-yet-
	// attempted `[data-subpost]` card currently in `container` — safe to
	// call repeatedly (loadRecent()'s first page, every loadMorePage() page
	// after it): observing an already-observed element is a no-op, and the
	// content_state/attempted checks below skip everything else.
	function observeRehydrateCandidates(screen, container) {
		if (!('IntersectionObserver' in window) || !container) {
			return;
		}
		if (!screen._rehydrateObserver) {
			screen._rehydrateObserver = new IntersectionObserver(
				(entries) => {
					entries.forEach((entry) => {
						if (!entry.isIntersecting) {
							return;
						}
						screen._rehydrateObserver.unobserve(entry.target);
						const id = entry.target.getAttribute('data-subpost');
						if (id && !screen._rehydrateAttempted.has(id) && !screen._rehydrateQueue.includes(id)) {
							screen._rehydrateQueue.push(id);
							drainRehydrateQueue(screen);
						}
					});
				},
				{ rootMargin: REHYDRATE_LOOKAHEAD }
			);
		}
		container.querySelectorAll('[data-subpost]').forEach((el) => {
			const id = el.getAttribute('data-subpost');
			const item = id ? screen._bySubId.get(id) : null;
			if (!item || 'full' === item.content_state || screen._rehydrateAttempted.has(id)) {
				return;
			}
			screen._rehydrateObserver.observe(el);
		});
	}

	// Disconnects the observer and clears its bookkeeping — called from
	// loadRecent() alongside teardownObserver() since a fresh load means a
	// fresh set of candidate cards (same reset loadRecent() already does for
	// _bySubId/_byMarkId).
	function teardownRehydrateObserver(screen) {
		if (screen._rehydrateObserver) {
			screen._rehydrateObserver.disconnect();
			screen._rehydrateObserver = null;
		}
		screen._rehydrateAttempted = new Set();
		screen._rehydrateQueue = [];
		screen._rehydrateInFlight = false;
		screen._rehydrateBackoffUntil = 0;
	}

	// Drains one item at a time from the rehydrate queue — never more than
	// one fetch in flight, and paused entirely until _rehydrateBackoffUntil
	// once the shared per-user limiter reports a 429.
	async function drainRehydrateQueue(screen) {
		if (screen._rehydrateInFlight || Date.now() < screen._rehydrateBackoffUntil) {
			return;
		}
		const id = screen._rehydrateQueue.shift();
		if (!id) {
			return;
		}
		screen._rehydrateInFlight = true;
		try {
			await apiGet('subscription-posts/' + id);
			screen._rehydrateAttempted.add(id);
			const item = screen._bySubId.get(id);
			if (item) {
				item.content_state = 'full';
			}
		} catch (err) {
			if (err && 429 === err.status) {
				// Rate-limited, not a real failure: leave it out of
				// _rehydrateAttempted so a later observeRehydrateCandidates()
				// call (the next page, or a fresh loadRecent()) can still
				// pick it back up once the backoff clears.
				const waitMs = (Number(err.retryAfter) || 60) * 1000;
				screen._rehydrateBackoffUntil = Date.now() + waitMs;
			} else {
				screen._rehydrateAttempted.add(id);
			}
		} finally {
			screen._rehydrateInFlight = false;
			drainRehydrateQueue(screen);
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
		// The routing popover (issue #255) — a sibling of its own toggle,
		// not nested inside a shared wrapper (see renderMarkItem()), so its
		// toggle is found via the shared item-wrap ancestor instead of
		// menu.parentElement the way the ⋯ menu's toggle is found above.
		root.querySelectorAll('[data-routing-panel]').forEach((panel) => {
			panel.hidden = true;
			const wrap = panel.closest('.daymark-recent__item-wrap');
			const toggle = wrap ? wrap.querySelector('[data-routing-toggle]') : null;
			if (toggle) {
				toggle.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function onFeedListClick(screen, event) {
		const target = event.target;

		// The "session expired" error state's own Reload button (see
		// authExpiredErrorHtml()) — a plain full-page reload is the fix,
		// since it's the one thing that fetches a fresh nonce.
		if (target.closest('[data-reload-app]')) {
			window.location.reload();
			return;
		}

		// The Bookmark toggle — checked first so tapping it never also
		// triggers the card's own expand-post/subpost tap (both live inside
		// the same clickable card; see renderBookmarkToggle()'s own
		// docblock for why this is a span[role="button"], not a real
		// nested <button>).
		const bookmarkToggle = target.closest('[data-bookmark-toggle]');
		if (bookmarkToggle) {
			event.preventDefault();
			event.stopPropagation();
			toggleBookmark(screen, bookmarkToggle);
			return;
		}

		// The Like/Repost toggles — same reasoning/placement as Bookmark
		// above.
		const likeToggle = target.closest('[data-like-toggle]');
		if (likeToggle) {
			event.preventDefault();
			event.stopPropagation();
			toggleLike(screen, likeToggle);
			return;
		}

		const repostToggle = target.closest('[data-repost-toggle]');
		if (repostToggle) {
			event.preventDefault();
			event.stopPropagation();
			toggleRepost(screen, repostToggle);
			return;
		}

		// The "open original" toggle — same reasoning/placement as the
		// Bookmark toggle above; the permanent replacement for the old
		// "View full post"/"View original" footer link, so this now works
		// even on a collapsed card, not only once expanded.
		const externalLinkToggle = target.closest('[data-external-link]');
		if (externalLinkToggle) {
			event.preventDefault();
			event.stopPropagation();
			openExternalLink(externalLinkToggle);
			return;
		}

		// The routing toggle — same reasoning/placement as the Bookmark
		// toggle above; opens/closes the sibling "where did this go" panel
		// (see toggleRoutingPanel()).
		const routingToggle = target.closest('[data-routing-toggle]');
		if (routingToggle) {
			event.preventDefault();
			event.stopPropagation();
			toggleRoutingPanel(screen, routingToggle);
			return;
		}

		// The Share toggle — same reasoning/placement as the Bookmark
		// toggle just above.
		const shareToggle = target.closest('[data-share-toggle]');
		if (shareToggle) {
			event.preventDefault();
			event.stopPropagation();
			shareItem(screen, shareToggle);
			return;
		}

		// A subscription-post card's tap: open its full content on the
		// dedicated post-view screen (see openPostView()) — fetched
		// externally via the click-through endpoint.
		const subTrigger = target.closest('[data-subpost]');
		if (subTrigger) {
			const item = screen._bySubId.get(subTrigger.getAttribute('data-subpost'));
			if (item) {
				openPostView('sub', item);
			}
			return;
		}

		// A Mark or ordinary post's own tap: same post-view screen, sourced
		// straight from this site's own database instead.
		const markTrigger = target.closest('[data-expand-post]');
		if (markTrigger) {
			const item = screen._byMarkId.get(markTrigger.getAttribute('data-expand-post'));
			if (item) {
				openPostView('mark', item);
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

		// One-tap "Publish" from the Drafts list (issue #265) — skips #create
		// entirely for a draft that's already ready; falls back to it
		// otherwise (openDraftAndPublish()'s own hasComposerContent() check).
		const publishNow = target.closest('[data-menu-publish]');
		if (publishNow) {
			event.preventDefault();
			const wrap = publishNow.closest('[data-item]');
			closeItemMenus();
			if (wrap) {
				openDraftAndPublish(wrap.getAttribute('data-item')).catch(() => {});
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

	// Enter/Space activation for the Bookmark toggle's span[role="button"]
	// — a real <button> gets this for free; this one doesn't, so it needs
	// its own keydown handling, bound alongside onFeedListClick() on every
	// feed-list container.
	function onFeedListKeydown(screen, event) {
		if ('Enter' !== event.key && ' ' !== event.key && 'Spacebar' !== event.key) {
			return;
		}
		const bookmarkToggle = event.target.closest('[data-bookmark-toggle]');
		if (bookmarkToggle) {
			event.preventDefault();
			toggleBookmark(screen, bookmarkToggle);
			return;
		}
		const likeToggle = event.target.closest('[data-like-toggle]');
		if (likeToggle) {
			event.preventDefault();
			toggleLike(screen, likeToggle);
			return;
		}
		const repostToggle = event.target.closest('[data-repost-toggle]');
		if (repostToggle) {
			event.preventDefault();
			toggleRepost(screen, repostToggle);
			return;
		}
		const externalLinkToggle = event.target.closest('[data-external-link]');
		if (externalLinkToggle) {
			event.preventDefault();
			openExternalLink(externalLinkToggle);
			return;
		}
		const routingToggle = event.target.closest('[data-routing-toggle]');
		if (routingToggle) {
			event.preventDefault();
			toggleRoutingPanel(screen, routingToggle);
			return;
		}
		const shareToggle = event.target.closest('[data-share-toggle]');
		if (shareToggle) {
			event.preventDefault();
			shareItem(screen, shareToggle);
		}
	}

	function setBookmarkToggleState(trigger, bookmarked) {
		trigger.classList.toggle('daymark-stat--bookmarked', bookmarked);
		trigger.classList.toggle('daymark-stat--active', bookmarked);
		trigger.setAttribute('aria-pressed', bookmarked ? 'true' : 'false');
		trigger.setAttribute(
			'aria-label',
			bookmarked ? __('Remove bookmark', 'daymark') : __('Bookmark for offline viewing', 'daymark')
		);
	}

	// Toggles a bookmark for the Mark or subscription post this trigger
	// belongs to. Optimistic: the icon flips immediately, then rolls back
	// if the request fails. On the Bookmarks-filtered Search view
	// specifically (screen.searchBookmarked), a successful unbookmark also
	// removes the card from view — the filtered list is defined as "only
	// what's currently bookmarked", so a card that's no longer bookmarked
	// has no reason to still be in it.
	async function toggleBookmark(screen, trigger) {
		const id = trigger.getAttribute('data-bookmark-toggle');
		const kind = trigger.getAttribute('data-bookmark-kind') || 'mark';
		if (!id) {
			return;
		}
		const wasBookmarked = 'true' === trigger.getAttribute('aria-pressed');
		const nextBookmarked = !wasBookmarked;
		setBookmarkToggleState(trigger, nextBookmarked);
		try {
			if (nextBookmarked) {
				await apiPost('bookmarks/' + id, {});
				cacheBookmarkOffline(screen, kind, id);
			} else {
				await apiDelete('bookmarks/' + id);
				removeCachedBookmarkOffline(id);
				if (screen && screen.searchBookmarked) {
					const wrap = trigger.closest('.daymark-recent__item-wrap');
					if (wrap) {
						wrap.remove();
					}
				}
			}
		} catch (err) {
			setBookmarkToggleState(trigger, wasBookmarked);
		}
	}

	// Shared optimistic-state flip for the Like/Repost toggles below — same
	// shape as setBookmarkToggleState() above, parameterized on which of the
	// two this is. `markId` is stashed on the element itself so a later undo
	// tap knows which Mark to delete without a second lookup.
	function setEngagementToggleState(trigger, kind, active, markId) {
		const activeClass = 'like' === kind ? 'daymark-stat--liked' : 'daymark-stat--reposted';
		const label = active
			? 'like' === kind
				? __('Unlike', 'daymark')
				: __('Undo repost', 'daymark')
			: 'like' === kind
			? __('Like', 'daymark')
			: __('Repost', 'daymark');
		trigger.classList.toggle(activeClass, active);
		trigger.classList.toggle('daymark-stat--active', active);
		trigger.setAttribute('aria-pressed', active ? 'true' : 'false');
		trigger.setAttribute('aria-label', label);
		trigger.setAttribute('title', label);
		trigger.setAttribute('data-' + kind + '-mark-id', String(markId || 0));
	}

	// The minimal Mark a Like/Repost tap publishes: a plain 'note' with no
	// media, carrying only the one POSSE target-URL field the server needs
	// (like_of/repost_of) to render u-like-of/u-repost-of and let whichever
	// federation plugin the site owner runs discover and act on it — the
	// same "compose a real Mark, let an already-installed plugin do the
	// actual outbound protocol work" pattern the existing Reply action
	// already established (see startReplyToSubscriptionPost()). Deliberately
	// omits targets[]/categories[] entirely (not even an explicit empty
	// array) so this call gets exactly the same type-based default
	// destination/category resolution any other Note Mark would — sending
	// an explicit empty selection would get *remembered* as the user's new
	// Note-type default (see Daymark_Publisher::remember_destination_prefs()),
	// silently overwriting their real preference for a background action
	// they didn't consciously make a destination choice for.
	function buildEngagementFormData(caption, item, targetField) {
		const formData = new FormData();
		formData.append('caption', caption);
		formData.append('primary_type', 'note');
		formData.append('status', 'publish');
		formData.append('ai_assist_used', '0');
		formData.append(targetField, item.permalink);
		return formData;
	}

	// Toggles a Like for the subscription post this trigger belongs to.
	// Optimistic, same as toggleBookmark() above, but — unlike a bookmark,
	// which is pure per-user set membership — liking actually publishes (or,
	// to undo, trashes) a small Mark of the site owner's own; see
	// buildEngagementFormData()'s own docblock for why.
	async function toggleLike(screen, trigger) {
		const id = trigger.getAttribute('data-like-toggle');
		const item = screen && screen._bySubId && screen._bySubId.get(id);
		if (!id || !item || !item.permalink) {
			return;
		}
		const wasLiked = 'true' === trigger.getAttribute('aria-pressed');
		const existingMarkId = trigger.getAttribute('data-like-mark-id') || '0';
		setEngagementToggleState(trigger, 'like', !wasLiked, existingMarkId);
		try {
			if (!wasLiked) {
				const caption = sprintf(
					/* translators: %s: title of the liked post */
					__('Liked "%s"', 'daymark'),
					item.title || item.permalink
				);
				const mark = await apiUpload('marks', buildEngagementFormData(caption, item, 'like_of'));
				setEngagementToggleState(trigger, 'like', true, mark.id);
			} else {
				await apiDelete('marks/' + existingMarkId);
				setEngagementToggleState(trigger, 'like', false, 0);
			}
		} catch (err) {
			setEngagementToggleState(trigger, 'like', wasLiked, existingMarkId);
		}
	}

	// Toggles a Repost for the subscription post this trigger belongs to —
	// same shape/reasoning as toggleLike() above.
	async function toggleRepost(screen, trigger) {
		const id = trigger.getAttribute('data-repost-toggle');
		const item = screen && screen._bySubId && screen._bySubId.get(id);
		if (!id || !item || !item.permalink) {
			return;
		}
		const wasReposted = 'true' === trigger.getAttribute('aria-pressed');
		const existingMarkId = trigger.getAttribute('data-repost-mark-id') || '0';
		setEngagementToggleState(trigger, 'repost', !wasReposted, existingMarkId);
		try {
			if (!wasReposted) {
				const caption = sprintf(
					/* translators: %s: title of the reposted post */
					__('Reposted "%s"', 'daymark'),
					item.title || item.permalink
				);
				const mark = await apiUpload('marks', buildEngagementFormData(caption, item, 'repost_of'));
				setEngagementToggleState(trigger, 'repost', true, mark.id);
			} else {
				await apiDelete('marks/' + existingMarkId);
				setEngagementToggleState(trigger, 'repost', false, 0);
			}
		} catch (err) {
			setEngagementToggleState(trigger, 'repost', wasReposted, existingMarkId);
		}
	}

	// Opens a Mark's or a subscription post's real permalink in a new tab
	// — the permanent stat-row replacement for the old "View full
	// post"/"View original" footer link (see expandBodyHtml()'s own
	// docblock). The URL lives directly on the element (data-external-link),
	// unlike Share, which needs a screen lookup for the title too.
	function openExternalLink(trigger) {
		const url = trigger.getAttribute('data-external-link');
		if (url) {
			window.open(url, '_blank', 'noopener');
		}
	}

	// Opens/closes a Mark's "where did this go" popover (issue #255).
	// Fetches per-connector routing detail on first open via GET
	// /marks/{id} — the same endpoint openDraft() already uses for the
	// composer's full payload — and caches the rendered result on the
	// panel itself (data-loaded) so reopening never refetches. Only one
	// item popover (this or the ⋯ menu) is ever open at a time, so this
	// closes whatever else was open first, the same way the ⋯ menu's own
	// toggle handler already does.
	async function toggleRoutingPanel(screen, trigger) {
		const id = trigger.getAttribute('data-routing-toggle');
		const wrap = trigger.closest('.daymark-recent__item-wrap');
		const panel = wrap ? wrap.querySelector('[data-routing-panel]') : null;
		if (!id || !panel) {
			return;
		}
		const wasOpen = !panel.hidden;
		closeItemMenus();
		if (wasOpen) {
			return;
		}
		panel.hidden = false;
		trigger.setAttribute('aria-expanded', 'true');
		if (panel.dataset.loaded) {
			return;
		}
		panel.innerHTML = '<p class="daymark-status">' + esc(__('Loading…', 'daymark')) + '</p>';
		try {
			const mark = await apiGet('marks/' + id);
			panel.innerHTML = routingPanelMarkup(mark);
			panel.dataset.loaded = '1';
		} catch (err) {
			panel.innerHTML =
				'<p class="daymark-error" role="alert">' +
				esc(__('Could not load routing detail.', 'daymark')) +
				'</p>';
		}
	}

	// Builds the popover's content from GET /marks/{id}'s response — reuses
	// the exact daymark-syndication/daymark-chip classes the Success
	// screen's own per-connector status list already established (see
	// SuccessScreen.renderDetail()), so a Mark's routing reads consistently
	// whether you're looking right after publishing or later from the
	// Timeline. "Your site" is always first and always "Published" — the
	// canonical destination is never itself a syndication target.
	function routingPanelMarkup(mark) {
		const targets = Array.isArray(mark.targets) ? mark.targets : [];
		const externalPosts =
			mark.external_posts && 'object' === typeof mark.external_posts ? mark.external_posts : {};
		const siteLink = mark.permalink
			? `<a class="daymark-btn daymark-btn--text" href="${esc(mark.permalink)}" target="_blank" rel="noopener">${esc(
					__('Your site', 'daymark')
			  )}</a>`
			: `<span>${esc(__('Your site', 'daymark'))}</span>`;
		const rows = targets
			.map((connectorId) => {
				const entry = externalPosts[connectorId] || {};
				const label = entry.label || connectorId;
				const modifier = routingChipModifier(entry.status);
				const target = entry.external_url
					? `<a class="daymark-btn daymark-btn--text" href="${esc(
							entry.external_url
					  )}" target="_blank" rel="noopener">${esc(label)}</a>`
					: `<span>${esc(label)}</span>`;
				return `
				<li class="daymark-syndication__item">
					<div class="daymark-syndication__row">
						${target}
						<span class="daymark-chip daymark-chip--${modifier}">${esc(routingStatusLabel(entry.status))}</span>
					</div>
					${syncRecencyMarkup(entry)}
				</li>`;
			})
			.join('');
		return `<ul class="daymark-syndication" aria-label="${esc(__('Where this Mark was routed', 'daymark'))}">
			<li class="daymark-syndication__item">
				<div class="daymark-syndication__row">
					${siteLink}
					<span class="daymark-chip daymark-chip--success">${esc(__('Published', 'daymark'))}</span>
				</div>
			</li>${rows}
		</ul>`;
	}

	// A backflow_supported target's replies are checked on a cadence
	// (hourly cron, plus a freshen when Notifications is viewed) that was
	// previously invisible — issue #258 surfaces it here rather than a new
	// UI element, since this popover is already "where did this go" for a
	// Mark and sync recency is the corresponding "did anything come back"
	// half of that picture. Deliberately just a "last checked" reading, not
	// a cooldown countdown: the underlying mechanism is an anti-hammering
	// window, not a promised schedule, so "checked 2 minutes ago" is the
	// accurate, sufficient signal rather than invented precision.
	function syncRecencyMarkup(entry) {
		if (!entry || !entry.backflow_supported) {
			return '';
		}
		const text = entry.backflow_last_synced_at
			? sprintf(
					/* translators: %s: relative time, e.g. "5 minutes ago" */
					__('Replies last checked %s', 'daymark'),
					relativeTime(entry.backflow_last_synced_at)
			  )
			: __('Replies not checked yet', 'daymark');
		return `<p class="daymark-syndication__recency">${esc(text)}</p>`;
	}

	function routingStatusLabel(status) {
		switch (status) {
			case 'published':
				return __('Published', 'daymark');
			case 'mocked':
				return __('Mocked', 'daymark');
			case 'unsupported':
				return __('Not supported', 'daymark');
			// The target's own connector plugin was deactivated/uninstalled
			// after this was selected (issue #263) — distinct from
			// 'unsupported' (the connector exists but can't represent this
			// Mark's type) so the two read as the different problems they are.
			case 'unavailable':
				return __('Not available', 'daymark');
			case 'failed':
				return __('Failed', 'daymark');
			default:
				return status ? status : __('Unknown', 'daymark');
		}
	}

	function routingChipModifier(status) {
		if ('published' === status) {
			return 'success';
		}
		if ('failed' === status || 'unsupported' === status || 'unavailable' === status) {
			return 'danger';
		}
		return 'muted';
	}

	// Shares a Mark's or a subscription post's real permalink: the OS
	// native share sheet when available (navigator.share() — this already
	// covers "open the local device's share menu" wherever a browser
	// supports it, mobile or desktop, with no separate iOS/Android/desktop
	// branching needed), falling back to a clipboard copy otherwise. A
	// user-cancelled share (AbortError) is normal, not a failure — no
	// fallback, no error shown.
	async function shareItem(screen, trigger) {
		const id = trigger.getAttribute('data-share-toggle');
		if (!id) {
			return;
		}
		const item =
			(screen && screen._byMarkId && screen._byMarkId.get(id)) ||
			(screen && screen._bySubId && screen._bySubId.get(id));
		const url = item && item.permalink;
		if (!url) {
			return;
		}
		if (navigator.share) {
			try {
				await navigator.share({ title: (item && item.title) || '', url });
				return;
			} catch (err) {
				if (err && 'AbortError' === err.name) {
					return;
				}
				// Any other failure (including a browser that advertises
				// navigator.share but rejects this particular call) falls
				// through to the clipboard-copy fallback below.
			}
		}
		await copyLinkToClipboard(url, trigger);
	}

	// Transient inline confirmation right on the tapped icon itself — no
	// separate toast/status region exists for a Timeline card's stat row
	// (unlike full-screen actions elsewhere, which each have their own
	// dedicated aria-live status paragraph). The aria-label/title swap
	// covers assistive tech; the on-screen bubble (see
	// showShareFlashBubble() below) covers a sighted desktop user, since
	// a color-only change with no visible text reads as "nothing
	// happened" on a browser with no navigator.share() (e.g. Firefox,
	// which the clipboard-copy fallback below always runs on) unless
	// they happen to hover the tiny icon afterward to catch the title
	// tooltip.
	function flashShareStatus(trigger, message) {
		const original = trigger.getAttribute('aria-label') || __('Share', 'daymark');
		trigger.setAttribute('aria-label', message);
		trigger.setAttribute('title', message);
		trigger.classList.add('daymark-stat--share-copied');
		showShareFlashBubble(trigger, message);
		window.setTimeout(() => {
			if (trigger.isConnected) {
				trigger.setAttribute('aria-label', original);
				trigger.setAttribute('title', original);
				trigger.classList.remove('daymark-stat--share-copied');
			}
		}, 2000);
	}

	// A small floating label above the Share icon, visible without hovering
	// or a screen reader — appended as the trigger's own child (rather than
	// a sibling in the shared flex row) so its `position: absolute` only
	// ever needs the trigger's own `position: relative`, regardless of
	// whatever row/card layout happens to contain it. Purely decorative
	// (aria-hidden — flashShareStatus()'s aria-label swap already carries
	// the accessible announcement) and self-removing on the same timer,
	// so a rapid double-tap never leaves two stacked bubbles behind.
	function showShareFlashBubble(trigger, message) {
		const existing = trigger.querySelector('.daymark-share-flash');
		if (existing) {
			existing.remove();
		}
		const bubble = document.createElement('span');
		bubble.className = 'daymark-share-flash';
		bubble.setAttribute('aria-hidden', 'true');
		bubble.textContent = message;
		trigger.appendChild(bubble);
		window.setTimeout(() => {
			bubble.remove();
		}, 2000);
	}

	async function copyLinkToClipboard(url, trigger) {
		try {
			await navigator.clipboard.writeText(url);
			flashShareStatus(trigger, __('Link copied', 'daymark'));
		} catch (err) {
			flashShareStatus(trigger, __("Couldn't copy link", 'daymark'));
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
		confirmBtn.textContent = __('Deleting…', 'daymark');
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
			confirmBtn.textContent = __('Delete', 'daymark');
			if (status) {
				status.textContent = sprintf(
					/* translators: %s: error message */
					__('Could not delete. %s', 'daymark'),
					err.message
				);
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
			list.innerHTML =
				'<p class="daymark-empty">' +
				esc(__('Nothing matches. Try a different search or filter.', 'daymark')) +
				'</p>';
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
			list.innerHTML = `<p class="daymark-empty">${sprintf(
				/* translators: 1: "Publish a Mark" link, 2: "subscribe to a site" link */
				__('Nothing here yet. %1$s or %2$s to fill your timeline.', 'daymark'),
				'<a href="#create">' + esc(__('Publish a Mark', 'daymark')) + '</a>',
				`<a href="${esc(config.adminSubscriptionsUrl || '#')}">${esc(
					__('subscribe to a site', 'daymark')
				)}</a>`
			)}</p>`;
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
		const title = (payload.caption || '').trim() || __('Untitled Mark', 'daymark');
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
				? `<span class="daymark-chip daymark-chip--danger">${esc(
						__("Couldn't publish", 'daymark')
				  )}</span> ${esc(__('Tap to review and retry', 'daymark'))}`
				: status === 'uploading'
				? `<span class="daymark-chip daymark-chip--muted">${esc(
						__('Uploading', 'daymark')
				  )}</span> ${esc(__('Publishing now…', 'daymark'))}`
				: `<span class="daymark-chip daymark-chip--draft">${esc(__('Offline', 'daymark'))}</span> ${esc(
						__("Will sync when you're back online", 'daymark')
				  )}`;
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

	// --- Shared topbar chrome: the Daymark icon link and the Notifications
	// icon button. Home shows the icon paired with the "Daymark" wordmark;
	// Explore/Search/Me show it on its own, alongside their own screen
	// title, since all four screens (plus Notifications, reached from the
	// button below) share one persistent top-level chrome — this keeps a
	// tap back to Timeline and a way to reach Notifications available from
	// every one of them, matching the bottom nav's own always-present rows.

	function daymarkIconLink() {
		return `<a class="daymark-iconbtn daymark-iconbtn--plain" href="#home" aria-label="${esc(
			__('Daymark — go to Timeline', 'daymark')
		)}"><img class="daymark-iconbtn__icon" src="${esc(
			config.daymarkIconUrl || ''
		)}" alt="" width="26" height="26" /></a>`;
	}

	// A "go back" link — the arrow keeps its usual meaning, but Daymark's own
	// icon (never the site's Site Icon, same as every other header chrome
	// use of it) stands in for a second word alongside it, so the accessible
	// name lives on the link itself instead. Shared by every screen that
	// needs an explicit back destination (Notifications, the full-screen
	// post view) rather than each hand-rolling its own copy.
	function backLinkWithIcon(hash, label) {
		return `<a class="daymark-backlink daymark-backlink--icon" href="${esc(hash)}" aria-label="${esc(
			label
		)}"><span aria-hidden="true">&larr;</span><img src="${esc(
			config.daymarkIconUrl || ''
		)}" alt="" width="26" height="26" /></a>`;
	}

	function notificationsIconButton() {
		const hasUnread = config.notifications && config.notifications.hasUnread;
		return `<a class="daymark-iconbtn" href="#notifications" aria-label="${esc(
			hasUnread ? __('Notifications — unread replies', 'daymark') : __('Notifications', 'daymark')
		)}">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
			${hasUnread ? '<span class="daymark-iconbtn__dot" aria-hidden="true"></span>' : ''}
		</a>`;
	}

	// A small bit of whimsy bookending the Timeline's own vertical rail (the
	// thin grey line drawn behind the list — see .daymark-recent__list::before
	// in app.css): a "sunrise" mark where it begins and a "sunset" mark where
	// it currently ends, in the same sunset-gradient palette (deep ember
	// overhead through to light gold) docs/brand.md already documents for the
	// app icon/banner artwork — quite literally marking a day, start to end,
	// which is the whole point of the name "Daymark". Purely decorative
	// (aria-hidden); CSS gates their visibility on the same
	// :has(.daymark-recent__typeicon) condition the rail itself uses, so
	// neither ever shows floating above/below an empty Timeline.

	function timelineStartFlourish() {
		return `<div class="daymark-timeline-flourish daymark-timeline-flourish--start" aria-hidden="true">
			<svg width="28" height="28" viewBox="0 0 28 28">
				<defs>
					<radialGradient id="daymark-flourish-start-grad" cx="50%" cy="35%" r="65%">
						<stop offset="0%" stop-color="#FFD9A8"></stop>
						<stop offset="100%" stop-color="#C93A06"></stop>
					</radialGradient>
				</defs>
				<g stroke="#C93A06" stroke-width="1.5" stroke-linecap="round" opacity="0.55">
					<line x1="14" y1="1" x2="14" y2="5"></line>
					<line x1="5.5" y1="5.5" x2="8.2" y2="8.2"></line>
					<line x1="22.5" y1="5.5" x2="19.8" y2="8.2"></line>
				</g>
				<circle cx="14" cy="15" r="6" fill="url(#daymark-flourish-start-grad)"></circle>
			</svg>
		</div>`;
	}

	function timelineEndFlourish() {
		return `<div class="daymark-timeline-flourish daymark-timeline-flourish--end" aria-hidden="true">
			<svg width="28" height="22" viewBox="0 0 28 22">
				<defs>
					<radialGradient id="daymark-flourish-end-grad" cx="50%" cy="25%" r="75%">
						<stop offset="0%" stop-color="#FFD9A8"></stop>
						<stop offset="100%" stop-color="#9E2A02"></stop>
					</radialGradient>
					<clipPath id="daymark-flourish-end-clip">
						<rect x="0" y="0" width="28" height="13"></rect>
					</clipPath>
				</defs>
				<circle cx="14" cy="13" r="6" fill="url(#daymark-flourish-end-grad)" clip-path="url(#daymark-flourish-end-clip)"></circle>
				<line x1="1" y1="13" x2="27" y2="13" stroke="#E0E0E0" stroke-width="1.5" stroke-linecap="round"></line>
			</svg>
		</div>`;
	}

	// --- Screen: Home (Timeline) ---

	const HomeScreen = {
		render() {
			// Home itself is the merged Marks + subscriptions feed now, so
			// this is just a plain "go home" link — no separate screen to
			// point at. The icon is always Daymark's own bundled icon
			// (config.daymarkIconUrl), never the site's own Site Icon, so
			// the app's own header chrome reads as Daymark's brand
			// identity regardless of what a site owner sets as their Site
			// Icon — not the Timeline nav glyph the bottom nav's own
			// Timeline tab still uses.
			const wordmark = `<a class="daymark-homelink" href="#home"><img class="daymark-homelink__icon" src="${esc(
				config.daymarkIconUrl || ''
			)}" alt="" width="26" height="26" /><span>Daymark</span></a>`;
			return `
			<header class="daymark-topbar">
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${wordmark}</h1>
				${notificationsIconButton()}
			</header>
			<section class="daymark-screen">
				<div class="daymark-pullrefresh" data-pull-indicator aria-hidden="true">
					<span class="daymark-spinner" aria-hidden="true"></span>
				</div>
				<section class="daymark-recent" data-pending-section hidden aria-labelledby="daymark-pending-heading">
					<h2 id="daymark-pending-heading" class="daymark-section-heading">${esc(__('Pending', 'daymark'))}</h2>
					<div class="daymark-recent__list" data-pending-list></div>
				</section>
				<section class="daymark-recent" data-drafts-section hidden aria-labelledby="daymark-drafts-heading">
					<h2 id="daymark-drafts-heading" class="daymark-section-heading">${esc(__('Drafts', 'daymark'))}</h2>
					<div class="daymark-recent__list" data-drafts-list></div>
				</section>
				<section class="daymark-recent" aria-labelledby="daymark-recent-heading">
					<h2 id="daymark-recent-heading" class="daymark-visually-hidden">${esc(__('Timeline', 'daymark'))}</h2>
					<p class="daymark-status" data-recent-refresh-status aria-live="polite"></p>
					${timelineStartFlourish()}
					<div class="daymark-recent__list" data-recent-list aria-live="polite">
						${skeletonRows(3)}
						<span class="daymark-visually-hidden">${esc(__('Loading your timeline', 'daymark'))}</span>
					</div>
					<div class="daymark-recent__sentinel" data-recent-sentinel aria-hidden="true"></div>
					<p class="daymark-recent__more" data-recent-more hidden></p>
					${timelineEndFlourish()}
				</section>
			</section>
			${navFooterMarkup('home')}`;
		},

		bindEvents() {
			// --- Per-item ⋯ menu (edit / delete) via list delegation ---
			root.querySelectorAll('[data-recent-list], [data-drafts-list]').forEach((list) => {
				list.addEventListener('click', (event) => onFeedListClick(this, event));
				list.addEventListener('keydown', (event) => onFeedListKeydown(this, event));
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
			bindChromeAutoHide(this);
		},

		async init() {
			this._searchSeq = 0;
			this._hasDrafts = false;
			this.recentPage = 1;
			this.recentDone = false;
			this.recentLoading = false;
			this._refreshing = false;
			// Keyed by id (string) → the list item, so tapping a card can
			// hand openPostView() everything it already has (title/
			// permalink/etc.) without re-querying the DOM. Separate Maps per
			// source, since a subscription post's id and a Mark's own post
			// id share no relationship and could otherwise collide.
			this._bySubId = new Map();
			this._byMarkId = new Map();
			// Most recent relative-period bucket rendered so far (issue
			// #145) — reset on every fresh init/loadRecent(), left alone by
			// loadMorePage() so an appended page continues the same run of
			// headers instead of repeating one.
			this._lastGroupKey = null;

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
				heading.textContent = __('Timeline', 'daymark');
			}
			this.teardownObserver();
			teardownRehydrateObserver(this);
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
				this._byMarkId.clear();
				this._lastGroupKey = null;
				arr.forEach((item) => rememberItem(this, item));
				if (!arr.length) {
					list.innerHTML = `<p class="daymark-empty">${sprintf(
						/* translators: 1: "Publish a Mark" link, 2: "subscribe to a site" link */
						__('Nothing here yet. %1$s or %2$s to fill your timeline.', 'daymark'),
						'<a href="#create">' + esc(__('Publish a Mark', 'daymark')) + '</a>',
						`<a href="${esc(config.adminSubscriptionsUrl || '#')}">${esc(
							__('subscribe to a site', 'daymark')
						)}</a>`
					)}</p>`;
					this.recentDone = true;
					if (sentinel) {
						sentinel.hidden = true;
					}
					return;
				}
				list.innerHTML = renderFeedItemsWithGroups(this, arr);
				observeRehydrateCandidates(this, list);

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
						'<button type="button" class="daymark-btn daymark-btn--text" data-recent-loadmore>' +
						esc(__('Load more', 'daymark')) +
						'</button>';
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
				list.innerHTML = isAuthExpiredError(err)
					? authExpiredErrorHtml()
					: '<p class="daymark-error" role="alert">' +
					  sprintf(
							/* translators: %s: error message */
							esc(__('Could not load your timeline. %s', 'daymark')),
							esc(err.message)
					  ) +
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
					// Deliberately not resetting this._lastGroupKey first —
					// continuing from wherever loadRecent()'s own page (or a
					// prior loadMorePage() call) left off is what keeps this
					// page's own headers from repeating one still in view.
					list.insertAdjacentHTML('beforeend', renderFeedItemsWithGroups(this, arr));
					observeRehydrateCandidates(this, list);
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

		// Note: loadRecent()/loadMorePage() above also arm scroll-triggered
		// rehydration of pruned subscription-post content (issue #93) via
		// teardownRehydrateObserver()/observeRehydrateCandidates() —
		// standalone functions defined after rememberItem() below, not
		// methods here, since Search never needs them (Timeline-only, per
		// the issue's own scope).

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
				status.textContent = __('Checking your subscriptions…', 'daymark');
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
					status.textContent = __('No subscriptions to refresh.', 'daymark');
				} else {
					const parts = [];
					if (refreshed) {
						parts.push(
							sprintf(
								/* translators: %d: number of feeds refreshed */
								_n('%d feed updated', '%d feeds updated', refreshed, 'daymark'),
								refreshed
							)
						);
					}
					if (skipped) {
						parts.push(
							sprintf(
								/* translators: %d: number of feeds skipped */
								_n(
									'%d checked too recently, skipped',
									'%d checked too recently, skipped',
									skipped,
									'daymark'
								),
								skipped
							)
						);
					}
					if (failed) {
						parts.push(
							sprintf(
								/* translators: %d: number of feeds that failed to refresh */
								_n('%d feed failed to refresh', '%d feeds failed to refresh', failed, 'daymark'),
								failed
							)
						);
					}
					status.textContent = parts.length ? parts.join('; ') + '.' : __('Up to date.', 'daymark');
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
				${daymarkIconLink()}
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(__('Search', 'daymark'))}</h1>
				${notificationsIconButton()}
			</header>
			<div class="daymark-searchbar daymark-searchbar--screen">
				<label class="daymark-visually-hidden" for="daymark-search-input">${esc(
					__('Search Daymark', 'daymark')
				)}</label>
				<input type="search" id="daymark-search-input" class="daymark-input" data-search-input placeholder="${esc(
					__('Search your Marks and the sites you follow', 'daymark')
				)}" autocomplete="off" />
				<div class="daymark-searchfilters" data-search-filters>
					<div class="daymark-filterchips" role="group" aria-label="${esc(
						__('Filter by type', 'daymark')
					)}" data-filter-chips>${filterChips}</div>
					<label class="daymark-visually-hidden" for="daymark-source-filter">${esc(
						__('Filter by source', 'daymark')
					)}</label>
					<select id="daymark-source-filter" class="daymark-sourcefilter" data-source-filter>${sourceOptionsMarkup(
						this._subscriptions
					)}</select>
				</div>
			</div>
			<section class="daymark-screen">
				<p class="daymark-searchbookmarks-banner" data-search-bookmarks-banner hidden>
					${esc(__('Showing your bookmarks.', 'daymark'))}
					<button type="button" class="daymark-btn daymark-btn--text" data-search-clear-bookmarks>${esc(
						__('Show everything', 'daymark')
					)}</button>
				</p>
				<section class="daymark-recent" aria-labelledby="daymark-search-results-heading">
					<h2 id="daymark-search-results-heading" class="daymark-visually-hidden">${esc(__('Results', 'daymark'))}</h2>
					<div class="daymark-recent__list" data-search-results aria-live="polite">
						${skeletonRows(3)}
						<span class="daymark-visually-hidden">${esc(__('Loading', 'daymark'))}</span>
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
				list.addEventListener('keydown', (event) => onFeedListKeydown(this, event));
			}

			const clearBookmarks = root.querySelector('[data-search-clear-bookmarks]');
			if (clearBookmarks) {
				clearBookmarks.addEventListener('click', () => {
					this.searchBookmarked = false;
					this.syncBookmarksBanner();
					this.runSearch();
				});
			}

			bindDismissible(this, [itemMenusDismissEntry(), navFooterDismissEntry(this)]);

			bindNavFooter(this);
		},

		async init() {
			this._searchSeq = 0;
			this.searchQuery = '';
			this.searchType = '';
			this.searchSource = '';
			this.searchBookmarked = false;
			this._bySubId = new Map();
			this._byMarkId = new Map();
			this._subscriptions = [];

			// A preset handed from Explore/Me ("browse by type", "your
			// Marks", "Following", "Bookmarks") right before navigate('#search')
			// — the same one-shot pattern state.pendingType already uses for
			// the composer.
			if (searchPreset) {
				this.searchType = searchPreset.type || '';
				this.searchSource = searchPreset.source || '';
				this.searchQuery = searchPreset.query || '';
				this.searchBookmarked = !!searchPreset.bookmarked;
				searchPreset = null;
			}

			this.syncFilterChips();
			this.syncBookmarksBanner();
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

		syncBookmarksBanner() {
			const banner = root.querySelector('[data-search-bookmarks-banner]');
			if (banner) {
				banner.hidden = !this.searchBookmarked;
			}
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
				skeletonRows(2) + '<span class="daymark-visually-hidden">' + esc(__('Searching', 'daymark')) + '</span>';
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
			if (this.searchBookmarked) {
				params.set('bookmarked', '1');
			}
			try {
				const items = await apiGet('timeline?' + params.toString());
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				const arr = Array.isArray(items) ? items : [];
				this._bySubId.clear();
				this._byMarkId.clear();
				arr.forEach((item) => rememberItem(this, item));
				if (!arr.length) {
					list.innerHTML =
						'<p class="daymark-empty">' +
						esc(__('Nothing matches. Try a different search or filter.', 'daymark')) +
						'</p>';
					return;
				}
				list.innerHTML = arr.map((item) => renderFeedItem(item)).join('');
			} catch (err) {
				if (seq !== this._searchSeq || !list.isConnected) {
					return;
				}
				// Only the Bookmarks-filtered view has a meaningful offline
				// fallback — its whole point is content already cached for
				// exactly this case (see cacheBookmarkOffline()); an
				// ordinary keyword/type search has no local data to fall
				// back to, so it still just reports the failure.
				if (this.searchBookmarked && (!navigator.onLine || err instanceof TypeError)) {
					await this.renderCachedBookmarks(list, seq);
					return;
				}
				list.innerHTML =
					'<p class="daymark-error" role="alert">' +
					sprintf(
						/* translators: %s: error message */
						esc(__('Search failed. %s', 'daymark')),
						esc(err.message)
					) +
					'</p>';
			}
		},

		// Offline fallback for the Bookmarks-filtered view: renders from
		// BOOKMARK_STORE's own cached item summaries instead of a live
		// GET /timeline. Applies the type/keyword filters client-side,
		// best-effort — the Source filter (mine/a specific subscription)
		// is skipped here, since the cache has no reliable per-source
		// membership to filter by offline.
		async renderCachedBookmarks(list, seq) {
			const cached = await getAllCachedBookmarks();
			if (seq !== this._searchSeq || !list.isConnected) {
				return;
			}
			let items = cached.map((record) => record.item).filter(Boolean);
			if (this.searchType) {
				items = items.filter(
					(item) => this.searchType === (item.type || item.post_format || '')
				);
			}
			if (this.searchQuery) {
				const query = this.searchQuery.toLowerCase();
				items = items.filter((item) => {
					const haystack = ((item.title || '') + ' ' + (item.excerpt || '')).toLowerCase();
					return haystack.includes(query);
				});
			}
			this._bySubId.clear();
			this._byMarkId.clear();
			items.forEach((item) => rememberItem(this, item));
			if (!items.length) {
				list.innerHTML =
					'<p class="daymark-empty">' +
					esc(__('No bookmarks cached for offline viewing yet.', 'daymark')) +
					'</p>';
				return;
			}
			list.innerHTML = items.map((item) => renderFeedItem(item)).join('');
		},
	};

	// --- Screen: Explore ---
	//
	// A first, deliberately non-chronological browsing destination — never
	// a second Timeline. Every section here is real, built entirely on
	// data the plugin already exposes (Mark type filtering, bookmark
	// state, active subscriptions): "Browse by type", "Bookmarks", and
	// "Following" all hand a preset off to Search rather than duplicating
	// its results rendering. Memories, highlights, collections, favorites,
	// and suggested content are future sections on this same screen, not
	// implied by anything rendered here.

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
				${daymarkIconLink()}
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(__('Explore', 'daymark'))}</h1>
				${notificationsIconButton()}
			</header>
			<section class="daymark-screen">
				<section class="daymark-recent" aria-labelledby="daymark-explore-types-heading">
					<h2 id="daymark-explore-types-heading" class="daymark-section-heading">${esc(
						__('Browse by type', 'daymark')
					)}</h2>
					<div class="daymark-exploretypes">${typeButtons}</div>
				</section>
				<section class="daymark-recent" aria-labelledby="daymark-explore-bookmarks-heading">
					<h2 id="daymark-explore-bookmarks-heading" class="daymark-section-heading">${esc(
						__('Bookmarks', 'daymark')
					)}</h2>
					<div class="daymark-exploretypes">
						<button type="button" class="daymark-exploretype" data-explore-bookmarks>${navIcon(
							BOOKMARK_GLYPH
						)}<span>${esc(__('Saved for offline', 'daymark'))}</span></button>
					</div>
				</section>
				<section class="daymark-recent" aria-labelledby="daymark-explore-following-heading">
					<h2 id="daymark-explore-following-heading" class="daymark-section-heading">${esc(
						__('Following', 'daymark')
					)}</h2>
					<div class="daymark-recent__list" data-explore-following>
						${skeletonRows(2)}
						<span class="daymark-visually-hidden">${esc(__('Loading', 'daymark'))}</span>
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

			const bookmarksBtn = root.querySelector('[data-explore-bookmarks]');
			if (bookmarksBtn) {
				bookmarksBtn.addEventListener('click', () => {
					searchPreset = { bookmarked: true };
					navigate('#search');
				});
			}

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
				list.innerHTML = `<p class="daymark-empty">${sprintf(
					/* translators: %s: "Subscribe to one" link */
					__("You're not following any sites yet. %s to see its posts here.", 'daymark'),
					`<a href="${esc(config.adminSubscriptionsUrl || '#')}">${esc(
						__('Subscribe to one', 'daymark')
					)}</a>`
				)}</p>`;
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
	// (Subscriptions in wp-admin, their WordPress profile — Notifications
	// is reached from the header icon every screen now shares, so it's no
	// longer a link in this body). Deliberately doesn't duplicate
	// WordPress's own account settings — Edit profile and Log out link
	// out to WordPress rather than reimplementing them.

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
				${daymarkIconLink()}
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(__('Me', 'daymark'))}</h1>
				${notificationsIconButton()}
			</header>
			<section class="daymark-screen">
				<div class="daymark-meprofile">
					${avatar}
					<span class="daymark-mename">${esc(user.displayName || '')}</span>
				</div>
				<nav class="daymark-melinks" aria-label="${esc(__('Your Daymark', 'daymark'))}">
					<button type="button" class="daymark-melink" data-me-mymarks>${esc(__('Your Marks', 'daymark'))}</button>
					${
						config.adminSubscriptionsUrl
							? `<a class="daymark-melink" href="${esc(config.adminSubscriptionsUrl)}">${esc(
									__('Subscriptions', 'daymark')
							  )}</a>`
							: ''
					}
					${
						user.profileEditUrl
							? `<a class="daymark-melink" href="${esc(user.profileEditUrl)}">${esc(
									__('Edit profile', 'daymark')
							  )}</a>`
							: ''
					}
					${
						user.logoutUrl
							? `<a class="daymark-melink" href="${esc(user.logoutUrl)}" data-me-logout>${esc(
									__('Log out', 'daymark')
							  )}</a>`
							: ''
					}
				</nav>
				<section class="daymark-recent" aria-labelledby="daymark-me-drafts-heading">
					<h2 id="daymark-me-drafts-heading" class="daymark-section-heading">${esc(__('Drafts', 'daymark'))}</h2>
					<div class="daymark-recent__list" data-me-drafts>
						${skeletonRows(2)}
						<span class="daymark-visually-hidden">${esc(__('Loading', 'daymark'))}</span>
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

			const logout = root.querySelector('[data-me-logout]');
			if (logout) {
				logout.addEventListener('click', (event) => {
					event.preventDefault();
					const href = logout.href;
					clearOfflineShellCache().then(() => {
						window.location.href = href;
					});
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
					list.innerHTML = `<p class="daymark-empty">${sprintf(
						/* translators: %s: "Start one" link */
						__('No drafts. %s.', 'daymark'),
						'<a href="#create">' + esc(__('Start one', 'daymark')) + '</a>'
					)}</p>`;
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
						'<p class="daymark-error" role="alert">' +
						sprintf(
							/* translators: %s: error message */
							esc(__('Could not load drafts. %s', 'daymark')),
							esc(err.message)
						) +
						'</p>';
				}
			}
		},
	};

	// --- Screen: Create Mark ---

	const CreateScreen = {
		render() {
			const editing = state.editing;
			return `
			<header class="daymark-topbar">
				<a class="daymark-backlink" href="#home">&larr; ${esc(__('Back', 'daymark'))}</a>
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(
					editing ? __('Edit Draft', 'daymark') : __('New Mark', 'daymark')
				)}</h1>
			</header>
			<section class="daymark-screen">
				<p class="daymark-autosave-status" data-autosave-status aria-live="polite"></p>
				${
					editing
						? '<p class="daymark-editbanner"><span class="daymark-chip daymark-chip--draft">' +
						  esc(__('Draft', 'daymark')) +
						  '</span> ' +
						  esc(__('Changes save to this Mark — new media is added alongside what’s attached.', 'daymark')) +
						  '</p>'
						: ''
				}
				${
					state.replyTo
						? `<p class="daymark-editbanner"><span class="daymark-chip daymark-chip--draft">${esc(
								__('Reply', 'daymark')
						  )}</span> ${sprintf(
								/* translators: %s: title or URL of the post being replied to */
								esc(__('Replying to %s', 'daymark')),
								esc(state.replyTo.title || state.replyTo.url)
						  )}</p>`
						: ''
				}
				<div data-existing-media-slot>${this.existingMediaMarkup()}</div>
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
					<button type="button" class="daymark-btn daymark-btn--text daymark-picker__library" data-picker-library>${esc(
						__('Choose from library instead', 'daymark')
					)}</button>
				</div>`
						: // Untyped entry (e.g. a Drafts/Explore empty-state link) —
						  // the intended capture mode isn't known yet, so this stays
						  // the original neutral, non-capture picker.
						  `<div class="daymark-picker">
					<input type="file" id="daymark-file-input" class="daymark-picker__input" accept="image/*,video/*,audio/*" multiple />
					<label for="daymark-file-input" class="daymark-picker__zone">
						<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
						<span>${esc(__('Tap to choose media', 'daymark'))}</span>
						<span class="daymark-picker__hint">${esc(
							__('Photos, videos, or audio from your device', 'daymark')
						)}</span>
					</label>
				</div>`
				}
				<div class="daymark-preview" data-preview></div>
				<p class="daymark-typebadge">${sprintf(
					/* translators: %s: Mark type label (e.g. "Image") */
					esc(__('Mark type: %s', 'daymark')),
					`<span class="daymark-chip" data-type-badge>${esc(TYPE_LABELS[effectiveType()])}</span>`
				)}</p>
				<div class="daymark-field">
					<label class="daymark-field__label" for="daymark-caption">${esc(__('Caption', 'daymark'))}</label>
					<textarea id="daymark-caption" class="daymark-textarea" rows="4" placeholder="${esc(
						__("What's happening?", 'daymark')
					)}">${esc(state.caption)}</textarea>
				</div>
				<div data-title-slot></div>
				<div data-transcript-slot></div>
				${
					config.ai && config.ai.available
						? '<button type="button" class="daymark-btn daymark-btn--secondary" data-action="ai-assist">' +
						  esc(__('AI Assist', 'daymark')) +
						  '</button>'
						: '' /* No AI provider configured — no AI options offered. */
				}
			</section>
			<footer class="daymark-actionbar">
				<p class="daymark-status" data-create-status aria-live="polite"></p>
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="next">${esc(
					__('Next: Publish →', 'daymark')
				)}</button>
			</footer>`;
		},

		// Media already attached to a draft (state.editing.media) — its own
		// re-renderable slot, separate from refreshMedia()'s new-picks grid,
		// since the two are unrelated arrays with unrelated layouts (see
		// buildMarkPayload()'s existingAlt/mediaOrder split). Re-run after a
		// reorder via refreshExistingMedia(); render() calls this once for
		// the initial paint.
		existingMediaMarkup() {
			const editing = state.editing;
			if (!editing || !editing.media.length) {
				return '';
			}
			return `<ul class="daymark-editmedia" aria-label="${esc(
				__('Media already attached to this draft', 'daymark')
			)}">${editing.media
				.map(
					(m) => `
					<li class="daymark-editmedia__item">
						${
							m.thumbnail
								? `<img class="daymark-editmedia__thumb" src="${esc(m.thumbnail)}" alt="${esc(
										sprintf(
											/* translators: %s: filename or media kind */
											__('Attached %s', 'daymark'),
											m.filename || m.kind
										)
								  )}" />`
								: `<span class="daymark-editmedia__glyph">${esc(m.kind)}</span>`
						}
						${
							m.kind === 'image'
								? `<span class="daymark-alt daymark-alt--edit">
										<label class="daymark-alt__label" for="daymark-existing-alt-${esc(m.id)}">${esc(
										__('Alt text', 'daymark')
								  )}</label>
										<input type="text" class="daymark-input daymark-alt__input" id="daymark-existing-alt-${esc(
											m.id
										)}" data-existing-alt="${esc(m.id)}" value="${esc(
										m.alt || ''
								  )}" placeholder="${esc(__('Describe this image', 'daymark'))}" />
									</span>`
								: `<span class="daymark-editmedia__name">${esc(m.filename || m.kind)}</span>`
						}
						${m.kind === 'image' ? this.reorderControlMarkup('existing', editing.media, m.id) : ''}
					</li>`
				)
				.join('')}</ul>`;
		},

		// Move/reorder controls for one image entry (issue #250) — shared by
		// both the new-picks file list (refreshMedia()) and the
		// already-attached media list (existingMediaMarkup()) above. Up/down
		// buttons rather than drag-and-drop: drag alone has no keyboard
		// equivalent and is fussy on touch inside a scrolling list, while a
		// button meets --daymark-tap-min and works identically by tap,
		// mouse, or keyboard. Only rendered when the list has 2+ images —
		// reordering a lone image has nothing to reorder against.
		reorderControlMarkup(scope, list, id) {
			const imageIds = list.filter((item) => item.kind === 'image').map((item) => item.id);
			if (imageIds.length < 2) {
				return '';
			}
			const pos = imageIds.findIndex((itemId) => String(itemId) === String(id));
			if (pos === -1) {
				return '';
			}
			const action = 'existing' === scope ? 'data-move-existing' : 'data-move-file';
			const idAttr = 'existing' === scope ? 'data-move-existing-id' : 'data-move-file-id';
			const disableUp = pos <= 0;
			const disableDown = pos >= imageIds.length - 1;
			return `<div class="daymark-reorder" role="group" aria-label="${esc(
				__('Reorder this image in the gallery', 'daymark')
			)}">
				<button type="button" class="daymark-reorder__btn" ${action}="up" ${idAttr}="${esc(
				id
			)}" aria-label="${esc(__('Move image up', 'daymark'))}" title="${esc(
				__('Move up', 'daymark')
			)}"${disableUp ? ' disabled' : ''}><span aria-hidden="true">&uarr;</span></button>
				<button type="button" class="daymark-reorder__btn" ${action}="down" ${idAttr}="${esc(
				id
			)}" aria-label="${esc(__('Move image down', 'daymark'))}" title="${esc(
				__('Move down', 'daymark')
			)}"${disableDown ? ' disabled' : ''}><span aria-hidden="true">&darr;</span></button>
			</div>`;
		},

		// Reorder a not-yet-uploaded (or already-uploaded-this-session) pick.
		moveFile(id, direction) {
			if (moveImageInList(state.files, id, direction)) {
				scheduleAutosave();
				this.refreshMedia();
			}
		},

		// Reorder media already attached to a draft being edited.
		moveExistingMedia(id, direction) {
			if (state.editing && moveImageInList(state.editing.media, id, direction)) {
				scheduleAutosave();
				this.refreshExistingMedia();
			}
		},

		// Re-render existingMediaMarkup() into its own slot (see render())
		// without touching the rest of the screen, and rebind the listeners
		// that live inside it.
		refreshExistingMedia() {
			const slot = root.querySelector('[data-existing-media-slot]');
			if (!slot) {
				return;
			}
			slot.innerHTML = this.existingMediaMarkup();
			this.bindExistingMediaEvents();
		},

		// Alt-text input and reorder-button listeners for the already-attached
		// media list — factored out so both the initial bindEvents() call and
		// every refreshExistingMedia() re-render wire the same handlers.
		bindExistingMediaEvents() {
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
			root.querySelectorAll('[data-move-existing]').forEach((button) => {
				button.addEventListener('click', () => {
					const id = button.getAttribute('data-move-existing-id');
					const direction = 'up' === button.getAttribute('data-move-existing') ? -1 : 1;
					this.moveExistingMedia(id, direction);
				});
			});
		},

		bindEvents() {
			// Quietly capture date/time + optional location the moment a
			// fresh composing session begins — a no-op when resuming a draft
			// (state.editing is already set by then) or later in the same
			// session (state.capturedAt is already set). See
			// maybeBeginQuietCapture()'s own docblock for why this single call
			// site covers every entry path into a new Mark.
			maybeBeginQuietCapture();

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
					this.addPickedFiles(input.files);
					input.value = '';
				});
			}

			// Drag-and-drop (issue #260): the picker zone already renders
			// with a dashed border, so it already reads as a drop target —
			// dragover/drop just wire up the actual behavior and a hover
			// highlight to confirm it. Feeds the exact same addPickedFiles()
			// path as the file input, so a dropped file is indistinguishable
			// from a picked one downstream (dedup, alt-text pre-fill,
			// autosave, all included). Inert on touch devices, which never
			// fire drag events — no behavior change there.
			const pickerZone = root.querySelector('.daymark-picker');
			if (pickerZone) {
				pickerZone.addEventListener('dragover', (event) => {
					event.preventDefault();
					pickerZone.classList.add('is-dragover');
				});
				pickerZone.addEventListener('dragleave', () => {
					pickerZone.classList.remove('is-dragover');
				});
				pickerZone.addEventListener('drop', (event) => {
					event.preventDefault();
					pickerZone.classList.remove('is-dragover');
					this.addPickedFiles(event.dataTransfer && event.dataTransfer.files);
				});
			}

			caption.addEventListener('input', () => {
				state.caption = caption.value;
				scheduleAutosave();
				scheduleQuietTagSuggestion();
			});

			// Alt edits and reorder taps on media already attached to a draft.
			this.bindExistingMediaEvents();

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
				if (!hasComposerContent()) {
					status.textContent = __('Add media or write a caption to continue.', 'daymark');
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

		// Shared file-intake path for the picker's file input and drag-and-
		// drop (issue #260) alike — a `FileList` (or anything array-like
		// enough for Array.from) in, deduped/entry-constructed/autosaved
		// the exact same way regardless of which one handed it over.
		addPickedFiles(fileList) {
			const picked = Array.from(fileList || []);
			if (!picked.length) {
				return;
			}
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
			this.refreshMedia();
			// Protect the actual picked media as soon as possible — don't
			// wait for Publish/Save as Draft to upload it.
			runAutosave();
			// Picked media alone is enough to ground a quiet tag
			// suggestion (same bar as the caption trigger below).
			scheduleQuietTagSuggestion();
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
						? `<img class="daymark-preview__img" src="${esc(entry.url)}" alt="${esc(
								sprintf(
									/* translators: %s: filename */
									__('Preview of %s', 'daymark'),
									entry.file.name
								)
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
						)}" aria-label="${esc(
						sprintf(
							/* translators: %s: filename */
							__('Clear %s', 'daymark'),
							entry.file.name
						)
					)}">${esc(__('Clear', 'daymark'))}</button>
					</div>
					${entry.kind === 'image' ? this.altFieldMarkup(entry) : ''}
					${entry.kind === 'image' ? this.reorderControlMarkup('file', state.files, entry.id) : ''}
				</li>`
				)
				.join('');

			const extraLabel =
				extra > 0
					? sprintf(
							/* translators: %d: number of additional media items */
							__(', plus %d more', 'daymark'),
							extra
					  )
					: '';
			preview.innerHTML = `
				<ul class="daymark-preview__grid" aria-label="${esc(
					__('Selected media previews', 'daymark')
				)}${esc(extraLabel)}">${tiles}</ul>
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

			preview.querySelectorAll('[data-move-file]').forEach((button) => {
				button.addEventListener('click', () => {
					const id = button.getAttribute('data-move-file-id');
					const direction = 'up' === button.getAttribute('data-move-file') ? -1 : 1;
					this.moveFile(id, direction);
				});
			});
		},

		// Alt-text field for one image entry, with a hint reflecting AI state
		// and a manual "Improve with AI"/"Suggest with AI" re-run button.
		altFieldMarkup(entry) {
			const hint =
				entry.altStatus === 'loading'
					? '<span class="daymark-alt__hint">' + esc(__('Generating alt text…', 'daymark')) + '</span>'
					: entry.altStatus === 'done'
					? '<span class="daymark-alt__hint">' +
					  esc(__('AI-suggested — edit as needed', 'daymark')) +
					  '</span>'
					: '';
			const canImprove = config.ai && config.ai.available && entry.altStatus !== 'loading';
			const improveButton = canImprove
				? `<button type="button" class="daymark-btn daymark-btn--text daymark-alt__improve" data-alt-improve="${esc(
						entry.id
				  )}">${esc(entry.alt ? __('Improve with AI', 'daymark') : __('Suggest with AI', 'daymark'))}</button>`
				: '';
			return `
				<div class="daymark-alt">
					<label class="daymark-alt__label" for="daymark-alt-${esc(entry.id)}">${esc(
				__('Alt text', 'daymark')
			)}</label>
					<input type="text" class="daymark-input daymark-alt__input" id="daymark-alt-${esc(
						entry.id
					)}" data-alt-for="${esc(entry.id)}" value="${esc(entry.alt)}" placeholder="${esc(
				__('Describe this image', 'daymark')
			)}" ${entry.altStatus === 'loading' ? 'aria-busy="true"' : ''} />
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
				hint.textContent = entry.altStatus === 'done' ? __('AI-suggested — edit as needed', 'daymark') : '';
			}
			const improveButton = field.parentElement.querySelector('.daymark-alt__improve');
			if (improveButton) {
				improveButton.disabled = false;
				improveButton.textContent = entry.alt ? __('Improve with AI', 'daymark') : __('Suggest with AI', 'daymark');
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
						<label class="daymark-field__label" for="daymark-title">${esc(__('Title (optional)', 'daymark'))}</label>
						<button type="button" class="daymark-infobtn" data-title-info aria-label="${esc(
							__('About the title field', 'daymark')
						)}" aria-expanded="false" aria-controls="daymark-title-hint">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
						</button>
					</div>
					<input type="text" class="daymark-input daymark-titlefield__input" id="daymark-title" data-title-input value="${esc(
						state.title
					)}" placeholder="${esc(__('Add a title', 'daymark'))}"${busy} />
					<p class="daymark-titlefield__hint" id="daymark-title-hint" data-title-hint hidden>${esc(
						__("If left blank, the title is generated from your Mark's text.", 'daymark')
					)}</p>
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
				? __('Generating transcript…', 'daymark')
				: state.transcript
				? __('Regenerate transcript', 'daymark')
				: __('Generate transcript', 'daymark');
			return `
				<div class="daymark-field daymark-transcriptfield">
					<label class="daymark-field__label" for="daymark-transcript">${esc(
						__('Transcript (optional)', 'daymark')
					)}</label>
					<textarea id="daymark-transcript" class="daymark-textarea" rows="4" placeholder="${esc(
						__('Add a transcript, or generate one with AI', 'daymark')
					)}"${
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
			<button type="button" class="daymark-sheet__backdrop" data-sheet-dismiss aria-label="${esc(
				__('Dismiss AI Assist', 'daymark')
			)}"></button>
			<div class="daymark-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="daymark-sheet-title">
				<h2 class="daymark-sheet__title" id="daymark-sheet-title" tabindex="-1">${esc(__('AI Assist', 'daymark'))}</h2>
				<div class="daymark-sheet__body" data-sheet-body aria-live="polite">
					<p class="daymark-loading"><span class="daymark-spinner" aria-hidden="true"></span> ${esc(
						__('Getting suggestions…', 'daymark')
					)}</p>
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
					<p class="daymark-error" role="alert">${sprintf(
						/* translators: %s: error message */
						esc(__('Could not get suggestions. %s', 'daymark')),
						esc(err.message)
					)}</p>
					<div class="daymark-sheet__actions">
						<button type="button" class="daymark-btn daymark-btn--primary" data-sheet-retry>${esc(
							__('Retry', 'daymark')
						)}</button>
						<button type="button" class="daymark-btn daymark-btn--text" data-sheet-skip>${esc(
							__('Skip', 'daymark')
						)}</button>
					</div>`;
				body.querySelector('[data-sheet-retry]').addEventListener('click', () => {
					body.innerHTML =
						'<p class="daymark-loading"><span class="daymark-spinner" aria-hidden="true"></span> ' +
						esc(__('Getting suggestions…', 'daymark')) +
						'</p>';
					this.fetchSuggestions();
				});
				body.querySelector('[data-sheet-skip]').addEventListener('click', () => this.hide());
			}
		},

		renderForm(suggestions) {
			const notice = suggestions.is_mocked
				? '<p class="daymark-notice">' +
				  esc(
						__(
							'Using demo suggestions — connect an AI provider in WordPress settings for real suggestions.',
							'daymark'
						)
				  ) +
				  '</p>'
				: suggestions.provider_label
				? `<p class="daymark-notice">${sprintf(
						/* translators: %s: AI provider name */
						esc(__('Suggestions by %s.', 'daymark')),
						esc(suggestions.provider_label)
				  )}</p>`
				: '';
			return `
			${notice}
			<div class="daymark-field">
				<label class="daymark-field__label" for="daymark-ai-caption">${esc(
					__('Suggested caption', 'daymark')
				)}</label>
				<textarea id="daymark-ai-caption" class="daymark-textarea" rows="3">${esc(
					suggestions.caption || ''
				)}</textarea>
			</div>
			<fieldset class="daymark-tags">
				<legend class="daymark-tags__legend">${esc(__('Suggested tags', 'daymark'))}</legend>
				<ul class="daymark-tags__list" data-tag-list></ul>
				<div class="daymark-tags__addrow">
					<label class="daymark-visually-hidden" for="daymark-ai-newtag">${esc(__('Add a tag', 'daymark'))}</label>
					<input type="text" id="daymark-ai-newtag" class="daymark-input" placeholder="${esc(
						__('Add a tag', 'daymark')
					)}" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="daymark-tag-suggest" />
					<button type="button" class="daymark-btn daymark-btn--secondary" data-tag-add>${esc(
						__('+ Add', 'daymark')
					)}</button>
				</div>
				<ul class="daymark-tags__suggest" id="daymark-tag-suggest" data-tag-suggest hidden></ul>
			</fieldset>
			<div class="daymark-sheet__actions">
				<button type="button" class="daymark-btn daymark-btn--primary" data-sheet-accept>${esc(
					__('Accept All', 'daymark')
				)}</button>
				<button type="button" class="daymark-btn daymark-btn--text" data-sheet-skip>${esc(
					__('Skip', 'daymark')
				)}</button>
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
				// An explicit user action, same as titleEdited/altEdited: never
				// let a later quiet suggestion overwrite what was just accepted.
				state.tagsEdited = true;
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
							<button type="button" class="daymark-tags__remove" data-tag-remove="${index}" aria-label="${esc(
								sprintf(
									/* translators: %s: tag name */
									__('Remove tag %s', 'daymark'),
									tag
								)
							)}">&times;</button>
						</li>`
						)
						.join('')
				: '<li class="daymark-note-card__meta">' + esc(__('No tags suggested.', 'daymark')) + '</li>';
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
	const CARD_KIND_LABELS = Object.assign({}, TYPE_LABELS, {
		article: __('Article', 'daymark'),
		link: __('Link', 'daymark'),
	});

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
			// at all to report as `type`. Its own real WordPress post
			// format is the strongest signal when it says something
			// richer than "Standard": Image/Gallery/Video mean the
			// featured image really is the point, so those get the same
			// media-dominant treatment a real Image/Gallery/Video Mark
			// does — the same distinction a subscription post's own
			// post_format already draws just below. A Standard post (or
			// any format this app doesn't otherwise recognize) reads as
			// an article instead — a smaller thumbnail beside the title
			// and excerpt, not a full-bleed banner, since a Standard
			// post's featured image is decoration, not the point, the
			// way an actual Image Mark's photo is.
			if (item.post_format && MEDIA_DOMINANT_KINDS.includes(item.post_format)) {
				return item.post_format;
			}
			const plainExcerpt = toPlainText(item.excerpt || '').trim();
			if (plainExcerpt) {
				return 'article';
			}
			return item.thumbnail ? 'image' : 'note';
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
		const label = CARD_KIND_LABELS[kind] || __('Post', 'daymark');
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
		const src = item.thumbnail || item.featured_image_url || item.site_icon_url;
		// A manufactured placeholder glyph only earns its keep for a kind
		// where it stands in for media the reader would otherwise expect —
		// the media-dominant kinds (image/video/gallery/mixed) plus audio,
		// which very often has neither a thumbnail nor a featured image (see
		// the docblock above renderCardMedia()). An article/standard-format
		// card with no featured image and no cached site icon has nothing
		// real to show either way, so the slot is dropped entirely rather
		// than reserving space for an icon that isn't standing in for
		// anything — the title/excerpt/date get the card's full width
		// instead, the same treatment note/link already get.
		const wantsPlaceholder = isMedia || 'audio' === kind;
		if (!wantsPlaceholder && !src) {
			return '';
		}
		const wrapClass = isMedia ? 'daymark-recent__thumbwrap daymark-recent__thumbwrap--media' : 'daymark-recent__thumbwrap';
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
	// ('Draft' on an unpublished Mark — a subscription post carries no chip
	// at all, since its site icon already makes clear it isn't yours), the
	// author when there is one (a subscription post only — a Mark's author
	// is implicitly config.currentUser, shown via its own site icon
	// instead), and — only when the server resolved one
	// (prepare_mark_summary(), class-rest-controller.php) — a reading-time
	// estimate. Deliberately doesn't repeat the kind as a text label the way
	// this line used to for a Mark (TYPE_LABELS[item.type]) — the rail's own
	// type icon (see renderTypeIcon()) already says that now, so the text
	// stays free for what the icon can't show. Camera and weather metadata
	// stay server-stored-only for now — deliberately not rendered here, to
	// keep this compact card from getting cluttered. The timestamp (and the
	// site name) aren't part of this line — see renderCardTimestampRow(),
	// rendered as their own bottom row instead.
	function renderCardMeta(item, chipHtml) {
		const parts = [];
		if (chipHtml) {
			parts.push(chipHtml);
		}
		if (item.author) {
			parts.push(esc(item.author));
		}
		if (item.reading_time_minutes) {
			parts.push(
				esc(
					sprintf(
						/* translators: %d: reading time in minutes */
						__('%d min read', 'daymark'),
						item.reading_time_minutes
					)
				)
			);
		}
		return parts.join(' &middot; ');
	}

	// A card's timestamp, on its own row at the bottom of the card, aligned
	// right — a quiet, corner-anchored detail rather than another item in
	// the meta line's list (see renderCardMeta() above). `.daymark-recent__body`
	// is a flex column for every card kind, so `align-self: flex-end` (see
	// app.css) is enough to push this row to the right without needing
	// absolute positioning that would risk overlapping the stats row or an
	// excerpt of unpredictable height.
	// `siteLabel` (issue: "site name on the date row") sits on the same row
	// as the timestamp, left-aligned — a Mark's own site name
	// (config.siteTitle) or a subscription post's source site
	// (subscriptionSiteLabel(item)), matching how a subscription post's
	// card already shows whose content it is. Omitted for a Draft (its
	// caller passes '' — a draft has no separate "published to" site yet,
	// the same reasoning renderMarkItem() already applies to skipping its
	// site icon). `time`'s own `margin-left: auto` (CSS) keeps it
	// right-aligned whether or not a site name precedes it.
	function renderCardTimestampRow(item, siteLabel) {
		if (!item.date && !siteLabel) {
			return '';
		}
		const site = siteLabel ? `<span class="daymark-recent__sitename">${esc(siteLabel)}</span>` : '';
		const time = item.date ? renderCardTimestamp(item.date) : '';
		return `<span class="daymark-recent__timestamprow">${site}${time}</span>`;
	}

	// The thumbnail/media(-or-placeholder) + title + meta + stats core of
	// one Mark's card markup — used by every Mark item any feed-list screen
	// renders (Home's Recent/Drafts, Search's results), wrapped by
	// renderMarkItem() in the same ⋯ actions menu for a Draft. Keeping this
	// in one place is what "reuse, don't reinvent Mark card markup" means.
	function renderMarkCore(item) {
		const kind = resolveCardKind(item);
		const title = item.title || __('Untitled Mark', 'daymark');
		// Drafts look identical to published Marks otherwise — and their
		// permalinks are invisible to visitors — so say so. (The Timeline
		// endpoint only ever returns published Marks, so this never fires
		// there; Home's Recent/Drafts lists are what actually rely on it.)
		const isDraft = item.status && 'publish' !== item.status;
		const chip = isDraft
			? '<span class="daymark-chip daymark-chip--draft">' + esc(__('Draft', 'daymark')) + '</span>'
			: '';
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
						${renderCardTimestampRow(item, isDraft ? '' : config.siteTitle || __('Site', 'daymark'))}
					</span>`;
	}

	// One subscription-post Timeline card. A <button>, not an <a>: opening it
	// navigates to the full-screen post view (openPostView(), issue #270),
	// rather than following its own href — its permalink points at the
	// *source* site, not anywhere in this app. No *counted* like/comment/
	// reblog stats: those only ever exist for a Mark — Daymark doesn't (and,
	// for someone else's post, can't cheaply) track the origin site's real
	// engagement totals. What it *can* track is its own record of the user's
	// own engagement (issue #41 follow-up) — Like and Repost toggle a small
	// Mark of the site owner's own (see toggleLike()/toggleRepost()), and the
	// Reply indicator is read-only, reflecting whether a reply Mark already
	// exists (the "Reply" action itself lives on the post view screen, see
	// startReplyToSubscriptionPost()).
	function renderSubscriptionPostCard(item) {
		const kind = resolveCardKind(item);
		const title = item.title || __('Untitled post', 'daymark');
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
						ariaLabel: sprintf(
							/* translators: %s: site name */
							__('Filter Timeline to posts from %s', 'daymark'),
							siteLabel
						),
						filterValue: String(item.subscription_id),
						siteUrl: item.site_url || '',
					})}
					${renderTypeIcon(kind)}
					<button type="button" class="daymark-recent__item daymark-recent__item--button daymark-recent__item--${esc(
						kind
					)}" data-subpost="${id}">
						${renderCardMedia(item, kind)}
						<span class="daymark-recent__body">
							<span class="daymark-recent__title">${esc(title)}</span>
							<span class="daymark-recent__meta">${renderCardMeta(item)}</span>
							${showExcerpt ? `<span class="daymark-recent__excerpt">${esc(excerpt)}</span>` : ''}
							<span class="daymark-item-stats daymark-item-stats--minimal">${renderLikeToggle(
								item
							)}${renderEngagementIndicator(
								COMMENT_GLYPH,
								!!item.replied_mark_id,
								'replied',
								__('Replied', 'daymark')
							)}${renderRepostToggle(item)}${renderBookmarkToggle(
								item,
								'subscription_post'
							)}${renderExternalLinkToggle(item)}${renderShareToggle(item)}</span>
							${renderCardTimestampRow(item, siteLabel)}
						</span>
					</button>
				</div>`;
	}

	// --- Full-screen post view: a Timeline card's own content, read on a
	// dedicated screen (issue #270) ---
	//
	// Replaces the old inline-expand panel (a Mark, an ordinary block-editor
	// post, or a subscription post used to expand its own content directly
	// below the card) with a full-screen takeover instead — the same
	// pattern Notifications already uses, and reached the same way: a back
	// arrow next to the Daymark icon in the upper-left (backLinkWithIcon()),
	// not a small panel fighting a Timeline card for space. See PostScreen
	// below for the screen itself; the content-loading functions here
	// (loadMarkExpandHtml()/fetchSubscriptionExpandBody(), the offline
	// fallback, the shared HTML wrapper) are unchanged from the inline-panel
	// era — only where their result gets rendered changed.

	// Renders whatever HTML a card's content loader resolved to. Used to
	// also append a "View full post"/"View original" link out to the real
	// permalink — that's now a permanent entry in the stat row instead
	// (renderExternalLinkToggle()), always visible rather than only once
	// expanded, so this function no longer needs the permalink at all.
	function expandBodyHtml(html) {
		if (!html) {
			return '';
		}
		// Already wp_kses_post()-sanitized server-side (both the Mark/post
		// content endpoint and the subscription click-through fetch) and
		// meant to be rendered as trusted HTML — not re-escaped here.
		return `<div class="daymark-expand-content">${html}</div>`;
	}

	function expandErrorHtml() {
		return '<p class="daymark-error" role="alert">' + esc(__("Couldn't load full content.", 'daymark')) + '</p>';
	}

	// Swaps each <img src> in cached content for a local object URL built
	// from that image's own cached Blob (see cacheContentImages()), so a
	// bookmarked item's images still render with no connectivity instead
	// of showing as broken links (issue #236) — the surrounding markup
	// was already viewable offline; only its images weren't, since they
	// were still just live URLs. An image with no cached Blob (its own
	// fetch failed at cache time, or the source added it since) is left
	// pointing at its original, still-offline-broken URL — nothing new to
	// fall back to for that one. The object URLs created here are never
	// explicitly revoked; the browser reclaims them at the latest on page
	// unload, and a bookmarked item's own image count is small enough at
	// personal-site scale that this is no different from any other
	// offline Blob this codebase already keeps for a session's duration.
	function rewriteContentImagesForOffline(html, images) {
		if (!html || !images || !Object.keys(images).length) {
			return html;
		}
		const template = document.createElement('template');
		template.innerHTML = html;
		template.content.querySelectorAll('img[src]').forEach((img) => {
			const src = img.getAttribute('src');
			const blob = src && images[src];
			if (blob) {
				img.setAttribute('src', URL.createObjectURL(blob));
			}
		});
		return template.innerHTML;
	}

	// A bookmarked item's cached content (see cacheBookmarkOffline()) is
	// the fallback for both loaders below, only reached on a
	// connectivity-shaped failure of the live fetch — an actual server
	// error (a real HTTP response, not a network failure) is rethrown
	// unchanged rather than silently masked by stale cached content.
	async function loadExpandHtmlOffline(err, id) {
		if (!(err instanceof TypeError) && navigator.onLine) {
			throw err;
		}
		const cached = await getCachedBookmark(id);
		if (!cached || !cached.content) {
			throw err;
		}
		return rewriteContentImagesForOffline(String(cached.content), cached.images);
	}

	// A Mark or ordinary post's own content — straight from the site's own
	// database via GET /marks/{id}/content, so there's no page to narrow
	// down in the first place (unlike a subscription post's external
	// click-through fetch, below): no comments, no theme chrome, ever.
	async function loadMarkExpandHtml(item) {
		let content;
		try {
			const full = await apiGet('marks/' + item.id + '/content');
			content = full && full.content ? String(full.content) : '';
		} catch (err) {
			content = await loadExpandHtmlOffline(err, item.id);
		}
		return expandBodyHtml(content);
	}

	// Shared by the normal load and a forced refresh (the "Refresh content"
	// action, PostScreen.load(true) below — see the `refresh` REST param's
	// own docblock) so the two call paths can never resolve to different
	// content. A forced refresh has no offline fallback worth falling back
	// *to* (the whole point is a fresh live copy) — its own failure is left
	// to propagate rather than silently masked by stale cached content the
	// way a normal load's connectivity-shaped failure already is. Returns
	// just the body — the Reply/"Refresh content" actions are chrome around
	// it now, part of PostScreen's own actionsHtml(), not baked into the
	// loaded content string.
	async function fetchSubscriptionExpandBody(item, forceRefresh) {
		let content;
		try {
			const path = 'subscription-posts/' + item.id + (forceRefresh ? '?refresh=1' : '');
			const full = await apiGet(path);
			content = full && full.body_content ? String(full.body_content) : '';
		} catch (err) {
			if (forceRefresh) {
				throw err;
			}
			content = await loadExpandHtmlOffline(err, item.id);
		}
		return expandBodyHtml(content);
	}

	// Jump into a fresh composer seeded to reply to a subscribed post —
	// abandons (autosaving first) any in-progress composition, same as the
	// Home launcher's own bubbles and openDraft(). `title` is a one-time
	// display hint for the "Replying to" chip (CreateScreen.render()), not
	// itself sent to the server; only the URL is (state.replyTo.url, via
	// buildMarkPayload()'s inReplyTo field).
	function startReplyToSubscriptionPost(url, title) {
		abandonComposer();
		state.replyTo = { url, title };
		navigate('#create');
	}

	// One-shot hand-off from whichever feed-list screen (Home or Search) a
	// post's card was tapped on to PostScreen — the same pattern
	// state.pendingType/searchPreset already use for a screen-to-screen
	// seed. Carries the item data the calling screen already has (from its
	// own _byMarkId/_bySubId) so PostScreen never needs to re-fetch a
	// summary it was just shown, plus `returnTo` (the hash to go back to)
	// since #post has no fixed back destination the way Notifications/
	// Create/Publish do.
	let pendingPostView = null;

	function openPostView(kind, item) {
		pendingPostView = { kind, item, returnTo: window.location.hash || '#home' };
		navigate('#post');
	}

	// --- Screen: full-screen post view ---
	//
	// A client-side-only screen (like #create/#publish/#success — no PHP
	// route; see Daymark_Routes::SCREENS) since the post being shown is
	// handed off in memory via pendingPostView, not encoded in the URL —
	// there's nothing to deep-link to on a cold load. showScreen()'s own
	// guard sends a direct/refreshed #post with no pending hand-off back to
	// Home, matching #publish/#success's existing missing-state guards.
	const PostScreen = {
		render() {
			const view = pendingPostView;
			const item = view && view.item ? view.item : {};
			const title = item.title || __('Post', 'daymark');
			return `
			<header class="daymark-topbar">
				${backLinkWithIcon(
					view && view.returnTo ? view.returnTo : '#home',
					__('Back to Timeline', 'daymark')
				)}
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(title)}</h1>
			</header>
			<section class="daymark-screen">
				<div class="daymark-postview" data-postview-body>
					${skeletonRows(3)}
					<span class="daymark-visually-hidden">${esc(__('Loading post', 'daymark'))}</span>
				</div>
			</section>`;
		},

		bindEvents() {
			const body = root.querySelector('[data-postview-body]');
			if (body) {
				body.addEventListener('click', (event) => this.onClick(event));
			}
		},

		onClick(event) {
			const replyTrigger = event.target.closest('[data-reply-to]');
			if (replyTrigger) {
				event.preventDefault();
				startReplyToSubscriptionPost(
					replyTrigger.getAttribute('data-reply-to') || '',
					replyTrigger.getAttribute('data-reply-title') || ''
				);
				return;
			}
			const refreshTrigger = event.target.closest('[data-refresh-subpost]');
			if (refreshTrigger) {
				event.preventDefault();
				this.load(true);
			}
		},

		// showScreen()'s own guard already redirects a direct/refreshed
		// #post with no pending hand-off back to Home before this ever
		// runs — same trust its #publish/#success guards get from their
		// own render()/init().
		async init() {
			this.view = pendingPostView;
			pendingPostView = null;
			await this.load(false);
		},

		// `forceRefresh` only ever applies to a subscription post — a Mark
		// or ordinary post's own content has no separate cached-vs-live
		// state to force past (loadMarkExpandHtml() always reads straight
		// from this site's own database).
		async load(forceRefresh) {
			const body = root.querySelector('[data-postview-body]');
			if (!body) {
				return;
			}
			const { kind, item } = this.view;
			body.innerHTML =
				'<p class="daymark-loading"><span class="daymark-spinner" aria-hidden="true"></span> ' +
				esc(__('Loading…', 'daymark')) +
				'</p>';
			try {
				const html =
					'sub' === kind
						? await fetchSubscriptionExpandBody(item, forceRefresh)
						: await loadMarkExpandHtml(item);
				if (body.isConnected) {
					body.innerHTML = (html || expandErrorHtml()) + this.actionsHtml();
				}
			} catch (err) {
				if (body.isConnected) {
					body.innerHTML = expandErrorHtml();
				}
			}
		},

		// A subscription post's own actions — a Mark/ordinary post's own
		// content has neither: replying to yourself makes no sense, and
		// there's no separate cached-vs-live copy to force a refresh past.
		actionsHtml() {
			const item = this.view.item;
			if ('sub' !== this.view.kind) {
				return '';
			}
			// A reply here rides Daymark's own POSSE markup rather than a
			// real Webmention protocol implementation: the published Mark's
			// u-in-reply-to link (Daymark_Microformats) is what any
			// Webmention plugin the site owner already runs auto-notifies
			// on publish. See CLAUDE.md's "Webmention: rescoped to lean on
			// ecosystem plugins" decision for why nothing here sends/
			// verifies a Webmention itself.
			const reply = item.permalink
				? `<button type="button" class="daymark-btn daymark-btn--text" data-reply-to="${esc(
						item.permalink
				  )}" data-reply-title="${esc(item.title || '')}">${esc(__('Reply', 'daymark'))}</button>`
				: '';
			// A cached post's own content is only ever extracted once, at
			// fetch time — an improvement to the server's own extraction
			// logic (a new stripping pass, say) never reaches an
			// already-cached post again on its own. This is the one way to
			// force that: re-fetch the source page live and re-run
			// extraction against it right now.
			const refresh = `<button type="button" class="daymark-btn daymark-btn--text" data-refresh-subpost="${esc(
				String(item.id)
			)}">${esc(__('Refresh content', 'daymark'))}</button>`;
			return `<p class="daymark-note-card__links">${reply}${refresh}</p>`;
		},
	};

	// --- Screen: Publish ---

	// Why a connector can't take the current Mark type, phrased by what
	// it does accept ("Needs video" for YouTube/TikTok, "Needs an image"
	// for Instagram).
	function unsupportedReason(connector) {
		const supports = Array.isArray(connector.supports) ? connector.supports : [];
		if (supports.includes('video') && !supports.includes('image')) {
			return __('Needs video', 'daymark');
		}
		if (supports.includes('image') && !supports.includes('note')) {
			return __('Needs an image', 'daymark');
		}
		return __('Unavailable', 'daymark');
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
						<span class="daymark-recent__meta">${esc(
							__(
								'No social networks connected yet — your site is the only destination. Connect one via a Daymark connector plugin (Settings → Connectors).',
								'daymark'
							)
						)}</span>
					</span></span>
				</li>`;

			const connectorRows = connectors
				.map((connector) => {
					const supported = connectorSupportsType(connector, state.primaryType);
					const checked = supported && state.targets.includes(connector.id) ? ' checked' : '';
					const chip = supported
						? `<span class="daymark-chip ${connector.connected ? 'daymark-chip--success' : 'daymark-chip--muted'}">${esc(
								connector.status_label || __('Mocked · Not connected', 'daymark')
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
							)}" data-connector="${esc(connector.id)}"${checked}${supported ? '' : ' disabled'} aria-label="${esc(
								supported
									? sprintf(
											/* translators: %s: connector name */
											__('Publish to %s', 'daymark'),
											connector.label
									  )
									: sprintf(
											/* translators: 1: connector name, 2: Mark type label */
											__('%1$s does not support %2$s Marks', 'daymark'),
											connector.label,
											TYPE_LABELS[state.primaryType] || state.primaryType
									  )
							)}" />
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
							<span class="daymark-chip daymark-chip--muted">${esc(__('Via plugin', 'daymark'))}</span>
						</span>
						<span class="daymark-toggle">
							<input type="checkbox" class="daymark-toggle__input" id="daymark-helper-${esc(
								helper.id
							)}" data-helper="${esc(helper.id)}"${
						state.helpers.includes(helper.id) ? ' checked' : ''
					} aria-label="${esc(
						sprintf(
							/* translators: %s: helper plugin name */
							__('Also publish through %s', 'daymark'),
							helper.label
						)
					)}" />
							<span class="daymark-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`
				)
				.join('');

			return `
			<header class="daymark-topbar">
				<a class="daymark-backlink" href="#create">&larr; ${esc(__('Back', 'daymark'))}</a>
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(
					__('Where should this go?', 'daymark')
				)}</h1>
			</header>
			<section class="daymark-screen">
				<p class="daymark-typebadge">${sprintf(
					/* translators: 1: "a"/"an" article, 2: Mark type chip markup */
					esc(__('Publishing %1$s %2$s Mark', 'daymark')),
					/^[aeiou]/i.test(TYPE_LABELS[state.primaryType] || '') ? esc(__('an', 'daymark')) : esc(__('a', 'daymark')),
					`<span class="daymark-chip">${esc(TYPE_LABELS[state.primaryType])}</span>`
				)}</p>
				<ul class="daymark-destlist">
					<li class="daymark-dest daymark-dest--locked">
						<span class="daymark-dest__row">
							<span class="daymark-dest__info">
								<span class="daymark-dest__name">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
									${esc(__('Your Site', 'daymark'))}
								</span>
								<span class="daymark-chip daymark-chip--success">${esc(__('Required', 'daymark'))}</span>
							</span>
							<span class="daymark-toggle">
								<input type="checkbox" class="daymark-toggle__input" checked disabled aria-label="${esc(
									__('Your Site (always included)', 'daymark')
								)}" />
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
					return `<p class="daymark-helpers-note">${sprintf(
						/* translators: %s: list of publishing helper plugin names */
						esc(
							__(
								'Your site’s publishing tools will also share this Mark, per their own settings: %s.',
								'daymark'
							)
						),
						`<strong>${names}</strong>`
					)}</p>`;
				})()}
				${this.renderCategories()}
			</section>
			<footer class="daymark-actionbar">
				<p class="daymark-status" data-publish-status aria-live="polite"></p>
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="publish">${esc(
					__('Publish Now', 'daymark')
				)}</button>
				<button type="button" class="daymark-btn daymark-btn--secondary" data-action="save-draft">${esc(
					__('Save as Draft', 'daymark')
				)}</button>
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
					} aria-label="${esc(
						sprintf(
							/* translators: %s: category name */
							__('File under %s', 'daymark'),
							cat.name
						)
					)}" />
							<span class="daymark-toggle__track" aria-hidden="true"></span>
						</span>
					</label>
				</li>`
				)
				.join('');
			const typeLabel = esc(TYPE_LABELS[state.primaryType] || __('these', 'daymark'));
			return `
				<h2 class="daymark-section-heading daymark-publish-subhead">${esc(__('File under', 'daymark'))}</h2>
				<p class="daymark-publish-subnote">${sprintf(
					/* translators: %s: Mark type label (e.g. "Image") */
					esc(__('Saved as the default for %s Marks — change it any time.', 'daymark')),
					typeLabel
				)}</p>
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
			button.textContent = isDraft ? __('Saving…', 'daymark') : __('Publishing…', 'daymark');
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
					button.textContent = isDraft ? __('Save as Draft', 'daymark') : __('Publish Now', 'daymark');
					status.textContent = sprintf(
						/* translators: %s: error message */
						isDraft ? __('Save failed: %s', 'daymark') : __('Publish failed: %s', 'daymark'),
						err2.message
					);
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
				<h1 class="daymark-topbar__title daymark-visually-hidden" tabindex="-1" data-daymark-focus>${esc(
					publish.wasDraft ? __('Draft saved', 'daymark') : __('Published', 'daymark')
				)}</h1>
			</header>
			<section class="daymark-screen daymark-success">
				<span class="daymark-success__icon">
					<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
				</span>
				<div data-success-detail aria-live="polite">${this.renderDetail(publish)}</div>
				<a class="daymark-success__link" href="#home">${esc(__('View Timeline →', 'daymark'))}</a>
			</section>
			<footer class="daymark-actionbar">
				<button type="button" class="daymark-btn daymark-btn--primary" data-action="create-another">${esc(
					__('Create Another', 'daymark')
				)}</button>
				<p class="daymark-status"><a class="daymark-btn--text daymark-btn" href="#home">${esc(
					__('View Timeline →', 'daymark')
				)}</a></p>
			</footer>`;
		},

		renderDetail(publish) {
			const response = publish.response;

			if (!response) {
				return `
				<h2 class="daymark-screen__heading">${esc(
					publish.wasDraft ? __('Saved as draft', 'daymark') : __('Published', 'daymark')
				)}</h2>
				<p class="daymark-note-card__meta">${esc(
					publish.wasDraft
						? __("Saving in the background — you'll find it under Drafts on Home once it's done.", 'daymark')
						: __("Uploading in the background — it'll appear in Recent Marks as soon as it's done.", 'daymark')
				)}</p>`;
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
			<h2 class="daymark-screen__heading">${esc(
				publish.wasDraft ? __('Saved as draft', 'daymark') : __('Published to your site', 'daymark')
			)}${
				!publish.wasDraft && permalink
					? ` <a class="daymark-success__viewlink" href="${esc(permalink)}" target="_blank" rel="noopener">(${esc(
							__('view', 'daymark')
					  )})</a>`
					: ''
			}</h2>
			${
				publish.wasDraft
					? '<p class="daymark-note-card__meta">' +
					  esc(__('Finish it any time from Recent Marks on Home.', 'daymark')) +
					  '</p>'
					: ''
			}
			${
				publish.wasDraft
					? publish.targets.length
						? '<p class="daymark-note-card__meta">' +
						  esc(__('Selected destinations will publish when this Mark goes live.', 'daymark')) +
						  '</p>'
						: ''
					: rows
					? `<ul class="daymark-syndication" aria-label="${esc(
							__('Syndication status', 'daymark')
					  )}">${rows}</ul>`
					: '<p class="daymark-note-card__meta">' + esc(__('No social destinations selected.', 'daymark')) + '</p>'
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
				return label === 'published'
					? pretty
					: sprintf(
							/* translators: %s: status label (e.g. "Queued") */
							__('%s (demo mode)', 'daymark'),
							pretty
					  );
			}
			return __('Mocked (demo mode)', 'daymark');
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

	// Groups a newest-first notification list into conversations (issue
	// #258): every 'comment' item belonging to the same Mark (post_id)
	// becomes one entry's `comments` array instead of scattering across the
	// list as separate items. A group's position is set by its *first*
	// occurrence — since the input is already sorted newest-first overall,
	// the first time a post_id appears is guaranteed to carry that
	// conversation's own most recent reply, so no separate re-sort is
	// needed to keep conversations themselves ordered newest-first.
	// Subscription-issue items ('dead_feed'/'feed_issue') aren't part of
	// any conversation and pass through as their own single-item group,
	// keeping their exact position in the merged, timestamp-sorted list.
	function groupNotificationItems(items) {
		const groups = [];
		const byPostId = new Map();
		items.forEach((item) => {
			if ('dead_feed' === item.type || 'feed_issue' === item.type) {
				groups.push({ kind: 'issue', item });
				return;
			}
			const postId = item.post_id;
			let group = byPostId.get(postId);
			if (!group) {
				group = {
					kind: 'conversation',
					postId,
					postTitle: item.post_title || '',
					postUrl: item.post_url || '',
					comments: [],
				};
				byPostId.set(postId, group);
				groups.push(group);
			}
			group.comments.push(item);
		});
		return groups;
	}

	// Reply text a user has started typing, keyed by comment ID — kept in
	// memory (not sent anywhere) so switching between replies, closing and
	// reopening the same one, or navigating back to Notifications never
	// silently discards an unsent reply. Cleared on send or explicit Cancel.
	const replyDrafts = {};

	const NotificationsScreen = {
		render() {
			const backLink = backLinkWithIcon('#home', __('Back to Timeline', 'daymark'));
			return `
			<header class="daymark-topbar">
				${backLink}
				<h1 class="daymark-topbar__title" tabindex="-1" data-daymark-focus>${esc(
					__('Notifications', 'daymark')
				)}</h1>
			</header>
			<section class="daymark-screen">
				<h2 class="daymark-section-heading">${esc(__('Recent Activity', 'daymark'))}</h2>
				<div class="daymark-notif-filter" data-notif-filter hidden>
					<label class="daymark-visually-hidden" for="daymark-notif-source">${esc(
						__('Filter by source', 'daymark')
					)}</label>
					<select id="daymark-notif-source" class="daymark-sourcefilter" data-notif-source-filter>
						<option value="">${esc(__('All', 'daymark'))}</option>
					</select>
				</div>
				<div class="daymark-recent__list" data-notification-list aria-live="polite">
					${skeletonRows(3)}
					<span class="daymark-visually-hidden">${esc(__('Loading notifications', 'daymark'))}</span>
				</div>
			</section>`;
		},

		bindEvents() {},

		// The full, unfiltered fetch — items are grouped/filtered from this
		// in memory (issue #258), never re-fetched, since the endpoint
		// already returns its whole (capped, unpaginated) result in one
		// request.
		items: [],
		sourceFilter: '',

		async init() {
			this.items = [];
			this.sourceFilter = '';
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
						'<p class="daymark-empty">' + esc(__('No new activity for your Marks.', 'daymark')) + '</p>';
					return;
				}
				this.items = items;
				this.bindSourceFilter(root.querySelector('[data-notif-filter]'), root.querySelector('[data-notif-source-filter]'));
				this.renderList(list);
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
						'<p class="daymark-error" role="alert">' +
						sprintf(
							/* translators: %s: error message */
							esc(__('Could not load notifications. %s', 'daymark')),
							esc(err.message)
						) +
						'</p>';
				}
			}
		},

		// A subscription-issue item isn't "from" a reply source the way a
		// comment is (it's a feed-health alert, not a reply) — it stays
		// visible regardless of the source filter (issue #258).
		isIssueItem(item) {
			return 'dead_feed' === item.type || 'feed_issue' === item.type;
		},

		// The source filter's options, derived from what's actually in this
		// user's own notifications rather than a hardcoded network list —
		// so it never offers a source that has nothing to show, and never
		// needs updating when a new connector or federation protocol
		// starts appearing here. Built once per fetch, not per filter
		// change.
		sourceFilterOptions() {
			const seen = new Map();
			this.items.forEach((item) => {
				if (this.isIssueItem(item)) {
					return;
				}
				const value = item.source || 'site';
				if (!seen.has(value)) {
					seen.set(value, item.source_label || __('On-site comment', 'daymark'));
				}
			});
			return Array.from(seen, ([value, label]) => ({ value, label }));
		},

		// Only rendered/shown once there's more than one source to choose
		// between — a single-source inbox (the common case for a new
		// install) has nothing to filter.
		bindSourceFilter(wrap, select) {
			if (!wrap || !select) {
				return;
			}
			const options = this.sourceFilterOptions();
			if (options.length < 2) {
				wrap.hidden = true;
				return;
			}
			select.innerHTML =
				`<option value="">${esc(__('All', 'daymark'))}</option>` +
				options.map((opt) => `<option value="${esc(opt.value)}">${esc(opt.label)}</option>`).join('');
			wrap.hidden = false;
			select.value = this.sourceFilter;
			select.addEventListener('change', () => {
				this.sourceFilter = select.value;
				this.renderList(root.querySelector('[data-notification-list]'));
			});
		},

		filteredItems() {
			if (!this.sourceFilter) {
				return this.items;
			}
			return this.items.filter((item) => this.isIssueItem(item) || item.source === this.sourceFilter);
		},

		// Renders the (already-fetched, in-memory) list: comment items are
		// grouped into one conversation card per Mark (issue #258) instead
		// of each reply scattering as its own flat card; subscription-issue
		// items stay standalone, interleaved by their own position in the
		// server's newest-first ordering — unaffected by grouping since
		// they were never part of any conversation.
		renderList(list) {
			if (!list || !list.isConnected) {
				return;
			}
			const filtered = this.filteredItems();
			if (!filtered.length) {
				list.innerHTML = '<p class="daymark-empty">' + esc(__('Nothing matches this filter.', 'daymark')) + '</p>';
				return;
			}
			list.innerHTML = groupNotificationItems(filtered)
				.map((group) => this.renderGroup(group))
				.join('');
			this.bindShowMore(list);
		},

		renderGroup(group) {
			if ('conversation' !== group.kind) {
				return this.renderItem(group.item);
			}
			const heading = group.postUrl
				? `<a href="${esc(group.postUrl)}">${esc(group.postTitle || __('(untitled Mark)', 'daymark'))}</a>`
				: esc(group.postTitle || __('(untitled Mark)', 'daymark'));
			return `
			<section class="daymark-conversation">
				<h3 class="daymark-conversation__heading">${heading}</h3>
				${group.comments.map((comment) => this.renderItem(comment)).join('')}
			</section>`;
		},

		renderItem(item) {
			if ('dead_feed' === item.type || 'feed_issue' === item.type) {
				return this.renderSubscriptionIssueItem(item);
			}

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
			// No "on {post title}" meta part here — every comment now
			// renders inside its own conversation card (issue #258), whose
			// own heading already names the Mark it belongs to.
			// A reply targets a specific comment; only offer it when we have a
			// comment id to reply to.
			const replyId = 'daymark-reply-' + commentId;
			return `
			<article class="daymark-note-card"${commentId ? ` data-comment-id="${esc(String(commentId))}"` : ''}>
				<span class="daymark-chip">${esc(item.source_label || __('Comment', 'daymark'))}</span>
				<p class="daymark-note-card__text daymark-clamp">${esc(text)}</p>
				${
					long
						? '<button type="button" class="daymark-note-card__showmore" data-showmore aria-expanded="false">' +
						  esc(__('Show more', 'daymark')) +
						  '</button>'
						: ''
				}
				${metaParts.length ? `<p class="daymark-note-card__meta">${metaParts.join(' &middot; ')}</p>` : ''}
				<div class="daymark-note-card__links">
					${
						item.post_url
							? `<a class="daymark-note-card__link" href="${esc(item.post_url)}">${esc(
									__('→ View Mark', 'daymark')
							  )}</a>`
							: ''
					}
					${
						item.source_url
							? `<a class="daymark-note-card__link" href="${esc(item.source_url)}" target="_blank" rel="noopener">${esc(
									__('↗ View on network', 'daymark')
							  )}</a>`
							: ''
					}
					${
						commentId
							? `<button type="button" class="daymark-note-card__reply" data-reply-toggle aria-expanded="false" aria-controls="${replyId}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg> ${esc(
									__('Reply', 'daymark')
							  )}</button>`
							: ''
					}
				</div>
				${
					commentId
						? `<div class="daymark-reply" id="${replyId}" data-reply-form hidden>
						<label class="daymark-visually-hidden" for="${replyId}-input">${esc(__('Your reply', 'daymark'))}</label>
						<textarea id="${replyId}-input" class="daymark-textarea daymark-reply__input" data-reply-input rows="2" placeholder="${esc(
								__('Write a reply…', 'daymark')
						  )}">${esc(replyDrafts[commentId] || '')}</textarea>
						<div class="daymark-reply__actions">
							<button type="button" class="daymark-btn daymark-btn--primary" data-reply-send>${esc(
								__('Send reply', 'daymark')
							)}</button>
							<button type="button" class="daymark-btn daymark-btn--text" data-reply-cancel>${esc(
								__('Cancel', 'daymark')
							)}</button>
						</div>
						<p class="daymark-reply__status" data-reply-status aria-live="polite"></p>
					</div>
					<p class="daymark-note-card__replied" data-replied hidden>${esc(__('Reply sent.', 'daymark'))}</p>`
						: ''
				}
			</article>`;
		},

		// A subscription currently having trouble fetching new posts
		// (`feed_issue`, still `active`) or fully flagged dead (`dead_feed`,
		// `status: 'error'`) — surfaced here instead of a separate wp-admin
		// notice (issue #189): this screen is already where a Daymark user
		// looks for "what needs my attention," so a second, admin-only
		// heads-up would just be a duplicate alert most users would never
		// see anyway. No reply/comment affordances apply — the only action
		// is managing the subscription itself, in wp-admin.
		renderSubscriptionIssueItem(item) {
			const isDead = 'dead_feed' === item.type;
			const siteLabel = item.site_title || item.site_url || __('A subscribed site', 'daymark');
			const metaParts = [];
			if (item.last_error) {
				metaParts.push(
					esc(
						isDead
							? item.last_error
							: sprintf(
									/* translators: %s: fetch failure reason */
									__('Recent fetch issue: %s', 'daymark'),
									item.last_error
							  )
					)
				);
			}
			if (item.last_checked_at) {
				const when = relativeTime(item.last_checked_at);
				if (when) {
					metaParts.push(esc(when));
				}
			}
			return `
			<article class="daymark-note-card">
				<span class="daymark-chip daymark-chip--danger">${esc(
					isDead ? __('Feed error', 'daymark') : __('Feed issue', 'daymark')
				)}</span>
				<p class="daymark-note-card__text">${esc(siteLabel)}</p>
				${metaParts.length ? `<p class="daymark-note-card__meta">${metaParts.join(' &middot; ')}</p>` : ''}
				<div class="daymark-note-card__links">
					<a class="daymark-note-card__link" href="${esc(config.adminSubscriptionsUrl || '#')}">${esc(
						__('→ Manage subscriptions', 'daymark')
					)}</a>
				</div>
			</article>`;
		},

		bindShowMore(list) {
			list.querySelectorAll('[data-showmore]').forEach((button) => {
				button.addEventListener('click', () => {
					const text = button.parentElement.querySelector('.daymark-note-card__text');
					const expanded = text.classList.toggle('is-expanded');
					button.textContent = expanded ? __('Show less', 'daymark') : __('Show more', 'daymark');
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
				status.textContent = __('Write a reply first.', 'daymark');
				input.focus();
				return;
			}
			sendBtn.disabled = true;
			if (cancelBtn) {
				cancelBtn.disabled = true;
			}
			sendBtn.textContent = __('Sending…', 'daymark');
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
				sendBtn.textContent = __('Send reply', 'daymark');
				status.textContent = sprintf(
					/* translators: %s: error message */
					__('Reply failed. %s', 'daymark'),
					err.message
				);
			}
		},
	};

	// --- Init ---

	SCREENS = {
		'#home': HomeScreen,
		'#create': CreateScreen,
		'#publish': PublishScreen,
		'#success': SuccessScreen,
		'#post': PostScreen,
		'#notifications': NotificationsScreen,
		'#explore': ExploreScreen,
		'#search': SearchScreen,
		'#me': MeScreen,
	};

	window.addEventListener('hashchange', () => {
		showScreen(window.location.hash);
	});

	// A drop landing outside the composer's own picker zone (issue #260)
	// would otherwise fall through to the browser's default behavior —
	// navigating the whole app away to the dropped file's raw content,
	// losing whatever composer state was in progress. This permanent,
	// app-wide guard runs regardless of which screen is open (not just
	// Create), since the cost of getting it wrong (a lost draft) is the
	// same everywhere; the picker zone's own dragover/drop handlers
	// (CreateScreen.bindEvents()) still run first and do the real work.
	window.addEventListener('dragover', (event) => event.preventDefault());
	window.addEventListener('drop', (event) => event.preventDefault());

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
	} else if (config.pendingType) {
		// An external launcher (wp-admin's "+New -> Daymark" item,
		// Daymark_Admin_Bar) wants the composer to open pre-set to a
		// specific type — the same one-shot state.pendingType mechanism the
		// in-app Home launcher already uses when a bubble is tapped, just
		// seeded here from window.daymarkApp.pendingType instead.
		resetComposer();
		state.pendingType = config.pendingType;
		showScreen('#create');
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
	//
	// A session that booted from the offline-fallback shell (issue #126;
	// assets/offline-boot.js sets config.offlineShell) reloads instead of
	// flushing in place — that config is deliberately nonce-less and
	// connector/category-empty (see build_app_config()'s own docblock), so
	// the only way back to a fully real session is the same fresh
	// /daymark navigation any other cold load gets. Nothing already queued
	// is lost: IndexedDB survives the reload and flushes again on the very
	// next boot, same as this listener already does below.
	window.addEventListener('online', () => {
		if (config.offlineShell) {
			window.location.reload();
			return;
		}
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

	// --- Bookmarks: refresh the offline content cache at boot ---
	//
	// issue #193's own explicit requirement: opening Daymark on a new
	// device (or any fresh app-shell load) should make sure every
	// currently-bookmarked item's full content is cached for offline
	// viewing, not just whatever happened to already be cached on this
	// device. Never awaited — a slow or offline sync attempt must never
	// delay the app shell's own first render; syncBookmarkCache() itself
	// is already best-effort end to end (see its own docblock).
	syncBookmarkCache().catch(() => {});

	// --- Service worker (PWA; cold-offline-load support, issue #126) ---
	//
	// Registered at /daymark/sw.js (a plain templated echo of
	// assets/daymark-sw.js — see Daymark_Routes::maybe_load_app_shell()),
	// not the static file under the plugin's own assets/ directory, and
	// with an explicit scope covering the app's own base (config.appUrl,
	// e.g. /daymark/) — this is what lets the worker actually control
	// /daymark* navigations, unlike the narrower, effectively-inert
	// assets-directory-only scope this registration used before. See
	// assets/daymark-sw.js's own docblock for exactly what that worker
	// does and doesn't cache under this wider scope.
	//
	// A stale registration at the old, narrower scope (assetsUrl) is
	// explicitly unregistered first — it never controlled any real page
	// anyway (see daymark-sw.js's own history), so this is just cleanup,
	// not a behavior change for anyone already running it.
	//
	// Feature-detected and failure-tolerant throughout: if registration
	// fails (HTTP-only local sites, older browsers), the app works
	// unchanged, exactly as before this feature existed.
	if ('serviceWorker' in navigator && config.appUrl) {
		navigator.serviceWorker
			.getRegistrations()
			.then((registrations) => {
				registrations.forEach((registration) => {
					if (config.assetsUrl && registration.scope === config.assetsUrl) {
						registration.unregister().catch(() => {});
					}
				});
			})
			.catch(() => {})
			.then(() =>
				navigator.serviceWorker.register(config.appUrl + 'sw.js', { scope: config.appUrl }).catch(() => {
					/* Never let SW registration break the app. */
				})
			)
			.then(() => {
				// Warms the service worker's own redacted config.json cache
				// (issue #126) — this normal, inline-configured boot path
				// never otherwise has a reason to fetch that endpoint (it
				// already has window.daymarkApp), but without this, a
				// device's very first-ever cold-offline load would find
				// nothing cached there at all: assets/offline-boot.js is the
				// only other caller, and it only ever runs on the
				// offline-fallback shell itself.
				//
				// Deliberately gated on navigator.serviceWorker.ready, and
				// posted as a message to registration.active rather than
				// issued as a plain page-side fetch() for the service
				// worker's own fetch handler to intercept — CI investigation
				// (issue #126) found that a runtime fetch dispatched
				// immediately after .ready resolves on a registration that
				// only just finished activating is not reliably routed
				// through that worker's fetch handler yet, even though
				// .ready has already resolved: the page's own fetch settled
				// fine (a live 200 reached it in well under a second) while
				// the redact+cache.put side effect the fetch handler is
				// supposed to trigger never ran. A message posted directly
				// to the active worker has no such dependency on
				// fetch-interception timing — see assets/daymark-sw.js's
				// 'daymark-warm-config-cache' handler, which does the
				// identical fetch-and-cache work from inside the worker
				// itself instead. Fire-and-forget beyond that, like every
				// other best-effort boot task above. Skipped on the offline
				// shell itself (config.offlineShell), which already made
				// this exact fetch moments ago during its own boot.
				if (config.appUrl && !config.offlineShell) {
					navigator.serviceWorker.ready
						.then((registration) => {
							if (registration.active) {
								registration.active.postMessage({
									type: 'daymark-warm-config-cache',
									url: config.appUrl + 'config.json',
								});
							}
						})
						.catch(() => {});
				}
			})
			.catch(() => {});
	}
})();
