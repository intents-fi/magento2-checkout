#!/usr/bin/env bash
# Run the pure-logic PHPUnit suite. Needs PHP 8.2+ and Composer on the host; no Magento install.
#
#   bin/run-phpunit.sh [phpunit args…]
set -euo pipefail
cd "$(dirname "$0")/.."
if [ ! -f dev/phpunit/vendor/autoload.php ]; then
  composer install --no-interaction --no-progress --working-dir=dev/phpunit
fi
exec dev/phpunit/vendor/bin/phpunit -c phpunit.xml.dist "$@"
