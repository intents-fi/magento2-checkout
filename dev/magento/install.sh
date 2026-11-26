#!/usr/bin/env bash
# Install Magento Open Source with IntentsFi_Checkout in app/code, against the services in
# docker-compose.yml. Throwaway: everything lands in dev/magento/src (git-ignored).
#
#   docker compose -f dev/magento/docker-compose.yml up -d
#   dev/magento/install.sh                      # MAGENTO_VERSION=2.4.8-p5 by default
#   (cd dev/magento/src && php -S 127.0.0.1:8080 -t pub phpserver/router.php)
#
# Packages come from the Mage-OS mirror of the Magento Open Source packages, which needs no
# repo.magento.com keys. Set COMPOSER_REPO=https://repo.magento.com/ to use Adobe's (needs keys).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/../.." && pwd)"
SRC="$HERE/src"
VERSION="${MAGENTO_VERSION:-2.4.8-p5}"
REPO="${COMPOSER_REPO:-https://mirror.mage-os.org/}"
BASE_URL="${BASE_URL:-http://127.0.0.1:8080/}"

if [ ! -f "$SRC/composer.json" ]; then
  composer create-project --no-interaction --no-progress --no-install \
    --repository-url="$REPO" "magento/project-community-edition=$VERSION" "$SRC"
  # Plugins (magento-composer-installer) lay out the project; allow them explicitly.
  composer --working-dir="$SRC" config --no-interaction allow-plugins true
fi
# Separate step, so a rerun after a failed download resumes rather than starting over.
if [ ! -f "$SRC/vendor/autoload.php" ]; then
  # --no-dev: the store needs none of the project's QA tools, and phpmd 3.x-dev has no tagged dist.
  composer --working-dir="$SRC" install --no-interaction --no-progress --no-dev
fi

# The module as a merchant installing from a zip into app/code would have it.
rm -rf "$SRC/app/code/IntentsFi/Checkout"
mkdir -p "$SRC/app/code/IntentsFi"
# Exactly what the shipped zip contains (bin/build-dist.sh), unzipped into app/code.
DIST="$(mktemp -d)"
"$MODULE/bin/build-dist.sh" "$DIST" >/dev/null
unzip -q "$DIST"/intentsfi-magento2-*.zip -d "$SRC/app/code/IntentsFi/Checkout"
rm -rf "$DIST"

cd "$SRC"
if [ ! -f app/etc/env.php ]; then
  php bin/magento setup:install \
    --base-url="$BASE_URL" \
    --db-host=127.0.0.1:33306 --db-name=magento --db-user=magento --db-password=magento \
    --search-engine=opensearch --opensearch-host=127.0.0.1 --opensearch-port=39200 \
    --admin-firstname=Admin --admin-lastname=Test --admin-email=admin@example.com \
    --admin-user=admin --admin-password='Admin12345!' \
    --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
    --backend-frontname=admin --cleanup-database
fi

# Throwaway dev store only: 2FA blocks the REST admin token smoke.sh uses. Never do this on a real store.
php bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth
php bin/magento module:enable IntentsFi_Checkout
php bin/magento setup:upgrade
php bin/magento deploy:mode:set developer
php bin/magento setup:di:compile
php bin/magento module:status IntentsFi_Checkout
