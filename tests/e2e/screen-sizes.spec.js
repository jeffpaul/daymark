// Daymark on screens that are not an ordinary phone: the side rail and the
// wider column on a tablet, laptop or desktop, and the iPhone Duo's sizes
// (closed, closed and turned, sharing its open screen, and opened mid-draft).
//
// The Duo's sizes are the ones Safari 27.1 gives a page, from the WebKit
// blog's "WebKit Features for Safari 27.1": 382 x 580 closed, 594 x 368
// closed and turned sideways, 951 x 585 open, 669 x 867 open and turned, and
// 385 x 571 beside another app. This runs in Chromium at those sizes, so it covers layout only; what
// a real Duo adds (the fold, its keyboard, an installed home-screen app) is
// not checked here.
//
//   npx playwright test tests/e2e/screen-sizes.spec.js
import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

const TABLET = { width: 820, height: 1106 };
const DUO_CLOSED = { width: 382, height: 580 };
const DUO_CLOSED_SIDEWAYS = { width: 594, height: 368 };
const DUO_OPEN = { width: 951, height: 585 };
const DUO_OPEN_TURNED = { width: 669, height: 867 };
const DUO_SHARED = { width: 385, height: 571 };

// The launcher's first-tap explainer would sit in front of every test
// that opens it, and a saved reading position would open the Timeline
// partway down. Same reasoning as hideServerSeenHints() in daymark.spec.js.
function quietStart() {
	let stored;
	Object.defineProperty( window, 'daymarkApp', {
		configurable: true,
		get: () => stored,
		set: ( value ) => {
			if ( value && typeof value === 'object' ) {
				value.interactionHintsSeen = [ 'launcher', 'checkin', 'microphone', 'install' ];
				value.timelinePosition = null;
			}
			stored = value;
		},
	} );
}

test.beforeEach( async ( { page } ) => {
	await page.addInitScript( quietStart );
} );

