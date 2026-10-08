/**
 * Daymark bookmarklet popup: shows a Close button when the page was opened
 * as the bookmarklet's popup window (see Daymark_Bookmarklet::script()).
 * Everything else on the page is a plain form and works without this file.
 */
(function () {
	'use strict';

	var button = document.querySelector('[data-bookmarklet-close]');

	if (!button || !(window.opener || window.name === 'daymark')) {
		return;
	}

	button.hidden = false;
	button.addEventListener('click', function () {
		window.close();
	});
})();
