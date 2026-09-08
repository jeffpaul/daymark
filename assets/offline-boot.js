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
 */
(function () {
	'use strict';

	var thisScript = document.currentScript;
	var configUrl = thisScript && thisScript.getAttribute('data-config-url');
	var appJsUrl = thisScript && thisScript.getAttribute('data-app-js-url');

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
