/**
 * Daymark E2E browser tests.
 *
 * Run:
 *   npx playwright install chromium   # once
 *   WP_BASE_URL=http://wp70.local WP_ADMIN_USER=... WP_ADMIN_PASS=... npx playwright test
 *
 * Needs a live WordPress with pretty permalinks, an administrator account,
 * and the daymark plugin active. Tests create posts titled "E2E ..." and do
 * not delete them — use a scratch site or clean up afterwards.
 *
 * No social connectors are required: Daymark publishes to "Your Site", and
 * it works with third-party publishing plugins via detection and per-Mark
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

// Scenario 1 (unauthenticated half): /daymark redirects to login.
test('unauthenticated /daymark redirects to login', async ({ page }) => {
	await page.goto('/daymark');
	await expect(page).toHaveURL(/wp-login/);
});

// Scenario 1: focused Daymark home, no wp-admin chrome.
test('authenticated user sees Daymark Home without wp-admin chrome', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await expect(page).toHaveTitle('Daymark');
	await expect(page.locator('[data-action="new-mark"]')).toBeVisible();
	await expect(page.locator('#wpadminbar')).toHaveCount(0);
	await expect(page.locator('#adminmenu')).toHaveCount(0);

	// A fresh user has no drafts: the Drafts section must not render
	// (regression: author display rules once overrode [hidden]).
	await expect(page.locator('[data-recent-list] .daymark-recent__item, [data-recent-list] .daymark-empty').first()).toBeVisible();
	await expect(page.locator('[data-drafts-section]')).toBeHidden();
});

// Publish a note Mark to your own site and see it in the Notes view.
// With nothing connected, "Your Site" is the only destination.
test('note Mark publishes to your site and appears in the notes view', async ({ page }) => {
	const caption = `E2E note ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();

	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Your Site is always the destination; no social networks are offered
	// unless a connector plugin registers one.
	await expect(page.locator('.daymark-dest__name', { hasText: 'Your Site' })).toBeVisible();

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	await page.goto('/notes/');
	await expect(page.getByText(caption)).toBeVisible();
});

// Categories: the "File under" picker files a Mark under a chosen
// category and remembers it as the per-type default for the next Mark.
// (CI seeds the "E2E Photos"/"E2E Travel" categories; the picker only
// renders when the site has a real choice beyond its default category.)
test('categories: File under picker files the Mark and remembers the choice per type', async ({ page }) => {
	const caption = `E2E category ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();

	// The picker renders with the seeded categories; pick "E2E Photos".
	const fileUnder = page.locator('.daymark-publish-subhead');
	await expect(fileUnder).toHaveText('File under');
	// The category rows sit at the bottom, under the sticky action bar, so a
	// force-click on the visually-hidden input lands on the footer instead.
	// Scroll the row clear, then toggle via its visible label text.
	const photosRow = page.locator('.daymark-dest').filter({ hasText: 'E2E Photos' });
	await photosRow.scrollIntoViewIfNeeded();
	await photosRow.getByText('E2E Photos').click();
	await expect(photosRow.locator('[data-category]')).toBeChecked();

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// wp-admin confirms the post is filed under the chosen category.
	await page.goto('/wp-admin/edit.php');
	const row = page.locator('tr').filter({ hasText: caption }).first();
	await expect(row).toContainText('E2E Photos');

	// Per-type memory: the next note Mark preselects the same category.
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', `E2E category memory ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();
	const rememberedPhotos = page
		.locator('.daymark-dest')
		.filter({ hasText: 'E2E Photos' })
		.locator('[data-category]');
	await expect(rememberedPhotos).toBeChecked();
});

// Image Mark via the file picker: per-image alt field, correct article
// on the publish screen, and it lands in the timeline + images views.
test('image Mark: alt field, correct article, appears in image views', async ({ page }) => {
	const caption = `E2E image ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();

	await page.setInputFiles('#daymark-file-input', 'tests/e2e/fixtures/test-image.png');
	await expect(page.locator('[data-type-badge]')).toHaveText(/image/i);

	// Every image offers a per-image alt field; describe it before publish.
	const altField = page.locator('[data-alt-for]').first();
	await expect(altField).toBeVisible();
	await altField.fill(`E2E alt ${RUN_ID}`);

	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Grammatical article: "an Image", not "a Image".
	await expect(page.locator('.daymark-typebadge')).toContainText('Publishing an Image Mark');

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

// Optional Title field: audio/video Marks surface an editable, optionally
// AI-pre-filled Title field with a ⓘ tap-to-reveal hint; note Marks do not.
test('audio Mark shows an editable optional Title field with a toggleable hint', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();

	// Scope every assertion to the composer screen (bare role/text matches
	// break this suite). Before any media the type is a note → no Title field.
	const composer = page.locator('.daymark-screen').first();
	await expect(composer.locator('[data-title-slot] .daymark-titlefield')).toHaveCount(0);

	// Attaching audio flips the effective type to audio and reveals the field.
	await page.setInputFiles('#daymark-file-input', 'tests/e2e/fixtures/test-audio.wav');
	await expect(page.locator('[data-type-badge]')).toHaveText(/audio/i);

	const titleField = composer.locator('[data-title-slot] .daymark-titlefield');
	await expect(titleField).toBeVisible();

	// The field is editable (prefilled by AI when a provider is configured, or
	// empty for manual entry otherwise) — either way the author can type.
	const titleInput = titleField.locator('[data-title-input]');
	await expect(titleInput).toBeVisible();
	await titleInput.fill(`E2E audio title ${RUN_ID}`);
	await expect(titleInput).toHaveValue(`E2E audio title ${RUN_ID}`);

	// The ⓘ button toggles the keyboard-reachable hint.
	const info = titleField.locator('[data-title-info]');
	const hint = titleField.locator('[data-title-hint]');
	await expect(hint).toBeHidden();
	await expect(info).toHaveAttribute('aria-expanded', 'false');
	await info.click();
	await expect(hint).toBeVisible();
	await expect(info).toHaveAttribute('aria-expanded', 'true');
	await info.click();
	await expect(hint).toBeHidden();
});

// The Title field is a per-type affordance: a plain note Mark never shows
// it (its title is derived from the caption/timestamp).
test('note Mark does not show the Title field', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();

	const composer = page.locator('.daymark-screen').first();
	await composer.locator('#daymark-caption').fill(`E2E no-title note ${RUN_ID}`);
	await expect(composer.locator('[data-title-slot] .daymark-titlefield')).toHaveCount(0);
});

// Scenario 7: real (stubbed) backflow replies appear in notifications.
// Replies to a Mark surface in notifications. Without a social connector
// we exercise this with an on-site comment on the Mark post — imported
// social replies share the same storage and rendering path.
test('notifications show replies to a Mark', async ({ page }) => {
	const caption = `E2E reply ${RUN_ID}`;
	const reply = `E2E nice shot ${RUN_ID}`;

	await loginAs(page);

	// Publish a note Mark through the UI.
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Add a comment to it via the core REST API (same nonce/session).
	await page.evaluate(async (replyText) => {
		const config = window.daymarkApp;
		const listRes = await fetch(`${config.restUrl}marks?per_page=1`, {
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

	await page.goto('/daymark/notifications');
	await expect(page.getByText(reply).first()).toBeVisible();
	await expect(page.getByText('On-site comment').first()).toBeVisible();
});

// --- Coverage for changes since 0.1.1 ---

// The primary CTA moved into the thumb zone: bottom of the viewport,
// above the site-views nav.
test('home CTA sits in the thumb zone', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	const button = page.locator('[data-action="new-mark"]');
	await expect(button).toBeVisible();
	const box = await button.boundingBox();
	const viewport = page.viewportSize();
	expect(box.y).toBeGreaterThan(viewport.height * 0.6);
});

// The footer (CTA + site nav) slides out of view while scrolling down the
// list, to reclaim its height for content, and back in on scroll-up or when
// keyboard focus reaches one of its own controls.
test('home footer auto-hides on scroll-down and returns on scroll-up or focus', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');

	// Enough seeded Marks to make the page taller than the viewport.
	await page.evaluate(async () => {
		const config = window.daymarkApp;
		for (let i = 1; i <= 10; i++) {
			await fetch(`${config.restUrl}marks`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ caption: `Auto-hide seed ${i}`, primary_type: 'note' }),
			});
		}
	});
	await page.goto('/daymark');

	// Wait for the first page of recent rows to actually render — the
	// footer itself is static markup and appears immediately, but scrolling
	// before the list has loaded is a race that leaves the page too short
	// to trigger auto-hide. Recent loads RECENT_PER_PAGE (5) rows at a time.
	await expect(page.locator('[data-recent-list] .daymark-recent__item')).toHaveCount(5);

	const footer = page.locator('.daymark-homefooter');
	await expect(footer).toBeVisible();
	await expect(footer).not.toHaveClass(/is-footer-hidden/);

	await page.mouse.wheel(0, 600);
	await expect(footer).toHaveClass(/is-footer-hidden/);

	await page.mouse.wheel(0, -600);
	await expect(footer).not.toHaveClass(/is-footer-hidden/);

	// Hide it again, then confirm tabbing a footer control reveals it.
	await page.mouse.wheel(0, 600);
	await expect(footer).toHaveClass(/is-footer-hidden/);
	await page.locator('[data-action="new-mark"]').focus();
	await expect(footer).not.toHaveClass(/is-footer-hidden/);
});

// With IntersectionObserver (all supported browsers) the recent list uses
// infinite scroll, so the redundant "View more on your timeline" link is not
// rendered — the appended pages already show everything and the bottom-nav
// Timeline icon still reaches the full timeline. (The link only appears as a
// no-IntersectionObserver fallback.)
test('home does not render the redundant View more link when infinite scroll is active', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');

	// Publish six quick note Marks via REST (fast, no media) — more than a
	// single page (5), so the first page is full and infinite scroll arms.
	await page.evaluate(async () => {
		const config = window.daymarkApp;
		for (let i = 1; i <= 6; i++) {
			await fetch(`${config.restUrl}marks`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ caption: `View-more seed ${i}`, primary_type: 'note' }),
			});
		}
	});

	await page.goto('/daymark');
	const rows = page.locator('[data-recent-list] .daymark-recent__item');
	await expect(rows.first()).toBeVisible();

	// Infinite scroll owns "more", so no redundant timeline link is shown...
	await expect(page.locator('.daymark-recent__morelink')).toHaveCount(0);
	// ...and the timeline stays reachable via the bottom-nav Timeline icon.
	await expect(
		page.locator('.daymark-bottomnav').getByRole('link', { name: 'Timeline' })
	).toBeVisible();
});

// Infinite scroll: the first page caps the list; scrolling the sentinel
// into view auto-fetches and appends the next page, growing the list
// beyond one page. The bottom section-nav stays anchored throughout.
test('infinite scroll appends more recent Marks as the sentinel enters view', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');

	// Seed enough published Marks to guarantee a second page (per_page 5).
	await page.evaluate(async () => {
		const config = window.daymarkApp;
		for (let i = 1; i <= 8; i++) {
			await fetch(`${config.restUrl}marks`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ caption: `Infinite seed ${i}`, primary_type: 'note' }),
			});
		}
	});

	await page.goto('/daymark');
	const rows = page.locator('[data-recent-list] .daymark-recent__item');
	await expect(rows.first()).toBeVisible();

	// Drive the sentinel into view to trigger the next page (a no-op if an
	// eager observer already loaded everything).
	await page
		.locator('[data-recent-sentinel]')
		.scrollIntoViewIfNeeded()
		.catch(() => {});

	// More than a single page (5) is now present.
	await expect.poll(async () => rows.count(), { timeout: 6000 }).toBeGreaterThan(5);

	// The section-nav stayed reachable (anchored, not buried by the list).
	await expect(page.locator('.daymark-bottomnav')).toBeVisible();
});

// Search: the header search icon expands an inline bar with type-filter
// chips; a query narrows the list, and a type filter narrows it too.
test('search: header icon expands the bar and query + type filter narrow the list', async ({ page }) => {
	const tag = `${RUN_ID}srch`;
	const alpha = `E2E searchable ${tag} alphaword`;
	const bravo = `E2E searchable ${tag} bravoword`;

	await loginAs(page);
	await page.goto('/daymark');

	// Two distinct note Marks to filter between.
	await page.evaluate(
		async ({ alpha, bravo }) => {
			const config = window.daymarkApp;
			for (const caption of [alpha, bravo]) {
				await fetch(`${config.restUrl}marks`, {
					method: 'POST',
					headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify({ caption, primary_type: 'note' }),
				});
			}
		},
		{ alpha, bravo }
	);

	await page.goto('/daymark');

	// The search bar is collapsed until the icon is tapped.
	const bar = page.locator('[data-searchbar]');
	await expect(bar).toBeHidden();
	await page.locator('[data-search-toggle]').click();
	await expect(bar).toBeVisible();

	const list = page.locator('[data-recent-list]');

	// A query narrows the recent list to the matching Mark only, and the
	// section heading switches from "Recent Marks" to "Results".
	const heading = page.locator('#daymark-recent-heading');
	await expect(heading).toHaveText('Recent Marks');
	await page.locator('[data-search-input]').fill(alpha);
	await expect(list.getByText(alpha).first()).toBeVisible();
	await expect(list.getByText(bravo)).toHaveCount(0);
	await expect(heading).toHaveText('Results');

	// A type filter narrows too: "Images" excludes these note Marks.
	await page.locator('[data-search-input]').fill('');
	await page.locator('[data-filter-chips] [data-filter="image"]').click();
	await expect(list.getByText(alpha)).toHaveCount(0);

	// Back to "Notes" surfaces them again.
	await page.locator('[data-filter-chips] [data-filter="note"]').click();
	await expect(list.getByText(alpha).first()).toBeVisible();
});

// Per-item delete: the ⋯ menu offers Delete, which requires an explicit
// confirm step. Cancel keeps the item; confirm removes it from the list.
test('per-item menu: delete requires confirm — cancel keeps, confirm removes', async ({ page }) => {
	const caption = `E2E deletable ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await page.evaluate(async (cap) => {
		const config = window.daymarkApp;
		await fetch(`${config.restUrl}marks`, {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ caption: cap, primary_type: 'note' }),
		});
	}, caption);

	await page.goto('/daymark');

	// Scope every action to this Mark's own card.
	const card = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption }).first();
	await expect(card).toBeVisible();

	// Open the ⋯ menu and start a delete — a confirm step must appear first.
	await card.locator('[data-menu-toggle]').click();
	await card.locator('[data-menu-delete]').click();
	const confirm = card.locator('[data-menu-confirm]');
	await expect(confirm).toBeVisible();
	await expect(confirm).toContainText('move to Trash');

	// Cancel returns to the action list and keeps the Mark.
	await card.locator('[data-menu-delete-cancel]').click();
	await expect(confirm).toBeHidden();
	await expect(card.locator('[data-menu-actions]')).toBeVisible();
	await expect(card).toBeVisible();

	// Delete again and confirm — this time it is removed from the list.
	await card.locator('[data-menu-delete]').click();
	await expect(confirm).toBeVisible();
	await card.locator('[data-menu-delete-confirm]').click();
	await expect(
		page.locator('.daymark-recent__item-wrap').filter({ hasText: caption })
	).toHaveCount(0);
});

// Per-notification reply: a reply icon reveals an inline box for that
// notification; submitting posts the reply and collapses the box.
test('notifications: reply icon expands an inline box and submits', async ({ page }) => {
	const caption = `E2E replyui ${RUN_ID}`;
	const incoming = `E2E incoming ${RUN_ID}`;
	const myReply = `E2E my reply ${RUN_ID}`;

	await loginAs(page);

	// Publish a note Mark through the UI.
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// A comment arrives on it (same storage path as an imported reply).
	await page.evaluate(async (replyText) => {
		const config = window.daymarkApp;
		const listRes = await fetch(`${config.restUrl}marks?per_page=1`, {
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
	}, incoming);

	await page.goto('/daymark/notifications');

	// Scope to this notification's own card.
	const card = page.locator('.daymark-note-card').filter({ hasText: incoming }).first();
	await expect(card).toBeVisible();

	// The reply box is hidden until the reply icon is tapped.
	const form = card.locator('[data-reply-form]');
	await expect(form).toBeHidden();
	await card.locator('[data-reply-toggle]').click();
	await expect(form).toBeVisible();

	// Submitting a reply collapses the box and confirms.
	await form.locator('[data-reply-input]').fill(myReply);
	await form.locator('[data-reply-send]').click();
	await expect(form).toBeHidden();
	await expect(card.locator('[data-replied]')).toBeVisible();
});

// Plugins list table offers a one-click path into the app.
test('plugins page offers an Open Daymark action link', async ({ page }) => {
	await loginAs(page);
	await page.goto('/wp-admin/plugins.php');
	const link = page
		.locator('tr[data-slug="daymark"]')
		.locator('a', { hasText: 'Open Daymark' })
		.first();
	await expect(link).toBeVisible();
	expect(await link.getAttribute('href')).toContain('/daymark');
});

// The PWA manifest serves directly (no canonical 301) with the app scope.
test('manifest serves directly with app start_url', async ({ request }) => {
	const res = await request.get('/daymark/manifest.json', { maxRedirects: 0 });
	expect(res.status()).toBe(200);
	expect(res.headers()['content-type']).toContain('manifest+json');
	const manifest = await res.json();
	expect(manifest.start_url).toContain('/daymark');
	expect(manifest.scope).toContain('/daymark');
});

// Save as Draft → Drafts row → resume editing → publish (running the
// stored destinations via deferred syndication).
test('draft lifecycle: save, resume from Drafts row, publish', async ({ page }) => {
	const caption = `E2E draft ${RUN_ID}`;
	const finished = `${caption} finished`;

	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="save-draft"]').click();
	await expect(page.getByText('Saved as draft')).toBeVisible();

	// Not publicly visible while a draft.
	await page.goto('/timeline/');
	await expect(page.getByText(caption)).toHaveCount(0);

	// Home shows the Drafts row; the row is chip-marked.
	await page.goto('/daymark');
	await expect(page.getByRole('heading', { name: 'Drafts' })).toBeVisible();
	const row = page.locator('[data-edit-draft]').filter({ hasText: caption }).first();
	await expect(row).toBeVisible();
	await expect(row.locator('.daymark-chip--draft')).toBeVisible();

	// Resume: composer reopens prefilled with the draft's caption.
	await row.click();
	await expect(page.getByText('Edit Draft')).toBeVisible();
	await expect(page.locator('#daymark-caption')).toHaveValue(caption);
	await page.fill('#daymark-caption', finished);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Draft row entry is gone; the published Mark is public.
	await page.goto('/daymark');
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
	await page.goto('/daymark');
	await page.evaluate(async () => {
		const config = window.daymarkApp;
		await fetch(`${config.restUrl}notifications`, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
		});
	});

	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// Comment dates are second-resolution and the "seen" baseline was set
	// marks ago; guarantee the reply lands in a later second so
	// has_unread()'s strict > holds on fast runners (not a flake).
	await page.waitForTimeout(1100);

	// A new reply arrives (on-site comment on the Mark post).
	await page.evaluate(async (replyText) => {
		const config = window.daymarkApp;
		const listRes = await fetch(`${config.restUrl}marks?per_page=1`, {
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
	await page.goto('/daymark');
	await expect(page.locator('.daymark-iconbtn__dot')).toBeVisible();

	// Viewing notifications clears it without a reload…
	await page.locator('.daymark-iconbtn').click();
	await expect(page.getByText(reply).first()).toBeVisible();
	await page.locator('.daymark-backlink').click();
	await expect(page.locator('[data-action="new-mark"]')).toBeVisible();
	await expect(page.locator('.daymark-iconbtn__dot')).toHaveCount(0);

	// …and stays cleared across a full reload (server-side read state).
	await page.goto('/daymark');
	await expect(page.locator('[data-action="new-mark"]')).toBeVisible();
	await expect(page.locator('.daymark-iconbtn__dot')).toHaveCount(0);
});

// While a publish is in flight: both buttons disabled, the button shows
// the loading state, and there is no separate "Publishing…" message.
test('publish in flight disables both buttons and shows only the button loading state', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', `E2E loading ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	// Hold the create request so the in-flight UI is observable.
	await page.route('**/daymark/v1/marks', async (route) => {
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
	await page.goto('/daymark');

	// Scope to the bottom nav: a substring name match on 'Timeline' would
	// also catch the recent section's "View more on your timeline →" link
	// when Marks exist, so query within the nav to assert the nav link itself.
	const nav = page.locator('.daymark-bottomnav');
	const timeline = nav.getByRole('link', { name: 'Timeline' });
	await expect(timeline).toBeVisible();
	await expect(timeline).toHaveAttribute('title', 'Timeline');
	await expect(timeline.locator('svg.daymark-bottomnav__icon')).toBeVisible();
	expect(await timeline.getAttribute('href')).toContain('/timeline');

	// Every view link carries an icon.
	await expect(page.locator('.daymark-bottomnav__link svg')).toHaveCount(5);
	// The label text is present for assistive tech but visually hidden.
	await expect(nav.getByRole('link', { name: 'Notes' })).toBeVisible();
});

