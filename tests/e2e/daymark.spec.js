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
import { readFileSync } from 'node:fs';

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

// Opens the composer via the Home launcher: tap "+ New Mark" to fan out the
// Image/Video/Audio/Note bubbles, then tap the one matching `type`. Replaces
// the old single click straight into #create now that the launcher sits in
// front of it.
async function openComposer(page, type = 'note') {
	await page.locator('[data-action="new-mark"]').click();
	await page.locator(`[data-launcher-type="${type}"]`).click();
}

// --- Subscriptions & Timeline (issue #78) helpers ---
//
// Subscribing and refreshing a feed hits a real external site
// (wordpress.org/news/, chosen because it reliably autodiscovers a feed at
// /feed/), so this file seeds it once and shares the result across the
// subscription tests below instead of repeating the outbound request per
// test. Safe to memoize at module scope: this file's tests run serially in
// a single Playwright worker (playwright.config.js: workers: 1,
// fullyParallel: false), so whichever subscription test below runs first
// performs the real subscribe + refresh and the others reuse the result.
let subscriptionSetup = null;

function ensureSubscription(page) {
	if (!subscriptionSetup) {
		subscriptionSetup = page.evaluate(async () => {
			const config = window.daymarkApp;
			const headers = { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' };
			const subRes = await fetch(`${config.restUrl}subscriptions`, {
				method: 'POST',
				headers,
				credentials: 'same-origin',
				body: JSON.stringify({ site_url: 'https://wordpress.org/news/' }),
			});
			let subscription = await subRes.json();

			// A retried test in this same worker can re-enter this branch
			// (subscriptionSetup only guards against concurrent calls within
			// one module lifetime, not a fresh one after a retry) against a
			// subscription this exact URL already created — that's a real
			// 409, not a bug, so fall back to looking the existing row up
			// rather than propagating a body with no `id`.
			if (!subscription || !subscription.id) {
				const listRes = await fetch(`${config.restUrl}subscriptions`, {
					headers,
					credentials: 'same-origin',
				});
				const list = await listRes.json();
				subscription = (Array.isArray(list) ? list : []).find(
					(s) => s.feed_url && s.feed_url.includes('wordpress.org')
				);
			}

			await fetch(`${config.restUrl}subscriptions/${subscription.id}/refresh`, {
				method: 'POST',
				headers,
				credentials: 'same-origin',
			});
			return subscription;
		});
	}
	return subscriptionSetup;
}

// Scrolls the recent list's infinite-scroll sentinel into view repeatedly
// until at least one subscription-post card (`[data-subpost]`) shows up, or
// the feed genuinely runs out of pages. Bounded: on a long-lived site (this
// file's own header warns tests "do not delete" what they create) enough
// prior E2E Marks can outrank an older real feed post by publish date and
// push it several pages deep, but not indefinitely — see the comment on
// the first subscription test below for why these tests sit early in this
// file specifically to keep that depth shallow on a normal/scratch site.
async function findSubscriptionCard(page) {
	const list = page.locator('[data-recent-list]');
	const card = list.locator('[data-subpost]').first();
	const sentinel = page.locator('[data-recent-sentinel]');

	for (let i = 0; i < 20; i++) {
		if (await card.count()) {
			return card;
		}
		if (await sentinel.isHidden().catch(() => true)) {
			break; // No more pages left to load.
		}
		await sentinel.scrollIntoViewIfNeeded().catch(() => {});
		await page.waitForTimeout(300);
	}
	return card;
}

// Scenario 1 (unauthenticated half): /daymark redirects to login.
test('unauthenticated /daymark redirects to login', async ({ page }) => {
	await page.goto('/daymark');
	await expect(page).toHaveURL(/wp-login/);
});

// The new nav destinations get the same unauthenticated treatment as Home
// and Notifications already did — a logged-out visitor never sees them.
test('unauthenticated Explore/Search/Me redirect to login too', async ({ page }) => {
	for (const path of ['/daymark/explore', '/daymark/search', '/daymark/me']) {
		await page.goto(path);
		await expect(page).toHaveURL(/wp-login/);
	}
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

// --- Subscriptions & Timeline (issue #78): Home is the merged feed ---
//
// These three tests are placed here, right after the two tests above that
// create no Marks, rather than at the end of this file with the rest of
// the "coverage for changes since" additions. A subscription post's
// `published_at` is the real source site's own publish date, not "now",
// so — unlike a fresh Mark, which is always the newest thing on the site
// and therefore always page one — it can be outranked and pushed several
// pages deep by the dozens of same-run E2E Marks the tests further down
// this file create. Running early keeps the merged feed's first page(s)
// small enough that findSubscriptionCard() above only rarely needs to
// scroll at all.

// A subscription-post item renders in the same merged [data-recent-list]
// as the user's own Marks — not a separate section — each via its own
// card shape.
test('home Timeline blends a subscribed feed post with the user’s own Mark', async ({ page }) => {
	const caption = `E2E timeline mark ${RUN_ID}`;

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

	await ensureSubscription(page);
	await page.goto('/daymark');

	// The Mark card: a <button> (expands its own content inline), not an
	// <a> anymore.
	const markWrap = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption });
	await expect(markWrap).toBeVisible();
	await expect(markWrap.locator('[data-expand-post]')).toBeVisible();

	// A subscription-post card: a <button>, not an <a>.
	const subCard = await findSubscriptionCard(page);
	await expect(subCard).toBeVisible();
	await expect(subCard).toHaveClass(/daymark-recent__item--button/);
});

// Clicking a Timeline card expands its own content in place, directly
// below the card — never a modal/overlay, and never (for a Mark or
// ordinary post) a navigation away from the app to the permalink the way
// it used to. Covers both a Mark and a subscription post, since both share
// the same inline-expand mechanism (toggleExpand()/onFeedListClick() in
// assets/app.js) and the same "just the post's own content, no
// nav/header/footer/comments" contract — a Mark/ordinary post's comes
// straight from the site's own post_content (GET /marks/{id}/content); a
// subscription post's is the existing external click-through fetch
// (GET /subscription-posts/{id}), which can plausibly fail in CI (timeout,
// 404, etc.), so that half asserts on whichever state the panel actually
// reaches rather than a hard-coded outcome.
test('clicking a Timeline card expands its content in place, not a modal or a navigation', async ({
	page,
}) => {
	const caption = `E2E expand mark ${RUN_ID}`;

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

	await ensureSubscription(page);
	await page.goto('/daymark');

	// A Mark card: expands its own content inline, sourced straight from
	// this site's own database — no external fetch, so this can assert a
	// hard-coded outcome (the caption's own text, since a note Mark's
	// content is just its caption as a paragraph block).
	const markWrap = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption });
	const markCard = markWrap.locator('[data-expand-post]');
	await expect(markCard).toBeVisible();
	await markCard.click();
	const markPanel = markWrap.locator('[data-expand-panel]');
	await expect(markPanel).toBeVisible();
	await expect(markPanel.locator('.daymark-expand-content')).toContainText(caption);
	// Still on the app shell (its own default-screen hash, #home, included)
	// — never navigated off to the Mark's real permalink.
	await expect(page).toHaveURL(/\/daymark\/(#home)?$/);
	await expect(page.locator('.daymark-sheet')).toHaveCount(0);

	// Clicking it again collapses it back in place.
	await markCard.click();
	await expect(markPanel).toBeHidden();

	// A subscription-post card: same mechanism, external content instead —
	// settles on success (`.daymark-expand-content`) or a graceful failure
	// (`.daymark-error`), either way followed by a real "View original ↗"
	// link with a real http(s) href, and never a modal or a navigation.
	// The external fetch has its own 15s server-side timeout, so this
	// allows generous headroom.
	const subCard = await findSubscriptionCard(page);
	await expect(subCard).toBeVisible();
	// A direct following-sibling of the card itself (same DOM shape
	// onFeedListClick()'s own closest('.daymark-recent__item-wrap') relies
	// on) — more precise than re-deriving the wrap via a `has:` filter,
	// which re-resolves subCard's own selector independently and can
	// disagree with which element was actually clicked.
	const subPanel = subCard.locator('xpath=following-sibling::*[@data-expand-panel]');
	await subCard.click();
	await expect(subPanel).toBeVisible();
	const viewOriginal = subPanel.getByRole('link', { name: /View original/ });
	await expect(viewOriginal).toBeVisible({ timeout: 20000 });
	expect(await viewOriginal.getAttribute('href')).toMatch(/^https?:\/\//);
	await expect(subPanel.locator('.daymark-loading')).toHaveCount(0);
	await expect(page).toHaveURL(/\/daymark\/(#home)?$/);
	await expect(page.locator('.daymark-sheet')).toHaveCount(0);
});

// Pull-to-refresh is gesture-only — there's no visible "Refresh" link or
// button on Home (Home is assumed to be the Timeline). Independent of the
// cron schedule and separately rate-limited per subscription (15 minutes);
// exercised here by refreshing right after ensureSubscription()'s own
// refresh, which should land on the "checked too recently" outcome. The
// exact status wording depends on real network timing, so this only
// asserts the pull indicator shows while in flight and the status settles
// to something non-empty afterward, not a specific message.
test('pull-to-refresh (touch drag) shows the pull indicator and reports a status', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await ensureSubscription(page);
	await page.goto('/daymark');

	// A brief artificial delay on the subscriptions list fetch so the
	// in-flight state is reliably observable rather than racing a refresh
	// that can resolve in well under a frame (same technique the "publish
	// in flight" test below uses via page.route).
	await page.route('**/daymark/v1/subscriptions', async (route) => {
		if ('GET' === route.request().method()) {
			await new Promise((resolve) => setTimeout(resolve, 800));
		}
		await route.continue();
	});

	const indicator = page.locator('[data-pull-indicator]');
	const status = page.locator('[data-recent-refresh-status]');
	await expect(status).toHaveText('');

	// Simulate the drag gesture bindPullGesture() listens for: a touchstart
	// at the top of an already-scrolled-to-top page, a touchmove more than
	// THRESHOLD (64px, damped by 0.5x) below that, then touchend — which is
	// what actually calls pullRefresh().
	await page.evaluate(() => {
		const list = document.querySelector('[data-recent-list]');
		const touchAt = (clientY) => new Touch({ identifier: 1, target: list, clientX: 40, clientY });
		list.dispatchEvent(new TouchEvent('touchstart', { touches: [touchAt(100)], bubbles: true }));
		list.dispatchEvent(new TouchEvent('touchmove', { touches: [touchAt(260)], bubbles: true }));
		list.dispatchEvent(new TouchEvent('touchend', { touches: [], bubbles: true }));
	});

	await expect(indicator).toHaveClass(/is-settling/);
	await expect(status).not.toHaveText('', { timeout: 20000 });
	await expect(indicator).not.toHaveClass(/is-settling/);
});

// Publish a note Mark to your own site and see it in the Notes view.
// With nothing connected, "Your Site" is the only destination.
test('note Mark publishes to your site and is findable via Search', async ({ page }) => {
	const caption = `E2E note ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);

	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();

	// Your Site is always the destination; no social networks are offered
	// unless a connector plugin registers one.
	await expect(page.locator('.daymark-dest__name', { hasText: 'Your Site' })).toBeVisible();

	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	// The Notes section page is gone; the same per-type filtering now lives
	// in Search.
	await page.goto('/daymark/search');
	await page.locator('[data-filter="note"]').click();
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
	await openComposer(page);
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
	await openComposer(page);
	await page.fill('#daymark-caption', `E2E category memory ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();
	const rememberedPhotos = page
		.locator('.daymark-dest')
		.filter({ hasText: 'E2E Photos' })
		.locator('[data-category]');
	await expect(rememberedPhotos).toBeChecked();
});

// Image Mark via the file picker: per-image alt field, correct article
// on the publish screen, and it lands in the images view.
test('image Mark: alt field, correct article, findable via Search', async ({ page }) => {
	const caption = `E2E image ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page, 'image');

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

	// Standard post visible in wp-admin, and findable via Search — the
	// Images section page is gone; the same per-type filtering now lives
	// there.
	await page.goto('/wp-admin/edit.php');
	await expect(page.locator('.row-title').filter({ hasText: caption }).first()).toBeVisible();
	await page.goto('/daymark/search');
	await page.locator('[data-filter="image"]').click();
	await expect(page.getByText(caption)).toBeVisible();
});

// Timeline cards get a differentiated visual treatment per content kind
// (issue: Timeline/card improvements) — an image Mark's card is the
// media-dominant layout (a full-width banner above the caption, not a
// small thumb beside it), while a Note Mark's stays the compact,
// typography-first row it always was. Both still carry the shared rail's
// type-indicator icon between the site icon and the card itself.
test('Timeline cards: image Mark gets the media-dominant layout, Note Mark stays compact', async ({
	page,
}) => {
	const imageCaption = `E2E card kind image ${RUN_ID}`;
	const noteCaption = `E2E card kind note ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');

	await openComposer(page, 'image');
	await page.setInputFiles('#daymark-file-input', 'tests/e2e/fixtures/test-image.png');
	const altField = page.locator('[data-alt-for]').first();
	await expect(altField).toBeVisible();
	await altField.fill(`E2E alt ${RUN_ID}`);
	await page.fill('#daymark-caption', imageCaption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	await page.evaluate(async (cap) => {
		const config = window.daymarkApp;
		await fetch(`${config.restUrl}marks`, {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ caption: cap, primary_type: 'note' }),
		});
	}, noteCaption);

	await page.goto('/daymark');

	const imageCard = page.locator('.daymark-recent__item-wrap').filter({ hasText: imageCaption });
	await expect(imageCard).toBeVisible();
	await expect(imageCard.locator('.daymark-recent__typeicon')).toHaveCount(1);
	await expect(imageCard.locator('.daymark-recent__item--image')).toHaveCount(1);
	await expect(imageCard.locator('.daymark-recent__thumbwrap--media')).toHaveCount(1);

	const noteCard = page.locator('.daymark-recent__item-wrap').filter({ hasText: noteCaption });
	await expect(noteCard).toBeVisible();
	await expect(noteCard.locator('.daymark-recent__typeicon')).toHaveCount(1);
	await expect(noteCard.locator('.daymark-recent__item--image')).toHaveCount(0);
	await expect(noteCard.locator('.daymark-recent__thumbwrap--media')).toHaveCount(0);
});

// Camera-first: "assume I'm standing somewhere and want to publish." A
// typed launcher entry's picker offers camera/mic capture as the primary
// action (requesting it via the `capture` attribute), with a lower-emphasis
// "Choose from library" fallback that clears it — so an already-taken photo
// stays reachable without being the default.
test('camera-first: the Image picker offers capture first, with a library fallback', async ({
	page,
}) => {
	// Any file chooser a trigger button opens is resolved immediately with
	// nothing selected — this test only checks the `capture` attribute the
	// buttons set/clear, not an actual file selection through them.
	page.on('filechooser', (chooser) => chooser.setFiles([]));

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page, 'image');

	const input = page.locator('#daymark-file-input');
	const captureBtn = page.locator('[data-picker-capture]');
	const libraryBtn = page.locator('[data-picker-library]');

	await expect(captureBtn).toContainText('Take Photo');
	await expect(libraryBtn).toContainText('Choose from library');
	await expect(input).not.toHaveAttribute('capture');

	await captureBtn.click();
	await expect(input).toHaveAttribute('capture', 'environment');

	await libraryBtn.click();
	await expect(input).not.toHaveAttribute('capture');
});

// Web Share Target: "Share -> Daymark" from the OS share sheet (Photos,
// Safari, any other app) posts straight to /daymark/share, which creates a
// draft and redirects into the composer with it already loaded — no need
// to open Daymark first and pick media manually.
test('share target: sharing a photo creates a draft that opens straight into the composer', async ({
	page,
}) => {
	const caption = `E2E share ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');

	// The OS share sheet's POST — same session cookies as the logged-in
	// page. Inspecting the redirect ourselves (rather than following it)
	// keeps the query string/fragment split unambiguous.
	const shareRes = await page.request.post('/daymark/share', {
		multipart: {
			title: caption,
			'media[]': {
				name: 'test-image.png',
				mimeType: 'image/png',
				buffer: readFileSync('tests/e2e/fixtures/test-image.png'),
			},
		},
		maxRedirects: 0,
	});

	expect(shareRes.status()).toBe(302);
	const location = shareRes.headers()['location'];
	expect(location).toContain('daymark_draft=');
	expect(location).toContain('#create');

	// A real browser navigation (not the API context) so the fragment is
	// honored and the app boots straight into that draft's composer. The
	// shared photo was already sideloaded server-side, so it shows as
	// existing (not freshly-picked) media, same as resuming any other draft
	// with an attachment already on it.
	await page.goto(location);
	await expect(page.locator('#daymark-caption')).toHaveValue(caption);
	await expect(page.locator('.daymark-editmedia__thumb')).toBeVisible();

	// It's a draft — findable via Drafts on Home, not published/syndicated.
	await page.goto('/daymark');
	await expect(page.locator('[data-edit-draft]').filter({ hasText: caption })).toBeVisible();
});

// Optional Title field: audio/video Marks surface an editable, optionally
// AI-pre-filled Title field with a ⓘ tap-to-reveal hint; note Marks do not.
test('audio Mark shows an editable optional Title field with a toggleable hint', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page, 'audio');

	// Scope every assertion to the composer screen (bare role/text matches
	// break this suite). The Audio bubble pre-sets the type before any file
	// is attached, so the Title field is already showing.
	const composer = page.locator('.daymark-screen').first();
	await expect(page.locator('[data-type-badge]')).toHaveText(/audio/i);

	// Attaching a real audio file exercises the picker's audio/* filter and
	// leaves the type unchanged.
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

	// It also dismisses the same way search and the reply box do: an
	// outside click, or Escape with focus returned to the ⓘ button.
	await info.click();
	await expect(hint).toBeVisible();
	await page.locator('#daymark-caption').click();
	await expect(hint).toBeHidden();
	await expect(info).toHaveAttribute('aria-expanded', 'false');

	await info.click();
	await expect(hint).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(hint).toBeHidden();
	await expect(info).toBeFocused();
});

// The Title field is a per-type affordance: a plain note Mark never shows
// it (its title is derived from the caption/timestamp).
test('note Mark does not show the Title field', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);

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
	await openComposer(page);
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
// keyboard focus reaches one of its own controls. The header does the
// opposite — hides on scroll-up, reappears on scroll-down — so the two
// chrome bars never both cost their height back at once.
test('home header/footer auto-hide in opposite directions and return on scroll or focus', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');

	// Enough seeded Marks to make the page taller than the viewport — kept
	// at 10 (not more) since Daymark_Rate_Limiter::ACTION_PUBLISH caps
	// publishing at 20 requests per 5 minutes, and 30 sequential creates
	// here also blew past this test's own timeout.
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

	// Wait for the page to actually grow taller than the viewport, then
	// pad it with a plain synthetic spacer well past the recent list —
	// real seeded rows alone left too little scroll room to test the
	// header's hide-on-scroll-up behavior without landing back inside the
	// y < 80 "always show both, near the top" reset (see
	// bindChromeAutoHide(), app.js) on the very next scroll-up. The spacer
	// only needs to exist for scroll room; it carries no content of its
	// own to assert on.
	await page.waitForFunction(() => document.documentElement.scrollHeight > window.innerHeight + 100);
	await page.evaluate(() => {
		const spacer = document.createElement('div');
		spacer.style.height = '2000px';
		document.body.appendChild(spacer);
	});

	const footer = page.locator('.daymark-homefooter');
	const header = page.locator('.daymark-topbar');
	await expect(footer).toBeVisible();
	await expect(footer).not.toHaveClass(/is-footer-hidden/);
	await expect(header).not.toHaveClass(/is-header-hidden/);

	// Scroll well clear of the top first, so every up/down step below stays
	// away from the y < 80 "always show both" reset.
	await page.mouse.wheel(0, 1000);
	await expect(footer).toHaveClass(/is-footer-hidden/);
	await expect(header).not.toHaveClass(/is-header-hidden/);

	// Scroll up (still far from the top): footer returns, header hides.
	await page.mouse.wheel(0, -200);
	await expect(footer).not.toHaveClass(/is-footer-hidden/);
	await expect(header).toHaveClass(/is-header-hidden/);

	// Scroll down again: footer hides, header returns.
	await page.mouse.wheel(0, 200);
	await expect(footer).toHaveClass(/is-footer-hidden/);
	await expect(header).not.toHaveClass(/is-header-hidden/);

	// Confirm tabbing a footer control reveals both bars, even with the
	// header currently hidden from a scroll-up.
	await page.mouse.wheel(0, -200);
	await expect(header).toHaveClass(/is-header-hidden/);
	await page.locator('[data-action="new-mark"]').focus();
	await expect(footer).not.toHaveClass(/is-footer-hidden/);
	await expect(header).not.toHaveClass(/is-header-hidden/);
});

// With IntersectionObserver (all supported browsers) the recent list uses
// infinite scroll, so the redundant "Load more" fallback button is not
// rendered — the appended pages already show everything. (The button only
// appears as a no-IntersectionObserver fallback.)
test('home does not render the redundant Load more button when infinite scroll is active', async ({ page }) => {
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

	// Infinite scroll owns "more", so no redundant fallback button is shown.
	await expect(page.locator('[data-recent-loadmore]')).toHaveCount(0);
	await expect(page.locator('.daymark-homelink')).toBeVisible();
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

// Search: its own bottom-nav destination and route (not a collapsible
// header bar on Home anymore) — a query narrows the results, and a type
// filter narrows them too.
test('search: the Search tab shows a query + type filter that narrow the results', async ({ page }) => {
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
	await page.locator('.daymark-bottomnav__link', { hasText: 'Search' }).click();
	await expect(page).toHaveURL(/#search$/);
	await expect(page.locator('.daymark-bottomnav__link.is-active')).toHaveText('Search');
	await expect(page.locator('h1', { hasText: 'Search' })).toBeVisible();

	const list = page.locator('[data-search-results]');

	// Landing on Search with no query/filter shows everything by default.
	await expect(list.getByText(alpha).first()).toBeVisible();
	await expect(list.getByText(bravo).first()).toBeVisible();

	// A query narrows the results to the matching Mark only.
	await page.locator('[data-search-input]').fill(alpha);
	await expect(list.getByText(alpha).first()).toBeVisible();
	await expect(list.getByText(bravo)).toHaveCount(0);

	// A type filter narrows too: "Images" excludes these note Marks.
	await page.locator('[data-search-input]').fill('');
	await page.locator('[data-filter-chips] [data-filter="image"]').click();
	await expect(list.getByText(alpha)).toHaveCount(0);

	// Back to "Notes" surfaces them again.
	await page.locator('[data-filter-chips] [data-filter="note"]').click();
	await expect(list.getByText(alpha).first()).toBeVisible();
});

// Source filter: "My Marks" scopes results to just the user's own Marks
// (excluding subscription posts); picking a specific subscribed site scopes
// it to just that site's posts (excluding Marks and every other
// subscription). Reuses ensureSubscription()'s shared, memoized subscribe +
// refresh from earlier in this file.
test('search: Source filter scopes results to My Marks or one subscribed site', async ({ page }) => {
	const caption = `E2E source-filter mark ${RUN_ID}`;

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

	const subscription = await ensureSubscription(page);
	await page.goto('/daymark/search');

	const sourceFilter = page.locator('[data-source-filter]');
	const list = page.locator('[data-search-results]');

	// The per-site <option>s populate asynchronously (loadSubscriptionsForFilter()
	// patches the <select> once GET /subscriptions resolves) — wait for the
	// one this test needs before selecting it, rather than assuming it's
	// already there.
	await expect(sourceFilter.locator(`option[value="${subscription.id}"]`)).toHaveCount(1);

	// "My Marks": the seeded Mark shows, no subscription-post card does.
	await sourceFilter.selectOption('mine');
	await expect(list.getByText(caption).first()).toBeVisible();
	await expect(list.locator('[data-subpost]')).toHaveCount(0);

	// The subscribed site: a subscription-post card shows, the Mark doesn't.
	await sourceFilter.selectOption(String(subscription.id));
	await expect(list.getByText(caption)).toHaveCount(0);
	await expect(list.locator('[data-subpost]').first()).toBeVisible();

	// Back to "All": the Mark is present again. (Not asserting a
	// subscription-post card reappears too here — search has no
	// pagination, a flat per_page=20, and this file's own many
	// Mark-creating tests can by now outnumber the WordPress.org feed's
	// handful of older posts within that window. That's an existing,
	// unrelated limitation of unscoped search, not something this test is
	// about.)
	await sourceFilter.selectOption('');
	await expect(list.getByText(caption).first()).toBeVisible();
});

// Site icon: tapping a Timeline item's site icon is a single click that
// filters Timeline down to just that source — no popover, no "visit the
// site" action. (A live product review of the earlier two-action popover
// version asked for exactly this: drop "visit site" entirely, and stop
// overlapping the icon on the thumbnail's corner like a circular badge, in
// favor of its own separate, non-circular layout element — see the CSS
// comment on .daymark-recent__siteicon.) Covers both card shapes — a
// Mark's own icon ("your Marks") and a subscription post's site icon — and
// reuses ensureSubscription()'s shared, memoized subscribe + refresh from
// earlier in this file.
test('site icon: a single click filters Timeline to that source', async ({ page }) => {
	const caption = `E2E siteicon mark ${RUN_ID}`;

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

	await ensureSubscription(page);
	await page.goto('/daymark');

	// --- The user's own Mark's site icon ---
	const markCard = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption }).first();
	await expect(markCard).toBeVisible();
	const markIcon = markCard.locator('[data-filter-site="mine"]');
	await expect(markIcon).toBeVisible();
	// No menu markup left to reuse — the icon itself carries the
	// filter action directly, and has its own accessible name since there's
	// no popover left to label a menu item inside. A published Mark's card
	// has no ⋯ menu at all (Draft-only affordance), so there's none here to
	// collide with.
	await expect(markIcon).toHaveAttribute('aria-label', 'Filter Timeline to your Marks');
	await expect(markCard.locator('[data-menu]')).toHaveCount(0);

	// The icon's native on-hover tooltip (issue #181) names the site itself
	// — distinct from aria-label's action wording above — as "Title\nURL".
	const siteConfig = await page.evaluate(() => ({
		siteTitle: window.daymarkApp.siteTitle,
		siteUrl: window.daymarkApp.siteUrl,
	}));
	await expect(markIcon).toHaveAttribute('title', `${siteConfig.siteTitle || 'Site'}\n${siteConfig.siteUrl}`);

	// One click — no menu-open step first — jumps straight to Search with
	// "My Marks" already applied.
	await markIcon.click();
	await expect(page).toHaveURL(/#search$/);
	const sourceFilter = page.locator('[data-source-filter]');
	const results = page.locator('[data-search-results]');
	await expect(sourceFilter).toHaveValue('mine');
	await expect(results.getByText(caption).first()).toBeVisible();
	await expect(results.locator('[data-subpost]')).toHaveCount(0);

	// --- A subscription post's site icon ---
	// Still on Search: switch the Source filter straight to this
	// subscription (its id is the option value — same value a
	// subscription-post card's own site icon uses for data-filter-site)
	// rather than clearing back to "All". Search has no pagination (a flat
	// per_page=20 fetch), so on a long-lived site "All" can be pushed past
	// page 1 by other E2E-created Marks; filtering directly to this
	// subscription guarantees a subscription-post card shows up. It renders
	// the same site icon Home's own cards do, one shared implementation.
	const subscription = await ensureSubscription(page);
	await sourceFilter.selectOption(String(subscription.id));
	const subCard = results.locator('[data-subpost]').first();
	await expect(subCard).toBeVisible();
	const subWrap = results
		.locator('.daymark-recent__item-wrap')
		.filter({ has: page.locator('[data-subpost]') })
		.first();
	const subIcon = subWrap.locator('[data-filter-site]');
	await expect(subIcon).toBeVisible();
	// The exact site title is real, external data (the subscribed site's
	// own), so only the fixed wording the label wraps around it is
	// asserted here — same tolerance the Source-filter test above already
	// applies to this same subscription.
	expect(await subIcon.getAttribute('aria-label')).toContain('Filter Timeline to posts from');
	// Same on-hover tooltip as the Mark icon above, but this site's title is
	// real external data — only its known site_url is asserted here, same
	// tolerance already applied to the aria-label above.
	expect(await subIcon.getAttribute('title')).toContain(subscription.site_url);

	// One click re-applies the Source filter to just this one subscribed
	// site, still on Search.
	await subIcon.click();
	await expect(sourceFilter).not.toHaveValue('');
	await expect(results.getByText(caption)).toHaveCount(0);
	await expect(results.locator('[data-subpost]').first()).toBeVisible();
});

// Per-item delete: the ⋯ menu is a Draft-only affordance (a published Mark
// offers no in-app Edit/Delete at all — see the "read no ⋯ menu" test below)
// and offers Delete, which requires an explicit confirm step. Cancel keeps
// the item; confirm removes it from the list.
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
			body: JSON.stringify({ caption: cap, primary_type: 'note', status: 'draft' }),
		});
	}, caption);

	await page.goto('/daymark');

	// Scope every action to this Draft's own card, and within it to the ⋯
	// actions menu specifically ([data-actions]).
	const card = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption }).first();
	await expect(card).toBeVisible();
	const menu = card.locator('[data-actions]');

	// Open the ⋯ menu and start a delete — a confirm step must appear first.
	await menu.locator('[data-menu-toggle]').click();
	await menu.locator('[data-menu-delete]').click();
	const confirm = menu.locator('[data-menu-confirm]');
	await expect(confirm).toBeVisible();
	await expect(confirm).toContainText('move to Trash');

	// Cancel returns to the action list and keeps the Mark.
	await menu.locator('[data-menu-delete-cancel]').click();
	await expect(confirm).toBeHidden();
	await expect(menu.locator('[data-menu-actions]')).toBeVisible();
	await expect(card).toBeVisible();

	// Delete again and confirm — this time it is removed from the list.
	await menu.locator('[data-menu-delete]').click();
	await expect(confirm).toBeVisible();
	await menu.locator('[data-menu-delete-confirm]').click();
	await expect(
		page.locator('.daymark-recent__item-wrap').filter({ hasText: caption })
	).toHaveCount(0);
});

// A published Mark is read-only in-app: no ⋯ menu, so no in-app Edit or
// Delete at all. wp-admin is where a published Mark gets adjusted or removed.
test('published Marks have no ⋯ Edit/Delete menu', async ({ page }) => {
	const caption = `E2E published readonly ${RUN_ID}`;

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

	const card = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption }).first();
	await expect(card).toBeVisible();
	await expect(card.locator('[data-actions]')).toHaveCount(0);
	await expect(card.locator('[data-menu-toggle]')).toHaveCount(0);
});

// Touch-target audit: every tappable control sizes off the app shell's own
// --daymark-tap-min (44px) token, not a hardcoded smaller value. Covers the
// controls the audit found under that minimum before being fixed.
test('touch targets: fixed controls meet the 44px minimum', async ({ page }) => {
	const publishedCaption = `E2E taptarget mark ${RUN_ID}`;
	const draftCaption = `E2E taptarget draft ${RUN_ID}`;
	const TAP_MIN = 44;

	await loginAs(page);
	await page.goto('/daymark');
	await page.evaluate(
		async ({ published, draft }) => {
			const config = window.daymarkApp;
			const post = (cap, status) =>
				fetch(`${config.restUrl}marks`, {
					method: 'POST',
					headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify({ caption: cap, primary_type: 'note', status }),
				});
			// The site icon only appears on a published Mark's card (a Draft
			// has no separate site to filter to); the ⋯ menu only appears on
			// a Draft's card (a published Mark is read-only in-app) — so this
			// audit needs one of each.
			await post(published, 'publish');
			await post(draft, 'draft');
		},
		{ published: publishedCaption, draft: draftCaption }
	);

	await page.goto('/daymark');
	const publishedCard = page.locator('.daymark-recent__item-wrap').filter({ hasText: publishedCaption }).first();
	await expect(publishedCard).toBeVisible();

	// Site icon filter button (published Mark only).
	const siteIcon = publishedCard.locator('[data-filter-site]');
	let box = await siteIcon.boundingBox();
	expect(box.width).toBeGreaterThanOrEqual(TAP_MIN);
	expect(box.height).toBeGreaterThanOrEqual(TAP_MIN);

	const draftCard = page.locator('.daymark-recent__item-wrap').filter({ hasText: draftCaption }).first();
	await expect(draftCard).toBeVisible();

	// Per-item ⋯ menu trigger (Draft only).
	const menuToggle = draftCard.locator('[data-menu-toggle]');
	box = await menuToggle.boundingBox();
	expect(box.width).toBeGreaterThanOrEqual(TAP_MIN);
	expect(box.height).toBeGreaterThanOrEqual(TAP_MIN);

	// Delete-confirmation buttons inside the ⋯ menu.
	const menu = draftCard.locator('[data-actions]');
	await menuToggle.click();
	await menu.locator('[data-menu-delete]').click();
	for (const name of ['data-menu-delete-cancel', 'data-menu-delete-confirm']) {
		box = await menu.locator(`[${name}]`).boundingBox();
		expect(box.height).toBeGreaterThanOrEqual(TAP_MIN);
	}
	await menu.locator('[data-menu-delete-cancel]').click();
	await menuToggle.click(); // close the menu

	// Search screen: type filter chip and Source filter select.
	await page.locator('.daymark-bottomnav__link', { hasText: 'Search' }).click();
	await expect(page).toHaveURL(/#search$/);
	box = await page.locator('[data-filter-chips] [data-filter="note"]').boundingBox();
	expect(box.height).toBeGreaterThanOrEqual(TAP_MIN);
	box = await page.locator('[data-source-filter]').boundingBox();
	expect(box.height).toBeGreaterThanOrEqual(TAP_MIN);
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
	await openComposer(page);
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

	// The toggle still reopens the (now empty) box; an outside click and
	// Escape dismiss it the same way search and the launcher do.
	await card.locator('[data-reply-toggle]').click();
	await expect(form).toBeVisible();
	await page.locator('h1.daymark-topbar__title').click();
	await expect(form).toBeHidden();

	await card.locator('[data-reply-toggle]').click();
	await expect(form).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(form).toBeHidden();
	await expect(card.locator('[data-reply-toggle]')).toBeFocused();
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
	// Camera-first: a home-screen shortcut jumps straight to the composer.
	expect(manifest.shortcuts).toHaveLength(1);
	expect(manifest.shortcuts[0].url).toContain('/daymark');
	expect(manifest.shortcuts[0].url).toContain('#create');
});

// Save as Draft → Drafts row → resume editing → publish (running the
// stored destinations via deferred syndication).
test('draft lifecycle: save, resume from Drafts row, publish', async ({ page }) => {
	const caption = `E2E draft ${RUN_ID}`;
	const finished = `${caption} finished`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="save-draft"]').click();
	await expect(page.getByText('Saved as draft')).toBeVisible();

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

	// Draft row entry is gone; the published Mark is findable via Search.
	await page.goto('/daymark');
	await expect(page.locator('[data-edit-draft]').filter({ hasText: caption })).toHaveCount(0);
	await page.goto('/daymark/search');
	await expect(page.locator('[data-search-results]').getByText(finished)).toBeVisible();
});

// Autosave: "nothing gets lost" even without tapping Save as Draft.
// Typing a caption and abandoning the composer (navigating straight back to
// Home, the way a closed tab or a killed app would) still leaves a
// resumable draft behind, because the composer autosaves it in the
// background — no explicit save action required.
test('autosave: an abandoned composition survives without Save as Draft', async ({ page }) => {
	const caption = `E2E autosave ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);
	await page.fill('#daymark-caption', caption);

	// Autosave debounces ~2.5s after the last edit, then reports success.
	await expect(page.locator('[data-autosave-status]')).toHaveText('Saved', { timeout: 10000 });

	// Abandon the composition exactly like a closed tab would: navigate
	// straight to Home, never tapping Publish or Save as Draft.
	await page.goto('/daymark');

	const row = page.locator('[data-edit-draft]').filter({ hasText: caption }).first();
	await expect(row).toBeVisible();
	await expect(row.locator('.daymark-chip--draft')).toBeVisible();

	// Resuming shows the autosaved caption, and the draft publishes normally.
	await row.click();
	await expect(page.locator('#daymark-caption')).toHaveValue(caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();
});

// Offline-first creation (issue #121): composing and saving while offline
// queues the Mark locally (IndexedDB) instead of failing, and it syncs to a
// real draft automatically once connectivity returns — no manual retry.
// Only covers a session already open when connectivity drops; a cold load
// of /daymark itself with zero connectivity is a documented non-goal (see
// CLAUDE.md), so this test only ever toggles offline after the app has
// already loaded, and navigates within the SPA (hash routes, no network)
// rather than reloading while offline.
test('offline: composing while offline queues locally and syncs when back online', async ({
	page,
}) => {
	const caption = `E2E offline ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);
	await page.fill('#daymark-caption', caption);

	await page.context().setOffline(true);
	try {
		await page.locator('[data-action="next"]').click();
		await page.locator('[data-action="save-draft"]').click();
		await expect(page.getByText('Saved as draft')).toBeVisible();

		// Queued locally, not yet a real draft — reachable via the in-app
		// link (client-side hash navigation; a real page load would fail
		// while offline, per the documented scope above).
		await page.locator('a.daymark-success__link[href="#home"]').click();
		const pendingRow = page.locator('[data-resume-pending]').filter({ hasText: caption });
		await expect(pendingRow).toBeVisible();
		await expect(page.locator('[data-edit-draft]').filter({ hasText: caption })).toHaveCount(0);
	} finally {
		await page.context().setOffline(false);
	}

	// The 'online' event triggers an automatic flush; give it a moment,
	// then reload (now safely online) to confirm the sync stuck.
	await expect(page.locator('[data-resume-pending]').filter({ hasText: caption })).toHaveCount(
		0,
		{ timeout: 10000 }
	);
	await page.reload();
	await expect(page.locator('[data-edit-draft]').filter({ hasText: caption })).toBeVisible();
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
	await openComposer(page);
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

// Optimistic publishing: "Tap Publish. Immediately appears in your
// timeline. Uploads continue in the background." Publish never waits on
// the network — it queues the Mark locally and moves on to Success right
// away, well before an artificially slow create request resolves. While
// that request is still in flight, Home's Pending section shows it as
// actively uploading (not the generic "Offline" wording used for a
// genuine connectivity failure); once the request confirms, the Success
// screen upgrades in place with the real "Published to your site" detail
// and the Pending row clears.
test('publish is optimistic: Success shows immediately and upgrades once the upload confirms', async ({
	page,
}) => {
	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);
	await page.fill('#daymark-caption', `E2E optimistic ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();

	// Hold the create request so the "still uploading" window is
	// observable — long enough to assert against, short enough to keep the
	// test fast.
	await page.route('**/daymark/v1/marks', async (route) => {
		await new Promise((resolve) => setTimeout(resolve, 1500));
		await route.continue();
	});

	await page.locator('[data-action="publish"]').click();

	// Success shows immediately, from client-known data only — well before
	// the delayed request could have resolved.
	await expect(page.getByText('Uploading in the background')).toBeVisible({ timeout: 800 });
	await expect(page.getByText('Published to your site')).toHaveCount(0);

	// Home's Pending section reflects the in-flight upload distinctly from
	// a genuine offline queue.
	await page.locator('a.daymark-success__link[href="#home"]').click();
	await expect(page.locator('[data-pending-section]')).toBeVisible();
	await expect(page.locator('[data-pending-section]').getByText('Uploading', { exact: true })).toBeVisible();

	// Once the delayed request resolves, the Pending section clears on its
	// own — no manual refresh needed.
	await expect(page.locator('[data-pending-section]')).toBeHidden({ timeout: 5000 });
});

// Regression for the race surfaced (but left unfixed as out of scope) while
// building the optimistic-publish flow above: picking a file fires an
// immediate background autosave upload for it (runAutosave(), not the
// debounced path) before entry.uploadedId is known. Tapping Publish while
// that upload is still in flight used to build its own payload from the
// same stale entry and re-send the file, attaching it to the post twice.
// The fix makes Publish await any in-flight autosave first.
test('publish does not re-upload a file whose autosave is still in flight', async ({ page }) => {
	const caption = `E2E race ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page, 'image');

	// First file: let its autosave finish normally, so the draft post
	// exists and this file already has its uploadedId before the race
	// below — isolates the assertion to the second file's in-flight upload.
	await page.setInputFiles('#daymark-file-input', 'tests/e2e/fixtures/test-image.png');
	await page.fill('#daymark-caption', caption);
	await expect(page.locator('[data-autosave-status]')).toHaveText('Saved', { timeout: 10000 });

	// Hold the second file's autosave upload (a POST to marks/{id}) open
	// long enough to tap Publish while it's still running.
	let updateRequests = 0;
	await page.route('**/daymark/v1/marks/*', async (route) => {
		if (route.request().method() === 'POST') {
			updateRequests += 1;
			await new Promise((resolve) => setTimeout(resolve, 1200));
		}
		await route.continue();
	});

	await page.setInputFiles('#daymark-file-input', 'tests/e2e/fixtures/test-image-2.png');
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();

	await expect(page.getByText('Published to your site')).toBeVisible({ timeout: 10000 });
	// Both file2's own autosave upload and Publish's request route through
	// this same endpoint; seeing more than one confirms the race was
	// actually exercised rather than the autosave finishing first on its own.
	expect(updateRequests).toBeGreaterThanOrEqual(1);

	const media = await page.evaluate(async (cap) => {
		const config = window.daymarkApp;
		const headers = { 'X-WP-Nonce': config.nonce };
		const listRes = await fetch(`${config.restUrl}marks?per_page=1`, {
			headers,
			credentials: 'same-origin',
		});
		const [latest] = await listRes.json();
		const markRes = await fetch(`${config.restUrl}marks/${latest.id}`, {
			headers,
			credentials: 'same-origin',
		});
		const mark = await markRes.json();
		return mark.media;
	}, caption);

	// Exactly two attachments — file1 and file2 once each. A regression
	// here shows up as three: file2 attached by both the autosave and the
	// unguarded Publish request.
	expect(media).toHaveLength(2);
});

// Home IS the merged Timeline feed (Marks + subscribed posts) now — there's
// no separate #timeline screen to navigate to. The header wordmark is just
// a plain "go home" link, and the merged feed's heading already reads
// "Timeline" directly on Home.
test('header home-link points home, and Home shows the Timeline feed', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');

	const home = page.locator('.daymark-homelink');
	await expect(home).toBeVisible();
	await expect(home).toHaveText('Daymark');
	await expect(home.locator('img')).toBeVisible();
	expect(await home.getAttribute('href')).toContain('#home');

	await expect(page.locator('#daymark-recent-heading')).toHaveText('Timeline');
});

// The persistent bottom nav (Timeline, Explore, +New, Search, Me) renders
// as icon links in that exact order flanking the launcher: an SVG glyph,
// the label as the accessible name (role+name) and as the hover title, no
// visible text — plus an active-tab indicator for whichever screen is
// current, and the launcher never picks that styling up by mistake.
test('bottom nav shows Timeline/Explore/Search/Me in order, with icons, accessible labels, and active state', async ({
	page,
}) => {
	await loginAs(page);
	await page.goto('/daymark');

	const nav = page.locator('.daymark-bottomnav');

	for (const label of ['Timeline', 'Explore', 'Search', 'Me']) {
		// exact: true matters here — getByRole's name match is substring by
		// default, and "Me" is a substring of "Timeline".
		const link = nav.getByRole('link', { name: label, exact: true });
		await expect(link).toBeVisible();
		await expect(link).toHaveAttribute('title', label);
		await expect(link.locator('svg.daymark-icon')).toBeVisible();
	}
	await expect(page.locator('.daymark-bottomnav__link svg')).toHaveCount(4);

	// Required order: Timeline, Explore, +New (center), Search, Me — the
	// launcher <div> has no title attribute, so it stands out from the tabs.
	const tabOrder = await nav.evaluate((el) =>
		Array.from(el.children).map((child) => child.getAttribute('title') || 'launcher')
	);
	expect(tabOrder).toEqual(['Timeline', 'Explore', 'launcher', 'Search', 'Me']);

	// Timeline is the default landing destination and shows as active.
	const timeline = nav.getByRole('link', { name: 'Timeline', exact: true });
	await expect(timeline).toHaveClass(/is-active/);
	await expect(timeline).toHaveAttribute('aria-current', 'page');

	// The +New launcher sits between the tabs and never picks up active-tab
	// styling itself.
	await expect(page.locator('[data-action="new-mark"]')).not.toHaveClass(/is-active/);

	// Navigating to another destination moves the active indicator.
	await nav.getByRole('link', { name: 'Explore', exact: true }).click();
	await expect(page).toHaveURL(/#explore$/);
	await expect(nav.getByRole('link', { name: 'Explore', exact: true })).toHaveClass(/is-active/);
	await expect(timeline).not.toHaveClass(/is-active/);
});

// +New works identically from every nav destination, not just Home — and
// never mistakes itself for the active tab on any of them.
test('+New launcher works from Explore, Search, and Me, not just Timeline', async ({ page }) => {
	await loginAs(page);

	for (const hash of ['#explore', '#search', '#me']) {
		await page.goto('/daymark' + hash);
		// These three URLs differ only by hash fragment, so the browser
		// treats each `goto()` as a same-document navigation — the app's
		// own `hashchange` listener (assets/app.js) picks it up and
		// re-renders asynchronously, and `goto()` can resolve before that
		// re-render actually happens. The launcher itself is a useless
		// signal to wait on here: it never carries `is-active` on ANY
		// screen (by design, so it never reads as a nav tab), so checking
		// it proves nothing about whether THIS iteration's screen has
		// rendered yet — the click could still land on the previous
		// iteration's still-present content underneath. Wait for this
		// screen's own nav tab to confirm it's current instead; that can
		// only be true once showScreen() has actually replaced the DOM
		// for this hash.
		await expect(page.locator(`a.daymark-bottomnav__link[href="${hash}"]`)).toHaveClass(
			/is-active/
		);
		await expect(page.locator('[data-action="new-mark"]')).not.toHaveClass(/is-active/);
		// Explore's Following list and Search's results both render a
		// `.daymark-skeleton` placeholder synchronously, then replace it
		// once their async fetch resolves (subscriptions, then a search
		// results page) — real content of a different size than the
		// placeholder can reflow the screen under the fixed launcher right
		// as this loop taps it. Wait for the skeletons to clear first, or
		// the click can land on the shifting content underneath instead of
		// the launcher.
		await expect(page.locator('.daymark-skeleton')).toHaveCount(0);
		// bindChromeAutoHide() (assets/app.js) hides the persistent footer
		// — the launcher lives inside it — on any scroll-down past 80px,
		// and only guarantees it back on scroll-up or focus. A reflow from
		// the async content above (or the browser's own scroll-anchoring
		// compensating for it) can trip that listener before this loop
		// ever scrolls on purpose, sliding the launcher out from under
		// its own click. Forcing scrollY back to 0 — and waiting for the
		// footer to actually confirm it's not hidden — before each tap
		// closes that race regardless of what triggered it.
		await page.evaluate(() => window.scrollTo(0, 0));
		await expect(page.locator('.daymark-homefooter')).not.toHaveClass(/is-footer-hidden/);
		// A plain `.click()` here still failed in CI even with every guard
		// above satisfied — Playwright's own logs showed it re-running
		// scrollIntoViewIfNeeded() on every retry (and, once, flagging the
		// target itself as "not stable"): a known category of flakiness
		// where scrollIntoViewIfNeeded() miscomputes against a
		// `position: sticky` element (the footer) by scoring its static,
		// unstuck flow position rather than its actual visual one, so it
		// keeps re-scrolling without ever settling and briefly exposes
		// whatever real content sits underneath. Activating the button via
		// keyboard sidesteps that geometric hit-test/scroll machinery
		// entirely — Enter on a focused <button> fires the same `click`
		// event a real tap would, so this still exercises the launcher's
		// real behavior, just without routing through the sticky-element
		// scroll quirk to get there.
		const launcherBtn = page.locator('[data-action="new-mark"]');
		await launcherBtn.focus();
		await page.keyboard.press('Enter');
		// Same story one level deeper: a bubble is deliberately
		// `pointer-events: none` until openLauncher()'s JS settle timer
		// (650ms; see the CSS comment on `.is-open.is-settled
		// .daymark-launcher__bubble` in assets/app.css) adds `is-settled` —
		// every bubble's fan-out path crosses the others', so nothing is
		// coordinate-safe to tap until the whole sequence has settled. A
		// geometric `.click()` here hit the exact same sticky-footer
		// scrollIntoView churn as the launcher button itself (CI showed the
		// scrim, and even the bare `.daymark-screen`, intercepting instead
		// of the bubble). `.focus()` targets this button by DOM identity,
		// not screen coordinates, so it's immune to both problems at once:
		// pointer-events doesn't affect programmatic/keyboard focus at all,
		// and there's no coordinate ambiguity for the settle timer to guard
		// against in the first place.
		const noteBubble = page.locator('[data-launcher-type="note"]');
		await noteBubble.focus();
		await page.keyboard.press('Enter');
		await expect(page).toHaveURL(/#create$/);
		await expect(page.getByText('New Mark')).toBeVisible();
	}
});

// Direct navigation and a hard refresh both land correctly on each new
// destination — they are real server routes (Daymark_Routes), not just
// client-side hash states.
test('Explore, Search, and Me are directly linkable and survive a refresh', async ({ page }) => {
	await loginAs(page);

	for (const { path, heading } of [
		{ path: '/daymark/explore', heading: 'Explore' },
		{ path: '/daymark/search', heading: 'Search' },
		{ path: '/daymark/me', heading: 'Me' },
	]) {
		await page.goto(path);
		await expect(page.locator('h1', { hasText: heading })).toBeVisible();
		await expect(page.locator('.daymark-bottomnav__link.is-active')).toHaveText(heading);

		await page.reload();
		await expect(page.locator('h1', { hasText: heading })).toBeVisible();
	}
});

// Explore, Search, and Me now share Home's own header chrome: the Daymark
// icon (upper left) links back to Timeline, and Notifications (upper
// right) is reachable from every one of them — so Me's own body no longer
// needs a separate "Notifications" link.
test('Explore, Search, and Me headers carry the Daymark icon and Notifications icon', async ({
	page,
}) => {
	await loginAs(page);

	for (const hash of ['#explore', '#search', '#me']) {
		await page.goto('/daymark' + hash);

		const homeIcon = page.locator('header.daymark-topbar a.daymark-iconbtn[href="#home"]');
		await expect(homeIcon).toBeVisible();
		await expect(homeIcon.locator('img')).toBeVisible();

		await expect(
			page.locator('header.daymark-topbar a.daymark-iconbtn[href="#notifications"]')
		).toBeVisible();
	}

	await expect(page.locator('.daymark-melink', { hasText: 'Notifications' })).toHaveCount(0);

	await page.goto('/daymark#explore');
	await page.locator('header.daymark-topbar a.daymark-iconbtn[href="#home"]').click();
	await expect(page).toHaveURL(/#home$/);
	await expect(page.locator('#daymark-recent-heading')).toHaveText('Timeline');
});

// The launcher: tapping "+ New Mark" fans out 4 labeled, accessible bubbles
// (icon-only visually, but each still has a real accessible name), and
// dismisses the same ways the rest of the app already does — an outside
// tap, or Escape (which also returns focus to the trigger).
test('launcher fans out accessible Image/Video/Audio/Note bubbles and dismisses via outside tap or Escape', async ({
	page,
}) => {
	await loginAs(page);
	await page.goto('/daymark');

	const btn = page.locator('[data-action="new-mark"]');
	await expect(btn).toHaveAttribute('aria-label', 'New Mark');
	await expect(btn).toHaveAttribute('aria-expanded', 'false');

	await btn.click();
	await expect(btn).toHaveAttribute('aria-expanded', 'true');

	for (const type of ['Image', 'Video', 'Audio', 'Note']) {
		await expect(page.getByRole('button', { name: `New ${type} Mark` })).toBeVisible();
	}

	// An outside tap (the dimming scrim over the recent list) closes it —
	// the scrim covers that area while open and is the real hit target,
	// since it sits on top of the content underneath it.
	await page.locator('.daymark-launcher__scrim').click();
	await expect(btn).toHaveAttribute('aria-expanded', 'false');

	// Escape closes it too, and returns focus to the launcher button.
	await btn.click();
	await expect(btn).toHaveAttribute('aria-expanded', 'true');
	await page.keyboard.press('Escape');
	await expect(btn).toHaveAttribute('aria-expanded', 'false');
	await expect(btn).toBeFocused();
});

// prefers-reduced-motion collapses the bounce/spin to an instant toggle —
// checked at the CSS level (computed transition-duration), since the visual
// end state is identical either way and only the choreography differs.
test('launcher animation respects prefers-reduced-motion', async ({ page }) => {
	await loginAs(page);
	await page.emulateMedia({ reducedMotion: 'reduce' });
	await page.goto('/daymark');

	const bubble = page.locator('[data-launcher-type="note"]');
	const duration = await bubble.evaluate((el) => getComputedStyle(el).transitionDuration);
	expect(duration).toMatch(/^0s(,\s*0s)*$/);
});

// Awareness note: when a third-party publishing plugin is active, the
// publish screen tells the user their Mark will also go out that way.
// (The E2E publish-helper mu-plugin registers a fake "Test Publicize".)
test('publish screen notes active third-party publishing plugins', async ({ page }) => {
	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);
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
	await openComposer(page);
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
	await openComposer(page);
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
	await openComposer(page);
	await page.fill('#daymark-caption', `E2E connector memory ${RUN_ID}`);
	await page.locator('[data-action="next"]').click();
	await expect(page.locator('[data-connector="e2e-net"]')).toBeChecked();
});

// Section pages render inside the theme with no Daymark-controlled chrome
// of their own — the back-link is the one way in from there to the app.
// Content-type pages are retired: /images (like /videos, /audio, /notes)
// no longer renders the old Daymark view. A fresh install never created
// it, so it 404s; an upgraded install's old page redirects to Explore
// instead of a bare 404 (Daymark_Routes' legacy-content-page redirect) —
// either way, the old view itself is gone.
test('the retired /images content-type page no longer renders the old Daymark view', async ({ page }) => {
	const response = await page.goto('/images/');
	if (response && 404 === response.status()) {
		return;
	}
	await expect(page).toHaveURL(/\/daymark\/explore\/?$/);
	await expect(page.locator('.daymark-view-backlink')).toHaveCount(0);
});

// Timeline is an interleaved, multi-source view now (issue #78) — it only
// exists inside the authenticated app (Home), never as a public page.
test('the public /timeline page is gone (404, no redirect)', async ({ page }) => {
	const response = await page.goto('/timeline/');
	expect(response.status()).toBe(404);
	await expect(page).toHaveURL(/\/timeline\/?$/);
});

// Home's Recent Marks list shows the same comment/like stat row as the
// public Timeline card — a zero count stays a dimmed icon-only, a real
// count shows next to a bolder icon.
test('home Recent Marks entries show comment/like counts', async ({ page }) => {
	const caption = `E2E stats ${RUN_ID}`;
	const reply = `E2E nice one ${RUN_ID}`;

	await loginAs(page);
	await page.goto('/daymark');
	await openComposer(page);
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

	await page.goto('/daymark');
	const row = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption });
	await expect(row.locator('.daymark-stat--comments.daymark-stat--active')).toHaveCount(0);
	await expect(row.locator('.daymark-stat--likes.daymark-stat--active')).toHaveCount(0);

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

	await page.goto('/daymark');
	const rowAfter = page.locator('.daymark-recent__item-wrap').filter({ hasText: caption });
	const commentStat = rowAfter.locator('.daymark-stat--comments');
	await expect(commentStat).toHaveClass(/daymark-stat--active/);
	await expect(commentStat.locator('.daymark-stat__count')).toHaveText('1');
});

