// Daymark's two controls in the block editor's Post sidebar: "Set featured
// content" under core's featured image button, and the Note length row in
// the summary. Both are added through core's own extension points, so this
// opens a real editor and checks that they show up and look like what is
// around them.
//
// The look is compared with core's own controls on the same page, not with
// fixed colors, so the spec holds on every WordPress version the suite runs
// on. WordPress 7.2 rebuilt this sidebar with DataForm and restyled both
// neighbors; if a later version restyles them again, the comparison fails
// on the early-warning run first.
//
//   npx playwright test tests/e2e/editor-sidebar.spec.js
import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

async function loginAs( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

// A new post with no title and one short paragraph, with the Post sidebar
// open. Nothing is saved.
async function openNewUntitledPost( page ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction(
		() => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);

	await page.evaluate( () => {
		const { dispatch } = window.wp.data;

		dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		// Not selected, so the sidebar stays on its Post tab.
		dispatch( 'core/block-editor' ).insertBlocks(
			window.wp.blocks.createBlock( 'core/paragraph', { content: 'Up early for the ferry.' } ),
			undefined,
			undefined,
			false
		);
		dispatch( 'core/block-editor' ).clearSelectedBlock();
		dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
	} );

	return page.locator( '.interface-interface-skeleton__sidebar' );
}

// The parts of an element's look that Daymark's controls copy from core.
function look( locator ) {
	return locator.evaluate( ( node ) => {
		const style = getComputedStyle( node );

		return {
			// WordPress 7.2's button has a dashed outline; earlier ones are
			// solid, drawn in more than one way, so only "dashed or not" is read.
			dashed: style.borderTopStyle === 'dashed',
			color: style.color,
			fontWeight: parseInt( style.fontWeight, 10 ),
		};
	} );
}

test.beforeEach( async ( { page } ) => {
	await loginAs( page );
} );

test( '"Set featured content" sits under the featured image button and looks like it', async ( { page } ) => {
	const sidebar = await openNewUntitledPost( page );

	const ours = sidebar.getByRole( 'button', { name: 'Set featured content', exact: true } );
	// A <button> up to WordPress 7.1, an element with role="button" from 7.2.
	const cores = sidebar.getByRole( 'button', { name: /^Set featured image/ } );

	await expect( cores ).toBeVisible();
	await expect( ours ).toBeVisible();

	const coresBox = await cores.boundingBox();
	const oursBox = await ours.boundingBox();

	// Directly below it, and the same width.
	expect( oursBox.y ).toBeGreaterThanOrEqual( coresBox.y + coresBox.height );
	expect( oursBox.y - ( coresBox.y + coresBox.height ) ).toBeLessThan( 40 );
	expect( Math.abs( oursBox.width - coresBox.width ) ).toBeLessThanOrEqual( 1 );
	expect( Math.abs( oursBox.height - coresBox.height ) ).toBeLessThanOrEqual( 1 );

	const oursLook = await look( ours );
	const coresLook = await look( cores );

	expect( oursLook.dashed ).toBe( coresLook.dashed );
	expect( oursLook.color ).toBe( coresLook.color );
	// Regular with regular, bold with bold: WordPress 7.0's medium weight (499)
	// and Daymark's 600 both read as bold, and 7.2's 400 does not.
	expect( Math.abs( oursLook.fontWeight - coresLook.fontWeight ) ).toBeLessThan( 150 );
} );

test( 'the Note length row shows in the summary and looks like the rows around it', async ( { page } ) => {
	const sidebar = await openNewUntitledPost( page );

	const row = sidebar.locator( '.daymark-note-length' );

	await expect( row ).toBeVisible();
	await expect( row ).toContainText( 'Short post' );
	await expect( row ).toContainText( /\d+ characters left/ );

	// Core's Status row, which every new post has: its label and its value.
	const coresLabel = sidebar.getByText( 'Status', { exact: true } );
	const coresValue = sidebar.getByText( 'Draft', { exact: true } );

	await expect( coresLabel ).toBeVisible();
	await expect( coresValue ).toBeVisible();

	const label = await look( row.locator( '.daymark-note-length__label' ) );
	const value = await look( row.locator( '.daymark-note-length__value' ) );

	expect( label.color ).toBe( ( await look( coresLabel ) ).color );
	expect( value.fontWeight ).toBe( ( await look( coresValue ) ).fontWeight );
} );
