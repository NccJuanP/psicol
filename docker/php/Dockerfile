FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql pcntl intl zip bcmath opcache \
    && apk add --no-cache fcgi \
    && printf '[www]\nping.path = /ping\naccess.suppress_path[] = /ping\n' > /usr/local/etc/php-fpm.d/zz-ping.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html
USER www-data

# Dev dependencies included so the test suite runs inside the container.
COPY --chown=www-data:www-data backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data backend/ ./
# Config comes from compose env vars; the empty .env only silences phpdotenv's missing-file warning.
RUN composer dump-autoload --optimize --no-interaction && touch .env

COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