async function loginAs( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

async function openApp( page ) {
	await loginAs( page );
	await page.goto( '/daymark' );
	await expect( page.locator( '[data-action="new-mark"]' ) ).toBeVisible();
}

// Opens the launcher and waits until its bubbles have stopped moving and
// take taps. el.click() rather than a pointer click, for the reasons
// openComposer() in daymark.spec.js gives.
async function openLauncher( page ) {
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.locator( '[data-action="new-mark"]' ).evaluate( ( el ) => el.click() );
	await expect( page.locator( '.daymark-launcher' ) ).toHaveClass( /is-settled/, { timeout: 8000 } );
}

async function openComposer( page, type = 'note' ) {
	await openLauncher( page );
	await page.locator( `[data-launcher-type="${ type }"]` ).evaluate( ( el ) => el.click() );
	await expect( page.locator( '#daymark-caption' ) ).toBeVisible();
}

// Where an element sits on screen, in whole points.
async function boxOf( locator ) {
	const box = await locator.boundingBox();
	expect( box, 'the element is on the page' ).not.toBeNull();
	return {
		left: Math.round( box.x ),
		top: Math.round( box.y ),
		right: Math.round( box.x + box.width ),
		bottom: Math.round( box.y + box.height ),
		width: Math.round( box.width ),
		height: Math.round( box.height ),
	};
}

function expectOnScreen( box, viewport ) {
	expect( box.left ).toBeGreaterThanOrEqual( 0 );
	expect( box.top ).toBeGreaterThanOrEqual( 0 );
	expect( box.right ).toBeLessThanOrEqual( viewport.width );
	expect( box.bottom ).toBeLessThanOrEqual( viewport.height );
}

const overlaps = ( a, b ) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;

async function launcherBubbleBoxes( page ) {
	const boxes = [];
	for ( const bubble of await page.locator( '[data-launcher-type]' ).all() ) {
		boxes.push( await boxOf( bubble ) );
	}
	return boxes;
}

test.describe( 'on a tablet-sized screen', () => {
	test.use( { viewport: TABLET } );

	test( 'the tabs are a rail down the side, with their names', async ( { page } ) => {
		await openApp( page );

		const rail = await boxOf( page.locator( '.daymark-homefooter' ) );
		expect( rail.left ).toBe( 0 );
		expect( rail.top ).toBe( 0 );
		expect( rail.height ).toBe( TABLET.height );
		expect( rail.width ).toBeLessThanOrEqual( 96 );

		// One under the other, in the order they have along the bottom bar.
		const tabs = page.locator( '.daymark-bottomnav__link' );
		await expect( tabs ).toHaveCount( 4 );
		let previousBottom = 0;
		for ( const tab of await tabs.all() ) {
			const box = await boxOf( tab );
			expect( box.top ).toBeGreaterThanOrEqual( previousBottom );
			expect( box.right ).toBeLessThanOrEqual( rail.right );
			previousBottom = box.bottom;
		}

		const names = page.locator( '.daymark-bottomnav__label' );
		await expect( names ).toHaveText( [ 'Timeline', 'Explore', 'Notifications', 'Me' ] );
		for ( const name of await names.all() ) {
			await expect( name ).toBeVisible();
		}

		// The new Mark button comes after the tabs, still inside the rail.
		const button = await boxOf( page.locator( '[data-action="new-mark"]' ) );
		expect( button.top ).toBeGreaterThanOrEqual( previousBottom );
		expect( button.right ).toBeLessThanOrEqual( rail.right );
	} );

	test( 'the column is wider than a phone and clear of the rail', async ( { page } ) => {
		await openApp( page );

		const rail = await boxOf( page.locator( '.daymark-homefooter' ) );
		const column = await boxOf( page.locator( '.daymark-shell' ) );
		expect( column.width ).toBe( 620 );
		expect( column.left ).toBeGreaterThanOrEqual( rail.right );
		// Centered on the screen, so the composer and a post (which have
		// no rail) sit in the same place as the Timeline.
		expect( column.left ).toBe( TABLET.width - column.right );
	} );

	test( 'the rail stays in place while the page scrolls', async ( { page } ) => {
		await openApp( page );
		// Tall enough to scroll, whatever the Timeline holds.
		await page.addStyleTag( { content: '.daymark-screen { min-height: 3000px; }' } );

		await page.evaluate( () => window.scrollTo( 0, 400 ) );
		await page.waitForTimeout( 100 );
		await page.evaluate( () => window.scrollTo( 0, 900 ) );
		// On a phone this is the point the bottom bar slides away.
		await expect( page.locator( '.daymark-homefooter' ) ).toHaveClass( /is-footer-hidden/ );
		await page.waitForTimeout( 300 );

		const rail = await boxOf( page.locator( '.daymark-homefooter' ) );
		expect( rail.top ).toBe( 0 );
		expect( rail.height ).toBe( TABLET.height );
		await expect( page.locator( '.daymark-bottomnav__link' ).first() ).toBeInViewport();
	} );

	test( 'the new Mark button opens the five types beside the rail', async ( { page } ) => {
		await openApp( page );
		const rail = await boxOf( page.locator( '.daymark-homefooter' ) );

		await openLauncher( page );

		const boxes = await launcherBubbleBoxes( page );
		expect( boxes ).toHaveLength( 5 );
		for ( const box of boxes ) {
			expectOnScreen( box, TABLET );
			expect( box.left ).toBeGreaterThanOrEqual( rail.right );
		}
		for ( let i = 0; i < boxes.length; i++ ) {
			for ( let j = i + 1; j < boxes.length; j++ ) {
				expect( overlaps( boxes[ i ], boxes[ j ] ), `types ${ i } and ${ j } overlap` ).toBe( false );
			}
		}
		// Their names fit too, without running into each other.
		const labels = [];
		for ( const label of await page.locator( '.daymark-launcher__label' ).all() ) {
			labels.push( await boxOf( label ) );
		}
		for ( let i = 0; i < labels.length; i++ ) {
			expectOnScreen( labels[ i ], TABLET );
			for ( let j = i + 1; j < labels.length; j++ ) {
				expect( overlaps( labels[ i ], labels[ j ] ), `names ${ i } and ${ j } overlap` ).toBe( false );
			}
		}

		await page.locator( '[data-launcher-type="note"]' ).click();
		await expect( page.locator( '#daymark-caption' ) ).toBeVisible();
	} );

	test( 'in a right-to-left language the rail is on the right and the types open to its left', async ( { page } ) => {
		await openApp( page );
		await page.evaluate( () => document.documentElement.setAttribute( 'dir', 'rtl' ) );

		const rail = await boxOf( page.locator( '.daymark-homefooter' ) );
		expect( rail.right ).toBe( TABLET.width );
		expect( rail.width ).toBeLessThanOrEqual( 96 );
		const column = await boxOf( page.locator( '.daymark-shell' ) );
		expect( column.right ).toBeLessThanOrEqual( rail.left );

		await openLauncher( page );
		const boxes = await launcherBubbleBoxes( page );
		for ( const box of boxes ) {
			expectOnScreen( box, TABLET );
			expect( box.right ).toBeLessThanOrEqual( rail.left );
		}
	} );

	test( 'a message shows at the bottom of the screen', async ( { page } ) => {
		await openApp( page );
		await openComposer( page );
		await page.fill( '#daymark-caption', 'E2E wide screen draft' );

		// Leaving the composer saves what was typed and says so.
		await page.locator( '.daymark-backlink' ).click();
		const bar = page.locator( '.daymark-msgbar' );
		await expect( bar ).toContainText( 'Saved to Drafts.' );

		const box = await boxOf( bar );
		expectOnScreen( box, TABLET );
		expect( box.top ).toBeGreaterThan( TABLET.height * 0.8 );
	} );
} );

test( 'on a phone the tabs stay in a bar along the bottom', async ( { page } ) => {
	await openApp( page );
	const viewport = page.viewportSize();

	const bar = await boxOf( page.locator( '.daymark-homefooter' ) );
	expect( bar.left ).toBe( 0 );
	expect( bar.width ).toBe( viewport.width );
	expect( bar.bottom ).toBe( viewport.height );
	expect( bar.height ).toBeLessThan( 120 );
	// Icons only: there is no room for names under five buttons.
	for ( const name of await page.locator( '.daymark-bottomnav__label' ).all() ) {
		await expect( name ).toBeHidden();
	}
} );

test.describe( 'iPhone Duo, closed', () => {
	test.use( { viewport: DUO_CLOSED } );

	test( 'the five Mark types fit above the new Mark button', async ( { page } ) => {
		await openApp( page );
		await openLauncher( page );

		for ( const box of await launcherBubbleBoxes( page ) ) {
			expectOnScreen( box, DUO_CLOSED );
		}
		for ( const label of await page.locator( '.daymark-launcher__label' ).all() ) {
			expectOnScreen( await boxOf( label ), DUO_CLOSED );
		}
	} );

	test( 'the composer shows Publish without scrolling', async ( { page } ) => {
		await openApp( page );
		await openComposer( page );
		await page.fill( '#daymark-caption', 'E2E Duo cover screen note' );

		const publish = page.getByRole( 'button', { name: 'Publish', exact: true } );
		await expect( publish ).toBeVisible();
		expectOnScreen( await boxOf( publish ), DUO_CLOSED );
		expectOnScreen( await boxOf( page.locator( '#daymark-caption' ) ), DUO_CLOSED );
		// Nothing is wider than the screen.
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBe( DUO_CLOSED.width );
	} );

	test( 'opening and closing the phone keeps the draft and its Publish button', async ( { page } ) => {
		await openApp( page );
		await openComposer( page );
		const caption = page.locator( '#daymark-caption' );
		await caption.fill( 'E2E typed on the cover screen' );

		// Opening the phone hands the same page a much wider window.
		await page.setViewportSize( DUO_OPEN );
		await expect( caption ).toHaveValue( 'E2E typed on the cover screen' );
		const publish = page.getByRole( 'button', { name: 'Publish', exact: true } );
		expectOnScreen( await boxOf( publish ), DUO_OPEN );
		await caption.pressSequentially( ', finished on the inner one' );

		await page.setViewportSize( DUO_CLOSED );
		await expect( caption ).toHaveValue( 'E2E typed on the cover screen, finished on the inner one' );
		expectOnScreen( await boxOf( publish ), DUO_CLOSED );
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBe( DUO_CLOSED.width );
	} );

	test( 'opening the phone on the Timeline moves the tabs to the rail, and closing it moves them back', async ( { page } ) => {
		await openApp( page );
		const footer = page.locator( '.daymark-homefooter' );
		expect( ( await boxOf( footer ) ).bottom ).toBe( DUO_CLOSED.height );

		await page.setViewportSize( DUO_OPEN );
		const rail = await boxOf( footer );
		expect( rail.top ).toBe( 0 );
		expect( rail.height ).toBe( DUO_OPEN.height );
		// All four tabs and the new Mark button fit the short open screen.
		for ( const tab of await page.locator( '.daymark-bottomnav__link' ).all() ) {
			expectOnScreen( await boxOf( tab ), DUO_OPEN );
		}
		expectOnScreen( await boxOf( page.locator( '[data-action="new-mark"]' ) ), DUO_OPEN );

		await page.setViewportSize( DUO_CLOSED );
		const bar = await boxOf( footer );
		expect( bar.width ).toBe( DUO_CLOSED.width );
		expect( bar.bottom ).toBe( DUO_CLOSED.height );
	} );
} );

test.describe( 'iPhone Duo, closed and turned sideways', () => {
	test.use( { viewport: DUO_CLOSED_SIDEWAYS } );

	test( 'the tabs stay along the bottom and the Mark types fit', async ( { page } ) => {
		await openApp( page );
		const bar = await boxOf( page.locator( '.daymark-homefooter' ) );
		expect( bar.bottom ).toBe( DUO_CLOSED_SIDEWAYS.height );
		expect( bar.height ).toBeLessThan( 120 );

		await openLauncher( page );
		for ( const box of await launcherBubbleBoxes( page ) ) {
			expectOnScreen( box, DUO_CLOSED_SIDEWAYS );
		}
	} );

	test( 'Publish and the destinations sheet\'s Done button can be reached', async ( { page } ) => {
		await openApp( page );
		await openComposer( page );
		await page.fill( '#daymark-caption', 'E2E Duo sideways note' );

		const publish = page.getByRole( 'button', { name: 'Publish', exact: true } );
		expectOnScreen( await boxOf( publish ), DUO_CLOSED_SIDEWAYS );

		// The sheet is taller than this screen, so it scrolls inside itself.
		await page.locator( '[data-where-open]' ).click();
		const done = page.locator( '[data-sheet-done]' );
		await done.scrollIntoViewIfNeeded();
		expectOnScreen( await boxOf( done ), DUO_CLOSED_SIDEWAYS );
		await done.click();
		await expect( done ).toBeHidden();
	} );
} );

test.describe( 'iPhone Duo, open and turned upright', () => {
	test.use( { viewport: DUO_OPEN_TURNED } );

	test( 'the tabs are a rail, and the column keeps clear of it', async ( { page } ) => {
		await openApp( page );
		const rail = await boxOf( page.locator( '.daymark-homefooter' ) );
		expect( rail.left ).toBe( 0 );
		expect( rail.height ).toBe( DUO_OPEN_TURNED.height );

		const column = await boxOf( page.locator( '.daymark-shell' ) );
		expect( column.left ).toBeGreaterThanOrEqual( rail.right );
		expect( column.width ).toBeGreaterThan( 480 );
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBe( DUO_OPEN_TURNED.width );
	} );
} );

test.describe( 'iPhone Duo, sharing its open screen with another app', () => {
	test.use( { viewport: DUO_SHARED } );

	test( 'Daymark is the phone layout, with Publish on screen', async ( { page } ) => {
		await openApp( page );
		const bar = await boxOf( page.locator( '.daymark-homefooter' ) );
		expect( bar.width ).toBe( DUO_SHARED.width );
		expect( bar.bottom ).toBe( DUO_SHARED.height );

		await openComposer( page );
		await page.fill( '#daymark-caption', 'E2E Duo shared screen note' );
		expectOnScreen( await boxOf( page.getByRole( 'button', { name: 'Publish', exact: true } ) ), DUO_SHARED );
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBe( DUO_SHARED.width );
	} );
} );
