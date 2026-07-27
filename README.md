# Moment

Personal Site Publisher Mode for WordPress.

**Requires at least:** 7.0 · **Tested up to:** 7.0 ·
**Requires PHP:** 8.1 · **Stable tag:** 0.1.0 · **License:** GPLv2 or later

Moment is a phone-first publishing experience for WordPress. A logged-in
user visits `/moment`, picks media from the camera roll, adds a caption,
and publishes a standard WordPress post — the site stays the canonical
source of truth.

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

## Requirements

- WordPress 7.0+ (the bundled AI Client powers optional AI Assist; publishing never requires a configured AI provider)
- PHP 8.1+

## Quick start

```bash
wp plugin activate moment
```

Then visit `/moment` on a phone-sized viewport while logged in.

## FAQ

### How do I connect Bluesky for bi-directional publishing?

Moment's built-in connectors are mocked demos. The companion plugin
**Moment Connector for Bluesky** (`moment-connector-bluesky/`, a separate
plugin in this repo) replaces the Bluesky mock with the real thing —
Moments publish to Bluesky, and replies flow back into WordPress as
comments on the original Moment. Credentials are managed through the
WordPress 7.0 **Connectors API**, so it feels core-native.

**Prefer ATmosphere's OAuth connection when present.** If the
[ATmosphere](https://wordpress.org/plugins/atmosphere/) plugin is active
with a connected account and its *own* auto-publish turned off, the
Bluesky connector publishes through ATmosphere's OAuth connection (no app
password, and the account is managed on Settings → Connectors) — the
status reads "Connected · via ATmosphere". If ATmosphere is instead
auto-posting every published post itself, Moment doesn't also drive
Bluesky (that would double-post); ATmosphere just appears in the
awareness note, and Moment falls back to the app-password path only when
ATmosphere isn't auto-posting. To use an app password directly:

1. **Activate both plugins** — `moment` and `moment-connector-bluesky`.
2. **Create a Bluesky app password**: Bluesky → Settings → Privacy and
   Security → [App Passwords](https://bsky.app/settings/app-passwords) →
   Add App Password. Never use your main account password.
3. **Enter the app password in WordPress**: wp-admin → Settings →
   **Connectors** → Bluesky. (Core masks the stored key in REST
   responses; you can also supply it via a `BLUESKY_APP_PASSWORD`
   environment variable or PHP constant instead, which takes precedence
   over the database value.)
4. **Enter your handle**: wp-admin → Settings → **General** → "Bluesky
   Handle" (e.g. `you.bsky.social`).
5. **Publish**: in `/moment`, the Bluesky row on the publish screen now
   shows **Connected**. Note and mixed Moments publish for real — the
   caption plus a link back to your post, and the Bluesky post URL is
   stored on the Moment (`_moment_external_posts`).
6. **Replies come back automatically**: an hourly background sync (plus
   an opportunistic freshen whenever the notifications feed is viewed)
   fetches actual replies from your Bluesky thread and imports them as
   WordPress comments labeled "Reply from Bluesky", deduplicated by
   reply ID. No manual sync step exists — they appear on the post and
   in `/moment/notifications` on their own. (The
   `POST /moment/v1/moments/{id}/sync-responses` endpoint remains for
   demos and integrations.)

If the connector is not configured (or a Bluesky call fails), publishing
never blocks — the connector degrades to the same mocked behavior as the
built-in demo connector.

### How do I connect Mastodon?

Same model as Bluesky, via the companion plugin **Moment Connector for
Mastodon** (`moment-connector-mastodon/`):

1. **Activate both plugins** — `moment` and `moment-connector-mastodon`.
2. **Create an access token on your instance**: Preferences →
   Development → **New application** (any name; `read` and `write`
   scopes) → copy the access token.
3. **Enter the token in WordPress**: wp-admin → Settings →
   **Connectors** → Mastodon. (Also accepted via a
   `MASTODON_ACCESS_TOKEN` environment variable or PHP constant.)
4. **Enter your instance**: wp-admin → Settings → **General** →
   "Mastodon Instance" (e.g. `https://mastodon.social`).
5. **Publish and sync** exactly as with Bluesky: note, image, and mixed
   Moments post for real (caption + link), and syncing responses imports
   direct replies to your status as comments labeled "Reply from
   Mastodon", deduplicated per reply.

The same never-blocks rule applies: unconfigured or failing, it falls
back to mocked demo behavior.

### Why don't I see any social networks on the publish screen?

Moment only offers destinations that can actually publish (and pull
replies back): a network appears once its connector plugin is active
*and* configured. With nothing connected, "Your Site" is the only
destination — publishing to your own site always works; social networks
are strictly additive. The same rule applies to AI: the **AI Assist**
button only appears when a WordPress AI provider is actually configured.

Moment also remembers your routing habits per Moment type: once you
publish, say, an image Moment to a specific set of networks, the next
image Moment preselects the same set (per user). Types you have never
published fall back to the built-in defaults (note → Bluesky, image →
Instagram, video → YouTube).

### What social network connectors could work?

Moment's adapter layer (`moment_register_connectors` +
`moment_import_network_responses`) is open to any network. Feasibility
by platform:

