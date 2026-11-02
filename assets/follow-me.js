/**
 * "Follow me with Daymark" block, on the page: shows the form (it starts
 * hidden, so with scripts off only the feed address shows) and sends a
 * visitor to their own site with this site's address filled in. Their own
 * Daymark takes it from there (Daymark_Follow_Link): it opens Follow a site
 * with this address in the field, and nothing is followed until they confirm.
 *
 * No strings of its own: the error message comes from the block's
 * data-invalid attribute, translated in PHP. Plain ES2020, no build step.
 */
(function () {
	'use strict';

	const PARAM = 'daymark_follow_site';

	/**
	 * The visitor's site address, as a URL, or null when it isn't one. Like
	 * the app's own Follow a site field, an address without a scheme gets
	 * https://. Any path is kept, since a site can live in a subdirectory.
	 *
	 * @param {string} value What the visitor typed.
	 * @return {URL|null}
	 */
	function readAddress(value) {
		let raw = String(value || '').trim();

		if (!raw) {
			return null;
		}

		if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(raw)) {
			raw = 'https://' + raw.replace(/^\/+/, '');
		}

		let url;

		try {
			url = new URL(raw);
		} catch (err) {
			return null;
		}

		if ((url.protocol !== 'https:' && url.protocol !== 'http:') || !url.hostname || url.username || url.password) {
			return null;
		}

		url.search = '';
		url.hash = '';

		return url;
	}

	/**
	 * @param {HTMLElement} block The block's wrapper.
	 * @return {void}
	 */
	function setUp(block) {
		const form = block.querySelector('.daymark-follow-me__form');
		const input = block.querySelector('.daymark-follow-me__input');
		const error = block.querySelector('.daymark-follow-me__error');
		const site = block.getAttribute('data-site') || '';

		if (!form || !input || !site) {
			return;
		}

		function clearError() {
			input.removeAttribute('aria-invalid');

			if (error) {
				error.textContent = '';
				error.hidden = true;
			}
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			const url = readAddress(input.value);

			if (!url) {
				input.setAttribute('aria-invalid', 'true');

				if (error) {
					error.textContent = block.getAttribute('data-invalid') || '';
					error.hidden = false;
				}

				input.focus();
				return;
			}

			clearError();
			url.searchParams.set(PARAM, site);
			window.location.assign(url.toString());
		});

		input.addEventListener('input', clearError);
		form.hidden = false;
	}

	function init() {
		document.querySelectorAll('[data-daymark-follow-me]').forEach(setUp);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
