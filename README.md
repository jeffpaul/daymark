# Moment

Personal Site Publisher Mode for WordPress.

[![CI](https://github.com/jeffpaul/moment/actions/workflows/ci.yml/badge.svg)](https://github.com/jeffpaul/moment/actions/workflows/ci.yml)
[![Tests](https://github.com/jeffpaul/moment/actions/workflows/tests.yml/badge.svg)](https://github.com/jeffpaul/moment/actions/workflows/tests.yml)
[![Plugin Check](https://github.com/jeffpaul/moment/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/jeffpaul/moment/actions/workflows/plugin-check.yml)
[![Hooks Docs](https://github.com/jeffpaul/moment/actions/workflows/hooks-docs.yml/badge.svg)](https://github.com/jeffpaul/moment/actions/workflows/hooks-docs.yml)
[![Dependency Review](https://github.com/jeffpaul/moment/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/jeffpaul/moment/actions/workflows/dependency-review.yml)

[![GPLv2 License](https://img.shields.io/github/license/jeffpaul/moment.svg)](https://github.com/jeffpaul/moment/blob/main/LICENSE)
[![WordPress Playground Demo](https://img.shields.io/badge/Playground_Demo-8A2BE2?logo=wordpress&logoColor=FFFFFF&labelColor=3858E9&color=3858E9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jeffpaul/moment/main/.github/blueprints/blueprint.json)

**Requires at least:** 7.0 · **Tested up to:** 7.0 ·
**Requires PHP:** 8.1 · **Stable tag:** 0.4.0 · **License:** GPL-2.0-or-later

Moment is a phone-first publishing experience for WordPress. A logged-in
user visits `/moment`, picks media from the camera roll, adds a caption,
and publishes a standard WordPress post — the site stays the canonical
source of truth.

**Extending Moment?** The full action and filter reference is published at
**<https://jeffpaul.github.io/moment/>**.

**Status:** early release. App shell, REST API, and home-screen/PWA support
are in place; see "Using Moment Like a Phone App" below.

## Colors

The Moment brand palette is a range of purples:

| Token | Value | Use |
|---|---|---|
| Primary purple | `#7A00DF` | Primary actions, accents, brand marks |
| Deep purple | `#5300BE` | Pressed/hover states, emphasis, dark surfaces |
| Light purple | `#D7A7FF` | Tints, highlights, chips, subtle backgrounds |
| Transparent purple | `rgba(122, 0, 223, 0.12)` | Washes, focus rings, selected states |

> The app shell applies these purples throughout via the `--moment-accent*`
> custom properties in `assets/app.css`; the manifest theme color and app
> icon use the same palette.

## AI-assisted development

This plugin was generated with [Claude Code](https://claude.com/claude-code)
working from the Project Moment specification documents, with human guidance,
review, and testing throughout — every build phase was gated on verification
against a live WordPress site, and the test suites (PHPUnit, WP-CLI smoke,
browser E2E) exist to keep that review honest. Treat it as an AI-generated,
human-directed software.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for
development setup, the test suites, coding standards, and the pull-request
workflow.

## Requirements

- WordPress 7.0+ (the bundled AI Client powers optional AI Assist; publishing never requires a configured AI provider)
- PHP 8.1+

## Quick start

```bash
wp plugin activate moment
```

Then visit `/moment` on a phone-sized viewport while logged in.

## FAQ

### How do I publish to social networks?

A Moment is a standard WordPress post, so the leanest path is to let a
publishing plugin you already trust share it. Moment is built to
cooperate with those rather than reimplement them:

- **Publicize-style plugins** (Jetpack Social, Share on Mastodon,
  ATmosphere, XPoster, Autoshare for Twitter, …) already share your
  Moment when it publishes. Moment detects them and shows an awareness
  note on the publish screen. For plugins that expose a per-post control,
  Moment turns that into an in-app **on/off toggle** per Moment —
  currently **Share on Mastodon**, **Autoshare for Twitter**, and
  **ATmosphere** (for Bluesky, when it's connected and auto-publishing).
- **Federation plugins** ([ActivityPub](https://wordpress.org/plugins/activitypub/),
  [ATmosphere](https://wordpress.org/plugins/atmosphere/),
  [Webmention](https://wordpress.org/plugins/webmention/)) make your site
  itself the account; replies they receive land as native WordPress
  comments and appear in Moment notifications automatically (see below).

Moment also keeps an open connector interface
(`moment_register_connectors`) so a plugin can register a first-class
destination — appearing on the publish screen and syndicating through
Moment's own pipeline, with replies imported via
`moment_import_network_responses` — without any change to Moment core. No
such connector ships in this repo; the hook is the integration point.

If nothing is set up, "Your Site" is simply the only destination —
publishing to your own site always works, and everything social is
additive.

### Why don't I see any social networks on the publish screen?

Moment only offers destinations that can actually publish (and pull
replies back): a network appears once a connector plugin registers it as
a destination. With nothing connected, "Your Site" is the only
destination — publishing to your own site always works; social networks
are strictly additive. The same rule applies to AI: the **AI Assist**
button only appears when a WordPress AI provider is actually configured.
(Publicize-style plugins are separate — they show up as the awareness
note or a per-Moment toggle, described above, not as destinations.)

When connectors are present, Moment remembers your routing habits per
Moment type: once you publish, say, an image Moment to a specific set of
networks, the next image Moment preselects the same set (per user).

### How are Moments filed into categories?

The publish screen has a **File under** picker for your site's existing
categories. Like destinations, the choice is remembered per Moment type —
file image Moments under "Photos" once and the next image Moment
preselects it (per user) — and you can change it for any single Moment.
The picker only appears when your site has categories beyond its default
one; otherwise Moments file into the default category as usual.
Selections are validated against your existing categories (Moment never
creates new ones) and stored natively, so they behave like any other
post's categories.

### What social network connectors could work?

Moment's adapter layer (`moment_register_connectors` +
`moment_import_network_responses`) is open to any network. Feasibility
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

### Do ActivityPub, ATmosphere, or Webmention work with Moment?

Yes — they're the *push-based* way replies come back. Those plugins
deliver social replies as native WordPress comments, which is exactly
Moment's backflow storage, so replies they import appear in Moment
notifications automatically — labeled with honest source context (Moment
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
Moment notifications — replies only.

Moment also renders IndieWeb `u-syndication` markup on Moment posts
("Also on: …" links to the syndicated copies), which is what Bridgy needs
to backfeed replies from those copies as webmentions — so publicize-style
syndication and webmention backfeed compose.

### I already run a social auto-poster (Jetpack Social, XPoster, …). Does it work with Moment?

Yes, automatically — because a Moment is a standard WordPress post.
Any "publicize"-style plugin that shares posts when they publish already
shares your Moments the same way; Moment neither drives nor blocks it.

So Moment doesn't need to reimplement that. Instead, when it detects one
of these active, the publish screen adds a small note — "Your site's
publishing tools will also share this Moment, per their own settings:
…" — so you know your Moment is going out that way too. Moment reads
only whether the plugin is active; it never calls or configures it, and
each plugin still shares according to its own settings and per-post
controls.

Detected out of the box: **Jetpack Social**, **ATmosphere**,
**Autoblue**, **Share on Mastodon**, **XPoster**, **Autoshare for
Twitter**, **Blog2Social**, **Social Networks Auto-Poster (SNAP)**, and
**Revive Old Posts**. Other publishing plugins can add themselves to the
note via the `moment_publish_helper_plugins` filter.

### Can I turn a plugin's sharing on or off per Moment?

For plugins that expose a public per-post control hook, yes — Moment
turns the awareness note into an actual **per-Moment toggle** on the
publish screen. Currently that's **Share on Mastodon** (via its
`share_on_mastodon_enabled` filter) and **Autoshare for Twitter** (via
`autoshare_for_twitter_enabled_default`). The toggle defaults to **off**
(opt-in), and Moment drives each plugin only through its own public
hook — it never writes the plugin's private data. A Moment that opts in
publishes through the plugin's normal flow when it goes live.

Adapters are registered through the `moment_publish_helper_adapters`
filter, so a plugin (or a companion add-on) can make itself
controllable by mapping a toggle to its own per-post hook. Plugins that
don't yet expose such a hook stay awareness-only until they do — the
right fix there is an upstream hook, not Moment writing private meta.

One thing to watch: if you run *both* a Moment connector for a network
**and** one of these plugins for the same network, a Moment can post
twice. The note is there partly to make that visible — turn one of them
off for that network if you don't want the duplicate.

### Which AI providers power which Moment features?

Moment never talks to an AI vendor directly — it goes through the
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

## Using Moment Like a Phone App

Moment is designed to sit on your phone's home screen like a native app.
The demo URL pattern is always:

```
https://[yoursite]/moment
```

For example: `https://example.com/moment` (log in first, or you will be
redirected to the WordPress login screen and then back to Moment).

### iOS (Safari)

1. Open `https://[yoursite]/moment` in Safari.
2. Tap the **Share** button.
3. Tap **Add to Home Screen**.
4. Confirm the name "Moment" and tap **Add**.

### Android (Chrome)

1. Open `https://[yoursite]/moment` in Chrome.
2. Tap the **⋮** menu.
3. Tap **Add to Home Screen** (or **Install App** when Chrome offers it).

### What to expect

- The home-screen icon launches Moment as a browser shortcut. Full
  standalone display (`display: standalone` in the manifest, no browser
  chrome) requires **HTTPS**.
- **Local demo note:** the local dev site (`http://wp70.local`) is
  HTTP-only, so iOS will open the shortcut in regular Safari and Chrome
  will not offer "Install App". That is expected for local demos — on
  any HTTPS site the same URL installs as a standalone app.
- A conservative service worker (`assets/moment-sw.js`, cache
  `moment-v1`, scope limited to the plugin `assets/` directory) caches
  only the app's static `app.css` and `app.js`. It never caches REST
  responses, nonces, HTML, media, or anything under `/wp-admin/`. There
  is no offline publishing mode.

### Icons

The home-screen, PWA, and favicon icons (`assets/icon-32.png`,
`assets/icon-192.png`, `assets/icon-512.png`) are the brand mark, generated
from the design master at `.wordpress-org/src/icon-source.png` (the same
source as the wp.org listing icon). The PWA manifest is served dynamically by
`Moment_Routes::build_manifest()` and prefers the site's own Site Icon when one
is set. To regenerate the checked-in PNGs from the master, for example:

```bash
# From the plugin root, on macOS (sips); use ImageMagick's `convert` elsewhere:
for s in 32 192 512; do
  sips -s format png -Z "$s" .wordpress-org/src/icon-source.png --out "assets/icon-$s.png"
done
```
