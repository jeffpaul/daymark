/**
 * Settings -> Daymark screen behavior:
 *
 * 1. Gives the Subscribe button a loading state on submit. Purely cosmetic —
 *    that form still POSTs and reloads the page the normal wp-admin way, so
 *    this has nothing to reset if the request fails; the fresh page load
 *    does that.
 * 2. Per-row Refresh forms submit via the REST refresh endpoint instead
 *    (issue #175), updating that row's Status/Last fetched cells in place
 *    rather than reloading the whole page. Requires the localized
 *    `daymarkAdminSubscriptions` config (see
 *    Daymark_Admin_Subscriptions::enqueue_assets()); without it — REST
 *    disabled, or the object simply didn't load — the form falls back to
 *    its plain admin-post.php submit, identical to this form's previous
 *    (page-reloading) behavior.
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		bindSubscribeLoadingState();
		bindRefreshForms();
	} );

	/**
	 * Gives the Subscribe button a loading state on submit (behavior 1).
	 *
	 * @return void
	 */
	function bindSubscribeLoadingState() {
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
	}

	/**
	 * Wires every per-subscription Refresh form to submit inline via the
	 * REST API instead of a full admin-post.php POST-redirect-GET (behavior
	 * 2). Each form carries the subscription ID it refreshes on its own
	 * `data-daymark-subscription-id` attribute (see
	 * Daymark_Admin_Subscriptions::render_refresh_form()).
	 *
	 * @return void
	 */
	function bindRefreshForms() {
		var config = window.daymarkAdminSubscriptions;

		if ( ! config ) {
			return;
		}

		var forms = document.querySelectorAll( '.daymark-subscription-refresh-form' );

		forms.forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				refreshSubscription( form, config );
			} );
		} );
	}

	/**
	 * Submit one Refresh form's request via the REST refresh endpoint and
	 * apply the result to that form's own row.
	 *
	 * @param {HTMLFormElement} form   The Refresh form that was submitted.
	 * @param {Object}          config Localized daymarkAdminSubscriptions config.
	 * @return void
	 */
	function refreshSubscription( form, config ) {
		var id = form.getAttribute( 'data-daymark-subscription-id' );
		var row = form.closest( 'tr' );
		var button = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		var errorEl = form.querySelector( '.daymark-subscription-refresh-error' );

		if ( ! id || ! row ) {
			// No row to update in place — fall back to the form's normal submit.
			form.submit();
			return;
		}

		setFormBusy( button, errorEl, true, config );

		fetch( config.restUrl + encodeURIComponent( id ) + '/refresh', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce }
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.body ) {
					applyRefreshedRow( row, result.body, config );
				} else {
					var message = result.body && result.body.message ? result.body.message : config.i18n.genericError;
					showRefreshError( errorEl, message );
				}
			} )
			.catch( function () {
				showRefreshError( errorEl, config.i18n.genericError );
			} )
			.then( function () {
				setFormBusy( button, errorEl, false, config );
			} );
	}

	/**
	 * Toggle a Refresh button's disabled/label state while a request is in
	 * flight, and clear any previous inline error when starting a new one.
	 *
	 * @param {HTMLInputElement|null} button
	 * @param {Element|null}          errorEl
	 * @param {boolean}               busy
	 * @param {Object}                config
	 * @return void
	 */
	function setFormBusy( button, errorEl, busy, config ) {
		if ( button ) {
			button.disabled = busy;
			button.value = busy ? config.i18n.refreshingLabel : config.i18n.refreshLabel;
		}

		if ( busy && errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}
	}

	/**
	 * Apply a successfully refreshed subscription (the REST endpoint's own
	 * prepared row shape) to its row's Status and Last fetched cells.
	 * Deliberately uses textContent throughout, never innerHTML — these
	 * values (site_title, last_error) ultimately trace back to another
	 * site's own HTTP response, so this never re-parses anything as markup.
	 *
	 * @param {Element} row          The subscription's <tr>.
	 * @param {Object}  subscription Prepared subscription row from the REST response.
	 * @param {Object}  config
	 * @return void
	 */
	function applyRefreshedRow( row, subscription, config ) {
		var statusText = row.querySelector( '.daymark-subscription-status-text' );
		var statusError = row.querySelector( '.daymark-subscription-status-error' );
		var lastFetched = row.querySelector( '.daymark-subscription-last-fetched' );
		var isError = 'error' === subscription.status;

		if ( statusText ) {
			statusText.textContent = isError ? config.i18n.statusError : config.i18n.statusActive;
		}

		if ( statusError ) {
			if ( isError && subscription.last_error ) {
				statusError.textContent = subscription.last_error;
				statusError.hidden = false;
			} else {
				statusError.textContent = '';
				statusError.hidden = true;
			}
		}

		if ( lastFetched ) {
			lastFetched.textContent = config.i18n.justNow;
		}
	}

	/**
	 * Show a failed refresh's message inline, next to that row's Refresh
	 * button, instead of a page-level admin notice — the whole point of
	 * this form no longer reloading the page.
	 *
	 * @param {Element|null} errorEl
	 * @param {string}       message
	 * @return void
	 */
	function showRefreshError( errorEl, message ) {
		if ( ! errorEl ) {
			return;
		}

		errorEl.textContent = message;
		errorEl.hidden = false;
	}
}());
