#!/usr/bin/env bash
# Check that lib/vendor-prefixed/ is exactly what bin/build-bundled-libs.sh
# builds from lib/composer.lock. Run it right after a rebuild; CI does.
#
# Two kinds of difference are expected on every rebuild and ignored:
# - Composer picks a random suffix for its autoloader class names
#   (ComposerAutoloaderInit<hash>), so the new suffix is swapped back to
#   the committed one before comparing. Everything else in those files,
#   such as the class map, is still compared.
# - composer/installed.json and installed.php record how and from which
#   commit the build ran, not what it contains.
#
# Usage: bash bin/build-bundled-libs.sh && bash bin/check-bundled-libs.sh
set -euo pipefail

cd "$(dirname "$0")/.."

pattern='ComposerAutoloaderInit[0-9a-f]{32}'
committed="$(git show HEAD:lib/vendor-prefixed/autoload.php | grep -oE "$pattern" | head -n1 | sed 's/^ComposerAutoloaderInit//')"
built="$(grep -oE "$pattern" lib/vendor-prefixed/autoload.php | head -n1 | sed 's/^ComposerAutoloaderInit//')"
if [ -n "$committed" ] && [ -n "$built" ] && [ "$committed" != "$built" ]; then
    grep -rlZ -- "$built" lib/vendor-prefixed | xargs -0 sed -i "s/$built/$committed/g"
fi

paths=(lib/vendor-prefixed
    ':(exclude)lib/vendor-prefixed/composer/installed.json'
    ':(exclude)lib/vendor-prefixed/composer/installed.php')
changes="$(git status --porcelain --untracked-files=all -- "${paths[@]}")"
if [ -n "$changes" ]; then
    echo "::error::lib/vendor-prefixed/ does not match lib/composer.lock. Run bash bin/build-bundled-libs.sh and commit the result."
    echo "$changes"
    git diff --stat -- "${paths[@]}"
    exit 1
fi

echo "lib/vendor-prefixed/ matches lib/composer.lock."
