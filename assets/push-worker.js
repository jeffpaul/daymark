/** Daymark push handlers, bundled into the wp-app service worker. */
const REST_BASE_URL = self.wpAppWorker.appData.restUrl;
const PUSH_CACHE_NAME = 'daymark-push-v1';
const PUSH_TOKEN_CACHE_KEY = '/daymark/push-token';

// Retire caches from Daymark's previous worker, preserving the device push token.
self.addEventListener('activate', (event) => {
	event.waitUntil(caches.keys().then((names) => Promise.all(names
		.filter((name) => name.startsWith('daymark-') && name !== PUSH_CACHE_NAME)
		.map((name) => caches.delete(name)))));
});

// Daymark's logout handshake clears the current shell and device push token.
self.addEventListener('message', (event) => {
	if (!event.data || event.data.type !== 'daymark-clear-offline-cache') return;
	event.waitUntil(Promise.all([caches.delete(self.wpAppWorker.cacheName), caches.delete(PUSH_CACHE_NAME)]).then(() => {
		if (event.ports && event.ports[0]) event.ports[0].postMessage({ done: true });
	}));
});

// Web push: show the message the site sent (see Daymark_Push_Sender), and
// open the app where it points when the notification is tapped. Every
// push must show a notification; Safari revokes push for a site that
// doesn't.
self.addEventListener('push', (event) => {
	let data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch (e) {
		data = {};
	}
	const text = (value) => (typeof value === 'string' ? value : '');
	const options = {
		body: text(data.body),
		data: { url: text(data.url) },
	};
	if (text(data.icon)) {
		options.icon = data.icon;
	}
	if (text(data.tag)) {
		options.tag = data.tag;
	}
	event.waitUntil(self.registration.showNotification(text(data.title) || 'Daymark', options));
});

self.addEventListener('notificationclick', (event) => {
	event.notification.close();
	const scope = self.registration.scope;
	let target = scope;
	try {
		const url = new URL((event.notification.data && event.notification.data.url) || scope, scope);
		// Only ever open this site.
		if (url.origin === self.location.origin) {
			target = url.href;
		}
	} catch (e) {
		target = scope;
	}
	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
			const open = windows.find((client) => client.url.startsWith(scope) && 'focus' in client);
			if (open) {
				return open.focus().then((client) => {
					if (client && 'navigate' in client) {
						return client.navigate(target).catch(() => client);
					}
					return client;
				});
			}
			return self.clients.openWindow(target);
		})
	);
});

async function getStoredPushToken() {
	try {
		const cache = await caches.open(PUSH_CACHE_NAME);
		const match = await cache.match(PUSH_TOKEN_CACHE_KEY);
		if (match) {
			return await match.json();
		}
	} catch (e) {
		/* Storage unavailable or unreadable. */
	}
	return null;
}

async function storePushToken(endpoint, token) {
	try {
		const cache = await caches.open(PUSH_CACHE_NAME);
		const data = new Response(JSON.stringify({ endpoint, token }), {
			headers: { 'Content-Type': 'application/json' },
		});
		await cache.put(PUSH_TOKEN_CACHE_KEY, data);
	} catch (e) {
		/* Best effort token storage. */
	}
}

// Browsers fire pushsubscriptionchange when a push subscription is renewed
// or replaced (key rotation, reset push service, etc. — issue #530).
// Resubscribe if needed, send the new subscription to the site using the
// stored capability token, then notify any open app windows so they re-sync.
self.addEventListener('pushsubscriptionchange', (event) => {
	event.waitUntil(
		(async () => {
			let newSub = event.newSubscription;
			if (!newSub) {
				const oldSub = event.oldSubscription;
				const key = (oldSub && oldSub.options && oldSub.options.applicationServerKey) || null;
				try {
					if (key) {
						newSub = await self.registration.pushManager.subscribe({
							userVisibleOnly: true,
							applicationServerKey: key,
						});
					} else {
						newSub = await self.registration.pushManager.getSubscription();
					}
				} catch (e) {
					newSub = null;
				}
			}

			if (!newSub) {
				return;
			}

			const subData = newSub.toJSON ? newSub.toJSON() : newSub;

			// 1. Send the new address to the site with the per-device token,
			// and store the token the site returns. This runs before any
			// window is told to re-sync: a window re-sync then finds a token
			// filed under the new address and sends it, so the site keeps it
			// instead of issuing a second one that races this one into the cache.
			const stored = await getStoredPushToken();
			const oldEndpoint = (event.oldSubscription && event.oldSubscription.endpoint) || (stored && stored.endpoint);
			const restBase = REST_BASE_URL;

			if (stored && stored.token && oldEndpoint && restBase) {
				try {
					const response = await fetch(restBase + 'push/subscriptions/replace', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							old_endpoint: oldEndpoint,
							token: stored.token,
							subscription: subData,
						}),
					});

					if (response.ok) {
						const result = await response.json();
						if (result && result.token && newSub.endpoint) {
							await storePushToken(newSub.endpoint, result.token);
						}
					}
				} catch (e) {
					/* Best effort background update; the window re-sync below and app-start re-sync are the fallback. */
				}
			}

			// 2. Tell any open app windows to re-sync with their logged-in session.
			try {
				const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
				for (const client of windows) {
					client.postMessage({
						type: 'daymark-push-subscription-change',
						subscription: subData,
					});
				}
			} catch (e) {
				/* Best effort window notification. */
			}
		})()
	);
});

