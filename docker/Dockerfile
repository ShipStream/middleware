FROM php:7.4-fpm as php

COPY php.ini /usr/local/etc/php/conf.d/zz-shipstream.ini

# Add crontab file for cron mode
RUN set -x && apt-get update && apt-get -y install --no-install-recommends -q cron && rm /etc/crontab
COPY cron.sh /cron.sh
COPY crontab /etc/middleware-crontab
RUN crontab -u www-data /etc/middleware-crontab

# Allow XDebug to be added at build time
ARG XDEBUG
RUN if test -n "$XDEBUG"; then \
      pecl install xdebug && docker-php-ext-enable xdebug && rm -rf /tmp/* \
    ;fi
ENV XDEBUG_CONFIG="idekey=PHPSTORM client_port=9000 client_host=host.docker.internal"
ENV XDEBUG_MODE=develop,debug


FROM composer AS composer

COPY composer.* /app/

RUN composer install \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction \
  --no-progress \
  --ignore-platform-reqs


FROM php as heroku

COPY --chown=www-data --from=composer /app /var/www/html