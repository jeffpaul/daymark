# Daymark — CLAUDE.md
# Claude Code project memory for the Daymark WordPress plugin prototype.
# Place this file in your WordPress installation root (same level as wp-config.php).
# Update bracketed values after Phase 0 environment checks.

## Plugin identity

| Key | Value |
|-----|-------|
| Public product name | Daymark |
| Plugin name | Daymark |
| Post noun | **Mark / Marks** (a published post is "a Mark") |
| Previous name | Moment (≤ 0.5.0; renamed for wp.org — "Moment" was not approved) |
| Plugin slug | `daymark` |
| Plugin directory | `daymark/` |
| Main plugin file | `daymark/daymark.php` |
| Text domain | `daymark` |
| REST namespace | `/wp-json/daymark/v1/` |
| Block namespace | `daymark/*` |
| PHP class prefix | `Daymark_` |
| PHP namespace | `Daymark\` (optional alternative) |
| Action/filter prefix | `daymark_` |
| Shortcode prefix | `daymark_` |

**NEVER use in code**: `project-daymark`, `project_daymark`, `projectdaymark` — and never any
`moment`-based identifier (pre-0.6.0 name; also collides with the Moment.js library bundled in core).
The only allowed `moment` references are the migration/uninstall legacy keys and verbatim changelog history.

## Environment

| Key | Value |
|-----|-------|
| WordPress version | 7.1 (readme.txt "Tested up to" bumped from 7.0 on WP 7.1's release — the `7.0-beta3-61869` build noted here was the local wp70 install at Phase 0 setup; reconfirm the exact installed build next time wp70 is running) |
| PHP version | 8.2.27 (site) / 8.5.7 (CLI) |
| WP 7.0 AI Client available | **yes** — via `ai` plugin 0.4.1: class is `WordPress\AiClient\AiClient` (namespaced; the legacy `WP_AI_Client` name does NOT exist). Anthropic/Google/OpenAI provider plugins active. |
| Site URL | http://wp70.local |
| Plugin path | ~/Local Sites/wp70/app/public/wp-content/plugins/daymark (symlink → ~/GitHub/jeffpaul/daymark) |
| Local environment | Local by Flywheel (site: wp70) |

**Repo layout note:** This repo root IS the plugin. `daymark.php` lives at the repo root, and the repo is symlinked into the wp70 site's plugins directory as `daymark/`. The `docs/` directory and `.claude/` are excluded from distribution via `.distignore`. Runtime gates (`wp plugin activate`, `wp eval`) run from `~/Local Sites/wp70/app/public` and require the wp70 site to be started in Local.

## Build commands

```bash
# Activate/deactivate
wp plugin activate daymark
wp plugin deactivate daymark

# PHP tests (from the repo root)
composer install
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 7.0   # once per machine
WP_TESTS_DIR=$TMPDIR/wordpress-tests-lib composer test   # macOS ($TMPDIR); /tmp on Linux/CI

# WP-CLI smoke suite (57 assertions) against a live site with the plugin active
WP=/path/to/wp-cli-wrapper bash tests/smoke.sh

# PHP linting (WordPress Coding Standards, incl. tests + PHP 8.2+ compat)
composer phpcs        # plugin sources
composer phpcs-tests  # test suite (phpcs-tests.xml.dist)
composer phpcompat    # PHPCompatibility standard (testVersion 8.2-)

# Browser E2E (Playwright; needs a live site + admin creds)
npm ci && npx playwright install chromium   # once per machine
WP_BASE_URL=http://wp70.local WP_ADMIN_USER=<user> WP_ADMIN_PASS=<pass> npx playwright test

