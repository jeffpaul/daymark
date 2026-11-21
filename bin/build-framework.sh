#!/usr/bin/env bash
# Reproduce the shared, unprefixed WP App runtime shipped in the plugin.
set -euo pipefail
framework_dir=${1:-"$(dirname "$0")/../lib/framework"}
cd "$framework_dir"
composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
# Package repository notes are not part of the runtime distribution.
rm -f packages/akirk/wp-app/AGENTS.md packages/akirk/wp-app/.gitattributes packages/akirk/wp-app/README.md
