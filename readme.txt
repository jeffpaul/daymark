=== Daymark ===
Contributors: jeffpaul
Tags: publishing, mobile, pwa, syndication, indieweb
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Personal Site Publisher Mode for WordPress: capture, caption, and publish Marks from your phone. Your site stays the source of truth.

== Description ==

Daymark is a phone-first publishing experience for WordPress. A logged-in user visits `/daymark`, picks media from the camera roll, adds a caption, and publishes a standard WordPress post — the site stays the canonical source of truth.

WordPress does not need to become a social network. Daymark makes your own site the starting point for social-shaped content: publish once on your domain, syndicate outward, and let the conversation flow back.

= What you get =

* **A phone app feel** — visit `/daymark`, add it to your home screen, and publish images, videos, audio, and notes from a focused, mobile-first app shell with none of the wp-admin chrome.
* **Standard WordPress posts** — every Mark is a regular post with block markup. Your feeds, themes, comments, and export tools all keep working, and deactivating Daymark never strands your content.
* **Syndication routing** — choose which networks each Mark also publishes to. Daymark remembers your routing habits per content type and only offers destinations that are actually connected.
* **Conversation backflow** — replies from syndicated copies come back to your site as native WordPress comments, automatically (hourly background sync plus an opportunistic refresh when you view notifications). No manual sync step.
* **Federation friendly** — replies delivered by the ActivityPub, ATmosphere, or Webmention plugins are recognized and labeled in Daymark notifications, and Daymark renders IndieWeb `u-syndication` markup so Bridgy backfeed works out of the box.
* **Optional AI Assist** — caption, alt text, and tag suggestions through the WordPress 7.0 AI Client. Any configured AI provider plugin powers all of it; no provider, no AI UI, and publishing never depends on it.
* **Blocks and shortcodes** — timeline and per-type views are available as both `[daymark_*]` shortcodes and `daymark/*` blocks, rendering identical output.

= Publishing destinations =

Your site itself is always the primary destination — social networks are strictly additive. Daymark works with the publishing plugins you already use (Jetpack Social, Share on Mastodon, ATmosphere, and more): it detects them and, where a plugin exposes a per-post control, adds an in-app on/off toggle per Mark. Replies delivered by federation plugins are recognized and labeled in notifications. An open connector interface lets any plugin register a first-class destination without changing Daymark.

= External services =

This plugin does not send data to any external service on its own. Publishing to social networks is handled by whichever publishing or federation plugin you install, and AI features go through the WordPress core AI Client and whichever provider plugin you have configured.

= AI-assisted development =

This plugin was generated with Claude Code working from the Project Daymark specification documents, with human guidance, review, and testing throughout — every build phase was gated on verification against a live WordPress site, and the test suites (PHPUnit, WP-CLI smoke, browser E2E) exist to keep that review honest. Treat it as AI-generated, human-directed software.

== Installation ==

1. Upload the `daymark` folder to `/wp-content/plugins/`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Visit `https://yoursite.example/daymark` on your phone while logged in.
4. Optional: add it to your home screen (Safari: Share → Add to Home Screen; Chrome: menu → Add to Home Screen / Install App). Standalone app display requires HTTPS.

Activation creates section pages (`/timeline`, `/images`, `/videos`, `/audio`, `/notes`) that render your Marks inside your theme.

== Frequently Asked Questions ==

= How do I publish to social networks? =

A Mark is a standard post, so any publishing plugin you already use shares it when it publishes. Daymark detects popular ones (Jetpack Social, Share on Mastodon, ATmosphere, XPoster, Autoshare for Twitter, and more) and notes them on the publish screen; for plugins that expose a per-post control it adds an in-app on/off toggle per Mark (currently Share on Mastodon, Autoshare for Twitter, and ATmosphere for Bluesky). Replies come back through federation plugins (ActivityPub, ATmosphere, Webmention) as native comments. Daymark also exposes an open connector interface (`daymark_register_connectors`) so a plugin can register a first-class destination. Your site is always the primary destination and publishing never depends on any of this.

= Why don't I see any social networks on the publish screen? =

