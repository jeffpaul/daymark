=== Moment ===
Contributors: jeffpaul
Tags: publishing, mobile, pwa, syndication, indieweb
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Personal Site Publisher Mode for WordPress: capture, caption, and publish Moments from your phone. Your site stays the source of truth.

== Description ==

Moment is a phone-first publishing experience for WordPress. A logged-in user visits `/moment`, picks media from the camera roll, adds a caption, and publishes a standard WordPress post — the site stays the canonical source of truth.

WordPress does not need to become a social network. Moment makes your own site the starting point for social-shaped content: publish once on your domain, syndicate outward, and let the conversation flow back.

= What you get =

* **A phone app feel** — visit `/moment`, add it to your home screen, and publish images, videos, audio, and notes from a focused, mobile-first app shell with none of the wp-admin chrome.
* **Standard WordPress posts** — every Moment is a regular post with block markup. Your feeds, themes, comments, and export tools all keep working, and deactivating Moment never strands your content.
* **Syndication routing** — choose which networks each Moment also publishes to. Moment remembers your routing habits per content type and only offers destinations that are actually connected.
* **Conversation backflow** — replies from syndicated copies come back to your site as native WordPress comments, automatically (hourly background sync plus an opportunistic refresh when you view notifications). No manual sync step.
* **Federation friendly** — replies delivered by the ActivityPub, ATmosphere, or Webmention plugins are recognized and labeled in Moment notifications, and Moment renders IndieWeb `u-syndication` markup so Bridgy backfeed works out of the box.
* **Optional AI Assist** — caption, alt text, and tag suggestions through the WordPress 7.0 AI Client. Any configured AI provider plugin powers all of it; no provider, no AI UI, and publishing never depends on it.
* **Blocks and shortcodes** — timeline and per-type views are available as both `[moment_*]` shortcodes and `moment/*` blocks, rendering identical output.

= Publishing destinations =

Out of the box, Moment ships demonstration connectors so you can explore the full publish-and-reply flow without any accounts. Real bi-directional publishing for Bluesky and Mastodon is available through separate companion connector plugins (see the FAQ) that store credentials via the WordPress 7.0 Connectors API. Your site itself is always the primary destination — social networks are strictly additive.

= External services =

This plugin does not send data to any external service. The bundled demonstration connectors are mocks; real network publishing is handled by separate companion connector plugins, and AI features go through the WordPress core AI Client and whichever provider plugin you have configured.

= AI-assisted development =

This plugin was generated with Claude Code working from the Project Moment specification documents, with human guidance, review, and testing throughout — every build phase was gated on verification against a live WordPress site, and the test suites (PHPUnit, WP-CLI smoke, browser E2E) exist to keep that review honest. Treat it as AI-generated, human-directed software.

== Installation ==

1. Upload the `moment` folder to `/wp-content/plugins/`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Visit `https://yoursite.example/moment` on your phone while logged in.
4. Optional: add it to your home screen (Safari: Share → Add to Home Screen; Chrome: menu → Add to Home Screen / Install App). Standalone app display requires HTTPS.

Activation creates section pages (`/timeline`, `/images`, `/videos`, `/audio`, `/notes`) that render your Moments inside your theme.

== Frequently Asked Questions ==

= How do I publish to Bluesky or Mastodon for real? =

Install the companion connector plugins — **Moment Connector for Bluesky** and **Moment Connector for Mastodon**, available from the plugin's GitHub repository at https://github.com/jeffpaul/moment. Each stores its credential (a Bluesky app password or Mastodon access token) through the WordPress 7.0 Connectors API under Settings → Connectors. Once configured, Moments publish for real and replies flow back into WordPress as comments on the original post. If a connector is unconfigured or a call fails, publishing never blocks — it degrades to mocked demo behavior.

= Why don't I see any social networks on the publish screen? =

Moment only offers destinations that can actually publish (and pull replies back): a network appears once its connector plugin is active and configured. With nothing connected, "Your Site" is the only destination — publishing to your own site always works.

= How do replies come back to my site? =

Automatically. An hourly background sync (plus a refresh whenever you view notifications) fetches replies from your syndicated copies and imports them as WordPress comments, deduplicated per reply and labeled with their source ("Reply from Bluesky"). If you run the ActivityPub, ATmosphere, or Webmention plugins, replies they deliver are recognized and labeled too — those arrive by push, live, with no polling at all.

= Which AI providers work with AI Assist? =

Any WordPress AI Client provider plugin — Anthropic (Claude), Google (Gemini), or OpenAI (GPT). Moment never talks to an AI vendor directly and never stores API keys; it goes through the core AI Client, and the first configured provider powers caption, alt text, and tag suggestions. Without a configured provider, the AI Assist UI simply does not appear.

