# Daymark — CLAUDE.md
# Claude Code project memory for the Daymark WordPress plugin prototype.
# Place this file in your WordPress installation root (same level as wp-config.php).
# Update bracketed values after Phase 0 environment checks.

## Working agreement: never merge PRs

Claude must never merge a pull request in this repo, under any circumstance —
not even when CI is green, review is approved, or the user previously approved
a merge in another session. Jeff is the final reviewer and merges every PR
himself. Open PRs as drafts, push fixes, respond to review/CI feedback, and
leave the PR ready — then stop and wait. If asked to merge, decline and
explain that merges are reserved for Jeff.

## Working agreement: commit attribution

Every commit made in this repo must end with two `Co-authored-by` trailers
(plus any session-tracking trailer the harness itself adds, e.g.
`Claude-Session:` — keep that too, don't drop it):

```
Co-authored-by: jeffpaul <jeffpaul@hotmail.com>
Co-authored-by: Claude Code (<model>) <noreply@anthropic.com>
```

`<model>` is the specific model that authored the commit (e.g. `Sonnet 5`,
`Opus 5`) — never a generic "Claude" with no model named, and never omitted.
`jeffpaul@hotmail.com` is Jeff's real, already-public git identity (visible
in this repo's own history, e.g. commit `0014748`) — use it verbatim rather
than guessing a `users.noreply.github.com` address, since that form only
resolves correctly if it matches the exact noreply style his account has
enabled. If a session's own system-level instructions specify a different
`Co-Authored-By` line for Claude, add these two trailers alongside it rather
than instead of it — both Jeff's line and the model-specific Claude line
must appear on every commit either way.

## Working agreement: changelog entries

Every PR with a user-facing change must add a `CHANGELOG.md` entry under
`## [Unreleased]`, in the same PR — see CONTRIBUTING.md's Changelog section
for the full format ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
category order, the PR-link convention). Keep the entry itself short — a
sentence or two, not a design writeup: the linked PR is where full detail
lives, so the entry only needs to say what changed and, briefly, why it
matters. Also paste that same entry verbatim into the PR template's own
`## Changelog` section — this repo squash-merges using the PR title and
description as the commit message, so this keeps `git log` on `main` as
scannable as the changelog itself instead of relying on whatever length of
prose the rest of the description happens to be.

## Working agreement: plain-language summaries

Every issue and PR Claude opens in this repo starts with a `## Summary`
section: two to four plain sentences that a middle-school student could
follow. Say what is wrong or what changes, who notices it, and why it
matters, with no class names, file paths, or jargon. The detailed write-up
goes below it. Jeff uses this summary to judge agent-written issues and PRs
in one read, so write it for him first. See CONTRIBUTING.md's "Start with a
plain-language summary" section, and the Summary fields in the issue forms
and PR template.

## Working agreement: translations

Daymark will be translated by volunteers on translate.wordpress.org, and
reaching many languages is a product priority. Every change must stay
translation-ready:

- Wrap every user-facing string, in PHP and in JavaScript, with the `daymark`
  text domain. Use placeholders instead of joining strings, `_n()` for counts,
  and a `/* translators: */` comment directly before the call.
- A new script with strings needs `wp_set_script_translations()` for its
  handle, or its strings stay in English.
- Run `bin/check-i18n.sh` before opening a PR. CI runs it too.
- Write CSS with logical properties (`margin-inline-start`, not
  `margin-left`), so right-to-left languages work. `bin/check-rtl-css.sh`
  fails on physical left/right CSS, and CI runs it; see the "Right-to-left
  layout" decision row below.

CONTRIBUTING.md has the full rules, and the "Translation-ready pass" decision
row below has the background.

## Mission

> Make publishing to your own WordPress site feel as joyful, immediate, and
> effortless as posting to the best mobile social apps, while ensuring
> everything you create remains yours.

