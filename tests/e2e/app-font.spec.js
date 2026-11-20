// The font setting, end to end in a browser: pick a Font Library font on
// Settings -> Daymark -> General, and the Daymark app shows its text in it.
//
// The font comes from tests/e2e/fixtures/daymark-e2e-font.php, so the spec
// doesn't depend on which theme the site runs.
//
//   npx playwright test tests/e2e/app-font.spec.js
import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const GENERAL_TAB = '/wp-admin/options-general.php?page=daymark&tab=general';

async function loginAs( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

async function chooseFont( page, value ) {
	await page.goto( GENERAL_TAB );
	await page.selectOption( '#daymark-app-font', value );
	await page.click( '#daymark-app-font-submit' );
	await expect( page.locator( '.notice' ).filter( { hasText: 'Font saved.' } ) ).toBeVisible();
}

// What the app's text is set in: the --daymark-font list on <html>, and
// the font files the app added to the page.
async function appFont( page ) {
	await page.goto( '/daymark/' );
	await page.waitForFunction( () => document.querySelector( '#daymark-app' )?.children.length > 0 );

	return page.evaluate( () => ( {
		list: document.documentElement.style.getPropertyValue( '--daymark-font' ),
		body: getComputedStyle( document.body ).fontFamily,
		files: [ ...document.fonts ].filter( ( face ) => face.family.replace( /["']/g, '' ) === 'Daymark App Font' ).length,
	} ) );
}

test.beforeEach( async ( { page } ) => {
	await loginAs( page );
} );

test.afterEach( async ( { page } ) => {
	// Back to Daymark's usual font for the specs that run next.
	await chooseFont( page, '' );
} );

test( 'a font picked from the Font Library is the font the app shows', async ( { page } ) => {
	// With nothing chosen, the app keeps its usual font and adds no files.
	await chooseFont( page, '' );

	const usual = await appFont( page );

	expect( usual.list ).toBe( '' );
	expect( usual.files ).toBe( 0 );

	// Nothing about Bionic Reading is offered any more.
	await page.goto( GENERAL_TAB );
	await expect( page.locator( '#wpbody-content' ) ).not.toContainText( /bionic/i );

	await chooseFont( page, 'daymark-e2e-font' );
	await expect( page.locator( '#daymark-app-font' ) ).toHaveValue( 'daymark-e2e-font' );

	const chosen = await appFont( page );

	// The font's own file first, then its name, then the usual fonts.
	expect( chosen.list ).toMatch( /^'Daymark App Font', "Daymark E2E Font", serif, .*sans-serif$/ );
	expect( chosen.body ).toContain( 'Daymark App Font' );
	expect( chosen.files ).toBe( 1 );
} );
