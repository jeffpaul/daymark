/**
 * Daymark service worker (issue #126: cold-offline-load support).
 *
 * Served at /daymark/sw.js (Daymark_Routes, class-routes.php — a plain
 * templated echo of this exact file, one string substitution for
 * __DAYMARK_ASSETS_URL__) rather than as a static file under the plugin's
 * own assets/ directory. That URL is what lets this worker's registration
 * scope cover every /daymark* route with NO Service-Worker-Allowed header:
 * per the Service Worker spec, a worker's maximum scope defaults to the
 * directory of its own script URL — serving it AT /daymark/sw.js means that
 * directory already IS /daymark/, for free.
 *
 * Caches exactly three things, all safe to persist indefinitely:
 * - app.css / app.js (this plugin's own static assets — unchanged from the
 *   original, narrower-scoped version of this file)
 * - the offline-fallback shell (templates/offline-shell.php, served at
 *   /daymark/offline.html) — precached at install time
 * - GET /daymark/config.json's response, but ONLY after stripping its
 *   `nonce` field first — see the fetch handler below. The live response
 *   itself (nonce intact) is still returned to the page immediately; only
 *   the durably-stored Cache Storage copy is redacted.
 *
 * NEVER cached, at all, under any circumstance:
 * - Any real /wp-json/ REST response
 * - A WP nonce, in the sense of anything ever written to Cache Storage —
 *   config.json's own nonce field is deleted before the redacted copy is
 *   stored (see above)
 * - /wp-admin/, /wp-login.php, anything not GET
 * - The real, dynamic, per-request /daymark* navigation response itself
 *   (templates/app-shell.php) — its own per-request CSP nonce is exactly
 *   why: caching-and-replaying that response would turn a single-use nonce
 *   into a durable, inspectable, effectively-static one, undermining the
 *   XSS mitigation it exists for. A failed navigation fetch falls back to
 *   the separate, static-shaped, script-nonce-free offline shell instead —
 *   see templates/offline-shell.php's own docblock for the full reasoning.
 */

const CACHE_NAME = 'daymark-v2';

// The substituted value is an absolute URL (scheme + host + path).
const ASSETS_BASE_URL = '__DAYMARK_ASSETS_URL__';
// Only the path portion is ever compared against a request's own url.pathname.
const ASSETS_BASE_PATH = new URL(ASSETS_BASE_URL).pathname;

self.addEventListener('install', (event) => {
	const scopePath = new URL(self.registration.scope).pathname;
	event.waitUntil(
		caches
			.open(CACHE_NAME)
			.then((cache) =>
				cache.addAll([ASSETS_BASE_URL + 'app.css', ASSETS_BASE_URL + 'app.js', scopePath + 'offline.html'])
			)
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((keys) =>
				Promise.all(
					keys
						.filter((key) => key.startsWith('daymark-') && key !== CACHE_NAME)
						.map((key) => caches.delete(key))
				)
			)
			.then(() => self.clients.claim())
	);
});

// A logged-out-elsewhere / shared-device safeguard (issue #126): the Me
// screen's Log out link posts this before navigating to WordPress's real
// logout URL, so a stale cached shell/config never lingers past a
// deliberate sign-out. Clearing the whole cache (not just these two
// entries) is simplest and cheap — app.css/app.js just get re-fetched and
// re-cached on the next successful load.
self.addEventListener('message', (event) => {
	if (!event.data || 'daymark-clear-offline-cache' !== event.data.type) {
		return;
	}
	event.waitUntil(
		caches.delete(CACHE_NAME).then(() => {
			if (event.ports && event.ports[0]) {
				event.ports[0].postMessage({ done: true });
			}
		})
	);
});

self.addEventListener('fetch', (event) => {
	if (event.request.method !== 'GET') {
		return; // Network-only passthrough for all writes.
	}

	const url = new URL(event.request.url);
	const scopePath = new URL(self.registration.scope).pathname;

	if (url.origin !== self.location.origin) {
		return;
	}

	// app.css / app.js: cache-first, exactly as before this worker's scope
	// widened. ignoreSearch so a ?ver= cache-busting param still hits the
	// precached entry.
	const isStaticAsset = url.pathname === ASSETS_BASE_PATH + 'app.css' || url.pathname === ASSETS_BASE_PATH + 'app.js';
	if (isStaticAsset) {
		event.respondWith(
			caches.match(event.request, { ignoreSearch: true }).then((cached) => {
				if (cached) {
					return cached;
				}
				return fetch(event.request).then((response) => {
					if (response.ok) {
						const copy = response.clone();
						// Same event.waitUntil() reasoning as the config.json
						// branch below: without it, the cache write can lose
						// the race against the worker being torn down once
						// respondWith()'s own promise has already resolved.
						event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)));
					}
					return response;
				});
			})
		);
		return;
	}

	// config.json: network-first, since a live fetch always carries a fresh
	// nonce a cached copy never should. The redacted (nonce-stripped) copy
	// is what actually answers a later offline request.
	//
	// The redaction + cache.put() is wrapped in event.waitUntil() — without
	// it, this is a real bug: respondWith()'s own promise resolves (and the
	// response reaches the page) as soon as the outer .then() returns
	// `response`, before the inner .json()/cache.put() chain has settled,
	// and the browser is free to consider the fetch event fully handled
	// and tear the worker down at that point, aborting the still-in-flight
	// cache write. waitUntil() tells it there's more work to wait for.
	if (url.pathname === scopePath + 'config.json') {
		event.respondWith(
			fetch(event.request)
				.then((response) => {
					if (response.ok) {
						event.waitUntil(
							response
								.clone()
								.json()
								.then((data) => {
									delete data.nonce;
									const redacted = new Response(JSON.stringify(data), {
										headers: { 'Content-Type': 'application/json' },
									});
									return caches.open(CACHE_NAME).then((cache) => cache.put(event.request, redacted));
								})
								.catch(() => {
									/* Malformed/non-JSON response: nothing to cache. */
								})
						);
					}
					return response;
				})
				.catch(() => caches.match(event.request))
		);
		return;
	}

	// A real navigation to /daymark* itself: network-first, and — unlike
	// every other branch above — the successful response is never written
	// to Cache Storage (see this file's own docblock on why). Only a
	// failed fetch (no connectivity) falls back to the precached, static,
	// nonce-free offline shell.
	//
	// The `+ '/'` on both sides handles the bare, no-trailing-slash home
	// route (plain /daymark, as valid as /daymark/) — url.pathname alone
	// wouldn't startsWith('/daymark/') since it's shorter than the scope
	// prefix in that one case.
	if ('navigate' === event.request.mode && (url.pathname + '/').startsWith(scopePath)) {
		event.respondWith(fetch(event.request).catch(() => caches.match(scopePath + 'offline.html')));
		return;
	}

	// Everything else (REST, admin, login, media, any other origin-relative
	// request) passes straight to the network untouched — we never call
	// respondWith() for it.
});