Daymark only offers destinations that can actually publish (and pull replies back): a network appears once a connector plugin registers it. With nothing connected, "Your Site" is the only destination — publishing to your own site always works. (Publishing plugins like Jetpack Social or Share on Mastodon aren't destinations — they appear as an awareness note or a per-Mark toggle instead.)

= How do replies come back to my site? =

If you run the ActivityPub, ATmosphere, or Webmention plugins, replies they deliver arrive as native WordPress comments and are recognized and labeled in Daymark notifications ("Reply from Bluesky", "Reply from the Fediverse", …) — by push, live, with no polling. When a polling connector is registered, an hourly background sync (plus a refresh whenever you view notifications) imports replies from your syndicated copies too, deduplicated per reply.

= Which AI providers work with AI Assist? =

Any WordPress AI Client provider plugin — Anthropic (Claude), Google (Gemini), or OpenAI (GPT). Daymark never talks to an AI vendor directly and never stores API keys; it goes through the core AI Client, and the first configured provider powers caption, alt text, and tag suggestions. Without a configured provider, the AI Assist UI simply does not appear.

= Does Daymark create a custom post type? =

No. Every Mark is a standard post with post meta, so your content is fully portable and remains intact and readable if you deactivate the plugin.

= Does it work offline? =

Partially. A conservative service worker caches only the app's static CSS and JS for fast loading. It never caches REST responses, nonces, HTML, or media, and there is no offline publishing mode.

== Screenshots ==

1. Home — the phone-first app shell: drafts and recent Marks in reach, one-tap publishing.
2. Create — pick media, add a caption, and get AI-suggested alt text for each image, editable before you publish.
3. Publish — your site is always the destination; file the Mark under categories (remembered per type), or save as a draft.
4. Notifications — replies from syndicated copies flow back automatically, labeled by source.
5. Timeline — Marks rendered inside your theme, via shortcode or block.

== Changelog ==

= 0.6.0 - 2026-08-04 =
**Changed**

* Moment is now **Daymark**, and your posts are now called **Marks** — the wordpress.org plugin review team did not approve the name "Moment" and approved "Daymark". The app lives at `/daymark` on new installs; existing installs keep their current app URL, so a home-screen icon keeps working.
* Existing installs are converted automatically: a one-time migration renames the stored options, per-user preferences, post and comment meta, and the section pages' blocks/shortcodes in place. Nothing is lost and nothing needs to be done by hand. (The migration will be retired in a later release.)
* For developers: every public identifier is renamed with no back-compat bridges — REST is `daymark/v1` with a `marks` resource, blocks are `daymark/*`, shortcodes `[daymark_*]`, hooks and meta use the `daymark_`/`_daymark_*` prefixes (the post marker is `_daymark_is_mark`), and PHP classes are `Daymark_*`.

= 0.5.0 - 2026-08-03 =

**Added**

* Optional Title field for audio and video Moments: those types read well with a title, so the composer offers one — pre-filled by AI from your caption when a provider is connected, editable, and optional (leave it blank and Moment derives the title from your text, as before). Which types show the field is filterable via the `moment_title_field_policy` filter.
* Search and filter your Moments from Home: a search icon in the header expands a search box, with type-filter chips for Images, Videos, Audio, and Notes; the list heading reads "Results" while you search.
* Manage a Moment straight from the list: a per-item menu edits a published Moment in the composer or deletes it (with a confirmation step; delete moves the Moment to Trash).
* Reply to a notification inline: each notification has a reply icon that opens a reply box and posts your reply as a comment on the Moment.
* A 50 MB per-file upload cap, with a clear message when a file is too large.
* For developers: new REST endpoints (`DELETE /moments/{id}`, `POST /notifications/{id}/reply`, `type`/`s` filters on `GET /moments`, and `POST /ai/title`), the `moment_title_field_policy` filter, and `backflow_supported` documented on the connector interface.

**Changed**

* Infinite scroll on the recent Moments list replaces the manual "view more" step, and the site-views navigation stays anchored at the bottom.
* The app, PWA, and browser-tab icons now use Moment's designed brand mark.

**Removed**

* The `podcast` Moment type: a podcast is simply an audio (or video) Moment. Use a Category if you want to label one as a podcast.

= 0.4.0 - 2026-07-29 =

**Added**

* Per-image alt text: every image in an image, gallery, or mixed Moment gets its own alt field in the composer. When an AI provider is connected, each image's alt is generated from the image itself (vision) and pre-filled for you to edit before publishing.
* Categories: a "File under" picker on the publish screen files a Moment under your existing categories, remembers the choice per Moment type, and lets you change it per Moment. Shown only when your site has categories beyond its default.
* ATmosphere joins the third-party publishing toggles, giving Bluesky a per-Moment on/off control alongside Share on Mastodon and Autoshare for Twitter.
* Success screen: an inline "(view)" link next to "Published to your site" and a "View all moments" link back home.
* Project health: LICENSE (GPL-2.0-or-later), contributing guide, code of conduct, issue and pull-request templates, and a dependency license-review check.

**Changed**

* iOS home-screen polish: the app now uses your Site Icon (or Moment's) as its home-screen icon, the bottom navigation sits flush with the bottom of the screen, and the Publish and Save as Draft buttons are spaced apart.

**Removed**

* The bundled Bluesky and Mastodon connector plugins. Moment now works with your existing publishing plugins — detected on the publish screen, with per-Moment toggles where a plugin supports it — and replies still flow back via ActivityPub, ATmosphere, and Webmention. The `moment_register_connectors` hook remains for any plugin that wants to add a first-class destination.

= 0.3.0 - 2026-07-22 =

**Added**

* Awareness for other publishing plugins: when Jetpack Social, ATmosphere, Autoblue, Share on Mastodon, XPoster, Autoshare for Twitter, Blog2Social, Social Networks Auto-Poster (SNAP), or Revive Old Posts is active, the publish screen notes that your Moment will also go out through it. Detection only — Moment never drives or configures them — and it is extensible via the `moment_publish_helper_plugins` filter.

**Changed**

* Site-views nav (Timeline/Images/Videos/Audio/Notes) now uses icons, with each label kept as the hover tooltip and the accessible name.
* Recent Moments caps at five, with a "View more" link to your timeline once there are more.
* Each Moment now gets a post format matching its type (image → Image, note → Aside, …) instead of inheriting the site's default format.
* Publishing shows the loading state on the button itself; both Publish and Save as Draft disable while a publish is in flight.
* The Drafts section is hidden when there are no drafts.

**Fixed**

* "Publishing a Image Moment" now reads "Publishing an Image Moment".

= 0.2.0 - 2026-07-16 =

**Added**

* Save as Draft: start a Moment now, finish it later. Drafts store your selected destinations and never syndicate until published.
* Continue editing: tap a draft on Home to reopen the composer with its caption, media, and destinations restored; publishing an edited draft (from the app or wp-admin) runs the stored destinations.
* Drafts row on Home keeps drafts reachable no matter how many Moments publish after them; draft rows carry a Draft chip.
* Unread indicator on the notifications bell — a simple dot, cleared by viewing notifications.
* "Open Moment" action link on the Plugins screen for a one-click path into the app.

**Changed**

* The + New Moment button moved to the bottom of the screen for one-handed reach.
* Section pages are created with Moment blocks, so block themes edit them natively; shortcodes remain fully supported.

**Fixed**

* Slug collisions are handled instead of silently shadowed: existing pages keep /timeline (etc.) while Moment views fall back to prefixed slugs, and a site with content at /moment gets the app at /moment-app — app links always point at the real locations.
* The PWA manifest is served dynamically for the resolved app path, without a redirect hop, and no longer hardcodes the wp-content path.

= 0.1.1 - 2026-07-15 =

**Changed**

* App shell CSS/JS now load through the WordPress enqueue API (registered handles, inline bootstrap config via `wp_add_inline_script`, defer strategy).
* Reworded the plugin description per wordpress.org review guidelines.

**Security**

* Tightened REST API capability checks: draft Moments list only for users who can edit them, notifications are scoped to Moments the current user can edit, syncing responses requires `edit_post` on the target, and attaching media requires `upload_files`.

= 0.1.0 - 2026-07-10 =

**Added**

* Initial release.
* Phone-first `/moment` app shell with Home, Create, Publish, and Notifications screens; PWA manifest and home-screen support.
* Publishing pipeline creating standard WordPress posts with block markup for image, video, audio, note, gallery, and mixed Moments.
* REST API under `/wp-json/moment/v1/` (moments, AI suggestions, response sync, notifications).
* Syndication connector registry with per-type routing defaults, per-user destination memory, and connected-only destination visibility.
* Automatic conversation backflow: hourly sync plus on-view freshen, importing replies as native WordPress comments.
* Federation integration: labeled backflow from the ActivityPub, ATmosphere, and Webmention plugins; IndieWeb u-syndication markup for Bridgy backfeed.
* Optional AI Assist (captions, alt text, tags) via the WordPress 7.0 AI Client.
* Timeline and per-type views as both shortcodes and dynamic blocks.

== Upgrade Notice ==

= 0.6.0 =
The plugin is renamed from Moment to Daymark (posts are now called Marks) — required by wordpress.org review. Every identifier changed with no back-compat bridge, but existing installs migrate automatically on update: your app URL, content, and settings all carry over untouched.

= 0.5.0 =
Adds an optional AI-assisted Title field for audio/video Moments, header search with type filters, infinite scroll, per-item edit/delete and inline notification replies, and a 50 MB upload cap. Removes the podcast type (a podcast is an audio/video Moment) and switches the app to the designed brand icon.

= 0.4.0 =
Adds per-image (AI-assisted) alt text and a per-type category picker, an ATmosphere publish toggle, iOS home-screen polish, and project health files. Removes the bundled Bluesky/Mastodon connector plugins in favor of your existing publishing plugins.

= 0.3.0 =
Notes active social-publishing plugins on the publish screen, icon-based site nav, per-type post formats, and publish-UI polish.

= 0.2.0 =
Adds drafts (save, resume, deferred syndication), an unread notifications indicator, and collision-safe app and section-page URLs.

= 0.1.1 =
Tightens REST API capability checks and moves app assets to the WordPress enqueue API.

= 0.1.0 =
Initial release.
