#!/bin/bash
# Verificacao completa: sync guard + PHPStan (host) + PHPUnit (Docker) para manager e site.
# Espelha .github/workflows/ci.yml — mantenha os dois em sincronia.
set -e
bash "$(dirname "$0")/check-shared-sync.sh"
( cd site && php app/inc/lib/vendor/bin/phpstan analyse )
( cd manager && php app/inc/lib/vendor/bin/phpstan analyse )
docker exec app php /var/www/app/site/app/inc/lib/vendor/bin/phpunit -c /var/www/app/site/phpunit.xml
docker exec app php /var/www/app/manager/app/inc/lib/vendor/bin/phpunit -c /var/www/app/manager/phpunit.xml
echo "Verificacao completa OK"
