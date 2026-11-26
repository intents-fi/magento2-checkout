#!/usr/bin/env bash
# Run a command with a given PHP version first on PATH, so install.sh, smoke.sh, connect-smoke.sh,
# bin/run-phpunit.sh and Composer all use it. Needs that version installed as phpX.Y (for example
# from the ondrej/php PPA, with bcmath curl gd intl mbstring mysql soap xml xsl zip sockets).
#
#   dev/magento/with-php.sh 8.5 dev/magento/install.sh
#   dev/magento/with-php.sh 8.4 bin/run-phpunit.sh
set -euo pipefail
VERSION="${1:?usage: with-php.sh <8.2|8.3|8.4|8.5> <command…>}"; shift
BIN="$(command -v "php$VERSION")" || { echo "php$VERSION is not installed" >&2; exit 1; }
SHIM="$(mktemp -d)"; trap 'rm -rf "$SHIM"' EXIT
ln -s "$BIN" "$SHIM/php"
PATH="$SHIM:$PATH" "$@"
