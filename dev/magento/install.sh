#!/usr/bin/env bash
# Install Magento Open Source with IntentsFi_Checkout, against the services in docker-compose.yml.
# Throwaway: everything lands in dev/magento/src (git-ignored), or in MAGENTO_SRC.
#
#   docker compose -f dev/magento/docker-compose.yml up -d
#   dev/magento/install.sh                      # MAGENTO_VERSION=2.4.8-p5 by default
#   (cd dev/magento/src && php -S 127.0.0.1:8080 -t pub phpserver/router.php)
#
# The module goes in the way a merchant installs it, from the zip bin/build-dist.sh builds:
#   MODULE_FROM=zip (default)   unzipped into app/code/IntentsFi/Checkout
#   MODULE_FROM=composer        `composer require intents/module-checkout:<version>` from a Composer
#                               artifact repository holding that zip: a local copy of the package,
#                               as the Adobe Commerce Marketplace serves it
# DEPLOY_MODE=developer (default) or production (setup:static-content:deploy as well).
# MAGENTO_SRC=<dir> keeps one install per Magento and PHP version (Composer resolves the store's
# dependencies for the PHP that runs it); smoke.sh and connect-smoke.sh take it too.
#
# Packages come from the Mage-OS mirror of the Magento Open Source packages, which needs no
# repo.magento.com keys. Set COMPOSER_REPO=https://repo.magento.com/ to use Adobe's (needs keys).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/../.." && pwd)"
SRC="${MAGENTO_SRC:-$HERE/src}"
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

# Exactly what ships: the zip bin/build-dist.sh builds.
MODULE_FROM="${MODULE_FROM:-zip}"
DEPLOY_MODE="${DEPLOY_MODE:-developer}"
ARTIFACTS="$SRC/var/intents-artifacts"
rm -rf "$ARTIFACTS" "$SRC/app/code/IntentsFi/Checkout"
mkdir -p "$ARTIFACTS"
"$MODULE/bin/build-dist.sh" "$ARTIFACTS" >/dev/null
ZIP="$(ls "$ARTIFACTS"/intentsfi-magento2-*.zip)"
PKG_VERSION="$(basename "$ZIP" .zip | sed 's/^intentsfi-magento2-//')"
case "$MODULE_FROM" in
  zip)
    # As a merchant unzipping it into app/code.
    composer --working-dir="$SRC" remove --no-interaction --no-progress --no-update intents/module-checkout >/dev/null 2>&1 || true
    mkdir -p "$SRC/app/code/IntentsFi"
    unzip -q "$ZIP" -d "$SRC/app/code/IntentsFi/Checkout"
    ;;
  composer)
    # As a merchant installing the Marketplace package with Composer.
    composer --working-dir="$SRC" config --no-interaction repositories.intents-local artifact "$ARTIFACTS"
    composer --working-dir="$SRC" require --no-interaction --no-progress --update-no-dev "intents/module-checkout:$PKG_VERSION"
    [ -f "$SRC/vendor/intents/module-checkout/registration.php" ] || { echo "the package did not install into vendor/" >&2; exit 1; }
    ;;
  *) echo "MODULE_FROM is zip or composer" >&2; exit 1 ;;
esac

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
if [ "$DEPLOY_MODE" = production ]; then
  # Compiles and deploys static content (en_US) itself.
  php bin/magento deploy:mode:set production
else
  php bin/magento deploy:mode:set developer
  php bin/magento setup:di:compile
fi
php bin/magento module:status IntentsFi_Checkout
