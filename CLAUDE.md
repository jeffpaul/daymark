# Project Moment — CLAUDE.md
# Claude Code project memory for the Moment WordPress plugin prototype.
# Place this file in your WordPress installation root (same level as wp-config.php).
# Update bracketed values after Phase 0 environment checks.

## Plugin identity

| Key | Value |
|-----|-------|
| Public product name | Project Moment |
| Plugin name | Moment |
| Plugin slug | `moment` |
| Plugin directory | `moment/` |
| Main plugin file | `moment/moment.php` |
| Text domain | `moment` |
| REST namespace | `/wp-json/moment/v1/` |
| Block namespace | `moment/*` |
| PHP class prefix | `Moment_` |
| PHP namespace | `Moment\` (optional alternative) |
| Action/filter prefix | `moment_` |
| Shortcode prefix | `moment_` |

**NEVER use in code**: `project-moment`, `project_moment`, `projectmoment`

## Environment

| Key | Value |
|-----|-------|
| WordPress version | 7.0-beta3-61869 |
| PHP version | 8.2.27 (site) / 8.5.7 (CLI) |
| WP 7.0 AI Client available | **yes** — via `ai` plugin 0.4.1: class is `WordPress\AiClient\AiClient` (namespaced; the legacy `WP_AI_Client` name does NOT exist). Anthropic/Google/OpenAI provider plugins active. |
| Site URL | http://wp70.local |
| Plugin path | ~/Local Sites/wp70/app/public/wp-content/plugins/moment (symlink → ~/GitHub/jeffpaul/moment) |
| Local environment | Local by Flywheel (site: wp70) |

**Repo layout note:** This repo root IS the plugin. `moment.php` lives at the repo root, and the repo is symlinked into the wp70 site's plugins directory as `moment/`. The `docs/` directory and `.claude/` are excluded from distribution via `.distignore`. Runtime gates (`wp plugin activate`, `wp eval`) run from `~/Local Sites/wp70/app/public` and require the wp70 site to be started in Local.

## Build commands

```bash
# Activate/deactivate
wp plugin activate moment
wp plugin deactivate moment

# PHP tests (from the repo root)
composer install
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 7.0   # once per machine
WP_TESTS_DIR=$TMPDIR/wordpress-tests-lib composer test   # macOS ($TMPDIR); /tmp on Linux/CI

# WP-CLI smoke suite (57 assertions) against a live site with the plugin active
WP=/path/to/wp-cli-wrapper bash tests/smoke.sh

# PHP linting (WordPress Coding Standards)
composer phpcs

# Browser E2E (Playwright; needs a live site + admin creds)
npm ci && npx playwright install chromium   # once per machine
WP_BASE_URL=http://wp70.local WP_ADMIN_USER=<user> WP_ADMIN_PASS=<pass> npx playwright test

# Watch for JS changes (if build step added)
npm run start

