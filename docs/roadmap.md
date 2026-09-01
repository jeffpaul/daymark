# Daymark — Roadmap

> The **future-facing** companion to the design record. Where
> **[docs/planning/README.md](planning/README.md)** explains where Daymark came
> from (vision, principles, build history) and **[CLAUDE.md](../CLAUDE.md)** is
> the authoritative record of how it is built today, this file is the shared
> view of where it is going next.

## How to read this roadmap

- **No dates.** Priorities move; versions don't. Buckets are "next up",
  "building on it", and "longer term", not release dates.
- **Principles bind it.** Anything proposed must serve the six principles in
  [planning §2](planning/README.md#2-principles) — Publish First, Mobile First,
  Ownership by Default, Portable by Design, AI Assist never AI First,
  Progressive Complexity — and must not cross the non-goals (§4 there). It
  should also read against [docs/design-principles.md](design-principles.md) —
  the Path / Day One / WordPress / modern-PWA rubric for what a proposal
  should *feel* like, and which parts of those influences stay left behind.
- **Non-goals stay non-goals until explicitly overturned.** Real social-API
  publishing in core, full offline PWA, push notifications, a custom post
  type, and multi-user team workflows are *not* planned. A roadmap line that
  contradicts one needs a written decision first. (A blanket "no settings
  dashboard" used to be on this list too — narrowed after the Subscriptions
  wp-admin screen shipped; see "Not planned" below for the current, more
  specific wording.)
- **When a bucket changes, update CLAUDE.md.** The architectural-decisions
  table there is the authoritative record; this file is the plan.

---

## Shipped — 0.7.0

Daymark is a healthy prototype (Phases 0–9 passed; hardening landed). The
0.7.0 series was about making it a clean, reviewable, releasable plugin — not
new product surface. Released on GitHub and wordpress.org.

- [x] Coding-standards suite covers tests (`composer phpcs-tests`) and PHP
  compatibility (`composer phpcompat`); CI runs both.
- [x] Security hardening: rate limiting on AI/publish/sync REST actions, a
  per-request upload byte budget, the alt-text IDOR fix, an atomic backflow
  cooldown, the comment-import approval filter, an AI prompt-injection guard,
  and a CSP header on the app shell.
- [x] Plugin Check is **blocking** in CI (was advisory).
- [x] The Playwright E2E suite runs in CI as a blocking job (`Browser E2E` in
  `tests.yml`, across WP minimum/stable/nightly) — no longer scaffolded-only.
- [x] Docs refreshed: SECURITY.md support table, CHANGELOG/readme.txt, and
  contributor + project-memory build/security notes.
- [x] **Retire `Daymark_Migration`.** Soft-deprecated in 0.7.0 (logged
  `_deprecated_function()` when it actually converted a legacy install), then
  removed in 0.9.0 along with `uninstall.php`'s legacy `moment_*` cleanup
  block and `tests/test-migration.php` ([#36](https://github.com/jeffpaul/daymark/issues/36)).
  A site still on Moment (≤ 0.5.0) must now upgrade through an intermediate
  0.6.x–0.8.x release before jumping to 0.9.0 or later.

**Still open from this era:**

- [ ] First-party connector ecosystem docs: a worked example of
  `daymark_register_connectors` for plugin authors, published alongside the
  hooks reference.

---

## Shipped — Subscriptions & Timeline Following (issue #78)

The largest body of work since 0.7.0. Home became a feed reader for the
sites you follow, without turning WordPress into a social network.

- [x] **Subscribe to any site's RSS/Atom feed** by URL, with feed
  autodiscovery. Lives on a **wp-admin screen** (Settings → Daymark), not the
  app shell — subscribing is infrequent, unlike day-to-day publishing/reading.
  Subscribe/refresh/unsubscribe share their exact implementation with the
  equivalent REST endpoints, so neither surface's behavior can drift from the
  other. The Subscribe button shows a loading state on submit; the table
  shows each subscription's status, when it was last fetched, and a Refresh
  action available on every row (not just a failing one).
- [x] **Home is the merged Timeline feed**: a user's own Marks interleaved
  with cached posts from every active subscription, sorted by published
  date. Rich-media formats (image/video/audio/gallery) render from embed data
  cached at ingest time; text posts render from cached metadata and fetch
  their full content on click-through (with an error state + link back to
  the source post if that fetch fails). Pull-to-refresh re-polls every active
  subscription.
- [x] **Cross-Timeline search**, filterable by source (My Marks, or one
  specific subscribed site) alongside the existing type chips.
- [x] **Polling and pruning**: a global daily WP-Cron poll (filterable
  interval) plus an independent, rate-limited (15 min, filterable) manual
  refresh. Cached content prunes to minimal metadata once a subscription
  exceeds its recent-post threshold (site icon shown as a placeholder for a
  pruned rich-media post).
- [x] **Dead-feed detection**: a subscription with 7 consecutive daily
  failures surfaces in the notifications screen.
- [x] **Unsubscribing** trashes every cached post ingested from that
  subscription (relying on core's 7-day trash retention), no matter which
  surface (REST or the wp-admin screen) removed the subscription.
- [x] **The public `/timeline` page, block, and shortcode are gone.**
  Timeline is now an interleaved, multi-source view that only makes sense
  authenticated — a public page under the same name showing something
  narrower (Marks only) was confusing and redundant. Hard-deleted on
  upgrade: a real 404, no redirect. Individual Mark permalinks and the
  site's own RSS/Atom feed are unaffected. (The other four section pages —
  `/images`, `/videos`, `/audio`, `/notes` — were unaffected *at the time*;
  they were removed in a later bottom-nav rework — see CLAUDE.md's
  "Content-type & Timeline pages removed" and "Bottom navigation" rows.)

- [x] **POSSE-quality outbound microformats2 markup.** Every published
  Mark's own permalink page renders valid `h-entry` markup (`e-content`,
  `p-name`/`p-summary`, `dt-published`, `u-url`, and `u-photo`/`u-video`/
  `u-audio` for rich media) and an author `h-card` (`p-author`, `p-name`,
  `u-photo`). A user-configurable `rel=me` field on the native WordPress
  profile screen renders as a `rel="me"` link next to the h-card. Verified
  against a live-rendered Mark's actual HTML output, not just unit tests —
  that live check is what caught a real bug (`post_class`'s filter
  signature has three arguments, not the two the first pass assumed).
  `u-in-reply-to` isn't rendered: nothing in the Mark data model records a
  parent post today, so the property is never applicable yet.

This was the outbound half of POSSE-quality microformats2 support, the one
piece of issue #78 that shipped without it at the time. (*Inbound*
microformats2 parsing of a *subscribed* site's markup is separate,
out-of-scope-for-#78 work tracked on
[issue #84](https://github.com/jeffpaul/daymark/issues/84).)

A related, smaller adjustment: the PHP minimum is now 8.2 (was 8.1 — 8.1
stopped receiving security fixes). `phpunit/phpunit` stays on `^9.6` rather
than moving to 11.x: WordPress core's own PHPUnit test scaffold still calls a
method PHPUnit 10 removed, so every test run under PHPUnit 10+ fails
regardless of anything in this plugin. Tracked on
[issue #106](https://github.com/jeffpaul/daymark/issues/106) for whenever
core fixes it — a diagnosed, ready-to-apply test-file rename
(`test-*.php` → `test_*.php`, required by PHPUnit 11's stricter file/class
matching) is documented there too.

Sixteen further Subscriptions enhancements explicitly deferred out of #78's
own scope are tracked as their own issues — see
[#79](https://github.com/jeffpaul/daymark/issues/79) through
[#94](https://github.com/jeffpaul/daymark/issues/94) (Action Scheduler,
per-subscription polling interval, multi-feed/category-scoped subscriptions,
additional source connectors, subscription grouping, keyword muting, feed
preview, inbound microformats2 parsing, Webmention support, WebSub/PuSH,
malformed/malicious feed hardening, OPML import/export, an admin page
recommending complementary IndieWeb plugins, scroll-triggered rehydration of
pruned content, on-demand site-icon refresh, and Bridgy Fed integration).
None of them are prioritized yet.

---

## Shipped — Bottom navigation rework

Replaced the app shell's Images/Videos/Audio/Notes content-type pages and
their flanking nav links with a persistent bottom nav — **Timeline, Explore,
+New, Search, Me** — and laid the foundation (routes, screens, shared
rendering) for the last three to grow into real destinations later. See
CLAUDE.md's "Content-type & Timeline pages removed" and "Bottom navigation"
decision rows for the full technical record.

- [x] **Bottom nav**: Timeline/Explore/+New/Search/Me, icon-only links with
  accessible labels, `aria-current`/`is-active` state, +New centered and
  never mistaken for a tab. Real routes for all four non-+New destinations.
- [x] **Content-type pages retired.** The Images/Videos/Audio/Notes section
  pages, their `daymark/*` blocks, `[daymark_*]` shortcodes, `Daymark_Renderer`,
  and the block-editor build system they were the only consumer of are gone.
  An existing install's pages are trashed (not hard-deleted) on upgrade, and
  the old URL 301s to Explore.
- [x] **Search** promoted from a collapsible bar on Home into its own screen,
  reusing the existing REST search/filtering rather than a second
  implementation.
- [x] **Explore v1**: "Browse by type" and "Following" — real, working
  sections built entirely on data the plugin already exposed. Deliberately
  not a second Timeline, and deliberately not further than that yet.
- [x] **Me v1**: identity, a link into Search scoped to the user's own Marks,
  a view-only Drafts list, and links out to Notifications, wp-admin
  Subscriptions, and WordPress's own profile/logout.

**Still open from this era:**

- [ ] Explore beyond "Browse by type"/"Following" — memories, highlights,
  collections, favorites, recently-popular, and suggested accounts/content
  all need their own supporting data before they can be real sections.
- [ ] Search filters beyond type and source — author, date, tag, and
  location all need their own REST support first.
- [ ] Me beyond its current links — published-content browsing beyond "all
  your Marks", drafts management (edit/delete) inline on Me instead of
  pointing back to Home, and any of "connected services"/notifications
  summarized in place rather than linked out.

---

## Next — building on the loop

The product's core is "fast publish, site-first". These directions deepen
that loop without new destinations or a new social network.

- **Routing transparency.** The type→destination model works (image→Instagram,
  note→Bluesky, per-user memory). Next: make the effective routing for a Mark
  visible and editable *after* publish (a per-Mark "where did this go" view on
  the Mark, so backflow and distribution line up).
- **Backflow, not just import.** Replies already return as native comments with
  source labels. Next: thread them per conversation in notifications, filter by
  source, and make the sync cadence/cooldown observable instead of implicit.
- **Connector ecosystem.** The extension seam exists (`daymark_register_connectors`).
  Grow it deliberately: a documented reference connector, a registry of known
  connectors, and graceful in-app messaging when a Mark's destination plugin is
  deactivated.
- **Publish-loop polish.** Larger media sources (photo picker, drag-and-drop on
  desktop), gallery reordering, and draft → publish continuation are all
  candidates — each judged by whether it makes publishing faster (Publish First),
  not more powerful.
- **i18n readiness.** The plugin is en_US-only today. Wire the text domain into
  a translation scaffold so translators can work before a multilingual release,
  without changing any shipped strings' behavior.

---

## Longer term

Directional, not commitments. Each needs a written decision (and a CLAUDE.md
decision-table row) before it becomes "next".

- **Default-on gravity.** The thesis is a third mode next to Admin and Editor.
  Long-term: make Daymark the default recommendation for social-shaped posting —
  surfaced in onboarding, discoverable from wp-admin without being wp-admin, and
  functional the moment the plugin activates (it already is).
- **Offline publishing (revisit).** The *online* half shipped: the composer now
  autosaves in-progress work to a real server-side draft automatically (see
  CLAUDE.md's "Composer autosave" decision row), closing the common
  data-loss cases (closed tab, backgrounded app, accidental navigation) as
  long as there's connectivity. Genuine offline resilience — surviving zero
  connectivity — is still a non-goal (conservative SW only) and needs its own
  written decision before it becomes "next"; tracked in
  [issue #121](https://github.com/jeffpaul/daymark/issues/121).
- **Measured success.** The candidate signals in
  [planning §10](planning/README.md#10-success-metrics--e2e-acceptance) — first-
  publish completion, time-to-first-Mark, repeat publishing — stay unmeasured by
  design (no analytics dashboard). Long-term, decide what *privacy-respecting*
  signal (if any) is worth adding.
- **Hosted Daymark — provenance only.** The consumer publishing product explored
  at planning time (see [planning §12](planning/README.md#12-optional-future-hosted-moment-not-built))
  is explicitly **not** on this roadmap. It is recorded as a candidate direction,
  with its guardrails (no proprietary storage, no single-host lock-in, no single
  mandated AI provider, no CPT packaging) intact if it is ever revisited.
- **In-app settings screen.** Subscription management lives in wp-admin today
  (`rel=me` will too, once built — see "Not planned" below and CLAUDE.md's
  decision table) — a deliberate choice for infrequently-touched
  configuration, not a permanent one. A future pass may migrate some of this
  into an in-app Daymark settings screen; the two stay deliberately separate
  for now.

---

## Not planned (unless a decision changes)

- Real social-API publishing in core — Daymark cooperates with ecosystem plugins
  instead.
- A custom post type — Marks are standard `post`s; that is a product promise.
- wp-admin chrome **inside the Daymark app shell's own UI**. The app shell
  stays focused on day-to-day operational use (reading the Timeline,
  publishing content). This is *not* a blanket ban on Daymark ever having a
  wp-admin screen — the Subscriptions settings screen (Settings → Daymark) is
  a deliberate, confirmed exception for infrequently-touched configuration,
  and `rel=me` is planned to land the same way (on the native WordPress
  profile screen, not a new Daymark screen) once it's built — see "Shipped —
  Subscriptions & Timeline Following" above for its current status.
  See CLAUDE.md's decision table for the reasoning and "In-app settings
  screen" above for the possible future direction.
- Push notifications and multi-user team workflows beyond standard WordPress roles.
- API-key storage — AI rides the WordPress 7.0 AI Client or nothing.
