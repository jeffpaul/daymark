=== Daymark ===
Contributors: jeffpaul
Tags: publishing, mobile, pwa, syndication, indieweb
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.9.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Personal Site Publisher Mode for WordPress: capture, caption, and publish Marks from your phone. Your site stays the source of truth.

== Description ==

**Mission:** Make publishing to your own WordPress site feel as joyful, immediate, and effortless as posting to the best mobile social apps, while ensuring everything you create remains yours.

Daymark is a phone-first publishing experience for WordPress. A logged-in user visits `/daymark`, taps to capture a photo, video, or voice recording (or picks one already on their device), adds a caption, and publishes a standard WordPress post — the site stays the canonical source of truth.

WordPress does not need to become a social network. Daymark makes your own site the starting point for social-shaped content: publish once on your domain, syndicate outward, and let the conversation flow back.

= What you get =

* **A phone app feel** — visit `/daymark`, add it to your home screen, and publish images, videos, audio, and notes from a focused, mobile-first app shell with none of the wp-admin chrome. A persistent bottom nav gets you to Timeline, Explore, Search, and Me, with a prominent +New button always in reach. A home-screen shortcut (long-press the icon) jumps straight into the composer.
* **Camera-first** — tap Image, Video, or Audio and Daymark opens your camera or microphone directly, ready to capture. Already have the photo or clip? "Choose from library" is right there too, just not the default.
* **Share sheet integration** — on your phone, share a photo, link, or text straight to Daymark from any app (Photos, Safari, wherever). It creates a draft and opens right into the composer, ready for a caption. No need to open Daymark first.
* **Standard WordPress posts** — every Mark is a regular post with block markup. Your feeds, themes, comments, and export tools all keep working, and deactivating Daymark never strands your content.
* **Syndication routing** — choose which networks each Mark also publishes to. Daymark remembers your routing habits per content type and only offers destinations that are actually connected.
* **Conversation backflow** — replies from syndicated copies come back to your site as native WordPress comments, automatically (hourly background sync plus an opportunistic refresh when you view notifications). No manual sync step.
* **Federation friendly** — replies delivered by the ActivityPub, ATmosphere, or Webmention plugins are recognized and labeled in Daymark notifications, and Daymark renders IndieWeb `u-syndication` markup so Bridgy backfeed works out of the box.
* **POSSE-quality markup** — every Mark's own permalink page carries outbound `h-entry`/`h-card` microformats2 markup, and a `rel=me` field on your WordPress profile renders as a `rel="me"` link, so IndieWeb readers and tools recognize your site without needing Daymark's own APIs.
* **Optional AI Assist** — caption, title, alt text, tag, and transcript suggestions through the WordPress 7.0 AI Client. Any configured AI provider plugin powers all of it; no provider, no AI UI, and publishing never depends on it. Generate a transcript for an audio or video Mark and its AI-suggested caption/title read as a summary of what's actually said. "Improve with AI" refines existing alt text instead of only describing an image from scratch. Typing a tag also suggests matching tags already used on your site — tap one instead of typing the full name.
* **Autosave, online or off** — the composer saves your in-progress Mark automatically as you write, well before you tap Publish or Save as Draft. Close the tab, get a phone call, or switch apps mid-caption and your work is already waiting for you under Drafts on Home. No connection at all? Compose and publish anyway — Daymark saves it on your device and syncs it automatically the moment you're back online.
* **Tap Publish, don't wait** — Publish and Save as Draft never make you sit and watch a spinner. Your Mark is safe and you're back on Home right away; the upload finishes in the background, which matters most for a large video, a podcast episode, or a big gallery. A Pending row on Home shows what's still uploading, and disappears on its own once it's done.

= Product principles =

Daymark is built around a few clear ideas:

* **Publishing comes before editing.** Getting a Mark out the door comes first; refining it is optional and never blocks the Publish button.
* **Your site is the source of truth.** Your site is always the destination that matters — social networks are additive, never a replacement.
* **Mobile is the primary creation experience.** Daymark is designed one-handed, for a phone, first.
* **Media is first-class.** Images, video, audio, and notes each get handling suited to them, not one-size-fits-all.
* **Everything should be publishable in under 30 seconds.** Smart defaults do the work so you don't have to.
* **AI assists the creator, never replaces them.** Every AI suggestion is optional and editable, and Daymark works exactly the same without one.
* **Progressive disclosure.** Simple by default; more control is there when you want it, without cluttering the common case.
* **Every Mark is portable and future-proof.** It's a standard WordPress post — it outlives Daymark itself.

= Publishing destinations =

Your site itself is always the primary destination — social networks are strictly additive. Daymark works with the publishing plugins you already use (Jetpack Social, Share on Mastodon, ATmosphere, and more): it detects them and, where a plugin exposes a per-post control, adds an in-app on/off toggle per Mark. Replies delivered by federation plugins are recognized and labeled in notifications. An open connector interface lets any plugin register a first-class destination without changing Daymark.

