/**
 * Bootstrap for the offline-fallback app shell (templates/offline-shell.php,
 * issue #126) — only ever loaded from that one page, never the normal
 * online app shell (templates/app-shell.php), which still inlines
 * window.daymarkApp directly.
 *
 * That page has no inline scripts at all (see its own docblock on why), so
 * this file's whole job is: fetch the bootstrap config GET /daymark/config.json
 * would normally supply inline, then load assets/app.js only once that
 * config is actually in place as window.daymarkApp — app.js itself needs no
 * changes at all, since from its perspective the config is simply already
 * there by the time it runs, same as the normal online path.
 *
 * A network-first fetch of config.json always gets a fresh, live REST
 * nonce when one is reachable; only when genuinely offline does the service
 * worker's own cached (deliberately nonce-stripped) copy answer instead —
 * see assets/daymark-sw.js. Either way this never blocks longer than a
 * failed fetch takes to reject: on total failure (no live config and
 * nothing cached yet) the composer still opens, connector/category lists
 * empty until real connectivity returns.
 *
 * This file is itself precached by the service worker (assets/daymark-sw.js)
 * the same way app.css/app.js are — nothing else would cache or intercept a
 * request for it, so a genuinely offline load would otherwise fail to fetch
 * this script at all, leaving the offline shell's own container stuck on
 * "Loading Daymark…" with no error surfaced anywhere.
 */
(function () {
	'use strict';

	// Both attributes are server-rendered by templates/offline-shell.php from
	// DAYMARK_PLUGIN_URL (never user input), but resolving and re-checking
	// them as same-origin URLs here — rather than trusting the attribute
	// strings as-is — closes the generic DOM-text-into-a-script-sink shape a
	// static scan would otherwise flag, and costs nothing for the one real,
	// always-same-origin case this file is ever used for.
	function sameOriginUrl(value) {
		if (!value) {
			return null;
		}
		try {
			var resolved = new URL(value, window.location.href);
			return resolved.origin === window.location.origin ? resolved.href : null;
		} catch (err) {
			return null;
		}
	}

	var thisScript = document.currentScript;
	var configUrl = sameOriginUrl(thisScript && thisScript.getAttribute('data-config-url'));
	var appJsUrl = sameOriginUrl(thisScript && thisScript.getAttribute('data-app-js-url'));

	if (!configUrl || !appJsUrl) {
		return;
	}

	function loadAppJs() {
		var script = document.createElement('script');
		script.src = appJsUrl;
		document.body.appendChild(script);
	}

	fetch(configUrl, { credentials: 'same-origin' })
		.then(function (res) {
			return res.ok ? res.json() : {};
		})
		.catch(function () {
			return {};
		})
		.then(function (configData) {
			// Marks that this config arrived via the offline-fallback path
			// (rather than the normal inline-script boot) — app.js's global
			// 'online' listener reloads the page once real connectivity
			// returns, rather than trying to patch a live nonce into an
			// already-running session (see that listener's own comment).
			configData.offlineShell = true;
			window.daymarkApp = configData;
			loadAppJs();
		});
})();
