FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends openssl tzdata \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && a2enmod ssl rewrite headers \
 && a2dissite 000-default \
 && sed -i 's/^Listen 80$/# Listen 80 (désactivé)/' /etc/apache2/ports.conf \
 && sed -i 's/^\s*Listen 443$/    # Listen 443 (désactivé)/' /etc/apache2/ports.conf

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/apache-site.conf /etc/apache2/sites-available/app.conf
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
RUN a2ensite app && chmod +x /usr/local/bin/app-entrypoint

COPY . /var/www/app
RUN rm -rf /var/www/app/data /var/www/app/.env \
 && chown -R www-data:www-data /var/www/app

WORKDIR /var/www/app
EXPOSE 8443
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
