=== Daymark ===
Contributors:      jeffpaul
Tags:              publishing, mobile, pwa, syndication, indieweb
Requires at least: 7.0
Tested up to:      7.1
Requires PHP:      8.2
Stable tag:        0.15.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Publish to your own site as fast as you'd post to a social app — photos, videos, voice notes, and quick thoughts, all from your phone, all truly yours.

== Description ==

Your phone is full of moments worth sharing. Daymark makes your own WordPress site the fastest, most natural place to share them — no app store, no algorithm, no platform that can change the rules on you tomorrow.

Open Daymark on your phone, tap to capture a photo, a video, or a quick voice note (or pick one you already took), add a caption, and publish. That's it. What you publish lives on your own site, under your own name, for as long as you want it there.

= Why people love publishing with Daymark =

* **It feels like your favorite social app — because your site deserves to.** Add Daymark to your phone's home screen and it opens like a real app: fast, focused, and built for one-handed use. No admin menus, no clutter — just capture, caption, and go.
* **Your camera is one tap away.** Choose Photo, Video, or Audio and Daymark opens your camera or microphone right away, ready to capture the moment. Already have the shot? Grabbing it from your library is just as easy.
* **Share to Daymark from anywhere on your phone.** Found something worth posting in Photos, Safari, or any other app? Use your phone's own Share button and send it straight to Daymark — it's waiting for your caption before you've even opened the app.
* **You never lose your work.** Start a caption, get interrupted, lose your signal on the subway — Daymark quietly saves everything as you go. Publish with no connection at all and it goes out the moment you're back online. Nothing you write is ever at risk of disappearing.
* **Tap Publish and move on with your day.** You're never stuck staring at a spinner, even for a big video or a whole gallery of photos — Daymark confirms instantly and finishes the upload quietly in the background.
* **It's genuinely yours, for good.** Everything you publish is a real WordPress post, not a locked-in, proprietary format. Your content works with your theme, your feeds, your backups — and stays exactly where it is even if you ever stop using Daymark.
* **Reach further, without extra work.** Already use a plugin to share to Bluesky, Mastodon, or elsewhere? Daymark works alongside it, and lets you choose per-post where each one of your posts should also go. Replies from those networks flow back to you automatically, right inside Daymark.
* **A helping hand, never a replacement for yours.** Ask for a suggested caption, title, or tags, or let Daymark describe a photo for accessibility — every suggestion is yours to accept, edit, or ignore, and Daymark works exactly the same with none of it turned on.

= Your site, your rules =

Daymark never asks you to choose between "easy" and "yours." Your own site is always where a post lives first; anywhere else it appears is a bonus, never a requirement. There's no subscription, no account to create anywhere else, and nothing about what you publish depends on a company staying in business or an app staying in the store.

= A note on privacy and external services =

Daymark is built to talk to as few outside services as possible, and never without a clear reason tied to something you did. Sharing to other networks happens only through publishing plugins you choose to install yourself — Daymark doesn't talk to any social network directly. Optional AI suggestions go through WordPress's own AI tools and whichever provider you've configured — Daymark never sees or stores an API key of its own.

