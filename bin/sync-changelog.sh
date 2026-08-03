#!/usr/bin/env bash
#
# Regenerate readme.txt's `== Changelog ==` section from CHANGELOG.md.
#
# CHANGELOG.md is the source of truth (Keep a Changelog 1.1.0). readme.txt needs
# the same content in wordpress.org's readme format, which supports `=` headings,
# lists, and bold — but not the `###` subheadings Keep a Changelog uses. So the
# generated section renders each category as a bold label instead:
#
#   CHANGELOG.md                readme.txt
#   ## [0.5.0] - 2026-08-03  →  = 0.5.0 - 2026-08-03 =
#   ### Added                →  **Added**
#   - An entry               →  * An entry
#
# The `[Unreleased]` section and the link-reference block are dropped: neither
# means anything on wordpress.org.
#
# Usage:
#   bin/sync-changelog.sh           # rewrite readme.txt in place
#   bin/sync-changelog.sh --check   # exit 1 with a diff if readme.txt is stale (CI)

set -euo pipefail

cd "$(dirname "$0")/.."

CHANGELOG=CHANGELOG.md
README=readme.txt
check_only=false

case "${1:-}" in
	--check) check_only=true ;;
	'') ;;
	*) echo "usage: $0 [--check]" >&2; exit 2 ;;
esac

for f in "$CHANGELOG" "$README"; do
	[ -f "$f" ] || { echo "error: $f not found" >&2; exit 1; }
done

# Convert the released sections of CHANGELOG.md into readme.txt changelog markup.
changelog_body() {
	awk '
		# Link-reference block ends the content we care about.
		/^\[[^]]+\]:[[:space:]]/ { exit }

		# Version heading: "## [0.5.0] - 2026-08-03" (skip "## [Unreleased]").
		/^## \[/ {
			line = $0
			sub(/^## \[/, "", line)
			sub(/\]/, "", line)
			if (line ~ /^[Uu]nreleased/) { started = 0; next }
			started = 1
			if (seen++) print ""
			print "= " line " ="
			next
		}

		!started { next }

		# Category heading: "### Added" -> "**Added**".
		/^### / {
			label = $0
			sub(/^### /, "", label)
			if (body) print ""
			print "**" label "**"
			print ""
			body = 0
			next
		}

		# Entry: "- text" -> "* text".
		/^- / {
			entry = $0
			sub(/^- /, "* ", entry)
			print entry
			body = 1
			next
		}

		# Continuation of a wrapped entry.
		/^[[:space:]]+[^[:space:]]/ && body { print; next }
	' "$CHANGELOG"
}

# Splice the generated body between the Changelog and Upgrade Notice headings.
render_readme() {
	changelog_body > "$tmp_body"
	awk -v body="$tmp_body" '
		/^== Changelog ==$/ {
			print
			print ""
			while ((getline line < body) > 0) print line
			print ""
			skipping = 1
			next
		}
		/^== / && skipping { skipping = 0 }
		!skipping { print }
	' "$README"
}

tmp_body=$(mktemp)
tmp_readme=$(mktemp)
trap 'rm -f "$tmp_body" "$tmp_readme"' EXIT

grep -q '^== Changelog ==$' "$README" || { echo "error: no '== Changelog ==' heading in $README" >&2; exit 1; }
grep -q '^== Upgrade Notice ==$' "$README" || { echo "error: no '== Upgrade Notice ==' heading in $README" >&2; exit 1; }

render_readme > "$tmp_readme"

if [ ! -s "$tmp_readme" ]; then
	echo "error: generated an empty $README; refusing to write" >&2
	exit 1
fi

if cmp -s "$tmp_readme" "$README"; then
	echo "$README changelog is in sync with $CHANGELOG."
	exit 0
fi

if [ "$check_only" = true ]; then
	echo "error: $README changelog is out of sync with $CHANGELOG." >&2
	echo "Run bin/sync-changelog.sh and commit the result." >&2
	echo >&2
	diff -u "$README" "$tmp_readme" >&2 || true
	exit 1
fi

cat "$tmp_readme" > "$README"
echo "Updated the $README changelog from $CHANGELOG."