This is the lens for every product and design decision in this repo — weigh a
new feature, a UI direction, or a PR against this sentence before anything
else. The eight product principles below are how it gets operationalized in
the codebase; the Path/Day One/WordPress/modern-PWA rubric in
[docs/design-principles.md](docs/design-principles.md) is how it gets
operationalized in *feel*; the Non-goals list is its enforced boundary. The
"Strategic line" at the end of this file is the same idea compressed into a
competitive-positioning tagline, not a separate or competing statement.

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
| WordPress version | 7.1 (readme.txt "Tested up to" bumped from 7.0 on WP 7.1's release — the `7.0-beta3-61869` build noted here was the local wp70 install at Phase 0 setup; reconfirm the exact installed build next time the wp71 site is running) |
| PHP version | 8.2.27 (site) / 8.5.7 (CLI) |
| WP 7.0 AI Client available | **yes** — via `ai` plugin 0.4.1: class is `WordPress\AiClient\AiClient` (namespaced; the legacy `WP_AI_Client` name does NOT exist). Anthropic/Google/OpenAI provider plugins active. |
| Site URL | https://wp71.local (HTTP redirects to HTTPS) |
| Plugin path | ~/Local Sites/wp71/app/public/wp-content/plugins/daymark (symlink → ~/GitHub/jeffpaul/daymark) |
| Local environment | Local by Flywheel (site: wp71 — renamed from wp70 after Phase 0 setup) |

**Repo layout note:** This repo root IS the plugin. `daymark.php` lives at the repo root, and the repo is symlinked into the wp71 site's plugins directory as `daymark/`. The `docs/` directory and `.claude/` are excluded from distribution via `.distignore`. Runtime gates (`wp plugin activate`, `wp eval`) run from `~/Local Sites/wp71/app/public` and require the wp71 site to be started in Local.

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
WP_BASE_URL=https://wp71.local WP_ADMIN_USER=<user> WP_ADMIN_PASS=<pass> npx playwright test

# Bundled runtime libraries (web-push-php, prefixed under Daymark\Vendor\)
bash bin/build-bundled-libs.sh   # rebuild lib/vendor-prefixed/ after changing lib/composer.json

# Block editor bundle (src/index.js → committed build/, shipped in the zip)
npm ci && npm run build   # rebuild after editing src/; CI fails if committed build/ is stale
npm run start             # watch mode while developing the block editor UI
```

## Key architectural decisions

The full record of what was decided and why (about 300 rows) lives in
[docs/architecture-decisions.md](docs/architecture-decisions.md). It is not
loaded automatically, so:

- **Before changing a feature, search that file for it** (feature name, issue
  number, class, or function). Code comments and docs that cite "CLAUDE.md's
  X row" or "the architectural decisions table" mean a row there.
- **Record each new decision there, not here**, one row per decision in the
  same Decision | Value | Rationale shape.

The phased build history (Phases 0 to 9 and the 0.7.0 hardening pass) is
summarized in [docs/planning/README.md](docs/planning/README.md) and git
history.

## Content model quick reference

```php
// Required post fields
post_type    = 'post'                     // NEVER 'daymark' custom type
post_status  = 'publish' or 'draft'       // based on capability
post_title   = generated from caption     // or timestamp fallback
post_content = block markup               // core/image, core/video, etc.

// Required post meta
_daymark_is_mark                = '1'
_daymark_primary_type           = image|video|audio|note|gallery|mixed|checkin
_daymark_media_ids              = JSON array of attachment IDs
_daymark_syndication_targets    = JSON array of selected connector IDs
_daymark_default_destinations   = JSON array of default connector IDs
_daymark_syndication_status     = not_attempted|mocked|queued|published|failed
_daymark_external_posts         = JSON object of external post references
_daymark_comment_backflow_enabled = '1' or '0'
_daymark_ai_assist_used         = '0' or '1'
_daymark_created_from           = 'mobile'
_daymark_transcript             = plain text (audio/video; AI-generated or hand-typed/edited)
_daymark_in_reply_to            = URL (set only via the Comment toggle's Webmention-preferred path on a subscribed post; rendered as u-in-reply-to)
_daymark_repost_of              = URL (set only via the "Repost" toggle on a subscribed post; rendered as u-repost-of)
_daymark_like_of                = URL (set only via the "Like" toggle on a subscribed post; rendered as u-like-of) — a Like Mark is stored as post_type 'daymark_like', never 'post' (see "Like Marks moved onto their own post type")
_daymark_activitypub_outbox_id  = int (ActivityPub plugin outbox post ID of the Like/Announce queued for this Like/Reblog Mark; its presence suppresses the matching Webmention)
_daymark_activitypub_activity_type = 'Like'|'Announce'

// Quiet metadata capture (all optional/best-effort — never required, never blocks publishing)
_daymark_captured_at            = MySQL datetime UTC (client-supplied or EXIF-derived)
_daymark_location               = JSON {lat, lng, accuracy?} (Check In Marks only)
_daymark_weather                = JSON {temperature, unit, condition, code}
_daymark_camera                 = JSON (non-empty subset of camera|aperture|iso|focal_length|shutter_speed)
_daymark_reading_time_minutes   = int (only when caption+transcript word count is substantial)
_daymark_place_name             = plain text (Checkin Marks only; reverse-geocoded, composer-editable place name)

// Featured Content (issue #401) — any post type with Featured Image
// (thumbnail) support that uses the block editor, NOT Mark-specific
_daymark_featured_content_type  = ''|audio|video|gallery|quote|link (link only honored on a Link-format post)
_daymark_featured_content       = JSON, keyed by content kind, e.g.:
                                   {"audio": {"source": "library", "attachment_id": 123}}
                                   {"video": {"source": "url", "url": "https://…"}}
_daymark_speech                 = JSON {file, hash, mime, truncated, created} — saved Read to me audio (file in uploads/daymark-speech/); any own post or followed-site post
_daymark_featured_content_image = JSON {url, width, height, source} — cached share image for a URL video/audio or link (issue #408); source = hash of the URL it came from

// Composer uploads (issue #483) — on the ATTACHMENT, only until a Mark uses it
_daymark_staged_upload          = user ID of the uploader (finished, not yet attached)
_daymark_staged_at              = Unix time it finished (cleanup after 7 days unused)
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
| POST | `/daymark/v1/marks` | Yes — edit_posts + nonce (and upload_files when `files[]` or `media_ids[]` is sent) |
| POST | `/daymark/v1/uploads` | Yes — edit_posts + upload_files + nonce (starts a chunked upload; `ACTION_UPLOAD` rate limit — see the resumable uploads decision) |
| GET | `/daymark/v1/uploads/{id}` | Yes — edit_posts + upload_files + nonce, owner only (404 otherwise) |
| POST/PUT | `/daymark/v1/uploads/{id}` | Yes — edit_posts + upload_files + nonce, owner only (appends one part) |
| DELETE | `/daymark/v1/uploads/{id}` | Yes — edit_posts + upload_files + nonce, owner only |
| GET | `/daymark/v1/marks` | Yes — edit_posts + nonce |
| POST | `/daymark/v1/ai/suggestions` | Yes — edit_posts + nonce |
| POST | `/daymark/v1/ai/title` | Yes — edit_posts + nonce |
| POST | `/daymark/v1/ai/alt-text` | Yes — upload_files + nonce |
| POST | `/daymark/v1/ai/transcript` | Yes — upload_files + nonce |
| POST | `/daymark/v1/ai/tags` | Yes — edit_posts + nonce |
| POST | `/daymark/v1/marks/{id}/sync-responses` | Yes — edit_posts + nonce |
| GET | `/daymark/v1/marks/{id}/content` | Yes — edit_posts + nonce (Timeline's own visibility rule, not per-post ownership — see the inline-expand decision below) |
| GET | `/daymark/v1/marks/{id}/featured-content-image` | Yes — edit_posts + nonce (same visibility rule as GET /marks/{id}/content; resolves a missing video/audio Featured Content thumbnail once, rate-limited — see the video card decision below) |
| GET | `/daymark/v1/marks/{id}/featured-content-link` | Yes — edit_posts + nonce (same visibility rule as GET /marks/{id}/content; returns a link Featured Content's Open Graph preview, resolving it once when not cached, rate-limited — see the link preview decision below) |
| GET | `/daymark/v1/notifications` | Yes — edit_posts + nonce |
| GET | `/daymark/v1/notifications/status` | Yes — edit_posts + nonce (unread flag only; marks nothing seen — see the Timeline UX pass decision) |
| POST | `/daymark/v1/subscriptions/refresh` | Yes — edit_posts + nonce (refresh every active subscription; one `ACTION_SUBSCRIPTION_REFRESH` charge — see the Timeline UX pass decision) |
| GET | `/daymark/v1/tags` | Yes — edit_posts + nonce |
| GET | `/daymark/v1/subscriptions/export` | Yes — manage_options + nonce (Settings -> Daymark's own capability; see the manage_options decision below) |
| POST | `/daymark/v1/subscriptions/import` | Yes — manage_options + nonce (same gate as export) |
| POST | `/daymark/v1/subscriptions/discover` | Yes — manage_options + nonce (finds a site's feeds for the app's Follow a site sheet; rate-limited) |
| POST | `/daymark/v1/subscriptions/follow` | Yes — manage_options + nonce (follows one feed from this user's last discovery, by index; an optional `site_url` must match that discovery's site) |
| POST | `/daymark/v1/bookmarks/{id}` | Yes — edit_posts + nonce (same "anything visible on this user's own Timeline" rule as GET /marks/{id}/content, not per-post ownership — see the Bookmarks decision below) |
| DELETE | `/daymark/v1/bookmarks/{id}` | Yes — edit_posts + nonce |
| GET | `/daymark/v1/bookmarks/{id}/image?url=` | Yes — edit_posts + nonce, and the post must be bookmarked by the current user with the URL in its content (see the off-site bookmark images decision) |
| GET | `/daymark/v1/subscription-posts/{id}/oembed` | Yes — edit_posts + nonce (same visibility rule as GET /subscription-posts/{id}; `target=reply` previews the post it replies to; see the Link-format oEmbed and Parse This decisions) |
| POST | `/daymark/v1/subscription-posts/{id}/comment` | Yes — edit_posts + nonce (same visibility rule as GET /subscription-posts/{id}; see the Comment-action decision below) |
| GET | `/daymark/v1/subscription-posts/{id}/replies` | Yes — edit_posts + nonce (same visibility rule as GET /subscription-posts/{id}; spends `ACTION_SUBSCRIPTION_POST_OPEN` only when the replies aren't cached; see the replies on followed posts decision) |
| POST | `/daymark/v1/subscription-posts/{id}/like` | Yes — edit_posts + nonce (same visibility rule as GET /subscription-posts/{id}; Jetpack-native or classic-Mark, resolved server-side — see the Jetpack-native Like/Comment decision below) |
| DELETE | `/daymark/v1/subscription-posts/{id}/like` | Yes — edit_posts + nonce (undoes whichever mechanism created the like, resolved entirely from the current user's own recorded state) |
| GET | `/daymark/v1/subscription-posts/{id}/like-availability` | Yes — edit_posts + nonce (whether a Like can reach this post's origin; rate-limited only when the answer isn't already cached — see the Like-availability decision above) |
| GET | `/daymark/v1/subscription-posts/{id}/engagement` | Yes — edit_posts + nonce (whether Like and Comment reach this post's site, and why not; spends `ACTION_SUBSCRIPTION_POST_FETCH` only when a lookup is needed — see the issue #561 decision) |
| POST | `/daymark/v1/notifications/plugin-overlaps/{plugin}/dismiss` | Yes — edit_posts + nonce (a pure local per-user write; see the plugin-overlap notification decision below) |
| GET | `/daymark/v1/location/reverse-geocode` | Yes — edit_posts + nonce (the Checkin composer's own Place-field lookup — see the Checkin type decision below) |
| GET | `/daymark/v1/location/search` | Yes — edit_posts + nonce (the Checkin composer's own Place-field search-as-you-type; same rate-limit bucket as reverse-geocode above — see the Checkin type decision below) |
| POST | `/daymark/v1/marks/{id}/speech` | Yes — edit_posts + nonce (same visibility rule as GET /marks/{id}/content; Read to me must be on; `ACTION_SPEECH` only when a new recording is made — see the Read to me decision) |
| POST | `/daymark/v1/subscription-posts/{id}/speech` | Yes — edit_posts + nonce (same as above, for a followed site's cached post) |
| GET | `/daymark/v1/featured-content/oembed` | Yes — edit_posts + nonce (the Featured Content editor panel's own live-preview lookup for a pasted URL; delegates to Daymark_Subscription_Oembed::resolve() — see the Featured Content decision below) |
| POST | `/daymark/v1/timeline/last-seen` | Yes — edit_posts + nonce (a pure local per-user write, no rate limit; see the Timeline reading position decision) |
| POST | `/daymark/v1/timeline/position` | Yes — edit_posts + nonce (a pure local per-user write, no rate limit; see the Timeline reading position decision) |
| POST | `/daymark/v1/interaction-hints/{hint}/seen` | Yes — edit_posts + nonce (a pure local per-user write, no rate limit; see the interaction-hint seen state decision) |
| POST | `/daymark/v1/webmention` | **No** — any site may send a Webmention; only registered while the built-in support is on. Checks the target locally, rate-limits per source host, and verifies later in WP-Cron (see the built-in Webmention decision) |
| POST/DELETE | `/daymark/v1/subscriptions/{id}/favorite` | Yes — manage_options + nonce (stars or unstars a followed site as a favorite; site-wide, like following) |
| POST/DELETE | `/daymark/v1/push/subscriptions` | Yes — edit_posts + nonce (saves or removes this device's push subscription for the current user; a local write, no rate limit) |
| POST | `/daymark/v1/push/subscriptions/replace` | **No** — unauthenticated background service worker update when browser rotates push address (issue #530); authorized by per-device capability token matching hashed user meta + user can `edit_posts`; IP rate-limited (`ACTION_PUSH_REPLACE`) |
| POST | `/daymark/v1/push/preferences` | Yes — edit_posts + nonce (which kinds of push the current user gets) |
| POST | `/daymark/v1/push/test` | Yes — edit_posts + nonce (sends a test push to the current user's devices; `ACTION_PUSH_TEST` rate limit) |
| GET/POST | `/daymark/v1/websub/{subscription_id}` | **No** — a WebSub hub calls this, not a Daymark user; see the WebSub decision above for what actually authorizes each method (a pending/topic match plus the callback token for GET, an HMAC signature for POST) |

## Security checklist (apply to every endpoint and form handler)

- [ ] `current_user_can()` before any write
- [ ] Nonce verified via `X-WP-Nonce` header
- [ ] Inputs sanitized with `sanitize_text_field()` / `wp_kses_post()` / `absint()`
- [ ] MIME type validated before `wp_handle_upload()` — not just extension
- [ ] All output escaped with `esc_html()` / `esc_attr()` / `esc_url()`
- [ ] No direct DB queries without `$wpdb->prepare()`
- [ ] No unauthenticated publishing endpoints
- [ ] Expensive endpoints rate limited via `Daymark_Rate_Limiter` (AI / publish / autosave / sync actions; filter `daymark_rate_limits`)
- [ ] Uploads checked per file **and** per request total (`Daymark_Publisher::validate_file_list()`, `MAX_TOTAL_FILE_BYTES`; filter `daymark_upload_total_max_bytes`); a chunked upload is checked at start, after its first part, and in full on finish, and a Mark only attaches the saving user's own staged uploads (`Daymark_Uploads::resolve_media_ids()`)
- [ ] Any outbound request goes through `Daymark_Outbound_Guard`, and a new one (or new data on an existing one) is listed under "External services" in `readme.txt`

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

- **[docs/architecture-decisions.md](docs/architecture-decisions.md)** — the
  authoritative record of every architectural and product decision (see "Key
  architectural decisions" above). Search it before changing a feature.
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
- **[docs/subscription-type-mapping.md](docs/subscription-type-mapping.md)** —
  the cross-source reference for how each built-in subscription source's own
  detected signals map (or don't yet map) to a Daymark `post_format`, and
  where Daymark's own type vocabulary would need to grow to use a signal a
  source already detects but currently discards. This file now holds the full
  detail for every type/format-mapping decision (the `feed` source's original
  content-sniffing pass, the cross-source audit, and both Note-mapping
  follow-ups) — docs/architecture-decisions.md carries only a one-line
  pointer to each.
- **[docs/ui-polish-history.md](docs/ui-polish-history.md)** — the full detail
  for narrow, visual/UX polish decisions (header-chrome alignment passes,
  Timeline card layout/spacing fixes, interaction-row refinements, and the
  now-superseded Timeline inline-expand era, among others), grouped by theme.
  Extracted from the decisions table to keep it focused on load-bearing
  architecture; each extracted row's own entry in
  docs/architecture-decisions.md is a one-line pointer into this file rather
  than its full original text.

This file (CLAUDE.md) and docs/architecture-decisions.md are the authoritative
**technical** record; the design record is their product/vision/history
companion.

## Product principles

Codified September 2026; supersedes the six-item list in
[docs/planning/README.md §2](docs/planning/README.md). This is the authoritative
list — use it as the lens for future product decisions, and cite the relevant
principle in a decision's rationale when it applies. Each principle is the
Mission statement above (also September 2026) broken into a concrete
implication for the codebase.

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
- Full offline PWA mode. Narrowed twice now: offline **creation** for a session already open when connectivity drops (issue #121), and a **cold load** of `/daymark` itself with zero connectivity (issue #126) — see "Offline-first creation" and "Cold-offline-load support" in the architectural decisions table for both. Still out of scope: general offline **reading** — Timeline/notifications/Explore/Search still require connectivity even on the offline-fallback shell (conservative SW only; only the shell itself, its static assets, and a redacted config are ever cached, never a REST response).
- A finished Explore, beyond its current "Browse by type" + "Following" foundation — memories, highlights, collections, favorites, recently-popular, and suggested accounts/content are future sections, not implied by what's there today
- Speculative AI or semantic search — Search stays keyword + explicit filters (type, author, date, tag, location, people followed are the planned filter set; only type and source exist today)

## Strategic line

WordPress does not need to become a social network.
It needs to become the best place for social-shaped content to begin.

The Mission statement at the top of this file is the same idea, stated as a
product goal rather than a competitive position — use that one as the lens
for evaluating new work.
