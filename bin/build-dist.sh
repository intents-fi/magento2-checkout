#!/usr/bin/env bash
# Build the distributable zip of IntentsFi_Checkout.
#
#   bin/build-dist.sh [destination directory]      # default: plugins/.dist/
#
# One zip serves both installs:
#   - Adobe Commerce Marketplace: composer.json at the zip root, no vendor/, version from composer.json.
#   - app/code: unzip into app/code/IntentsFi/Checkout, then bin/magento setup:upgrade.
#
# Only the module ships. The dev tooling (dev/, bin/, phpunit.xml.dist, biome.json), the
# pure-logic tests, dotfiles and the marketplace listing material do not.
#
# The Connect protocol is not forked: lib/connect/class-connect-protocol.php must be byte-identical
# to plugins/_shared/connect/class-connect-protocol.php, the copy every store plugin uses.
set -euo pipefail

cd "$(dirname "$0")/.."
export TZ=UTC   # zip stores local times: pin them, so the build is reproducible
DEST="${1:-$(cd .. && pwd)/.dist}"
VERSION="$(php -r 'echo json_decode(file_get_contents("composer.json"), true)["version"];')"
CONST="$(sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p" Model/Config.php)"
if [ "$VERSION" != "$CONST" ]; then
  echo "composer.json version ($VERSION) and Config::VERSION ($CONST) differ" >&2
  exit 1
fi

for f in composer.json registration.php etc/module.xml; do
  [ -f "$f" ] || { echo "missing $f" >&2; exit 1; }
done
find . -name '*.php' -not -path './dev/*' -print0 | xargs -0 -n1 php -l >/dev/null

SHARED="../_shared/connect/class-connect-protocol.php"
if [ -f "$SHARED" ] && ! cmp -s "$SHARED" lib/connect/class-connect-protocol.php; then
  echo "lib/connect/class-connect-protocol.php differs from $SHARED: copy it over" >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
tar -cf - \
  --exclude='./dev' --exclude='./bin' --exclude='./Test' --exclude='./marketplace' \
  --exclude='./phpunit.xml.dist' --exclude='./biome.json' --exclude='./.*' --exclude='*/.*' \
  . | tar -xf - -C "$STAGE"

# Nothing that develops or tests the module may ship.
LEAKS="$(cd "$STAGE" && find . \( -name node_modules -o -name vendor -o -name Test -o -name tests -o -name dev \
  -o -name bin -o -name marketplace -o -name 'phpunit*' -o -name 'biome.json' -o -name 'composer.lock' \
  -o -name '*.zip' -o -name '.*' \) ! -name . -print)"
if [ -n "$LEAKS" ]; then
  echo "Development files in the distributable:" >&2
  echo "$LEAKS" >&2
  exit 1
fi

# Reproducible: the same commit builds the same zip, byte for byte (fixed times, sorted entries,
# no extra attributes), so a published sha256 can be checked by rebuilding.
find "$STAGE" -exec touch -h -d '2026-01-01 00:00:00 UTC' {} +
find "$STAGE" -type f -exec chmod 0644 {} +
find "$STAGE" -type d -exec chmod 0755 {} +

mkdir -p "$DEST"
ZIP="$DEST/intentsfi-magento2-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && find . -type f | sed 's|^\./||' | LC_ALL=C sort | zip -q -X "$ZIP" -@ )

echo "Built $ZIP"
echo "sha256 $(sha256sum "$ZIP" | cut -d' ' -f1)  size $(wc -c <"$ZIP" | tr -d ' ') bytes"
unzip -Z1 "$ZIP"