The one exception: if you allow location access while composing a post, Daymark makes a single, free, no-account-needed weather lookup for that location (via [Open-Meteo](https://open-meteo.com/)) so a future version can show it alongside your post. You're always in control — see "What does Daymark quietly capture, and can I turn it off?" below to see exactly what's captured and to turn any of it off.

= Openly built =

Daymark's code and its full design history are public on [GitHub](https://github.com/jeffpaul/daymark), including an unusually candid record of every decision along the way — built with the help of AI coding tools, under ongoing human direction, review, and testing.

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

Replying to a subscribed post works the same way, in reverse: tap "Reply" on an expanded Timeline card, write your reply, and publish it as a normal Mark. The published Mark's permalink carries a `u-in-reply-to` link to the source, and the Webmention plugin (if installed and active) notifies the source automatically the moment your reply goes live — Daymark itself never sends, receives, or verifies a Webmention, it just makes sure the markup a Webmention plugin looks for is there. For the best Daymark + IndieWeb experience, install the [Webmention plugin](https://wordpress.org/plugins/webmention/) (and ActivityPub/ATmosphere alongside it) so replies and mentions from across the web show up in your notifications automatically — Settings -> Daymark's Connectors tab lists all three with an Install/Activate button right there, no need to leave wp-admin. Don't want to install the ActivityPub plugin at all? [Bridgy Fed](https://fed.brid.gy/) is a free, hosted bridge — not a plugin — that gives your site a fediverse and Bluesky presence through the Webmention support above, under an auto-generated handle on its own domain rather than a native handle on yours; it's also listed on the Connectors tab, right alongside the plugin options.

= Does Daymark work with the Friends plugin? =

Yes. If you already follow someone through the [Friends plugin](https://wordpress.org/plugins/friends/), subscribing to their site in Daymark reads their posts straight from Friends' own cache instead of independently re-fetching their site a second time — Friends already does the real fetching, parsing, and post-format classification for a friend, so Daymark just reuses it. This only ever applies to a friend you've already added in Friends' own UI; Daymark doesn't add friends on Friends' behalf, and a site Friends doesn't yet follow subscribes exactly as it always has (via its RSS/Atom feed, WordPress REST API, or microformats2 markup).

= Which AI providers work with AI Assist? =

Any WordPress AI Client provider plugin — Anthropic (Claude), Google (Gemini), or OpenAI (GPT). Daymark never talks to an AI vendor directly and never stores API keys; it goes through the core AI Client, and the first configured provider powers caption, title, alt text, tag, and transcript suggestions. Without a configured provider, the AI Assist UI simply does not appear.

= What does Daymark quietly capture, and can I turn it off? =

Composing a Mark quietly captures a few pieces of metadata in the background, without any field to fill in: the date/time it was created, your device's location (only if your browser grants permission — never a form field to fill in), current weather for that location, camera details from a photo's own EXIF data (camera model, aperture, ISO, and similar), an estimated reading time for a longer caption, and AI-suggested tags. None of it is required, none of it can block or delay publishing, and anything that isn't available (permission denied, no EXIF data, no AI provider configured, etc.) is simply left out rather than causing an error.

This is captured ahead of planned future work: showing where a Mark was made ("checkins"), its weather at the time, and richer photo details, directly in the Timeline. Location and weather aren't shown anywhere in Daymark yet — they're captured now so that display work has real data to build on rather than starting from a Timeline with nothing to show. If you'd rather this wasn't captured at all while it's still invisible, location, weather, and camera metadata can each be turned off independently from the **Privacy** section of Settings -> Daymark — no code required.

A developer can also set these same defaults from code, which still takes priority over the Settings -> Daymark checkboxes — see [the Daymark developer docs on GitHub](https://github.com/jeffpaul/daymark) for the specific filters.

Turning off location capture also stops the weather lookup, since weather is only ever attempted alongside a resolved location; the weather toggle alone leaves location capture on but skips just the weather lookup. A Mark's captured location is stored for your own site's use and is never published on its public permalink page unless you explicitly opt in via the "Publish location publicly" checkbox in the same Privacy section.

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

= 0.15.0 - 2026-09-10 =
**Added**

* Settings -> Daymark's subscriptions table now has a "Check for other feeds" action per row — useful when Daymark picked the wrong source for a site (e.g. a WordPress REST API that mixes every language together on a multilingual site). It lists every feed/source discovered for that site and lets you switch to a different one without unsubscribing and resubscribing.
* The first time you tap Like, Comment, Reblog, Bookmark, "Open original", or Share, a short overlay explains what that icon does — shown once per icon, right after the tap, never again after that.

**Changed**

* Subscription posts get a new Comment action, replacing the old "Reply" button that opened the full composer — tap it, type your comment, and it's delivered straight to the original post: via Webmention when both your site and the source support it (behind the scenes this still publishes a small Mark on your own site so your Webmention plugin can deliver it, same as before), or posted directly to the source site otherwise. Reblog now asks for an optional comment of your own before publishing, instead of always using a generic "Reposted ..." caption.
* The full-screen post view's standalone "Refresh content" text link is now an icon at the end of the interaction row (after Share), instead of its own row below the post — a shorter, less tall screen with one consistent set of icons for everything you can do with a post.
* The Timeline interaction row's "Open original", Share, Routing (on your own Marks), and "Refresh content" (on the full post view) actions now live behind a new ⋯ overflow menu, keeping Like, Comment, Reblog, and Bookmark as the primary row's exposed icons. A subscription post's overflow menu also gains a new Unsubscribe action — unsubscribe from a site directly from its card or full post view, with a confirmation step first, instead of needing a trip to Settings -> Daymark.

**Fixed**

* Timeline cards whose kind shows a small thumbnail beside the title (an article with a featured image, an audio/podcast post, a subscription post falling back to its site icon) had their interaction icons and site-name/date row indented under that thumbnail, reading as shifted right compared to a thumbnail-less card (note, link). Both rows now line up flush with the card's own left edge on every kind.
* Subscribing to an ordinary, valid site could fail with "Please enter a valid site URL." inside WordPress Playground, including the sites this plugin's own Playground previews try to preset automatically. Root cause: a DNS-resolution function returning something other than a real IP address or a clean failure was wrongly trusted as a "resolved" (and then judged unsafe) address.
* A subscribed WordPress or Friends post with a confirmed Image/Video/Audio/Gallery post format but no explicit featured image (common for themes that show a post's own first inline image instead) showed the subscription's site icon blown up in the card's media banner instead of that image.
* The full-screen post view's back arrow and Daymark icon were vertically centered against the post title's full height, so a long title that wrapped to two or three lines left them floating in the middle of the block instead of level with its first line.
* A plain, no-image subscription post could show a small thumbnail duplicating its own site icon — an author-bio box's avatar photo, embedded in the post's own content by the theme, was being picked up as the card's featured image. Avatar images are no longer treated as post content.
* A Mark's own like/comment/repost counts on its Timeline card no longer show Daymark's orange "active" accent color just because the count is 1 or more — that color is reserved for your own action (a genuine Like/Repost/Comment/Bookmark toggle); these three are always other people's engagement with your Mark, so they now stay a plain, muted count regardless of how high it is.
* A Timeline card's trailing whitespace below its own site-name/date row — before the next card begins — is now identical across every Mark type and post format. Media-dominant cards (image, gallery, video, mixed media) previously had roughly double the trailing space of every other kind (audio, note, article, link, standard), a real inconsistency visible scrolling down a mixed Timeline.

= 0.14.0 - 2026-09-09 =

**Added**

* Settings -> Daymark now has a Privacy section with a checkbox each for location, weather, and camera-metadata capture, plus whether a Mark's location is published publicly — previously these were only reachable by adding a filter in code. A filter still overrides its matching checkbox, so nothing already using one changes behavior.
* Settings -> Daymark's Subscriptions section now has a "Check for new posts" dropdown (Hourly / Every 6 hours / Every 12 hours / Daily, the previous default) controlling how often Daymark checks your subscriptions for new content — previously only changeable via a filter in code. A filter still overrides it.
* Settings -> Daymark's subscriptions table now has a search box that filters the list by site name, site URL, or feed URL — useful once you have more than a handful of subscriptions.
* Settings -> Daymark has a new Connectors tab recommending IndieWeb plugins that pair well with Daymark — Webmention, ActivityPub, and ATmosphere — each with a plain-language description of what it adds, a link to its WordPress.org page, and an inline Install/Activate button reflecting whether it's already installed or active. None of these is required.
* The Connectors tab also lists [Bridgy Fed](https://fed.brid.gy/), a free hosted bridge (not a plugin) that gives your site a fediverse and Bluesky presence through the Webmention support above, with no ActivityPub or AT Protocol plugin needed — an alternative to the ActivityPub plugin above, not an addition to it, since it bridges you in under an auto-generated handle rather than your own domain's native identity.

**Changed**

* **Breaking (for anything targeting the old URL directly): Settings -> Daymark's page slug got shorter and the page was reorganized into tabs.** It's now at `/wp-admin/options-general.php?page=daymark` (was `options-general.php?page=daymark-subscriptions`), split into Subscriptions, Connectors, Import/Export, and Privacy tabs instead of one long page. A plain visit to the old URL redirects automatically, so a browser bookmark still works — but the page's hook suffix changed too, from `settings_page_daymark-subscriptions` to `settings_page_daymark`, along with the matching `settings_page_daymark-subscriptions` admin body class WordPress adds automatically. Anything keyed to either of those directly (a custom `admin_enqueue_scripts`/`admin_head` hook, hand-written CSS/JS targeting the old body class) needs updating to the new slug — the redirect only covers a plain page load.
* Settings -> Daymark's subscriptions table now defaults to A-to-Z order by site name (falling back to the site URL only when there's no name) instead of raw subscribe order — the Site, Status, and Last fetched column headers remain independently sortable as before.
* A subscription post's Timeline card no longer shows its author's name directly under the title — for most single-author sites that read the same as, or very close to, the site name already shown on the card's bottom row, so it was dropped as redundant.
* Search's Source filter dropdown now lists subscribed sites alphabetically by name, with "All" and "My Marks" pinned first — previously they appeared in subscribe order.
* A "link"-format Timeline card no longer stands out with its own orange-tinted background — it now reads like a plain Note/Article card, matching the rest of the Timeline. Its full-screen post view also shows a best-effort oEmbed preview of the post's own detected outbound link (e.g. an embedded Mastodon post, a video player) when one is available.
* A microformats2-subscribed site's reply or RSVP posts now render as Notes on the Timeline instead of plain, undifferentiated articles — matching how status/chat-format posts already do. Reposts, likes, and bookmarks are unchanged for now.

**Fixed**

* A bookmark on a Mark or subscription post no longer outlives it — unsubscribing from a site, deleting a Mark, or WordPress's own trash-retention eventually purging either one now also clears any bookmark pointing at it, instead of leaving a permanently orphaned entry behind.
* The full-screen post view lost the site title, date, and interaction icons (Like through Share) a Timeline card already shows once opened — they're now kept visible below the post's own content.
* Explore/Search/Me's header Daymark icon and title still sat farther right than Home's own icon and wordmark, even after a prior pass matched their icon-to-title gap — the tap target's own leading overhang (44px box, 26px icon) was shifting the icon itself, not just the gap.

= 0.13.0 - 2026-09-08 =

**Added**

* Gallery Marks can now be manually reordered in the composer — up/down buttons next to each image (both newly picked files and media already attached to a resumed draft) let you set the order the published gallery renders in.
* A published Mark's Timeline card now has a routing icon showing exactly where it was sent — your own site plus every syndication target attempted, each with its own status (published/mocked, failed, or unsupported) and a link out where one exists.
* Notifications now groups a Mark's replies into one conversation card instead of scattering them as separate flat cards, and adds a source filter (once you have more than one) so you can narrow the list to just one reply origin. The per-Mark routing popover also now shows when a real syndicated target's replies were last checked.
* The composer's picker now accepts a dragged-and-dropped file on desktop, attaching it the same way picking it via the file input would.
* A Draft's ⋯ menu now has a "Publish" action, alongside Edit and Delete, that skips straight to the Publish screen for a draft that's already ready — no need to reopen the full composer first.
* A Mark's own card now shows your site's name on the same row as its timestamp, left-aligned — matching how a subscription post's card already shows its source site there.
* A subscription post's own full-screen view now has a "Refresh content" action that forces a fresh live re-fetch, instead of the cached content being stuck at whatever it looked like the first time it was fetched.
* A subscribed feed post whose content hasn't been fetched yet now rehydrates automatically as its Timeline card scrolls near the viewport, instead of waiting for you to tap it — the same background fetch a click-through already used, just triggered earlier so opening it moments later is instant.
* `/daymark` now loads and the composer works even on a cold, zero-connectivity load — open it once online, then a later relaunch with no signal at all (a subway, a flight, a dead zone) still gets you a working composer that queues locally, instead of a browser error page.

**Changed**

* Tapping a Timeline card (a Mark, an ordinary post, or a subscription post) now opens its full content on a dedicated full-screen post view instead of expanding it in place below the card — the same full-screen pattern Notifications already uses, with a back arrow next to the Daymark icon in the upper-left. Notifications' own back link now shares that same treatment (previously its own separate markup).

**Fixed**

* A syndication target that couldn't represent a Mark's type (e.g. selecting YouTube for a note) was silently dropped instead of being recorded as failed — `_daymark_syndication_status` could never actually show `failed` in practice. Every attempted target is now recorded with its own outcome, whether it succeeded or not.
* A syndication target whose connector plugin had been deactivated or uninstalled after it was selected was also silently dropped instead of recorded — the routing popover now shows it as "Not available" instead of it just vanishing.
* A resumed draft with existing media but no caption could get silently bounced back to the composer instead of reaching the Publish screen — the readiness check only ever looked at newly picked files, never a draft's own already-attached media.
* The Like/Repost toggle's own auto-published Mark (used to carry an outbound `u-like-of`/`u-repost-of` link) no longer shows up as its own card on the Timeline — it was never meant to be read as content.
* A long Timeline card title no longer gets cut off with an ellipsis — it now wraps onto as many lines as it needs, matching how the excerpt already displays in full.
* The Like-through-Share stat-row icons are now evenly spaced again — a read-only stat (a plain count, or the "Replied" indicator) previously had no minimum width of its own, throwing off the row's rhythm next to the interactive icons that did.
* The Timeline's vertical rail line no longer visibly breaks at a relative-date group header ("Today", "Last Week", ...), and now connects cleanly to the sunrise/sunset flourishes that bookend it.
* Explore/Search/Me's header icon and title no longer sit farther apart than Home's own icon and "Daymark" wordmark do.
* Broadened the "Skip to content"/post-navigation stripping in a subscription post's expanded content to catch a few more common theme/framework conventions (additional skip-link targets, a wider set of wrapper elements for the previous/next post links).
* A Jetpack Tiled Gallery's images could overlap each other and surrounding text in a subscription post's expanded content, since the layout CSS that positions them never loads here — they now fall back to a plain stacked layout instead.

**Developer**

* Every user-facing PHP string now uses a WordPress translation function under the `daymark` text domain, and the app shell's script registers `wp-i18n` + `wp_set_script_translations()` — laying the groundwork for wordpress.org's own GlotPress translation system once the plugin ships there. No bundled translation files, no behavior change for an English-language site.
* Every user-facing string in the app shell's own JavaScript (`assets/app.js`) now uses `wp.i18n.__()`/`_n()`/`sprintf()` under the `daymark` text domain, completing the JS half of i18n readiness started in #252 — no wording or behavior change for an English-language site.
* The hooks reference site (<https://jeffpaul.github.io/daymark/>) now shows Daymark's own icon as its browser tab favicon instead of the Docusaurus generator's default.
* The hooks reference site now includes a "Writing a Connector" guide — a complete, minimal `Daymark_Syndication_Connector` example (the interface, the `publish()` payload/result shapes, and the relevant hooks) for anyone building a real syndication destination.

= 0.12.0 - 2026-09-07 =

**Added**

* A subscribed post's Timeline card now has a "Replied" indicator plus real Like and Repost toggles — Like/Repost publish a small Mark of your own, letting an already-installed federation plugin (ActivityPub/Webmention/ATmosphere) send the actual outbound like/reblog, the same way the existing Reply action already works. Full engagement counts from the origin site aren't obtainable in general, so this shows your own engagement instead.

**Changed**

* An expanded Timeline card now gives the borrowed post content its own light gray background, distinct from the card's own white chrome — so a subscription post's "Reply" action reads clearly as Daymark's own UI rather than part of the quoted page.
* A Timeline card's excerpt no longer gets clamped to a fixed 1-2 lines — it now shows in full whenever the server provides one (up to ~40 words for a subscription post), instead of cutting off real content at an arbitrary card-height cap.
* The Settings -> Daymark subscriptions table's "Edit name" text link is now a pencil icon next to the site name — clicking it makes the name editable inline, saving on Enter, Tab, or clicking away instead of a separate Save button.
* The Settings -> Daymark subscriptions table's Refresh action is now a circular-arrows icon next to "Last fetched" instead of a labeled button in the Actions column — clicking it spins the icon while the refresh is in flight.

**Fixed**

* Reduced the empty vertical space between the header and the first Timeline item — the pull-to-refresh indicator's collapsed box and the empty refresh-status message were each still costing a full flex gap even though neither had any visible content.
* A subscription post's expanded content could show a Jetpack "Share this:" block, a Jetpack "Related" posts block, floated images overlapping surrounding text, a "Skip to content" link the existing stripping didn't catch, a theme's own publish-date/category markup nested alongside the real post body, and a "Previous:"/"Next:" post-navigation link — none of that is part of the post content from an RSS-feed point of view.
* A Timeline card for a post with no featured image and no cached site icon no longer shows a manufactured placeholder icon — the title/excerpt/date now use the card's full width instead.
* The Timeline could show a raw WordPress error ("Could not load your timeline. Cookie check failed") when the app-shell page's nonce went stale — most commonly a home-screen-installed PWA session resumed after a long background suspension. It now shows "Your session has expired" with a Reload button.
* A subscription post whose only image was lazy-loaded (a placeholder `src` with the real URL in `data-src`/`data-lazy-src`/`srcset`) previously showed no Timeline card thumbnail at all — the content sniffer now falls back through those common lazy-load attributes when `src` itself is empty or a placeholder.
* The Home/Explore/Search/Me header now always shows Daymark's own icon instead of the site's configured Site Icon, tightens Explore/Search/Me's header title spacing to match Home's, and replaces the Notifications page's "Back" text with the Daymark icon (keeping the arrow).
* Search's "Showing your bookmarks." banner text now matches the size of its "Show everything" link — they previously rendered at two different sizes.
* The Share icon's clipboard-copy fallback (used on any browser without a native share sheet, e.g. Firefox) now shows a visible on-screen "Link copied" confirmation instead of only a subtle color change — it previously looked like nothing had happened.
* A Timeline card's date, once shown as an actual date rather than a relative "Xd ago" reading, now formats it using the site's own Settings -> General -> Date Format instead of the browser's locale default (previously always MM/DD/YYYY-style).
* A bookmarked post's images now render correctly when viewed offline — its cached content markup displayed fine with no connectivity, but its `<img>` tags still pointed at the live origin site, so images showed as broken links. Images are now cached alongside the content and swapped in from that local copy when offline.
* The Settings -> Daymark subscriptions table's site icon now renders inline, just to the left of the site title, instead of in its own dedicated column.
* On a screen with very little content (e.g. a near-empty Timeline), the bottom nav and the floating "+New" launcher no longer float mid-page instead of pinned to the bottom — the screen itself now always claims the full available height it's meant to.
* A subscription post's Timeline stat row (Like, Comment, Repost, Bookmark, "open original", Share) now spaces every icon evenly instead of splitting into two unevenly-spaced clusters.

= 0.11.0 - 2026-09-05 =

**Added**

* The Settings -> Daymark subscriptions table now shows each site's cached icon in its own column, between Site and Status.
* The Settings -> Daymark subscriptions table's Site, Status, and Last fetched column headers are now clickable to sort the table ascending or descending.
* A subscription's Site name can now be edited directly in Settings -> Daymark, for when the auto-derived name (especially a Friends-plugin-sourced one) isn't obviously who or what it is.
* A Timeline card's site icon now shows the site's name and URL as a native tooltip on hover.
* A subscription that's failing to fetch new posts now shows a "Recent fetch issue" message in Settings -> Daymark well before it's flagged fully dead, and a dismissible wp-admin notice links back to the table when at least one subscription has a problem.
* The Timeline's own vertical rail now gets a small "sunrise" flourish where it begins and a "sunset" flourish where it currently ends, in the app's own sunset-gradient palette.
* A Timeline card (Mark or subscribed post) can now be bookmarked directly from its stat row for offline viewing — a new "Bookmarks" section on Explore shows just what you've saved, and its full content is cached for offline viewing automatically, including on a new device the moment you open Daymark.
* A Timeline card's stat row now has a Share icon — it opens your device's native share menu when available, or copies the post's link to your clipboard otherwise.
* The Timeline now groups cards under relative-period headers — Today, This Week, Last Week, This Month, Last Month, This Year, or a bare year for anything older — so a long scroll back through older content reads by chronological chunk instead of a wall of individual per-card timestamps.

**Changed**

* A Timeline card's date now sits on its own row, right-aligned at the bottom of the card, instead of sharing the meta line with the chip/author/reading-time text above it — a quieter, corner-anchored placement that reads more like a timestamp and less like one more label in a list.
* Clicking "Refresh" on a subscription in Settings -> Daymark now updates that row's Status and Last fetched values in place instead of reloading the whole page.
* A subscription post's Timeline card no longer shows a "Subscribed" chip — its site icon already makes clear it isn't one of your own Marks, so the chip was just taking up space.
* A Timeline card's comment/like/repost stat row is now ordered like, comment, reblog (was comment, like, repost).
* The header now shows the Daymark icon in the upper left on every screen (Home, Explore, Search, Me) instead of just the Timeline icon on Home — tapping it from Explore, Search, or Me takes you back to the Timeline. Explore, Search, and Me also now show the Notifications icon in the upper right, so it's been removed as a separate link on the Me page.
* A subscription having trouble fetching new posts (or fully dead) now shows up right in your Notifications, with a link back to Settings -> Daymark — no more separate wp-admin notice to check.
* The Daymark icon in the header is a bit bigger now (closer to the size of the bottom nav's own icons), and on Explore/Search/Me it no longer sits inside a bordered square button.
* Expanding a Timeline card's content now grows the card's own bordered frame instead of inserting content below it that could look like it overlapped other Timeline elements. The "View full post"/"View original" link is now a permanent icon in the stat row (between Bookmark and Share) instead of only appearing once a card is expanded, and every icon in the row now shows a hover tooltip.

**Fixed**

* Importing an OPML file could leave every newly subscribed site showing zero posts on the Timeline until the next scheduled poll (up to a day away by default) — subscribing to a single site by URL already fetches its content right away, but import never did. A successful import now triggers an immediate background poll.
* Subscribing to a second feed on an already-subscribed WordPress site (e.g. a friend publishing both a Posts archive and a separate Notes archive on one install) previously failed as a duplicate, since the site's REST API always resolves to the same site-wide feed regardless of which page you subscribed from. You can now paste a specific feed URL directly to subscribe to exactly that feed, and subscribing to a second page on the same site now falls back to that page's own RSS/Atom feed instead of failing.
* A subscribed post's expanded content could show a stray "Skip to content" accessibility link from the source site's own theme, when that site had no `<article>` element for Daymark to narrow down to.

[View the full changelog history](https://github.com/jeffpaul/daymark/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 0.15.0 =
Subscription posts get an instant Comment action (replacing the old "Reply" button) and the Timeline interaction row is tidier: Open original, Share, Routing, and Refresh content now live behind a new ⋯ overflow menu, which also adds a one-tap Unsubscribe for subscription posts. First-time explainer overlays introduce each icon on first tap. Also fixes: a duplicated site-icon thumbnail from author-bio avatars, a like/comment/repost count wrongly shown in Daymark's accent color, uneven card spacing across Mark types, and a Playground subscribe-by-URL failure.

= 0.14.0 =
Settings -> Daymark's URL is shorter now (`?page=daymark`, with the old URL redirecting automatically) and gained a Connectors tab recommending the Webmention/ActivityPub/ATmosphere plugins and Bridgy Fed, plus a Privacy section and a "Check for new posts" frequency setting. The subscriptions table now defaults to A-to-Z sorting and has a search box, and a bookmark on a deleted Mark or post no longer lingers as an orphaned entry.

= 0.13.0 =
`/daymark` now works even on a cold, zero-connectivity load — open it once online, and a later relaunch with no signal at all still gets you a working composer. Tapping a Timeline card now opens a dedicated full-screen post view instead of expanding in place, gallery images can be manually reordered in the composer, and a published Mark's new routing icon shows exactly where it was sent (and whether each destination succeeded).

= 0.12.0 =
Subscribed posts get real engagement: Like and Repost toggles alongside the existing Reply, publishing a small Mark of your own so an installed federation plugin sends the actual outbound activity. The Settings -> Daymark subscriptions table is tidier too — site icon, "Edit name," and Refresh are all now inline icons next to what they act on instead of separate columns/buttons.

= 0.11.0 =
Timeline cards can now be bookmarked for offline viewing and shared via your device's native share menu. Expanding a card grows its own bordered frame instead of overlapping other Timeline elements, with a permanent "open original" icon and hover tooltips on every stat-row icon. The Timeline also now groups cards under relative-period headers (Today, This Week, Last Month, etc.) for easier scanning further back.

= 0.10.0 =
Subscriptions get four new detection sources (WebSub push delivery, microformats2-only sites, WordPress REST API, and Friends-plugin friends) plus more accurate post-type mapping, OPML import/export, and an on-demand icon refresh. Timeline cards gain a repost count and a Reply action on subscribed posts. Published Marks drop their in-app Edit/Delete menu — use wp-admin for that now.

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