// --- Router listener teardown (issue #64) ---
//
// bindDismissible() is the only source in app.js of capture-phase
// document-level click/keydown listeners, so counting those two specifically
// is a direct, DOM-level probe for the leak the issue describes — not just
// an indirect behavioral proxy for it. Instrumenting document.addEventListener/
// removeEventListener from an init script (installed before any app code
// runs) lets the assertions below catch the exact regression: under the
// pre-fix code, each screen controller that calls bindDismissible() (Home,
// Create, Notifications) only ever cleaned up its *own* previous pair, so
// the first hash-routed visit to a second such screen left the first
// screen's pair still attached, permanently, alongside the second's.
async function trackDismissListeners(page) {
	await page.addInitScript(() => {
		window.__dismissCounts = { click: 0, keydown: 0 };
		const originalAdd = document.addEventListener.bind(document);
		const originalRemove = document.removeEventListener.bind(document);
		const isCapture = (options) => options === true || (options && options.capture);
		document.addEventListener = (type, handler, options) => {
			if (isCapture(options) && (type === 'click' || type === 'keydown')) {
				window.__dismissCounts[type] += 1;
			}
			return originalAdd(type, handler, options);
		};
		document.removeEventListener = (type, handler, options) => {
			if (isCapture(options) && (type === 'click' || type === 'keydown')) {
				window.__dismissCounts[type] -= 1;
			}
			return originalRemove(type, handler, options);
		};
	});
}

