#!/bin/bash
# Verificacao completa: PHPStan (host) + PHPUnit (Docker) para manager e site.
set -e
( cd site && php app/inc/lib/vendor/bin/phpstan analyse )
( cd manager && php app/inc/lib/vendor/bin/phpstan analyse )
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit
docker exec infinnityimportacao php /var/www/infinnityimportacao/manager/app/inc/lib/vendor/bin/phpunit
echo "Verificacao completa OK"
