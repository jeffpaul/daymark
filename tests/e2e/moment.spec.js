/**
 * Moment E2E browser tests (doc 13 scenarios 1-4, 7 + wp-admin visibility).
 *
 * Run:
 *   npx playwright install chromium   # once
 *   WP_BASE_URL=http://wp70.local WP_ADMIN_USER=... WP_ADMIN_PASS=... npx playwright test
 *
 * Needs a live WordPress with pretty permalinks, an administrator account,
 * and the moment + moment-connector-bluesky plugins active. Tests create
 * posts titled "E2E ..." and do not delete them — use a scratch site or
 * clean up afterwards.
 *
 * CONNECTED BLUESKY REQUIRED: the publish screen only offers connected
 * networks, so the target site needs Bluesky "connected" with the stubbed
 * API (the CI workflow does all of this):
 *   1. copy tests/e2e/fixtures/moment-e2e-bluesky-stub.php into wp-content/mu-plugins/
 *   2. wp option update moment_bluesky_handle e2e.bsky.social
 *   3. wp option update connectors_social_bluesky_app_password e2e-fake
 *
 * FRESH USER REQUIRED per run: destination memory persists per user, so
 * the image test's "nothing preselected" baseline needs an account with
 * no publish history (recreate the test user between local runs).
 */
import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'password';

// Unique-ish per run so title assertions never match older test posts.
const RUN_ID = `${Date.now()}`.slice(-6);

// Logs in through wp-login.php; the session cookie persists on the context.
async function loginAs(page) {
	await page.goto('/wp-login.php');
	await page.fill('#user_login', ADMIN_USER);
	await page.fill('#user_pass', ADMIN_PASS);
	await page.click('#wp-submit');
	await page.waitForURL('**/wp-admin/**');
}

// Scenario 1 (unauthenticated half): /moment redirects to login.
test('unauthenticated /moment redirects to login', async ({ page }) => {
	await page.goto('/moment');
	await expect(page).toHaveURL(/wp-login/);
});

// Scenario 1: focused Moment home, no wp-admin chrome.
test('authenticated user sees Moment Home without wp-admin chrome', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');
	await expect(page).toHaveTitle('Moment');
	await expect(page.locator('[data-action="new-moment"]')).toBeVisible();
	await expect(page.locator('#wpadminbar')).toHaveCount(0);
	await expect(page.locator('#adminmenu')).toHaveCount(0);

	// A fresh user has no drafts: the Drafts section must not render
	// (regression: author display rules once overrode [hidden]).
	await expect(page.locator('[data-recent-list] .moment-recent__item, [data-recent-list] .moment-empty').first()).toBeVisible();
	await expect(page.locator('[data-drafts-section]')).toBeHidden();
});

// Scenarios 3 + 4: note Moment with the connected network (Bluesky)
// preselected by the model default; unconnected networks are not offered.
test('note Moment: connected Bluesky preselected, publishes, appears in notes view', async ({ page }) => {
	const caption = `E2E note ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();

	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Bluesky is connected (stubbed) → offered with a Connected chip and
	// preselected for notes. Unconnected networks are not offered at all.
	await expect(page.locator('[data-connector="bluesky"]')).toBeChecked();
	await expect(page.getByText('Connected').first()).toBeVisible();
	await expect(page.locator('[data-connector="instagram"]')).toHaveCount(0);
	await expect(page.locator('[data-connector="youtube"]')).toHaveCount(0);
	await expect(page.locator('[data-connector="mastodon"]')).toHaveCount(0);

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();
	await expect(page.getByText('Bluesky')).toBeVisible(); // syndication row

	await page.goto('/notes/');
	await expect(page.getByText(caption)).toBeVisible();
});

// Categories: the "File under" picker files a Moment under a chosen
// category and remembers it as the per-type default for the next Moment.
// (CI seeds the "E2E Photos"/"E2E Travel" categories; the picker only
// renders when the site has a real choice beyond its default category.)
test('categories: File under picker files the Moment and remembers the choice per type', async ({ page }) => {
	const caption = `E2E category ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();

	// The picker renders with the seeded categories; pick "E2E Photos".
	const fileUnder = page.locator('.moment-publish-subhead');
	await expect(fileUnder).toHaveText('File under');
	const photos = page.locator('[data-category]').filter({ has: page.getByText('E2E Photos') }).locator('input');
	await photos.check({ force: true });

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// wp-admin confirms the post is filed under the chosen category.
	await page.goto('/wp-admin/edit.php');
	const row = page.locator('tr').filter({ hasText: caption }).first();
	await expect(row).toContainText('E2E Photos');

	// Per-type memory: the next note Moment preselects the same category.
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', `E2E category memory ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();
	const rememberedPhotos = page
		.locator('[data-category]')
		.filter({ has: page.getByText('E2E Photos') })
		.locator('input');
	await expect(rememberedPhotos).toBeChecked();
});

