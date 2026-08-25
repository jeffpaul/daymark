/**
 * Settings -> Daymark: gives the Subscribe button a loading state on
 * submit. Purely cosmetic — the form still POSTs and reloads the page the
 * normal wp-admin way, so this has nothing to reset if the request fails;
 * the fresh page load does that.
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'daymark-subscribe-submit' );
		var form = button ? button.closest( 'form' ) : null;

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function () {
			button.disabled = true;

			if ( button.dataset.daymarkLoadingLabel ) {
				button.value = button.dataset.daymarkLoadingLabel;
			}
		} );
	} );
}());
