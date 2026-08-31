# Daymark

![Daymark](.wordpress-org/banner-1544x500.png)

[![CI](https://github.com/jeffpaul/daymark/actions/workflows/ci.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/ci.yml)
[![Tests](https://github.com/jeffpaul/daymark/actions/workflows/tests.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/tests.yml)
[![Plugin Check](https://github.com/jeffpaul/daymark/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/plugin-check.yml)
[![Hooks Docs](https://github.com/jeffpaul/daymark/actions/workflows/hooks-docs.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/hooks-docs.yml)
[![Dependency Review](https://github.com/jeffpaul/daymark/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/dependency-review.yml)

[![GPLv2 License](https://img.shields.io/github/license/jeffpaul/daymark.svg)](https://github.com/jeffpaul/daymark/blob/main/LICENSE)
[![WordPress Playground Demo](https://img.shields.io/badge/Playground_Demo-8A2BE2?logo=wordpress&logoColor=FFFFFF&labelColor=3858E9&color=3858E9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jeffpaul/daymark/main/.github/blueprints/blueprint.json)

> Personal Site Publisher Mode for WordPress.

## Overview

Daymark is a phone-first publishing experience for WordPress — a "Personal Site
Publisher Mode" that makes posting to your own site as fast as a social app. A
logged-in user visits `/daymark`, picks media from the camera roll or types a
note, optionally runs AI Assist, and publishes a standard WordPress post. The
site stays the canonical source of truth; social networks are optional, additive
destinations.

Every Mark is a normal `post` — no custom post type — so your content stays
portable and works with standard themes, feeds, comments, and exports. Around
that, Daymark adds:

- a mobile **app shell** at `/daymark`, installable to the home screen as a PWA;
- a **REST API** for creating and listing Marks;
- optional **AI Assist** for captions, tags, and per-image alt text via the
  WordPress 7.0 AI Client (no provider, no AI UI — and publishing never
  depends on it);
- **conversation backflow** that brings social replies back as native
  WordPress comments and surfaces them in an in-app notifications screen.

Publishing outward happens through the WordPress plugins you already trust —
publicize-style and federation plugins — rather than Daymark reimplementing
network APIs. See the [FAQ](#faq) for how that works.

**Extending Daymark?** The full action and filter reference is published at
**<https://jeffpaul.github.io/daymark/>**.

## Requirements

- WordPress 7.0+ (the bundled AI Client powers optional AI Assist; publishing never requires a configured AI provider)
- PHP 8.1+

## Using Daymark Like a Phone App

Activate the plugin, then visit `/daymark` on a phone-sized viewport while logged
in:

```bash
wp plugin activate daymark
```

Daymark is designed to sit on your phone's home screen like a native app. The URL
pattern is always:

```
https://[yoursite]/daymark
```

For example: `https://example.com/daymark` (log in first, or you will be
redirected to the WordPress login screen and then back to Daymark).

### iOS (Safari)

1. Open `https://[yoursite]/daymark` in Safari.
2. Tap the **Share** button.
3. Tap **Add to Home Screen**.
4. Confirm the name "Daymark" and tap **Add**.

### Android (Chrome)

1. Open `https://[yoursite]/daymark` in Chrome.
2. Tap the **⋮** menu.
3. Tap **Add to Home Screen** (or **Install App** when Chrome offers it).

### What to expect

- The home-screen icon launches Daymark as a browser shortcut. Full
  standalone display (`display: standalone` in the manifest, no browser
  chrome) requires **HTTPS**.
- **Local demo note:** the local dev site (`http://wp70.local`) is
  HTTP-only, so iOS will open the shortcut in regular Safari and Chrome
  will not offer "Install App". That is expected for local demos — on
  any HTTPS site the same URL installs as a standalone app.
- A conservative service worker (`assets/daymark-sw.js`, cache
  `daymark-v1`, scope limited to the plugin `assets/` directory) caches
  only the app's static `app.css` and `app.js`. It never caches REST
  responses, nonces, HTML, media, or anything under `/wp-admin/`. There
  is no offline publishing mode.

## FAQ

### How do I publish to social networks?

A Mark is a standard WordPress post, so the leanest path is to let a
publishing plugin you already trust share it. Daymark is built to
cooperate with those rather than reimplement them:

- **Publicize-style plugins** (Jetpack Social, Share on Mastodon,
  ATmosphere, XPoster, Autoshare for Twitter, …) already share your
  Daymark when it publishes. Daymark detects them and shows an awareness
  note on the publish screen. For plugins that expose a per-post control,
  Daymark turns that into an in-app **on/off toggle** per Mark —
  currently **Share on Mastodon**, **Autoshare for Twitter**, and
  **ATmosphere** (for Bluesky, when it's connected and auto-publishing).
- **Federation plugins** ([ActivityPub](https://wordpress.org/plugins/activitypub/),
  [ATmosphere](https://wordpress.org/plugins/atmosphere/),
  [Webmention](https://wordpress.org/plugins/webmention/)) make your site
  itself the account; replies they receive land as native WordPress
  comments and appear in Daymark notifications automatically (see below).

Daymark also keeps an open connector interface
(`daymark_register_connectors`) so a plugin can register a first-class
destination — appearing on the publish screen and syndicating through
Daymark's own pipeline, with replies imported via
`daymark_import_network_responses` — without any change to Daymark core. No
such connector ships in this repo; the hook is the integration point.

If nothing is set up, "Your Site" is simply the only destination —
publishing to your own site always works, and everything social is
additive.

### Why don't I see any social networks on the publish screen?

Daymark only offers destinations that can actually publish (and pull
replies back): a network appears once a connector plugin registers it as
a destination. With nothing connected, "Your Site" is the only
destination — publishing to your own site always works; social networks
are strictly additive. The same rule applies to AI: the **AI Assist**
button only appears when a WordPress AI provider is actually configured.
(Publicize-style plugins are separate — they show up as the awareness
note or a per-Mark toggle, described above, not as destinations.)

When connectors are present, Daymark remembers your routing habits per
Mark type: once you publish, say, an image Mark to a specific set of
networks, the next image Mark preselects the same set (per user).

### How are Marks filed into categories?

The publish screen has a **File under** picker for your site's existing
categories. Like destinations, the choice is remembered per Mark type —
file image Marks under "Photos" once and the next image Mark
preselects it (per user) — and you can change it for any single Mark.
The picker only appears when your site has categories beyond its default
one; otherwise Marks file into the default category as usual.
Selections are validated against your existing categories (Daymark never
creates new ones) and stored natively, so they behave like any other
post's categories.

### What social network connectors could work?

Daymark's adapter layer (`daymark_register_connectors` +
`daymark_import_network_responses`) is open to any network. Feasibility
by platform:

| Network | Publish | Reply backflow | Notes |
|---|---|---|---|
| **Bluesky** | via ATmosphere | via ATmosphere / Bridgy | Covered today by the ATmosphere plugin; AT Protocol is open, no app review |
| **Mastodon** | via Share on Mastodon | via ActivityPub | Covered today by existing plugins; open API, no app review |
| Threads | plausible | plausible | Official API exists; requires a Meta app + review |
| X | plausible | limited | API v2 posting works; free tier is heavily rate-limited, replies effectively need a paid tier |
| Instagram | hard | hard | Graph API requires a Business/Creator account, app review, and media hosted at public URLs |
| YouTube | plausible | plausible | Data API v3 upload + commentThreads; OAuth app + quota management |
| TikTok | hard | hard | Content Posting API requires developer-program approval and audited scopes |
| Pixelfed / micro.blog / Nostr | plausible | varies | Open/self-hostable protocols, similar shape to Mastodon |

The pattern is consistent: open protocols (AT, ActivityPub) are
weekend-sized connectors; platforms with app-review gates are projects.

### Do ActivityPub, ATmosphere, or Webmention work with Daymark?

Yes — they're the *push-based* way replies come back. Those plugins
deliver social replies as native WordPress comments, which is exactly
Daymark's backflow storage, so replies they import appear in Daymark
notifications automatically — labeled with honest source context (Daymark
recognizes each plugin's comment markers):

| Plugin | Covers | Notification label |
|---|---|---|
| [ActivityPub](https://wordpress.org/plugins/activitypub/) | Fediverse (Mastodon, Threads, Pixelfed, …) | Reply from the Fediverse |
| [ATmosphere](https://wordpress.org/plugins/atmosphere/) | Bluesky / AT Protocol | Reply from Bluesky |
| [Webmention](https://wordpress.org/plugins/webmention/) | IndieWeb + [Bridgy](https://brid.gy) backfeed | Reply via Webmention |

There are two identity models, and both are valid: a publicize-style
plugin posts a copy to **your personal account** on the network, while
ActivityPub/ATmosphere make **your site itself the account** (people
follow your domain; replies arrive by push, live, no syncing). Reactions
(likes/reposts) that those plugins store as comments are kept out of
Daymark notifications — replies only.

Daymark also renders IndieWeb `u-syndication` markup on Mark posts
("Also on: …" links to the syndicated copies), which is what Bridgy needs
to backfeed replies from those copies as webmentions — so publicize-style
syndication and webmention backfeed compose.

Every Mark's own permalink page carries full outbound `h-entry`/`h-card`
microformats2 markup too, and Users → Your Profile has a `rel=me` field
that renders as a `rel="me"` link next to your h-card — so IndieWeb tools
(readers, IndieAuth, Bridgy) can read a Mark correctly without any of this
plugin's own APIs.

### I already run a social auto-poster (Jetpack Social, XPoster, …). Does it work with Daymark?

Yes, automatically — because a Mark is a standard WordPress post.
Any "publicize"-style plugin that shares posts when they publish already
shares your Marks the same way; Daymark neither drives nor blocks it.

So Daymark doesn't need to reimplement that. Instead, when it detects one
of these active, the publish screen adds a small note — "Your site's
publishing tools will also share this Mark, per their own settings:
…" — so you know your Mark is going out that way too. Daymark reads
only whether the plugin is active; it never calls or configures it, and
each plugin still shares according to its own settings and per-post
controls.

Detected out of the box: **Jetpack Social**, **ATmosphere**,
**Autoblue**, **Share on Mastodon**, **XPoster**, **Autoshare for
Twitter**, **Blog2Social**, **Social Networks Auto-Poster (SNAP)**, and
**Revive Old Posts**. Other publishing plugins can add themselves to the
note via the `daymark_publish_helper_plugins` filter.

### Can I turn a plugin's sharing on or off per Mark?

For plugins that expose a public per-post control hook, yes — Daymark
turns the awareness note into an actual **per-Mark toggle** on the
publish screen. Currently that's **Share on Mastodon** (via its
`share_on_mastodon_enabled` filter) and **Autoshare for Twitter** (via
`autoshare_for_twitter_enabled_default`). The toggle defaults to **off**
(opt-in), and Daymark drives each plugin only through its own public
hook — it never writes the plugin's private data. A Mark that opts in
publishes through the plugin's normal flow when it goes live.

Adapters are registered through the `daymark_publish_helper_adapters`
filter, so a plugin (or a companion add-on) can make itself
controllable by mapping a toggle to its own per-post hook. Plugins that
don't yet expose such a hook stay awareness-only until they do — the
right fix there is an upstream hook, not Daymark writing private meta.

One thing to watch: if you run *both* a Mark connector for a network
**and** one of these plugins for the same network, a Mark can post
twice. The note is there partly to make that visible — turn one of them
off for that network if you don't want the duplicate.

### Which AI providers power which Daymark features?

Daymark never talks to an AI vendor directly — it goes through the
WordPress 7.0 **AI Client**, so any configured provider plugin powers
all of AI Assist. Configure exactly one (or several — the first
configured provider is used):

| Provider plugin | Powers |
|---|---|
| AI Provider for Anthropic (Claude) | Caption and tag suggestions (AI Assist sheet); per-image alt text generated from the image itself |
| AI Provider for Google (Gemini) | Same — the features are provider-agnostic |
| AI Provider for OpenAI (GPT) | Same |

Feature-by-feature: **caption suggestion** rewrites your draft text (or
proposes one from the media context) and **tag suggestions** propose post
tags — both from the AI Assist sheet, with accepted tags applied as real
post tags at publish. **Alt text** works differently: every image in the
composer gets its own alt field, pre-filled from the actual image via a
vision call to the AI Client and editable before you publish (when no
provider is configured the field is simply empty to fill in by hand). All
of it is optional: no provider, no AI UI, and publishing never depends on
it.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for
development setup, the test suites, coding standards, and the pull-request
workflow.

## AI-assisted development

This plugin was generated with [Claude Code](https://claude.com/claude-code)
working from the Project Daymark specification documents, with human guidance,
review, and testing throughout — every build phase was gated on verification
against a live WordPress site, and the test suites (PHPUnit, WP-CLI smoke,
browser E2E) exist to keep that review honest. Treat it as an AI-generated,
human-directed software.