// Scenario 2 + destination memory: image Moment via the file picker; no
// networks preselected initially (image default Instagram is not
// connected), the user's explicit choice is remembered for the next
// image Moment.
test('image Moment: picker works, choice of networks is remembered per type', async ({ page }) => {
	const caption = `E2E image ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();

	await page.setInputFiles('#moment-file-input', 'tests/e2e/fixtures/test-image.png');
	await expect(page.locator('[data-type-badge]')).toHaveText(/image/i);
	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Grammatical article: "an Image", not "a Image".
	await expect(page.locator('.moment-typebadge')).toContainText('Publishing an Image Moment');

	// Image default is Instagram — not connected, so nothing preselected;
	// Bluesky is offered (text networks take any type) but off.
	await expect(page.locator('[data-connector="bluesky"]')).not.toBeChecked();

	// Explicitly choose Bluesky for this image Moment.
	await page.locator('[data-connector="bluesky"]').click({ force: true });
	await expect(page.locator('[data-connector="bluesky"]')).toBeChecked();

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Standard post visible in wp-admin.
	await page.goto('/wp-admin/edit.php');
	await expect(page.locator('.row-title').filter({ hasText: caption }).first()).toBeVisible();

	// Timeline and images views show it.
	await page.goto('/timeline/');
	await expect(page.getByText(caption)).toBeVisible();
	await page.goto('/images/');
	await expect(page.getByText(caption)).toBeVisible();

	// Destination memory: the next image Moment preselects Bluesky.
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.setInputFiles('#moment-file-input', 'tests/e2e/fixtures/test-image.png');
	await page.fill('#moment-caption', `E2E image memory ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();
	await expect(page.locator('[data-connector="bluesky"]')).toBeChecked();
});

// Scenario 7: real (stubbed) backflow replies appear in notifications.
test('notifications show imported social replies with source labels', async ({ page }) => {
	const caption = `E2E backflow ${RUN_ID}`;

	await loginAs(page);

	// Publish a note Moment to connected (stubbed) Bluesky through the UI.
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();
	await expect(page.locator('[data-connector="bluesky"]')).toBeChecked();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Trigger the sync from the app context (uses the page's own nonce +
	// REST config, same as a future in-UI sync control would).
	const imported = await page.evaluate(async () => {
		const config = window.momentApp;
		const listRes = await fetch(`${config.restUrl}moments?per_page=1`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		const [latest] = await listRes.json();
		const syncRes = await fetch(`${config.restUrl}moments/${latest.id}/sync-responses`, {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ networks: ['bluesky'] }),
		});
		const sync = await syncRes.json();
		return sync.imported_count;
	});
	expect(imported).toBeGreaterThan(0);

	await page.goto('/moment/notifications');
	await expect(page.getByText('Reply from Bluesky').first()).toBeVisible();
	await expect(page.getByText('Love this one!').first()).toBeVisible();
});

// --- Coverage for changes since 0.1.1 ---

// The primary CTA moved into the thumb zone: bottom of the viewport,
// above the site-views nav.
test('home CTA sits in the thumb zone', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');
	const button = page.locator('[data-action="new-moment"]');
	await expect(button).toBeVisible();
	const box = await button.boundingBox();
	const viewport = page.viewportSize();
	expect(box.y).toBeGreaterThan(viewport.height * 0.6);
});

