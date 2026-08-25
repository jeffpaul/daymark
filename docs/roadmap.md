# Daymark — Roadmap

> The **future-facing** companion to the design record. Where
> **[docs/planning/README.md](planning/README.md)** explains where Daymark came
> from (vision, principles, build history) and **[CLAUDE.md](../CLAUDE.md)** is
> the authoritative record of how it is built today, this file is the shared
> view of where it is going next.

## How to read this roadmap

- **No dates.** Priorities move; versions don't. Buckets are "next up",
  "building on it", and "longer term", not release dates.
- **Principles bind it.** Anything proposed must serve the six principles in
  [planning §2](planning/README.md#2-principles) — Publish First, Mobile First,
  Ownership by Default, Portable by Design, AI Assist never AI First,
  Progressive Complexity — and must not cross the non-goals (§4 there).
- **Non-goals stay non-goals until explicitly overturned.** Real social-API
  publishing in core, full offline PWA, push notifications, a custom post type,
  a settings dashboard, and multi-user team workflows are *not* planned. A
  roadmap line that contradicts one needs a written decision first.
- **When a bucket changes, update CLAUDE.md.** The architectural-decisions
  table there is the authoritative record; this file is the plan.

---

## Now — release readiness (0.7.x)

Daymark is a healthy prototype (Phases 0–9 passed; hardening landed). The 0.7.x
series is about making it a clean, reviewable, releasable plugin — not new
product surface.

**Done (post-Phase hardening pass):**

- [x] Coding-standards suite now covers tests (`composer phpcs-tests`) and PHP
  8.2+ compatibility (`composer phpcompat`); CI runs both.
- [x] Security hardening: rate limiting on AI/publish/sync REST actions, a
  per-request upload byte budget, the alt-text IDOR fix, an atomic backflow
  cooldown, the comment-import approval filter, an AI prompt-injection guard,
  and a CSP header on the app shell.
- [x] Plugin Check is **blocking** in CI (was advisory).
- [x] Docs refreshed: SECURITY.md support table, CHANGELOG/readme.txt, and
  contributor + project-memory build/security notes.
- [x] The Playwright E2E suite now runs in CI as a blocking job (`Browser E2E`
  in `tests.yml`, across WP minimum/stable/nightly), no longer scaffolded-only.

**In flight / remaining:**

- [ ] Retire `Daymark_Migration`. Soft-deprecate it in the next minor, then
  remove it (with `uninstall.php`'s legacy block and `tests/test-migration.php`)
  once no supported install still ships a `moment_version` option.
- [ ] Ship **0.7.0** on wordpress.org. This includes the changelog/readme
  regen (already synced) and a clean Plugin Check run.
- [ ] First-party connector ecosystem docs: a worked example of
  `daymark_register_connectors` for plugin authors, published alongside the
  hooks reference.

---

## Next — building on the loop (0.8.x)

The product's core is "fast publish, site-first". These directions deepen that
loop without new destinations or a new social network.

- **Routing transparency.** The type→destination model works (image→Instagram,
  note→Bluesky, per-user memory). Next: make the effective routing for a Mark
  visible and editable *after* publish (a per-Mark "where did this go" view on
  the Mark, so backflow and distribution line up).
- **Backflow, not just import.** Replies already return as native comments with
  source labels. Next: thread them per conversation in notifications, filter by
  source, and make the sync cadence/cooldown observable instead of implicit.
- **Connector ecosystem.** The extension seam exists (`daymark_register_connectors`).
  Grow it deliberately: a documented reference connector, a registry of known
  connectors, and graceful in-app messaging when a Mark's destination plugin is
  deactivated.
- **Publish-loop polish.** Larger media sources (photo picker, drag-and-drop on
  desktop), gallery reordering, and draft → publish continuation are all
  candidates — each judged by whether it makes publishing faster (Publish First),
  not more powerful.
- **i18n readiness.** The plugin is en_US-only today. Wire the text domain into
  a translation scaffold so translators can work before a multilingual release,
  without changing any shipped strings' behavior.

---

## Longer term

Directional, not commitments. Each needs a written decision (and a CLAUDE.md
decision-table row) before it becomes "next".

- **Default-on gravity.** The thesis is a third mode next to Admin and Editor.
  Long-term: make Daymark the default recommendation for social-shaped posting —
  surfaced in onboarding, discoverable from wp-admin without being wp-admin, and
  functional the moment the plugin activates (it already is).
- **Offline publishing (revisit).** Today a non-goal (conservative SW only). If
  drafts + publish queue move to the client, revisit the non-goal with a written
  decision: offline-first is the natural Publish-First endpoint for mobile.
- **Measured success.** The candidate signals in
  [planning §10](planning/README.md#10-success-metrics--e2e-acceptance) — first-
  publish completion, time-to-first-Mark, repeat publishing — stay unmeasured by
  design (no analytics dashboard). Long-term, decide what *privacy-respecting*
  signal (if any) is worth adding.
- **Hosted Daymark — provenance only.** The consumer publishing product explored
  at planning time (see [planning §12](planning/README.md#12-optional-future-hosted-moment-not-built))
  is explicitly **not** on this roadmap. It is recorded as a candidate direction,
  with its guardrails (no proprietary storage, no single-host lock-in, no single
  mandated AI provider, no CPT packaging) intact if it is ever revisited.

---

## Not planned (unless a decision changes)

- Real social-API publishing in core — Daymark cooperates with ecosystem plugins
  instead.
- A custom post type — Marks are standard `post`s; that is a product promise.
- A settings dashboard, analytics dashboards, or wp-admin chrome inside Daymark.
- Push notifications and multi-user team workflows beyond standard WordPress roles.
- API-key storage — AI rides the WordPress 7.0 AI Client or nothing.