= External services =

Publishing to social networks is handled by whichever publishing or federation plugin you install, and AI features go through the WordPress core AI Client and whichever provider plugin you have configured — Daymark itself talks to neither directly.

Daymark does make one outbound request of its own: when composing a Mark, if your browser's location permission is granted, Daymark quietly looks up current weather conditions for that location from [Open-Meteo](https://open-meteo.com/) (`api.open-meteo.com`), a free service that requires no account or API key. This only happens when a location was captured for that Mark, and only on publish. See "What does Daymark quietly capture, and can I turn it off?" in the FAQ for what's captured and how to disable any of it.

= AI-assisted development =

This plugin was generated with Claude Code working from the Project Daymark specification documents, with human guidance, review, and testing throughout — every build phase was gated on verification against a live WordPress site, and the test suites (PHPUnit, WP-CLI smoke, browser E2E) exist to keep that review honest. Treat it as AI-generated, human-directed software.

== Installation ==

1. Upload the `daymark` folder to `/wp-content/plugins/`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Visit `https://yoursite.example/daymark` on your phone while logged in.
4. Optional: add it to your home screen (Safari: Share → Add to Home Screen; Chrome: menu → Add to Home Screen / Install App). Standalone app display requires HTTPS.

Activation creates no public pages of its own. Timeline, Explore, Search, and Me all live inside the authenticated `/daymark` app shell.

== Frequently Asked Questions ==

= How do I publish to social networks? =

A Mark is a standard post, so any publishing plugin you already use shares it when it publishes. Daymark detects popular ones (Jetpack Social, Share on Mastodon, ATmosphere, XPoster, Autoshare for Twitter, and more) and notes them on the publish screen; for plugins that expose a per-post control it adds an in-app on/off toggle per Mark (currently Share on Mastodon, Autoshare for Twitter, and ATmosphere for Bluesky). Replies come back through federation plugins (ActivityPub, ATmosphere, Webmention) as native comments. Daymark also exposes an open connector interface (`daymark_register_connectors`) so a plugin can register a first-class destination. Your site is always the primary destination and publishing never depends on any of this.

= Why don't I see any social networks on the publish screen? =