// Recent Moments caps at five and offers a "View more" link to the
// timeline once more than five published Moments exist.
test('home shows five recent Moments with a View more link to the timeline', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');

	// Publish six quick note Moments via REST (fast, no media).
	await page.evaluate(async () => {
		const config = window.momentApp;
		for (let i = 1; i <= 6; i++) {
			await fetch(`${config.restUrl}moments`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ caption: `View-more seed ${i}`, primary_type: 'note' }),
			});
		}
	});

	await page.goto('/moment');
	const rows = page.locator('[data-recent-list] .moment-recent__item');
	await expect(rows.first()).toBeVisible();
	await expect(rows).toHaveCount(5);

	const more = page.locator('.moment-recent__morelink');
	await expect(more).toBeVisible();
	expect(await more.getAttribute('href')).toContain('/timeline');
});

// Plugins list table offers a one-click path into the app.
test('plugins page offers an Open Moment action link', async ({ page }) => {
	await loginAs(page);
	await page.goto('/wp-admin/plugins.php');
	const link = page
		.locator('tr[data-slug="moment"]')
		.locator('a', { hasText: 'Open Moment' })
		.first();
	await expect(link).toBeVisible();
	expect(await link.getAttribute('href')).toContain('/moment');
});

// The PWA manifest serves directly (no canonical 301) with the app scope.
test('manifest serves directly with app start_url', async ({ request }) => {
	const res = await request.get('/moment/manifest.json', { maxRedirects: 0 });
	expect(res.status()).toBe(200);
	expect(res.headers()['content-type']).toContain('manifest+json');
	const manifest = await res.json();
	expect(manifest.start_url).toContain('/moment');
	expect(manifest.scope).toContain('/moment');
});

// Save as Draft → Drafts row → resume editing → publish (running the
// stored destinations via deferred syndication).
test('draft lifecycle: save, resume from Drafts row, publish', async ({ page }) => {
	const caption = `E2E draft ${RUN_ID}`;
	const finished = `${caption} finished`;

	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();
	await expect(page.locator('[data-connector="bluesky"]')).toBeChecked();
	await page.locator('[data-action="save-draft"]').click();
	await expect(page.getByText('Saved as draft')).toBeVisible();

	// Not publicly visible while a draft.
	await page.goto('/timeline/');
	await expect(page.getByText(caption)).toHaveCount(0);

	// Home shows the Drafts row; the row is chip-marked.
	await page.goto('/moment');
	await expect(page.getByRole('heading', { name: 'Drafts' })).toBeVisible();
	const row = page.locator('[data-edit-draft]').filter({ hasText: caption }).first();
	await expect(row).toBeVisible();
	await expect(row.locator('.moment-chip--draft')).toBeVisible();

	// Resume: composer reopens prefilled, destinations remembered.
	await row.click();
	await expect(page.getByText('Edit Draft')).toBeVisible();
	await expect(page.locator('#moment-caption')).toHaveValue(caption);
	await page.fill('#moment-caption', finished);
	await page.locator('[data-action="next"]').click();
	await expect(page.locator('[data-connector="bluesky"]')).toBeChecked();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();
	await expect(page.getByText('Bluesky')).toBeVisible();

	// Draft row entry is gone; the published Moment is public.
	await page.goto('/moment');
	await expect(page.locator('[data-edit-draft]').filter({ hasText: caption })).toHaveCount(0);
	await page.goto('/timeline/');
	await expect(page.getByText(finished)).toBeVisible();
});

