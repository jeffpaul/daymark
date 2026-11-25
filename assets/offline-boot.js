/** Adapt the framework bootstrap to Daymark's existing application entry point. */
(function () {
	'use strict';
	window.wpAppBootstrap.then(function (boot) {
		var config = boot.data;
		// This document reloads on reconnect to restore all live integrations.
		config.offlineShell = true;
		if (config.localeData && window.wp && window.wp.i18n) {
			window.wp.i18n.setLocaleData(config.localeData, 'daymark');
		}
		delete config.localeData;
		var appUrl = new URL('app.js', config.assetsUrl);
		if (appUrl.origin !== window.location.origin) {
			throw new Error('The application script must use the same origin.');
		}
		window.daymarkApp = config;
		var script = document.createElement('script');
		script.src = appUrl.href;
		script.onerror = function () { showError(new Error('Could not load Daymark. Reload when online.')); };
		document.body.appendChild(script);
	}).catch(showError);

	function showError(error) {
		var root = document.getElementById('daymark-app');
		if (root) root.textContent = error.message;
	}
})();
