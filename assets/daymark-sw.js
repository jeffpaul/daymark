/**
 * Daymark service worker — conservative static-asset cache only.
 *
 * Caches EXACTLY two files, cache-first: the plugin's app.css and app.js
 * (resolved relative to this worker's location in the plugin assets dir).
 *
 * NEVER cached:
 * - REST API responses (/wp-json/) — always fresh, never stored
 * - WP nonces or any authenticated payloads
 * - /wp-admin/ anything
 * - Media/attachment URLs
 * - HTML documents (the /daymark app shell is always network-rendered)
 *
 * SCOPE CONSTRAINT (intentional, documented):
 * This worker is served from /wp-content/plugins/daymark/assets/, so its
 * maximum scope is that directory — it CANNOT control the /daymark page
 * itself, and we deliberately do NOT add a Service-Worker-Allowed header
 * to widen the scope. That is fine here:
 * - install-time precaching below still populates the Cache Storage with
 *   app.css and app.js regardless of scope, satisfying the PWA
 *   installability + offline-asset checks;
 * - the fetch handler only ever answers for requests inside the assets
 *   scope, so there is zero risk of stale REST data, stale nonces, or a
 *   stale app shell being served;
 * - the /daymark HTML document is always fetched from the network.
 */

const CACHE_NAME = 'daymark-v1';

// Relative to this worker's location: /wp-content/plugins/daymark/assets/.
const PRECACHE_URLS = ['./app.css', './app.js'];

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches
			.open(CACHE_NAME)
			.then((cache) => cache.addAll(PRECACHE_URLS))
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

self.addEventListener('fetch', (event) => {
	if (event.request.method !== 'GET') {
		return; // Network-only passthrough for all writes.
	}

	const url = new URL(event.request.url);
	const scopePath = new URL(self.registration.scope).pathname;

	// Only ever answer for the two known static assets inside our scope.
	// Everything else (REST, admin, media, HTML) passes straight to the
	// network untouched — we never call respondWith() for it.
	const isCacheableAsset =
		url.origin === self.location.origin &&
		url.pathname.startsWith(scopePath) &&
		(url.pathname.endsWith('/app.css') || url.pathname.endsWith('/app.js'));

	if (!isCacheableAsset) {
		return;
	}

	// Cache-first; ignoreSearch so ?ver= cache-busting params still hit
	// the precached entry. Falls back to network (and refreshes the cache)
	// on miss.
	event.respondWith(
		caches.match(event.request, { ignoreSearch: true }).then((cached) => {
			if (cached) {
				return cached;
			}
			return fetch(event.request).then((response) => {
				if (response.ok) {
					const copy = response.clone();
					caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
				}
				return response;
			});
		})
	);
});