# Production build (if build step added)
npm run build
```

## Phase status

Update this after each phase completes. Do not mark DONE unless the phase gate passed.

- [x] Phase 0: Environment + CLAUDE.md verified (AI Client runtime check deferred to Phase 4 — requires wp70 running)
- [x] Phase 1: Plugin scaffold + activation (gate PASSED: activated cleanly; Moment_Plugin exists; 5 section pages created; /moment redirects unauthenticated → wp-login)
- [x] Phase 2: REST API + publisher (gate PASSED: unauthenticated GET /moments → 401; all 5 routes registered; note-Moment publish smoke test created post with full meta)
- [x] Phase 3: Frontend app shell (gate PASSED: authenticated /moment → 200, `<title>Moment</title>`, momentApp config inlined, zero wp-admin/admin-bar references)
- [x] Phase 4: AI Assist adapter (gate PASSED: all keys present; REAL path live — Anthropic via core AI Client; mock fallback deterministic under wp_supports_ai=false)
- [x] Phase 5: Syndication routing + connectors (gate PASSED: image→instagram + note→bluesky defaults; 7 connectors registered; mock publish round-trip stores _moment_external_posts and sets status to 'mocked')
- [x] Phase 6: Conversation backflow + notifications (gate PASSED + independently re-verified: sync imports labeled mock replies as standard WP comments; repeat sync deduped; notifications include 'Reply from Bluesky' item and exclude non-Moment post comments; non-Moment sync → 404)
- [x] Phase 7: Blocks and shortcodes (gate PASSED: all 5 shortcodes + all 5 moment/* dynamic blocks registered; /timeline page renders Moments; shortcode and block output byte-identical via shared renderer)
- [x] Phase 8: PWA + home screen (gate PASSED: manifest reachable + valid JSON; icon-192/512 PNGs generated from icon.svg and served 200; conservative SW at assets/moment-sw.js — caches only app.css/app.js, never REST/nonces/admin/HTML)
- [x] Phase 9: E2E tests (gate PASSED: WP-CLI smoke suite tests/smoke.sh 57/57 against live wp70; PHPUnit scaffolded — needs WP test lib (`WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test`); Playwright scaffolded, not run — needs `npm i -D @playwright/test && npx playwright install chromium`)

## Key architectural decisions

Document decisions here as they are made. This is the authoritative record.

| Decision | Value | Rationale |
|----------|-------|-----------|
| Content model | Standard `post` post type | Portability; standard feeds/comments/templates |
| Custom post type | Not used | Deactivation safety; standard theme compat |
| Route strategy | Rewrite rule + `template_include` on a `moment_app` query var | No page dependency; full control of app-shell markup (no theme chrome); clean `/moment` PWA scope. Section pages (`/timeline`, `/images`, `/videos`, `/audio`, `/notes`) are auto-created pages with shortcodes since those should render inside the theme. |
| Block vs shortcode | **Both** — shortcodes as required baseline, dynamic blocks (`block.json` + `render.php`, no build step) as thin wrappers | Activation pages already embed `[moment_*]` shortcodes; MVP spec requires both; all query/markup logic lives once in `Moment_Renderer::render()` so both surfaces emit identical HTML |
| WP 7.0 AI path | **Real** — `WordPress\AiClient\AiClient` via `wp_ai_client_prompt()`; provider Anthropic (first configured). Mock fallback when no provider is configured or any call fails. | Plugin requires WP 7.0+, so the AI Client is assumed present (no class/function existence shims). Detection: `wp_supports_ai()` + ≥1 `isProviderConfigured()`. Never throws, never blocks publishing. Legacy `WP_AI_Client` name does not exist — do not use it for calls. |
| JS framework | Vanilla ES2020, no build step | Prototype speed; no npm required |
| Brand colors | Purples: primary `#7A00DF`, deep `#5300BE`, light `#D7A7FF`, transparent `rgba(122, 0, 223, 0.12)` | Documented in [docs/brand.md](docs/brand.md) and applied throughout the app shell (`--moment-accent*` custom props), views, manifest, theme-color, and icon. |
| Destination visibility | Only **connected** connectors are offered as publish destinations; auto-applied type defaults filter to connected too (explicit API selections honored as-is). AI Assist UI only renders when a provider `is_available()`. No demo-mode filter — the site itself is always the canonical destination. | A destination that can't publish or return replies shouldn't be offered. No connector ships in-repo; the connector interface + `moment_register_connectors` hook is the extension point and is covered by PHPUnit (`test-syndication.php`), not E2E. Model defaults stay recorded in `_moment_default_destinations`. |
| Companion connectors removed | The `moment-connector-bluesky`/`-mastodon` plugins were removed from the repo (not published to wp.org). Real syndication rides on existing ecosystem plugins instead: publicize-style plugins via `Moment_Publish_Helpers` (awareness + per-Moment toggles) and federation plugins via `Moment_Federated_Comments`. ATmosphere is a controllable toggle (its documented `atmosphere_disabled` opt-out meta), so Bluesky gets an in-app on/off when ATmosphere is connected and auto-publishing. | Leaner repo; aligns with the "no real social API publishing in core" non-goal. The open connector hook remains for any plugin to register a first-class destination. |
| Destination memory | Explicit per-type selections are remembered in `moment_destination_prefs` user meta and win over model defaults on the next publish of that type (explicit empty = "none" is remembered too). | Publishing habits differ per person and per content type; `Moment_Publisher::get_effective_defaults()` is the single resolution point used by both the app shell and the publisher's no-selection fallback. |
| Automatic backflow | Replies sync without user action: hourly WP-Cron (`moment_backflow_sync`) over recent Moments with real `backflow_supported` references, plus an async freshen when notifications are viewed (staleness transient, `moment_backflow_freshen_window`). Mock references never auto-sync. Per-post 10-min cooldown; look-back window `moment_backflow_sync_days` (14). | Real-time-or-automatic beats a manual sync button; push channels (federation plugins) are already live, so this covers only the API-polling connectors. Schedule self-heals on init for already-active installs. |
| Federation backflow | `Moment_Federated_Comments` labels replies delivered by ActivityPub (`protocol=activitypub`), ATmosphere (`protocol=atproto`), and Webmention (`protocol=webmention`) plugins at read time in notifications; reaction comment types (like/repost) are excluded (`type=comment` query). `Moment_Syndication_Links` renders `u-syndication` markup on singular Moment posts for Bridgy backfeed. | Federation plugins store replies as native WP comments — Moment's storage model — so push-based backflow needs only labeling, not import. No dependency on the plugins; pure comment-meta detection (schemas verified from plugin source). |

