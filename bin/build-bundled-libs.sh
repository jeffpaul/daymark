#!/usr/bin/env bash
# Rebuild lib/vendor-prefixed/, the runtime libraries Daymark ships.
#
# Daymark has no build step for users, and WordPress Playground installs
# this repository straight from git, so the libraries are committed. Strauss
# renames every class under Daymark\Vendor\ so they can't collide with
# another plugin's copy of the same library.
#
# Usage: bash bin/build-bundled-libs.sh   (needs composer)
set -euo pipefail

cd "$(dirname "$0")/../lib"

rm -rf vendor vendor-prefixed
composer install --no-interaction --no-progress
php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/strauss
rm -rf vendor
# Keep licenses; drop repository chrome that isn't code. Strauss 0.31+
# copies each package's docs and metadata too; none of it is loaded at
# runtime. vendor-prefixed/composer/ is Composer's own autoloader, so it's
# left alone.
find vendor-prefixed -type d \( -name .github -o -name docs \) -prune -exec rm -rf {} +
find vendor-prefixed -path vendor-prefixed/composer -prune -o -type f \( \
    -name 'README*' -o -name 'CHANGELOG*' -o -name 'SECURITY*' \
    -o -name composer.json -o -name puli.json -o -name 'phpstan.neon*' \
    -o -name '.php-cs-fixer*' \) -exec rm -f {} +

echo "Rebuilt lib/vendor-prefixed/. Commit it together with lib/composer.lock."
