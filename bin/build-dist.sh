#!/usr/bin/env bash
# Build an installable plugin without committing generated runtime libraries.
# Usage: bash bin/build-dist.sh /tmp/daymark-dist
set -euo pipefail
cd "$(dirname "$0")/.."
destination=${1:?Usage: bash bin/build-dist.sh /tmp/daymark-dist}
if [ -d "$destination" ] && [ -n "$(ls -A "$destination")" ]; then
    echo "Distribution destination must be empty: $destination" >&2
    exit 1
fi
workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT
mkdir -p "$workdir/daymark" "$workdir/framework"
# Never copy a developer's possibly stale runtime into the release.
rsync -a --exclude-from=.distignore --exclude='.git' --exclude='/lib/framework/packages/' ./ "$workdir/daymark/"
cp lib/framework/composer.json lib/framework/composer.lock "$workdir/framework/"
bash bin/build-framework.sh "$workdir/framework"
mkdir -p "$workdir/daymark/lib/framework"
mv "$workdir/framework/packages" "$workdir/daymark/lib/framework/packages"
mkdir -p "$destination"
rsync -a "$workdir/daymark/" "$destination/"