= Does Moment create a custom post type? =

No. Every Moment is a standard post with post meta, so your content is fully portable and remains intact and readable if you deactivate the plugin.

= Does it work offline? =

Partially. A conservative service worker caches only the app's static CSS and JS for fast loading. It never caches REST responses, nonces, HTML, or media, and there is no offline publishing mode.

== Screenshots ==

1. Home — the phone-first app shell: drafts and recent Moments in reach, one-tap publishing.
2. Create — pick media from the camera roll, add a caption, optional AI Assist.
3. Publish — choose destinations (your site always, connected networks strictly additive) or save as a draft.
4. Notifications — replies from syndicated copies flow back automatically, labeled by source.
5. Timeline — Moments rendered inside your theme, via shortcode or block.

== Changelog ==

= 0.3.0 =
* Awareness for other publishing plugins: when Jetpack Social, ATmosphere, Autoblue, Share on Mastodon, XPoster, Autoshare for Twitter, Blog2Social, Social Networks Auto-Poster (SNAP), or Revive Old Posts is active, the publish screen notes that your Moment will also go out through it. Detection only — Moment never drives or configures them — and it is extensible via the `moment_publish_helper_plugins` filter.
* Site-views nav (Timeline/Images/Videos/Audio/Notes) now uses icons, with each label kept as the hover tooltip and the accessible name.
* Recent Moments caps at five with a "View more" link to your timeline once there are more.
* Each Moment now gets a post format matching its type (image → Image, note → Aside, …) instead of inheriting the site's default format.
* Publishing shows the loading state on the button itself; both Publish and Save as Draft disable while a publish is in flight.
* "Publishing a Image Moment" now reads "Publishing an Image Moment".
* The Drafts section is hidden when there are no drafts.

= 0.2.0 =
* Save as Draft: start a Moment now, finish it later. Drafts store your selected destinations and never syndicate until published.
* Continue editing: tap a draft on Home to reopen the composer with its caption, media, and destinations restored; publishing an edited draft (from the app or wp-admin) runs the stored destinations.
* Drafts row on Home keeps drafts reachable no matter how many Moments publish after them; draft rows carry a Draft chip.
* Unread indicator on the notifications bell — a simple dot, cleared by viewing notifications.
* The + New Moment button moved to the bottom of the screen for one-handed reach.
* "Open Moment" action link on the Plugins screen for a one-click path into the app.
* Section pages are created with Moment blocks, so block themes edit them natively; shortcodes remain fully supported.
* Slug collisions are handled instead of silently shadowed: existing pages keep /timeline (etc.) while Moment views fall back to prefixed slugs, and a site with content at /moment gets the app at /moment-app — app links always point at the real locations.
* The PWA manifest is served dynamically for the resolved app path, without a redirect hop, and no longer hardcodes the wp-content path.

= 0.1.1 =
* App shell CSS/JS now load through the WordPress enqueue API (registered handles, inline bootstrap config via wp_add_inline_script, defer strategy).
* Tightened REST API capability checks: draft Moments list only for users who can edit them, notifications are scoped to Moments the current user can edit, syncing responses requires edit_post on the target, and attaching media requires upload_files.
* Reworded the plugin description per wordpress.org review guidelines.

= 0.1.0 =
* Initial release.
* Phone-first `/moment` app shell with Home, Create, Publish, and Notifications screens; PWA manifest and home-screen support.
* Publishing pipeline creating standard WordPress posts with block markup for image, video, audio, podcast, note, gallery, and mixed Moments.
* REST API under `/wp-json/moment/v1/` (moments, AI suggestions, response sync, notifications).
* Syndication connector registry with per-type routing defaults, per-user destination memory, and connected-only destination visibility.
* Automatic conversation backflow: hourly sync plus on-view freshen, importing replies as native WordPress comments.
* Federation integration: labeled backflow from the ActivityPub, ATmosphere, and Webmention plugins; IndieWeb u-syndication markup for Bridgy backfeed.
* Optional AI Assist (captions, alt text, tags) via the WordPress 7.0 AI Client.
* Timeline and per-type views as both shortcodes and dynamic blocks.

== Upgrade Notice ==

= 0.3.0 =
Notes active social-publishing plugins on the publish screen, icon-based site nav, per-type post formats, and publish-UI polish.

= 0.2.0 =
Adds drafts (save, resume, deferred syndication), an unread notifications indicator, and collision-safe app and section-page URLs.

= 0.1.1 =
Tightens REST API capability checks and moves app assets to the WordPress enqueue API.

= 0.1.0 =
Initial release.
