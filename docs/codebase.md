# Moment — Codebase Guide

A developer-facing map of how the Moment plugin works. It complements the
[product design record](planning/README.md) (why) and the
[README](../README.md) (what) with the **how**: where things live, how the
pieces fit together, and where to hook in.

For contributing workflow details (setup, testing, coding standards), see
[CONTRIBUTING.md](../CONTRIBUTING.md).

## Architecture at a glance

Moment is a classic WordPress plugin — no build step, no custom post type. It
turns a standard `post` into a "Moment" by tagging it with `_moment_*` post
meta and adding:

1. a mobile-first **app shell** at `/moment` (PWA-installable) for
   capture/caption/publish;
2. a **REST API** (`/wp-json/moment/v1/`) that the app talks to;
3. optional **AI Assist** (captions, tags, alt text) via the WordPress 7.0 AI
   Client, with a deterministic mock fallback;
4. **syndication routing** — a connector registry that maps each content type
   to default destinations (the connectors themselves are third-party);
5. **conversation backflow** — social replies become native WordPress comments,
   surfaced in an in-app notifications screen.

The site is always the canonical destination. Social networks are optional,
additive endpoints reached through the WordPress plugin ecosystem, never through
Moment-internal network API calls.

```
                    ┌──────────────────────────────────────────┐
  phone browser ──► │  /moment  (Moment_Routes → app-shell.php) │  PWA + vanilla JS
                    └──────────────────┬───────────────────────┘
                                       │ X-WP-Nonce + edit_posts
                    ┌──────────────────▼───────────────────────┐
                    │  REST API  (Moment_Rest_Controller)      │
                    │  POST/GET /moments · ai/… · notifications│
                    └──────────────────┬───────────────────────┘
                                       ▼
        ┌──────────────────────────────┴────────────────────────────────┐
        │  Moment_Publisher — validates, uploads media, writes the post │
        │  + _moment_* meta, routes to selected connectors, fires hooks │
        └──────────────────────────────┬────────────────────────────────┘
                          ┌────────────┴─────────────┐
                          ▼                          ▼
              third-party connectors        AI Assist / backflow
              (via moment_register_           (Moment_AI_Assist,
               connectors hook)               Moment_Notifications)
```

## Repository layout

The repository **is** the plugin: `moment.php` lives at the repo root and the
repo is symlinked into a WordPress install as `wp-content/plugins/moment`.

