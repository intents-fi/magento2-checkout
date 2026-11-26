#!/usr/bin/env bash
# The Adobe Commerce Marketplace technical review's code check: the Magento Coding Standard
# (magento/magento-coding-standard) at severity 10, on exactly what ships (the built zip), run the
# way Adobe runs it: with --ignore-annotations, so a `// phpcs:ignore` comment excuses nothing.
# Runs it twice, with version 25 of the standard (what Adobe's EQP used on 1.0.2) and version 40
# (the current one); both must report no errors and no warnings at severity 10.
#
#   bin/phpcs.sh [extra phpcs args…]       # e.g. --severity=5 to see the warnings too
#   bin/phpcs.sh --write-report            # also regenerate marketplace/phpcs-report.txt
#
# Installs each standard into dev/phpcs/v<version> on first run. phpstan and rector are dependencies
# of the standard's other tools, not of phpcs, and ship as dist-only GitHub archives, so they are
# `replace`d to keep the install working without GitHub API access.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSIONS=(25 40)
WRITE_REPORT=0
if [ "${1:-}" = "--write-report" ]; then WRITE_REPORT=1; shift; fi

install_standard() {
  local version="$1" tools="dev/phpcs/v$1"
  if [ -x "$tools/vendor/bin/phpcs" ]; then return; fi
  mkdir -p "$tools"
  cat >"$tools/composer.json" <<JSON
{
  "require": { "magento/magento-coding-standard": "$version.*" },
  "replace": { "phpstan/phpstan": "*", "rector/rector": "*" },
  "config": { "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true } }
}
JSON
  composer install --no-interaction --no-progress --working-dir="$tools" >&2 \
    || composer install --no-interaction --no-progress --prefer-source --working-dir="$tools" >&2
  # Register the standards here rather than rely on the Composer installer plugin, which Composer
  # skips when it runs as root without an interactive prompt: each <dir>/<Standard>/ruleset.xml.
  local paths
  paths="$(cd "$tools" && find "$PWD/vendor" -mindepth 4 -maxdepth 4 -name ruleset.xml -not -path '*/squizlabs/*' \
    -exec dirname {} \; | xargs -n1 dirname | sort -u | paste -sd, -)"
  "$tools/vendor/bin/phpcs" --config-set installed_paths "$paths" >/dev/null
}

# The installed versions, for the report header: "40 (phpcs 3.13.6)".
describe() {
  local tools="dev/phpcs/v$1"
  printf '%s (phpcs %s)' \
    "$(php -r '$l=json_decode(file_get_contents($argv[1]),true); foreach($l["packages"] as $p) if($p["name"]=="magento/magento-coding-standard") echo $p["version"];' "$tools/composer.lock")" \
    "$("$tools/vendor/bin/phpcs" --version | sed -n 's/.*version \([0-9.]*\).*/\1/p')"
}

OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT
bin/build-dist.sh "$OUT" >/dev/null
ZIP="$(ls "$OUT"/intentsfi-magento2-*.zip)"
mkdir "$OUT/module"
unzip -q "$ZIP" -d "$OUT/module"

COMMON=(--standard=Magento2 --ignore-annotations --extensions=php,phtml,xml,js,html --basepath="$OUT/module")
for v in "${VERSIONS[@]}"; do
  install_standard "$v"
  echo "== magento/magento-coding-standard $(describe "$v"), severity 10, --ignore-annotations"
  "dev/phpcs/v$v/vendor/bin/phpcs" "${COMMON[@]}" --severity=10 -s "$@" "$OUT/module" 2>/dev/null
  echo "clean"
done

if [ "$WRITE_REPORT" = 1 ]; then
  REPORT=marketplace/phpcs-report.txt
  # Every severity, with v40: the warnings the gate does not count, for the reviewer.
  ALL="$("dev/phpcs/v40/vendor/bin/phpcs" "${COMMON[@]}" --severity=1 --report-width=120 \
    --report=summary --report-source "$OUT/module" 2>/dev/null || true)"
  PROTOCOL="$(printf '%s\n' "$ALL" | awk '$1 == "Lib/ConnectProtocol.php" { print $3 }')"
  {
    echo "Magento Coding Standard on $(basename "$ZIP"), run by bin/phpcs.sh on $(date -u +%F) with"
    echo "--ignore-annotations (as Adobe's EQP runs it: phpcs:ignore comments are not honoured)."
    for v in "${VERSIONS[@]}"; do
      echo "  magento/magento-coding-standard $(describe "$v"), severity 10 (the Marketplace gate): 0 errors, 0 warnings."
    done
    echo "All severities (v40): 0 errors; warnings by file below. ${PROTOCOL:-0} of them are formatting in"
    echo "Lib/ConnectProtocol.php, generated from the WordPress-style Connect protocol that every Intents"
    echo "store plugin shares (not forked, so not reformatted here)."
    echo
    printf '%s\n' "$ALL" | sed -n '/^PHP CODE SNIFFER REPORT SUMMARY/,$p'
  } >"$REPORT"
  echo "wrote $REPORT"
fi
echo "Magento Coding Standard: clean"
