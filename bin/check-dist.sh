#!/usr/bin/env bash
#
# Checks what Daymark ships, so a development-only file can't ride along in a
# release.
#
# Daymark is handed out two ways, and each has its own list of what to leave
# out:
#
#   - the plugin zip (the GitHub release asset, and the tree uploaded to
#     wordpress.org), built with rsync and .distignore — see
#     .github/workflows/release.yml
#   - GitHub's "Source code" archives for a tag, built by `git archive`,
#     which skips whatever .gitattributes marks export-ignore
#
# This fails when:
#
#   - the tracked payloads differ (apart from source-only runtime manifests),
#     which means a path is in one list
#     and not the other
#   - a Markdown file would ship at the top of the plugin. Notes for people
#     working on Daymark (README.md, CONTRIBUTING.md, a one-off write-up)
#     belong in both lists or under docs/; site owners get readme.txt
#
# It reads the files git has staged (the same as the last commit on a clean
# checkout, which is what CI has), so `git add` a change before running it.
#
# Usage: bin/check-dist.sh

set -euo pipefail

cd "$(dirname "$0")/.."

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Every staged file, written out with nothing left out yet.
git checkout-index --all --prefix="$work/src/"

# The tracked distribution payload; build-dist.sh adds the locked runtime.
mkdir "$work/zip"
( cd "$work/src" && rsync -a --exclude-from=.distignore --exclude='.git' ./ "$work/zip/" )
( cd "$work/zip" && find . -type f | sed 's|^\./||' | LC_ALL=C sort ) > "$work/zip.txt"

# Source archives keep the runtime manifest/lockfile for Composer setup.
# The source archive's files. `git archive` reads .gitattributes from the tree
# it is given, so the staged copy is the one that counts.
git archive "$(git write-tree)" | tar -t | grep -v '/$' | grep -vE '^lib/framework/composer\.(json|lock)$' | LC_ALL=C sort > "$work/archive.txt"

status=0

only_zip=$(comm -23 "$work/zip.txt" "$work/archive.txt")
only_archive=$(comm -13 "$work/zip.txt" "$work/archive.txt")

if [ -n "$only_zip" ]; then
	echo "In the plugin zip but not in GitHub's source archive."
	echo "Either add these to .distignore, or take them out of .gitattributes:"
	echo "$only_zip" | sed 's/^/  /'
	status=1
fi

if [ -n "$only_archive" ]; then
	echo "In GitHub's source archive but not in the plugin zip."
	echo "Either add these to .gitattributes (export-ignore), or take them out of .distignore:"
	echo "$only_archive" | sed 's/^/  /'
	status=1
fi

stray=$(grep -E '^[^/]+\.md$' "$work/zip.txt" || true)

if [ -n "$stray" ]; then
	echo "Markdown files at the top of the plugin zip."
	echo "Add these to .distignore and .gitattributes, or move them under docs/:"
	echo "$stray" | sed 's/^/  /'
	status=1
fi

if [ "$status" -eq 0 ]; then
	echo "OK: distribution source and source archive agree on $(wc -l < "$work/zip.txt" | tr -d ' ') files."
fi

exit "$status"
