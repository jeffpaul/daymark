# RECOVERY.md — Daymark project state snapshot

Written 2026-08-25, ahead of a planned machine reformat. This is a
point-in-time snapshot, not living documentation — delete it once you've
read it, or once its content is stale.

## What this project is

Daymark — a phone-first "Personal Site Publisher Mode" WordPress plugin
(repo `jeffpaul/daymark`, previously named Moment through 0.5.0). Publish
images/video/audio/notes from `/daymark` on your phone; the site stays the
source of truth. Locally, the repo is symlinked into the `wp70` Local by
Flywheel site's plugins directory. Built with Claude Code, human-reviewed at
every phase; see `CLAUDE.md` for the full technical record and
`docs/planning/README.md` for product vision/history.

## State right now

- **0.7.0 is released** (GitHub and wordpress.org).
- Since then, the **Subscriptions & Timeline Following** feature (issue
  [#78](https://github.com/jeffpaul/daymark/issues/78), now closed) shipped
  the bulk of what it set out to do: subscribe to another site's RSS/Atom
  feed by URL (a wp-admin screen, Settings → Daymark), Home became the
  merged Timeline feed (your Marks + subscribed sites' posts), cross-Timeline
  search, scheduled + manual polling with pruning, dead-feed detection in
  notifications, and — most recently — removing the old public `/timeline`
  page/block/shortcode now that Timeline only makes sense authenticated.
  **Not done**: the outbound POSSE/microformats2 markup (h-entry/h-card,
  `rel=me`) — confirmed via grep that none of it exists in the codebase yet.
  It isn't tracked by any of the other follow-up issues (#79–#94) either.
- The PHP minimum was raised to 8.2 (from 8.1, which lost security support).
  `phpunit/phpunit` stays on `^9.6` — WordPress core's own PHPUnit test
  scaffold doesn't support PHPUnit 10+ yet, tracked in
  [#106](https://github.com/jeffpaul/daymark/issues/106).
- Both merged as of this snapshot:
  - **[#110](https://github.com/jeffpaul/daymark/pull/110)** — removed the
    public `/timeline` page/block/shortcode.
  - **[#111](https://github.com/jeffpaul/daymark/pull/111)** — brought
    `docs/roadmap.md` current with the shipped Subscriptions work.

## What was actively happening

Just finished responding to Autofix/CI feedback on #110 and #111 (the last
real fix was a missed E2E test, `tests/e2e/blocks-editor.spec.js`, that
still exercised the now-removed Daymark Timeline block) and confirming both
merged cleanly. No code changes in flight — this snapshot itself is the
last thing that happened, prompted by an upcoming machine reformat.

## Next 2–3 steps

1. Decide whether the leftover POSSE/microformats2 work (h-entry/h-card
   markup, a `rel=me` profile field, IndieWebify.me validation) gets its own
   tracking issue — #78 itself is closed, and nothing else covers it.
2. Pick the next feature to build. Nothing specific was chosen before this
   snapshot — `docs/roadmap.md`'s "Next — building on the loop" section has
   the standing candidates (per-Mark routing transparency, threaded backflow
   in notifications, a documented connector ecosystem, publish-loop polish,
   i18n readiness).
3. Some old local branches (see the git audit this snapshot came with) were
   pushed to `origin` as a precaution before the reformat but never went
   through a PR — worth a look to see if any are stray/safe to delete, or
   still-relevant work that got orphaned.

## Where to look for more

- `CLAUDE.md` — the authoritative technical record: architecture decisions,
  hooks, content model, security checklist.
- `docs/roadmap.md` — product direction, shipped-vs-open by feature.
- `docs/planning/README.md` — vision, principles, non-goals, build history.
- Issue #78's comment thread — the Subscriptions feature's full
  acceptance-criteria checklist and status.
