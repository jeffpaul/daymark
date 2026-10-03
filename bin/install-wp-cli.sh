#!/usr/bin/env bash
# Install WP-CLI for CI, with retries and a checksum check.
#
# Usage: bash bin/install-wp-cli.sh [install-dir]
#
# Replaces shivammathur/setup-php's `tools: wp-cli`, whose download failed
# intermittently ("Could not setup wp-cli") and left later steps with
# `wp: command not found`. This fetches WP-CLI's official stable build
# (https://github.com/wp-cli/builds), retries a failed download, and checks
# it against the SHA-512 published beside it before installing.

set -euo pipefail

INSTALL_DIR=${1:-/usr/local/bin}
BASE_URL="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

fetch() {
	curl -sSfL --retry 5 --retry-delay 3 --retry-all-errors --connect-timeout 15 "$1" -o "$2"
}

fetch "$BASE_URL/wp-cli.phar" "$WORK/wp-cli.phar"
fetch "$BASE_URL/wp-cli.phar.sha512" "$WORK/wp-cli.phar.sha512"

EXPECTED=$(tr -d '[:space:]' < "$WORK/wp-cli.phar.sha512")
ACTUAL=$(sha512sum "$WORK/wp-cli.phar" | cut -d' ' -f1)

if [ "$EXPECTED" != "$ACTUAL" ]; then
	echo "WP-CLI checksum mismatch: expected $EXPECTED, got $ACTUAL" >&2
	exit 1
fi

chmod +x "$WORK/wp-cli.phar"

if [ -w "$INSTALL_DIR" ]; then
	mv "$WORK/wp-cli.phar" "$INSTALL_DIR/wp"
else
	sudo mv "$WORK/wp-cli.phar" "$INSTALL_DIR/wp"
fi

"$INSTALL_DIR/wp" --version --allow-root
