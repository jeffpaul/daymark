/**
 * Featured Content gallery slider (issue #406).
 *
 * Enqueued only on a singular post whose Featured Content type is gallery
 * (Daymark_Featured_Content::maybe_enqueue_frontend_style()). Plain ES2020,
 * no dependencies, no build step. Without this script the server markup
 * stacks every slide; once it runs, one slide shows at a time.
 */
( function () {
	'use strict';

	var SWIPE_MIN = 40;

	function announce( live, index, total ) {
		if ( ! live ) {
			return;
		}

		var template = live.getAttribute( 'data-template' ) || '';

		live.textContent = template.replace( '%1$d', String( index + 1 ) ).replace( '%2$d', String( total ) );
	}

	function init( root ) {
		var slides = Array.prototype.slice.call( root.querySelectorAll( '.daymark-fc-gallery__slide' ) );

		if ( slides.length < 2 ) {
			return;
		}

		var dots = Array.prototype.slice.call( root.querySelectorAll( '[data-daymark-gallery-dot]' ) );
		var live = root.querySelector( '[data-daymark-gallery-live]' );
		var index = 0;
		var startX = null;
		var startY = null;

		function show( next ) {
			index = ( next + slides.length ) % slides.length;

			slides.forEach( function ( slide, i ) {
				var active = i === index;

				slide.classList.toggle( 'is-active', active );
				slide.hidden = ! active;
			} );

			dots.forEach( function ( dot, i ) {
				if ( i === index ) {
					dot.setAttribute( 'aria-current', 'true' );
				} else {
					dot.removeAttribute( 'aria-current' );
				}
			} );

			announce( live, index, slides.length );
		}

		root.classList.add( 'is-enhanced' );
		show( 0 );

		root.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( ! target || ! target.closest ) {
				return;
			}

			if ( target.closest( '[data-daymark-gallery-prev]' ) ) {
				event.preventDefault();
				show( index - 1 );
			} else if ( target.closest( '[data-daymark-gallery-next]' ) ) {
				event.preventDefault();
				show( index + 1 );
			} else if ( target.closest( '[data-daymark-gallery-dot]' ) ) {
				event.preventDefault();
				show( parseInt( target.closest( '[data-daymark-gallery-dot]' ).getAttribute( 'data-index' ), 10 ) );
			}
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				show( index - 1 );
			} else if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				show( index + 1 );
			}
		} );

		// Swipe or click-drag, through pointer events so a finger, a pen, and
		// a mouse all share one path. The prev/next/dot buttons keep their
		// own click handling, so a press that starts on one is ignored here.
		// A mouse drag that moves far enough to count as a swipe also eats
		// the click that follows it, so letting go over a link or button
		// never activates it.
		var swallowClick = false;

		root.addEventListener( 'pointerdown', function ( event ) {
			if ( ( 'mouse' === event.pointerType && 0 !== event.button ) || ( event.target.closest && event.target.closest( 'button' ) ) ) {
				return;
			}

			startX = event.clientX;
			startY = event.clientY;
			swallowClick = false;

			// Keep receiving the drag's pointerup even if the pointer is
			// released outside the gallery.
			try {
				root.setPointerCapture( event.pointerId );
			} catch ( err ) {
				// Unsupported: the drag just ends when it leaves the gallery.
			}
		} );

		function endDrag( event ) {
			if ( null === startX ) {
				return;
			}

			var dx = event.clientX - startX;
			var dy = event.clientY - startY;

			startX = null;
			startY = null;

			if ( 'pointercancel' === event.type || Math.abs( dx ) < SWIPE_MIN || Math.abs( dx ) < Math.abs( dy ) ) {
				return;
			}

			swallowClick = true;
			show( dx < 0 ? index + 1 : index - 1 );
		}

		root.addEventListener( 'pointerup', endDrag );
		root.addEventListener( 'pointercancel', endDrag );

		root.addEventListener(
			'click',
			function ( event ) {
				if ( swallowClick ) {
					swallowClick = false;
					event.preventDefault();
					event.stopPropagation();
				}
			},
			true
		);
	}

	function boot() {
		document.querySelectorAll( '[data-daymark-gallery]' ).forEach( init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
