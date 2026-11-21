#!/usr/bin/env bash
# Reproduce the shared, unprefixed WP App runtime shipped in the plugin.
set -euo pipefail
cd "$(dirname "$0")/../lib/framework"
composer install --no-dev --no-interaction --prefer-dist --no-progress
# Package repository notes are not part of the runtime distribution.
rm -f packages/akirk/wp-app/AGENTS.md packages/akirk/wp-app/.gitattributes packages/akirk/wp-app/README.md