# Block editor bundle (src/index.js → committed build/, shipped in the zip)
npm ci && npm run build   # rebuild after editing src/; CI fails if committed build/ is stale
npm run start             # watch mode while developing the block editor UI
```

## Phase status

Update this after each phase completes. Do not mark DONE unless the phase gate passed.

- [x] Phase 0: Environment + CLAUDE.md verified (AI Client runtime check deferred to Phase 4 — requires wp70 running)
- [x] Phase 1: Plugin scaffold + activation (gate PASSED: activated cleanly; Daymark_Plugin exists; 5 section pages created; /daymark redirects unauthenticated → wp-login)
- [x] Phase 2: REST API + publisher (gate PASSED: unauthenticated GET /marks → 401; all 5 routes registered; note-Daymark publish smoke test created post with full meta)
- [x] Phase 3: Frontend app shell (gate PASSED: authenticated /daymark → 200, `<title>Daymark</title>`, daymarkApp config inlined, zero wp-admin/admin-bar references)
- [x] Phase 4: AI Assist adapter (gate PASSED: all keys present; REAL path live — Anthropic via core AI Client; mock fallback deterministic under wp_supports_ai=false)
- [x] Phase 5: Syndication routing + connectors (gate PASSED: image→instagram + note→bluesky defaults; 7 connectors registered; mock publish round-trip stores _daymark_external_posts and sets status to 'mocked')
- [x] Phase 6: Conversation backflow + notifications (gate PASSED + independently re-verified: sync imports labeled mock replies as standard WP comments; repeat sync deduped; notifications include 'Reply from Bluesky' item and exclude non-Mark post comments; non-Daymark sync → 404)
- [x] Phase 7: Blocks and shortcodes (gate PASSED: all 5 shortcodes + all 5 daymark/* dynamic blocks registered; /timeline page renders Marks; shortcode and block output byte-identical via shared renderer)
- [x] Phase 8: PWA + home screen (gate PASSED: manifest reachable + valid JSON; icon-192/512 PNGs generated from icon.svg and served 200; conservative SW at assets/daymark-sw.js — caches only app.css/app.js, never REST/nonces/admin/HTML)
- [x] Phase 9: E2E tests (gate PASSED: WP-CLI smoke suite tests/smoke.sh 57/57 against live wp70; PHPUnit scaffolded — needs WP test lib (`WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test`); Playwright scaffolded, not run — needs `npm i -D @playwright/test && npx playwright install chromium`)

## Post-phase hardening (0.7.0 prep)

Not a phase gate — a cleanup pass run after Phase 9, before release-readiness work:

- [x] **Code hygiene**: modernized legacy `isset() ?: ''` → `??`; added the PHPCompatibility standard (`composer phpcompat`), a test-suite PHPCS ruleset (`composer phpcs-tests`, `phpcs-tests.xml.dist`), and the matching CI jobs.
- [x] **Security hardening**: `Daymark_Rate_Limiter` on AI/publish/sync REST actions; per-request upload total budget (`MAX_TOTAL_FILE_BYTES`, `daymark_upload_total_max_bytes`); `apply_alt_map` scoped to a Mark's own media (IDOR fix); shared 10-min sync cooldown for real connector references with an atomic `wp_cache_add` lock; `daymark_comment_import_approved` filter; AI prompt-injection guard; CSP header on the app shell (`daymark_app_content_security_policy`).
- [x] **Docs**: SECURITY.md supported-version table, CHANGELOG/readme.txt regeneration, CONTRIBUTING/CLAUDE build-command + security-checklist updates, plugin-check CI now blocking, `docs/roadmap.md` added (release-readiness → 0.8.x → long-term; linked from the design record).

## Key architectural decisions

Document decisions here as they are made. This is the authoritative record.

| Decision | Value | Rationale |
|----------|-------|-----------|
| Content model | Standard `post` post type | Portability; standard feeds/comments/templates |
| Custom post type | Not used | Deactivation safety; standard theme compat |
| Route strategy | Rewrite rule + `template_include` on a `daymark_app` query var | No page dependency; full control of app-shell markup (no theme chrome); clean `/daymark` PWA scope. Section pages (`/images`, `/videos`, `/audio`, `/notes`) are auto-created pages with shortcodes since those should render inside the theme. There is no public Timeline page — see the "Public Timeline page removed" row. |
| Block vs shortcode | **Both** — shortcodes as required baseline, dynamic blocks (`block.json` + `render.php`) as thin wrappers around a shared editor bundle (`src/index.js` → `build/`) | Activation pages already embed `[daymark_*]` shortcodes; MVP spec requires both; all query/markup logic lives once in `Daymark_Renderer::render()` so both surfaces emit identical HTML |
| WP 7.0 AI path | **Real** — `WordPress\AiClient\AiClient` via `wp_ai_client_prompt()`; provider Anthropic (first configured). Mock fallback when no provider is configured or any call fails. | Plugin requires WP 7.0+, so the AI Client is assumed present (no class/function existence shims). Detection: `wp_supports_ai()` + ≥1 `isProviderConfigured()`. Never throws, never blocks publishing. Legacy `WP_AI_Client` name does not exist — do not use it for calls. |
| JS framework | App shell: vanilla ES2020, no build step. Block editor UI (`src/`): compiled with `@wordpress/scripts` into the committed, shipped `build/` bundle. | The app shell stays build-free ("no npm required" for end users); the block editor is the one place Gutenberg demands a compiled script, so it carries the only build step. CI (`blocks-build` job) fails if the committed bundle drifts from `src/`. |
| Brand colors | Sunset: primary `#C93A06`, deep `#9E2A02`, light `#FFD9A8`, transparent `rgba(201, 58, 6, 0.12)` | Documented in [docs/brand.md](docs/brand.md) and applied throughout the app shell (`--daymark-accent*` custom props), views, manifest, theme-color, and icon. Banner and icon artwork carry the range as a red-to-gold gradient. |
| Destination visibility | Only **connected** connectors are offered as publish destinations; auto-applied type defaults filter to connected too (explicit API selections honored as-is). AI Assist UI only renders when a provider `is_available()`. No demo-mode filter — the site itself is always the canonical destination. | A destination that can't publish or return replies shouldn't be offered. No connector ships in-repo; the connector interface + `daymark_register_connectors` hook is the extension point and is covered by PHPUnit (`test-syndication.php`), not E2E. Model defaults stay recorded in `_daymark_default_destinations`. |
| Companion connectors removed | The `moment-connector-bluesky`/`-mastodon` plugins (pre-rename naming) were removed from the repo (not published to wp.org). Real syndication rides on existing ecosystem plugins instead: publicize-style plugins via `Daymark_Publish_Helpers` (awareness + per-Mark toggles) and federation plugins via `Daymark_Federated_Comments`. ATmosphere is a controllable toggle (its documented `atmosphere_disabled` opt-out meta), so Bluesky gets an in-app on/off when ATmosphere is connected and auto-publishing. | Leaner repo; aligns with the "no real social API publishing in core" non-goal. The open connector hook remains for any plugin to register a first-class destination. |
| Destination memory | Explicit per-type selections are remembered in `daymark_destination_prefs` user meta and win over model defaults on the next publish of that type (explicit empty = "none" is remembered too). | Publishing habits differ per person and per content type; `Daymark_Publisher::get_effective_defaults()` is the single resolution point used by both the app shell and the publisher's no-selection fallback. |
| Automatic backflow | Replies sync without user action: hourly WP-Cron (`daymark_backflow_sync`) over recent Marks with real `backflow_supported` references, plus an async freshen when notifications are viewed (staleness transient, `daymark_backflow_freshen_window`). Mock references never auto-sync. Per-post 10-min cooldown; look-back window `daymark_backflow_sync_days` (14). | Real-time-or-automatic beats a manual sync button; push channels (federation plugins) are already live, so this covers only the API-polling connectors. Schedule self-heals on init for already-active installs. |
| Rename to Daymark (0.6.0) | The plugin shipped as **Moment** through 0.5.0; wp.org review did not approve that name and approved **Daymark**. Posts are now **Marks**. Every identifier renamed (slug, text domain, REST `daymark/v1` + `marks` resource, blocks `daymark/*`, shortcodes `[daymark_*]`, hooks/meta `daymark_`/`_daymark_*` with marker `_daymark_is_mark`, classes `Daymark_*`, route `/daymark`) with **no back-compat bridges**. `Daymark_Migration` (guarded on the legacy `moment_version` option, run from activation + init@5) converted old installs in place — options, user meta, post/comment meta, section-page markup. Retired: soft-deprecated in 0.7.0, removed in 0.9.0, along with `uninstall.php`'s legacy `moment_*` cleanup block and `tests/test-migration.php` ([#36](https://github.com/jeffpaul/daymark/issues/36)). A site still on Moment (≤ 0.5.0) must upgrade through an intermediate 0.6.x–0.8.x release before jumping to 0.9.0 or later — there is no direct-jump conversion path anymore. | wp.org naming requirement; "moment" also collides with core's bundled Moment.js. Changelog history before 0.6.0 stays verbatim under the old name. |
| App URL is /daymark only (Unreleased) | `/daymark` (or `/daymark-app` when that slug is already owned by real site content) is the app's only real base, on fresh **and** migrated installs alike — `Daymark_Migration` no longer carries a migrated install's old Moment-era base (e.g. `moment`) into `daymark_app_base`. Instead it's kept under its own `daymark_legacy_app_base` option purely so `Daymark_Routes` can 301 that old URL (and any already-installed home-screen icon pointing at it) to wherever the app lives now. | The rename's own promise ("a home-screen-installed app URL should not move underneath its users") doesn't require the *old brand's slug* to keep serving the app forever — a transparent redirect honors that promise without leaving `/moment` as a second, parallel home for the app. |
| Federation backflow | `Daymark_Federated_Comments` labels replies delivered by ActivityPub (`protocol=activitypub`), ATmosphere (`protocol=atproto`), and Webmention (`protocol=webmention`) plugins at read time in notifications; reaction comment types (like/repost) are excluded (`type=comment` query). `Daymark_Syndication_Links` renders `u-syndication` markup on singular Mark posts for Bridgy backfeed. | Federation plugins store replies as native WP comments — Mark's storage model — so push-based backflow needs only labeling, not import. No dependency on the plugins; pure comment-meta detection (schemas verified from plugin source). |
| Subscriptions data model (issue #78) | `daymark_subscription` is a **custom DB table** (config: URL, feed URL, status, failure count, timestamps). `daymark_subscription_post` (cached content) is a **CPT** — the one deliberate exception to "Custom post type: Not used" above. | The "no CPT" rationale is about a Mark's own portability/deactivation-safety/theme-compat, none of which applies to someone else's cached, prune-able feed content. Unsubscribing relies on WordPress's own trash-and-7-day-retention lifecycle, and Timeline's date-sorted merge with Marks needs one `WP_Query` across both post types — both are free on a CPT and hand-built on a table. |
| Subscriptions polling cron (issue #78) | WP-Cron, matching the existing `daymark_backflow_sync` pattern. Not Action Scheduler. | Zero new dependencies, and this exact problem shape (periodic polling over a growing external set) already works on WP-Cron for backflow. Action Scheduler is tracked as a future option in [issue #79](https://github.com/jeffpaul/daymark/issues/79) if real usage shows WP-Cron isn't holding up. |
| Subscriptions favicon retrieval (issue #78) | Discover via `<link rel="icon">` in the site's `<head>` (the same fetch already made for feed autodiscovery), falling back to `/favicon.ico`. | Reuses a fetch that's already happening rather than adding a second request or a third-party favicon service, which would leak subscription targets to an external party. |
| Subscriptions `rel=me` config (issue #78) | Lives on WordPress's own native profile screen (Users → Your Profile) as user meta, not a new Daymark app-shell screen. `Daymark_Microformats` reads it when rendering h-card markup. | No existing app-shell screen fits it, and building one is real UI work for a single settings field. Format validation only this phase — no reciprocal-link verification. |
| POSSE-quality outbound microformats2 markup (issue #78) | Lives in a dedicated `Daymark_Microformats` class, not `Daymark_Renderer`. `Daymark_Renderer` only builds the aggregate `/images`, `/videos`, `/audio`, `/notes`, and app-shell views; a Mark's own permalink page is rendered by the active theme, so this hooks `post_class`/`the_title`/`the_content` the same way `Daymark_Syndication_Links` already does for that same theme-rendered path. | Keeps the aggregate-view renderer and the singular-permalink markup concerns in separate classes with separate hook surfaces, matching the precedent `Daymark_Syndication_Links` already set rather than overloading `Daymark_Renderer` with a rendering path it was never responsible for. |
| Public Timeline page removed (issue #78) | The `/timeline` section page, the `daymark/timeline` block, and the `[daymark_timeline]` shortcode no longer exist. `Daymark_Plugin::ACTIVATION_PAGES` no longer includes `timeline`; `Daymark_Plugin::remove_public_timeline_page()` (hooked on `init` at priority 5, before routes read `daymark_pages` at the default priority) hard-deletes an existing install's page — a genuine, deliberate exception to "never deletes user content," scoped to a page verified to still carry Daymark's own generated markup. Real 404, no redirect. Individual Mark permalinks and the site's own RSS/Atom feed are unaffected; the other four section pages (`/images`, `/videos`, `/audio`, `/notes`) are unaffected. | Timeline is now an interleaved, multi-source view (a user's own Marks plus subscribed sites' posts) — see "Subscriptions data model" above — and that concept only makes sense inside the authenticated app shell (Home). A public page under the same name showing something narrower (Marks only, no subscribed content) was confusing and redundant now that Home covers the real thing. |
| Subscribe-by-URL + subscription management (issue #78) | A wp-admin screen (Settings → Daymark, `Daymark_Admin_Subscriptions`), not the app shell. Plain form posts (`admin_post_{action}`, POST-redirect-GET), gated on `edit_posts` like every other Daymark permission check — not the wp-admin-conventional `manage_options`. Also reachable via a "Subscriptions" action link on the Plugins list screen. Subscribe/unsubscribe logic is shared with the REST endpoints (`Daymark_Subscriptions::subscribe_to_site()`, `::unsubscribe()`) so neither surface's behavior can drift from the other. | The general principle this confirms (see the non-goals note below): the app shell is for day-to-day operational use — reading the Timeline, publishing content — while configuration/settings that are touched rarely belong in wp-admin instead. A future pass may migrate this into an in-app settings screen, but the two stay deliberately separate for now. |

## Content model quick reference

```php
// Required post fields
post_type    = 'post'                     // NEVER 'daymark' custom type
post_status  = 'publish' or 'draft'       // based on capability
post_title   = generated from caption     // or timestamp fallback
post_content = block markup               // core/image, core/video, etc.

// Required post meta
_daymark_is_mark                = '1'
_daymark_primary_type           = image|video|audio|note|gallery|mixed
_daymark_media_ids              = JSON array of attachment IDs
_daymark_syndication_targets    = JSON array of selected connector IDs
_daymark_default_destinations   = JSON array of default connector IDs
_daymark_syndication_status     = not_attempted|mocked|queued|published|failed
_daymark_external_posts         = JSON object of external post references
_daymark_comment_backflow_enabled = '1' or '0'
_daymark_ai_assist_used         = '0' or '1'
_daymark_created_from           = 'mobile'
```

## Action hooks (must fire in correct order)

```php
do_action('daymark_register_connectors', $registry);  // on init
do_action('daymark_published', $post_id, $daymark_data);  // after successful post creation
do_action('daymark_syndication_complete', $post_id, $results);  // after connector publish
do_action('daymark_import_responses', $post_id, $network_id);  // backflow trigger
```

## REST endpoints

| Method | Path | Auth required |
|--------|------|--------------|
| POST | `/daymark/v1/marks` | Yes — edit_posts + nonce |
| GET | `/daymark/v1/marks` | Yes — edit_posts + nonce |
| POST | `/daymark/v1/ai/suggestions` | Yes — edit_posts + nonce |
| POST | `/daymark/v1/marks/{id}/sync-responses` | Yes — edit_posts + nonce |
| GET | `/daymark/v1/notifications` | Yes — edit_posts + nonce |

## Security checklist (apply to every endpoint and form handler)

- [ ] `current_user_can()` before any write
- [ ] Nonce verified via `X-WP-Nonce` header
- [ ] Inputs sanitized with `sanitize_text_field()` / `wp_kses_post()` / `absint()`
- [ ] MIME type validated before `wp_handle_upload()` — not just extension
- [ ] All output escaped with `esc_html()` / `esc_attr()` / `esc_url()`
- [ ] No direct DB queries without `$wpdb->prepare()`
- [ ] No unauthenticated publishing endpoints
- [ ] Expensive endpoints rate limited via `Daymark_Rate_Limiter` (AI / publish / sync actions; filter `daymark_rate_limits`)
- [ ] Uploads checked per file **and** per request total (`Daymark_Publisher::validate_file_list()`, `MAX_TOTAL_FILE_BYTES`; filter `daymark_upload_total_max_bytes`)

## Sub-agent directory

The phased build (Phases 0–9) used five specialist sub-agents under `.claude/agents/`
(`wp-php-core`, `moment-frontend`, `moment-syndication`, `moment-backflow`,
`moment-tester` — named for the plugin's pre-0.6.0 identity). Those files were removed
after 0.4.0: the phased build was complete, they had drifted from the shipped code, and
their durable guidance (identity, security checklist, content model, hooks) already
lived in this file and in CONTRIBUTING.md. The original definitions live in git history
(in the removed build-prompt doc `docs/05_llm_prompt_build_prototype_claude_code.md`);
the compacted design record summarizes the phased build in its build-history section —
[docs/planning/README.md](docs/planning/README.md).

Scaffolding was reinstated under `.claude/agents/` for the Subscriptions & Timeline
Following feature ([issue #78](https://github.com/jeffpaul/daymark/issues/78)), renamed
to current identity and rewritten to avoid the original failure mode: each file now
points to this document for anything durable rather than duplicating it, so a fact
changing here doesn't leave a stale copy behind in five other places.

- **`wp-php-core`** — REST endpoints, the publisher, the `daymark_subscription` table.
  (The public Timeline page removal and the POSSE/microformats2 markup work are both
  done — see the architectural decisions table.)
- **`daymark-subscriptions`** (new) — the inbound `Daymark_Subscription_Source`
  connector interface and registry, feed autodiscovery, favicon retrieval, the
  `daymark_subscription_post` CPT, content ingest rules, pruning, polling/refresh, and
  dead-feed detection.
- **`daymark-frontend`** (renamed from `moment-frontend`) — the app shell, plus Home's
  pull-to-refresh over every active subscription. (Subscribe-by-URL and subscription
  management ended up as a wp-admin screen instead — see the architectural decisions
  table — so that work isn't this agent's after all.)
- **`daymark-backflow`** (renamed from `moment-backflow`) — the notifications data
  layer, plus surfacing a dead-feed flag that `daymark-subscriptions` sets.
- **`daymark-tester`** (renamed from `moment-tester`) — unchanged remit, applied to all
  of the above.

`moment-syndication` (outbound connectors) was not recreated — nothing in this feature
touches outbound syndication. Add it back when work that does resumes.

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
- **[docs/design-principles.md](docs/design-principles.md)** — the Path / Day
  One / WordPress / modern-PWA rubric for what Daymark should *feel* like: what
  each of those four products lends and what part of each stays deliberately
  left behind (social feed mechanics, journal-app privacy model, platform
  lock-in, native chrome). Read it alongside the six product principles in
  planning §2 when judging a new feature or UI direction — this file's own
  Non-goals below are the enforced boundary; the design-principles doc is the
  rubric for staying in the right *feel* inside that boundary.

This file (CLAUDE.md) remains the authoritative **technical** record; the design
record is its product/vision/history companion.

## Product principles

Codified September 2026; supersedes the six-item list in
[docs/planning/README.md §2](docs/planning/README.md). This is the authoritative
list — use it as the lens for future product decisions, and cite the relevant
principle in a decision's rationale when it applies.

1. **Publishing comes before editing.** The composer's job is to get a Mark
   published, not to be a content editor. Refinement (per-image alt text,
   captions, titles) is available but never blocks or gates the publish action.
2. **Your site is the source of truth.** The site is always the canonical,
   required destination; every other destination is additive syndication, never
   a replacement — see "Destination visibility" and the content model above.
3. **Mobile is the primary creation experience.** The app shell is designed
   one-handed, phone-first, touch-first — vanilla ES2020 with no build step,
   generous tap targets, no wp-admin chrome. Desktop/tablet use works but isn't
   the design target.
4. **Media is first-class.** Images, video, audio, galleries, and notes are
   equal citizens with type-specific handling (per-image alt text, type-based
   destination routing, MIME validation, upload budgets) — not an attachment
   bolted onto a text post.
5. **Everything should be publishable in under 30 seconds.** Defaults do the
   work: type-based destination preselection, remembered destination
   preferences, minimal required fields, no forced onboarding. Every new
   required field or step is a cost to be justified against this.
6. **AI assists the creator, never replaces them.** AI Assist is optional,
   additive, and never blocks publishing — no configured provider means no AI
   UI at all, every suggestion is editable before it ships, and the mock
   fallback keeps the flow identical either way.
7. **Progressive disclosure: simple by default, powerful when needed.** The
   common path (one image, one tap) stays uncluttered; less-common power
   (destination overrides, categories, block editor settings, subscription
   management) surfaces only when relevant, or is pushed to wp-admin rather
   than crowding the app shell — see the non-goals note below.
8. **Every daymark is portable and future-proof.** A Mark is always a standard
   `post` with meta — never a bespoke format — so it survives the plugin,
   theme, or host being removed. This is why Marks specifically stay off
   custom post types even though `daymark_subscription_post` (someone else's
   cached content, not a Mark) is the deliberate exception.

## Non-goals (never build these in the prototype)

- Real social API publishing
- Real social comment/reply polling or webhooks
- Custom post type (unless hard constraint appears)
- AI provider API key storage
- A plugin marketplace, or a general-purpose settings dashboard built for its own sake
- wp-admin chrome or site management features inside the Daymark app shell's own UI — the app shell stays focused on day-to-day operational use (reading the Timeline, publishing content). Infrequently-touched configuration/settings (e.g. subscription management, `rel=me`) belong in wp-admin instead, outside the app shell — see the architectural decisions table for the confirmed instances of this. Not a ban on Daymark ever having a wp-admin screen; see the row above for the current thinking on eventually migrating some of this into an in-app settings screen.
- Multi-user or team workflows beyond standard WordPress roles
- Full offline PWA mode (manifest + conservative service worker only)
- Push notifications

## Strategic line

WordPress does not need to become a social network.
It needs to become the best place for social-shaped content to begin.
