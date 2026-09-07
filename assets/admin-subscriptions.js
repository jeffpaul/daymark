/**
 * Settings -> Daymark screen behavior:
 *
 * 1. Gives the Subscribe button a loading state on submit. Purely cosmetic —
 *    that form still POSTs and reloads the page the normal wp-admin way, so
 *    this has nothing to reset if the request fails; the fresh page load
 *    does that.
 * 2. Per-row Refresh forms — rendered as a small circular-arrows icon next
 *    to "Last fetched" rather than a labeled Actions-column button (see
 *    Daymark_Admin_Subscriptions::render_refresh_form()) — submit via the
 *    REST refresh endpoint instead (issue #175), updating that row's
 *    Status/Last fetched cells in place rather than reloading the whole
 *    page, and spinning the icon (`daymark-is-refreshing`) while the
 *    request is in flight. Requires the localized
 *    `daymarkAdminSubscriptions` config (see
 *    Daymark_Admin_Subscriptions::enqueue_assets()); without it — REST
 *    disabled, or the object simply didn't load — the form falls back to
 *    its plain admin-post.php submit, identical to this form's previous
 *    (page-reloading) behavior.
 * 3. The per-row "Edit site name" pencil (a <details>/<summary> disclosure
 *    on its own — see Daymark_Admin_Subscriptions::render_edit_title_form())
 *    becomes a true inline editor: opening it focuses/selects the input,
 *    and Enter, Tab, or clicking away — all of which end in the input's
 *    own `blur` — saves via a background POST to the existing admin-post
 *    handler instead of a full page reload, then updates the visible name
 *    in place. Needs no REST endpoint or localized config of its own: the
 *    form already carries everything a real submission needs (action,
 *    subscription ID, nonce), so this is the exact same write, just not
 *    navigated to.
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		bindSubscribeLoadingState();
		bindRefreshForms();
		bindEditTitleDisclosures();
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
		var button = form.querySelector( '.daymark-subscription-refresh-trigger' );
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
	 * Toggle the Refresh icon button's disabled/processing state while a
	 * request is in flight, and clear any previous inline error when
	 * starting a new one. An icon-only control has no label to swap the
	 * way the old text button did — `daymark-is-refreshing` is what
	 * actually spins the dashicon (see enqueue_assets()'s inline style),
	 * and the `aria-label`/`title` swap to a "Refreshing…" announcement
	 * carries the same information a screen reader or hover previously got
	 * from the button's own visible text.
	 *
	 * @param {HTMLButtonElement|null} button
	 * @param {Element|null}           errorEl
	 * @param {boolean}                busy
	 * @param {Object}                 config
	 * @return void
	 */
	function setFormBusy( button, errorEl, busy, config ) {
		if ( button ) {
			button.disabled = busy;
			button.classList.toggle( 'daymark-is-refreshing', busy );
			var label = busy ? config.i18n.refreshingLabel : config.i18n.refreshLabel;
			button.setAttribute( 'aria-label', label );
			button.setAttribute( 'title', label );
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
		// Issue #182: mirrors Daymark_Admin_Subscriptions::render_subscription_row()'s
		// own $has_error_message condition — a subscription can be failing
		// (and carry a real last_error) well before consecutive_failure_count
		// reaches the dead threshold, not just once status flips to 'error'.
		var failureCount = Number( subscription.consecutive_failure_count ) || 0;
		var hasIssue = Boolean( subscription.last_error ) && ( isError || failureCount > 0 );

		if ( statusText ) {
			statusText.textContent = isError ? config.i18n.statusError : config.i18n.statusActive;
		}

		if ( statusError ) {
			if ( hasIssue ) {
				statusError.textContent = isError
					? subscription.last_error
					: config.i18n.recentFetchIssue.replace( '%s', subscription.last_error );
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

	/**
	 * Wires every per-subscription "Edit site name" <details> disclosure
	 * (behavior 3) into an inline editor: focus/select the input the
	 * moment it opens, and save on Enter, Tab, or clicking away — all of
	 * which end in the input's own `blur` event, so that one handler
	 * covers all three.
	 *
	 * @return void
	 */
	function bindEditTitleDisclosures() {
		var disclosures = document.querySelectorAll( '.daymark-subscription-edit-title' );

		disclosures.forEach( function ( details ) {
			var input = details.querySelector( '.daymark-subscription-title-input' );
			var form = details.querySelector( '.daymark-subscription-edit-title-form' );

			if ( ! input || ! form ) {
				return;
			}

			details.addEventListener( 'toggle', function () {
				if ( details.open ) {
					input.focus();
					input.select();
				}
			} );

			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					// The default action here is a real form submission
					// (the lone text field in this form), which would
					// reload the page — blur instead, so the save below
					// runs the same inline way a Tab or click-away would.
					event.preventDefault();
					input.blur();
				}
			} );

			input.addEventListener( 'blur', function () {
				saveTitleInline( details, form, input );
			} );
		} );
	}

	/**
	 * Save one "Edit site name" input's current value via a background
	 * POST to the exact same admin-post handler its form would otherwise
	 * navigate to — reusing the real write path (validation, nonce check,
	 * the DB update) unchanged, just not followed to its redirect. Updates
	 * the visible name in place on success and always closes the
	 * disclosure; a failure reverts the input to its last known-good value
	 * and shows an inline error without closing, so the user can retry.
	 *
	 * @param {Element}            details The <details> disclosure.
	 * @param {HTMLFormElement}    form    Its form (action/nonce/subscription id).
	 * @param {HTMLInputElement}   input   The site-title text input.
	 * @return void
	 */
	function saveTitleInline( details, form, input ) {
		var titleText = details.parentElement
			? details.parentElement.querySelector( '[data-daymark-title-text]' )
			: null;
		var errorEl = form.querySelector( '.daymark-subscription-title-error' );
		var fallbackLabel = details.getAttribute( 'data-daymark-fallback-label' ) || '';
		var newValue = input.value;
		var priorValue = input.defaultValue;

		if ( errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}

		if ( newValue === priorValue ) {
			details.open = false;
			return;
		}

		fetch( form.getAttribute( 'action' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData( form )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'daymark_edit_title_failed' );
				}

				if ( titleText ) {
					titleText.textContent = '' !== newValue ? newValue : fallbackLabel;
				}

				input.defaultValue = newValue;
				details.open = false;
			} )
			.catch( function () {
				input.value = priorValue;

				if ( errorEl ) {
					var config = window.daymarkAdminSubscriptions;
					errorEl.textContent = config && config.i18n
						? config.i18n.genericError
						: 'Something went wrong. Please try again.';
					errorEl.hidden = false;
				}
			} );
	}
}());
