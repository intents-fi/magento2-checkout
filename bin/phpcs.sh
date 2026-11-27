#!/usr/bin/env bash
# The Adobe Commerce Marketplace technical review's code check: the Magento Coding Standard
# (magento/magento-coding-standard) at severity 10, on exactly what ships (the built zip).
#
#   bin/phpcs.sh [extra phpcs args…]       # e.g. --severity=5 to see the warnings too
#
# Installs the standard into dev/phpcs on first run. phpstan and rector are dependencies of the
# standard's other tools, not of phpcs, and ship as dist-only GitHub archives, so they are
# `replace`d to keep the install working without GitHub API access.
set -euo pipefail
cd "$(dirname "$0")/.."
TOOLS=dev/phpcs
if [ ! -x "$TOOLS/vendor/bin/phpcs" ]; then
  mkdir -p "$TOOLS"
  cat >"$TOOLS/composer.json" <<'JSON'
{
  "require": { "magento/magento-coding-standard": "^40" },
  "replace": { "phpstan/phpstan": "*", "rector/rector": "*" },
  "config": { "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true } }
}
JSON
  composer install --no-interaction --no-progress --working-dir="$TOOLS" \
    || composer install --no-interaction --no-progress --prefer-source --working-dir="$TOOLS"
fi

OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT
bin/build-dist.sh "$OUT" >/dev/null
mkdir "$OUT/module"
unzip -q "$OUT"/intentsfi-magento2-*.zip -d "$OUT/module"
"$TOOLS/vendor/bin/phpcs" --standard=Magento2 --severity=10 \
  --extensions=php,phtml,xml,js,html -s "$@" "$OUT/module"
echo "Magento Coding Standard: clean"