Daymark only offers destinations that can actually publish (and pull replies back): a network appears once a connector plugin registers it. With nothing connected, "Your Site" is the only destination — publishing to your own site always works. (Publishing plugins like Jetpack Social or Share on Mastodon aren't destinations — they appear as an awareness note or a per-Mark toggle instead.)

= How do replies come back to my site? =

If you run the ActivityPub, ATmosphere, or Webmention plugins, replies they deliver arrive as native WordPress comments and are recognized and labeled in Daymark notifications ("Reply from Bluesky", "Reply from the Fediverse", …) — by push, live, with no polling. When a polling connector is registered, an hourly background sync (plus a refresh whenever you view notifications) imports replies from your syndicated copies too, deduplicated per reply.

Replying to a subscribed post works the same way, in reverse: tap "Reply" on an expanded Timeline card, write your reply, and publish it as a normal Mark. The published Mark's permalink carries a `u-in-reply-to` link to the source, and the Webmention plugin (if installed and active) notifies the source automatically the moment your reply goes live — Daymark itself never sends, receives, or verifies a Webmention, it just makes sure the markup a Webmention plugin looks for is there. For the best Daymark + IndieWeb experience, install the [Webmention plugin](https://wordpress.org/plugins/webmention/) (and ActivityPub/ATmosphere alongside it) so replies and mentions from across the web show up in your notifications automatically.

= Does Daymark work with the Friends plugin? =

Yes. If you already follow someone through the [Friends plugin](https://wordpress.org/plugins/friends/), subscribing to their site in Daymark reads their posts straight from Friends' own cache instead of independently re-fetching their site a second time — Friends already does the real fetching, parsing, and post-format classification for a friend, so Daymark just reuses it. This only ever applies to a friend you've already added in Friends' own UI; Daymark doesn't add friends on Friends' behalf, and a site Friends doesn't yet follow subscribes exactly as it always has (via its RSS/Atom feed, WordPress REST API, or microformats2 markup).

= Which AI providers work with AI Assist? =

Any WordPress AI Client provider plugin — Anthropic (Claude), Google (Gemini), or OpenAI (GPT). Daymark never talks to an AI vendor directly and never stores API keys; it goes through the core AI Client, and the first configured provider powers caption, title, alt text, tag, and transcript suggestions. Without a configured provider, the AI Assist UI simply does not appear.

= What does Daymark quietly capture, and can I turn it off? =

Composing a Mark quietly captures a few pieces of metadata in the background, without any field to fill in: the date/time it was created, your device's location (only if your browser grants permission — never a form field to fill in), current weather for that location, camera details from a photo's own EXIF data (camera model, aperture, ISO, and similar), an estimated reading time for a longer caption, and AI-suggested tags. None of it is required, none of it can block or delay publishing, and anything that isn't available (permission denied, no EXIF data, no AI provider configured, etc.) is simply left out rather than causing an error.

This is captured ahead of planned future work: showing where a Mark was made ("checkins"), its weather at the time, and richer photo details, directly in the Timeline. Location and weather aren't shown anywhere in Daymark yet — they're captured now so that display work has real data to build on rather than starting from a Timeline with nothing to show. If you'd rather this wasn't captured at all while it's still invisible, location, weather, and camera metadata can each be turned off independently with a filter in your theme or a small site-specific plugin:

`add_filter( 'daymark_capture_location', '__return_false' );`, `add_filter( 'daymark_capture_weather', '__return_false' );`, and `add_filter( 'daymark_capture_camera_metadata', '__return_false' );`

Turning off location capture also stops the weather lookup, since weather is only ever attempted alongside a resolved location; the weather filter alone leaves location capture on but skips just the weather lookup. A Mark's captured location is stored for your own site's use and is never published on its public permalink page unless you explicitly opt in with `add_filter( 'daymark_publish_location_publicly', '__return_true' );`.

= Does Daymark create a custom post type? =

No. Every Mark is a standard post with post meta, so your content is fully portable and remains intact and readable if you deactivate the plugin.

= What happens if I close the app or lose connection while composing? =

Your work is always safe. The composer autosaves your caption, media, alt text, and destination choices as you go, so a closed tab, a phone call, or switching apps doesn't lose it. With a connection, it saves straight to a real draft on your site — reopen Daymark and it's waiting under Drafts on Home. Without one, it saves to your device instead, shows up under Pending on Home, and publishes or saves itself automatically the moment you're back online — you don't need to do anything.

= Why doesn't Publish make me wait for a big video or gallery to finish uploading? =

It never does, for any Mark, whether or not there's a big upload involved. Tapping Publish (or Save as Draft) saves your Mark right away and takes you straight to the confirmation screen; the actual upload and any syndication happen in the background afterward. A Pending row on Home shows it while that's still in progress, and it moves into your normal Recent Marks (or Drafts) the moment it's done — usually fast enough that you'll never even notice, but for a large video or podcast file it's the difference between an instant tap and a long wait staring at a spinner.

= Can I share a photo to Daymark from another app? =

Yes, once you've added Daymark to your home screen (required for the share sheet to offer it as an app to share to). Share a photo, video, a link, or selected text from Photos, Safari, or almost any other app, and pick Daymark — it creates a draft with whatever you shared and opens straight into the composer so you can add a caption and publish. You need to be logged in already; the share sheet has no way to log you in first.

= Can I create a Mark while offline? =

Yes. Compose, add media, and tap Publish (or Save as Draft) with no connection at all — Daymark saves it on your device and shows the same confirmation screen either way. It publishes automatically as soon as you're back online; until then you'll find it under Pending on Home. This covers a session already open when you go offline (or start one offline); loading `/daymark` for the very first time with zero connectivity doesn't work yet — that needs a network for the initial page load.

= Does it work offline? =

Mostly, for the part that matters most: creating a Mark works fully offline once the app is open (see the previous question). A conservative service worker also caches the app's static CSS and JS for fast loading. What doesn't work offline: loading `/daymark` for the first time with no connection, and reading your Timeline, notifications, or other people's content — those still need a connection, and REST responses, nonces, HTML, and media are never cached.

== Screenshots ==

1. Home — the phone-first app shell: drafts and recent Marks in reach, one-tap publishing.
2. Create — pick media, add a caption, and get AI-suggested alt text for each image, editable before you publish.
3. Publish — your site is always the destination; file the Mark under categories (remembered per type), or save as a draft.
4. Notifications — replies from syndicated copies flow back automatically, labeled by source.

== Changelog ==

= 0.9.0 - 2026-09-02 =
**Added**

* Replaced the Images/Videos/Audio/Notes links flanking +New with a persistent bottom nav: **Timeline, Explore, +New, Search, Me**. Explore, Search, and Me are real routes (`/daymark/explore`, `/daymark/search`, `/daymark/me`), so a direct link or refresh lands correctly, and the active tab shows via `aria-current="page"`.
* **Explore**: a non-chronological browsing destination — "Browse by type" (Image/Video/Audio/Note) and "Following" (your subscriptions) hand a preset off to Search rather than duplicating it. Memories, collections, and suggested content are future sections.
* **Search**: promoted out of Home's collapsible header search bar into its own screen and nav destination, reusing the same keyword/type/source query — Home no longer has an inline search UI.
* **Me**: a minimal personal-identity screen — avatar/display name, a link into Search scoped to your own Marks, a view-only Drafts list (tap to resume editing), and links to Notifications, the wp-admin Subscriptions screen, and WordPress's own profile/logout.
* Composer autosave: your in-progress Mark (caption, media, alt text, destination, category) now saves automatically to a real draft as you compose — no manual save step, though it still requires a connection like the existing manual Save as Draft.
* A typed-but-unsent Notifications reply is similarly protected against being lost when you switch to another reply or navigate back to Notifications.
* Every Timeline item now shows its own site icon (a Mark's own avatar, or a subscribed site's icon) as its own leading element, separate from the item's thumbnail — a single click filters Timeline to that source, the same Source filter Search already offers.
* A site icon degrades gracefully to a plain letter glyph if it fails to load — a subscription's icon is sometimes an unverified guess at the site's `/favicon.ico`, which doesn't always resolve.
* Timeline cards now render by content kind instead of one uniform row, inspired by (not copied from) Path's own timeline: a type-indicator icon sits beside the site icon, threaded to the item above/below by a connecting line.
* Image, gallery, video, and mixed-media Marks get a media-dominant card (full-width photo banner, secondary caption, a play button for video, a format badge for gallery/video/mixed); audio gets a compact artwork-and-metadata row; a Note Mark stays a lightweight typography-first row and now shows its own caption text when it carries more than the title alone does.
* A subscription post's plain-text posts split into Article (longer excerpt, optional thumbnail) and Link (compact bordered mini-card, no image) — an approximation, since the feed source doesn't yet preserve real content length to tell a genuinely short post from a truncated one.
* Every media-dominant kind falls back to a branded placeholder panel, not a blank space, when there's no real photo to show — the common case for a video or audio Mark.
* Timeline now includes every published post on your site, not just ones created through Daymark's own composer — a real featured image gets the same media-dominant treatment an Image Mark gets, real body text reads as an Article card, and anything else falls back to a plain row. It carries no ⋯ Edit/Delete menu, since Daymark's composer isn't the right tool for editing block-editor content — use wp-admin for that.
* Filtering Search or Explore to one specific type (Image, Video, …) still only ever matches true Marks, since an ordinary post has no such type of its own to filter by.
* A Timeline card's relative timestamp ("Just now", "12m ago", shifting to a plain date after a week) is now a real `<time>` element carrying the exact date and time as its title — hover it, or have a screen reader announce it, for precision at any age.
* A single-image or gallery Mark's card now gets a noticeably taller photo banner (320px, up from 176px) than the other media-dominant kinds — the photo is the content for these two types, so it gets more room instead of being cropped to a modest strip.
* Tapping a Timeline card now expands its content right in place, below the card, instead of navigating you away (a Mark or your own ordinary post) or opening an overlay (a subscription post). Tap again to collapse; only one card is expanded at a time.
* What you see is just that post's own content — no comments, navigation, header, or footer pulled in along with it, whether it's a Mark, an outside post, or a subscribed site's post. A "View full post ↗"/"View original ↗" link is still there for the real page.
* Offline-first creation: compose, publish, or save a draft with no connection at all — Daymark saves it on your device and shows the same success screen as if you were online, then publishes or syncs automatically the moment you're back online. A new Pending section on Home shows what's still waiting.
* Composer autosave now falls back to the same offline saving. Covers a session already open when you go offline (or start one offline) — loading `/daymark` itself for the very first time still needs a connection.
* The AI Assist sheet's "Add a tag" field now suggests matching tags already used on your site as you type — tap one instead of typing the full name.
* Optimistic publishing: tapping Publish or Save as Draft no longer waits for the upload to finish — you're back on the confirmation screen immediately, and the Mark uploads and syndicates in the background, which matters most for a large video, a podcast episode, or a big gallery.
* A Pending row on Home shows it in progress and clears once it's done; the confirmation screen fills in the permalink and syndication status once the background upload confirms.
* Camera-first capture: tapping Image, Video, or Audio now opens your device's camera or microphone directly, ready to capture — "Choose from library instead" is still right there, just not the default.
* A new home-screen shortcut (long-press the installed icon) jumps straight into the composer, skipping Home and the +New tap.
* Share sheet integration: share a photo, link, or text to Daymark from any app on your phone — it creates a draft and opens straight into the composer with it already loaded, ready for a caption. Requires having added Daymark to your home screen.
* AI Assist can now generate a transcript for an audio or video Mark — tap "Generate transcript" in the composer. Once a Mark has a transcript, its AI-suggested caption and title are grounded in what's actually said, so "summarize podcast" falls out of the existing suggestions rather than being a separate feature.
* AI Assist can also improve, not just regenerate, an image's alt text via a new "Improve with AI"/"Suggest with AI" button. Both are exactly as optional and non-blocking as the rest of AI Assist: no configured provider means no button, and a failed request just leaves the field for manual entry.

**Changed**

* For developers: the four wordpress.org screenshots (`.wordpress-org/screenshot-*.png`) still showed the plugin's previous identity, Moment. Regenerated against a live 0.9.0 build with current Daymark branding and content.

**Removed**

* `Daymark_Migration`, the one-time storage conversion from the plugin's previous identity, Moment (≤ 0.5.0), to Daymark, deprecated since 0.7.0. **If your site still runs Moment (≤ 0.5.0), you must upgrade through an intermediate 0.6.x–0.8.x release first — jumping straight to this version will not convert your old Moment data.**
* The Images, Videos, Audio, and Notes section pages, their `daymark/*` blocks, `[daymark_*]` shortcodes, `Daymark_Renderer`, and the `@wordpress/scripts` block-editor build that existed only to power them — superseded by Explore's "Browse by type" section. **Existing pages move to Trash (not hard-deleted) on upgrade**, and old URLs 301 to `/daymark/explore` instead of a bare 404.

**Fixed**

* For developers: `uninstall.php` now cleans up everything the Subscriptions feature and the POSSE microformats2 work added — the `daymark_subscriptions` table, cached content, the poller's cron event, related options/user meta, and rate-limiter transients.
* For developers: the SPA router now tears down the previous screen's outside-click/Escape dismiss listeners before rendering the next one, instead of leaving them attached to `document`. Not user-visible today, but closes off a failure mode for a future screen.
* Several tappable controls were sized below the app shell's own documented 44px minimum tap target: the Timeline item's ⋯ menu trigger, the site icon filter button, the tag remove ("×") button, the Notifications reply screen's Send button, the ⋯ menu's delete-confirmation buttons, and the Search screen's filter chips. All now size off the shared `--daymark-tap-min` token.
* Subscribing to a site captured its feed `<link>` tag's own title — commonly WordPress's default `"{Site Name} » Feed"` convention — instead of the plain site name. Now reads the site's own `<title>` tag; the old value is kept as a new `feed_title` column for a future multi-feed subscription.

**Developer**

* Added product principles documentation.
* Added design principles documentation.
* Added a Mission statement as the authoritative lens above the product principles, in CLAUDE.md, README.md, and readme.txt.
* Composer autosave requests (`autosave=1`) use a new, independent `Daymark_Rate_Limiter::ACTION_AUTOSAVE` bucket rather than `ACTION_PUBLISH`, so background autosave activity can never exhaust the budget for a real Publish/Save as Draft tap.
* Audited the app shell against "gesture-friendly, large touch targets, comfortable thumb reach, minimal text entry" and documented it as a named commitment.
* Added `GET /daymark/v1/tags` (existing `post_tag` terms matching a search string) backing the new tag autocomplete.
* `publishInBackground()`/`syncPendingMark()` extend the offline-first-creation IndexedDB pending queue to a queue-first, always-on mechanism for a deliberate Publish/Save-as-Draft tap, rather than only a connectivity-failure fallback.
* The pending-record schema gained a `status` field (`uploading`/`queued`/`error`) so `renderPendingItem()` can show which is true instead of every pending item saying "Offline".
* Added a `shortcuts` entry to the PWA manifest pointing at `#create`. `share_target` was considered and deliberately deferred — tracked in [#131](https://github.com/jeffpaul/daymark/issues/131) — since it needs its own service-worker scope decision.
* Added `share_target` to the PWA manifest and a new `{base}/share` route (`Daymark_Share_Target`) receiving the OS share-sheet POST directly — no service-worker involvement needed, since a share-target delivery is a real top-level navigation the server can handle like any other form submission.
* No REST nonce is possible from an OS-originated request; relies on WordPress's SameSite=Lax auth cookie plus a `Sec-Fetch-Site` check as defense in depth.
* `Daymark_AI_Assist::get_transcript_suggestion()`/`generate_transcript()` add audio transcription, mirroring the existing vision alt-text pattern; new `POST /daymark/v1/ai/transcript` endpoint, manual/author-triggered only. A mocked transcript is an empty string rather than a fabricated placeholder.
* `get_image_alt_suggestion()`/`generate_vision_alt()` accept an `existing_alt` value so "Improve with AI" refines existing text rather than describing from scratch.
* AI-organized galleries were considered and deliberately deferred: no manual gallery-reorder UI exists yet for an AI ordering to sit on top of — tracked in [#134](https://github.com/jeffpaul/daymark/issues/134).
* `Daymark_Subscription_Source_Feed::normalize()` now also sniffs a subscribed post's content/description HTML for media (`sniff_content_media()`) when RSS enclosures carry no format signal — closing the gap where an inline `<img>`/`<video>`/`<audio>` with no `<enclosure>` always fell through to `standard`.
* A microformats2 `u-photo`/`u-video`/`u-audio` class counts regardless of surrounding text length; a bare `<img>` only counts when the accompanying text is short. A full mf2 h-entry connector ([#84](https://github.com/jeffpaul/daymark/issues/84)), the WP REST API's `format` field for WP-to-WP subscriptions ([#137](https://github.com/jeffpaul/daymark/issues/137)), and ActivityPub/Microsub following ([#88](https://github.com/jeffpaul/daymark/issues/88)) are tracked separately.
* CI: the Hooks Docs workflow now runs on push to `main` only, not on pull requests. The PR Playground Preview workflow no longer re-runs on every push to an open PR.
* Added `GET /daymark/v1/marks/{id}/content` backing the Timeline's inline-expand panel: renders `post_content` via `apply_filters( 'the_content', ... )` in isolation, so the response is just that post's own content, never the page a permalink visit would render around it. Not gated on `_daymark_is_mark`, matching `GET /timeline`'s own inclusive query.
* `Daymark_Subscription_Poller::extract_body_html()` is hardened alongside it: `<nav>`/`<header>`/`<footer>`/`<aside>` elements are stripped wherever they appear, and the fetched page's `<article>` element is preferred over its whole `<body>` when present, with a defensive pass dropping a trailing `id="comments"`/`id="respond"` container.
* Reordered every changelog section (0.1.0 through 0.9.0) to a consistent category order — Added, Changed, Deprecated, Removed, Fixed, Security, Developer — content unchanged, documented in CONTRIBUTING.md.

= 0.8.0 - 2026-08-31 =

**Added**

* Home is now the merged Timeline feed: the user's own Marks interleaved with cached posts from subscribed sites, replacing what was a Recent Marks list of only their own Marks. Opening a subscribed post fetches and shows its full content in place; pulling down from the top of the list refreshes every active subscription. ([#102](https://github.com/jeffpaul/daymark/pull/102), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
* Search now covers the whole Timeline (Marks and subscription posts) instead of only the user's own Marks, with a new Source filter next to the existing type chips to narrow it back down to "My Marks" or one specific subscribed site. ([#103](https://github.com/jeffpaul/daymark/pull/103), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
* A Settings → Daymark screen for managing subscriptions: subscribe to a site by URL, see its status and when it was last fetched, refresh it on demand, and unsubscribe. Also reachable via a new "Subscriptions" action link on the Plugins list screen. The Subscribe button shows a loading state while the request is in flight. ([#104](https://github.com/jeffpaul/daymark/pull/104), part of [#78](https://github.com/jeffpaul/daymark/issues/78))
* Home's Recent Marks list now shows the same comment/like stat row as the public Timeline card — a zero count stays a dimmed icon-only, a real count shows next to a bolder icon. Resolves the compactness side of [#42](https://github.com/jeffpaul/daymark/issues/42) in favor of the shared visual language.
* A Mark's own permalink page now carries outbound POSSE-quality microformats2 markup: `h-entry` (with `e-content`, `p-name`/`p-summary`, `dt-published`, `u-url`, and `u-photo`/`u-video`/`u-audio` for attached media) and an author `h-card` (`p-author`, `p-name`, `u-photo`). A new `rel=me` field on the native Users → Your Profile screen renders as a `rel="me"` link next to the h-card when set. Deliberately leaves out `u-email` — a WordPress account email isn't meant to be public, and it's optional in the h-card spec. (part of [#78](https://github.com/jeffpaul/daymark/issues/78))

**Changed**

* For developers: `Requires PHP` is now 8.2 (was 8.1) — PHP 8.1 stopped receiving security fixes. `phpunit/phpunit` stays on `^9.6` rather than moving to 11.x: WordPress core's own PHPUnit test scaffold still calls a method PHPUnit 10 removed, so every test run under PHPUnit 10+ fails regardless of anything in this plugin. Tracked in [#106](https://github.com/jeffpaul/daymark/issues/106) for whenever core fixes it.
* For developers: `CONTRIBUTING.md`'s crediting-contributors section now says explicitly that Claude Code gets a `Co-Authored-By:` trailer too, alongside human contributors, when it wrote or materially helped write a change.
* For developers: `CONTRIBUTING.md`'s release checklist now opens with a dependency update check (`npm`/`composer outdated` and `audit`, patch/minor routinely, majors held for a deliberate compatibility review) and a bundle size/tree-shaking check, before opening the release PR.
* For developers: this release's dependency check found nothing to update — `npm outdated` and `composer outdated --direct` are clean apart from the already-tracked `phpunit/phpunit` hold-back (see above). `npm audit` reports 32 advisories, all in `webpack-dev-server`'s transitive chain under the `@wordpress/scripts` devDependency (local build/watch tooling only — the plugin ships no npm `dependencies` and none of this reaches the distribution zip); `composer audit` is clean. Bundle size unchanged at 778 bytes.

**Deprecated**

* `Daymark_Migration` (the one-time Moment → Daymark storage conversion) is soft-deprecated ahead of removal in 0.9.0. No behavior change for anyone still upgrading from Moment (≤ 0.5.0) — sites with real legacy data to convert now also get a logged `_deprecated_function()` notice (visible under `WP_DEBUG`) at the moment the conversion runs, as a heads-up before it's removed.

**Removed**

* The public `/timeline` page, the `daymark/timeline` block, and the `[daymark_timeline]` shortcode. Timeline is now an interleaved, multi-source view (your own Marks plus subscribed sites' posts, via Home) that only makes sense inside the authenticated app — a public page under the same name showing something narrower was confusing and redundant. An existing install's `/timeline` page is hard-deleted on upgrade (real 404, no redirect); individual Mark permalinks, your site's RSS/Atom feed, and the other four section pages (`/images`, `/videos`, `/audio`, `/notes`) are unaffected. (part of [#78](https://github.com/jeffpaul/daymark/issues/78))

**Fixed**

* A subscribed post's fetched full content no longer leaks the raw page's `<script>`/`<style>` source as visible text in the click-through detail view. `wp_kses_post()` only strips those tags, not their enclosed text, so a fetched page's tracking scripts and print styles were showing up as plain text; the fetch now narrows to the page's `<body>` and drops script/style elements entirely (tag and content) before sanitizing.
* The app now only ever lives at `/daymark` even on an install that migrated from Moment *before* the 0.7.0 fix shipped. That fix only stopped a *future* migration from carrying the old base forward — a site that had already migrated kept it stuck at e.g. `/moment` forever, since that setting is deliberately never re-checked once resolved. It's now self-corrected on first use: the old value moves to the redirect (same as a fresh migration), and the "Open Daymark" link on the Installed Plugins screen and every other app URL correctly point at `/daymark`.
* A Mark migrated from Moment (which never set a featured image) now shows its thumbnail in Home's Recent Marks list, the same way it already did on the public Timeline: the list reads the featured image first, then falls back to the Mark's own first image attachment, instead of only ever checking the featured image.
* A generated title for a long caption with no spaces (e.g. Japanese, which `wp_trim_words()` only shortens on a CJK-translated locale) is now trimmed to a character-count backstop instead of used in full. The limit is filterable via `daymark_title_max_chars`. ([#75](https://github.com/jeffpaul/daymark/pull/75), fixes [#74](https://github.com/jeffpaul/daymark/issues/74))
* The "+ New Mark" launcher's Image/Video/Audio/Note bubbles now genuinely burst outward from the button and settle back into it, instead of mostly fading in near their own final position with a slight scale. A scroll that happens while a bubble is still mid fan-out (including one an automated click's own scroll-into-view step can trigger) no longer closes the launcher out from under itself before it's had a chance to become tappable.

**Security**

* A cached subscription post could previously be edited or deleted through WordPress's own generic REST API (`wp/v2/subscription-posts`), auto-registered because the post type was `show_in_rest => true` and gated only by ordinary edit/delete-post capabilities — entirely separate from, and bypassing, Daymark's own read-only routes. Nothing in the app ever used that generic endpoint; it's now disabled, so a cached copy of someone else's content can only ever be written by the subscription poller itself.
* For developers: bumped `nanoid`, a transitive devDependency of `@wordpress/scripts`' bundled Lighthouse tooling, to resolve a high-severity advisory. Dev-tooling only — never invoked by this project's own build/test scripts and never shipped in the plugin zip.

= 0.7.0 - 2026-08-07 =

**Added**

* The five `daymark/*` blocks (Timeline, Images, Videos, Audio, Notes) now expose how many recent Marks they show as a setting in the block editor, instead of requiring a hand-edit of the block markup. The count control appears under Block tab → "Number of Marks" (1–50) and the editor preview updates as you drag it.

**Changed**

* Tapping "+ New Mark" now fans out into Image/Video/Audio/Note bubbles, Path-app style, instead of always landing on a generic composer — pick a type and the composer opens pre-set to it. The button itself shrank to a plain "+" circle, and Timeline moved from the bottom nav up into the header as a combined icon + "Daymark" home-link, freeing a slot for the new launcher among the remaining Images/Video/Audio/Notes icons. Every public view now also carries a small "← Daymark" link back into the app, since section pages render inside your theme with no app chrome of their own. The animation respects `prefers-reduced-motion`, and every icon — the launcher and its four bubbles — has a real accessible name.
* For developers: the coding-standards suite now covers the test files (`composer phpcs-tests`) and checks PHP 8.1+ compatibility with the PHPCompatibility standard (`composer phpcompat`); CI runs both.

**Fixed**

* The app now only ever lives at `/daymark` (or `/daymark-app` when that slug is already taken by real site content), even on an install migrated from Moment. Since 0.6.1, a migrated install kept serving the app at its old `/moment` URL, with `/daymark` merely redirecting there — now it's the other way around: `/daymark` is the real app, and `/moment` (and any home-screen icon already pointing at it) 301s to it instead.
* Search and a notification's reply box now dismiss the same way the per-item menu and the "+ New Mark" launcher already do: tapping outside them, or pressing Escape, closes them and returns keyboard focus to their own toggle. Escape now works no matter which control inside search has focus, not only the text field itself.
* The composer's title-field "ⓘ" hint follows suit too: an outside tap or Escape closes it and returns focus to the ⓘ button.

**Security**

* Editing a Mark's alt text is now scoped to that Mark's own media — an ID-mapped alt edit can no longer be aimed at an image that belongs to a different post.
* Expensive actions are now rate limited per user: AI Assist requests, publishing, and manual response syncs. Over the limit, Daymark asks you to wait a moment instead of processing (limits are configurable via the `daymark_rate_limits` filter).
* Uploads are now capped per request as well as per file, so many files can't be combined to bypass the 50 MB per-file cap (itself now filterable via `daymark_upload_max_bytes`). The combined upload limit is 200 MB, filterable via `daymark_upload_total_max_bytes`.
* Manual response syncs for real connector references now honor the same per-post cooldown as automatic backflow (with an atomic lock so overlapping syncs can't double-poll), while mocked demo syncs stay instant and repeat-safe.
* The app shell now sends a conservative Content-Security-Policy header, filterable via `daymark_app_content_security_policy`. Its inline bootstrap script is nonce-scoped rather than relying on `'unsafe-inline'`, so an injected `<script>` tag has no way to execute even if something else on the page were compromised.
* Imported social replies can be routed through moderation: the `daymark_comment_import_approved` filter decides whether an imported reply is approved.
* AI Assist now treats your draft text strictly as data — instructions hidden inside a caption or filename can't redirect the model. Draft text and filenames are wrapped in an explicit data boundary in the prompt itself, and AI-generated captions, titles, and alt text are now hard-capped server-side rather than only requested via the prompt.

= 0.6.1 - 2026-08-05 =

**Changed**

* The five `daymark/*` blocks (Timeline, Images, Videos, Audio, Notes) now support color, typography, and spacing from your theme's Global Styles, and sit under their own "Daymark" category in the block inserter instead of the generic "Widgets" bucket. The inserter's hover preview now shows the block rendered against your actual content.
* Each Mark on the public views now shows a comment count and a like count (from replies and reactions the ActivityPub, ATmosphere, or Webmention plugins deliver, plus your own on-site comments). A count of zero stays quiet — just a dimmed icon, no "0" — and steps up in weight and color as soon as there's something to report.
* Audio and video Marks on the public views now play inline, right in the card, instead of showing only a badge and caption; note Marks get a larger, pull-quoted caption since the text is the whole Mark.
* The Home footer (New Mark button + site nav) slides out of the way while you scroll down the recent list, reclaiming its height for content, and slides back on scroll-up or when keyboard focus reaches it. The top bar is a touch more compact too.

**Fixed**

* `/daymark` no longer 404s on an install migrated from Moment. The app deliberately keeps its persisted URL (e.g. `/moment`) so a home-screen icon never breaks, but the new brand's own URL had nothing registered at all — it now redirects to wherever the app actually lives.

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

= 0.9.0 =
A redesigned bottom navigation (Timeline, Explore, Search, Me) replaces the old Images/Videos/Audio/Notes pages — existing pages move to Trash automatically and old links redirect to Explore. Composer autosave, offline-first creation, and optimistic publishing mean your work is never lost and Publish never makes you wait. Timeline cards now render by content kind and expand in place instead of navigating away or opening an overlay. If your site still runs Moment (<= 0.5.0), upgrade through an intermediate 0.6.x-0.8.x release first — this version can no longer convert old Moment data.

= 0.8.0 =
Home is now a merged Timeline: subscribe to any site's RSS/Atom feed by URL (Settings → Daymark), interleaved with your own Marks and searchable together. The public /timeline page is removed. Marks now carry outbound h-entry/h-card markup, plus a rel=me profile field.

= 0.7.0 =
Security hardening (rate limiting, an alt-text access-control fix, upload caps, a stricter CSP, AI prompt-injection defenses), a new Path-style launcher and header/footer redesign, a fix so the app only lives at /daymark on migrated installs, and consistent outside-click/Escape dismissal.

= 0.6.1 =
Fixes a 404 at /daymark on installs migrated from Moment. Also adds comment/like counts and inline audio/video/note previews to the public views, Global Styles support for the daymark/* blocks, and an auto-hiding Home footer.

= 0.6.0 =
The plugin is renamed from Moment to Daymark (posts are now called Marks) — required by wordpress.org review. Every identifier changed with no back-compat bridge, but existing installs migrate automatically on update: your app URL, content, and settings all carry over untouched.

= 0.5.0 =
Adds an optional AI-assisted Title field for audio/video Moments, header search with type filters, infinite scroll, per-item edit/delete, and inline notification replies. Removes the podcast type (now just an audio/video Moment) and switches the app to the designed brand icon.

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
