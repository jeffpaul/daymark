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

- The Settings -> Daymark subscriptions table now shows each site's cached icon in its own column, between Site and Status. ([#171](https://github.com/jeffpaul/daymark/pull/171))
- The Settings -> Daymark subscriptions table's Site, Status, and Last fetched column headers are now clickable to sort the table ascending or descending. ([#179](https://github.com/jeffpaul/daymark/pull/179))
- A subscription's Site name can now be edited directly in Settings -> Daymark, for when the auto-derived name (especially a Friends-plugin-sourced one) isn't obviously who or what it is. ([#179](https://github.com/jeffpaul/daymark/pull/179))
- A Timeline card's site icon now shows the site's name and URL as a native tooltip on hover. ([#179](https://github.com/jeffpaul/daymark/pull/179))
- A subscription that's failing to fetch new posts now shows a "Recent fetch issue" message in Settings -> Daymark well before it's flagged fully dead, and a dismissible wp-admin notice links back to the table when at least one subscription has a problem. ([#182](https://github.com/jeffpaul/daymark/issues/182))

### Changed

- A Timeline card's date now sits on its own row, right-aligned at the bottom of the card, instead of sharing the meta line with the chip/author/reading-time text above it — a quieter, corner-anchored placement that reads more like a timestamp and less like one more label in a list. ([#173](https://github.com/jeffpaul/daymark/pull/173))
- Clicking "Refresh" on a subscription in Settings -> Daymark now updates that row's Status and Last fetched values in place instead of reloading the whole page. ([#177](https://github.com/jeffpaul/daymark/pull/177))
- A subscription post's Timeline card no longer shows a "Subscribed" chip — its site icon already makes clear it isn't one of your own Marks, so the chip was just taking up space. ([#179](https://github.com/jeffpaul/daymark/pull/179))
- A Timeline card's comment/like/repost stat row is now ordered like, comment, reblog (was comment, like, repost). ([#186](https://github.com/jeffpaul/daymark/pull/186))
- The header now shows the Daymark icon in the upper left on every screen (Home, Explore, Search, Me) instead of just the Timeline icon on Home — tapping it from Explore, Search, or Me takes you back to the Timeline. Explore, Search, and Me also now show the Notifications icon in the upper right, so it's been removed as a separate link on the Me page. ([#188](https://github.com/jeffpaul/daymark/pull/188))
- A subscription having trouble fetching new posts (or fully dead) now shows up right in your Notifications, with a link back to Settings -> Daymark — no more separate wp-admin notice to check. ([#190](https://github.com/jeffpaul/daymark/pull/190))

### Fixed

- Importing an OPML file could leave every newly subscribed site showing zero posts on the Timeline until the next scheduled poll (up to a day away by default) — subscribing to a single site by URL already fetches its content right away, but import never did. A successful import now triggers an immediate background poll. ([#175](https://github.com/jeffpaul/daymark/pull/175))
- Subscribing to a second feed on an already-subscribed WordPress site (e.g. a friend publishing both a Posts archive and a separate Notes archive on one install) previously failed as a duplicate, since the site's REST API always resolves to the same site-wide feed regardless of which page you subscribed from. You can now paste a specific feed URL directly to subscribe to exactly that feed, and subscribing to a second page on the same site now falls back to that page's own RSS/Atom feed instead of failing. ([#184](https://github.com/jeffpaul/daymark/pull/184))

## [0.10.0] - 2026-09-04

### Added

- wp-admin now offers two quick ways into Daymark: an "Open Daymark" link next to "Visit Site" under the site name in the admin bar, and a "Daymark" item in the "+New" menu that jumps straight into the composer pre-set to an Image Mark. ([#158](https://github.com/jeffpaul/daymark/pull/158))
- Subscriptions can now be exported to and imported from a standard OPML file — Settings -> Daymark gets Export/Import controls (`GET`/`POST /daymark/v1/subscriptions/export`/`import` back them). Import reports a per-entry result (subscribed, already subscribed, or failed) rather than failing the whole file on one bad entry, and a dead-flagged subscription is included in the export since it's still a follow worth backing up. ([#80](https://github.com/jeffpaul/daymark/issues/80))
- A subscription's cached site icon can now be refreshed on demand from Settings -> Daymark's "Refresh icon" action, instead of only ever being resolved once at subscribe time — useful when a site rebrands its favicon, or for a subscription imported via OPML from another reader with no cached icon at all. ([#94](https://github.com/jeffpaul/daymark/issues/94))
- A Mark now quietly picks up capture date/time, optional location, weather, camera EXIF metadata, an estimated reading time, and AI-suggested tags — none of it requires filling anything in, and none of it can block or delay publishing. Location, weather, and camera metadata capture can each be disabled with a filter (`daymark_capture_location`/`daymark_capture_weather`/`daymark_capture_camera_metadata`) — see the readme.txt/README.md FAQ for what's captured and why, ahead of planned future Timeline display work. ([#157](https://github.com/jeffpaul/daymark/pull/157))
- A subscribed feed that advertises WebSub (PubSubHubbub) support now delivers its updates by push instead of waiting for the next scheduled poll, so new posts from a supporting site can show up on your Timeline within moments of being published. Purely additive: the existing polling schedule keeps running unchanged for every subscription regardless of WebSub support, so nothing changes for a feed that doesn't advertise a hub. ([#161](https://github.com/jeffpaul/daymark/pull/161))
- Expanding a subscribed post's Timeline card now offers a "Reply" action that opens the composer seeded to that post. Publishing sends a normal Mark whose permalink carries a `u-in-reply-to` link to the source — install the [Webmention plugin](https://wordpress.org/plugins/webmention/) and it notifies the source automatically the moment your reply goes live. ([#162](https://github.com/jeffpaul/daymark/pull/162))
- You can now subscribe to IndieWeb sites that publish microformats2 (h-entry/h-card) markup but have no traditional RSS/Atom feed at all — a new parser discovers and reads that markup directly, mapping richer post types (reply/like/repost/bookmark/RSVP) and author info onto your Timeline the same way a feed-based subscription would. When a site has both a feed and h-feed markup, the feed is always used. ([#163](https://github.com/jeffpaul/daymark/pull/163))
- Subscribing to another WordPress site now prefers its real REST API over its RSS/Atom feed when both are reachable, so Timeline cards for that subscription's posts get the site's actual post format (image/video/audio/gallery/standard) instead of a guess from feed content. Any other site — including a WordPress site with the REST API disabled — subscribes exactly as before. ([#164](https://github.com/jeffpaul/daymark/pull/164))
- If you already follow someone through the [Friends plugin](https://wordpress.org/plugins/friends/), subscribing to their site in Daymark now reads their posts straight from Friends' own already-fetched cache instead of independently re-polling their site — no extra setup, and it only ever applies to a friend you've already added there. ([#165](https://github.com/jeffpaul/daymark/pull/165))
- A Mark's Timeline card now shows a repost count alongside its existing comment and like counts, the same quiet, zero-hides-itself treatment as those two — reposts delivered as replies/reactions from the ActivityPub, ATmosphere, or Webmention plugins now show up on the stat row too, not just in Notifications. ([#166](https://github.com/jeffpaul/daymark/pull/166))
- Subscribing to a WordPress site (directly, or to a friend through the Friends plugin) now shows a "Status" or "Chat" formatted post as a Note on your Timeline, with its own icon, instead of a generic "Standard"/article card. ([#167](https://github.com/jeffpaul/daymark/pull/167))

### Changed

- A published Mark's Timeline card no longer offers in-app Edit or Delete — the ⋯ actions menu is now a Draft-only affordance. To edit or delete a published Mark, use wp-admin directly. ([#153](https://github.com/jeffpaul/daymark/pull/153))

### Fixed

- Subscribing to a site could fail with "This subscription could not be saved. It may already exist." on a site where Daymark was already active before updating to 0.9.0 — the `daymark_subscriptions` table is created on plugin activation, which WordPress doesn't re-run on a plain file update. `Daymark_Subscriptions::install()` now self-heals on `init`, matching the pattern already used for the backflow and subscription-polling cron schedules. ([#149](https://github.com/jeffpaul/daymark/pull/149))
- A Timeline card for your own Mark showed your WordPress user avatar/Gravatar as its leading site icon, not your site's actual Site Icon — often just a plain letter glyph, since a self-hosted site's admin rarely has a Gravatar set. It now shows the site's Site Icon (or Daymark's own bundled icon if none is set), the same source resolution `Daymark_Routes::icon_url()` already uses for the browser favicon and PWA icons, and consistent with how a subscribed site's posts already show that site's own icon rather than its author's. ([#149](https://github.com/jeffpaul/daymark/pull/149))
- Timeline's connecting line between type icons only reached a few px above and below each icon, never actually touching the next or previous item's icon — visibly broken on any row taller than a compact one, which is most of them. It's now drawn once per list rather than per item, so it runs continuously from the first icon to the last regardless of how tall an individual card is, still behind every icon rather than over it. ([#149](https://github.com/jeffpaul/daymark/pull/149))
- An ordinary "Standard"-format post with a featured image rendered with the same full-bleed banner treatment a real Image Mark gets, since Timeline only ever checked whether a thumbnail existed, never the post's own WordPress post format. It now reports that format to the app shell, so a Standard post reads as a smaller thumbnail beside its title and excerpt (an Article card), and only a post whose actual format is Image/Gallery/Video gets the full banner. ([#149](https://github.com/jeffpaul/daymark/pull/149))
- A Timeline card's image showed an empty placeholder instead of the real photo whenever that image was served from a different host than the site itself — a CDN or offload plugin (Jetpack's Photon, S3, Cloudflare, ...), or a subscribed site's own thumbnail/favicon. The app shell's Content-Security-Policy only allowed images from `'self'`, silently blocking every one of those; `img-src`/`media-src` now also allow `https:` sources. ([#149](https://github.com/jeffpaul/daymark/pull/149))
- The header never hid on scroll the way the footer already did, so it was always taking up space the footer's own auto-hide was reclaiming. It now hides on scroll-up and reappears on scroll-down — the opposite direction from the footer — so the two never both cost their height back at the same time. ([#149](https://github.com/jeffpaul/daymark/pull/149))
- An empty Timeline's "Nothing here yet. Publish a Mark or subscribe to a site to fill your timeline." only ever linked "Publish a Mark" — "subscribe to a site" was plain text with nowhere to go. It now links to the Settings -> Daymark subscribe screen, the same destination Explore's own "Following" empty state already links to. ([#159](https://github.com/jeffpaul/daymark/pull/159))
- Subscribing to a WordPress site with the REST API reachable (Daymark's own preferred source for it) could classify every one of its posts as a plain "Standard" card, even an image/video/audio-only one, on any site that never assigns WordPress post formats — the common case, especially with a block theme. Subscribing to that same site's RSS/Atom feed instead already detected inline media correctly; the REST connector now falls back to the same content-sniffing when the site's own `format` field is `standard`. A friend followed through the Friends plugin gets the same, richer fallback (video/audio/gallery, not just a single image as before). ([#167](https://github.com/jeffpaul/daymark/pull/167))

### Security

- Feed and subscription fetches (feed content, site-HTML autodiscovery/favicon/title, and the click-through full-content fetch) now enforce filterable response size caps (2 MB / 1 MB / 4 MB respectively) — a response at or beyond the cap is rejected outright rather than parsed or cached as if it were complete. ([#81](https://github.com/jeffpaul/daymark/issues/81))
- Added `Daymark_Subscription_Url_Guard`, a shared SSRF defense-in-depth check applied to every subscription/feed/site URL before it's fetched — on top of the IPv4 protection WordPress core's `wp_http_validate_url()` already provides, it also rejects IPv6 loopback/unique-local/link-local addresses, IPv4-mapped IPv6 literals, the IPv4 CGNAT range, embedded userinfo, and non-standard ports. ([#81](https://github.com/jeffpaul/daymark/issues/81))
- A subscription now records a human-readable `last_error` reason for its most recent failed check (surfaced in the wp-admin Subscriptions screen, notifications, and the REST API), instead of only a status flag and a failure count with no explanation of why. ([#81](https://github.com/jeffpaul/daymark/issues/81))
- Feed, site-HTML, and click-through fetch timeouts are now filterable instead of hardcoded. ([#81](https://github.com/jeffpaul/daymark/issues/81))

### Developer

- `SECURITY.md`'s supported-versions table had sat at `0.6.x` since that release, several versions stale — bumped to `0.9.x`, the actual current release, and added a reminder to the release checklist in CONTRIBUTING.md so this doesn't silently drift again (this table isn't build-enforced the way the four version-number locations are).
- The changelog-entry expectation is now called out in CLAUDE.md and the PR template, not just CONTRIBUTING.md, with explicit guidance to keep entries short. ([#156](https://github.com/jeffpaul/daymark/pull/156))
- The "Preview in WordPress Playground" PR comment now also posts on a PR's first `synchronize` (push), not only `opened`/`reopened` — a PR opened via the GitHub API (rather than a person pushing through the web UI) doesn't reliably deliver a plain `pull_request` `opened` event, so an `opened`-only trigger could silently never post the button at all. ([#158](https://github.com/jeffpaul/daymark/pull/158))
- The PR template now has its own `## Changelog` section, for pasting in the same entry you added to `CHANGELOG.md`. Since this repo squash-merges using the PR title and description as the commit message, this keeps `git log` on `main` as scannable as the changelog itself instead of only as concise as the rest of a given PR's description happens to be. ([#160](https://github.com/jeffpaul/daymark/pull/160))

## [0.9.0] - 2026-09-02

### Added

- Replaced the Images/Videos/Audio/Notes links flanking +New with a persistent bottom nav: **Timeline, Explore, +New, Search, Me**. Explore, Search, and Me are real routes (`/daymark/explore`, `/daymark/search`, `/daymark/me`), so a direct link or refresh lands correctly, and the active tab shows via `aria-current="page"`. ([#123](https://github.com/jeffpaul/daymark/pull/123))
- **Explore**: a non-chronological browsing destination — "Browse by type" (Image/Video/Audio/Note) and "Following" (your subscriptions) hand a preset off to Search rather than duplicating it. Memories, collections, and suggested content are future sections. ([#123](https://github.com/jeffpaul/daymark/pull/123))
- **Search**: promoted out of Home's collapsible header search bar into its own screen and nav destination, reusing the same keyword/type/source query — Home no longer has an inline search UI. ([#123](https://github.com/jeffpaul/daymark/pull/123))
- **Me**: a minimal personal-identity screen — avatar/display name, a link into Search scoped to your own Marks, a view-only Drafts list (tap to resume editing), and links to Notifications, the wp-admin Subscriptions screen, and WordPress's own profile/logout. ([#123](https://github.com/jeffpaul/daymark/pull/123))
- Composer autosave: your in-progress Mark (caption, media, alt text, destination, category) now saves automatically to a real draft as you compose — no manual save step, though it still requires a connection like the existing manual Save as Draft. ([#122](https://github.com/jeffpaul/daymark/pull/122))
- A typed-but-unsent Notifications reply is similarly protected against being lost when you switch to another reply or navigate back to Notifications. ([#122](https://github.com/jeffpaul/daymark/pull/122))
- Every Timeline item now shows its own site icon (a Mark's own avatar, or a subscribed site's icon) as its own leading element, separate from the item's thumbnail — a single click filters Timeline to that source, the same Source filter Search already offers. ([#120](https://github.com/jeffpaul/daymark/pull/120), [#125](https://github.com/jeffpaul/daymark/pull/125))
- A site icon degrades gracefully to a plain letter glyph if it fails to load — a subscription's icon is sometimes an unverified guess at the site's `/favicon.ico`, which doesn't always resolve. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Timeline cards now render by content kind instead of one uniform row, inspired by (not copied from) Path's own timeline: a type-indicator icon sits beside the site icon, threaded to the item above/below by a connecting line. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Image, gallery, video, and mixed-media Marks get a media-dominant card (full-width photo banner, secondary caption, a play button for video, a format badge for gallery/video/mixed); audio gets a compact artwork-and-metadata row; a Note Mark stays a lightweight typography-first row and now shows its own caption text when it carries more than the title alone does. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- A subscription post's plain-text posts split into Article (longer excerpt, optional thumbnail) and Link (compact bordered mini-card, no image) — an approximation, since the feed source doesn't yet preserve real content length to tell a genuinely short post from a truncated one. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Every media-dominant kind falls back to a branded placeholder panel, not a blank space, when there's no real photo to show — the common case for a video or audio Mark. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Timeline now includes every published post on your site, not just ones created through Daymark's own composer — a real featured image gets the same media-dominant treatment an Image Mark gets, real body text reads as an Article card, and anything else falls back to a plain row. It carries no ⋯ Edit/Delete menu, since Daymark's composer isn't the right tool for editing block-editor content — use wp-admin for that. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Filtering Search or Explore to one specific type (Image, Video, …) still only ever matches true Marks, since an ordinary post has no such type of its own to filter by. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- A Timeline card's relative timestamp ("Just now", "12m ago", shifting to a plain date after a week) is now a real `<time>` element carrying the exact date and time as its title — hover it, or have a screen reader announce it, for precision at any age. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- A single-image or gallery Mark's card now gets a noticeably taller photo banner (320px, up from 176px) than the other media-dominant kinds — the photo is the content for these two types, so it gets more room instead of being cropped to a modest strip. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Tapping a Timeline card now expands its content right in place, below the card, instead of navigating you away (a Mark or your own ordinary post) or opening an overlay (a subscription post). Tap again to collapse; only one card is expanded at a time. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- What you see is just that post's own content — no comments, navigation, header, or footer pulled in along with it, whether it's a Mark, an outside post, or a subscribed site's post. A "View full post ↗"/"View original ↗" link is still there for the real page. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Offline-first creation: compose, publish, or save a draft with no connection at all — Daymark saves it on your device and shows the same success screen as if you were online, then publishes or syncs automatically the moment you're back online. A new Pending section on Home shows what's still waiting. ([#127](https://github.com/jeffpaul/daymark/pull/127))
- Composer autosave now falls back to the same offline saving. Covers a session already open when you go offline (or start one offline) — loading `/daymark` itself for the very first time still needs a connection. ([#127](https://github.com/jeffpaul/daymark/pull/127))
- The AI Assist sheet's "Add a tag" field now suggests matching tags already used on your site as you type — tap one instead of typing the full name. ([#128](https://github.com/jeffpaul/daymark/pull/128))
- Optimistic publishing: tapping Publish or Save as Draft no longer waits for the upload to finish — you're back on the confirmation screen immediately, and the Mark uploads and syndicates in the background, which matters most for a large video, a podcast episode, or a big gallery. ([#130](https://github.com/jeffpaul/daymark/pull/130))
- A Pending row on Home shows it in progress and clears once it's done; the confirmation screen fills in the permalink and syndication status once the background upload confirms. ([#130](https://github.com/jeffpaul/daymark/pull/130))
- Camera-first capture: tapping Image, Video, or Audio now opens your device's camera or microphone directly, ready to capture — "Choose from library instead" is still right there, just not the default. ([#132](https://github.com/jeffpaul/daymark/pull/132))
- A new home-screen shortcut (long-press the installed icon) jumps straight into the composer, skipping Home and the +New tap. ([#132](https://github.com/jeffpaul/daymark/pull/132))
- Share sheet integration: share a photo, link, or text to Daymark from any app on your phone — it creates a draft and opens straight into the composer with it already loaded, ready for a caption. Requires having added Daymark to your home screen. ([#133](https://github.com/jeffpaul/daymark/pull/133))
- AI Assist can now generate a transcript for an audio or video Mark — tap "Generate transcript" in the composer. Once a Mark has a transcript, its AI-suggested caption and title are grounded in what's actually said, so "summarize podcast" falls out of the existing suggestions rather than being a separate feature. ([#135](https://github.com/jeffpaul/daymark/pull/135))
- AI Assist can also improve, not just regenerate, an image's alt text via a new "Improve with AI"/"Suggest with AI" button. Both are exactly as optional and non-blocking as the rest of AI Assist: no configured provider means no button, and a failed request just leaves the field for manual entry. ([#135](https://github.com/jeffpaul/daymark/pull/135))

### Changed

- For developers: the four wordpress.org screenshots (`.wordpress-org/screenshot-*.png`) still showed the plugin's previous identity, Moment. Regenerated against a live 0.9.0 build with current Daymark branding and content. ([#117](https://github.com/jeffpaul/daymark/pull/117))

### Removed

- `Daymark_Migration`, the one-time storage conversion from the plugin's previous identity, Moment (≤ 0.5.0), to Daymark, deprecated since 0.7.0. **If your site still runs Moment (≤ 0.5.0), you must upgrade through an intermediate 0.6.x–0.8.x release first — jumping straight to this version will not convert your old Moment data.** ([#36](https://github.com/jeffpaul/daymark/issues/36), [#117](https://github.com/jeffpaul/daymark/pull/117))
- The Images, Videos, Audio, and Notes section pages, their `daymark/*` blocks, `[daymark_*]` shortcodes, `Daymark_Renderer`, and the `@wordpress/scripts` block-editor build that existed only to power them — superseded by Explore's "Browse by type" section. **Existing pages move to Trash (not hard-deleted) on upgrade**, and old URLs 301 to `/daymark/explore` instead of a bare 404. ([#123](https://github.com/jeffpaul/daymark/pull/123))

### Fixed

- For developers: `uninstall.php` now cleans up everything the Subscriptions feature and the POSSE microformats2 work added — the `daymark_subscriptions` table, cached content, the poller's cron event, related options/user meta, and rate-limiter transients. ([#116](https://github.com/jeffpaul/daymark/pull/116))
- For developers: the SPA router now tears down the previous screen's outside-click/Escape dismiss listeners before rendering the next one, instead of leaving them attached to `document`. Not user-visible today, but closes off a failure mode for a future screen. ([#64](https://github.com/jeffpaul/daymark/issues/64), [#115](https://github.com/jeffpaul/daymark/pull/115))
- Several tappable controls were sized below the app shell's own documented 44px minimum tap target: the Timeline item's ⋯ menu trigger, the site icon filter button, the tag remove ("×") button, the Notifications reply screen's Send button, the ⋯ menu's delete-confirmation buttons, and the Search screen's filter chips. All now size off the shared `--daymark-tap-min` token. ([#128](https://github.com/jeffpaul/daymark/pull/128))
- Subscribing to a site captured its feed `<link>` tag's own title — commonly WordPress's default `"{Site Name} » Feed"` convention — instead of the plain site name. Now reads the site's own `<title>` tag; the old value is kept as a new `feed_title` column for a future multi-feed subscription. ([#140](https://github.com/jeffpaul/daymark/pull/140))

### Developer

- Added product principles documentation. ([#118](https://github.com/jeffpaul/daymark/pull/118))
- Added design principles documentation. ([#119](https://github.com/jeffpaul/daymark/pull/119))
- Added a Mission statement as the authoritative lens above the product principles, in CLAUDE.md, README.md, and readme.txt. ([#136](https://github.com/jeffpaul/daymark/pull/136))
- Composer autosave requests (`autosave=1`) use a new, independent `Daymark_Rate_Limiter::ACTION_AUTOSAVE` bucket rather than `ACTION_PUBLISH`, so background autosave activity can never exhaust the budget for a real Publish/Save as Draft tap. ([#122](https://github.com/jeffpaul/daymark/pull/122))
- Audited the app shell against "gesture-friendly, large touch targets, comfortable thumb reach, minimal text entry" and documented it as a named commitment. ([#128](https://github.com/jeffpaul/daymark/pull/128))
- Added `GET /daymark/v1/tags` (existing `post_tag` terms matching a search string) backing the new tag autocomplete. ([#128](https://github.com/jeffpaul/daymark/pull/128))
- `publishInBackground()`/`syncPendingMark()` extend the offline-first-creation IndexedDB pending queue to a queue-first, always-on mechanism for a deliberate Publish/Save-as-Draft tap, rather than only a connectivity-failure fallback. ([#130](https://github.com/jeffpaul/daymark/pull/130))
- The pending-record schema gained a `status` field (`uploading`/`queued`/`error`) so `renderPendingItem()` can show which is true instead of every pending item saying "Offline". ([#130](https://github.com/jeffpaul/daymark/pull/130))
- Added a `shortcuts` entry to the PWA manifest pointing at `#create`. `share_target` was considered and deliberately deferred — tracked in [#131](https://github.com/jeffpaul/daymark/issues/131) — since it needs its own service-worker scope decision. ([#132](https://github.com/jeffpaul/daymark/pull/132))
- Added `share_target` to the PWA manifest and a new `{base}/share` route (`Daymark_Share_Target`) receiving the OS share-sheet POST directly — no service-worker involvement needed, since a share-target delivery is a real top-level navigation the server can handle like any other form submission. ([#133](https://github.com/jeffpaul/daymark/pull/133))
- No REST nonce is possible from an OS-originated request; relies on WordPress's SameSite=Lax auth cookie plus a `Sec-Fetch-Site` check as defense in depth. ([#133](https://github.com/jeffpaul/daymark/pull/133))
- `Daymark_AI_Assist::get_transcript_suggestion()`/`generate_transcript()` add audio transcription, mirroring the existing vision alt-text pattern; new `POST /daymark/v1/ai/transcript` endpoint, manual/author-triggered only. A mocked transcript is an empty string rather than a fabricated placeholder. ([#135](https://github.com/jeffpaul/daymark/pull/135))
- `get_image_alt_suggestion()`/`generate_vision_alt()` accept an `existing_alt` value so "Improve with AI" refines existing text rather than describing from scratch. ([#135](https://github.com/jeffpaul/daymark/pull/135))
- AI-organized galleries were considered and deliberately deferred: no manual gallery-reorder UI exists yet for an AI ordering to sit on top of — tracked in [#134](https://github.com/jeffpaul/daymark/issues/134). ([#135](https://github.com/jeffpaul/daymark/pull/135))
- `Daymark_Subscription_Source_Feed::normalize()` now also sniffs a subscribed post's content/description HTML for media (`sniff_content_media()`) when RSS enclosures carry no format signal — closing the gap where an inline `<img>`/`<video>`/`<audio>` with no `<enclosure>` always fell through to `standard`. ([#138](https://github.com/jeffpaul/daymark/pull/138))
- A microformats2 `u-photo`/`u-video`/`u-audio` class counts regardless of surrounding text length; a bare `<img>` only counts when the accompanying text is short. A full mf2 h-entry connector ([#84](https://github.com/jeffpaul/daymark/issues/84)), the WP REST API's `format` field for WP-to-WP subscriptions ([#137](https://github.com/jeffpaul/daymark/issues/137)), and ActivityPub/Microsub following ([#88](https://github.com/jeffpaul/daymark/issues/88)) are tracked separately. ([#138](https://github.com/jeffpaul/daymark/pull/138))
- CI: the Hooks Docs workflow now runs on push to `main` only, not on pull requests. The PR Playground Preview workflow no longer re-runs on every push to an open PR. ([#139](https://github.com/jeffpaul/daymark/pull/139))
- Added `GET /daymark/v1/marks/{id}/content` backing the Timeline's inline-expand panel: renders `post_content` via `apply_filters( 'the_content', ... )` in isolation, so the response is just that post's own content, never the page a permalink visit would render around it. Not gated on `_daymark_is_mark`, matching `GET /timeline`'s own inclusive query. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- `Daymark_Subscription_Poller::extract_body_html()` is hardened alongside it: `<nav>`/`<header>`/`<footer>`/`<aside>` elements are stripped wherever they appear, and the fetched page's `<article>` element is preferred over its whole `<body>` when present, with a defensive pass dropping a trailing `id="comments"`/`id="respond"` container. ([#144](https://github.com/jeffpaul/daymark/pull/144))
- Reordered every changelog section (0.1.0 through 0.9.0) to a consistent category order — Added, Changed, Deprecated, Removed, Fixed, Security, Developer — content unchanged, documented in CONTRIBUTING.md. ([#147](https://github.com/jeffpaul/daymark/pull/147))

## [0.8.0] - 2026-08-31

### Added

- Home is now the merged Timeline feed: the user's own Marks interleaved with cached posts from subscribed sites, replacing what was a Recent Marks list of only their own Marks. Opening a subscribed post fetches and shows its full content in place; pulling down from the top of the list refreshes every active subscription. ([#102](https://github.com/jeffpaul/daymark/pull/102), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
- Search now covers the whole Timeline (Marks and subscription posts) instead of only the user's own Marks, with a new Source filter next to the existing type chips to narrow it back down to "My Marks" or one specific subscribed site. ([#103](https://github.com/jeffpaul/daymark/pull/103), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
- A Settings → Daymark screen for managing subscriptions: subscribe to a site by URL, see its status and when it was last fetched, refresh it on demand, and unsubscribe. Also reachable via a new "Subscriptions" action link on the Plugins list screen. The Subscribe button shows a loading state while the request is in flight. ([#104](https://github.com/jeffpaul/daymark/pull/104), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
- Home's Recent Marks list now shows the same comment/like stat row as the public Timeline card — a zero count stays a dimmed icon-only, a real count shows next to a bolder icon. Resolves the compactness side of [#42](https://github.com/jeffpaul/daymark/issues/42) in favor of the shared visual language. ([#72](https://github.com/jeffpaul/daymark/pull/72))
- A Mark's own permalink page now carries outbound POSSE-quality microformats2 markup: `h-entry` (with `e-content`, `p-name`/`p-summary`, `dt-published`, `u-url`, and `u-photo`/`u-video`/`u-audio` for attached media) and an author `h-card` (`p-author`, `p-name`, `u-photo`). A new `rel=me` field on the native Users → Your Profile screen renders as a `rel="me"` link next to the h-card when set. Deliberately leaves out `u-email` — a WordPress account email isn't meant to be public, and it's optional in the h-card spec. (part of [#78](https://github.com/jeffpaul/daymark/issues/78))

### Changed

- For developers: `Requires PHP` is now 8.2 (was 8.1) — PHP 8.1 stopped receiving security fixes. `phpunit/phpunit` stays on `^9.6` rather than moving to 11.x: WordPress core's own PHPUnit test scaffold still calls a method PHPUnit 10 removed, so every test run under PHPUnit 10+ fails regardless of anything in this plugin. Tracked in [#106](https://github.com/jeffpaul/daymark/issues/106) for whenever core fixes it.
- For developers: `CONTRIBUTING.md`'s crediting-contributors section now says explicitly that Claude Code gets a `Co-Authored-By:` trailer too, alongside human contributors, when it wrote or materially helped write a change. ([#108](https://github.com/jeffpaul/daymark/pull/108))
- For developers: `CONTRIBUTING.md`'s release checklist now opens with a dependency update check (`npm`/`composer outdated` and `audit`, patch/minor routinely, majors held for a deliberate compatibility review) and a bundle size/tree-shaking check, before opening the release PR. ([#105](https://github.com/jeffpaul/daymark/pull/105))
- For developers: this release's dependency check found nothing to update — `npm outdated` and `composer outdated --direct` are clean apart from the already-tracked `phpunit/phpunit` hold-back (see above). `npm audit` reports 32 advisories, all in `webpack-dev-server`'s transitive chain under the `@wordpress/scripts` devDependency (local build/watch tooling only — the plugin ships no npm `dependencies` and none of this reaches the distribution zip); `composer audit` is clean. Bundle size unchanged at 778 bytes.

### Deprecated

- `Daymark_Migration` (the one-time Moment → Daymark storage conversion) is soft-deprecated ahead of removal in 0.9.0. No behavior change for anyone still upgrading from Moment (≤ 0.5.0) — sites with real legacy data to convert now also get a logged `_deprecated_function()` notice (visible under `WP_DEBUG`) at the moment the conversion runs, as a heads-up before it's removed. ([#69](https://github.com/jeffpaul/daymark/pull/69))

### Removed

- The public `/timeline` page, the `daymark/timeline` block, and the `[daymark_timeline]` shortcode. Timeline is now an interleaved, multi-source view (your own Marks plus subscribed sites' posts, via Home) that only makes sense inside the authenticated app — a public page under the same name showing something narrower was confusing and redundant. An existing install's `/timeline` page is hard-deleted on upgrade (real 404, no redirect); individual Mark permalinks, your site's RSS/Atom feed, and the other four section pages (`/images`, `/videos`, `/audio`, `/notes`) are unaffected. (part of [#78](https://github.com/jeffpaul/daymark/issues/78))

### Fixed

- A subscribed post's fetched full content no longer leaks the raw page's `<script>`/`<style>` source as visible text in the click-through detail view. `wp_kses_post()` only strips those tags, not their enclosed text, so a fetched page's tracking scripts and print styles were showing up as plain text; the fetch now narrows to the page's `<body>` and drops script/style elements entirely (tag and content) before sanitizing. ([#102](https://github.com/jeffpaul/daymark/pull/102))
- The app now only ever lives at `/daymark` even on an install that migrated from Moment *before* the 0.7.0 fix shipped. That fix only stopped a *future* migration from carrying the old base forward — a site that had already migrated kept it stuck at e.g. `/moment` forever, since that setting is deliberately never re-checked once resolved. It's now self-corrected on first use: the old value moves to the redirect (same as a fresh migration), and the "Open Daymark" link on the Installed Plugins screen and every other app URL correctly point at `/daymark`. ([#71](https://github.com/jeffpaul/daymark/pull/71))
- A Mark migrated from Moment (which never set a featured image) now shows its thumbnail in Home's Recent Marks list, the same way it already did on the public Timeline: the list reads the featured image first, then falls back to the Mark's own first image attachment, instead of only ever checking the featured image. ([#72](https://github.com/jeffpaul/daymark/pull/72))
- A generated title for a long caption with no spaces (e.g. Japanese, which `wp_trim_words()` only shortens on a CJK-translated locale) is now trimmed to a character-count backstop instead of used in full. The limit is filterable via `daymark_title_max_chars`. ([#75](https://github.com/jeffpaul/daymark/pull/75), fixes [#74](https://github.com/jeffpaul/daymark/issues/74))
- The "+ New Mark" launcher's Image/Video/Audio/Note bubbles now genuinely burst outward from the button and settle back into it, instead of mostly fading in near their own final position with a slight scale. A scroll that happens while a bubble is still mid fan-out (including one an automated click's own scroll-into-view step can trigger) no longer closes the launcher out from under itself before it's had a chance to become tappable. ([#72](https://github.com/jeffpaul/daymark/pull/72))

### Security

- A cached subscription post could previously be edited or deleted through WordPress's own generic REST API (`wp/v2/subscription-posts`), auto-registered because the post type was `show_in_rest => true` and gated only by ordinary edit/delete-post capabilities — entirely separate from, and bypassing, Daymark's own read-only routes. Nothing in the app ever used that generic endpoint; it's now disabled, so a cached copy of someone else's content can only ever be written by the subscription poller itself. ([#103](https://github.com/jeffpaul/daymark/pull/103))
- For developers: bumped `nanoid`, a transitive devDependency of `@wordpress/scripts`' bundled Lighthouse tooling, to resolve a high-severity advisory. Dev-tooling only — never invoked by this project's own build/test scripts and never shipped in the plugin zip. ([#105](https://github.com/jeffpaul/daymark/pull/105))

## [0.7.0] - 2026-08-07

### Added

- The five `daymark/*` blocks (Timeline, Images, Videos, Audio, Notes) now expose how many recent Marks they show as a setting in the block editor, instead of requiring a hand-edit of the block markup. The count control appears under Block tab → "Number of Marks" (1–50) and the editor preview updates as you drag it. ([#56](https://github.com/jeffpaul/daymark/pull/56))

### Changed

- Tapping "+ New Mark" now fans out into Image/Video/Audio/Note bubbles, Path-app style, instead of always landing on a generic composer — pick a type and the composer opens pre-set to it. The button itself shrank to a plain "+" circle, and Timeline moved from the bottom nav up into the header as a combined icon + "Daymark" home-link, freeing a slot for the new launcher among the remaining Images/Video/Audio/Notes icons. Every public view now also carries a small "← Daymark" link back into the app, since section pages render inside your theme with no app chrome of their own. The animation respects `prefers-reduced-motion`, and every icon — the launcher and its four bubbles — has a real accessible name. ([#53](https://github.com/jeffpaul/daymark/pull/53))
- For developers: the coding-standards suite now covers the test files (`composer phpcs-tests`) and checks PHP 8.1+ compatibility with the PHPCompatibility standard (`composer phpcompat`); CI runs both.

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

## [0.6.1] - 2026-08-05

### Changed

- The five `daymark/*` blocks (Timeline, Images, Videos, Audio, Notes) now support color, typography, and spacing from your theme's Global Styles, and sit under their own "Daymark" category in the block inserter instead of the generic "Widgets" bucket. The inserter's hover preview now shows the block rendered against your actual content. ([#39](https://github.com/jeffpaul/daymark/pull/39))
- Each Mark on the public views now shows a comment count and a like count (from replies and reactions the ActivityPub, ATmosphere, or Webmention plugins deliver, plus your own on-site comments). A count of zero stays quiet — just a dimmed icon, no "0" — and steps up in weight and color as soon as there's something to report. ([#43](https://github.com/jeffpaul/daymark/pull/43))
- Audio and video Marks on the public views now play inline, right in the card, instead of showing only a badge and caption; note Marks get a larger, pull-quoted caption since the text is the whole Mark. ([#44](https://github.com/jeffpaul/daymark/pull/44))
- The Home footer (New Mark button + site nav) slides out of the way while you scroll down the recent list, reclaiming its height for content, and slides back on scroll-up or when keyboard focus reaches it. The top bar is a touch more compact too. ([#50](https://github.com/jeffpaul/daymark/pull/50))

### Fixed

- `/daymark` no longer 404s on an install migrated from Moment. The app deliberately keeps its persisted URL (e.g. `/moment`) so a home-screen icon never breaks, but the new brand's own URL had nothing registered at all — it now redirects to wherever the app actually lives. ([#49](https://github.com/jeffpaul/daymark/pull/49))

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

[unreleased]: https://github.com/jeffpaul/daymark/compare/0.10.0...HEAD
[0.10.0]: https://github.com/jeffpaul/daymark/compare/0.9.0...0.10.0
[0.9.0]: https://github.com/jeffpaul/daymark/compare/0.8.0...0.9.0
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