| Path | Role |
|---|---|
| `moment.php` | Plugin bootstrap: constants, `require_once` graph, activation hooks, `Moment_Plugin::instance()` |
| `includes/` | All PHP classes (see [Class map](#class-map)) |
| `includes/connectors/` | Connector interface, base class, and the 7 built-in (mock) connectors |
| `templates/app-shell.php` | The `/moment` app shell HTML document |
| `assets/` | Vanilla JS app (`app.js`), app + section-page styles, PWA icons, service worker |
| `blocks/` | Five dynamic Gutenberg blocks (`block.json` + `render.php`), one per section view |
| `tests/` | PHPUnit suite, `smoke.sh` (WP-CLI E2E), and Playwright E2E (`tests/e2e/`) |
| `bin/` | Test-harness scripts (`install-wp-tests.sh`, `download-wp.sh`) |
| `docs/` | Design record (`planning/`), brand (`brand.md`), and this guide |
| `.wordpress-org/` | wp.org assets (banner, icons, screenshots) |

`.distignore` (and the mirrored `.gitattributes`) strip dev-only paths —
`tests/`, `docs/`, `bin/`, tooling, and AI-oriented files like `CLAUDE.md` —
from the distributed plugin zip.

## Bootstrap and class map

`moment.php` defines `MOMENT_*` constants, requires every class, registers
activation/deactivation hooks, and instantiates `Moment_Plugin`. The plugin
singleton wires everything up:

- `on_plugins_loaded()` — hooks the init-time actions.
- `on_init()` — registers connectors (`moment_register_connectors`), the
  backflow cron schedule, federated-comment labeling, and syndication links.

| Class | File | Responsibility |
|---|---|---|
| `Moment_Plugin` | `includes/class-plugin.php` | Singleton; activation/deactivation; page creation; wiring |
| `Moment_Routes` | `includes/class-routes.php` | Rewrite rule + `moment_app` query var; serves the app shell |
| `Moment_Rest_Controller` | `includes/class-rest-controller.php` | All REST routes, permissions, serialization |
| `Moment_Publisher` | `includes/class-publisher.php` | Create/update Moments, media upload, meta, syndication |
| `Moment_Publish_Helpers` | `includes/class-publish-helpers.php` | Awareness of publicize-style plugins + per-Moment toggles |
| `Moment_AI_Assist` | `includes/class-ai-assist.php` | AI captions/tags/alt text; mock fallback |
| `Moment_Blocks` | `includes/class-blocks.php` | Registers the 5 dynamic blocks + 5 shortcodes |
| `Moment_Renderer` | `includes/class-renderer.php` | Single shared query/markup engine for blocks *and* shortcodes |
| `Moment_Syndication_Registry` | `includes/class-syndication-registry.php` | Connector registry, per-type defaults |
| `Moment_Notifications` | `includes/class-notifications.php` | Backflow import, notification feed, reply handling |
| `Moment_Backflow_Sync` | `includes/class-backflow-sync.php` | Hourly cron that polls `backflow_supported` references |
| `Moment_Federated_Comments` | `includes/class-federated-comments.php` | Labels ActivityPub/ATmosphere/Webmention replies |
| `Moment_Syndication_Links` | `includes/class-syndication-links.php` | `u-syndication` markup on singular Moments |

## Content model

Every Moment is a standard `post` — never a custom post type. Portability is a
core promise. Required fields:

```
post_type    = 'post'                      # NEVER 'moment'
post_status  = 'publish' | 'draft'
post_title   = generated from caption      # or timestamp fallback
post_content = standard block markup       # core/image, core/video, …
```

Moment-ness and routing live in post meta:

| Meta | Meaning |
|---|---|
| `_moment_is_moment` | `'1'` — this post is a Moment |
| `_moment_primary_type` | `image` \| `video` \| `audio` \| `podcast` \| `note` \| `gallery` \| `mixed` |
| `_moment_media_ids` | JSON array of attachment IDs |
| `_moment_syndication_targets` | JSON array of selected connector IDs (this publish) |
| `_moment_default_destinations` | JSON array of default connector IDs (remembered model defaults) |
| `_moment_syndication_status` | `not_attempted` \| `mocked` \| `queued` \| `published` \| `failed` |
| `_moment_external_posts` | JSON object of external post references, keyed by connector ID |
| `_moment_comment_backflow_enabled` | `'1'` \| `'0'` |
| `_moment_ai_assist_used` | `'0'` \| `'1'` |
| `_moment_created_from` | `'mobile'` |

## Front-end architecture

The app shell is **vanilla ES2020 with no build step**. `templates/app-shell.php`
renders a minimal document (no theme chrome, no wp-admin) and inlines a
`momentApp` config object (REST URL, nonce, site URL, section-page links, type
defaults). `assets/app.js` contains:

- a single `state` object for the composer (files, caption, tags, targets,
  categories, AI flags, home pagination);
- the composer flow: pick media → optional AI Assist sheet → caption/tags → type
  and destination routing → publish/edit;
- the home timeline with type filter, search, and infinite scroll
  (`state.homePage` / `loadMore`);
- a notifications feed that freshens backflow when viewed;
- tiny API helpers (`apiGet`, `apiPost`, `apiDelete`, `apiUpload`) that always
  send the `X-WP-Nonce` header.

Styling uses the brand purples (`--moment-accent*` custom properties; see
[`docs/brand.md`](brand.md)). PWA support is a conservative service worker
(`assets/moment-sw.js`) that caches only app assets — never REST responses,
nonces, or HTML — plus a manifest and generated icons.

Section pages (`/timeline`, `/images`, `/videos`, `/audio`, `/notes`) are
auto-created pages embedding the `[moment_*]` shortcodes; they render inside the
theme via `Moment_Renderer`.

## REST API

All routes require `edit_posts` **and** a valid nonce via the `X-WP-Nonce`
header. Unauthenticated requests get 401; authenticated-but-unauthorized get
403.

| Method | Path | Purpose |
|---|---|---|
| POST | `/moment/v1/moments` | Create a Moment (multipart for media) |
| GET | `/moment/v1/moments` | List Moments (`per_page`, `page`, `status`, `type`, `s`) |
| GET | `/moment/v1/moments/{id}` | Fetch one Moment |
| POST | `/moment/v1/moments/{id}` | Edit caption/status/media of a Moment |
| DELETE | `/moment/v1/moments/{id}` | Trash a Moment (requires `delete_post`) |
| POST | `/moment/v1/ai/suggestions` | AI caption/tags (`text`, `primary_type`, `media_ids`) |
| POST | `/moment/v1/ai/alt-text` | Vision alt text for one uploaded image |
| POST | `/moment/v1/moments/{id}/sync-responses` | Trigger backflow import for a Moment |
| GET | `/moment/v1/notifications` | Notification feed (with `source_label`) |
| POST | `/moment/v1/notifications/{comment_id}/reply` | Reply to a backflow comment |

## The publish pipeline

`Moment_Publisher::publish()` is the single entry point (also used by the
smoke suite):

1. **Validate** — capability (`edit_posts`), content-type constraints, MIME
   from file *content* (not extension), per-file size cap
   (`MAX_FILE_BYTES`, 50 MB).
2. **Upload media** — `wp_handle_upload()` into the Media Library; build
   `core/image|video|audio|gallery|cover` block markup.
3. **Write the post** — `post` type, generated title, block content, and the
   full `_moment_*` meta set.
4. **Resolve destinations** — `get_effective_defaults()` merges per-type model
   defaults with remembered `moment_destination_prefs` user meta; only
   **connected** connectors are offered/auto-applied.
5. **Syndicate** — for each selected connector, record a mock/external post
   reference in `_moment_external_posts` and set `_moment_syndication_status`.
6. **Fire hooks** — `moment_published`, then `moment_syndication_complete`.

`update()` reuses the same pipeline for edits (draft→publish transitions
re-trigger syndication via `syndicate_on_publish`).

## AI Assist

`Moment_AI_Assist` wraps the WordPress 7.0 AI Client
(`WordPress\AiClient\AiClient` via `wp_ai_client_prompt()`). It:

- is **available** only when `wp_supports_ai()` is true and at least one
  provider is configured;
- returns a suggestion bundle (`caption`, `alt_text`, `tags`, `is_mocked`,
  `provider_label`);
- falls back to a **deterministic** mock (same input → same output) when no
  provider is configured or any call fails;
- never throws and never blocks publishing.

The API provider is the first configured one; AI Assist never stores API keys
(that is WordPress core's job).

## Backflow and notifications

Replies travel in as native WordPress comments so themes/feeds/comments/export
keep working:

- **API-polling connectors** — `Moment_Notifications::import_responses()` pulls
  replies for a Moment and imports them as comments (mock in core). A real
  connector implements `moment_import_network_responses`.
- **Automatic sync** — `Moment_Backflow_Sync` runs hourly via WP-Cron
  (`moment_backflow_sync`) over recent Moments with real
  `backflow_supported` references (per-post cooldown + look-back window; mock
  references never auto-sync). The notifications view also freshens on load.
- **Federation** — `Moment_Federated_Comments` labels replies delivered by
  ActivityPub/ATmosphere/Webmention plugins (`protocol=` comment meta) at read
  time; `Moment_Syndication_Links` emits `u-syndication` for Bridgy backfeed.

## Extension points

The open, documented surfaces for third-party plugins:

| Hook | Type | Purpose |
|---|---|---|
| `moment_register_connectors` | action | Register connectors on the `Moment_Syndication_Registry` |
| `moment_published` | action | After a Moment post is created (`$post_id`, `$moment_data`) |
| `moment_syndication_complete` | action | After connectors publish (`$post_id`, `$results`) |
| `moment_import_responses` | action | Backflow trigger (`$post_id`, `$network_id`) |
| `moment_import_network_responses` | filter | Connector returns imported replies (null = unhandled) |
| `moment_default_destinations` | filter | Override per-type model destination defaults |
| `moment_publish_helper_plugins` / `moment_publish_helper_adapters` | filter | Extend publicize-style helper detection |
| `moment_backflow_sync_days` / `moment_backflow_freshen_window` | filter | Backflow look-back / staleness windows |

The connector contract is `interface-syndication-connector.php`; `class-connector-base.php`
provides defaults. No connector ships as a real network client — a companion
plugin registers a first-class destination via the connector hook.

## Security model

Enforced on every endpoint and form handler (see CONTRIBUTING.md):

- `current_user_can()` before any write; REST routes also require the nonce.
- Inputs sanitized (`sanitize_text_field`, `wp_kses_post`, `absint`, …).
- MIME validated from file content with `finfo`, not the extension.
- All output escaped (`esc_html` / `esc_attr` / `esc_url`).
- No unauthenticated publishing, and no direct SQL without `$wpdb->prepare()`.

## Testing strategy

Four layers, all runnable locally (see CONTRIBUTING.md):

1. **PHPUnit** (`composer test`) — 20 test files covering publisher, REST
   (permissions, list/delete/reply, titles), AI Assist, backflow, syndication,
   federated comments, activation pages, drafts, categories, and helpers.
2. **WP-CLI smoke suite** (`tests/smoke.sh`) — 60+ assertions against a live
   install covering the 10 E2E scenarios, including portability
   (deactivate/reactivate) and real render checks.
3. **Playwright** (`npx playwright test`) — browser E2E for the app shell
   flows (composer, AI sheet, destinations, notifications) with fixture
   plugins that stub connectors/helpers.
4. **CI** (`.github/workflows/tests.yml`) — resolves stable + release-candidate
   WordPress versions and runs all three suites against both; also Plugin
   Check, dependency review, and a scheduled WordPress-version checker that
   opens "Tested up to" bump PRs.

## Non-goals

The prototype deliberately does **not** do: real social API publishing,
real-time comment polling/webhooks, a custom post type, AI key storage, a
settings dashboard, multi-user/team workflows, or full offline PWA mode. See
the [design record](planning/README.md) for the product rationale.