// Unread indicator: set by newly imported replies, cleared by viewing
// notifications — client-side and across a full reload.
test('unread dot appears for new replies and clears after viewing', async ({ page }) => {
	const caption = `E2E unread ${RUN_ID}`;

	await loginAs(page);

	// Hygiene: earlier tests leave syndicated Moments with never-imported
	// stub replies; the async backflow freshen would import them during
	// this test and legitimately re-set the unread flag. Drain everything
	// first so this test owns the only unread transition.
	await page.goto('/moment');
	await page.evaluate(async () => {
		const config = window.momentApp;
		// per_page=50 is the REST cap — drain as many prior Moments as the
		// API allows so the baseline holds even on a well-used site.
		const listRes = await fetch(`${config.restUrl}moments?per_page=50`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		const moments = await listRes.json();
		for (const moment of moments) {
			await fetch(`${config.restUrl}moments/${moment.id}/sync-responses`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ networks: ['bluesky'] }),
			});
		}
		// Mark everything seen so the baseline is "no unread".
		await fetch(`${config.restUrl}notifications`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
	});

	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Comment dates are second-resolution and the "seen" baseline was set
	// moments ago; guarantee the imported reply lands in a later second so
	// has_unread()'s strict > holds on fast runners (not a flake).
	await page.waitForTimeout(1100);

	// Import replies through the sync endpoint (same as the hourly cron).
	await page.evaluate(async () => {
		const config = window.momentApp;
		const listRes = await fetch(`${config.restUrl}moments?per_page=1`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		const [latest] = await listRes.json();
		await fetch(`${config.restUrl}moments/${latest.id}/sync-responses`, {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ networks: ['bluesky'] }),
		});
	});

	// Fresh load: the bell carries the unread dot.
	await page.goto('/moment');
	await expect(page.locator('.moment-iconbtn__dot')).toBeVisible();

	// Viewing notifications clears it without a reload…
	await page.locator('.moment-iconbtn').click();
	await expect(page.getByText('Reply from Bluesky').first()).toBeVisible();
	await page.locator('.moment-backlink').click();
	await expect(page.locator('[data-action="new-moment"]')).toBeVisible();
	await expect(page.locator('.moment-iconbtn__dot')).toHaveCount(0);

	// …and stays cleared across a full reload (server-side read state).
	await page.goto('/moment');
	await expect(page.locator('[data-action="new-moment"]')).toBeVisible();
	await expect(page.locator('.moment-iconbtn__dot')).toHaveCount(0);
});

// While a publish is in flight: both buttons disabled, the button shows
// the loading state, and there is no separate "Publishing…" message.
test('publish in flight disables both buttons and shows only the button loading state', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', `E2E loading ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	// Hold the create request so the in-flight UI is observable.
	await page.route('**/moment/v1/moments', async (route) => {
		await new Promise((resolve) => setTimeout(resolve, 1500));
		await route.continue();
	});

	await page.locator('[data-action="publish"]').click();

	const publishBtn = page.locator('[data-action="publish"]');
	const draftBtn = page.locator('[data-action="save-draft"]');
	await expect(publishBtn).toBeDisabled();
	await expect(draftBtn).toBeDisabled();
	await expect(publishBtn).toHaveText('Publishing…');
	await expect(page.locator('[data-publish-status]')).toHaveText('');

	await expect(page.getByText('Published to your site')).toBeVisible();
});

// Site-views nav renders as icon links: an SVG glyph, the label as the
// accessible name (role+name) and as the hover title, no visible text.
test('site-views nav shows icons with accessible labels', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');

	const timeline = page.getByRole('link', { name: 'Timeline' });
	await expect(timeline).toBeVisible();
	await expect(timeline).toHaveAttribute('title', 'Timeline');
	await expect(timeline.locator('svg.moment-bottomnav__icon')).toBeVisible();
	expect(await timeline.getAttribute('href')).toContain('/timeline');

	// Every view link carries an icon.
	await expect(page.locator('.moment-bottomnav__link svg')).toHaveCount(5);
	// The label text is present for assistive tech but visually hidden.
	await expect(page.getByRole('link', { name: 'Notes' })).toBeVisible();
});

// Awareness note: when a third-party publishing plugin is active, the
// publish screen tells the user their Moment will also go out that way.
// (The E2E publish-helper mu-plugin registers a fake "Test Publicize".)
test('publish screen notes active third-party publishing plugins', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', `E2E helpers ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	const note = page.locator('.moment-helpers-note');
	await expect(note).toBeVisible();
	await expect(note).toContainText('Test Publicize');
});

// A controllable third-party helper gets its own per-Moment toggle, and
// the selection is sent with the publish request.
test('controllable helper: per-Moment toggle appears and is sent on publish', async ({ page }) => {
	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', `E2E helper ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	const toggle = page.locator('[data-helper="moment-e2e-helper"]');
	await expect(toggle).toBeVisible();
	await expect(toggle).not.toBeChecked(); // opt-in: default off
	await toggle.click({ force: true });
	await expect(toggle).toBeChecked();

	// Capture the create request body to confirm the selection is sent.
	let sentBody = '';
	await page.route('**/moment/v1/moments', async (route) => {
		if ('POST' === route.request().method()) {
			sentBody = route.request().postData() || '';
		}
		await route.continue();
	});

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();
	expect(sentBody).toContain('publish_helpers');
	expect(sentBody).toContain('moment-e2e-helper');
});