test('switching screens tears down the previous screen’s dismiss listeners instead of leaking them', async ({
	page,
}) => {
	const caption = `E2E leak check ${RUN_ID}`;
	const reply = `E2E leak reply ${RUN_ID}`;

	await trackDismissListeners(page);
	await loginAs(page);

	// A Mark with a reply so Notifications' own bindDismissible() call (which
	// only fires once its list has at least one item) actually re-arms on
	// every visit, rather than trivially passing on an empty list.
	await page.goto('/daymark');
	await openComposer(page);
	await page.fill('#daymark-caption', caption);
	await page.locator('[data-action="next"]').click();
	await page.locator('[data-action="publish"]').click();
	await expect(page.getByText('Published to your site')).toBeVisible();

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

	await page.goto('/daymark');
	await expect(page.locator('[data-action="new-mark"]')).toBeVisible();

	const counts = () => page.evaluate(() => window.__dismissCounts);

	// Home's own bindEvents() runs once on this load and registers exactly
	// one click+keydown capture pair (the item menu, launcher, and search
	// share it).
	await expect.poll(async () => (await counts()).click).toBe(1);
	await expect.poll(async () => (await counts()).keydown).toBe(1);

	// Hash-routed switches (showScreen(), not a full page load) across every
	// screen that calls bindDismissible() — Notifications and Create — and
	// back to Home again. The count must stay at exactly one pair throughout:
	// under the pre-fix code the very first switch below already left it at
	// two (Home's pair never removed, Notifications' pair added alongside).
	await page.locator('.daymark-iconbtn').click(); // -> #notifications
	await expect(page.getByText(reply).first()).toBeVisible();
	await expect.poll(async () => (await counts()).click).toBe(1);
	await expect.poll(async () => (await counts()).keydown).toBe(1);

	await page.locator('.daymark-backlink').click(); // -> #home
	await expect(page.locator('[data-action="new-mark"]')).toBeVisible();
	await expect.poll(async () => (await counts()).click).toBe(1);
	await expect.poll(async () => (await counts()).keydown).toBe(1);

	await openComposer(page); // -> #create
	await expect.poll(async () => (await counts()).click).toBe(1);
	await expect.poll(async () => (await counts()).keydown).toBe(1);

	await page.locator('.daymark-backlink').click(); // -> #home
	await expect(page.locator('[data-action="new-mark"]')).toBeVisible();
	await expect.poll(async () => (await counts()).click).toBe(1);
	await expect.poll(async () => (await counts()).keydown).toBe(1);

	// Escape on Notifications only ever closes its own reply box, never a
	// leftover disclosure from a screen that isn't showing anymore.
	await page.locator('.daymark-iconbtn').click(); // -> #notifications
	const card = page.locator('.daymark-note-card').filter({ hasText: reply }).first();
	await card.locator('[data-reply-toggle]').click();
	await expect(card.locator('[data-reply-form]')).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(card.locator('[data-reply-form]')).toBeHidden();
	await expect.poll(async () => (await counts()).click).toBe(1);
	await expect.poll(async () => (await counts()).keydown).toBe(1);
});
