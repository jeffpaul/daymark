/**
 * Moment E2E browser tests.
 *
 * Run:
 *   npx playwright install chromium   # once
 *   WP_BASE_URL=http://wp70.local WP_ADMIN_USER=... WP_ADMIN_PASS=... npx playwright test
 *
 * Needs a live WordPress with pretty permalinks, an administrator account,
 * and the moment plugin active. Tests create posts titled "E2E ..." and do
 * not delete them — use a scratch site or clean up afterwards.
 *
 * No social connectors are required: Moment publishes to "Your Site", and
 * it works with third-party publishing plugins via detection and per-Moment
 * toggles. The syndication-connector interface itself is covered by the
 * PHPUnit suite, not here.
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

// Publish a note Moment to your own site and see it in the Notes view.
// With nothing connected, "Your Site" is the only destination.
test('note Moment publishes to your site and appears in the notes view', async ({ page }) => {
	const caption = `E2E note ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();

	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Your Site is always the destination; no social networks are offered
	// unless a connector plugin registers one.
	await expect(page.locator('.moment-dest__name', { hasText: 'Your Site' })).toBeVisible();

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

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
	// The real checkbox is visually hidden behind the toggle track, so click
	// it (force) rather than check() — matching the destination/helper toggles.
	const photos = page.locator('.moment-dest').filter({ hasText: 'E2E Photos' }).locator('[data-category]');
	await photos.click({ force: true });
	await expect(photos).toBeChecked();

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
		.locator('.moment-dest')
		.filter({ hasText: 'E2E Photos' })
		.locator('[data-category]');
	await expect(rememberedPhotos).toBeChecked();
});

// Image Moment via the file picker: per-image alt field, correct article
// on the publish screen, and it lands in the timeline + images views.
test('image Moment: alt field, correct article, appears in image views', async ({ page }) => {
	const caption = `E2E image ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();

	await page.setInputFiles('#moment-file-input', 'tests/e2e/fixtures/test-image.png');
	await expect(page.locator('[data-type-badge]')).toHaveText(/image/i);

	// Every image offers a per-image alt field; describe it before publish.
	const altField = page.locator('[data-alt-for]').first();
	await expect(altField).toBeVisible();
	await altField.fill(`E2E alt ${RUN_ID}`);

	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Grammatical article: "an Image", not "a Image".
	await expect(page.locator('.moment-typebadge')).toContainText('Publishing an Image Moment');

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Standard post visible in wp-admin, and in the timeline + images views.
	await page.goto('/wp-admin/edit.php');
	await expect(page.locator('.row-title').filter({ hasText: caption }).first()).toBeVisible();
	await page.goto('/timeline/');
	await expect(page.getByText(caption)).toBeVisible();
	await page.goto('/images/');
	await expect(page.getByText(caption)).toBeVisible();
});

// Scenario 7: real (stubbed) backflow replies appear in notifications.
// Replies to a Moment surface in notifications. Without a social connector
// we exercise this with an on-site comment on the Moment post — imported
// social replies share the same storage and rendering path.
test('notifications show replies to a Moment', async ({ page }) => {
	const caption = `E2E reply ${RUN_ID}`;
	const reply = `E2E nice shot ${RUN_ID}`;

	await loginAs(page);

	// Publish a note Moment through the UI.
	await page.goto('/moment');
	await page.locator('[data-action="new-moment"]').click();
	await page.fill('#moment-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Add a comment to it via the core REST API (same nonce/session).
	await page.evaluate(async (replyText) => {
		const config = window.momentApp;
		const listRes = await fetch(`${config.restUrl}moments?per_page=1`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		const [latest] = await listRes.json();
		await fetch('/wp-json/wp/v2/comments', {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ post: latest.id, content: replyText }),
		});
	}, reply);

	await page.goto('/moment/notifications');
	await expect(page.getByText(reply).first()).toBeVisible();
	await expect(page.getByText('On-site comment').first()).toBeVisible();
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

	// Resume: composer reopens prefilled with the draft's caption.
	await row.click();
	await expect(page.getByText('Edit Draft')).toBeVisible();
	await expect(page.locator('#moment-caption')).toHaveValue(caption);
	await page.fill('#moment-caption', finished);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Draft row entry is gone; the published Moment is public.
	await page.goto('/moment');
	await expect(page.locator('[data-edit-draft]').filter({ hasText: caption })).toHaveCount(0);
	await page.goto('/timeline/');
	await expect(page.getByText(finished)).toBeVisible();
});

// Unread indicator: set by a new reply, cleared by viewing notifications —
// client-side and across a full reload.
test('unread dot appears for a new reply and clears after viewing', async ({ page }) => {
	const caption = `E2E unread ${RUN_ID}`;
	const reply = `E2E unread reply ${RUN_ID}`;

	await loginAs(page);

	// Baseline: mark existing notifications seen so this test owns the only
	// unread transition.
	await page.goto('/moment');
	await page.evaluate(async () => {
		const config = window.momentApp;
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
	// moments ago; guarantee the reply lands in a later second so
	// has_unread()'s strict > holds on fast runners (not a flake).
	await page.waitForTimeout(1100);

	// A new reply arrives (on-site comment on the Moment post).
	await page.evaluate(async (replyText) => {
		const config = window.momentApp;
		const listRes = await fetch(`${config.restUrl}moments?per_page=1`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
		const [latest] = await listRes.json();
		await fetch('/wp-json/wp/v2/comments', {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ post: latest.id, content: replyText }),
		});
	}, reply);

	// Fresh load: the bell carries the unread dot.
	await page.goto('/moment');
	await expect(page.locator('.moment-iconbtn__dot')).toBeVisible();

	// Viewing notifications clears it without a reload…
	await page.locator('.moment-iconbtn').click();
	await expect(page.getByText(reply).first()).toBeVisible();
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
