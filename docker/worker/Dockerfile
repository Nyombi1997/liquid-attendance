# Service de synchronisation : récupère les pointages des ZKTeco (Python/pyzk,
# testé sur les SF400 et le F18) puis lance le calcul des journées (PHP,
# mêmes règles que l'application).
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-pip tzdata \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && pip3 install --no-cache-dir --break-system-packages pyzk "PyMySQL>=1.1"

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY . /var/www/app
RUN rm -rf /var/www/app/data /var/www/app/.env && chmod +x /var/www/app/worker/run.sh

WORKDIR /var/www/app
CMD ["/var/www/app/worker/run.sh"]
