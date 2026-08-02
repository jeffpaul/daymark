# Moment — Design Record (compacted planning archive)

This is a single, compacted record of the planning documents written for the
Moment prototype (2026), preserved after the plugin shipped at **v0.4.0**. It
condenses ~4,500 lines of pre-build vision, spec, visual, and process notes into
the essence worth keeping.

**Scope of this file vs. others:**
- **[CLAUDE.md](../../CLAUDE.md)** is the live, authoritative *technical* record —
  plugin identity, content-model meta keys, architectural decisions, hooks, REST
  routes. Where this file and CLAUDE.md overlap, CLAUDE.md wins.
- This file holds what CLAUDE.md deliberately doesn't: the **product vision,
  positioning, principles, visual/PWA intent, success criteria, and build
  history** — plus a record of where the original planning docs went **stale
  relative to what shipped**.
- The full original planning docs (18 files) live in git history; see the
  [source map](#source-map) at the end.

---

## 1. Vision & positioning

Publishing to your own site *feels like work*; posting to social is effortless —
so social won attention and the open web lost. Moment closes that gap: a
**phone-first "personal site publisher" mode** for WordPress that makes posting
to your own site as fast as a social app, without giving up ownership.

It is a **third mode** alongside the existing two, and deliberately *not* mobile
wp-admin:

| Mode | Purpose |
|------|---------|
| Admin | Manage the site |
| Editor | Author long-form content |
| **Moment** | **Publish social-shaped content fast, from a phone** |

> **Strategic line:** *WordPress does not need to become a social network. It
> needs to become the best place for social-shaped content to begin.*

Supporting framing that captured the intent in demos: *"This is WordPress, but it
does not feel like managing WordPress."* And the constraint is the point — *"the
constraint is the feature."*

## 2. Principles

1. **Publish First** — every decision should make publishing faster, not more powerful.
2. **Mobile First** — designed for a phone in one hand, touch-first.
3. **Ownership by Default** — your site is the canonical source; social is distribution.
4. **Portable by Design** — content survives the plugin, theme, or host being removed.
5. **AI Assist, never AI First** — AI never sits between the person and the publish button.
6. **Progressive Complexity** — start with one tap; grow into full WordPress later.

## 3. Target users

- **Primary:** mobile-first creators who don't (yet) think of themselves as
  website owners — the audience social apps captured.
- **Secondary:** existing WordPress users who want a fast phone workflow for
  quick posts.

## 4. MVP scope & non-goals

**MVP:** a logged-in user opens `/moment`, picks camera-roll media or types text,
optionally runs AI Assist, and publishes a standard `post` with attached media —
then views it in feed-style screens (timeline / images / videos / audio / notes)
and a notifications screen. The simplest path is *one image + optional caption*.

**Non-goals (these define the product as much as the features):**

- No native mobile app; no wp-admin / Gutenberg replacement; no new social network.
- No theme builder, plugin marketplace, user-management dashboard, or analytics dashboards.
- No advanced/production media editing; no complex onboarding; no multi-user
  workflows beyond standard WordPress roles.
- No polished settings system.
- **No real syndication to external platforms** in core.
- **No real conversation backflow polling/webhooks** in core.
- **No real AI provider integration as a *requirement*** (and no API-key storage).
- **No custom post type** unless absolutely unavoidable.

*"Moment wins by refusing complexity."*

## 5. Content model

A Moment is a **standard WordPress `post`** — never a custom post type — for
portability, standard feeds/comments/templates, and deactivation safety. Media
are standard attachments; content is block markup (`core/image`, `core/gallery`,
`core/video`, paragraphs). State lives in `_moment_*` post meta.

> The authoritative, current list of meta keys and their values is in
> **[CLAUDE.md → Content model quick reference](../../CLAUDE.md)**. In short:
> `_moment_is_moment`, `_moment_primary_type`, `_moment_media_ids`,
> `_moment_syndication_targets`, `_moment_default_destinations`,
> `_moment_syndication_status`, `_moment_external_posts`,
> `_moment_comment_backflow_enabled`, `_moment_ai_assist_used`,
> `_moment_created_from`.

## 6. Syndication routing

The site is always the **required, canonical** destination; social destinations
are optional, preselected by Moment type, and editable per publish. Selections
are stored in post meta.

| Moment type | Default destination |
|-------------|---------------------|
| Note / text | Bluesky |
| Image | Instagram |
| Gallery | Instagram |
| Video | YouTube |
| Audio / Podcast | Configured audio/podcast destination |
| Mixed | Primary type / ask |

Connections sit behind an adapter layer: the `Moment_Syndication_Connector`
interface (`is_connected()`, `supports_moment_type()`, `publish()`), with the
`moment_register_connectors` action as the open extension point.

> ⚠️ **Stale vs. shipped:** the original docs described Moment shipping its *own*
> Bluesky/Mastodon connector plugins. **Superseded** — those companion connectors
> were removed before 0.4.0. Bundled connectors are mock-only; real syndication
> now rides on existing ecosystem plugins (publicize-style + federation), and the
> `moment_register_connectors` hook is the sole first-class extension seam.

## 7. Conversation backflow

Replies from elsewhere return to the original Moment post as **native WordPress
comments**, carrying `_moment_comment_*` meta and rendered with a source label
("Reply from Bluesky", "Comment from Instagram", "On-site comment"). The
`/moment` notifications screen shows on-site + returned responses **for Moment
content only** — comments on non-Moment posts are excluded by default,
server-side.

> ⚠️ **Stale vs. shipped:** the docs assume *mock/pull import only*. **Shipped
> 0.4.0 adds** read-time labeling of *push-delivered* federation comments
> (ActivityPub / ATproto / Webmention) — a mechanism the planning docs don't
> describe.

## 8. Key decisions & shipped-reality deltas

| Decision | As planned | As shipped (0.4.0) |
|----------|-----------|--------------------|
| Content vehicle | Standard `post`, no CPT | ✅ unchanged |
| Canonical source | Site always canonical; social optional | ✅ unchanged |
| Integrations | Adapter/connector layer, not hard-coded APIs | ✅ layer kept; **bundled network connector plugins removed**, delegated to ecosystem plugins |
| AI | Optional, never blocks publish, no key storage; "WP 7.0 AI Client" (generic) | ✅ optional/non-blocking; **specifically** the namespaced `WordPress\AiClient\AiClient` via `wp_ai_client_prompt()` + `wp_supports_ai()` detection (legacy `WP_AI_Client` name does **not** exist) |
| Backflow | Replies as WP comments | ✅ + read-time labeling of push federation comments |
| PWA | Best-effort, scoped to `/moment`; never cache REST/nonces/admin/private media | ✅ conservative SW caches only `app.css` / `app.js` |

## 9. Visual & brand / PWA

**Feel:** clean, calm, minimal, mobile-first — "fast publishing energy," a
personal-creator tool, **not** an enterprise dashboard and explicitly **not**
wp-admin. Large tap targets. Notifications must not look like comment-moderation UI.

**Brand:** the visual briefs specified *feel*, not exact colors (no palette was
fixed at planning time). The shipped brand accent is **purple `#7A00DF`**
(documented in CLAUDE.md/README). A simple app-style icon (`icon.svg` → 192/512
PNGs) supports "Add to Home Screen."

**PWA / home screen:** web app manifest (name `Moment`, start URL & scope
`/moment`, `display: standalone`, theme/background colors, standard icons);
service worker registered only on Moment routes with conservative caching. No
push notifications, no background sync (both explicit non-goals). Baseline
requirement was documenting "Add to Home Screen" for iOS Safari and Android Chrome.

> Historical / not shipped as described: a first-run onboarding flow (e.g. a
> `jane.moment.site` address picker, starter profile styles) and a set of named
> front-end patterns (Moment Timeline, Image Grid, Video Shelf, Audio Feed, Notes
> Stream, Profile Header). The shipped product uses section-page shortcodes/blocks
> instead. A reference mockup board image is preserved at
> [`assets/moment-reference-mockup-board.png`](assets/moment-reference-mockup-board.png).

## 10. Success metrics & E2E acceptance

**Candidate success signals** (to refine post-demo): first-publish completion
rate, time to first Moment, home-screen adoption, repeat publishing (3+/week),
Moment-type coverage, override-usage rate, AI-Assist usage, and comprehension of
routing / notifications / portability.

**The 10 E2E acceptance scenarios** (the shipped acceptance basis — mapped to the
smoke, PHPUnit, and Playwright suites):

1. `/moment` launches like an app — no wp-admin chrome.
2. Publish an image Moment (post + media + featured image + `_moment_is_moment`; shows in timeline/images).
3. Publish a note (text-only) Moment.
4. Moment type drives default destination preselection; "Your Site" always required.
5. Override destination defaults; selection stored, default persists.
6. AI Assist optional — graceful mock without a provider, real adapter path with one.
7. Returned responses appear on the post and in notifications, source-labeled.
8. Non-Moment post comments excluded from notifications by default.
9. Content is portable — survives plugin deactivation as a standard post + media.
10. The thesis is legible in under 60 seconds.

## 11. Build history

The prototype was built with **Claude Code** in a phased, gated sequence
(Phases 0–9), each phase ending in a bash verification gate that had to pass
before the next began. CLAUDE.md's phase-status list is the record of that run.

The build used five domain-specialist sub-agents (`wp-php-core`,
`moment-frontend`, `moment-syndication`, `moment-backflow`, `moment-tester`).
Those agent definitions and the two original build-prompt documents
(`05_llm_prompt_build_prototype.md`, the single-pass prompt; and
`05_llm_prompt_build_prototype_claude_code.md`, the multi-agent orchestration
prompt) were removed after 0.4.0 once the build was complete — they're preserved
in git history. Durable process lessons: **gate every phase**, and keep a single
resumable source of truth (CLAUDE.md).

## 12. Optional future: hosted Moment (not built)

Explored, explicitly *not* part of the plugin: a consumer publishing product
powered by WordPress — *"a hosted Moment plan is not cheap WordPress hosting… the
first post is the first site,"* around a ~$5/month tier. Firm guardrails if ever
pursued: no proprietary storage, no single-host lock-in, no single mandated AI
provider, and no custom-post-type packaging. Recorded here for provenance only.

---

## Source map

Each original planning doc and where its essence now lives. Originals are in git
history (removed when this record was added).

| Original doc | Essence now in |
|--------------|----------------|
| `00_README.md` | §4–7 here + CLAUDE.md |
| `00_README_addendum.md` | §11 (build history) |
| `01_blog_post_project_moment.md` | §1 (vision) |
| `02_one_page_product_brief.md` | §1–7 |
| `03_private_demo_storyline.md` | §1 (framing) |
| `04_prototype_mvp_spec.md` | §4 + CLAUDE.md |
| `05_llm_prompt_build_prototype.md` | §11 (+ git history) |
| `05_llm_prompt_build_prototype_claude_code.md` | §11 (+ git history) |
| `06_visual_mockup_brief.md` | §9 |
| `07_visual_mockup_prompts.md` | §9 |
| `08_decisions_and_open_questions.md` | §8 + CLAUDE.md |
| `09_default_syndication_routing.md` | §6 |
| `10_home_screen_and_pwa_instructions.md` | §9 (PWA) |
| `11_conversation_backflow_notifications.md` | §7 |
| `12_content_model_technical_path.md` | §5 + CLAUDE.md |
| `13_success_metrics_and_e2e_tests.md` | §10 |
| `14_private_demo_script.md` | §1 (framing) |
| `15_hosted_moment_concept.md` | §12 |
