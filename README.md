# Daymark

![Daymark](.wordpress-org/banner-1544x500.png)

[![GPLv2 License](https://img.shields.io/github/license/jeffpaul/daymark.svg)](https://github.com/jeffpaul/daymark/blob/main/LICENSE)
[![WordPress Playground Demo](https://img.shields.io/badge/Playground_Demo-8A2BE2?logo=wordpress&logoColor=FFFFFF&labelColor=3858E9&color=3858E9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jeffpaul/daymark/main/.github/blueprints/blueprint.json)

> Publish to your own site as fast as you'd post to a social app — photos, videos, voice notes, and quick thoughts, all from your phone, all truly yours.

## Your phone is full of moments worth sharing

Daymark makes your own WordPress site the fastest, most natural place to
share them — no app store, no algorithm deciding who sees it, no platform
that can change the rules on you tomorrow.

Open Daymark on your phone, tap to capture a photo, a video, or a quick
voice note (or pick one you already took), add a caption, and publish.
That's it. What you publish lives on your own site, under your own name,
for as long as you want it there.

## Why people love publishing with Daymark

- **It feels like your favorite social app — because your site deserves
  to.** Add Daymark to your phone's home screen and it opens like a real
  app: fast, focused, and built for one-handed use.
- **Your camera is one tap away.** Choose Photo, Video, or Audio and
  Daymark opens your camera or microphone right away. Already have the
  shot? Grabbing it from your library is just as easy.
- **Share to Daymark from anywhere on your phone**, using your phone's own
  Share button — from Photos, Safari, or almost any other app.
- **You never lose your work**, online or off — Daymark quietly saves as
  you go and publishes the moment you're back online.
- **Tap Publish and move on with your day.** No spinner to wait out, even
  for a big video or a whole gallery of photos.
- **It's genuinely yours, for good.** Every post is a real WordPress post
  — not a locked-in format — so it keeps working with your theme, your
  feeds, your backups, even if you ever stop using Daymark.
- **Reach further, without extra work.** Daymark works alongside the
  sharing plugins you already use, and brings replies from other networks
  back to you automatically.
- **A helping hand, never a replacement for yours.** Optional AI
  suggestions for captions, titles, tags, and alt text — always yours to
  accept, edit, or ignore.

**[Try Daymark right now in your browser](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jeffpaul/daymark/main/.github/blueprints/blueprint.json)**
— a full, temporary WordPress site with Daymark pre-installed, no signup
and nothing to install.

## Getting started

1. Install and activate Daymark like any WordPress plugin.
2. Visit `/daymark` on your phone while logged in — for example,
   `https://yoursite.com/daymark`.
3. Add it to your home screen so it opens like an app:
   - **iPhone (Safari):** tap Share, then **Add to Home Screen**.
   - **Android (Chrome):** tap the **⋮** menu, then **Add to Home Screen**
     (or **Install App**, when Chrome offers it).

That's the whole setup — there's no separate account to create, no
subscription, and nothing else to configure before your first post.

## Learn more

The full FAQ — how syndication and replies work, which AI providers are
supported, what Daymark quietly captures and how to turn it off, offline
behavior, and more — lives in **[readme.txt](readme.txt)**, the same
content that appears on the plugin's wordpress.org listing.

---

## For developers and extenders

The rest of this README is for anyone building with, contributing to, or
evaluating the code behind Daymark, rather than using it to publish.

### What it is, technically

Every Mark is a standard WordPress `post` — no custom post type — with
`_daymark_*` post meta carrying the rest. Around that, Daymark adds:

- a mobile **app shell** at `/daymark` (vanilla ES2020, no build step),
  installable to the home screen as a PWA;
- a **REST API** (`/wp-json/daymark/v1/`) for creating and listing Marks;
- optional **AI Assist** through the WordPress 7.0 AI Client — no
  provider configured means no AI UI at all, and publishing never depends
  on it;
- **conversation backflow** that brings replies back as native WordPress
  comments;
- a **subscriptions/Timeline-following** system for reading other sites'
  content (RSS/Atom, the WordPress REST API, microformats2, and the
  Friends plugin) alongside your own Marks.

Outbound syndication happens through the WordPress plugins you already
trust (publicize-style and federation plugins) rather than Daymark
reimplementing network APIs — see the FAQ in [readme.txt](readme.txt) for
how that works from a user's side.

### Requirements

- WordPress 7.0+ (the bundled AI Client powers optional AI Assist)
- PHP 8.2+

### Extending Daymark

- **Register a syndication connector** via `daymark_register_connectors` +
  `daymark_import_network_responses` — the
  [hooks reference site](https://jeffpaul.github.io/daymark/) documents
  every public hook, and includes a "Writing a Connector" guide with a
  complete worked example.
- **Register a subscription source** (an inbound content connector) via
  the `Daymark_Subscription_Source` interface and its registry — see
  `includes/sources/` for the built-in feed/WordPress-REST/microformats2/
  Friends-plugin sources as reference implementations.
- **Filters and hooks** for AI capture defaults, publish helper adapters,
  destination defaults, comment import, and more are all documented on
  the hooks reference site linked above.

### Architecture, decisions, and project history

- **[CLAUDE.md](CLAUDE.md)** is the authoritative technical record — the
  content model, REST endpoints, security checklist, and a full log of
  every architectural decision and why it was made. It's the single best
  starting point for understanding *why* the code looks the way it does.
- **[docs/planning/README.md](docs/planning/README.md)** — condensed
  product vision, positioning, and the original MVP spec.
- **[docs/design-principles.md](docs/design-principles.md)** — the
  design rubric (Path / Day One / WordPress / modern PWA) for what
  Daymark should *feel* like.
- **[docs/roadmap.md](docs/roadmap.md)** — what's shipped and what's
  next.
- **[docs/ui-polish-history.md](docs/ui-polish-history.md)** and
  **[docs/subscription-type-mapping.md](docs/subscription-type-mapping.md)**
  — detailed decision logs for UI polish and subscription content-type
  detection, split out of CLAUDE.md to keep it focused.

### Contributing

Contributions are welcome — see **[CONTRIBUTING.md](CONTRIBUTING.md)** for
development setup, the test suites (PHPUnit, WP-CLI smoke, Playwright
E2E), coding standards, and the pull-request workflow. Found a security
issue instead? See **[SECURITY.md](SECURITY.md)** for private reporting.

### AI-assisted development

This plugin was generated with [Claude Code](https://claude.com/claude-code)
working from the Project Daymark specification documents, with human
guidance, review, and testing throughout — every build phase was gated on
verification against a live WordPress site, and the test suites exist to
keep that review honest. Treat it as AI-generated, human-directed
software.

### CI status

[![CI](https://github.com/jeffpaul/daymark/actions/workflows/ci.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/ci.yml)
[![Tests](https://github.com/jeffpaul/daymark/actions/workflows/tests.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/tests.yml)
[![Plugin Check](https://github.com/jeffpaul/daymark/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/plugin-check.yml)
[![Hooks Docs](https://github.com/jeffpaul/daymark/actions/workflows/hooks-docs.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/hooks-docs.yml)
[![Dependency Review](https://github.com/jeffpaul/daymark/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/jeffpaul/daymark/actions/workflows/dependency-review.yml)

### License

Daymark is licensed under **GPL-2.0-or-later**
([GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)).
