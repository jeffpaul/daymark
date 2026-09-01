# Changelog

All notable changes to Daymark are documented in this file. Through 0.5.0
the plugin was named Moment; those entries are kept as shipped.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file is the source of truth for the changelog: the `== Changelog ==` section
of `readme.txt` is generated from it by `bin/sync-changelog.sh`, and the GitHub
release notes for a tag are its section here. Add entries under
[Unreleased](#unreleased) as you go, then rename that heading to the new version
at release time.

Entries link to the pull request that made the change. Releases before 0.5.0
have no links because they predate this project's pull-request workflow — their
history is reachable through the compare links at the bottom of this file. The
links are stripped when `readme.txt` is generated, since wordpress.org readers
can't act on them.

## [Unreleased]

### Added

- A persistent bottom navigation — **Timeline, Explore, +New, Search, Me** — replacing the site-views links (Images/Videos/Audio/Notes) that used to flank the +New launcher. Explore, Search, and Me are new destinations with real `/daymark/explore`, `/daymark/search`, and `/daymark/me` routes (like `/daymark/notifications` already had), so a direct link or a browser refresh lands correctly, and each shows as the active tab (`aria-current="page"`) while the +New launcher never picks up that styling.
- **Explore**: a first, deliberately non-chronological browsing destination — not a second Timeline. "Browse by type" (Image/Video/Audio/Note) and "Following" (your active subscriptions) both hand a preset off to Search rather than duplicating its results view, and are built entirely on data/endpoints the plugin already had. Memories, highlights, collections, favorites, and suggested content are future sections, not implied by what ships here.
- **Search**: promoted out of Home's old collapsible header search bar into its own screen and nav destination, reusing the same keyword/type/source query. Home no longer has an inline search UI.
- **Me**: a minimal personal-identity screen — avatar and display name, a link into Search scoped to your own Marks, a view-only Drafts list (tap to resume editing), and links out to Notifications, the wp-admin Subscriptions screen, and WordPress's own profile/logout screens.
- Composer autosave: your in-progress Mark (caption, media, alt text, destination and category choices) now saves automatically to a real draft as you compose, well before you tap Publish or Save as Draft. Close the tab, take a call, or switch apps mid-caption and your work is waiting under Drafts on Home — no manual save step needed. Still requires an internet connection, the same as the existing manual Save as Draft. A typed-but-unsent Notifications reply is similarly protected against being lost when you switch to another reply or navigate back to Notifications.
- Every Timeline item now shows its own site icon (a Mark's own avatar, or a subscribed site's icon) as its own leading element, separate from the item's thumbnail. Tapping it is a single click straight to the same Source filter Search already offers — no menu, no separate "visit the site" option. A Note Mark or a subscription post with no real photo no longer shows a second, type-initial icon next to it — that placeholder is gone, so title and caption text run further before wrapping.
- Offline-first creation: compose, publish, or save a draft with no connection at all — Daymark saves it on your device and shows the same success screen as if you were online. It publishes or syncs itself automatically the moment you're back online, no manual retry, and a new Pending section on Home shows what's still waiting. Composer autosave now falls back to the same offline saving, so it protects your work whether or not you have a connection. Covers a session already open when you go offline (or start one offline) — loading `/daymark` itself for the very first time still needs a connection.
- The AI Assist sheet's "Add a tag" field now suggests matching tags already used on your site as you type — tap one to add it instead of typing the full name.
- Optimistic publishing: tapping Publish or Save as Draft no longer waits for the upload to finish — you're back on the confirmation screen immediately, and the Mark uploads and syndicates in the background, which matters most for a large video, a podcast episode, or a big gallery. A Pending row on Home shows it in progress and clears once it's done; the confirmation screen fills in the permalink and syndication status once the background upload confirms.

### Developer

- Added product principles documentation. ([#118](https://github.com/jeffpaul/daymark/pull/118))
- Added design principles documentation. ([#119](https://github.com/jeffpaul/daymark/pull/119))
- Composer autosave requests (`autosave=1` on `POST /marks` / `PUT /marks/{id}`) use a new, independent `Daymark_Rate_Limiter::ACTION_AUTOSAVE` bucket rather than `ACTION_PUBLISH`, so frequent background autosave activity can never exhaust the budget for a real Publish/Save as Draft tap.
- Audited the app shell against "gesture-friendly, large touch targets, comfortable thumb reach, minimal text entry" and documented it as a named commitment (CLAUDE.md, docs/design-principles.md, docs/roadmap.md) rather than only an implicit effect of other principles. Added `GET /daymark/v1/tags` (existing `post_tag` terms matching a search string) backing the new tag autocomplete.
- `publishInBackground()`/`syncPendingMark()` extend the offline-first-creation IndexedDB pending queue (`assets/app.js`) to a queue-first, always-on mechanism for the deliberate Publish/Save-as-Draft tap, rather than only a connectivity-failure fallback; the pending-record schema gained a `status` field (`uploading`/`queued`/`error`) so `renderPendingItem()` can show which is true instead of every pending item saying "Offline". No server/PHP changes — same REST endpoints and payload shape as before.

### Changed

- For developers: the four wordpress.org screenshots (`.wordpress-org/screenshot-*.png`) still showed the plugin's previous identity, Moment — pre-0.6.0 branding, the removed public Timeline nav item, and no sign of Subscriptions or POSSE markup. Regenerated against a live 0.9.0 build with current Daymark branding and content.

### Fixed

- For developers: `uninstall.php` now cleans up everything the Subscriptions feature and the POSSE microformats2 work added — the `daymark_subscriptions` table, cached `daymark_sub_post` content, the subscription poller's cron event, the `daymark_redirect_rule_added`/`daymark_subscriptions_db_version` options, the `daymark_rel_me_url` user meta, and rate-limiter transients. It previously only covered what existed before those features shipped.
- For developers: the SPA router now tears down the previous screen's outside-click/Escape dismiss listeners before rendering the next one, instead of leaving them attached to `document` and firing against whichever screen loads next. Not user-visible today (every current dismiss action is a no-op once its own screen is gone), but closes off the failure mode for a future one that isn't. ([#64](https://github.com/jeffpaul/daymark/issues/64))
- Several tappable controls were sized below the app shell's own documented 44px minimum tap target: the Timeline item's ⋯ menu trigger, the site icon filter button, the tag remove ("×") button, the Notifications reply screen's Send button, the ⋯ menu's delete-confirmation buttons, and the Search screen's filter chips. All now size off the shared `--daymark-tap-min` token.

### Removed

- `Daymark_Migration`, the one-time storage conversion from the plugin's previous identity, Moment (≤ 0.5.0), to Daymark. It has been deprecated since 0.7.0. **If your site still runs Moment (≤ 0.5.0), you must upgrade through an intermediate 0.6.x–0.8.x release first — jumping straight to this version will not convert your old Moment data.** ([#36](https://github.com/jeffpaul/daymark/issues/36))
- The Images, Videos, Audio, and Notes section pages, their `daymark/*` blocks, `[daymark_*]` shortcodes, `Daymark_Renderer`, and the `@wordpress/scripts` block-editor build (`src/`, `build/`) that existed only to power them — superseded by Explore's "Browse by type" section. **An existing install's pages are moved to Trash (not hard-deleted) on upgrade**, and a bookmarked or indexed old URL now 301s to `/daymark/explore` instead of a bare 404.

## [0.8.0] - 2026-08-31

### Added

- Home is now the merged Timeline feed: the user's own Marks interleaved with cached posts from subscribed sites, replacing what was a Recent Marks list of only their own Marks. Opening a subscribed post fetches and shows its full content in place; pulling down from the top of the list refreshes every active subscription. ([#102](https://github.com/jeffpaul/daymark/pull/102), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
- Search now covers the whole Timeline (Marks and subscription posts) instead of only the user's own Marks, with a new Source filter next to the existing type chips to narrow it back down to "My Marks" or one specific subscribed site. ([#103](https://github.com/jeffpaul/daymark/pull/103), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
- A Settings → Daymark screen for managing subscriptions: subscribe to a site by URL, see its status and when it was last fetched, refresh it on demand, and unsubscribe. Also reachable via a new "Subscriptions" action link on the Plugins list screen. The Subscribe button shows a loading state while the request is in flight. ([#104](https://github.com/jeffpaul/daymark/pull/104), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
- Home's Recent Marks list now shows the same comment/like stat row as the public Timeline card — a zero count stays a dimmed icon-only, a real count shows next to a bolder icon. Resolves the compactness side of [#42](https://github.com/jeffpaul/daymark/issues/42) in favor of the shared visual language. ([#72](https://github.com/jeffpaul/daymark/pull/72))
- A Mark's own permalink page now carries outbound POSSE-quality microformats2 markup: `h-entry` (with `e-content`, `p-name`/`p-summary`, `dt-published`, `u-url`, and `u-photo`/`u-video`/`u-audio` for attached media) and an author `h-card` (`p-author`, `p-name`, `u-photo`). A new `rel=me` field on the native Users → Your Profile screen renders as a `rel="me"` link next to the h-card when set. Deliberately leaves out `u-email` — a WordPress account email isn't meant to be public, and it's optional in the h-card spec. (part of [#78](https://github.com/jeffpaul/daymark/issues/78))

### Removed

- The public `/timeline` page, the `daymark/timeline` block, and the `[daymark_timeline]` shortcode. Timeline is now an interleaved, multi-source view (your own Marks plus subscribed sites' posts, via Home) that only makes sense inside the authenticated app — a public page under the same name showing something narrower was confusing and redundant. An existing install's `/timeline` page is hard-deleted on upgrade (real 404, no redirect); individual Mark permalinks, your site's RSS/Atom feed, and the other four section pages (`/images`, `/videos`, `/audio`, `/notes`) are unaffected. (part of [#78](https://github.com/jeffpaul/daymark/issues/78))

### Changed

- For developers: `Requires PHP` is now 8.2 (was 8.1) — PHP 8.1 stopped receiving security fixes. `phpunit/phpunit` stays on `^9.6` rather than moving to 11.x: WordPress core's own PHPUnit test scaffold still calls a method PHPUnit 10 removed, so every test run under PHPUnit 10+ fails regardless of anything in this plugin. Tracked in [#106](https://github.com/jeffpaul/daymark/issues/106) for whenever core fixes it.
- For developers: `CONTRIBUTING.md`'s crediting-contributors section now says explicitly that Claude Code gets a `Co-Authored-By:` trailer too, alongside human contributors, when it wrote or materially helped write a change. ([#108](https://github.com/jeffpaul/daymark/pull/108))
- For developers: `CONTRIBUTING.md`'s release checklist now opens with a dependency update check (`npm`/`composer outdated` and `audit`, patch/minor routinely, majors held for a deliberate compatibility review) and a bundle size/tree-shaking check, before opening the release PR. ([#105](https://github.com/jeffpaul/daymark/pull/105))
- For developers: this release's dependency check found nothing to update — `npm outdated` and `composer outdated --direct` are clean apart from the already-tracked `phpunit/phpunit` hold-back (see above). `npm audit` reports 32 advisories, all in `webpack-dev-server`'s transitive chain under the `@wordpress/scripts` devDependency (local build/watch tooling only — the plugin ships no npm `dependencies` and none of this reaches the distribution zip); `composer audit` is clean. Bundle size unchanged at 778 bytes.

### Fixed

- A subscribed post's fetched full content no longer leaks the raw page's `<script>`/`<style>` source as visible text in the click-through detail view. `wp_kses_post()` only strips those tags, not their enclosed text, so a fetched page's tracking scripts and print styles were showing up as plain text; the fetch now narrows to the page's `<body>` and drops script/style elements entirely (tag and content) before sanitizing. ([#102](https://github.com/jeffpaul/daymark/pull/102))
- The app now only ever lives at `/daymark` even on an install that migrated from Moment *before* the 0.7.0 fix shipped. That fix only stopped a *future* migration from carrying the old base forward — a site that had already migrated kept it stuck at e.g. `/moment` forever, since that setting is deliberately never re-checked once resolved. It's now self-corrected on first use: the old value moves to the redirect (same as a fresh migration), and the "Open Daymark" link on the Installed Plugins screen and every other app URL correctly point at `/daymark`. ([#71](https://github.com/jeffpaul/daymark/pull/71))
- A Mark migrated from Moment (which never set a featured image) now shows its thumbnail in Home's Recent Marks list, the same way it already did on the public Timeline: the list reads the featured image first, then falls back to the Mark's own first image attachment, instead of only ever checking the featured image. ([#72](https://github.com/jeffpaul/daymark/pull/72))
- A generated title for a long caption with no spaces (e.g. Japanese, which `wp_trim_words()` only shortens on a CJK-translated locale) is now trimmed to a character-count backstop instead of used in full. The limit is filterable via `daymark_title_max_chars`. ([#75](https://github.com/jeffpaul/daymark/pull/75), fixes [#74](https://github.com/jeffpaul/daymark/issues/74))
- The "+ New Mark" launcher's Image/Video/Audio/Note bubbles now genuinely burst outward from the button and settle back into it, instead of mostly fading in near their own final position with a slight scale. A scroll that happens while a bubble is still mid fan-out (including one an automated click's own scroll-into-view step can trigger) no longer closes the launcher out from under itself before it's had a chance to become tappable. ([#72](https://github.com/jeffpaul/daymark/pull/72))

### Security

- A cached subscription post could previously be edited or deleted through WordPress's own generic REST API (`wp/v2/subscription-posts`), auto-registered because the post type was `show_in_rest => true` and gated only by ordinary edit/delete-post capabilities — entirely separate from, and bypassing, Daymark's own read-only routes. Nothing in the app ever used that generic endpoint; it's now disabled, so a cached copy of someone else's content can only ever be written by the subscription poller itself. ([#103](https://github.com/jeffpaul/daymark/pull/103))
- For developers: bumped `nanoid`, a transitive devDependency of `@wordpress/scripts`' bundled Lighthouse tooling, to resolve a high-severity advisory. Dev-tooling only — never invoked by this project's own build/test scripts and never shipped in the plugin zip. ([#105](https://github.com/jeffpaul/daymark/pull/105))

### Deprecated

- `Daymark_Migration` (the one-time Moment → Daymark storage conversion) is soft-deprecated ahead of removal in 0.9.0. No behavior change for anyone still upgrading from Moment (≤ 0.5.0) — sites with real legacy data to convert now also get a logged `_deprecated_function()` notice (visible under `WP_DEBUG`) at the moment the conversion runs, as a heads-up before it's removed. ([#69](https://github.com/jeffpaul/daymark/pull/69))

## [0.7.0] - 2026-08-07

### Added

- The five `daymark/*` blocks (Timeline, Images, Videos, Audio, Notes) now expose how many recent Marks they show as a setting in the block editor, instead of requiring a hand-edit of the block markup. The count control appears under Block tab → "Number of Marks" (1–50) and the editor preview updates as you drag it. ([#56](https://github.com/jeffpaul/daymark/pull/56))

### Fixed

- The app now only ever lives at `/daymark` (or `/daymark-app` when that slug is already taken by real site content), even on an install migrated from Moment. Since 0.6.1, a migrated install kept serving the app at its old `/moment` URL, with `/daymark` merely redirecting there — now it's the other way around: `/daymark` is the real app, and `/moment` (and any home-screen icon already pointing at it) 301s to it instead. ([#62](https://github.com/jeffpaul/daymark/pull/62))
- Search and a notification's reply box now dismiss the same way the per-item menu and the "+ New Mark" launcher already do: tapping outside them, or pressing Escape, closes them and returns keyboard focus to their own toggle. Escape now works no matter which control inside search has focus, not only the text field itself. ([#63](https://github.com/jeffpaul/daymark/pull/63))
- The composer's title-field "ⓘ" hint follows suit too: an outside tap or Escape closes it and returns focus to the ⓘ button. ([#65](https://github.com/jeffpaul/daymark/pull/65))

### Security

- Editing a Mark's alt text is now scoped to that Mark's own media — an ID-mapped alt edit can no longer be aimed at an image that belongs to a different post.
- Expensive actions are now rate limited per user: AI Assist requests, publishing, and manual response syncs. Over the limit, Daymark asks you to wait a moment instead of processing (limits are configurable via the `daymark_rate_limits` filter).
- Uploads are now capped per request as well as per file, so many files can't be combined to bypass the 50 MB per-file cap (itself now filterable via `daymark_upload_max_bytes`). The combined upload limit is 200 MB, filterable via `daymark_upload_total_max_bytes`.
- Manual response syncs for real connector references now honor the same per-post cooldown as automatic backflow (with an atomic lock so overlapping syncs can't double-poll), while mocked demo syncs stay instant and repeat-safe.
- The app shell now sends a conservative Content-Security-Policy header, filterable via `daymark_app_content_security_policy`. Its inline bootstrap script is nonce-scoped rather than relying on `'unsafe-inline'`, so an injected `<script>` tag has no way to execute even if something else on the page were compromised. ([#60](https://github.com/jeffpaul/daymark/pull/60))
- Imported social replies can be routed through moderation: the `daymark_comment_import_approved` filter decides whether an imported reply is approved.
- AI Assist now treats your draft text strictly as data — instructions hidden inside a caption or filename can't redirect the model. Draft text and filenames are wrapped in an explicit data boundary in the prompt itself, and AI-generated captions, titles, and alt text are now hard-capped server-side rather than only requested via the prompt. ([#60](https://github.com/jeffpaul/daymark/pull/60))

### Changed

- Tapping "+ New Mark" now fans out into Image/Video/Audio/Note bubbles, Path-app style, instead of always landing on a generic composer — pick a type and the composer opens pre-set to it. The button itself shrank to a plain "+" circle, and Timeline moved from the bottom nav up into the header as a combined icon + "Daymark" home-link, freeing a slot for the new launcher among the remaining Images/Video/Audio/Notes icons. Every public view now also carries a small "← Daymark" link back into the app, since section pages render inside your theme with no app chrome of their own. The animation respects `prefers-reduced-motion`, and every icon — the launcher and its four bubbles — has a real accessible name. ([#53](https://github.com/jeffpaul/daymark/pull/53))
- For developers: the coding-standards suite now covers the test files (`composer phpcs-tests`) and checks PHP 8.1+ compatibility with the PHPCompatibility standard (`composer phpcompat`); CI runs both.

## [0.6.1] - 2026-08-05

### Fixed

- `/daymark` no longer 404s on an install migrated from Moment. The app deliberately keeps its persisted URL (e.g. `/moment`) so a home-screen icon never breaks, but the new brand's own URL had nothing registered at all — it now redirects to wherever the app actually lives. ([#49](https://github.com/jeffpaul/daymark/pull/49))

### Changed

- The five `daymark/*` blocks (Timeline, Images, Videos, Audio, Notes) now support color, typography, and spacing from your theme's Global Styles, and sit under their own "Daymark" category in the block inserter instead of the generic "Widgets" bucket. The inserter's hover preview now shows the block rendered against your actual content. ([#39](https://github.com/jeffpaul/daymark/pull/39))
- Each Mark on the public views now shows a comment count and a like count (from replies and reactions the ActivityPub, ATmosphere, or Webmention plugins deliver, plus your own on-site comments). A count of zero stays quiet — just a dimmed icon, no "0" — and steps up in weight and color as soon as there's something to report. ([#43](https://github.com/jeffpaul/daymark/pull/43))
- Audio and video Marks on the public views now play inline, right in the card, instead of showing only a badge and caption; note Marks get a larger, pull-quoted caption since the text is the whole Mark. ([#44](https://github.com/jeffpaul/daymark/pull/44))
- The Home footer (New Mark button + site nav) slides out of the way while you scroll down the recent list, reclaiming its height for content, and slides back on scroll-up or when keyboard focus reaches it. The top bar is a touch more compact too. ([#50](https://github.com/jeffpaul/daymark/pull/50))

## [0.6.0] - 2026-08-04

### Changed

- Moment is now **Daymark**, and your posts are now called **Marks** — the wordpress.org plugin review team did not approve the name "Moment" and approved "Daymark". The app lives at `/daymark` on new installs; existing installs keep their current app URL, so a home-screen icon keeps working. ([#35](https://github.com/jeffpaul/daymark/pull/35))
- Existing installs are converted automatically: a one-time migration renames the stored options, per-user preferences, post and comment meta, and the section pages' blocks/shortcodes in place. Nothing is lost and nothing needs to be done by hand. (The migration will be retired in a later release.) ([#35](https://github.com/jeffpaul/daymark/pull/35))
- For developers: every public identifier is renamed with no back-compat bridges — REST is `daymark/v1` with a `marks` resource, blocks are `daymark/*`, shortcodes `[daymark_*]`, hooks and meta use the `daymark_`/`_daymark_*` prefixes (the post marker is `_daymark_is_mark`), and PHP classes are `Daymark_*`. ([#35](https://github.com/jeffpaul/daymark/pull/35))

## [0.5.0] - 2026-08-03

### Added

- Optional Title field for audio and video Moments: those types read well with a title, so the composer offers one — pre-filled by AI from your caption when a provider is connected, editable, and optional (leave it blank and Moment derives the title from your text, as before). Which types show the field is filterable via the `moment_title_field_policy` filter. ([#28](https://github.com/jeffpaul/daymark/pull/28))
- Search and filter your Moments from Home: a search icon in the header expands a search box, with type-filter chips for Images, Videos, Audio, and Notes; the list heading reads "Results" while you search. ([#23](https://github.com/jeffpaul/daymark/pull/23), [#25](https://github.com/jeffpaul/daymark/pull/25))
- Manage a Moment straight from the list: a per-item menu edits a published Moment in the composer or deletes it (with a confirmation step; delete moves the Moment to Trash). ([#23](https://github.com/jeffpaul/daymark/pull/23), [#25](https://github.com/jeffpaul/daymark/pull/25))
- Reply to a notification inline: each notification has a reply icon that opens a reply box and posts your reply as a comment on the Moment. ([#23](https://github.com/jeffpaul/daymark/pull/23), [#25](https://github.com/jeffpaul/daymark/pull/25))
- A 50 MB per-file upload cap, with a clear message when a file is too large. ([#22](https://github.com/jeffpaul/daymark/pull/22))
- For developers: new REST endpoints (`DELETE /moments/{id}`, `POST /notifications/{id}/reply`, `type`/`s` filters on `GET /moments`, and `POST /ai/title`), the `moment_title_field_policy` filter, and `backflow_supported` documented on the connector interface. ([#22](https://github.com/jeffpaul/daymark/pull/22), [#23](https://github.com/jeffpaul/daymark/pull/23), [#28](https://github.com/jeffpaul/daymark/pull/28))

### Changed

- Infinite scroll on the recent Moments list replaces the manual "view more" step, and the site-views navigation stays anchored at the bottom. ([#25](https://github.com/jeffpaul/daymark/pull/25))
- The app, PWA, and browser-tab icons now use Moment's designed brand mark. ([#21](https://github.com/jeffpaul/daymark/pull/21))

### Removed

- The `podcast` Moment type: a podcast is simply an audio (or video) Moment. Use a Category if you want to label one as a podcast. ([#27](https://github.com/jeffpaul/daymark/pull/27))

## [0.4.0] - 2026-07-29

### Added

- Per-image alt text: every image in an image, gallery, or mixed Moment gets its own alt field in the composer. When an AI provider is connected, each image's alt is generated from the image itself (vision) and pre-filled for you to edit before publishing.
- Categories: a "File under" picker on the publish screen files a Moment under your existing categories, remembers the choice per Moment type, and lets you change it per Moment. Shown only when your site has categories beyond its default.
- ATmosphere joins the third-party publishing toggles, giving Bluesky a per-Moment on/off control alongside Share on Mastodon and Autoshare for Twitter.
- Success screen: an inline "(view)" link next to "Published to your site" and a "View all moments" link back home.
- Project health: LICENSE (GPL-2.0-or-later), contributing guide, code of conduct, issue and pull-request templates, and a dependency license-review check.

### Changed

- iOS home-screen polish: the app now uses your Site Icon (or Moment's) as its home-screen icon, the bottom navigation sits flush with the bottom of the screen, and the Publish and Save as Draft buttons are spaced apart.

### Removed

- The bundled Bluesky and Mastodon connector plugins. Moment now works with your existing publishing plugins — detected on the publish screen, with per-Moment toggles where a plugin supports it — and replies still flow back via ActivityPub, ATmosphere, and Webmention. The `moment_register_connectors` hook remains for any plugin that wants to add a first-class destination.

## [0.3.0] - 2026-07-22

### Added

- Awareness for other publishing plugins: when Jetpack Social, ATmosphere, Autoblue, Share on Mastodon, XPoster, Autoshare for Twitter, Blog2Social, Social Networks Auto-Poster (SNAP), or Revive Old Posts is active, the publish screen notes that your Moment will also go out through it. Detection only — Moment never drives or configures them — and it is extensible via the `moment_publish_helper_plugins` filter.

### Changed

- Site-views nav (Timeline/Images/Videos/Audio/Notes) now uses icons, with each label kept as the hover tooltip and the accessible name.
- Recent Moments caps at five, with a "View more" link to your timeline once there are more.
- Each Moment now gets a post format matching its type (image → Image, note → Aside, …) instead of inheriting the site's default format.
- Publishing shows the loading state on the button itself; both Publish and Save as Draft disable while a publish is in flight.
- The Drafts section is hidden when there are no drafts.

### Fixed

- "Publishing a Image Moment" now reads "Publishing an Image Moment".

## [0.2.0] - 2026-07-16

### Added

- Save as Draft: start a Moment now, finish it later. Drafts store your selected destinations and never syndicate until published.
- Continue editing: tap a draft on Home to reopen the composer with its caption, media, and destinations restored; publishing an edited draft (from the app or wp-admin) runs the stored destinations.
- Drafts row on Home keeps drafts reachable no matter how many Moments publish after them; draft rows carry a Draft chip.
- Unread indicator on the notifications bell — a simple dot, cleared by viewing notifications.
- "Open Moment" action link on the Plugins screen for a one-click path into the app.

### Changed

- The + New Moment button moved to the bottom of the screen for one-handed reach.
- Section pages are created with Moment blocks, so block themes edit them natively; shortcodes remain fully supported.

### Fixed

- Slug collisions are handled instead of silently shadowed: existing pages keep /timeline (etc.) while Moment views fall back to prefixed slugs, and a site with content at /moment gets the app at /moment-app — app links always point at the real locations.
- The PWA manifest is served dynamically for the resolved app path, without a redirect hop, and no longer hardcodes the wp-content path.

## [0.1.1] - 2026-07-15

### Changed

- App shell CSS/JS now load through the WordPress enqueue API (registered handles, inline bootstrap config via `wp_add_inline_script`, defer strategy).
- Reworded the plugin description per wordpress.org review guidelines.

### Security

- Tightened REST API capability checks: draft Moments list only for users who can edit them, notifications are scoped to Moments the current user can edit, syncing responses requires `edit_post` on the target, and attaching media requires `upload_files`.

## [0.1.0] - 2026-07-10

### Added

- Initial release.
- Phone-first `/moment` app shell with Home, Create, Publish, and Notifications screens; PWA manifest and home-screen support.
- Publishing pipeline creating standard WordPress posts with block markup for image, video, audio, note, gallery, and mixed Moments.
- REST API under `/wp-json/moment/v1/` (moments, AI suggestions, response sync, notifications).
- Syndication connector registry with per-type routing defaults, per-user destination memory, and connected-only destination visibility.
- Automatic conversation backflow: hourly sync plus on-view freshen, importing replies as native WordPress comments.
- Federation integration: labeled backflow from the ActivityPub, ATmosphere, and Webmention plugins; IndieWeb u-syndication markup for Bridgy backfeed.
- Optional AI Assist (captions, alt text, tags) via the WordPress 7.0 AI Client.
- Timeline and per-type views as both shortcodes and dynamic blocks.

[unreleased]: https://github.com/jeffpaul/daymark/compare/0.8.0...HEAD
[0.8.0]: https://github.com/jeffpaul/daymark/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/jeffpaul/daymark/compare/0.6.1...0.7.0
[0.6.1]: https://github.com/jeffpaul/daymark/compare/0.6.0...0.6.1
[0.6.0]: https://github.com/jeffpaul/daymark/compare/0.5.0...0.6.0
[0.5.0]: https://github.com/jeffpaul/daymark/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/jeffpaul/daymark/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/jeffpaul/daymark/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/jeffpaul/daymark/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/jeffpaul/daymark/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/jeffpaul/daymark/releases/tag/0.1.0
