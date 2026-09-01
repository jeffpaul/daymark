# Daymark — Design Principles

> Companion to **[docs/planning/README.md §2](planning/README.md#2-principles)**,
> which sets the six *product* principles (Publish First, Mobile First,
> Ownership by Default, Portable by Design, AI Assist never AI First,
> Progressive Complexity). Those answer *what Daymark optimizes for*. This
> document answers a different question: *which existing products' feel is
> Daymark allowed to borrow from, and what part of each is explicitly left
> behind?* Use both together when judging a new feature, a UI direction, or a
> PR: the six principles say what wins; this rubric says what it should feel
> like while winning.

Daymark sits at the intersection of four influences. From each, it borrows a
specific *feeling* — never the whole product, and never the parts that would
turn Daymark into the thing it's explicitly not.

## Path — beautiful, calm, joyful publishing

**Borrow:**
- Beautiful visual storytelling
- Calm publishing — no feed pressure, no engagement chrome
- Media-first timeline
- Joyful creation

**Not the social network.** No like counts as a design focus, no algorithmic
ranking, no engagement loop. Daymark's publish flow should feel like Path's
"one photo, one moment" simplicity — not like optimizing a post for reach.
This is the visual/interaction half of **Publish First**: fast is necessary
but not sufficient, it also has to feel good. See
[docs/brand.md](brand.md) for the sunset palette this feeling is built on,
and the app shell's large tap targets — every interactive element sized off
a single `--daymark-tap-min: 44px` token, audited against hardcoded
smaller values (CLAUDE.md's "Touch-target / thumb-reach / gesture /
text-entry audit" decision) — and minimal chrome (Phase 3, CLAUDE.md).

## Day One — a sense of personal history

**Borrow:**
- Chronological browsing
- Calendar navigation
- Rediscovering older posts
- Automatic metadata
- Powerful search

**Not a private journal.** Day One's introspective, single-user framing stays
behind — Marks are published, portable WordPress posts, not encrypted diary
entries. What's worth borrowing is the *sense of accumulating personal
history over time*, not the privacy model. Today this shows up as
Timeline's cross-source search (Subscriptions & Timeline Following,
CLAUDE.md's architectural-decisions table); calendar navigation, richer
rediscovery ("on this day"), and automatic metadata surfacing (EXIF,
location, capture time) are not yet built — treat them as this principle's
open roadmap direction, not a shipped feature, and judge any proposal in
that space by whether it deepens *rediscovery of your own history* rather
than adding a new content-management surface.

## WordPress — the ownership model

**Borrow:**
- Your site is the canonical copy
- Extensible
- Portable
- Exportable
- Standards-based

**Hide almost everything else behind an advanced editor.** This is the one
influence Daymark inherits structurally, not just aesthetically: a Mark is a
standard `post` (never a custom post type — see CLAUDE.md's "Content model"
decision), syndication rides an open connector hook
(`daymark_register_connectors`), and outbound content carries real
microformats2 markup for portability off-platform. The corresponding
restraint is the app shell's own: no wp-admin chrome, no settings dashboard,
no plugin-marketplace UI inside Daymark's day-to-day surface (see the
Non-goals section of CLAUDE.md and "Not planned" in
[docs/roadmap.md](roadmap.md)). Power and configuration are always one tap
away in wp-admin's advanced editor — never inline in the fast-publish path.

## Modern PWAs — the experience, not the chrome

**Borrow:**
- Installable
- Offline capable
- Background uploads
- Share sheet integration
- Instant startup
- Responsive, thumb-friendly interactions

**Not native UI chrome.** Daymark should feel instant and app-like without
pretending to be a native app or reimplementing OS-level navigation
patterns. Today this is the manifest + conservative service worker (Phase 8:
caches `app.css`/`app.js` only, never REST/nonces/admin/private media — full
offline mode and background sync are explicit non-goals, see CLAUDE.md and
roadmap "Longer term"). Share sheet integration and background uploads are
open roadmap territory, not shipped; when they land, they should extend this
same restraint rather than pull in native-style chrome.

"Responsive, thumb-friendly interactions" is deliberately gesture-*friendly*,
not gesture-*first*: Home's pull-to-refresh (`bindPullGesture()`) is the app
shell's one real touch gesture — a deliberate exception, with no separate
Refresh button, because pulling down on a feed is an established enough
mobile convention to stand on its own. Everywhere else, a gesture is
considered only as a bonus layered on top of an existing tap-based action,
never as a hidden replacement for one: see the audit decision in CLAUDE.md
for why swipe-to-delete on the Timeline's ⋯ menu was considered and declined
on exactly this ground (its tap-based delete flow already exists and stays
the only path). The same audit is where "minimal text entry" (AI Assist
pre-filling caption/title/alt text, tap-to-pick tag autocomplete instead of
typing full tag names) lives as a named commitment rather than an implicit
side effect of the AI Assist principle.

## How to use this rubric

When evaluating a new feature, screen, or PR:

1. **Name the influence it's borrowing from.** If it doesn't map to one of
   the four above, it's probably out of scope for Daymark — check it against
   the Non-goals in CLAUDE.md and "Not planned" in
   [docs/roadmap.md](roadmap.md) before building it.
2. **Check it isn't also borrowing the part left behind** — engagement
   mechanics (Path), a private/single-user model (Day One), platform
   lock-in or hidden proprietary storage (WordPress), or native chrome
   (PWAs). Any of those is a sign the feature needs to be redesigned, not
   just tuned.
3. **Confirm it still serves one of the six principles** in
   [planning §2](planning/README.md#2-principles). This rubric shapes *feel*;
   those principles decide *whether it ships at all*.
