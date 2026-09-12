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
  publishing in core, push notifications, a custom post type, and
  multi-user team workflows are *not* planned. A roadmap line that
  contradicts one needs a written decision first. (A blanket "no settings
  dashboard" used to be on this list too — narrowed after the Subscriptions
  wp-admin screen shipped, and a blanket "no offline PWA" narrowed the same
  way after offline-first creation shipped; see "Not planned" below and
  CLAUDE.md's non-goals for the current, precise wording of each.)
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

First-party connector ecosystem docs (a worked `daymark_register_connectors`
example, published alongside the hooks reference) shipped as part of
"Connector ecosystem basics" — see "Shipped — Connector ecosystem basics"
below.

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
  `u-in-reply-to` now renders too, once a Mark actually is a reply — see
  "Webmention: rescoped to lean on ecosystem plugins" below.

This was the outbound half of POSSE-quality microformats2 support, the one
piece of issue #78 that shipped without it at the time. (*Inbound*
microformats2 parsing of a *subscribed* site's markup was separate,
out-of-scope-for-#78 work — it shipped later; see "Microformats2 (h-entry/
h-card) subscription parsing" below.)

- [x] **Subscription content-type inference: closes the inline-media gap.**
  `Daymark_Subscription_Source_Feed::normalize()` already derived a
  subscribed post's `post_format` from RSS enclosures; it now also sniffs
  the item's own content/description HTML (`WP_HTML_Tag_Processor`, no new
  dependency) when enclosures carry no signal — the common case of an
  ordinary post with an inline `<img>`/`<video>`/`<audio>` and no
  `<enclosure>` at all. A microformats2 `u-photo`/`u-video`/`u-audio` class
  counts regardless of surrounding text length; a bare `<img>` only counts
  when the accompanying text is short, so a long article's header image
  doesn't get misclassified as a photo post. See CLAUDE.md's "Subscription
  content-type inference" decision. Three options from the same discussion
  shipped separately — a full mf2 h-entry connector
  ([#84](https://github.com/jeffpaul/daymark/issues/84)), preferring the
  WordPress REST API's real `format` field for WP-to-WP subscriptions
  ([#137](https://github.com/jeffpaul/daymark/issues/137)), and a Friends
  plugin subscription source ([#88](https://github.com/jeffpaul/daymark/issues/88))
  — see below. Native ActivityPub-following ingestion and a Microsub client
  remain deferred (also tracked under #88): the ActivityPub plugin's own
  Reader UI for followed accounts is still experimental/feature-flagged,
  with an open, unresolved upstream discussion about how incoming posts
  should even be stored, and Microsub requires an external, non-WordPress
  server (e.g. Aperture) most Daymark users won't already have. Revisit
  once the ActivityPub plugin's own schema stabilizes, or Bridgy Fed
  ([#91](https://github.com/jeffpaul/daymark/issues/91)) offers a
  lower-effort path to the same Mastodon/Fediverse interoperability.

- [x] **WebSub/PubSubHubbub subscribing.** A subscribed feed that advertises
  a hub via `<link rel="hub">` now delivers new posts by push instead of
  waiting for the next scheduled poll — purely additive, the existing
  polling cron keeps running unchanged for every subscription regardless of
  WebSub support. See CLAUDE.md's "WebSub/PubSubHubbub subscribing (issue
  #82)" decision.

- [x] **Webmention: rescoped to lean on ecosystem plugins.** Issue #83 as
  filed asked for a full native Webmention sender, receiver, spec
  verification, and Vouch spam mitigation — reimplementing federation
  protocol work this plugin already made a deliberate choice not to own (see
  "Federation backflow" in CLAUDE.md). Rescoped to the one genuinely new
  capability: composing a reply to a subscribed post from its expanded
  Timeline card, which records `_daymark_in_reply_to` and renders
  `u-in-reply-to` on the resulting Mark's permalink. Whichever Webmention/
  ActivityPub/ATmosphere plugin the site owner runs sends and verifies from
  there. See CLAUDE.md's "Webmention: rescoped to lean on ecosystem plugins
  (issue #83)" decision.

- [x] **Microformats2 (h-entry/h-card) subscription parsing.** A new
  `Daymark_Subscription_Source_Microformats` connector discovers and parses
  h-feed/h-entry markup directly from a subscribed site's pages — a
  companion to the RSS/Atom connector, sharing its normalize() contract, so
  ingest and Timeline rendering never need to know which source produced an
  item. A minimal, purpose-built regex-based parser (no new Composer
  dependency — `mf2/mf2`'s last tagged release predates this work by several
  years) rather than a spec-complete mf2 implementation. Where a site
  exposes both a feed and h-feed markup, the feed always wins (registration
  order in `Daymark_Subscription_Source_Registry`); microformats2 is the
  sole source for a site with h-feed/h-entry markup but no discoverable feed
  at all. h-entry post types (reply/like/repost/bookmark/rsvp) are detected
  via the IndieWeb post-type-discovery algorithm and given a sensible
  fallback title when the entry itself carries no `p-name`. See CLAUDE.md's
  "Microformats2 (h-entry/h-card) subscription parsing (issue #84)"
  decision.

- [x] **Prefer the WordPress REST API for WP-to-WP subscriptions.** A new
  `Daymark_Subscription_Source_WordPress` connector, checked *before* the
  RSS/Atom feed source (the opposite precedence direction from the mf2
  connector above — the real thing beats a guess): discovers a site's REST
  API via the same `<link rel="https://api.w.org/">` tag a browser or client
  library would, then actively probes `wp/v2/posts` before trusting it,
  since a site can disable the REST API while leaving the discovery tag in
  place. When reachable, `post_format` comes straight from the site's own
  real value instead of the feed connector's enclosure/content-sniffing
  guess; the WordPress formats with no dedicated Daymark bucket
  (`aside`/`link`/`quote`) map down to `standard`, the same treatment issue
  #84's own unmapped h-entry post types get (`status`/`chat` were later
  mapped to Daymark's own `note` bucket instead — see below). Any other site
  — REST API disabled, unreachable, or not WordPress at all — falls straight
  through to the existing feed connector with no behavior change. See
  CLAUDE.md's "Prefer the WordPress REST API for WP-to-WP subscriptions
  (issue #137)" decision.

- [x] **Friends plugin as a subscription source.** A new
  `Daymark_Subscription_Source_Friends` connector, checked before every
  other built-in source, surfaces a friend the site owner already follows
  through the [Friends plugin](https://wordpress.org/plugins/friends/) —
  optional, never a hard dependency. Unlike every other source it makes no
  network request at all: Friends already fetches, parses, deduplicates,
  and classifies a friend's posts itself, caching the result locally, so
  this connector is a plain local database query against that cache and
  reads Friends' own already-assigned post format directly. Daymark never
  drives Friends' own "add a friend" flow — a site Friends doesn't already
  follow falls straight through to the other sources exactly as if this
  one didn't exist. See CLAUDE.md's "Friends plugin as a subscription
  source (issue #88)" decision, including an explicit callout that this
  was researched against the Friends plugin's public source rather than a
  live installation, since there was no way to install a third-party
  plugin for testing in this environment.

- [x] **Subscription type-mapping audit.** With four built-in sources now
  shipped, an audit of every signal each one can read and how (or whether) it
  reaches a Timeline card's `post_format` — written up as
  [docs/subscription-type-mapping.md](subscription-type-mapping.md). Found
  and fixed a real gap: the `wordpress` source (second-preferred, ahead of
  `feed`) trusted its site's real `format` field completely, with no
  fallback for the common case of a WordPress site that never assigns post
  formats at all — meaning it could detect a site's own media *less*
  accurately than the fallback `feed` connector already did for that same
  content. Fixed by extracting the existing content-sniffing fallback into a
  shared `Daymark_Subscription_Content_Sniffer` class and using it in both
  `wordpress` and (upgraded from a narrower image-only check) `friends`.
  Also documents, without changing at the time, two signals a source detects
  and discards: `microformats`'s own mf2 post-type discovery (reply/like/
  repost/bookmark/rsvp) and the five WordPress-native formats with no
  Daymark bucket — both flagged as the concrete starting points for a future
  expansion of Daymark's own type vocabulary. See CLAUDE.md's "Subscription
  type-mapping audit" decision.

- [x] **Status/chat post formats mapped to Daymark's Note type.** The first
  of the two vocabulary-expansion opportunities the type-mapping audit
  flagged, acted on: WordPress's `status` and `chat` post_format values
  (read by the `wordpress` and `friends` sources) now map to Daymark's own
  `note` post_format bucket instead of collapsing to `standard` alongside
  `aside`/`link`/`quote`, which still do — a `status`/`chat` post already
  reads as a short, timestamped text update the same way a Daymark Note
  does, unlike an aside/link/quote post which centers something other than
  the author's own words. `note` is treated as a fully confirmed format, the
  same as a real `image`/`video`/`audio`/`gallery` assignment — it's never
  second-guessed by the content-sniffing fallback. No changes were needed
  outside the two sources' own format resolution: the ingest, REST, and
  app-shell layers already treated `post_format` as an open string rather
  than a hardcoded enum. mf2 post-type discovery (reply/like/repost/
  bookmark/rsvp) remains unaddressed. See CLAUDE.md's "Status/chat post
  formats mapped to Daymark's Note type" decision.

A related, smaller adjustment: the PHP minimum is now 8.2 (was 8.1 — 8.1
stopped receiving security fixes). `phpunit/phpunit` stays on `^9.6` rather
than moving to 11.x: WordPress core's own PHPUnit test scaffold still calls a
method PHPUnit 10 removed, so every test run under PHPUnit 10+ fails
regardless of anything in this plugin. Tracked on
[issue #106](https://github.com/jeffpaul/daymark/issues/106) for whenever
core fixes it — a diagnosed, ready-to-apply test-file rename
(`test-*.php` → `test_*.php`, required by PHPUnit 11's stricter file/class
matching) is documented there too.

Sixteen further Subscriptions enhancements were explicitly deferred out of
#78's own scope and tracked as their own issues — see
[#79](https://github.com/jeffpaul/daymark/issues/79) through
[#94](https://github.com/jeffpaul/daymark/issues/94) (Action Scheduler,
per-subscription polling interval, multi-feed/category-scoped subscriptions,
additional source connectors, subscription grouping, keyword muting, feed
preview, inbound microformats2 parsing, Webmention support, WebSub/PuSH,
malformed/malicious feed hardening, OPML import/export, an admin page
recommending complementary IndieWeb plugins, scroll-triggered rehydration of
pruned content, on-demand site-icon refresh, and Bridgy Fed integration).
Inbound microformats2 parsing (#84), Webmention support (#83), WebSub/PuSH
(#82), WordPress REST API preference for WP-to-WP subscriptions (#137), the
Friends-plugin half of additional source connectors (#88), and the admin
page recommending complementary IndieWeb plugins (#86) have since shipped —
see the entries above. #88's own ActivityPub/Microsub half remains
deferred, per that entry's own reasoning above. None of what remains
otherwise is prioritized yet.

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

- [ ] Explore beyond "Browse by type"/"Following"/"Bookmarks" ([#294](https://github.com/jeffpaul/daymark/issues/294))
  — Explore gained a third section, "Bookmarks" (a link into Search
  preset to a reader's own saved items), as a side effect of the Bookmarks
  feature shipping (see "Shipped — Engagement" below), but memories,
  highlights, collections, recently-popular, and suggested accounts/content
  all still need their own supporting data before they can be real sections.
- [ ] Search filters beyond type and source ([#293](https://github.com/jeffpaul/daymark/issues/293))
  — author, date, tag, and location all need their own REST support first.
- [ ] Me beyond its current links ([#295](https://github.com/jeffpaul/daymark/issues/295))
  — published-content browsing beyond "all your Marks", drafts management
  (edit/delete) inline on Me instead of pointing back to Home, and any of
  "connected services"/notifications summarized in place rather than linked
  out.

---

## Shipped — Offline-first creation (issue #121)

"Creating while offline. Publish later. Users shouldn't care." Composing,
editing, and saving a Mark now works the same whether or not there's a
network — see CLAUDE.md's "Offline-first creation" decision row for the
full technical record.

- [x] **Offline queue.** A Mark composed or edited while offline (or that
  hits a network-level failure despite `navigator.onLine`) is stored whole
  — including picked media, as real Blobs — in IndexedDB instead of
  failing. It's replayed through the exact same `POST /marks` / `PUT
  /marks/{id}` endpoints a live Publish/Save as Draft/autosave already
  uses the moment connectivity returns (on the `online` event, and once at
  boot), so the server never sees a different code path for
  offline-originated work.
- [x] **Users shouldn't care.** Publishing or saving a draft while offline
  shows the same success screen as the online path ("Saved offline — will
  publish/sync automatically"), not an error. A Pending section on Home
  shows what's still queued, and it empties on its own as items sync.
- [x] **Autosave works offline too.** The composer's autosave (previously
  online-only) now falls back to the same offline queue, so "nothing gets
  lost" holds even when a session starts or goes offline mid-composition.

**Cold app-shell load while offline**, originally out of scope here, shipped
separately as [issue #126](https://github.com/jeffpaul/daymark/issues/126) —
see CLAUDE.md's "Cold-offline-load support" decision row.

---

## Shipped — Touch-target / thumb-reach / gesture / text-entry audit

"Gesture-friendly. Large touch targets. Comfortable thumb reach. Minimal
text entry." An audit against these four found the app shell mostly already
compliant — the `--daymark-tap-min: 44px` token, and every primary CTA
already bottom-pinned — and fixed the gaps. See CLAUDE.md's "Touch-target /
thumb-reach / gesture / text-entry audit" decision row for the full
technical record.

- [x] **Touch targets.** Six controls sized below the plugin's own 44px
  minimum (the Timeline ⋯ menu trigger, the site-icon filter button, the
  tag-chip remove button, the reply Send button, the ⋯ menu's
  delete-confirmation buttons, and the Search screen's filter chips) now
  size off `var(--daymark-tap-min)` instead of a hardcoded, smaller value.
- [x] **Minimal text entry.** Existing-tag autocomplete (`GET
  /daymark/v1/tags`) lets the composer's tag field offer a tap-to-pick
  suggestion instead of requiring the full name to be typed every time.
- [x] **Documented, not just enforced.** Gesture-friendliness (pull-to-refresh
  as the one deliberate exception to "tap-first") and minimal text entry are
  now named commitments in docs/design-principles.md, not only implicit
  side effects of other principles.

**Considered and declined:**

- [ ] **Swipe-to-delete** on the Timeline's ⋯ menu was considered and
  declined — the existing tap-based delete flow is already the required
  single-pointer alternative a swipe gesture would need to keep anyway, so
  adding one would only add a hidden, undiscoverable second path rather
  than remove a step.

---

## Shipped — Optimistic publishing

"Tap Publish. Immediately appears in your timeline. Uploads continue in the
background. Background sync. Especially useful for large videos, audio
(podcasts), galleries. Don't make users wait." Publish/Save as Draft no
longer blocks on the network at all — see CLAUDE.md's "Optimistic
publishing" decision row for the full technical record.

- [x] **Queue-first Publish.** A tap queues the Mark locally (a fast
  IndexedDB write, reusing the same store the offline queue already had)
  and moves on to the Success screen immediately; the real request —
  including the full media upload for a large video/podcast/gallery — runs
  afterward, in the background, regardless of connectivity.
- [x] **Immediately appears.** Home's Pending section shows the Mark right
  away, distinguishing three states for the first time (previously every
  pending item said "Offline" regardless of why): actively uploading,
  queued for connectivity, or a real failure that needs a retry.
- [x] **Don't make users wait, but don't lose detail either.** The Success
  screen shows an immediate confirmation from client-known data alone, then
  upgrades in place with the real permalink/syndication status once the
  background request confirms — the one place in the app that shows
  per-connector syndication status, kept rather than dropped.

---

## Shipped — Camera-first capture

"Assume 'I'm standing somewhere and want to publish.' Not 'I'm sitting at my
desktop writing.'" An audit found the composer's file picker had no `capture`
attribute anywhere — every media type opened a generic photo/file chooser,
and the docs themselves described the flow as picking "camera-roll media."
See CLAUDE.md's "Camera-first capture" decision row for the full technical
record.

- [x] **Capture-first picker.** A typed launcher entry (Image/Video/Audio)
  now offers "Take Photo"/"Record Video"/"Record Audio" as the primary
  picker action — it opens the device's camera or mic directly. "Choose from
  library instead" stays one tap away as a secondary action, so an
  already-taken photo is never harder to publish than before.
- [x] **A shortcut straight to the composer.** A PWA manifest `shortcuts`
  entry lets a long-press on the installed home-screen icon skip Home and
  the +New launcher tap entirely.

---

## Shipped — Share sheet integration

"Share -> Daymark" on iOS/Android: sharing a photo, link, or text from any
app creates a Daymark draft directly, without opening the app first — "one
of the best ways to get content into WordPress." Originally scoped out of
Camera-first capture (tracked as issue #131) on the assumption it would need
a service-worker scope-widening decision; it turned out not to. See
CLAUDE.md's "Share sheet integration" decision row for the full technical
record.

- [x] **A real `share_target`.** The PWA manifest declares it; a new
  `{base}/share` server route (`Daymark_Share_Target`) receives the POST
  directly — no service worker involved at all, since a share-target
  delivery is a real top-level navigation the server can just handle like
  any other form submission.
- [x] **Always a draft.** Reuses `Daymark_Publisher::publish()` directly —
  same content model, same default destinations/categories, same
  drafts-never-syndicate guarantee every other draft already has.
- [x] **Lands you right where you'd expect.** A successful share redirects
  straight into that draft's composer (skipping Home entirely), with the
  shared photo and any shared text already there.

---

## Shipped — Publish-loop polish

All three named candidates from this bucket have shipped.

- [x] **Composer drag-and-drop** ([#260](https://github.com/jeffpaul/daymark/issues/260))
  onto the picker (desktop), sharing one intake path (`addPickedFiles()`)
  with the existing file-input `change` handler.
- [x] **Manual gallery reordering** ([#250](https://github.com/jeffpaul/daymark/issues/250)),
  via up/down buttons rather than drag-and-drop (keyboard-operable, meets
  the tap-target minimum). This is also the prerequisite an AI-assisted
  ordering suggestion needs — a manual override surface for an author to
  see and correct an AI's proposal, the same way every other AI Assist
  suggestion already works. See "AI Assist expansion" below for that
  still-deferred follow-up ([#134](https://github.com/jeffpaul/daymark/issues/134)).
- [x] **Draft → publish continuation** ([#265](https://github.com/jeffpaul/daymark/issues/265)).
  A Draft's ⋯ menu gained a one-tap "Publish" action (alongside Edit/Delete)
  that skips the composer entirely for a draft that's already ready — 3
  taps down to 2. Building it surfaced and fixed a real, pre-existing bug
  in the `#publish` navigation guard: it only ever checked newly picked
  files and the caption, never a resumed draft's own already-attached
  media, so a plain photo draft with no caption could silently bounce back
  to the composer even before this shortcut existed.

---

## Shipped — Connector ecosystem basics

[#263](https://github.com/jeffpaul/daymark/issues/263). The extension seam
already existed (`daymark_register_connectors`); this closed the two concrete
gaps growing it deliberately turned up.

- [x] **Fixed a real bug**: a Mark published to a destination whose connector
  plugin was later deactivated/uninstalled had that target silently dropped
  from `publish_to_targets()` — the same "attempt discarded, not recorded"
  gap issue #255 (below) also fixed, at a different guard clause in the same
  method. Now recorded as `status: 'unavailable'` in `_daymark_external_posts`,
  surfaced in the Timeline's routing popover as "Not available."
- [x] **Documented a reference connector**: a complete, minimal
  `Daymark_Syndication_Connector` implementation (the interface, the publish
  payload/result shape, and the three relevant hooks) published alongside the
  hooks reference site.
- [ ] **Deliberately deferred**: a registry of known third-party connectors —
  no real one exists yet to list, so a registry would have nothing in it;
  revisit once a connector plugin actually ships.

---

## Shipped — AI Assist expansion

Audited AI Assist against every capability named as core to the concept
(suggest title, improve alt text, summarize podcast, generate transcript,
organize galleries, suggest tags). Title and tags already shipped; this
closed the remaining real gaps. See CLAUDE.md's "AI as an assistant" decision.

- [x] **Generate transcript** (audio/video), manual and author-triggered
  only — the composer never auto-uploads picked media for this the way it
  does for image alt-text suggestions.
- [x] **Improve alt text** once one already exists (distinct composer button
  copy from the first-suggestion case), and always applies its result since
  a manual tap is an explicit request.
- [x] **Summarize podcast** — satisfied by grounding, not a new capability:
  once a Mark has a transcript, an excerpt of it is embedded as context for
  every existing caption/title suggestion call, so a generated caption or
  title already reads as a summary of what's said.
- [ ] **Organize galleries** ([#134](https://github.com/jeffpaul/daymark/issues/134))
  remains deferred. Its prerequisite — a manual reorder affordance an AI
  proposal could sit on top of — shipped as manual gallery reordering (see
  "Publish-loop polish" above), so this is now unblocked whenever it's
  prioritized.

---

## Shipped — Quiet Mark metadata capture

"Quietly capture date/time, location (optional), weather (optional), camera
metadata, reading time, and AI-generated tags — don't make users fill those
in." See CLAUDE.md's "Quiet Mark metadata capture" decision.

- [x] **Capture timestamp, location, weather, camera EXIF, reading time, and
  AI-suggested tags** automatically while composing — every piece best-effort
  and silently absent on failure, never blocking or delaying a publish.
- [x] **Independently disableable.** Location, weather, and camera-metadata
  capture each have their own filter (default on), later exposed as plain
  checkboxes non-technical site owners can use directly — see "Privacy
  section" under Subscriptions admin maturation, below.
- [x] **Not displayed anywhere yet, by design.** This is captured ahead of
  planned future work (Timeline check-ins, weather display, richer photo
  metadata) — a site owner can opt out before that display work ships,
  rather than only after.

---

## Shipped — wp-admin entry points

Two admin bar shortcuts, both gated on `edit_posts`: "Open Daymark" beside
"Visit Site," and a "Daymark" entry under wp-admin's own "+New" menu that
jumps straight into the composer preset to an image Mark (matching
Camera-first capture's own "Image is the default, most common option"
ordering). See CLAUDE.md's "wp-admin entry points" decision.

---

## Shipped — Engagement: Bookmarks, Like/Repost/Comment, Share, and the full-screen post view

The largest body of Timeline/reading work since the bottom-nav rework —
subscribed content became something a reader can save, react to, and reply
to, not just view.

- [x] **Full-screen post view** ([#270](https://github.com/jeffpaul/daymark/issues/270))
  replaced the old inline-expand-in-place panel: tapping a card (a Mark, an
  ordinary post, or a subscription post) now opens its full content on a
  dedicated screen, matching the pattern Notifications already used. Later
  fixes kept the card's own site-name/date/interaction row visible on this
  screen too ([#287](https://github.com/jeffpaul/daymark/issues/287)) and
  top-aligned its header against a wrapped title ([#315](https://github.com/jeffpaul/daymark/issues/315)).
- [x] **Bookmarks** ([#193](https://github.com/jeffpaul/daymark/issues/193)):
  save a Mark or subscription post for later, with a dedicated Explore
  section, and full offline caching of a bookmarked item's content — later
  extended to its referenced images too ([#236](https://github.com/jeffpaul/daymark/issues/236))
  — so a bookmark is genuinely available with no connection. A bookmark is
  cleaned up automatically if its post is later actually deleted (not just
  trashed).
- [x] **Share icon** ([#195](https://github.com/jeffpaul/daymark/issues/195)):
  opens the OS/native share sheet via `navigator.share()` where available,
  falling back to a copy-to-clipboard with a visible confirmation.
- [x] **Like and Repost toggles** on a subscription post (issue #41
  follow-up), instant and composer-free — each publishes (or, untoggled,
  trashes) a minimal Note Mark carrying `_daymark_like_of`/`_daymark_repost_of`,
  which whichever federation plugin the site owner runs turns into a real
  like/reblog. A **Repost** gained an optional caption step
  ([#317](https://github.com/jeffpaul/daymark/issues/317)); a Reblog's own
  Mark now contains a real link to the origin post, not just caption text
  ([#355](https://github.com/jeffpaul/daymark/issues/355)). A **Like Mark is
  hidden from the site's own discovery surfaces** (archives, feed, REST
  collection, sitemap, oEmbed) while its permalink stays reachable, since a
  Webmention still needs to fetch it ([#361](https://github.com/jeffpaul/daymark/issues/361)).
- [x] **Comment**, replacing the original composer-based "Reply" action
  ([#317](https://github.com/jeffpaul/daymark/issues/317)): sends a real
  Webmention (preferred, when the local Webmention plugin is active and the
  origin advertises a receiver) or falls back to a native, unauthenticated
  `wp/v2/comments` POST. A pre-check now warns *before* the composer opens
  when a destination can't accept a comment at all, rather than after the
  reader has already typed one ([#351](https://github.com/jeffpaul/daymark/issues/351)).
- [x] **First-time explainer overlays** for all six interaction-row icons
  (Like/Comment/Reblog/Bookmark/"Open original"/Share), shown once per
  device after the action succeeds — never gating the tap itself
  ([#321](https://github.com/jeffpaul/daymark/issues/321)); for Comment and
  Reblog specifically, the explainer was later moved to appear *before* the
  compose step instead of after ([#357](https://github.com/jeffpaul/daymark/issues/357)).
- [x] **⋯ overflow menu** ([#326](https://github.com/jeffpaul/daymark/issues/326))
  moved Open original/Share/Routing/Refresh content behind a secondary menu,
  keeping Like/Comment/Reblog/Bookmark as the always-visible primary row, and
  added a new **Unsubscribe** action reachable directly from a subscription
  post's card.
- [x] **Link previews** for a "link"-kind subscription post: a best-effort
  oEmbed preview first ([#279](https://github.com/jeffpaul/daymark/issues/279)),
  falling back to an Open Graph/Twitter Card/plain-`<title>` preview for the
  far more common case of an ordinary page with no oEmbed endpoint at all
  ([#349](https://github.com/jeffpaul/daymark/issues/349)).

---

## Shipped — Subscriptions: reading polish

Smaller Timeline-reading fixes that shipped alongside the engagement work
above.

- [x] **Grouped under relative-period headers** (Today, This Week, Last
  Week, This Month, Last Month, This Year, or a bare year) —
  [#145](https://github.com/jeffpaul/daymark/issues/145).
- [x] **Scroll-triggered rehydration** of a pruned subscription post's
  content just before it scrolls into view, so opening it moments later
  renders instantly — [#93](https://github.com/jeffpaul/daymark/issues/93).
- [x] **Friendlier session-expired messaging** on a stale nonce (a common
  case for a home-screen PWA resumed from a long background suspension):
  a plain "reload" prompt instead of a raw WordPress error string —
  [#214](https://github.com/jeffpaul/daymark/issues/214).
- [x] **Absolute dates use the site's own Date Format setting**, not the
  browser's locale default — [#238](https://github.com/jeffpaul/daymark/issues/238).

---

## Shipped — Notifications maturation

- [x] **Threaded by conversation** (grouped by the Mark a reply belongs to),
  a **source filter**, and **backflow sync recency** ("Replies last checked
  X ago") surfaced in the routing popover — [#258](https://github.com/jeffpaul/daymark/issues/258).
- [x] **Overlapping IndieWeb plugin detection**: Post Kinds, Microformats 2,
  Syndication Links, or IndieBlocks running alongside Daymark (each
  overlapping something Daymark already renders natively) surfaces as a
  dismissible notification rather than being silently suppressed — Daymark
  never controls another plugin's behavior, only reads and flags the
  overlap — [#346](https://github.com/jeffpaul/daymark/issues/346).

---

## Shipped — Subscriptions: admin screen and multi-feed maturation

Settings → Daymark grew from a single subscribe/manage page into a real,
tabbed settings surface, and subscribing itself grew to handle a site with
more than one feed worth following.

- [x] **OPML import/export** ([#80](https://github.com/jeffpaul/daymark/issues/80))
  and **on-demand site-icon refresh** ([#94](https://github.com/jeffpaul/daymark/issues/94)).
- [x] **Restructured into tabs** — Subscriptions, Connectors, Import/Export,
  Privacy — with a shorter URL (`options-general.php?page=daymark`, 301'd
  from the old one) ([#86](https://github.com/jeffpaul/daymark/issues/86)).
  The **Connectors tab** recommends Webmention/ActivityPub/ATmosphere with
  live install/activate status via WordPress core's own install flow, and
  documents **Bridgy Fed** as a lower-effort alternative path to the
  fediverse/Bluesky ([#91](https://github.com/jeffpaul/daymark/issues/91)).
- [x] **Privacy section** ([#289](https://github.com/jeffpaul/daymark/issues/289))
  exposes the Quiet Mark metadata capture opt-outs (location/weather/camera)
  as plain checkboxes, and **configurable check frequency**
  ([#291](https://github.com/jeffpaul/daymark/issues/291)) replaces the fixed
  daily poll with an Hourly/6-hour/12-hour/Daily choice.
- [x] **Subscriptions table UX polish**: sortable columns, defaulting to A-Z
  by site name ([#178](https://github.com/jeffpaul/daymark/issues/178),
  [#303](https://github.com/jeffpaul/daymark/issues/303)), a plain-GET
  **search box** ([#281](https://github.com/jeffpaul/daymark/issues/281)),
  an **inline, editable site name**
  ([#180](https://github.com/jeffpaul/daymark/issues/180),
  [#242](https://github.com/jeffpaul/daymark/issues/242)), and **inline
  Refresh**/site-icon actions
  ([#176](https://github.com/jeffpaul/daymark/issues/176),
  [#245](https://github.com/jeffpaul/daymark/issues/245)) instead of a full
  page reload per action.
- [x] **Following more than one feed from the same site**, and picking
  which feed(s) to follow up front. What started as a single-choice
  "switch feeds" picker on an existing subscription
  ([#307](https://github.com/jeffpaul/daymark/issues/307)) grew into an
  additive multi-select picker (check new feeds, existing ones stay locked
  in — unsubscribe is still the only way to remove one)
  ([#334](https://github.com/jeffpaul/daymark/issues/334)), gained
  **per-language feed detection** for multilingual sites via standard
  `hreflang` markup, defaulting to the site's own configured language
  ([#336](https://github.com/jeffpaul/daymark/issues/336)), let one submit
  both add and remove feeds at once
  ([#363](https://github.com/jeffpaul/daymark/issues/363)), and — for a
  brand-new subscribe specifically — became an inline, single-select picker
  with an editable site name shown before the first real subscribe happens
  ([#368](https://github.com/jeffpaul/daymark/issues/368)).

---

## Shipped — i18n readiness

Daymark is translation-ready via wordpress.org's own GlotPress
infrastructure once it ships there — no bundled `languages/` folder or
custom translation build of its own. An audit closed two real PHP-side
gaps and wired `wp_set_script_translations()` into the app shell
([#252](https://github.com/jeffpaul/daymark/issues/252)); a follow-up
converted every user-facing string in `assets/app.js` (roughly 300
literals) to route through `wp.i18n`
([#253](https://github.com/jeffpaul/daymark/issues/253)).

---

## Shipped — Per-Mark routing transparency

[#255](https://github.com/jeffpaul/daymark/issues/255). A new Timeline icon
opens a popover showing exactly where a Mark was sent — your own site,
always first, followed by each selected destination's real status
(published/mocked/failed/unsupported/unavailable) and a link out where one
exists. Fixed a real, previously-undetectable data-model gap along the way:
a target rejected before it ever reached its connector was silently
discarded rather than recorded as `failed`, so that documented status value
could never actually be produced.

---

## Next — building on the loop

The product's core is "fast publish, site-first". These directions deepen
that loop without new destinations or a new social network.

- **mf2 `repost`/`like`/`bookmark` mapping** ([#292](https://github.com/jeffpaul/daymark/issues/292),
  the half deliberately deferred when `reply`/`rsvp` shipped — see "Shipped
  — Subscriptions & Timeline Following" above). The `microformats` source
  already computes IndieWeb post-type discovery for every h-entry; the open
  question is what these three reaction types should become on the
  Timeline — hidden entirely, mirroring how Daymark's own Like/Repost Marks
  are hidden from discovery ([#361](https://github.com/jeffpaul/daymark/issues/361)),
  or a `link_url` reuse for `bookmark` specifically. Not resolved.
- **AI-assisted gallery ordering** ([#134](https://github.com/jeffpaul/daymark/issues/134)).
  Manual gallery reordering shipped as its prerequisite (see "Shipped —
  Publish-loop polish" above); this is now unblocked whenever it's
  prioritized.
- **Whether Bridgy Fed unblocks native ActivityPub-following**
  ([#91](https://github.com/jeffpaul/daymark/issues/91)'s own flagged next
  step, feeding into [#3](https://github.com/jeffpaul/daymark/issues/3)).
  If a Bridgy-Fed-bridged Mastodon/Bluesky account turns out to expose a
  fetchable feed or microformats2 shape, the existing `feed`/`microformats`
  subscription sources could follow it with no new source class at all —
  never confirmed (this environment can't reach Bridgy Fed's own service to
  check), so it's the concrete next step before any further native
  ActivityPub-reading work.
- **Explore, Search, and Me's remaining scope** — see "Still open from this
  era" under "Shipped — Bottom navigation rework" above
  ([#294](https://github.com/jeffpaul/daymark/issues/294),
  [#293](https://github.com/jeffpaul/daymark/issues/293),
  [#295](https://github.com/jeffpaul/daymark/issues/295)). Each needs new
  supporting REST/data work before it can grow past its current foundation.

---

## Longer term

Directional, not commitments. Each needs a written decision (and a CLAUDE.md
decision-table row) before it becomes "next".

- **Default-on gravity.** The thesis is a third mode next to Admin and Editor.
  Long-term: make Daymark the default recommendation for social-shaped posting —
  surfaced in onboarding, discoverable from wp-admin without being wp-admin, and
  functional the moment the plugin activates (it already is).
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
- **In-app settings screen.** Subscription management, the Connectors and
  Privacy tabs, and `rel=me` configuration all live in wp-admin today
  (`rel=me` on WordPress's own native profile screen; everything else on
  the tabbed Settings → Daymark screen — see "Not planned" below and
  CLAUDE.md's decision table) — a deliberate choice for infrequently-touched
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
  wp-admin screen — the tabbed Settings → Daymark screen (Subscriptions,
  Connectors, Import/Export, Privacy) is a deliberate, confirmed exception
  for infrequently-touched configuration, and `rel=me` already lands the
  same way, on the native WordPress profile screen rather than a new
  Daymark screen — see "Shipped — Subscriptions & Timeline Following" and
  "Shipped — Subscriptions: admin screen and multi-feed maturation" above.
  See CLAUDE.md's decision table for the reasoning and "In-app settings
  screen" above for the possible future direction.
- Push notifications and multi-user team workflows beyond standard WordPress roles.
- API-key storage — AI rides the WordPress 7.0 AI Client or nothing.