## Content model quick reference

```php
// Required post fields
post_type    = 'post'                     // NEVER 'moment' custom type
post_status  = 'publish' or 'draft'       // based on capability
post_title   = generated from caption     // or timestamp fallback
post_content = block markup               // core/image, core/video, etc.

// Required post meta
_moment_is_moment              = '1'
_moment_primary_type           = image|video|audio|note|gallery|mixed
_moment_media_ids              = JSON array of attachment IDs
_moment_syndication_targets    = JSON array of selected connector IDs
_moment_default_destinations   = JSON array of default connector IDs
_moment_syndication_status     = not_attempted|mocked|queued|published|failed
_moment_external_posts         = JSON object of external post references
_moment_comment_backflow_enabled = '1' or '0'
_moment_ai_assist_used         = '0' or '1'
_moment_created_from           = 'mobile'
```

## Action hooks (must fire in correct order)

```php
do_action('moment_register_connectors', $registry);  // on init
do_action('moment_published', $post_id, $moment_data);  // after successful post creation
do_action('moment_syndication_complete', $post_id, $results);  // after connector publish
do_action('moment_import_responses', $post_id, $network_id);  // backflow trigger
```

## REST endpoints

| Method | Path | Auth required |
|--------|------|--------------|
| POST | `/moment/v1/moments` | Yes — edit_posts + nonce |
| GET | `/moment/v1/moments` | Yes — edit_posts + nonce |
| POST | `/moment/v1/ai/suggestions` | Yes — edit_posts + nonce |
| POST | `/moment/v1/moments/{id}/sync-responses` | Yes — edit_posts + nonce |
| GET | `/moment/v1/notifications` | Yes — edit_posts + nonce |

## Security checklist (apply to every endpoint and form handler)

- [ ] `current_user_can()` before any write
- [ ] Nonce verified via `X-WP-Nonce` header
- [ ] Inputs sanitized with `sanitize_text_field()` / `wp_kses_post()` / `absint()`
- [ ] MIME type validated before `wp_handle_upload()` — not just extension
- [ ] All output escaped with `esc_html()` / `esc_attr()` / `esc_url()`
- [ ] No direct DB queries without `$wpdb->prepare()`
- [ ] No unauthenticated publishing endpoints

## Sub-agent directory

The phased build (Phases 0–9) used five specialist sub-agents under `.claude/agents/`
(`wp-php-core`, `moment-frontend`, `moment-syndication`, `moment-backflow`,
`moment-tester`). Those files were removed after 0.4.0: the phased build is complete,
they had drifted from the shipped code, and their durable guidance (identity, security
checklist, content model, hooks) already lives in this file and in CONTRIBUTING.md. The
original agent definitions live in git history (in the removed build-prompt doc
`docs/05_llm_prompt_build_prototype_claude_code.md`); the compacted design record
summarizes the phased build in its build-history section —
[docs/planning/README.md](docs/planning/README.md).

## Project artifact context

The prototype's planning docs (vision, product brief, MVP spec, routing, backflow,
content model, success metrics, visual/PWA, build history) were compacted into a
single design record after 0.4.0:

- **[docs/planning/README.md](docs/planning/README.md)** — condensed product
  vision, positioning, principles, non-goals, the type→destination routing model,
  the backflow model, success metrics, the 10 E2E acceptance scenarios, and
  visual/PWA intent. It flags where the original docs went stale vs shipped
  (connector removal, namespaced AI client, federation labeling) and maps each
  original doc to its new home. The 18 originals are in git history.

This file (CLAUDE.md) remains the authoritative **technical** record; the design
record is its product/vision/history companion.

## Non-goals (never build these in the prototype)

- Real social API publishing
- Real social comment/reply polling or webhooks
- Custom post type (unless hard constraint appears)
- AI provider API key storage
- Plugin marketplace or settings dashboard
- wp-admin chrome or site management features in Moment UI
- Multi-user or team workflows beyond standard WordPress roles
- Full offline PWA mode (manifest + conservative service worker only)
- Push notifications

## Strategic line

WordPress does not need to become a social network.
It needs to become the best place for social-shaped content to begin.