| Network | Publish | Reply backflow | Notes |
|---|---|---|---|
| **Bluesky** | ✅ shipped | ✅ shipped | App password; AT Protocol is open, no app review |
| **Mastodon** | ✅ shipped | ✅ shipped | Per-instance access token; open API, no app review |
| Threads | plausible | plausible | Official API exists; requires a Meta app + review |
| X | plausible | limited | API v2 posting works; free tier is heavily rate-limited, replies effectively need a paid tier |
| Instagram | hard | hard | Graph API requires a Business/Creator account, app review, and media hosted at public URLs |
| YouTube | plausible | plausible | Data API v3 upload + commentThreads; OAuth app + quota management |
| TikTok | hard | hard | Content Posting API requires developer-program approval and audited scopes |
| Pixelfed / micro.blog / Nostr | plausible | varies | Open/self-hostable protocols, similar shape to Mastodon |

The pattern is consistent: open protocols (AT, ActivityPub) are
weekend-sized connectors; platforms with app-review gates are projects.

### Do ActivityPub, ATmosphere, or Webmention work with Moment?

Yes — and they're the *push-based* complement to Moment's connectors.
Those plugins deliver social replies as native WordPress comments, which
is exactly Moment's backflow storage, so replies they import appear in
Moment notifications automatically — labeled with honest source context
(Moment recognizes each plugin's comment markers):

| Plugin | Covers | Notification label |
|---|---|---|
| [ActivityPub](https://wordpress.org/plugins/activitypub/) | Fediverse (Mastodon, Threads, Pixelfed, …) | Reply from the Fediverse |
| [ATmosphere](https://wordpress.org/plugins/atmosphere/) | Bluesky / AT Protocol | Reply from Bluesky |
| [Webmention](https://wordpress.org/plugins/webmention/) | IndieWeb + [Bridgy](https://brid.gy) backfeed | Reply via Webmention |

The identity model differs from connectors, and both are valid: Moment
connectors publish a copy to **your personal account** (replies to that
copy come back on sync), while ActivityPub/ATmosphere make **your site
itself the account** (people follow your domain; replies arrive by push,
live, no syncing). Reactions (likes/reposts) that those plugins store as
comments are kept out of Moment notifications — replies only.

Moment also renders IndieWeb `u-syndication` markup on Moment posts
("Also on: Bluesky · Mastodon" links to the syndicated copies), which is
what Bridgy needs to backfeed replies from those copies as webmentions —
so connector-based syndication and webmention backfeed compose.

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
| AI Provider for Anthropic (Claude) | Caption suggestions, alt text drafts, tag suggestions — the full AI Assist sheet |
| AI Provider for Google (Gemini) | Same — the features are provider-agnostic |
| AI Provider for OpenAI (GPT) | Same |

Feature-by-feature: **caption suggestion** rewrites your draft text (or
proposes one from the media context); **alt text** drafts accessibility
text for the primary image; **tags** proposes post tags — accepted
suggestions are applied as real post tags and attachment alt text at
publish. All of it is optional: no provider, no AI UI, and publishing
never depends on it.

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

The manifest references `assets/icon.svg` (the source of truth) plus
`assets/icon-192.png` and `assets/icon-512.png`, all checked in. If you
edit the SVG, regenerate the PNGs, for example:

```bash
# From the plugin root, with librsvg installed:
rsvg-convert -w 192 -h 192 assets/icon.svg > assets/icon-192.png
rsvg-convert -w 512 -h 512 assets/icon.svg > assets/icon-512.png
```
