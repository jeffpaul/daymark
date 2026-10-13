#!/usr/bin/env bash
#
# Checks that Daymark's strings are ready for translate.wordpress.org.
#
# Runs WP-CLI's string extractor (`wp i18n make-pot`, the same extractor
# wordpress.org uses to build the plugin's translation template) over the
# plugin's PHP and JavaScript, then fails when:
#
#   - the extractor prints any warning, such as a placeholder with no
#     "translators:" comment, or one string with two different comments;
#   - any translation call uses a text domain other than `daymark`, or none,
#     since those strings would never pick up Daymark's translations.
#
# Needs WP-CLI on PATH (or WP=/path/to/wp). Writes nothing to the repo.
#
# Usage: bin/check-i18n.sh

set -euo pipefail

cd "$(dirname "$0")/.."

WP="${WP:-wp}"
EXCLUDE="vendor,tests,docs,node_modules,bin,test-results,wp-hooks-docs"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

make_pot() {
	# PHP deprecation notices from WP-CLI's own bundled libraries are noise
	# on newer PHP versions, so only the extractor's own lines are kept.
	"$WP" i18n make-pot . "$1" --exclude="$EXCLUDE" "${@:2}" 2>&1 \
		| grep -v -e '^PHP Deprecated' -e '^Deprecated' -e '^$' || true
}

output="$(make_pot "$TMP/daymark.pot" --domain=daymark)"
echo "$output"

status=0

if grep -q '^Warning:' <<<"$output"; then
	echo "::error::The string extractor printed warnings (above). Fix them so translators see one clear comment per string."
	status=1
fi

make_pot "$TMP/all.pot" --ignore-domain >/dev/null

daymark_count="$(grep -c '^msgid "' "$TMP/daymark.pot" || true)"
all_count="$(grep -c '^msgid "' "$TMP/all.pot" || true)"

if [ "$daymark_count" != "$all_count" ]; then
	echo "::error::$((all_count - daymark_count)) string(s) use a text domain other than 'daymark' (or none):"
	diff <(grep '^msgid "' "$TMP/daymark.pot" | sort -u) <(grep '^msgid "' "$TMP/all.pot" | sort -u) | grep '^>' || true
	status=1
fi

echo "Found $daymark_count translatable strings."
exit "$status"
