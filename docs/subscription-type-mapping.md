# Daymark — Subscription Type-Mapping Reference

> Companion to CLAUDE.md's own architectural-decision rows for each
> subscription source ("Subscription content-type inference", "Microformats2
> (h-entry/h-card) subscription parsing (issue #84)", "Prefer the WordPress
> REST API for WP-to-WP subscriptions (issue #137)", "Friends plugin as a
> subscription source (issue #88)") and to
> [docs/roadmap.md](roadmap.md). Those rows document *why* each source was
> built the way it was, one at a time, as each shipped; this file is the
> cross-source reference that answers a different question — *of everything
> a subscribed site can tell Daymark about a post, what actually reaches a
> Timeline card's `post_format`, and what doesn't?* Update it whenever a
> source's detection logic changes, a new source is added, or Daymark's own
> post_format/type vocabulary grows.

## Why this exists

Four built-in `Daymark_Subscription_Source` connectors each read a different
kind of signal from a subscribed site — a REST API's own real value, an RSS
enclosure, microformats2 markup, another plugin's already-computed
classification — but every one of them ultimately normalizes into the exact
same eight-key shape (`title`/`excerpt`/`author`/`published_at`/`permalink`/
`post_format`/`featured_image_url`/`raw_media`) so ingest and Timeline
rendering never need to know which source produced an item. That shared
contract is a strength (see CLAUDE.md's own reasoning for it), but it also
means it's easy for a signal one source *can* detect to quietly go unused,
either because a different source never learned to look for it, or because
Daymark's own `post_format` vocabulary (`image`\|`video`\|`audio`\|`gallery`\|
`note`\|`standard`) has no bucket for it at all. This document is the audit
that finds those gaps and tracks which ones have been closed.

## Registration order (precedence)

`Daymark_Subscription_Source_Registry::discover_feeds()` tries every
registered source in order and uses the first one that successfully
discovers something for a given URL — the mechanism behind every precedence
rule below. Current order, most-preferred first:

1. **`friends`** — a friend already followed via the Friends plugin, issue #88
2. **`wordpress`** — a working WordPress REST API, issue #137
3. **`feed`** — a traditional RSS/Atom feed (the original connector)
4. **`microformats`** — h-feed/h-entry markup with no feed at all, issue #84

## The mapping table

| Source | Real, structured per-item signal | How it becomes `post_format` | Content-sniffing fallback |
|---|---|---|---|
| `friends` | Friends' own already-assigned WordPress post_format taxonomy value (Friends does its own content-based format-discovery upstream, before Daymark ever sees the cached post) | `image`/`video`/`audio`/`gallery` pass straight through; `status`/`chat` map to `note`; `aside`/`link`/`quote`/unset all collapse to `standard` | **Yes** — `Daymark_Subscription_Content_Sniffer` scans the cached post's own content HTML only when the resolved format is `standard` (never for `note` — see "Follow-up: status/chat mapped to Note" below); a real Friends-assigned format is never second-guessed |
| `wordpress` | The subscribed site's real `format` field from `GET wp/v2/posts` | Same pass-through/`note`-mapping/collapse as `friends` | **Yes** — same shared sniffer, over `content.rendered`, only when `format` resolves to `standard` |
| `feed` | Media RSS `medium` (or MIME `type` prefix) on an RSS `<enclosure>` — `video`/`audio`/`image` counted directly, more than one image → `gallery` | Enclosure counts feed the same 5-way decision | **Yes** (original implementation; the sniffer now lives in a shared class other sources use too) — only when *no* enclosure carried any signal at all |
| `microformats` | mf2 `u-photo`/`u-video`/`u-audio` property elements on the h-entry itself, resolved to real URLs by a nesting-aware parser that excludes a nested `h-card`/`h-cite`'s own properties | Any video → `video`; any audio → `audio`; >1 photo → `gallery`; 1 photo → `image`; none → `standard` | **No, and none needed** — an h-entry's own mf2 markup already *is* the explicit signal every other source's fallback is trying to approximate; there is no "no enclosure" ambiguity to recover from |

All four sources share the exact same weighting once a signal is found:
video beats audio beats more-than-one-photo (gallery) beats one photo
(image) beats nothing (standard) — see
`Daymark_Subscription_Content_Sniffer::classify()` for the three
sniffer-based sources, and `Daymark_Subscription_Source_Microformats::
normalize()` for its own mf2-native equivalent.

## What this pass fixed

Auditing all four sources side by side surfaced one real, previously-unnoticed
gap, plus one inconsistency worth closing for its own sake:

- **`wordpress` had no content-sniffing fallback at all.** Its own docblock
  argued that reading the real `format` field meant it never needed to
  guess — true when a site actually assigns post formats, but most
  WordPress sites (especially on a block theme) never touch that taxonomy at
  all, so every one of their posts reported `format: 'standard'` regardless
  of what they actually contained. Since `wordpress` is now the
  *second*-preferred source (behind only `friends`), this meant a WP site's
  posts could be detected *less* accurately through the connector built
  specifically to read WordPress more accurately than the fallback `feed`
  connector already did for the exact same site. Fixed by extracting `feed`'s
  own sniffing logic into a new shared `Daymark_Subscription_Content_Sniffer`
  class and having `wordpress` fall back to it whenever `format` resolves to
  `standard` — mirroring the "a structured signal always wins, but `standard`
  isn't confirmed absence of media" pattern `friends` already used.
- **`friends`' own fallback only ever looked for a single plain `<img>`.**
  Upgraded to the same shared sniffer `feed` and `wordpress` now use, so a
  friend's cached post can be promoted to `video`/`audio`/`gallery`, not just
  `image`, when Friends itself assigned no format.

Both changes only ever *promote away from* an unconfirmed `standard` result;
neither ever overrides a real, explicitly assigned format from any source.

## Follow-up: status/chat mapped to Note

The first of the two vocabulary-expansion opportunities this audit flagged
(below) has since been acted on: WordPress's `status` and `chat` post_format
values now map to Daymark's own `note` post_format bucket in both
`wordpress` and `friends` (`DAYMARK_NOTE_FORMATS` on each class), rather than
collapsing to `standard` alongside `aside`/`link`/`quote`, which still do.
`note` is treated exactly like a real, confirmed media format — it never
enters the content-sniff fallback, so an incidental inline image on a
`status`-format post can't override it. `aside`/`link`/`quote` were left
alone: an aside is commentary on other content rather than its own update, a
link post is about the linked-to thing rather than the author's own words,
and a quote post centers someone else's words — none of the three read as
"the author's own short note" the unambiguous way `status`/`chat` do.

No changes were needed anywhere outside the two sources' own format
resolution: the poller (`sanitize_key()`, no fixed-vocabulary whitelist),
the REST layer (passes `post_format` through as stored), and the app shell
(`resolveCardKind()` already returns any non-`standard` value verbatim, and
`note` already has a full icon/label/rendering treatment since a Mark can
already be one) all already treated `post_format` as an open string, not a
hardcoded enum.

## Signals detected but not reflected in a Daymark type

One category of real signal is read by a source today and then discarded,
rather than lost to a gap in detection — a genuinely different situation
from the fixes above, and a product decision rather than a bug:

- **mf2 post-type discovery (`microformats` source).** Every h-entry already
  runs through the IndieWeb post-type-discovery algorithm
  (`detect_post_type()`), classifying it as `rsvp`/`reply`/`repost`/`like`/
  `bookmark`/`note` — but that classification is used *only* to pick a
  sensible fallback title (e.g. "Like") when the entry itself carries no
  `p-name`. It never affects `post_format`, by explicit design (see the
  issue #84 decision row: "Daymark's Timeline does not render the reply/
  like/repost semantic distinctly"). This is the most concrete opportunity
  in this whole audit to expand Daymark's own vocabulary: the detection
  already exists, fully computed, on every subscribed h-entry — the only
  missing piece is a place in Daymark's data model and Timeline card
  rendering for it to go. Notably, Daymark already has half of a mirror-image
  concept on the *outbound* side — a Mark's own `_daymark_in_reply_to`/
  `u-in-reply-to` (issue #83) — but nothing today reads an *inbound*
  subscribed reply back into any equivalent field.

This isn't a bug to fix — it's exactly the kind of "no natural equivalent"
case CLAUDE.md's decisions already name — but it's flagged here because it's
the most concrete remaining candidate if Daymark's own type vocabulary ever
grows to cover reply/reaction content from subscriptions the way it now does
for short-form status-style updates (see "Follow-up" above).

The other half of what this row originally flagged — `aside`/`link`/`quote`
still having no Daymark equivalent — is now a settled design choice rather
than an open question: see "Follow-up: status/chat mapped to Note" above for
why those three specifically were left collapsed to `standard`.

## Remaining gaps in detection itself

Lower priority than the two above, since none is a structured signal a site
is actively trying to communicate:

- **Media RSS enclosure mediums beyond image/video/audio** (e.g. `document`,
  `executable`) are never counted by `feed` at all — not classified, not
  sniffed, nothing. Given none has a Daymark equivalent either, this costs
  nothing today, but is worth knowing about if a future Mark type ever wants
  to represent a linked document/download.
- **A friend's own reply/like/repost signal**, if Friends tracks one
  internally for ActivityPub-sourced content, isn't read by
  `Daymark_Subscription_Source_Friends` at all — only `post_format` is. This
  is explicitly unverified: this source's own docblock already flags that it
  was researched against the public akirk/friends GitHub source rather than
  a live installation, so confirming whether such a signal even exists there
  needs a real Friends-active site to check against.

## When Daymark's own type vocabulary changes

If a future decision adds a new Mark type (or a new `post_format` bucket) to
represent any of the above, come back to this file and:

1. Add a row for the new value in the mapping table above.
2. Move it out of "Signals detected but not reflected" / "Remaining gaps"
   into the mapping table proper.
3. Update the affected source's own `normalize()` and its class docblock
   (search for `DAYMARK_MEDIA_FORMATS`/`DAYMARK_NOTE_FORMATS` — the format
   buckets each source currently hardcodes).
