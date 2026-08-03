# Contributing to Moment

Thanks for your interest in improving Moment — a phone-first publishing
experience for WordPress. Contributions of all kinds are welcome: bug
reports, fixes, tests, docs, and new connectors.

This project follows a [Code of Conduct](CODE_OF_CONDUCT.md); by
participating, you are expected to uphold it.

## Ways to contribute

- **Report a bug or request a feature** via
  [GitHub issues](https://github.com/jeffpaul/moment/issues). Include your
  WordPress and PHP versions, the steps to reproduce, and what you expected
  to happen.
- **Open a pull request** for fixes and improvements (see
  [Pull requests](#pull-requests) below).
- **Build a connector.** Moment's adapter layer
  (`moment_register_connectors` + `moment_import_network_responses`) is open
  to any network — a companion plugin can register a real destination
  without changing Moment core.

## Repository layout

This repository **is** the plugin: `moment.php` lives at the repo root and
the repo is symlinked into a WordPress install as `wp-content/plugins/moment`.

| Path | What it is |
|---|---|
| `moment.php`, `includes/`, `templates/`, `assets/`, `blocks/` | The Moment plugin itself |
| `tests/` | PHPUnit tests, the WP-CLI smoke suite (`tests/smoke.sh`), and Playwright E2E (`tests/e2e/`) |
| `wp-hooks-docs/` | Docusaurus site for the generated hook reference |
| `docs/` | Product/spec documents (excluded from the distributed plugin) |

`.distignore` controls what is excluded from the built plugin zip (tests,
tooling, and docs are all excluded).

## Development setup

### Requirements

- **WordPress 7.0+** (Moment targets the 7.0 Connectors API and AI Client)
- **PHP 8.1+**
- **Composer** (PHP dependencies, PHPUnit, and coding standards)
- **Node.js 18+** (only for the Playwright E2E suite)
- A local WordPress site. [Local by Flywheel](https://localwp.com/) is what
  the plugin is developed against, but any local WP 7.0 works.

### Install

Symlink the repo into your site's plugins directory and activate:

```bash
ln -s /path/to/moment /path/to/wp/wp-content/plugins/moment
wp plugin activate moment
```

Then visit `/moment` on a phone-sized viewport while logged in.

## Testing

All changes should keep the suites green and add coverage for new behavior.

### PHPUnit

```bash
composer install

# Once per machine — installs the WordPress test library:
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 7.0

# Run the suite (macOS uses $TMPDIR; Linux/CI use /tmp):
WP_TESTS_DIR=$TMPDIR/wordpress-tests-lib composer test
```

### Coding standards (PHPCS / WordPress Coding Standards)

```bash
composer phpcs        # report violations
composer phpcs:fix    # auto-fix what phpcbf can
```

The ruleset is `phpcs.xml.dist`. A clean PHPCS run is required to merge.

### WP-CLI smoke suite

Runs a broad set of assertions against a live site with the plugin active:

```bash
WP=/path/to/wp-cli-wrapper bash tests/smoke.sh
```

### Browser E2E (Playwright)

```bash
npm ci && npx playwright install chromium   # once per machine

WP_BASE_URL=http://yoursite.local \
WP_ADMIN_USER=admin WP_ADMIN_PASS=password \
npx playwright test
```

The E2E suite needs a live WordPress with pretty permalinks and an admin
account. Some scenarios exercise the *connected* connector path against a
stubbed AT Protocol API — see the setup notes at the top of
`tests/e2e/moment.spec.js` for the fixtures and options involved.

## Coding standards and conventions

- **WordPress Coding Standards** for all PHP (enforced via PHPCS).
- **Naming is strict.** Use the plugin's own identifiers everywhere:
  - Slug / text domain: `moment`
  - REST namespace: `/wp-json/moment/v1/`
  - Block namespace: `moment/*`
  - PHP class prefix: `Moment_` · Action/filter/shortcode prefix: `moment_`
  - **Never** use `project-moment`, `project_moment`, or `projectmoment`.
- **Content model:** every Moment is a standard `post` with `_moment_*` post
  meta — never a custom post type. Portability is a core promise.
- **Frontend is vanilla ES2020**, no build step. Keep it framework-free.
- **Security checklist** for every REST endpoint and form handler:
  capability check before any write, nonce verified via the `X-WP-Nonce`
  header, inputs sanitized, output escaped, MIME validated from file content
  (not the extension), and no unauthenticated publishing endpoints.

## Hook documentation

Public hooks are documented from their docblocks and rendered by the
Docusaurus site in `wp-hooks-docs/`, published at
<https://jeffpaul.github.io/moment/>. When adding or changing a hook, write a
clear docblock — and **avoid curly braces `{ }` in hook docblock prose**, as
MDX parses them as JavaScript and will break the docs build. (Describe array
shapes in words rather than `array{...}` syntax in hook docblocks.)

## Pull requests

1. **Branch off `main`** and open your PR against `main`.
2. **Keep CI green.** Every PR runs CI, Tests, Plugin Check, and Hooks Docs;
   all must pass before merge.
3. **Add or update tests** alongside behavior changes (PHPUnit for
   PHP/REST, Playwright for user-facing flows).
4. **Write clear commit messages** with an imperative subject line
   (e.g. "Add per-image alt text"), and a body explaining the why when it
   isn't obvious.
5. **Update docs** (`README.md`, `readme.txt`, and this file) when you
   change user-facing behavior or the development workflow.
6. **Write the description as the commit message.** Merges here are squashed
   using the PR title and description verbatim, so that text becomes
   permanent git history. Say what changed and why, note anything you
   verified by hand, and delete the template's HTML comment (and the
   `Fixes #` line if there is no issue). The PR template is deliberately
   almost empty for this reason — the review checklist, the Playground
   preview button, and contributor credit are all posted or maintained
   automatically, so review scaffolding never reaches the commit message.

### Crediting contributors

We credit everyone who helps — code, testing, review, or ideas — not just the
person who wrote the commits. This repository squash-merges with the **PR title
and description** as the commit message, so list contributors as
`Co-authored-by:` trailers at the **end of the PR description**. They then land in
the merge commit and on the GitHub contributor graph:

```
Co-authored-by: Ada Lovelace <12345+ada@users.noreply.github.com>
```

Use each person's GitHub no-reply email — `https://api.github.com/users/USERNAME`
gives their numeric ID. Keep these lines last, with nothing after them.

Most of this is automated: the **Credit contributors** workflow
(`.github/workflows/credit-contributors.yml`) maintains a `Co-authored-by:` block at
the end of the PR description covering everyone who commits to, reviews, or comments
on the PR — plus the authors and commenters of its linked issues. You only need to
add someone by hand when they can't be detected (for example, off-GitHub help).

## Changelog

`CHANGELOG.md` is the source of truth for what changed, and it follows
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/). Add an entry
under `## [Unreleased]` in the same PR as your change, grouped under one of the
standard headings — **Added**, **Changed**, **Deprecated**, **Removed**,
**Fixed**, **Security**:

```markdown
## [Unreleased]

### Added

- Optional Title field for audio and video Moments… ([#28](https://github.com/jeffpaul/moment/pull/28))
```

Write for the person using Moment, not for the repository. "You can now search
your Moments from Home" beats "implement search endpoint" — no commit hashes and
no internal file names.

End each entry with a link to its pull request, for traceability. Use an explicit
markdown link rather than a bare `#28`, which does not autolink in a rendered
file, and list more than one where a change genuinely spanned PRs. These links
are stripped automatically when `readme.txt` is generated, so keep them here.

`readme.txt`'s `== Changelog ==` section is **generated** from `CHANGELOG.md` —
never edit it by hand. wordpress.org's readme format has no `###` headings, so
the generator renders each category as a bold label instead:

```bash
bin/sync-changelog.sh           # regenerate readme.txt from CHANGELOG.md
bin/sync-changelog.sh --check   # what CI runs; fails with a diff if stale
```

CI fails if the two have drifted, so wordpress.org can't end up showing a stale
changelog.

## Releasing

Releases are tag-driven: pushing a version tag builds the distribution zip and
publishes the GitHub release (`.github/workflows/release.yml`).

1. **Open a release PR** that bumps the version in all four places — the
   `Version:` header and `MOMENT_VERSION` in `moment.php`, `Stable tag:` in
   `readme.txt`, and `package.json`. The release workflow fails the build if
   these disagree with the tag.
2. **Close out the changelog.** Rename `## [Unreleased]` to
   `## [X.Y.Z] - YYYY-MM-DD` (ISO 8601), add the version's compare link to the
   reference block at the bottom of the file, start a fresh empty
   `## [Unreleased]`, then run `bin/sync-changelog.sh` and commit the
   regenerated `readme.txt`. Add the `readme.txt` upgrade notice too — that one
   is hand-written, since it is a short wordpress.org-specific summary rather
   than a changelog.
3. **Keep the release PR description short.** It becomes the commit message the
   tag points at, which is what anyone browsing the tag list reads first. Put
   post-merge steps in a PR comment rather than the description — or pass an
   explicit message at merge time:
   ```bash
   gh pr merge <n> --squash --subject "Release X.Y.Z (#<n>)" --body-file notes.md
   ```
4. **Tag the merge commit lightweight** — `git tag X.Y.Z <sha>`, never
   `git tag -a`. GitHub shows a lightweight tag's verification from the
   underlying commit, and squash merges here are GitHub-signed, so the tag reads
   as Verified. An unsigned annotated tag reads as Unverified instead.
5. **Push the tag.** The workflow verifies the version, builds the zip, checks
   that nothing untracked or dev-only leaked into it, and creates the release
   with notes from the changelog. It is safe to re-run against an existing
   release: the zip is re-uploaded and hand-written notes are left alone.

## License

Moment is licensed under **GPL-2.0-or-later**
([GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)). By
contributing, you agree that your contributions are licensed under the
same terms.
