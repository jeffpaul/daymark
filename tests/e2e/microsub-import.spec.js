// Importing follows from a Microsub server (issue #592), end to end in a
// browser: sign in on the person's own sign-in page, come back to a
// checklist grouped by channel, and import the chosen sites.
//
// The sign-in page, the token endpoint, the Microsub server, and the sites
// it follows are all stood in for by tests/e2e/fixtures/daymark-e2e-microsub.php.
//
//   npx playwright test tests/e2e/microsub-import.spec.js
import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const IMPORT_TAB = '/wp-admin/options-general.php?page=daymark&tab=import-export';

async function loginAs( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

test.beforeEach( async ( { page } ) => {
	await loginAs( page );
	// Unfollow the pretend sites and forget any half-finished import, so a
	// retry starts from the same place.
	await page.goto( '/?daymark_e2e_microsub_reset=1' );
} );

test.afterEach( async ( { page } ) => {
	// Leave no pretend sites in the Timeline for the specs that run next.
	await page.goto( '/?daymark_e2e_microsub_reset=1' );
} );

test( 'signing in to a Microsub server lists its follows by channel and imports the chosen ones', async ( { page } ) => {
	await page.goto( IMPORT_TAB );

	// The web address starts as this site's own.
	const address = page.locator( '#daymark_microsub_me' );
	await expect( address ).toHaveValue( /^http:\/\/127\.0\.0\.1:8889\/$|^https?:\/\// );

	await address.fill( 'microsub-me.example' );
	await page.click( '#daymark-microsub-import-connect' );

	// The browser is sent to the person's own sign-in page.
	await expect( page.locator( 'h1' ) ).toHaveText( 'Sign in as microsub-me.example' );
	await page.click( '#daymark-e2e-approve' );

	// Back on the Import/Export tab, with the checklist.
	await expect( page ).toHaveURL( /tab=import-export/ );

	const form = page.locator( '[data-daymark-microsub-import-form]' );

	await expect( form ).toBeVisible();
	await expect( page.getByText( 'microsub-reader.example follows 3 sites for you.' ) ).toBeVisible();

	const channels = form.locator( '[data-daymark-microsub-import-channel]' );

	await expect( channels ).toHaveCount( 2 );
	await expect( channels.nth( 0 ).locator( 'legend' ) ).toContainText( 'Friends' );
	await expect( channels.nth( 0 ).locator( 'legend' ) ).toContainText( '2 sites' );
	await expect( channels.nth( 1 ).locator( 'legend' ) ).toContainText( 'News' );
	await expect( form.locator( '[data-daymark-microsub-import-entry]' ) ).toHaveCount( 3 );

	// Unchecking a channel unchecks its sites, and "Select all" shows mixed.
	await channels.nth( 1 ).locator( '[data-daymark-microsub-import-channel-toggle]' ).uncheck();
	await expect( channels.nth( 1 ).locator( '[data-daymark-microsub-import-entry]' ) ).not.toBeChecked();
	await expect( channels.nth( 0 ).locator( '[data-daymark-microsub-import-entry]' ).first() ).toBeChecked();
	expect(
		await form.locator( '[data-daymark-microsub-import-toggle-all]' ).evaluate( ( toggle ) => toggle.indeterminate )
	).toBe( true );

	await page.click( '#daymark-microsub-import-submit' );

	await expect( page.getByText( 'Import complete: 2 subscribed, 0 already subscribed, 0 failed.' ) ).toBeVisible();

	// The list is gone once everything chosen has been imported, and the
	// two friends are now ordinary followed sites.
	await expect( page.locator( '[data-daymark-microsub-import-form]' ) ).toHaveCount( 0 );
	await expect( page.locator( '#daymark_microsub_me' ) ).toBeVisible();

	await page.goto( '/wp-admin/options-general.php?page=daymark&tab=subscriptions' );
	await expect( page.getByText( 'microsub-ada.example' ).first() ).toBeVisible();
	await expect( page.getByText( 'microsub-grace.example' ).first() ).toBeVisible();
	await expect( page.getByText( 'microsub-news.example' ) ).toHaveCount( 0 );
} );

test( 'denying the sign-in imports nothing and says so', async ( { page } ) => {
	await page.goto( IMPORT_TAB );
	await page.fill( '#daymark_microsub_me', 'https://microsub-me.example/' );
	await page.click( '#daymark-microsub-import-connect' );
	await page.click( '#daymark-e2e-deny' );

	await expect( page ).toHaveURL( /tab=import-export/ );
	await expect( page.getByText( 'Sign-in was cancelled, so nothing was imported.' ) ).toBeVisible();
	await expect( page.locator( '[data-daymark-microsub-import-form]' ) ).toHaveCount( 0 );
} );