// Awareness note: when a third-party publishing plugin is active, the
// publish screen tells the user their Mark will also go out that way.
// (The E2E publish-helper mu-plugin registers a fake "Test Publicize".)
test('publish screen notes active third-party publishing plugins', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', `E2E helpers ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	const note = page.locator('.daymark-helpers-note');
	await expect(note).toBeVisible();
	await expect(note).toContainText('Test Publicize');
});

// A controllable third-party helper gets its own per-Mark toggle, and
// the selection is sent with the publish request.
test('controllable helper: per-Mark toggle appears and is sent on publish', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', `E2E helper ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	const toggle = page.locator('[data-helper="daymark-e2e-helper"]');
	await expect(toggle).toBeVisible();
	await expect(toggle).not.toBeChecked(); // opt-in: default off
	await toggle.click({ force: true });
	await expect(toggle).toBeChecked();

	// Capture the create request body to confirm the selection is sent.
	let sentBody = '';
	await page.route('**/daymark/v1/marks', async (route) => {
		if ('POST' === route.request().method()) {
			sentBody = route.request().postData() || '';
		}
		await route.continue();
	});

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();
	expect(sentBody).toContain('publish_helpers');
	expect(sentBody).toContain('daymark-e2e-helper');
});

// A registered, connected connector appears as a real destination, can be
// toggled and published to, and is remembered per Mark type. The E2E
// Connector fixture registers a fake connected network; this guards the
// connector destination UI in app.js (rendering, checked-state, per-type
// preselection) that ships but no default install exercises. Backflow
// *display* of connector replies is covered by PHPUnit, not here.
test('connected connector: destination toggle publishes and is remembered per type', async ({ page }) => {
	const caption = `E2E connector ${RUN_ID}`;

	await loginAs(page);
	// Opt this test's requests into the fake connector (see the
	// daymark-e2e-connector fixture); other tests keep the no-connector base.
	await page
		.context()
		.addCookies([{ name: 'daymark_e2e_connector', value: '1', url: new URL(page.url()).origin }]);
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Offered as a connected destination, initially unchecked (the fake
	// connector isn't a model default for notes).
	const toggle = page.locator('[data-connector="e2e-net"]');
	await expect(toggle).toBeVisible();
	await expect(page.getByText('Connected · E2E')).toBeVisible();
	await expect(toggle).not.toBeChecked();

	await toggle.click({ force: true });
	await expect(toggle).toBeChecked();

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();
	// The success screen lists the chosen syndication destination.
	await expect(page.getByText('E2E Network')).toBeVisible();

	// Per-type memory: the next note Mark preselects the connector.
	await page.goto('/daymark');
	await page.locator('[data-action="new-mark"]').click();
	await page.fill('#daymark-caption', `E2E connector memory ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();
	await expect(page.locator('[data-connector="e2e-net"]')).toBeChecked();
});
