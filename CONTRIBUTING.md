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
| `docs/` | Product/spec documents and the [codebase guide](docs/codebase.md) (excluded from the distributed plugin) |

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

## License

Moment is licensed under **GPL-2.0-or-later**
([GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)). By
contributing, you agree that your contributions are licensed under the
same terms.
