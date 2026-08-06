/**
 * Block editor E2E: the daymark/* blocks' count control.
 *
 * Run:
 *   npx playwright install chromium   # once
 *   WP_BASE_URL=http://wp70.local WP_ADMIN_USER=... WP_ADMIN_PASS=... \
 *     npx playwright test tests/e2e/blocks-editor.spec.js
 *
 * Needs a live WordPress with pretty permalinks, an administrator account,
 * and the daymark plugin active. The block editor bundle is committed, so
 * no local build is needed to run this.
 *
 * Inserts a Daymark Timeline block, changes its count in the block
 * inspector, and verifies the setting survives a publish and reload.
 */
import { test, expect, devices } from '@playwright/test';

// The block editor's settings sidebar is a desktop feature.
test.use( { ...devices[ 'Desktop Chrome' ] } );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'password';

// Logs in through wp-login.php; the session cookie persists on the context.
async function loginAs( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

// The block inspector opens by default once a block is selected; if the
// settings sidebar was toggled off, the keyboard shortcut brings it back.
async function revealInspector( page ) {
	const inspector = page.locator( '.block-editor-block-inspector' );
	try {
		await inspector.waitFor( { state: 'visible', timeout: 5000 } );
	} catch {
		await page.keyboard.press( 'ControlOrMeta+Shift+,' );
		await expect( inspector ).toBeVisible();
	}
}

test( 'Daymark Timeline block exposes a count control that persists', async ( { page } ) => {
	await loginAs( page );
	await page.goto( '/wp-admin/post-new.php' );

	// Wait for the editor canvas.
	await expect( page.locator( '.block-editor-block-list__layout, .editor-post-title__input' ).first() ).toBeVisible();

	// Insert a Daymark Timeline block from the inserter.
	await page.locator( '.block-editor-inserter__toggle' ).first().click();
	await page.locator( '.block-editor-inserter__search input' ).fill( 'Timeline' );
	await page.locator( '.block-editor-block-types-list button', { hasText: 'Daymark Timeline' } ).click();

	// The block is selected; its server-side preview renders in the canvas.
	const block = page.locator( '.wp-block-daymark-timeline' );
	await expect( block ).toBeVisible();
	await expect( page.locator( '.daymark-view' ).first() ).toBeVisible();

	await revealInspector( page );

	// The count control is a RangeControl: a slider with a number input.
	const rangeInput = page.locator( '.components-range-control input[type="range"]' );
	const numberInput = page.locator( '.components-range-control input[type="number"]' );
	await expect( rangeInput ).toBeVisible();
	await expect( rangeInput ).toHaveValue( '10' );

	// Change the count to 20.
	await numberInput.fill( '20' );
	await numberInput.blur();
	await expect( rangeInput ).toHaveValue( '20' );

	// Publish, then reload the editor and check the attribute persisted.
	await page.getByRole( 'button', { name: /^publish/i } ).first().click();
	const panel = page.locator( '.editor-post-publish-panel' );
	if ( await panel.isVisible().catch( () => false ) ) {
		await panel.getByRole( 'button', { name: /^publish/i } ).click();
	}
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );

	await page.reload();
	await expect( page.locator( '.block-editor-block-list__layout, .editor-post-title__input' ).first() ).toBeVisible();

	await expect( page.locator( '.wp-block-daymark-timeline' ).first() ).toBeVisible();
	await page.locator( '.wp-block-daymark-timeline' ).first().click();
	await revealInspector( page );

	await expect( page.locator( '.components-range-control input[type="number"]' ) ).toHaveValue( '20' );
	await expect( page.locator( '.components-range-control input[type="range"]' ) ).toHaveValue( '20' );
} );
